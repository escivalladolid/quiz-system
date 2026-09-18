<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/exam_status.php';
require_once __DIR__ . '/../helpers/exam_monitoring.php';
require_once __DIR__ . '/../helpers/exam_attempts.php';
require_once __DIR__ . '/../helpers/archive.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('This endpoint only accepts POST requests.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo = getDbConnection();
$user = requireRole($pdo, ['STUDENT']);
$input = getJsonInput();
requireFields($input, ['exam_id']);

$examId = (int) $input['exam_id'];
$studentId = (int) $user['user_id'];
$requestedAttemptId = examAttemptIdFromInput($input);
$eventType = strtoupper(trim((string) ($input['event_type'] ?? 'HEARTBEAT')));
$allowedEvents = [
    'EXAM_STARTED', 'HEARTBEAT', 'ACTIVE', 'BACKGROUND',
    'NETWORK_LOST', 'NETWORK_RESTORED', 'QUESTION_VIEWED',
    'ANSWER_CHANGED', 'SCREENSHOT', 'SCREEN_RECORDING', 'MULTI_WINDOW',
    'SUBMITTED', 'CLOSED'
];

if (!in_array($eventType, $allowedEvents, true)) {
    sendError('Unsupported monitoring event.', 'INVALID_EVENT', 422);
}

try {
    syncExamStatuses($pdo);

    $examStmt = $pdo->prepare(
        'SELECT e.exam_id, e.status, e.is_archived, c.status AS class_status, c.is_archived AS class_is_archived, e.class_id
         FROM exams e JOIN classes c ON c.class_id=e.class_id WHERE e.exam_id = :eid'
    );
    $examStmt->execute(['eid' => $examId]);
    $exam = $examStmt->fetch();
    if (!$exam) sendError('Exam not found.', 'NOT_FOUND', 404);
    $isArchived = ((int) ($exam['is_archived'] ?? 0) === 1 || (int) ($exam['class_is_archived'] ?? 0) === 1
        || strtoupper((string) ($exam['status'] ?? '')) === 'ARCHIVED'
        || strtoupper((string) ($exam['class_status'] ?? '')) === 'ARCHIVED');

    $enrollStmt = $pdo->prepare(
        'SELECT 1 FROM enrollments WHERE user_id = :uid AND class_id = :cid'
    );
    $enrollStmt->execute(['uid' => $studentId, 'cid' => $exam['class_id']]);
    if (!$enrollStmt->fetch()) {
        sendError('You are not enrolled in this class.', 'NOT_ENROLLED', 403);
    }

    $attempt = resolveExamAttempt($pdo, $examId, $studentId, $requestedAttemptId);
    if (!$attempt && $eventType !== 'SUBMITTED') {
        sendError(
            $requestedAttemptId !== null ? 'The supplied exam attempt is not valid.' : 'Start the exam before sending monitoring data.',
            $requestedAttemptId !== null ? 'INVALID_ATTEMPT' : 'ATTEMPT_NOT_STARTED',
            403
        );
    }
    $attemptId = $attempt ? (int) $attempt['attempt_id'] : null;

    $submissionStmt = $pdo->prepare(
        'SELECT 1 FROM exam_submissions WHERE exam_id = :eid AND user_id = :uid'
    );
    $submissionStmt->execute(['eid' => $examId, 'uid' => $studentId]);
    $alreadySubmitted = (bool) $submissionStmt->fetch();

    if ($alreadySubmitted && $eventType !== 'SUBMITTED') {
        sendError('This exam has already been submitted.', 'ALREADY_SUBMITTED', 409);
    }
    if (!$alreadySubmitted && ($isArchived || strtoupper((string) $exam['status']) !== 'LIVE')) {
        sendError('This exam is closed.', 'EXAM_CLOSED', 403);
    }

    $questionId = isset($input['question_id']) ? (int) $input['question_id'] : 0;
    if ($questionId > 0) {
        $questionStmt = $pdo->prepare(
            'SELECT 1 FROM questions WHERE question_id = :qid AND exam_id = :eid'
        );
        $questionStmt->execute(['qid' => $questionId, 'eid' => $examId]);
        if (!$questionStmt->fetch()) {
            sendError('Question does not belong to this exam.', 'INVALID_QUESTION', 422);
        }
    }

    $activityLogFailed = false;
    $recorded = recordExamActivity($pdo, $examId, $studentId, $eventType, [
        'attempt_id' => $attemptId,
        'question_id' => $questionId,
        'question_index' => isset($input['question_index']) ? (int) $input['question_index'] : null,
        'answered_count' => isset($input['answered_count']) ? (int) $input['answered_count'] : null,
        'total_questions' => isset($input['total_questions']) ? (int) $input['total_questions'] : null,
        'network_state' => $input['network_state'] ?? null,
    ], $activityLogFailed);

    // If the newer activity history migration is not present, retain the
    // security events that teachers need in the legacy feed. This is only a
    // compatibility path; once exam_activity_log exists, it is the source of
    // truth and no duplicate legacy row is written.
    $legacyRecorded = false;
    $activityLogAvailable = examActivityLogAvailable($pdo);
    if (($activityLogFailed || !$activityLogAvailable) && $eventType !== 'HEARTBEAT') {
        $legacyRecorded = recordLegacyExamActivity($pdo, $examId, $studentId, $eventType, $attemptId);
    }

    $monitoringAvailable = examMonitoringTablesAvailable($pdo);

    // Monitoring is intentionally additive. If the migration has not been
    // applied yet, the exam remains usable and the teacher screen reports the
    // capability as unavailable instead of breaking the attempt.
    sendSuccess([
        'recorded' => $recorded || $legacyRecorded,
        'monitoring_available' => $monitoringAvailable,
        'activity_log_available' => $activityLogAvailable,
        'attempt_id' => $attemptId,
    ]);
} catch (PDOException $e) {
    sendError('Database error: ' . $e->getMessage(), 'DB_ERROR', 500);
}
