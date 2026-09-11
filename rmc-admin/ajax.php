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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.', 'code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

// Whitelisted actions: action => [API method, API path]
$routes = [
    'user_create'         => ['POST', 'admin/user_create.php'],
    'user_update'         => ['POST', 'admin/user_update.php'],
    'user_status'         => ['POST', 'admin/user_status.php'],
    'class_create'        => ['POST', 'admin/class_create.php'],
    'class_update'        => ['POST', 'admin/class_update.php'],
    'class_status'        => ['POST', 'admin/class_status.php'],
    'class_roster'        => ['GET', 'admin/class_roster.php'],
    'class_roster_update' => ['POST', 'admin/class_roster_update.php'],
    'exam_status'         => ['POST', 'admin/exam_status.php'],
    'session_kill'        => ['POST', 'admin/session_kill.php'],
];

$action = $_GET['action'] ?? '';
if (!isset($routes[$action])) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Unknown action.', 'code' => 'NOT_FOUND']);
    exit;
}

[$method, $path] = $routes[$action];

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
echo json_encode(is_array($res['body']) && $res['body'] !== []
    ? $res['body']
    : ['success' => false, 'error' => 'Unexpected API response.', 'code' => 'UPSTREAM_ERROR']);