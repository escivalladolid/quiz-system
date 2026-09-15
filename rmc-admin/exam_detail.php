<?php
require_once __DIR__ . '/inc/bootstrap.php';
admin_require_login();

$admin = $_SESSION['admin_user'];
$exam_id = (int) ($_GET['id'] ?? 0);

$db_online = true;
$exam = null;
$questions = [];
$submissions = [];

if ($exam_id > 0) {
    $res = admin_api_request('GET', 'admin/exam_detail.php?exam_id=' . $exam_id, [], (string) ($admin['token'] ?? ''));
    if (admin_api_response_ok($res)) {
        $payload = $res['body']['data'];
        $exam = is_array($payload['exam'] ?? null) ? $payload['exam'] : null;
        $questions = admin_array_rows($payload['questions'] ?? null);
        $submissions = admin_array_rows($payload['submissions'] ?? null);
    } else {
        $db_online = false;
    }
}

if (is_array($exam)) {
    $exam = array_replace([
        'exam_name' => 'Unnamed assessment', 'subject_name' => '', 'subject_code' => '',
        'block' => '', 'status' => 'DRAFT', 'duration_minutes' => 0,
        'passing_score' => 0, 'question_count' => 0, 'points_count' => 0,
        'start_time' => null, 'end_time' => null, 'is_closed' => 0,
        'closed_at' => null, 'max_exit_attempts' => 0,
    ], $exam);
}

$STATUS_TAGS = ['DRAFT' => 'tag-dim', 'SCHEDULED' => 'tag-royal', 'LIVE' => 'tag-pass', 'CLOSED' => 'tag-navy', 'ARCHIVED' => 'tag-arch'];

function fmt_secs($secs): string {
    if ($secs === null || $secs === '') { return '—'; }
    $secs = (int) $secs;
    return sprintf('%d:%02d', intdiv($secs, 60), $secs % 60);
}

$page_title = $exam ? $exam['exam_name'] : 'Assessment Details';
$active_nav = 'assessments';
require_once __DIR__ . '/inc/header.php';
?>
<?php if (!$db_online): ?>
  <div class="notice bad"><b>Live data unavailable</b> — the backend/database is not reachable right now.</div>
<?php endif; ?>

<a class="back-link" href="assessments.php">
  <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9.5 3.5 5 8l4.5 4.5"/></svg>
  Back to Assessments
</a>

<?php if (!$exam): ?>
  <div class="panel"><div class="empty-state"><span class="big">&#9888;</span>Assessment not found or unreachable.</div></div>
