<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo = getDbConnection();
$teacher = requireRole($pdo, ['TEACHER']);
$teacher_id = $teacher['user_id'];

$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$exam_id  = isset($_GET['exam_id'])  ? (int)$_GET['exam_id']  : 0;

try {
    // Always return classes for filter dropdown
    $stmt = $pdo->prepare("SELECT class_id, subject_name, block FROM classes WHERE teacher_id=? AND status='ACTIVE' ORDER BY subject_name");
    $stmt->execute([$teacher_id]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$class_id) {
        sendSuccess(['classes' => $classes, 'exams' => [], 'summary' => null, 'pass_fail' => null, 'distribution' => [], 'question_analysis' => [], 'students' => []]);
    }

    // Verify ownership
    $stmt = $pdo->prepare("SELECT 1 FROM classes WHERE class_id=? AND teacher_id=?");
    $stmt->execute([$class_id, $teacher_id]);
    if (!$stmt->fetch()) sendError('Class not found.', 'NOT_FOUND', 404);

    // Exams for this class
    $stmt = $pdo->prepare("SELECT exam_id, exam_name, total_points, passing_score FROM exams WHERE class_id=? ORDER BY exam_name");
    $stmt->execute([$class_id]);
    $all_exams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // When a specific exam_id is supplied, it MUST belong to the verified
    // class above (class ownership was already checked). Otherwise a teacher
    // could read another teacher's exam submissions/questions by passing
    // class_id=<own>&exam_id=<victim>  (IDOR). Reject any exam_id that is
    // not among this class's own exams.
    if ($exam_id) {
        $valid_exam_for_class = false;
        foreach ($all_exams as $ex) {
            if ((int)$ex['exam_id'] === $exam_id) {
                $valid_exam_for_class = true;
                break;
            }
        }
        if (!$valid_exam_for_class) {
            sendError('Exam not found for this class.', 'NOT_FOUND', 404);
        }
    }

    // Determine which exam ids to include
    $target_ids = $exam_id ? [$exam_id] : array_column($all_exams, 'exam_id');
    if (empty($target_ids)) {
        sendSuccess(['classes' => $classes, 'exams' => $all_exams, 'summary' => null, 'pass_fail' => null, 'distribution' => [], 'question_analysis' => [], 'students' => []]);
    }

    $placeholders = implode(',', array_fill(0, count($target_ids), '?'));

    // Enrolled students
    $stmt = $pdo->prepare("SELECT u.user_id, u.first_name, u.last_name FROM users u JOIN enrollments e ON u.user_id=e.user_id WHERE e.class_id=? ORDER BY u.last_name, u.first_name");
    $stmt->execute([$class_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // All submissions for target exams
    $stmt = $pdo->prepare("SELECT es.user_id, es.exam_id, es.score, es.correct_count, es.total_questions, es.answers_json FROM exam_submissions es WHERE es.exam_id IN ($placeholders)");
    $stmt->execute($target_ids);
    $all_submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group submissions by user
    $user_subs = [];
    foreach ($all_submissions as $sub) {
        $user_subs[$sub['user_id']][] = $sub;
    }

    // Build exam lookup maps
    $exam_max_map = [];
    $passing_map = [];
    foreach ($all_exams as $ex) {
        $exam_max_map[$ex['exam_id']] = (int)($ex['total_points'] ?? 100);
        $passing_map[$ex['exam_id']] = (int)($ex['passing_score'] ?? 0);
    }

// All individual percentages for distribution & summary
    $all_pcts = [];
    foreach ($all_submissions as $sub) {
        $correct = (int) ($sub['correct_count'] ?? 0);
        $total   = (int) ($sub['total_questions'] ?? 0);
        $all_pcts[] = $total > 0 ? round(($correct / $total) * 100) : 0;
    }
    $total_subs = count($all_pcts);

    // --- Per-student stats ---
    $student_results = [];
    $overall_pct_sum = 0;
    $overall_pct_count = 0;
    $pass_count = 0;
    $fail_count = 0;

    foreach ($students as $stu) {
        $uid = $stu['user_id'];
        $subs = $user_subs[$uid] ?? [];

        if (empty($subs)) {
            $student_results[] = [
                'user_id'    => $uid,
                'first_name' => $stu['first_name'],
                'last_name'  => $stu['last_name'],
                'score'      => 0,
                'total'      => 0,
                'percentage' => 0,
                'passed'     => false,
            ];
            continue;
        }

        // Calculate average percentage across the student's submissions
        $pct_sum = 0;
        $pct_count = 0;
        $total_score = 0;
        $total_questions = 0;
        $all_passed = true;

foreach ($subs as $s) {
            $score   = (int)$s['correct_count'];
            $tq      = (int)$s['total_questions'];

            // Percentage is derived from correct answers vs total questions.
            $pct = round(($tq > 0 ? ($score / $tq) * 100 : 0), 1);
            $pct_sum += $pct;
            $pct_count++;

            // For display: total score across submissions
            $total_score += $score;
            $total_questions += $tq;

            // Check pass/fail per exam (percentage >= passing_score)
            $passing = $passing_map[$s['exam_id']] ?? 0;
            if ($passing > 0 && $pct < $passing) {
                $all_passed = false;
            }
        }

        $avg_pct = $pct_count > 0 ? round($pct_sum / $pct_count) : 0;
        $overall_pct_sum += $avg_pct;
        $overall_pct_count++;

        if ($all_passed) $pass_count++;
        else $fail_count++;

        $student_results[] = [
            'user_id'    => $uid,
            'first_name' => $stu['first_name'],
            'last_name'  => $stu['last_name'],
            'score'      => $total_score,
            'total'      => $total_questions,
            'percentage' => $avg_pct,
            'passed'     => $all_passed,
        ];
    }

    // --- Summary ---
    $summary = [
        'avg_score' => !empty($all_pcts) ? round(array_sum($all_pcts) / count($all_pcts), 1) : 0,
        'highest'   => !empty($all_pcts) ? max($all_pcts) : 0,
        'lowest'    => !empty($all_pcts) ? min($all_pcts) : 0,
        'pass_rate' => ($pass_count + $fail_count) > 0 ? round(($pass_count / ($pass_count + $fail_count)) * 100, 1) : 0,
    ];

    // --- Pass vs Fail ---
    $pass_fail = ['passed' => $pass_count, 'failed' => $fail_count];

    // --- Score Distribution (by percentage) ---
    $dist = ['90-100' => 0, '80-89' => 0, '70-79' => 0, '60-69' => 0, 'Below 60' => 0];
    foreach ($all_pcts as $pct) {
        if ($pct >= 90) $dist['90-100']++;
        elseif ($pct >= 80) $dist['80-89']++;
        elseif ($pct >= 70) $dist['70-79']++;
        elseif ($pct >= 60) $dist['60-69']++;
        else $dist['Below 60']++;
    }
    $distribution = [];
    foreach ($dist as $range => $count) {
        $distribution[] = ['range' => $range, 'count' => $count];
    }

    // --- Question Analysis (only when a specific exam is selected) ---
    $question_analysis = [];
    if ($exam_id) {
        $stmt = $pdo->prepare("SELECT question_id, question_type, options, correct_answer, order_num FROM questions WHERE exam_id=? ORDER BY order_num ASC");
        $stmt->execute([$exam_id]);
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($questions) && !empty($all_submissions)) {
            // Pre-decode options for each question
            $q_opts = [];
            foreach ($questions as $q) {
                $qid = $q['question_id'];
                $opts = $q['options'] ? json_decode($q['options'], true) : null;
                $correct = trim($q['correct_answer'] ?? '');

                // Build a lookup: correct_answer -> option index/key
                $correct_key = null;
                if (is_array($opts)) {
                    foreach ($opts as $k => $v) {
                        if (trim((string)$v) === $correct || trim((string)$k) === $correct) {
                            $correct_key = is_string($k) ? $k : $v;
                            break;
                        }
                    }
                }
                if ($correct_key === null) {
                    $correct_key = $correct;
                }

                $q_opts[$qid] = [
                    'order'        => $q['order_num'],
                    'correct_key'  => $correct_key,
                    'correct_text' => $correct,
                    'opts'         => $opts,
                    'type'         => strtoupper($q['question_type'] ?? 'MC'),
                ];
            }

            foreach ($q_opts as $qid => $info) {
                $correct = 0;
                foreach ($all_submissions as $sub) {
                    $answers = json_decode($sub['answers_json'], true);
                    if (!is_array($answers)) continue;
                    $ans = isset($answers[$qid]) ? trim((string)$answers[$qid]) : null;
                    if ($ans === null || $ans === '') continue;

                    $is_correct = false;

                    // Try exact match with correct_key first
                    if ($ans === $info['correct_key']) {
                        $is_correct = true;
                    }
                    // Try exact match with correct_text
                    elseif ($ans === $info['correct_text']) {
                        $is_correct = true;
                    }
                    // If options is an array, check if ans matches the value at correct_key
                    elseif (is_array($info['opts'])) {
                        if (isset($info['opts'][$ans]) && trim((string)$info['opts'][$ans]) === $info['correct_text']) {
                            $is_correct = true;
                        }
                    }

                    if ($is_correct) $correct++;
                }
                $total = count($all_submissions);
                $question_analysis[] = [
                    'question_number'    => $info['order'],
                    'correct_percentage' => $total > 0 ? round(($correct / $total) * 100, 1) : 0,
                ];
            }
        }
    }

    sendSuccess([
        'classes'           => $classes,
        'exams'             => $all_exams,
        'summary'           => $summary,
        'pass_fail'         => $pass_fail,
        'distribution'      => $distribution,
        'question_analysis' => $question_analysis,
        'students'          => $student_results,
    ]);

} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
