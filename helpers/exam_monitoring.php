<?php

/**
 * Best-effort, app-level exam monitoring.
 *
 * This records only what the quiz client can legitimately observe inside the
 * exam: presence, foreground/background state, question progress, answer
 * progress, network state, and submission/proctoring events. It never stores
 * answer text, screenshots, camera data, or activity outside the app.
 */

function examActivityLogAvailable(PDO $pdo): bool {
    static $available = null;
    if ($available !== null) return $available;

    try {
        $pdo->query('SELECT 1 FROM exam_activity_log LIMIT 1');
        $available = true;
    } catch (PDOException $e) {
        // Monitoring is additive. A missing migration must never prevent a
        // student from continuing an otherwise valid exam attempt.
        $available = false;
    }

    return $available;
}

function examPresenceTableAvailable(PDO $pdo): bool {
    static $available = null;
    if ($available !== null) return $available;

    try {
        $pdo->query('SELECT 1 FROM exam_live_presence LIMIT 1');
        $available = true;
    } catch (PDOException $e) {
        // The activity history and the latest-presence table are independent
        // capabilities. Keep the history usable if only one migration exists.
        $available = false;
    }

    return $available;
}

function examMonitoringTablesAvailable(PDO $pdo): bool {
    return examActivityLogAvailable($pdo) && examPresenceTableAvailable($pdo);
}

function examMonitoringStatusForEvent(string $eventType): string {
    switch ($eventType) {
        case 'BACKGROUND':
        case 'TAB_SWITCH':
        case 'SCREENSHOT':
        case 'SCREEN_RECORDING':
        case 'MULTI_WINDOW':
            return 'AWAY';
        case 'NETWORK_LOST':
            return 'OFFLINE';
        case 'SUBMITTED':
            return 'SUBMITTED';
        default:
            return 'ACTIVE';
    }
}

/**
 * Store one activity event and update the latest presence row.
 * Returns false when the optional monitoring migration is not installed.
 */
function recordExamActivity(PDO $pdo, int $examId, int $userId, string $eventType, array $context = []): bool {
    $eventType = strtoupper(trim($eventType));
    $allowed = [
        'EXAM_STARTED', 'HEARTBEAT', 'ACTIVE', 'BACKGROUND',
        'NETWORK_LOST', 'NETWORK_RESTORED', 'QUESTION_VIEWED',
        'ANSWER_CHANGED', 'TAB_SWITCH', 'SCREENSHOT', 'SCREEN_RECORDING',
        'MULTI_WINDOW', 'SUBMITTED', 'CLOSED'
    ];
    if (!in_array($eventType, $allowed, true)) $eventType = 'HEARTBEAT';

    $activityAvailable = examActivityLogAvailable($pdo);
    $presenceAvailable = examPresenceTableAvailable($pdo);
    if (!$activityAvailable && !$presenceAvailable) return false;

    $questionId = isset($context['question_id']) && (int) $context['question_id'] > 0
        ? (int) $context['question_id'] : null;
    $questionIndex = isset($context['question_index']) && (int) $context['question_index'] > 0
        ? (int) $context['question_index'] : null;
    $answeredCount = isset($context['answered_count']) && (int) $context['answered_count'] >= 0
        ? (int) $context['answered_count'] : null;
    $totalQuestions = isset($context['total_questions']) && (int) $context['total_questions'] > 0
        ? (int) $context['total_questions'] : null;
    $networkState = isset($context['network_state'])
        ? substr(strtoupper(trim((string) $context['network_state'])), 0, 16) : null;
    $status = examMonitoringStatusForEvent($eventType);

    $recorded = false;
    try {
        // Heartbeats update presence only. Persisting every 10-second pulse as
        // a history row would create unnecessary database growth.
        if ($activityAvailable && $eventType !== 'HEARTBEAT') {
            $eventStmt = $pdo->prepare(
                'INSERT INTO exam_activity_log
                    (exam_id, user_id, event_type, question_id, question_index,
                     answered_count, total_questions, network_state, created_at)
                 VALUES (:eid, :uid, :event, :qid, :qindex, :answered, :total,
                         :network, NOW())'
            );
            $eventStmt->execute([
                'eid' => $examId, 'uid' => $userId, 'event' => $eventType,
                'qid' => $questionId, 'qindex' => $questionIndex,
                'answered' => $answeredCount, 'total' => $totalQuestions,
                'network' => $networkState,
            ]);
            $recorded = true;
        }

        if ($presenceAvailable) {
            $presenceStmt = $pdo->prepare(
            'INSERT INTO exam_live_presence
                (exam_id, user_id, status, current_question_id, question_index,
                 answered_count, total_questions, last_event, last_seen_at, updated_at)
             VALUES (:eid, :uid, :status, :qid, :qindex, :answered, :total,
                     :event, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                status              = VALUES(status),
                current_question_id = COALESCE(VALUES(current_question_id), current_question_id),
                question_index      = COALESCE(VALUES(question_index), question_index),
                answered_count      = COALESCE(VALUES(answered_count), answered_count),
                total_questions     = COALESCE(VALUES(total_questions), total_questions),
                last_event          = VALUES(last_event),
                last_seen_at        = VALUES(last_seen_at),
                updated_at          = NOW()'
            );
            $presenceStmt->execute([
            'eid'      => $examId,
            'uid'      => $userId,
            'status'   => $status,
            'qid'      => $questionId,
            'qindex'   => $questionIndex,
            'answered' => $answeredCount,
            'total'    => $totalQuestions,
            'event'    => $eventType,
            ]);
            $recorded = true;
        }
    } catch (PDOException $e) {
        // Monitoring is telemetry, never a gate for starting, saving, or
        // submitting an exam. A transient telemetry DB failure is ignored.
        return $recorded;
    }

    return $recorded;
}

/**
 * Compatibility fallback for security events when the activity migration is
 * not installed yet. The legacy table has no rich context, but retaining the
 * event lets teachers see screenshot/multi-window alerts immediately.
 */
function recordLegacyExamActivity(PDO $pdo, int $examId, int $userId, string $eventType): bool {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO exam_proctoring_log (exam_id, user_id, event_type, created_at)
             VALUES (:eid, :uid, :event, NOW())'
        );
        $stmt->execute(['eid' => $examId, 'uid' => $userId, 'event' => $eventType]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}
