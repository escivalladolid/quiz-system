<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/exam_status.php';
require_once __DIR__ . '/../../helpers/exam_grading.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('This endpoint only accepts POST requests.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo  = getDbConnection();
$user = requireRole($pdo, ['STUDENT']);
$input = getJsonInput();
requireFields($input, ['exam_id']);

$examId       = (int) $input['exam_id'];
$answers      = (isset($input['answers']) && is_array($input['answers'])) ? $input['answers'] : [];
$timeUsedSecs = isset($input['time_used_secs']) ? (int) $input['time_used_secs'] : null;
$exitAttempts = isset($input['exit_attempts']) ? (int) $input['exit_attempts'] : 0;
$autoSubmitted = !empty($input['auto_submitted']) ? 1 : 0;

// Sync time-based transitions so the status below is always current.
syncExamStatuses($pdo);

// The client keeps its live answers in memory and also auto-saves each one to
// exam_temp_answers (exam_save_answer.php). A fast submit can carry an
// incomplete in-memory map (e.g. radio selections made moments before
// submitting), dropping answers. Merge the server-side auto-saved answers as
// the authoritative complement so no answered question is ever lost from
// grading: for any question the client did NOT send, use the auto-saved value.
$mergeStmt = $pdo->prepare(
    'SELECT answers_json FROM exam_temp_answers
     WHERE exam_id = :eid AND user_id = :uid'
);
$mergeStmt->execute(['eid' => $examId, 'uid' => $user['user_id']]);
$tempRow = $mergeStmt->fetch();
if ($tempRow && !empty($tempRow['answers_json'])) {
    $tempSaved = json_decode($tempRow['answers_json'], true);
    if (is_array($tempSaved)) {
        foreach ($tempSaved as $qid => $value) {
            $qid = (string) $qid;
            if (!array_key_exists($qid, $answers) && $value !== null && $value !== '') {
                $answers[$qid] = $value;
            }
        }
    }
}

// Get exam
$examStmt = $pdo->prepare(
    'SELECT e.exam_id, e.status, e.class_id, e.passing_score
     FROM exams e WHERE e.exam_id = :eid'
);
$examStmt->execute(['eid' => $examId]);
$exam = $examStmt->fetch();

if (!$exam) {
    sendError('Exam not found.', 'NOT_FOUND', 404);
}

// If the student has already submitted, this is a resume/resubmit attempt.
// Return the existing result idempotently instead of erroring, so the app
// never shows "submit failed" for an already-submitted exam.
$subCheck = $pdo->prepare(
    'SELECT submission_id, score, correct_count, total_questions, time_used_secs,
            submitted_at, exit_attempts, auto_submitted
     FROM exam_submissions WHERE exam_id = :eid AND user_id = :uid'
);
$subCheck->execute(['eid' => $examId, 'uid' => $user['user_id']]);
$existingSub = $subCheck->fetch();
if ($existingSub) {
    $existingCorrect = (int) $existingSub['correct_count'];
    $existingTotal   = (int) $existingSub['total_questions'];
    $existingPct     = $existingTotal > 0 ? round(($existingCorrect / $existingTotal) * 100, 2) : 0.0;
    $existingPassed  = ($exam['passing_score'] !== null)
        ? ($existingPct >= (float) $exam['passing_score'])
        : null;

    sendSuccess([
        'already_submitted' => true,
        'submission_id'     => (int) $existingSub['submission_id'],
        'score'             => $existingCorrect,
        'correct_count'     => $existingCorrect,
        'total_questions'   => $existingTotal,
        'percentage'        => $existingPct,
        'time_used_secs'    => $existingSub['time_used_secs'] !== null ? (int) $existingSub['time_used_secs'] : null,
        'exit_attempts'     => (int) $existingSub['exit_attempts'],
        'auto_submitted'    => (bool) $existingSub['auto_submitted'],
        'submitted_at'      => $existingSub['submitted_at'],
        'passed'            => $existingPassed,
    ]);
}

// If the exam closed (manually or automatically), reject further submissions.
if (strtoupper((string)$exam['status']) !== 'LIVE') {
    sendError('Exam closed. Further submissions are rejected.', 'EXAM_CLOSED', 403);
}

// Verify enrollment
$enrollCheck = $pdo->prepare('SELECT 1 FROM enrollments WHERE user_id = :uid AND class_id = :cid');
$enrollCheck->execute(['uid' => $user['user_id'], 'cid' => $exam['class_id']]);
if (!$enrollCheck->fetch()) {
    sendError('You are not enrolled in this class.', 'NOT_ENROLLED', 403);
}

