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

$status = strtoupper(trim((string) ($_GET['status'] ?? '')));
$search = trim((string) ($_GET['search'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 50)));
$where = [];
$params = [];
if (in_array($status, ['OPEN', 'IN_PROGRESS', 'RESOLVED'], true)) {
    $where[] = 'status = :status';
    $params['status'] = $status;
}
if ($search !== '') {
    $where[] = '(contact LIKE :s1 OR description LIKE :s2 OR step LIKE :s3 OR screen LIKE :s4)';
    $like = '%' . $search . '%';
    $params += ['s1' => $like, 's2' => $like, 's3' => $like, 's4' => $like];
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    $count = $pdo->prepare("SELECT COUNT(*) FROM login_problem_reports $whereSql");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $stmt = $pdo->prepare(
        "SELECT report_id, contact, role, step, description, screen, app_version,
                device_info, ip_address, status, created_at
         FROM login_problem_reports $whereSql
         ORDER BY created_at DESC, report_id DESC
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Login problem reports unavailable: ' . get_class($e));
    sendError('Login problem reports are not available until the database migration is applied.', 'SERVER_MISCONFIGURED', 503);
}

sendSuccess([
    'page' => $page,
    'per_page' => $perPage,
    'total' => $total,
    'pages' => $pages,
    'reports' => $reports,
]);
