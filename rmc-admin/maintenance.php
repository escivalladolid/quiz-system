<?php
require_once __DIR__ . '/inc/bootstrap.php';
admin_require_login();

$admin = $_SESSION['admin_user'];

$db_online = true;
$data = ['db_now' => null, 'tz_offset_seconds' => 0, 'table_counts' => [], 'sessions' => [],
         'active_sessions' => 0, 'php_version' => PHP_VERSION];

$res = admin_api_request('GET', 'admin/maintenance.php', [], $admin['token']);
if ($res['http_code'] === 200 && ($res['body']['success'] ?? false) === true) {
    $data = array_merge($data, $res['body']['data']);
} else {
    $db_online = false;
}

$counts   = $data['table_counts'];
$sessions = $data['sessions'];
$api_base = rtrim(admin_api_base(), '/');

function human_remaining(string $expires): string {
    $diff = strtotime($expires) - time();
    if ($diff <= 0) return 'expired';
    $h = intdiv($diff, 3600);
    $m = intdiv($diff % 3600, 60);
    return ($h > 0 ? "{$h}h " : '') . "{$m}m";
}

$page_title = 'Maintenance';
$active_nav = 'maintenance';
require_once __DIR__ . '/inc/header.php';
?>
<?php if (!$db_online): ?>
  <div class="notice bad"><b>Live data unavailable</b> — the backend/database is not reachable right now.</div>
<?php endif; ?>

<section class="panel section-gap">
  <div class="panel-head"><h3>System Health</h3><span class="muted">environment &amp; connectivity</span></div>
  <div class="stat-grid" style="grid-template-columns:repeat(4,1fr);">
    <div class="stat-tile">
      <div class="lbl">Database</div>
      <div class="num" style="font-size:15px;"><?php echo $db_online ? 'ONLINE' : 'OFFLINE'; ?></div>
      <div class="lbl" style="margin-top:6px;"><?php echo $db_online ? e('now: ' . $data['db_now']) : 'backend/DB not reachable'; ?></div>
    </div>
    <div class="stat-tile">
      <div class="lbl">Timezone offset</div>
      <div class="num" style="font-size:15px;"><?php echo $db_online ? sprintf('%+dh', (int) ($data['tz_offset_seconds'] / 3600)) . ' ' . sprintf('%02d:%02d', intdiv((int) $data['tz_offset_seconds'], 3600), (intdiv(abs((int) $data['tz_offset_seconds']), 60) % 60)) : '—'; ?></div>
      <div class="lbl" style="margin-top:6px;">server now: <?php echo e(date('M j, Y g:i A')); ?></div>
    </div>
    <div class="stat-tile">
      <div class="lbl">PHP</div>
      <div class="num" style="font-size:15px;"><?php echo e(PHP_VERSION); ?></div>
      <div class="lbl" style="margin-top:6px;"><?php echo e(PHP_INT_SIZE === 8 ? '64-bit' : '32-bit'); ?></div>
    </div>
    <div class="stat-tile">
      <div class="lbl">API base</div>
      <div class="num mono" style="font-size:12.5px;word-break:break-all;"><?php echo e($api_base); ?></div>
      <div class="lbl" style="margin-top:6px;">derived from request host</div>
    </div>
  </div>
</section>

<div class="dash-grid">
  <div style="min-width:0;">
    <section class="panel">
      <div class="panel-head">
        <h3>Data Inventory</h3>
        <span class="muted">row counts across core tables</span>
      </div>
      <div class="table-wrap">
        <?php if (empty($counts)): ?>
          <div class="empty-state"><span class="big">&#128190;</span>No table data available.</div>
        <?php else: ?>
          <table class="data-table">
            <thead><tr><th>Table</th><th>Rows</th></tr></thead>
            <tbody>
              <?php foreach ($counts as $name => $n): ?>
                <tr>
                  <td><span class="mono" style="color:var(--ink-800);"><?php echo e($name); ?></span></td>
                  <td><span class="mono"><?php echo number_format($n); ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <aside class="rail">
    <section class="panel">
      <div class="panel-head"><h3>Active Sessions</h3><span class="muted"><?php echo (int) $data['active_sessions']; ?> total</span></div>
      <div class="table-wrap">
        <?php if (empty($sessions)): ?>
          <div class="empty-state"><span class="big">&#128273;</span>No sessions right now.</div>
        <?php else: ?>
          <table class="data-table">
            <thead><tr><th>User</th><th>Role</th><th>Expires</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($sessions as $s): ?>
                <?php $own = $admin['token'] === $s['session_id']; ?>
                <tr>
                  <td>
                    <div><?php echo e(trim($s['first_name'] . ' ' . $s['last_name'])); ?> <?php if ($own): ?><span class="tag tag-royal" style="padding:0 5px;font-size:9px;">YOU</span><?php endif; ?></div>
                    <div style="font-size:11px;color:var(--ink-400);">@<?php echo e($s['username']); ?></div>
                  </td>
                  <td><span class="tag tag-navy"><?php echo e($s['role_name']); ?></span></td>
                  <td class="mono" style="font-size:12px;"><?php echo e(human_remaining($s['expires_at'])); ?></td>
                  <td>
                    <?php if (!$own): ?>
                      <button class="btn-filter" data-kill="<?php echo e($s['session_id']); ?>" data-user="<?php echo e(trim($s['first_name'] . ' ' . $s['last_name'])); ?>">End</button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
      <form class="filter-bar" style="margin-top:12px;" data-kill-user-form>
        <input class="input" type="text" name="user_ids" placeholder="End all sessions for user id(s) — e.g. 3 7 12" style="flex:1;">
        <button class="btn-filter" type="submit">End sessions</button>
      </form>
    </section>
  </aside>
