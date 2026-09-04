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

if (!$input || !isset($input['question_id'])) {
    requireFields($input ?? [], ['question_id']);
}

try {
    $stmt = $pdo->prepare("SELECT q.question_id FROM questions q JOIN exams e ON q.exam_id=e.exam_id JOIN classes c ON e.class_id=c.class_id WHERE q.question_id=? AND c.teacher_id=?");
    $stmt->execute([$input['question_id'], $teacher_id]);
    if (!$stmt->fetch()) {
        sendError('Question not found or not authorized.', 'NOT_FOUND', 404);
    }

    $stmt = $pdo->prepare("DELETE FROM questions WHERE question_id=?");
    $stmt->execute([$input['question_id']]);

    sendSuccess(['message' => 'Question deleted successfully']);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
