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

$classId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($classId <= 0) {
    sendError('Missing or invalid class id.', 'INVALID_ID', 422);
}

syncExamStatuses($pdo);

// Verify the student is enrolled
$enrollCheck = $pdo->prepare('SELECT 1 FROM enrollments WHERE user_id = :uid AND class_id = :cid');
$enrollCheck->execute(['uid' => $user['user_id'], 'cid' => $classId]);
if (!$enrollCheck->fetch()) {
    sendError('You are not enrolled in this class.', 'NOT_ENROLLED', 403);
}

// Get class info
$stmt = $pdo->prepare(
    'SELECT c.class_id, c.subject_code, c.subject_name, c.block, c.class_code,
            CONCAT(u.first_name, \' \', u.last_name) AS teacher_name
     FROM classes c
     JOIN users u ON u.user_id = c.teacher_id
     WHERE c.class_id = :cid'
);
$stmt->execute(['cid' => $classId]);
$classInfo = $stmt->fetch();

if (!$classInfo) {
    sendError('Class not found.', 'NOT_FOUND', 404);
}

// Get exams for this class
$examStmt = $pdo->prepare(
    'SELECT e.exam_id, e.exam_name, e.duration_minutes, e.status, e.total_points,
            s.score, s.correct_count, s.total_questions, qtp.tp
     FROM exams e
     LEFT JOIN exam_submissions s ON s.exam_id = e.exam_id AND s.user_id = :uid
     LEFT JOIN (
         SELECT exam_id, COALESCE(SUM(points),0) AS tp FROM questions GROUP BY exam_id
     ) qtp ON qtp.exam_id = e.exam_id
     WHERE e.class_id = :cid
     ORDER BY FIELD(e.status, \'LIVE\', \'SCHEDULED\', \'DRAFT\', \'CLOSED\', \'ARCHIVED\'), e.exam_name ASC'
);
$examStmt->execute(['uid' => $user['user_id'], 'cid' => $classId]);
$exams = $examStmt->fetchAll();

// Normalize to the variable-point score model: score = earned points (stored),
// max_points = SUM of the exam's question points, percentage derived.
foreach ($exams as &$ex) {
    $examId       = (int) $ex['exam_id'];
    $earned       = $ex['score'] !== null ? (int) $ex['score'] : null;
    $totalPts     = $ex['score'] !== null ? (int) ($ex['tp'] ?? 0) : null;
    $ex['score']         = $earned;
    $ex['earned_points'] = $earned;
    $ex['total_points']  = $totalPts;
    $ex['max_points']    = $totalPts;
    $ex['percentage']    = ($earned !== null && $totalPts > 0) ? round(($earned / $totalPts) * 100, 2) : null;
}
unset($ex);

sendSuccess([
    'class' => $classInfo,
    'exams' => $exams,
]);
