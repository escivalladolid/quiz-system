<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';

header('Content-Type: application/json');

$pdo = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

$teacher = requireRole($pdo, ['TEACHER']);
$teacher_id = $teacher['user_id'];
$input = getJsonInput();

if (!$input) {
    sendError('Invalid JSON input.', 'BAD_REQUEST', 400);
}

requireFields($input, ['subject_name', 'subject_code', 'block']);

try {
    do {
        $class_code = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $stmt = $pdo->prepare("SELECT class_id FROM classes WHERE class_code=?");
        $stmt->execute([$class_code]);
    } while ($stmt->fetch());

    $stmt = $pdo->prepare("INSERT INTO classes (teacher_id, subject_name, subject_code, class_code, block, status) VALUES (?, ?, ?, ?, ?, 'ACTIVE')");
    $stmt->execute([$teacher_id, $input['subject_name'], $input['subject_code'], $class_code, $input['block']]);

    $class_id = $pdo->lastInsertId();

    sendSuccess([
        'class_id' => $class_id,
        'class_code' => $class_code,
        'subject_name' => $input['subject_name']
    ], 201);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
