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
</head>
<body>

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
        <input type="password" id="password" name="password" placeholder="Enter your password" required />

        <div class="forgot-password">
          <a href="forgot_password.php">Forgot Password?</a>
        </div>

        <button type="submit">Login</button>

        <div class="forgot-password">
          <a href="signup.php">Don't have an account? Sign up</a>
        </div>

        <div class="dashboard fw-bold fs-6 mt-5 text-center">
          <a href="../dashboard/dashboard.php">Back to Dashboard</a>
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
})();
// Inject intended URL from PHP session (if any)
window.__INTENDED_URL__ = <?php echo json_encode($intended); ?>;
</script>
</body>
</html>
