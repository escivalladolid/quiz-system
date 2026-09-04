<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/exam_status.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('This endpoint only accepts POST requests.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo   = getDbConnection();
$user  = requireRole($pdo, ['STUDENT']);
$input = getJsonInput();
requireFields($input, ['exam_id', 'question_id', 'answer']);

$examId    = (int) $input['exam_id'];
$questionId = (int) $input['question_id'];
$answer    = $input['answer'];
$studentId = $user['user_id'];

// Sync time-based transitions so the status below is always current.
syncExamStatuses($pdo);

// Verify the exam exists and is still live
$examStmt = $pdo->prepare(
    'SELECT e.exam_id, e.status, e.class_id FROM exams e WHERE e.exam_id = :eid'
);
$examStmt->execute(['eid' => $examId]);
$exam = $examStmt->fetch();

if (!$exam) {
    sendError('Exam not found.', 'NOT_FOUND', 404);
}

// If the exam became CLOSED/ARCHIVED, immediately reject any further saves.
if (strtoupper((string)$exam['status']) !== 'LIVE') {
    sendError('Exam closed. Further submissions are rejected.', 'EXAM_CLOSED', 403);
}

// Verify student is enrolled
$enrollCheck = $pdo->prepare('SELECT 1 FROM enrollments WHERE user_id = :uid AND class_id = :cid');
$enrollCheck->execute(['uid' => $studentId, 'cid' => $exam['class_id']]);
if (!$enrollCheck->fetch()) {
    sendError('You are not enrolled in this class.', 'NOT_ENROLLED', 403);
}

// Verify the question belongs to this exam
$qCheck = $pdo->prepare('SELECT 1 FROM questions WHERE question_id = :qid AND exam_id = :eid');
$qCheck->execute(['qid' => $questionId, 'eid' => $examId]);
if (!$qCheck->fetch()) {
    sendError('Question does not belong to this exam.', 'INVALID_QUESTION', 422);
}

// Check if already submitted
$subCheck = $pdo->prepare('SELECT 1 FROM exam_submissions WHERE exam_id = :eid AND user_id = :uid');
$subCheck->execute(['eid' => $examId, 'uid' => $studentId]);
if ($subCheck->fetch()) {
    sendError('Exam already submitted. Cannot save answers.', 'ALREADY_SUBMITTED', 409);
}

/*
 * Auto-save answer to the exam_temp_answers table.
 *
 * IMPORTANT: You must create this table once. Run the following SQL:
 *
 *   CREATE TABLE IF NOT EXISTS exam_temp_answers (
 *       temp_id      INT AUTO_INCREMENT PRIMARY KEY,
 *       exam_id      INT NOT NULL,
 *       user_id      INT NOT NULL,
 *       answers_json JSON DEFAULT NULL,
 *       created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 *       updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 *       UNIQUE KEY unique_temp_answer (exam_id, user_id),
 *       FOREIGN KEY (exam_id) REFERENCES exams(exam_id) ON DELETE CASCADE,
 *       FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
 *   ) ENGINE=InnoDB;
 */

$hasTempTable = true;
try {
    $pdo->query('SELECT 1 FROM exam_temp_answers LIMIT 1');
} catch (PDOException $e) {
    $hasTempTable = false;
}

if (!$hasTempTable) {
    // Fallback: acknowledge the save without persisting
    sendSuccess([
        'saved'       => true,
        'question_id' => $questionId,
        'answer'      => $answer,
        'persisted'   => false,
        'note'        => 'exam_temp_answers table not found. Answer acknowledged but not persisted.',
    ]);
}

$qidStr = (string) $questionId;

// Try to find existing temp answer row
$existingStmt = $pdo->prepare(
    'SELECT temp_id, answers_json FROM exam_temp_answers
     WHERE exam_id = :eid AND user_id = :uid'
);
$existingStmt->execute(['eid' => $examId, 'uid' => $studentId]);
$tempRow = $existingStmt->fetch();

if ($tempRow) {
    $currentAnswers = json_decode($tempRow['answers_json'], true) ?? [];
    $currentAnswers[$qidStr] = $answer;
    $pdo->prepare(
        'UPDATE exam_temp_answers SET answers_json = :answers, updated_at = NOW()
         WHERE temp_id = :tid'
    )->execute([
        'answers' => json_encode($currentAnswers),
        'tid'     => $tempRow['temp_id'],
    ]);
} else {
    $pdo->prepare(
        'INSERT INTO exam_temp_answers (exam_id, user_id, answers_json, created_at, updated_at)
         VALUES (:eid, :uid, :answers, NOW(), NOW())'
    )->execute([
        'eid'     => $examId,
        'uid'     => $studentId,
        'answers' => json_encode([$qidStr => $answer]),
    ]);
}

sendSuccess([
    'saved'       => true,
    'question_id' => $questionId,
    'answer'      => $answer,
    'persisted'   => true,
]);
