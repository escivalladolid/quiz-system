<?php

/**
 * Helpers for resolving the student's current persisted exam attempt and
 * counting attempt-scoped proctoring events.  The attempt id is server-owned;
 * a client may only use an attempt that belongs to its own exam/user pair.
 */

function resolveExamAttempt(PDO $pdo, int $examId, int $userId, ?int $attemptId = null): ?array {
    if ($examId <= 0 || $userId <= 0) return null;

    if ($attemptId !== null && $attemptId > 0) {
        $stmt = $pdo->prepare(
            'SELECT attempt_id, exam_id, user_id, started_at, deadline_at
             FROM exam_attempts
             WHERE attempt_id = :aid AND exam_id = :eid AND user_id = :uid
             LIMIT 1'
        );
        $stmt->execute(['aid' => $attemptId, 'eid' => $examId, 'uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // Legacy clients did not send an attempt id.  Resolve their only/current
    // persisted attempt without weakening validation for the new clients.
    $stmt = $pdo->prepare(
        'SELECT attempt_id, exam_id, user_id, started_at, deadline_at
         FROM exam_attempts
         WHERE exam_id = :eid AND user_id = :uid
         ORDER BY created_at DESC, attempt_id DESC
         LIMIT 1'
    );
    $stmt->execute(['eid' => $examId, 'uid' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function examAttemptIdFromInput(array $input): ?int {
    if (!array_key_exists('attempt_id', $input) || $input['attempt_id'] === null || $input['attempt_id'] === '') {
        return null;
    }
    $value = filter_var($input['attempt_id'], FILTER_VALIDATE_INT);
    return ($value !== false && $value > 0) ? (int) $value : null;
}

function countAttemptTabSwitches(PDO $pdo, int $examId, int $userId, int $attemptId): int {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM exam_proctoring_log
         WHERE exam_id = :eid AND user_id = :uid AND attempt_id = :aid
           AND event_type = 'TAB_SWITCH'"
    );
    $stmt->execute(['eid' => $examId, 'uid' => $userId, 'aid' => $attemptId]);
    return (int) $stmt->fetchColumn();
}

function examProctoringAttemptColumnAvailable(PDO $pdo): bool {
    static $available = null;
    if ($available !== null) return $available;

    try {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'exam_proctoring_log'
               AND column_name = 'attempt_id'
             LIMIT 1"
        );
        $stmt->execute();
        $available = (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        $available = false;
    }

    return $available;
}

function examActivityAttemptColumnAvailable(PDO $pdo): bool {
    static $available = null;
    if ($available !== null) return $available;

    try {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'exam_activity_log'
               AND column_name = 'attempt_id'
             LIMIT 1"
        );
        $stmt->execute();
        $available = (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        $available = false;
    }

    return $available;
}

