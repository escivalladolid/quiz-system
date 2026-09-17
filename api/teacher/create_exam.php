<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/exam_grading.php';
require_once __DIR__ . '/../../helpers/exam_builder.php';
require_once __DIR__ . '/../../helpers/exam_status.php';

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

$input['duration_minutes'] = validateExamDuration($input['duration_minutes']);
$input['passing_score'] = filter_var($input['passing_score'], FILTER_VALIDATE_INT);
if ($input['passing_score'] === false || $input['passing_score'] < 1 || $input['passing_score'] > 100) {
    sendError('Passing score must be between 1 and 100.', 'BAD_REQUEST', 422);
}
$input['max_exit_attempts'] = filter_var($input['max_exit_attempts'] ?? 3, FILTER_VALIDATE_INT);
if ($input['max_exit_attempts'] === false || $input['max_exit_attempts'] < 1 || $input['max_exit_attempts'] > 10) {
    sendError('Maximum exit attempts must be between 1 and 10.', 'BAD_REQUEST', 422);
}

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

    $startTime = normalizeExamDateTime($input['start_time'] ?? null, 'Availability start time');
    $endTime = normalizeExamDateTime($input['end_time'] ?? null, 'Availability end time');
    validateExamAvailability($startTime, $endTime);

    if ($status === 'SCHEDULED') {
        if ($startTime === null || $startTime === '') {
            $startTime = $pdo->query('SELECT NOW()')->fetchColumn(); // server time
        }
        // Availability is independent of the per-student duration. A blank
        // end time means the exam remains available until manually closed;
        // duration_minutes still limits each student's individual attempt.
        validateExamAvailability($startTime, $endTime);
    }

    if (true) {
        $publishing = $status !== 'DRAFT';
        $input['questions'] = $input['questions'] ?? [];
        if (!is_array($input['questions'])) throw new InvalidArgumentException('Questions must be a list.');
        $input['questions'] = prepareBuilderQuestions($input['questions'], $publishing);
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("INSERT INTO exams (class_id, exam_name, description, duration_minutes, passing_score, hold_scores, status, start_time, end_time, total_points, randomize_questions, randomize_options, max_exit_attempts) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $input['class_id'],
        $input['exam_name'],
        $input['description'] ?? '',
        $input['duration_minutes'],
        $input['passing_score'],
        !empty($input['hold_scores']) ? 1 : 0,
        $status,
        $startTime,
        $endTime,
        $input['total_points'] ?? 100,
        isset($input['randomize_questions']) ? ($input['randomize_questions'] ? 1 : 0) : 0,
        isset($input['randomize_options']) ? ($input['randomize_options'] ? 1 : 0) : 0,
        $input['max_exit_attempts']
    ]);

    $exam_id = $pdo->lastInsertId();

    if (!empty($input['questions']) && is_array($input['questions'])) {
        $stmt = $pdo->prepare("INSERT INTO questions (exam_id, question_text, question_type, options, correct_answer, points, answer_matching, answer_rules, order_num) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($input['questions'] as $i => $q) {
            $options = is_array($q['options'] ?? null) ? json_encode($q['options']) : ($q['options'] ?? null);
            $stmt->execute([
                $exam_id,
                $q['question_text'],
                questionTypeForStorage($q['question_type'] ?? 'MULTIPLE_CHOICE'),
                $options,
                $q['correct_answer'] ?? null,
                $q['points'] ?? 1,
                $q['answer_matching'] ?? 'EXACT',
                $q['answer_rules'] ?? null,
                $q['order_num'] ?? $i
            ]);
        }
    }

    $pdo->commit();

    sendSuccess(['exam_id' => $exam_id], 201);
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    sendError($e->getMessage(), 'INVALID_QUESTIONS', 422);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    sendError('Database error: ' . $e->getMessage(), 'DB_ERROR', 500);
}
