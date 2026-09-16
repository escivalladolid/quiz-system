<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/validation.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo = getDbConnection();
$admin = requireRole($pdo, ['ADMIN']);

$input = getJsonInput();
requireFields($input, ['rows']);

$rows = $input['rows'];
if (!is_array($rows)) {
    sendError('rows must be an array.', 'INVALID_INPUT', 422);
}
if (count($rows) > 5000) {
    sendError('Too many rows (max 5000 per import).', 'TOO_MANY_ROWS', 422);
}

$added = 0;
$updated = 0;
$skipped = 0;
$errors = [];

try {
    $sel = $pdo->prepare('SELECT id FROM teacher_roster WHERE employee_number = ?');
    $ins = $pdo->prepare(
        'INSERT INTO teacher_roster (employee_number, full_name, department, email) VALUES (?, ?, ?, ?)'
    );
    $upd = $pdo->prepare(
        'UPDATE teacher_roster SET full_name = ?, department = ?, email = COALESCE(?, email), imported_at = NOW() WHERE employee_number = ?'
    );

    foreach ($rows as $i => $row) {
        $emp  = trim((string) ($row['employee_number'] ?? ''));
        $full = trim((string) ($row['full_name'] ?? ''));
        $dept = isset($row['department']) ? trim((string) $row['department']) : '';
        if ($dept === '') $dept = null;
        $email = isset($row['email']) ? trim((string) $row['email']) : '';
        if ($email !== '' && ($emailError = validateEmail($email)) !== null) {
            $skipped++;
            $errors[] = 'Row ' . ($i + 1) . ': ' . $emailError;
            continue;
        }
        $emailValue = $email !== '' ? $email : null;

        if ($emp === '' || $full === '') {
            $skipped++;
            $errors[] = 'Row ' . ($i + 1) . ': missing employee_number or full_name';
            continue;
        }

        try {
            $sel->execute([$emp]);
            if ($sel->fetch()) {
                $upd->execute([$full, $dept, $emailValue, $emp]);
                $updated++;
            } else {
                $ins->execute([$emp, $full, $dept, $emailValue]);
                $added++;
            }
        } catch (PDOException $e) {
            $skipped++;
            $errors[] = 'Row ' . ($i + 1) . ' (EMP ' . $emp . '): ' . $e->getMessage();
        }
    }

    $log = $pdo->prepare('INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)');
    $log->execute([$admin['user_id'], 'ROSTER_IMPORT', "Imported teacher roster: $added added, $updated updated, $skipped skipped"]);

    sendSuccess(['added' => $added, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors]);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}
