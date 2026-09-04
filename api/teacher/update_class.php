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

if (!$input) {
    sendError('Invalid JSON input.', 'BAD_REQUEST', 400);
}

if (!isset($input['class_id'])) {
    requireFields($input, ['class_id']);
}

try {
    $stmt = $pdo->prepare("SELECT class_id FROM classes WHERE class_id=? AND teacher_id=?");
    $stmt->execute([$input['class_id'], $teacher_id]);
    if (!$stmt->fetch()) {
        sendError('Class not found.', 'NOT_FOUND', 404);
    }

    $updates = [];
    $params = [];

    if (isset($input['subject_name'])) {
        $updates[] = 'subject_name=?';
        $params[] = $input['subject_name'];
    }
    if (isset($input['subject_code'])) {
        $updates[] = 'subject_code=?';
        $params[] = $input['subject_code'];
    }
    if (isset($input['block'])) {
        $updates[] = 'block=?';
        $params[] = $input['block'];
    }

    if (empty($updates)) {
        sendError('No fields to update.', 'BAD_REQUEST', 400);
    }

    $params[] = $input['class_id'];
    $stmt = $pdo->prepare("UPDATE classes SET " . implode(', ', $updates) . " WHERE class_id=?");
    $stmt->execute($params);

    sendSuccess(['message' => 'Class updated successfully']);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
