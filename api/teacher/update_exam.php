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

if (!$input || !isset($input['exam_id'])) {
    requireFields($input ?? [], ['exam_id']);
}

$exam_id = $input['exam_id'];

try {
    $stmt = $pdo->prepare("SELECT e.exam_id, e.start_time, e.end_time, e.max_exit_attempts FROM exams e JOIN classes c ON e.class_id=c.class_id WHERE e.exam_id=? AND c.teacher_id=?");
    $stmt->execute([$exam_id, $teacher_id]);
    $examRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$examRow) {
        sendError('Exam not found or not authorized.', 'NOT_FOUND', 404);
    }

    $stmt2 = $pdo->prepare("SELECT COUNT(*) AS cnt FROM exam_submissions WHERE exam_id=?");
    $stmt2->execute([$exam_id]);
    $subRow = $stmt2->fetch(PDO::FETCH_ASSOC);
    $hasSubmissions = ((int)$subRow['cnt']) > 0;

    if (isset($input['questions'])) {
        $statusStmt = $pdo->prepare('SELECT status FROM exams WHERE exam_id=?');
        $statusStmt->execute([$exam_id]);
        $publishing = strtoupper($input['status'] ?? $statusStmt->fetchColumn()) !== 'DRAFT';
        if (!is_array($input['questions'])) throw new InvalidArgumentException('Questions must be a list.');
        $input['questions'] = prepareBuilderQuestions($input['questions'], $publishing);
    }

    $pdo->beginTransaction();
    $safeFields = ['exam_name', 'description', 'duration_minutes', 'passing_score', 'hold_scores', 'total_points', 'max_exit_attempts'];
    $lockedFields = ['randomize_questions', 'randomize_options', 'duration_minutes', 'passing_score', 'hold_scores', 'total_points', 'max_exit_attempts', 'start_time', 'end_time'];

    $updates = [];
    $params = [];

    foreach ($safeFields as $field) {
        if (isset($input[$field])) {
            if ($field === 'duration_minutes') {
                $input[$field] = validateExamDuration($input[$field]);
            }
            if ($field === 'passing_score') {
                $passing = filter_var($input[$field], FILTER_VALIDATE_INT);
                if ($passing === false || $passing < 1 || $passing > 100) {
                    sendError('Passing score must be between 1 and 100.', 'BAD_REQUEST', 422);
                }
                $input[$field] = $passing;
            }
            if ($field === 'max_exit_attempts') {
                $maxExitAttempts = filter_var($input[$field], FILTER_VALIDATE_INT);
                if ($maxExitAttempts === false || $maxExitAttempts < 1 || $maxExitAttempts > 10) {
                    sendError('Maximum exit attempts must be between 1 and 10.', 'BAD_REQUEST', 422);
                }
                $input[$field] = $maxExitAttempts;
            }
            $updates[] = "$field=?";
            $params[] = $field === 'hold_scores' ? (!empty($input[$field]) ? 1 : 0) : $input[$field];
        }
    }

    if ($hasSubmissions) {
        $rejected = [];
        foreach ($lockedFields as $field) {
            if (isset($input[$field])) {
                $rejected[] = $field;
            }
        }
        if (isset($input['questions'])) {
            $rejected[] = 'questions';
        }

        if (!empty($rejected)) {
            sendError(
                'Cannot modify locked fields while students have submissions: ' . implode(', ', $rejected),
                'FIELDS_LOCKED', 409
            );
        }
    } else {
        if (isset($input['randomize_questions'])) {
            $updates[] = 'randomize_questions=?';
            $params[] = $input['randomize_questions'] ? 1 : 0;
        }
        if (isset($input['randomize_options'])) {
            $updates[] = 'randomize_options=?';
            $params[] = $input['randomize_options'] ? 1 : 0;
        }

        // Scheduling controls: publishing sets SCHEDULED with an availability
        // start time; the automatic transition flips it to LIVE once that
        // time hits. The availability end is independent of duration_minutes.
        // A blank end time means "until manually closed".
        if (isset($input['status'])) {
            $newStatus = strtoupper((string)$input['status']);
            if (!in_array($newStatus, ['DRAFT', 'SCHEDULED'], true)) {
                sendError('Invalid status. Exams can be DRAFT or SCHEDULED here.', 'BAD_REQUEST', 400);
            }
            $updates[] = 'status=?';
            $params[] = $newStatus;

            if ($newStatus === 'SCHEDULED') {
                $duration = (int) ($input['duration_minutes'] ?? 60);
                $startTime = normalizeExamDateTime($input['start_time'] ?? null, 'Availability start time');
                $endTime = normalizeExamDateTime($input['end_time'] ?? null, 'Availability end time');
                $startTime = $startTime ?? $pdo->query('SELECT NOW()')->fetchColumn();
                validateExamAvailability($startTime, $endTime);
                $updates[] = 'start_time=?';
                $params[] = $startTime;
                $updates[] = 'end_time=?';
                $params[] = $endTime;
            }
        } else {
            $normalizedStartForUpdate = array_key_exists('start_time', $input)
                ? normalizeExamDateTime($input['start_time'], 'Availability start time')
                : normalizeExamDateTime($examRow['start_time'] ?? null, 'Availability start time');
            $normalizedEndForUpdate = array_key_exists('end_time', $input)
                ? normalizeExamDateTime($input['end_time'], 'Availability end time')
                : normalizeExamDateTime($examRow['end_time'] ?? null, 'Availability end time');
            validateExamAvailability($normalizedStartForUpdate, $normalizedEndForUpdate);
            if (array_key_exists('start_time', $input)) {
                $updates[] = 'start_time=?';
                $params[] = $normalizedStartForUpdate;
            }
            if (array_key_exists('end_time', $input)) {
                $updates[] = 'end_time=?';
                $params[] = $normalizedEndForUpdate;
            }
        }

        if (isset($input['questions']) && is_array($input['questions'])) {
            $pdo->prepare("DELETE FROM questions WHERE exam_id=?")->execute([$exam_id]);

            $ins = $pdo->prepare("INSERT INTO questions (exam_id, question_type, question_text, options, correct_answer, points, answer_matching, answer_rules, order_num) VALUES (?,?,?,?,?,?,?,?,?)");
            foreach ($input['questions'] as $idx => $q) {
                $opts = isset($q['options']) ? json_encode($q['options']) : null;
                $ins->execute([
                    $exam_id,
                    questionTypeForStorage($q['question_type'] ?? 'MC'),
                    $q['question_text'] ?? '',
                    $opts,
                    $q['correct_answer'] ?? '',
                    $q['points'] ?? 1,
                    $q['answer_matching'] ?? 'EXACT',
                $q['answer_rules'] ?? null,
                    $q['order_index'] ?? $idx
                ]);
            }
        }
    }

    if (empty($updates)) {
        $pdo->commit();
        if (!empty($input['questions']) && !$hasSubmissions) {
            $confirmedMaxExitAttempts = filter_var($examRow['max_exit_attempts'] ?? null, FILTER_VALIDATE_INT);
            if ($confirmedMaxExitAttempts === false || $confirmedMaxExitAttempts < 1 || $confirmedMaxExitAttempts > 10) {
                sendError('The exam has no valid maximum exit-attempt limit configured.', 'SERVER_MISCONFIGURED', 500);
            }
            sendSuccess([
                'message' => 'Exam questions updated successfully',
                'max_exit_attempts' => $confirmedMaxExitAttempts,
            ]);
        } else {
            sendError('No fields to update.', 'BAD_REQUEST', 400);
        }
    } else {
        $params[] = $exam_id;
        $stmt = $pdo->prepare("UPDATE exams SET " . implode(', ', $updates) . " WHERE exam_id=?");
        $stmt->execute($params);
        $pdo->commit();
        $confirmedStmt = $pdo->prepare('SELECT max_exit_attempts FROM exams WHERE exam_id = ?');
        $confirmedStmt->execute([$exam_id]);
        $confirmedMaxExitAttempts = $confirmedStmt->fetchColumn();
        if ($confirmedMaxExitAttempts === false || $confirmedMaxExitAttempts === null) {
            sendError('The exam exit-attempt limit was not stored.', 'DB_ERROR', 500);
        }
        sendSuccess([
            'message' => 'Exam updated successfully',
            'max_exit_attempts' => (int) $confirmedMaxExitAttempts,
        ]);
    }
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    sendError($e->getMessage(), 'INVALID_QUESTIONS', 422);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    sendError('Database error: ' . $e->getMessage(), 'DB_ERROR', 500);
}
