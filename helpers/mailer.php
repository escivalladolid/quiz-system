<?php
/**
 * Minimal SMTP mailer using PHP stream sockets (no Composer dependency).
 *
 * Reads SMTP_HOST / SMTP_PORT / SMTP_USER / SMTP_PASSWORD / SMTP_FROM_EMAIL /
 * SMTP_FROM_NAME from the environment, mirroring config/database.php. When
 * SMTP_HOST is unset, sendMail() returns false and the caller decides how to
 * handle the failure (e.g. log the code server-side and continue).
 *
 * Works on XAMPP (plain sockets) and Render (env vars set in the service
 * settings). Supports implicit TLS (port 465) and STARTTLS (typically 587).
 */

function smtp_configured(): bool {
    return (bool) getenv('SMTP_HOST');
}

/**
 * Send a plain-HTML email via SMTP.
 *
 * @param string $to      Recipient address.
 * @param string $subject Email subject.
 * @param string $html    HTML body (a plain-text part is auto-derived).
 * @return bool True on success, false when SMTP config is missing or the
 *              server rejected the message.
 */
function sendMail(string $to, string $subject, string $html): bool {
    if (!smtp_configured()) {
        return false;
    }

    $host = (string) getenv('SMTP_HOST');
    $port = (int) (getenv('SMTP_PORT') ?: 587);
    $user = (string) getenv('SMTP_USER');
    $pass = (string) getenv('SMTP_PASSWORD');
    $fromEmail = (string) (getenv('SMTP_FROM_EMAIL') ?: ($user !== '' ? $user : 'no-reply@rmc.edu.ph'));
    $fromName = (string) (getenv('SMTP_FROM_NAME') ?: 'RMC Quiz & Exam System');

    $remote = ($port === 465 ? 'tls://' : '') . $host . ':' . $port;
    $conn = @stream_socket_client($remote, $errno, $errstr, 20);
    if (!$conn) {
        error_log('Mailer: could not connect to SMTP ' . $host . ':' . $port . ' -> ' . $errstr);
        return false;
    }
    stream_set_timeout($conn, 20);

    if (!smtp_read($conn, 220)) { fclose($conn); return false; }
    fwrite($conn, "EHLO quiz.rmc.edu.ph\r\n");
    if (!smtp_read($conn, 250)) { fclose($conn); return false; }

    // STARTTLS for non-implicit-TLS ports where it is offered.
    if ($port !== 465) {
        fwrite($conn, "STARTTLS\r\n");
        if (smtp_read($conn, 220)) {
            if (!stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                error_log('Mailer: STARTTLS handshake failed for ' . $host);
                fclose($conn);
                return false;
            }
            fwrite($conn, "EHLO quiz.rmc.edu.ph\r\n");
            smtp_read($conn, 250); // multi-line capability response
        } else {
            error_log('Mailer: STARTTLS not accepted by ' . $host . ', continuing unencrypted');
        }
    }

    if ($user !== '' && $pass !== '') {
        fwrite($conn, "AUTH LOGIN\r\n");
        if (!smtp_read($conn, 334)) { fclose($conn); return false; }
        fwrite($conn, base64_encode($user) . "\r\n");
        if (!smtp_read($conn, 334)) { fclose($conn); return false; }
        fwrite($conn, base64_encode($pass) . "\r\n");
        if (!smtp_read($conn, 235)) { fclose($conn); return false; }
    }

    $fromHeader = $fromName . ' <' . $fromEmail . '>';
    fwrite($conn, "MAIL FROM:<$fromEmail>\r\n");
    if (!smtp_read($conn, 250)) { fclose($conn); return false; }
    fwrite($conn, "RCPT TO:<$to>\r\n");
    if (!smtp_read($conn, 250)) { fclose($conn); return false; }

    $text = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html));
    $boundary = 'rmcpw' . bin2hex(random_bytes(8));
    $message = "From: $fromHeader\r\n"
        . "To: <$to>\r\n"
        . "Subject: $subject\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n"
        . "\r\n"
        . "--$boundary\r\n"
        . "Content-Type: text/plain; charset=utf-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n"
        . "\r\n"
        . $text . "\r\n"
        . "--$boundary\r\n"
        . "Content-Type: text/html; charset=utf-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n"
        . "\r\n"
        . $html . "\r\n"
        . "--$boundary--\r\n";

    fwrite($conn, "DATA\r\n");
    if (!smtp_read($conn, 354)) { fclose($conn); return false; }
    fwrite($conn, preg_replace('/^\./m', '..', $message) . "\r\n.\r\n");
    if (!smtp_read($conn, 250)) { fclose($conn); return false; }

    fwrite($conn, "QUIT\r\n");
    smtp_read($conn, 221);
    fclose($conn);
    return true;
}

/**
 * Build the standard password-reset email for a given reset token.
 */
function sendPasswordResetEmail(string $to, string $resetToken, string $expiresAt): bool {
    $subject = 'RMC Quiz & Exam System - Password Reset';
    $expiryReadable = date('F j, Y g:i A', strtotime($expiresAt));
    $html = '<p>You recently requested to reset your password for the RMC Quiz '
        . 'and Exam System.</p>'
        . '<p>Your reset code is:</p>'
        . '<p style="font-size:22px;font-weight:bold;letter-spacing:4px;">'
        . htmlspecialchars($resetToken) . '</p>'
        . '<p>This code expires on <strong>' . htmlspecialchars($expiryReadable)
        . '</strong>. If you did not request this reset, you can safely ignore '
        . 'this email.</p>';
    return sendMail($to, $subject, $html);
}

/**
 * Build the email used to verify a newly registered roster account.
 * The token is intentionally delivered only by email and is never returned
 * in the API response or written to the application log.
 */
function sendRegistrationVerificationEmail(string $to, string $verificationToken, string $expiresAt): bool {
    $subject = 'RMC Quiz & Exam System - Verify your email';
    $expiryReadable = date('F j, Y g:i A', strtotime($expiresAt));
    $html = '<p>Thank you for registering for the RMC Quiz and Exam System.</p>'
        . '<p>Enter this verification code in the app to activate your account:</p>'
        . '<p style="font-size:22px;font-weight:bold;letter-spacing:4px;">'
        . htmlspecialchars($verificationToken) . '</p>'
        . '<p>This code expires on <strong>' . htmlspecialchars($expiryReadable)
        . '</strong>. If you did not create this account, you can safely ignore '
        . 'this email.</p>';
    return sendMail($to, $subject, $html);
}

/**
 * Read one SMTP server reply line, look for the expected leading code.
 */
function smtp_read($conn, int $expectedCode): bool {
    $lines = [];
    while (($line = fgets($conn, 512)) !== false) {
        $code = (int) substr($line, 0, 3);
        if (isset($line[3]) && $line[3] === '-') {
            $lines[] = $line; // multi-line response continues
            continue;
        }
        $lines[] = $line;
        return $code === $expectedCode;
    }
    error_log('Mailer: SMTP connection dropped while reading response, expected ' . $expectedCode);
    return false;
}
