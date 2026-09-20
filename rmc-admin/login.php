<?php
require_once __DIR__ . '/inc/bootstrap.php';
// Same-origin preflight refreshes stale forms without submitting credentials.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['prepare_login'] ?? '') === '1') {
    header('Content-Type: application/json');
    echo json_encode(['csrf_token' => admin_csrf_token(), 'signed_in' => admin_logged_in()]);
    exit;
}

// Already signed in? Bounce straight to the dashboard instead of rendering
// the login form again (mirror of dashboard.php's admin_require_login(), inverted).
if (admin_logged_in()) {
    header('Location: ' . admin_url('dashboard'));
    exit;
}

$error = null;
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? null;
    $expectedToken = $_SESSION['csrf_token'] ?? '';
    $validToken = is_string($submittedToken) && is_string($expectedToken)
        && $expectedToken !== '' && hash_equals($expectedToken, $submittedToken);

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$validToken) {
        http_response_code(403);
        $error = 'This login page expired, possibly after a server update. Please enter your password and sign in again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Please enter both your username and password.';
    } else {
        $res = admin_api_request('POST', 'login.php', [
            'username' => $username,
            'password' => $password,
        ]);

        $apiData = is_array($res['body']['data'] ?? null) ? $res['body']['data'] : [];
        $ok = ($res['body']['success'] ?? false) === true && !empty($apiData['session_token']);

        if ($ok && ($apiData['role'] ?? null) !== 'ADMIN') {
            $error = 'This panel is restricted to Admin accounts.';
        } elseif (!$ok) {
            $error = $res['body']['error'] ?? 'Unable to reach the server. Please try again.';
        } else {
            // Prevent session fixation when credentials are accepted.
            session_regenerate_id(true);
            $_SESSION['admin_user'] = [
                'user_id' => (int) ($apiData['user_id'] ?? 0),
                'name'    => trim(($apiData['first_name'] ?? '') . ' ' . ($apiData['last_name'] ?? '')),
                'username' => $apiData['username'] ?? '',
                'email'   => $apiData['email'] ?? '',
                'token'   => $apiData['session_token'] ?? '',
            ];

            // Keep login through server restarts; persist beyond browser close only if requested.
            admin_set_remember_cookie((string) $_SESSION['admin_user']['token'], !empty($_POST['remember']));

            header('Location: ' . admin_url('dashboard'));
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="../assets/img/rmc-favicon.png" type="image/png" sizes="any">
<title>Admin Login — Regis Marie College</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root{
    --navy-deep:#0A1F44; --navy:#0F2E6B; --royal:#1E4FA0; --royal-light:#4E7FD1;
    --amber:#e8a33d; --amber-dim:#f0c07a;
    --sky:#9FC3EE; --paper:#F3F6FB; --ink:#0B1220;
    --danger:#C4453C;
  }
  *{margin:0;padding:0;box-sizing:border-box;}
  body{
    font-family:'Inter',sans-serif;
    min-height:100vh;
    display:flex; align-items:center; justify-content:center;
    background:
      radial-gradient(1100px 700px at 82% -10%, rgba(232,163,61,0.40), transparent 60%),
      linear-gradient(160deg, var(--navy-deep) 0%, var(--navy) 55%, var(--royal) 100%);
    padding:32px;
  }
  a{color:inherit;text-decoration:none;}
  .wrap-outer{ width:100%; max-width:420px; }
  .back-link{
    display:inline-flex; align-items:center; gap:6px;
    margin-bottom:22px; font-size:13px; font-weight:600; color:rgba(255,255,255,0.85);
  }
  .back-link:hover{ color:#fff; }
  .card{
    width:100%; max-width:420px;
    background:#fff;
    border-radius:18px;
    padding:40px 36px 34px;
    box-shadow:0 30px 60px rgba(5,12,30,0.35);
  }
  .badge{
    display:inline-flex; align-items:center; gap:8px;
    background:rgba(10,31,68,0.06);
    border:1px solid rgba(10,31,68,0.12);
    padding:6px 12px; border-radius:999px;
    font-size:11.5px; font-weight:700; letter-spacing:.06em; text-transform:uppercase;
    color:var(--navy);
    margin-bottom:18px;
  }
  .badge svg{width:13px;height:13px;}
  h1{
    font-family:'Fraunces',serif; font-weight:600;
    font-size:26px; color:var(--navy-deep); line-height:1.2;
  }
  p.sub{ margin-top:8px; font-size:14px; color:#5B6478; line-height:1.55; }
  form{ margin-top:28px; display:flex; flex-direction:column; gap:16px; }
  label{ font-size:12.5px; font-weight:700; color:var(--navy-deep); letter-spacing:.02em; }
  .field{ display:flex; flex-direction:column; gap:6px; }
  input{
    padding:12px 14px; border-radius:9px; border:1.4px solid #DCE2ED;
    font-size:14.5px; font-family:'Inter',sans-serif; color:var(--ink);
    outline:none; transition:border-color .15s ease;
  }
  input:focus{ border-color:var(--amber); }
  .row-between{
    display:flex; align-items:center; justify-content:space-between;
    font-size:13px; gap:10px; flex-wrap:wrap;
  }
  .row-between a{ color:var(--royal); font-weight:600; }
  .row-between a:hover{ text-decoration:underline; }
  .row-between label{ display:flex; align-items:center; gap:7px; font-weight:500; color:#5B6478; }
  .row-between label input{ width:auto; accent-color:var(--navy); }
  button.submit{
    margin-top:6px;
    background:var(--amber); color:var(--navy-deep);
    border:none; padding:13px; border-radius:10px;
    font-size:14.5px; font-weight:700; cursor:pointer;
    transition:background .15s ease, transform .15s ease;
  }
  button.submit:hover{ background:var(--amber-dim); transform:translateY(-1px); }
  button.submit:disabled{ opacity:.6; cursor:not-allowed; transform:none; }
  .error{
    background:rgba(196,69,60,0.08); border:1px solid rgba(196,69,60,0.25);
    color:var(--danger); font-size:13px; font-weight:600;
    padding:10px 12px; border-radius:8px; margin-top:22px;
  }
  .divider{ margin:26px 0 18px; border:none; border-top:1px solid #EAEDF4; }
  .foot-note{ font-size:12.5px; color:#8891A3; text-align:center; line-height:1.6; }
  .foot-note a{ color:var(--navy); font-weight:600; }

  /* ---------- FORGOT PASSWORD MODAL ---------- */
  .modal-overlay{
    position:fixed; inset:0; z-index:50;
    background:rgba(10,20,45,0.55);
    display:none; align-items:center; justify-content:center;
    padding:24px;
  }
  .modal-overlay.open{ display:flex; }
  .modal-card{
    width:100%; max-width:400px;
    background:#fff; border-radius:16px; padding:28px;
    box-shadow:0 24px 50px rgba(5,12,30,0.35);
  }
  .modal-title{ font-family:'Fraunces',serif; font-size:20px; color:var(--navy-deep); font-weight:600; margin-bottom:6px; }
  .modal-sub{ font-size:13.5px; color:#5B6478; line-height:1.55; margin-bottom:18px; }
  .modal-actions{ display:flex; gap:10px; justify-content:flex-end; margin-top:20px; }
  .btn{
    display:inline-flex; align-items:center; gap:8px;
    padding:10px 18px; border-radius:9px;
    font-size:13.5px; font-weight:700; cursor:pointer;
    transition:background .15s ease, transform .15s ease;
  }
  .btn-outline{ background:#fff; color:var(--navy); border:1.4px solid #DCE2ED; }
  .btn-outline:hover{ background:var(--paper); }
  .btn-primary{ background:var(--navy-deep); color:#fff; border:none; }
  .btn-primary:hover{ background:var(--royal); }
  .alert{
    padding:10px 12px; border-radius:8px; font-size:13px; font-weight:600;
    margin-bottom:14px;
  }
  .alert-error{ background:rgba(196,69,60,0.08); border:1px solid rgba(196,69,60,0.25); color:var(--danger); }
  .alert-ok{ background:rgba(30,130,76,0.08); border:1px solid rgba(30,130,76,0.25); color:#1E824C; }
  .hidden{ display:none; }
  .dev-token{
    margin-top:12px; font-family:Consolas,monospace; font-size:12px;
    color:#8a6d1f; background:#FBF6E9; border:1px dashed rgba(212,175,55,0.5);
    padding:8px 10px; border-radius:8px; word-break:break-all;
  }
</style>
<script>window.RMC_API = <?php echo admin_json_for_script(admin_api_base()); ?>;</script>
</head>
<body>
<div class="wrap-outer">
  <a href="../index.html" class="back-link">&larr; Back to Regis Marie College</a>
  <div class="card">
    <span class="badge">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2 4 5v6c0 5 3.5 8.5 8 11 4.5-2.5 8-6 8-11V5l-8-3Z"/></svg>
      Restricted access
    </span>
    <h1>Admin Login</h1>
    <p class="sub">This portal is restricted to Regis Marie College administrators, who manage user accounts, monitor examinations, and generate system-wide reports.</p>

    <div class="error" id="errorBox"<?php if ($error === null) echo ' style="display:none;"'; ?>><?php echo e($error ?? 'Incorrect username or password. Please try again.'); ?></div>

    <form id="adminLoginForm" method="post" action="<?= e(admin_url('login')) ?>" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo e(admin_csrf_token()); ?>">
      <div class="field">
        <label for="username">Username</label>
        <input id="username" name="username" type="text" autocomplete="username"
               value="<?php echo e($username); ?>" placeholder="Enter your username" required autofocus>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password"
               placeholder="••••••••" required>
      </div>
      <div class="row-between">
        <label><input type="checkbox" id="remember" name="remember" value="1"> Remember this device</label>
        <a href="#" id="btnForgot">Forgot password?</a>
      </div>
      <button class="submit" type="submit">Sign in to admin panel</button>
    </form>

    <hr class="divider">
    <p class="foot-note">Students and teachers register in the Regis Marie College mobile app using their student or employee number.</p>
  </div>
</div>

<!-- Forgot Password modal -->
<div class="modal-overlay" id="modalForgot">
  <div class="modal-card">
    <h3 class="modal-title">Reset your password</h3>
    <p class="modal-sub">Enter the email registered on your admin account and we will issue a one-time reset code.</p>
    <div class="alert hidden" id="fpInfo"></div>
    <div class="field">
      <label for="fpEmail">Email</label>
      <input class="input" type="email" id="fpEmail" placeholder="you@rmc.edu.ph" autocomplete="email">
    </div>
    <div class="modal-actions">
      <button class="btn btn-outline" type="button" id="btnFpClose">Cancel</button>
      <button class="btn btn-primary" type="button" id="btnFpSend">Send Reset Code</button>
    </div>
    <div class="dev-token hidden" id="fpDevToken"></div>
  </div>
</div>

<script>
(function () {
  var loginForm = document.getElementById('adminLoginForm');
  var loginBusy = false;
  loginForm.addEventListener('submit', async function(event) {
    event.preventDefault();
    if (loginBusy || !loginForm.reportValidity()) return;
    loginBusy = true;
    var button = loginForm.querySelector('button[type="submit"]');
    button.disabled = true;
    button.textContent = 'Signing in…';
    try {
      var url = new URL(loginForm.action, window.location.href);
      url.searchParams.set('prepare_login', '1');
      var response = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
      if (!response.ok) throw new Error('Could not prepare login');
      var state = await response.json();
      if (state.signed_in) { window.location.assign('dashboard'); return; }
      if (!state.csrf_token) throw new Error('Missing security token');
      loginForm.elements.csrf_token.value = state.csrf_token;
      HTMLFormElement.prototype.submit.call(loginForm);
    } catch (error) {
      var message = document.getElementById('errorBox');
      message.textContent = 'Could not connect securely. Check your connection and try again.';
      message.style.display = 'block';
      loginBusy = false;
      button.disabled = false;
      button.textContent = 'Sign in to admin panel';
    }
  });
  var modal = document.getElementById('modalForgot');
  var btnForgot = document.getElementById('btnForgot');
  var btnClose = document.getElementById('btnFpClose');
  var btnSend = document.getElementById('btnFpSend');
  var fpEmail = document.getElementById('fpEmail');
  var info = document.getElementById('fpInfo');
  var devToken = document.getElementById('fpDevToken');

  function openModal() {
    info.classList.add('hidden');
    devToken.classList.add('hidden');
    modal.classList.add('open');
    fpEmail.focus();
  }
  function closeModal() {
    modal.classList.remove('open');
  }

  btnForgot.addEventListener('click', function (ev) { ev.preventDefault(); openModal(); });
  btnClose.addEventListener('click', closeModal);
  modal.addEventListener('click', function (ev) { if (ev.target === modal) closeModal(); });

  btnSend.addEventListener('click', function () {
    var email = fpEmail.value.trim();
    info.classList.add('hidden');
    devToken.classList.add('hidden');
    if (!email) {
      showInfo('alert-error', 'Please enter your email address.');
      return;
    }
    btnSend.disabled = true;
    btnSend.textContent = 'Sending…';

    fetch(window.RMC_API + 'forgot_password.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: email })
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res.success) {
          showInfo('alert-ok', res.data.message);
          // Local/dev convenience: the API returns a testing token; show it
          // only when present so the reviewer can complete the reset.
          if (res.data.reset_token_for_testing) {
            devToken.textContent = 'DEV ONLY — reset token: ' + res.data.reset_token_for_testing;
            devToken.classList.remove('hidden');
          }
        } else {
          showInfo('alert-error', res.error);
        }
      })
      .catch(function () {
  var loginForm = document.getElementById('adminLoginForm');
  var loginBusy = false;
  loginForm.addEventListener('submit', async function(event) {
    event.preventDefault();
    if (loginBusy || !loginForm.reportValidity()) return;
    loginBusy = true;
    var button = loginForm.querySelector('button[type="submit"]');
    button.disabled = true;
    button.textContent = 'Signing in…';
    try {
      var url = new URL(loginForm.action, window.location.href);
      url.searchParams.set('prepare_login', '1');
      var response = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
      if (!response.ok) throw new Error('Could not prepare login');
      var state = await response.json();
      if (state.signed_in) { window.location.assign('dashboard'); return; }
      if (!state.csrf_token) throw new Error('Missing security token');
      loginForm.elements.csrf_token.value = state.csrf_token;
      HTMLFormElement.prototype.submit.call(loginForm);
    } catch (error) {
      var message = document.getElementById('errorBox');
      message.textContent = 'Could not connect securely. Check your connection and try again.';
      message.style.display = 'block';
      loginBusy = false;
      button.disabled = false;
      button.textContent = 'Sign in to admin panel';
    }
  });
        showInfo('alert-error', 'Unable to reach the server. Please try again.');
      })
      .finally(function () {
  var loginForm = document.getElementById('adminLoginForm');
  var loginBusy = false;
  loginForm.addEventListener('submit', async function(event) {
    event.preventDefault();
    if (loginBusy || !loginForm.reportValidity()) return;
    loginBusy = true;
    var button = loginForm.querySelector('button[type="submit"]');
    button.disabled = true;
    button.textContent = 'Signing in…';
    try {
      var url = new URL(loginForm.action, window.location.href);
      url.searchParams.set('prepare_login', '1');
      var response = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
      if (!response.ok) throw new Error('Could not prepare login');
      var state = await response.json();
      if (state.signed_in) { window.location.assign('dashboard'); return; }
      if (!state.csrf_token) throw new Error('Missing security token');
      loginForm.elements.csrf_token.value = state.csrf_token;
      HTMLFormElement.prototype.submit.call(loginForm);
    } catch (error) {
      var message = document.getElementById('errorBox');
      message.textContent = 'Could not connect securely. Check your connection and try again.';
      message.style.display = 'block';
      loginBusy = false;
      button.disabled = false;
      button.textContent = 'Sign in to admin panel';
    }
  });
        btnSend.disabled = false;
        btnSend.textContent = 'Send Reset Code';
      });
  });

  function showInfo(kind, text) {
    info.className = 'alert hidden';
    void info.offsetWidth;
    info.textContent = text;
    if (kind) info.classList.add(kind);
    info.classList.remove('hidden');
  }
})();
</script>
</body>
</html>