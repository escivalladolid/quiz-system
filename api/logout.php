<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/response.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('This endpoint only accepts POST requests.', 'METHOD_NOT_ALLOWED', 405);
}

/* Logout is intentionally idempotent: the app can clear its local session
 * even when the network is unavailable or the token already expired. */
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
if (!preg_match('/Bearer\s+(.+)/', $authHeader, $matches)) {
    sendSuccess(['logged_out' => true]);
}

$token = trim($matches[1]);
if ($token === '') {
    sendSuccess(['logged_out' => true]);
}

try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare('DELETE FROM sessions WHERE token = ?');
    $stmt->execute([$token]);
    sendSuccess(['logged_out' => true]);
} catch (PDOException $e) {
    sendError('Unable to end the session right now.', 'SERVER_ERROR', 500);
}
