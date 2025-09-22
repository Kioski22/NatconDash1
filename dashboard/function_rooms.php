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
    <title>73rd PEME National Convention</title>
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
        
    body { background: var(--primary-color); color:#fff;}

    .text-gradient {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    #main {
        margin-top: 50px;
    }
        .page-title {
            background: white;
            color: black;
            padding: 15px;
            text-align: center;
            font-weight: 600;
            margin-bottom: 30px;
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
    <!-- Page Title -->
    <div class="page-title mt-5">FUNCTION ROOMS</div>

    <!-- Info -->
    <div class="container mb-3">
        <div class="alert alert-info small">
            <strong>Note:</strong> The Day page (dashboard/day.php) was updated.
            <ul class="mb-0">
                <li>day.php resolves the requested day from ?day, ?day_id or by matching today's date in the DB.</li>
                <li>Function rooms and speakers are fetched dynamically.</li>
                <li>Links here point to day.php?day_id=&lt;id&gt;.</li>
            </ul>
        </div>
    </div>

    <div class="container">
        <!-- Function Room Cards only -->
        <div class="row">
            <div class="col-md-8">
                <p class="text-muted mb-4">
                    Explore the seminars for Day 1 to Day 3.
                    Each day features different speakers and technical topics.
                </p>

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
                    <h4><?= $label ?></h4>
                    <div class="function-room-date">
                        <?= (new DateTime($dayDate))->format('F j, Y') ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- Evaluation Card -->
                <div class="function-room-card">
                    <h4>NATIONAL CONVENTION EVALUATION</h4>
                    <div class="function-room-date">Required for certificate generation</div>
                </div>
            </div>
        </div>
    </div>
    </div>
</body>
</html>
