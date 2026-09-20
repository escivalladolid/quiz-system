<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/validation.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('This endpoint only accepts POST requests.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo = getDbConnection();
$user = requireRole($pdo, ['STUDENT', 'TEACHER']);
$input = getJsonInput();
requireFields($input, ['current_password', 'new_password']);

$currentPassword = (string) $input['current_password'];
$logoutAllDevices = ($input['logout_all_devices'] ?? false) === true;
$newPassword = (string) $input['new_password'];
if (($err = validatePassword($newPassword)) !== null) {
    sendError($err, 'WEAK_PASSWORD', 422);
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ? AND status = \'ACTIVE\' FOR UPDATE');
    $stmt->execute([(int) $user['user_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
        $pdo->rollBack();
        sendError('Current password is incorrect.', 'UNAUTHORIZED', 401);
    }
    if (password_verify($newPassword, $row['password_hash'])) {
        $pdo->rollBack();
        sendError('Your new password must be different from the current password.', 'PASSWORD_UNCHANGED', 422);
    }

    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
    $stmt->execute([$newHash, (int) $user['user_id']]);

    // A password change invalidates any outstanding unauthenticated reset code.
    $stmt = $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?');
    $stmt->execute([(int) $user['user_id']]);

    $stmt = $pdo->prepare('INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)');
    $stmt->execute([(int) $user['user_id'], 'PASSWORD_CHANGED', 'Password changed from an authenticated session.']);

    if ($logoutAllDevices) {
        $stmt = $pdo->prepare('DELETE FROM sessions WHERE user_id = ?');
        $stmt->execute([(int) $user['user_id']]);
    }
    $pdo->commit();
    sendSuccess(['message' => 'Password changed successfully.', 'logged_out_all_devices' => $logoutAllDevices]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('QuizSystem password change error: ' . $e->getMessage());
    sendError('Something went wrong while changing the password. Please try again.', 'SERVER_ERROR', 500);
}
