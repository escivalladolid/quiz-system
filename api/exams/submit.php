<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/exam_status.php';
require_once __DIR__ . '/../../helpers/exam_grading.php';
require_once __DIR__ . '/../../helpers/exam_monitoring.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('This endpoint only accepts POST requests.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo  = getDbConnection();
$user = requireRole($pdo, ['STUDENT']);
$input = getJsonInput();
requireFields($input, ['exam_id']);

$examId       = (int) $input['exam_id'];
$answers      = (isset($input['answers']) && is_array($input['answers'])) ? $input['answers'] : [];
$timeUsedSecs = isset($input['time_used_secs']) ? (int) $input['time_used_secs'] : null;
$exitAttempts = isset($input['exit_attempts']) ? (int) $input['exit_attempts'] : 0;
$autoSubmitted = !empty($input['auto_submitted']) ? 1 : 0;

// Sync time-based transitions so the status below is always current.
syncExamStatuses($pdo);

// Build the idempotent receipt for an existing submission row. score is stored
// as earned points (variable-point scoring); percentage is derived from the
// SUM of question points, never exams.total_points.
$buildReceipt = function (PDO $pdo, array $sub, ?float $passingScore): array {
    $totalPtsStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(points), 0) AS total_points FROM questions WHERE exam_id = :eid'
    );
    $totalPtsStmt->execute(['eid' => (int) $sub['exam_id']]);
    $totalPoints = (int) $totalPtsStmt->fetchColumn();

    $earned = (int) $sub['score'];
    $pct    = $totalPoints > 0 ? round(($earned / $totalPoints) * 100, 2) : 0.0;
    $passed = ($passingScore !== null) ? ($pct >= $passingScore) : null;

    return [
        'submission_id'     => (int) $sub['submission_id'],
        'score'             => $earned,
        'earned_points'     => $earned,
        'total_points'      => $totalPoints,
        'correct_count'     => (int) $sub['correct_count'],
        'total_questions'   => (int) $sub['total_questions'],
        'percentage'        => $pct,
        'passing_score'     => $passingScore,
        'passed'            => $passed,
        'time_used_secs'    => $sub['time_used_secs'] !== null ? (int) $sub['time_used_secs'] : null,
        'exit_attempts'     => (int) $sub['exit_attempts'],
        'auto_submitted'    => (bool) $sub['auto_submitted'],
        'submitted_at'      => $sub['submitted_at'],
    ];
};

// Get exam
$examStmt = $pdo->prepare(
    'SELECT e.exam_id, e.status, e.class_id, e.passing_score
     FROM exams e WHERE e.exam_id = :eid'
);
$examStmt->execute(['eid' => $examId]);
$exam = $examStmt->fetch();

if (!$exam) {
    sendError('Exam not found.', 'NOT_FOUND', 404);
}
$passingScore = $exam['passing_score'] !== null ? (float) $exam['passing_score'] : null;

// If the student has already submitted, this is a resume/resubmit attempt.
// Return the existing result idempotently instead of erroring, so the app
// never shows "submit failed" for an already-submitted exam.
$subCheck = $pdo->prepare(
    'SELECT submission_id, exam_id, score, correct_count, total_questions, time_used_secs,
            submitted_at, exit_attempts, auto_submitted
     FROM exam_submissions WHERE exam_id = :eid AND user_id = :uid'
);
$subCheck->execute(['eid' => $examId, 'uid' => $user['user_id']]);
$existingSub = $subCheck->fetch();
if ($existingSub) {
    $receipt = $buildReceipt($pdo, $existingSub, $passingScore);
    $receipt['already_submitted'] = true;
    sendSuccess($receipt);
}

// If the exam closed (manually or automatically), reject further submissions.
if (strtoupper((string)$exam['status']) !== 'LIVE') {
    sendError('Exam closed. Further submissions are rejected.', 'EXAM_CLOSED', 403);
}

// Verify enrollment
$enrollCheck = $pdo->prepare('SELECT 1 FROM enrollments WHERE user_id = :uid AND class_id = :cid');
$enrollCheck->execute(['uid' => $user['user_id'], 'cid' => $exam['class_id']]);
if (!$enrollCheck->fetch()) {
    sendError('You are not enrolled in this class.', 'NOT_ENROLLED', 403);
}

// Configuration gates: a missing dependency must fail loudly.
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

