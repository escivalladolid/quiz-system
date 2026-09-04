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

try {
    $updates = [];
    $params = [];

    if (isset($input['first_name'])) {
        $updates[] = 'first_name=?';
        $params[] = $input['first_name'];
    }
    if (isset($input['last_name'])) {
        $updates[] = 'last_name=?';
        $params[] = $input['last_name'];
    }
    if (isset($input['email'])) {
        $updates[] = 'email=?';
        $params[] = $input['email'];
    }

    if (empty($updates)) {
        sendError('No fields to update.', 'BAD_REQUEST', 400);
    }

    $params[] = $teacher_id;
    $stmt = $pdo->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE user_id=?");
    $stmt->execute($params);

    sendSuccess(['message' => 'Profile updated successfully']);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
