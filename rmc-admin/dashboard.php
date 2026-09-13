<?php
/**
 * RMC Quiz & Examination System — Admin Portal (single-file dashboard).
 * This is the live version of the rmc_admin_dashboard.html template: same
 * design, every view populated with real data from the shared API, fetched
 * server-side with the stored admin token.
 */
require_once __DIR__ . '/inc/bootstrap.php';
admin_require_login();

$admin = $_SESSION['admin_user'];
$token = (string) ($admin['token'] ?? '');

/* ---------- fetch real data ---------- */

$stats = ['total_users'=>0,'total_students'=>0,'total_teachers'=>0,'total_admins'=>0,
          'new_students_7d'=>0,'new_teachers_7d'=>0,'flagged_7d'=>0,'auto_submitted_7d'=>0,
          'flagged_exit_7d'=>0,'flagged_last_24h'=>0,'live_exams'=>0];
$recent = [];
$logs   = [];
$users  = [];
$classes = [];

$res = admin_api_request('GET', 'admin/stats.php', [], $token);
if (($res['body']['success'] ?? false) === true) {
    $data   = $res['body']['data'] ?? [];
    $stats  = array_merge($stats, is_array($data['stats'] ?? null) ? $data['stats'] : []);
    $recent = is_array($data['recent_submissions'] ?? null) ? $data['recent_submissions'] : [];
}

$res = admin_api_request('GET', 'admin/logs.php?per_page=30', [], $token);
if (($res['body']['success'] ?? false) === true) {
    $logs = is_array(($res['body']['data']['logs'] ?? null)) ? $res['body']['data']['logs'] : [];
}

$res = admin_api_request('GET', 'admin/users.php?per_page=50', [], $token);
if (($res['body']['success'] ?? false) === true) {
    $users = is_array(($res['body']['data']['users'] ?? null)) ? $res['body']['data']['users'] : [];
}

$res = admin_api_request('GET', 'admin/reports.php', [], $token);
if (($res['body']['success'] ?? false) === true) {
    $classes = is_array(($res['body']['data']['classes'] ?? null)) ? $res['body']['data']['classes'] : [];
}

$totalUsers    = (int) ($stats['total_users'] ?? 0);
$totalStudents = (int) ($stats['total_students'] ?? 0);
$totalTeachers = (int) ($stats['total_teachers'] ?? 0);
$totalAdmins   = (int) ($stats['total_admins'] ?? 0);
$newStudents7d = (int) ($stats['new_students_7d'] ?? 0);
$newTeachers7d = (int) ($stats['new_teachers_7d'] ?? 0);
$flagged7d     = (int) ($stats['flagged_7d'] ?? 0);
$auto7d        = (int) ($stats['auto_submitted_7d'] ?? 0);
$exit7d        = (int) ($stats['flagged_exit_7d'] ?? 0);
$flag24h       = (int) ($stats['flagged_last_24h'] ?? 0);
$liveExams     = (int) ($stats['live_exams'] ?? 0);
$activeCount   = $totalStudents + $totalTeachers + $totalAdmins;
$inactiveCount = max(0, $totalUsers - $activeCount);
$activePct     = $totalUsers > 0 ? number_format(($activeCount / $totalUsers) * 100, 1) : '0.0';

$adminName  = trim((string) ($admin['name'] ?? $admin['username'] ?? 'Admin'));
$adminShort = $adminName !== '' ? $adminName : 'Admin';
$avatarChar = strtoupper(mb_substr(preg_replace('/[^A-Za-z]/', '', $adminShort), 0, 1) ?: 'A');

function rmc_when(?string $ts): string {
    $t = $ts ? strtotime($ts) : 0;
    if (!$t) return '';
    $today = strtotime('today');
    $yday  = strtotime('yesterday');
    if ($t >= $today)            return 'Today, ' . date('g:i A', $t);
    if ($t >= $yday)             return 'Yesterday, ' . date('g:i A', $t);
    return date('M j, Y', $t);
}

/** Merged system-activity feed (submissions + admin logs), newest first. */
function rmc_activity_rows(array $recent, array $logs): array {
    $rows = [];
    foreach ($recent as $r) {
        $pct  = isset($r['percentage']) && $r['percentage'] !== null ? (int) round((float) $r['percentage']) : null;
        $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
        $exam = (string) ($r['exam_name'] ?? 'exam');
        $subj = (string) ($r['subject_name'] ?? '');
        $txt  = '<strong>' . e($name ?: 'A student') . '</strong> submitted <strong>' . e($exam) . '</strong>';
        if ($subj !== '') $txt .= ' in ' . e($subj);
        if ($pct !== null) $txt .= ' (' . $pct . '%)';
        $rows[] = ['dot' => 'submit', 'text' => $txt, 'time' => rmc_when((string) ($r['submitted_at'] ?? '')), 'ts' => (string) ($r['submitted_at'] ?? '')];
    }
    foreach ($logs as $l) {
        $action = strtoupper((string) ($l['action'] ?? ''));
        $dot = 'admin';
        if (in_array($action, ['LOGIN', 'SESSION_KILL'], true))  $dot = 'login';
        elseif ($action === 'EXAM_STATUS')                       $dot = 'flag';
        else if (strpos($action, 'CLASS') === 0)                 $dot = 'login';
        $actor = trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? ''));
        if ($actor === '' || $actor === ' ') $actor = (string) ($l['username'] ?? '');
        $desc = (string) ($l['description'] ?? $action);
        if ($action === 'LOGIN') $txt = '<strong>' . e($actor ?: 'Admin') . '</strong> signed in';
        else                     $txt = '<strong>' . e($actor ?: 'Admin') . '</strong> — ' . e($desc);
        $rows[] = ['dot' => $dot, 'text' => $txt, 'time' => rmc_when((string) ($l['created_at'] ?? '')), 'ts' => (string) ($l['created_at'] ?? '')];
    }
    usort($rows, function ($a, $b) { return strcmp((string) $b['ts'], (string) $a['ts']); });
    return array_slice($rows, 0, 6);
}

/** Account-change rows from user-related admin logs. */
function rmc_account_change_rows(array $logs): array {
    $out = [];
    foreach ($logs as $l) {
        $action = strtoupper((string) ($l['action'] ?? ''));
        if (!in_array($action, ['USER_CREATE', 'USER_UPDATE', 'USER_STATUS'], true)) continue;
        $desc   = (string) ($l['description'] ?? '');
        $active = strpos($desc, "'INACTIVE'") === false && strpos($desc, "'BANNED'") === false;
        $actor  = trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? ''));
        if ($actor === '' || $actor === ' ') $actor = (string) ($l['username'] ?? '');
        $out[] = ['name' => $actor ?: 'Admin', 'meta' => $desc, 'active' => $active,
                  'time' => rmc_when((string) ($l['created_at'] ?? ''))];
        if (count($out) >= 5) break;
    }
    return $out;
}

$activityRows = rmc_activity_rows($recent, $logs);
$accountRows  = rmc_account_change_rows($logs);

$subjectBars = [];
$sectionBars = [];
foreach (array_slice($classes, 0, 5) as $c) {
    $lab = trim(($c['subject_name'] ?? '') . ' · ' . ($c['block'] ?? ''), ' ·');
    $subjectBars[] = ['label' => $lab ?: '—', 'value' => round((float) ($c['avg_pct'] ?? 0)), 'amber' => (float) ($c['avg_pct'] ?? 0) < 75];
}
foreach (array_slice($classes, 0, 5) as $c) {
    $sectionBars[] = ['label' => (string) ($c['block'] ?? '—'), 'value' => round((float) ($c['pass_rate'] ?? 0)), 'amber' => (float) ($c['pass_rate'] ?? 0) < 75];
}

$self_id = (int) ($admin['user_id'] ?? 0);

/* ---------- Classes view (teachers + class list) ---------- */
$teachers = [];
$classesAll = [];
$classStatus = ['total' => 0, 'active' => 0, 'archived' => 0];
$res = admin_api_request('GET', 'admin/users.php?role=TEACHER&per_page=100', [], $token);
if (($res['body']['success'] ?? false) === true) {
    $teachers = is_array($res['body']['data']['users'] ?? null) ? $res['body']['data']['users'] : [];
}
$res = admin_api_request('GET', 'admin/classes.php?per_page=100', [], $token);
if (($res['body']['success'] ?? false) === true) {
    $d = $res['body']['data'] ?? [];
    $classesAll = is_array($d['classes'] ?? null) ? $d['classes'] : [];
    foreach (($d['summary'] ?? []) as $k => $v) { if (isset($classStatus[$k])) { $classStatus[$k] = (int) $v; } }
}

/* ---------- Assessments view (exams) ---------- */
$examsAll = [];
$examStatusCounts = ['DRAFT' => 0, 'SCHEDULED' => 0, 'LIVE' => 0, 'CLOSED' => 0, 'ARCHIVED' => 0];
$classesOption = [];
$res = admin_api_request('GET', 'admin/exams.php?per_page=100', [], $token);
if (($res['body']['success'] ?? false) === true) {
    $d = $res['body']['data'] ?? [];
    $examsAll = is_array($d['exams'] ?? null) ? $d['exams'] : [];
    foreach (($d['summary'] ?? []) as $k => $v) { if (isset($examStatusCounts[$k])) { $examStatusCounts[$k] = (int) $v; } }
}
foreach ($classesAll as $cc) {
    $label = trim(($cc['subject_code'] ?? '') . ' · ' . ($cc['class_code'] ?? '') . ($cc['block'] ? ' · ' . $cc['block'] : ''));
    $classesOption[] = ['class_id' => (int) $cc['class_id'], 'label' => $label];
}

/* ---------- Logs view (audit trail) ---------- */
$auditLogs = [];
$auditSummary = [];
$res = admin_api_request('GET', 'admin/logs.php?per_page=200', [], $token);
if (($res['body']['success'] ?? false) === true) {
    $auditLogs = is_array($res['body']['data']['logs'] ?? null) ? $res['body']['data']['logs'] : [];
    $auditSummary = is_array($res['body']['data']['summary'] ?? null) ? $res['body']['data']['summary'] : [];
}

/* ---------- Maintenance view (health + sessions) ---------- */
$maint = ['db_now' => null, 'tz_offset_seconds' => 0, 'table_counts' => [], 'sessions' => [],
          'active_sessions' => 0, 'php_version' => PHP_VERSION];
$res = admin_api_request('GET', 'admin/maintenance.php', [], $token);
if (($res['body']['success'] ?? false) === true) {
    $maint = array_merge($maint, $res['body']['data'] ?? []);
}
$maintCounts = $maint['table_counts'];
$maintSessions = $maint['sessions'];
$apiBase = rtrim(admin_api_base(), '/');
function rmc_remain(string $expires): string {
    $diff = strtotime($expires) - time();
    if ($diff <= 0) return 'expired';
    $h = intdiv($diff, 3600);
    $m = intdiv($diff % 3600, 60);
    return ($h > 0 ? "{$h}h " : '') . "{$m}m";
}

