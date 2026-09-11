<?php
require_once __DIR__ . '/inc/bootstrap.php';

$admin = $_SESSION['admin_user'];

$status    = strtoupper(trim($_GET['status'] ?? ''));
$teacherId = (int) ($_GET['teacher_id'] ?? 0);
$search    = trim($_GET['search'] ?? '');
$page      = max(1, (int) ($_GET['page'] ?? 1));

$params = ['page' => $page, 'per_page' => 12];
if (in_array($status, ['ACTIVE', 'ARCHIVED'], true)) { $params['status'] = $status; }
if ($teacherId > 0) { $params['teacher_id'] = $teacherId; }
if ($search !== '') { $params['search'] = $search; }

$db_online = true;
$data = ['classes' => [], 'total' => 0, 'pages' => 1, 'page' => 1,
         'summary' => ['total' => 0, 'active' => 0, 'archived' => 0]];
$teachers = [];

$res = admin_api_request('GET', 'admin/classes.php?' . http_build_query($params), [], $admin['token']);
if ($res['http_code'] === 200 && ($res['body']['success'] ?? false) === true) {
    $data = array_merge($data, $res['body']['data']);
} else {
    $db_online = false;
}
$res2 = admin_api_request('GET', 'admin/users.php?role=TEACHER&per_page=50', [], $admin['token']);
if ($res2['http_code'] === 200 && ($res2['body']['success'] ?? false) === true) {
    $teachers = $res2['body']['data']['users'] ?? [];
}

$classes = $data['classes'];
$total   = (int) $data['total'];
$pages   = (int) $data['pages'];
$page    = (int) $data['page'];
$summary = $data['summary'];

function qs(array $extra): string {
    global $status, $teacherId, $search;
    $all = ['status' => $status, 'teacher_id' => $teacherId > 0 ? $teacherId : '', 'search' => $search] + $extra;
    $all = array_filter($all, function ($v) { return $v !== '' && $v !== null; });
    return http_build_query($all);
}

$page_title = 'Class Management';
$active_nav = 'classes';
require_once __DIR__ . '/inc/header.php';
?>
<?php if (!$db_online): ?>
  <div class="notice bad"><b>Live data unavailable</b> — the backend/database is not reachable right now.</div>
<?php endif; ?>

<div class="list-toolbar">
  <div>
    <h2>Classes</h2>
    <div class="muted" style="font-size:12px;color:var(--ink-400);margin-top:2px;">
      <?php echo e($summary['total'] . ' total · ' . $summary['active'] . ' active · ' . $summary['archived'] . ' archived'); ?>
    </div>
  </div>
  <button class="btn btn-primary" id="btnAddClass" type="button" style="width:auto;padding:0 20px;">
    <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" style="margin-right:6px;"><path d="M8 3.5v9"/><path d="M3.5 8h9"/></svg>
    Add Class
  </button>
</div>

<form class="filter-bar" method="get" action="classes.php">
  <select class="input" name="teacher_id" aria-label="Teacher">
    <option value="0">All teachers</option>
    <?php foreach ($teachers as $t): ?>
      <option value="<?php echo (int) $t['user_id']; ?>" <?php echo $teacherId === (int) $t['user_id'] ? 'selected' : ''; ?>>
        <?php echo e(trim($t['first_name'] . ' ' . $t['last_name'])); ?>
      </option>
    <?php endforeach; ?>
  </select>
  <select class="input" name="status" aria-label="Status">
    <option value="">All statuses</option>
    <option value="ACTIVE" <?php echo $status === 'ACTIVE' ? 'selected' : ''; ?>>Active</option>
    <option value="ARCHIVED" <?php echo $status === 'ARCHIVED' ? 'selected' : ''; ?>>Archived</option>
  </select>
  <input class="input" type="search" name="search" value="<?php echo e($search); ?>" placeholder="Search subject, code, block…">
  <button class="btn-filter" type="submit">Apply</button>
  <?php if ($status !== '' || $teacherId > 0 || $search !== ''): ?>
    <a class="btn-filter" href="classes.php" style="text-decoration:none;">Clear</a>
  <?php endif; ?>
