<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo = getDbConnection();
$admin = requireRole($pdo, ['ADMIN']);

$input = getJsonInput();
requireFields($input, ['class_id']);

$class_id = (int) $input['class_id'];
$student_ids = [];
if (!empty($input['student_ids']) && is_array($input['student_ids'])) {
    foreach ($input['student_ids'] as $id) {
        $student_ids[] = (int) $id;
    }
}
$student_ids = array_values(array_unique(array_filter($student_ids)));

try {
    $stmt = $pdo->prepare('SELECT class_code FROM classes WHERE class_id = ?');
    $stmt->execute([$class_id]);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$class) {
        sendError('Class not found.', 'NOT_FOUND', 404);
    }

    if (!empty($student_ids)) {
        $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role_id = 1 AND user_id IN ($placeholders)");
        $stmt->execute(array_merge($student_ids, []));
        if ((int) $stmt->fetchColumn() !== count($student_ids)) {
            sendError('One or more selected users are not students.', 'INVALID_STUDENT', 422);
        }
    }

    $pdo->beginTransaction();

    // Full-replace roster: drop everyone not in the new list, then add missing joins.
    if (!empty($student_ids)) {
        $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
        $stmt = $pdo->prepare("DELETE FROM enrollments WHERE class_id = ? AND user_id NOT IN ($placeholders)");
        $stmt->execute(array_merge([$class_id], $student_ids));

        $stmt = $pdo->prepare("SELECT user_id FROM enrollments WHERE class_id = ?");
        $stmt->execute([$class_id]);
        $have = array_map(fn ($r) => (int) $r['user_id'], $stmt->fetchAll(PDO::FETCH_ASSOC));
        $missing = array_values(array_diff($student_ids, $have));
        $ins = $pdo->prepare('INSERT IGNORE INTO enrollments (user_id, class_id) VALUES (?, ?)');
        foreach ($missing as $sid) {
            $ins->execute([$sid, $class_id]);
        }
    } else {
        $stmt = $pdo->prepare('DELETE FROM enrollments WHERE class_id = ?');
        $stmt->execute([$class_id]);
    }

    $pdo->commit();

    $log = $pdo->prepare('INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)');
    $log->execute([$admin['user_id'], 'CLASS_ROSTER', "Updated roster for class {$class['class_code']} (" . count($student_ids) . ' students)']);

    sendSuccess(['class_id' => $class_id, 'enrolled' => count($student_ids)]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}