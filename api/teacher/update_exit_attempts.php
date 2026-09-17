<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';

header('Content-Type: application/json');

$pdo = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

$teacher = requireRole($pdo, ['TEACHER']);
$teacher_id = $teacher['user_id'];
$input = getJsonInput();

if (!$input || !isset($input['exam_id']) || !isset($input['max_exit_attempts'])) {
    requireFields($input ?? [], ['exam_id', 'max_exit_attempts']);
}

$maxExitAttempts = filter_var($input['max_exit_attempts'], FILTER_VALIDATE_INT);
if ($maxExitAttempts === false || $maxExitAttempts < 1 || $maxExitAttempts > 10) {
    sendError('Maximum exit attempts must be between 1 and 10.', 'BAD_REQUEST', 422);
}

try {
    $stmt = $pdo->prepare("SELECT e.exam_id FROM exams e JOIN classes c ON e.class_id=c.class_id WHERE e.exam_id=? AND c.teacher_id=?");
    $stmt->execute([$input['exam_id'], $teacher_id]);
    if (!$stmt->fetch()) {
        sendError('Exam not found or not authorized.', 'NOT_FOUND', 404);
    }

    $stmt = $pdo->prepare("UPDATE exams SET max_exit_attempts=? WHERE exam_id=?");
    $stmt->execute([$maxExitAttempts, $input['exam_id']]);

    sendSuccess([
        'message' => 'Exit attempts updated successfully',
        'max_exit_attempts' => $maxExitAttempts,
    ]);
} catch (PDOException $e) {
    sendError('Database error: ' . $e->getMessage(), 'DB_ERROR', 500);
}
