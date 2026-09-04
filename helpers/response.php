<?php
function sendSuccess(array $data = [], int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

function sendError(string $message, string $code = 'ERROR', int $statusCode = 400): void {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $message, 'code' => $code]);
    exit;
}

function getJsonInput(): array {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        sendError('Invalid or missing JSON request body.', 'INVALID_JSON', 400);
    }
    return $decoded;
}

function requireFields(array $input, array $requiredFields): void {
    $missing = [];
    foreach ($requiredFields as $field) {
        $val = $input[$field] ?? null;
        $empty = ($val === null || (is_string($val) && trim($val) === '') || (is_array($val) && empty($val)));
        if ($empty) {
            $missing[] = $field;
        }
    }
    if (!empty($missing)) {
        sendError('Missing required field(s): ' . implode(', ', $missing), 'MISSING_FIELDS', 422);
    }
}