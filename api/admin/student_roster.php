<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo = getDbConnection();
requireRole($pdo, ['ADMIN']);

$search = trim((string) ($_GET['search'] ?? ''));
$status = strtoupper(trim((string) ($_GET['status'] ?? '')));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 50)));

$where = [];
$params = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(s.lrn LIKE :s1 OR s.full_name LIKE :s2 OR s.program LIKE :s3 OR s.email LIKE :s4 OR u.username LIKE :s5 OR u.email LIKE :s6)';
    $params['s1'] = $like;
    $params['s2'] = $like;
    $params['s3'] = $like;
    $params['s4'] = $like;
    $params['s5'] = $like;
    $params['s6'] = $like;
}
if ($status === 'REGISTERED') {
    $where[] = 'u.user_id IS NOT NULL';
} elseif ($status === 'AWAITING') {
    $where[] = 'u.user_id IS NULL';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare(
    "SELECT COUNT(*) AS total
       FROM student_roster s
       LEFT JOIN users u ON u.student_id = s.lrn AND u.role_id = 1
       $whereSql"
);
$countStmt->execute($params);
$total = (int) ($countStmt->fetchColumn() ?: 0);
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    "SELECT s.id, s.lrn, s.full_name, s.program, s.email, s.imported_at,
            u.user_id, u.username, u.email AS account_email, u.status AS account_status,
            u.created_at AS account_created_at
       FROM student_roster s
       LEFT JOIN users u ON u.student_id = s.lrn AND u.role_id = 1
       $whereSql
      ORDER BY s.full_name ASC, s.lrn ASC
      LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summaryStmt = $pdo->query(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN u.user_id IS NULL THEN 1 ELSE 0 END) AS awaiting,
            SUM(CASE WHEN u.user_id IS NOT NULL THEN 1 ELSE 0 END) AS registered
       FROM student_roster s
       LEFT JOIN users u ON u.student_id = s.lrn AND u.role_id = 1"
);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'awaiting' => 0, 'registered' => 0];

sendSuccess([
    'page' => $page,
    'per_page' => $perPage,
    'total' => $total,
    'pages' => $pages,
    'summary' => [
        'total' => (int) ($summary['total'] ?? 0),
        'registered' => (int) ($summary['registered'] ?? 0),
        'awaiting' => (int) ($summary['awaiting'] ?? 0),
    ],
    'students' => $students,
]);
