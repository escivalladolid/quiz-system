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
            s.correct_count, s.total_questions
     FROM exams e
     LEFT JOIN exam_submissions s ON s.exam_id = e.exam_id AND s.user_id = :uid
     WHERE e.class_id = :cid
     ORDER BY FIELD(e.status, \'LIVE\', \'SCHEDULED\', \'DRAFT\', \'CLOSED\', \'ARCHIVED\'), e.exam_name ASC'
);
$examStmt->execute(['uid' => $user['user_id'], 'cid' => $classId]);
$exams = $examStmt->fetchAll();

// Normalize to canonical score model: score = correct count, percentage derived.
foreach ($exams as &$ex) {
    $correct = $ex['correct_count'] !== null ? (int) $ex['correct_count'] : null;
    $total   = $ex['total_questions'] !== null ? (int) $ex['total_questions'] : null;
    $ex['score']      = $correct;
    $ex['percentage'] = ($correct !== null && $total > 0) ? round(($correct / $total) * 100, 2) : null;
}
unset($ex);

sendSuccess([
    'class' => $classInfo,
    'exams' => $exams,
]);
