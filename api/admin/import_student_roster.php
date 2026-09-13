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
    $sel = $pdo->prepare('SELECT id FROM student_roster WHERE lrn = ?');
    $ins = $pdo->prepare(
        'INSERT INTO student_roster (lrn, full_name, program) VALUES (?, ?, ?)'
    );
    $upd = $pdo->prepare(
        'UPDATE student_roster SET full_name = ?, program = ?, imported_at = NOW() WHERE lrn = ?'
    );

    foreach ($rows as $i => $row) {
        $lrn   = trim((string) ($row['lrn'] ?? ''));
        $full  = trim((string) ($row['full_name'] ?? ''));
        $prog  = isset($row['program']) ? trim((string) $row['program']) : '';
        if ($prog === '') $prog = null;

        if ($lrn === '' || $full === '') {
            $skipped++;
            $errors[] = 'Row ' . ($i + 1) . ': missing lrn or full_name';
            continue;
        }

        try {
            $sel->execute([$lrn]);
            if ($sel->fetch()) {
                $upd->execute([$full, $prog, $lrn]);
                $updated++;
            } else {
                $ins->execute([$lrn, $full, $prog]);
                $added++;
            }
        } catch (PDOException $e) {
            $skipped++;
            $errors[] = 'Row ' . ($i + 1) . ' (LRN ' . $lrn . '): ' . $e->getMessage();
        }
    }

    $log = $pdo->prepare('INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)');
    $log->execute([$admin['user_id'], 'ROSTER_IMPORT', "Imported student roster: $added added, $updated updated, $skipped skipped"]);

    sendSuccess(['added' => $added, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors]);
} catch (PDOException $e) {
    error_log('QuizSystem DB Error: ' . $e->getMessage());
    sendError('An unexpected error occurred. Please try again.', 'DB_ERROR', 500);
}