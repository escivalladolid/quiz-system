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
      <li class="nav-item" data-view="reports">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/></svg>
        System-Wide Reports
      </li>
      <li class="nav-item" data-view="maintenance">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
        System Maintenance
      </li>
      <li class="nav-item" data-view="screens">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>
        All Screens
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
          <button class="btn btn-amber" onclick="document.getElementById('modal-create').classList.add('open')">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14"/></svg>
            Create User
          </button>
        </div>

        <div class="table-panel">
          <div class="table-scroll">
          <table id="userTable">
            <thead>
              <tr>
                <th>ID</th>
                <th>Name</th>
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
                <tr data-role="<?php echo e($roleName); ?>" data-status="<?php echo e($status); ?>" data-name="<?php echo e(strtolower(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')))); ?>">
                  <td class="id-cell"><?php echo e($idDisp); ?></td>
                  <td class="name-cell"><strong><?php echo e(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))); ?></strong><span><?php echo e($u['email'] ?? ''); ?></span></td>
                  <td><span class="role-badge <?php echo $roleCls; ?>"><?php echo e($roleName); ?></span></td>
                  <td><?php echo e($assign); ?></td>
                  <td><span class="status-badge <?php echo $statusCls; ?>"><span class="status-dot"></span><?php echo e(ucfirst(strtolower($status))); ?></span></td>
                  <td class="mono" style="font-size:12px;color:var(--ink-soft);"><?php echo e($created); ?></td>
                  <td>
                    <div class="row-actions">
                      <a class="icon-btn" href="users.php?search=<?php echo e(urlencode($u['username'] ?? '')); ?>" title="Manage in User Management">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5Z"/></svg>
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!count($users)): ?>
                <tr><td colspan="7" class="id-cell" style="text-align:center;padding:26px;">No users found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
          </div>
          <div class="table-note"><?php echo number_format(count($users)); ?> account(s) shown · full management (bulk suspend/ban, status toggles) lives in the User Management screen</div>
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
        <div class="export-row">
          <a class="btn btn-ghost btn-sm" href="logs.php">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export CSV
          </a>
          <a class="btn btn-primary btn-sm" href="reports.php">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Full Reports
          </a>
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
        <div class="maint-grid">
          <div class="maint-card">
            <h3>Database &amp; Sessions</h3>
            <p>Monitor active user sessions and terminate compromised or duplicate sign-ins platform-wide.</p>
            <a class="btn btn-primary btn-sm" href="maintenance.php">Open Sessions Console</a>
            <div class="maint-meta"><span>Live exams</span><span class="mono"><?php echo number_format($liveExams); ?></span></div>
          </div>

          <div class="maint-card">
            <h3>School Year Management</h3>
            <p>Open or close the active academic period, and archive the previous year's records for reporting.</p>
            <a class="btn btn-primary btn-sm" href="maintenance.php">Manage Academic Period</a>
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
              <div class="toggle-row-text"><strong>Maintenance mode</strong><span>Managed from the Sessions Console</span></div>
              <div class="switch"></div>
            </div>
          </div>

          <div class="maint-card">
            <h3>System Logs</h3>
            <p>Raw login, submission, and error logs for troubleshooting and audit purposes.</p>
            <a class="btn btn-ghost btn-sm" href="logs.php">View Full Logs</a>
            <div class="maint-meta"><span>Audit trail</span><span class="mono">activity_logs</span></div>
          </div>
        </div>
      </div>
    </div>

    <!-- ============ ALL SCREENS VIEW ============ -->
    <div class="view" id="view-screens">
      <div class="topbar">
        <div class="topbar-title">
          <div class="hamburger" onclick="toggleSidebar()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
          </div>
          <div>
          <h1>All Screens</h1>
          <div class="page-sub">Every module of the admin panel, including full-scale management screens</div>
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
        <div class="maint-grid">
          <div class="maint-card">
            <h3>User Management</h3>
            <p>Full user CRUD, bulk suspend/ban/activate, search and filters.</p>
            <a class="btn btn-primary btn-sm" href="users.php">Open User Management</a>
          </div>
          <div class="maint-card">
            <h3>Class Management</h3>
            <p>Classes, sections, teacher assignments, and roster management.</p>
            <a class="btn btn-primary btn-sm" href="classes.php">Open Class Management</a>
          </div>
          <div class="maint-card">
            <h3>Assessment Oversight</h3>
            <p>Exams, scheduling, force-close, and per-assessment drill-down.</p>
            <a class="btn btn-primary btn-sm" href="assessments.php">Open Assessment Oversight</a>
          </div>
          <div class="maint-card">
            <h3>Reports &amp; Analytics</h3>
            <p>Full institution-level analytics, per-class and per-exam breakdowns.</p>
            <a class="btn btn-primary btn-sm" href="reports.php">Open Reports &amp; Analytics</a>
          </div>
          <div class="maint-card">
            <h3>System Logs</h3>
            <p>Raw audit trail with filters for review.</p>
            <a class="btn btn-primary btn-sm" href="logs.php">Open System Logs</a>
          </div>
          <div class="maint-card">
            <h3>System Maintenance</h3>
            <p>Sessions console, hardware status, and platform-level maintenance.</p>
            <a class="btn btn-primary btn-sm" href="maintenance.php">Open Maintenance</a>
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

<script>
  var RMC_ADMIN = <?php echo json_encode([
      'name' => $adminShort,
      'selfId' => (int) ($admin['user_id'] ?? 0),
  ]); ?>;

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

  document.querySelectorAll('.filter-chip').forEach(function(chip){
    chip.addEventListener('click', function(){
      var filter = chip.getAttribute('data-filter') || 'All';
      document.querySelectorAll('.filter-chip').forEach(function(c){ c.classList.remove('active'); });
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
</script>

</body>
</html>