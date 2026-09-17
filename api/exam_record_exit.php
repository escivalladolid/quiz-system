<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';
require_once __DIR__ . '/../helpers/exam_status.php';
require_once __DIR__ . '/../helpers/exam_grading.php';
require_once __DIR__ . '/../helpers/exam_attempts.php';
require_once __DIR__ . '/../helpers/exam_monitoring.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('This endpoint only accepts POST requests.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo   = getDbConnection();
$user  = requireRole($pdo, ['STUDENT']);
$input = getJsonInput();
requireFields($input, ['exam_id']);

$examId = (int) $input['exam_id'];
$studentId = (int) $user['user_id'];
$requestedAttemptId = examAttemptIdFromInput($input);

try {
    syncExamStatuses($pdo);

    $examStmt = $pdo->prepare(
        'SELECT e.exam_id, e.status, e.max_exit_attempts, c.class_id
         FROM exams e
         JOIN classes c ON c.class_id = e.class_id
         WHERE e.exam_id = :eid'
    );
    $examStmt->execute(['eid' => $examId]);
    $exam = $examStmt->fetch(PDO::FETCH_ASSOC);

    if (!$exam) {
        sendError('Exam not found.', 'NOT_FOUND', 404);
    }

    if (strtoupper((string) $exam['status']) !== 'LIVE') {
        sendError('This exam is closed.', 'EXAM_CLOSED', 403);
    }

    $maxExitAttempts = filter_var($exam['max_exit_attempts'] ?? null, FILTER_VALIDATE_INT);
    if ($maxExitAttempts === false || $maxExitAttempts < 1 || $maxExitAttempts > 10) {
        error_log('Invalid max_exit_attempts for exam ' . $examId . '; refusing to use a fallback.');
        sendError('This exam has no valid maximum exit-attempt limit configured.', 'SERVER_MISCONFIGURED', 500);
    }

    $enrollCheck = $pdo->prepare('SELECT 1 FROM enrollments WHERE user_id = :uid AND class_id = :cid');
    $enrollCheck->execute(['uid' => $studentId, 'cid' => $exam['class_id']]);
    if (!$enrollCheck->fetch()) {
        sendError('You are not enrolled in this class.', 'NOT_ENROLLED', 403);
    }

    $subCheck = $pdo->prepare('SELECT submission_id FROM exam_submissions WHERE exam_id = :eid AND user_id = :uid');
    $subCheck->execute(['eid' => $examId, 'uid' => $studentId]);
    if ($subCheck->fetch()) {
        sendError('You have already submitted this exam.', 'ALREADY_SUBMITTED', 409);
    }

    $attempt = resolveExamAttempt($pdo, $examId, $studentId, $requestedAttemptId);
    if (!$attempt) {
        sendError(
            $requestedAttemptId !== null ? 'The supplied exam attempt is not valid.' : 'Start the exam before recording an exit.',
            $requestedAttemptId !== null ? 'INVALID_ATTEMPT' : 'ATTEMPT_NOT_STARTED',
            403
        );
    }
    $attemptId = (int) $attempt['attempt_id'];

    // This endpoint cannot safely count or finalize without the attempt key.
    // Fail loudly until migration_attempt_scoped_proctoring.sql is installed.
    if (!examProctoringAttemptColumnAvailable($pdo)) {
        sendError(
            'The attempt-scoped proctoring migration is missing on the server. Ask your administrator to apply migration_attempt_scoped_proctoring.sql.',
            'SERVER_MISCONFIGURED',
            500
        );
    }

    $pdo->beginTransaction();
    $autoSubmission = null;
    $tabSwitchCount = 0;
    try {
        // Serialize the submission slot with a threshold-triggered submit or a
        // normal submit arriving at the same time as this exit event.
        $lockStmt = $pdo->prepare(
            'SELECT submission_id FROM exam_submissions
             WHERE exam_id = :eid AND user_id = :uid FOR UPDATE'
        );
        $lockStmt->execute(['eid' => $examId, 'uid' => $studentId]);
        if ($lockStmt->fetch()) {
            $pdo->rollBack();
            sendError('You have already submitted this exam.', 'ALREADY_SUBMITTED', 409);
        }

        $insert = $pdo->prepare(
            'INSERT INTO exam_proctoring_log
                (exam_id, user_id, attempt_id, event_type, created_at)
             VALUES (:eid, :uid, :aid, :evt, NOW())'
        );
        $insert->execute([
            'eid' => $examId,
            'uid' => $studentId,
            'aid' => $attemptId,
            'evt' => 'TAB_SWITCH',
        ]);

        // Only this attempt contributes to the configured threshold. Legacy
        // rows with a NULL attempt_id are intentionally ignored.
        $tabSwitchCount = countAttemptTabSwitches($pdo, $examId, $studentId, $attemptId);
        $thresholdReached = $tabSwitchCount >= (int) $maxExitAttempts;

        if ($thresholdReached) {
            // The server finalizes the attempt while holding the submission
            // lock. The client may be killed immediately after this request;
            // the authoritative submission row still exists.
            $pdo->query('SELECT 1 FROM exam_answer_revisions LIMIT 1');
            $saved = loadStudentRevisionAnswers($pdo, $examId, $studentId)['answers'];
            $questionsStmt = $pdo->prepare(
                'SELECT question_id, question_type, options, correct_answer,
                        points, answer_matching, answer_rules
                 FROM questions WHERE exam_id = :eid'
            );
            $questionsStmt->execute(['eid' => $examId]);
            $questions = $questionsStmt->fetchAll(PDO::FETCH_ASSOC);
            $grade = gradeExamQuestions($questions, $saved, null);

            $startedAt = strtotime((string) $attempt['started_at']);
            $timeUsedSecs = $startedAt > 0 ? max(0, time() - $startedAt) : null;
            $inserted = true;
            try {
                $pdo->prepare(
                    'INSERT INTO exam_submissions
                        (exam_id, user_id, answers_json, score, correct_count, total_questions,
                         time_used_secs, exit_attempts, auto_submitted)
                     VALUES
                        (:eid, :uid, :answers, :score, :correct, :total, :time, :exit, 1)'
                )->execute([
                    'eid' => $examId,
                    'uid' => $studentId,
                    'answers' => json_encode($saved, JSON_UNESCAPED_UNICODE),
                    'score' => $grade['score'],
                    'correct' => $grade['correct_count'],
                    'total' => $grade['total_questions'],
                    'time' => $timeUsedSecs,
                    'exit' => $tabSwitchCount,
                ]);
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') throw $e;
                $inserted = false;
            }

            $receiptStmt = $pdo->prepare(
                'SELECT submission_id, auto_submitted, exit_attempts
                 FROM exam_submissions WHERE exam_id = :eid AND user_id = :uid'
            );
            $receiptStmt->execute(['eid' => $examId, 'uid' => $studentId]);
            $receipt = $receiptStmt->fetch(PDO::FETCH_ASSOC);
            $autoSubmission = [
                'submission_id' => $receipt ? (int) $receipt['submission_id'] : null,
                'inserted' => $inserted,
                'auto_submitted' => $receipt ? (bool) $receipt['auto_submitted'] : true,
                'exit_attempts' => $receipt ? (int) $receipt['exit_attempts'] : $tabSwitchCount,
            ];
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Keep the rich activity feed in sync after the authoritative transaction.
    // The direct TAB_SWITCH row above is already the legacy fallback, so do not
    // write a second legacy row for the same violation.
    $activityLogFailed = false;
    recordExamActivity($pdo, $examId, $studentId, 'TAB_SWITCH', [
        'attempt_id' => $attemptId,
        'network_state' => 'ONLINE',
    ], $activityLogFailed);

    if ($autoSubmission !== null) {
        $submitActivityFailed = false;
        recordExamActivity($pdo, $examId, $studentId, 'SUBMITTED', [
            'attempt_id' => $attemptId,
            'network_state' => 'ONLINE',
        ], $submitActivityFailed);
        if ($submitActivityFailed || !examActivityLogAvailable($pdo)) {
            recordLegacyExamActivity($pdo, $examId, $studentId, 'SUBMITTED', $attemptId);
        }
    }

    $thresholdReached = $tabSwitchCount >= (int) $maxExitAttempts;
    sendSuccess([
        'attempt_id' => $attemptId,
        'tab_switch_count' => $tabSwitchCount,
        'current_exit_attempts' => $tabSwitchCount,
        'max_exit_attempts' => (int) $maxExitAttempts,
        'threshold_reached' => $thresholdReached,
        'auto_submit_required' => $thresholdReached,
        'server_submitted' => $autoSubmission !== null,
        'submission_id' => $autoSubmission['submission_id'] ?? null,
        'auto_submitted' => $autoSubmission['auto_submitted'] ?? false,
        'remaining_attempts' => max(0, (int) $maxExitAttempts - $tabSwitchCount),
    ]);
} catch (PDOException $e) {
    sendError('Database error: ' . $e->getMessage(), 'DB_ERROR', 500);
} catch (RuntimeException $e) {
    error_log('Exam exit finalization error: ' . $e->getMessage());
    sendError('The server could not finalize this exam attempt. Please contact your instructor.', 'SERVER_MISCONFIGURED', 500);
}
