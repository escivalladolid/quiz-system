<?php
require_once __DIR__ . '/inc/bootstrap.php';
admin_require_login();

$admin = $_SESSION['admin_user'];

$status  = strtoupper(trim($_GET['status'] ?? ''));
$classId = (int) ($_GET['class_id'] ?? 0);
$search  = trim($_GET['search'] ?? '');
$page    = max(1, (int) ($_GET['page'] ?? 1));

$params = ['page' => $page, 'per_page' => 12];
if (in_array($status, ['DRAFT', 'SCHEDULED', 'LIVE', 'CLOSED', 'ARCHIVED'], true)) { $params['status'] = $status; }
if ($classId > 0) { $params['class_id'] = $classId; }
if ($search !== '') { $params['search'] = $search; }

$db_online = true;
$data = ['exams' => [], 'total' => 0, 'pages' => 1, 'page' => 1,
         'summary' => ['DRAFT' => 0, 'SCHEDULED' => 0, 'LIVE' => 0, 'CLOSED' => 0, 'ARCHIVED' => 0]];
$classesOption = [];

$res = admin_api_request('GET', 'admin/exams.php?' . http_build_query($params), [], $admin['token']);
if ($res['http_code'] === 200 && ($res['body']['success'] ?? false) === true) {
    $data = array_merge($data, $res['body']['data']);
} else {
    $db_online = false;
}
$res2 = admin_api_request('GET', 'admin/classes.php?per_page=50', [], $admin['token']);
if ($res2['http_code'] === 200 && ($res2['body']['success'] ?? false) === true) {
    $classesOption = $res2['body']['data']['classes'] ?? [];
}

$exams  = $data['exams'];
$total  = (int) $data['total'];
$pages  = (int) $data['pages'];
$page   = (int) $data['page'];
$summary = $data['summary'];

$STATUS_TAGS = ['DRAFT' => 'tag-dim', 'SCHEDULED' => 'tag-royal', 'LIVE' => 'tag-pass', 'CLOSED' => 'tag-navy', 'ARCHIVED' => 'tag-arch'];

function qs(array $extra): string {
    global $status, $classId, $search;
    $all = ['status' => $status, 'class_id' => $classId > 0 ? $classId : '', 'search' => $search] + $extra;
    $all = array_filter($all, function ($v) { return $v !== '' && $v !== null; });
    return http_build_query($all);
}

$page_title = 'Assessment Oversight';
$active_nav = 'assessments';
require_once __DIR__ . '/inc/header.php';
?>
<?php if (!$db_online): ?>
  <div class="notice bad"><b>Live data unavailable</b> — the backend/database is not reachable right now.</div>
<?php endif; ?>

<div class="list-toolbar">
  <div>
    <h2>Assessments</h2>
    <div class="muted" style="font-size:12px;color:var(--ink-400);margin-top:2px;">Administrative view of every exam across all classes.</div>
  </div>
</div>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
  <?php foreach (['DRAFT', 'SCHEDULED', 'LIVE', 'CLOSED', 'ARCHIVED'] as $st): ?>
    <?php
      $chipParams = ['status' => $st];
      if ($classId > 0) { $chipParams['class_id'] = $classId; }
      if ($search !== '') { $chipParams['search'] = $search; }
      $chipHref = 'assessments.php?' . http_build_query($chipParams);
      $activeChip = $status === $st;
    ?>
    <a href="<?php echo e($chipHref); ?>" class="status-chip <?php echo $STATUS_TAGS[$st]; ?>"
       style="text-decoration:none;<?php echo $activeChip ? 'outline:2px solid var(--accent-500);outline-offset:2px;' : ''; ?>">
      <?php echo e($st); ?> · <?php echo (int) $summary[$st]; ?>
    </a>
  <?php endforeach; ?>
</div>

