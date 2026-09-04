<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/exam_status.php';

header('Content-Type: application/json');

$pdo = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

$teacher = requireRole($pdo, ['TEACHER']);
$teacher_id = $teacher['user_id'];

try {
    syncExamStatuses($pdo);

    $stmt = $pdo->prepare("SELECT first_name, last_name, username, email FROM users WHERE user_id=?");
    $stmt->execute([$teacher_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        sendError('User not found.', 'NOT_FOUND', 404);
    }

    $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM classes WHERE teacher_id=? AND status='ACTIVE'");
    $stmt2->execute([$teacher_id]);
    $classes_count = (int)$stmt2->fetchColumn();

    $stmt3 = $pdo->prepare("SELECT COALESCE(SUM(sc.student_count), 0) FROM (SELECT (SELECT COUNT(*) FROM enrollments WHERE class_id=c.class_id) AS student_count FROM classes c WHERE c.teacher_id=? AND c.status='ACTIVE') sc");
    $stmt3->execute([$teacher_id]);
    $students_count = (int)$stmt3->fetchColumn();

    $stmt4 = $pdo->prepare("SELECT COUNT(*) FROM exams e JOIN classes c ON e.class_id=c.class_id WHERE c.teacher_id=? AND e.status='LIVE'");
    $stmt4->execute([$teacher_id]);
    $live_exams_count = (int)$stmt4->fetchColumn();

    sendSuccess([
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'username' => $user['username'],
        'email' => $user['email'],
        'department' => '',
        'classes_count' => $classes_count,
        'students_count' => $students_count,
        'live_exams_count' => $live_exams_count
    ]);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
