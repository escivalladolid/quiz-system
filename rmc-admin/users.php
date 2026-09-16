<?php
require_once __DIR__ . '/inc/bootstrap.php';
admin_require_login();

$admin = $_SESSION['admin_user'];
$self_id = (int) ($admin['user_id'] ?? 0);

$role    = strtoupper(trim($_GET['role'] ?? ''));
$status  = strtoupper(trim($_GET['status'] ?? ''));
$search  = trim($_GET['search'] ?? '');
$page    = max(1, (int) ($_GET['page'] ?? 1));

$params = ['page' => $page, 'per_page' => 12];
if (in_array($role, ['STUDENT', 'TEACHER', 'ADMIN'], true)) { $params['role'] = $role; }
if (in_array($status, ['PENDING', 'ACTIVE', 'INACTIVE', 'BANNED'], true)) { $params['status'] = $status; }
if ($search !== '') { $params['search'] = $search; }

$db_online = true;
$data = ['users' => [], 'total' => 0, 'pages' => 1, 'page' => 1, 'per_page' => 12];
$res = admin_api_request('GET', 'admin/users.php?' . http_build_query($params), [], (string) ($admin['token'] ?? ''));
if (admin_api_response_ok($res)) {
    $data = admin_api_data($res, $data);
    $data['users'] = admin_array_rows($data['users'] ?? null);
} else {
    $db_online = false;
}
$users = $data['users'];
$total = (int) $data['total'];
$pages = (int) $data['pages'];
$page  = (int) $data['page'];

function qs(array $extra): string {
    global $role, $status, $search;
    $all = ['role' => $role, 'status' => $status, 'search' => $search] + $extra;
    $all = array_filter($all, function ($v) { return $v !== '' && $v !== null; });
    return http_build_query($all);
}

$page_title = 'User Management';
$active_nav = 'users';
require_once __DIR__ . '/inc/header.php';
?>
<?php if (!$db_online): ?>
  <div class="notice bad"><b>Live data unavailable</b> — the backend/database is not reachable right now.</div>
<?php endif; ?>

<div class="list-toolbar">
  <h2>Users</h2>
  <button class="btn btn-primary" id="btnAddUser" type="button" style="width:auto;padding:0 20px;">
    <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" style="margin-right:6px;"><path d="M8 3.5v9"/><path d="M3.5 8h9"/></svg>
    Add User
  </button>
</div>

<form class="filter-bar" method="get" action="<?php echo e(admin_url('users')); ?>">
  <select class="input" name="role" aria-label="Role">
    <option value="">All roles</option>
    <?php foreach (['STUDENT', 'TEACHER', 'ADMIN'] as $r): ?>
      <option value="<?php echo e($r); ?>" <?php echo $role === $r ? 'selected' : ''; ?>><?php echo e($r); ?></option>
    <?php endforeach; ?>
  </select>
  <select class="input" name="status" aria-label="Status">
    <option value="">All statuses</option>
    <?php foreach (['PENDING', 'ACTIVE', 'INACTIVE', 'BANNED'] as $s): ?>
      <option value="<?php echo e($s); ?>" <?php echo $status === $s ? 'selected' : ''; ?>><?php echo e(ucfirst(strtolower($s))); ?></option>
    <?php endforeach; ?>
  </select>
  <input class="input" type="search" name="search" value="<?php echo e($search); ?>" placeholder="Search name, username or email…">
  <button class="btn-filter" type="submit">Apply</button>
  <?php if ($role !== '' || $status !== '' || $search !== ''): ?>
    <a class="btn-filter" href="<?php echo e(admin_url('users')); ?>" style="text-decoration:none;">Clear</a>
  <?php endif; ?>
</form>

<div class="bulk-bar" id="bulkBar">
  <span id="bulkCount">0 selected</span>
  <button class="btn-sm" type="button" data-bulk="ACTIVE">&uarr; Activate</button>
  <button class="btn-sm" type="button" data-bulk="INACTIVE">&#10072;&#10072; Suspend</button>
  <button class="btn-sm danger" type="button" data-bulk="BANNED">&#10005; Ban</button>
  <button class="clear" type="button" id="btnBulkClear">Clear selection</button>
</div>

