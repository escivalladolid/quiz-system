<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/exam_status.php';
require_once __DIR__ . '/../helpers/exam_grading.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('This endpoint only accepts GET requests.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo    = getDbConnection();
$user   = requireRole($pdo, ['STUDENT']);

$examId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($examId <= 0) {
    sendError('Missing or invalid exam id.', 'INVALID_ID', 422);
}

syncExamStatuses($pdo);

// Get exam info and verify student is enrolled
$examStmt = $pdo->prepare(
    'SELECT e.exam_id, e.exam_name, e.description, e.duration_minutes, e.status,
            e.total_points, e.passing_score, e.randomize_questions, e.randomize_options,
            e.max_exit_attempts,
            c.class_id, c.subject_name,
            u.first_name AS teacher_first_name, u.last_name AS teacher_last_name
     FROM exams e
     JOIN classes c ON c.class_id = e.class_id
     JOIN users u ON u.user_id = c.teacher_id
     WHERE e.exam_id = :eid'
);
$examStmt->execute(['eid' => $examId]);
$exam = $examStmt->fetch();

if (!$exam) {
    sendError('Exam not found.', 'NOT_FOUND', 404);
}

$enrollCheck = $pdo->prepare('SELECT 1 FROM enrollments WHERE user_id = :uid AND class_id = :cid');
$enrollCheck->execute(['uid' => $user['user_id'], 'cid' => $exam['class_id']]);
if (!$enrollCheck->fetch()) {
    sendError('You are not enrolled in this class.', 'NOT_ENROLLED', 403);
}

// Check if already submitted
$subCheck = $pdo->prepare(
    'SELECT submission_id, score, exit_attempts, auto_submitted, answers_json
     FROM exam_submissions WHERE exam_id = :eid AND user_id = :uid'
);
$subCheck->execute(['eid' => $examId, 'uid' => $user['user_id']]);
$existing = $subCheck->fetch();

// The student's previous answers (if already submitted) so the exam screen can
// pre-populate / display what they already answered. Never expose correct answers.
$previousAnswers = [];
if ($existing && $existing['answers_json']) {
    $decoded = json_decode($existing['answers_json'], true);
    if (is_array($decoded)) {
        $previousAnswers = $decoded;
    }
}

// No submission yet: restore answers that were auto-saved mid-attempt so the
// student can continue exactly where they left off after the app was closed.
if (!$existing) {
    try {
        $pdo->query('SELECT 1 FROM exam_temp_answers LIMIT 1');
        $tempStmt = $pdo->prepare(
            'SELECT answers_json FROM exam_temp_answers
             WHERE exam_id = :eid AND user_id = :uid'
        );
        $tempStmt->execute(['eid' => $examId, 'uid' => $user['user_id']]);
        $tempRow = $tempStmt->fetch();
        if ($tempRow && !empty($tempRow['answers_json'])) {
            $decodedTemp = json_decode($tempRow['answers_json'], true);
            if (is_array($decodedTemp)) {
                $previousAnswers = $decodedTemp;
            }
        }
    } catch (PDOException $e) {
        // exam_temp_answers table missing; nothing to restore.
    }
}

// Students may enter only LIVE exams. If the exam closed while they were
// taking it, block further access unless a submission already exists (so
// results remain viewable).
$examStatus = strtoupper((string)$exam['status']);
if ($examStatus !== 'LIVE' && !$existing) {
    sendError('This exam is not available for taking right now.', 'EXAM_NOT_OPEN', 403);
}

// Build question query based on randomize_questions setting
$orderClause = $exam['randomize_questions'] ? 'ORDER BY RAND()' : 'ORDER BY order_num ASC';

$qStmt = $pdo->prepare(
    "SELECT question_id, question_text, question_type, options,
            points, answer_matching, order_num
     FROM questions WHERE exam_id = :eid $orderClause"
);
$qStmt->execute(['eid' => $examId]);
$questions = $qStmt->fetchAll();

// Process questions: strip correct_answer, normalize options for each type
foreach ($questions as &$q) {
    // Never expose correct_answer to client
    unset($q['correct_answer']);

    $type = normalizeQuestionType($q['question_type'] ?? '');
    $q['question_type'] = $type;

    // Decode options JSON
    $jsonOptions = $q['options'] ? json_decode($q['options'], true) : null;

    if ($type === 'MC') {
        // Options live in the JSON column.
        $q['options'] = is_array($jsonOptions) ? array_values($jsonOptions) : [];
    } elseif ($type === 'TF') {
        // Force standard True/False options regardless of what's in the DB
        $q['options'] = ['True', 'False'];
    } elseif ($type === 'ID') {
        // No options needed for identification
        $q['options'] = null;
    } elseif ($type === 'ENUM') {
        $q['options'] = null;
        if (is_array($jsonOptions)) {
            $q['expected_count'] = count($jsonOptions);
        }
    }

    // Attach the student's own previous answer for display when resuming.
    // Available for already-submitted exams and for in-progress attempts that
    // have auto-saved answers in exam_temp_answers.
    if ($existing || !empty($previousAnswers)) {
        $prev = $previousAnswers[(string) $q['question_id']] ?? null;
        $q['student_answer'] = ($prev !== null)
            ? resolveOptionLetter($prev, $q['options'] ?? null, $type)
            : null;
    }
}
unset($q); // Break the reference left by the foreach above; otherwise the
           // next foreach over $questions silently overwrites the last element

// Calculate total points from actual questions (sum of individual question points)
$totalPointsFromQuestions = 0;
foreach ($questions as $q) {
    $totalPointsFromQuestions += (int) ($q['points'] ?? 1);
}

sendSuccess([
    'exam' => [
        'exam_id'              => $exam['exam_id'],
        'exam_name'            => $exam['exam_name'],
        'description'          => $exam['description'],
        'duration_minutes'     => $exam['duration_minutes'],
        'status'               => $exam['status'],
        'total_points'         => $exam['total_points'],
        'passing_score'        => $exam['passing_score'] ?? null,
        'total_points_from_questions' => $totalPointsFromQuestions,
        'randomize_questions'  => (int) ($exam['randomize_questions'] ?? 0),
        'randomize_options'    => (int) ($exam['randomize_options'] ?? 0),
        'max_exit_attempts'    => $exam['max_exit_attempts'] ?? null,
        'teacher_name'         => trim($exam['teacher_first_name'] . ' ' . $exam['teacher_last_name']),
    ],
    'questions'  => $questions,
    'submitted'  => $existing ? true : false,
    'previous_answers' => $existing ? $previousAnswers : null,
    'score'      => $existing ? $existing['score'] : null,
    'exit_attempts' => $existing ? $existing['exit_attempts'] : null,
    'auto_submitted' => $existing ? (bool) $existing['auto_submitted'] : null,
]);