<?php else: ?>
  <div class="detail-head">
    <div>
      <h2><?php echo e($exam['exam_name']); ?></h2>
      <div class="detail-sub"><?php echo e(trim($exam['subject_name'] . ' · ' . $exam['subject_code'] . ' · ' . $exam['block'])); ?>
        <span class="tag <?php echo $STATUS_TAGS[$exam['status']] ?? 'tag-dim'; ?>" style="margin-left:8px;"><?php echo e($exam['status']); ?></span>
      </div>
    </div>
    <div style="display:flex;gap:8px;">
      <?php if ($exam['status'] === 'LIVE'): ?>
        <button class="btn btn-primary" type="button" id="btnForceClose" style="width:auto;padding:0 18px;height:40px;background:var(--error-600);color:#fff;">Force Close</button>
      <?php endif; ?>
      <?php if (in_array($exam['status'], ['DRAFT', 'CLOSED', 'ARCHIVED'], true)): ?>
        <button class="btn btn-primary" type="button" id="btnSchedule" style="width:auto;padding:0 18px;height:40px;">
          <?php echo $exam['status'] === 'ARCHIVED' ? 'Restore &amp; Schedule' : ($exam['status'] === 'DRAFT' ? 'Schedule' : 'Reopen'); ?>
        </button>
      <?php endif; ?>
      <?php if ($exam['status'] !== 'ARCHIVED'): ?>
        <button class="btn btn-outline" type="button" id="btnArchive" style="width:auto;padding:0 18px;height:40px;">Archive</button>
      <?php endif; ?>
    </div>
  </div>

  <div class="chips">
    <div class="chip">Duration <b><?php echo (int) $exam['duration_minutes']; ?> min</b></div>
    <div class="chip">Passing <b><?php echo (int) $exam['passing_score']; ?>%</b></div>
    <div class="chip">Questions <b><?php echo (int) $exam['question_count']; ?></b></div>
    <div class="chip">Points <b><?php echo (int) $exam['points_count']; ?></b></div>
    <div class="chip">Window <b><?php echo ($exam['start_time'] && $exam['end_time']) ? e(date('M j, g:i A', strtotime($exam['start_time'])) . ' → ' . date('M j, g:i A', strtotime($exam['end_time']))) : 'not scheduled'; ?></b></div>
    <?php if ($exam['is_closed']): ?><div class="chip">Closed at <b><?php echo $exam['closed_at'] ? e(date('M j, g:i A', strtotime($exam['closed_at']))) : '—'; ?></b></div><?php endif; ?>
    <div class="chip">Exit attempts <b><?php echo (int) $exam['max_exit_attempts']; ?></b></div>
  </div>

  <div class="dash-grid">
    <section class="panel section-gap">
      <div class="panel-head"><h3>Questions (<?php echo count($questions); ?>)</h3></div>
      <?php if (empty($questions)): ?>
        <div class="empty-state"><span class="big">&#128213;</span>No questions have been added to this assessment yet.</div>
      <?php else: ?>
        <?php foreach ($questions as $q): ?>
          <div class="qrow">
            <span class="qnum">#<?php echo (int) ($q['order_num'] ?? 0); ?></span>
            <div class="qbody">
              <div class="qtext"><?php echo e($q['question_text'] ?? ''); ?></div>
              <div style="margin-top:4px;display:flex;gap:6px;align-items:center;">
                <span class="tag tag-dim"><?php echo e($q['question_type'] ?? ''); ?></span>
                <span class="mono" style="font-size:11.5px;color:var(--ink-400);"><?php echo (int) ($q['points'] ?? 0); ?> pt<?php echo (int) ($q['points'] ?? 0) !== 1 ? 's' : ''; ?></span>
                <?php if (($q['correct_answer'] ?? null) !== null && ($q['correct_answer'] ?? '') !== ''): ?>
                  <span class="correct">&#10003; <?php echo e($q['correct_answer']); ?></span>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <aside class="rail">
      <section class="panel section-gap">
        <div class="panel-head"><h3>Submissions (<?php echo count($submissions); ?>)</h3></div>
        <div class="table-wrap">
          <?php if (empty($submissions)): ?>
            <div class="empty-state"><span class="big">&#128221;</span>No submissions for this assessment yet.</div>
          <?php else: ?>
            <table class="data-table">
              <thead><tr><th>Student</th><th>Score</th><th>Result</th><th>Time</th><th>At</th></tr></thead>
              <tbody>
                <?php foreach ($submissions as $s): ?>
                  <?php
                    $pct = $s['percentage'] ?? null;
                    $pctTxt = $pct === null ? '—' : number_format($pct, 1) . '%';
                    $passed = $s['passed'] ?? null;
                    $cls = $passed === null ? 'tag-dim' : ($passed ? 'tag-pass' : 'tag-fail');
                    $tagTxt = $passed === null ? 'n/s' : ($passed ? 'PASSED' : 'FAILED');
                    $submittedAt = $s['submitted_at'] ?? null;
                  ?>
                  <tr>
                    <td>
                      <div><?php echo e(trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? '')) ?: 'Unknown student'); ?></div>
                      <div style="font-size:11px;color:var(--ink-400);"><?php echo e($s['section'] ?? ''); ?></div>
                    </td>
                    <td><span class="mono"><?php echo (int) ($s['score'] ?? 0); ?> pts · <?php echo $pctTxt; ?></span></td>
                    <td><span class="tag <?php echo $cls; ?>"><?php echo $tagTxt; ?></span></td>
                    <td><span class="mono" style="font-size:12px;"><?php echo fmt_secs($s['time_used_secs'] ?? null); ?></span></td>
                    <td style="font-size:11.5px;color:var(--ink-400);"><?php echo e(!empty($submittedAt) ? date('M j, g:i', strtotime((string) $submittedAt)) : '—'); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </section>
    </aside>
  </div>

  <!-- Reopen / Schedule modal -->
  <div class="modal-overlay" id="modalSchedule">
    <div class="modal-card" style="max-width:440px;">
      <h3 class="modal-title" id="schedTitle">Schedule Assessment</h3>
      <p class="modal-sub" id="schedSub">Choose the availability window. The exam runs between these times.</p>
      <div class="alert alert-error hidden" id="schedAlert"></div>
      <div class="modal-body-form">
        <div class="field"><label for="s_start">Start time</label><input class="input" type="datetime-local" id="s_start" required></div>
        <div class="field"><label for="s_end">End time</label><input class="input" type="datetime-local" id="s_end" required></div>
      </div>
      <div class="modal-actions" style="margin-top:16px;">
        <button class="btn btn-outline" type="button" data-close="modalSchedule">Cancel</button>
        <button class="btn btn-primary" type="button" id="btnSchedGo">Save Schedule</button>
      </div>
    </div>
  </div>

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
    var EXAM = <?php echo (int) $exam_id; ?>;

    function open(id) { document.getElementById(id).classList.add('open'); }
    function close(id) { document.getElementById(id).classList.remove('open'); }
    document.querySelectorAll('[data-close]').forEach(function (b) {
      b.addEventListener('click', function () { close(b.getAttribute('data-close')); });
    });
    document.querySelectorAll('.modal-overlay').forEach(function (ov) {
      ov.addEventListener('click', function (e) { if (e.target === ov) ov.classList.remove('open'); });
    });

    function postStatus(action, extra, done) {
      var payload = { exam_id: EXAM, action: action };
      if (extra) Object.assign(payload, extra);
      fetch(A('exam_status'), { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.RMC_CSRF }, body: JSON.stringify(payload) })
        .then(function (r) { return r.json(); })
        .then(function (res) { res.success ? done(true) : done(false, res.error); })
        .catch(function () { done(false, 'Network error — is the backend running?'); });
    }

    // simple confirm
    var cfTitle = document.getElementById('cfTitle');
    var cfBody = document.getElementById('cfBody');
    var cfAlert = document.getElementById('cfAlert');
    var cfGo = document.getElementById('cfGo');
    var cfAction = null;
    function askConfirm(title, body, fn) { cfTitle.textContent = title; cfBody.textContent = body; cfAlert.classList.add('hidden'); cfAction = fn; open('modalConfirm'); }
    cfGo.addEventListener('click', function () {
      if (!cfAction) return;
      cfGo.disabled = true;
      cfAction(function (ok, msg) {
        cfGo.disabled = false;
        if (ok) { window.location.reload(); }
        else { cfAlert.textContent = msg || 'Request failed.'; cfAlert.classList.remove('hidden'); }
      });
    });

    var fcBtn = document.getElementById('btnForceClose');
    if (fcBtn) fcBtn.addEventListener('click', function () {
      askConfirm('Force close exam?', 'Stops the exam immediately. Students mid-exam keep their submission; further attempts are blocked.', function (done) { postStatus('force_close', null, done); });
    });

    var arcBtn = document.getElementById('btnArchive');
    if (arcBtn) arcBtn.addEventListener('click', function () {
      askConfirm('Archive exam?', 'Hides the exam from teachers/students. Submissions are preserved; you can restore and reschedule later.', function (done) { postStatus('archive', null, done); });
    });

    function toDb(v) { return v.replace('T', ' ') + ':00'; }

    var schedBtn = document.getElementById('btnSchedule');
    var schedAlert = document.getElementById('schedAlert');
    if (schedBtn) {
      schedBtn.addEventListener('click', function () {
        schedAlert.classList.add('hidden');
        var dt = document.getElementById('s_start');
        var de = document.getElementById('s_end');
        var start = window.SCHED_START, end = window.SCHED_END;
        dt.value = start; de.value = end;
        open('modalSchedule');
      });
    }
    var schedGo = document.getElementById('btnSchedGo');
    if (schedGo) schedGo.addEventListener('click', function () {
      var sv = document.getElementById('s_start').value;
      var ev = document.getElementById('s_end').value;
      if (!sv || !ev) { schedAlert.textContent = 'Please pick both a start and an end time.'; schedAlert.classList.remove('hidden'); return; }
      schedGo.disabled = true;
      postStatus('schedule', { start_time: toDb(sv), end_time: toDb(ev) }, function (ok, msg) {
        if (ok) { window.location.reload(); }
        else { schedAlert.textContent = msg || 'Failed to schedule.'; schedAlert.classList.remove('hidden'); schedGo.disabled = false; }
      });
    });
  })();
  </script>
  <script>
    window.SCHED_START = <?php echo admin_json_for_script($exam['start_time'] ? date('Y-m-d\TH:i', strtotime($exam['start_time'])) : ''); ?>;
    window.SCHED_END = <?php echo admin_json_for_script($exam['end_time'] ? date('Y-m-d\TH:i', strtotime($exam['end_time'])) : ''); ?>;
  </script>
<?php endif; ?>

<?php require_once __DIR__ . '/inc/footer.php'; ?>
