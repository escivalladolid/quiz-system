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
requireFields($input, ['exam_id', 'action']);

$exam_id = (int) $input['exam_id'];
$action  = strtolower(trim($input['action']));

if (!in_array($action, ['force_close', 'schedule', 'archive', 'unarchive'], true)) {
    sendError('Invalid action. Use force_close, schedule, archive or unarchive.', 'INVALID_ACTION', 422);
}

try {
    $stmt = $pdo->prepare('SELECT exam_id, exam_name, status, is_archived, archived_at, is_closed FROM exams WHERE exam_id = ?');
    $stmt->execute([$exam_id]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$exam) {
        sendError('Exam not found.', 'NOT_FOUND', 404);
    }
    $oldStatus = effectiveArchiveStatus($exam);
    $isArchived = ((int) ($exam['is_archived'] ?? 0) === 1 || strtoupper((string) ($exam['status'] ?? '')) === 'ARCHIVED');

    $logDesc = null;

    if ($action === 'force_close') {
        if ($isArchived || $oldStatus !== 'LIVE') {
            sendError('Only a LIVE exam can be force-closed.', 'INVALID_STATE', 422);
        }
        $stmt = $pdo->prepare(
            "UPDATE exams SET status = 'CLOSED', is_closed = 1, closed_at = NOW(), end_time = NOW() WHERE exam_id = ?"
        );
        $stmt->execute([$exam_id]);
        $logDesc = "Force-closed exam {$exam['exam_name']}";
    }

    if ($action === 'schedule') {
        $start = trim($input['start_time'] ?? '');
        $end   = trim($input['end_time'] ?? '');
        if ($start === '' || $end === '' || !strtotime($start) || !strtotime($end)) {
            sendError('Valid start_time and end_time are required to schedule.', 'INVALID_TIME', 422);
        }
        if (strtotime($end) <= strtotime($start)) {
            sendError('End time must be after start time.', 'INVALID_TIME', 422);
        }
        if ($isArchived || in_array($oldStatus, ['LIVE', 'SCHEDULED'], true)) {
            sendError('This exam is already ' . strtolower($oldStatus) . '.', 'INVALID_STATE', 422);
        }
        $stmt = $pdo->prepare(
            "UPDATE exams SET status = 'SCHEDULED', is_closed = 0, closed_at = NULL, start_time = ?, end_time = ? WHERE exam_id = ?"
        );
        $stmt->execute([$start, $end, $exam_id]);
        $logDesc = "Scheduled exam {$exam['exam_name']} ($start → $end)";
    }

    if ($action === 'archive') {
        if ($isArchived) {
            sendError('This exam is already archived.', 'INVALID_STATE', 422);
        }
        $stmt = $pdo->prepare(
            'UPDATE exams SET is_archived = 1, archived_at = COALESCE(archived_at, NOW()) WHERE exam_id = ?'
        );
        $stmt->execute([$exam_id]);
        $logDesc = "Archived exam {$exam['exam_name']}";
    }

    if ($action === 'unarchive') {
        if (!$isArchived) {
            sendError('This exam is not archived.', 'INVALID_STATE', 422);
        }
        $stmt = $pdo->prepare(
            "UPDATE exams SET is_archived = 0, archived_at = NULL,
                    status = CASE WHEN status = 'ARCHIVED' THEN 'CLOSED' ELSE status END
              WHERE exam_id = ?"
        );
        $stmt->execute([$exam_id]);
        $logDesc = "Restored exam {$exam['exam_name']}";
    }

    $log = $pdo->prepare('INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)');
    $log->execute([$admin['user_id'], 'EXAM_STATUS', $logDesc]);

    $stmt = $pdo->prepare('SELECT status, is_archived, archived_at FROM exams WHERE exam_id = ?');
    $stmt->execute([$exam_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $newStatus = effectiveArchiveStatus($result);

    sendSuccess([
        'exam_id' => $exam_id,
        'previous_status' => $oldStatus,
        'status' => $newStatus,
        'is_archived' => (int) ($result['is_archived'] ?? 0),
        'archived_at' => $result['archived_at'] ?? null,
    ]);
} catch (PDOException $e) {
    if ($e->getCode() === '42S22') {
        sendError('Soft-archive migration is required before archiving exams.', 'ARCHIVE_MIGRATION_REQUIRED', 503);
    }
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
