<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo = getDbConnection();
$admin = requireRole($pdo, ['ADMIN']);

$input = getJsonInput();
requireFields($input, ['class_id', 'status']);

$class_id = (int) $input['class_id'];
$status = strtoupper(trim($input['status']));

if (!in_array($status, ['ACTIVE', 'ARCHIVED'], true)) {
    sendError('Invalid status. Use ACTIVE or ARCHIVED.', 'INVALID_STATUS', 422);
}

try {
    $stmt = $pdo->prepare('SELECT class_code FROM classes WHERE class_id = ?');
    $stmt->execute([$class_id]);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$class) {
        sendError('Class not found.', 'NOT_FOUND', 404);
    }

    // Archiving does not delete enrollments or exams — it only hides the class.
    $stmt = $pdo->prepare('UPDATE classes SET status = ? WHERE class_id = ?');
    $stmt->execute([$status, $class_id]);

    $log = $pdo->prepare('INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)');
    $log->execute([$admin['user_id'], 'CLASS_STATUS', "Set class {$class['class_code']} to $status"]);

    sendSuccess(['class_id' => $class_id, 'status' => $status]);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}