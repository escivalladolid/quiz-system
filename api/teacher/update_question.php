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

if (!$input || !isset($input['question_id'])) {
    requireFields($input ?? [], ['question_id']);
}

try {
    $stmt = $pdo->prepare("SELECT q.question_id FROM questions q JOIN exams e ON q.exam_id=e.exam_id JOIN classes c ON e.class_id=c.class_id WHERE q.question_id=? AND c.teacher_id=?");
    $stmt->execute([$input['question_id'], $teacher_id]);
    if (!$stmt->fetch()) {
        sendError('Question not found or not authorized.', 'NOT_FOUND', 404);
    }

    $updates = [];
    $params = [];

    if (isset($input['question_text'])) {
        $updates[] = 'question_text=?';
        $params[] = $input['question_text'];
    }
    if (isset($input['question_type'])) {
        $updates[] = 'question_type=?';
        $params[] = questionTypeForStorage($input['question_type']);
    }
    if (isset($input['options'])) {
        $updates[] = 'options=?';
        $params[] = is_array($input['options']) ? json_encode($input['options']) : $input['options'];
    }
    if (isset($input['correct_answer'])) {
        $updates[] = 'correct_answer=?';
        $params[] = $input['correct_answer'];
    }
    if (isset($input['points'])) {
        $updates[] = 'points=?';
        $params[] = $input['points'];
    }
    if (isset($input['answer_matching'])) {
        if (!in_array($input['answer_matching'], ['EXACT', 'IGNORE_CASE'], true)) {
            sendError('Invalid answer matching mode.', 'BAD_REQUEST', 400);
        }
        $updates[] = 'answer_matching=?';
        $params[] = $input['answer_matching'];
    }
    if (isset($input['answer_rules'])) {
        if (is_array($input['answer_rules'])) {
            $rules = json_encode($input['answer_rules']);
        } else {
            $decoded = json_decode((string)$input['answer_rules'], true);
            $rules = is_array($decoded) ? json_encode($decoded) : false;
        }
        if ($rules === false || strlen($rules) > 4000) {
            sendError('Invalid answer rules.', 'BAD_REQUEST', 400);
        }
        $updates[] = 'answer_rules=?';
        $params[] = $rules;
    }
    if (isset($input['option_a'])) {
        $updates[] = 'option_a=?';
        $params[] = $input['option_a'];
    }
    if (isset($input['option_b'])) {
        $updates[] = 'option_b=?';
        $params[] = $input['option_b'];
    }
    if (isset($input['option_c'])) {
        $updates[] = 'option_c=?';
        $params[] = $input['option_c'];
    }
    if (isset($input['option_d'])) {
        $updates[] = 'option_d=?';
        $params[] = $input['option_d'];
    }
    if (isset($input['correct_option'])) {
        $updates[] = 'correct_option=?';
        $params[] = $input['correct_option'];
    }

    if (empty($updates)) {
        sendError('No fields to update.', 'BAD_REQUEST', 400);
    }

    $params[] = $input['question_id'];
    $stmt = $pdo->prepare("UPDATE questions SET " . implode(', ', $updates) . " WHERE question_id=?");
    $stmt->execute($params);

    sendSuccess(['message' => 'Question updated successfully']);
} catch (PDOException $e) {
    sendError('Database error: ' . $e->getMessage(), 'DB_ERROR', 500);
}
