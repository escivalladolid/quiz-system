<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/archive.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo = getDbConnection();
$admin = requireRole($pdo, ['ADMIN']);

$input = getJsonInput();
requireFields($input, ['class_id']);

$class_id = (int) $input['class_id'];
$action = strtolower(trim((string) ($input['action'] ?? '')));
if ($action === '') {
    // Keep old admin-panel clients working while making archive/unarchive the
    // explicit API vocabulary going forward.
    $legacyStatus = strtoupper(trim((string) ($input['status'] ?? '')));
    $action = $legacyStatus === 'ARCHIVED' ? 'archive' : ($legacyStatus === 'ACTIVE' ? 'unarchive' : '');
}
if (!in_array($action, ['archive', 'unarchive'], true)) {
    sendError('Invalid action. Use archive or unarchive.', 'INVALID_ACTION', 422);
}

try {
    $stmt = $pdo->prepare('SELECT class_code, status, is_archived, archived_at FROM classes WHERE class_id = ?');
    $stmt->execute([$class_id]);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$class) {
        sendError('Class not found.', 'NOT_FOUND', 404);
    }

    // Archiving does not delete enrollments or exams — it only hides the class.
    if ($action === 'archive') {
        $stmt = $pdo->prepare('UPDATE classes SET is_archived = 1, archived_at = COALESCE(archived_at, NOW()) WHERE class_id = ?');
        $stmt->execute([$class_id]);
        $status = 'ARCHIVED';
    } else {
        $stmt = $pdo->prepare(
            "UPDATE classes SET is_archived = 0, archived_at = NULL,
                    status = CASE WHEN status = 'ARCHIVED' THEN 'ACTIVE' ELSE status END
              WHERE class_id = ?"
        );
        $stmt->execute([$class_id]);
        $status = 'ACTIVE';
    }

    $log = $pdo->prepare('INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)');
    $log->execute([$admin['user_id'], 'CLASS_STATUS', "Set class {$class['class_code']} to $status"]);

    $stmt = $pdo->prepare('SELECT class_id, status, is_archived, archived_at FROM classes WHERE class_id = ?');
    $stmt->execute([$class_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    addEffectiveArchiveFields($result);
    sendSuccess(['class_id' => $class_id, 'status' => $result['status'], 'is_archived' => (int) $result['is_archived'], 'archived_at' => $result['archived_at']]);
} catch (PDOException $e) {
    if ($e->getCode() === '42S22') {
        sendError('Soft-archive migration is required before archiving classes.', 'ARCHIVE_MIGRATION_REQUIRED', 503);
    }
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
