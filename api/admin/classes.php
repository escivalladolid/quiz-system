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

$status    = strtoupper(trim($_GET['status'] ?? ''));
$teacherId = (int) ($_GET['teacher_id'] ?? 0);
$search    = trim($_GET['search'] ?? '');
$page      = max(1, (int) ($_GET['page'] ?? 1));
$perPage   = min(50, max(1, (int) ($_GET['per_page'] ?? 12)));

$where = [];
$params = [];

if ($status !== '' && in_array($status, ['ACTIVE', 'ARCHIVED'], true)) {
    $where[] = $status === 'ARCHIVED' ? archivedSql('c') : activeSql('c');
}
if ($teacherId > 0) {
    $where[] = 'c.teacher_id = :teacher_id';
    $params['teacher_id'] = $teacherId;
}
if ($search !== '') {
    $like = '%' . $search . '%';
    $where[] = '(c.subject_name LIKE :s1 OR c.subject_code LIKE :s2 OR c.class_code LIKE :s3 OR c.block LIKE :s4)';
    $params['s1'] = $like;
    $params['s2'] = $like;
    $params['s3'] = $like;
    $params['s4'] = $like;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM classes c $whereSql");
$stmt->execute($params);
$total = (int) $stmt->fetch()['total'];
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    "SELECT c.class_id, c.subject_code, c.subject_name, c.block, c.class_code,
            c.status, c.is_archived, c.archived_at, c.created_at, c.teacher_id,
            t.first_name AS teacher_first_name, t.last_name AS teacher_last_name,
            (SELECT COUNT(*) FROM enrollments e WHERE e.class_id = c.class_id) AS enrolled_count,
            (SELECT COUNT(*) FROM exams x WHERE x.class_id = c.class_id) AS exam_count
     FROM classes c
     LEFT JOIN users t ON t.user_id = c.teacher_id
     $whereSql
     ORDER BY c.created_at DESC, c.class_id DESC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($classes as &$classRow) {
    addEffectiveArchiveFields($classRow);
}
unset($classRow);

$sum = $pdo->query("SELECT
    (SELECT COUNT(*) FROM classes) AS total,
    (SELECT COUNT(*) FROM classes WHERE " . activeSql() . ") AS active,
    (SELECT COUNT(*) FROM classes WHERE " . archivedSql() . ") AS archived
")->fetch(PDO::FETCH_ASSOC);

sendSuccess([
    'page' => $page,
    'per_page' => $perPage,
    'total' => $total,
    'pages' => $pages,
    'summary' => [
        'total' => (int) $sum['total'],
        'active' => (int) $sum['active'],
        'archived' => (int) $sum['archived'],
    ],
    'classes' => $classes,
]);
