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
$input = json_decode(file_get_contents('php://input'), true) ?: [];

// The admin's own API session must never be terminated here.
$headers = getallheaders();
$ownToken = '';
if (preg_match('/Bearer\s+(.+)/', $headers['Authorization'] ?? $headers['authorization'] ?? '', $m)) {
    $ownToken = $m[1];
}

// Accept either a single session_id or a set of user_ids (kill all their sessions).
$sessionIds = isset($input['session_id']) ? [(string) $input['session_id']] : [];
$userIds = $input['user_ids'] ?? [];

if (!empty($input['user_id'])) {
    $userIds[] = (int) $input['user_id'];
}
$userIds = array_values(array_unique(array_map('intval', $userIds)));

if (empty($sessionIds) && empty($userIds)) {
    sendError('Provide a session_id or user_ids to end sessions.', 'VALIDATION_ERROR', 422);
}

try {
    $pdo->beginTransaction();
    $deleted = 0;
    $killedUsers = [];

    if (!empty($sessionIds)) {
        $sessionIds = array_values(array_filter($sessionIds, function ($sid) use ($ownToken) {
            return $sid !== $ownToken;
        }));
        if (!empty($sessionIds)) {
            $ph = implode(',', array_fill(0, count($sessionIds), '?'));
            $stmt = $pdo->prepare("DELETE FROM sessions WHERE session_id IN ($ph) AND token != ?");
            $stmt->execute(array_merge($sessionIds, [$ownToken]));
            $deleted = $stmt->rowCount();
        }
    }

    if (!empty($userIds)) {
        $ph = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $pdo->prepare("DELETE FROM sessions WHERE user_id IN ($ph) AND token != ?");
        $stmt->execute(array_merge($userIds, [$ownToken]));
        $deleted += $stmt->rowCount();
        $killedUsers = $userIds;
    }

    $pdo->prepare(
        "INSERT INTO activity_logs (user_id, action, description, created_at)
         VALUES (:uid, 'SESSION_KILL', :desc, NOW())"
    )->execute([
        'uid' => $admin['user_id'],
        'desc' => trim(($sessionIds ? 'Ended session(s): ' . implode(',', array_slice($sessionIds, 0, 5)) : '')
            . ($killedUsers ? ($sessionIds ? '; ' : '') . 'Ended all sessions for user id(s): ' . implode(',', $killedUsers) : '')
            . " ($deleted row(s))"),
    ]);

    $pdo->commit();
    sendSuccess(['deleted' => $deleted]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}