<section class="panel">
  <div class="table-tools"><span class="muted"><?php echo number_format($total); ?> user(s)</span></div>
  <div class="table-wrap">
    <?php if (empty($users)): ?>
      <div class="empty-state">
        <span class="big">&#11088;</span>
        No users found<?php echo $db_online ? '' : ' (backend unreachable)'; ?>.<br>
        Try different filters, or add a user to get started.
      </div>
    <?php else: ?>
      <table class="data-table" id="userTable">
        <thead>
          <tr>
            <th class="check-col"><input type="checkbox" id="chkAll" title="Select all on this page"></th>
            <th>User</th>
            <th>Role</th>
            <th>Student Info</th>
            <th>Status</th>
            <th>Created</th>
            <th class="actions-col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <?php
              $firstName = (string) ($u['first_name'] ?? '');
              $lastName = (string) ($u['last_name'] ?? '');
              $roleName = strtoupper((string) ($u['role_name'] ?? ''));
              $status = strtoupper((string) ($u['status'] ?? ''));
              $fb = e(strtoupper(($firstName[0] ?? 'S') . ($lastName[0] ?? 'T')));
              $name = e(trim($firstName . ' ' . $lastName));
              $roleCls = $roleName === 'ADMIN' ? 'tag-navy' : ($roleName === 'TEACHER' ? 'tag-royal' : 'tag-dim');
              $statusCls = $status === 'ACTIVE' ? 'tag-pass' : ($status === 'BANNED' ? 'tag-fail' : 'tag-dim');
              $stuInfo = $roleName === 'STUDENT'
                  ? e(trim(($u['student_id'] ?? '') . ' · ' . ($u['year_level'] ?? '') . ' · ' . ($u['section'] ?? ''), ' ·'))
                  : '<span style="color:var(--ink-400);">—</span>';
              $created = e(date('M j, Y', strtotime((string) ($u['created_at'] ?? ''))));
            ?>
            <tr data-id="<?php echo (int) ($u['user_id'] ?? 0); ?>" data-role="<?php echo e($roleName); ?>" data-status="<?php echo e($status); ?>">
              <td class="check-col">
                <input type="checkbox" class="row-check" value="<?php echo (int) ($u['user_id'] ?? 0); ?>"
                       <?php echo (int) ($u['user_id'] ?? 0) === $self_id ? 'disabled title="This is you"' : ''; ?>>
              </td>
              <td>
                <div class="row-user">
                  <div class="row-avatar"><?php echo $fb; ?></div>
                  <div>
                    <div><?php echo $name; ?></div>
                    <div style="font-size:11.5px;color:var(--ink-400);">@<?php echo e($u['username'] ?? ''); ?> · <?php echo e($u['email'] ?? ''); ?></div>
                  </div>
                </div>
              </td>
              <td><span class="tag <?php echo $roleCls; ?>"><?php echo e($roleName); ?></span></td>
              <td><?php echo $stuInfo; ?></td>
              <td><span class="tag <?php echo $statusCls; ?>"><?php echo e($status); ?></span></td>
              <td style="color:var(--ink-400);font-size:12px;"><?php echo $created; ?></td>
              <td class="actions-col">
                <button class="row-action" type="button" data-act="edit" title="Edit">
                  <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="m11.3 2.9 1.8 1.8-7.6 7.6-2.5.7.7-2.5z"/><path d="M9.5 4.7l1.8 1.8"/></svg>
                </button>
                <?php if ($status === 'ACTIVE'): ?>
                  <button class="row-action" type="button" data-act="suspend" title="Suspend">
                    <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M6 4.5v7"/><path d="M10 4.5v7"/></svg>
                  </button>
                  <button class="row-action danger" type="button" data-act="ban" title="Ban">
                    <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><circle cx="8" cy="8" r="5.4"/><path d="m4.8 4.8 6.4 6.4"/></svg>
                  </button>
                <?php elseif ($status === 'INACTIVE'): ?>
                  <button class="row-action" type="button" data-act="activate" title="Activate">
                    <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="m5.5 3.8 6 4.2-6 4.2z"/></svg>
                  </button>
                  <button class="row-action danger" type="button" data-act="ban" title="Ban">
                    <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><circle cx="8" cy="8" r="5.4"/><path d="m4.8 4.8 6.4 6.4"/></svg>
                  </button>
                <?php elseif ($status === 'BANNED'): ?>
                  <button class="row-action" type="button" data-act="activate" title="Reactivate">
                    <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="m5.5 3.8 6 4.2-6 4.2z"/></svg>
                  </button>
                <?php else: ?>
                  <span style="font-size:11px;color:var(--ink-400);">Awaiting email</span>
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
      <a class="pg <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo e($page > 1 ? admin_url('users') . '?' . qs(['page' => $page - 1]) : '#'); ?>">&laquo;</a>
      <?php for ($p = 1; $p <= $pages; $p++): ?>
        <?php if ($p === $page): ?>
          <span class="pg cur"><?php echo $p; ?></span>
        <?php else: ?>
          <a class="pg" href="<?php echo e(admin_url('users') . '?' . qs(['page' => $p])); ?>"><?php echo $p; ?></a>
        <?php endif; ?>
      <?php endfor; ?>
      <a class="pg <?php echo $page >= $pages ? 'disabled' : ''; ?>" href="<?php echo e($page < $pages ? admin_url('users') . '?' . qs(['page' => $page + 1]) : '#'); ?>">&raquo;</a>
    </div>
  <?php endif; ?>
