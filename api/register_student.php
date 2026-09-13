<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/validation.php';
require_once __DIR__ . '/../helpers/registration.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('This endpoint only accepts POST requests.', 'METHOD_NOT_ALLOWED', 405);
}

$input = getJsonInput();

$lrn = isset($input['lrn']) ? trim($input['lrn']) : '';
$password = isset($input['password']) ? (string) $input['password'] : '';
$emailGiven = isset($input['email']) ? trim($input['email']) : null;

if ($lrn === '') {
    sendError('Student number (LRN) is required.', 'MISSING_FIELDS', 422);
}
if ($password === '') {
    sendError('Password is required.', 'MISSING_FIELDS', 422);
}
if (($err = validatePassword($password)) !== null) {
    sendError($err, 'INVALID_PASSWORD', 422);
}
if ($emailGiven !== null && $emailGiven !== '') {
    if (($err = validateEmail($emailGiven)) !== null) {
        sendError($err, 'INVALID_EMAIL', 422);
    }
}

$pdo = getDbConnection();

try {
    $stmt = $pdo->prepare('SELECT lrn, full_name, program FROM student_roster WHERE lrn = ?');
    $stmt->execute([$lrn]);
    $roster = $stmt->fetch();

    if (!$roster) {
        sendError('Student number not found in the enrolled list. Please verify with the Admin Office.', 'LRN_NOT_FOUND', 404);
    }

    $stmt = $pdo->prepare('SELECT user_id FROM users WHERE student_id = ? AND role_id = 1');
    $stmt->execute([$lrn]);
    if ($stmt->fetch()) {
        sendError('An account already exists for this student number. Please log in instead.', 'LRN_ALREADY_REGISTERED', 409);
    }

    splitFullName($roster['full_name'], $first, $last);
    $username = generateUniqueUsername($pdo, $first, $last);
    $email = ($emailGiven !== null && $emailGiven !== '') ? $emailGiven : $username . '@rmc.edu.ph';

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $section = $roster['program'] !== null ? substr(trim($roster['program']), 0, 50) : null;

    $stmt = $pdo->prepare(
        'INSERT INTO users (first_name, last_name, username, email, password_hash,
                            student_id, year_level, section, status, role_id)
         VALUES (?, ?, ?, ?, ?, ?, NULL, ?, \'ACTIVE\', 1)'
    );
    $stmt->execute([$first, $last, $username, $email, $hash, $lrn, $section]);
    $user_id = (int) $pdo->lastInsertId();

    $log = $pdo->prepare('INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)');
    $log->execute([$user_id, 'ACCOUNT_REGISTERED', 'Student registered via official roster.']);

    sendSuccess([
        'user_id'    => $user_id,
        'role'       => 'STUDENT',
        'first_name' => $first,
        'last_name'  => $last,
        'username'   => $username,
        'email'      => $email,
    ], 201);

} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('Something went wrong while registering. Please try again.', 'SERVER_ERROR', 500);
}