</div>

<section class="panel section-gap">
  <div class="panel-head"><h3>Admin Guide &amp; Operations</h3><span class="muted">Help Center content</span></div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;">
    <div class="soft-tip">
      <h4>Getting started</h4>
      <p>Manage students, teachers and sections from <b>Users</b>. Build class rosters under <b>Classes</b>. Oversee every exam (force-close a live exam, schedule or reopen windows, archive) under <b>Assessments</b> &raquo; drill into any exam for per-submission results.</p>
    </div>
    <div class="soft-tip">
      <h4>Defaults &amp; security</h4>
      <p>Seeded admin: <span class="mono">admin</span> / <span class="mono">admin123</span> (change it via Users &raquo; it uses the same password policy, 8+ chars with a digit). Suspending or banning a user signs out every one of their sessions immediately.</p>
    </div>
    <div class="soft-tip">
      <h4>Scoring</h4>
      <p>Percentages are derived from raw earned points: <span class="mono">score / SUM(points) &times; 100</span>. Pass/fail compares that percentage to the exam&rsquo;s passing threshold. Items are all-or-nothing &mdash; no partial credit.</p>
    </div>
    <div class="soft-tip">
      <h4>Backups &amp; deploy</h4>
      <p>Use the migration files in <span class="mono">database/</span> (schema, migration_score_raw_points.sql). This panel and the PHP API are plain PHP &mdash; deploy the same tree to Render; the API base auto-derives to the hosting host, so no config changes are needed.</p>
    </div>
  </div>
</section>

<div id="killModal" class="modal-bg" hidden>
  <div class="modal">
    <h3>End session</h3>
    <p style="color:var(--ink-600);font-size:13.5px;margin:10px 0 16px;">Sign out <b id="killName"></b>. This revokes their API token immediately &mdash; the student will be returned to the login screen on their next action.</p>
    <div class="modal-actions">
      <button class="btn" id="killCancel">Cancel</button>
      <button class="btn primary" id="killConfirm">End session</button>
    </div>
  </div>
</div>

<script>
(function () {
  var lastKill = null;
  function openKill(payload) {
    if (!payload) return;
    lastKill = payload;
    document.getElementById('killName').textContent = payload.user || '';
    document.getElementById('killModal').hidden = false;
  }
  function closeKill() {
    lastKill = null;
    document.getElementById('killModal').hidden = true;
  }
  document.querySelectorAll('[data-kill]').forEach(function (b) {
    b.addEventListener('click', function () {
      openKill({ session_id: b.dataset.kill, user: b.dataset.user });
    });
  });
  var form = document.querySelector('[data-kill-user-form]');
  if (form) form.addEventListener('submit', function (e) {
    e.preventDefault();
    var raw = form.user_ids.value.split(/\s+/)
      .map(function (s) { return parseInt(s, 10); })
      .filter(function (n) { return Number.isFinite(n) && n > 0; });
    if (!raw.length) { form.user_ids.focus(); return; }
    openKill({ user_ids: raw, user: 'user id(s) ' + raw.join(', ') });
  });
  document.getElementById('killCancel').addEventListener('click', closeKill);
  document.getElementById('killConfirm').addEventListener('click', function () {
    var b = this;
    var body = lastKill;
    if (!body) return;
    b.disabled = true;
    fetch('ajax.php?action=session_kill', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.RMC_CSRF },
      body: JSON.stringify(body)
    })
    .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
    .then(function (res) {
      if (res.ok && res.j.success) { location.reload(); }
      else { alert(res.j.error || 'Could not end session.'); b.disabled = false; closeKill(); }
    })
    .catch(function () { alert('Network error.'); b.disabled = false; closeKill(); });
  });
})();
</script>

<?php require_once __DIR__ . '/inc/footer.php'; ?>