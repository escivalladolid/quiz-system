<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/notifications.php';

header('Content-Type: application/json');

$pdo = getDbConnection();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

$user = getAuthenticatedUser($pdo);
try {
    $marked = markAllNotificationsRead($pdo, (int) $user['user_id']);
    sendSuccess([
        'marked_count' => $marked,
        'unread_count' => countUnreadNotifications($pdo, (int) $user['user_id']),
    ]);
} catch (PDOException $e) {
    error_log('Mark all notifications read error: ' . $e->getMessage());
    sendError('Unable to update notification state right now.', 'DB_ERROR', 500);
}
