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

    $maxExitAttempts = filter_var($exam['max_exit_attempts'] ?? null, FILTER_VALIDATE_INT);
    if ($maxExitAttempts === false || $maxExitAttempts < 1 || $maxExitAttempts > 10) {
        sendError('This exam has no valid maximum exit-attempt limit configured.', 'SERVER_MISCONFIGURED', 500);
    }
    $exam['max_exit_attempts'] = $maxExitAttempts;

    $monitoringAvailable = examMonitoringTablesAvailable($pdo);
    $activityLogAvailable = examActivityLogAvailable($pdo);
    $presenceAvailable = examPresenceTableAvailable($pdo);
    $violationTable = $activityLogAvailable ? 'exam_activity_log' : 'exam_proctoring_log';
    $violationCountsSql = "
                     COALESCE(vc.tab_switch_count, 0) AS tab_switch_count,
                     COALESCE(vc.screenshot_count, 0) AS screenshot_count,
                     COALESCE(vc.multi_window_count, 0) AS multi_window_count,
                     COALESCE(vc.screen_recording_count, 0) AS screen_recording_count,
                     COALESCE(vc.background_count, 0) AS background_count,
                     COALESCE(vc.total_violation_count, 0) AS total_violation_count,
                     vc.last_security_event,
                     vc.last_security_event_at,";
    $violationJoinSql = "
              LEFT JOIN (
                  SELECT user_id,
                         SUM(event_type = 'TAB_SWITCH') AS tab_switch_count,
                         SUM(event_type = 'SCREENSHOT') AS screenshot_count,
                         SUM(event_type = 'MULTI_WINDOW') AS multi_window_count,
                         SUM(event_type = 'SCREEN_RECORDING') AS screen_recording_count,
                         SUM(event_type = 'BACKGROUND') AS background_count,
                         SUM(event_type IN ('TAB_SWITCH', 'SCREENSHOT', 'MULTI_WINDOW',
                             'SCREEN_RECORDING', 'BACKGROUND', 'CLOSED')) AS total_violation_count,
                         SUBSTRING_INDEX(GROUP_CONCAT(
                             CASE WHEN event_type IN ('TAB_SWITCH', 'SCREENSHOT', 'MULTI_WINDOW',
                                 'SCREEN_RECORDING', 'BACKGROUND', 'CLOSED') THEN event_type END
                             ORDER BY created_at DESC SEPARATOR ','), ',', 1) AS last_security_event,
                         MAX(CASE WHEN event_type IN ('TAB_SWITCH', 'SCREENSHOT', 'MULTI_WINDOW',
                             'SCREEN_RECORDING', 'BACKGROUND', 'CLOSED') THEN created_at END) AS last_security_event_at
                  FROM {$violationTable}
                  WHERE exam_id = :vid
                  GROUP BY user_id
              ) vc ON vc.user_id = u.user_id";
    if ($monitoringAvailable) {
        $studentsStmt = $pdo->prepare(
            'SELECT u.user_id, u.first_name, u.last_name,
                    es.score, es.exit_attempts, es.auto_submitted, es.submitted_at,' . $violationCountsSql . '
                    COALESCE(vc.last_security_event_at, lp.last_seen_at) AS last_activity,
                    lp.status AS presence_status,
                    lp.current_question_id, lp.question_index,
                    lp.answered_count, lp.total_questions,
                    lp.last_event, lp.last_seen_at,
                    TIMESTAMPDIFF(SECOND, lp.last_seen_at, NOW()) AS seconds_since_seen
             FROM enrollments en
             JOIN users u ON u.user_id = en.user_id
             LEFT JOIN exam_submissions es
               ON es.exam_id = :eid AND es.user_id = u.user_id
             ' . $violationJoinSql . '
             LEFT JOIN exam_live_presence lp
               ON lp.exam_id = :eid3 AND lp.user_id = u.user_id
             WHERE en.class_id = :cid
             ORDER BY u.last_name, u.first_name'
        );
        $studentsStmt->execute([
            'eid' => $examId,
            'vid' => $examId,
            'eid3' => $examId,
            'cid' => $exam['class_id'],
        ]);
    } else {
        // Compatibility fallback while the optional live-monitoring migration
        // is being applied. This preserves the original tab-switch view.
        $studentsStmt = $pdo->prepare(
            'SELECT u.user_id, u.first_name, u.last_name,
                    es.score, es.exit_attempts, es.auto_submitted, es.submitted_at,' . $violationCountsSql . '
                    vc.last_security_event_at AS last_activity
             FROM enrollments en
             JOIN users u ON u.user_id = en.user_id
             LEFT JOIN exam_submissions es
               ON es.exam_id = :eid AND es.user_id = u.user_id
             ' . $violationJoinSql . '
             WHERE en.class_id = :cid
             ORDER BY u.last_name, u.first_name'
        );
        $studentsStmt->execute([
            'eid' => $examId,
            'vid' => $examId,
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
        $student['screenshot_count'] = (int) ($student['screenshot_count'] ?? 0);
        $student['multi_window_count'] = (int) ($student['multi_window_count'] ?? 0);
        $student['screen_recording_count'] = (int) ($student['screen_recording_count'] ?? 0);
        $student['background_count'] = (int) ($student['background_count'] ?? 0);
        $student['total_violation_count'] = (int) ($student['total_violation_count'] ?? 0);
        $student['last_security_event'] = $student['last_security_event'] ?? null;
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
             ORDER BY p.created_at DESC
             LIMIT 50"
        );
        $logStmt->execute(['eid' => $examId]);
        foreach ($logStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = trim($row['first_name'] . ' ' . $row['last_name']);
            $type = strtoupper((string) $row['event_type']);
            $feedType = 'info';
            switch ($type) {
                case 'TAB_SWITCH':
                    $message = $name . ' left the exam screen';
                    $feedType = 'warning';
                    break;
                case 'BACKGROUND':
                case 'CLOSED':
                    $message = $name . ' left the exam app';
                    $feedType = 'warning';
                    break;
                case 'SCREENSHOT':
                    $message = $name . ' attempted to capture a screenshot';
                    $feedType = 'warning';
                    break;
                case 'SCREEN_RECORDING':
                    $message = $name . ' started screen recording';
                    $feedType = 'warning';
                    break;
                case 'MULTI_WINDOW':
                    $message = $name . ' entered split-screen or multi-window mode';
                    $feedType = 'warning';
                    break;
                case 'SUBMITTED':
                    $message = $name . ' submitted the exam';
                    $feedType = 'auto_submit';
                    break;
                case 'EXAM_STARTED':
                    $message = $name . ' started the exam';
                    break;
                case 'QUESTION_VIEWED':
                    $message = $name . ' viewed a question';
                    break;
                case 'ANSWER_CHANGED':
                    $message = $name . ' updated an answer';
                    break;
                case 'NETWORK_LOST':
                    $message = $name . ' lost network connection';
                    $feedType = 'warning';
                    break;
                case 'NETWORK_RESTORED':
                    $message = $name . ' restored network connection';
                    break;
                case 'ACTIVE':
                    $message = $name . ' returned to the exam';
                    break;
                default:
                    $message = $name . ' generated an exam activity event';
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
    }

    $subCountStmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM exam_submissions WHERE exam_id = :eid');
    $subCountStmt->execute(['eid' => $examId]);
    $submissionCount = (int) $subCountStmt->fetch()['cnt'];

    sendSuccess([
        'exam' => array_merge($exam, ['submission_count' => $submissionCount]),
        'students' => $students,
        'events' => $events,
        'monitoring_available' => $monitoringAvailable,
        'presence_available' => $presenceAvailable,
        'activity_log_available' => $activityLogAvailable,
        'max_exit_attempts' => $exam['max_exit_attempts'],
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