// Fetch all questions for this exam
$qStmt = $pdo->prepare(
    'SELECT question_id, question_text, question_type, options, correct_answer,
            points, answer_matching
     FROM questions WHERE exam_id = :eid'
);
$qStmt->execute(['eid' => $examId]);
$questions = $qStmt->fetchAll();

$totalQuestions = count($questions);
$correctCount   = 0;

// Default objective scoring: every question is worth exactly 1 point.
// Correct = +1, wrong/unanswered = +0. Total possible = number of questions.
foreach ($questions as $q) {
    $qid        = (string) $q['question_id'];
    $type       = normalizeQuestionType($q['question_type'] ?? 'MC');
    $matching   = $q['answer_matching'] ?? 'EXACT';
    $correct    = $q['correct_answer'];
    $studentAns = $answers[$qid] ?? null;

    if ($studentAns === null) {
        // Unanswered — 0 points.
        continue;
    }

    $isCorrect = false;

    switch ($type) {
        case 'MC':
        case 'TF':
            // Older submissions may store the option letter ("B") instead of text
            $resolved = resolveOptionLetter($studentAns, json_decode($q['options'] ?? '', true) ?: null, $type);
            $isCorrect = (trim((string) $resolved) === trim((string) $correct));
            break;

        case 'ID':
            $studentTrimmed = trim((string) $studentAns);
            $correctTrimmed = trim((string) $correct);
            if ($matching === 'IGNORE_CASE') {
                $isCorrect = (mb_strtolower($studentTrimmed) === mb_strtolower($correctTrimmed));
            } else {
                $isCorrect = ($studentTrimmed === $correctTrimmed);
            }
            break;

        case 'ENUM':
            // All-or-nothing: every expected line must match, exactly like
            // exam_grading.php::isAnswerCorrect. No partial credit.
            $expectedLines = preg_split('/\r?\n|\|/', trim((string) $correct));
            $expectedLines = array_map('trim', $expectedLines);
            $expectedLines = array_filter($expectedLines, fn($l) => $l !== '');

            $studentLines = preg_split('/\r?\n|,|\|/', (string) $studentAns);
            $studentLines = array_map('trim', $studentLines);
            $studentLines = array_filter($studentLines, fn($l) => $l !== '');

            $matchedLines = 0;
            foreach ($expectedLines as $expected) {
                foreach ($studentLines as $sLine) {
                    $match = ($matching === 'IGNORE_CASE')
                        ? (mb_strtolower($sLine) === mb_strtolower($expected))
                        : ($sLine === $expected);
                    if ($match) {
                        $matchedLines++;
                        break;
                    }
                }
            }

            $isCorrect = count($expectedLines) > 0 && $matchedLines === count($expectedLines);
            break;
    }

    if ($isCorrect) {
        $correctCount++;
    }
}

// SCORE = number of correct answers (= raw points, 1 per correct question).
$score = $correctCount;

// PERCENTAGE = (correct / total questions) x 100. Never treated as added points.
$percentage = $totalQuestions > 0
    ? round(($correctCount / $totalQuestions) * 100, 2)
    : 0.0;

// Pass/fail is decided from the percentage against the teacher's passing score.
$passed = ($exam['passing_score'] !== null)
    ? ($percentage >= (float) $exam['passing_score'])
    : null;

// Save submission
$pdo->prepare(
    'INSERT INTO exam_submissions
        (exam_id, user_id, answers_json, score, correct_count, total_questions,
         time_used_secs, exit_attempts, auto_submitted)
     VALUES
        (:eid, :uid, :answers, :score, :correct, :total, :time, :exit, :auto)'
)->execute([
    'eid'    => $examId,
    'uid'    => $user['user_id'],
    'answers'=> json_encode($answers),
    'score'  => $score,
    'correct'=> $correctCount,
    'total'  => $totalQuestions,
    'time'   => $timeUsedSecs,
    'exit'   => $exitAttempts,
    'auto'   => $autoSubmitted,
]);

sendSuccess([
    'score'             => $score,
    'correct_count'     => $correctCount,
    'total_questions'   => $totalQuestions,
    'percentage'        => $percentage,
    'passing_score'     => $exam['passing_score'] ?? null,
    'passed'            => $passed,
    'time_used_secs'    => $timeUsedSecs,
    'exit_attempts'     => $exitAttempts,
    'auto_submitted'    => (bool) $autoSubmitted,]);
