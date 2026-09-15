<?php
require_once __DIR__ . '/inc/bootstrap.php';
admin_require_login();

$admin = $_SESSION['admin_user'];

$action = strtoupper(trim($_GET['action'] ?? ''));
$userId = (int) ($_GET['user_id'] ?? 0);
$search = trim($_GET['search'] ?? '');
$from   = trim($_GET['from'] ?? '');
$to     = trim($_GET['to'] ?? '');
$page   = max(1, (int) ($_GET['page'] ?? 1));

$params = ['page' => $page, 'per_page' => 25];
if ($action !== '') { $params['action'] = $action; }
if ($userId > 0) { $params['user_id'] = $userId; }
if ($search !== '') { $params['search'] = $search; }
if ($from !== '') { $params['from'] = $from; }
if ($to !== '') { $params['to'] = $to; }

$db_online = true;
$data = ['logs' => [], 'total' => 0, 'pages' => 1, 'page' => 1, 'summary' => []];

$res = admin_api_request('GET', 'admin/logs.php?' . http_build_query($params), [], (string) ($admin['token'] ?? ''));
if (admin_api_response_ok($res)) {
    $data = admin_api_data($res, $data);
    $data['logs'] = admin_array_rows($data['logs'] ?? null);
    $data['summary'] = admin_array_rows($data['summary'] ?? null);
} else {
    $db_online = false;
}

$logs   = $data['logs'];
$total  = (int) $data['total'];
$pages  = (int) $data['pages'];
$page   = (int) $data['page'];
$summary = $data['summary'];

function qs(array $extra): string {
    global $action, $userId, $search, $from, $to;
    $all = ['action' => $action, 'user_id' => $userId > 0 ? $userId : '', 'search' => $search,
            'from' => $from, 'to' => $to] + $extra;
    $all = array_filter($all, function ($v) { return $v !== '' && $v !== null; });
    return http_build_query($all);
}

$page_title = 'System Logs';
$active_nav = 'logs';
require_once __DIR__ . '/inc/header.php';
?>
<?php if (!$db_online): ?>
  <div class="notice bad"><b>Live data unavailable</b> — the backend/database is not reachable right now.</div>
<?php endif; ?>

<div class="list-toolbar">
  <div>
    <h2>System Logs</h2>
    <div class="muted" style="font-size:12px;color:var(--ink-400);margin-top:2px;">Audit trail from activity_logs — actions taken by admins and sign-in activity.</div>
  </div>
</div>

<?php if (!empty($summary)): ?>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;">
    <a href="<?php echo e(admin_url('logs')); ?>" class="chip" style="text-decoration:none;">All · <?php echo (int) array_sum(array_column($summary, 'cnt')); ?></a>
          <?php foreach ($summary as $s): ?>
      <?php $summaryAction = strtoupper((string) ($s['action'] ?? '')); $activeChip = ($action === $summaryAction); ?>
      <a href="<?php echo e(admin_url('logs') . '?' . http_build_query(array_filter([
          'action' => $summaryAction, 'user_id' => $userId > 0 ? $userId : '',
          'search' => $search, 'from' => $from, 'to' => $to
      ], function ($v) { return $v !== '' && $v !== null; }))); ?>"
         class="chip" style="text-decoration:none;<?php echo $activeChip ? 'outline:2px solid var(--accent-500);outline-offset:2px;' : ''; ?>">
        <?php echo e($summaryAction); ?> · <?php echo (int) ($s['cnt'] ?? 0); ?>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<form class="filter-bar" method="get" action="<?php echo e(admin_url('logs')); ?>">
  <input class="input" type="search" name="search" value="<?php echo e($search); ?>" placeholder="Search actor or description…">
  <input class="input" type="date" name="from" value="<?php echo e($from); ?>" aria-label="From date" style="width:170px;">
  <input class="input" type="date" name="to" value="<?php echo e($to); ?>" aria-label="To date" style="width:170px;">
  <button class="btn-filter" type="submit">Apply</button>
  <?php if ($action !== '' || $userId > 0 || $search !== '' || $from !== '' || $to !== ''): ?>
    <a class="btn-filter" href="<?php echo e(admin_url('logs')); ?>" style="text-decoration:none;">Clear</a>
  <?php endif; ?>
</form>

<section class="panel">
  <div class="table-tools"><span class="muted"><?php echo number_format($total); ?> log entr<?php echo $total === 1 ? 'y' : 'ies'; ?></span></div>
  <div class="table-wrap">
    <?php if (empty($logs)): ?>
      <div class="empty-state">
        <span class="big">&#128274;</span>
        No log entries found<?php echo $db_online ? '' : ' (backend unreachable)'; ?>.<br>
        Activity is recorded automatically as users log in and admins make changes.
      </div>
    <?php else: ?>
      <table class="data-table">
        <thead>
          <tr>
            <th>When</th>
            <th>Actor</th>
            <th>Action</th>
            <th>Description</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $l): ?>
            <?php
              $createdAt = (string) ($l['created_at'] ?? '');
              $actorUsername = (string) ($l['username'] ?? '');
              $actorName = trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? ''));
              $logAction = (string) ($l['action'] ?? '');
              $logDescription = (string) ($l['description'] ?? '');
            ?>
            <tr>
              <td style="white-space:nowrap;font-size:12px;" class="mono"><?php echo e($createdAt !== '' ? date('M j, Y  g:i A', strtotime($createdAt)) : '—'); ?></td>
              <td>
                <?php if ($actorUsername !== ''): ?>
                  <div><?php echo e($actorName !== '' ? $actorName : 'Unknown user'); ?></div>
                  <div style="font-size:11px;color:var(--ink-400);">@<?php echo e($actorUsername); ?></div>
                <?php else: ?>
                  <span style="color:var(--ink-400);">system</span>
                <?php endif; ?>
              </td>
              <td><span class="tag tag-navy"><?php echo e($logAction); ?></span></td>
              <td style="color:var(--ink-600);max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo e($logDescription); ?>"><?php echo e($logDescription); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <?php if ($pages > 1): ?>
    <div class="pagination">
      <a class="pg <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo e($page > 1 ? admin_url('logs') . '?' . qs(['page' => $page - 1]) : '#'); ?>">&laquo;</a>
      <?php for ($p = 1; $p <= $pages; $p++): ?>
        <?php if ($p === $page): ?>
          <span class="pg cur"><?php echo $p; ?></span>
        <?php else: ?>
          <a class="pg" href="<?php echo e(admin_url('logs') . '?' . qs(['page' => $p])); ?>"><?php echo $p; ?></a>
        <?php endif; ?>
      <?php endfor; ?>
      <a class="pg <?php echo $page >= $pages ? 'disabled' : ''; ?>" href="<?php echo e($page < $pages ? admin_url('logs') . '?' . qs(['page' => $page + 1]) : '#'); ?>">&raquo;</a>
    </div>
  <?php endif; ?>
</section>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