</section>

<!-- Add / Edit User modal -->
<div class="modal-overlay" id="modalUser">
  <div class="modal-card" style="max-width:560px;">
    <h3 class="modal-title" id="userModalTitle">Add User</h3>
    <p class="modal-sub" id="userModalSub">Create a new account manually. Admins are always seeded by an existing admin.</p>
    <div class="alert alert-error hidden" id="userFormAlert"></div>
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
          <div style="font-size:11.5px;color:var(--ink-400);margin-top:4px;" id="pwHint">At least 8 characters with a number.</div>
        </div>
        <div class="field" id="wrap_sid"><label for="f_sid">Student ID</label><input class="input" id="f_sid" name="student_id"></div>
        <div class="field" id="wrap_year"><label for="f_year">Year level</label><input class="input" id="f_year" name="year_level" placeholder="2nd Year"></div>
        <div class="field full" id="wrap_sec"><label for="f_sec">Section</label><input class="input" id="f_sec" name="section" placeholder="BSIT 2-B"></div>
      </div>
      <div class="alert alert-info hidden" id="selfLock">You cannot change your own role or status.</div>
      <div class="modal-actions">
        <button class="btn btn-outline" type="button" data-close="modalUser">Cancel</button>
        <button class="btn btn-primary" type="submit" id="btnUserSave">Save User</button>
      </div>
    </form>
  </div>
</div>

<!-- Confirm action modal -->
<div class="modal-overlay" id="modalConfirm">
  <div class="modal-card" style="max-width:400px;">
    <h3 class="modal-title" id="cfTitle">Are you sure?</h3>
    <p class="modal-sub" id="cfBody" style="margin-bottom:16px;">This action will be applied.</p>
    <div class="alert alert-error hidden" id="cfAlert"></div>
    <div class="modal-actions">
      <button class="btn btn-outline" type="button" data-close="modalConfirm">Cancel</button>
      <button class="btn btn-primary" type="button" id="cfGo">Confirm</button>
    </div>
  </div>
</div>

