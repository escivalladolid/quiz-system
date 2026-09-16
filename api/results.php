<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/exam_grading.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('This endpoint only accepts GET requests.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo    = getDbConnection();
$user   = requireRole($pdo, ['STUDENT']);

/**
 * Determine what a student may see for an exam + this student's submission.
 *
 * Two independent gates:
 *   - review_available  = per-question detailed review. True when the exam is
 *     closed (or its deadline passed) OR the teacher released THIS student's
 *     submission early (exam_submissions.results_released = 1).
 *   - scores_visible    = aggregate score/percentage/passed. It is true for
 *     normal auto-graded exams after submission, or for a held exam once the
 *     teacher closes it. A per-student early release unlocks detailed review
 *     but does not bypass the hold-scores setting.
 *
 * Lazy auto-close: if the exam's scheduled end time has passed but the stored
 * flag wasn't flipped yet, flip it now so later reads are cheap.
 */
function resolveReviewAvailability(PDO $pdo, int $examId, bool $submissionReleased = false): array {
    $stmt = $pdo->prepare(
        'SELECT e.exam_id, e.is_closed, e.status, e.end_time, e.hold_scores
         FROM exams e WHERE e.exam_id = :eid'
    );
    $stmt->execute(['eid' => $examId]);
    $exam = $stmt->fetch();

    if (!$exam) {
        return [
            'review_available' => false,
            'scores_visible'   => false,
            'is_closed'        => false,
        ];
    }

    $pastDeadline = !empty($exam['end_time']) && (strtotime($exam['end_time']) < time());
    $isClosed = (int) $exam['is_closed'] === 1
        || strtoupper((string) ($exam['status'] ?? '')) === 'CLOSED';

    if (!$isClosed && $pastDeadline) {
        $pdo->prepare(
            'UPDATE exams SET is_closed = 1, closed_at = NOW() WHERE exam_id = :eid AND is_closed = 0'
        )->execute(['eid' => $examId]);
        $isClosed = true;
    }

    if ($isClosed) {
        return [
            'review_available' => true,
            'scores_visible'   => true,
            'is_closed'        => true,
        ];
    }

    $scoresVisible = (int) ($exam['hold_scores'] ?? 0) === 0;
    return [
        'review_available' => $scoresVisible || $submissionReleased,
        'scores_visible'   => $scoresVisible,
        'is_closed'        => false,
    ];
}

$examId = isset($_GET['exam_id']) ? (int) $_GET['exam_id'] : 0;

// Per-exam detail (used by the student result/review screen)
if ($examId > 0) {
    $stmt = $pdo->prepare(
        'SELECT s.submission_id, s.exam_id, s.score, s.correct_count, s.total_questions,
                s.time_used_secs, s.submitted_at, s.answers_json, s.results_released,
                e.exam_name, e.total_points AS max_points, e.passing_score,
                e.hold_scores,
                c.subject_code, c.subject_name
         FROM exam_submissions s
         JOIN exams e ON e.exam_id = s.exam_id
         JOIN classes c ON c.class_id = e.class_id
         WHERE s.user_id = :uid AND s.exam_id = :eid'
    );
    $stmt->execute(['uid' => $user['user_id'], 'eid' => $examId]);
    $result = $stmt->fetch();

    if (!$result) {
        sendError('No submission found for this exam.', 'NOT_FOUND', 404);
    }

    $availability = resolveReviewAvailability($pdo, $examId, (int) $result['results_released'] === 1);
    $reviewAvailable = $availability['review_available'];
    $scoresVisible   = $availability['scores_visible'];

    // Variable-point scoring: score is the stored earned points; total points
    // = SUM of the actual questions' points (authoritative, not exams.total_points).
    $earnedPoints  = (int) $result['score'];
    $totalPnStmt   = $pdo->prepare('SELECT COALESCE(SUM(points), 0) FROM questions WHERE exam_id = :eid');
    $totalPnStmt->execute(['eid' => $examId]);
    $totalPoints   = (int) $totalPnStmt->fetchColumn();
    $correctCount  = (int) $result['correct_count'];
    $totalQuestions = (int) $result['total_questions'];
    $percentage    = $totalPoints > 0 ? round(($earnedPoints / $totalPoints) * 100, 2) : 0.0;

    $passed = $result['passing_score'] !== null
        ? ($percentage >= (float) $result['passing_score'])
        : null;

    $payload = [
        'submission_id'     => (int) $result['submission_id'],
        'exam_id'           => (int) $result['exam_id'],
        'exam_name'         => $result['exam_name'],
        'subject_code'      => $result['subject_code'],
        'subject_name'      => $result['subject_name'],
        // Aggregate score fields stay null while a teacher-held exam is open.
        'score'             => $scoresVisible ? $earnedPoints : null,
        'earned_points'     => $scoresVisible ? $earnedPoints : null,
        'max_points'        => $totalPoints,
        'total_points'      => $totalPoints,
        'correct_count'     => $scoresVisible ? $correctCount : null,
        'total_questions'   => $totalQuestions,
        'percentage'        => $scoresVisible ? $percentage : null,
        'passed'            => $scoresVisible ? $passed : null,
        'time_used_secs'    => $result['time_used_secs'] !== null ? (int) $result['time_used_secs'] : null,
        'submitted_at'      => $result['submitted_at'],
        'review_available'  => $reviewAvailable,
        'scores_visible'    => $scoresVisible,
    ];

    // Detailed per-question review: available when the exam is closed OR the
    // teacher released this student's submission early.
    if ($reviewAvailable) {
        $payload['questions'] = buildReviewQuestions($pdo, $examId, $result['answers_json']);
    }

    sendSuccess($payload);
}

// List of all results for the student
$stmt = $pdo->prepare(
    'SELECT s.submission_id, s.exam_id, s.score, s.correct_count, s.total_questions,
            s.time_used_secs, s.submitted_at, s.results_released,
     e.exam_name, e.total_points AS max_points, e.passing_score,
            e.hold_scores,
            c.subject_code, c.subject_name
     FROM exam_submissions s
     JOIN exams e ON e.exam_id = s.exam_id
     JOIN classes c ON c.class_id = e.class_id
     WHERE s.user_id = :uid
     ORDER BY s.submitted_at DESC'
);
$stmt->execute(['uid' => $user['user_id']]);
$results = $stmt->fetchAll();

$payload = [];
$tpStmt = $pdo->query('SELECT exam_id, COALESCE(SUM(points),0) AS tp FROM questions GROUP BY exam_id');
$totalPointsByExam = $tpStmt->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($results as $r) {
    $availability = resolveReviewAvailability($pdo, (int) $r['exam_id'], (int) $r['results_released'] === 1);
    $reviewAvailable = $availability['review_available'];
    $scoresVisible   = $availability['scores_visible'];
    $earned = (int) $r['score'];
    $total  = (int) ($totalPointsByExam[(int) $r['exam_id']] ?? 0);
    $pct    = $total > 0 ? round(($earned / $total) * 100, 2) : 0.0;
    $payload[] = [
        'submission_id'    => (int) $r['submission_id'],
        'exam_id'          => (int) $r['exam_id'],
        'score'            => $scoresVisible ? $earned : null,
        'earned_points'    => $scoresVisible ? $earned : null,
        'total_points'     => $total,
        'correct_count'    => $scoresVisible ? (int) $r['correct_count'] : null,
        'total_questions'  => (int) $r['total_questions'],
        'percentage'       => $scoresVisible ? $pct : null,
        'passing_score'    => $r['passing_score'] !== null ? (float) $r['passing_score'] : null,
        'passed'           => $scoresVisible && $r['passing_score'] !== null
            ? $pct >= (float) $r['passing_score'] : null,
        'time_used_secs'   => $r['time_used_secs'] !== null ? (int) $r['time_used_secs'] : null,
        'submitted_at'     => $r['submitted_at'],
        'exam_name'        => $r['exam_name'],
        'max_points'       => $total,
        'subject_code'     => $r['subject_code'],
        'subject_name'     => $r['subject_name'],
        'review_available' => $reviewAvailable,
        'scores_visible'   => $scoresVisible,
    ];
}

sendSuccess(['results' => $payload]);
