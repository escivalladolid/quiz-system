<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/exam_attempts.php';

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
    $stmt = $pdo->prepare("SELECT e.exam_id, e.exam_name, COALESCE(qtp.tp,0) AS total_points, e.passing_score FROM exams e LEFT JOIN (SELECT exam_id, COALESCE(SUM(points),0) AS tp FROM questions GROUP BY exam_id) qtp ON qtp.exam_id=e.exam_id WHERE e.class_id=? ORDER BY e.exam_name");
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
    $stmt = $pdo->prepare("SELECT es.user_id, es.exam_id, es.score, es.correct_count, es.total_questions, es.answers_json, es.time_used_secs, es.exit_attempts, es.auto_submitted, es.submitted_at FROM exam_submissions es WHERE es.exam_id IN ($placeholders)");
    $stmt->execute($target_ids);
    $all_submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Start times live on the persisted attempt, while finish times live on
    // the submission.  Keep this lookup optional so older installations that
    // have not applied the attempt migration can still load reports.
    $attempt_start_map = [];
    try {
        $attemptStmt = $pdo->prepare(
            "SELECT exam_id, user_id, started_at
             FROM exam_attempts
             WHERE exam_id IN ($placeholders)"
        );
        $attemptStmt->execute($target_ids);
        while ($attempt = $attemptStmt->fetch(PDO::FETCH_ASSOC)) {
            $attempt_start_map[$attempt['exam_id'] . ':' . $attempt['user_id']] = $attempt['started_at'];
        }
    } catch (PDOException $ignored) {
        // The start time will be null when the legacy database has no attempts table.
    }

    // Proctoring telemetry is optional. Report exports still work when an
    // older database has not installed the monitoring migration.
    $activity_flag_map = [];
    try {
        $activityAttemptScope = examActivityAttemptColumnAvailable($pdo)
            ? " AND a.attempt_id = (SELECT MAX(ea.attempt_id) FROM exam_attempts ea
                                    WHERE ea.exam_id = a.exam_id AND ea.user_id = a.user_id)"
            : ' AND 1 = 0';
        $activityStmt = $pdo->prepare(
            "SELECT exam_id, user_id, COUNT(*) AS flag_count
             FROM exam_activity_log a
             WHERE exam_id IN ($placeholders)
               AND event_type IN ('TAB_SWITCH','MULTI_WINDOW','SCREENSHOT','SCREEN_RECORDING','CLOSED')
               $activityAttemptScope
             GROUP BY exam_id, user_id"
        );
        $activityStmt->execute($target_ids);
        while ($activity = $activityStmt->fetch(PDO::FETCH_ASSOC)) {
            $activity_flag_map[$activity['exam_id'] . ':' . $activity['user_id']] = (int) $activity['flag_count'];
        }
    } catch (PDOException $ignored) {
        // Fall back to the exit_attempts value stored with each submission.
    }

    // Group submissions by user
    $user_subs = [];
    foreach ($all_submissions as $sub) {
        $user_subs[$sub['user_id']][] = $sub;
    }

    // Build exam lookup maps
    $tpStmt = $pdo->prepare("SELECT exam_id, COALESCE(SUM(points),0) AS tp FROM questions WHERE exam_id IN ($placeholders) GROUP BY exam_id");
    $tpStmt->execute($target_ids);
    $exam_max_map = [];
    while ($tpRow = $tpStmt->fetch(PDO::FETCH_ASSOC)) {
        $exam_max_map[$tpRow['exam_id']] = (int)$tpRow['tp'];
    }
    $passing_map = [];
    foreach ($all_exams as $ex) {
        if (!isset($exam_max_map[$ex['exam_id']])) $exam_max_map[$ex['exam_id']] = 0;
        $passing_map[$ex['exam_id']] = $ex['passing_score'] !== null
            ? (float) $ex['passing_score'] : 0.0;
    }

