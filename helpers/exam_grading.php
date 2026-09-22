<?php
/**
 * Shared answer-grading + review-building helpers.
 *
 * Submission and review both call these functions so matching cannot drift.
 */

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
 * Map a question type (short or long form) to the canonical ENUM value used
 * by the questions.question_type column. The DB column only accepts the full
 * words; any other value is silently coerced to '' by MySQL, wiping the type.
 */
function questionTypeForStorage(?string $raw): string {
    switch (normalizeQuestionType($raw)) {
        case 'TF':   return 'TRUE_FALSE';
        case 'ID':   return 'IDENTIFICATION';
        case 'ENUM': return 'ENUMERATION';
        case 'MC':
        default:     return 'MULTIPLE_CHOICE';
    }
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

/**
 * Same matching rules as exams/submit.php so the review matches the grade.
 */
function answerRules($raw): array {
    if (is_string($raw)) $raw = json_decode($raw, true);
    if (!is_array($raw)) $raw = [];
    if (!is_array($raw['alternatives'] ?? null)) $raw['alternatives'] = [];
    return $raw;
}

function normalizeTypedAnswer(string $value, ?string $matching, array $rules): string {
    $value = trim($value);
    if (!empty($rules['ignore_punctuation'])) $value = (string) preg_replace('/[\p{P}]/u', '', $value);
    if (!empty($rules['ignore_extra_spaces'])) $value = (string) preg_replace('/\s+/u', ' ', trim($value));
    return $matching === 'IGNORE_CASE' ? mb_strtolower($value, 'UTF-8') : $value;
}

function isAnswerCorrect(string $type, $studentAns, ?string $correct, ?string $matching, $rawRules = null): bool {
    if ($studentAns === null || trim((string)$studentAns) === '') return false;
    $type = normalizeQuestionType($type);
    $rules = answerRules($rawRules);
    if ($type === 'ID') {
        $expected = array_merge([(string)$correct], array_map('strval', $rules['alternatives']));
        $answer = normalizeTypedAnswer((string)$studentAns, $matching, $rules);
        if ($answer === '') return false;
        foreach ($expected as $candidate) {
            if ($answer === normalizeTypedAnswer((string)$candidate, $matching, $rules)) return true;
        }
        return false;
    }
    if ($type === 'ENUM') {
        $expected = array_values(array_filter(array_map('trim', preg_split('/\r?\n|\|/', (string)$correct)), fn($v) => $v !== ''));
        // Android joins answer fields with newlines. Preserve commas inside each field.
        // Legacy questions retain the old comma delimiter until explicitly edited.
        $pattern = !empty($rules['separate_enum_fields']) ? '/\r?\n/' : '/\r?\n|,|\|/';
        $actual = array_values(array_filter(array_map('trim', preg_split($pattern, (string)$studentAns)), fn($v) => $v !== ''));
        if (!$expected || count($actual) !== count($expected)) return false;
        $expected = array_map(fn($v) => normalizeTypedAnswer($v, $matching, $rules), $expected);
        $actual = array_map(fn($v) => normalizeTypedAnswer($v, $matching, $rules), $actual);
        if (empty($rules['require_order'])) { sort($expected); sort($actual); }
        return $expected === $actual;
    }
    return trim((string)$studentAns) === trim((string)$correct);
}

/**
 * Build the per-question review breakdown for a submission's answers JSON.
 */
function buildReviewQuestions(PDO $pdo, int $examId, ?string $answersJson): array {
    $answers = $answersJson ? json_decode($answersJson, true) : [];
    if (!is_array($answers)) $answers = [];

    $qStmt = $pdo->prepare(
        'SELECT question_id, question_text, question_type, options, correct_answer,
                points, answer_matching, answer_rules
         FROM questions WHERE exam_id = :eid ORDER BY order_num ASC'
    );
    $qStmt->execute(['eid' => $examId]);
    $questions = $qStmt->fetchAll();

    $items = [];
    foreach ($questions as $q) {
        $type = normalizeQuestionType($q['question_type'] ?? 'MC');

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
            $studentAnswer = resolveOptionLetter($studentAnswer, $options, $type);
        }

        $isCorrect = null;
        if ($studentAnswer !== null) {
            $isCorrect = isAnswerCorrect($type, $studentAnswer, $q['correct_answer'], $q['answer_matching'], $q['answer_rules'] ?? null);
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
 * Calculate the institution's Base-50 grade from earned and possible points.
 * The raw percentage remains a separate informational value.
 */
function calculateBase50Grade(int|float $earnedPoints, int|float $totalPoints): ?float {
    if ($totalPoints <= 0) {
        return null;
    }
    return round((($earnedPoints / $totalPoints) * 50) + 50, 2);
}

/** Compare a Base-50 grade with the configured exam threshold. */
function passesBase50Grade(?float $base50Grade, ?float $passingScore): ?bool {
    if ($base50Grade === null || $passingScore === null) {
        return null;
    }
    return $base50Grade >= $passingScore;
}

/** Grade a complete question set using the same matching rules as review. */
function gradeExamQuestions(array $questions, array $answers, ?float $passingScore): array {
    $earned = 0;
    $possible = 0;
    $correctCount = 0;
    foreach ($questions as $question) {
        $points = (int) ($question['points'] ?? 1);
        $possible += $points;
        $type = normalizeQuestionType($question['question_type'] ?? 'MC');
        $options = $type === 'TF' ? ['True', 'False']
            : (is_array($question['options'] ?? null) ? $question['options'] : json_decode($question['options'] ?? 'null', true));
        $options = is_array($options) ? array_values($options) : null;
        $answer = $answers[(string) $question['question_id']] ?? null;
        if ($answer !== null) $answer = resolveOptionLetter($answer, $options, $type);
        if (isAnswerCorrect($type, $answer, $question['correct_answer'], $question['answer_matching'] ?? 'EXACT', $question['answer_rules'] ?? null)) {
            $earned += $points;
            $correctCount++;
        }
    }
    $percentage = $possible > 0 ? round($earned / $possible * 100, 2) : 0.0;
    $base50Grade = calculateBase50Grade($earned, $possible);
    return ['score' => $earned, 'earned_points' => $earned, 'total_points' => $possible,
        'correct_count' => $correctCount, 'total_questions' => count($questions),
        'percentage' => $percentage,
        'base50_grade' => $base50Grade,
        'passed' => passesBase50Grade($base50Grade, $passingScore)];
}

/**
 * Load the student's latest auto-saved answers from the versioned
 * exam_answer_revisions table (one row per exam/user/question; each row only
 * ever holds the highest revision thanks to the version-gated upsert in
 * exam_save_answer.php). Returns ['answers' => [qid => answer],
 * 'revisions' => [qid => revision]]. Throws PDOException if the table is
 * missing — callers decide whether to degrade or fail loudly.
 */
function loadStudentRevisionAnswers(PDO $pdo, int $examId, int $userId): array {
    $stmt = $pdo->prepare(
        'SELECT question_id, answer, revision FROM exam_answer_revisions
         WHERE exam_id = :eid AND user_id = :uid'
    );
    $stmt->execute(['eid' => $examId, 'uid' => $userId]);
    $answers = [];
    $revisions = [];
    while ($row = $stmt->fetch()) {
        $qid = (string) $row['question_id'];
        $answers[$qid] = $row['answer'];
        $revisions[$qid] = (int) $row['revision'];
    }
    return ['answers' => $answers, 'revisions' => $revisions];
}
