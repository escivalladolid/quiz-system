<?php
require_once __DIR__ . '/inc/bootstrap.php';
admin_require_login();

$admin = $_SESSION['admin_user'];

$db_online = true;
$data = ['overall' => null, 'classes' => [], 'weakest' => [], 'daily' => []];

$res = admin_api_request('GET', 'admin/reports.php', [], (string) ($admin['token'] ?? ''));
if (admin_api_response_ok($res)) {
    $data = admin_api_data($res, $data);
    $data['classes'] = admin_array_rows($data['classes'] ?? null);
    $data['weakest'] = admin_array_rows($data['weakest'] ?? null);
    $data['daily'] = admin_array_rows($data['daily'] ?? null);
} else {
    $db_online = false;
}

$overall = is_array($data['overall'] ?? null) ? $data['overall'] : null;
$classes = admin_array_rows($data['classes'] ?? null);
$weakest = admin_array_rows($data['weakest'] ?? null);
$daily   = admin_array_rows($data['daily'] ?? null);
$maxDaily = 1;
foreach ($daily as $d) { $maxDaily = max($maxDaily, (int) ($d['count'] ?? 0)); }

$page_title = 'System-Wide Reports';
$active_nav = 'reports';
require_once __DIR__ . '/inc/header.php';
?>
<?php if (!$db_online): ?>
  <div class="notice bad"><b>Live data unavailable</b> — the backend/database is not reachable right now.</div>
<?php endif; ?>

<div class="stat-grid" style="grid-template-columns:repeat(4,1fr);">
  <div class="stat-card">
    <div class="stat-label">Overall Average</div>
    <div class="stat-value"><?php echo $overall ? number_format((float) ($overall['avg_pct'] ?? 0), 1) . '%' : '—'; ?></div>
    <div class="stat-sub">across <?php echo $overall ? (int) ($overall['exam_count'] ?? 0) : 0; ?> assessments</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Pass Rate</div>
    <div class="stat-value"><?php echo $overall ? number_format((float) ($overall['pass_rate'] ?? 0), 1) . '%' : '—'; ?></div>
    <div class="stat-sub"><?php echo $overall ? (int) ($overall['pass_count'] ?? 0) . ' of ' . (int) ($overall['submission_count'] ?? 0) . ' submissions passed' : ''; ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Submissions</div>
    <div class="stat-value"><?php echo $overall ? number_format((int) ($overall['submission_count'] ?? 0)) : '—'; ?></div>
    <div class="stat-sub"><?php echo $overall ? number_format((int) ($overall['attempts_users'] ?? 0)) . ' distinct students' : ''; ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Exam Footprint</div>
    <div class="stat-value"><?php echo $overall ? (int) ($overall['exam_count'] ?? 0) : '—'; ?></div>
    <div class="stat-sub"><?php echo $overall ? (int) ($overall['active_classes'] ?? 0) . ' active classes' : ''; ?></div>
  </div>
</div>

<div class="dash-grid">
  <div style="min-width:0;">
    <section class="panel section-gap">
      <div class="panel-head"><h3>Class Averages</h3><span class="muted">avg % and pass rate per class</span></div>
      <?php if (empty($classes)): ?>
        <div class="empty-state"><span class="big">&#128202;</span>No classes to report on<?php echo $db_online ? '' : ' (backend unreachable)'; ?>.</div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th>Class</th>
                <th style="width:34%;">Average</th>
                <th>Submissions</th>
                <th>Pass Rate</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($classes as $c): ?>
                <tr>
                  <td>
                    <div><?php echo e(trim(($c['subject_name'] ?? '') . ' · ' . ($c['subject_code'] ?? ''))); ?></div>
                    <div style="font-size:11.5px;color:var(--ink-400);"><?php echo e(trim(($c['block'] ?? '') . ' · ' . ($c['exam_count'] ?? 0) . ' exam(s)')); ?></div>
                  </td>
                  <td>
                    <div style="display:flex;align-items:center;gap:10px;">
                      <div class="progress-track" style="flex:1;"><div class="progress-fill" style="width:<?php echo min(100, (float) ($c['avg_pct'] ?? 0)); ?>%;"></div></div>
                      <span class="mono" style="font-size:12.5px;"><?php echo number_format((float) ($c['avg_pct'] ?? 0), 1); ?>%</span>
                    </div>
                  </td>
                  <td><span class="mono"><?php echo (int) ($c['submission_count'] ?? 0); ?></span></td>
                  <td>
                    <?php if ((int) ($c['pass_rate'] ?? 0) >= 60): ?>
                      <span class="tag tag-pass"><?php echo number_format((float) ($c['pass_rate'] ?? 0), 1); ?>%</span>
                    <?php elseif ((int) ($c['pass_rate'] ?? 0) >= 40): ?>
                      <span class="tag tag-royal"><?php echo number_format((float) ($c['pass_rate'] ?? 0), 1); ?>%</span>
                    <?php else: ?>
                      <span class="tag tag-fail"><?php echo number_format((float) ($c['pass_rate'] ?? 0), 1); ?>%</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="panel">
      <div class="panel-head"><h3>Submissions — last 14 days</h3><span class="muted"><?php echo array_sum(array_column($daily, 'count')); ?> total</span></div>
      <?php if (empty($daily)): ?>
        <div class="empty-state"><span class="big">&#128200;</span>No activity to chart.</div>
      <?php else: ?>
        <div class="chart">
          <?php foreach ($daily as $d): ?>
            <?php $dayCount = (int) ($d['count'] ?? 0); $h = ($dayCount / $maxDaily) * 100; ?>
            <div class="chart-bar <?php echo $dayCount === 0 ? 'zero' : ''; ?>" style="height:<?php echo max(3, $h); ?>%;" data-count="<?php echo $dayCount; ?>"></div>
          <?php endforeach; ?>
        </div>
        <div class="chart-x">
          <?php foreach ($daily as $d): ?>
            <span><?php echo e(date('d', strtotime((string) ($d['date'] ?? '')))); ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>

  <aside class="rail">
    <section class="panel">
      <div class="panel-head"><h3>Weakest Assessments</h3><span class="muted">lowest average first</span></div>
      <?php if (empty($weakest)): ?>
        <div class="empty-state"><span class="big">&#128161;</span>No submitted assessments to rank yet.</div>
      <?php else: ?>
        <?php foreach ($weakest as $w): ?>
          <div class="weak-row">
            <span class="w-avg" title="average score"><?php echo number_format((float) ($w['avg_pct'] ?? 0), 1); ?>%</span>
            <div class="w-body">
              <div class="w-name"><?php echo e($w['exam_name'] ?? 'Unnamed assessment'); ?></div>
              <div class="w-sub"><?php echo e(trim(($w['subject_code'] ?? '') . ' · ' . ($w['block'] ?? ''))); ?> · <?php echo (int) ($w['submission_count'] ?? 0); ?> sub(s) · pass <?php echo (int) ($w['passing_score'] ?? 0); ?>%</div>
            </div>
            <span class="tag <?php echo (float) ($w['pass_rate'] ?? 0) >= 60 ? 'tag-pass' : ((float) ($w['pass_rate'] ?? 0) >= 40 ? 'tag-royal' : 'tag-fail'); ?>"><?php echo number_format((float) ($w['pass_rate'] ?? 0), 0); ?>%</span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
  </aside>
</div>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
