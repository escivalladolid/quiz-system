<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../helpers/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('This endpoint only accepts GET requests.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo    = getDbConnection();
$user   = requireRole($pdo, ['STUDENT']);

$studentId = $user['user_id'];

// 1. Get enrolled classes
$classStmt = $pdo->prepare(
    'SELECT c.class_id, c.subject_code, c.subject_name
     FROM classes c
     JOIN enrollments e ON e.class_id = c.class_id
     WHERE e.user_id = :uid
     ORDER BY c.subject_code ASC'
);
$classStmt->execute(['uid' => $studentId]);
$classes = $classStmt->fetchAll();

// 2. For each class, build the leaderboard
$leaderboards = [];

foreach ($classes as $class) {
    $cid = $class['class_id'];

    // Get all students in this class with their exam averages
    $lbStmt = $pdo->prepare(
        'SELECT u.user_id, u.first_name, u.last_name,
                AVG(CASE WHEN s.total_questions > 0 THEN (s.correct_count / s.total_questions) * 100 END) AS avg_score,
                COUNT(s.submission_id) AS exams_taken
         FROM enrollments e
         JOIN users u ON u.user_id = e.user_id
         LEFT JOIN exam_submissions s ON s.user_id = u.user_id
            AND s.exam_id IN (SELECT exam_id FROM exams WHERE class_id = :cid)
         WHERE e.class_id = :cid2
         GROUP BY u.user_id, u.first_name, u.last_name
         ORDER BY avg_score DESC, exams_taken DESC'
    );
    $lbStmt->execute(['cid' => $cid, 'cid2' => $cid]);
    $allStudents = $lbStmt->fetchAll();

    // Calculate class average (mean of all students' averages)
    $classAvg = 0;
    $validCount = 0;
    foreach ($allStudents as $s) {
        if ($s['avg_score'] !== null) {
            $classAvg += (float) $s['avg_score'];
            $validCount++;
        }
    }
    $classAvgValue = $validCount > 0 ? round($classAvg / $validCount, 1) : 0;

    // Build ranked list with tied ranks
    $ranked = [];
    $rank = 0;
    $prevScore = null;
    $prevRank = 0;
    foreach ($allStudents as $i => $s) {
        $avg = $s['avg_score'] !== null ? (float) $s['avg_score'] : null;
        if ($avg !== null) {
            $rank++;
            if ($prevScore !== null && abs($avg - $prevScore) < 0.01) {
                $currentRank = $prevRank;
            } else {
                $currentRank = $rank;
            }
            $prevScore = $avg;
            $prevRank = $currentRank;
        } else {
            $currentRank = null;
        }

        $ranked[] = [
            'user_id'      => (int) $s['user_id'],
            'first_name'   => $s['first_name'],
            'last_name'    => $s['last_name'],
            'avg_score'    => $avg !== null ? round($avg, 1) : null,
            'exams_taken'  => (int) $s['exams_taken'],
            'rank'         => $currentRank,
            'is_me'        => $s['user_id'] == $studentId,
        ];
    }

    // Top 5
    $top5 = array_slice($ranked, 0, 5);

    // Find the student's own rank
    $myRank = null;
    $myAvg = null;
    foreach ($ranked as $r) {
        if ($r['is_me']) {
            $myRank = $r['rank'];
            $myAvg = $r['avg_score'];
            break;
        }
    }

    $leaderboards[] = [
        'class_id'     => (int) $cid,
        'subject_code' => $class['subject_code'],
        'subject_name' => $class['subject_name'],
        'total_students' => count($allStudents),
        'class_avg'    => $classAvgValue,
        'my_rank'      => $myRank,
        'my_avg'       => $myAvg,
        'top5'         => $top5,
    ];
}

sendSuccess(['leaderboards' => $leaderboards]);
