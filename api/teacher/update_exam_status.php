<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/exam_status.php';
require_once __DIR__ . '/../../helpers/notifications.php';

header('Content-Type: application/json');

$pdo = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

$teacher = requireRole($pdo, ['TEACHER']);
$teacher_id = $teacher['user_id'];

$input = getJsonInput();

if (!$input) {
    sendError('Invalid JSON input.', 'BAD_REQUEST', 400);
}

$exam_id = $input['exam_id'] ?? null;
$new_status = strtoupper((string)($input['status'] ?? ''));

if (!$exam_id || !$new_status) {
    sendError('Missing exam_id or status.', 'BAD_REQUEST', 400);
}

$allowed = ['DRAFT', 'SCHEDULED', 'LIVE', 'CLOSED', 'ARCHIVED'];
if (!in_array($new_status, $allowed)) {
    sendError('Invalid status value.', 'BAD_REQUEST', 400);
}

try {
    syncExamStatuses($pdo);

    $stmt = $pdo->prepare("SELECT e.*, c.teacher_id FROM exams e JOIN classes c ON e.class_id=c.class_id WHERE e.exam_id=?");
    $stmt->execute([$exam_id]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$exam) {
        sendError('Exam not found.', 'NOT_FOUND', 404);
    }
    if ($exam['teacher_id'] != $teacher_id) {
        sendError('Unauthorized.', 'FORBIDDEN', 403);
    }

    $current = strtoupper((string)$exam['status']);

    // Archived exams are read-only and can never change or go live again.
    if ($current === 'ARCHIVED' && $new_status !== 'ARCHIVED') {
        sendError('Archived exams cannot be modified.', 'EXAM_ARCHIVED', 409);
    }

    // Teachers may archive only CLOSED exams.
    if ($new_status === 'ARCHIVED' && $current !== 'CLOSED') {
        sendError('Only closed exams can be archived.', 'EXAM_NOT_CLOSED', 409);
    }

    // Reopening a closed exam: clear the closed flag and reset the availability
    // start. Duration_minutes is the student's personal countdown and must not
    // be used as the availability end. Drop a stale old end_time; preserve a
    // future explicit availability close.
    $isReopen = in_array($current, ['CLOSED'], true) && in_array($new_status, ['SCHEDULED', 'LIVE'], true);
    if ($isReopen) {
        $endTime = (!empty($exam['end_time']) && strtotime($exam['end_time']) > time())
            ? $exam['end_time'] : null;
        $pdo->prepare("UPDATE exams SET status=?, start_time=NOW(), end_time=?, is_closed=0, closed_at=NULL WHERE exam_id=?")
            ->execute([$new_status, $endTime, $exam_id]);
    } elseif ($new_status === 'CLOSED') {
        $stmt2 = $pdo->prepare("UPDATE exams SET status=?, is_closed=1, closed_at=NOW() WHERE exam_id=?");
        $stmt2->execute([$new_status, $exam_id]);
    } else {
        // Moving an exam to SCHEDULED without an explicit start time schedules
        // it for the next server second. Do not derive an availability close
        // from duration_minutes; that field belongs to each student's attempt.
        if ($new_status === 'SCHEDULED' && ($exam['start_time'] === null || $exam['start_time'] === '')) {
            $pdo->prepare("UPDATE exams SET start_time=NOW(), end_time=CASE WHEN end_time IS NOT NULL AND end_time <= NOW() THEN NULL ELSE end_time END WHERE exam_id=?")
                ->execute([$exam_id]);
        }
        $stmt2 = $pdo->prepare("UPDATE exams SET status=? WHERE exam_id=?");
        $stmt2->execute([$new_status, $exam_id]);
    }

    $final = $pdo->prepare("SELECT status, is_closed, closed_at FROM exams WHERE exam_id=?");
    $final->execute([$exam_id]);
    $row = $final->fetch(PDO::FETCH_ASSOC);

    if ($current === 'DRAFT' && in_array($new_status, ['SCHEDULED', 'LIVE'], true)) {
        try {
            createExamPublishedNotification(
                $pdo,
                (int) $exam['class_id'],
                (int) $exam_id,
                (string) ($exam['exam_name'] ?? '')
            );
        } catch (Throwable $notificationError) {
            error_log('Exam published notification could not be created: ' . $notificationError->getMessage());
        }
    }

    sendSuccess([
        'message' => 'Exam status updated successfully.',
        'exam_id' => (int)$exam_id,
        'status' => $row['status'],
        'is_closed' => (int)$row['is_closed'] === 1,
        'closed_at' => $row['closed_at'],
    ]);
} catch (PDOException $e) {
    sendError('Database error: ' . $e->getMessage(), 'DB_ERROR', 500);
}
