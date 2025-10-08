<?php
session_start();
$toast = $_SESSION['toast'] ?? null;
unset($_SESSION['toast']);
$loginNotice = $_SESSION['login_notice'] ?? null; // set by protected pages
unset($_SESSION['login_notice']);
$intended = $_SESSION['intended_url'] ?? null; // keep until after successful login
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/login.css" />
  <style>
    /* Safe layout overrides */
    .login-container{
      min-height:100vh; display:flex; align-items:center; justify-content:center; background:#f8fafc;
    }
    .login-card{
      display:flex; flex-wrap:wrap; max-width:1000px; width:100%;
      background:#fff; border-radius:16px; overflow:hidden;
      box-shadow:0 10px 30px rgba(0,0,0,.1);
    }
    .login-image{ flex:1 1 45%; min-width:300px; max-height:600px; display:block; }
    .login-image img{ width:100%; height:100%; object-fit:cover; display:block; }
    .login-form{ flex:1 1 55%; min-width:300px; padding:2rem 2.5rem; }
    @media (max-width: 768px){
      .login-image{ display:none !important; }
      .login-form{ flex:1 1 100%; padding:1.5rem; }
    }
  </style>
  <style>
    /* Navbar styling borrowed from dashboard for consistency */
    .navbar {
      backdrop-filter: blur(20px);
      background: rgba(255,255,255,0.95)!important;
      border-bottom:1px solid #e5e7eb;
    }
    .navbar-brand span {background:linear-gradient(135deg,#111e6c,#e27900);-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
    .nav-link {font-weight:500;}
    .nav-link:hover {color:#111e6c!important;}
    .btn-nav-auth {border:2px solid #111e6c;color:#111e6c;background:transparent;}
    .btn-nav-auth:hover {background:#111e6c;color:#fff;}
  </style>
</head>
<body>
  <!-- Site Navbar -->
  <nav class="navbar navbar-expand-lg navbar-light shadow-sm fixed-top">
    <div class="container">
      <a class="navbar-brand d-flex align-items-center" href="../dashboard/dashboard.php">
        <img src="../image/natcon.jpg" alt="PSME Logo" class="me-3 rounded-circle" style="height:55px;">
        <div>
          <span class="fs-5 fw-bold d-block">73rd PSME</span>
          <small class="d-none d-md-block fw-medium">National Convention</small>
        </div>
      </a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navLogin">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="navLogin">
        <ul class="navbar-nav ms-auto align-items-center">
          <li class="nav-item"><a class="nav-link" href="../dashboard/dashboard.php">Home</a></li>
          <li class="nav-item"><a class="nav-link" href="../dashboard/about.php">About</a></li>
          <li class="nav-item"><a class="nav-link" href="../dashboard/programs.php">Programs</a></li>
          <li class="nav-item ms-2 d-flex gap-2">
            <a href="signup.php" class="btn btn-nav-auth rounded-pill px-4 py-2 fw-medium">Sign Up</a>
          </li>
        </ul>
      </div>
    </div>
  </nav>
  <div style="height:80px"></div>

<div class="login-container">
  <div class="login-card">
    <div class="login-image">
      <img src="../image/natcon.jpg" alt="Login Image"/>
    </div>
    <div class="login-form">
      <form id="loginForm" method="post" action="../api/api_login.php" novalidate>
        <h2 class="fw-bold">LOGIN</h2>

        <?php if ($loginNotice): ?>
          <div class="alert alert-info py-2 small" role="alert" style="border-radius:10px;"><?= htmlspecialchars($loginNotice, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?></div>
        <?php endif; ?>

        <label for="email">Email</label>
        <input type="email" id="email" name="email" placeholder="Enter your email" required />

        <label for="password">Password</label>
        <div class="password-wrapper mb-2">
          <input type="password" id="password" name="password" placeholder="Enter your password" required />
          <button type="button" class="toggle-pass-btn" id="toggleLoginPwd" aria-label="Show password" style="position:absolute;top:50%;right:10px;transform:translateY(-50%);background:transparent;border:none;color:#0d6efd;cursor:pointer;padding:4px;display:flex;align-items:center;justify-content:center;z-index:100;min-width:28px;min-height:28px;">👁️</button>
        </div>

        <div class="forgot-password">
          <a href="forgot_password.php">Forgot Password?</a>
        </div>

        <button type="submit">Login</button>

        <div class="mt-5 text-center small">
          <span class="me-2">Don't have an account?</span><a href="signup.php" class="fw-semibold">Sign up</a>
          <span class="mx-2 text-muted">•</span>
          <a href="../dashboard/dashboard.php" class="fw-semibold">Back to Dashboard</a>
        </div>

        <!-- Redirect Overlay (shown only on successful login) -->
        <div id="loadingOverlay" class="position-fixed top-0 start-0 w-100 h-100 d-none" style="background: rgba(255,255,255,0.94); backdrop-filter: blur(18px); z-index: 9999;">
          <div class="position-absolute top-50 start-50 translate-middle text-center" style="max-width:340px; width:100%;">
            <div class="mb-4">
              <div class="spinner-border text-primary" role="status" style="width:3.5rem;height:3.5rem;">
                <span class="visually-hidden">Loading...</span>
              </div>
            </div>
            <h4 id="redirectTitle" class="fw-bold text-primary mb-2">Logging you in...</h4>
            <p id="redirectMsg" class="text-muted small mb-0">Please wait while we prepare your dashboard.</p>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Toast -->
<div class="position-fixed top-0 end-0 p-3" style="z-index:1080">
  <div id="toast" class="toast text-bg-primary border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div id="toastBody" class="toast-body"></div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  const form = document.getElementById('loginForm');
  const pwd = document.getElementById('password');
  const togglePwd = document.getElementById('toggleLoginPwd');
  const overlay = document.getElementById('loadingOverlay');
  const redirectTitle = document.getElementById('redirectTitle');
  const redirectMsg = document.getElementById('redirectMsg');
  const submitBtn = form.querySelector('button[type="submit"]');
  const toastEl = document.getElementById('toast');
  const toastBody = document.getElementById('toastBody');
  const showToast = (msg, ok=true, delay=1800) => {
    toastEl.classList.remove('text-bg-success','text-bg-danger','text-bg-primary');
    toastEl.classList.add(ok ? 'text-bg-success' : 'text-bg-danger');
    toastBody.textContent = msg;
    new bootstrap.Toast(toastEl, { delay }).show();
  };

  // Server flash message (from api_login.php)
  <?php if ($toast): ?>
    showToast(<?= json_encode($toast['msg']) ?>, <?=  $toast['type']==='success' ? 'true' : 'false' ?>);
  <?php endif; ?>

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.dataset.originalText = submitBtn.textContent;
      submitBtn.textContent = 'Checking...';
    }
    let success = false;
    try {
      const fd = new FormData(form);
      const res = await fetch(form.action, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
      });

      if (res.redirected) {
        // Treat as success redirect (fallback)
        success = true;
        overlay.classList.remove('d-none');
        setTimeout(()=>{ window.location.href = res.url; }, 400);
        return;
      }

      const data = await res.json().catch(() => null);
      if (data && data.success) {
        success = true;
        // Show redirect overlay (no success toast, per requirement)
        if (redirectTitle) redirectTitle.textContent = 'Login successful!';
        if (redirectMsg) redirectMsg.textContent = 'Redirecting...';
        overlay.classList.remove('d-none');
        // If intended url stored in session (PHP side), we need to fetch it.
        // We can't read PHP session directly here; create a lightweight endpoint or embed via data attribute.
        // Simpler: server will echo intended into a JS variable below.
        setTimeout(()=>{ window.location.href = window.__INTENDED_URL__ || data.redirect || '../dashboard/dashboard.php'; }, 700);
      } else {
        showToast((data && data.message) || 'Invalid email or password.', false);
      }
    } catch (err) {
      showToast('Network error. Try again.', false);
    } finally {
      if (!success) {
        overlay.classList.add('d-none');
        if (submitBtn) {
          submitBtn.disabled = false;
          if (submitBtn.dataset.originalText) submitBtn.textContent = submitBtn.dataset.originalText;
        }
      }
    }
  });

  const eyeOpen = '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>';
  const eyeClosed = '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l18 18"/><path d="M10.73 10.73a3 3 0 004.24 4.24"/><path d="M6.1 6.1A10.94 10.94 0 001 12s4 8 11 8a10.94 10.94 0 005.9-1.9M14.12 9.88 20 4"/></svg>';
  
  function renderEye(){
    if(!togglePwd) return;
    const isHidden = pwd.type === 'password';
    togglePwd.innerHTML = isHidden ? eyeOpen : eyeClosed;
    togglePwd.setAttribute('aria-label', (isHidden ? 'Show' : 'Hide') + ' password');
  }
  
  // Initialize eye toggle
  if(togglePwd && pwd){
    // Set initial eye icon
    setTimeout(() => {
      renderEye();
    }, 100);
    
    togglePwd.addEventListener('click', ()=>{ 
      pwd.type = pwd.type==='password' ? 'text':'password'; 
      renderEye(); 
    });
    togglePwd.addEventListener('keydown', e=>{ 
      if(e.key==='Enter'||e.key===' '){ 
        e.preventDefault(); 
        pwd.type = pwd.type==='password' ? 'text':'password'; 
        renderEye(); 
      }
    });
  }
})();
// Inject intended URL from PHP session (if any)
window.__INTENDED_URL__ = <?php echo json_encode($intended); ?>;
</script>
</body>
</html>
