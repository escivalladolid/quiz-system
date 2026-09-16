<?php
/**
 * Helpers for the roster-based self-registration flow
 * (register_student.php / register_teacher.php).
 */

/**
 * Split a roster "Full Name" into first and last names.
 * The final token becomes the last name; everything before it is the
 * first name ("Juan Dela Cruz" -> first "Juan Dela", last "Cruz").
 */
function splitFullName(string $fullName, ?string &$firstName, ?string &$lastName): void {
    $fullName = trim($fullName);

    // Registrar exports commonly use "SURNAME, GIVEN NAME". Preserve that
    // meaning instead of treating the final given-name token as the surname.
    if (strpos($fullName, ',') !== false) {
        [$surname, $givenNames] = array_map('trim', explode(',', $fullName, 2));
        if ($surname !== '' && $givenNames !== '') {
            $firstName = $givenNames;
            $lastName = $surname;
            return;
        }
    }

    $parts = preg_split('/\s+/', $fullName);
    $lastName  = (string) array_pop($parts);
    $firstName = implode(' ', $parts);
    if ($firstName === '') {
        $firstName = $lastName;
    }
}

/**
 * Slug a name fragment into a username-safe form:
 * lowercase, non-alphanumeric runs become ".", leading/trailing dots trimmed.
 */
function slugifyName(string $name): string {
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/', '.', $slug);
    $slug = trim($slug, '.');
    return $slug;
}

/**
 * Generate a unique username of the form first.last[.N] using the official
 * roster name. In the (rare) collision case a numeric suffix is appended.
 */
function generateUniqueUsername(PDO $pdo, string $first, string $last): string {
    $base = slugifyName($first . '.' . $last);
    if (strlen($base) < 3) {
        $base = str_pad($base, 3, '0');
    }
    if (strlen($base) > 30) {
        $base = substr($base, 0, 30);
    }

    $candidate = $base;
    $suffix = 2;
    while (true) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $stmt->execute([$candidate]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $candidate;
        }
        $candidate = $base . '.' . $suffix;
        $suffix++;
        if ($suffix > 200) {
            $candidate = $base . '.' . bin2hex(random_bytes(2));
        }
        if ($suffix > 10000) {
            break; // give up; username will be checked again upstream
        }
    }
    return $candidate;
}