// All individual percentages for distribution & summary
    $all_pcts = [];
    foreach ($all_submissions as $sub) {
        $earned = (int) ($sub['score'] ?? 0);
        $tp     = $exam_max_map[$sub['exam_id']] ?? 0;
        $all_pcts[] = $tp > 0 ? round(($earned / $tp) * 100) : 0;
    }
    $total_subs = count($all_pcts);

    // --- Per-student stats ---
    $student_results = [];
    $overall_pct_sum = 0;
    $overall_pct_count = 0;
    $pass_count = 0;
    $fail_count = 0;
    $flagged_student_count = 0;
    $total_flag_count = 0;

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
                'time_used_secs' => null,
                'started_at' => null,
                'submitted_at' => null,
                'flag_count' => 0,
                'auto_submitted' => false,
            ];
            continue;
        }

        // Calculate average percentage across the student's submissions
        $pct_sum = 0;
        $pct_count = 0;
        $total_score = 0;
        $total_points_total = 0;
        $total_time_used = 0;
        $has_time_used = false;
        $student_flag_count = 0;
        $student_auto_submitted = false;
        $all_passed = true;
        $first_started_at = null;
        $last_submitted_at = null;

foreach ($subs as $s) {
            $score   = (int)$s['score'];
            $tp      = $exam_max_map[$s['exam_id']] ?? 0;

            // Percentage is derived from earned points vs sum of question points.
            $pct = round(($tp > 0 ? ($score / $tp) * 100 : 0), 1);
            $pct_sum += $pct;
            $pct_count++;

            // For display: total score across submissions
            $total_score += $score;
            $total_points_total += $tp;

            if ($s['time_used_secs'] !== null && $s['time_used_secs'] !== '') {
                $total_time_used += max(0, (int) $s['time_used_secs']);
                $has_time_used = true;
            }

            $attemptStartedAt = $attempt_start_map[$s['exam_id'] . ':' . $uid] ?? null;
            if ($attemptStartedAt !== null && $attemptStartedAt !== '') {
                if ($first_started_at === null || (string) $attemptStartedAt < (string) $first_started_at) {
                    $first_started_at = $attemptStartedAt;
                }
            }
            $submissionFinishedAt = $s['submitted_at'] ?? null;
            if ($submissionFinishedAt !== null && $submissionFinishedAt !== '') {
                if ($last_submitted_at === null || (string) $submissionFinishedAt > (string) $last_submitted_at) {
                    $last_submitted_at = $submissionFinishedAt;
                }
            }
            $activityFlags = (int) ($activity_flag_map[$s['exam_id'] . ':' . $uid] ?? 0);
            // Avoid double-counting the same exit when both telemetry and the
            // legacy submission counter contain it.
            $student_flag_count += max($activityFlags, (int) ($s['exit_attempts'] ?? 0));
            $student_auto_submitted = $student_auto_submitted || !empty($s['auto_submitted']);

            // Check pass/fail per exam using the Base-50 grade. The raw
            // percentage above remains for informational distributions.
            $passing = $passing_map[$s['exam_id']] ?? 0;
            $base50 = $tp > 0 ? round((($score / $tp) * 50) + 50, 2) : null;
            if ($passing > 0 && ($base50 === null || $base50 < $passing)) {
                $all_passed = false;
            }
        }

        $avg_pct = $pct_count > 0 ? round($pct_sum / $pct_count) : 0;
        $overall_pct_sum += $avg_pct;
        $overall_pct_count++;

        if ($all_passed) $pass_count++;
        else $fail_count++;

        if ($student_flag_count > 0) $flagged_student_count++;
        $total_flag_count += $student_flag_count;

        $student_results[] = [
            'user_id'    => $uid,
            'first_name' => $stu['first_name'],
            'last_name'  => $stu['last_name'],
            'score'      => $total_score,
            'total'      => $total_points_total,
            'percentage' => $avg_pct,
            'passed'     => $all_passed,
            'time_used_secs' => $has_time_used ? $total_time_used : null,
            // With one exam selected these are that student's exact attempt
            // times.  For the all-exams view they represent first start and
            // last finish across the selected exams.
            'started_at' => $first_started_at,
            'submitted_at' => $last_submitted_at,
            'flag_count' => $student_flag_count,
            'auto_submitted' => $student_auto_submitted,
        ];
    }

    // --- Summary ---
    $summary = [
        'avg_score' => !empty($all_pcts) ? round(array_sum($all_pcts) / count($all_pcts), 1) : 0,
        'highest'   => !empty($all_pcts) ? max($all_pcts) : 0,
        'lowest'    => !empty($all_pcts) ? min($all_pcts) : 0,
        'pass_rate' => ($pass_count + $fail_count) > 0 ? round(($pass_count / ($pass_count + $fail_count)) * 100, 1) : 0,
        'flagged_count' => $total_flag_count,
        'flagged_students' => $flagged_student_count,
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
