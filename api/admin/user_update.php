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
requireFields($input, ['user_id']);

$user_id = (int) $input['user_id'];

try {
    $stmt = $pdo->prepare(
        'SELECT u.user_id, u.first_name, u.last_name, u.username, u.email, u.student_id,
                u.year_level, u.section, u.role_id, u.status
         FROM users u WHERE u.user_id = ?'
    );
    $stmt->execute([$user_id]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
        sendError('User not found.', 'NOT_FOUND', 404);
    }

    $isSelf = ((int) $target['user_id'] === (int) $admin['user_id']);

    $first_name = trim($input['first_name'] ?? $target['first_name'] ?? '');
    $last_name  = trim($input['last_name'] ?? $target['last_name'] ?? '');
    $username   = trim($input['username'] ?? $target['username'] ?? '');
    $email      = trim($input['email'] ?? $target['email'] ?? '');
    $password   = (string) ($input['password'] ?? '');
    $role_id    = (int) ($input['role_id'] ?? $target['role_id']);
    $status     = strtoupper(trim($input['status'] ?? $target['status']));
    $student_id = trim($input['student_id'] ?? $target['student_id'] ?? '') ?: null;
    $year_level = trim($input['year_level'] ?? $target['year_level'] ?? '') ?: null;
    $section    = trim($input['section'] ?? $target['section'] ?? '') ?: null;

    if ($first_name === '' || $last_name === '') {
        sendError('First and last name are required.', 'INVALID_INPUT', 422);
    }
    if (!preg_match('/^[A-Za-z0-9._]{3,30}$/', $username)) {
        sendError('Username must be 3–30 characters using letters, numbers, dots or underscores.', 'INVALID_INPUT', 422);
    }
    if (($err = validateEmail($email)) !== null) {
        sendError($err, 'INVALID_EMAIL', 422);
    }
    if (!in_array($role_id, [1, 2, 3], true)) {
        sendError('Invalid role.', 'INVALID_ROLE', 422);
    }
    if (!in_array($status, ['ACTIVE', 'INACTIVE', 'BANNED'], true)) {
        sendError('Invalid status.', 'INVALID_STATUS', 422);
    }
    if (($target['status'] ?? '') === 'PENDING' && $status === 'ACTIVE') {
        sendError('Email verification is required before a pending account can be activated.', 'EMAIL_NOT_VERIFIED', 409);
    }

    // Never allow an admin to lock themselves out by demoting, banning or
    // deactivating their own account.
    if ($isSelf) {
        if ($role_id !== (int) $target['role_id']) {
            sendError('You cannot change your own role.', 'SELF_OPERATION', 403);
        }
        if ($role_id !== 3) {
            sendError('You cannot demote your own account.', 'SELF_OPERATION', 403);
        }
        if ($status !== $target['status']) {
            sendError('You cannot change your own account status.', 'SELF_OPERATION', 403);
        }
    }
    if ($role_id === 1 && ($student_id === '' || $year_level === '' || $section === '')) {
        sendError('Student ID, year level and section are required for student accounts.', 'MISSING_FIELDS', 422);
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ? AND user_id <> ?');
    $stmt->execute([$username, $user_id]);
    if ((int) $stmt->fetchColumn() > 0) {
        sendError('That username is already taken.', 'DUPLICATE_USERNAME', 409);
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND user_id <> ?');
    $stmt->execute([$email, $user_id]);
    if ((int) $stmt->fetchColumn() > 0) {
        sendError('That email is already registered.', 'DUPLICATE_EMAIL', 409);
    }

    if ($password !== '') {
        if (($err = validatePassword($password)) !== null) {
            sendError($err, 'INVALID_PASSWORD', 422);
        }
        $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
        $stmt->execute([password_hash($password, PASSWORD_BCRYPT), $user_id]);
    }

    $stmt = $pdo->prepare(
        'UPDATE users SET first_name = ?, last_name = ?, username = ?, email = ?,
                          student_id = ?, year_level = ?, section = ?, status = ?, role_id = ?
         WHERE user_id = ?'
    );
    $stmt->execute([$first_name, $last_name, $username, $email,
                    $student_id, $year_level, $section, $status, $role_id, $user_id]);

    // A non-ACTIVE account must be locked out immediately, not at token expiry.
    if ($status !== 'ACTIVE') {
        $kill = $pdo->prepare('DELETE FROM sessions WHERE user_id = ?');
        $kill->execute([$user_id]);
    }

    $log = $pdo->prepare('INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)');
    $log->execute([$admin['user_id'], 'USER_UPDATE', "Updated user \"$username\" (user_id $user_id)"]);

    sendSuccess(['user_id' => $user_id]);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
