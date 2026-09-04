<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('This endpoint only accepts GET requests.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo    = getDbConnection();
$user   = requireRole($pdo, ['STUDENT']);

/**
 * Determine whether a student-facing review is available for an exam.
 * Lazy auto-close: if the exam's scheduled end time has passed but the
 * stored flag wasn't flipped yet, flip it now so later reads are cheap.
 */
function resolveReviewAvailability(PDO $pdo, int $examId): array {
    $stmt = $pdo->prepare(
        'SELECT e.exam_id, e.is_closed, e.end_time
         FROM exams e WHERE e.exam_id = :eid'
    );
    $stmt->execute(['eid' => $examId]);
    $exam = $stmt->fetch();

    if (!$exam) {
        return ['review_available' => false];
    }

    $pastDeadline = !empty($exam['end_time']) && (strtotime($exam['end_time']) < time());

    if ((int) $exam['is_closed'] === 1) {
        return ['review_available' => true];
    }

    if ($pastDeadline) {
        $pdo->prepare(
            'UPDATE exams SET is_closed = 1, closed_at = NOW() WHERE exam_id = :eid AND is_closed = 0'
        )->execute(['eid' => $examId]);
        return ['review_available' => true];
    }

    return ['review_available' => false];
}

$examId = isset($_GET['exam_id']) ? (int) $_GET['exam_id'] : 0;

// Per-exam detail (used by the student result/review screen)
if ($examId > 0) {
    $stmt = $pdo->prepare(
        'SELECT s.submission_id, s.exam_id, s.score, s.correct_count, s.total_questions,
                s.time_used_secs, s.submitted_at, s.answers_json,
                e.exam_name, e.total_points AS max_points, e.passing_score,
                c.subject_code, c.subject_name
         FROM exam_submissions s
         JOIN exams e ON e.exam_id = s.exam_id
         JOIN classes c ON c.class_id = e.class_id
         WHERE s.user_id = :uid AND s.exam_id = :eid'
    );
    $stmt->execute(['uid' => $user['user_id'], 'eid' => $examId]);
    $result = $stmt->fetch();

    if (!$result) {
        sendError('No submission found for this exam.', 'NOT_FOUND', 404);
    }

    $availability = resolveReviewAvailability($pdo, $examId);
    $reviewAvailable = $availability['review_available'];

    $correctCount = (int) $result['correct_count'];
    $totalQuestions = (int) $result['total_questions'];
    $percentage = $totalQuestions > 0 ? round(($correctCount / $totalQuestions) * 100, 2) : 0.0;

    $passed = $result['passing_score'] !== null
        ? ($percentage >= (float) $result['passing_score'])
        : null;

    $payload = [
        'submission_id'     => (int) $result['submission_id'],
        'exam_id'           => (int) $result['exam_id'],
        'exam_name'         => $result['exam_name'],
        'subject_code'      => $result['subject_code'],
        'subject_name'      => $result['subject_name'],
        'score'             => $correctCount,
        'max_points'        => $totalQuestions,
        'correct_count'     => $correctCount,
        'total_questions'   => $totalQuestions,
        'percentage'        => $percentage,
        'passed'            => $passed,
        'time_used_secs'    => $result['time_used_secs'] !== null ? (int) $result['time_used_secs'] : null,
        'submitted_at'      => $result['submitted_at'],
        'review_available'  => $reviewAvailable,
    ];

    // Only expose per-question correctness once the exam is closed
    if ($reviewAvailable) {
        $payload['questions'] = buildReviewQuestions($pdo, $examId, $result['answers_json']);
    }

    sendSuccess($payload);
}

// List of all results for the student
$stmt = $pdo->prepare(
    'SELECT s.submission_id, s.exam_id, s.score, s.correct_count, s.total_questions,
            s.time_used_secs, s.submitted_at,
            e.exam_name, e.total_points AS max_points,
            c.subject_code, c.subject_name
     FROM exam_submissions s
     JOIN exams e ON e.exam_id = s.exam_id
     JOIN classes c ON c.class_id = e.class_id
     WHERE s.user_id = :uid
     ORDER BY s.submitted_at DESC'
);
$stmt->execute(['uid' => $user['user_id']]);
$results = $stmt->fetchAll();

$payload = [];
foreach ($results as $r) {
    $availability = resolveReviewAvailability($pdo, (int) $r['exam_id']);
    $correct = (int) $r['correct_count'];
    $total   = (int) $r['total_questions'];
    $pct     = $total > 0 ? round(($correct / $total) * 100, 2) : 0.0;
    $payload[] = [
        'submission_id'    => (int) $r['submission_id'],
        'exam_id'          => (int) $r['exam_id'],
        'score'            => $correct,
        'correct_count'    => $correct,
        'total_questions'  => $total,
        'percentage'       => $pct,
        'time_used_secs'   => $r['time_used_secs'] !== null ? (int) $r['time_used_secs'] : null,
        'submitted_at'     => $r['submitted_at'],
        'exam_name'        => $r['exam_name'],
        'max_points'       => $total,
        'subject_code'     => $r['subject_code'],
        'subject_name'     => $r['subject_name'],
        'review_available' => $availability['review_available'],
    ];
}

sendSuccess(['results' => $payload]);

/**
 * Build the per-question review breakdown for a submission.
 * Returns only the data the student may see (no hidden answer fields).
 */
function buildReviewQuestions(PDO $pdo, int $examId, ?string $answersJson): array {
    $answers = $answersJson ? json_decode($answersJson, true) : [];
    if (!is_array($answers)) $answers = [];

    $qStmt = $pdo->prepare(
        'SELECT question_id, question_text, question_type, options, correct_answer,
                points, answer_matching
         FROM questions WHERE exam_id = :eid ORDER BY order_num ASC'
    );
    $qStmt->execute(['eid' => $examId]);
    $questions = $qStmt->fetchAll();

    $items = [];
    foreach ($questions as $q) {
        $type = normalizeQuestionType($q['question_type'] ?? 'MC');

        // Normalize options for display
        $options = null;
        if ($type === 'TF') {
            $options = ['True', 'False'];
        } elseif ($type === 'MC' || $type === 'ENUM') {
            $jsonOptions = $q['options'] ? json_decode($q['options'], true) : null;
            if (is_array($jsonOptions) && count($jsonOptions) > 0) {
                $options = array_values($jsonOptions);
            }
        }

        $studentAnswer = $answers[(string) $q['question_id']] ?? null;
        if ($studentAnswer !== null) {
            // Older/imported submissions stored the option letter ("B") instead
            // of the option text; resolve it so display + grading stay consistent.
            $studentAnswer = resolveOptionLetter($studentAnswer, $options, $type);
        }

        $isCorrect = null;
        if ($studentAnswer !== null) {
            $isCorrect = isAnswerCorrect($type, $studentAnswer, $q['correct_answer'], $q['answer_matching']);
        }

        $items[] = [
            'question_id'    => (int) $q['question_id'],
            'question_text'  => $q['question_text'],
            'question_type'  => $type,
            'options'        => $options,
            'correct_answer' => $q['correct_answer'],
            'student_answer' => $studentAnswer,
            'is_correct'     => $isCorrect,
            'points'         => (int) ($q['points'] ?? 1),
        ];
    }

    return $items;
}

/**
 * If a student answer is a bare option letter ("B") for an MC/TF question,
 * resolve it to the matching option text. Anything else passes through.
 */
function resolveOptionLetter($studentAns, ?array $options, string $type) {
    if ($type !== 'MC' && $type !== 'TF') return $studentAns;
    if (!is_array($options) || count($options) === 0) return $studentAns;

    $ans = trim((string) $studentAns);
    if ($ans === '' || strlen($ans) > 1) return $studentAns;

    $letter = strtoupper($ans);
    if ($letter < 'A' || $letter > 'Z') return $studentAns;

    $idx = ord($letter) - ord('A');
    if ($idx < 0 || $idx >= count($options)) return $studentAns;

    $optText = trim((string) $options[$idx]);
    return $optText !== '' ? $optText : $studentAns;
}

function normalizeQuestionType(?string $raw): string {
    $type = strtoupper(trim($raw ?? ''));
    switch ($type) {
        case 'MULTIPLE_CHOICE': return 'MC';
        case 'TRUE_FALSE':      return 'TF';
        case 'IDENTIFICATION':  return 'ID';
        case 'ENUMERATION':     return 'ENUM';
        default:                return $type !== '' ? $type : 'MC';
    }
}

/**
 * Same matching rules as exams/submit.php so the review matches the grade.
 */
function isAnswerCorrect(string $type, $studentAns, ?string $correct, ?string $matching): bool {
    if ($studentAns === null) return false;
    $matching = $matching ?? 'EXACT';

    switch ($type) {
        case 'MC':
        case 'TF':
            return trim((string) $studentAns) === trim((string) $correct);

        case 'ID':
            $studentTrimmed = trim((string) $studentAns);
            $correctTrimmed = trim((string) $correct);
            if ($matching === 'IGNORE_CASE') {
                return mb_strtolower($studentTrimmed) === mb_strtolower($correctTrimmed);
            }
            return $studentTrimmed === $correctTrimmed;

        case 'ENUM':
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
            return count($expectedLines) > 0 && $matchedLines === count($expectedLines);

        default:
            return trim((string) $studentAns) === trim((string) $correct);
    }
}