</form>

<section class="panel">
  <div class="table-tools"><span class="muted"><?php echo number_format($total); ?> class(es)</span></div>
  <div class="table-wrap">
    <?php if (empty($classes)): ?>
      <div class="empty-state">
        <span class="big">&#127979;</span>
        No classes found<?php echo $db_online ? '' : ' (backend unreachable)'; ?>.<br>
        Add a class, or adjust your filters.
      </div>
    <?php else: ?>
      <table class="data-table" id="classTable">
        <thead>
          <tr>
            <th>Class</th>
            <th>Teacher</th>
            <th>Enrolled</th>
            <th>Assessments</th>
            <th>Status</th>
            <th>Created</th>
            <th class="actions-col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($classes as $c): ?>
            <?php
              $subject = e(trim($c['subject_name'] . ' · ' . $c['subject_code']));
              $sub = e(trim($c['class_code'] . ' · ' . $c['block']));
              $teacher = trim(($c['teacher_first_name'] ?? '') . ' ' . ($c['teacher_last_name'] ?? ''));
              $statusCls = $c['status'] === 'ACTIVE' ? 'tag-pass' : 'tag-dim';
              $created = e(date('M j, Y', strtotime($c['created_at'])));
            ?>
            <tr data-id="<?php echo (int) $c['class_id']; ?>" data-status="<?php echo e($c['status']); ?>">
              <td>
                <div><?php echo $subject; ?></div>
                <div style="font-size:11.5px;color:var(--ink-400);"><?php echo $sub; ?></div>
              </td>
              <td><?php echo $teacher ? e($teacher) : '<span style="color:var(--ink-400);">—</span>'; ?></td>
              <td><span class="mono"><?php echo (int) $c['enrolled_count']; ?></span> students</td>
              <td><span class="mono"><?php echo (int) $c['exam_count']; ?></span> exams</td>
              <td><span class="tag <?php echo $statusCls; ?>"><?php echo e($c['status']); ?></span></td>
              <td style="color:var(--ink-400);font-size:12px;"><?php echo $created; ?></td>
              <td class="actions-col">
                <button class="row-action" type="button" data-act="roster" title="Manage roster">
                  <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="6" cy="6.2" r="2.2"/><path d="M2.8 13.2c0-1.9 1.4-3 3.2-3s3.2 1.1 3.2 3"/><path d="M10.6 3.9a2.2 2.2 0 1 1 0 4.3"/><path d="M12 10.4c1.7.3 3.2 1.4 3.2 3.2"/></svg>
                </button>
                <button class="row-action" type="button" data-act="edit" title="Edit">
                  <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="m11.3 2.9 1.8 1.8-7.6 7.6-2.5.7.7-2.5z"/><path d="M9.5 4.7l1.8 1.8"/></svg>
                </button>
                <button class="row-action <?php echo $c['status'] === 'ARCHIVED' ? '' : 'danger'; ?>" type="button" data-act="archive" title="<?php echo $c['status'] === 'ARCHIVED' ? 'Restore' : 'Archive'; ?>">
                  <?php if ($c['status'] === 'ARCHIVED'): ?>
                    <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="m3.5 10.5 2 2 2-2"/><path d="M5.5 12.5v-6"/><path d="M5.5 4.5 2.5 6.5l3 2 3-2z"/></svg>
                  <?php else: ?>
                    <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h10l-.8 7H3.8L3 6z"/><path d="M6 6V4h4v2"/><path d="M2.4 3.6h11.2"/></svg>
                  <?php endif; ?>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <?php if ($pages > 1): ?>
    <div class="pagination">
      <a class="pg <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="<?php echo e($page > 1 ? 'classes.php?' . qs(['page' => $page - 1]) : '#'); ?>">&laquo;</a>
      <?php for ($p = 1; $p <= $pages; $p++): ?>
        <?php if ($p === $page): ?>
          <span class="pg cur"><?php echo $p; ?></span>
        <?php else: ?>
          <a class="pg" href="<?php echo e('classes.php?' . qs(['page' => $p])); ?>"><?php echo $p; ?></a>
        <?php endif; ?>
      <?php endfor; ?>
      <a class="pg <?php echo $page >= $pages ? 'disabled' : ''; ?>" href="<?php echo e($page < $pages ? 'classes.php?' . qs(['page' => $page + 1]) : '#'); ?>">&raquo;</a>
    </div>
  <?php endif; ?>
