<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Sign Up</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/login.css" />
  <style>
    .login-container{min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f8fafc;}
    .login-card{display:flex;flex-wrap:wrap;max-width:1000px;width:100%;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,.1);}    
    .login-image{flex:1 1 45%;min-width:300px;max-height:600px;display:block;}    
    .login-image img{width:100%;height:100%;object-fit:cover;display:block;}
    .login-form{flex:1 1 55%;min-width:300px;padding:2rem 2.5rem;}
    @media (max-width: 768px){.login-image{display:none!important}.login-form{flex:1 1 100%;padding:1.5rem}}
    .navbar {backdrop-filter: blur(20px);background: rgba(255,255,255,0.95)!important;border-bottom:1px solid #e5e7eb;}
    .navbar-brand span {background:linear-gradient(135deg,#111e6c,#e27900);-webkit-background-clip:text;-webkit-text-fill-color:transparent;}
    .nav-link {font-weight:500;}
    .nav-link:hover {color:#111e6c!important;}
    .btn-nav-auth {border:2px solid #111e6c;color:#111e6c;background:transparent;}
    .btn-nav-auth:hover {background:#111e6c;color:#fff;}
  .password-wrapper { position:relative; }
  .password-wrapper .form-control { padding-right:70px; }
  .eye-icon { position:absolute; top:50%; transform:translateY(-50%); right:14px; cursor:pointer; user-select:none; font-size:12px; color:#0d6efd; background:none; border:none; }
    .message { text-align:center; font-weight:600; }
    .btn[disabled]{ opacity:.65; cursor:not-allowed; }
  </style>
</head>
<body>
  <!-- Navbar (same style as login) -->
  <nav class="navbar navbar-expand-lg navbar-light shadow-sm fixed-top">
    <div class="container">
      <a class="navbar-brand d-flex align-items-center" href="../dashboard/dashboard.php">
        <img src="../image/natcon.jpg" alt="PSME Logo" class="me-3 rounded-circle" style="height:55px;">
        <div>
          <span class="fs-5 fw-bold d-block">73rd PSME</span>
          <small class="d-none d-md-block fw-medium">National Convention</small>
        </div>
      </a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navSignup"><span class="navbar-toggler-icon"></span></button>
      <div class="collapse navbar-collapse" id="navSignup">
        <ul class="navbar-nav ms-auto align-items-center">
          <li class="nav-item"><a class="nav-link" href="../dashboard/dashboard.php">Home</a></li>
            <li class="nav-item"><a class="nav-link" href="../dashboard/about.php">About</a></li>
            <li class="nav-item"><a class="nav-link" href="../dashboard/programs.php">Programs</a></li>
            <li class="nav-item ms-2 d-flex gap-2">
              <a href="login.php" class="btn btn-nav-auth rounded-pill px-4 py-2 fw-medium">Login</a>
            </li>
        </ul>
      </div>
    </div>
  </nav>
  <div style="height:80px"></div>

  <!-- Toast container -->
  <div class="position-fixed top-0 end-0 p-3" style="z-index:1080">
    <div id="toast" class="toast align-items-center text-bg-primary border-0" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="d-flex">
        <div id="toastBody" class="toast-body">...</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
    </div>
  </div>

  <div class="login-container">
    <div class="login-card">
      <div class="login-image">
        <img src="../image/natcon.jpg" alt="Signup Image"/>
      </div>
      <div class="login-form">
        <form id="signup-form" novalidate>
          <h2 class="fw-bold">SIGN UP</h2>
          <div id="msg" class="message mb-2"></div>

          <label for="name" class="mt-2">Name</label>
          <input type="text" class="form-control mb-3" id="name" name="name" required maxlength="150" autocomplete="name" placeholder="Enter your name" />

          <label for="email">Email</label>
          <input type="email" class="form-control mb-3" id="email" name="email" required maxlength="190" autocomplete="email" placeholder="Enter your email" />

          <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <div class="password-wrapper" style="position:relative;">
              <input type="password" class="form-control" id="password" name="password" required minlength="8" autocomplete="new-password" placeholder="Create a password" style="padding-right:50px;" />
              <button type="button" class="toggle-pass-btn" id="togglePwd" aria-label="Show password" style="position:absolute;top:50%;right:10px;transform:translateY(-50%);background:transparent;border:none;color:#0d6efd;cursor:pointer;padding:4px;display:flex;align-items:center;justify-content:center;z-index:100;min-width:28px;min-height:28px;font-size:16px;">👁️</button>
            </div>
          </div>

          <div class="mb-3">
            <label for="confirm_password" class="form-label">Confirm Password</label>
            <div class="password-wrapper" style="position:relative;">
              <input type="password" class="form-control" id="confirm_password" required minlength="8" autocomplete="new-password" placeholder="Re-enter password" style="padding-right:50px;" />
              <button type="button" class="toggle-pass-btn" id="toggleConfirmPwd" aria-label="Show confirm password" style="position:absolute;top:50%;right:10px;transform:translateY(-50%);background:transparent;border:none;color:#0d6efd;cursor:pointer;padding:4px;display:flex;align-items:center;justify-content:center;z-index:100;min-width:28px;min-height:28px;font-size:16px;">👁️</button>
            </div>
            <div id="pwdMatchMsg" class="small mt-1"></div>
          </div>

          <button id="btnSubmit" type="submit" class="btn btn-primary w-100">Create Account</button>

          <div class="mt-5 text-center small">
            <span class="me-2">Already have an account?</span><a href="login.php" class="fw-semibold">Login</a>
            <span class="mx-2 text-muted">•</span>
            <a href="../dashboard/dashboard.php" class="fw-semibold">Back to Dashboard</a>
          </div>
        </form>
      </div>
    </div>
  </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  const form = document.getElementById('signup-form');
  const btn = document.getElementById('btnSubmit');
  const msg = document.getElementById('msg');
  const email = document.getElementById('email');
  const name = document.getElementById('name');
  const pwd = document.getElementById('password');
  const toggle = document.getElementById('togglePwd');
  const confirmPwd = document.getElementById('confirm_password');
  const toggleConfirm = document.getElementById('toggleConfirmPwd');
  const matchMsg = document.getElementById('pwdMatchMsg');

  function setMsg(text, ok){
    msg.textContent = text || '';
    msg.className = 'message mb-2 ' + (text ? (ok ? 'text-success' : 'text-danger') : '');
  }

  const eyeOpen = '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>';
  const eyeClosed = '<svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l18 18"/><path d="M10.73 10.73a3 3 0 004.24 4.24"/><path d="M6.1 6.1A10.94 10.94 0 001 12s4 8 11 8a10.94 10.94 0 005.9-1.9M14.12 9.88 20 4"/></svg>';
  function renderEye(btn, input){
    const isHidden = input.type === 'password';
    if(isHidden) {
      btn.innerHTML = '👁️';
    } else {
      btn.innerHTML = '🙈';
    }
    btn.setAttribute('aria-label', (isHidden ? 'Show' : 'Hide') + ' password');
  }
  function attachToggle(btn, input){
    if(!btn || !input) return;
    // Set initial icon immediately
    renderEye(btn, input);
    
    btn.addEventListener('click', ()=>{ 
      input.type = input.type==='password' ? 'text':'password'; 
      renderEye(btn,input); 
    });
    btn.addEventListener('keydown', e=>{ 
      if(e.key==='Enter' || e.key===' '){ 
        e.preventDefault(); 
        input.type = input.type==='password' ? 'text':'password'; 
        renderEye(btn,input); 
      }
    });
  }
  
  attachToggle(toggle, pwd);
  attachToggle(toggleConfirm, confirmPwd);

  function updateMatch(){
    if(!confirmPwd.value){
      matchMsg.textContent='';
      matchMsg.className='small mt-1';
      btn.disabled = false; // allow other validation to handle
      return;
    }
    if(pwd.value === confirmPwd.value){
      matchMsg.textContent='Passwords match';
      matchMsg.className='small mt-1 text-success';
      if(!form.dataset.submitting){ btn.disabled = false; }
    } else {
      matchMsg.textContent='Passwords do not match';
      matchMsg.className='small mt-1 text-danger';
      btn.disabled = true;
    }
  }

  pwd.addEventListener('input', updateMatch);
  confirmPwd.addEventListener('input', updateMatch);

  function showToast(text, ok=true){
    const t = document.getElementById('toast');
    const tb = document.getElementById('toastBody');
    t.classList.remove('text-bg-primary','text-bg-danger','text-bg-success');
    t.classList.add(ok ? 'text-bg-success' : 'text-bg-danger');
    tb.textContent = text;
    new bootstrap.Toast(t, { delay: 1500 }).show();
  }

  function ensureOverlay(){
    if(document.getElementById('redirectOverlay')) return document.getElementById('redirectOverlay');
    const div = document.createElement('div');
    div.id='redirectOverlay';
    div.className='position-fixed top-0 start-0 w-100 h-100 d-flex flex-column justify-content-center align-items-center';
    div.style.cssText='background:rgba(255,255,255,0.94);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);z-index:1085;';
    div.innerHTML = `
      <div class="text-center p-4 rounded-4 bg-white shadow" style="min-width:260px;max-width:320px;">
        <div class="spinner-border text-primary mb-3" style="width:3rem;height:3rem;" role="status"></div>
        <h5 class="fw-bold text-primary mb-1" id="redirTitle">Creating account...</h5>
        <p class="small text-muted mb-0" id="redirMsg">Please wait a moment</p>
      </div>`;
    document.body.appendChild(div); return div;
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    setMsg('', true);

    if (!name.value.trim()) return setMsg('Name is required.', false);
    if (!email.value.trim()) return setMsg('Email is required.', false);
    const lower = email.value.trim().toLowerCase();
    const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(lower);
    if (!emailOk) return setMsg('Invalid email.', false);
    if (pwd.value.length < 8) return setMsg('Password must be at least 8 characters.', false);
    if (pwd.value !== confirmPwd.value) return setMsg('Passwords do not match.', false);

    btn.disabled = true;
    form.dataset.submitting = '1';
    const prevText = btn.textContent; btn.textContent = 'Submitting...';
    try {
      const fd = new FormData();
      fd.set('name', name.value.trim());
      fd.set('email', lower);
      fd.set('password', pwd.value);

      const res = await fetch('../api/api_signup.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json().catch(() => ({}));

      if (data.status === 'success') {
        const ov = ensureOverlay();
        const title = ov.querySelector('#redirTitle');
        const msgEl = ov.querySelector('#redirMsg');
        if (title) title.textContent = 'Account Created!';
        if (msgEl) msgEl.textContent = 'Redirecting you to login...';
        setTimeout(()=>{ window.location.href = data.redirect || 'login.php'; }, 1200);
      } else {
        showToast(data.message || 'Signup failed.', false);
      }
    } catch (err) {
      console.error(err);
      showToast('Network error. Please try again.', false);
    } finally {
      btn.disabled = false; btn.textContent = prevText;
      delete form.dataset.submitting;
      updateMatch();
    }
  });
})();
</script>
</body>
</html>
