<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/exam_status.php';
require_once __DIR__ . '/../../helpers/exam_monitoring.php';

header('Content-Type: application/json');

$pdo = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

$teacher = requireRole($pdo, ['TEACHER']);
$teacherId = (int) $teacher['user_id'];
$examId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($examId <= 0) {
    sendError('Missing exam id parameter.', 'BAD_REQUEST', 400);
}

try {
    syncExamStatuses($pdo);

    $examStmt = $pdo->prepare(
        'SELECT e.*, c.subject_name
         FROM exams e
         JOIN classes c ON e.class_id = c.class_id
         WHERE e.exam_id = :eid AND c.teacher_id = :tid'
    );
    $examStmt->execute(['eid' => $examId, 'tid' => $teacherId]);
    $exam = $examStmt->fetch(PDO::FETCH_ASSOC);
    if (!$exam) sendError('Exam not found.', 'NOT_FOUND', 404);

    $monitoringAvailable = examMonitoringTablesAvailable($pdo);
    $activityLogAvailable = examActivityLogAvailable($pdo);
    if ($monitoringAvailable) {
        $studentsStmt = $pdo->prepare(
            'SELECT u.user_id, u.first_name, u.last_name,
                    es.score, es.exit_attempts, es.auto_submitted, es.submitted_at,
                    COALESCE(pc.cnt, 0) AS tab_switch_count,
                    COALESCE(pc.last_at, lp.last_seen_at) AS last_activity,
                    lp.status AS presence_status,
                    lp.current_question_id, lp.question_index,
                    lp.answered_count, lp.total_questions,
                    lp.last_event, lp.last_seen_at,
                    TIMESTAMPDIFF(SECOND, lp.last_seen_at, NOW()) AS seconds_since_seen
             FROM enrollments en
             JOIN users u ON u.user_id = en.user_id
             LEFT JOIN exam_submissions es
               ON es.exam_id = :eid AND es.user_id = u.user_id
             LEFT JOIN (
                 SELECT user_id, COUNT(*) AS cnt, MAX(created_at) AS last_at
                 FROM exam_proctoring_log
                 WHERE exam_id = :eid2 AND event_type = \'TAB_SWITCH\'
                 GROUP BY user_id
             ) pc ON pc.user_id = u.user_id
             LEFT JOIN exam_live_presence lp
               ON lp.exam_id = :eid3 AND lp.user_id = u.user_id
             WHERE en.class_id = :cid
             ORDER BY u.last_name, u.first_name'
        );
        $studentsStmt->execute([
            'eid' => $examId,
            'eid2' => $examId,
            'eid3' => $examId,
            'cid' => $exam['class_id'],
        ]);
    } else {
        // Compatibility fallback while the optional live-monitoring migration
        // is being applied. This preserves the original tab-switch view.
        $studentsStmt = $pdo->prepare(
            'SELECT u.user_id, u.first_name, u.last_name,
                    es.score, es.exit_attempts, es.auto_submitted, es.submitted_at,
                    COALESCE(pc.cnt, 0) AS tab_switch_count,
                    pc.last_at AS last_activity
             FROM enrollments en
             JOIN users u ON u.user_id = en.user_id
             LEFT JOIN exam_submissions es
               ON es.exam_id = :eid AND es.user_id = u.user_id
             LEFT JOIN (
                 SELECT user_id, COUNT(*) AS cnt, MAX(created_at) AS last_at
                 FROM exam_proctoring_log
                 WHERE exam_id = :eid2 AND event_type = \'TAB_SWITCH\'
                 GROUP BY user_id
             ) pc ON pc.user_id = u.user_id
             WHERE en.class_id = :cid
             ORDER BY u.last_name, u.first_name'
        );
        $studentsStmt->execute([
            'eid' => $examId,
            'eid2' => $examId,
            'cid' => $exam['class_id'],
        ]);
    }

    $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
    $activeCount = 0;
    $awayCount = 0;
    $offlineCount = 0;
    $submittedCount = 0;
    $notStartedCount = 0;

    foreach ($students as &$student) {
        $submitted = !empty($student['submitted_at']);
        $lastSeen = $student['last_seen_at'] ?? null;
        $secondsSinceSeen = isset($student['seconds_since_seen'])
            ? (int) $student['seconds_since_seen'] : null;
        $presence = strtoupper((string) ($student['presence_status'] ?? ''));

        if ($submitted) {
            $status = 'SUBMITTED';
            $submittedCount++;
        } elseif (!$monitoringAvailable || !$lastSeen) {
            $status = $monitoringAvailable ? 'NOT_STARTED' : 'UNKNOWN';
            if ($status === 'NOT_STARTED') $notStartedCount++;
        } elseif ($secondsSinceSeen !== null && $secondsSinceSeen > 30) {
            $status = 'OFFLINE';
            $offlineCount++;
        } elseif ($presence === 'AWAY') {
            $status = 'AWAY';
            $awayCount++;
        } elseif ($presence === 'OFFLINE') {
            $status = 'OFFLINE';
            $offlineCount++;
        } else {
            $status = 'ACTIVE';
            $activeCount++;
        }

        $answered = isset($student['answered_count']) ? (int) $student['answered_count'] : 0;
        $total = isset($student['total_questions']) ? (int) $student['total_questions'] : 0;
        $student['presence_status'] = $status;
        $student['answered_count'] = $answered;
        $student['total_questions'] = $total;
        $student['progress_percent'] = $total > 0 ? round(($answered / $total) * 100, 1) : 0;
        $student['question_index'] = isset($student['question_index']) ? (int) $student['question_index'] : 0;
        $student['tab_switch_count'] = (int) ($student['tab_switch_count'] ?? 0);
        $student['seconds_since_seen'] = $secondsSinceSeen;
        $student['last_seen_at'] = $lastSeen;
        $student['last_activity'] = $student['last_activity'] ?? $lastSeen;
    }
    unset($student);

    $events = [];
    if ($activityLogAvailable) {
        $eventStmt = $pdo->prepare(
            'SELECT u.first_name, u.last_name, a.event_type,
                    a.question_index, a.created_at
             FROM exam_activity_log a
             JOIN users u ON u.user_id = a.user_id
             WHERE a.exam_id = :eid AND a.event_type <> \'HEARTBEAT\'
             ORDER BY a.created_at DESC
             LIMIT 100'
        );
        $eventStmt->execute(['eid' => $examId]);
        foreach ($eventStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $type = strtoupper((string) $row['event_type']);
            $name = trim($row['first_name'] . ' ' . $row['last_name']);
            $question = isset($row['question_index']) && $row['question_index'] !== null
                ? ' (question ' . (int) $row['question_index'] . ')' : '';
            $message = $name;
            $feedType = 'info';
            switch ($type) {
                case 'TAB_SWITCH':
                    $message .= ' left the exam screen';
                    $feedType = 'warning';
                    break;
                case 'BACKGROUND':
                    $message .= ' left the exam app';
                    $feedType = 'warning';
                    break;
                case 'MULTI_WINDOW':
                    $message .= ' entered split-screen or multi-window mode';
                    $feedType = 'warning';
                    break;
                case 'SCREENSHOT':
                    $message .= ' attempted to capture a screenshot';
                    $feedType = 'warning';
                    break;
                case 'SCREEN_RECORDING':
                    $message .= ' started screen recording';
                    $feedType = 'warning';
                    break;
                case 'ACTIVE':
                    $message .= ' returned to the exam';
                    break;
                case 'QUESTION_VIEWED':
                    $message .= ' viewed' . $question;
                    break;
                case 'ANSWER_CHANGED':
                    $message .= ' updated an answer' . $question;
                    break;
                case 'NETWORK_LOST':
                    $message .= ' lost network connection';
                    $feedType = 'warning';
                    break;
                case 'NETWORK_RESTORED':
                    $message .= ' restored network connection';
                    break;
                case 'SUBMITTED':
                    $message .= ' submitted the exam';
                    $feedType = 'auto_submit';
                    break;
                case 'EXAM_STARTED':
                    $message .= ' started the exam';
                    break;
                default:
                    $message .= ' generated an exam activity event';
                    break;
            }
            $events[] = [
                'student_name' => $name,
                'type' => $feedType,
                'event_type' => $type,
                'message' => $message,
                'occurred_at' => $row['created_at'],
            ];
        }
    } else {
        $logStmt = $pdo->prepare(
            "SELECT u.first_name, u.last_name, p.event_type, p.created_at
             FROM exam_proctoring_log p
             JOIN users u ON u.user_id = p.user_id
             WHERE p.exam_id = :eid
               AND p.event_type IN ('TAB_SWITCH', 'SCREENSHOT', 'SCREEN_RECORDING', 'MULTI_WINDOW', 'SUBMITTED', 'CLOSED')
             ORDER BY p.created_at DESC
             LIMIT 50"
        );
        $logStmt->execute(['eid' => $examId]);
        foreach ($logStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = trim($row['first_name'] . ' ' . $row['last_name']);
            $type = strtoupper((string) $row['event_type']);
            $message = $name . ' left the exam screen';
            if ($type === 'SCREENSHOT') $message = $name . ' attempted to capture a screenshot';
            elseif ($type === 'SCREEN_RECORDING') $message = $name . ' started screen recording';
            elseif ($type === 'MULTI_WINDOW') $message = $name . ' entered split-screen or multi-window mode';
            elseif ($type === 'SUBMITTED') $message = $name . ' submitted the exam';
            elseif ($type === 'CLOSED') $message = $name . ' left the exam';
            $events[] = [
                'student_name' => $name,
                'type' => 'warning',
                'event_type' => $type,
                'message' => $message,
                'occurred_at' => $row['created_at'],
            ];
        }
    }

    $subCountStmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM exam_submissions WHERE exam_id = :eid');
    $subCountStmt->execute(['eid' => $examId]);
    $submissionCount = (int) $subCountStmt->fetch()['cnt'];

    sendSuccess([
        'exam' => array_merge($exam, ['submission_count' => $submissionCount]),
        'students' => $students,
        'events' => $events,
        'monitoring_available' => $monitoringAvailable,
        'activity_log_available' => $activityLogAvailable,
        'summary' => [
            'active' => $activeCount,
            'away' => $awayCount,
            'offline' => $offlineCount,
            'submitted' => $submittedCount,
            'not_started' => $notStartedCount,
            'total' => count($students),
        ],
        'updated_at' => date('c'),
    ]);
} catch (PDOException $e) {
    sendError('Database error: ' . $e->getMessage(), 'DB_ERROR', 500);
}
