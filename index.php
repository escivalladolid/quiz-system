<?php
/**
 * Root endpoint — Render health check target.
 *
 * Render's default health check performs GET / and expects an HTTP 200.
 * This returns a minimal status body (no data, no schema). The APP_ENV
 * variable (set in render.yaml) lets you confirm which environment served it.
 */
header('Content-Type: application/json');
http_response_code(200);

$env = getenv('APP_ENV') ?: 'development';

echo json_encode([
    'success' => true,
    'service' => 'quiz-system-api',
    'environment' => $env,
    'status' => 'ok',
]);