<?php
require_once __DIR__ . '/exam_grading.php';

/** Canonicalize builder payloads; incomplete work is allowed only in drafts. */
function prepareBuilderQuestions(array $questions, bool $publishing): array {
    if ($publishing && !$questions) throw new InvalidArgumentException('Add at least one question before publishing.');
    foreach ($questions as $i => &$q) {
        if (!is_array($q)) throw new InvalidArgumentException('Invalid question.');
        $type = normalizeQuestionType($q['question_type'] ?? '');
        if (!in_array($type, ['MC','TF','ID','ENUM'], true)) throw new InvalidArgumentException('Choose a supported question type.');
        $q['question_type'] = $type;
        $q['answer_matching'] = $q['answer_matching'] ?? 'EXACT';
        if (!in_array($q['answer_matching'], ['EXACT','IGNORE_CASE'], true)) throw new InvalidArgumentException('Invalid capitalization setting.');
        $rules = answerRules($q['answer_rules'] ?? null);
        $allowed = ['ignore_extra_spaces','ignore_punctuation','require_order','separate_enum_fields'];
        $clean = [];
        foreach ($allowed as $key) if (array_key_exists($key,$rules)) $clean[$key] = (bool)$rules[$key];
        $clean['review_required'] = (bool)($q['needs_review'] ?? $rules['review_required'] ?? false);
        $clean['review_reason'] = (string)($q['review_reason'] ?? $rules['review_reason'] ?? 'Verify the imported question.');
        $clean['alternatives'] = [];
        $alternatives = $rules['alternatives'] ?? [];
        if (!is_array($alternatives)) throw new InvalidArgumentException('Accepted answers must be a list.');
        if ($type === 'ID') foreach ($alternatives as $a) {
            if (!is_string($a)) throw new InvalidArgumentException('Accepted answers must be text.');
            if (trim($a) !== '') $clean['alternatives'][] = trim($a);
        }
        $q['answer_rules'] = json_encode($clean, JSON_UNESCAPED_UNICODE);
        $q['options'] = is_array($q['options'] ?? null) ? array_values($q['options']) : [];
        if ($type === 'ENUM') $q['correct_answer'] = implode("\n", array_map('trim', $q['options']));
        $error = '';
        if (trim($q['question_text'] ?? '') === '') $error = 'Question text is required.';
        elseif (($q['points'] ?? 1) < 1) $error = 'Points must be positive.';
        elseif (!empty($clean['review_required'])) $error = 'Confirm the imported question has been reviewed.';
        elseif ($type === 'MC' && (count($q['options']) < 2 || in_array('',array_map('trim',$q['options']),true) || count(array_unique($q['options'])) !== count($q['options']) || !in_array($q['correct_answer'] ?? '',$q['options'],true))) $error = 'Fill unique choices and select a valid correct answer.';
        elseif ($type === 'TF' && !in_array($q['correct_answer'] ?? '',['True','False'],true)) $error = 'Select True or False.';
        elseif ($type === 'ID' && trim($q['correct_answer'] ?? '') === '') $error = 'Correct answer is required.';
        elseif ($type === 'ENUM' && (!$q['options'] || in_array('',array_map('trim',$q['options']),true))) $error = 'Fill every required enumeration answer.';
        if ($publishing && $error !== '') throw new InvalidArgumentException('Q'.($i+1).': '.$error);
    }
    unset($q);
    return $questions;
}