<script>
window.RMC_SELF = <?php echo (int) $self_id; ?>;
window.USERS = <?php echo admin_json_for_script($users); ?>;
</script>
<script>
(function () {
  'use strict';

  var A = function (action) { return 'ajax.php?action=' + action; };

  var selfId = window.RMC_SELF;
  var usersById = {};
  (window.USERS || []).forEach(function (u) { usersById[u.user_id] = u; });

  // ---------- modal helpers ----------
  function openModal(id) { document.getElementById(id).classList.add('open'); }
  function closeModal(id) { document.getElementById(id).classList.remove('open'); }
  document.querySelectorAll('[data-close]').forEach(function (b) {
    b.addEventListener('click', function () { closeModal(b.getAttribute('data-close')); });
  });
  document.querySelectorAll('.modal-overlay').forEach(function (ov) {
    ov.addEventListener('click', function (e) { if (e.target === ov) ov.classList.remove('open'); });
  });

  // ---------- bulk selection ----------
  var bulkBar = document.getElementById('bulkBar');
  var bulkCount = document.getElementById('bulkCount');
  var chkAll = document.getElementById('chkAll');
  var selected = {};

  function refreshSelection() {
    var n = Object.keys(selected).length;
    bulkBar.classList.toggle('show', n > 0);
    bulkCount.textContent = n + ' selected';
    if (chkAll) {
      var boxes = document.querySelectorAll('.row-check:not(:disabled)');
      chkAll.checked = boxes.length > 0 && boxes.length === document.querySelectorAll('.row-check:checked').length;
    }
  }
  document.querySelectorAll('.row-check').forEach(function (cb) {
    cb.addEventListener('change', function () {
      if (cb.checked) selected[cb.value] = true; else delete selected[cb.value];
      refreshSelection();
    });
  });
  if (chkAll) {
    chkAll.addEventListener('change', function () {
      document.querySelectorAll('.row-check:not(:disabled)').forEach(function (cb) {
        cb.checked = chkAll.checked;
        if (cb.checked) selected[cb.value] = true; else delete selected[cb.value];
      });
      refreshSelection();
    });
  }
  document.getElementById('btnBulkClear').addEventListener('click', function () {
    document.querySelectorAll('.row-check').forEach(function (cb) { cb.checked = false; });
    selected = {};
    refreshSelection();
  });

  // ---------- confirm engine ----------
  var cfTitle = document.getElementById('cfTitle');
  var cfBody = document.getElementById('cfBody');
  var cfAlert = document.getElementById('cfAlert');
  var cfGo = document.getElementById('cfGo');
  var cfAction = null;

  function askConfirm(title, body, fn) {
    cfTitle.textContent = title;
    cfBody.textContent = body;
    cfAlert.classList.add('hidden');
    cfAction = fn;
    openModal('modalConfirm');
  }
  document.getElementById('cfGo').addEventListener('click', function () {
    if (!cfAction) return;
    cfGo.disabled = true;
    cfAction(function (ok, msg) {
      cfGo.disabled = false;
      if (ok) { window.location.reload(); }
      else {
        cfAlert.textContent = msg || 'Request failed. Please try again.';
        cfAlert.classList.remove('hidden');
      }
    });
  });

  function postStatus(ids, status, deco) {
    return function (done) {
      fetch(A('user_status'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.RMC_CSRF },
        body: JSON.stringify({ user_ids: ids, status: status })
      })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res.success) { done(true); }
          else { done(false, res.error || deco); }
        })
        .catch(function () { done(false, 'Network error — is the backend running?'); });
    };
  }

  // Row action buttons
  document.querySelectorAll('.row-action').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var tr = btn.closest('tr');
      var id = Number(tr.getAttribute('data-id'));
      var act = btn.getAttribute('data-act');
      if (act === 'edit') { openEdit(id); return; }
      var label = act === 'ban' ? 'Ban' : (act === 'activate' ? 'Activate' : 'Suspend');
      askConfirm(label + ' user?', label + ' the selected user account. ' + (act === 'ban' ? 'Banned users cannot sign in.' : 'Status changes apply immediately.'), postStatus([id], act === 'ban' ? 'BANNED' : (act === 'activate' ? 'ACTIVE' : 'INACTIVE'), label + ' failed.'));
    });
  });

  // Bulk bar
  document.querySelectorAll('[data-bulk]').forEach(function (b) {
    b.addEventListener('click', function () {
      var ids = Object.keys(selected).map(Number);
      var status = b.getAttribute('data-bulk');
      var label = status === 'BANNED' ? 'Ban' : (status === 'ACTIVE' ? 'Activate' : 'Suspend');
      askConfirm(label + ' ' + ids.length + ' user(s)?', label + ' the ' + ids.length + ' selected user(s). ' + (status === 'BANNED' ? 'Banned users cannot sign in.' : ''), postStatus(ids, status, label + ' failed.'));
    });
  });

  // ---------- user form modal ----------
  var userModal = document.getElementById('modalUser');
  var form = document.getElementById('userForm');
  var userFormAlert = document.getElementById('userFormAlert');
  var selfLock = document.getElementById('selfLock');
  var pwLabel = document.getElementById('pwLabel');
  var pwHint = document.getElementById('pwHint');
  var editingId = null;

  function setStudentFields(roleId) {
    var isStudent = roleId === 1;
    ['wrap_sid', 'wrap_year', 'wrap_sec'].forEach(function (id) {
      document.getElementById(id).style.display = isStudent ? '' : 'none';
    });
  }

  document.getElementById('btnAddUser').addEventListener('click', function () {
    editingId = null;
    form.reset();
    document.getElementById('uid').value = '';
    document.getElementById('userModalTitle').textContent = 'Add User';
    document.getElementById('userModalSub').textContent = 'Create a new account. Admins are always added by an existing admin.';
    pwLabel.textContent = 'Password'; pwHint.textContent = 'At least 8 characters with a number.';
    document.getElementById('f_password').required = true;
    document.getElementById('f_status').value = 'ACTIVE';
    selfLock.classList.add('hidden');
    document.getElementById('f_role').disabled = false;
    document.getElementById('f_status').disabled = false;
    setStudentFields(Number(document.getElementById('f_role').value));
    userFormAlert.classList.add('hidden');
    openModal('modalUser');
  });

  function openEdit(id) {
    var u = usersById[id];
    if (!u) return;
    editingId = id;
    form.reset();
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
    pwLabel.textContent = 'Password (leave blank to keep current)';
    pwHint.textContent = 'Only filled in when resetting the password.';
    selfLock.classList.toggle('hidden', u.user_id !== selfId);
    document.getElementById('f_role').disabled = u.user_id === selfId;
    document.getElementById('f_status').disabled = u.user_id === selfId;
    setStudentFields(Number(u.role_id));
    userFormAlert.classList.add('hidden');
    document.getElementById('userModalTitle').textContent = 'Edit User';
    document.getElementById('userModalSub').textContent = '@' + u.username + ' — account details and state.';
    openModal('modalUser');
  }

  document.getElementById('f_role').addEventListener('change', function () { setStudentFields(Number(this.value)); });

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    userFormAlert.classList.add('hidden');
    var fd = new FormData(form);
    // When editing yourself, role/status selects are disabled; disabled
    // fields are excluded from FormData, so read their current value directly.
    var fRoleEl = document.getElementById('f_role');
    var fStatusEl = document.getElementById('f_status');
    var roleId = Number(fRoleEl.disabled ? fRoleEl.value : fd.get('role_id'));
    var statusVal = fStatusEl.disabled ? fStatusEl.value : fd.get('status');
    var payload = {
      first_name: fd.get('first_name'), last_name: fd.get('last_name'),
      username: fd.get('username'), email: fd.get('email'),
      role_id: roleId, status: statusVal,
      student_id: fd.get('student_id') || '', year_level: fd.get('year_level') || '', section: fd.get('section') || ''
    };
    if (roleId === 1 && (!payload.student_id || !payload.year_level || !payload.section)) {
      userFormAlert.textContent = 'Student ID, year level and section are required for student accounts.';
      userFormAlert.classList.remove('hidden');
      return;
    }
    if (editingId === null) {
      payload.password = fd.get('password');
      if (!payload.password) { userFormAlert.textContent = 'Password is required for new users.'; userFormAlert.classList.remove('hidden'); return; }
    } else {
      payload.user_id = editingId;
      var pw = fd.get('password');
      if (pw) payload.password = pw;
    }

    var url = editingId === null ? A('user_create') : A('user_update');
    var btn = document.getElementById('btnUserSave');
    btn.disabled = true; btn.textContent = 'Saving…';

    fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.RMC_CSRF }, body: JSON.stringify(payload) })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success) { window.location.reload(); }
        else { userFormAlert.textContent = res.error || 'Request failed.'; userFormAlert.classList.remove('hidden'); }
      })
      .catch(function () { userFormAlert.textContent = 'Network error — is the backend running?'; userFormAlert.classList.remove('hidden'); })
      .finally(function () { btn.disabled = false; btn.textContent = editingId === null ? 'Save User' : 'Save Changes'; });
  });

  setStudentFields(2);
})();
</script>
<?php require_once __DIR__ . '/inc/footer.php'; ?>
