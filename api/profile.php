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

// Basic profile from session token user
$profile = [
    'user_id'    => (int) $user['user_id'],
    'first_name' => $user['first_name'],
    'last_name'  => $user['last_name'],
    'username'   => $user['username'],
    'email'      => $user['email'],
    'role'       => $user['role_name'],
];

// Extra student info
$stuStmt = $pdo->prepare('SELECT student_id, year_level, section FROM users WHERE user_id = :uid');
$stuStmt->execute(['uid' => $user['user_id']]);
$studentInfo = $stuStmt->fetch();
if ($studentInfo) {
    $profile['student_id'] = $studentInfo['student_id'];
    $profile['year_level'] = $studentInfo['year_level'];
    $profile['section']    = $studentInfo['section'];
}

// Enrolled class count
$cntStmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM enrollments WHERE user_id = :uid');
$cntStmt->execute(['uid' => $user['user_id']]);
$profile['enrolled_classes'] = (int) $cntStmt->fetch()['cnt'];

sendSuccess($profile);
