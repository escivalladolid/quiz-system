<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';

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

if (!$exam_id) {
    sendError('Missing exam_id.', 'BAD_REQUEST', 400);
}

try {
    $stmt = $pdo->prepare("SELECT e.*, c.teacher_id FROM exams e JOIN classes c ON e.class_id=c.class_id WHERE e.exam_id=?");
    $stmt->execute([$exam_id]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$exam) {
        sendError('Exam not found.', 'NOT_FOUND', 404);
    }
    if ($exam['teacher_id'] != $teacher_id) {
        sendError('Unauthorized.', 'FORBIDDEN', 403);
    }

    $pdo->beginTransaction();
    // Legacy/explicit cleanup. FKs cascade for exam_attempts and
    // exam_answer_revisions on exam deletion, but delete explicitly so no
    // shipped migration ordering matters. Tolerate tables that a deployment
    // simply has not migrated yet (42S02 = missing base table).
    $legacyDelete = function (int $examId, string $table) use ($pdo) {
        try {
            $pdo->prepare("DELETE FROM `$table` WHERE exam_id=?")->execute([$examId]);
        } catch (PDOException $e) {
            if ($e->getCode() !== '42S02') throw $e;
        }
    };
    $legacyDelete($exam_id, 'exam_temp_answers');
    $legacyDelete($exam_id, 'exam_answer_revisions');
    $legacyDelete($exam_id, 'exam_attempts');
    $legacyDelete($exam_id, 'exam_activity_log');
    $legacyDelete($exam_id, 'exam_live_presence');
    $legacyDelete($exam_id, 'exam_proctoring_log');
    $legacyDelete($exam_id, 'exam_submissions');
    $pdo->prepare("DELETE FROM questions WHERE exam_id=?")->execute([$exam_id]);
    $pdo->prepare("DELETE FROM exams WHERE exam_id=?")->execute([$exam_id]);
    $pdo->commit();

    sendSuccess(['message' => 'Exam deleted successfully.']);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
