<?php
/**
 * Database connection helper.
 * Every endpoint calls getDbConnection() to get a PDO instance.
 */

/**
 * Align PHP's clock with the MySQL server clock.
 *
 * All exam scheduling / status logic is driven by MySQL NOW() (the "server
 * time" reference). If PHP's default timezone differs from MySQL's, then
 * date()/strtotime() produce wall-clock strings that MySQL interprets
 * differently, causing freshly scheduled/reopened exams to instantly auto-close
 * (their end_time ends up in the past). Deriving PHP's timezone from the DB
 * keeps both clocks identical so every date computation is consistent.
 */
function syncPhpTimezone(PDO $pdo): void {
    try {
        $offsetSec = (int) $pdo->query('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())')->fetchColumn();
        if ($offsetSec % 3600 === 0) {
            $hours = (int) ($offsetSec / 3600);
            $tz = $hours === 0
                ? 'UTC'
                : 'Etc/GMT' . ($hours > 0 ? '-' . $hours : '+' . abs($hours));
            date_default_timezone_set($tz);
        }
    } catch (Exception $e) {
        // Non-whole-hour offsets keep the default timezone.
    }
}

/**
 * Load database settings from environment variables.
 *
 * Production (Render + external Aiven MySQL): set DB_HOST / DB_PORT /
 * DB_NAME / DB_USER / DB_PASSWORD and OPTIONALLY DB_SSL_CA_B64 to the
 * environment service variables. Aiven requires TLS; if DB_SSL_CA_B64
 * holds the base64-encoded Aiven CA certificate (ca.pem), the connection
 * verifies the server's certificate via TLS.
 *
 * Local development (XAMPP): env vars are likely unset, so these defaults
 * fall back to the standard local MySQL config. This keeps XAMPP working
 * with zero extra setup (no SSL needed locally).
 *
 * No production credentials are hardcoded here.
 */
function getDbConnection(): PDO {
    $host     = getenv('DB_HOST')     ?: '127.0.0.1';
    $port     = getenv('DB_PORT')     ?: '3306';
    $dbname   = getenv('DB_NAME')     ?: 'quiz_system';
    $username = getenv('DB_USER')     ?: 'root';
    $password = (string) getenv('DB_PASSWORD'); // default: no password (XAMPP)

    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    // Aiven (and most managed MySQL) require TLS. When DB_SSL_CA_B64 holds
    // the base64 Aiven "ca.pem", write it to a temp file and attach it to
    // the connection so the server cert is verified. Leave unset for local
    // XAMPP (no TLS) so development keeps working unchanged.
    $caB64 = getenv('DB_SSL_CA_B64');
    if ($caB64) {
        $caPath = sys_get_temp_dir() . '/quizsystem_ca.pem';
        $decoded = base64_decode($caB64, true);
        if ($decoded !== false && $decoded !== '') {
            @file_put_contents($caPath, $decoded);
            $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
        }
    }

    try {
        $pdo = new PDO($dsn, $username, $password, $options);
        syncPhpTimezone($pdo);
        return $pdo;
    } catch (PDOException $e) {
        error_log('QuizSystem DB Error: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Database connection failed.',
            'code' => 'DB_CONNECTION_ERROR',
        ]);
        exit;
    }
}