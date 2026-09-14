<?php
/**
 * Server-side AJAX bridge for the admin panel.
 * The browser never sees the API session token: it posts a whitelisted
 * action here and this script performs the API call server-side with the
 * token stored in the PHP session, then returns the API's JSON body.
 */
require_once __DIR__ . '/inc/bootstrap.php';

header('Content-Type: application/json');

if (!admin_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Session expired. Please log in again.', 'code' => 'UNAUTHORIZED']);
    exit;
}

// Whitelisted actions: action => [API method, API path]
$routes = [
    'user_create'         => ['POST', 'admin/user_create.php'],
    'user_update'         => ['POST', 'admin/user_update.php'],
    'user_status'         => ['POST', 'admin/user_status.php'],
    'roster_import_student' => ['POST', 'admin/import_student_roster.php'],
    'roster_import_teacher' => ['POST', 'admin/import_teacher_roster.php'],
    'class_create'        => ['POST', 'admin/class_create.php'],
    'class_update'        => ['POST', 'admin/class_update.php'],
    'class_status'        => ['POST', 'admin/class_status.php'],
    'class_roster'        => ['GET', 'admin/class_roster.php'],
    'class_roster_update' => ['POST', 'admin/class_roster_update.php'],
    'exam_status'         => ['POST', 'admin/exam_status.php'],
    'exam_detail'         => ['GET', 'admin/exam_detail.php'],
    'session_kill'        => ['POST', 'admin/session_kill.php'],
];

$action = $_GET['action'] ?? '';
if (!isset($routes[$action])) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Unknown action.', 'code' => 'NOT_FOUND']);
    exit;
}

[$method, $path] = $routes[$action];

if ($_SERVER['REQUEST_METHOD'] !== $method) {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.', 'code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

if ($method === 'GET') {
    $payload = [];
    foreach ($_GET as $k => $v) {
        if ($k === 'action') continue;
        $payload[$k] = $v;
    }
} else {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw ?: '', true);
    if (!is_array($payload)) {
        $payload = [];
    }
}

$res = admin_api_request($method, $path, $payload, $_SESSION['admin_user']['token'] ?? null);

if ($res['http_code'] >= 400) {
    http_response_code($res['http_code']);
}

$ok = is_array($res['body']) && ($res['body']['success'] ?? false) === true;

if ($ok) {
    $flashMap = [
        'user_create'         => 'User created successfully.',
        'user_update'         => (((string) ($payload['password'] ?? '')) !== '' ? 'Password updated successfully.' : 'User updated successfully.'),
        'class_create'        => 'Class created successfully.',
        'class_update'        => 'Class updated successfully.',
        'class_roster_update' => 'Roster updated successfully.',
    ];

    if (isset($flashMap[$action])) {
        admin_flash_set('success', $flashMap[$action]);
    } elseif ($action === 'user_status') {
        $s = strtoupper((string) ($payload['status'] ?? ''));
        $label = ($s === 'BANNED' ? 'banned' : ($s === 'INACTIVE' ? 'suspended' : 'activated'));
        $count = is_array($payload['user_ids'] ?? null) ? count($payload['user_ids']) : 1;
        admin_flash_set('success', $label . ' ' . $count . ' user' . ($count === 1 ? '' : 's') . '.');
    } elseif ($action === 'class_status') {
        $archived = strtoupper((string) ($payload['status'] ?? '')) === 'ARCHIVED';
        admin_flash_set('success', $archived ? 'Class archived.' : 'Class restored.');
    } elseif ($action === 'exam_status') {
        $act = strtolower((string) ($payload['action'] ?? ''));
        $labels = ['force_close' => 'Exam force-closed.', 'archive' => 'Exam archived.', 'schedule' => 'Exam scheduled.'];
        admin_flash_set('success', $labels[$act] ?? 'Exam updated.');
    } elseif ($action === 'session_kill') {
        admin_flash_set('success', 'Session terminated.');
    } elseif ($action === 'roster_import_student') {
        admin_flash_set('success', 'Student roster imported.');
    } elseif ($action === 'roster_import_teacher') {
        admin_flash_set('success', 'Teacher roster imported.');
    }
}

echo json_encode(is_array($res['body']) && $res['body'] !== []
    ? $res['body']
    : ['success' => false, 'error' => 'Unexpected API response.', 'code' => 'UPSTREAM_ERROR']);