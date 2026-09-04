<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/exam_status.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('This endpoint only accepts POST requests.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo   = getDbConnection();
$user  = requireRole($pdo, ['STUDENT']);
$input = getJsonInput();
requireFields($input, ['exam_id']);

$examId = (int) $input['exam_id'];
$studentId = $user['user_id'];

// Sync time-based transitions so the status below is always current.
syncExamStatuses($pdo);

// Get exam with teacher info and question count
$examStmt = $pdo->prepare(
    'SELECT e.exam_id, e.exam_name, e.description, e.duration_minutes, e.status,
            e.total_points, e.passing_score, e.randomize_questions, e.randomize_options,
            e.max_exit_attempts, e.end_time,
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

// Students may start only LIVE exams.
$examStatus = strtoupper((string)$exam['status']);
if ($examStatus !== 'LIVE') {
    if (in_array($examStatus, ['DRAFT', 'SCHEDULED'], true)) {
        sendError('This exam is not open yet.', 'EXAM_NOT_OPEN', 403);
    }
    sendError('This exam is closed.', 'EXAM_CLOSED', 403);
}

// Verify student is enrolled in the class
$enrollCheck = $pdo->prepare('SELECT 1 FROM enrollments WHERE user_id = :uid AND class_id = :cid');
$enrollCheck->execute(['uid' => $studentId, 'cid' => $exam['class_id']]);
if (!$enrollCheck->fetch()) {
    sendError('You are not enrolled in this class.', 'NOT_ENROLLED', 403);
}

// Check if already submitted
$subCheck = $pdo->prepare(
    'SELECT submission_id, score, correct_count, total_questions, time_used_secs,
            submitted_at, exit_attempts, auto_submitted
     FROM exam_submissions WHERE exam_id = :eid AND user_id = :uid'
);
$subCheck->execute(['eid' => $examId, 'uid' => $studentId]);
$existing = $subCheck->fetch();

if ($existing) {
    sendError('You have already submitted this exam.', 'ALREADY_SUBMITTED', 409);
}

// Count questions for this exam
$countStmt = $pdo->prepare('SELECT COUNT(*) AS qcount FROM questions WHERE exam_id = :eid');
$countStmt->execute(['eid' => $examId]);
$questionCount = (int) $countStmt->fetch()['qcount'];

// Calculate total points from actual questions
$pointsStmt = $pdo->prepare('SELECT COALESCE(SUM(points), 0) AS total_pts FROM questions WHERE exam_id = :eid');
$pointsStmt->execute(['eid' => $examId]);
$totalPointsFromQuestions = (int) $pointsStmt->fetch()['total_pts'];

// Server timestamps in server-local time (aligned to MySQL by config/database.php).
// The app parses time_started/deadline with a device-local SimpleDateFormat, so
// sending UTC strings here made the deadline appear ~8 hours in the past and the
// exam instantly auto-submitted. Always send local wall-clock strings instead.
$now = date('Y-m-d H:i:s');
$durationMin = (int) $exam['duration_minutes'];
if ($durationMin > 0) {
    $deadline = date('Y-m-d H:i:s', strtotime($now . ' + ' . $durationMin . ' minutes'));
} elseif (!empty($exam['end_time'])) {
    // Unlimited exam with a server-set end time.
    $deadline = $exam['end_time'];
} else {
    // Unlimited exam (stays open until manually closed): far-future sentinel so
    // the client countdown never reaches zero and auto-submits immediately.
    $deadline = '2099-12-31 23:59:59';
}

sendSuccess([
    'exam_id'               => $exam['exam_id'],
    'exam_name'             => $exam['exam_name'],
    'description'           => $exam['description'],
    'duration_minutes'      => $exam['duration_minutes'],
    'status'                => $exam['status'],
    'total_points'          => $exam['total_points'],
    'total_points_from_questions' => $totalPointsFromQuestions,
    'passing_score'         => $exam['passing_score'] ?? null,
    'randomize_questions'   => (int) ($exam['randomize_questions'] ?? 0),
    'randomize_options'     => (int) ($exam['randomize_options'] ?? 0),
    'max_exit_attempts'     => $exam['max_exit_attempts'] ?? null,
    'teacher_name'          => trim($exam['teacher_first_name'] . ' ' . $exam['teacher_last_name']),
    'subject_name'          => $exam['subject_name'],
    'question_count'        => $questionCount,
    'show_results'          => 1,
    'time_started'          => $now,
    'deadline'              => $deadline,
]);
