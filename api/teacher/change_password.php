<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/validation.php';

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

requireFields($input, ['current_password', 'new_password']);

$pwError = validatePassword($input['new_password']);
if ($pwError) {
    sendError($pwError, 'WEAK_PASSWORD', 422);
}

try {
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id=?");
    $stmt->execute([$teacher_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($input['current_password'], $user['password_hash'])) {
        sendError('Current password is incorrect.', 'UNAUTHORIZED', 401);
    }

    $new_hash = password_hash($input['new_password'], PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("UPDATE users SET password_hash=? WHERE user_id=?");
    $stmt->execute([$new_hash, $teacher_id]);

    sendSuccess(['message' => 'Password changed successfully']);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