/* ---------- Full reports ---------- */
$reportMeta = ['overall' => null, 'classes' => [], 'weakest' => [], 'daily' => []];
$res = admin_api_request('GET', 'admin/reports.php', [], $token);
if (($res['body']['success'] ?? false) === true) {
    $reportMeta = array_merge($reportMeta, $res['body']['data'] ?? []);
}
$overall  = $reportMeta['overall'];
$weakest  = $reportMeta['weakest'];
$daily    = $reportMeta['daily'];
$maxDaily = 1;
foreach ($daily as $d) { $maxDaily = max($maxDaily, (int) $d['count']); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RMC Quiz &amp; Examination System — Admin Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root{
    --navy-950:#0b1730;
    --navy-900:#122347;
    --navy-800:#1a2f5c;
    --navy-700:#24406f;
    --amber:#e8a33d;
    --amber-dim:#f0c07a;
    --skyseal:#9fdbff;
    --paper:#f6f7fb;
    --paper-raised:#ffffff;
    --ink:#152039;
    --ink-soft:#5a6478;
    --line:#e3e6ee;
    --ok:#2f8f5b;
    --ok-bg:#e6f4ec;
    --warn:#b3541e;
    --warn-bg:#fbeadd;
    --danger:#b3341f;
    --danger-bg:#fbe6e2;
    --radius-card: 4px;
  }
  *{box-sizing:border-box;}
  html,body{margin:0;padding:0;}
  body{
    font-family:'Inter',sans-serif;
    background:var(--paper);
    color:var(--ink);
    -webkit-font-smoothing:antialiased;
  }
  h1,h2,h3,.brand-word{
    font-family:'Fraunces',serif;
    letter-spacing:-0.01em;
  }
  .mono{font-family:'IBM Plex Mono',monospace;}

  /* ---------- Shell layout ---------- */
  .shell{display:grid;grid-template-columns:250px 1fr;min-height:100vh;}

  /* ---------- Sidebar ---------- */
  .sidebar{
    background:var(--navy-900);
    color:#dfe6f5;
    display:flex;
    flex-direction:column;
    position:sticky;top:0;height:100vh;
  }
  .sidebar-brand{
    display:flex;align-items:center;gap:12px;
    padding:22px 20px 18px 20px;
    border-bottom:1px solid rgba(255,255,255,0.09);
  }
  .sidebar-brand img{width:38px;height:38px;border-radius:50%;flex:none;}
  .sidebar-brand .brand-text{line-height:1.15;}
  .sidebar-brand .brand-word{font-size:14px;font-weight:600;color:#fff;line-height:1.25;}
  .sidebar-brand .brand-sub{font-size:10.5px;color:var(--amber-dim);letter-spacing:.08em;text-transform:uppercase;margin-top:2px;}

  .sidebar-section-label{
    font-size:10.5px;color:#7c8bab;letter-spacing:.08em;
    padding:18px 20px 6px 20px;
  }
  .nav-list{list-style:none;margin:0;padding:0 10px;}
  .nav-item{
    display:flex;align-items:center;gap:11px;
    padding:10px 12px;margin:1px 0;
    border-radius:6px;
    font-size:14px;color:#c4cee3;
    cursor:pointer;text-decoration:none;
    border-left:3px solid transparent;
    transition:background .15s ease, color .15s ease;
  }
  .nav-item svg{flex:none;opacity:.85;}
  .nav-item:hover{background:rgba(255,255,255,0.06);color:#fff;}
  .nav-item.active{
    background:rgba(232,163,61,0.13);
    color:#fff;
    border-left:3px solid var(--amber);
  }
  .sidebar-foot{
    margin-top:auto;padding:16px 20px 20px 20px;
    border-top:1px solid rgba(255,255,255,0.09);
    font-size:11.5px;color:#7c8bab;
  }
  .sidebar-foot strong{color:#c4cee3;display:block;font-size:12.5px;margin-bottom:2px;}

  /* ---------- Main column ---------- */
  .main{display:flex;flex-direction:column;min-width:0;}
  .topbar{
    background:var(--paper-raised);
    border-bottom:1px solid var(--line);
    padding:16px 32px;
    display:flex;align-items:center;justify-content:space-between;
    position:sticky;top:0;z-index:5;
  }
  .topbar h1{font-size:21px;font-weight:600;margin:0;color:var(--navy-900);}
  .topbar .page-sub{font-size:12.5px;color:var(--ink-soft);margin-top:2px;font-family:'Inter';}
  .topbar-right{display:flex;align-items:center;gap:18px;}
  .search-box{
    display:flex;align-items:center;gap:8px;
    background:var(--paper);border:1px solid var(--line);
    border-radius:6px;padding:8px 12px;font-size:13px;color:var(--ink-soft);
    width:230px;
  }
  .search-box input{border:none;background:none;outline:none;font-size:13px;font-family:'Inter';width:100%;color:var(--ink);}
  .admin-chip{
    display:flex;align-items:center;gap:9px;
    padding:6px 10px 6px 6px;border-radius:24px;
    background:var(--paper);border:1px solid var(--line);
    cursor:pointer;
  }
  .admin-chip .avatar{
    width:28px;height:28px;border-radius:50%;
    background:var(--navy-800);color:var(--amber-dim);
    display:flex;align-items:center;justify-content:center;
    font-size:11.5px;font-weight:600;font-family:'IBM Plex Mono';
  }
  .admin-chip .chip-text{font-size:12.5px;line-height:1.2;}
  .admin-chip .chip-text strong{display:block;font-size:12.5px;color:var(--ink);}
  .admin-chip .chip-text span{color:var(--ink-soft);font-size:11px;}
  .chip-caret{color:var(--ink-soft);flex:none;}

  .admin-menu{position:relative;}
  .admin-dropdown{
    display:none;position:absolute;top:calc(100% + 8px);right:0;
    background:var(--paper-raised);border:1px solid var(--line);
    border-radius:8px;box-shadow:0 12px 28px rgba(11,23,48,0.16);
    width:190px;padding:6px;z-index:20;
  }
  .admin-dropdown.open{display:block;}
  .admin-dropdown-item{
    display:flex;align-items:center;gap:9px;
    padding:9px 10px;border-radius:6px;font-size:13px;color:var(--ink);
    cursor:pointer;text-decoration:none;
  }
  .admin-dropdown-item svg{flex:none;color:var(--ink-soft);}
  .admin-dropdown-item:hover{background:var(--paper);}
  .admin-dropdown-divider{height:1px;background:var(--line);margin:5px 4px;}
  .admin-dropdown-item.logout{color:var(--danger);}
  .admin-dropdown-item.logout svg{color:var(--danger);}
  .admin-dropdown-item.logout:hover{background:var(--danger-bg);}

  .content{padding:26px 32px 60px 32px;}
  .view{display:none;}
  .view.active{display:block;}

  /* ---------- Stat cards ---------- */
  .stat-row{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;}
  .stat-card{
    background:var(--paper-raised);
    border:1px solid var(--line);
    border-top:3px solid var(--navy-900);
    border-radius:var(--radius-card);
    padding:18px 18px 16px 18px;
  }
  .stat-card.accent{border-top-color:var(--amber);}
  .stat-card .stat-label{font-size:12px;color:var(--ink-soft);margin-bottom:10px;}
  .stat-card .stat-value{font-family:'IBM Plex Mono';font-size:28px;font-weight:600;color:var(--navy-900);}
  .stat-card .stat-delta{font-size:11.5px;margin-top:6px;color:var(--ok);}
  .stat-card .stat-delta.flat{color:var(--ink-soft);}

  /* ---------- Panels ---------- */
  .panel-grid{display:grid;grid-template-columns:1.5fr 1fr;gap:18px;}
  .panel{
    background:var(--paper-raised);
    border:1px solid var(--line);
    border-radius:var(--radius-card);
    overflow:hidden;
  }
  .panel-head{
    display:flex;align-items:center;justify-content:space-between;
    padding:16px 20px;border-bottom:1px solid var(--line);
  }
  .panel-head h2{font-size:15.5px;font-weight:600;margin:0;color:var(--navy-900);}
  .panel-head .panel-note{font-size:11.5px;color:var(--ink-soft);}
  .panel-body{padding:6px 0;}
  .panel-empty{font-size:12.5px;color:var(--ink-soft);padding:14px 20px;}

  .activity-row{
    display:flex;gap:12px;align-items:flex-start;
    padding:11px 20px;font-size:13px;border-bottom:1px solid #f0f1f6;
  }
  .activity-row:last-child{border-bottom:none;}
  .activity-dot{width:7px;height:7px;border-radius:50%;margin-top:5px;flex:none;}
  .activity-dot.login{background:var(--navy-700);}
  .activity-dot.submit{background:var(--ok);}
  .activity-dot.flag{background:var(--danger);}
  .activity-dot.admin{background:var(--amber);}
  .activity-text{min-width:0;}
  .activity-text strong{font-weight:600;}
  .activity-time{font-size:11px;color:var(--ink-soft);margin-top:2px;}

  .accountlist-row{
    display:flex;align-items:center;justify-content:space-between;
    padding:11px 20px;border-bottom:1px solid #f0f1f6;font-size:13px;
  }
  .accountlist-row:last-child{border-bottom:none;}
  .accountlist-name{font-weight:500;}
  .accountlist-meta{font-size:11.5px;color:var(--ink-soft);margin-top:1px;}
  .accountlist-meta em{display:block;font-style:normal;color:var(--ink-soft);}

  /* ---------- Buttons ---------- */
  .btn{
    font-family:'Inter';font-size:12.5px;font-weight:600;
    padding:9px 15px;border-radius:6px;border:1px solid transparent;
    cursor:pointer;display:inline-flex;align-items:center;gap:7px;
    text-decoration:none;
  }
  .btn-primary{background:var(--navy-900);color:#fff;}
  .btn-primary:hover{background:var(--navy-800);}
  .btn-amber{background:var(--amber);color:var(--navy-950);}
  .btn-amber:hover{background:var(--amber-dim);}
  .btn-ghost{background:transparent;color:var(--navy-900);border:1px solid var(--line);}
  .btn-ghost:hover{background:var(--paper);}
  .btn-sm{padding:6px 10px;font-size:11.5px;}

  /* ---------- Table (User Management) ---------- */
  .toolbar{
    display:flex;align-items:center;justify-content:space-between;
    margin-bottom:16px;gap:14px;flex-wrap:wrap;
  }
  .toolbar-left{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
  .filter-chip{
    font-size:12px;padding:7px 13px;border-radius:20px;
    border:1px solid var(--line);background:var(--paper-raised);color:var(--ink-soft);
    cursor:pointer;
  }
  .filter-chip.active{background:var(--navy-900);color:#fff;border-color:var(--navy-900);}

  table{width:100%;border-collapse:collapse;background:var(--paper-raised);}
  thead th{
    text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;
    color:var(--ink-soft);padding:12px 20px;border-bottom:1px solid var(--line);
    font-weight:600;background:#fafbfd;
  }
  tbody td{padding:13px 20px;font-size:13.5px;border-bottom:1px solid #f0f1f6;vertical-align:middle;}
  tbody tr:hover{background:#fafbfd;}
  .id-cell{font-family:'IBM Plex Mono';font-size:12px;color:var(--ink-soft);}
  .name-cell strong{display:block;font-weight:600;}
  .name-cell span{font-size:11.5px;color:var(--ink-soft);}

  .role-badge{
    font-size:11px;font-weight:600;padding:4px 10px;border-radius:20px;display:inline-block;
  }
  .role-student{background:#e7edf9;color:var(--navy-800);}
  .role-teacher{background:#fbeadd;color:var(--warn);}
  .role-admin{background:#f4e3c1;color:#8a5c0f;}

  .status-badge{font-size:11px;font-weight:600;padding:4px 10px;border-radius:20px;display:inline-flex;align-items:center;gap:5px;}
  .status-active{background:var(--ok-bg);color:var(--ok);}
  .status-inactive{background:var(--danger-bg);color:var(--danger);}
  .status-dot{width:6px;height:6px;border-radius:50%;background:currentColor;}

  .row-actions{display:flex;gap:6px;}
  .icon-btn{
    width:28px;height:28px;border-radius:6px;border:1px solid var(--line);
    background:var(--paper-raised);display:flex;align-items:center;justify-content:center;
    cursor:pointer;color:var(--ink-soft);
  }
  .icon-btn:hover{background:var(--paper);color:var(--navy-900);}

  .table-panel{background:var(--paper-raised);border:1px solid var(--line);border-radius:var(--radius-card);overflow:hidden;}
  .table-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;}
  .table-note{font-size:12px;color:var(--ink-soft);padding:12px 20px;border-top:1px solid var(--line);background:#fafbfd;}

  /* ---------- Reports view ---------- */
  .report-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px;}
  .bar-chart{padding:18px 20px 20px 20px;}
  .bar-row{display:grid;grid-template-columns:110px 1fr 44px;align-items:center;gap:10px;margin-bottom:13px;}
  .bar-row:last-child{margin-bottom:0;}
  .bar-label{font-size:12.5px;color:var(--ink);}
  .bar-track{background:#eef0f6;border-radius:20px;height:9px;overflow:hidden;}
  .bar-fill{height:100%;border-radius:20px;background:var(--navy-800);}
  .bar-fill.amber{background:var(--amber);}
  .bar-value{font-family:'IBM Plex Mono';font-size:12px;color:var(--ink-soft);text-align:right;}

  .integrity-list{padding:4px 0;}
  .integrity-row{display:flex;justify-content:space-between;align-items:center;padding:11px 20px;border-bottom:1px solid #f0f1f6;font-size:13px;}
  .integrity-row:last-child{border-bottom:none;}
  .integrity-count{font-family:'IBM Plex Mono';font-weight:600;color:var(--navy-900);}

  .export-row{display:flex;justify-content:flex-end;gap:10px;margin-bottom:14px;}

  /* ---------- Maintenance view ---------- */
  .maint-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;}
  .maint-card{background:var(--paper-raised);border:1px solid var(--line);border-radius:var(--radius-card);padding:22px;}
  .maint-card h3{font-size:15px;margin:0 0 6px 0;color:var(--navy-900);}
  .maint-card p{font-size:12.5px;color:var(--ink-soft);margin:0 0 16px 0;line-height:1.55;}
  .maint-meta{display:flex;justify-content:space-between;font-size:11.5px;color:var(--ink-soft);margin-top:14px;padding-top:14px;border-top:1px solid var(--line);}
  .maint-meta .mono{color:var(--ink);}

  .toggle-row{display:flex;align-items:center;justify-content:space-between;padding:13px 0;border-bottom:1px solid #f0f1f6;}
  .toggle-row:last-child{border-bottom:none;}
  .toggle-row-text strong{display:block;font-size:13px;}
  .toggle-row-text span{font-size:11.5px;color:var(--ink-soft);}
  .switch{width:38px;height:21px;border-radius:20px;background:var(--line);position:relative;cursor:pointer;flex:none;}
  .switch.on{background:var(--ok);}
  .switch::after{content:'';position:absolute;width:16px;height:16px;border-radius:50%;background:#fff;top:2.5px;left:3px;transition:left .15s ease;}
  .switch.on::after{left:19px;}

  /* ---------- Modal ---------- */
  .modal-backdrop{
    display:none;position:fixed;inset:0;background:rgba(11,23,48,0.55);
    align-items:center;justify-content:center;z-index:50;
  }
  .modal-backdrop.open{display:flex;}
  .modal{
    background:var(--paper-raised);border-radius:6px;width:420px;max-width:92vw;
    padding:26px;box-shadow:0 20px 60px rgba(0,0,0,0.25);
  }
  .modal h3{margin:0 0 4px 0;font-size:17px;color:var(--navy-900);}
  .modal .modal-sub{font-size:12.5px;color:var(--ink-soft);margin-bottom:18px;}
  .form-row{margin-bottom:14px;}
  .form-row label{font-size:12px;font-weight:600;color:var(--ink);display:block;margin-bottom:6px;}
  .form-row select,.form-row input{
    width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:6px;
    font-family:'Inter';font-size:13px;color:var(--ink);background:var(--paper);
  }
  .modal-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:20px;}
  .modal-alert{display:none;background:var(--danger-bg);border:1px solid rgba(179,52,31,0.3);color:var(--danger);
    font-size:12.5px;padding:8px 10px;border-radius:6px;margin-bottom:12px;}
  .modal-alert.show{display:block;}

  /* ---------- Flash banners ---------- */
  .flash-banner{margin:0 0 0;display:flex;align-items:center;gap:10px;
    padding:11px 16px;border-radius:10px;font-size:13px;font-weight:600;
    line-height:1.4;}
  .flash-banner.alert-ok{background:#e5f4ec;color:#1d8a4e;border:1px solid #bfe3cf;}
  .flash-banner.alert-error{background:rgba(196,69,60,0.08);color:#b3411e;border:1px solid rgba(179,52,31,0.3);}
  @keyframes flashFadeOut{0%{opacity:1;transform:translateY(0)}70%{opacity:1;transform:translateY(0)}100%{opacity:0;transform:translateY(-8px)}}
  .flash-banner.auto-hide{animation:flashFadeOut 5s ease forwards;}

  /* ---------- Tag chips (portable admin.css styles) ---------- */
  .tag{
    display:inline-block;font-family:'Inter';font-size:10.5px;font-weight:700;
    letter-spacing:.03em;padding:3px 9px;border-radius:20px;
  }
  .tag-navy{background:var(--navy-900);color:#fff;}
  .tag-royal{background:#fbf3df;color:var(--navy-900);border:1px solid #eed28e;}
  .tag-pass{background:var(--ok-bg);color:var(--ok);}
  .tag-fail{background:var(--danger-bg);color:var(--danger);}
  .tag-dim{background:#eef0f6;color:var(--ink-soft);}
  .tag-arch{background:#e7edf9;color:var(--navy-800);}
  .tag-amber{background:var(--amber);color:var(--navy-950);}

  .status-chip{
    display:inline-flex;align-items:center;gap:6px;font-size:12px;padding:7px 13px;
    border-radius:20px;border:1px solid var(--line);background:var(--paper-raised);
    color:var(--ink-soft);cursor:pointer;white-space:nowrap;
  }
  .status-chip.active{background:var(--navy-900);color:#fff;border-color:var(--navy-900);}
  .chip{
    display:inline-flex;align-items:center;gap:6px;font-family:'Inter';font-size:11.5px;
    padding:6px 11px;border-radius:20px;border:1px solid var(--line);
    color:var(--ink-soft);background:var(--paper-raised);
  }
  .chip b{font-family:'IBM Plex Mono';font-weight:600;color:var(--navy-900);}

  .muted{color:var(--ink-soft);}
  .row-avatar{
    width:32px;height:32px;border-radius:50%;flex:none;background:var(--navy-900);
    color:var(--amber-dim);display:flex;align-items:center;justify-content:center;
    font-size:11.5px;font-weight:600;font-family:'IBM Plex Mono';
  }
  .row-user{display:flex;align-items:center;gap:11px;}
  .check-col{width:34px;}

  /* ---------- Bulk bar (users) ---------- */
  .bulk-bar{
    display:none;align-items:center;gap:8px;flex-wrap:wrap;font-size:12.5px;
    background:var(--paper-raised);border:1px solid var(--line);border-radius:8px;
    padding:9px 13px;margin-bottom:14px;
  }
  .bulk-bar.show{display:flex;}
  .bulk-bar .bulk-count{font-weight:600;color:var(--ink);margin-right:4px;}
  .bulk-bar button{
    font-family:'Inter';font-size:12px;font-weight:600;padding:6px 11px;border-radius:6px;
    border:1px solid var(--line);background:var(--paper-raised);cursor:pointer;color:var(--navy-900);
  }
  .bulk-bar button:hover{background:var(--paper);}
  .bulk-bar button.bulk-danger{color:var(--danger);}
  .bulk-bar .bulk-clear{margin-left:auto;background:none;border:none;color:var(--ink-soft);font-size:12px;cursor:pointer;}
  .bulk-bar .bulk-clear:hover{color:var(--navy-900);}

  /* ---------- Filter bar (forms) ---------- */
  .filter-bar{
    display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px;
  }
  .input{
    font-family:'Inter';font-size:13px;color:var(--ink);
    padding:9px 11px;border:1px solid var(--line);border-radius:6px;background:var(--paper-raised);
  }
  .input:focus{outline:none;border-color:var(--amber);}
  .btn-filter{
    font-family:'Inter';font-size:12.5px;font-weight:600;padding:9px 15px;border-radius:6px;
    border:1px solid var(--line);background:var(--paper-raised);color:var(--navy-900);cursor:pointer;
  }
  .btn-filter:hover{border-color:var(--amber);color:var(--navy-900);}

  /* ---------- Forms in modals ---------- */
  .modal-body-form{display:grid;grid-template-columns:1fr 1fr;gap:12px 14px;}
  .modal-body-form .full{grid-column:1 / -1;}
  .field{margin-bottom:0;}
  .field label{font-size:12px;font-weight:600;color:var(--ink);display:block;margin-bottom:6px;}
  .field .input{width:100%;}

  /* ---------- Roster ---------- */
  .roster-row{
    display:flex;align-items:center;gap:10px;padding:10px 13px;font-size:13px;
    border-bottom:1px solid #f0f1f6;cursor:pointer;
  }
  .roster-row:hover{background:var(--paper);}
  .roster-row input{accent-color:var(--navy-900);}
  .roster-row .muted{font-size:11.5px;}

  /* ---------- Progress / charts (reports) ---------- */
  .progress-track{background:#eef0f6;border-radius:20px;height:9px;overflow:hidden;}
  .progress-fill{height:100%;border-radius:20px;background:linear-gradient(90deg,var(--navy-900),var(--navy-700));}
  .dash-grid{display:grid;grid-template-columns:1.6fr 1fr;gap:18px;align-items:start;}
  .section-gap{margin-bottom:18px;}
  .chart{
    display:flex;align-items:flex-end;gap:5px;height:150px;padding:14px 18px 0;border-bottom:1px solid var(--line);
  }
  .chart-bar{
    flex:1;background:var(--navy-800);border-radius:4px 4px 0 0;min-width:6px;position:relative;
  }
  .chart-bar.zero{background:#e6e8ef;}
  .chart-bar:hover::after{
    content:attr(data-count);position:absolute;top:-22px;left:50%;transform:translateX(-50%);
    background:var(--navy-900);color:#fff;font-family:'IBM Plex Mono';font-size:10px;
    padding:2px 6px;border-radius:4px;white-space:nowrap;
  }
  .chart-x{display:flex;gap:5px;padding:8px 18px 16px;}
  .chart-x span{flex:1;text-align:center;font-family:'IBM Plex Mono';font-size:10px;color:var(--ink-soft);}
  .weak-row{
    display:flex;align-items:center;gap:12px;padding:12px 18px;border-bottom:1px solid #f0f1f6;
  }
  .weak-row:last-child{border-bottom:none;}
  .w-avg{
    font-family:'IBM Plex Mono';font-size:15px;font-weight:600;color:var(--navy-900);flex:none;width:52px;
  }
  .w-body{min-width:0;flex:1;}
  .w-name{font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .w-sub{font-size:11.5px;color:var(--ink-soft);margin-top:1px;}

  /* ---------- Exam detail modal ---------- */
  .ed-questions .qrow{
    display:flex;gap:12px;padding:12px 0;border-bottom:1px solid #f0f1f6;font-size:13px;
  }
  .ed-questions .qrow:last-child{border-bottom:none;}
  .qnum{font-family:'IBM Plex Mono';font-size:11px;color:var(--amber);flex:none;font-weight:600;}
  .qtext{font-weight:500;}
  .correct{color:var(--ok);font-size:12px;}

  /* ---------- Empty state ---------- */
  .empty-state{
    text-align:center;color:var(--ink-soft);font-size:13px;padding:26px 20px;line-height:1.6;
  }
  .empty-state .big{font-size:20px;display:block;margin-bottom:6px;}

  /* ---------- Toolbar (list headers) ---------- */
  .list-toolbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;gap:12px;flex-wrap:wrap;}
  .list-toolbar h2{font-size:19px;font-weight:600;margin:0;color:var(--navy-900);}
  .table-tools{display:flex;justify-content:space-between;align-items:center;padding:12px 20px;border-bottom:1px solid var(--line);font-size:12px;color:var(--ink-soft);}

  /* ---------- Health tiles (maintenance) ---------- */
  .health-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:18px;}
  .health-tile{background:var(--paper-raised);border:1px solid var(--line);border-top:3px solid var(--navy-900);border-radius:var(--radius-card);padding:16px 18px;}
  .health-tile.amber{border-top-color:var(--amber);}
  .health-tile .lbl{font-size:11px;color:var(--ink-soft);text-transform:uppercase;letter-spacing:.05em;}
  .health-tile .num{font-family:'IBM Plex Mono';font-size:17px;font-weight:600;color:var(--navy-900);margin-top:6px;word-break:break-all;}
  .soft-tip{
    background:var(--paper-raised);border:1px solid var(--line);border-left:3px solid var(--amber);
    border-radius:var(--radius-card);padding:16px 18px;
  }
  .soft-tip h4{font-family:'Fraunces',serif;font-size:14.5px;color:var(--navy-900);margin:0 0 6px;}
  .soft-tip p{font-size:12.5px;color:var(--ink-soft);margin:0;line-height:1.6;}

  /* ---------- Mobile nav (hamburger + off-canvas sidebar) ---------- */
  .hamburger{
    display:none;width:36px;height:36px;flex:none;
    border:1px solid var(--line);border-radius:6px;background:var(--paper-raised);
    align-items:center;justify-content:center;cursor:pointer;color:var(--navy-900);
    margin-right:10px;
  }
  .topbar-title{display:flex;align-items:center;min-width:0;}
  .sidebar-overlay{
    display:none;position:fixed;inset:0;background:rgba(11,23,48,0.5);
    z-index:55;
  }

  @media (max-width: 1180px){
    .stat-row{grid-template-columns:repeat(2,1fr);}
  }

  @media (max-width: 880px){
    .shell{grid-template-columns:1fr;}
    .sidebar{
      position:fixed;top:0;left:0;height:100vh;width:250px;
      transform:translateX(-100%);
      transition:transform .22s ease;
      z-index:60;
    }
    .sidebar.open{transform:translateX(0);}
    .sidebar-overlay.open{display:block;}
    .hamburger{display:flex;}
    .content{padding:20px 18px 50px 18px;}
    .topbar{padding:14px 18px;flex-wrap:wrap;gap:12px;}
    .topbar-right{gap:12px;}
    .panel-grid,.report-grid,.maint-grid{grid-template-columns:1fr;}
  }

  @media (max-width: 680px){
    .topbar h1{font-size:18px;}
    .topbar .page-sub{display:none;}
    .search-box{display:none;}
    .stat-row{grid-template-columns:repeat(2,1fr);gap:10px;}
    .stat-card{padding:14px 14px 12px 14px;}
    .stat-card .stat-value{font-size:22px;}
    .content{padding:16px 14px 44px 14px;}
    .toolbar{flex-direction:column;align-items:stretch;}
    .toolbar-left{flex-wrap:wrap;}
    .export-row{justify-content:flex-start;flex-wrap:wrap;}
    .maint-card button.btn{margin-bottom:8px;}
  }

  @media (max-width: 460px){
    .stat-row{grid-template-columns:1fr;}
    .admin-chip .chip-text{display:none;}
    .admin-chip{padding:6px;}
    .admin-dropdown{right:-6px;}
    .modal{padding:20px;}
  }
</style>
</head>
<body>

<div class="sidebar-overlay" id="sidebar-overlay" onclick="toggleSidebar()"></div>

<div class="shell">

  <?php if (!empty($_SESSION['flash'])): ?>
  <div style="padding:18px 18px 0;">
    <?php admin_flash_display(); ?>
  </div>
  <?php endif; ?>

  <!-- ================= SIDEBAR ================= -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <img src="assets/rmc-seal.jpg" alt="Regis Marie College seal">
      <div class="brand-text">
        <div class="brand-word">RMC Quiz &amp; Examination System</div>
        <div class="brand-sub">Admin Portal</div>
      </div>
    </div>

    <div class="sidebar-section-label">Overview</div>
    <ul class="nav-list">
      <li class="nav-item active" data-view="dashboard">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
        Dashboard
      </li>
    </ul>

    <div class="sidebar-section-label">Management</div>
    <ul class="nav-list">
      <li class="nav-item" data-view="users">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        User Management
      </li>
      <li class="nav-item" data-view="classes">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
        Class Management
      </li>
      <li class="nav-item" data-view="assessments">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 2v4"/><path d="M16 2v4"/><path d="M3 9h18"/><path d="m9 14 2 2 4-4"/></svg>
        Assessments
      </li>
      <li class="nav-item" data-view="reports">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>
        System-Wide Reports
      </li>
      <li class="nav-item" data-view="logs">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6"/><path d="M9 17h6"/></svg>
        System Logs
      </li>
      <li class="nav-item" data-view="maintenance">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
        System Maintenance
      </li>
    </ul>

    <div class="sidebar-foot">
      <strong>Regis Marie College</strong>
      Institutional record · S.Y. 2026&ndash;2027
    </div>
  </aside>

  <!-- ================= MAIN ================= -->
  <div class="main">

    <!-- ============ DASHBOARD VIEW ============ -->
    <div class="view active" id="view-dashboard">
      <div class="topbar">
        <div class="topbar-title">
          <div class="hamburger" onclick="toggleSidebar()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
          </div>
          <div>
          <h1>Dashboard</h1>
          <div class="page-sub">System-wide snapshot across all classes and sections</div>
        </div>
        </div>
        <div class="topbar-right">
          <div class="search-box">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            <input type="text" placeholder="Search records">
          </div>
          <div class="admin-menu">
            <div class="admin-chip" onclick="toggleAdminMenu(this)">
              <div class="avatar"><?php echo e($avatarChar); ?></div>
              <div class="chip-text"><strong><?php echo e($adminShort); ?></strong><span>Administrator</span></div>
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="chip-caret"><polyline points="6 9 12 15 18 9"/></svg>
            </div>
            <div class="admin-dropdown">
              <div class="admin-dropdown-divider"></div>
              <a class="admin-dropdown-item logout" href="logout.php">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Log Out
              </a>
            </div>
          </div>
        </div>
      </div>

      <div class="content">
        <div class="stat-row">
          <div class="stat-card">
            <div class="stat-label">Total Students</div>
            <div class="stat-value"><?php echo number_format($totalStudents); ?></div>
            <div class="stat-delta<?php echo $newStudents7d > 0 ? '' : ' flat'; ?>"><?php echo $newStudents7d > 0 ? '+' . $newStudents7d . ' enrolled this week' : 'No change this week'; ?></div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Total Teachers</div>
            <div class="stat-value"><?php echo number_format($totalTeachers); ?></div>
            <div class="stat-delta<?php echo $newTeachers7d > 0 ? '' : ' flat'; ?>"><?php echo $newTeachers7d > 0 ? '+' . $newTeachers7d . ' new this week' : 'No change this week'; ?></div>
          </div>
          <div class="stat-card accent">
            <div class="stat-label">Active Accounts</div>
            <div class="stat-value"><?php echo number_format($activeCount); ?></div>
            <div class="stat-delta"><?php echo e($activePct); ?>% of all accounts</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Inactive Accounts</div>
            <div class="stat-value"><?php echo number_format($inactiveCount); ?></div>
            <div class="stat-delta flat">Awaiting reactivation</div>
          </div>
        </div>

        <div class="panel-grid">
          <div class="panel">
            <div class="panel-head">
              <h2>System Activity Log</h2>
              <span class="panel-note">Logins &middot; submissions &middot; account changes</span>
            </div>
            <div class="panel-body">
              <?php foreach ($activityRows as $row): ?>
                <div class="activity-row">
                  <div class="activity-dot <?php echo e($row['dot']); ?>"></div>
                  <div class="activity-text">
                    <div><?php echo $row['text']; ?></div>
                    <div class="activity-time"><?php echo e($row['time']); ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
              <?php if (!count($activityRows)): ?>
                <div class="panel-empty">No activity recorded yet.</div>
              <?php endif; ?>
            </div>
          </div>

          <div class="panel">
            <div class="panel-head">
              <h2>Recent Account Changes</h2>
              <span class="panel-note">Latest admin actions</span>
            </div>
            <div class="panel-body">
              <?php foreach ($accountRows as $row): ?>
                <div class="accountlist-row">
                  <div>
                    <div class="accountlist-name"><?php echo e($row['name']); ?></div>
                    <div class="accountlist-meta"><span><?php echo e($row['meta']); ?></span><em><?php echo e($row['time']); ?></em></div>
                  </div>
                  <span class="status-badge <?php echo $row['active'] ? 'status-active' : 'status-inactive'; ?>"><span class="status-dot"></span><?php echo $row['active'] ? 'Active' : 'Inactive'; ?></span>
                </div>
              <?php endforeach; ?>
              <?php if (!count($accountRows)): ?>
                <div class="panel-empty">No account changes recorded yet.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ============ USER MANAGEMENT VIEW ============ -->
    <div class="view" id="view-users">
      <div class="topbar">
        <div class="topbar-title">
          <div class="hamburger" onclick="toggleSidebar()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
          </div>
          <div>
          <h1>User Management</h1>
          <div class="page-sub">Create, edit, and deactivate student, teacher, and admin accounts</div>
        </div>
        </div>
        <div class="topbar-right">
          <div class="admin-menu">
            <div class="admin-chip" onclick="toggleAdminMenu(this)">
              <div class="avatar"><?php echo e($avatarChar); ?></div>
              <div class="chip-text"><strong><?php echo e($adminShort); ?></strong><span>Administrator</span></div>
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="chip-caret"><polyline points="6 9 12 15 18 9"/></svg>
            </div>
            <div class="admin-dropdown">
              <div class="admin-dropdown-divider"></div>
              <a class="admin-dropdown-item logout" href="logout.php">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Log Out
              </a>
            </div>
          </div>
        </div>
      </div>

      <div class="content">
        <div class="toolbar">
          <div class="toolbar-left">
            <div class="search-box" style="width:260px;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
              <input type="text" placeholder="Search by name or ID" id="userSearch">
            </div>
            <span class="filter-chip active" data-filter="All">All</span>
            <span class="filter-chip" data-filter="STUDENT">Students</span>
            <span class="filter-chip" data-filter="TEACHER">Teachers</span>
            <span class="filter-chip" data-filter="ADMIN">Admins</span>
          </div>
          <button class="btn btn-amber" id="btnAddUser" type="button">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14"/></svg>
            Create User
          </button>
        </div>

        <div class="bulk-bar" id="bulkBar">
          <span class="bulk-count" id="bulkCount">0 selected</span>
          <button type="button" class="bulk-status" data-bulk="ACTIVE">Activate</button>
          <button type="button" class="bulk-status" data-bulk="INACTIVE">Suspend</button>
          <button type="button" class="bulk-status bulk-danger" data-bulk="BANNED">Ban</button>
          <button type="button" class="bulk-clear" id="btnBulkClear">Clear selection</button>
        </div>

        <div class="table-panel">
          <div class="table-scroll">
          <table id="userTable">
            <thead>
              <tr>
                <th class="check-col"><input type="checkbox" id="chkAll" title="Select all on this page"></th>
                <th>User</th>
                <th>Role</th>
                <th>Section / Assignment</th>
                <th>Status</th>
                <th>Created</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($users as $u): ?>
                <?php
                  $roleName = strtoupper((string) ($u['role_name'] ?? ''));
                  $roleCls  = $roleName === 'STUDENT' ? 'role-student' : ($roleName === 'TEACHER' ? 'role-teacher' : 'role-admin');
                  $status   = strtoupper((string) ($u['status'] ?? 'ACTIVE'));
                  $statusCls = $status === 'ACTIVE' ? 'status-active' : 'status-inactive';
                  $idDisp   = $u['student_id'] !== '' && $u['student_id'] !== null
                              ? $u['student_id']
                              : ($roleName === 'ADMIN' ? 'ADM-' . str_pad((string) $u['user_id'], 3, '0', STR_PAD_LEFT) : '@' . $u['username']);
                  $assign   = $roleName === 'STUDENT'
                              ? trim(($u['year_level'] ?? '') . ' – ' . ($u['section'] ?? ''), ' –')
                              : ($roleName === 'TEACHER' ? 'Teaching faculty' : 'Office of the Registrar');
                  $created  = date('M j, Y', strtotime((string) ($u['created_at'] ?? 'now')));
                ?>
                <tr data-id="<?php echo (int) $u['user_id']; ?>" data-role="<?php echo e($roleName); ?>" data-status="<?php echo e($status); ?>" data-name="<?php echo e(strtolower(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')))); ?>">
                  <td class="check-col">
                    <input type="checkbox" class="row-check" value="<?php echo (int) $u['user_id']; ?>"
                           <?php echo (int) $u['user_id'] === $self_id ? 'disabled title="This is you"' : ''; ?>>
                  </td>
                  <td class="name-cell">
                    <div class="row-user">
                      <div class="row-avatar"><?php echo e(strtoupper(mb_substr((string) ($u['first_name'] ?? 'S'), 0, 1) . mb_substr((string) ($u['last_name'] ?? 'T'), 0, 1))); ?></div>
                      <div>
                        <strong><?php echo e(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))); ?></strong>
                        <span>@<?php echo e($u['username'] ?? ''); ?> · <?php echo e($u['email'] ?? ''); ?></span>
                      </div>
                    </div>
                  </td>
                  <td><span class="role-badge <?php echo $roleCls; ?>"><?php echo e($roleName); ?></span></td>
                  <td class="id-cell" style="font-size:12.5px;color:var(--ink);"><?php echo e($assign); ?></td>
                  <td><span class="status-badge <?php echo $statusCls; ?>"><span class="status-dot"></span><?php echo e(ucfirst(strtolower($status))); ?></span></td>
                  <td class="mono" style="font-size:12px;color:var(--ink-soft);"><?php echo e($created); ?></td>
                  <td>
                    <div class="row-actions">
                      <button class="icon-btn" type="button" data-act="edit" title="Edit">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5Z"/></svg>
                      </button>
                      <?php if ($status === 'ACTIVE'): ?>
                        <button class="icon-btn" type="button" data-act="suspend" title="Suspend">
                          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 4v16"/><path d="M18 4v16"/></svg>
                        </button>
                        <button class="icon-btn" type="button" data-act="ban" title="Ban" style="color:var(--danger);">
                          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="m5 5 14 14"/></svg>
                        </button>
                      <?php elseif ($status === 'INACTIVE'): ?>
                        <button class="icon-btn" type="button" data-act="activate" title="Activate">
                          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 12 5 5 9-10"/></svg>
                        </button>
                        <button class="icon-btn" type="button" data-act="ban" title="Ban" style="color:var(--danger);">
                          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="m5 5 14 14"/></svg>
                        </button>
                      <?php else: ?>
                        <button class="icon-btn" type="button" data-act="activate" title="Reactivate">
                          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 12 5 5 9-10"/></svg>
                        </button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!count($users)): ?>
                <tr><td colspan="7" class="id-cell" style="text-align:center;padding:26px;font-size:13px;color:var(--ink-soft);">No users found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
          </div>
<div class="table-note"><?php echo number_format(count($users)); ?> account(s) shown · admins cannot be banned by checkbox (this is you — use row actions)</div>
        </div>

        <div class="table-panel">
          <div style="padding:18px 20px;">
            <h3 style="margin:0 0 4px;">Import Roster</h3>
            <div style="font-size:13px;color:var(--ink-soft);margin-bottom:14px;">
              Upload the registrar's student list (LRNs) or HR employee list so students and teachers can
              register and auto-activate through the app. Re-importing a file updates existing entries and adds new rows.
            </div>
            <div class="form-row" style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;">
              <div class="field" style="flex:1;min-width:220px;margin:0;">
                <label>Student roster (.csv) &mdash; columns: <span class="mono">lrn, full_name, program</span></label>
                <input class="input" type="file" id="studentRosterFile" accept=".csv,text/csv">
              </div>
              <button class="btn btn-amber" type="button" id="btnImportStudents">Import student roster</button>
            </div>
            <div class="form-row" style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;margin-top:12px;">
              <div class="field" style="flex:1;min-width:220px;margin:0;">
                <label>Teacher roster (.csv) &mdash; columns: <span class="mono">employee_number, full_name, department</span></label>
                <input class="input" type="file" id="teacherRosterFile" accept=".csv,text/csv">
              </div>
              <button class="btn btn-amber" type="button" id="btnImportTeachers">Import teacher roster</button>
            </div>
            <div class="modal-alert" id="rosterImportAlert"></div>
            <div class="mono" id="rosterImportSummary" style="margin-top:8px;font-size:12.5px;color:var(--ink-soft);"></div>
          </div>
        </div>

      </div>

    <!-- ============ CLASS MANAGEMENT VIEW ============ -->
    <div class="view" id="view-classes">
      <div class="topbar">
        <div class="topbar-title">
          <div class="hamburger" onclick="toggleSidebar()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
          </div>
          <div>
          <h1>Class Management</h1>
          <div class="page-sub">Classes, sections, teacher assignments, and roster management</div>
        </div>
        </div>
        <div class="topbar-right">
          <div class="admin-menu">
            <div class="admin-chip" onclick="toggleAdminMenu(this)">
              <div class="avatar"><?php echo e($avatarChar); ?></div>
              <div class="chip-text"><strong><?php echo e($adminShort); ?></strong><span>Administrator</span></div>
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="chip-caret"><polyline points="6 9 12 15 18 9"/></svg>
            </div>
            <div class="admin-dropdown">
              <div class="admin-dropdown-divider"></div>
              <a class="admin-dropdown-item logout" href="logout.php">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Log Out
              </a>
            </div>
          </div>
        </div>
      </div>

      <div class="content">
        <div class="toolbar">
          <div class="toolbar-left">
            <div class="search-box" style="width:260px;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
              <input type="text" placeholder="Search subject, code, block…" id="classSearch">
            </div>
            <span class="filter-chip cls-chip active" data-filter="All">All</span>
            <span class="filter-chip cls-chip" data-filter="ACTIVE">Active</span>
            <span class="filter-chip cls-chip" data-filter="ARCHIVED">Archived</span>
            <span class="muted mono" style="font-size:11.5px;"><?php echo (int) $classStatus['total'] ?> total · <?php echo (int) $classStatus['active'] ?> active · <?php echo (int) $classStatus['archived'] ?> archived</span>
          </div>
          <button class="btn btn-amber" id="btnAddClass" type="button">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14"/></svg>
            Add Class
          </button>
        </div>

        <div class="table-panel">
          <div class="table-scroll">
          <table id="classTable">
            <thead>
              <tr>
                <th>Class</th>
                <th>Teacher</th>
                <th>Enrolled</th>
                <th>Assessments</th>
                <th>Status</th>
                <th>Created</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($classesAll as $c): ?>
                <?php
                  $subject = e(trim(($c['subject_name'] ?? '') . ' · ' . ($c['subject_code'] ?? '')));
                  $sub     = e(trim(($c['class_code'] ?? '') . ' · ' . ($c['block'] ?? '')));
                  $teacher = trim(($c['teacher_first_name'] ?? '') . ' ' . ($c['teacher_last_name'] ?? ''));
                  $created = e(date('M j, Y', strtotime($c['created_at'] ?? 'now')));
                ?>
                <tr data-id="<?php echo (int) $c['class_id']; ?>" data-status="<?php echo e($c['status'] ?? 'ACTIVE'); ?>" data-name="<?php echo e(strtolower(trim(($c['subject_name'] ?? '') . ' ' . ($c['class_code'] ?? '') . ' ' . ($c['block'] ?? '') . ' ' . $teacher))); ?>">
                  <td style="min-width:200px;">
                    <div><strong><?php echo $subject; ?></strong></div>
                    <div style="font-size:11.5px;color:var(--ink-soft);"><?php echo $sub; ?></div>
                  </td>
                  <td><?php echo $teacher ? e($teacher) : '<span style="color:var(--ink-soft);">—</span>'; ?></td>
                  <td class="mono"><?php echo (int) ($c['enrolled_count'] ?? 0); ?> students</td>
                  <td class="mono"><?php echo (int) ($c['exam_count'] ?? 0); ?> exams</td>
                  <td><span class="tag <?php echo ($c['status'] ?? '') === 'ARCHIVED' ? 'tag-dim' : 'tag-pass'; ?>"><?php echo e($c['status'] ?? 'ACTIVE'); ?></span></td>
                  <td class="mono" style="font-size:12px;color:var(--ink-soft);"><?php echo $created; ?></td>
                  <td>
                    <div class="row-actions">
                      <button class="icon-btn" type="button" data-act="roster" title="Manage roster">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                      </button>
                      <button class="icon-btn" type="button" data-act="edit" title="Edit">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5Z"/></svg>
                      </button>
                      <button class="icon-btn<?php echo ($c['status'] ?? '') !== 'ARCHIVED' ? '" style="color:var(--danger);' : ''; ?>" type="button" data-act="archive" title="<?php echo ($c['status'] ?? '') === 'ARCHIVED' ? 'Restore' : 'Archive'; ?>">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18l-1.2 12.4a2 2 0 0 1-2 1.6H6.2a2 2 0 0 1-2-1.6L3 6z"/><path d="M10 11h4"/><path d="M6 3h12"/></svg>
                      </button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!count($classesAll)): ?>
                <tr><td colspan="7" class="id-cell" style="text-align:center;padding:26px;font-size:13px;color:var(--ink-soft);">No classes found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
          </div>
          <div class="table-note"><?php echo number_format(count($classesAll)); ?> class(es) shown · enrollments and exam records are preserved when a class is archived</div>
        </div>
      </div>
    </div>

    <!-- ============ ASSESSMENTS VIEW ============ -->
    <div class="view" id="view-assessments">
      <div class="topbar">
        <div class="topbar-title">
          <div class="hamburger" onclick="toggleSidebar()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
          </div>
          <div>
          <h1>Assessment Oversight</h1>
          <div class="page-sub">Exams, scheduling, force-close, and per-assessment drill-down</div>
        </div>
        </div>
        <div class="topbar-right">
          <div class="admin-menu">
            <div class="admin-chip" onclick="toggleAdminMenu(this)">
              <div class="avatar"><?php echo e($avatarChar); ?></div>
              <div class="chip-text"><strong><?php echo e($adminShort); ?></strong><span>Administrator</span></div>
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="chip-caret"><polyline points="6 9 12 15 18 9"/></svg>
            </div>
            <div class="admin-dropdown">
              <div class="admin-dropdown-divider"></div>
              <a class="admin-dropdown-item logout" href="logout.php">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Log Out
              </a>
            </div>
          </div>
        </div>
      </div>

      <div class="content">
        <div class="toolbar">
          <div class="toolbar-left" style="gap:8px;">
            <span class="filter-chip ex-chip active" data-filter="All">All</span>
            <span class="filter-chip ex-chip" data-filter="LIVE">LIVE · <?php echo (int) $examStatusCounts['LIVE']; ?></span>
            <span class="filter-chip ex-chip" data-filter="SCHEDULED">SCHEDULED · <?php echo (int) $examStatusCounts['SCHEDULED']; ?></span>
            <span class="filter-chip ex-chip" data-filter="DRAFT">DRAFT · <?php echo (int) $examStatusCounts['DRAFT']; ?></span>
            <span class="filter-chip ex-chip" data-filter="CLOSED">CLOSED · <?php echo (int) $examStatusCounts['CLOSED']; ?></span>
            <span class="filter-chip ex-chip" data-filter="ARCHIVED">ARCHIVED · <?php echo (int) $examStatusCounts['ARCHIVED']; ?></span>
          </div>
          <div class="search-box" style="width:230px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            <input type="text" placeholder="Search exam or subject…" id="examSearch">
          </div>
        </div>

        <div class="table-panel">
          <div class="table-scroll">
          <table id="examTable">
            <thead>
              <tr>
                <th>Assessment</th>
                <th>Class</th>
                <th>Schedule</th>
                <th>Meta</th>
                <th>Submissions / Avg</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($examsAll as $e): ?>
                <?php
                  $schedule = ($e['start_time'] && $e['end_time'])
                      ? e(date('M j, g:i A', strtotime($e['start_time'])) . ' → ' . date('g:i A', strtotime($e['end_time'])))
                      : '<span style="color:var(--ink-soft);">not scheduled</span>';
                  $avg = ((int) ($e['submission_count'] ?? 0) > 0 && ($e['avg_pct'] ?? null) !== null)
                      ? number_format((float) $e['avg_pct'], 1) . '%'
                      : '—';
                  $st = $e['status'] ?? 'DRAFT';
                  $tagCls = $st === 'LIVE' ? 'tag-pass' : ($st === 'SCHEDULED' ? 'tag-royal' : ($st === 'CLOSED' ? 'tag-navy' : ($st === 'ARCHIVED' ? 'tag-arch' : 'tag-dim')));
                ?>
                <tr data-id="<?php echo (int) $e['exam_id']; ?>" data-status="<?php echo e($st); ?>" data-name="<?php echo e(strtolower(trim(($e['exam_name'] ?? '') . ' ' . ($e['subject_code'] ?? '') . ' ' . ($e['subject_name'] ?? '') . ' ' . ($e['block'] ?? '')))); ?>">
                  <td style="min-width:190px;">
                    <div><strong><?php echo e($e['exam_name'] ?? ''); ?></strong></div>
                    <div style="font-size:11.5px;color:var(--ink-soft);"><?php echo e(trim(($e['subject_code'] ?? '') . ' · ' . ($e['block'] ?? ''))); ?></div>
                  </td>
                  <td><?php echo e($e['subject_name'] ?? ''); ?></td>
                  <td style="font-size:12px;"><?php echo $schedule; ?></td>
                  <td>
                    <span class="mono"><?php echo (int) ($e['question_count'] ?? 0); ?></span> q · <span class="mono"><?php echo (int) ($e['points_count'] ?? 0); ?></span> pts<br>
                    <span style="font-size:11px;color:var(--ink-soft);">pass <?php echo (int) ($e['passing_score'] ?? 0); ?>%</span>
                  </td>
                  <td><span class="mono"><?php echo number_format((int) ($e['submission_count'] ?? 0)); ?></span> · avg <span class="mono"><?php echo $avg; ?></span></td>
                  <td><span class="tag <?php echo $tagCls; ?>"><?php echo e($st); ?></span></td>
                  <td>
                    <div class="row-actions">
                      <button class="icon-btn" type="button" data-act="review" title="Review">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>
                      </button>
                      <?php if ($st === 'LIVE'): ?>
                        <button class="icon-btn" type="button" data-act="force_close" title="Force close" style="color:var(--danger);">
                          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="m5 5 14 14"/></svg>
                        </button>
                      <?php endif; ?>
                      <?php if (in_array($st, ['DRAFT', 'CLOSED', 'ARCHIVED'], true)): ?>
                        <button class="icon-btn" type="button" data-act="schedule" title="<?php echo $st === 'ARCHIVED' ? 'Restore &amp; schedule' : 'Schedule'; ?>">
                          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 2v4"/><path d="M16 2v4"/><path d="M3 9h18"/></svg>
                        </button>
                      <?php endif; ?>
                      <?php if ($st !== 'ARCHIVED'): ?>
                        <button class="icon-btn" type="button" data-act="archive" title="Archive">
                          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18l-1.2 12.4a2 2 0 0 1-2 1.6H6.2a2 2 0 0 1-2-1.6L3 6z"/><path d="M10 11h4"/><path d="M6 3h12"/></svg>
                        </button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!count($examsAll)): ?>
                <tr><td colspan="7" class="id-cell" style="text-align:center;padding:26px;font-size:13px;color:var(--ink-soft);">No assessments found. Exams are created by teachers in the mobile app.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
          </div>
          <div class="table-note"><?php echo number_format(count($examsAll)); ?> assessment(s) shown · click the eye icon to drill into questions and per-student submissions</div>
        </div>
      </div>
    </div>

    <!-- ============ SYSTEM LOGS VIEW ============ -->
    <div class="view" id="view-logs">
      <div class="topbar">
        <div class="topbar-title">
          <div class="hamburger" onclick="toggleSidebar()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
          </div>
          <div>
          <h1>System Logs</h1>
          <div class="page-sub">Audit trail from activity_logs — admin actions and sign-in activity</div>
        </div>
        </div>
        <div class="topbar-right">
          <div class="admin-menu">
            <div class="admin-chip" onclick="toggleAdminMenu(this)">
              <div class="avatar"><?php echo e($avatarChar); ?></div>
              <div class="chip-text"><strong><?php echo e($adminShort); ?></strong><span>Administrator</span></div>
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="chip-caret"><polyline points="6 9 12 15 18 9"/></svg>
            </div>
            <div class="admin-dropdown">
              <div class="admin-dropdown-divider"></div>
              <a class="admin-dropdown-item logout" href="logout.php">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Log Out
              </a>
            </div>
          </div>
        </div>
      </div>

      <div class="content">
        <div class="toolbar">
          <div class="toolbar-left" style="gap:8px;">
            <span class="filter-chip log-chip active" data-filter="All">All</span>
            <?php foreach ($auditSummary as $s): ?>
              <span class="filter-chip log-chip" data-filter="<?php echo e($s['action']); ?>"><?php echo e($s['action']); ?> · <?php echo (int) $s['cnt']; ?></span>
            <?php endforeach; ?>
          </div>
          <div class="toolbar-left" style="margin-top:8px;flex:1;justify-content:flex-end;">
            <div class="search-box" style="width:230px;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
              <input type="text" placeholder="Search actor or description…" id="logSearch">
            </div>
            <input class="input" type="date" id="logFrom" aria-label="From date" style="width:155px;">
            <input class="input" type="date" id="logTo" aria-label="To date" style="width:155px;">
          </div>
        </div>

        <div class="table-panel">
          <div class="table-tools"><span><?php echo number_format(count($auditLogs)); ?> log entr<?php echo count($auditLogs) === 1 ? 'y' : 'ies'; ?> shown</span><span class="muted">most recent first</span></div>
          <div class="table-scroll">
          <table id="logTable">
            <thead>
              <tr>
                <th>When</th>
                <th>Actor</th>
                <th>Action</th>
                <th>Description</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($auditLogs as $l): ?>
                <?php
                  $logAction = strtoupper((string) ($l['action'] ?? ''));
                  $actor = trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? ''));
                  $actor = $actor !== '' ? $actor : ($l['username'] ?? 'system');
                ?>
                <tr data-action="<?php echo e($logAction); ?>" data-when="<?php echo e(date('Y-m-d', strtotime($l['created_at']))); ?>" data-search="<?php echo e(strtolower($actor . ' ' . ($l['description'] ?? '') . ' ' . ($l['username'] ?? ''))); ?>">
                  <td class="mono" style="white-space:nowrap;font-size:12px;color:var(--ink-soft);"><?php echo e(date('M j, Y  g:i A', strtotime($l['created_at']))); ?></td>
                  <td>
                    <div><strong style="font-weight:600;"><?php echo e($actor); ?></strong></div>
                    <?php if ($l['username']): ?><div style="font-size:11px;color:var(--ink-soft);">@<?php echo e($l['username']); ?></div><?php endif; ?>
                  </td>
                  <td><span class="tag tag-royal"><?php echo e($logAction); ?></span></td>
                  <td style="color:var(--ink-soft);max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo e($l['description'] ?? ''); ?>"><?php echo e($l['description'] ?? ''); ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!count($auditLogs)): ?>
                <tr><td colspan="4" class="id-cell" style="text-align:center;padding:26px;font-size:13px;color:var(--ink-soft);">No log entries found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
          </div>
        </div>
      </div>
    </div>

    <!-- ============ REPORTS VIEW ============ -->
    <div class="view" id="view-reports">
      <div class="topbar">
        <div class="topbar-title">
          <div class="hamburger" onclick="toggleSidebar()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
          </div>
          <div>
          <h1>System-Wide Reports</h1>
          <div class="page-sub">Institution-level performance across subjects, sections, and academic periods</div>
        </div>
        </div>
        <div class="topbar-right">
          <div class="admin-menu">
            <div class="admin-chip" onclick="toggleAdminMenu(this)">
              <div class="avatar"><?php echo e($avatarChar); ?></div>
              <div class="chip-text"><strong><?php echo e($adminShort); ?></strong><span>Administrator</span></div>
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="chip-caret"><polyline points="6 9 12 15 18 9"/></svg>
            </div>
            <div class="admin-dropdown">
              <div class="admin-dropdown-divider"></div>
              <a class="admin-dropdown-item logout" href="logout.php">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Log Out
              </a>
            </div>
          </div>
        </div>
      </div>

      <div class="content">
        <div class="stat-row" style="margin-top:4px;">
          <div class="stat-card">
            <div class="stat-label">Overall Average</div>
            <div class="stat-value" style="font-size:22px;"><?php echo $overall ? number_format((float) $overall['avg_pct'], 1) . '%' : '—'; ?></div>
            <div class="stat-delta flat">across <?php echo $overall ? (int) $overall['exam_count'] : 0; ?> assessments</div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Pass Rate</div>
            <div class="stat-value" style="font-size:22px;"><?php echo $overall ? number_format((float) $overall['pass_rate'], 1) . '%' : '—'; ?></div>
            <div class="stat-delta flat"><?php echo $overall ? (int) $overall['pass_count'] . ' of ' . (int) $overall['submission_count'] . ' submissions passed' : ''; ?></div>
          </div>
          <div class="stat-card accent">
            <div class="stat-label">Submissions</div>
            <div class="stat-value" style="font-size:22px;"><?php echo $overall ? number_format((int) $overall['submission_count']) : '—'; ?></div>
            <div class="stat-delta flat"><?php echo $overall ? number_format((int) $overall['attempts_users']) . ' distinct students' : ''; ?></div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Exam Footprint</div>
            <div class="stat-value" style="font-size:22px;"><?php echo $overall ? (int) $overall['exam_count'] : '—'; ?></div>
            <div class="stat-delta flat"><?php echo $overall ? (int) $overall['active_classes'] . ' active classes' : ''; ?></div>
          </div>
        </div>

        <div class="report-grid">
          <div class="panel bar-chart">
            <div class="panel-head" style="padding:0 0 14px 0;border:none;">
              <h2>Average Score by Subject</h2>
              <span class="panel-note">Live derived percentage</span>
            </div>
            <?php foreach ($subjectBars as $b): ?>
              <div class="bar-row"><div class="bar-label"><?php echo e($b['label']); ?></div><div class="bar-track"><div class="bar-fill<?php echo $b['amber'] ? ' amber' : ''; ?>" style="width:<?php echo (int) $b['value']; ?>%"></div></div><div class="bar-value"><?php echo (int) $b['value']; ?>%</div></div>
            <?php endforeach; ?>
            <?php if (!count($subjectBars)): ?><div class="panel-empty">No exam data yet.</div><?php endif; ?>
          </div>

          <div class="panel bar-chart">
            <div class="panel-head" style="padding:0 0 14px 0;border:none;">
              <h2>Pass Rate by Section</h2>
              <span class="panel-note">Exams passed vs. submitted</span>
            </div>
            <?php foreach ($sectionBars as $b): ?>
              <div class="bar-row"><div class="bar-label"><?php echo e($b['label']); ?></div><div class="bar-track"><div class="bar-fill<?php echo $b['amber'] ? ' amber' : ''; ?>" style="width:<?php echo (int) $b['value']; ?>%"></div></div><div class="bar-value"><?php echo (int) $b['value']; ?>%</div></div>
            <?php endforeach; ?>
            <?php if (!count($sectionBars)): ?><div class="panel-empty">No exam data yet.</div><?php endif; ?>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head">
            <h2>Assessment Integrity Events</h2>
            <span class="panel-note">7-day window</span>
          </div>
          <div class="integrity-list">
            <div class="integrity-row"><span>Tab-switch / app-exit attempts detected</span><span class="integrity-count"><?php echo number_format($exit7d); ?></span></div>
            <div class="integrity-row"><span>Exam auto-submitted after time expired</span><span class="integrity-count"><?php echo number_format($auto7d); ?></span></div>
            <div class="integrity-row"><span>Integrity flags (last 24 hours)</span><span class="integrity-count"><?php echo number_format($flag24h); ?></span></div>
            <div class="integrity-row"><span>Total integrity flags recorded (7 days)</span><span class="integrity-count"><?php echo number_format($flagged7d); ?></span></div>
          </div>
        </div>

        <div class="panel section-gap" style="padding:0 0 8px;">
          <div class="panel-head"><h2>Class Averages</h2><span class="panel-note">avg % and pass rate per class</span></div>
          <?php if (empty($reportMeta['classes'])): ?>
            <div class="panel-empty">No classes to report on yet.</div>
          <?php else: ?>
            <div class="table-scroll">
              <table>
                <thead>
                  <tr><th>Class</th><th style="width:34%;">Average</th><th>Submissions</th><th>Pass Rate</th></tr>
                </thead>
                <tbody>
                  <?php foreach ($reportMeta['classes'] as $c): ?>
                    <?php
                      $pct = (float) ($c['avg_pct'] ?? 0);
                      $pr  = (float) ($c['pass_rate'] ?? 0);
                      $prCls = $pr >= 60 ? 'tag-pass' : ($pr >= 40 ? 'tag-royal' : 'tag-fail');
                    ?>
                    <tr>
                      <td>
                        <div><strong style="font-weight:600;"><?php echo e(trim(($c['subject_name'] ?? '') . ' · ' . ($c['subject_code'] ?? ''))); ?></strong></div>
                        <div style="font-size:11.5px;color:var(--ink-soft);"><?php echo e(trim(($c['block'] ?? '') . ' · ' . (int) ($c['exam_count'] ?? 0) . ' exam(s)')); ?></div>
                      </td>
                      <td>
                        <div style="display:flex;align-items:center;gap:10px;">
                          <div class="progress-track" style="flex:1;"><div class="progress-fill" style="width:<?php echo min(100, $pct); ?>%;"></div></div>
                          <span class="mono" style="font-size:12.5px;"><?php echo number_format($pct, 1); ?>%</span>
                        </div>
                      </td>
                      <td class="mono"><?php echo (int) ($c['submission_count'] ?? 0); ?></td>
                      <td><span class="tag <?php echo $prCls; ?>"><?php echo number_format($pr, 1); ?>%</span></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <div class="panel section-gap">
          <div class="panel-head"><h2>Submissions — last 14 days</h2><span class="panel-note"><?php echo number_format(array_sum(array_column($daily, 'count'))); ?> total</span></div>
          <?php if (empty($daily)): ?>
            <div class="panel-empty">No activity to chart.</div>
          <?php else: ?>
            <div class="chart">
              <?php foreach ($daily as $d): ?>
                <?php $h = ((int) $d['count'] / $maxDaily) * 100; ?>
                <div class="chart-bar <?php echo (int) $d['count'] === 0 ? 'zero' : ''; ?>" style="height:<?php echo max(3, $h); ?>%;" data-count="<?php echo (int) $d['count']; ?>"></div>
              <?php endforeach; ?>
            </div>
            <div class="chart-x">
              <?php foreach ($daily as $d): ?>
                <span><?php echo e(date('d', strtotime($d['date']))); ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="panel">
          <div class="panel-head"><h2>Weakest Assessments</h2><span class="panel-note">lowest average first</span></div>
          <?php if (empty($weakest)): ?>
            <div class="panel-empty">No submitted assessments to rank yet.</div>
          <?php else: ?>
            <?php foreach ($weakest as $w): ?>
              <div class="weak-row">
                <span class="w-avg" title="average score"><?php echo number_format((float) $w['avg_pct'], 1); ?>%</span>
                <div class="w-body">
                  <div class="w-name"><?php echo e($w['exam_name']); ?></div>
                  <div class="w-sub"><?php echo e(trim(($w['subject_code'] ?? '') . ' · ' . ($w['block'] ?? ''))); ?> · <?php echo (int) $w['submission_count']; ?> sub(s) · pass <?php echo (int) $w['passing_score']; ?>%</div>
                </div>
                <span class="tag <?php echo (float) $w['pass_rate'] >= 60 ? 'tag-pass' : ((float) $w['pass_rate'] >= 40 ? 'tag-royal' : 'tag-fail'); ?>"><?php echo number_format((float) $w['pass_rate'], 0); ?>%</span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ============ MAINTENANCE VIEW ============ -->
    <div class="view" id="view-maintenance">
      <div class="topbar">
        <div class="topbar-title">
          <div class="hamburger" onclick="toggleSidebar()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
          </div>
          <div>
          <h1>System Maintenance</h1>
          <div class="page-sub">Backups, academic period settings, and platform-level controls</div>
        </div>
        </div>
        <div class="topbar-right">
          <div class="admin-menu">
            <div class="admin-chip" onclick="toggleAdminMenu(this)">
              <div class="avatar"><?php echo e($avatarChar); ?></div>
              <div class="chip-text"><strong><?php echo e($adminShort); ?></strong><span>Administrator</span></div>
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="chip-caret"><polyline points="6 9 12 15 18 9"/></svg>
            </div>
            <div class="admin-dropdown">
              <div class="admin-dropdown-divider"></div>
              <a class="admin-dropdown-item logout" href="logout.php">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Log Out
              </a>
            </div>
          </div>
        </div>
      </div>

      <div class="content">
        <div class="health-grid">
          <div class="health-tile amber">
            <div class="lbl">Database</div>
            <div class="num" style="font-size:15px;">ONLINE</div>
            <div class="lbl" style="margin-top:6px;text-transform:none;">now: <?php echo e((string) ($maint['db_now'] ?? '—')); ?></div>
          </div>
          <div class="health-tile">
            <div class="lbl">Timezone offset</div>
            <div class="num" style="font-size:15px;"><?php echo sprintf('%+d:%02d', intdiv((int) $maint['tz_offset_seconds'], 3600), intdiv(abs((int) $maint['tz_offset_seconds']), 60) % 60); ?></div>
            <div class="lbl" style="margin-top:6px;text-transform:none;">server now: <?php echo e(date('M j, Y g:i A')); ?></div>
          </div>
          <div class="health-tile">
            <div class="lbl">PHP</div>
            <div class="num" style="font-size:15px;"><?php echo e(PHP_VERSION); ?></div>
            <div class="lbl" style="margin-top:6px;text-transform:none;"><?php echo e(PHP_INT_SIZE === 8 ? '64-bit' : '32-bit'); ?></div>
          </div>
          <div class="health-tile">
            <div class="lbl">API base</div>
            <div class="num" style="font-size:12.5px;"><?php echo e($apiBase); ?></div>
            <div class="lbl" style="margin-top:6px;text-transform:none;">derived from request host</div>
          </div>
        </div>

        <div class="dash-grid" style="margin-bottom:18px;">
          <div style="min-width:0;">
            <div class="panel">
              <div class="panel-head"><h2>Data Inventory</h2><span class="panel-note">row counts across core tables</span></div>
              <div class="table-scroll">
                <table>
                  <thead><tr><th>Table</th><th>Rows</th></tr></thead>
                  <tbody>
                    <?php foreach ($maintCounts as $name => $n): ?>
                      <tr><td><span class="mono" style="color:var(--navy-900);"><?php echo e($name); ?></span></td><td><span class="mono"><?php echo number_format($n); ?></span></td></tr>
                    <?php endforeach; ?>
                    <?php if (empty($maintCounts)): ?>
                      <tr><td colspan="2" class="id-cell" style="text-align:center;padding:20px;color:var(--ink-soft);">No table data available.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <aside style="min-width:0;">
            <div class="panel">
              <div class="panel-head"><h2>Active Sessions</h2><span class="panel-note"><?php echo (int) $maint['active_sessions']; ?> total</span></div>
              <div class="table-scroll">
                <table>
                  <thead><tr><th>User</th><th>Role</th><th>Expires</th><th></th></tr></thead>
                  <tbody>
                    <?php foreach ($maintSessions as $s): ?>
                      <?php $own = $token === ($s['session_id'] ?? ''); ?>
                      <tr>
                        <td>
                          <div><strong style="font-weight:600;"><?php echo e(trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''))); ?></strong> <?php if ($own): ?><span class="tag tag-amber" style="padding:0 5px;font-size:9px;vertical-align:middle;">YOU</span><?php endif; ?></div>
                          <div style="font-size:11px;color:var(--ink-soft);">@<?php echo e($s['username'] ?? ''); ?></div>
                        </td>
                        <td><span class="tag tag-navy"><?php echo e($s['role_name'] ?? ''); ?></span></td>
                        <td class="mono" style="font-size:12px;color:var(--ink-soft);"><?php echo e(rmc_remain((string) ($s['expires_at'] ?? 'now'))); ?></td>
                        <td><?php if (!$own): ?><button class="btn-filter" type="button" data-kill="<?php echo e($s['session_id'] ?? ''); ?>" data-user="<?php echo e(trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''))); ?>">End</button><?php endif; ?></td>
                      </tr>
                    <?php endforeach; ?>
                    <?php if (empty($maintSessions)): ?>
                      <tr><td colspan="4" class="id-cell" style="text-align:center;padding:20px;color:var(--ink-soft);">No sessions right now.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
              <div class="filter-bar" style="margin:12px 14px 14px;" data-kill-user-form>
                <input class="input" type="text" name="user_ids" placeholder="End all sessions for user id(s) — e.g. 3 7 12" style="flex:1;min-width:140px;">
                <button class="btn-filter" type="submit">End sessions</button>
              </div>
            </div>
          </aside>
        </div>

        <div class="maint-grid" style="margin-bottom:18px;">
          <div class="maint-card">
            <h3>School Year Management</h3>
            <p>Open or close the active academic period, and archive the previous year's records for reporting.</p>
            <div class="maint-meta"><span>Current period</span><span class="mono">S.Y. 2026–2027</span></div>
          </div>
          <div class="maint-card">
            <h3>Platform Controls</h3>
            <div class="toggle-row">
              <div class="toggle-row-text"><strong>Screenshot prevention</strong><span>Handled by the mobile exam client</span></div>
              <div class="switch on"></div>
            </div>
            <div class="toggle-row">
              <div class="toggle-row-text"><strong>App-exit restriction</strong><span>Locks students into the exam view</span></div>
              <div class="switch on"></div>
            </div>
            <div class="toggle-row">
              <div class="toggle-row-text"><strong>Maintenance mode</strong><span>Managed from active sessions above</span></div>
              <div class="switch"></div>
            </div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head"><h2>Admin Guide &amp; Operations</h2><span class="panel-note">Help Center content</span></div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;padding:16px 20px 20px;">
            <div class="soft-tip">
              <h4>Getting started</h4>
              <p>Manage students, teachers and sections from <b>User Management</b>. Build class rosters under <b>Class Management</b>. Oversee every exam (force-close a live exam, schedule or reopen windows, archive) under <b>Assessments</b> — drill into any exam via the eye icon.</p>
            </div>
            <div class="soft-tip">
              <h4>Defaults &amp; security</h4>
              <p>Seeded admin: <span class="mono">admin</span> / <span class="mono">admin123</span> (change it via User Management). Suspending or banning a user signs out every one of their sessions immediately.</p>
            </div>
            <div class="soft-tip">
              <h4>Scoring</h4>
              <p>Percentages are derived from raw earned points: <span class="mono">score / SUM(points) × 100</span>. Pass/fail compares that percentage to the exam’s passing threshold. Items are all-or-nothing — no partial credit.</p>
            </div>
            <div class="soft-tip">
              <h4>Backups &amp; deploy</h4>
              <p>Use the migration files in <span class="mono">database/</span>. This panel and the PHP API are plain PHP — deploy the same tree to Render; the API base auto-derives to the hosting host.</p>
            </div>
          </div>
        </div>
      </div>
    </div>

    </div>
</div>

<!-- ============ CREATE USER MODAL ============ -->
<div class="modal-backdrop" id="modal-create">
  <div class="modal">
    <h3>Create User</h3>
    <div class="modal-sub">Add a new student, teacher, or admin account. Student accounts require ID, year level and section.</div>
    <div class="modal-alert" id="createAlert"></div>
    <form id="createForm" novalidate>
      <div class="form-row">
        <label>Role</label>
        <select name="role" id="createRole">
          <option value="2">Teacher</option>
          <option value="1">Student</option>
          <option value="3">Admin</option>
        </select>
      </div>
      <div class="form-row">
        <label>First Name</label>
        <input type="text" name="first_name" required>
      </div>
      <div class="form-row">
        <label>Last Name</label>
        <input type="text" name="last_name" required>
      </div>
      <div class="form-row">
        <label>Username</label>
        <input type="text" name="username" required>
      </div>
      <div class="form-row">
        <label>Email</label>
        <input type="email" name="email" required>
      </div>
      <div class="form-row">
        <label>Password</label>
        <input type="password" name="password" required minlength="8">
      </div>
      <div class="form-row" id="rowStudentId">
        <label>Student ID / LRN</label>
        <input type="text" name="student_id">
      </div>
      <div class="form-row" id="rowYearLevel">
        <label>Year Level</label>
        <input type="text" name="year_level" placeholder="e.g. 2nd Year">
      </div>
      <div class="form-row" id="rowSection">
        <label>Section</label>
        <input type="text" name="section" placeholder="e.g. BSIT 2-B">
      </div>
      <div class="modal-actions">
        <button class="btn btn-ghost" type="button" onclick="document.getElementById('modal-create').classList.remove('open')">Cancel</button>
        <button class="btn btn-amber" type="submit">Create Account</button>
      </div>
    </form>
  </div>
</div>

<!-- ============ EDIT USER MODAL ============ -->
<div class="modal-backdrop" id="modal-user">
  <div class="modal" style="max-width:560px;width:100%;">
    <h3 id="userModalTitle">Edit User</h3>
    <div class="modal-sub" id="userModalSub">Account details and state.</div>
    <div class="modal-alert" id="userFormAlert"></div>
    <form id="userForm" novalidate>
      <input type="hidden" name="user_id" id="uid">
      <div class="modal-body-form">
        <div class="field"><label for="f_fname">First name</label><input class="input" id="f_fname" name="first_name" required></div>
        <div class="field"><label for="f_lname">Last name</label><input class="input" id="f_lname" name="last_name" required></div>
        <div class="field"><label for="f_username">Username</label><input class="input" id="f_username" name="username" autocomplete="off" required></div>
        <div class="field"><label for="f_email">Email</label><input class="input" id="f_email" name="email" type="email" required></div>
        <div class="field">
          <label for="f_role">Role</label>
          <select class="input" id="f_role" name="role_id">
            <option value="1">Student</option>
            <option value="2">Teacher</option>
            <option value="3">Admin</option>
          </select>
        </div>
        <div class="field">
          <label for="f_status">Status</label>
          <select class="input" id="f_status" name="status">
            <option value="ACTIVE">Active</option>
            <option value="INACTIVE">Inactive</option>
            <option value="BANNED">Banned</option>
          </select>
        </div>
        <div class="field full">
          <label for="f_password" id="pwLabel">Password</label>
          <input class="input" id="f_password" name="password" type="password" autocomplete="new-password">
          <div style="font-size:11.5px;color:var(--ink-soft);margin-top:4px;" id="pwHint">At least 8 characters with a number.</div>
        </div>
        <div class="field" id="wrap_sid"><label for="f_sid">Student ID</label><input class="input" id="f_sid" name="student_id"></div>
        <div class="field" id="wrap_year"><label for="f_year">Year level</label><input class="input" id="f_year" name="year_level" placeholder="2nd Year"></div>
        <div class="field full" id="wrap_sec"><label for="f_sec">Section</label><input class="input" id="f_sec" name="section" placeholder="BSIT 2-B"></div>
      </div>
      <div class="modal-alert" id="selfLock" style="display:none;background:var(--warn-bg);border-color:rgba(179,84,30,0.3);color:var(--warn);">You cannot change your own role or status.</div>
      <div class="modal-actions">
        <button class="btn btn-ghost" type="button" data-close-m="modal-user">Cancel</button>
        <button class="btn btn-amber" type="submit" id="btnUserSave">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ============ CLASS MODAL ============ -->
<div class="modal-backdrop" id="modal-class">
  <div class="modal" style="max-width:520px;width:100%;">
    <h3 id="classModalTitle">Add Class</h3>
    <div class="modal-sub" id="classModalSub">Create a class and assign its teacher.</div>
    <div class="modal-alert" id="classFormAlert"></div>
    <form id="classForm" novalidate>
      <input type="hidden" name="class_id" id="cid">
      <div class="modal-body-form">
        <div class="field"><label for="c_scode">Subject code</label><input class="input" id="c_scode" name="subject_code" placeholder="HUM02" required></div>
        <div class="field"><label for="c_ccode">Class code</label><input class="input" id="c_ccode" name="class_code" placeholder="MATH2A" required></div>
        <div class="field full"><label for="c_sname">Subject name</label><input class="input" id="c_sname" name="subject_name" placeholder="Mathematics 2" required></div>
        <div class="field full"><label for="c_block">Block / section</label><input class="input" id="c_block" name="block" placeholder="BSIT 2-A" required></div>
        <div class="field">
          <label for="c_teacher">Teacher</label>
          <select class="input" id="c_teacher" name="teacher_id" required>
            <option value="">Select teacher…</option>
            <?php foreach ($teachers as $t): ?>
              <option value="<?php echo (int) $t['user_id']; ?>"><?php echo e(trim(($t['first_name'] ?? '') . ' ' . ($t['last_name'] ?? ''))); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="c_status">Status</label>
          <select class="input" id="c_status" name="status">
            <option value="ACTIVE">Active</option>
            <option value="ARCHIVED">Archived</option>
          </select>
        </div>
      </div>
      <div class="modal-actions">
        <button class="btn btn-ghost" type="button" data-close-m="modal-class">Cancel</button>
        <button class="btn btn-amber" type="submit" id="btnClassSave">Save Class</button>
      </div>
    </form>
  </div>
</div>

<!-- ============ ROSTER MODAL ============ -->
<div class="modal-backdrop" id="modal-roster">
  <div class="modal" style="max-width:640px;width:100%;">
    <h3 id="rosterTitle">Class Roster</h3>
    <div class="modal-sub" id="rosterSub">Check the students enrolled in this class; save to apply.</div>
    <div class="modal-alert" id="rosterAlert"></div>
    <div class="field" style="margin-bottom:12px;">
      <input class="input" type="search" id="rosterSearch" placeholder="Filter students by name or student ID…" style="width:100%;">
    </div>
    <div style="max-height:340px;overflow-y:auto;border:1px solid var(--line);border-radius:10px;">
      <div class="empty-state" id="rosterLoading">Loading roster…</div>
      <div id="rosterList"></div>
    </div>
    <div class="muted" style="font-size:12px;margin:10px 0 0;"><span id="rosterChecked">0</span> enrolled · <span id="rosterTotal">0</span> students → <span class="mono">ACTIVE</span> accounts only</div>
    <div class="modal-actions">
      <button class="btn btn-ghost" type="button" data-close-m="modal-roster">Cancel</button>
      <button class="btn btn-amber" type="button" id="btnRosterSave">Save Roster</button>
    </div>
  </div>
</div>

<!-- ============ SCHEDULE MODAL ============ -->
<div class="modal-backdrop" id="modal-schedule">
  <div class="modal" style="max-width:440px;width:100%;">
    <h3 id="schedTitle">Schedule Assessment</h3>
    <div class="modal-sub" id="schedSub">Choose the availability window. The exam runs between these times.</div>
    <div class="modal-alert" id="schedAlert"></div>
    <div class="modal-body-form">
      <div class="field"><label for="s_start">Start time</label><input class="input" type="datetime-local" id="s_start" required></div>
      <div class="field"><label for="s_end">End time</label><input class="input" type="datetime-local" id="s_end" required></div>
    </div>
    <div class="modal-actions">
      <button class="btn btn-ghost" type="button" data-close-m="modal-schedule">Cancel</button>
      <button class="btn btn-amber" type="button" id="btnSchedGo">Save Schedule</button>
    </div>
  </div>
</div>

<!-- ============ EXAM DETAIL MODAL ============ -->
<div class="modal-backdrop" id="modal-exam">
  <div class="modal" style="max-width:760px;width:100%;">
    <h3 id="edTitle">Assessment Details</h3>
    <div class="modal-sub" id="edSub"></div>
    <div class="modal-alert" id="edAlert"></div>
    <div id="edBody"></div>
    <div class="modal-actions">
      <button class="btn btn-ghost" type="button" data-close-m="modal-exam">Close</button>
    </div>
  </div>
</div>

<!-- ============ CONFIRM MODAL ============ -->
<div class="modal-backdrop" id="modal-confirm">
  <div class="modal" style="max-width:400px;width:100%;">
    <h3 id="cfTitle">Are you sure?</h3>
    <div class="modal-sub" id="cfBody" style="margin-bottom:16px;"></div>
    <div class="modal-alert" id="cfAlert"></div>
    <div class="modal-actions">
      <button class="btn btn-ghost" type="button" data-close-m="modal-confirm">Cancel</button>
      <button class="btn btn-amber" type="button" id="cfGo" style="background:var(--navy-900);color:#fff;">Confirm</button>
    </div>
  </div>
</div>

<!-- ============ KILL SESSION MODAL ============ -->
<div class="modal-backdrop" id="modal-kill">
  <div class="modal" style="max-width:420px;width:100%;">
    <h3>End session</h3>
    <p style="color:var(--ink-soft);font-size:13.5px;margin:10px 0 16px;">Sign out <b id="killName" style="color:var(--ink);"></b>. This revokes their API token immediately — the user will be returned to the login screen on their next action.</p>
    <div class="modal-actions">
      <button class="btn btn-ghost" type="button" data-close-m="modal-kill">Cancel</button>
      <button class="btn btn-amber" type="button" id="killConfirm" style="background:var(--danger);color:#fff;">End session</button>
    </div>
  </div>
</div>

<script>
  var RMC_ADMIN = <?php echo json_encode([
      'name' => $adminShort,
      'selfId' => (int) ($admin['user_id'] ?? 0),
  ]); ?>;
  window.USERS   = <?php echo json_encode($users); ?>;
  window.CLASSES = <?php echo json_encode($classesAll); ?>;
  window.TEACHERS = <?php echo json_encode($teachers); ?>;
  window.EXAMS   = <?php echo json_encode($examsAll); ?>;

  function toggleSidebar(){
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebar-overlay').classList.toggle('open');
  }

  document.querySelectorAll('.nav-item').forEach(function(item){
    item.addEventListener('click', function(){
      if (item.getAttribute('data-soon')) return;
      document.querySelectorAll('.nav-item').forEach(function(i){ i.classList.remove('active'); });
      document.querySelectorAll('.view').forEach(function(v){ v.classList.remove('active'); });
      item.classList.add('active');
      document.getElementById('view-' + item.dataset.view).classList.add('active');
      document.getElementById('sidebar').classList.remove('open');
      document.getElementById('sidebar-overlay').classList.remove('open');
      window.scrollTo(0, 0);
    });
  });

  document.querySelectorAll('#view-users .filter-chip').forEach(function(chip){
    chip.addEventListener('click', function(){
      var filter = chip.getAttribute('data-filter') || 'All';
      document.querySelectorAll('#view-users .filter-chip').forEach(function(c){ c.classList.remove('active'); });
      chip.classList.add('active');
      document.querySelectorAll('#userTable tbody tr').forEach(function(tr){
        var show = filter === 'All' || tr.getAttribute('data-role') === filter;
        tr.style.display = show ? '' : 'none';
      });
    });
  });

  var userSearch = document.getElementById('userSearch');
  if (userSearch) {
    userSearch.addEventListener('input', function(){
      var q = this.value.toLowerCase().trim();
      document.querySelectorAll('#userTable tbody tr').forEach(function(tr){
        var hit = tr.getAttribute('data-name').indexOf(q) !== -1 || tr.getAttribute('data-role').toLowerCase().indexOf(q) !== -1;
        tr.style.display = hit ? '' : 'none';
      });
    });
  }

  document.querySelectorAll('.switch').forEach(function(sw){
    sw.addEventListener('click', function(){ sw.classList.toggle('on'); });
  });

  function toggleAdminMenu(chipEl){
    var dropdown = chipEl.parentElement.querySelector('.admin-dropdown');
    var isOpen = dropdown.classList.contains('open');
    document.querySelectorAll('.admin-dropdown').forEach(function(d){ d.classList.remove('open'); });
    if(!isOpen) dropdown.classList.add('open');
  }
  document.addEventListener('click', function(e){
    if(!e.target.closest('.admin-menu')){
      document.querySelectorAll('.admin-dropdown').forEach(function(d){ d.classList.remove('open'); });
    }
  });

  var modal = document.getElementById('modal-create');
  var createForm = document.getElementById('createForm');
  var createAlert = document.getElementById('createAlert');
  var createRole = document.getElementById('createRole');

  function toggleRoleFields(){
    var isStudent = createRole.value === '1';
    ['rowStudentId','rowYearLevel','rowSection'].forEach(function(id){
      document.getElementById(id).style.display = isStudent ? '' : 'none';
    });
    ['student_id','year_level','section'].forEach(function(n){
      document.getElementById('createForm')[n].required = isStudent;
    });
  }
  createRole.addEventListener('change', toggleRoleFields);
  toggleRoleFields();

  createForm.addEventListener('submit', function(ev){
    ev.preventDefault();
    createAlert.classList.remove('show');
    var fd = new FormData(createForm);
    var payload = {
      first_name: fd.get('first_name'), last_name: fd.get('last_name'),
      username: fd.get('username'), email: fd.get('email'),
      password: fd.get('password'), role_id: Number(fd.get('role')),
      status: 'ACTIVE',
      student_id: fd.get('student_id') || '', year_level: fd.get('year_level') || '', section: fd.get('section') || ''
    };
    if (payload.role_id === 1 && (!payload.student_id || !payload.year_level || !payload.section)) {
      createAlert.textContent = 'Student ID, year level and section are required for students.';
      createAlert.classList.add('show');
      return;
    }
    var btn = ev.target.querySelector('[type="submit"]');
    btn.disabled = true; btn.textContent = 'Creating…';
    fetch('ajax.php?action=user_create', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success) { window.location.reload(); }
        else { createAlert.textContent = res.error || 'Request failed.'; createAlert.classList.add('show'); }
      })
      .catch(function () { createAlert.textContent = 'Network error.'; createAlert.classList.add('show'); })
      .finally(function () { btn.disabled = false; btn.textContent = 'Create Account'; });
  });

  modal.addEventListener('click', function(e){
    if(e.target === this) this.classList.remove('open');
  });

  /* ================= consolidation modules ================= */

  function openMd(id){ document.getElementById(id).classList.add('open'); }
  function closeMd(id){ document.getElementById(id).classList.remove('open'); }
  document.querySelectorAll('[data-close-m]').forEach(function(b){
    b.addEventListener('click', function(){ closeMd(b.getAttribute('data-close-m')); });
  });
  document.querySelectorAll('.modal-backdrop').forEach(function(ov){
    ov.addEventListener('click', function(e){
      if(e.target === ov) ov.classList.remove('open');
    });
  });

  function postAjax(action, payload){
    return fetch('ajax.php?action=' + action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function(r){ return r.json(); });
  }
  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(ch){
      return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[ch];
    });
  }
  function dstr(ts){
    if (!ts) return '';
    var d = new Date(String(ts).replace(' ', 'T'));
    if (isNaN(d.getTime())) return ts;
    var m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var h = d.getHours();
    return m[d.getMonth()] + ' ' + d.getDate() + ', ' + ((h % 12) || 12) + ':' + String(d.getMinutes()).padStart(2,'0') + ' ' + (h < 12 ? 'AM' : 'PM');
  }
  function fmtSecs(s){
    s = parseInt(s, 10);
    if (!Number.isFinite(s)) return '—';
    var m = Math.floor(s / 60), ss = s % 60;
    return m + ':' + String(ss).padStart(2, '0');
  }

  /* confirm engine */
  var cfAlert = document.getElementById('cfAlert');
  var cfGo = document.getElementById('cfGo');
  var cfAction = null;
  function askConfirm(title, body, fn){
    document.getElementById('cfTitle').textContent = title;
    document.getElementById('cfBody').textContent = body;
    cfAlert.classList.remove('show');
    cfAction = fn;
    openMd('modal-confirm');
  }
  cfGo.addEventListener('click', function(){
    if (!cfAction) return;
    cfGo.disabled = true;
    cfAction(function(ok, msg){
      cfGo.disabled = false;
      if (ok) { window.location.reload(); }
      else {
        cfAlert.textContent = msg || 'Request failed. Please try again.';
        cfAlert.classList.add('show');
      }
    });
  });

  /* ---------- USERS ---------- */
  var usersById = {};
  (window.USERS || []).forEach(function(u){ usersById[u.user_id] = u; });
  var userForm = document.getElementById('userForm');
  var editingUserId = null;

  function setStudentFields(roleId){
    ['wrap_sid','wrap_year','wrap_sec'].forEach(function(id){
      document.getElementById(id).style.display = roleId === 1 ? '' : 'none';
    });
  }
  document.getElementById('f_role').addEventListener('change', function(){ setStudentFields(Number(this.value)); });

  document.getElementById('btnAddUser').addEventListener('click', function(){
    editingUserId = null;
    userForm.reset();
    document.getElementById('uid').value = '';
    document.getElementById('userModalTitle').textContent = 'Create User';
    document.getElementById('userModalSub').textContent = 'Create a new account. Admins are always provisioned by an existing admin.';
    document.getElementById('pwLabel').textContent = 'Password';
    document.getElementById('pwHint').textContent = 'At least 8 characters with a number.';
    document.getElementById('f_password').required = true;
    document.getElementById('f_role').disabled = false;
    document.getElementById('f_status').disabled = false;
    document.getElementById('f_status').value = 'ACTIVE';
    document.getElementById('selfLock').style.display = 'none';
    document.getElementById('userFormAlert').classList.remove('show');
    setStudentFields(Number(document.getElementById('f_role').value));
    openMd('modal-user');
  });

  function openEditUser(id){
    var u = usersById[id]; if (!u) return;
    editingUserId = id;
    userForm.reset();
    document.getElementById('uid').value = u.user_id;
    document.getElementById('f_fname').value = u.first_name;
    document.getElementById('f_lname').value = u.last_name;
    document.getElementById('f_username').value = u.username;
    document.getElementById('f_email').value = u.email;
    document.getElementById('f_role').value = String(u.role_id);
    document.getElementById('f_status').value = u.status;
    document.getElementById('f_sid').value = u.student_id || '';
    document.getElementById('f_year').value = u.year_level || '';
    document.getElementById('f_sec').value = u.section || '';
    document.getElementById('f_password').value = '';
    document.getElementById('f_password').required = false;
    document.getElementById('pwLabel').textContent = 'Password (leave blank to keep current)';
    document.getElementById('pwHint').textContent = 'Only filled in when resetting the password.';
    document.getElementById('selfLock').style.display = (u.user_id === RMC_ADMIN.selfId) ? 'block' : 'none';
    document.getElementById('f_role').disabled = u.user_id === RMC_ADMIN.selfId;
    document.getElementById('f_status').disabled = u.user_id === RMC_ADMIN.selfId;
    document.getElementById('userFormAlert').classList.remove('show');
    document.getElementById('userModalTitle').textContent = 'Edit User';
    document.getElementById('userModalSub').textContent = '@' + u.username + ' — account details and state.';
    setStudentFields(Number(u.role_id));
    openMd('modal-user');
  }

  document.getElementById('userForm').addEventListener('submit', function(ev){
    ev.preventDefault();
    document.getElementById('userFormAlert').classList.remove('show');
    var fd = new FormData(userForm);
    var roleEl = document.getElementById('f_role');
    var stEl = document.getElementById('f_status');
    var roleId = Number(roleEl.disabled ? roleEl.value : fd.get('role_id'));
    var statusVal = stEl.disabled ? stEl.value : fd.get('status');
    var payload = {
      first_name: fd.get('first_name'), last_name: fd.get('last_name'),
      username: fd.get('username'), email: fd.get('email'),
      role_id: roleId, status: statusVal,
      student_id: fd.get('student_id') || '', year_level: fd.get('year_level') || '', section: fd.get('section') || ''
    };
    if (roleId === 1 && (!payload.student_id || !payload.year_level || !payload.section)) {
      document.getElementById('userFormAlert').textContent = 'Student ID, year level and section are required for student accounts.';
      document.getElementById('userFormAlert').classList.add('show');
      return;
    }
    if (editingUserId === null) {
      payload.password = fd.get('password');
      if (!payload.password) { document.getElementById('userFormAlert').textContent = 'Password is required for new users.'; document.getElementById('userFormAlert').classList.add('show'); return; }
    } else {
      payload.user_id = editingUserId;
      var pw = fd.get('password');
      if (pw) payload.password = pw;
    }
    var btn = document.getElementById('btnUserSave');
    btn.disabled = true; btn.textContent = 'Saving…';
    postAjax(editingUserId === null ? 'user_create' : 'user_update', payload)
      .then(function(res){
        if (res.success) {
          window.location.reload();
        }
        else { document.getElementById('userFormAlert').textContent = res.error || 'Request failed.'; document.getElementById('userFormAlert').classList.add('show'); }
      })
      .catch(function(){ document.getElementById('userFormAlert').textContent = 'Network error.'; document.getElementById('userFormAlert').classList.add('show'); })
      .finally(function(){ btn.disabled = false; btn.textContent = editingUserId === null ? 'Create Account' : 'Save Changes'; });
  });

  function userStatusPayload(ids, status){
    return { user_ids: ids, status: status };
  }
  document.getElementById('userTable').addEventListener('click', function(e){
    var btn = e.target.closest('button[data-act]');
    if (!btn) return;
    var tr = btn.closest('tr');
    var id = Number(tr.getAttribute('data-id'));
    var act = btn.getAttribute('data-act');
    if (act === 'edit') { openEditUser(id); return; }
    var map = { suspend:['INACTIVE','Suspend'], ban:['BANNED','Ban'], activate:['ACTIVE','Activate'] };
    var m = map[act]; if (!m) return;
    askConfirm(m[1] + ' user?', m[1] + ' @' + (usersById[id] ? usersById[id].username : id) + (m[0] === 'BANNED' ? ' Banned users cannot sign in.' : ''), function(done){
      postAjax('user_status', userStatusPayload([id], m[0]))
        .then(function(res){ res.success ? done(true) : done(false, res.error || m[1] + ' failed.'); })
        .catch(function(){ done(false, 'Network error.'); });
    });
  });

  /* roster import */
  var rosterAlertEl = document.getElementById('rosterImportAlert');
  var rosterSummaryEl = document.getElementById('rosterImportSummary');
  function showRosterAlert(msg){
    rosterAlertEl.textContent = msg || '';
    rosterAlertEl.classList.toggle('show', !!msg);
  }
  function parseCSVRows(text, cols){
    var rows = [], cur = [], field = '', quoted = false;
    for (var i = 0; i < text.length; i++) {
      var c = text[i];
      if (quoted) {
        if (c === '"') {
          if (text[i + 1] === '"') { field += '"'; i++; }
          else { quoted = false; }
        } else { field += c; }
      } else {
        if (c === '"') { quoted = true; }
        else if (c === ',') { cur.push(field); field = ''; }
        else if (c === '\n' || c === '\r') {
          if (c === '\r' && text[i + 1] === '\n') i++;
          cur.push(field); field = '';
          if (cur.join('').trim() !== '') rows.push(cur);
          cur = [];
        } else { field += c; }
      }
    }
    if (field !== '' || cur.length) { cur.push(field); if (cur.join('').trim() !== '') rows.push(cur); }
    return rows.map(function(r){
      var o = {};
      for (var j = 0; j < cols.length; j++) o[cols[j]] = (r[j] || '').trim();
      return o;
    });
  }
  function handleRosterImport(inputEl, cols, action){
    var f = inputEl.files && inputEl.files[0];
    if (!f) { showRosterAlert('Choose a CSV file first.'); return; }
    var reader = new FileReader();
    reader.onload = function(){
      try {
        var rows = parseCSVRows(String(reader.result), cols);
        if (rows.length && cols.indexOf((rows[0][cols[0]] || '').toLowerCase()) !== -1) rows.shift();
        if (!rows.length) { showRosterAlert('No data rows found in ' + f.name + '.'); return; }
        rosterSummaryEl.textContent = '';
        showRosterAlert('Importing ' + rows.length + ' row(s)…');
        postAjax(action, { rows: rows })
          .then(function(res){
            if (res.success && res.data) {
              var d = res.data;
              var msg = 'Imported ' + d.added + ' new, updated ' + d.updated + ', skipped ' + d.skipped + '.';
              showRosterAlert(d.skipped > 0 ? msg : '');
              rosterSummaryEl.textContent = (d.errors || []).length
                ? 'Skipped rows: ' + d.errors.slice(0, 4).join(' · ') + ((d.errors || []).length > 4 ? ' · …' : '')
                : msg;
            } else { showRosterAlert(res.error || 'Import failed.'); }
          })
          .catch(function(){ showRosterAlert('Network error.'); });
      } catch (e) { showRosterAlert('Could not read ' + f.name + ': ' + e.message); }
    };
    reader.readAsText(f);
  }
  document.getElementById('btnImportStudents').addEventListener('click', function(){
    handleRosterImport(document.getElementById('studentRosterFile'), ['lrn', 'full_name', 'program'], 'roster_import_student');
  });
  document.getElementById('btnImportTeachers').addEventListener('click', function(){
    handleRosterImport(document.getElementById('teacherRosterFile'), ['employee_number', 'full_name', 'department'], 'roster_import_teacher');
  });

  /* bulk selection */
  var bulkBar = document.getElementById('bulkBar');
  var bulkCount = document.getElementById('bulkCount');
  var chkAll = document.getElementById('chkAll');
  var selected = {};
  function refreshSelection(){
    var n = Object.keys(selected).length;
    bulkBar.classList.toggle('show', n > 0);
    bulkCount.textContent = n + ' selected';
    if (chkAll) {
      var boxes = document.querySelectorAll('#userTable .row-check:not(:disabled)');
      var cks = document.querySelectorAll('#userTable .row-check:not(:disabled):checked');
      chkAll.checked = boxes.length > 0 && boxes.length === cks.length;
    }
  }
  document.querySelectorAll('#userTable .row-check').forEach(function(cb){
    cb.addEventListener('change', function(){
      if (cb.checked) selected[cb.value] = true; else delete selected[cb.value];
      refreshSelection();
    });
  });
  if (chkAll) chkAll.addEventListener('change', function(){
    document.querySelectorAll('#userTable .row-check:not(:disabled)').forEach(function(cb){
      cb.checked = chkAll.checked;
      if (cb.checked) selected[cb.value] = true; else delete selected[cb.value];
    });
    refreshSelection();
  });
  document.getElementById('btnBulkClear').addEventListener('click', function(){
    document.querySelectorAll('#userTable .row-check').forEach(function(cb){ cb.checked = false; });
    selected = {}; refreshSelection();
  });
  document.querySelectorAll('#bulkBar .bulk-status').forEach(function(b){
    b.addEventListener('click', function(){
      var ids = Object.keys(selected).map(Number);
      var status = b.getAttribute('data-bulk');
      var label = status === 'BANNED' ? 'Ban' : (status === 'ACTIVE' ? 'Activate' : 'Suspend');
      if (!ids.length) return;
      askConfirm(label + ' ' + ids.length + ' user(s)?', label + ' the ' + ids.length + ' selected user(s).' + (status === 'BANNED' ? ' Banned users cannot sign in.' : ''), function(done){
        postAjax('user_status', userStatusPayload(ids, status))
          .then(function(res){ res.success ? done(true) : done(false, res.error || label + ' failed.'); })
          .catch(function(){ done(false, 'Network error.'); });
      });
    });
  });

  /* ---------- CLASSES ---------- */
  var classesById = {};
  (window.CLASSES || []).forEach(function(c){ classesById[c.class_id] = c; });

  document.querySelectorAll('#view-classes .cls-chip').forEach(function(chip){
    chip.addEventListener('click', function(){
      var f = chip.getAttribute('data-filter') || 'All';
      document.querySelectorAll('#view-classes .cls-chip').forEach(function(c){ c.classList.remove('active'); });
      chip.classList.add('active');
      document.querySelectorAll('#classTable tbody tr').forEach(function(tr){
        tr.style.display = (f === 'All' || tr.getAttribute('data-status') === f) ? '' : 'none';
      });
    });
  });
  var classSearch = document.getElementById('classSearch');
  if (classSearch) classSearch.addEventListener('input', function(){
    var q = this.value.toLowerCase().trim();
    document.querySelectorAll('#classTable tbody tr').forEach(function(tr){
      tr.style.display = tr.getAttribute('data-name').indexOf(q) !== -1 ? '' : 'none';
    });
  });

  var classForm = document.getElementById('classForm');
  var editingClassId = null;
  document.getElementById('btnAddClass').addEventListener('click', function(){
    editingClassId = null;
    classForm.reset();
    document.getElementById('cid').value = '';
    document.getElementById('c_status').value = 'ACTIVE';
    document.getElementById('classModalTitle').textContent = 'Add Class';
    document.getElementById('classModalSub').textContent = 'Create a class and assign its teacher.';
    document.getElementById('classFormAlert').classList.remove('show');
    openMd('modal-class');
  });
  function openEditClass(id){
    var c = classesById[id]; if (!c) return;
    editingClassId = id;
    classForm.reset();
    document.getElementById('cid').value = c.class_id;
    document.getElementById('c_scode').value = c.subject_code;
    document.getElementById('c_sname').value = c.subject_name;
    document.getElementById('c_block').value = c.block;
    document.getElementById('c_ccode').value = c.class_code;
    document.getElementById('c_teacher').value = String(c.teacher_id);
    document.getElementById('c_status').value = c.status;
    document.getElementById('classFormAlert').classList.remove('show');
    document.getElementById('classModalTitle').textContent = 'Edit Class';
    document.getElementById('classModalSub').textContent = c.subject_name + ' (' + c.class_code + ')';
    openMd('modal-class');
  }
  document.getElementById('classForm').addEventListener('submit', function(ev){
    ev.preventDefault();
    document.getElementById('classFormAlert').classList.remove('show');
    var fd = new FormData(classForm);
    var payload = {
      subject_code: fd.get('subject_code'), subject_name: fd.get('subject_name'),
      block: fd.get('block'), class_code: fd.get('class_code'),
      teacher_id: Number(fd.get('teacher_id')), status: fd.get('status')
    };
    if (!payload.teacher_id) { document.getElementById('classFormAlert').textContent = 'Please assign a teacher.'; document.getElementById('classFormAlert').classList.add('show'); return; }
    if (editingClassId !== null) payload.class_id = editingClassId;
    var btn = document.getElementById('btnClassSave');
    btn.disabled = true; btn.textContent = 'Saving…';
    postAjax(editingClassId === null ? 'class_create' : 'class_update', payload)
      .then(function(res){
        if (res.success) { window.location.reload(); }
        else { document.getElementById('classFormAlert').textContent = res.error || 'Request failed.'; document.getElementById('classFormAlert').classList.add('show'); }
      })
      .catch(function(){ document.getElementById('classFormAlert').textContent = 'Network error.'; document.getElementById('classFormAlert').classList.add('show'); })
      .finally(function(){ btn.disabled = false; btn.textContent = 'Save Class'; });
  });

  document.getElementById('classTable').addEventListener('click', function(e){
    var btn = e.target.closest('button[data-act]');
    if (!btn) return;
    var tr = btn.closest('tr');
    var id = Number(tr.getAttribute('data-id'));
    var act = btn.getAttribute('data-act');
    var c = classesById[id]; if (!c) return;
    if (act === 'edit') { openEditClass(id); return; }
    if (act === 'roster') { openRoster(id); return; }
    var restore = c.status === 'ARCHIVED';
    var label = restore ? 'Restore class' : 'Archive class';
    var body = restore ? 'Un-hide this class so it can be managed again.' : 'Hide the class from teachers/students. Enrollments and exam records are preserved.';
    askConfirm(label + '?', body, function(done){
      postAjax('class_status', { class_id: id, status: restore ? 'ACTIVE' : 'ARCHIVED' })
        .then(function(res){ res.success ? done(true) : done(false, res.error || 'Request failed.'); })
        .catch(function(){ done(false, 'Network error.'); });
    });
  });

  /* class roster modal */
  var rosterList = document.getElementById('rosterList');
  var rosterLoading = document.getElementById('rosterLoading');
  var rosterSearch = document.getElementById('rosterSearch');
  var rosterTotal = document.getElementById('rosterTotal');
  var rosterCount = document.getElementById('rosterChecked');
  var currentClassId = null;
  var rosterStudents = [];
  function renderRoster(){
    var q = (rosterSearch.value || '').toLowerCase();
    var checked = 0, html = '';
    rosterStudents.forEach(function(s){
      var hay = ((s.first_name + ' ' + s.last_name) + ' ' + s.username + ' ' + (s.student_id || '')).toLowerCase();
      if (q && hay.indexOf(q) === -1) return;
      html += '<label class="roster-row"><input type="checkbox" class="roster-cb" value="' + s.user_id + '"' + (s.enrolled ? ' checked' : '') + '><span style="flex:1;">' + esc(s.first_name + ' ' + s.last_name) + '</span><span class="muted">@' + esc(s.username) + ' · ' + esc(s.student_id || 'N/A') + '</span></label>';
      if (s.enrolled) checked++;
    });
    rosterList.innerHTML = html || '<div style="padding:18px;color:var(--ink-soft);font-size:13px;text-align:center;">No students match.</div>';
    rosterCount.textContent = checked;
    rosterTotal.textContent = rosterStudents.length;
  }
  function openRoster(id){
    var c = classesById[id]; if (!c) return;
    currentClassId = id;
    document.getElementById('rosterTitle').textContent = c.subject_code + ' · ' + c.class_code;
    document.getElementById('rosterSub').textContent = c.subject_name + ' — ' + c.block;
    document.getElementById('rosterAlert').classList.remove('show');
    rosterSearch.value = '';
    rosterList.innerHTML = '';
    rosterLoading.classList.remove('hidden');
    document.getElementById('btnRosterSave').disabled = true;
    openMd('modal-roster');
    fetch('ajax.php?action=class_roster&class_id=' + id)
      .then(function(r){ return r.json(); })
      .then(function(res){
        if (!res.success) throw new Error(res.error || 'Failed to load roster.');
        rosterStudents = res.data.students || [];
        document.getElementById('btnRosterSave').disabled = false;
        rosterLoading.classList.add('hidden');
        renderRoster();
      })
      .catch(function(err){
        rosterLoading.textContent = err.message || 'Failed to load roster.';
        rosterLoading.classList.remove('hidden');
      });
  }
  rosterSearch.addEventListener('input', renderRoster);
  rosterList.addEventListener('change', renderRoster);
  document.getElementById('btnRosterSave').addEventListener('click', function(){
    var ids = [];
    document.querySelectorAll('.roster-cb').forEach(function(cb){ if (cb.checked) ids.push(Number(cb.value)); });
    var btn = this;
    btn.disabled = true; btn.textContent = 'Saving…';
    postAjax('class_roster_update', { class_id: currentClassId, student_ids: ids })
      .then(function(res){
        if (res.success) { window.location.reload(); }
        else { document.getElementById('rosterAlert').textContent = res.error || 'Failed to save roster.'; document.getElementById('rosterAlert').classList.add('show'); btn.disabled = false; btn.textContent = 'Save Roster'; }
      })
      .catch(function(){ document.getElementById('rosterAlert').textContent = 'Network error.'; document.getElementById('rosterAlert').classList.add('show'); btn.disabled = false; btn.textContent = 'Save Roster'; });
  });

  /* ---------- ASSESSMENTS ---------- */
  var examsById = {};
  (window.EXAMS || []).forEach(function(e){ examsById[e.exam_id] = e; });

  document.querySelectorAll('#view-assessments .ex-chip').forEach(function(chip){
    chip.addEventListener('click', function(){
      var f = chip.getAttribute('data-filter') || 'All';
      document.querySelectorAll('#view-assessments .ex-chip').forEach(function(c){ c.classList.remove('active'); });
      chip.classList.add('active');
      document.querySelectorAll('#examTable tbody tr').forEach(function(tr){
        tr.style.display = (f === 'All' || tr.getAttribute('data-status') === f) ? '' : 'none';
      });
    });
  });
  var examSearch = document.getElementById('examSearch');
  if (examSearch) examSearch.addEventListener('input', function(){
    var q = this.value.toLowerCase().trim();
    document.querySelectorAll('#examTable tbody tr').forEach(function(tr){
      tr.style.display = tr.getAttribute('data-name').indexOf(q) !== -1 ? '' : 'none';
    });
  });

  function examPost(examId, action, extra, done){
    var payload = { exam_id: examId, action: action };
    if (extra) Object.assign(payload, extra);
    postAjax('exam_status', payload)
      .then(function(res){ res.success ? done(true) : done(false, res.error); })
      .catch(function(){ done(false, 'Network error — is the backend running?'); });
  }

  document.getElementById('examTable').addEventListener('click', function(e){
    var btn = e.target.closest('button[data-act]');
    if (!btn) return;
    var tr = btn.closest('tr');
    var id = Number(tr.getAttribute('data-id'));
    var act = btn.getAttribute('data-act');
    var ex = examsById[id]; if (!ex) return;
    if (act === 'review') { openExamDetail(id); return; }
    if (act === 'force_close') {
      askConfirm('Force close exam?', 'Stops the exam immediately. Mid-exam submissions are kept; further attempts are blocked.', function(done){ examPost(id, 'force_close', null, done); });
    } else if (act === 'archive') {
      askConfirm('Archive exam?', 'Hides the exam from teachers/students. Submissions are preserved; you can reschedule it later.', function(done){ examPost(id, 'archive', null, done); });
    } else if (act === 'schedule') {
      document.getElementById('schedAlert').classList.remove('show');
      document.getElementById('s_start').value = ex.start_time ? String(ex.start_time).replace(' ', 'T').slice(0, 16) : '';
      document.getElementById('s_end').value = ex.end_time ? String(ex.end_time).replace(' ', 'T').slice(0, 16) : '';
      document.getElementById('schedTitle').textContent = ex.status === 'ARCHIVED' ? 'Restore & Schedule' : (ex.status === 'DRAFT' ? 'Schedule' : 'Reopen');
      openMd('modal-schedule');
      document.getElementById('btnSchedGo').onclick = function(){
        var sv = document.getElementById('s_start').value;
        var ev = document.getElementById('s_end').value;
        if (!sv || !ev) { document.getElementById('schedAlert').textContent = 'Please pick both a start and an end time.'; document.getElementById('schedAlert').classList.add('show'); return; }
        var b = this; b.disabled = true;
        examPost(id, 'schedule', { start_time: sv.replace('T', ' ') + ':00', end_time: ev.replace('T', ' ') + ':00' }, function(ok, msg){
          if (ok) { window.location.reload(); }
          else { document.getElementById('schedAlert').textContent = msg || 'Failed to schedule.'; document.getElementById('schedAlert').classList.add('show'); b.disabled = false; }
        });
      };
    }
  });

  function openExamDetail(id){
    document.getElementById('edAlert').classList.remove('show');
    document.getElementById('edBody').innerHTML = '<div class="empty-state">Loading…</div>';
    openMd('modal-exam');
    fetch('ajax.php?action=exam_detail&exam_id=' + id)
      .then(function(r){ return r.json(); })
      .then(function(res){
        if (!res.success) throw new Error(res.error || 'Failed to load.');
        var d = res.data;
        var exm = d.exam || {};
        var qs = d.questions || [];
        var subs = d.submissions || [];
        document.getElementById('edTitle').textContent = exm.exam_name || ('Exam #' + id);
        document.getElementById('edSub').textContent = (exm.subject_name || '') + ' · ' + (exm.subject_code || '') + ' · ' + (exm.block || '') + ' — ' + (exm.status || '');
        var qhtml = qs.map(function(q, i){
          return '<div class="roster-row" style="cursor:default;"><span class="qnum">#' + (q.order_num || (i + 1)) + '</span><div style="flex:1;min-width:0;"><div>' + esc(q.question_text) + '</div><div style="margin-top:4px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;"><span class="tag tag-dim">' + esc(q.question_type || '') + '</span><span class="mono" style="font-size:11.5px;color:var(--ink-soft);">' + (q.points || 0) + ' pt</span>' + ((q.correct_answer !== null && q.correct_answer !== '' && q.correct_answer !== undefined) ? '<span style="font-size:11.5px;color:#1d8a4e;"><b>✓ ' + esc(q.correct_answer) + '</b></span>' : '') + '</div></div></div>';
        }).join('') || '<div class="empty-state">No questions on this assessment yet.</div>';
        var shtml = subs.length ? '<div class="table-scroll"><table><thead><tr><th>Student</th><th>Score</th><th>Result</th><th>Time</th><th>At</th></tr></thead><tbody>' + subs.map(function(s){
          var pct = s.percentage === null || s.percentage === undefined ? '—' : Number(s.percentage).toFixed(1) + '%';
          var cls = (s.passed === null || s.passed === undefined) ? 'tag-dim' : (s.passed ? 'tag-pass' : 'tag-fail');
          var txt = (s.passed === null || s.passed === undefined) ? 'n/s' : (s.passed ? 'PASSED' : 'FAILED');
          return '<tr><td><div>' + esc((s.first_name || '') + ' ' + (s.last_name || '')) + '</div><div style="font-size:11px;color:var(--ink-soft);">' + esc(s.section || '') + '</div></td><td class="mono">' + (s.score != null ? s.score : '—') + ' pts · ' + pct + '</td><td><span class="tag ' + cls + '">' + txt + '</span></td><td class="mono" style="font-size:12px;">' + fmtSecs(s.time_used_secs) + '</td><td style="font-size:11.5px;color:var(--ink-soft);">' + dstr(s.submitted_at) + '</td></tr>';
        }).join('') + '</tbody></table></div>' : '<div class="empty-state">No submissions for this assessment yet.</div>';
        document.getElementById('edBody').innerHTML =
          '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:6px;"><span class="chip">Duration <b>' + (exm.duration_minutes || 0) + ' min</b></span><span class="chip">Passing <b>' + (exm.passing_score || 0) + '%</b></span><span class="chip">Questions <b>' + (exm.question_count || 0) + '</b></span><span class="chip">Points <b>' + (exm.points_count || 0) + '</b></span></div>'
          + '<h4 style="margin:16px 0 6px;color:var(--navy-900);">Questions (' + qs.length + ')</h4>'
          + '<div style="max-height:220px;overflow-y:auto;border:1px solid var(--line);border-radius:8px;">' + qhtml + '</div>'
          + '<h4 style="margin:16px 0 6px;color:var(--navy-900);">Submissions (' + subs.length + ')</h4>'
          + '<div style="max-height:260px;overflow-y:auto;border:1px solid var(--line);border-radius:8px;">' + shtml + '</div>';
      })
      .catch(function(err){
        document.getElementById('edAlert').textContent = err.message || 'Failed to load assessment.';
        document.getElementById('edAlert').classList.add('show');
      });
  }

  /* ---------- LOGS ---------- */
  document.querySelectorAll('#view-logs .log-chip').forEach(function(chip){
    chip.addEventListener('click', function(){
      document.querySelectorAll('#view-logs .log-chip').forEach(function(c){ c.classList.remove('active'); });
      chip.classList.add('active');
      applyLogFilter();
    });
  });
  var logSearch = document.getElementById('logSearch');
  var logFrom = document.getElementById('logFrom');
  var logTo = document.getElementById('logTo');
  function applyLogFilter(){
    var f = document.querySelector('#view-logs .log-chip.active');
    var action = f ? (f.getAttribute('data-filter') || 'All') : 'All';
    var q = (logSearch.value || '').toLowerCase().trim();
    var from = logFrom.value, to = logTo.value;
    document.querySelectorAll('#logTable tbody tr').forEach(function(tr){
      var okAction = action === 'All' || tr.getAttribute('data-action') === action;
      var okSearch = !q || tr.getAttribute('data-search').indexOf(q) !== -1;
      var w = tr.getAttribute('data-when');
      var okDate = (!from || w >= from) && (!to || w <= to);
      tr.style.display = (okAction && okSearch && okDate) ? '' : 'none';
    });
  }
  logSearch.addEventListener('input', applyLogFilter);
  logFrom.addEventListener('change', applyLogFilter);
  logTo.addEventListener('change', applyLogFilter);

  /* ---------- MAINTENANCE ---------- */
  var lastKill = null;
  document.querySelectorAll('[data-kill]').forEach(function(b){
    b.addEventListener('click', function(){
      lastKill = { session_id: b.getAttribute('data-kill'), user: b.getAttribute('data-user') };
      document.getElementById('killName').textContent = lastKill.user || '';
      openMd('modal-kill');
    });
  });
  var killForm = document.querySelector('#view-maintenance [data-kill-user-form]');
  if (killForm) killForm.addEventListener('submit', function(e){
    e.preventDefault();
    var raw = [];
    (killForm.user_ids.value.split(/[,\s]+/)).forEach(function(s){
      var n = parseInt(s, 10);
      if (Number.isFinite(n) && n > 0) raw.push(n);
    });
    if (!raw.length) { killForm.user_ids.focus(); return; }
    lastKill = { user_ids: raw, user: 'user id(s) ' + raw.join(', ') };
    document.getElementById('killName').textContent = lastKill.user;
    openMd('modal-kill');
  });
  document.getElementById('killConfirm').addEventListener('click', function(){
    if (!lastKill) return;
    var b = this;
    b.disabled = true;
    postAjax('session_kill', lastKill)
      .then(function(res){
        if (res.success) { window.location.reload(); }
        else { alert(res.error || 'Could not end session.'); b.disabled = false; closeMd('modal-kill'); }
      })
      .catch(function(){ alert('Network error.'); b.disabled = false; closeMd('modal-kill'); });
  });
</script>

</body>
</html>