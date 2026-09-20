<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('This endpoint only accepts POST requests.', 'METHOD_NOT_ALLOWED', 405);
}

$input = getJsonInput();

if (empty($input['password'])) {
    sendError('Password is required.', 'MISSING_FIELDS', 422);
}
$password = $input['password'];

// Accept usernames, email addresses and official roster identifiers.
$identifier = isset($input['identifier']) ? trim((string) $input['identifier']) : '';
if ($identifier === '') {
    $identifier = isset($input['username']) ? trim((string) $input['username']) : '';
}
if ($identifier === '') {
    $identifier = isset($input['email']) ? trim((string) $input['email']) : '';
}

if ($identifier === '') {
    sendError('Provide your Student No., Employee No., username, or email to log in.', 'MISSING_FIELDS', 422);
}

$pdo = getDbConnection();

try {
    $stmt = $pdo->prepare(
        'SELECT u.user_id, u.first_name, u.last_name, u.username, u.email,
                u.password_hash, u.status, r.role_name
         FROM users u JOIN roles r ON r.role_id = u.role_id
         WHERE u.username = ?
            OR u.email = ?
            OR u.student_id = ?
            OR u.employee_number = ?
'
    );
    $stmt->execute([$identifier, $identifier, $identifier, $identifier]);

    $matches = $stmt->fetchAll();
    $user = null;
    foreach ($matches as $candidate) {
        if (!empty($candidate['password_hash']) && password_verify($password, $candidate['password_hash'])) {
            if ($user !== null) {
                sendError('This login ID is ambiguous. Please use your email address.', 'AMBIGUOUS_IDENTIFIER', 409);
            }
            $user = $candidate;
        }
    }
    $invalidCredsMessage = 'Incorrect login ID or password.';

    if (!$user) {
        sendError($invalidCredsMessage, 'INVALID_CREDENTIALS', 401);
    }
    if ($user['status'] === 'PENDING') {
        sendError('Please verify your email with the code we sent before logging in.', 'NOT_ACTIVATED', 403);
    }
    if ($user['status'] !== 'ACTIVE') {
        sendError('This account is inactive. Please contact the Admin Office.', 'ACCOUNT_INACTIVE', 403);
    }
    if (!$user['password_hash'] || !password_verify($password, $user['password_hash'])) {
        sendError($invalidCredsMessage, 'INVALID_CREDENTIALS', 401);
    }

    /*
     * Keep one active mobile/web session per account. The user row lock makes
     * the check and insert atomic even when two devices submit credentials at
     * the same time. Expired rows are harmless and are removed first so an
     * old session cannot block a new login.
     */
    $pdo->beginTransaction();
    $userLockStmt = $pdo->prepare('SELECT user_id FROM users WHERE user_id = ? FOR UPDATE');
    $userLockStmt->execute([(int) $user['user_id']]);

    $cleanupStmt = $pdo->prepare('DELETE FROM sessions WHERE user_id = ? AND expires_at <= NOW()');
    $cleanupStmt->execute([(int) $user['user_id']]);

    $activeSessionStmt = $pdo->prepare(
        'SELECT session_id, created_at, expires_at
         FROM sessions
         WHERE user_id = ? AND expires_at > NOW()
         ORDER BY created_at DESC
         LIMIT 1
         FOR UPDATE'
    );
    $activeSessionStmt->execute([(int) $user['user_id']]);
    if ($activeSessionStmt->fetch()) {
        $pdo->rollBack();
        sendError(
            'This account is already logged in on another device. Log out there first or ask an administrator to end the active session.',
            'ACTIVE_SESSION',
            409
        );
    }

    $sessionToken = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));

    $sessionStmt = $pdo->prepare(
        'INSERT INTO sessions (user_id, token, expires_at) VALUES (:user_id, :token, :expires_at)'
    );
    $sessionStmt->execute([
        'user_id' => $user['user_id'],
        'token' => $sessionToken,
        'expires_at' => $expiresAt,
    ]);

    $logStmt = $pdo->prepare(
        'INSERT INTO activity_logs (user_id, action, description) VALUES (:user_id, :action, :details)'
    );
    $logStmt->execute([
        'user_id' => $user['user_id'],
        'action' => 'LOGIN',
        'details' => 'Logged in as ' . $user['role_name'] . '.',
    ]);
    $pdo->commit();

    sendSuccess([
        'user_id' => (int)$user['user_id'],
        'role' => $user['role_name'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'username' => $user['username'],
        'email' => $user['email'],
        'session_token' => $sessionToken,
        'expires_at' => $expiresAt,
    ]);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sendError('Something went wrong while logging in.', 'SERVER_ERROR', 500);
}
