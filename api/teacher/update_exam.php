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

if (!$input || !isset($input['exam_id'])) {
    requireFields($input ?? [], ['exam_id']);
}

$exam_id = $input['exam_id'];

try {
    $stmt = $pdo->prepare("SELECT e.exam_id FROM exams e JOIN classes c ON e.class_id=c.class_id WHERE e.exam_id=? AND c.teacher_id=?");
    $stmt->execute([$exam_id, $teacher_id]);
    if (!$stmt->fetch()) {
        sendError('Exam not found or not authorized.', 'NOT_FOUND', 404);
    }

    $stmt2 = $pdo->prepare("SELECT COUNT(*) AS cnt FROM exam_submissions WHERE exam_id=?");
    $stmt2->execute([$exam_id]);
    $subRow = $stmt2->fetch(PDO::FETCH_ASSOC);
    $hasSubmissions = ((int)$subRow['cnt']) > 0;

    $safeFields = ['exam_name', 'description', 'duration_minutes', 'passing_score', 'total_points', 'max_exit_attempts'];
    $lockedFields = ['randomize_questions', 'randomize_options'];

    $updates = [];
    $params = [];

    foreach ($safeFields as $field) {
        if (isset($input[$field])) {
            $updates[] = "$field=?";
            $params[] = $input[$field];
        }
    }

    if ($hasSubmissions) {
        $rejected = [];
        foreach ($lockedFields as $field) {
            if (isset($input[$field])) {
                $rejected[] = $field;
            }
        }
        if (!empty($input['questions'])) {
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

        // Scheduling controls: publishing sets SCHEDULED with a start time;
        // the automatic transition flips it to LIVE once the start time hits.
        // Times are always recomputed on publish so a stale end_time can never
        // instantly close a freshly scheduled exam. Unlimited exams (0 min)
        // get no end_time and stay open until manually closed.
        if (isset($input['status'])) {
            $newStatus = strtoupper((string)$input['status']);
            if (!in_array($newStatus, ['DRAFT', 'SCHEDULED'], true)) {
                sendError('Invalid status. Exams can be DRAFT or SCHEDULED here.', 'BAD_REQUEST', 400);
            }
            $updates[] = 'status=?';
            $params[] = $newStatus;

            if ($newStatus === 'SCHEDULED') {
                $duration = (int) ($input['duration_minutes'] ?? 60);
                $startTime = (!empty($input['start_time']))
                    ? $input['start_time']
                    : $pdo->query('SELECT NOW()')->fetchColumn();
                if (!empty($input['end_time'])) {
                    $endTime = $input['end_time'];
                } else {
                    $endTime = ($duration > 0)
                        ? date('Y-m-d H:i:s', strtotime($startTime . ' + ' . $duration . ' minutes'))
                        : null;
                }
                $updates[] = 'start_time=?';
                $params[] = $startTime;
                $updates[] = 'end_time=?';
                $params[] = $endTime;
            }
        } else {
            if (isset($input['start_time']) && !empty($input['start_time'])) {
                $updates[] = 'start_time=?';
                $params[] = $input['start_time'];
            }
            if (isset($input['end_time']) && !empty($input['end_time'])) {
                $updates[] = 'end_time=?';
                $params[] = $input['end_time'];
            }
        }

        if (!empty($input['questions']) && is_array($input['questions'])) {
            $pdo->prepare("DELETE FROM questions WHERE exam_id=?")->execute([$exam_id]);

            $ins = $pdo->prepare("INSERT INTO questions (exam_id, question_type, question_text, options, correct_answer, points, answer_matching, order_num) VALUES (?,?,?,?,?,?,?,?)");
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
                    $q['order_index'] ?? $idx
                ]);
            }
        }
    }

    if (empty($updates)) {
        if (!empty($input['questions']) && !$hasSubmissions) {
            sendSuccess(['message' => 'Exam questions updated successfully']);
        } else {
            sendError('No fields to update.', 'BAD_REQUEST', 400);
        }
    } else {
        $params[] = $exam_id;
        $stmt = $pdo->prepare("UPDATE exams SET " . implode(', ', $updates) . " WHERE exam_id=?");
        $stmt->execute($params);
        sendSuccess(['message' => 'Exam updated successfully']);
    }
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
