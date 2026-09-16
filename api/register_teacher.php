<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/validation.php';
require_once __DIR__ . '/../helpers/registration.php';
require_once __DIR__ . '/../helpers/email_tokens.php';
require_once __DIR__ . '/../helpers/mailer.php';
require_once __DIR__ . '/../helpers/system_alerts.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('This endpoint only accepts POST requests.', 'METHOD_NOT_ALLOWED', 405);
}

$input = getJsonInput();

$employeeNumber = isset($input['employee_number']) ? trim($input['employee_number']) : '';
$password = isset($input['password']) ? (string) $input['password'] : '';
$emailGiven = isset($input['email']) ? trim((string) $input['email']) : '';

if ($employeeNumber === '') {
    sendError('Employee number is required.', 'MISSING_FIELDS', 422);
}
if ($password === '') {
    sendError('Password is required.', 'MISSING_FIELDS', 422);
}
if ($emailGiven === '') {
    sendError('Email is required so we can verify your account.', 'MISSING_FIELDS', 422);
}
if (($err = validatePassword($password)) !== null) {
    sendError($err, 'INVALID_PASSWORD', 422);
}
if (($err = validateEmail($emailGiven)) !== null) {
    sendError($err, 'INVALID_EMAIL', 422);
}

$requestedUsername = trim((string) ($input['username'] ?? ''));
if ($requestedUsername !== '' && !preg_match('/^[A-Za-z][A-Za-z0-9_.]{2,29}$/D', $requestedUsername)) {
    sendError('Username must be 3–30 characters, start with a letter, and contain only letters, numbers, dots or underscores.', 'INVALID_USERNAME', 422);
}
$pdo = getDbConnection();

try {
    $stmt = $pdo->prepare('SELECT employee_number, full_name, department, email FROM teacher_roster WHERE employee_number = ?');
    $stmt->execute([$employeeNumber]);
    $roster = $stmt->fetch();

    if (!$roster) {
        sendError('Employee number not found in the employed list. Please verify with the Admin Office.', 'EMP_NOT_FOUND', 404);
    }

    $rosterEmail = trim((string) ($roster['email'] ?? ''));
    if ($rosterEmail !== '' && strcasecmp($rosterEmail, $emailGiven) !== 0) {
        sendError('The email does not match the official teacher roster. Please contact the Admin Office.', 'EMAIL_MISMATCH', 422);
    }

    $stmt = $pdo->prepare('SELECT user_id, status FROM users WHERE employee_number = ? AND role_id = 2');
    $stmt->execute([$employeeNumber]);
    $existing = $stmt->fetch();
    if ($existing) {
        if (($existing['status'] ?? '') === 'PENDING') {
            sendError('A pending account already exists. Check your email for the verification code or request a new one.', 'REGISTRATION_PENDING', 409);
        }
        sendError('An account already exists for this employee number. Please log in instead.', 'EMP_ALREADY_REGISTERED', 409);
    }

    $stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$emailGiven]);
    if ($stmt->fetch()) {
        sendError('That email address is already registered. Please use another email or reset the existing password.', 'EMAIL_ALREADY_REGISTERED', 409);
    }

    splitFullName($roster['full_name'], $first, $last);
    $username = $requestedUsername !== '' ? $requestedUsername : generateUniqueUsername($pdo, $first, $last);
    $usernameCheck = $pdo->prepare('SELECT user_id FROM users WHERE username = ? LIMIT 1');
    $usernameCheck->execute([$username]);
    if ($usernameCheck->fetch()) {
        sendError('That username is already taken. Please choose another.', 'USERNAME_TAKEN', 409);
    }
    $hash = password_hash($password, PASSWORD_BCRYPT);

    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT INTO users (first_name, last_name, username, email, password_hash,
                            student_id, employee_number, year_level, section, status, role_id)
         VALUES (?, ?, ?, ?, ?, NULL, ?, NULL, NULL, \'PENDING\', 2)'
    );
    $stmt->execute([$first, $last, $username, $emailGiven, $hash, $employeeNumber]);
    $user_id = (int) $pdo->lastInsertId();

    $verificationToken = generateEmailCode();
    $expiresAt = date('Y-m-d H:i:s', time() + 3600);
    $stmt = $pdo->prepare(
        'INSERT INTO email_verifications (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
    );
    $stmt->execute([$user_id, hash('sha256', $verificationToken), $expiresAt]);

    if (!sendRegistrationVerificationEmail($emailGiven, $verificationToken, $expiresAt)) {
        $pdo->rollBack();
        recordSystemAlert(
            $pdo,
            'VERIFICATION_EMAIL_FAILURE',
            'Verification email delivery failed during teacher registration.',
            null,
            $employeeNumber,
            [
                'operation' => 'teacher_registration',
                'provider' => trim((string) (getenv('EMAIL_PROVIDER') ?: 'smtp')),
                'email_status' => 503,
            ]
        );
        sendError('We could not send the verification email right now. Please try again later.', 'EMAIL_DELIVERY_UNAVAILABLE', 503);
    }

    $log = $pdo->prepare('INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)');
    $log->execute([$user_id, 'ACCOUNT_REGISTERED_PENDING', 'Teacher registered via official roster; email verification required.']);
    $pdo->commit();

    sendSuccess([
        'user_id'    => $user_id,
        'role'       => 'TEACHER',
        'first_name' => $first,
        'last_name'  => $last,
        'username'   => $username,
        'email'      => $emailGiven,
        'verification_required' => true,
        'expires_at' => $expiresAt,
    ], 201);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1062) {
        sendError('Username, email or roster ID is already registered. Please check your details.', 'ACCOUNT_ALREADY_EXISTS', 409);
    }
    error_log('Registration failed: ' . get_class($e));
    sendError('Something went wrong while registering. Please try again.', 'SERVER_ERROR', 500);
}
