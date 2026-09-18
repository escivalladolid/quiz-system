<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/archive.php';
require_once __DIR__ . '/../../helpers/exam_status.php';

header('Content-Type: application/json');

$pdo = getDbConnection();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

$teacher = requireRole($pdo, ['TEACHER']);
$input = getJsonInput();
requireFields($input, ['exam_id']);
$examId = (int) $input['exam_id'];
$action = strtolower(trim((string) ($input['action'] ?? 'archive')));
if (!in_array($action, ['archive', 'unarchive'], true)) {
    sendError('Invalid action. Use archive or unarchive.', 'INVALID_ACTION', 422);
}

try {
    syncExamStatuses($pdo);
    $stmt = $pdo->prepare(
        'SELECT e.exam_id, e.exam_name, e.status, e.is_archived, e.archived_at, e.is_closed, c.teacher_id
           FROM exams e JOIN classes c ON c.class_id = e.class_id
          WHERE e.exam_id = ? AND c.teacher_id = ?'
    );
    $stmt->execute([$examId, $teacher['user_id']]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$exam) {
        sendError('Exam not found.', 'NOT_FOUND', 404);
    }

    if ($action === 'archive') {
        $effective = effectiveArchiveStatus($exam);
        if ($effective === 'ARCHIVED') {
            sendError('This exam is already archived.', 'INVALID_STATE', 409);
        }
        if ($effective !== 'CLOSED' && (int) ($exam['is_closed'] ?? 0) !== 1) {
            sendError('Only closed exams can be archived.', 'EXAM_NOT_CLOSED', 409);
        }
        $stmt = $pdo->prepare(
            'UPDATE exams SET is_archived = 1, archived_at = COALESCE(archived_at, NOW()) WHERE exam_id = ?'
        );
        $stmt->execute([$examId]);
        $message = 'Exam archived successfully.';
    } else {
        if (!((int) ($exam['is_archived'] ?? 0) === 1 || strtoupper((string) $exam['status']) === 'ARCHIVED')) {
            sendError('This exam is not archived.', 'INVALID_STATE', 409);
        }
        $stmt = $pdo->prepare(
            "UPDATE exams
                SET is_archived = 0,
                    archived_at = NULL,
                    status = CASE WHEN status = 'ARCHIVED' THEN 'CLOSED' ELSE status END
              WHERE exam_id = ?"
        );
        $stmt->execute([$examId]);
        $message = 'Exam restored successfully.';
    }

    $stmt = $pdo->prepare('SELECT exam_id, exam_name, status, is_archived, archived_at, is_closed FROM exams WHERE exam_id = ?');
    $stmt->execute([$examId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    addEffectiveArchiveFields($result);
    sendSuccess(['message' => $message, 'exam' => $result]);
} catch (PDOException $e) {
    if ($e->getCode() === '42S22') {
        sendError('Soft-archive migration is required before archiving exams.', 'ARCHIVE_MIGRATION_REQUIRED', 503);
    }
    error_log('QuizSystem archive exam error: ' . $e->getMessage());
    sendError('Could not update the exam archive state.', 'DB_ERROR', 500);
}
