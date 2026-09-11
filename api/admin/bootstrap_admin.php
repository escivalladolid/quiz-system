<?php
/**
 * RMC Quiz & Exam System — one-shot admin bootstrap.
 *
 * Creates role 3 (ADMIN) and the default admin account on ANY database,
 * so the admin web panel can start working without manual SQL access.
 *
 * SAFETY:
 * - Gated by the ADMIN_BOOTSTRAP_CODE environment variable (must be set
 *   in Render before use; refused when missing).
 * - Idempotent + self-disabling: once role ADMIN + the 'admin' user exist
 *   it does nothing but report already-seeded.
 * - Additive only (INSERT IGNORE): never deletes or alters data.
 *
 * URL: /api/admin/bootstrap_admin.php?code=<ADMIN_BOOTSTRAP_CODE value>
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

$expected = (string) getenv('ADMIN_BOOTSTRAP_CODE');
$given    = (string) ($_GET['code'] ?? '');

if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Missing or invalid ADMIN_BOOTSTRAP_CODE. Set it as a Render env var, then call this URL with ?code=<your value>.',
        'code' => 'FORBIDDEN',
    ]);
    exit;
}

$pdo = getDbConnection();

try {
    $roleExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM roles WHERE role_id = 3 OR role_name = 'ADMIN'"
    )->fetchColumn();

    $adminExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM users WHERE username = 'admin' AND role_id = 3"
    )->fetchColumn();

    if ($roleExists && $adminExists) {
        sendSuccess([
            'seeded' => false,
            'message' => 'Admin role and account already exist; nothing was changed.',
        ]);
    }

    $pdo->exec("INSERT IGNORE INTO roles (role_id, role_name) VALUES (3, 'ADMIN')");

    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO users
            (first_name, last_name, username, email, password_hash, role_id, status)
         VALUES
            (:first_name, :last_name, :username, :email, :password_hash, :role_id, :status)"
    );
    $stmt->execute([
        'first_name'   => 'System',
        'last_name'    => 'Administrator',
        'username'     => 'admin',
        'email'        => 'admin@rmc.edu.ph',
        'password_hash' => '$2y$10$YnKVR1RrwrlJUAZHvnzzf.3dGsHXLF1A.rR1MtTn8ZtSqMZtC7COW',
        'role_id'      => 3,
        'status'       => 'ACTIVE',
    ]);

    sendSuccess([
        'seeded' => true,
        'message' => 'Admin role and default admin account created. Login with admin / admin123, then change the password.',
    ]);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error (bootstrap): ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}