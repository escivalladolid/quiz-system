<?php
require_once __DIR__ . '/response.php';

function getAuthenticatedUser(PDO $pdo): array {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!preg_match('/Bearer\s+(.+)/', $authHeader, $matches)) {
        sendError('Missing or invalid Authorization header.', 'UNAUTHORIZED', 401);
    }
    $token = $matches[1];

    $stmt = $pdo->prepare(
        'SELECT u.user_id, u.first_name, u.last_name, u.username, u.email, r.role_name, s.expires_at
         FROM sessions s
         JOIN users u ON u.user_id = s.user_id
         JOIN roles r ON r.role_id = u.role_id
         WHERE s.token = :token'
    );
    $stmt->execute(['token' => $token]);
    $session = $stmt->fetch();

    if (!$session) {
        sendError('Invalid session token.', 'UNAUTHORIZED', 401);
    }
    if (strtotime($session['expires_at']) < time()) {
        sendError('Session expired. Please log in again.', 'SESSION_EXPIRED', 401);
    }

    return $session;
}

function requireRole(PDO $pdo, array $allowedRoles): array {
    $user = getAuthenticatedUser($pdo);
    if (!in_array($user['role_name'], $allowedRoles)) {
        sendError('You do not have permission to access this resource.', 'FORBIDDEN', 403);
    }
    return $user;
}