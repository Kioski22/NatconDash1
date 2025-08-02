<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login</title>
  <link rel="stylesheet" href="css/login.css" />
</head>
<body>

<div class="login-container">
  <div class="login-card">
    <div class="login-image">
      <img src="../image/natcon.jpg" alt="Login Image" />
    </div>
    <div class="login-form">
      <form method="post" action="../api/api_login.php">
        <h2>Login</h2>

        <label for="email">Email</label>
        <input type="text" id="email" name="email" placeholder="Email" required />

        <label for="password">Password</label>
        <input type="password" id="password" name="password" placeholder="Password" required />

        <div class="forgot-password">
          <a href="#">Forgot Password?</a>
        </div>

        <button type="submit">Login</button>

        <div class="forgot-password">
          <a href="signup.php">Don't have an account? Sign up</a>
        </div>

        <!-- Loading Overlay -->
        <?php if (!isset($_SESSION['user']['id'])): ?>
          <div id="loadingOverlay" class="position-fixed top-0 start-0 w-100 h-100 d-none justify-content-center align-items-center" style="background: rgba(255,255,255,0.95); backdrop-filter: blur(10px); z-index: 9999;">
            <div class="text-center">
              <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;">
                <span class="visually-hidden">Loading...</span>
              </div>
              <h5 class="text-primary">Redirecting...</h5>
            </div>
          </div>
        <?php endif; ?>
      </form>
    </div>
  </div>
</div>

</body>
</html>
