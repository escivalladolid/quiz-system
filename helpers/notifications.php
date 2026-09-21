<?php

/**
 * Notification persistence and audience helpers.
 *
 * Notifications are addressed to a class, user, or role. A class notification
 * is one row and is resolved at read time using the current enrollment or
 * teacher ownership relationship; no per-student rows are created.
 */

function createNotification(PDO $pdo, array $notification): ?int
{
    $targetType = strtoupper(trim((string) ($notification['target_type'] ?? '')));
    if (!in_array($targetType, ['CLASS', 'USER', 'ROLE'], true)) {
        throw new InvalidArgumentException('Invalid notification target type.');
    }

    $eventKey = trim((string) ($notification['event_key'] ?? ''));
    if ($eventKey === '') {
        throw new InvalidArgumentException('Notification event_key is required.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO notifications
            (target_type, target_id, type, title, message, reference_type,
             reference_id, expires_at, event_key)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE notification_id = LAST_INSERT_ID(notification_id)'
    );
    $stmt->execute([
        $targetType,
        (int) ($notification['target_id'] ?? 0),
        trim((string) ($notification['type'] ?? 'GENERAL')),
        trim((string) ($notification['title'] ?? 'Notification')),
        trim((string) ($notification['message'] ?? '')),
        ($notification['reference_type'] ?? null) !== null
            ? trim((string) $notification['reference_type']) : null,
        ($notification['reference_id'] ?? null) !== null
            ? (int) $notification['reference_id'] : null,
        ($notification['expires_at'] ?? null) ?: null,
        $eventKey,
    ]);

    $id = (int) $pdo->lastInsertId();
    return $id > 0 ? $id : null;
}

/** Create the single class-scoped event generated when an exam is published. */
function createExamPublishedNotification(PDO $pdo, int $classId, int $examId, string $examName): ?int
{
    return createNotification($pdo, [
        'target_type' => 'CLASS',
        'target_id' => $classId,
        'type' => 'EXAM_PUBLISHED',
        'title' => 'New Exam Available',
        'message' => trim($examName) !== ''
            ? trim($examName) . ' is now available for your class.'
            : 'A new exam is now available for your class.',
        'reference_type' => 'EXAM',
        'reference_id' => $examId,
        'event_key' => sprintf('CLASS:%d:EXAM_PUBLISHED:%d', $classId, $examId),
    ]);
}

/**
 * Adds the authenticated user's audience predicates and binds unique named
 * parameters so the same clause can be reused by list/count/read endpoints.
 */
function notificationAudienceSql(array &$params, int $userId, string $prefix): string
{
    $userParam = $prefix . '_user';
    $roleParam = $prefix . '_role';
    $teacherParam = $prefix . '_teacher';
    $enrolledParam = $prefix . '_enrolled';

    $params[$userParam] = $userId;
    $params[$roleParam] = $userId;
    $params[$teacherParam] = $userId;
    $params[$enrolledParam] = $userId;

    return "(
        (n.target_type = 'USER' AND n.target_id = :$userParam)
        OR (n.target_type = 'ROLE' AND n.target_id = (
            SELECT u.role_id FROM users u WHERE u.user_id = :$roleParam
        ))
        OR (n.target_type = 'CLASS' AND EXISTS (
            SELECT 1 FROM classes c_audience
            WHERE c_audience.class_id = n.target_id
              AND (
                  c_audience.teacher_id = :$teacherParam
                  OR EXISTS (
                      SELECT 1 FROM enrollments e_audience
                      WHERE e_audience.class_id = c_audience.class_id
                        AND e_audience.user_id = :$enrolledParam
                  )
              )
        ))
    )";
}

function notificationListForUser(PDO $pdo, int $userId, int $limit = 100, bool $unreadOnly = false): array
{
    $limit = max(1, min($limit, 200));
    $params = ['read_user' => $userId];
    $audience = notificationAudienceSql($params, $userId, 'list');
    $unread = $unreadOnly ? ' AND nr.notification_id IS NULL' : '';

    $sql = "SELECT
                n.notification_id, n.target_type, n.target_id, n.type,
                n.title, n.message, n.reference_type, n.reference_id,
                n.created_at, n.expires_at,
                CASE WHEN nr.notification_id IS NULL THEN 0 ELSE 1 END AS is_read,
                c.subject_name AS class_name, c.block AS class_block,
                e.exam_name
            FROM notifications n
            LEFT JOIN notification_reads nr
                ON nr.notification_id = n.notification_id
               AND nr.user_id = :read_user
            LEFT JOIN classes c
                ON n.target_type = 'CLASS' AND c.class_id = n.target_id
            LEFT JOIN exams e
                ON n.reference_type = 'EXAM' AND e.exam_id = n.reference_id
            WHERE (n.expires_at IS NULL OR n.expires_at > NOW())
              AND $audience
              $unread
            ORDER BY n.created_at DESC, n.notification_id DESC
            LIMIT $limit";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['notification_id'] = (int) $row['notification_id'];
        $row['target_id'] = (int) $row['target_id'];
        $row['reference_id'] = $row['reference_id'] === null ? null : (int) $row['reference_id'];
        $row['is_read'] = (int) $row['is_read'] === 1;
    }
    unset($row);
    return $rows;
}

function countUnreadNotifications(PDO $pdo, int $userId): int
{
    $params = ['read_user' => $userId];
    $audience = notificationAudienceSql($params, $userId, 'count');
    $stmt = $pdo->prepare("SELECT COUNT(*)
        FROM notifications n
        LEFT JOIN notification_reads nr
          ON nr.notification_id = n.notification_id
         AND nr.user_id = :read_user
        WHERE (n.expires_at IS NULL OR n.expires_at > NOW())
          AND nr.notification_id IS NULL
          AND $audience");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function getNotificationForUser(PDO $pdo, int $userId, int $notificationId): ?array
{
    $params = ['read_user' => $userId, 'notification_id' => $notificationId];
    $audience = notificationAudienceSql($params, $userId, 'one');
    $stmt = $pdo->prepare("SELECT n.notification_id, n.reference_type, n.reference_id,
        CASE WHEN nr.notification_id IS NULL THEN 0 ELSE 1 END AS is_read
        FROM notifications n
        LEFT JOIN notification_reads nr
          ON nr.notification_id = n.notification_id
         AND nr.user_id = :read_user
        WHERE n.notification_id = :notification_id
          AND (n.expires_at IS NULL OR n.expires_at > NOW())
          AND $audience
        LIMIT 1");
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function markNotificationRead(PDO $pdo, int $userId, int $notificationId): bool
{
    if (!getNotificationForUser($pdo, $userId, $notificationId)) {
        return false;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO notification_reads (notification_id, user_id, read_at)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE read_at = read_at'
    );
    $stmt->execute([$notificationId, $userId]);
    return true;
}

function markAllNotificationsRead(PDO $pdo, int $userId): int
{
    $params = ['insert_user' => $userId, 'read_user' => $userId];
    $audience = notificationAudienceSql($params, $userId, 'all');
    $stmt = $pdo->prepare("INSERT INTO notification_reads (notification_id, user_id, read_at)
        SELECT n.notification_id, :insert_user, NOW()
        FROM notifications n
        LEFT JOIN notification_reads nr
          ON nr.notification_id = n.notification_id
         AND nr.user_id = :read_user
        WHERE (n.expires_at IS NULL OR n.expires_at > NOW())
          AND nr.notification_id IS NULL
          AND $audience");
    $stmt->execute($params);
    return (int) $stmt->rowCount();
}
