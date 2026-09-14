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

$employeeNumber = isset($input['employee_number']) ? trim($input['employee_number']) : '';
$password = isset($input['password']) ? (string) $input['password'] : '';
$emailGiven = isset($input['email']) ? trim($input['email']) : null;

if ($employeeNumber === '') {
    sendError('Employee number is required.', 'MISSING_FIELDS', 422);
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
    $stmt = $pdo->prepare('SELECT employee_number, full_name, department FROM teacher_roster WHERE employee_number = ?');
    $stmt->execute([$employeeNumber]);
    $roster = $stmt->fetch();

    if (!$roster) {
        sendError('Employee number not found in the employed list. Please verify with the Admin Office.', 'EMP_NOT_FOUND', 404);
    }

    $stmt = $pdo->prepare('SELECT user_id FROM users WHERE employee_number = ? AND role_id = 2');
    $stmt->execute([$employeeNumber]);
    if ($stmt->fetch()) {
        sendError('An account already exists for this employee number. Please log in instead.', 'EMP_ALREADY_REGISTERED', 409);
    }

    splitFullName($roster['full_name'], $first, $last);
    $username = generateUniqueUsername($pdo, $first, $last);
    $email = ($emailGiven !== null && $emailGiven !== '') ? $emailGiven : $username . '@rmc.edu.ph';

    $hash = password_hash($password, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare(
        'INSERT INTO users (first_name, last_name, username, email, password_hash,
                            student_id, employee_number, year_level, section, status, role_id)
         VALUES (?, ?, ?, ?, ?, NULL, ?, NULL, NULL, \'ACTIVE\', 2)'
    );
    $stmt->execute([$first, $last, $username, $email, $hash, $employeeNumber]);
    $user_id = (int) $pdo->lastInsertId();

    $log = $pdo->prepare('INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)');
    $log->execute([$user_id, 'ACCOUNT_REGISTERED', 'Teacher registered via official roster.']);

    sendSuccess([
        'user_id'    => $user_id,
        'role'       => 'TEACHER',
        'first_name' => $first,
        'last_name'  => $last,
        'username'   => $username,
        'email'      => $email,
    ], 201);

} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('Something went wrong while registering. Please try again.', 'SERVER_ERROR', 500);
}