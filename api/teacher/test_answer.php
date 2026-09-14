<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/exam_builder.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendError('Use POST.', 'METHOD_NOT_ALLOWED',405);
requireRole(getDbConnection(), ['TEACHER']);
$input = getJsonInput();
try {
    if (!is_array($input) || !is_array($input['question'] ?? null)) {
        sendError('A question object is required.', 'BAD_REQUEST', 400);
    }
    $input['question']['needs_review'] = false;
    $q = prepareBuilderQuestions([$input['question']], true)[0];
    sendSuccess(['accepted' => isAnswerCorrect($q['question_type'], $input['answer'] ?? '', $q['correct_answer'] ?? '', $q['answer_matching'], $q['answer_rules'])]);
} catch (InvalidArgumentException $e) { sendError($e->getMessage(),'INVALID_QUESTION',422); }
