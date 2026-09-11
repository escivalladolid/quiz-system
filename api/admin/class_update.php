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

$subject_code = strtoupper(trim($input['subject_code'] ?? ''));
$subject_name = trim($input['subject_name'] ?? '');
$block        = trim($input['block'] ?? '');
$class_code   = strtoupper(trim($input['class_code'] ?? ''));
$teacher_id   = (int) ($input['teacher_id'] ?? 0);
$status       = strtoupper(trim($input['status'] ?? ''));

try {
    $stmt = $pdo->prepare('SELECT class_id, class_code FROM classes WHERE class_id = ?');
    $stmt->execute([$class_id]);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$class) {
        sendError('Class not found.', 'NOT_FOUND', 404);
    }

    if ($subject_code === '' || $subject_name === '' || $block === '' || $class_code === '') {
        sendError('Subject code, subject name, block and class code are required.', 'MISSING_FIELDS', 422);
    }
    if (!preg_match('/^[A-Z0-9._\-]{2,10}$/', $class_code)) {
        sendError('Class code must be 2–10 characters (letters, digits, dot, dash, underscore).', 'INVALID_INPUT', 422);
    }
    if (!preg_match('/^[A-Z0-9 .\-]{1,20}$/', $subject_code)) {
        sendError('Subject code must be 1–20 characters.', 'INVALID_INPUT', 422);
    }
    if (!in_array($status, ['ACTIVE', 'ARCHIVED'], true)) {
        sendError('Invalid status.', 'INVALID_STATUS', 422);
    }
    if ($teacher_id <= 0) {
        sendError('A teacher must be assigned.', 'INVALID_TEACHER', 422);
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE user_id = ? AND role_id = 2');
    $stmt->execute([$teacher_id]);
    if ((int) $stmt->fetchColumn() === 0) {
        sendError('The chosen teacher does not exist.', 'INVALID_TEACHER', 422);
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM classes WHERE class_code = ? AND class_id <> ?');
    $stmt->execute([$class_code, $class_id]);
    if ((int) $stmt->fetchColumn() > 0) {
        sendError('That class code is already in use.', 'DUPLICATE_CLASS_CODE', 409);
    }

    $stmt = $pdo->prepare(
        'UPDATE classes SET subject_code = ?, subject_name = ?, block = ?, class_code = ?, teacher_id = ?, status = ?
         WHERE class_id = ?'
    );
    $stmt->execute([$subject_code, $subject_name, $block, $class_code, $teacher_id, $status, $class_id]);

    $log = $pdo->prepare('INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)');
    $log->execute([$admin['user_id'], 'CLASS_UPDATE', "Updated class $subject_code ($class_code)"]);

    sendSuccess(['class_id' => $class_id]);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}