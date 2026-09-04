<?php
/**
 * Shared answer-grading + review-building helpers.
 *
 * The matching rules here MUST stay in sync with exams/submit.php so that
 * review breakdowns always agree with the stored score.
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

/**
 * Build the per-question review breakdown for a submission's answers JSON.
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
