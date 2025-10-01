<?php
session_start();
require_once __DIR__ . '/../db.php';

// Current user (optional)
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$userName = trim((string)($_SESSION['user_name'] ?? $_SESSION['name'] ?? ''));
$userEmail = trim((string)($_SESSION['user_email'] ?? $_SESSION['email'] ?? ''));

date_default_timezone_set('Asia/Manila');
$today = (new DateTime())->format('Y-m-d');

if (!function_exists('e')) {
  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}


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

// NEW: compute day_number index for each day id (1-based by date)
$dayIndexById = [];
foreach ($days as $i => $d) {
    $dayIndexById[(int)$d['id']] = $i + 1;
}


// --- Get last convention date ---
$lastConventionDate = null;
if ($r = $conn->query("SELECT MAX(day_date) AS last_date FROM function_room_days")) {
    $row = $r->fetch_assoc();
    $lastConventionDate = $row['last_date'] ?? null;
}

function isConventionOver($today, $lastDate) {
    if (!$lastDate) return false;
    return (new DateTime($today)) > (new DateTime($lastDate));
}

$conventionOver = isConventionOver($today, $lastConventionDate);

// NatCon (overall) evaluation submission status for current user
$natconEvalSubmitted = false;
if ($userId) {
  if ($st = $conn->prepare("SELECT id FROM natcon_eval_responses WHERE user_id = ? LIMIT 1")) {
    $st->bind_param('i', $userId);
    $st->execute();
    $st->store_result();
    if ($st->num_rows > 0) { $natconEvalSubmitted = true; }
    $st->close();
  }
}

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

    .small-note { color: <?= $THEME['text-muted'] ?>; font-size: .9rem; }
    </style>
</head>
<body>
    <!-- Header Section -->
  <nav class="navbar navbar-expand-lg navbar-light shadow-custom fixed-top">
    <div class="container">
      <!-- Logo + Title -->
      <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
        <img src="../image/natcon.jpg" alt="PSME Logo" class="me-3 rounded-circle" style="height: 55px;">
        <div class="text-center" style="line-height:1.15;">
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

        <?php if (!empty($_SESSION['flash'])): $f=$_SESSION['flash']; unset($_SESSION['flash']); ?>
          <div class="alert alert-<?= e($f['type'] ?? 'info') ?>"><?= e($f['msg'] ?? '') ?></div>
        <?php endif; ?>

        <div class="row justify-content-center">
            <div class="col-md-8 text-center">
                <p class="text-white mb-1">
                    Explore the seminars for Day 1 to Day 3.
                    Each day features different speakers and technical topics.
                </p>
                <p class="small-note mb-4">Select a Day to view rooms, mark attendance, and access speaker quizzes on the Day page.</p>

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

                    // Attendance UI removed – handled now on day.php
                ?>
       <div class="function-room-card <?= $isActive ? '' : 'disabled' ?>" 
         data-day-id="<?= $dayId ?>" 
         data-day-label="<?= e($label) ?>" 
         data-active="<?= $isActive ? 1 : 0 ?>">
                    <h4 class="day-number"><?= $label ?></h4>
                    <div class="function-room-date">
                        <?= (new DateTime($dayDate))->format('F j, Y') ?>
                    </div>

                    <!-- Attendance UI removed -->
                </div>
                <?php endforeach; ?>

                <!-- Evaluation Card (Clickable) -->
                <div class="function-room-card" id="natconEvalCard" style="position:relative;">
                    <div class="stretched-link" data-nav="eval" style="position:absolute;inset:0;z-index:5;"></div>
                    <h4 class="day-number mb-2 d-flex align-items-center justify-content-between">NATIONAL CONVENTION EVALUATION
                      <?php if ($natconEvalSubmitted): ?>
                        <span class="badge bg-success ms-2" style="font-size:.7rem;">SUBMITTED</span>
                      <?php endif; ?>
                    </h4>
                    <div class="function-room-date mb-2">Your overall feedback helps improve future conventions.</div>
                    <div class="small mb-2" style="color:<?= $THEME['text-muted'] ?>;">Estimated time: 3–4 minutes. One submission per account.</div>
                    <?php if (!$userId): ?>
                      <div class="mt-2"><span class="badge bg-warning text-dark">Login required</span></div>
                    <?php endif; ?>
                    <div class="mt-2 small text-uppercase" style="letter-spacing:1px;opacity:.7;">Tap / Click to <?php echo $natconEvalSubmitted? 'view submission' : 'start'; ?></div>
                </div>

                <!-- Certificates Card -->
                <div class="function-room-card" id="certificatesCard" style="position:relative;">
                  <div class="stretched-link" data-nav="certs" style="position:absolute;inset:0;z-index:5;"></div>
                  <h4 class="day-number mb-2">CERTIFICATES CENTER</h4>
                  <div class="function-room-date mb-2">Generate or email your available certificates once eligible.</div>
                  <ul class="text-start small mb-2" style="color:<?= $THEME['text-muted'] ?>;line-height:1.3;">
                    <li>Attendance / Appearance</li>
                    <li>CPD Form (when criteria met)</li>
                    <li>Email copy delivery</li>
                  </ul>
                  <div class="small text-uppercase" style="letter-spacing:1px;opacity:.7;">Tap / Click to open</div>
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
              <li><strong>Mark your Attendance</strong> for the day (once per day) on this page.</li>
              <li>Proceed to the speaker page to take the <strong>Quiz</strong>, then the <strong>Speaker Evaluation</strong>.</li>
            </ol>

            <div class="alert alert-warning">
              <strong>Important:</strong> You must be physically present before marking attendance for the day.
            </div>

            <p class="mb-0">Certificate generation (when enabled) requires attendance and completing the quizzes and speaker evaluation forms.</p>
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
      // JS enhancements added: intercept day card click to enforce attendance submission first
      document.addEventListener('DOMContentLoaded', function () {
        var el = document.getElementById('certStepsModal');
        if (el && typeof bootstrap !== 'undefined') {
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
        }

        // Simple day card navigation (attendance now handled on day.php)
        document.querySelectorAll('.function-room-card').forEach(function(card){
          card.addEventListener('click', function(){
            var active = card.getAttribute('data-active') === '1';
            if (!active) return;
            var dayId = card.getAttribute('data-day-id');
            window.location = 'day.php?day_id=' + dayId;
          });
        });

        // Click handlers for evaluation & certificates cards (using data-nav)
        var userLoggedIn = <?= $userId ? 'true' : 'false' ?>;
        document.querySelectorAll('.stretched-link[data-nav]').forEach(function(link){
          link.addEventListener('click', function(e){
            e.preventDefault();
            var dest = this.getAttribute('data-nav');
            if (dest === 'eval') {
              if (!userLoggedIn) {
                window.location = '../Credentials/login.php?redirect=' + encodeURIComponent('dashboard/natcon_evaluation.php');
                return;
              }
              window.location = 'natcon_evaluation.php';
            } else if (dest === 'certs') {
              window.location = 'certificates.php';
            }
          });
        });
      });
    </script>
</body>
</html>