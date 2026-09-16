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

$single = $input['user_id'] ?? null;
$ids = [];
if ($single !== null) {
    $ids[] = (int) $single;
}
if (!empty($input['user_ids']) && is_array($input['user_ids'])) {
    foreach ($input['user_ids'] as $id) {
        $ids[] = (int) $id;
    }
}
$ids = array_values(array_unique(array_filter($ids)));
if (empty($ids)) {
    sendError('No users selected.', 'MISSING_FIELDS', 422);
}

$status = strtoupper(trim($input['status'] ?? ''));
if (!in_array($status, ['ACTIVE', 'INACTIVE', 'BANNED'], true)) {
    sendError('Invalid status. Use ACTIVE, INACTIVE or BANNED.', 'INVALID_STATUS', 422);
}

$myId = (int) $admin['user_id'];
$ids = array_values(array_filter($ids, function ($id) use ($myId) {
    return $id !== $myId;
}));
if (empty($ids)) {
    sendError('You cannot change your own account status.', 'SELF_OPERATION', 403);
}

try {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    if ($status === 'ACTIVE') {
        $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE user_id IN ($placeholders) AND status = 'PENDING'");
        $check->execute($ids);
        if ((int) $check->fetchColumn() > 0) {
            sendError('Email verification is required before a pending account can be activated.', 'EMAIL_NOT_VERIFIED', 409);
        }
    }
    $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE user_id IN ($placeholders)");
    $stmt->execute(array_merge([$status], $ids));
    $affected = $stmt->rowCount();

    // Suspend/ban must take effect immediately: drop the user's live sessions
    // so existing tokens stop authenticating until the account is re-activated.
    $kill = $pdo->prepare("DELETE FROM sessions WHERE user_id IN ($placeholders)");
    $kill->execute($ids);

    $log = $pdo->prepare('INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)');
    $log->execute([$myId, 'USER_STATUS', "Set status '$status' on $affected user(s)"]);

    sendSuccess(['updated' => $affected]);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
