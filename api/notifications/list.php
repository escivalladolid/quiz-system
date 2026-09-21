<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/notifications.php';

header('Content-Type: application/json');

$pdo = getDbConnection();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

$user = getAuthenticatedUser($pdo);
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
$unreadOnly = filter_var($_GET['unread_only'] ?? false, FILTER_VALIDATE_BOOLEAN);

try {
    sendSuccess([
        'notifications' => notificationListForUser($pdo, (int) $user['user_id'], $limit, $unreadOnly),
        'unread_count' => countUnreadNotifications($pdo, (int) $user['user_id']),
    ]);
} catch (PDOException $e) {
    error_log('Notification list error: ' . $e->getMessage());
    sendError('Unable to load notifications right now.', 'DB_ERROR', 500);
}