</section>

<!-- Add / Edit Class modal -->
<div class="modal-overlay" id="modalClass">
  <div class="modal-card" style="max-width:520px;">
    <h3 class="modal-title" id="classModalTitle">Add Class</h3>
    <p class="modal-sub" id="classModalSub">Create a class and assign its teacher.</p>
    <div class="alert alert-error hidden" id="classFormAlert"></div>
    <form id="classForm" novalidate>
      <input type="hidden" name="class_id" id="cid">
      <div class="modal-body-form">
        <div class="field"><label for="f_scode">Subject code</label><input class="input" id="f_scode" name="subject_code" placeholder="HUM02" required></div>
        <div class="field"><label for="f_ccode">Class code</label><input class="input" id="f_ccode" name="class_code" placeholder="MATH2A" required></div>
        <div class="field full"><label for="f_sname">Subject name</label><input class="input" id="f_sname" name="subject_name" placeholder="Mathematics 2" required></div>
        <div class="field full"><label for="f_block">Block / section</label><input class="input" id="f_block" name="block" placeholder="BSIT 2-A" required></div>
        <div class="field">
          <label for="f_teacher">Teacher</label>
          <select class="input" id="f_teacher" name="teacher_id" required>
            <option value="">Select teacher…</option>
            <?php foreach ($teachers as $t): ?>
              <option value="<?php echo (int) $t['user_id']; ?>"><?php echo e(trim($t['first_name'] . ' ' . $t['last_name'])); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="f_status">Status</label>
          <select class="input" id="f_status" name="status">
            <option value="ACTIVE">Active</option>
            <option value="ARCHIVED">Archived</option>
          </select>
        </div>
      </div>
      <div class="modal-actions">
        <button class="btn btn-outline" type="button" data-close="modalClass">Cancel</button>
        <button class="btn btn-primary" type="submit" id="btnClassSave">Save Class</button>
      </div>
    </form>
  </div>
</div>

<!-- Roster modal -->
<div class="modal-overlay" id="modalRoster">
  <div class="modal-card" style="max-width:640px;">
    <h3 class="modal-title" id="rosterTitle">Class Roster</h3>
    <p class="modal-sub" id="rosterSub">Check the students enrolled in this class; save to apply.</p>
    <div class="alert alert-error hidden" id="rosterAlert"></div>
    <div class="field">
      <input class="input" type="search" id="rosterSearch" placeholder="Filter students by name or student ID…">
    </div>
    <div style="max-height:340px;overflow-y:auto;border:1px solid var(--line);border-radius:10px;">
      <div class="empty-state" id="rosterLoading">Loading roster…</div>
      <div id="rosterList"></div>
    </div>
    <div class="muted" style="font-size:12px;color:var(--ink-400);margin:10px 0 0;"><span id="rosterChecked">0</span> enrolled · <span id="rosterTotal">0</span> students → <span class="mono">ACTIVE</span> accounts only</div>
    <div class="modal-actions">
      <button class="btn btn-outline" type="button" data-close="modalRoster">Cancel</button>
      <button class="btn btn-primary" type="button" id="btnRosterSave">Save Roster</button>
    </div>
  </div>
