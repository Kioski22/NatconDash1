<?php
session_start(); // Make sure session is started at the top of the file

// Helper vars
$isLoggedIn = isset($_SESSION['user_id']);
$userName = $_SESSION['user_name'] ?? 'Account';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>73rd PSME National Convention</title>

  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Font Awesome -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <!-- Custom CSS -->
  <link rel="stylesheet" href="css/styles.css">

  <style>
    :root {
      --primary-color: #111e6c;
      --primary-dark: #1116ec;
      --primary-light: #dbeafe;
      --secondary-color: #e27900ff;
      --secondary-dark: #ce6e00ff;
      --success-color: #10b981;
      --dark-color: #111e6c;
      --light-gray: #f8fafc;
      --border-color: #e5e7eb;
    }
    
    * {
      font-family: 'Poppins', sans-serif;
    }
    
    .navbar {
      backdrop-filter: blur(20px);
      background: rgba(255, 255, 255, 0.95) !important;
      border-bottom: 1px solid var(--border-color);
    }
    
    .navbar-brand span {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    /* Responsive title sizing for mobile */
    .navbar-brand .brand-title { white-space: normal; line-height: 1.1; }
    @media (max-width: 576px) {
      .navbar-brand .brand-title { font-size: 1rem !important; }
    }
    @media (max-width: 400px) {
      .navbar-brand .brand-title { font-size: 0.9rem !important; }
    }
    
    .btn-primary {
      background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
      border: none;
      box-shadow: 0 4px 15px rgba(37, 99, 235, 0.3);
    }
    
    .btn-primary:hover {
      background: linear-gradient(135deg, var(--primary-dark), var(--primary-color));
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(37, 99, 235, 0.4);
    }
    
    .btn-outline-primary {
      border: 2px solid var(--primary-color);
      color: var(--primary-color);
    }
    
    .btn-outline-primary:hover {
      background: var(--primary-color);
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(37, 99, 235, 0.3);
    }
    
    .btn-warning {
      background: linear-gradient(135deg, var(--secondary-color), var(--secondary-dark));
      border: none;
      box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3);
    }
    
    .btn-warning:hover {
      background: linear-gradient(135deg, var(--secondary-dark), var(--secondary-color));
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(245, 158, 11, 0.4);
    }

    body {
      background: var(--primary-color);
    }
    
    #hero-section {
      position: relative;
      background: 
      linear-gradient(rgba(255, 255, 255, 1), rgba(255, 255, 255, 0.6)), /* dark overlay */
      url("../image/natcon banner.png") center/cover no-repeat;
    }

    .hero-section {
      background: linear-gradient(135deg, var(--light-gray), #ffffffde);
    }
    
    .function-room-section {
      background: linear-gradient(135deg, #ffffff, var(--light-gray));
    }
    
    .program-section {
      background: var(--primary-color);
    }
    
    .card {
      border: none;
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
      transition: all 0.3s ease;
    }
    
    .card:hover {
      transform: translateY(-8px);
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
    }
    
    .shadow-custom {
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
    }
    
    .text-gradient {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .custom-toggler {
      border: 2px solid #111e6c;
      border-radius: 5px;
      background-color: #ffffff;
      padding: 8px 10px;
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
    }

    .custom-toggler .navbar-toggler-icon {
      background-image: none;
      width: 24px;
      height: 2px;
      background-color: #111e6c;
      position: relative;
      display: inline-block;
    }

    .custom-toggler .navbar-toggler-icon::before,
    .custom-toggler .navbar-toggler-icon::after {
      content: "";
      background-color: #111e6c;
      width: 24px;
      height: 2px;
      position: absolute;
      left: 0;
      transition: 0.3s ease;
    }

    .custom-toggler .navbar-toggler-icon::before {
      top: -8px;
    }

    .custom-toggler .navbar-toggler-icon::after {
      top: 8px;
    }

    /* Hover effect */
    .custom-toggler:hover {
      background-color: #f0f0f0;
      border-color: #0e1759;
    }

    .nav-link {
      font-weight: 500;
      transition: all 0.3s ease;
    }
    
    .nav-link:hover {
      color: var(--primary-color) !important;
      transform: translateY(-1px);
    }
    
    .nav-link.active {
      color: var(--primary-color) !important;
      font-weight: 600;
    }
    
    html {
      scroll-behavior: smooth;
    }
    
    .badge-custom {
      background: linear-gradient(135deg, var(--primary-light), rgba(245, 158, 11, 0.1));
      color: var(--primary-color);
      border: 1px solid rgba(37, 99, 235, 0.2);
    }
    
    .hover-link:hover {
      color: var(--secondary-color) !important;
      transform: translateX(5px);
      transition: all 0.3s ease;
    }

    /* Prevent horizontal scrollbar in footer */
    body {
      overflow-x: hidden;
    }

    footer {
      overflow-x: hidden;
      width: 100%;
      box-sizing: border-box;
    }

    footer .container {
      max-width: 100%;
      overflow-x: hidden;
    }

    footer p, footer span {
      word-wrap: break-word;
      overflow-wrap: break-word;
      hyphens: auto;
      max-width: 100%;
    }

    /* Specific fixes for long email addresses */
    footer .text-light span {
      word-break: break-all;
      line-height: 1.4;
    }

    /* Ensure footer content doesn't overflow */
    footer .row {
      margin-left: 0;
      margin-right: 0;
    }

    footer .col-lg-6, footer .col-md-6 {
      padding-left: 15px;
      padding-right: 15px;
      overflow: hidden;
    }

    /* Make sure long organization name wraps properly */
    footer h5 {
      word-wrap: break-word;
      line-height: 1.3;
    }

    /* Responsive adjustments for smaller screens */
    @media (max-width: 768px) {
      footer .d-flex {
        flex-wrap: wrap;
      }
      
      footer .text-light span {
        font-size: 14px;
      }
      
      footer h5 {
        font-size: 1.1rem;
      }
    }

    @media (max-width: 576px) {
      footer .container {
        padding-left: 10px;
        padding-right: 10px;
      }
      
      footer .text-light span {
        font-size: 13px;
      }
    }

    /* Hero Section Mobile Optimization */
    #hero-section {
      min-height: 100vh;
      min-height: 100dvh; /* Dynamic viewport height for mobile browsers */
    }

    /* Ensure hero content is properly centered on mobile */
    @media (max-width: 768px) {
      #hero-section {
        min-height: 100vh;
        min-height: 100svh; /* Small viewport height for mobile */
        display: flex;
        align-items: center;
      }
      
      #hero-section .container {
        width: 100%;
        padding-left: 1rem;
        padding-right: 1rem;
      }
      
      #hero-section .row {
        min-height: 100vh;
        min-height: 100svh;
      }
      
      #hero-section h1 {
        font-size: 2.5rem;
        line-height: 1.2;
      }
      
      #hero-section .lead {
        font-size: 1rem;
        margin-bottom: 2rem !important;
      }
      
      #hero-section .btn {
        font-size: 0.9rem;
        padding: 0.75rem 2rem;
      }
    }

    @media (max-width: 576px) {
      #hero-section h1 {
        font-size: 2rem;
      }
      
      #hero-section .btn {
        font-size: 0.85rem;
        padding: 0.7rem 1.5rem;
      }
    }

    /* Loading Overlay (central for all screen sizes) */
    .loading-overlay { position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(255,255,255,0.94); backdrop-filter:blur(18px); -webkit-backdrop-filter:blur(18px); z-index:2000; }
    .loading-overlay.active { display:flex; }
    .loading-box { background:#ffffff; padding:2rem 2.25rem; border-radius:1.25rem; box-shadow:0 8px 32px rgba(0,0,0,0.12); border:1px solid #e5e7eb; min-width:250px; max-width:320px; }
    .loading-box h5 { font-size:1.05rem; }
    @keyframes popIn { from { opacity:0; transform:scale(.9);} to { opacity:1; transform:scale(1);} }
    .loading-box { animation: popIn .35s ease; }
  </style>
  </head>
  <body>
    <!-- Header Section -->
    <nav class="navbar navbar-expand-lg navbar-light shadow-custom fixed-top">
      <div class="container">
        <!-- Logo + Title -->
        <a class="navbar-brand d-flex align-items-center" href="#">
          <img src="../image/natcon.jpg" alt="PSME Logo" class="me-3 rounded-circle" style="height:55px;">
          <div>
            <span class="brand-title fs-5 fw-bold d-block">73rd PSME National Convention</span>
            
          </div>
        </a>

        <!-- Navbar Toggle -->
        <button class="navbar-toggler custom-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Navbar Links -->
        <div class="collapse navbar-collapse" id="navbarNav">
          <ul class="navbar-nav ms-auto align-items-center">
            <li class="nav-item">
              <a class="nav-link active fw-semibold px-3" href="dashboard.php">Home</a>
            </li>
            <li class="nav-item">
              <a class="nav-link fw-semibold px-3" href="about.php">About</a>
            </li>
            <li class="nav-item">
              <a class="nav-link fw-semibold px-3" href="#footer">Contact</a>
            </li>

            <li class="nav-item ms-3">
              <?php if ($isLoggedIn): ?>
                <!-- Logged-in navbar (user dropdown) -->
                <div class="dropdown">
                  <button class="btn btn-outline-primary rounded-pill px-4 py-2 fw-medium dropdown-toggle d-flex align-items-center" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="me-2 d-inline-flex justify-content-center align-items-center rounded-circle bg-primary text-white" style="width:28px;height:28px;">
                      <?php echo strtoupper(substr($userName,0,1)); ?>
                    </span>
                    <span class="d-none d-sm-inline"><?php echo htmlspecialchars($userName, ENT_QUOTES); ?></span>
                  </button>
                  <ul class="dropdown-menu dropdown-menu-end shadow">
                    <li><a class="dropdown-item" href="dashboard.php"><i class="fas fa-home me-2 text-primary"></i>Dashboard</a></li>
                    <li><a class="dropdown-item" href="programs.php"><i class="fas fa-calendar-alt me-2 text-primary"></i>Programs</a></li>
                    <li><a class="dropdown-item" href="../Credentials/forgot_password.php"><i class="fas fa-key me-2 text-primary"></i>Change Password</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="../Credentials/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                  </ul>
                </div>
              <?php else: ?>
                <!-- Guest navbar -->
                <button id="customLoginBtn" class="btn btn-primary rounded-pill px-4 py-2 me-2 fw-medium">
                  <i class="fas fa-sign-in-alt me-2"></i>Login
                </button>
                <button id="customSignupBtn" class="btn btn-outline-primary rounded-pill px-4 py-2 fw-medium">
                  <i class="fas fa-user-plus me-2"></i>Sign Up
                </button>
              <?php endif; ?>
            </li>
          </ul>
        </div>

        <!-- (Overlay moved below nav) -->
      </div>
    </nav>

    <?php if (!$isLoggedIn): ?>
    <div id="loadingOverlay" class="loading-overlay d-none" aria-hidden="true" role="alert" aria-live="polite">
      <div class="loading-box text-center">
        <div class="spinner-border text-primary mb-3" role="status" aria-hidden="true" style="width:3rem;height:3rem;"></div>
        <h5 id="loadingOverlayTitle" class="text-primary fw-medium mb-1">Redirecting...</h5>
        <p id="loadingOverlayMsg" class="text-muted small mb-0">Please wait a moment</p>
      </div>
    </div>
    <?php endif; ?>

  <!-- Main Content Section -->
  <main class="content" style="margin-top: 85px;">
    <!-- Top Full Width Image -->
    <section class="hero-section" id="hero-section">
      <div class="container ">
        <div class="row align-items-center min-vh-100">
          <div class="col-12 text-center">
            <h1 class="display-3 fw-bold mb-3 text-gradient">Welcome to PSME <br> National Convention</h1>
            <p class="lead mb-5 mx-auto fw-medium text-black" style="max-width: 600px;">
              Join us for the most prestigious mechanical engineering convention in the Philippines. 
              Connect, learn, and innovate with industry leaders.
            </p>
            <div class="d-flex flex-column flex-sm-row gap-3 justify-content-center">
              <?php if(!$isLoggedIn): ?>
              <a href="../Credentials/login.php" id="heroGetStartedBtn" class="btn btn-primary btn-lg rounded-pill px-5 py-3 fw-medium">
                <i class="fas fa-rocket me-2"></i>Get Started
              </a>
              <?php else: ?>
              <a href="programs.php" class="btn btn-primary btn-lg rounded-pill px-5 py-3 fw-medium">
                <i class="fas fa-rocket me-2"></i>Go to Programs
              </a>
              <?php endif; ?>
              <a href="#program" class="btn btn-outline-primary btn-lg rounded-pill px-5 py-3 fw-medium">
                <i class="fa  s fa-calendar me-2"></i>View Program
              </a>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Bottom Section with Image on Left and Text on Right -->
    <section id="function-room" class="function-room-section py-5">
      <div class="container">
        <div class="row align-items-center g-5 mb-5">
          <div class="col-lg-6 mb-4 mb-lg-0">
            <div class="position-relative">
              <img src="../image/natcon banner.png" alt="Function Room Image" class="img-fluid rounded-custom shadow-custom">
              <div class="position-absolute top-0 start-0 m-3">
                <span class="badge bg-success text-white fs-8 px-3 py-2 rounded-pill">
                  <i class="fas fa-star me-1"></i>Featured
                </span>
              </div>
            </div>
          </div>
          <div class="col-lg-6">
            <div class="ps-lg-4">
              <div class="mb-4">
                <span class="badge badge-custom fs-8 px-3 py-2 rounded-pill mb-3">
                  <i class="fas fa-users me-2"></i>Interactive Experience
                </span>
              </div>
              <h2 class="display-6 fw-bold mb-4 text-gradient">FUNCTION ROOM</h2>
              <p class="lead mb-4">
                Experience cutting-edge attendance tracking, engaging interactive quizzes, 
                and comprehensive evaluation systems designed for modern conventions.
              </p>
              <div class="d-flex flex-column flex-sm-row gap-3">
                <?php if(!$isLoggedIn): ?>
                  <a href="../Credentials/signup.php" class="btn btn-warning btn-lg rounded-pill px-3 py-3 fs-6 fw-medium text-black">
                    <i class="fas fa-user-plus me-2"></i>Sign Up Now
                  </a>
                <?php else: ?>
                  <a href="programs.php" class="btn btn-warning btn-lg rounded-pill px-3 py-3 fs-6 fw-medium text-black">
                    <i class="fas fa-door-open me-2"></i>Enter Function Room
                  </a>
                <?php endif; ?>
                <a href="#" class="btn btn-outline-secondary btn-lg rounded-pill px-3 py-3 fs-6 fw-medium">
                  <i class="fas fa-info-circle me-2"></i>Learn More
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Program Section Full Width Blue Background -->
    <section id="program" class="program-section py-5">
      <div class="container">
        <div class="row">
          <div class="col-12 text-center">
            <h2 class="display-6 fw-bold mb-4 text-white">Convention Programme</h2>
            <p class="lead text-white mb-5 mx-auto" style="max-width: 650px;">
              Discover our comprehensive schedule packed with expert sessions, networking opportunities, and innovation showcases.
            </p>
            <div class="row justify-content-center">
              <div class="col-lg-6">
                <div class="card border-0 rounded-custom overflow-hidden">
                  <img src="../image/natcon.jpg" alt="Program Schedule" class="card-img-top">
                  <div class="card-body p-4">
                    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3">
                      <div class="text-center text-sm-start">
                        <h5 class="card-title mb-1 fw-bold">Complete Event Schedule</h5>
                        <p class="mb-0 small" style="max-width: 200px;">Download the full program with all sessions and timings</p>
                      </div>
                      <a href="#" class="btn btn-primary rounded-pill px-3 py-3 fw-medium">
                        <i class="fas fa-download me-2"></i>Download PDF
                      </a>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </main>

 <!-- Footer Section -->
<footer class="bg-white text-black position-relative overflow-hidden" id="footer">
  <div class="position-absolute top-0 start-0 w-100 h-100 bg-white"></div>
  <!-- Main Footer Content -->
  <div class="container py-5 position-relative">
    <div class="row g-4">
      <div class="col-lg-6">
        <div class="d-flex align-items-center mb-4">
          <img src="../image/psme_logo.png" alt="PSME Logo" class="me-3 rounded-custom shadow-sm" style="height: 60px;">
          <div>
            <h5 class="fw-bold mb-1 text-black">Philippine Society of Mechanical Engineers</h5>
            <p class="text-black mb-0 fw-medium">Excellence in Engineering Innovation</p>
          </div>
        </div>
        <div class="text-black">
          <p class="mb-3 d-flex align-items-start">
            <i class="fas fa-map-marker-alt me-3 mt-1 flex-shrink-0" style="color: #000e6c"></i>
            <span>19 Scout Bayoran St., Brgy. South Triangle<br>Quezon City, Philippines</span>
          </p>
          <p class="mb-3 d-flex align-items-start">
            <i class="fas fa-phone me-3 mt-1 flex-shrink-0" style="color: #000e6c"></i>
            <span>(02) 7752-2527 | (02) 8285-6585</span>
          </p>
          <p class="mb-0 d-flex align-items-start">
            <i class="fas fa-envelope me-3 mt-1 flex-shrink-0" style="color: #000e6c"></i>
            <span>membership@psmeinc.org.ph<br>icto@psmeinc.org.ph</span>
          </p>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="row">
          <div class="col-md-6">
            <h6 class="fw-bold mb-3 text-black">Quick Links</h6>
            <ul class="list-unstyled">
              <li class="mb-2">
                <a href="dashboard.php" class="text-black text-decoration-none d-flex align-items-center hover-link">
                  <i class="fas fa-chevron-right me-2 small" style="color: #000e6c"></i>Home
                </a>
              </li>
              <li class="mb-2">
                <a href="about.php" class="text-black text-decoration-none d-flex align-items-center hover-link">
                  <i class="fas fa-chevron-right me-2 small" style="color: #000e6c"></i>About Us
                </a>
              </li>
              <li class="mb-2">
                <a href="programs.php" class="text-black text-decoration-none d-flex align-items-center hover-link">
                  <i class="fas fa-chevron-right me-2 small" style="color: #000e6c"></i>Programs
                </a>
              </li>
            </ul>
          </div>
          <div class="col-md-6">
            <h6 class="fw-bold mb-3 text-black">Connect With Us</h6>
            <div class="d-flex gap-3 mb-4">
              <ul class="list-unstyled">
                <li>
                  <a href="https://www.facebook.com/psmeinc" class="fs-5 hover-link text-decoration-none" style="color: #000e6c">
                <i class="fab fa-facebook"></i> <span class="small align-items-center">PSME Official</span>
              </a>
            </li>
              <li>
                <a href="https://www.facebook.com/natconpsme" class="fs-5 hover-link text-decoration-none" style="color: #000e6c">
                <i class="fab fa-facebook"></i> <span class="small">PSME NatCon</span>
              </a>
            </li>
              </ul>
            </div>
            <div class="bg-secondary bg-opacity-25 rounded-custom p-3 border border-secondary border-opacity-50">
              <h6 class="fw-bold mb-2 text-black small">Stay Updated</h6>
              <p class="text-black small mb-0">Get the latest convention updates and announcements.</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  
  <!-- Bottom Footer -->
  <div class="py-3 position-relative" style="background: var(--primary-color)">
    <div class="container">
      <div class="row align-items-center g-2">
        <div class="col-md-6 col-12">
          <p class="mb-0 small text-white text-center text-md-start">© 2025 PSME. All rights reserved.</p>
        </div>
        <div class="col-md-6 col-12 text-center text-md-end">
          <p class="mb-0 small text-white">
            Developed with <i class="fas fa-heart text-danger mx-1"></i> by 
            <strong class="text-white">PSME - ICTO</strong> and Jemuel Agban
          </p>
        </div>
      </div>
    </div>
  </div>
</footer>

  <!-- Bootstrap JS and dependencies -->
  <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.min.js"></script>

  <script>
    (function(){
      const loginBtn = document.getElementById('customLoginBtn');
      const signupBtn = document.getElementById('customSignupBtn');
      const overlay = document.getElementById('loadingOverlay');
      const titleEl = document.getElementById('loadingOverlayTitle');
      const msgEl = document.getElementById('loadingOverlayMsg');

      function forceReflow(el){ if(!el) return; void el.offsetHeight; }

      function showOverlay(title='Redirecting...', msg='Please wait a moment') {
        if (!overlay) return;
        if (titleEl) titleEl.textContent = title;
        if (msgEl) msgEl.textContent = msg;
        overlay.classList.remove('d-none');
        overlay.classList.add('active','d-flex'); // d-flex as bootstrap fallback
        overlay.setAttribute('aria-hidden','false');
        forceReflow(overlay);
      }

      function handleRedirect(targetURL, title, msg) {
        showOverlay(title, msg);
        // Use two animation frames to ensure paint before navigation
        requestAnimationFrame(()=>{
          setTimeout(()=>{ window.location.href = targetURL; }, 700);
        });
      }

      if (loginBtn) {
        loginBtn.addEventListener('click', (e) => { e.preventDefault(); handleRedirect('../Credentials/login.php','Redirecting to Login...','Please wait while we prepare the page'); });
      }
      if (signupBtn) {
        signupBtn.addEventListener('click', (e) => { e.preventDefault(); handleRedirect('../Credentials/signup.php','Redirecting to Sign Up...','Loading registration form'); });
      }
      const heroBtn = document.getElementById('heroGetStartedBtn');
      if(heroBtn){
        heroBtn.addEventListener('click', (e)=>{ e.preventDefault(); handleRedirect('../Credentials/login.php','Redirecting to Login...','Preparing your session'); });
      }
    })();
  </script>

  <?php if (!$isLoggedIn): ?>
  <!-- First-Visit Onboarding Modal (guests only) -->
  <div class="modal fade" id="onboardModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content border-0 shadow">
        <div class="modal-header border-0">
          <h5 class="modal-title fw-bold text-primary">Welcome</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p class="mb-3">To participate in sessions and earn certificates, follow these steps:</p>
          <ol class="ps-3 small mb-0">
            <li class="mb-2">Sign up for an account, then log in.</li>
            <li class="mb-2">Enter the Function Room.</li>
            <li class="mb-2">Select the day you are currently attending.</li>
            <li class="mb-2">Choose your program:
              <ul class="mt-1">
                <li><strong>Main Program:</strong> select the speaker you will attend.</li>
                <li><strong>Sub Program:</strong> select the technical conference first, then choose the speaker.</li>
              </ul>
            </li>
          </ol>
        </div>
        <div class="modal-footer border-0 d-flex justify-content-between">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" value="1" id="onboardDontShow">
            <label class="form-check-label small" for="onboardDontShow">Don't show again</label>
          </div>
          <div class="d-flex gap-2">
            <?php if (!$isLoggedIn): ?>
            <a href="../Credentials/signup.php" class="btn btn-outline-primary btn-sm">Sign Up</a>
            <a href="../Credentials/login.php" class="btn btn-primary btn-sm">Login</a>
            <?php else: ?>
            <a href="programs.php" class="btn btn-primary btn-sm">Enter Function Room</a>
            <?php endif; ?>
            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Got it</button>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <script>
    // Auto-open onboarding modal on first visit to dashboard (guests only)
    (function(){
      const STORAGE_KEY = 'natcon_onboard_seen_v1_dashboard';
      try {
        // If user is logged in, do nothing (modal not present)
        const isLoggedIn = <?= $isLoggedIn ? 'true':'false' ?>;
        if (isLoggedIn) return;
        const seen = localStorage.getItem(STORAGE_KEY) === '1';
        // Only show on first land if not seen
        if (!seen) {
          const modalEl = document.getElementById('onboardModal');
          if (modalEl && window.bootstrap) {
            const modal = new bootstrap.Modal(modalEl, { backdrop: 'static', keyboard: true });
            // Delay slightly to avoid clashing with initial layout/animations
            setTimeout(()=> modal.show(), 400);
            const dontShow = document.getElementById('onboardDontShow');
            const markSeen = () => { if (dontShow?.checked) { localStorage.setItem(STORAGE_KEY, '1'); } };
            modalEl.addEventListener('hidden.bs.modal', markSeen);
          }
        }
      } catch(e) { /* ignore storage errors */ }
    })();
  </script>

</body>
</html>