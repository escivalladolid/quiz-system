<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/exam_status.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('This endpoint only accepts POST requests.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo       = getDbConnection();
$teacher   = requireRole($pdo, ['TEACHER']);
$teacherId = $teacher['user_id'];

$input = getJsonInput();
requireFields($input, ['exam_id']);

$examId = (int) $input['exam_id'];
if ($examId <= 0) {
    sendError('Invalid exam id.', 'INVALID_ID', 422);
}

$releaseAll = !empty($input['release_all']);
$studentId  = isset($input['student_id']) ? (int) $input['student_id'] : 0;
if (!$releaseAll && $studentId <= 0) {
    sendError('Provide a student_id or set release_all = true.', 'BAD_REQUEST', 400);
}

try {
    syncExamStatuses($pdo);

    // Verify the teacher owns this exam's class.
    $examStmt = $pdo->prepare(
        'SELECT e.exam_id, e.exam_name, e.is_closed, c.teacher_id
         FROM exams e
         JOIN classes c ON c.class_id = e.class_id
         WHERE e.exam_id = ?'
    );
    $examStmt->execute([$examId]);
    $exam = $examStmt->fetch();

    if (!$exam) {
        sendError('Exam not found.', 'NOT_FOUND', 404);
    }

    if ((int) $exam['teacher_id'] !== $teacherId) {
        sendError('You do not own this exam.', 'FORBIDDEN', 403);
    }

    if ($releaseAll) {
        // Release the detailed review for every student of this exam.
        // Idempotent: released_at keeps its first value (COALESCE).
        $upd = $pdo->prepare(
            "UPDATE exam_submissions
                SET results_released = 1,
                    released_at = COALESCE(released_at, NOW())
              WHERE exam_id = :eid"
        );
        $upd->execute(['eid' => $examId]);
        $releasedCount = $upd->rowCount();

        sendSuccess([
            'exam_id'        => $examId,
            'release_all'    => true,
            'released_count' => $releasedCount,
            'is_closed'      => (int) $exam['is_closed'] === 1,
        ]);
    }

    // Single-student release.
    $subStmt = $pdo->prepare(
        'SELECT submission_id FROM exam_submissions
         WHERE exam_id = :eid AND user_id = :uid'
    );
    $subStmt->execute(['eid' => $examId, 'uid' => $studentId]);
    $submission = $subStmt->fetch();

    if (!$submission) {
        sendError('No submission found for this student.', 'NOT_FOUND', 404);
    }

    $upd = $pdo->prepare(
        "UPDATE exam_submissions
            SET results_released = 1,
                released_at = COALESCE(released_at, NOW())
          WHERE exam_id = :eid AND user_id = :uid"
    );
    $upd->execute(['eid' => $examId, 'uid' => $studentId]);

    sendSuccess([
        'exam_id'          => $examId,
        'student_id'       => $studentId,
        'release_all'      => false,
        'results_released' => true,
        'is_closed'        => (int) $exam['is_closed'] === 1,
    ]);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}