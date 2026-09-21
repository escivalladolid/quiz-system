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
$input = getJsonInput();
$notificationId = filter_var($input['notification_id'] ?? null, FILTER_VALIDATE_INT);
if ($notificationId === false || $notificationId < 1) {
    sendError('A valid notification_id is required.', 'MISSING_FIELDS', 422);
}

try {
    if (!markNotificationRead($pdo, (int) $user['user_id'], (int) $notificationId)) {
        sendError('Notification not found.', 'NOT_FOUND', 404);
    }
    sendSuccess([
        'notification_id' => (int) $notificationId,
        'unread_count' => countUnreadNotifications($pdo, (int) $user['user_id']),
    ]);
} catch (PDOException $e) {
    error_log('Mark notification read error: ' . $e->getMessage());
    sendError('Unable to update notification state right now.', 'DB_ERROR', 500);
}
