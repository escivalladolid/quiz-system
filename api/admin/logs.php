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

$action = strtoupper(trim($_GET['action'] ?? ''));
$userId = (int) ($_GET['user_id'] ?? 0);
$search = trim($_GET['search'] ?? '');
$from   = trim($_GET['from'] ?? '');
$to     = trim($_GET['to'] ?? '');
$page   = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));

$where = [];
$params = [];

if ($action !== '') {
    $where[] = 'l.action = :action';
    $params['action'] = $action;
}
if ($userId > 0) {
    $where[] = 'l.user_id = :user_id';
    $params['user_id'] = $userId;
}
if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(u.username LIKE :s1 OR u.first_name LIKE :s2 OR u.last_name LIKE :s3 OR l.description LIKE :s4)';
    $params['s1'] = $like;
    $params['s2'] = $like;
    $params['s3'] = $like;
    $params['s4'] = $like;
}
if ($from !== '' && strtotime($from)) {
    $where[] = 'l.created_at >= :from';
    $params['from'] = $from . ' 00:00:00';
}
if ($to !== '' && strtotime($to)) {
    $where[] = 'l.created_at < :to_plus_one';
    $params['to_plus_one'] = date('Y-m-d', strtotime($to) + 86400) . ' 00:00:00';
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM activity_logs l LEFT JOIN users u ON u.user_id = l.user_id $whereSql");
$stmt->execute($params);
$total = (int) $stmt->fetch()['total'];
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    "SELECT l.log_id, l.action, l.description, l.created_at,
            u.user_id, u.first_name, u.last_name, u.username
     FROM activity_logs l
     LEFT JOIN users u ON u.user_id = l.user_id
     $whereSql
     ORDER BY l.created_at DESC, l.log_id DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = $pdo->query("SELECT action, COUNT(*) AS cnt FROM activity_logs GROUP BY action ORDER BY cnt DESC")->fetchAll(PDO::FETCH_ASSOC);

sendSuccess([
    'page' => $page,
    'per_page' => $perPage,
    'total' => $total,
    'pages' => $pages,
    'summary' => $summary,
    'logs' => $logs,
]);