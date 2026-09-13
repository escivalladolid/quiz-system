<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/exam_status.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('This endpoint only accepts GET requests.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo    = getDbConnection();
$user   = requireRole($pdo, ['STUDENT']);

// Sync time-based transitions so every exam shows its current status.
syncExamStatuses($pdo);

// All exams for classes the student is enrolled in, with submission status
$stmt = $pdo->prepare(
    'SELECT e.exam_id, e.exam_name, e.duration_minutes, e.status AS exam_status,
            e.total_points, e.is_closed, c.subject_code, c.subject_name, c.block,
            s.score, s.correct_count, s.total_questions, s.submission_id, s.results_released
     FROM exams e
     JOIN classes c ON c.class_id = e.class_id
     JOIN enrollments en ON en.class_id = c.class_id AND en.user_id = :uid
     LEFT JOIN exam_submissions s ON s.exam_id = e.exam_id AND s.user_id = :uid2
     ORDER BY FIELD(e.status, \'LIVE\', \'SCHEDULED\', \'DRAFT\', \'CLOSED\', \'ARCHIVED\'), e.exam_name ASC'
);
$stmt->execute(['uid' => $user['user_id'], 'uid2' => $user['user_id']]);
$exams = $stmt->fetchAll();

$tpStmt = $pdo->query('SELECT exam_id, COALESCE(SUM(points),0) AS tp FROM questions GROUP BY exam_id');
$totalPointsByExam = $tpStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Normalize each exam to the variable-point score model:
// score = earned points (stored), max_points = SUM of the exam's question
// points, percentage = earned / max_points. Unsubmitted exams report nulls.
// scores_visible = exam fully closed (is_closed OR status CLOSED) — until
// then the aggregate score stays hidden from the student even if the teacher
// released their detailed review early (results_released = 1).
foreach ($exams as &$ex) {
    $examId     = (int) $ex['exam_id'];
    $earned     = $ex['score'] !== null ? (int) $ex['score'] : null;
    $totalPts   = $ex['score'] !== null ? (int) ($totalPointsByExam[$examId] ?? 0) : null;
    $scoresVisible = ((int) $ex['is_closed'] === 1) || strtoupper((string) $ex['exam_status']) === 'CLOSED';
    $ex['has_submission'] = $ex['submission_id'] !== null;
    $ex['review_available'] = $ex['submission_id'] !== null
        && ($scoresVisible || (int) $ex['results_released'] === 1);
    $ex['scores_visible'] = $scoresVisible;
    $ex['score']        = $scoresVisible ? $earned : null;
    $ex['earned_points']= $scoresVisible ? $earned : null;
    $ex['total_points'] = $scoresVisible ? $totalPts : $ex['total_points'];
    $ex['max_points']   = $scoresVisible ? $totalPts : $ex['total_points'];
    $ex['percentage']   = ($scoresVisible && $earned !== null && $totalPts > 0)
        ? round(($earned / $totalPts) * 100, 2)
        : null;
}
unset($ex);

sendSuccess(['exams' => $exams]);
