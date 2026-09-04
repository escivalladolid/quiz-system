<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';

header('Content-Type: application/json');

$pdo = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

$teacher = requireRole($pdo, ['TEACHER']);
$teacher_id = $teacher['user_id'];
$class_id = $_GET['id'] ?? null;

if (!$class_id) {
    sendError('Missing class id parameter.', 'BAD_REQUEST', 400);
}

try {
    $stmt = $pdo->prepare("SELECT c.subject_name FROM classes c WHERE c.class_id=? AND c.teacher_id=?");
    $stmt->execute([$class_id, $teacher_id]);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$class) {
        sendError('Class not found.', 'NOT_FOUND', 404);
    }

    $stmt2 = $pdo->prepare("SELECT e.exam_id, e.exam_name, (SELECT COUNT(*) FROM exam_submissions WHERE exam_id=e.exam_id) AS submission_count, (SELECT AVG(score) FROM exam_submissions WHERE exam_id=e.exam_id) AS avg_score, (SELECT MAX(score) FROM exam_submissions WHERE exam_id=e.exam_id) AS highest, (SELECT MIN(score) FROM exam_submissions WHERE exam_id=e.exam_id) AS lowest FROM exams e WHERE e.class_id=?");
    $stmt2->execute([$class_id]);
    $exams = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    foreach ($exams as &$exam) {
        $exam['avg_score'] = $exam['avg_score'] ? round((float)$exam['avg_score'], 2) : null;
        $exam['highest'] = $exam['highest'] ?? null;
        $exam['lowest'] = $exam['lowest'] ?? null;
    }
    unset($exam);

    $stmt3 = $pdo->prepare("SELECT u.user_id, u.first_name, u.last_name, c.block FROM users u JOIN enrollments en ON u.user_id=en.user_id JOIN classes c ON en.class_id=c.class_id WHERE en.class_id=?");
    $stmt3->execute([$class_id]);
    $students = $stmt3->fetchAll(PDO::FETCH_ASSOC);

    $section_scores = [];
    foreach ($students as $student) {
        $section = $student['block'] ?? 'Unknown';
        if (!isset($section_scores[$section])) {
            $section_scores[$section] = ['total' => 0, 'count' => 0];
        }
        $stmt4 = $pdo->prepare("SELECT AVG(es.score) AS avg FROM exam_submissions es WHERE es.user_id=? AND es.exam_id IN (SELECT exam_id FROM exams WHERE class_id=?)");
        $stmt4->execute([$student['user_id'], $class_id]);
        $student_avg = $stmt4->fetch(PDO::FETCH_ASSOC);

        if ($student_avg && $student_avg['avg'] !== null) {
            $section_scores[$section]['total'] += (float)$student_avg['avg'];
            $section_scores[$section]['count']++;
        }
    }

    $section_comparison = [];
    foreach ($section_scores as $section => $data) {
        $section_comparison[] = [
            'section' => $section,
            'avg_score' => $data['count'] > 0 ? round($data['total'] / $data['count'], 2) : 0,
            'student_count' => $data['count']
        ];
    }

    $weakest_exam = null;
    $weakest_avg = null;
    foreach ($exams as $exam) {
        if ($exam['avg_score'] !== null) {
            if ($weakest_avg === null || $exam['avg_score'] < $weakest_avg) {
                $weakest_avg = $exam['avg_score'];
                $weakest_exam = $exam['exam_name'];
            }
        }
    }

    $insight = [
        'weakest_exam' => $weakest_exam,
        'weakest_avg' => $weakest_avg,
        'message' => $weakest_exam ? "Students struggle most with \"$weakest_exam\" (average: $weakest_avg%)" : 'No submission data available yet'
    ];

    sendSuccess([
        'subject_name' => $class['subject_name'],
        'exams' => $exams,
        'section_comparison' => $section_comparison,
        'insight' => $insight
    ]);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
