<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('This endpoint only accepts POST requests.', 'METHOD_NOT_ALLOWED', 405);
}

$input = getJsonInput();
requireFields($input, ['verification_token']);
$tokenInput = trim((string) $input['verification_token']);
if (preg_match('/^[A-HJ-NP-Z2-9]{8}$/i', $tokenInput)) {
    $token = strtoupper($tokenInput);
} elseif (preg_match('/^[a-f0-9]{32}$/i', $tokenInput)) {
    // Accept codes issued before the short-code change while they remain valid.
    $token = strtolower($tokenInput);
} else {
    sendError('The verification code is invalid or expired.', 'INVALID_VERIFICATION_TOKEN', 422);
}

$pdo = getDbConnection();

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'SELECT ev.verification_id, ev.user_id, u.first_name, u.last_name, u.email, u.status
         FROM email_verifications ev
         JOIN users u ON u.user_id = ev.user_id
         WHERE ev.token_hash = ? AND ev.expires_at > NOW() AND u.role_id IN (1, 2)
         FOR UPDATE'
    );
    $stmt->execute([hash('sha256', $token)]);
    $verification = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$verification || $verification['status'] !== 'PENDING') {
        if ($pdo->inTransaction()) $pdo->rollBack();
        sendError('The verification code is invalid or expired. Request a new code and try again.', 'INVALID_VERIFICATION_TOKEN', 422);
    }

    $stmt = $pdo->prepare("UPDATE users SET status = 'ACTIVE' WHERE user_id = ? AND status = 'PENDING'");
    $stmt->execute([(int) $verification['user_id']]);
    if ($stmt->rowCount() !== 1) {
        $pdo->rollBack();
        sendError('This account could not be activated. Please request a new code.', 'ACTIVATION_FAILED', 409);
    }

    $stmt = $pdo->prepare('DELETE FROM email_verifications WHERE user_id = ?');
    $stmt->execute([(int) $verification['user_id']]);

    $log = $pdo->prepare('INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)');
    $log->execute([(int) $verification['user_id'], 'EMAIL_VERIFIED', 'Registration email verified; account activated.']);
    $pdo->commit();

    sendSuccess([
        'user_id' => (int) $verification['user_id'],
        'email' => $verification['email'],
        'first_name' => $verification['first_name'],
        'last_name' => $verification['last_name'],
        'verified' => true,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('QuizSystem verification error: ' . $e->getMessage());
    sendError('Something went wrong while verifying the account. Please try again.', 'SERVER_ERROR', 500);
}
