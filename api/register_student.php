<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/validation.php';
require_once __DIR__ . '/../helpers/registration.php';
require_once __DIR__ . '/../helpers/mailer.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('This endpoint only accepts POST requests.', 'METHOD_NOT_ALLOWED', 405);
}

$input = getJsonInput();

$lrn = isset($input['lrn']) ? trim($input['lrn']) : '';
$password = isset($input['password']) ? (string) $input['password'] : '';
$emailGiven = isset($input['email']) ? trim((string) $input['email']) : '';

if ($lrn === '') {
    sendError('Student No. is required.', 'MISSING_FIELDS', 422);
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

$pdo = getDbConnection();

try {
    $stmt = $pdo->prepare('SELECT lrn, full_name, program, email FROM student_roster WHERE lrn = ?');
    $stmt->execute([$lrn]);
    $roster = $stmt->fetch();

    if (!$roster) {
        sendError('Student number not found in the enrolled list. Please verify with the Admin Office.', 'LRN_NOT_FOUND', 404);
    }

    $rosterEmail = trim((string) ($roster['email'] ?? ''));
    if ($rosterEmail !== '' && strcasecmp($rosterEmail, $emailGiven) !== 0) {
        sendError('The email does not match the official student roster. Please contact the Admin Office.', 'EMAIL_MISMATCH', 422);
    }

    $stmt = $pdo->prepare('SELECT user_id, status FROM users WHERE student_id = ? AND role_id = 1');
    $stmt->execute([$lrn]);
    $existing = $stmt->fetch();
    if ($existing) {
        if (($existing['status'] ?? '') === 'PENDING') {
            sendError('A pending account already exists. Check your email for the verification code or request a new one.', 'REGISTRATION_PENDING', 409);
        }
        sendError('An account already exists for this student number. Please log in instead.', 'LRN_ALREADY_REGISTERED', 409);
    }

    $stmt = $pdo->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$emailGiven]);
    if ($stmt->fetch()) {
        sendError('That email address is already registered. Please use another email or reset the existing password.', 'EMAIL_ALREADY_REGISTERED', 409);
    }

    splitFullName($roster['full_name'], $first, $last);
    $username = generateUniqueUsername($pdo, $first, $last);
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $section = $roster['program'] !== null ? substr(trim($roster['program']), 0, 50) : null;

    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT INTO users (first_name, last_name, username, email, password_hash,
                            student_id, year_level, section, status, role_id)
         VALUES (?, ?, ?, ?, ?, ?, NULL, ?, \'PENDING\', 1)'
    );
    $stmt->execute([$first, $last, $username, $emailGiven, $hash, $lrn, $section]);
    $user_id = (int) $pdo->lastInsertId();

    $verificationToken = bin2hex(random_bytes(16));
    $expiresAt = date('Y-m-d H:i:s', time() + 3600);
    $stmt = $pdo->prepare(
        'INSERT INTO email_verifications (user_id, token_hash, expires_at) VALUES (?, ?, ?)'
    );
    $stmt->execute([$user_id, hash('sha256', $verificationToken), $expiresAt]);

    if (!sendRegistrationVerificationEmail($emailGiven, $verificationToken, $expiresAt)) {
        $pdo->rollBack();
        sendError('We could not send the verification email right now. Please try again later.', 'EMAIL_DELIVERY_UNAVAILABLE', 503);
    }

    $log = $pdo->prepare('INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)');
    $log->execute([$user_id, 'ACCOUNT_REGISTERED_PENDING', 'Student registered via official roster; email verification required.']);
    $pdo->commit();

    sendSuccess([
        'user_id'    => $user_id,
        'role'       => 'STUDENT',
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
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('Something went wrong while registering. Please try again.', 'SERVER_ERROR', 500);
}