// The student must start the exam (exam_start.php action=start) and may only
// submit while their OWN deadline is still open. A closed exam is handled
// above, so a late submit after global close is still accepted (finalize).
$attemptStmt = $pdo->prepare(
    'SELECT started_at, deadline_at FROM exam_attempts WHERE exam_id = :eid AND user_id = :uid'
);
$attemptStmt->execute(['eid' => $examId, 'uid' => $user['user_id']]);
$attempt = $attemptStmt->fetch();

if (!$attempt) {
    sendError('Start the exam before submitting.', 'ATTEMPT_NOT_STARTED', 403);
}

$deadlineAt = $attempt['deadline_at'];
$now = date('Y-m-d H:i:s');
if ($deadlineAt && $deadlineAt !== '2099-12-31 23:59:59' && strtotime($deadlineAt) < strtotime($now)) {
    sendError('Your time for this exam has expired.', 'EXAM_TIME_EXPIRED', 403);
}

// Fetch all questions for this exam
$qStmt = $pdo->prepare(
    'SELECT question_id, question_text, question_type, options, correct_answer,
            points, answer_matching, answer_rules
     FROM questions WHERE exam_id = :eid'
);
$qStmt->execute(['eid' => $examId]);
$questions = $qStmt->fetchAll();

// Finalize atomically:
//   1. Lock the (exam, student) submission slot so an in-flight save either
//      completes before grading (its answer is included) or is rejected after
//      this submission lands.
//   2. Merge the latest durable auto-saved revisions as the authoritative
//      complement: for any question the client did NOT send, use the newest
//      saved value — a fast submit never drops answers.
//   3. Insert the submission; a concurrent duplicate insert (23000) is
//      resolved to the stored row so every retry gets ONE confirmed result.
$pdo->beginTransaction();
try {
    $lockStmt = $pdo->prepare(
        'SELECT user_id FROM exam_submissions WHERE exam_id = :eid AND user_id = :uid FOR UPDATE'
    );
    $lockStmt->execute(['eid' => $examId, 'uid' => $user['user_id']]);
    if ($lockStmt->fetch()) {
        $subCheck->execute(['eid' => $examId, 'uid' => $user['user_id']]);
        $receipt = $buildReceipt($pdo, $subCheck->fetch(), $passingScore);
        $receipt['already_submitted'] = true;
        $pdo->commit();
        sendSuccess($receipt);
    }

    $saved = loadStudentRevisionAnswers($pdo, $examId, $user['user_id'])['answers'];
    foreach ($saved as $qid => $value) {
        $qid = (string) $qid;
        if (!array_key_exists($qid, $answers) && $value !== null && $value !== '') {
            $answers[$qid] = $value;
        }
    }

    $grade = gradeExamQuestions($questions, $answers, $passingScore);
    $totalQuestions = $grade['total_questions'];

    $inserted = true;
    try {
        $pdo->prepare(
            'INSERT INTO exam_submissions
                (exam_id, user_id, answers_json, score, correct_count, total_questions,
                 time_used_secs, exit_attempts, auto_submitted)
             VALUES
                (:eid, :uid, :answers, :score, :correct, :total, :time, :exit, :auto)'
        )->execute([
            'eid'    => $examId,
            'uid'    => $user['user_id'],
            'answers'=> json_encode($answers),
            'score'  => $grade['score'],
            'correct'=> $grade['correct_count'],
            'total'  => $totalQuestions,
            'time'   => $timeUsedSecs,
            'exit'   => $exitAttempts,
            'auto'   => $autoSubmitted,
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') throw $e;
        $inserted = false;
    }

    // One authoritative row exists regardless of this request winning or losing
    // the insert race; read it back so every retry gets the SAME result.
    $subCheck->execute(['eid' => $examId, 'uid' => $user['user_id']]);
    $receipt = $buildReceipt($pdo, $subCheck->fetch(), $passingScore);
    $receipt['already_submitted'] = !$inserted;

    $pdo->commit();
    $answeredCount = 0;
    foreach ($answers as $answerValue) {
        if (is_array($answerValue)) {
            if (!empty($answerValue)) $answeredCount++;
        } elseif (trim((string) $answerValue) !== '') {
            $answeredCount++;
        }
    }
    recordExamActivity($pdo, $examId, (int) $user['user_id'], 'SUBMITTED', [
        'answered_count' => $answeredCount,
        'total_questions' => $totalQuestions,
        'network_state' => 'ONLINE',
    ]);
    sendSuccess($receipt);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
}
