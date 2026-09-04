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

try {
    if ($class_id) {
        // Single-class report requested — return stats + students for Android
        $stmt = $pdo->prepare("SELECT subject_name FROM classes WHERE class_id=? AND teacher_id=? AND status='ACTIVE'");
        $stmt->execute([$class_id, $teacher_id]);
        $class = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$class) {
            sendError('Class not found.', 'NOT_FOUND', 404);
        }

        $stmt2 = $pdo->prepare("SELECT u.user_id, u.first_name, u.last_name FROM users u JOIN enrollments e ON u.user_id=e.user_id WHERE e.class_id=?");
        $stmt2->execute([$class_id]);
        $students = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        $stmt3 = $pdo->prepare("SELECT exam_id, exam_name, passing_score FROM exams WHERE class_id=?");
        $stmt3->execute([$class_id]);
        $exams = $stmt3->fetchAll(PDO::FETCH_ASSOC);

        $pass_count = 0;
        $fail_count = 0;
        $total_pct_sum = 0;
        $total_pct_count = 0;

foreach ($students as &$student) {
            $stmt4 = $pdo->prepare("SELECT es.score, es.correct_count, es.total_questions FROM exam_submissions es WHERE es.user_id=? AND es.exam_id IN (SELECT exam_id FROM exams WHERE class_id=?)");
            $stmt4->execute([$student['user_id'], $class_id]);
            $scores = $stmt4->fetchAll(PDO::FETCH_ASSOC);

            $student['exams_taken'] = count($scores);
            $student_avg = 0;
            if (!empty($scores)) {
                $pct_sum = 0;
                foreach ($scores as $s) {
                    $correct = (int) ($s['correct_count'] ?? 0);
                    $total   = (int) ($s['total_questions'] ?? 0);
                    $pct = $total > 0 ? ($correct / $total) * 100 : 0;
                    $pct_sum += $pct;
                }
                $student_avg = $pct_sum / count($scores);
                $total_pct_sum += $student_avg;
                $total_pct_count++;
            }
            $student['avg_score'] = round($student_avg, 2);
        }
        unset($student);

// Calculate pass/fail from exam_submissions directly (percentage >= passing_score)
        $stmt5 = $pdo->prepare(
            "SELECT COUNT(*) AS pass_count FROM exam_submissions es
             JOIN exams e ON es.exam_id = e.exam_id
             WHERE e.class_id=? AND es.total_questions > 0
               AND (es.correct_count / es.total_questions) * 100 >= e.passing_score"
        );
        $stmt5->execute([$class_id]);
        $pass_count = (int)$stmt5->fetchColumn();

        $stmt6 = $pdo->prepare(
            "SELECT COUNT(*) AS fail_count FROM exam_submissions es
             JOIN exams e ON es.exam_id = e.exam_id
             WHERE e.class_id=? AND (es.total_questions = 0
               OR (es.correct_count / es.total_questions) * 100 < e.passing_score)"
        );
        $stmt6->execute([$class_id]);
        $fail_count = (int)$stmt6->fetchColumn();

        $class_avg = $total_pct_count > 0 ? round($total_pct_sum / $total_pct_count, 2) : 0;

        $stats = [
            'avg_score' => $class_avg,
            'pass_count' => $pass_count,
            'fail_count' => $fail_count
        ];

        sendSuccess(['stats' => $stats, 'students' => $students]);
    } else {
        // No id — return all classes list (original behavior)
        $stmt = $pdo->prepare("SELECT c.class_id, c.subject_name, c.block, (SELECT COUNT(*) FROM enrollments WHERE class_id=c.class_id) AS student_count FROM classes c WHERE c.teacher_id=? AND c.status='ACTIVE'");
        $stmt->execute([$teacher_id]);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];

        foreach ($classes as $class) {
            $stmt2 = $pdo->prepare("SELECT u.user_id, u.first_name, u.last_name FROM users u JOIN enrollments e ON u.user_id=e.user_id WHERE e.class_id=?");
            $stmt2->execute([$class['class_id']]);
            $students = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            $stmt3 = $pdo->prepare("SELECT e.exam_id, e.exam_name, e.passing_score FROM exams e WHERE e.class_id=?");
            $stmt3->execute([$class['class_id']]);
            $exams = $stmt3->fetchAll(PDO::FETCH_ASSOC);

$class_avg = 0;
            $pass_count = 0;
            $fail_count = 0;
            $total_pct_sum = 0;
            $total_pct_count = 0;

            foreach ($students as &$student) {
                $stmt4 = $pdo->prepare("SELECT es.exam_id, es.correct_count, es.total_questions FROM exam_submissions es WHERE es.user_id=? AND es.exam_id IN (SELECT exam_id FROM exams WHERE class_id=?)");
                $stmt4->execute([$student['user_id'], $class['class_id']]);
                $scores = $stmt4->fetchAll(PDO::FETCH_ASSOC);

                $student['exams_taken'] = count($scores);
                $student_avg = 0;
                if (!empty($scores)) {
                    $pct_sum = 0;
                    foreach ($scores as &$s) {
                        $correct = (int) ($s['correct_count'] ?? 0);
                        $total   = (int) ($s['total_questions'] ?? 0);
                        $pct = $total > 0 ? ($correct / $total) * 100 : 0;
                        $pct_sum += $pct;
                        $total_pct_sum += $pct;
                        $total_pct_count++;
                        $s['percentage'] = round($pct, 2);
                        $s['score'] = $correct;
                    }
                    unset($s);
                    $student_avg = $pct_sum / count($scores);
                }
                $student['avg_score'] = round($student_avg, 2);
                $student['scores'] = $scores;

                foreach ($exams as $exam) {
                    foreach ($scores as $s) {
                        if ($s['exam_id'] == $exam['exam_id']) {
                            if ($s['percentage'] >= $exam['passing_score']) {
                                $pass_count++;
                            } else {
                                $fail_count++;
                            }
                        }
                    }
                }
            }
            unset($student);

            $class_avg = $total_pct_count > 0 ? round($total_pct_sum / $total_pct_count, 2) : 0;

            $result[] = [
                'class_id' => $class['class_id'],
                'subject_name' => $class['subject_name'],
                'student_count' => $class['student_count'],
                'avg_score' => $class_avg,
                'pass_count' => $pass_count,
                'fail_count' => $fail_count,
                'students' => $students
            ];
        }

        sendSuccess(['classes' => $result]);
    }
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
