<?php
require_once __DIR__ . '/inc/bootstrap.php';

// Logging out changes authentication state, so it must be a CSRF-protected
// POST. A third-party page must not be able to log an administrator out by
// embedding or linking to this endpoint.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo 'Method Not Allowed';
    exit;
}
admin_verify_csrf($_POST['csrf_token'] ?? null);

admin_clear_remember_cookie();

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

header('Location: ' . admin_url('login'));
exit;
