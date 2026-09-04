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
requireFields($input, ['exam_id']);

$examId = (int) $input['exam_id'];
$studentId = $user['user_id'];

try {
    syncExamStatuses($pdo);

    $examStmt = $pdo->prepare(
        'SELECT e.exam_id, e.status, e.max_exit_attempts, c.class_id
         FROM exams e
         JOIN classes c ON c.class_id = e.class_id
         WHERE e.exam_id = :eid'
    );
    $examStmt->execute(['eid' => $examId]);
    $exam = $examStmt->fetch();

    if (!$exam) {
        sendError('Exam not found.', 'NOT_FOUND', 404);
    }

    if (strtoupper((string) $exam['status']) !== 'LIVE') {
        sendError('This exam is closed.', 'EXAM_CLOSED', 403);
    }

    // Verify the student is enrolled and has not already submitted.
    $enrollCheck = $pdo->prepare('SELECT 1 FROM enrollments WHERE user_id = :uid AND class_id = :cid');
    $enrollCheck->execute(['uid' => $studentId, 'cid' => $exam['class_id']]);
    if (!$enrollCheck->fetch()) {
        sendError('You are not enrolled in this class.', 'NOT_ENROLLED', 403);
    }

    $subCheck = $pdo->prepare('SELECT 1 FROM exam_submissions WHERE exam_id = :eid AND user_id = :uid');
    $subCheck->execute(['eid' => $examId, 'uid' => $studentId]);
    if ($subCheck->fetch()) {
        sendError('You have already submitted this exam.', 'ALREADY_SUBMITTED', 409);
    }

    $insert = $pdo->prepare(
        'INSERT INTO exam_proctoring_log (exam_id, user_id, event_type, created_at)
         VALUES (:eid, :uid, :evt, NOW())'
    );
    $insert->execute(['eid' => $examId, 'uid' => $studentId, 'evt' => 'TAB_SWITCH']);

    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) AS cnt FROM exam_proctoring_log WHERE exam_id = :eid AND user_id = :uid'
    );
    $countStmt->execute(['eid' => $examId, 'uid' => $studentId]);
    $count = (int) $countStmt->fetch()['cnt'];

    sendSuccess([
        'tab_switch_count' => $count,
        'max_exit_attempts' => $exam['max_exit_attempts'] !== null ? (int) $exam['max_exit_attempts'] : null,
    ]);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
