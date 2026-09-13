<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/exam_status.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('This endpoint only accepts POST requests.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo   = getDbConnection();
$user  = requireRole($pdo, ['STUDENT']);
$input = getJsonInput();
requireFields($input, ['exam_id', 'question_id', 'answer']);

$examId    = (int) $input['exam_id'];
$questionId = (int) $input['question_id'];
$answer    = $input['answer'];
// Client-generated per-question version: monotonically increasing. Saved
// answers are only accepted as the newest version for their question, so a
// stale offline replay can never overwrite a newer answer.
$revision  = isset($input['revision']) ? max(0, (int) $input['revision']) : 0;
$studentId = $user['user_id'];

// Sync time-based transitions so the status below is always current.
syncExamStatuses($pdo);

// Verify the exam exists and is still live
$examStmt = $pdo->prepare(
    'SELECT e.exam_id, e.status, e.class_id FROM exams e WHERE e.exam_id = :eid'
);
$examStmt->execute(['eid' => $examId]);
$exam = $examStmt->fetch();

if (!$exam) {
    sendError('Exam not found.', 'NOT_FOUND', 404);
}

// If the exam became CLOSED/ARCHIVED, immediately reject any further saves.
if (strtoupper((string)$exam['status']) !== 'LIVE') {
    sendError('Exam closed. Further submissions are rejected.', 'EXAM_CLOSED', 403);
}

// Verify student is enrolled
$enrollCheck = $pdo->prepare('SELECT 1 FROM enrollments WHERE user_id = :uid AND class_id = :cid');
$enrollCheck->execute(['uid' => $studentId, 'cid' => $exam['class_id']]);
if (!$enrollCheck->fetch()) {
    sendError('You are not enrolled in this class.', 'NOT_ENROLLED', 403);
}

// Verify the question belongs to this exam
$qCheck = $pdo->prepare('SELECT 1 FROM questions WHERE question_id = :qid AND exam_id = :eid');
$qCheck->execute(['qid' => $questionId, 'eid' => $examId]);
if (!$qCheck->fetch()) {
    sendError('Question does not belong to this exam.', 'INVALID_QUESTION', 422);
}

// Fast path: already submitted
$subCheck = $pdo->prepare('SELECT 1 FROM exam_submissions WHERE exam_id = :eid AND user_id = :uid');
$subCheck->execute(['eid' => $examId, 'uid' => $studentId]);
if ($subCheck->fetch()) {
    sendError('Exam already submitted. Cannot save answers.', 'ALREADY_SUBMITTED', 409);
}

// Configuration gates: a missing dependency must fail loudly, never silently
// degrade into an "acknowledged but not persisted" success.
foreach (['exam_attempts', 'exam_answer_revisions'] as $tableName) {
    try {
        $pdo->query("SELECT 1 FROM `$tableName` LIMIT 1");
    } catch (PDOException $e) {
        sendError(
            "The $tableName table is missing on the server. Ask your administrator to apply the migration.",
            'SERVER_MISCONFIGURED',
            500
        );
    }
}

// Save atomically:
//   1. Serialize against submission by locking the (exam, student) slot in
//      exam_submissions (gap lock when no submission exists yet). A submit
//      racing with this save waits for the lock; once a submission exists,
//      further saves are rejected — no write can land after finalize.
//   2. Reject saves when no attempt is started (ATTEMPT_NOT_STARTED) and when
//      the per-student deadline has passed (EXAM_TIME_EXPIRED). The unlimited
//      sentinel never expires.
//   3. Version-gated upsert: a stale (lower revision) write can never replace
//      a newer answer for the same question; retries with an equal revision are
//      idempotent no-ops.
$pdo->beginTransaction();
try {
    $lockStmt = $pdo->prepare(
        'SELECT user_id FROM exam_submissions WHERE exam_id = :eid AND user_id = :uid FOR UPDATE'
    );
    $lockStmt->execute(['eid' => $examId, 'uid' => $studentId]);
    if ($lockStmt->fetch()) {
        $pdo->rollBack();
        sendError('Exam already submitted. Cannot save answers.', 'ALREADY_SUBMITTED', 409);
    }

    $attemptStmt = $pdo->prepare(
        'SELECT started_at, deadline_at FROM exam_attempts WHERE exam_id = :eid AND user_id = :uid'
    );
    $attemptStmt->execute(['eid' => $examId, 'uid' => $studentId]);
    $attempt = $attemptStmt->fetch();

    if (!$attempt) {
        $pdo->rollBack();
        sendError('Start the exam before saving answers.', 'ATTEMPT_NOT_STARTED', 403);
    }

    $deadlineAt = $attempt['deadline_at'];
    $now = date('Y-m-d H:i:s');
    if ($deadlineAt && $deadlineAt !== '2099-12-31 23:59:59' && strtotime($deadlineAt) < strtotime($now)) {
        $pdo->rollBack();
        sendError('Your time for this exam has expired.', 'EXAM_TIME_EXPIRED', 403);
    }

    $pdo->prepare(
        'INSERT INTO exam_answer_revisions (exam_id, user_id, question_id, revision, answer)
         VALUES (:eid, :uid, :qid, :rev, :answer)
         ON DUPLICATE KEY UPDATE
            answer      = IF(revision <= :rev, VALUES(answer), answer),
            revision    = IF(revision <= :rev, VALUES(revision), revision),
            updated_at  = IF(revision <= :rev, NOW(), updated_at)'
    )->execute([
        'eid'    => $examId,
        'uid'    => $studentId,
        'qid'    => $questionId,
        'rev'    => $revision,
        'answer' => $answer,
    ]);

    $currentStmt = $pdo->prepare(
        'SELECT revision FROM exam_answer_revisions
         WHERE exam_id = :eid AND user_id = :uid AND question_id = :qid'
    );
    $currentStmt->execute(['eid' => $examId, 'uid' => $studentId, 'qid' => $questionId]);
    $currentRevision = (int) ($currentStmt->fetch()['revision'] ?? $revision);

    $pdo->commit();

    sendSuccess([
        'saved'            => true,
        'question_id'      => $questionId,
        'answer'           => $answer,
        'revision'         => $revision,
        'current_revision' => $currentRevision,
        'accepted'         => $revision >= $currentRevision,
        'persisted'        => true,
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}