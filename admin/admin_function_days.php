<?php
session_start();
require_once __DIR__ . '/../db.php';

// Only allow admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: admin_login.php');
    exit;
}

// Ensure CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

$admin_name = $_SESSION['user_name'] ?? 'Admin';

// Handle mode change (verify CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mode'])) {
    $posted = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], (string)$posted)) {
        // simple feedback; you can show a nicer UI message instead
        die('Invalid CSRF token');
    }

    $mode = $_POST['mode'] === 'admin_only' ? 'admin_only' : 'date_strict';
    $stmt = $conn->prepare("INSERT INTO app_settings (setting_key, setting_value) 
                             VALUES ('function_room_mode', ?) 
                             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->bind_param("s", $mode);
    $stmt->execute();
    $stmt->close();
    header("Location: admin_function_days.php");
    exit;
}

// Load current mode
$currentMode = 'date_strict';
$res = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key='function_room_mode'");
if ($res && $row = $res->fetch_assoc()) {
    $currentMode = $row['setting_value'];
}

// Fetch days
$days = [];
$sql = "SELECT id, label, day_date, is_enabled FROM function_room_days ORDER BY day_date ASC";
if ($stmt = $conn->prepare($sql)) {
    $stmt->execute();
    $res = $stmt->get_result();
    $days = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Admin — Function Room Days</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .sidebar { min-height: 100vh; box-shadow: inset -1px 0 0 rgba(0,0,0,.1); }
        .sidebar .nav-link { color: #333; }
        .sidebar .nav-link.active { color: #2470dc; }
        .sidebar-heading { font-size:.75rem; text-transform:uppercase; }
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
                    <li class="nav-item"><a class="nav-link" href="Admin_dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
                    <li class="nav-item">
                        <a class="nav-link active" data-bs-toggle="collapse" href="#functionRoomsMenu"><i class="bi bi-door-open"></i> Function Rooms <i class="bi bi-chevron-down float-end"></i></a>
                        <div class="collapse show" id="functionRoomsMenu">
                            <ul class="nav flex-column ms-3">
                                <li class="nav-item"><a class="nav-link" href="manage_rooms.php">Manage Rooms</a></li>
                                <li class="nav-item"><a class="nav-link active" href="admin_function_days.php">Toggle Days</a></li>
                            </ul>
                        </div>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" data-bs-toggle="collapse" href="#speakersMenu"><i class="bi bi-person-video3"></i> Speakers <i class="bi bi-chevron-down float-end"></i></a>
                        <div class="collapse" id="speakersMenu">
                            <ul class="nav flex-column ms-3">
                                <li class="nav-item"><a class="nav-link" href="manage_speakers.php">Speaker Management</a></li>
                                <li class="nav-item"><a class="nav-link" href="manage_quiz.php">Quiz Management</a></li>
                            </ul>
                        </div>
                    </li>
                    <li class="nav-item mt-3"><a class="nav-link text-danger" href="admin_logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                </ul>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">Function Room Days</h1>
            </div>

            <!-- Mode Selection Card -->
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title">Access Mode</h5>
                    <form method="post" class="mb-0">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <div class="row align-items-center">
                            <div class="col-auto">
                                <select name="mode" class="form-select">
                                    <option value="date_strict" <?= $currentMode === 'date_strict' ? 'selected' : '' ?>>Date Strict</option>
                                    <option value="admin_only" <?= $currentMode === 'admin_only' ? 'selected' : '' ?>>Admin Only</option>
                                </select>
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-primary">Update Mode</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Days Toggle Card -->
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-3">Toggle Days</h5>
                    <div class="list-group">
                        <?php foreach ($days as $d): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?= htmlspecialchars($d['label']) ?></strong>
                                    <div class="text-muted"><?= (new DateTime($d['day_date']))->format('F j, Y') ?></div>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input toggle-day" type="checkbox"
                                           data-day-id="<?= (int)$d['id'] ?>"
                                           <?= ((int)$d['is_enabled'] === 1) ? 'checked' : '' ?>>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;

document.querySelectorAll('.toggle-day').forEach(cb => {
  cb.addEventListener('change', function(){
    const dayId = this.dataset.dayId;
    const isEnabled = this.checked ? 1 : 0;
    fetch('../api/toggle_day.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ day_id: dayId, is_enabled: isEnabled, csrf_token: CSRF_TOKEN })
    }).then(r=>r.json()).then(json=>{
      if (!json.success) {
        alert('Error: ' + (json.error || 'Could not update'));
        this.checked = !this.checked;
      }
    }).catch(()=>{ alert('Network error'); this.checked = !this.checked; });
  });
});
</script>
</body>
</html>
