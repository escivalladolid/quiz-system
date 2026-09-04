<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/exam_status.php';

header('Content-Type: application/json');

$pdo = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

$teacher = requireRole($pdo, ['TEACHER']);
$teacher_id = $teacher['user_id'];

try {
    syncExamStatuses($pdo);

    $stmt = $pdo->prepare("SELECT class_id FROM classes WHERE teacher_id=?");
    $stmt->execute([$teacher_id]);
    $class_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$class_ids) {
        sendSuccess([
            'exams' => [],
            'stats' => ['total' => 0, 'draft' => 0, 'scheduled' => 0, 'live' => 0, 'closed' => 0, 'archived' => 0]
        ]);
        return;
    }

    $placeholders = str_repeat('?,', count($class_ids) - 1) . '?';

    $stmt = $pdo->prepare("SELECT e.*, c.subject_name, c.class_code, (SELECT COUNT(*) FROM exam_submissions WHERE exam_id=e.exam_id) AS submission_count, (SELECT AVG(score) FROM exam_submissions WHERE exam_id=e.exam_id) AS avg_score FROM exams e JOIN classes c ON e.class_id=c.class_id WHERE e.class_id IN ($placeholders) ORDER BY e.created_at DESC");
    $stmt->execute($class_ids);
    $exams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats = ['total' => count($exams), 'draft' => 0, 'scheduled' => 0, 'live' => 0, 'closed' => 0, 'archived' => 0];
    foreach ($exams as $exam) {
        $status = strtolower($exam['status']);
        if (isset($stats[$status])) {
            $stats[$status]++;
        }
    }

    sendSuccess([
        'exams' => $exams,
        'stats' => $stats
    ]);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
