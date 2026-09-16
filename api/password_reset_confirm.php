<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/validation.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('This endpoint only accepts POST requests.', 'METHOD_NOT_ALLOWED', 405);
}

$input = getJsonInput();
requireFields($input, ['reset_token', 'new_password']);

$resetTokenInput = trim((string) $input['reset_token']);
$newPassword = $input['new_password'];

if (preg_match('/^[A-HJ-NP-Z2-9]{8}$/i', $resetTokenInput)) {
    $resetToken = strtoupper($resetTokenInput);
} elseif (preg_match('/^[a-f0-9]{32}$/i', $resetTokenInput)) {
    // Accept reset codes issued before the short-code change while they remain valid.
    $resetToken = strtolower($resetTokenInput);
} else {
    sendError('Invalid or expired reset code.', 'INVALID_RESET_TOKEN', 422);
}

if (($pwError = validatePassword($newPassword)) !== null) {
    sendError($pwError, 'WEAK_PASSWORD', 422);
}

$pdo = getDbConnection();
$tokenHash = hash('sha256', $resetToken);

try {
    // Tokens are stored hashed; join with users so we can enforce ACTIVE and
    // reject soft-invalid (INACTIVE/BANNED) accounts on reset.
    $stmt = $pdo->prepare(
        'SELECT pr.user_id, u.status, u.username
         FROM password_resets pr
         JOIN users u ON u.user_id = pr.user_id
         WHERE pr.reset_token = :token_hash
           AND pr.expires_at > NOW()'
    );
    $stmt->execute(['token_hash' => $tokenHash]);
    $row = $stmt->fetch();

    if (!$row) {
        sendError('Invalid or expired reset code.', 'INVALID_RESET_TOKEN', 422);
    }

    if ($row['status'] !== 'ACTIVE') {
        sendError('This account is not active. Contact your administrator.', 'ACCOUNT_INACTIVE', 403);
    }

    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);

    $pdo->beginTransaction();

    $updateStmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE user_id = :user_id');
    $updateStmt->execute(['hash' => $newHash, 'user_id' => $row['user_id']]);

    // The reset code is single-use: remove it now that it has been consumed.
    $deleteStmt = $pdo->prepare('DELETE FROM password_resets WHERE user_id = :user_id');
    $deleteStmt->execute(['user_id' => $row['user_id']]);

    // After a password reset every existing session is invalidated, so a
    // leaked/forgotten device cannot keep acting on the old credentials.
    $sessionsStmt = $pdo->prepare('DELETE FROM sessions WHERE user_id = :user_id');
    $sessionsStmt->execute(['user_id' => $row['user_id']]);

    $pdo->commit();

    sendSuccess(['message' => 'Password reset successfully. You can now log in with your new password.']);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sendError('Something went wrong while processing your request.', 'SERVER_ERROR', 500);
}
