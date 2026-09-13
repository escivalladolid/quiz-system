<?php
/**
 * RMC Quiz & Exam — Admin Web Panel bootstrap.
 *
 * Session handling, server-side access to the shared PHP API, CSRF tokens
 * and small auth helpers for the admin panel. The panel lives inside the
 * PHP backend (Backend-PHP/rmc-admin) so the API base is always derived
 * from the current request — same host, works identically on local XAMPP
 * and on Render with zero configuration.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => false,          // true on Render behind HTTPS; harmless when off locally
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('RMC_ADMIN');
    session_start();
}

/** HTML-escape a value for safe output. */
function e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Root-relative public admin URL, also valid under a local backend prefix. */
function admin_url(string $path = ''): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? '/rmc-admin/login.php';
    $backend = rtrim(str_replace('\\', '/', dirname(dirname($script))), '/');
    return $backend . '/admin/' . ltrim($path, '/');
}

/** Absolute URL of the shared API directory, derived from the current request. */
function admin_api_base(): string {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/Capstone-Mobile-Quiz-System/Backend-PHP/rmc-admin/login.php';
    $panelDir   = dirname($scriptName);          // .../rmc-admin
    $backendDir = dirname($panelDir);             // .../Backend-PHP
    // Scheme detection behind a TLS-terminating proxy (Render): trust the
    // X-Forwarded-Proto header when present, since $_SERVER['HTTPS'] is
    // empty on Render (TLS ends at the proxy). Without this the panel would
    // self-call over http:// and never reach the container (port 80).
    $fwdProto  = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    $isHTTPS   = $fwdProto === 'https'
                 || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme    = $isHTTPS ? 'https' : 'http';
    $host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . $backendDir . '/api/';
}

/**
 * Call a shared API endpoint with the same JSON conventions login.php /
 * forgot_password.php use. Returns ['http_code' => int, 'body' => array].
 * Uses cURL when available and falls back to HTTP streams otherwise.
 */
function admin_api_request(string $method, string $path, array $payload = [], ?string $token = null): array {
    $url     = admin_api_base() . ltrim($path, '/');
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    $json    = $payload ? json_encode($payload) : null;
    if ($token !== null && $token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            // TLS peer verification stays ON: the panel self-calls the API
            // on the same host. Local XAMPP uses plain http (no TLS, option
            // ignored); Render's HTTPS endpoint has a valid cert, so the
            // system CA bundle verifies it normally.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create(['http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'content'       => $json ?? '',
            'ignore_errors' => true,
            'timeout'       => 15,
        ]]);
        $raw  = @file_get_contents($url, false, $context);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
    }

    $decoded = json_decode((string) $raw, true);
    return ['http_code' => $code, 'body' => is_array($decoded) ? $decoded : []];
}

/** True when an admin session is active. */
function admin_logged_in(): bool {
    return isset($_SESSION['admin_user']);
}

/**
 * Restore the admin session from the long-lived "remember this device" cookie.
 *
 * The panel's PHP session is file-backed, so Render's free tier wipes it on
 * every dyno restart / sleep — that alone forces an admin to log back in
 * "even though I never logged out." The API session token, in contrast, lives
 * in the DB-backed `sessions` table and survives restarts. So when the PHP
 * session is gone but the cookie still holds a valid DB token we rebuild the
 * session from admin/me.php instead of showing the login screen.
 */
function admin_restore_remember(): void {
    if (admin_logged_in()) {
        return;
    }
    $token = $_COOKIE['rmc_admin_remember'] ?? '';
    if ($token === '') {
        return;
    }

    $res = admin_api_request('GET', 'admin/me.php', [], $token);
    if (($res['http_code'] ?? 0) !== 200 || empty($res['body']['success'])) {
        // Token was revoked/expired — drop the stale cookie instead of
        // hammering the API on every request.
        admin_clear_remember_cookie();
        return;
    }

    $data = $res['body']['data'] ?? [];
    $_SESSION['admin_user'] = [
        'user_id'  => (int) ($data['user_id'] ?? 0),
        'name'     => trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')),
        'username' => $data['username'] ?? '',
        'email'    => $data['email'] ?? '',
        'token'    => $token,
    ];
}

/** Write the "remember this device" cookie (30-day, device-scoped token). */
function admin_set_remember_cookie(string $token): void {
    $fwdProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    $secure   = $fwdProto === 'https' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie('rmc_admin_remember', $token, [
        'expires'  => time() + 30 * 24 * 3600,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Expire the "remember this device" cookie (logout / invalid token). */
function admin_clear_remember_cookie(): void {
    setcookie('rmc_admin_remember', '', [
        'expires'  => time() - 42000,
        'path'     => '/',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// One-shot restore on every protected page: rebuild the session from the
// DB-backed token when the file-backed PHP session was wiped (Render
// restart / dyno sleep). Bounces the user straight to the dashboard instead
// of forcing another login.
if (!admin_logged_in()) {
    admin_restore_remember();
}

/** Redirect to the login page when the admin is not authenticated. */
function admin_require_login(): void {
    if (!admin_logged_in()) {
        header('Location: ' . admin_url('login'));
        exit;
    }
}

/** Generate a CSRF token for the current session. */
function admin_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Verify a submitted CSRF token, bailing with 403 when invalid. */
function admin_verify_csrf(?string $token): void {
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid security token. Please try again.', 'code' => 'FORBIDDEN']);
        exit;
    }
}

/** Store a one-shot flash message for display on the next page load. */
function admin_flash_set(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Render the flash message (if any) as an alert banner, then clear it from
 * the session so it does not persist on refresh.
 */
function admin_flash_display(): void {
    if (empty($_SESSION['flash'])) return;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    $cls = ($flash['type'] ?? 'success') === 'success' ? 'alert-ok' : 'alert-error';
    if ($flash['type'] === 'success') {
        $icon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
    } else {
        $icon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
    }
    echo '<div class="alert flash-banner auto-hide ' . $cls . '">' . $icon . ' ' . e($flash['message'] ?? '') . '</div>';
}