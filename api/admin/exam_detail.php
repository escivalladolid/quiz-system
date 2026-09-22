<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/archive.php';
require_once __DIR__ . '/../../helpers/exam_grading.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo = getDbConnection();
$admin = requireRole($pdo, ['ADMIN']);

$exam_id = (int) ($_GET['exam_id'] ?? 0);
if ($exam_id <= 0) {
    sendError('Missing exam_id parameter.', 'BAD_REQUEST', 400);
}

try {
    $stmt = $pdo->prepare(
        "SELECT e.*, c.subject_name, c.subject_code, c.block,
                c.status AS class_status, c.is_archived AS class_is_archived, c.archived_at AS class_archived_at,
                (SELECT COUNT(*) FROM questions q WHERE q.exam_id = e.exam_id) AS question_count,
                (SELECT COALESCE(SUM(q.points), 0) FROM questions q WHERE q.exam_id = e.exam_id) AS points_count
         FROM exams e JOIN classes c ON c.class_id = e.class_id
         WHERE e.exam_id = ?"
    );
    $stmt->execute([$exam_id]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$exam) {
        sendError('Exam not found.', 'NOT_FOUND', 404);
    }
    if ((int) ($exam['class_is_archived'] ?? 0) === 1
        || strtoupper((string) ($exam['class_status'] ?? '')) === 'ARCHIVED') {
        $exam['is_archived'] = 1;
        $exam['archived_at'] = $exam['archived_at'] ?? $exam['class_archived_at'] ?? null;
    }
    addEffectiveArchiveFields($exam);

    $questions = $pdo->prepare('SELECT question_id, question_type, question_text, options, points, correct_answer, answer_matching, order_num
                                FROM questions WHERE exam_id = ? ORDER BY order_num, question_id');
    $questions->execute([$exam_id]);
    $questions = $questions->fetchAll(PDO::FETCH_ASSOC);

    $subs = $pdo->prepare(
        "SELECT es.submission_id, u.user_id, u.first_name, u.last_name, u.section,
                es.score, es.correct_count, es.total_questions, es.time_used_secs,
                es.auto_submitted, es.exit_attempts, es.submitted_at,
                ROUND((es.score / NULLIF(qtp.tp, 0)) * 100, 2) AS percentage,
                ROUND(((es.score / NULLIF(qtp.tp, 0)) * 50) + 50, 2) AS base50_grade
         FROM exam_submissions es
         JOIN users u ON u.user_id = es.user_id
         LEFT JOIN (SELECT exam_id, COALESCE(SUM(points), 0) AS tp FROM questions GROUP BY exam_id) qtp
                ON qtp.exam_id = es.exam_id
         WHERE es.exam_id = ?
         ORDER BY es.submitted_at DESC
         LIMIT 300"
    );
    $subs->execute([$exam_id]);
    $submissions = $subs->fetchAll(PDO::FETCH_ASSOC);

    foreach ($submissions as &$s) {
        $s['percentage'] = $s['percentage'] !== '' && $s['percentage'] !== null ? (float) $s['percentage'] : null;
        $s['base50_grade'] = $s['base50_grade'] !== '' && $s['base50_grade'] !== null ? (float) $s['base50_grade'] : null;
        $passingScore = $exam['passing_score'] !== null ? (float) $exam['passing_score'] : null;
        $s['passed'] = passesBase50Grade($s['base50_grade'], $passingScore);
    }
    unset($s);

    sendSuccess([
        'exam' => $exam,
        'questions' => $questions,
        'submissions' => $submissions,
    ]);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
