<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo = getDbConnection();
$admin = requireRole($pdo, ['ADMIN']);
$input = getJsonInput();
requireFields($input, ['issue_type', 'issue_id', 'status']);

$issueType = strtoupper(trim((string) $input['issue_type']));
$issueId = (int) $input['issue_id'];
$status = strtoupper(trim((string) $input['status']));
if (!in_array($issueType, ['SYSTEM_ALERT', 'STUDENT_REPORT'], true)) {
    sendError('Invalid support issue type.', 'INVALID_INPUT', 422);
}
if ($issueId <= 0) {
    sendError('Invalid support issue id.', 'INVALID_INPUT', 422);
}
if (!in_array($status, ['NEW', 'IN_PROGRESS', 'RESOLVED'], true)) {
    sendError('Invalid status. Use NEW, IN_PROGRESS or RESOLVED.', 'INVALID_STATUS', 422);
}

try {
    if ($issueType === 'SYSTEM_ALERT') {
        if ($status === 'RESOLVED') {
            $stmt = $pdo->prepare(
                'UPDATE system_alerts
                 SET status = :status, resolved_at = NOW(), resolved_by = :admin_id
                 WHERE alert_id = :id'
            );
            $stmt->execute(['status' => $status, 'admin_id' => (int) $admin['user_id'], 'id' => $issueId]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE system_alerts
                 SET status = :status, resolved_at = NULL, resolved_by = NULL
                 WHERE alert_id = :id'
            );
            $stmt->execute(['status' => $status, 'id' => $issueId]);
        }
    } else {
        // OPEN is the existing database representation of NEW.
        $storedStatus = $status === 'NEW' ? 'OPEN' : $status;
        $stmt = $pdo->prepare('UPDATE login_problem_reports SET status = :status WHERE report_id = :id');
        $stmt->execute(['status' => $storedStatus, 'id' => $issueId]);
    }

    if ($stmt->rowCount() < 1) {
        $table = $issueType === 'SYSTEM_ALERT' ? 'system_alerts' : 'login_problem_reports';
        $idColumn = $issueType === 'SYSTEM_ALERT' ? 'alert_id' : 'report_id';
        $check = $pdo->prepare("SELECT 1 FROM {$table} WHERE {$idColumn} = ? LIMIT 1");
        $check->execute([$issueId]);
        if (!$check->fetchColumn()) {
            sendError('Support issue not found.', 'NOT_FOUND', 404);
        }
    }

    $log = $pdo->prepare(
        'INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)'
    );
    $log->execute([
        (int) $admin['user_id'],
        'SUPPORT_ISSUE_STATUS',
        "Set {$issueType} #{$issueId} to {$status}",
    ]);

    sendSuccess([
        'issue_type' => $issueType,
        'issue_id' => $issueId,
        'status' => $status,
    ]);
} catch (PDOException $e) {
    error_log('Support issue update failed: ' . get_class($e));
    sendError('Support issue status could not be updated. Please try again.', 'DB_ERROR', 500);
}
