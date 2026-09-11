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
requireFields($input, ['subject_code', 'subject_name', 'block', 'class_code', 'teacher_id']);

$subject_code = strtoupper(trim($input['subject_code']));
$subject_name = trim($input['subject_name']);
$block        = trim($input['block']);
$class_code   = strtoupper(trim($input['class_code']));
$teacher_id   = (int) $input['teacher_id'];
$status       = strtoupper(trim($input['status'] ?? 'ACTIVE'));

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

try {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE user_id = ? AND role_id = 2');
    $stmt->execute([$teacher_id]);
    if ((int) $stmt->fetchColumn() === 0) {
        sendError('The chosen teacher does not exist.', 'INVALID_TEACHER', 422);
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM classes WHERE class_code = ?');
    $stmt->execute([$class_code]);
    if ((int) $stmt->fetchColumn() > 0) {
        sendError('That class code is already in use.', 'DUPLICATE_CLASS_CODE', 409);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO classes (subject_code, subject_name, block, class_code, teacher_id, status)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$subject_code, $subject_name, $block, $class_code, $teacher_id, $status]);
    $class_id = (int) $pdo->lastInsertId();

    $log = $pdo->prepare('INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)');
    $log->execute([$admin['user_id'], 'CLASS_CREATE', "Created class $subject_code ($class_code)"]);

    sendSuccess(['class_id' => $class_id], 201);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}