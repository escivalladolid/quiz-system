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

/** Absolute URL of the shared API directory, derived from the current request. */
function admin_api_base(): string {
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/Capstone-Mobile-Quiz-System/Backend-PHP/rmc-admin/login.php';
    $panelDir   = dirname($scriptName);          // .../rmc-admin
    $backendDir = dirname($panelDir);             // .../Backend-PHP
    $scheme     = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host       = $_SERVER['HTTP_HOST'] ?? 'localhost';
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
            CURLOPT_SSL_VERIFYPEER => false, // local XAMPP has no valid cert; overridden by HTTPS origin
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

/** Redirect to the login page when the admin is not authenticated. */
function admin_require_login(): void {
    if (!admin_logged_in()) {
        header('Location: login.php');
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