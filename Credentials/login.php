<?php
session_start();
$toast = $_SESSION['toast'] ?? null;
unset($_SESSION['toast']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login</title>
  <link rel="stylesheet" href="css/login.css" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>

<div class="login-container">
  <div class="login-card">
    <div class="login-image">
      <img src="../image/natcon.jpg" alt="Login Image"/>
    </div>
    <div class="login-form">
      <form id="loginForm" method="post" action="../api/api_login.php" novalidate>
        <h2>LOGIN</h2>

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

        <!-- Loading Overlay -->
        <div id="loadingOverlay" class="position-fixed top-0 start-0 w-100 h-100 d-none justify-content-center align-items-center" style="background: rgba(255,255,255,0.95); backdrop-filter: blur(10px); z-index: 9999;">
          <div class="text-center">
            <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;">
              <span class="visually-hidden">Loading...</span>
            </div>
            <h5 class="text-primary">Redirecting...</h5>
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
    overlay.classList.remove('d-none');

    try {
      const fd = new FormData(form);
      const res = await fetch(form.action, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
      });

      // If the API did a non-AJAX redirect, follow it
      if (res.redirected) {
        window.location.href = res.url;
        return;
      }

      const data = await res.json().catch(() => null);
      if (data && data.success) {
        showToast('Login successful. Redirecting…', true,1000);
        window.location.href = data.redirect || '../dashboard/dashboard.php';
      } else {
        showToast((data && data.message) || 'Invalid email or password.', false);
      }
    } catch (err) {
      showToast('Network error. Try again.', false);
    } finally {
      overlay.classList.add('d-none');
    }
  });
})();
</script>
</body>
</html>
