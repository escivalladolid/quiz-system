<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/exam_status.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('This endpoint only accepts POST requests.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo  = getDbConnection();
$user = requireRole($pdo, ['TEACHER']);

$input = getJsonInput();
requireFields($input, ['exam_id']);

$examId = (int) $input['exam_id'];
if ($examId <= 0) {
    sendError('Invalid exam id.', 'INVALID_ID', 422);
}

// Verify the exam exists and belongs to a class owned by this teacher
$stmt = $pdo->prepare(
    'SELECT e.exam_id, e.status, e.is_closed, e.closed_at, c.teacher_id
     FROM exams e
     JOIN classes c ON c.class_id = e.class_id
     WHERE e.exam_id = :eid'
);
$stmt->execute(['eid' => $examId]);
$exam = $stmt->fetch();

if (!$exam) {
    sendError('Exam not found.', 'NOT_FOUND', 404);
}

if ((int) $exam['teacher_id'] !== (int) $user['user_id']) {
    sendError('You do not own this exam.', 'FORBIDDEN', 403);
}

$currentStatus = strtoupper((string) $exam['status']);

// Idempotent: if the exam is already CLOSED or ARCHIVED, do nothing.
if (in_array($currentStatus, ['CLOSED', 'ARCHIVED'], true)) {
    sendSuccess([
        'exam_id'   => $examId,
        'status'    => $currentStatus,
        'is_closed' => (int) $exam['is_closed'] === 1,
        'closed_at' => $exam['closed_at'],
    ]);
}

// Manual close: immediately update the database status, ignoring the
// remaining exam time. This blocks any further student submissions.
$pdo->prepare(
    "UPDATE exams
        SET is_closed = 1, closed_at = NOW(), status = 'CLOSED'
      WHERE exam_id = :eid"
)->execute(['eid' => $examId]);

$closed = $pdo->prepare('SELECT status, is_closed, closed_at FROM exams WHERE exam_id = :eid');
$closed->execute(['eid' => $examId]);
$row = $closed->fetch();

sendSuccess([
    'exam_id'   => $examId,
    'is_closed' => (int) $row['is_closed'] === 1,
    'closed_at' => $row['closed_at'],
    'status'    => $row['status'],
]);
