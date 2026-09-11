<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo = getDbConnection();
$admin = requireRole($pdo, ['ADMIN']);

try {
    $dbNow = $pdo->query('SELECT NOW() AS now')->fetchColumn();
    $tzOffsetSec = (int) $pdo->query('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())')->fetchColumn();

    $tables = ['users', 'classes', 'enrollments', 'exams', 'questions',
               'exam_submissions', 'exam_temp_answers', 'sessions', 'activity_logs', 'password_resets'];
    $counts = [];
    foreach ($tables as $t) {
        $counts[$t] = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    }

    $sessions = $pdo->query(
        "SELECT s.session_id, s.expires_at, s.created_at,
                u.user_id, u.first_name, u.last_name, u.username, r.role_name
         FROM sessions s
         JOIN users u ON u.user_id = s.user_id
         JOIN roles r ON r.role_id = u.role_id
         ORDER BY s.created_at DESC
         LIMIT 100"
    )->fetchAll(PDO::FETCH_ASSOC);

    sendSuccess([
        'db_now' => $dbNow,
        'tz_offset_seconds' => $tzOffsetSec,
        'table_counts' => $counts,
        'active_sessions' => count($sessions),
        'sessions' => $sessions,
        'php_version' => PHP_VERSION,
    ]);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}