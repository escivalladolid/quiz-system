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

$usernameGiven = isset($input['username']) ? trim($input['username']) : null;
$emailGiven = isset($input['email']) ? trim($input['email']) : null;

if (!$usernameGiven && !$emailGiven) {
    sendError('Provide either username or email to log in.', 'MISSING_FIELDS', 422);
}

$pdo = getDbConnection();

try {
    if ($usernameGiven) {
        $stmt = $pdo->prepare(
            'SELECT u.user_id, u.first_name, u.last_name, u.username, u.email, u.password_hash, u.status, r.role_name
             FROM users u JOIN roles r ON r.role_id = u.role_id
             WHERE u.username = :identifier'
        );
        $stmt->execute(['identifier' => $usernameGiven]);
    } else {
        $stmt = $pdo->prepare(
            'SELECT u.user_id, u.first_name, u.last_name, u.username, u.email, u.password_hash, u.status, r.role_name
             FROM users u JOIN roles r ON r.role_id = u.role_id
             WHERE u.email = :identifier'
        );
        $stmt->execute(['identifier' => $emailGiven]);
    }

    $user = $stmt->fetch();
    $invalidCredsMessage = 'Incorrect username/email or password.';

    if (!$user) {
        sendError($invalidCredsMessage, 'INVALID_CREDENTIALS', 401);
    }
    if ($user['status'] !== 'ACTIVE') {
        sendError('This account has not been activated yet. Please activate your account first.', 'NOT_ACTIVATED', 403);
    }
    if (!$user['password_hash'] || !password_verify($password, $user['password_hash'])) {
        sendError($invalidCredsMessage, 'INVALID_CREDENTIALS', 401);
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
    sendError('Something went wrong while logging in.', 'SERVER_ERROR', 500);
}