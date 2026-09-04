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

    $stmt = $pdo->prepare("SELECT c.*, (SELECT COUNT(*) FROM enrollments WHERE class_id=c.class_id) AS student_count, (SELECT COUNT(*) FROM exams WHERE class_id=c.class_id AND status='LIVE') AS active_exams_count FROM classes c WHERE c.teacher_id=? AND c.status='ACTIVE' ORDER BY c.created_at DESC");
    $stmt->execute([$teacher_id]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_classes = count($classes);
    $total_students = 0;
    foreach ($classes as $c) {
        $total_students += (int)$c['student_count'];
    }

    $placeholders = str_repeat('?,', count($classes) - 1) . '?';
    $class_ids = array_column($classes, 'class_id');
    $live_exams = 0;
    if ($class_ids) {
        $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM exams WHERE class_id IN ($placeholders) AND status='LIVE'");
        $stmt2->execute($class_ids);
        $live_exams = (int)$stmt2->fetchColumn();
    }

    sendSuccess([
        'classes' => $classes,
        'stats' => [
            'total_classes' => $total_classes,
            'total_students' => $total_students,
            'live_exams' => $live_exams
        ]
    ]);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
