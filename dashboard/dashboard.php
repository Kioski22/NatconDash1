<?php
session_start(); // Make sure session is started at the top of the file
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
      --secondary-color: #ffa200ff;
      --secondary-dark: #e27900ff;
      --success-color: #10b981;
      --dark-color: #1f2937;
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
    
    .hero-section {
      background: linear-gradient(135deg, var(--light-gray), #ffffff);
    }
    
    .function-room-section {
      background: linear-gradient(135deg, #ffffff, var(--light-gray));
    }
    
    .program-section {
      background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
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
    
    .rounded-custom {
      border-radius: 20px;
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
  </style>
  </head>
  <body>
    <!-- Header Section -->
    <nav class="navbar navbar-expand-lg navbar-light shadow-custom fixed-top">
      <div class="container">
        <!-- Logo + Title -->
        <a class="navbar-brand d-flex align-items-center" href="#">
          <img src="../image/natcon.jpg" alt="PSME Logo" class="me-3 rounded-custom" style="height: 50px;">
          <div>
            <span class="fs-5 fw-bold d-block">73rd PSME</span>
            <small class="text-muted d-none d-md-block fw-medium">National Convention</small>
          </div>
        </a>

        <!-- Navbar Toggle -->
        <button class="navbar-toggler border-0 shadow-sm" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
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
              <a class="nav-link fw-semibold px-3" href="#">Contact</a>
            </li>
            <li class="nav-item ms-3">
              <?php if (isset($_SESSION['user']['id'])): ?>
                <a class="btn btn-outline-danger rounded-pill px-4 py-2" href="../Credentials/logout.php">
                  <i class="fas fa-sign-out-alt me-2"></i>Logout
                </a>
              <?php else: ?>
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

        <!-- Loading Overlay -->
        <?php if (!isset($_SESSION['user']['id'])): ?>
          <div id="loadingOverlay" class="position-fixed top-0 start-0 w-100 h-100 d-none justify-content-center align-items-center" style="background: rgba(255,255,255,0.98); backdrop-filter: blur(20px); z-index: 9999;">
            <div class="text-center">
              <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;">
                <span class="visually-hidden">Loading...</span>
              </div>
              <h5 class="text-primary fw-medium">Redirecting...</h5>
              <p class="text-muted small">Please wait a moment</p>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </nav>

  <!-- Main Content Section -->
  <main class="content">
    <!-- Top Full Width Image -->
    <section class="hero-section py-5">
      <div class="container">
        <div class="row align-items-center min-vh-75">
          <div class="col-12 text-center">
            <div class="mb-4">
              <span class="badge badge-custom fs-6 px-4 py-2 rounded-pill mt-5 mb-3">
                <i class="fas fa-calendar-alt me-2"></i>73rd National Convention
              </span>
            </div>
            <h1 class="display-3 fw-bold mb-4 text-gradient">Welcome to PSME <br> National Convention</h1>
            <p class="lead mb-5 mx-auto" style="max-width: 600px;">
              Join us for the most prestigious mechanical engineering convention in the Philippines. 
              Connect, learn, and innovate with industry leaders.
            </p>
            <div class="d-flex flex-column flex-sm-row gap-3 justify-content-center">
              <a href="#function-room" class="btn btn-primary btn-lg rounded-pill px-5 py-3 fw-medium">
                <i class="fas fa-rocket me-2"></i>Get Started
              </a>
              <a href="#program" class="btn btn-outline-primary btn-lg rounded-pill px-5 py-3 fw-medium">
                <i class="fas fa-calendar me-2"></i>View Program
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
              <p class="lead text-muted mb-4">
                Experience cutting-edge attendance tracking, engaging interactive quizzes, 
                and comprehensive evaluation systems designed for modern conventions.
              </p>
              <div class="d-flex flex-column flex-sm-row gap-3">
                <a href="function_rooms.php" class="btn btn-warning btn-lg rounded-pill px-5 py-3 fw-medium text-white">
                  <i class="fas fa-door-open me-2"></i>Enter Function Room
                </a>
                <a href="#" class="btn btn-outline-secondary btn-lg rounded-pill px-5 py-3 fw-medium">
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
            <div class="mb-4">
              <span class="badge bg-white text-primary fs-6 px-4 py-2 rounded-pill mb-3 fw-medium">
                <i class="fas fa-calendar-check me-2"></i>Event Schedule
              </span>
            </div>
            <h2 class="display-6 fw-bold mb-4 text-white">Convention Programme</h2>
            <p class="lead text-white-50 mb-5 mx-auto" style="max-width: 600px;">
              Discover our comprehensive schedule packed with expert sessions, networking opportunities, and innovation showcases.
            </p>
            <div class="row justify-content-center">
              <div class="col-lg-8">
                <div class="card border-0 rounded-custom overflow-hidden">
                  <img src="../image/natcon.jpg" alt="Program Schedule" class="card-img-top">
                  <div class="card-body p-4">
                    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3">
                      <div class="text-center text-sm-start">
                        <h5 class="card-title mb-1 fw-bold">Complete Event Schedule</h5>
                        <p class="text-muted mb-0 small">Download the full program with all sessions and timings</p>
                      </div>
                      <a href="#" class="btn btn-primary rounded-pill px-4 py-2 fw-medium">
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
<footer class="bg-dark text-white position-relative overflow-hidden">
  <div class="position-absolute top-0 start-0 w-100 h-100" style="background: linear-gradient(135deg, var(--dark-color), #111827); opacity: 0.9;"></div>
  <!-- Main Footer Content -->
  <div class="container py-5 position-relative">
    <div class="row g-4">
      <div class="col-lg-6">
        <div class="d-flex align-items-center mb-4">
          <img src="../image/psme_logo.png" alt="PSME Logo" class="me-3 rounded-custom shadow-sm" style="height: 60px;">
          <div>
            <h5 class="fw-bold mb-1 text-white">Philippine Society of Mechanical Engineers</h5>
            <p class="text-light mb-0 fw-medium">Excellence in Engineering Innovation</p>
          </div>
        </div>
        <div class="text-light">
          <p class="mb-3 d-flex align-items-start">
            <i class="fas fa-map-marker-alt me-3 mt-1 text-primary flex-shrink-0"></i>
            <span>19 Scout Bayoran St., Brgy. South Triangle<br>Quezon City, Philippines</span>
          </p>
          <p class="mb-3 d-flex align-items-start">
            <i class="fas fa-phone me-3 mt-1 text-primary flex-shrink-0"></i>
            <span>(02) 7752-2527 | (02) 8285-6585</span>
          </p>
          <p class="mb-0 d-flex align-items-start">
            <i class="fas fa-envelope me-3 mt-1 text-primary flex-shrink-0"></i>
            <span class="small">membership@psmeinc.org.ph<br class="d-sm-none"> | <br class="d-sm-none">icto@psmeinc.org.ph</span>
          </p>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="row">
          <div class="col-md-6">
            <h6 class="fw-bold mb-3 text-white">Quick Links</h6>
            <ul class="list-unstyled">
              <li class="mb-2">
                <a href="dashboard.php" class="text-light text-decoration-none d-flex align-items-center hover-link">
                  <i class="fas fa-chevron-right me-2 small text-primary"></i>Home
                </a>
              </li>
              <li class="mb-2">
                <a href="about.php" class="text-light text-decoration-none d-flex align-items-center hover-link">
                  <i class="fas fa-chevron-right me-2 small text-primary"></i>About Us
                </a>
              </li>
              <li class="mb-2">
                <a href="#" class="text-light text-decoration-none d-flex align-items-center hover-link">
                  <i class="fas fa-chevron-right me-2 small text-primary"></i>Contact
                </a>
              </li>
              <li class="mb-2">
                <a href="function_rooms.php" class="text-light text-decoration-none d-flex align-items-center hover-link">
                  <i class="fas fa-chevron-right me-2 small text-primary"></i>Function Room
                </a>
              </li>
            </ul>
          </div>
          <div class="col-md-6">
            <h6 class="fw-bold mb-3 text-white">Connect With Us</h6>
            <div class="d-flex gap-3 mb-4">
              <a href="#" class="text-light fs-5 hover-link">
                <i class="fab fa-facebook"></i>
              </a>
              <a href="#" class="text-light fs-5 hover-link">
                <i class="fab fa-twitter"></i>
              </a>
              <a href="#" class="text-light fs-5 hover-link">
                <i class="fab fa-linkedin"></i>
              </a>
              <a href="#" class="text-light fs-5 hover-link">
                <i class="fab fa-instagram"></i>
              </a>
            </div>
            <div class="bg-secondary bg-opacity-25 rounded-custom p-3 border border-secondary border-opacity-50">
              <h6 class="fw-medium mb-2 text-white small">Stay Updated</h6>
              <p class="text-light small mb-0">Get the latest convention updates and announcements.</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  
  <!-- Bottom Footer -->
  <div class="py-3 position-relative" style="background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));">
    <div class="container">
      <div class="row align-items-center g-2">
        <div class="col-md-6 col-12">
          <p class="mb-0 small text-white opacity-75 text-center text-md-start">© 2025 PSME. All rights reserved.</p>
        </div>
        <div class="col-md-6 col-12 text-center text-md-end">
          <p class="mb-0 small text-white opacity-75">
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
    const loginBtn = document.getElementById('customLoginBtn');
    const signupBtn = document.getElementById('customSignupBtn');
    const overlay = document.getElementById('loadingOverlay');

    function handleRedirect(targetURL) {
      overlay.classList.remove('d-none');
      overlay.classList.add('d-flex');
      setTimeout(() => {
        window.location.href = targetURL;
      }, 2000);
    }

    if (loginBtn) {
      loginBtn.addEventListener('click', () => handleRedirect('../Credentials/login.php'));
    }

    if (signupBtn) {
      signupBtn.addEventListener('click', () => handleRedirect('../Credentials/signup.php'));
    }
  </script>

</body>
</html>