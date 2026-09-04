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
            e.total_points, c.subject_code, c.subject_name, c.block,
            s.score, s.correct_count, s.total_questions, s.submission_id
     FROM exams e
     JOIN classes c ON c.class_id = e.class_id
     JOIN enrollments en ON en.class_id = c.class_id AND en.user_id = :uid
     LEFT JOIN exam_submissions s ON s.exam_id = e.exam_id AND s.user_id = :uid2
     ORDER BY FIELD(e.status, 'LIVE', 'SCHEDULED', 'DRAFT', 'CLOSED', 'ARCHIVED'), e.exam_name ASC'
);
$stmt->execute(['uid' => $user['user_id'], 'uid2' => $user['user_id']]);
$exams = $stmt->fetchAll();

sendSuccess(['exams' => $exams]);
