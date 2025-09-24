<?php
session_start(); // Make sure session is started at the top of the file
$isLoggedIn = isset($_SESSION['user_id']);
$userName = $_SESSION['user_name'] ?? 'Account';
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>About Us - 73rd PSME National Convention</title>

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
      --primary-color: #000e6c;
      --primary-dark: #0006ec;
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
    
    .hero-section {
      background: linear-gradient(135deg, var(--light-gray), #ffffff);
    }
    
    .team-section {
      background: linear-gradient(135deg, #ffffff, var(--light-gray));
    }
    
    .board-section {
      background: linear-gradient(135deg, var(--light-gray), #ffffff);
    }
    
    .secretariat-section {
      background: linear-gradient(135deg, #ffffff, var(--light-gray));
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
      background: var(--primary-color);
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

    /* Organizational Chart Photo Styles */
    .org-chart-container {
      position: relative;
      overflow: hidden;
      border-radius: 20px;
      cursor: pointer;
      transition: all 0.3s ease;
      box-shadow: 0 15px 50px rgba(0, 0, 0, 0.15);
      background: #ffffff;
      padding: 20px;
    }
    
    .org-chart-container:hover {
      transform: translateY(-8px);
      box-shadow: 0 25px 80px rgba(0, 0, 0, 0.25);
    }
    
    .org-chart-image {
      width: 100%;
      height: auto;
      min-height: 400px;
      object-fit: contain;
      border-radius: 15px;
      transition: all 0.3s ease;
      background: var(--light-gray);
    }
    
    .org-chart-container:hover .org-chart-image {
      transform: scale(1.02);
    }
    
    .org-chart-overlay {
      position: absolute;
      top: 20px;
      left: 20px;
      right: 20px;
      bottom: 20px;
      background: linear-gradient(135deg, rgba(37, 99, 235, 0.85), rgba(245, 158, 11, 0.85));
      opacity: 0;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 15px;
      backdrop-filter: blur(10px);
    }
    
    .org-chart-container:hover .org-chart-overlay {
      opacity: 1;
    }
    
    .org-chart-zoom-icon {
      color: white;
      font-size: 3rem;
      text-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
      animation: pulse 2s infinite;
    }
    
    @keyframes pulse {
      0% { transform: scale(1); }
      50% { transform: scale(1.1); }
      100% { transform: scale(1); }
    }

    .org-chart-title {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      color: white;
      padding: 15px 25px;
      border-radius: 15px;
      font-weight: 600;
      text-align: center;
      margin-top: 20px;
      box-shadow: 0 8px 25px rgba(37, 99, 235, 0.3);
    }

    /* Lightbox Styles */
    .lightbox {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.95);
      z-index: 9999;
      backdrop-filter: blur(15px);
    }
    
    .lightbox-content {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      max-width: 95%;
      max-height: 95%;
      border-radius: 20px;
      overflow: hidden;
      box-shadow: 0 25px 100px rgba(0, 0, 0, 0.8);
      background: white;
      padding: 10px;
    }
    
    .lightbox img {
      width: 100%;
      height: auto;
      display: block;
      border-radius: 15px;
    }
    
    .lightbox-close {
      position: absolute;
      top: 25px;
      right: 25px;
      color: white;
      font-size: 35px;
      cursor: pointer;
      background: rgba(0, 0, 0, 0.7);
      width: 60px;
      height: 60px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.3s ease;
      z-index: 10000;
    }
    
    .lightbox-close:hover {
      background: rgba(255, 0, 0, 0.8);
      transform: scale(1.1);
    }

    /* Section Title Styles */
    .section-title {
      position: relative;
      display: inline-block;
      margin-bottom: 3rem;
    }
    
    .section-title::after {
      content: '';
      position: absolute;
      bottom: -15px;
      left: 50%;
      transform: translateX(-50%);
      width: 100px;
      height: 5px;
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      border-radius: 3px;
    }

    /* Responsive adjustments */
    @media (max-width: 768px) {
      .org-chart-image {
        min-height: 300px;
      }
      
      .lightbox-content {
        max-width: 98%;
        max-height: 98%;
        padding: 5px;
      }
      
      .lightbox-close {
        top: 15px;
        right: 15px;
        width: 50px;
        height: 50px;
        font-size: 28px;
      }

      .org-chart-zoom-icon {
        font-size: 2.5rem;
      }
    }

    @media (max-width: 576px) {
      .org-chart-container {
        padding: 15px;
      }
      
      .org-chart-image {
        min-height: 250px;
      }

      .org-chart-title {
        padding: 12px 20px;
        font-size: 0.9rem;
      }
    }
  </style>
</head>
<body>
  <!-- Header Section -->
  <nav class="navbar navbar-expand-lg navbar-light shadow-custom fixed-top">
    <div class="container">
      <!-- Logo + Title -->
      <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
        <img src="../image/natcon.jpg" alt="PSME Logo" class="me-3 rounded-circle" style="height: 55px;">
        <div>
          <span class="fs-5 fw-bold d-block">73rd PSME</span>
          <small class="d-none d-md-block fw-medium">National Convention</small>
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
            <a class="nav-link fw-semibold px-3" href="dashboard.php">Home</a>
          </li>
          <li class="nav-item">
            <a class="nav-link active fw-semibold px-3" href="about.php">About</a>
          </li>
          <li class="nav-item">
            <a class="nav-link fw-semibold px-3" href="#footer">Contact</a>
          </li>

          <li class="nav-item ms-3">
            <?php if ($isLoggedIn): ?>
              <!-- Logged-in dropdown -->
              <div class="dropdown">
                <button class="btn btn-outline-primary rounded-pill px-4 py-2 fw-medium dropdown-toggle d-flex align-items-center" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                  <span class="me-2 d-inline-flex justify-content-center align-items-center rounded-circle bg-primary text-white" style="width:28px;height:28px;">
                    <?php echo strtoupper(substr($userName, 0, 1)); ?>
                  </span>
                  <span class="d-none d-sm-inline"><?php echo htmlspecialchars($userName, ENT_QUOTES); ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow">
                  <li><a class="dropdown-item" href="dashboard.php"><i class="fas fa-home me-2 text-primary"></i>Dashboard</a></li>
                  <li><a class="dropdown-item" href="function_rooms.php"><i class="fas fa-door-open me-2 text-primary"></i>Function Room</a></li>
                  <li><a class="dropdown-item" href="../Credentials/forgot_password.php"><i class="fas fa-key me-2 text-primary"></i>Change Password</a></li>
                  <li><hr class="dropdown-divider"></li>
                  <li><a class="dropdown-item text-danger" href="../Credentials/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                </ul>
              </div>
            <?php else: ?>
              <!-- Guest buttons -->
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

      <!-- Loading Overlay (only for guests) -->
      <?php if (!$isLoggedIn): ?>
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
  <main class="content" style="margin-top: 85px;">
    <!-- Hero Section -->
    <section class="hero-section py-5">
      <div class="container">
        <div class="row align-items-center">
          <div class="col-12 text-center">
            <h1 class="display-4 fw-bold py-3 mb-4 text-gradient">About Our Organization</h1>
            <p class="lead mb-0 mx-auto text-black" style="max-width: 800px;">
              Discover the organizational structure and leadership behind the 73rd PSME National Convention. 
              Our comprehensive charts showcase the dedicated professionals advancing mechanical engineering excellence in the Philippines.
            </p>
          </div>
        </div>
      </div>
    </section>

    <!-- Meet Our Team Section -->
    <section id="team" class="team-section py-5">
      <div class="container">
        <div class="row">
          <div class="col-12 text-center mb-5">
            <h2 class="display-6 fw-bold text-gradient section-title">MEET OUR TEAM</h2>
          </div>
          <div class="col-12 text-center mb-2">
            <h2 class="display-8 fw-bold text-black">2025 PSME NatCon Committee</h2>
          </div>
        </div>
        <div class="row justify-content-center">
          <div class="col-lg-10 col-xl-8">
            <div class="org-chart-container" onclick="openLightbox('../image/org-charts/NatCon.jpg', 'PSME Team Organizational Chart')">
              <img src="../image/org-charts/NatCon.jpg" alt="PSME Team Organizational Chart" class="org-chart-image" onerror="this.src='../image/natcon banner.png'">
              <div class="org-chart-overlay">
                <i class="fas fa-search-plus org-chart-zoom-icon"></i>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Board of Trustees Section -->
    <section id="board" class="board-section py-5">
      <div class="container">
        <div class="row">
          <div class="col-12 text-center mb-2">
            <h2 class="display-8 fw-bold text-black">2025 National Board of Trustees</h2>
          </div>
        </div>
        <div class="row justify-content-center">
          <div class="col-lg-10 col-xl-8">
            <div class="org-chart-container" onclick="openLightbox('../image/org-charts/NBOT.jpg', '2025 National Board of Trustees')">
              <img src="../image/org-charts/NBOT.jpg" alt="2025 National Board of Trustees" class="org-chart-image" onerror="this.src='../image/natcon banner.png'">
              <div class="org-chart-overlay">
                <i class="fas fa-search-plus org-chart-zoom-icon"></i>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- National Secretariat Section -->
    <section id="secretariat" class="secretariat-section py-5">
      <div class="container">
        <div class="row">
          <div class="col-12 text-center mb-2">
            <h2 class="display-8 fw-bold text-black">2025 PSME National Secretariat</h2>
          </div>
        </div>
        <div class="row justify-content-center">
          <div class="col-lg-10 col-xl-8">
            <div class="org-chart-container" onclick="openLightbox('../image/org-charts/Sec.png', '2025 PSME National Secretariat')">
              <img src="../image/org-charts/Sec.png" alt="2025 PSME National Secretariat" class="org-chart-image" onerror="this.src='../image/natcon banner.png'">
              <div class="org-chart-overlay">
                <i class="fas fa-search-plus org-chart-zoom-icon"></i>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </main>

  <!-- Lightbox Modal -->
  <div id="lightbox" class="lightbox" onclick="closeLightbox()">
    <div class="lightbox-content" onclick="event.stopPropagation()">
      <span class="lightbox-close" onclick="closeLightbox()">&times;</span>
      <img id="lightbox-image" src="" alt="">
    </div>
  </div>

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
                <a href="function_rooms.php" class="text-black text-decoration-none d-flex align-items-center hover-link">
                  <i class="fas fa-chevron-right me-2 small" style="color: #000e6c"></i>Function Room
                </a>
              </li>
            </ul>
          </div>
          <div class="col-md-6">
            <h6 class="fw-bold mb-3 text-black">Connect With Us</h6>
            <div class="d-flex gap-3 mb-4">
              <a href="https://www.facebook.com/psmeinc" class="fs-5 hover-link" style="color: #000e6c">
                <i class="fab fa-facebook"></i>
              </a>
              <a href="#" class="fs-5 hover-link" style="color: #000e6c">
                <i class="fab fa-twitter"></i>
              </a>
              <a href="#" class="fs-5 hover-link" style="color: #000e6c">
                <i class="fab fa-linkedin"></i>
              </a>
              <a href="#" class="fs-5 hover-link" style="color: #000e6c">
                <i class="fab fa-instagram"></i>
              </a>
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
    // Redirect helpers for guest buttons
    const overlay = document.getElementById('loadingOverlay');
    function handleRedirect(targetURL) {
      if (overlay) {
        overlay.classList.remove('d-none');
        overlay.classList.add('d-flex');
      }
      setTimeout(() => { window.location.href = targetURL; }, 800);
    }
    const loginBtn = document.getElementById('customLoginBtn');
    const signupBtn = document.getElementById('customSignupBtn');
    if (loginBtn) loginBtn.addEventListener('click', () => handleRedirect('../Credentials/login.php'));
    if (signupBtn) signupBtn.addEventListener('click', () => handleRedirect('../Credentials/signup.php'));
  </script>
</body>
</html>