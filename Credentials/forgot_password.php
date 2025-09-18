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
    .card{width:100%;max-width:480px;border-radius:14px}
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
          <label class="form-label" for="email">Email</label>
          <input id="email" name="email" type="email" class="form-control" required
                 autocomplete="email" maxlength="190"
                 placeholder="name@example.com" title="Enter your account email">
        </div>
        <button id="btnRequest" class="btn btn-primary w-100" type="submit">
          <span class="spinner-border spinner-border-sm me-2 d-none" id="spRequest" role="status" aria-hidden="true"></span>
          Send OTP
        </button>
        <input type="hidden" id="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
      </form>

      <!-- Step 2: Verify OTP + set new password -->
      <form id="formReset" class="hidden mt-3">
        <p class="small text-muted mb-2">We sent a 6‑digit code to <strong id="emailMask"></strong>. Check your Inbox/Spam.</p>
        <div class="mb-2">
          <label class="form-label" for="otp">6-digit OTP</label>
          <input id="otp" name="otp" inputmode="numeric" pattern="[0-9]{6}"
                 minlength="6" maxlength="6" class="form-control" required
                 placeholder="123456" title="Enter the 6-digit code">
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
          <button id="btnReset" class="btn btn-success" type="submit">
            <span class="spinner-border spinner-border-sm me-2 d-none" id="spReset" role="status" aria-hidden="true"></span>
            Reset Password
          </button>
          <button id="btnResend" class="btn btn-link" type="button" disabled>
            Resend OTP (<span id="cd">15</span>s)
          </button>
        </div>
        <p class="small text-muted mt-2 mb-0">
          If you don’t receive the email, wait 1–2 minutes or contact support.
        </p>
      </form>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const toastEl = document.getElementById('toast');
    const toastBody = document.getElementById('toastBody');
    function notify(msg, ok=true, delay=2000){
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
    const spRequest = document.getElementById('spRequest');
    const spReset = document.getElementById('spReset');
    const emailMaskEl = document.getElementById('emailMask');

    // Match API session rate limit (15s)
    const RATE_SECONDS = 15;

    let countdown = RATE_SECONDS, timer=null, lockedEmail='';

    function startTimer(){
      btnResend.disabled = true;
      countdown = RATE_SECONDS; cdEl.textContent = countdown;
      clearInterval(timer);
      timer = setInterval(()=>{
        countdown--; cdEl.textContent = countdown;
        if (countdown <= 0){ clearInterval(timer); btnResend.disabled = false; }
      },1000);
    }

    function maskEmail(email){
      const [user, domain] = email.split('@');
      if (!domain) return email;
      const u = user.length <= 2 ? user[0] + '*' : user[0] + '*'.repeat(Math.max(1, user.length - 2)) + user.slice(-1);
      const parts = domain.split('.');
      const d0 = parts[0]; const rest = parts.slice(1).join('.');
      const dmasked = d0[0] + '*'.repeat(Math.max(1, d0.length - 2)) + d0.slice(-1);
      return `${u}@${dmasked}${rest ? '.'+rest : ''}`;
    }

    async function postForm(data){
      const r = await fetch('../api/api_forgot_password.php', {
        method:'POST',
        body: data,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials:'same-origin'
      });
      // Try JSON first, fallback to generic
      let j = null;
      try { j = await r.json(); } catch(_) { /* non-JSON */ }
      return j || { ok:false, message:'Server did not return JSON' };
    }

    formReq.addEventListener('submit', async (e)=>{
      e.preventDefault();
      const email = emailEl.value.trim().toLowerCase();
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){ notify('Enter a valid email.', false); return; }
      btnReq.disabled = true; spRequest.classList.remove('d-none');
      try{
        const fd = new FormData();
        fd.set('action','request');
        fd.set('email', email);
        fd.set('csrf_token', csrf);
        const j = await postForm(fd);
        if (j.ok){
          notify('If the email exists, an OTP was sent.', true);
          lockedEmail = email;
          emailMaskEl.textContent = maskEmail(email);
          formReq.classList.add('hidden');
          formRes.classList.remove('hidden');
          startTimer();
        } else {
          notify(j.message || 'Unable to send OTP.', false);
        }
      }catch(err){ console.error(err); notify('Network error.', false); }
      finally{ btnReq.disabled = false; spRequest.classList.add('d-none'); }
    });

    btnResend.addEventListener('click', async ()=>{
      if (!lockedEmail) return;
      btnResend.disabled = true;
      try{
        const fd = new FormData();
        fd.set('action','request');
        fd.set('email', lockedEmail);
        fd.set('csrf_token', csrf);
        const j = await postForm(fd);
        if (j.ok){
          notify('OTP re-sent if email exists.', true);
          startTimer();
        } else {
          notify(j.message || 'Unable to resend OTP.', false);
          btnResend.disabled = false;
        }
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

      btnRes.disabled = true; spReset.classList.remove('d-none');
      try{
        const fd = new FormData();
        fd.set('action','reset');
        fd.set('email', lockedEmail || emailEl.value.trim().toLowerCase());
        fd.set('otp', otp);
        fd.set('new_password', np);
        fd.set('csrf_token', csrf);
        const j = await postForm(fd);
        if (j.ok){
          notify('Password updated. Redirecting to login…', true, 1200);
          setTimeout(()=>{ window.location.href = './login.php'; }, 1200);
        } else {
          notify(j.message || 'Invalid OTP or expired.', false);
        }
      }catch(err){ console.error(err); notify('Network error.', false); }
      finally{ btnRes.disabled = false; spReset.classList.add('d-none'); }
    });
  </script>
</body>
</html>