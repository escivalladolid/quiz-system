<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/exam_status.php';
require_once __DIR__ . '/../../helpers/archive.php';

header('Content-Type: application/json');

$pdo = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

$teacher = requireRole($pdo, ['TEACHER']);
$teacher_id = $teacher['user_id'];

try {
    syncExamStatuses($pdo);

    $includeArchived = in_array(strtolower((string) ($_GET['include_archived'] ?? '0')), ['1', 'true', 'yes'], true);
    $classFilter = $includeArchived ? '' : ' AND ' . activeSql('c');
    $examFilter = $includeArchived ? '' : ' AND ' . activeSql('e');

    $stmt = $pdo->prepare("SELECT class_id FROM classes c WHERE teacher_id=?" . ($includeArchived ? '' : ' AND ' . activeSql('c')));
    $stmt->execute([$teacher_id]);
    $class_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$class_ids) {
        sendSuccess([
            'include_archived' => $includeArchived,
            'exams' => [],
            'stats' => ['total' => 0, 'draft' => 0, 'scheduled' => 0, 'live' => 0, 'closed' => 0, 'archived' => 0]
        ]);
        return;
    }

    $placeholders = str_repeat('?,', count($class_ids) - 1) . '?';

    $stmt = $pdo->prepare("SELECT e.*, c.subject_name, c.class_code, c.status AS class_status, c.is_archived AS class_is_archived,
        COALESCE(qtp.tp,0) AS total_points,
        (SELECT COUNT(*) FROM exam_submissions WHERE exam_id=e.exam_id) AS submission_count,
        (SELECT AVG(CASE WHEN qtp2.tp > 0 THEN (es.score / qtp2.tp) * 100 END) FROM exam_submissions es LEFT JOIN (SELECT exam_id, COALESCE(SUM(points),0) AS tp FROM questions GROUP BY exam_id) qtp2 ON qtp2.exam_id=es.exam_id WHERE es.exam_id=e.exam_id) AS avg_score
        FROM exams e JOIN classes c ON e.class_id=c.class_id LEFT JOIN (SELECT exam_id, COALESCE(SUM(points),0) AS tp FROM questions GROUP BY exam_id) qtp ON qtp.exam_id=e.exam_id
        WHERE e.class_id IN ($placeholders) $classFilter $examFilter ORDER BY e.created_at DESC");
    $stmt->execute($class_ids);
    $exams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats = ['total' => count($exams), 'draft' => 0, 'scheduled' => 0, 'live' => 0, 'closed' => 0, 'archived' => 0];
    foreach ($exams as &$exam) {
        if ((int) ($exam['is_archived'] ?? 0) === 1
            || (int) ($exam['class_is_archived'] ?? 0) === 1
            || strtoupper((string) ($exam['status'] ?? '')) === 'ARCHIVED'
            || strtoupper((string) ($exam['class_status'] ?? '')) === 'ARCHIVED') {
            $exam['status'] = 'ARCHIVED';
        }
        $status = strtolower($exam['status']);
        if (isset($stats[$status])) {
            $stats[$status]++;
        }
    }
    unset($exam);

    sendSuccess([
        'include_archived' => $includeArchived,
        'exams' => $exams,
        'stats' => $stats
    ]);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
