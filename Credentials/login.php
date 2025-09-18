<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Login</title>
  <link rel="stylesheet" href="css/login.css" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap" rel="stylesheet">
</head>
<body>

<div class="login-container">
  <div class="login-card">
    <div class="login-image">
      <img src="../image/natcon.jpg" alt="Login Image"/>
    </div>
    <div class="login-form">
      <form method="post" action="../api/api_login.php">
        <h2>LOGIN</h2>

        <label for="email">Email</label>
        <input type="text" id="email" name="email" placeholder="Enter your email" required />

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
