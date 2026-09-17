<?php
/**
 * Single source of truth for exam status.
 *
 * The exams.status column is the ONLY authoritative status. All time-based
 * transitions happen here, using the MySQL server clock (NOW()) so the server
 * time is always the reference — never the device clock.
 *
 * Automatic transitions (applied before any exam data is returned):
 *
 *   SCHEDULED -> LIVE   when start_time is reached
 *   LIVE      -> CLOSED when end_time is reached (auto-close)
 *
 * ARCHIVED exams are never touched and can never become LIVE again, because
 * both statements below filter on the current status first.
 *
 * Every endpoint that exposes exam status must call syncExamStatuses($pdo)
 * immediately after opening the connection, before running its queries.
 */

function syncExamStatuses(PDO $pdo): void {
    // Throttle: exam start/end transitions are edge-triggered on TIME. Running
    // both UPDATEs on every request is wasted round trips when nothing has
    // changed (they are no-ops ~all the time). Skip if synced within the last
    // few seconds; a tiny transition delay is far cheaper than 2 DB calls per
    // request and is re-attempted on the very next one.
    $cacheFile = sys_get_temp_dir() . '/quizsystem_status_sync_at';
    $lastSync  = (int) @file_get_contents($cacheFile);
    if ($lastSync + 5 > time()) {
        return;
    }
    @file_put_contents($cacheFile, time());

    // Scheduled exams become live once the start time arrives.
    $pdo->exec(
        "UPDATE exams
            SET status = 'LIVE'
          WHERE status = 'SCHEDULED'
            AND start_time IS NOT NULL
            AND start_time <= NOW()"
    );

    // Live exams close automatically once the end time passes.
    $pdo->exec(
        "UPDATE exams
            SET status = 'CLOSED',
                is_closed = 1,
                closed_at = COALESCE(closed_at, NOW())
          WHERE status = 'LIVE'
            AND end_time IS NOT NULL
            AND end_time <= NOW()"
    );
}

/**
 * Convert an API date value to the canonical database format. The builder sends
 * local server-time values as Y-m-d H:i:s; accepting other strtotime-readable
 * values keeps older web clients compatible while still rejecting nonsense.
 */
function normalizeExamDateTime($value, string $fieldName): ?string {
    if ($value === null) return null;
    if (!is_scalar($value)) {
        throw new InvalidArgumentException($fieldName . ' must be a valid date and time.');
    }
    $raw = trim((string) $value);
    if ($raw === '') return null;
    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        throw new InvalidArgumentException($fieldName . ' must be a valid date and time.');
    }
    return date('Y-m-d H:i:s', $timestamp);
}

/** Ensure the optional availability window is ordered correctly. */
function validateExamAvailability(?string $startTime, ?string $endTime): void {
    if ($startTime !== null && $endTime !== null && strtotime($endTime) <= strtotime($startTime)) {
        throw new InvalidArgumentException('Availability end time must be after the start time.');
    }
}

function validateExamDuration($value): int {
    if (!is_scalar($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
        throw new InvalidArgumentException('Exam duration must be a whole number of minutes.');
    }
    $minutes = (int) $value;
    if ($minutes < 0 || $minutes > 1440) {
        throw new InvalidArgumentException('Exam duration must be between 0 and 1440 minutes.');
    }
    return $minutes;
}
