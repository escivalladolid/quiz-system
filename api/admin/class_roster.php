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

$class_id = (int) ($_GET['class_id'] ?? 0);
if ($class_id <= 0) {
    sendError('Missing class_id parameter.', 'BAD_REQUEST', 400);
}

try {
    $stmt = $pdo->prepare('SELECT class_id, subject_code, subject_name, class_code, block FROM classes WHERE class_id = ?');
    $stmt->execute([$class_id]);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$class) {
        sendError('Class not found.', 'NOT_FOUND', 404);
    }

    $students = $pdo->query(
        "SELECT user_id, first_name, last_name, username, student_id, year_level, section
         FROM users WHERE role_id = 1 AND status = 'ACTIVE'
         ORDER BY last_name, first_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare('SELECT user_id FROM enrollments WHERE class_id = ?');
    $stmt->execute([$class_id]);
    $enrolled = array_map(fn ($r) => (int) $r['user_id'], $stmt->fetchAll(PDO::FETCH_ASSOC));

    foreach ($students as &$s) {
        $s['user_id'] = (int) $s['user_id'];
        $s['enrolled'] = in_array((int) $s['user_id'], $enrolled, true);
    }
    unset($s);

    sendSuccess(['class' => $class, 'students' => $students, 'enrolled_count' => count($enrolled)]);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}