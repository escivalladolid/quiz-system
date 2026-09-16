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

$role   = strtoupper(trim($_GET['role'] ?? ''));
$status = strtoupper(trim($_GET['status'] ?? ''));
$search = trim($_GET['search'] ?? '');
$page   = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(50, max(1, (int) ($_GET['per_page'] ?? 12)));

$where = [];
$params = [];

if ($role !== '' && in_array($role, ['STUDENT', 'TEACHER', 'ADMIN'], true)) {
    $where[] = 'r.role_name = :role';
    $params['role'] = $role;
}
if ($status !== '' && in_array($status, ['PENDING', 'ACTIVE', 'INACTIVE', 'BANNED'], true)) {
    $where[] = 'u.status = :status';
    $params['status'] = $status;
}
if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR u.username LIKE :s3 OR u.email LIKE :s4)';
    $params['s1'] = $like;
    $params['s2'] = $like;
    $params['s3'] = $like;
    $params['s4'] = $like;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM users u JOIN roles r ON r.role_id = u.role_id $whereSql");
$stmt->execute($params);
$total = (int) $stmt->fetch()['total'];
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    "SELECT u.user_id, u.first_name, u.last_name, u.username, u.email, u.student_id,
            u.year_level, u.section, u.status, u.role_id, r.role_name, u.created_at
     FROM users u JOIN roles r ON r.role_id = u.role_id
     $whereSql
     ORDER BY u.created_at DESC, u.user_id DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

sendSuccess([
    'page' => $page,
    'per_page' => $perPage,
    'total' => $total,
    'pages' => $pages,
    'users' => $users,
]);
