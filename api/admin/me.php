<?php
/** Return the currently authenticated administrator for remember-device restores. */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo = getDbConnection();
$admin = requireRole($pdo, ['ADMIN']);

sendSuccess([
    'user_id' => (int) $admin['user_id'],
    'first_name' => $admin['first_name'],
    'last_name' => $admin['last_name'],
    'username' => $admin['username'],
    'email' => $admin['email'],
    'role_name' => $admin['role_name'],
]);
