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

if (!$input || !isset($input['class_id'])) {
    requireFields($input ?? [], ['class_id']);
}

try {
    $stmt = $pdo->prepare("SELECT class_id FROM classes WHERE class_id=? AND teacher_id=?");
    $stmt->execute([$input['class_id'], $teacher_id]);
    if (!$stmt->fetch()) {
        sendError('Class not found.', 'NOT_FOUND', 404);
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT exam_id FROM exams WHERE class_id=?");
    $stmt->execute([$input['class_id']]);
    $exam_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if ($exam_ids) {
        $placeholders = str_repeat('?,', count($exam_ids) - 1) . '?';

        $stmt = $pdo->prepare("DELETE FROM questions WHERE exam_id IN ($placeholders)");
        $stmt->execute($exam_ids);

        $stmt = $pdo->prepare("DELETE FROM exam_submissions WHERE exam_id IN ($placeholders)");
        $stmt->execute($exam_ids);

        $stmt = $pdo->prepare("DELETE FROM exams WHERE class_id=?");
        $stmt->execute([$input['class_id']]);
    }

    $stmt = $pdo->prepare("DELETE FROM enrollments WHERE class_id=?");
    $stmt->execute([$input['class_id']]);

    $stmt = $pdo->prepare("DELETE FROM classes WHERE class_id=?");
    $stmt->execute([$input['class_id']]);

    $pdo->commit();

    sendSuccess(['message' => 'Class deleted successfully']);
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
