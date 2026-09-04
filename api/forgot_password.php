<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/validation.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('This endpoint only accepts POST requests.', 'METHOD_NOT_ALLOWED', 405);
}

$input = getJsonInput();
requireFields($input, ['email']);
$email = trim($input['email']);

$emailError = validateEmail($email);
if ($emailError !== null) {
    sendError($emailError, 'INVALID_EMAIL', 422);
}

$pdo = getDbConnection();
$genericMessage = 'If that email is registered, a reset code has been sent.';

try {
    $stmt = $pdo->prepare('SELECT user_id, status FROM users WHERE email = :email');
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if ($user && $user['status'] === 'ACTIVE') {
        $resetToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

        $insertStmt = $pdo->prepare(
            'INSERT INTO password_resets (user_id, reset_token, expires_at) VALUES (:user_id, :reset_token, :expires_at)'
        );
        $insertStmt->execute([
            'user_id' => $user['user_id'],
            'reset_token' => $resetToken,
            'expires_at' => $expiresAt,
        ]);

        // Log the reset token server-side for this capstone's manual reset
        // flow, but do NOT return it in the API response. Returning it would
        // hand any caller (who knows the victim's email) the token needed to
        // take over the account.
        error_log('QuizSystem password reset user_id=' . $user['user_id'] . ' token=' . $resetToken . ' expires=' . $expiresAt);

        sendSuccess([
            'message' => $genericMessage,
            'expires_at' => $expiresAt,
        ]);
    }

    sendSuccess(['message' => $genericMessage]);

} catch (PDOException $e) {
    sendError('Something went wrong while processing your request.', 'SERVER_ERROR', 500);
}