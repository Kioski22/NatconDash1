<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Sign Up</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <style>
    :root { --bg-color:#f4f4f4; --btn-color: #111e6c; }
    body { background-color:var(--bg-color); font-family:"Poppins",sans-serif; display:flex; justify-content:center; align-items:center; min-height:100vh; }
    .form-wrapper { max-width:1000px; margin:24px auto; background:#fff; border-radius:1rem; box-shadow:0 2px 10px rgba(0,0,0,.1); overflow:hidden; }
    .form-image { width:100%; height:auto; object-fit:cover; border-top-left-radius:1rem; border-top-right-radius:1rem; }
    .form-flex { display:block; }
    #btnSubmit{background-color: var(--btn-color);}
    .logn{
      margin: 10px 0;
      text-align: right;
    }
    .logn a{
      font-size: 12px;
      color: var(--btn-color);
      text-decoration: none;
    }
    h2{
      margin-bottom: 20px;
      font-size: 22px;
      text-align: center;
    }
    input{
      width: 100%;
      padding: 8px;
      font-size: 14px;
      border: 1px solid #aaa;
      border-radius: 5px;
    }
    @media (min-width:768px){ .form-flex{ display:flex; } .form-image{ max-width:50%; border-top-right-radius:0; border-bottom-left-radius:1rem; } }
    .form-padding { padding:2rem; flex:1; }
    .form-control { background-color:#f9f9f9; }
    .message { text-align:center; font-weight:600; }
    .eye-icon { position:absolute; right:10px; top:50%; transform:translateY(-50%); cursor:pointer; user-select:none; font-size:12px; color:#0d6efd; }
    .btn[disabled]{ opacity:.65; cursor:not-allowed; }
  </style>
</head>
<body>
  <!-- Toast container -->
  <div class="position-fixed top-0 end-0 p-3" style="z-index:1080">
    <div id="toast" class="toast align-items-center text-bg-primary border-0" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="d-flex">
        <div id="toastBody" class="toast-body">...</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
    </div>
  </div>

  <div class="container">
    <div class="form-wrapper">
      <div class="form-flex">
        <img src="../image/natcon.jpg" alt="Natcon" class="img-fluid form-image" />
        <div class="form-padding">
          <div id="msg" class="message mb-2"></div>
          <h2 class="text-center mb-4">SIGN UP</h2>

          <form id="signup-form" novalidate>
            <div class="mb-3">
              <label for="name" class="form-label">Name</label>
              <input type="text" class="form-control" id="name" name="name" required maxlength="150" autocomplete="name" placeholder="Enter your name"/>
            </div>

            <div class="mb-3">
              <label for="email" class="form-label">Email</label>
              <input type="email" class="form-control" id="email" name="email" required maxlength="190" autocomplete="email" placeholder="Enter your email"/>
            </div>

            <div class="mb-3 position-relative">
              <label for="password" class="form-label">Password</label>
              <input type="password" class="form-control" id="password" name="password" required minlength="8" autocomplete="new-password" style="padding-right:60px" placeholder="Create a password"/>
              <span class="eye-icon" id="togglePwd">Show</span>
            </div>

            <button id="btnSubmit" type="submit" class="btn w-100 text-white">Sign Up</button>

            <div class="logn">
               <a href="login.php">Already have an account? Login</a>
            </div>

          </form>
        </div>
      </div>
    </div>
  </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  const form = document.getElementById('signup-form');
  const btn = document.getElementById('btnSubmit');
  const msg = document.getElementById('msg');
  const email = document.getElementById('email');
  const name = document.getElementById('name');
  const pwd = document.getElementById('password');
  const toggle = document.getElementById('togglePwd');

  function setMsg(text, ok){
    msg.textContent = text || '';
    msg.className = 'message mb-2 ' + (text ? (ok ? 'text-success' : 'text-danger') : '');
  }

  toggle.addEventListener('click', () => {
    const show = pwd.type === 'password';
    pwd.type = show ? 'text' : 'password';
    toggle.textContent = show ? 'Hide' : 'Show';
  });

  function showToast(text, ok=true){
    const t = document.getElementById('toast');
    const tb = document.getElementById('toastBody');
    t.classList.remove('text-bg-primary','text-bg-danger','text-bg-success');
    t.classList.add(ok ? 'text-bg-success' : 'text-bg-danger');
    tb.textContent = text;
    new bootstrap.Toast(t, { delay: 1500 }).show();
  }

  // Create a lightweight redirect overlay (only inserted when needed)
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

    // client-side validation
    if (!name.value.trim()) return setMsg('Name is required.', false);
    if (!email.value.trim()) return setMsg('Email is required.', false);
    const lower = email.value.trim().toLowerCase();
    const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(lower);
    if (!emailOk) return setMsg('Invalid email.', false);
    if (pwd.value.length < 8) return setMsg('Password must be at least 8 characters.', false);

    btn.disabled = true;
    try {
      const fd = new FormData();
      fd.set('name', name.value.trim());
      fd.set('email', lower);
      fd.set('password', pwd.value);

      const res = await fetch('../api/api_signup.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json().catch(() => ({}));

      if (data.status === 'success') {
        // Show redirect overlay guiding user to login
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
      btn.disabled = false;
    }
  });
})();
</script>
</body>
</html>
