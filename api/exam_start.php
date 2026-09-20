<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/exam_status.php';
require_once __DIR__ . '/../helpers/exam_monitoring.php';
require_once __DIR__ . '/../helpers/archive.php';

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

// 'instructions' (default) only returns exam metadata and must NOT start the
// timer. 'start' records the attempt the first time the student begins and
// returns the SAME deadline on every later reopen of that attempt.
$action  = strtolower((string) ($input['action'] ?? 'instructions'));
$starting = ($action === 'start');

// Sync time-based transitions so the status below is always current.
syncExamStatuses($pdo);

// Get exam with teacher info and question count
$examStmt = $pdo->prepare(
    'SELECT e.exam_id, e.exam_name, e.description, e.duration_minutes, e.status,
            e.total_points, e.passing_score, e.randomize_questions, e.randomize_options,
            e.max_exit_attempts, e.start_time, e.end_time, e.is_closed, e.hold_scores,
            e.is_archived, e.archived_at, c.status AS class_status, c.is_archived AS class_is_archived,
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

$maxExitAttempts = filter_var($exam['max_exit_attempts'] ?? null, FILTER_VALIDATE_INT);
if ($maxExitAttempts === false || $maxExitAttempts < 1 || $maxExitAttempts > 10) {
    sendError('This exam has no valid maximum exit-attempt limit configured.', 'SERVER_MISCONFIGURED', 500);
}

if ((int) ($exam['is_archived'] ?? 0) === 1 || (int) ($exam['class_is_archived'] ?? 0) === 1
    || strtoupper((string) ($exam['status'] ?? '')) === 'ARCHIVED'
    || strtoupper((string) ($exam['class_status'] ?? '')) === 'ARCHIVED') {
    sendError('This exam has been archived and is no longer available.', 'EXAM_ARCHIVED', 403);
}

// Keep the availability window authoritative even if a status transition is
// briefly stale (the status sync is intentionally throttled). This prevents a
// new attempt from starting before the scheduled opening or after the close.
$nowTimestamp = time();
if (!empty($exam['start_time']) && strtotime($exam['start_time']) > $nowTimestamp) {
    sendError('This exam is not open yet.', 'EXAM_NOT_OPEN', 403);
}
if (!empty($exam['end_time']) && strtotime($exam['end_time']) <= $nowTimestamp) {
    sendError('This exam is closed.', 'EXAM_CLOSED', 403);
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
// The *_epoch fields are absolute instants (UTC) so the app can count down
// without any timezone guessing; the wall-clock strings are kept for legacy UI.
$now = date('Y-m-d H:i:s');
$durationMin = (int) $exam['duration_minutes'];

// Attempt timestamps are persisted so that:
//   - viewing the instructions screen never touches the timer, and
//   - reopening an attempt preserves the ORIGINAL deadline instead of granting
//     a fresh full duration.
$attempt = null;
if ($starting) {
    if ($durationMin > 0) {
        $deadline = date('Y-m-d H:i:s', strtotime($now . ' + ' . $durationMin . ' minutes'));
        if (!empty($exam['end_time']) && strtotime($exam['end_time']) < strtotime($deadline)) {
            // A timed attempt never extends past the exam's scheduled close.
            $deadline = $exam['end_time'];
        }
    } elseif (!empty($exam['end_time'])) {
        // Unlimited exam with a server-set end time.
        $deadline = $exam['end_time'];
    } else {
        // Unlimited exam (stays open until manually closed): far-future sentinel so
        // the client countdown never reaches zero and auto-submits immediately.
        $deadline = '2099-12-31 23:59:59';
    }

    $hasAttemptTable = true;
    try {
        $pdo->query('SELECT 1 FROM exam_attempts LIMIT 1');
    } catch (PDOException $e) {
        $hasAttemptTable = false;
    }

    if (!$hasAttemptTable) {
        sendError(
            'The exam_attempts table is missing on the server. Ask your administrator to apply the migration (migration_exam_attempts.sql).',
            'SERVER_MISCONFIGURED',
            500
        );
    }

    $getAttempt = $pdo->prepare(
        'SELECT attempt_id, started_at, deadline_at FROM exam_attempts
         WHERE exam_id = :eid AND user_id = :uid'
    );
    $getAttempt->execute(['eid' => $examId, 'uid' => $studentId]);
    $attempt = $getAttempt->fetch();

    if (!$attempt) {
        try {
            $pdo->prepare(
                'INSERT INTO exam_attempts (exam_id, user_id, started_at, deadline_at, created_at)
                 VALUES (:eid, :uid, :started, :deadline, NOW())'
            )->execute([
                'eid'      => $examId,
                'uid'      => $studentId,
                'started'  => $now,
                'deadline' => $deadline,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') throw $e;
            // A concurrent start created the attempt (same or near-identical
            // timestamps); read the winner's row instead of erroring.
        }
        $getAttempt->execute(['eid' => $examId, 'uid' => $studentId]);
        $attempt = $getAttempt->fetch();
    }
    // Attempt already exists (reopen): the stored started_at/deadline_at
    // are returned untouched below.
}

$startedAt = $attempt['started_at'] ?? null;
$deadlineAt = $attempt['deadline_at'] ?? null;

if ($starting && $attempt) {
    $activityLogFailed = false;
    recordExamActivity($pdo, $examId, $studentId, 'EXAM_STARTED', [
        'attempt_id' => $attempt ? (int) $attempt['attempt_id'] : null,
        'total_questions' => $questionCount,
        'network_state' => 'ONLINE',
    ], $activityLogFailed);
    if ($activityLogFailed || !examActivityLogAvailable($pdo)) {
        recordLegacyExamActivity($pdo, $examId, $studentId, 'EXAM_STARTED', $attempt ? (int) $attempt['attempt_id'] : null);
    }
}

sendSuccess([
    'exam_id'               => $exam['exam_id'],
    'exam_name'             => $exam['exam_name'],
    'description'           => $exam['description'],
    'duration_minutes'      => $exam['duration_minutes'],
    'status'                => effectiveArchiveStatus($exam),
    'is_archived'           => ((int) ($exam['is_archived'] ?? 0) === 1 || (int) ($exam['class_is_archived'] ?? 0) === 1) ? 1 : 0,
    'archived_at'           => $exam['archived_at'] ?? null,
    'total_points'          => $exam['total_points'],
    'total_points_from_questions' => $totalPointsFromQuestions,
    'passing_score'         => $exam['passing_score'] ?? null,
    'randomize_questions'   => (int) ($exam['randomize_questions'] ?? 0),
    'randomize_options'     => (int) ($exam['randomize_options'] ?? 0),
    'max_exit_attempts'     => $maxExitAttempts,
    'teacher_name'          => trim($exam['teacher_first_name'] . ' ' . $exam['teacher_last_name']),
    'subject_name'          => $exam['subject_name'],
    'availability_start'    => $exam['start_time'],
    'availability_end'      => $exam['end_time'],
    'question_count'        => $questionCount,
    'hold_scores'           => (int) ($exam['hold_scores'] ?? 0),
    'show_results'          => ((int) $exam['is_closed'] === 1 || (int) ($exam['hold_scores'] ?? 0) === 0) ? 1 : 0,
    'started'               => $starting,
    'time_started'          => $startedAt,
    'deadline'              => $deadlineAt,
    'attempt_id'            => $attempt ? (int) $attempt['attempt_id'] : null,
    'time_started_epoch'    => $startedAt ? strtotime($startedAt) : null,
    'deadline_epoch'        => $deadlineAt ? strtotime($deadlineAt) : null,
]);
