<?php
/**
 * "Remember this device" — panel session bootstrap glue.
 *
 * The admin panel authenticates with a DB-backed API session token (the
 * `sessions` table on the shared Postgres). Those tokens survive dyno
 * restarts, dyno sleep/wake and browser-tab closes — but the PHP file-backed
 * `$_SESSION` that saves them does NOT (Render wipes the panel's on-disk PHP
 * session dir whenever the instance restarts or sleeps, and it ends on
 * browser close).
 *
 * So "I keep having to log in even though I never logged out" is not a login
 * form failure — it is the session *container* being reset while the API
 * credential is still perfectly valid. This helper closes that gap: the API
 * token is persisted in a long-lived cookieholok and, when the PHP session is
 * gone but the cookie still holds a token the API still recognises (GET
 * admin/me.php), we rebuild the panel session from that same token in one
 * round trip. No new OAuth, no password re-entry, no re-login.
 */

/**
 * Persist a login token as a long-lived remember-me cookie (30 days).
 * Secure flag mirrors the request scheme so it works on local XAMPP
 * (http) and on Render (https behind the proxy).
 */
function admin_set_remember_cookie(string $token): void {
    $fwdProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    $isHTTPS  = $fwdProto === 'https'
             || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('rmc_admin_token', $token, [
        'expires'  => time() + 30 * 24 * 3600,   // 30 days
        'path'     => '/',
        'secure'   => $isHTTPS,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Clear the remember-me cookie (used on logout).
 */
function admin_clear_remember_cookie(): void {
    setcookie('rmc_admin_token', '', [
        'expires'  => time() - 42000,
        'path'     => '/',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Rebuild the admin panel session from the remembered API token, if any.
 * Returns early when a live PHP session already exists. Should run once per
 * page load, right after session_start().
 */
function admin_restore_remember(): void {
    if (admin_logged_in()) {
        return;
    }
    $token = $_COOKIE['rmc_admin_token'] ?? '';
    if ($token === '') {
        return;
    }

    $res = admin_api_request('GET', 'admin/me.php', [], $token);
    if (($res['http_code'] ?? 0) !== 200) {
        // Token revoked/expired — drop the stale cookie so we don't hammer
        // the API on every request.
        admin_clear_remember_cookie();
        return;
    }
    $body = $res['body']['data'] ?? [];
    $_SESSION['admin_user'] = [
        'user_id'  => (int) ($body['user_id'] ?? 0),
        'name'     => trim(($body['first_name'] ?? '') . ' ' . ($body['last_name'] ?? '')),
        'username' => $body['username'] ?? '',
        'email'    => $body['email'] ?? '',
        'token'    => $token,
    ];
}
