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
$exam_id = $_GET['id'] ?? null;

if (!$exam_id) {
    sendError('Missing exam id parameter.', 'BAD_REQUEST', 400);
}

try {
    syncExamStatuses($pdo);

    $stmt = $pdo->prepare("SELECT e.exam_id FROM exams e JOIN classes c ON e.class_id=c.class_id WHERE e.exam_id=? AND c.teacher_id=?");
    $stmt->execute([$exam_id, $teacher_id]);
    if (!$stmt->fetch()) {
        sendError('Exam not found or not authorized.', 'NOT_FOUND', 404);
    }

    $stmt2 = $pdo->prepare("SELECT COUNT(*) AS cnt FROM exam_submissions WHERE exam_id=?");
    $stmt2->execute([$exam_id]);
    $row = $stmt2->fetch(PDO::FETCH_ASSOC);

    $count = (int)$row['cnt'];

    sendSuccess([
        'has_submissions' => $count > 0,
        'submission_count' => $count
    ]);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
