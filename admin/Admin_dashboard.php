<?php
session_start();
require_once __DIR__ . '/../db.php';

// normalize DB connection (fixes "Undefined variable $db")
if (isset($conn) && $conn instanceof mysqli) {
    $db = $conn;
} elseif (isset($mysqli) && $mysqli instanceof mysqli) {
    $db = $mysqli;
} else {
    $db = null;
}
if (!($db instanceof mysqli)) {
    http_response_code(500);
    echo "Database connection not available.";
    exit;
}

// ensure CSRF token used by the admin form
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

// Check if user is logged in as admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: admin_login.php');
    exit;
}

$admin_name = $_SESSION['user_name'] ?? 'Admin';

// fetch current certificate settings (show in admin UI)
$cert_enabled = '0';
$cert_auto_date = '';
$stmtCert = $db->prepare("SELECT setting_value FROM admin_settings WHERE setting_key = 'cert_generation_enabled' LIMIT 1");
if ($stmtCert) {
    $stmtCert->execute();
    $r = $stmtCert->get_result()->fetch_assoc();
    $stmtCert->close();
    if (!empty($r['setting_value'])) $cert_enabled = $r['setting_value'];
}
$stmtAuto = $db->prepare("SELECT setting_value FROM admin_settings WHERE setting_key = 'cert_auto_enable_date' LIMIT 1");
if ($stmtAuto) {
    $stmtAuto->execute();
    $r2 = $stmtAuto->get_result()->fetch_assoc();
    $stmtAuto->close();
    if (!empty($r2['setting_value'])) $cert_auto_date = $r2['setting_value'];
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .sidebar {
            min-height: 100vh;
            box-shadow: inset -1px 0 0 rgba(0, 0, 0, .1);
        }
        .sidebar .nav-link {
            color: #333;
        }
        .sidebar .nav-link.active {
            color: #2470dc;
        }
        .sidebar-heading {
            font-size: .75rem;
            text-transform: uppercase;
        }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <nav class="col-md-3 col-lg-2 d-md-block bg-light sidebar collapse">
            <div class="position-sticky pt-3">
                <div class="px-3 mb-3">
                    <span class="fs-5">Welcome, <?= htmlspecialchars($admin_name) ?></span>
                </div>
                <hr>
                
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link active" href="Admin_dashboard.php">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                    
                    <!-- Function Rooms Section -->
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="collapse" href="#functionRoomsMenu">
                            <i class="bi bi-door-open"></i> Function Rooms
                            <i class="bi bi-chevron-down float-end"></i>
                        </a>
                        <div class="collapse" id="functionRoomsMenu">
                            <ul class="nav flex-column ms-3">
                                <li class="nav-item">
                                    <a class="nav-link" href="admin_manage_function_rooms.php">
                                        Manage Rooms
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="admin_function_days.php">
                                        Toggle Days
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </li>

                    <!-- Speakers Section -->
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="collapse" href="#speakersMenu">
                            <i class="bi bi-person-video3"></i> Speakers
                            <i class="bi bi-chevron-down float-end"></i>
                        </a>
                        <div class="collapse" id="speakersMenu">
                            <ul class="nav flex-column ms-3">
                                <li class="nav-item">
                                    <a class="nav-link" href="manage_speakers.php">
                                        Speaker Management
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="manage_quiz.php">
                                        Quiz Management
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </li>

                    <li class="nav-item mt-3">
                        <a class="nav-link text-danger" href="admin_logout.php">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">Dashboard</h1>
            </div>

            <!-- Dashboard Content -->
            <div class="row">
                <div class="col-md-4 mb-4">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">Function Rooms</h5>
                            <p class="card-text">Manage function rooms and their availability.</p>
                            <a href="admin_manage_function_rooms.php" class="btn btn-primary">Manage Rooms</a>
                        </div>
                    </div>
                </div>

                <div class="col-md-4 mb-4">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">Speakers</h5>
                            <p class="card-text">Manage speakers and their sessions.</p>
                            <a href="admin_speaker_manage.php" class="btn btn-primary">Manage Speakers</a>
                        </div>
                    </div>
                </div>

                <div class="col-md-4 mb-4">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">Quiz Management</h5>
                            <p class="card-text">Create and manage speaker quizzes.</p>
                            <a href="manage_quiz.php" class="btn btn-primary">Manage Quiz</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Certificate generation toggle (Admin) -->
            <div class="card mb-3">
              <div class="card-body">
                <h6 class="card-title">Certificate of Attendance</h6>
                <p class="small text-muted">Control certificate generation. You can also set a date after which certificates are automatically enabled if attendees were marked present.</p>

                <form method="post" action="../api/toggle_certificate.php" class="row g-2 align-items-center">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  <div class="col-auto">
                    <div class="form-check form-switch">
                      <input class="form-check-input" type="checkbox" id="certEnabled" name="cert_enabled" value="1" <?= ($cert_enabled === '1') ? 'checked' : '' ?>>
                      <label class="form-check-label" for="certEnabled">Enable generation</label>
                    </div>
                  </div>
                  <div class="col-auto">
                    <label class="form-label small mb-0">Auto-enable from</label>
                    <input type="date" class="form-control" name="cert_auto_date" value="<?= htmlspecialchars($cert_auto_date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" />
                  </div>
                  <div class="col-auto">
                    <button class="btn btn-primary">Save</button>
                  </div>
                </form>
              </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>