<form class="filter-bar" method="get" action="assessments.php">
  <select class="input" name="class_id" aria-label="Class">
    <option value="0">All classes</option>
    <?php foreach ($classesOption as $c): ?>
      <option value="<?php echo (int) $c['class_id']; ?>" <?php echo $classId === (int) $c['class_id'] ? 'selected' : ''; ?>>
        <?php echo e(trim($c['subject_code'] . ' · ' . $c['class_code'] . ($c['block'] ? ' · ' . $c['block'] : ''))); ?>
      </option>
    <?php endforeach; ?>
  </select>
  <input class="input" type="search" name="search" value="<?php echo e($search); ?>" placeholder="Search exam or subject…">
  <button class="btn-filter" type="submit">Apply</button>
  <?php if ($status !== '' || $classId > 0 || $search !== ''): ?>
    <a class="btn-filter" href="assessments.php" style="text-decoration:none;">Clear</a>
  <?php endif; ?>
</form>

<section class="panel">
  <div class="table-tools"><span class="muted"><?php echo number_format($total); ?> assessment(s)</span></div>
  <div class="table-wrap">
    <?php if (empty($exams)): ?>
      <div class="empty-state">
        <span class="big">&#128218;</span>
        No assessments found<?php echo $db_online ? '' : ' (backend unreachable)'; ?>.<br>
        Assessments are created by teachers in the mobile app.
      </div>
    <?php else: ?>
      <table class="data-table">
        <thead>
          <tr>
            <th>Assessment</th>
            <th>Class</th>
            <th>Schedule</th>
            <th>Meta</th>
            <th>Submissions / Avg</th>
            <th>Status</th>
            <th class="actions-col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($exams as $e): ?>
            <?php
              $schedule = ($e['start_time'] && $e['end_time'])
                  ? e(date('M j, g:i A', strtotime($e['start_time'])) . ' → ' . date('g:i A', strtotime($e['end_time'])))
                  : '<span style="color:var(--ink-400);">not scheduled</span>';
              $avg = ((int) $e['submission_count'] > 0 && $e['avg_pct'] !== null)
                  ? number_format((float) $e['avg_pct'], 1) . '%'
                  : '—';
              $tagCls = $STATUS_TAGS[$e['status']] ?? 'tag-dim';
            ?>
            <tr data-id="<?php echo (int) $e['exam_id']; ?>" data-status="<?php echo e($e['status']); ?>">
              <td>
                <div><?php echo e($e['exam_name']); ?></div>
                <div style="font-size:11.5px;color:var(--ink-400);"><?php echo e($e['subject_code'] . ' · ' . $e['block']); ?></div>
              </td>
              <td><?php echo e($e['subject_name']); ?></td>
              <td style="font-size:12px;"><?php echo $schedule; ?></td>
              <td>
                <span class="mono"><?php echo (int) $e['question_count']; ?></span> q · <span class="mono"><?php echo (int) $e['points_count']; ?></span> pts<br>
                <span style="font-size:11px;color:var(--ink-400);">pass <?php echo (int) $e['passing_score']; ?>%</span>
              </td>
              <td>
                <span class="mono"><?php echo number_format((int) $e['submission_count']); ?></span> · avg <span class="mono"><?php echo $avg; ?></span>
              </td>
              <td><span class="tag <?php echo $tagCls; ?>"><?php echo e($e['status']); ?></span></td>
              <td class="actions-col">
                <a class="row-action" style="text-decoration:none;" href="exam_detail.php?id=<?php echo (int) $e['exam_id']; ?>" title="Review">
                  <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M1.8 8S4 3.8 8 3.8 14.2 8 14.2 8 12 12.2 8 12.2 1.8 8 1.8 8z"/><circle cx="8" cy="8" r="2"/></svg>
                </a>
                <?php if ($e['status'] === 'LIVE'): ?>
                  <button class="row-action danger" type="button" data-act="force_close" title="Force close">
                    <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><circle cx="8" cy="8" r="5.4"/><path d="m4.8 4.8 6.4 6.4"/></svg>
                  </button>
                <?php endif; ?>
                <?php if ($e['status'] !== 'ARCHIVED'): ?>
                  <button class="row-action" type="button" data-act="archive" title="Archive">
                    <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h10l-.8 7H3.8L3 6z"/><path d="M6 6V4h4v2"/><path d="M2.4 3.6h11.2"/></svg>
                  </button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <?php if ($pages > 1): ?>
    <div class="pagination">
      <a class="pg <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo e($page > 1 ? 'assessments.php?' . qs(['page' => $page - 1]) : '#'); ?>">&laquo;</a>
      <?php for ($p = 1; $p <= $pages; $p++): ?>
        <?php if ($p === $page): ?>
          <span class="pg cur"><?php echo $p; ?></span>
        <?php else: ?>
          <a class="pg" href="<?php echo e('assessments.php?' . qs(['page' => $p])); ?>"><?php echo $p; ?></a>
        <?php endif; ?>
      <?php endfor; ?>
      <a class="pg <?php echo $page >= $pages ? 'disabled' : ''; ?>" href="<?php echo e($page < $pages ? 'assessments.php?' . qs(['page' => $page + 1]) : '#'); ?>">&raquo;</a>
    </div>
  <?php endif; ?>
