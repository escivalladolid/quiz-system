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
    // The database status field is the single source of truth. Apply the
    // automatic time-based transitions before reading any exam data.
    syncExamStatuses($pdo);

    // Get teacher's classes for filter chips
    $stmt = $pdo->prepare("SELECT class_id, subject_code, subject_name, block FROM classes WHERE teacher_id=? AND status='ACTIVE' ORDER BY subject_name");
    $stmt->execute([$teacher_id]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all exams across teacher's classes
    $stmt = $pdo->prepare("
        SELECT 
            e.exam_id,
            e.exam_name,
            e.status,
            e.duration_minutes,
            e.start_time,
            e.end_time,
            e.passing_score,
            e.total_points,
            c.class_id,
            c.subject_name,
            c.block,
            (SELECT COUNT(*) FROM enrollments WHERE class_id=c.class_id) AS total_students,
            (SELECT COUNT(*) FROM exam_submissions WHERE exam_id=e.exam_id) AS submission_count,
            (SELECT COUNT(*) FROM questions WHERE exam_id=e.exam_id) AS question_count,
            (SELECT ROUND(AVG(CASE WHEN total_questions > 0 THEN (correct_count / total_questions) * 100 END), 1) FROM exam_submissions WHERE exam_id=e.exam_id) AS class_average
        FROM exams e
        JOIN classes c ON e.class_id=c.class_id
        WHERE c.teacher_id=?
        ORDER BY e.created_at DESC
    ");
    $stmt->execute([$teacher_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group exams only by their actual database status.
    $draft = [];
    $scheduled = [];
    $live = [];
    $closed = [];
    $archived = [];

    foreach ($rows as $r) {
        $exam = [
            'exam_id'          => (int)$r['exam_id'],
            'exam_name'        => $r['exam_name'],
            'status'           => $r['status'],
            'subject_name'     => $r['subject_name'],
            'block'            => $r['block'],
            'class_id'         => (int)$r['class_id'],
            'duration_minutes' => (int)$r['duration_minutes'],
            'start_time'       => $r['start_time'],
            'end_time'         => $r['end_time'],
            'question_count'   => (int)$r['question_count'],
            'total_students'   => (int)$r['total_students'],
            'submission_count' => (int)$r['submission_count'],
            'class_average'    => $r['class_average'] ? (float)$r['class_average'] : null,
        ];

        switch (strtoupper((string)$r['status'])) {
            case 'DRAFT':     $draft[] = $exam; break;
            case 'SCHEDULED': $scheduled[] = $exam; break;
            case 'LIVE':      $live[] = $exam; break;
            case 'ARCHIVED':  $archived[] = $exam; break;
            case 'CLOSED':
            default:          $closed[] = $exam; break;
        }
    }

    sendSuccess([
        'classes' => $classes,
        'draft' => $draft,
        'scheduled' => $scheduled,
        'live' => $live,
        'closed' => $closed,
        'archived' => $archived,
        'stats' => [
            'total' => count($draft) + count($scheduled) + count($live) + count($closed) + count($archived),
            'draft' => count($draft),
            'scheduled' => count($scheduled),
            'live' => count($live),
            'closed' => count($closed),
            'archived' => count($archived),
        ],
    ]);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
