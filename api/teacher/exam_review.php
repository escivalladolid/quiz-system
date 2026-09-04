<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/exam_status.php';
require_once __DIR__ . '/../../helpers/exam_grading.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('This endpoint only accepts GET requests.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo       = getDbConnection();
$teacher   = requireRole($pdo, ['TEACHER']);
$teacherId = $teacher['user_id'];
$examId    = isset($_GET['exam_id']) ? (int) $_GET['exam_id'] : 0;

if (!$examId) {
    sendError('Missing exam id parameter.', 'BAD_REQUEST', 400);
}

try {
    syncExamStatuses($pdo);

    // Verify the teacher owns this exam's class.
    $examStmt = $pdo->prepare(
        'SELECT e.exam_id, e.exam_name, e.total_points AS max_points, e.passing_score,
                c.subject_name, c.class_code, c.block
         FROM exams e
         JOIN classes c ON c.class_id = e.class_id
         WHERE e.exam_id = ? AND c.teacher_id = ?'
    );
    $examStmt->execute([$examId, $teacherId]);
    $exam = $examStmt->fetch();

    if (!$exam) {
        sendError('Exam not found.', 'NOT_FOUND', 404);
    }

    // Per-student review: ?exam_id=X&student_id=Y
    $studentId = isset($_GET['student_id']) ? (int) $_GET['student_id'] : 0;
    if ($studentId > 0) {
        $subStmt = $pdo->prepare(
            'SELECT s.submission_id, s.user_id, s.score, s.correct_count, s.total_questions,
                    s.time_used_secs, s.submitted_at, s.answers_json,
                    u.first_name, u.last_name
             FROM exam_submissions s
             JOIN users u ON u.user_id = s.user_id
             WHERE s.exam_id = :eid AND s.user_id = :uid'
        );
        $subStmt->execute(['eid' => $examId, 'uid' => $studentId]);
        $submission = $subStmt->fetch();

        if (!$submission) {
            sendError('No submission found for this student.', 'NOT_FOUND', 404);
        }

        // Tab-switch log timestamps for this student (most recent first).
        $logStmt = $pdo->prepare(
            'SELECT created_at FROM exam_proctoring_log
             WHERE exam_id = :eid AND user_id = :uid
             ORDER BY created_at DESC'
        );
        $logStmt->execute(['eid' => $examId, 'uid' => $studentId]);
        $tabSwitchLog = array_map(fn($r) => $r['created_at'], $logStmt->fetchAll());

        sendSuccess([
            'exam' => [
                'exam_id'   => (int) $exam['exam_id'],
                'exam_name' => $exam['exam_name'],
                'subject_name' => $exam['subject_name'],
                'block'     => $exam['block'],
                'max_points'    => (int) $exam['max_points'],
                'passing_score' => $exam['passing_score'] !== null ? (int) $exam['passing_score'] : null,
            ],
            'student' => [
                'submission_id'   => (int) $submission['submission_id'],
                'student_id'      => (int) $submission['user_id'],
                'student_name'    => trim($submission['first_name'] . ' ' . $submission['last_name']),
                'score'           => (int) $submission['score'],
                'correct_count'   => (int) $submission['correct_count'],
                'total_questions' => (int) $submission['total_questions'],
                'time_used_secs'  => $submission['time_used_secs'] !== null ? (int) $submission['time_used_secs'] : null,
                'submitted_at'    => $submission['submitted_at'],
                'tab_switch_count' => count($tabSwitchLog),
            ],
            'tab_switch_log' => $tabSwitchLog,
            'questions' => buildReviewQuestions($pdo, $examId, $submission['answers_json']),
        ]);
    }

    // Submission list
    $listStmt = $pdo->prepare(
        'SELECT s.submission_id, s.user_id, s.score, s.correct_count, s.total_questions,
                s.time_used_secs, s.submitted_at,
                u.first_name, u.last_name,
                (SELECT COUNT(*) FROM exam_proctoring_log p
                  WHERE p.exam_id = s.exam_id AND p.user_id = s.user_id) AS tab_switch_count
         FROM exam_submissions s
         JOIN users u ON u.user_id = s.user_id
         WHERE s.exam_id = :eid
         ORDER BY s.score DESC, s.submitted_at ASC'
    );
    $listStmt->execute(['eid' => $examId]);
    $submissions = $listStmt->fetchAll();

    $payload = [];
    foreach ($submissions as $s) {
        $payload[] = [
            'submission_id'   => (int) $s['submission_id'],
            'student_id'      => (int) $s['user_id'],
            'student_name'    => trim($s['first_name'] . ' ' . $s['last_name']),
            'score'           => (int) $s['score'],
            'correct_count'   => (int) $s['correct_count'],
            'total_questions' => (int) $s['total_questions'],
            'time_used_secs'  => $s['time_used_secs'] !== null ? (int) $s['time_used_secs'] : null,
            'submitted_at'    => $s['submitted_at'],
            'tab_switch_count' => (int) $s['tab_switch_count'],
        ];
    }

    sendSuccess([
        'exam' => [
            'exam_id'       => (int) $exam['exam_id'],
            'exam_name'     => $exam['exam_name'],
            'subject_name'  => $exam['subject_name'],
            'block'         => $exam['block'],
            'max_points'    => (int) $exam['max_points'],
            'passing_score' => $exam['passing_score'] !== null ? (int) $exam['passing_score'] : null,
        ],
        'submissions' => $payload,
    ]);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
