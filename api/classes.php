<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('This endpoint only accepts GET requests.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo    = getDbConnection();
$user   = requireRole($pdo, ['STUDENT']);

$stmt = $pdo->prepare(
    'SELECT c.class_id, c.subject_code, c.subject_name, c.block, c.class_code,
            CONCAT(u.first_name, \' \', u.last_name) AS teacher_name
     FROM classes c
     JOIN enrollments e ON e.class_id = c.class_id
     JOIN users u ON u.user_id = c.teacher_id
     WHERE e.user_id = :uid
     ORDER BY c.subject_code ASC'
);
$stmt->execute(['uid' => $user['user_id']]);
$classes = $stmt->fetchAll();

sendSuccess(['classes' => $classes]);
