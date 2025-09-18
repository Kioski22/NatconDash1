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
    :root { --bg-color:#f4f4f4; }
    body { background-color:var(--bg-color); font-family:"Poppins",sans-serif; display:flex; justify-content:center; align-items:center; min-height:100vh; }
    .form-wrapper { max-width:1000px; margin:24px auto; background:#fff; border-radius:1rem; box-shadow:0 2px 10px rgba(0,0,0,.1); overflow:hidden; }
    .form-image { width:100%; height:auto; object-fit:cover; border-top-left-radius:1rem; border-top-right-radius:1rem; }
    .form-flex { display:block; }
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
          <h2 class="text-center mb-4">Sign Up</h2>

          <form id="signup-form" novalidate>
            <div class="mb-3">
              <label for="name" class="form-label">Name</label>
              <input type="text" class="form-control" id="name" name="name" required maxlength="150" autocomplete="name" />
            </div>

            <div class="mb-3">
              <label for="email" class="form-label">Email</label>
              <input type="email" class="form-control" id="email" name="email" required maxlength="190" autocomplete="email" />
            </div>

            <div class="mb-3 position-relative">
              <label for="password" class="form-label">Password</label>
              <input type="password" class="form-control" id="password" name="password" required minlength="8" autocomplete="new-password" style="padding-right:60px" />
              <span class="eye-icon" id="togglePwd">Show</span>
            </div>

            <button id="btnSubmit" type="submit" class="btn btn-success w-100">Sign Up</button>
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
        showToast('Signup successful. Redirecting…', true);
        setTimeout(() => {
          window.location.href = data.redirect || '../dashboard/dashboard.php';
        }, 1500);
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