</div>

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
window.CLASSES = <?php echo json_encode($classes); ?>;
window.TEACHERS = <?php echo json_encode($teachers); ?>;
</script>
<script>
(function () {
  'use strict';

  var A = function (action) { return 'ajax.php?action=' + action; };

  var classesById = {};
  window.CLASSES.forEach(function (c) { classesById[c.class_id] = c; });
  var teachersById = {};
  window.TEACHERS.forEach(function (t) { teachersById[t.user_id] = t; });

  function openModal(id) { document.getElementById(id).classList.add('open'); }
  function closeModal(id) { document.getElementById(id).classList.remove('open'); }
  document.querySelectorAll('[data-close]').forEach(function (b) {
    b.addEventListener('click', function () { closeModal(b.getAttribute('data-close')); });
  });
  document.querySelectorAll('.modal-overlay').forEach(function (ov) {
    ov.addEventListener('click', function (e) { if (e.target === ov) ov.classList.remove('open'); });
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
  cfGo.addEventListener('click', function () {
    if (!cfAction) return;
    cfGo.disabled = true;
    cfAction(function (ok, msg) {
      cfGo.disabled = false;
      if (ok) { window.location.reload(); }
      else { cfAlert.textContent = msg || 'Request failed. Please try again.'; cfAlert.classList.remove('hidden'); }
    });
  });

  function post(action, payload, done) {
    return fetch(A(action), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success) { done(true); } else { done(false, res.error); }
      })
      .catch(function () { done(false, 'Network error — is the backend running?'); });
  }

  // ---------- row actions ----------
  document.querySelectorAll('#classTable .row-action').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var tr = btn.closest('tr');
      var id = Number(tr.getAttribute('data-id'));
      var act = btn.getAttribute('data-act');
      var c = classesById[id];
      if (!c) return;
      if (act === 'edit') { openEdit(id); return; }
      if (act === 'roster') { openRoster(id); return; }
      var restore = c.status === 'ARCHIVED';
      var label = restore ? 'Restore class' : 'Archive class';
      var body = restore
        ? 'Un-hide this class so it can be managed again.'
        : 'Hide the class from teachers/students. Enrollments and exam records are preserved.';
      askConfirm(label + '?', body, function (done) {
        post('class_status', { class_id: id, status: restore ? 'ACTIVE' : 'ARCHIVED' }, done);
      });
    });
  });

  // ---------- class form modal ----------
  var form = document.getElementById('classForm');
  var classFormAlert = document.getElementById('classFormAlert');
  var editingId = null;

  document.getElementById('btnAddClass').addEventListener('click', function () {
    editingId = null;
    form.reset();
    document.getElementById('cid').value = '';
    document.getElementById('f_status').value = 'ACTIVE';
    document.getElementById('classModalTitle').textContent = 'Add Class';
    document.getElementById('classModalSub').textContent = 'Create a class and assign its teacher.';
    classFormAlert.classList.add('hidden');
    openModal('modalClass');
  });

  function openEdit(id) {
    var c = classesById[id];
    if (!c) return;
    editingId = id;
    form.reset();
    document.getElementById('cid').value = c.class_id;
    document.getElementById('f_scode').value = c.subject_code;
    document.getElementById('f_sname').value = c.subject_name;
    document.getElementById('f_block').value = c.block;
    document.getElementById('f_ccode').value = c.class_code;
    document.getElementById('f_teacher').value = String(c.teacher_id);
    document.getElementById('f_status').value = c.status;
    classFormAlert.classList.add('hidden');
    document.getElementById('classModalTitle').textContent = 'Edit Class';
    document.getElementById('classModalSub').textContent = c.subject_name + ' (' + c.class_code + ')';
    openModal('modalClass');
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    classFormAlert.classList.add('hidden');
    var fd = new FormData(form);
    var payload = {
      subject_code: fd.get('subject_code'), subject_name: fd.get('subject_name'),
      block: fd.get('block'), class_code: fd.get('class_code'),
      teacher_id: Number(fd.get('teacher_id')), status: fd.get('status')
    };
    if (!payload.teacher_id) { classFormAlert.textContent = 'Please assign a teacher.'; classFormAlert.classList.remove('hidden'); return; }
    if (editingId !== null) { payload.class_id = editingId; }
    var btn = document.getElementById('btnClassSave');
    btn.disabled = true; btn.textContent = 'Saving…';
    post(editingId === null ? 'class_create' : 'class_update', payload, function (ok, msg) {
      if (ok) { window.location.reload(); }
      else {
        classFormAlert.textContent = msg || 'Request failed.';
        classFormAlert.classList.remove('hidden');
      }
      btn.disabled = false; btn.textContent = 'Save Class';
    });
  });

  // ---------- roster modal ----------
  var rosterList = document.getElementById('rosterList');
  var rosterLoading = document.getElementById('rosterLoading');
  var rosterSearch = document.getElementById('rosterSearch');
  var currentClassId = null;
  var rosterStudents = [];

  function renderRoster() {
    var q = (rosterSearch.value || '').toLowerCase();
    var checked = 0;
    var html = '';
    rosterStudents.forEach(function (s) {
      var hay = (s.first_name + ' ' + s.last_name + ' ' + s.username + ' ' + (s.student_id || '')).toLowerCase();
      if (q && hay.indexOf(q) === -1) return;
      html += '<label class="roster-row" data-sid="' + s.user_id + '">'
        + '<input type="checkbox" class="roster-cb" value="' + s.user_id + '" ' + (s.enrolled ? 'checked' : '') + '> '
        + '<span>' + escapeHtml(s.first_name + ' ' + s.last_name) + '</span>'
        + '<span class="muted">@' + escapeHtml(s.username) + ' · ' + escapeHtml(s.student_id || 'N/A') + '</span>'
        + '</label>';
      if (s.enrolled) checked++;
    });
    rosterList.innerHTML = html || '<div style="padding:18px;color:var(--ink-400);font-size:13px;text-align:center;">No students match.</div>';
    document.getElementById('rosterChecked').textContent = checked;
    document.getElementById('rosterTotal').textContent = rosterStudents.length;
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }

  function openRoster(id) {
    var c = classesById[id];
    if (!c) return;
    currentClassId = id;
    document.getElementById('rosterTitle').textContent = c.subject_code + ' · ' + c.class_code;
    document.getElementById('rosterSub').textContent = c.subject_name + ' — ' + c.block;
    document.getElementById('rosterAlert').classList.add('hidden');
    rosterSearch.value = '';
    rosterList.innerHTML = '';
    rosterLoading.classList.remove('hidden');
    document.getElementById('btnRosterSave').disabled = true;
    openModal('modalRoster');

    fetch(A('class_roster') + '&class_id=' + id)
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.success) throw new Error(res.error || 'Failed to load roster.');
        rosterStudents = res.data.students;
        document.getElementById('btnRosterSave').disabled = false;
        rosterLoading.classList.add('hidden');
        renderRoster();
      })
      .catch(function (err) {
        rosterLoading.textContent = err.message || 'Failed to load roster.';
      });
  }

  rosterSearch.addEventListener('input', renderRoster);
  document.getElementById('rosterList').addEventListener('change', renderRoster);

  document.getElementById('btnRosterSave').addEventListener('click', function () {
    var ids = [];
    document.querySelectorAll('.roster-cb').forEach(function (cb) { if (cb.checked) ids.push(Number(cb.value)); });
    var btn = this;
    btn.disabled = true; btn.textContent = 'Saving…';
    post('class_roster_update', { class_id: currentClassId, student_ids: ids }, function (ok, msg) {
      if (ok) { window.location.reload(); }
      else {
        document.getElementById('rosterAlert').textContent = msg || 'Failed to save roster.';
        document.getElementById('rosterAlert').classList.remove('hidden');
        btn.disabled = false; btn.textContent = 'Save Roster';
      }
    });
  });
})();
</script>
<?php require_once __DIR__ . '/inc/footer.php'; ?>