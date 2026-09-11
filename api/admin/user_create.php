<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/validation.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo = getDbConnection();
$admin = requireRole($pdo, ['ADMIN']);

$input = getJsonInput();
requireFields($input, ['first_name', 'last_name', 'username', 'email', 'password', 'role_id']);

$first_name = trim($input['first_name']);
$last_name  = trim($input['last_name']);
$username   = trim($input['username']);
$email      = trim($input['email']);
$password   = (string) $input['password'];
$role_id    = (int) $input['role_id'];
$status     = strtoupper(trim($input['status'] ?? 'ACTIVE'));
$student_id = trim($input['student_id'] ?? '') ?: null;
$year_level = trim($input['year_level'] ?? '') ?: null;
$section    = trim($input['section'] ?? '') ?: null;

if ($first_name === '' || $last_name === '') {
    sendError('First and last name are required.', 'INVALID_INPUT', 422);
}
if (!preg_match('/^[A-Za-z0-9._]{3,30}$/', $username)) {
    sendError('Username must be 3–30 characters using letters, numbers, dots or underscores.', 'INVALID_INPUT', 422);
}
if (($err = validateEmail($email)) !== null) {
    sendError($err, 'INVALID_EMAIL', 422);
}
if (($err = validatePassword($password)) !== null) {
    sendError($err, 'INVALID_PASSWORD', 422);
}
if (!in_array($role_id, [1, 2, 3], true)) {
    sendError('Invalid role.', 'INVALID_ROLE', 422);
}
if (!in_array($status, ['ACTIVE', 'INACTIVE', 'BANNED'], true)) {
    sendError('Invalid status.', 'INVALID_STATUS', 422);
}
if ($role_id === 1 && ($student_id === '' || $year_level === '' || $section === '')) {
    sendError('Student ID, year level and section are required for student accounts.', 'MISSING_FIELDS', 422);
}

try {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $stmt->execute([$username]);
    if ((int) $stmt->fetchColumn() > 0) {
        sendError('That username is already taken.', 'DUPLICATE_USERNAME', 409);
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ((int) $stmt->fetchColumn() > 0) {
        sendError('That email is already registered.', 'DUPLICATE_EMAIL', 409);
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare(
        'INSERT INTO users (first_name, last_name, username, email, password_hash,
                            student_id, year_level, section, status, role_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$first_name, $last_name, $username, $email, $hash,
                    $student_id, $year_level, $section, $status, $role_id]);
    $user_id = (int) $pdo->lastInsertId();

    $log = $pdo->prepare('INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)');
    $log->execute([$admin['user_id'], 'USER_CREATE', "Created user \"$username\" ($first_name $last_name, role_id $role_id)"]);

    sendSuccess(['user_id' => $user_id], 201);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}