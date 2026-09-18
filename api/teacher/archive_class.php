<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/archive.php';

header('Content-Type: application/json');

$pdo = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

// Role enforcement is performed here as well as in the UI. Students can never
// archive or restore records by calling this endpoint directly.
$actor = requireRole($pdo, ['TEACHER', 'ADMIN']);
$input = getJsonInput();
requireFields($input, ['class_id']);

$classId = (int) $input['class_id'];
$action = strtolower(trim((string) ($input['action'] ?? 'archive')));
if (!in_array($action, ['archive', 'unarchive'], true)) {
    sendError('Invalid action. Use archive or unarchive.', 'INVALID_ACTION', 422);
}

try {
    $sql = 'SELECT class_id, class_code, status, is_archived, archived_at, teacher_id
            FROM classes WHERE class_id = ?';
    $params = [$classId];
    if ($actor['role_name'] === 'TEACHER') {
        $sql .= ' AND teacher_id = ?';
        $params[] = $actor['user_id'];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$class) {
        sendError('Class not found.', 'NOT_FOUND', 404);
    }

    if ($action === 'archive') {
        $stmt = $pdo->prepare(
            'UPDATE classes SET is_archived = 1, archived_at = COALESCE(archived_at, NOW()) WHERE class_id = ?'
        );
        $stmt->execute([$classId]);
        $message = 'Class archived successfully.';
    } else {
        // Rows created by the legacy status-only archive are made active again;
        // newly archived rows retain their prior status.
        $stmt = $pdo->prepare(
            "UPDATE classes
                SET is_archived = 0,
                    archived_at = NULL,
                    status = CASE WHEN status = 'ARCHIVED' THEN 'ACTIVE' ELSE status END
              WHERE class_id = ?"
        );
        $stmt->execute([$classId]);
        $message = 'Class restored successfully.';
    }

    $stmt = $pdo->prepare('SELECT class_id, class_code, status, is_archived, archived_at FROM classes WHERE class_id = ?');
    $stmt->execute([$classId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    addEffectiveArchiveFields($result);

    sendSuccess([
        'message' => $message,
        'class' => $result,
    ]);
} catch (PDOException $e) {
    if ($e->getCode() === '42S22') {
        sendError('Soft-archive migration is required before archiving classes.', 'ARCHIVE_MIGRATION_REQUIRED', 503);
    }
    error_log('QuizSystem archive class error: ' . $e->getMessage());
    sendError('Could not update the class archive state.', 'DB_ERROR', 500);
}
