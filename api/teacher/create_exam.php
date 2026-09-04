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

requireFields($input, ['class_id', 'exam_name', 'duration_minutes', 'passing_score']);

try {
    $stmt = $pdo->prepare("SELECT class_id FROM classes WHERE class_id=? AND teacher_id=?");
    $stmt->execute([$input['class_id'], $teacher_id]);
    if (!$stmt->fetch()) {
        sendError('Class not found or not authorized.', 'NOT_FOUND', 404);
    }

    // A new exam starts as a DRAFT. Publishing schedules it; the automatic
    // transition (helpers/exam_status.php) flips SCHEDULED -> LIVE when the
    // start time arrives.
    $status = strtoupper((string)($input['status'] ?? 'DRAFT'));
    if (!in_array($status, ['DRAFT', 'SCHEDULED'], true)) {
        $status = 'DRAFT';
    }

    $startTime = null;
    $endTime = null;

    if ($status === 'SCHEDULED') {
        $startTime = $input['start_time'] ?? null;
        $endTime = $input['end_time'] ?? null;

        if ($startTime === null || $startTime === '') {
            $startTime = $pdo->query('SELECT NOW()')->fetchColumn(); // server time
        }
        // Unlimited exams (0 minutes) stay open until manually closed.
        if ($endTime === null || $endTime === '') {
            $duration = (int) $input['duration_minutes'];
            $endTime = ($duration > 0)
                ? date('Y-m-d H:i:s', strtotime($startTime . ' + ' . $duration . ' minutes'))
                : null;
        }
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("INSERT INTO exams (class_id, exam_name, description, duration_minutes, passing_score, status, start_time, end_time, total_points, randomize_questions, randomize_options, max_exit_attempts) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $input['class_id'],
        $input['exam_name'],
        $input['description'] ?? '',
        $input['duration_minutes'],
        $input['passing_score'],
        $status,
        $startTime,
        $endTime,
        $input['total_points'] ?? 100,
        isset($input['randomize_questions']) ? ($input['randomize_questions'] ? 1 : 0) : 0,
        isset($input['randomize_options']) ? ($input['randomize_options'] ? 1 : 0) : 0,
        $input['max_exit_attempts'] ?? 3
    ]);

    $exam_id = $pdo->lastInsertId();

    if (!empty($input['questions']) && is_array($input['questions'])) {
        $stmt = $pdo->prepare("INSERT INTO questions (exam_id, question_text, question_type, options, correct_answer, points, answer_matching, order_num) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($input['questions'] as $i => $q) {
            $options = is_array($q['options']) ? json_encode($q['options']) : ($q['options'] ?? null);
            $stmt->execute([
                $exam_id,
                $q['question_text'],
                questionTypeForStorage($q['question_type'] ?? 'MULTIPLE_CHOICE'),
                $options,
                $q['correct_answer'] ?? null,
                $q['points'] ?? 1,
                $q['answer_matching'] ?? 'EXACT',
                $q['order_num'] ?? $i
            ]);
        }
    }

    $pdo->commit();

    sendSuccess(['exam_id' => $exam_id], 201);
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
