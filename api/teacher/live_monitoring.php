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
$exam_id = $_GET['id'] ?? null;

if (!$exam_id) {
    sendError('Missing exam id parameter.', 'BAD_REQUEST', 400);
}

try {
    syncExamStatuses($pdo);

    $stmt = $pdo->prepare("SELECT e.*, c.subject_name FROM exams e JOIN classes c ON e.class_id=c.class_id WHERE e.exam_id=? AND c.teacher_id=?");
    $stmt->execute([$exam_id, $teacher_id]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$exam) {
        sendError('Exam not found.', 'NOT_FOUND', 404);
    }

    // All enrolled students, with submission info (if any) and tab-switch stats.
    $studentsStmt = $pdo->prepare(
        'SELECT u.user_id, u.first_name, u.last_name,
                es.score, es.exit_attempts, es.auto_submitted, es.submitted_at,
                COALESCE(pc.cnt, 0) AS tab_switch_count,
                pc.last_at AS last_activity
         FROM enrollments en
         JOIN users u ON u.user_id = en.user_id
         LEFT JOIN exam_submissions es ON es.exam_id = :eid AND es.user_id = u.user_id
         LEFT JOIN (
             SELECT user_id, COUNT(*) AS cnt, MAX(created_at) AS last_at
             FROM exam_proctoring_log
             WHERE exam_id = :eid2
             GROUP BY user_id
         ) pc ON pc.user_id = u.user_id
         WHERE en.class_id = :cid
         ORDER BY u.last_name, u.first_name'
    );
    $studentsStmt->execute(['eid' => $exam_id, 'eid2' => $exam_id, 'cid' => $exam['class_id']]);
    $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Recent proctoring events (tab switches + auto-submits) with timestamps.
    $events = [];
    $logStmt = $pdo->prepare(
        'SELECT u.first_name, u.last_name, p.event_type, p.created_at
         FROM exam_proctoring_log p
         JOIN users u ON u.user_id = p.user_id
         WHERE p.exam_id = :eid
         ORDER BY p.created_at DESC
         LIMIT 50'
    );
    $logStmt->execute(['eid' => $exam_id]);
    foreach ($logStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $events[] = [
            'student_name' => trim($row['first_name'] . ' ' . $row['last_name']),
            'type'         => $row['event_type'] === 'TAB_SWITCH' ? 'tab_switch' : 'event',
            'occurred_at'  => $row['created_at'],
        ];
    }

    $subCountStmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM exam_submissions WHERE exam_id = :eid');
    $subCountStmt->execute(['eid' => $exam_id]);
    $submissionCount = (int) $subCountStmt->fetch()['cnt'];

    sendSuccess([
        'exam' => array_merge($exam, ['submission_count' => $submissionCount]),
        'students' => $students,
        'events' => $events,
    ]);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
