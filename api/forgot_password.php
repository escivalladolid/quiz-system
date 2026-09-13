<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/validation.php';
require_once __DIR__ . '/../helpers/mailer.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('This endpoint only accepts POST requests.', 'METHOD_NOT_ALLOWED', 405);
}

$input = getJsonInput();

// Accept either the registered email address or the username (logins use
// username). Password resets only require one of the two.
$identifier = trim($input['email'] ?? '');
if ($identifier === '') {
    $identifier = trim($input['username'] ?? '');
}
if ($identifier === '') {
    sendError('Missing required field(s): email or username', 'MISSING_FIELDS', 422);
}

$byEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false;
if ($byEmail && validateEmail($identifier) !== null) {
    sendError(validateEmail($identifier), 'INVALID_EMAIL', 422);
}

$pdo = getDbConnection();
$genericMessage = 'If that account exists, a reset code has been sent to the registered email.';

try {
    if ($byEmail) {
        $stmt = $pdo->prepare('SELECT user_id, email, status FROM users WHERE email = :identifier');
    } else {
        $stmt = $pdo->prepare('SELECT user_id, email, status FROM users WHERE username = :identifier');
    }
    $stmt->execute(['identifier' => $identifier]);
    $user = $stmt->fetch();

    if ($user && $user['status'] === 'ACTIVE') {
        $resetToken = bin2hex(random_bytes(16));

        // Hash the token before storing so a leaked DB dump cannot be used
        // directly. RESET tokens are short-lived (1h) and treated as single-use
        // by password_reset_confirm.php.
        $tokenHash = hash('sha256', $resetToken);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

        // One active reset per user: invalidate any older outstanding tokens.
        $deleteStmt = $pdo->prepare('DELETE FROM password_resets WHERE user_id = :user_id');
        $deleteStmt->execute(['user_id' => $user['user_id']]);

        $insertStmt = $pdo->prepare(
            'INSERT INTO password_resets (user_id, reset_token, expires_at) VALUES (:user_id, :reset_token, :expires_at)'
        );
        $insertStmt->execute([
            'user_id' => $user['user_id'],
            'reset_token' => $tokenHash,
            'expires_at' => $expiresAt,
        ]);

        // SMTP every time it is configured; otherwise log the code server-side
        // so this capstone's manual/demo reset flow can still be exercised
        // without a mail server.
        $sent = sendPasswordResetEmail($user['email'], $resetToken, $expiresAt);
        if (!$sent) {
            error_log(
                'QuizSystem password reset (no SMTP): user_id=' . $user['user_id']
                . ' code=' . $resetToken . ' expires=' . $expiresAt
            );
        }

        sendSuccess([
            'message' => $genericMessage,
            'expires_at' => $expiresAt,
        ]);
    }

    sendSuccess(['message' => $genericMessage]);

} catch (PDOException $e) {
    sendError('Something went wrong while processing your request.', 'SERVER_ERROR', 500);
}