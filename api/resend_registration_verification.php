<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/validation.php';
require_once __DIR__ . '/../helpers/email_tokens.php';
require_once __DIR__ . '/../helpers/mailer.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('This endpoint only accepts POST requests.', 'METHOD_NOT_ALLOWED', 405);
}

$input = getJsonInput();
requireFields($input, ['email']);
$email = trim((string) $input['email']);
if (($err = validateEmail($email)) !== null) {
    sendError($err, 'INVALID_EMAIL', 422);
}

$pdo = getDbConnection();

try {
    $stmt = $pdo->prepare(
        "SELECT user_id, first_name, status
         FROM users
         WHERE email = ? AND role_id IN (1, 2)
         LIMIT 1"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Keep the response generic when the address is unknown or already active.
    if (!$user || $user['status'] !== 'PENDING') {
        sendSuccess(['verification_sent' => false]);
    }

    $stmt = $pdo->prepare(
        'SELECT created_at FROM email_verifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1'
    );
    $stmt->execute([(int) $user['user_id']]);
    $previous = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($previous && strtotime($previous['created_at']) > time() - 60) {
        sendError('Please wait a minute before requesting another verification code.', 'RATE_LIMITED', 429);
    }

    $verificationToken = generateEmailCode();
    $expiresAt = date('Y-m-d H:i:s', time() + 3600);
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('DELETE FROM email_verifications WHERE user_id = ?');
    $stmt->execute([(int) $user['user_id']]);
    $stmt = $pdo->prepare(
        'INSERT INTO email_verifications (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
    );
    $stmt->execute([(int) $user['user_id'], hash('sha256', $verificationToken), $expiresAt]);

    if (!sendRegistrationVerificationEmail($email, $verificationToken, $expiresAt)) {
        $pdo->rollBack();
        sendError('We could not send the verification email right now. Please try again later.', 'EMAIL_DELIVERY_UNAVAILABLE', 503);
    }
    $pdo->commit();

    sendSuccess(['verification_sent' => true, 'expires_at' => $expiresAt]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('QuizSystem verification resend error: ' . $e->getMessage());
    sendError('Something went wrong while requesting a new code. Please try again.', 'SERVER_ERROR', 500);
}
