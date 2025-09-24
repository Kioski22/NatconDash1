<?php
session_start();
require_once __DIR__ . '/../db.php';

// Current user (optional)
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

// Server timezone
date_default_timezone_set('Asia/Manila');
$today = (new DateTime())->format('Y-m-d');

// --- Load room mode ---
$roomMode = 'date_strict'; // fallback
$res = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key='function_room_mode'");
if ($res && $row = $res->fetch_assoc()) {
    $roomMode = $row['setting_value'];
}

// --- Fetch function-room days ---
$days = [];
$sql = "SELECT id, label, day_date, is_enabled FROM function_room_days ORDER BY day_date ASC";
if ($stmt = $conn->prepare($sql)) {
    $stmt->execute();
    $res = $stmt->get_result();
    $days = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// --- Get last convention date ---
$lastConventionDate = null;
if ($r = $conn->query("SELECT MAX(day_date) AS last_date FROM function_room_days")) {
    $row = $r->fetch_assoc();
    $lastConventionDate = $row['last_date'] ?? null;
}

// --- Helpers ---
function isConventionOver($today, $lastDate) {
    if (!$lastDate) return false;
    return (new DateTime($today)) > (new DateTime($lastDate));
}

$conventionOver = isConventionOver($today, $lastConventionDate);

// Theme
$THEME = [
    'primary' => '#0B2A59',
    'accent'  => '#1a4189',
    'text'    => '#FFFFFF',
    'text-muted' => '#D6E0FF'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Function Rooms - 73rd PSME National Convention</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

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
      color:#fff;
      padding-top: 70px;
    }

    .text-gradient {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .page-title {
        background: white;
        color: black;
        padding: 15px;
        text-align: center;
        font-weight: 600;
        margin-bottom: 30px;
    }

    .day-number {
      color: var(--secondary-color);
    }

    .function-room-card {
        background: rgba(255,255,255,0.1);
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 15px;
        cursor: pointer;
        transition: all 0.3s ease;
        border: 1px solid rgba(255,255,255,0.2);
    }
    .function-room-card:hover:not(.disabled) {
        transform: translateY(-5px);
        background: rgba(255,255,255,0.15);
    }
    .function-room-card.disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
    .function-room-date {
        color: <?= $THEME['text-muted'] ?>;
        font-size: 0.9rem;
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
            <a class="nav-link fw-semibold px-3" href="dashboard.php">< Back to Home</a>
          </li>
        </ul>
      </div>
    </div>
  </nav>

  <div class="main" id="main">
    <div class="display-6 fw-bold m-5 text-white text-center">FUNCTION ROOMS</div>
    <div class="container">
        <!-- Function Room Cards only -->
        <div class="row justify-content-center">
            <div class="col-md-8 text-center">
                <p class="text-white mb-4">
                    Explore the seminars for Day 1 to Day 3.
                    Each day features different speakers and technical topics.
                </p>

                <!-- Button: How to earn Certificates -->
                <button id="openCertStepsBtn" class="btn btn-outline-light mb-4" data-bs-toggle="modal" data-bs-target="#certStepsModal">
                  How to earn your Certificates
                </button>

                <?php foreach ($days as $d):
                    $dayId = (int)$d['id'];
                    $label = htmlspecialchars($d['label']);
                    $dayDate = $d['day_date'];
                    $isEnabled = ((int)$d['is_enabled'] === 1);
                    $isToday = ($today === $dayDate);
                    $isActive = ($roomMode === 'date_strict') ? ($isToday && $isEnabled) : $isEnabled;
                ?>
                <div class="function-room-card <?= $isActive ? '' : 'disabled' ?>" 
                     onclick="<?= $isActive ? "window.location='day.php?day_id={$dayId}'" : '' ?>">
                    <h4 class="day-number"><?= $label ?></h4>
                    <div class="function-room-date">
                        <?= (new DateTime($dayDate))->format('F j, Y') ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- Evaluation Card -->
                <div class="function-room-card">
                    <h4 class="day-number">NATIONAL CONVENTION EVALUATION</h4>
                    <div class="function-room-date">Required for certificate generation</div>
                </div>
            </div>
        </div>
    </div>
    </div>

    <!-- Certificates Steps Modal -->
    <div class="modal fade" id="certStepsModal" tabindex="-1" aria-labelledby="certStepsModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="color:#111;">
          <div class="modal-header">
            <h5 class="modal-title" id="certStepsModalLabel">How to earn your Certificates</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p class="mb-2">Certificates available:</p>
            <ul class="mb-3">
              <li>Certificate of Attendance</li>
              <li>Certificate of Appearance</li>
              <li>CPD Form</li>
            </ul>

            <hr>

            <p class="mb-2">Follow these steps:</p>
            <ol class="mb-3">
              <li>Select the Day that is enabled (the current day of the convention).</li>
              <li>Select a Function Room.</li>
              <li>Select the Speaker in the room you are currently attending.</li>
              <li><strong>Mark your Attendance</strong> on the speaker’s page. This serves as your attendance for the day.</li>
              <li>After attendance, take the <strong>Quiz</strong> prepared by the speaker.</li>
              <li>Submit the <strong>Speaker Evaluation</strong> form.</li>
            </ol>

            <div class="alert alert-warning">
              <strong>Important:</strong> You must be physically present in the function room before filling out the attendance on the speaker’s form.
            </div>

            <p class="mb-0">Certificate generation (when enabled) requires a minimum of 3 days of attendance, and completing the quizzes and speaker evaluation forms.</p>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            <a class="btn btn-primary" href="dashboard.php">Go to Dashboard</a>
          </div>
        </div>
      </div>
    </div>

    <!-- Bootstrap JS + auto-show once per day -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        var el = document.getElementById('certStepsModal');
        if (!el || typeof bootstrap === 'undefined') return;

        var url = new URL(window.location.href);
        var forceShow = url.searchParams.get('showCert') === '1';
        var ref = document.referrer || '';
        var fromDashboard = ref.indexOf('dashboard.php') !== -1;

        var sessionKey = 'certStepsShown_session';
        var dayKey = 'certStepsShown_' + new Date().toISOString().slice(0,10);

        var shouldShow = false;

        if (forceShow) {
          shouldShow = true;
        } else if (fromDashboard && !sessionStorage.getItem(sessionKey)) {
          shouldShow = true;
          sessionStorage.setItem(sessionKey, '1');
        } else if (!localStorage.getItem(dayKey)) {
          shouldShow = true;
          localStorage.setItem(dayKey, '1');
        }

        if (shouldShow) {
          var modal = new bootstrap.Modal(el, { backdrop: 'static', keyboard: true });
          modal.show();
        }
      });
    </script>
</body>
</html>