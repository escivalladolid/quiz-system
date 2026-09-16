<?php
/**
 * Generate a short, readable one-time code for email verification and resets.
 * Ambiguous characters (I, O, 0, and 1) are deliberately excluded.
 */
function generateEmailCode(int $length = 8): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $maxIndex = strlen($alphabet) - 1;
    $code = '';

    for ($i = 0; $i < $length; $i++) {
        $code .= $alphabet[random_int(0, $maxIndex)];
    }

    return $code;
}
