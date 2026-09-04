<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('This endpoint only accepts POST requests.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo  = getDbConnection();
$user = requireRole($pdo, ['STUDENT']);
$input = getJsonInput();
requireFields($input, ['class_code']);

$code = strtoupper(trim($input['class_code']));

$stmt = $pdo->prepare('SELECT class_id, subject_code, subject_name, block FROM classes WHERE class_code = :code');
$stmt->execute(['code' => $code]);
$class = $stmt->fetch();

if (!$class) {
    sendError('No class found with that code.', 'CLASS_NOT_FOUND', 404);
}

$check = $pdo->prepare('SELECT 1 FROM enrollments WHERE user_id = :uid AND class_id = :cid');
$check->execute(['uid' => $user['user_id'], 'cid' => $class['class_id']]);
if ($check->fetch()) {
    sendError('You are already enrolled in this class.', 'ALREADY_ENROLLED', 409);
}

$pdo->prepare('INSERT INTO enrollments (user_id, class_id) VALUES (:uid, :cid)')
    ->execute(['uid' => $user['user_id'], 'cid' => $class['class_id']]);

sendSuccess([
    'message'      => 'Successfully joined the class.',
    'class_id'     => (int) $class['class_id'],
    'subject_code' => $class['subject_code'],
    'subject_name' => $class['subject_name'],
    'block'        => $class['block'],
]);
