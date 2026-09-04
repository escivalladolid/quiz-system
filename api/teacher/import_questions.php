<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/exam_grading.php';

header('Content-Type: application/json');

$pdo = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

$teacher = requireRole($pdo, ['TEACHER']);
$teacher_id = $teacher['user_id'];
$input = getJsonInput();

if (!$input) {
    sendError('Invalid JSON input.', 'BAD_REQUEST', 400);
}

requireFields($input, ['exam_id', 'questions']);

if (!is_array($input['questions']) || empty($input['questions'])) {
    sendError('Questions must be a non-empty array.', 'BAD_REQUEST', 400);
}

try {
    $stmt = $pdo->prepare("SELECT e.exam_id FROM exams e JOIN classes c ON e.class_id=c.class_id WHERE e.exam_id=? AND c.teacher_id=?");
    $stmt->execute([$input['exam_id'], $teacher_id]);
    if (!$stmt->fetch()) {
        sendError('Exam not found or not authorized.', 'NOT_FOUND', 404);
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT COALESCE(MAX(order_num), -1) + 1 FROM questions WHERE exam_id=?");
    $stmt->execute([$input['exam_id']]);
    $next_index = (int)$stmt->fetchColumn();

    $count = 0;
    $insert = $pdo->prepare("INSERT INTO questions (exam_id, question_text, question_type, options, correct_answer, points, answer_matching, order_num) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($input['questions'] as $q) {
        $options = isset($q['options']) ? (is_array($q['options']) ? json_encode($q['options']) : $q['options']) : null;
        $insert->execute([
            $input['exam_id'],
            $q['question_text'],
            questionTypeForStorage($q['question_type'] ?? 'MULTIPLE_CHOICE'),
            $options,
            $q['correct_answer'] ?? null,
            $q['points'] ?? 1,
            $q['answer_matching'] ?? 'EXACT',
            $next_index + $count
        ]);
        $count++;
    }

    $pdo->commit();

    sendSuccess([
        'message' => "Successfully imported $count questions",
        'count' => $count
    ], 201);
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