</section>

<!-- Confirm action modal -->
<div class="modal-overlay" id="modalConfirm">
  <div class="modal-card" style="max-width:400px;">
    <h3 class="modal-title" id="cfTitle">Are you sure?</h3>
    <p class="modal-sub" id="cfBody" style="margin-bottom:16px;"></p>
    <div class="alert alert-error hidden" id="cfAlert"></div>
    <div class="modal-actions">
      <button class="btn btn-outline" type="button" data-close="modalConfirm">Cancel</button>
      <button class="btn btn-primary" type="button" id="cfGo">Confirm</button>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';
  var A = function (action) { return 'ajax.php?action=' + action; };

  var cfTitle = document.getElementById('cfTitle');
  var cfBody = document.getElementById('cfBody');
  var cfAlert = document.getElementById('cfAlert');
  var cfGo = document.getElementById('cfGo');
  var cfAction = null;

  function askConfirm(title, body, fn) {
    cfTitle.textContent = title; cfBody.textContent = body;
    cfAlert.classList.add('hidden');
    cfAction = fn;
    document.getElementById('modalConfirm').classList.add('open');
  }
  function closeConfirm() { document.getElementById('modalConfirm').classList.remove('open'); }
  document.querySelectorAll('[data-close]').forEach(function (b) {
    b.addEventListener('click', function () { document.getElementById(b.getAttribute('data-close')).classList.remove('open'); });
  });
  document.querySelectorAll('.modal-overlay').forEach(function (ov) {
    ov.addEventListener('click', function (e) { if (e.target === ov) ov.classList.remove('open'); });
  });

  cfGo.addEventListener('click', function () {
    if (!cfAction) return;
    cfGo.disabled = true;
    cfAction(function (ok, msg) {
      cfGo.disabled = false;
      if (ok) { window.location.reload(); }
      else { cfAlert.textContent = msg || 'Request failed.'; cfAlert.classList.remove('hidden'); }
    });
  });

  document.querySelectorAll('[data-act]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var tr = btn.closest('tr');
      var id = Number(tr.getAttribute('data-id'));
      var act = btn.getAttribute('data-act');
      if (act === 'force_close') {
        askConfirm('Force close exam?', 'Stops the exam immediately. Students mid-exam will have their submission kept; further attempts are blocked.', function (done) {
          fetch(A('exam_status'), { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.RMC_CSRF }, body: JSON.stringify({ exam_id: id, action: 'force_close' }) })
            .then(function (r) { return r.json(); })
            .then(function (res) { res.success ? done(true) : done(false, res.error); })
            .catch(function () { done(false, 'Network error — is the backend running?'); });
        });
      } else if (act === 'archive') {
        askConfirm('Archive exam?', 'Hides the exam from teachers/students. Submissions are preserved; you can reschedule it later.', function (done) {
          fetch(A('exam_status'), { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.RMC_CSRF }, body: JSON.stringify({ exam_id: id, action: 'archive' }) })
            .then(function (r) { return r.json(); })
            .then(function (res) { res.success ? done(true) : done(false, res.error); })
            .catch(function () { done(false, 'Network error — is the backend running?'); });
        });
      }
    });
  });
})();
</script>
<?php require_once __DIR__ . '/inc/footer.php'; ?>