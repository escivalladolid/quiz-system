<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/exam_status.php';
require_once __DIR__ . '/../../helpers/exam_grading.php';

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

    $stmt = $pdo->prepare("SELECT e.*, c.subject_name, c.class_code,
        (SELECT COUNT(*) FROM exam_submissions s WHERE s.exam_id = e.exam_id) AS submission_count,
        (SELECT COUNT(*) FROM enrollments en WHERE en.class_id = e.class_id) AS total_students
        FROM exams e JOIN classes c ON e.class_id=c.class_id WHERE e.exam_id=? AND c.teacher_id=?");
    $stmt->execute([$exam_id, $teacher_id]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$exam) {
        sendError('Exam not found.', 'NOT_FOUND', 404);
    }

    $stmt2 = $pdo->prepare("SELECT * FROM questions WHERE exam_id=? ORDER BY order_num ASC");
    $stmt2->execute([$exam_id]);
    $questions = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    foreach ($questions as &$q) {
        $q['question_type'] = questionTypeForStorage($q['question_type'] ?? '');
        if ($q['options']) {
            $q['options'] = json_decode($q['options'], true);
        }
    }
    unset($q);

    sendSuccess([
        'exam' => $exam,
        'questions' => $questions
    ]);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
