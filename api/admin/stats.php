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
    $stmt = $pdo->query("SELECT
        (SELECT COUNT(*) FROM users) AS total_users,
        (SELECT COUNT(*) FROM users WHERE role_id = 1 AND status = 'ACTIVE') AS total_students,
        (SELECT COUNT(*) FROM users WHERE role_id = 2 AND status = 'ACTIVE') AS total_teachers,
        (SELECT COUNT(*) FROM users WHERE role_id = 3 AND status = 'ACTIVE') AS total_admins,
        (SELECT COUNT(*) FROM users WHERE role_id = 1 AND created_at >= NOW() - INTERVAL 7 DAY) AS new_students_7d,
        (SELECT COUNT(*) FROM users WHERE role_id = 2 AND created_at >= NOW() - INTERVAL 7 DAY) AS new_teachers_7d,
        (SELECT COUNT(*) FROM classes) AS total_classes,
        (SELECT COUNT(*) FROM classes WHERE status = 'ACTIVE') AS active_classes,
        (SELECT COUNT(*) FROM exams) AS total_exams,
        (SELECT COUNT(*) FROM exams WHERE status = 'LIVE') AS live_exams,
        (SELECT COUNT(*) FROM questions) AS total_questions,
        (SELECT COUNT(*) FROM exam_submissions) AS total_submissions,
        (SELECT COUNT(*) FROM exam_submissions WHERE submitted_at >= NOW() - INTERVAL 1 DAY) AS submissions_last_24h,
        (SELECT COUNT(*) FROM exam_submissions WHERE submitted_at >= NOW() - INTERVAL 7 DAY
         AND (auto_submitted = 1 OR exit_attempts > 0)) AS flagged_7d,
        (SELECT COUNT(*) FROM exam_submissions WHERE submitted_at >= NOW() - INTERVAL 7 DAY AND auto_submitted = 1) AS auto_submitted_7d,
        (SELECT COUNT(*) FROM exam_submissions WHERE submitted_at >= NOW() - INTERVAL 7 DAY AND exit_attempts > 0) AS flagged_exit_7d,
        (SELECT COUNT(*) FROM exam_submissions WHERE submitted_at >= NOW() - INTERVAL 1 DAY
         AND (auto_submitted = 1 OR exit_attempts > 0)) AS flagged_last_24h,
        (SELECT COUNT(*) FROM sessions WHERE expires_at > NOW()) AS active_sessions
    ");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    $recent = $pdo->query(
        "SELECT es.submission_id, u.user_id, u.first_name, u.last_name, u.section,
                e.exam_id, e.exam_name, e.passing_score, c.subject_name, c.block, es.score,
                es.submitted_at,
                ROUND((es.score / NULLIF(qtp.tp, 0)) * 100, 2) AS percentage
         FROM exam_submissions es
         JOIN users u ON u.user_id = es.user_id
         JOIN exams e ON e.exam_id = es.exam_id
         JOIN classes c ON c.class_id = e.class_id
         LEFT JOIN (SELECT exam_id, COALESCE(SUM(points), 0) AS tp FROM questions GROUP BY exam_id) qtp
                ON qtp.exam_id = e.exam_id
         ORDER BY es.submitted_at DESC
         LIMIT 8"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($recent as &$row) {
        $row['percentage'] = $row['percentage'] !== '' && $row['percentage'] !== null ? (float) $row['percentage'] : null;
        $row['score'] = (int) $row['score'];
        $row['passing_score'] = (int) $row['passing_score'];
        $row['passed'] = $row['percentage'] !== null && (int) $row['passing_score'] <= 100
            ? $row['percentage'] >= (int) $row['passing_score']
            : null;
    }
    unset($row);

    // Submissions per day for the dashboard chart (last 7 days, oldest first).
    $dayRows = $pdo->query(
        "SELECT DATE(submitted_at) AS d, COUNT(*) AS c
         FROM exam_submissions
         WHERE submitted_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
         GROUP BY DATE(submitted_at)"
    )->fetchAll(PDO::FETCH_ASSOC);
    $byDay = [];
    foreach ($dayRows as $r) {
        $byDay[$r['d']] = (int) $r['c'];
    }
    $chart = ['labels' => [], 'values' => []];
    for ($i = 6; $i >= 0; $i--) {
        $ts = strtotime("-$i day");
        $chart['labels'][] = date('D', $ts);
        $chart['values'][] = $byDay[date('Y-m-d', $ts)] ?? 0;
    }

    $stats = array_map(function ($v) { return (int) $v; }, $stats);

    sendSuccess([
        'stats' => $stats,
        'recent_submissions' => $recent,
        'submissions_7d' => $chart,
    ]);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}