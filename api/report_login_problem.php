<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/mailer.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('This endpoint only accepts POST requests.', 'METHOD_NOT_ALLOWED', 405);
}

$input = getJsonInput();
$contact = trim((string) ($input['contact'] ?? ''));
$description = trim((string) ($input['description'] ?? ''));
$step = trim((string) ($input['step'] ?? ''));
$role = strtoupper(trim((string) ($input['role'] ?? 'UNKNOWN')));
$screen = trim((string) ($input['screen'] ?? ''));
$appVersion = trim((string) ($input['app_version'] ?? ''));
$deviceInfo = trim((string) ($input['device_info'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')));
$ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

if ($contact === '' || $description === '' || $step === '') {
    sendError('Contact, step, and description are required.', 'MISSING_FIELDS', 422);
}
if (mb_strlen($contact) > 255 || mb_strlen($description) > 1000) {
    sendError('Contact or description is too long.', 'INVALID_INPUT', 422);
}
if (!in_array($role, ['STUDENT', 'TEACHER', 'UNKNOWN'], true)) $role = 'UNKNOWN';
$step = substr($step, 0, 40);
$screen = substr($screen, 0, 80);
$appVersion = substr($appVersion, 0, 40);
$deviceInfo = substr($deviceInfo, 0, 255);

$pdo = getDbConnection();
try {
    // Five reports per source address in a rolling 15-minute window keeps the
    // support channel useful without blocking a legitimate retry.
    $rate = $pdo->prepare(
        'SELECT COUNT(*) FROM login_problem_reports
         WHERE ip_address = :ip AND created_at > (NOW() - INTERVAL 15 MINUTE)'
    );
    $rate->execute(['ip' => $ip]);
    if ((int) $rate->fetchColumn() >= 5) {
        sendError('Too many reports from this connection. Please try again later.', 'RATE_LIMITED', 429);
    }

    $insert = $pdo->prepare(
        'INSERT INTO login_problem_reports
            (contact, role, step, description, screen, app_version, device_info, ip_address)
         VALUES (:contact, :role, :step, :description, :screen, :app_version, :device_info, :ip)'
    );
    $insert->execute([
        'contact' => $contact,
        'role' => $role,
        'step' => $step,
        'description' => $description,
        'screen' => $screen !== '' ? $screen : null,
        'app_version' => $appVersion !== '' ? $appVersion : null,
        'device_info' => $deviceInfo !== '' ? $deviceInfo : null,
        'ip' => $ip !== '' ? $ip : null,
    ]);
    $reportId = (int) $pdo->lastInsertId();
} catch (PDOException $e) {
    error_log('Login problem report could not be saved: ' . get_class($e));
    sendError('Support reporting is temporarily unavailable. Please try again later.', 'SERVER_MISCONFIGURED', 503);
}

$adminEmail = trim((string) (getenv('ADMIN_EMAIL') ?: 'rmcquizandexamination@gmail.com'));
$emailSent = $adminEmail !== '' && sendLoginProblemReportEmail(
    $adminEmail, $contact, $role, $step, $description, $screen, $deviceInfo
);

sendSuccess([
    'saved' => true,
    'report_id' => $reportId,
    'email_sent' => $emailSent,
    'message' => 'Your report was received. The administrator can review it in the admin panel.',
]);
