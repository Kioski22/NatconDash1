<?php
session_start();
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Forgot Password</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f5f7fb;font-family:system-ui,Segoe UI,Roboto}
    .card{width:100%;max-width:440px;border-radius:14px}
    .hidden{display:none!important}
  </style>
</head>
<body>
  <!-- Toast -->
  <div class="position-fixed top-0 end-0 p-3" style="z-index:1080">
    <div id="toast" class="toast text-bg-primary border-0" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="d-flex">
        <div id="toastBody" class="toast-body">...</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <h4 class="mb-3">Forgot password</h4>

      <!-- Step 1: Request OTP -->
      <form id="formRequest">
        <div class="mb-3">
          <label class="form-label">Email</label>
          <input id="email" name="email" type="email" class="form-control" required autocomplete="email" maxlength="190">
        </div>
        <button id="btnRequest" class="btn btn-primary w-100" type="submit">Send OTP</button>
        <input type="hidden" id="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
      </form>

      <!-- Step 2: Verify OTP + set new password -->
      <form id="formReset" class="hidden mt-3">
        <div class="mb-2">
          <label class="form-label">6-digit OTP</label>
          <input id="otp" name="otp" inputmode="numeric" pattern="\\d{6}" minlength="6" maxlength="6" class="form-control" required>
        </div>
        <div class="mb-2">
          <label class="form-label">New password</label>
          <input id="newpwd" name="newpwd" type="password" class="form-control" minlength="8" required autocomplete="new-password">
        </div>
        <div class="mb-3">
          <label class="form-label">Confirm password</label>
          <input id="confirmpwd" type="password" class="form-control" minlength="8" required autocomplete="new-password">
        </div>
        <div class="d-flex justify-content-between align-items-center">
          <button id="btnReset" class="btn btn-success" type="submit">Reset Password</button>
          <button id="btnResend" class="btn btn-link" type="button" disabled>Resend OTP (<span id="cd">60</span>s)</button>
        </div>
      </form>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const toastEl = document.getElementById('toast');
    const toastBody = document.getElementById('toastBody');
    function notify(msg, ok=true, delay=1800){
      toastEl.classList.remove('text-bg-success','text-bg-danger','text-bg-primary');
      toastEl.classList.add(ok ? 'text-bg-success':'text-bg-danger');
      toastBody.textContent = msg;
      new bootstrap.Toast(toastEl, {delay}).show();
    }
    const emailEl = document.getElementById('email');
    const formReq = document.getElementById('formRequest');
    const formRes = document.getElementById('formReset');
    const btnReq = document.getElementById('btnRequest');
    const btnRes = document.getElementById('btnReset');
    const btnResend = document.getElementById('btnResend');
    const cdEl = document.getElementById('cd');
    const csrf = document.getElementById('csrf').value;

    let countdown = 60, timer=null, lockedEmail='';
    function startTimer(){
      btnResend.disabled = true;
      countdown = 60; cdEl.textContent = countdown;
      clearInterval(timer);
      timer = setInterval(()=>{
        countdown--; cdEl.textContent = countdown;
        if (countdown <= 0){ clearInterval(timer); btnResend.disabled = false; }
      },1000);
    }

    formReq.addEventListener('submit', async (e)=>{
      e.preventDefault();
      const email = emailEl.value.trim().toLowerCase();
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){ notify('Enter a valid email.', false); return; }
      btnReq.disabled = true;
      try{
        const fd = new FormData();
        fd.set('action','request');
        fd.set('email', email);
        fd.set('csrf_token', csrf);
        const r = await fetch('../api/api_forgot_password.php', {method:'POST', body:fd, credentials:'same-origin'});
        const j = await r.json().catch(()=>({ok:false,message:'Server error'}));
        if (j.ok){
          notify('If the email exists, an OTP was sent.', true);
          formReq.classList.add('hidden');
          formRes.classList.remove('hidden');
          lockedEmail = email;
          startTimer();
        } else {
          notify(j.message || 'Unable to send OTP.', false);
        }
      }catch(err){ console.error(err); notify('Network error.', false); }
      finally{ btnReq.disabled = false; }
    });

    btnResend.addEventListener('click', async ()=>{
      if (!lockedEmail) return;
      btnResend.disabled = true;
      try{
        const fd = new FormData();
        fd.set('action','request');
        fd.set('email', lockedEmail);
        fd.set('csrf_token', csrf);
        const r = await fetch('../api/api_forgot_password.php', {method:'POST', body:fd, credentials:'same-origin'});
        await r.json();
        notify('OTP re-sent if email exists.', true);
        startTimer();
      }catch(_){ notify('Network error.', false); btnResend.disabled = false; }
    });

    formRes.addEventListener('submit', async (e)=>{
      e.preventDefault();
      const otp = document.getElementById('otp').value.trim();
      const np = document.getElementById('newpwd').value;
      const cp = document.getElementById('confirmpwd').value;
      if (!/^\d{6}$/.test(otp)){ notify('OTP must be 6 digits.', false); return; }
      if (np.length < 8){ notify('Password must be at least 8 characters.', false); return; }
      if (np !== cp){ notify('Passwords do not match.', false); return; }

      btnRes.disabled = true;
      try{
        const fd = new FormData();
        fd.set('action','reset');
        fd.set('email', lockedEmail || emailEl.value.trim().toLowerCase());
        fd.set('otp', otp);
        fd.set('new_password', np);
        fd.set('csrf_token', csrf);
        const r = await fetch('../api/api_forgot_password.php', {method:'POST', body:fd, credentials:'same-origin'});
        const j = await r.json().catch(()=>({ok:false}));
        if (j.ok){
          notify('Password updated. Redirecting to login…', true, 1200);
          setTimeout(()=>{ window.location.href = './login.php'; }, 1200);
        } else {
          notify(j.message || 'Invalid OTP or expired.', false);
        }
      }catch(err){ console.error(err); notify('Network error.', false); }
      finally{ btnRes.disabled = false; }
    });
  </script>
</body>
</html>