<?php
/**
 * Best-effort system alert recording. A mail failure must still return its
 * normal response even if the alert table has not been migrated yet.
 */
function recordSystemAlert(
    PDO $pdo,
    string $alertType,
    string $description,
    ?int $affectedUserId = null,
    ?string $affectedIdentifier = null,
    array $details = [],
    string $severity = 'ERROR'
): ?int {
    $alertType = substr(trim($alertType), 0, 64);
    $description = trim($description);
    $severity = strtoupper(trim($severity));
    if ($alertType === '' || $description === '') {
        return null;
    }
    if (!in_array($severity, ['ERROR', 'WARNING', 'INFO'], true)) {
        $severity = 'ERROR';
    }
    $json = $details !== [] ? json_encode($details, JSON_INVALID_UTF8_SUBSTITUTE) : null;
    if ($json === false) {
        $json = null;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO system_alerts
                (alert_type, severity, affected_user_id, affected_identifier, description, details)
             VALUES (:type, :severity, :user_id, :identifier, :description, :details)'
        );
        $stmt->bindValue(':type', $alertType);
        $stmt->bindValue(':severity', $severity);
        if ($affectedUserId === null) {
            $stmt->bindValue(':user_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':user_id', $affectedUserId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':identifier', $affectedIdentifier !== null && $affectedIdentifier !== ''
            ? substr($affectedIdentifier, 0, 255) : null, $affectedIdentifier !== null && $affectedIdentifier !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':description', substr($description, 0, 1000));
        $stmt->bindValue(':details', $json, $json === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->execute();
        return (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        // Do not turn a user-facing registration/mail response into a second
        // outage when the optional support migration is not present.
        error_log('System alert could not be recorded: ' . get_class($e));
        return null;
    }
}

