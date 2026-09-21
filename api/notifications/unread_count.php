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
try {
    sendSuccess(['unread_count' => countUnreadNotifications($pdo, (int) $user['user_id'])]);
} catch (PDOException $e) {
    error_log('Unread notification count error: ' . $e->getMessage());
    sendError('Unable to load notification count right now.', 'DB_ERROR', 500);
}
