<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/archive.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo = getDbConnection();
$admin = requireRole($pdo, ['ADMIN']);

try {
    // Overall aggregates — percentages always derived from (score / SUM(points)).
    $overall = $pdo->query(
        "SELECT
            (SELECT COUNT(*) FROM exam_submissions) AS submission_count,
            (SELECT COUNT(DISTINCT user_id) FROM exam_submissions) AS attempts_users,
            (SELECT SUM(CASE WHEN qtp.tp > 0 AND ROUND(((es.score / qtp.tp) * 50) + 50, 2) >= e.passing_score THEN 1 ELSE 0 END)
             FROM exam_submissions es
             LEFT JOIN exams e ON e.exam_id = es.exam_id
             LEFT JOIN (SELECT exam_id, COALESCE(SUM(points), 0) AS tp FROM questions GROUP BY exam_id) qtp ON qtp.exam_id = es.exam_id) AS pass_count,
            (SELECT COALESCE(AVG(CASE WHEN qtp.tp > 0 THEN (es.score / qtp.tp) * 100 END), 0)
             FROM exam_submissions es
             LEFT JOIN (SELECT exam_id, COALESCE(SUM(points), 0) AS tp FROM questions GROUP BY exam_id) qtp ON qtp.exam_id = es.exam_id) AS avg_pct,
            (SELECT COUNT(*) FROM classes WHERE " . activeSql() . ") AS active_classes,
            (SELECT COUNT(*) FROM exams) AS exam_count
        "
    )->fetch(PDO::FETCH_ASSOC);

    // Per-class aggregates (classes with at least one exam or submission are shown first).
    $classes = $pdo->query(
        "SELECT c.class_id, c.subject_code, c.subject_name, c.block,
            (SELECT COUNT(*) FROM exams x WHERE x.class_id = c.class_id) AS exam_count,
            (SELECT COUNT(*) FROM exam_submissions es JOIN exams e3 ON e3.exam_id = es.exam_id WHERE e3.class_id = c.class_id) AS submission_count,
            (SELECT ROUND(COALESCE(AVG(CASE WHEN qtp.tp > 0 THEN (es.score / qtp.tp) * 100 END), 0), 2)
             FROM exam_submissions es
             JOIN exams e2 ON e2.exam_id = es.exam_id
             LEFT JOIN (SELECT exam_id, COALESCE(SUM(points), 0) AS tp FROM questions GROUP BY exam_id) qtp ON qtp.exam_id = es.exam_id
             WHERE e2.class_id = c.class_id) AS avg_pct,
             (SELECT COALESCE(ROUND((SUM(CASE WHEN qtp.tp > 0 AND ROUND(((es.score / qtp.tp) * 50) + 50, 2) >= e4.passing_score THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0)) * 100, 1), 0)
             FROM exam_submissions es
             JOIN exams e4 ON e4.exam_id = es.exam_id
             LEFT JOIN (SELECT exam_id, COALESCE(SUM(points), 0) AS tp FROM questions GROUP BY exam_id) qtp ON qtp.exam_id = es.exam_id
             WHERE e4.class_id = c.class_id) AS pass_rate
         FROM classes c
         ORDER BY c.block, c.subject_code"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Weakest assessments — worst average first.
    $weakest = $pdo->query(
        "SELECT e.exam_id, e.exam_name, c.subject_code, c.block,
            e.passing_score,
            (SELECT COUNT(*) FROM exam_submissions es WHERE es.exam_id = e.exam_id) AS submission_count,
            (SELECT ROUND(COALESCE(AVG(CASE WHEN qtp.tp > 0 THEN (es.score / qtp.tp) * 100 END), 0), 2)
             FROM exam_submissions es
             LEFT JOIN (SELECT exam_id, COALESCE(SUM(points), 0) AS tp FROM questions GROUP BY exam_id) qtp ON qtp.exam_id = es.exam_id
             WHERE es.exam_id = e.exam_id) AS avg_pct,
            (SELECT COALESCE(ROUND((SUM(CASE WHEN qtp.tp > 0 AND ROUND(((es.score / qtp.tp) * 50) + 50, 2) >= e.passing_score THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0)) * 100, 1), 0)
             FROM exam_submissions es
             LEFT JOIN (SELECT exam_id, COALESCE(SUM(points), 0) AS tp FROM questions GROUP BY exam_id) qtp ON qtp.exam_id = es.exam_id
             WHERE es.exam_id = e.exam_id) AS pass_rate
         FROM exams e
         JOIN classes c ON c.class_id = e.class_id
         HAVING submission_count > 0
         ORDER BY avg_pct ASC, submission_count DESC
         LIMIT 8"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Submissions per day for the last 14 days (fills chart gaps with zeros).
    $rows = $pdo->query(
        "SELECT DATE(submitted_at) AS d, COUNT(*) AS cnt
         FROM exam_submissions
         WHERE submitted_at >= (CURDATE() - INTERVAL 13 DAY)
         GROUP BY DATE(submitted_at)"
    )->fetchAll(PDO::FETCH_ASSOC);
    $byDate = [];
    foreach ($rows as $r) { $byDate[$r['d']] = (int) $r['cnt']; }
    $daily = [];
    for ($i = 13; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $daily[] = ['date' => $d, 'count' => $byDate[$d] ?? 0];
    }

    $overall['submission_count'] = (int) $overall['submission_count'];
    $overall['attempts_users'] = (int) $overall['attempts_users'];
    $overall['pass_count'] = (int) $overall['pass_count'];
    $overall['active_classes'] = (int) $overall['active_classes'];
    $overall['exam_count'] = (int) $overall['exam_count'];
    $overall['avg_pct'] = $overall['avg_pct'] !== '' ? round((float) $overall['avg_pct'], 2) : 0;
    $overall['pass_rate'] = $overall['submission_count'] > 0
        ? round(($overall['pass_count'] / $overall['submission_count']) * 100, 1)
        : 0;

    sendSuccess(['overall' => $overall, 'classes' => $classes, 'weakest' => $weakest, 'daily' => $daily]);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
