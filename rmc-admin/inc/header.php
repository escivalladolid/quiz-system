<?php
/**
 * Persistent admin shell header.
 * Screen pages set $page_title and $active_nav, then require this file.
 * Sets $GLOBALS['db_online'] to control the topbar status chip (default: online).
 */
require_once __DIR__ . '/bootstrap.php';
admin_require_login();

$admin = $_SESSION['admin_user'];
$page_title = $page_title ?? 'Admin';
$active_nav = $active_nav ?? 'users';
$db_online = $GLOBALS['db_online'] ?? true;

$user_thread = trim(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? ''));
$initials = strtoupper(($admin['first_name'][0] ?? 'A') . ($admin['last_name'][0] ?? 'D'));

$nav_items = [
    ['key' => 'dashboard',    'href' => admin_url('dashboard'),   'label' => 'Dashboard',          'soon' => null, 'icon' => '<rect x="2.7" y="2.9" width="4.3" height="5.4" rx="1.1"/><rect x="9" y="2.9" width="4.3" height="2.6" rx="1.1"/><rect x="9" y="7.5" width="4.3" height="5.6" rx="1.1"/><rect x="2.7" y="10.3" width="4.3" height="2.9" rx="1.1"/>'],
    ['key' => 'users',        'href' => admin_url('users'),        'label' => 'User Management',    'soon' => null, 'icon' => '<circle cx="6" cy="6.2" r="2.2"/><path d="M2.8 13.2c0-1.9 1.4-3 3.2-3s3.2 1.1 3.2 3"/><path d="M10.6 3.9a2.2 2.2 0 1 1 0 4.3"/><path d="M12 10.4c1.7.3 3.2 1.4 3.2 3.2"/>'],
    ['key' => 'classes',      'href' => admin_url('classes'),      'label' => 'Class Management',   'soon' => null, 'icon' => '<path d="M2.5 9.3 8 4.3l5.5 5"/><path d="M4.5 8.6V13h7V8.6"/><path d="M6.5 13v-2.6h3V13"/>'],
    ['key' => 'assessments',  'href' => admin_url('assessments'), 'label' => 'Assessment Oversight','soon' => null, 'icon' => '<rect x="4" y="2.5" width="8" height="11" rx="1.4"/><path d="M6.5 5.8h3"/><path d="M6.5 8.3h3"/><path d="M6.5 10.8h1.8"/><path d="M9.3 14.2 10.4 15.5 13 12.6"/>'],
    ['key' => 'reports',      'href' => admin_url('reports'),     'label' => 'Reports & Analytics','soon' => null, 'icon' => '<path d="M4 13h8"/><path d="M5.4 7.4h1.6v5.6H5.4z"/><path d="M8.3 4.6h1.6v8.4H8.3z"/>'],
    ['key' => 'logs',         'href' => admin_url('logs'),        'label' => 'System Logs',        'soon' => null, 'icon' => '<circle cx="8" cy="8" r="5.6"/><path d="M8 5.2V8l2 1.6"/>'],
    ['key' => 'maintenance',  'href' => admin_url('maintenance'),'label' => 'Maintenance',        'soon' => null, 'icon' => '<path d="M2.6 4.4h5.6"/><path d="M11.6 4.4h1.8"/><circle cx="9.8" cy="4.4" r="1.6"/><path d="M2.6 9.6h1.8"/><path d="M7.8 9.6h5.6"/><circle cx="6" cy="9.6" r="1.6"/><path d="M2.6 14.8h5.6"/><path d="M11.6 14.8h1.8"/><circle cx="9.8" cy="14.8" r="1.6"/>'],
];

function nav_svg(string $inner): string {
    return '<svg class="nav-ico" viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($page_title); ?> — RMC Quiz &amp; Exam</title>
<script>
try {
  var t = localStorage.getItem('rmc_theme') || 'light';
  if (t === 'auto' && window.matchMedia) {
    t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  if (t === 'light' || t === 'dark') {
    document.documentElement.setAttribute('data-theme', t);
  }
} catch (e) {}
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/admin.css">
</head>
<body class="shell">
<div class="app">
  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="sidebar-brand-mark">RMC</div>
      <div class="sidebar-brand-text">
        <h1>Regis Marie College</h1>
        <span>Admin Console</span>
      </div>
    </div>
    <nav class="sidebar-nav">
      <?php foreach ($nav_items as $i => $item): ?>
        <?php if ($item['key'] === 'reports'): ?><div class="nav-label">Data &amp; Oversight</div><?php endif; ?>
        <div class="nav-item">
          <a class="nav-link<?php echo $active_nav === $item['key'] ? ' nav-active' : ''; ?><?php echo $item['soon'] !== null ? ' is-soon' : ''; ?>"
             href="<?php echo e($item['href']); ?>"
             <?php if ($item['soon'] !== null): ?>title="Built in Screen <?php echo (int) $item['soon']; ?>"<?php endif; ?>>
            <?php echo nav_svg($item['icon']); ?>
            <span><?php echo e($item['label']); ?></span>
            <?php if ($item['soon'] !== null): ?><span class="nav-badge">S<?php echo (int) $item['soon']; ?></span><?php endif; ?>
          </a>
        </div>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
      <div class="who">
        <b><?php echo e($user_thread); ?></b>
        <span>Administrator</span>
      </div>
      <a class="logout-link" href="<?= e(admin_url('logout')) ?>" title="Log out">
        <svg viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2.8 8h7.6"/><path d="M8.4 4.8 11.6 8 8.4 11.2"/><path d="M6 2.6H2.8v10.8H6"/></svg>
      </a>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="topbar-btn" id="btnSidebar" title="Toggle sidebar">
        <svg viewBox="0 0 16 16" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M2.5 4h11"/><path d="M2.5 8h11"/><path d="M2.5 12h6.5"/></svg>
      </button>
      <div class="topbar-title">
        <h2><?php echo e($page_title); ?></h2>
        <span>RMC Mobile-Based Quiz &amp; Examination System</span>
      </div>
      <div class="topbar-search">
        <span class="search-ico">
          <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><circle cx="7" cy="7" r="4.6"/><path d="m10.6 10.6 2.9 2.9"/></svg>
        </span>
        <input class="input" type="search" placeholder="Search…" aria-label="Search">
      </div>
      <span class="status-chip <?php echo $db_online ? 'ok' : 'bad'; ?>" title="Live backend status">
        <?php echo $db_online ? 'All systems nominal' : 'Backend unreachable'; ?>
      </span>
      <div class="avatar-menu">
        <button class="avatar" id="btnAvatar" title="Account menu"><?php echo e($initials); ?></button>
        <div class="dropdown" id="avatarDropdown">
          <div class="dropdown-head">
            <b><?php echo e($user_thread); ?></b>
            <span><?php echo e($admin['email'] ?? ''); ?> · ADMIN</span>
          </div>
          <a class="dropdown-item" href="users.php">
            <svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"><rect x="2.8" y="3" width="10.4" height="10" rx="1.6"/><path d="m2.8 6 5.2 3.4L13.2 6"/></svg>
            Inbox &amp; help
          </a>
          <a class="dropdown-item danger" href="<?= e(admin_url('logout')) ?>">
            <svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M2.8 8h7.6"/><path d="M8.4 4.8 11.6 8 8.4 11.2"/><path d="M6 2.6H2.8v10.8H6"/></svg>
            Log out
          </a>
        </div>
      </div>
    </header>
    <main class="content">