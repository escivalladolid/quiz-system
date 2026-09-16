<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../helpers/auth.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

$pdo = getDbConnection();
requireRole($pdo, ['ADMIN']);

$status = strtoupper(trim((string) ($_GET['status'] ?? '')));
$type = strtoupper(trim((string) ($_GET['type'] ?? '')));
$search = trim((string) ($_GET['search'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 50)));
$validStatuses = ['NEW', 'IN_PROGRESS', 'RESOLVED'];
$validTypes = ['SYSTEM_ALERT', 'STUDENT_REPORT'];
if (!in_array($status, $validStatuses, true)) $status = '';
if (!in_array($type, $validTypes, true)) $type = '';

$searchLike = $search === '' ? null : '%' . $search . '%';
$issues = [];
$total = 0;
$summary = ['NEW' => 0, 'IN_PROGRESS' => 0, 'RESOLVED' => 0];

try {
    // Fetch enough rows from each source to produce a correctly interleaved
    // newest-first page after the two feeds are normalized below.
    $fetchLimit = min(500, max(100, $page * $perPage));

    if ($type === '' || $type === 'SYSTEM_ALERT') {
        $where = [];
        $params = [];
        if ($status !== '') {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        if ($searchLike !== null) {
            $where[] = '(alert_type LIKE :search_type OR description LIKE :search_description OR affected_identifier LIKE :search_identifier)';
            $params['search_type'] = $searchLike;
            $params['search_description'] = $searchLike;
            $params['search_identifier'] = $searchLike;
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $count = $pdo->prepare("SELECT COUNT(*) FROM system_alerts$whereSql");
        $count->execute($params);
        $systemTotal = (int) $count->fetchColumn();
        $total += $systemTotal;

        $summaryStmt = $pdo->query("SELECT status, COUNT(*) AS cnt FROM system_alerts GROUP BY status");
        foreach ($summaryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $s = strtoupper((string) ($row['status'] ?? ''));
            if (isset($summary[$s])) $summary[$s] += (int) $row['cnt'];
        }

        $stmt = $pdo->prepare(
            "SELECT alert_id, alert_type, severity, affected_user_id, affected_identifier,
                    description, details, status, created_at, updated_at, resolved_at, resolved_by
             FROM system_alerts$whereSql
             ORDER BY created_at DESC, alert_id DESC
             LIMIT $fetchLimit"
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $details = null;
            if (!empty($row['details'])) {
                $decoded = json_decode((string) $row['details'], true);
                $details = is_array($decoded) ? $decoded : null;
            }
            $issues[] = [
                'issue_type' => 'SYSTEM_ALERT',
                'issue_id' => (int) $row['alert_id'],
                'type_label' => 'System alert',
                'alert_type' => (string) $row['alert_type'],
                'severity' => (string) $row['severity'],
                'affected_user_id' => $row['affected_user_id'] === null ? null : (int) $row['affected_user_id'],
                'affected_identifier' => $row['affected_identifier'],
                'contact' => null,
                'role' => null,
                'step' => null,
                'screen' => null,
                'app_version' => null,
                'device_info' => null,
                'description' => (string) $row['description'],
                'details' => $details,
                'status' => strtoupper((string) $row['status']),
                'source_status' => strtoupper((string) $row['status']),
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'resolved_at' => $row['resolved_at'],
                'resolved_by' => $row['resolved_by'] === null ? null : (int) $row['resolved_by'],
            ];
        }
    }

    if ($type === '' || $type === 'STUDENT_REPORT') {
        $where = [];
        $params = [];
        if ($status === 'NEW') {
            $where[] = "status = 'OPEN'";
        } elseif ($status === 'IN_PROGRESS') {
            $where[] = "status = 'IN_PROGRESS'";
        } elseif ($status === 'RESOLVED') {
            $where[] = "status = 'RESOLVED'";
        }
        if ($searchLike !== null) {
            $where[] = '(contact LIKE :search_contact OR description LIKE :search_description OR step LIKE :search_step OR screen LIKE :search_screen)';
            $params['search_contact'] = $searchLike;
            $params['search_description'] = $searchLike;
            $params['search_step'] = $searchLike;
            $params['search_screen'] = $searchLike;
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $count = $pdo->prepare("SELECT COUNT(*) FROM login_problem_reports$whereSql");
        $count->execute($params);
        $reportTotal = (int) $count->fetchColumn();
        $total += $reportTotal;

        // OPEN is the legacy stored value for the support center's NEW state.
        $summaryStmt = $pdo->query("SELECT status, COUNT(*) AS cnt FROM login_problem_reports GROUP BY status");
        foreach ($summaryStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $s = strtoupper((string) ($row['status'] ?? ''));
            $normalized = $s === 'OPEN' ? 'NEW' : $s;
            if (isset($summary[$normalized])) $summary[$normalized] += (int) $row['cnt'];
        }

        $stmt = $pdo->prepare(
            "SELECT report_id, contact, role, step, description, screen, app_version,
                    device_info, status, created_at
             FROM login_problem_reports$whereSql
             ORDER BY created_at DESC, report_id DESC
             LIMIT $fetchLimit"
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sourceStatus = strtoupper((string) $row['status']);
            $issues[] = [
                'issue_type' => 'STUDENT_REPORT',
                'issue_id' => (int) $row['report_id'],
                'type_label' => 'Student report',
                'alert_type' => null,
                'severity' => 'INFO',
                'affected_user_id' => null,
                'affected_identifier' => (string) $row['contact'],
                'contact' => (string) $row['contact'],
                'role' => (string) $row['role'],
                'step' => (string) $row['step'],
                'screen' => $row['screen'],
                'app_version' => $row['app_version'],
                'device_info' => $row['device_info'],
                'description' => (string) $row['description'],
                'details' => null,
                'status' => $sourceStatus === 'OPEN' ? 'NEW' : $sourceStatus,
                'source_status' => $sourceStatus,
                'created_at' => $row['created_at'],
                'updated_at' => $row['created_at'],
                'resolved_at' => null,
                'resolved_by' => null,
            ];
        }
    }
} catch (PDOException $e) {
    error_log('Support issues unavailable: ' . get_class($e));
    sendError('Support issues are not available until the support database migration is applied.', 'SERVER_MISCONFIGURED', 503);
}

usort($issues, static function (array $a, array $b): int {
    $time = strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    return $time !== 0 ? $time : ((int) $b['issue_id'] <=> (int) $a['issue_id']);
});
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;
$issues = array_slice($issues, $offset, $perPage);

sendSuccess([
    'page' => $page,
    'per_page' => $perPage,
    'total' => $total,
    'pages' => $pages,
    'summary' => $summary,
    'issues' => $issues,
]);

