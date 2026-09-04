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

try {
    $stmt = $pdo->prepare("SELECT e.exam_id FROM exams e JOIN classes c ON e.class_id=c.class_id WHERE e.exam_id=? AND c.teacher_id=?");
    $stmt->execute([$input['exam_id'], $teacher_id]);
    if (!$stmt->fetch()) {
        sendError('Exam not found or not authorized.', 'NOT_FOUND', 404);
    }

    $stmt = $pdo->prepare("UPDATE exams SET max_exit_attempts=? WHERE exam_id=?");
    $stmt->execute([$input['max_exit_attempts'], $input['exam_id']]);

    sendSuccess(['message' => 'Exit attempts updated successfully']);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
