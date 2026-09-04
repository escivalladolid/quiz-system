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
$class_id = $_GET['id'] ?? null;

if (!$class_id) {
    sendError('Missing class id parameter.', 'BAD_REQUEST', 400);
}

try {
    syncExamStatuses($pdo);

    $stmt = $pdo->prepare("SELECT c.*, (SELECT COUNT(*) FROM enrollments WHERE class_id=c.class_id) AS student_count FROM classes c WHERE c.class_id=? AND c.teacher_id=?");
    $stmt->execute([$class_id, $teacher_id]);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$class) {
        sendError('Class not found.', 'NOT_FOUND', 404);
    }

    $stmt2 = $pdo->prepare("SELECT u.user_id, u.first_name, u.last_name, u.username FROM users u JOIN enrollments e ON u.user_id=e.user_id WHERE e.class_id=?");
    $stmt2->execute([$class_id]);
    $students = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    $stmt3 = $pdo->prepare("SELECT e.*, (SELECT COUNT(*) FROM exam_submissions WHERE exam_id=e.exam_id) AS submission_count, (SELECT AVG(score) FROM exam_submissions WHERE exam_id=e.exam_id) AS avg_score, (SELECT COUNT(*) FROM questions WHERE exam_id=e.exam_id) AS question_count FROM exams e WHERE e.class_id=? ORDER BY e.created_at DESC");
    $stmt3->execute([$class_id]);
    $exams = $stmt3->fetchAll(PDO::FETCH_ASSOC);

    sendSuccess([
        'class' => $class,
        'students' => $students,
        'exams' => $exams
    ]);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
