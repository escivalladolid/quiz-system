<?php
function validateEmail(string $email): ?string {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Please provide a valid email address.';
    }
    return null;
}

function validatePassword(string $password): ?string {
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'Password must contain at least one number.';
    }
    return null;
}