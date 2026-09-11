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

$status   = strtoupper(trim($_GET['status'] ?? ''));
$classId  = (int) ($_GET['class_id'] ?? 0);
$search   = trim($_GET['search'] ?? '');
$page     = max(1, (int) ($_GET['page'] ?? 1));
$perPage  = min(50, max(1, (int) ($_GET['per_page'] ?? 12)));

$allowedStatuses = ['DRAFT', 'SCHEDULED', 'LIVE', 'CLOSED', 'ARCHIVED'];
$where = [];
$params = [];

if ($status !== '' && in_array($status, $allowedStatuses, true)) {
    $where[] = 'e.status = :status';
    $params['status'] = $status;
}
if ($classId > 0) {
    $where[] = 'e.class_id = :class_id';
    $params['class_id'] = $classId;
}
if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(e.exam_name LIKE :s1 OR c.subject_name LIKE :s2 OR c.subject_code LIKE :s3)';
    $params['s1'] = $like;
    $params['s2'] = $like;
    $params['s3'] = $like;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM exams e JOIN classes c ON c.class_id = e.class_id $whereSql");
$stmt->execute($params);
$total = (int) $stmt->fetch()['total'];
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    "SELECT e.exam_id, e.exam_name, e.status, e.start_time, e.end_time, e.duration_minutes,
            e.passing_score, e.total_points, e.is_closed, e.closed_at, e.created_at, e.class_id,
            c.subject_name, c.subject_code, c.block,
            (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.exam_id) AS question_count,
            (SELECT COALESCE(SUM(q.points), 0) FROM questions q WHERE q.exam_id = e.exam_id) AS points_count,
            (SELECT COUNT(*) FROM exam_submissions s WHERE s.exam_id = e.exam_id) AS submission_count,
            (SELECT ROUND(COALESCE(AVG(CASE WHEN COALESCE(qtp.tp, 0) > 0 THEN (s.score / qtp.tp) * 100 END), 0), 2)
             FROM exam_submissions s
             LEFT JOIN (SELECT exam_id, COALESCE(SUM(points), 0) AS tp FROM questions GROUP BY exam_id) qtp
                    ON qtp.exam_id = s.exam_id
             WHERE s.exam_id = e.exam_id) AS avg_pct
     FROM exams e
     JOIN classes c ON c.class_id = e.class_id
     $whereSql
     ORDER BY e.created_at DESC, e.exam_id DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$exams = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = $pdo->query(
    "SELECT status, COUNT(*) AS cnt FROM exams GROUP BY status"
)->fetchAll(PDO::FETCH_ASSOC);
$summaryMap = ['DRAFT' => 0, 'SCHEDULED' => 0, 'LIVE' => 0, 'CLOSED' => 0, 'ARCHIVED' => 0];
foreach ($summary as $row) {
    $summaryMap[$row['status']] = (int) $row['cnt'];
}

sendSuccess([
    'page' => $page,
    'per_page' => $perPage,
    'total' => $total,
    'pages' => $pages,
    'summary' => $summaryMap,
    'exams' => $exams,
]);