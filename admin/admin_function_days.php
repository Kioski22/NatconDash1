<?php
session_start();
require_once __DIR__ . '/../db.php';

// Allow admin or super
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['admin','super'], true)) {
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

// Simple add day (single) handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_day'])) {
    $posted = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], (string)$posted)) { die('Invalid CSRF token'); }
    $day_date = trim((string)($_POST['day_date'] ?? ''));
    $label = trim((string)($_POST['label'] ?? ''));
    if ($day_date !== '') {
        if ($label === '') { $label = $day_date; }
        if ($stmt = $conn->prepare("INSERT INTO function_room_days (label, day_date, is_enabled) VALUES (?,?,1)")){
            $stmt->bind_param('ss', $label, $day_date);
            $stmt->execute();
            $stmt->close();
        }
    }
    header('Location: admin_function_days.php');
    exit;
}

// Simple bulk add handler (one per line: YYYY-MM-DD[, Label])
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_add'])) {
    $posted = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], (string)$posted)) { die('Invalid CSRF token'); }
    $bulk = (string)($_POST['bulk_days_text'] ?? '');
    if ($bulk !== ''){
        $lines = preg_split('/\r?\n/', $bulk);
        if ($ins = $conn->prepare("INSERT INTO function_room_days (label, day_date, is_enabled) VALUES (?,?,1)")){
            foreach ($lines as $i => $line) {
                $line = trim($line); if ($line==='') continue;
                $parts = explode(',', $line, 2);
                $date = trim($parts[0] ?? '');
                $lab = trim($parts[1] ?? ''); if ($lab==='') $lab = $date !== '' ? $date : ('Day '.($i+1));
                if ($date!=='') { $ins->bind_param('ss', $lab, $date); $ins->execute(); }
            }
            $ins->close();
        }
    }
    header('Location: admin_function_days.php');
    exit;
}

// Handle add day(s) via simple POST (no JS/API)
$flash = null; $flashType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_day']) || isset($_POST['add_bulk']))) {
    // CSRF
    $posted = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], (string)$posted)) {
        $flash = 'Invalid CSRF token';
        $flashType = 'danger';
    } else {
        $added = 0; $duplicates = 0; $errors = 0;
        // Helper to parse date to YYYY-MM-DD
        $parseDate = function(string $s): ?string {
            $s = trim($s);
            if ($s === '') return null;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
            $ts = strtotime($s);
            if ($ts === false) return null;
            return date('Y-m-d', $ts);
        };

        if (isset($_POST['add_day'])) {
            $date = $parseDate((string)($_POST['add_day_date'] ?? ''));
            $label = trim((string)($_POST['add_day_label'] ?? ''));
            if ($date === null) {
                $flash = 'Please provide a valid date (YYYY-MM-DD).';
                $flashType = 'danger';
            } else {
                // skip if exists
                if ($st = $conn->prepare("SELECT id FROM function_room_days WHERE day_date=? LIMIT 1")){
                    $st->bind_param('s', $date); $st->execute(); $st->store_result();
                    if ($st->num_rows > 0) { $duplicates++; }
                    else {
                        $st->close();
                        if ($ins = $conn->prepare("INSERT INTO function_room_days (label, day_date, is_enabled) VALUES (?,?,1)")){
                            $lab = ($label !== '' ? $label : $date);
                            $ins->bind_param('ss', $lab, $date);
                            if ($ins->execute()) { $added++; }
                            else { $errors++; }
                            $ins->close();
                        } else { $errors++; }
                        // ensure $st is closed if not earlier
                    }
                    // Close if still open
                    if ($st && $st->num_rows > 0) { $st->close(); }
                } else { $errors++; }
                $flash = "Added $added, duplicates $duplicates, errors $errors.";
                $flashType = ($errors>0 && $added===0)?'danger':'success';
            }
        }

        if (isset($_POST['add_bulk'])) {
            $bulk = trim((string)($_POST['bulk_days_text'] ?? ''));
            if ($bulk === '') { $flash = 'Please enter at least one line to bulk add.'; $flashType='danger'; }
            else {
                $lines = preg_split('/\r?\n/', $bulk);
                $i=1;
                foreach ($lines as $line) {
                    $line = trim($line); if ($line==='') continue;
                    $parts = array_map('trim', explode(',', $line, 2));
                    $date = $parseDate($parts[0] ?? ''); if ($date===null) { $errors++; continue; }
                    $label = $parts[1] ?? ('Day '.$i);
                    if ($st = $conn->prepare("SELECT id FROM function_room_days WHERE day_date=? LIMIT 1")){
                        $st->bind_param('s', $date); $st->execute(); $st->store_result();
                        if ($st->num_rows > 0) { $duplicates++; $st->close(); $i++; continue; }
                        $st->close();
                    }
                    if ($ins = $conn->prepare("INSERT INTO function_room_days (label, day_date, is_enabled) VALUES (?,?,1)")){
                        $ins->bind_param('ss', $label, $date);
                        if ($ins->execute()) { $added++; } else { $errors++; }
                        $ins->close();
                    } else { $errors++; }
                    $i++;
                }
                $flash = "Added $added, duplicates $duplicates, errors $errors.";
                $flashType = ($errors>0 && $added===0)?'danger':'success';
            }
        }
    }
    // After processing, refresh days list to reflect changes
    $days = [];
    if ($stmt = $conn->prepare($sql)) {
        $stmt->execute(); $res = $stmt->get_result(); $days = $res->fetch_all(MYSQLI_ASSOC); $stmt->close();
    }
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
    <title>Function Room Days | 73rd PSME National Convention</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* CSS Custom Properties */
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #10b981 0%, #059669 100%);
            --warning-gradient: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            --danger-gradient: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            --info-gradient: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            --shadow-soft: 0 2px 15px rgba(0,0,0,0.08);
            --shadow-hover: 0 8px 30px rgba(0,0,0,0.12);
            --border-radius: 12px;
        }
        
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        
        .main-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-soft);
            margin-top: 2rem;
            margin-bottom: 2rem;
        }
        
        .page-header {
            background: var(--primary-gradient);
            color: white;
            padding: 2rem;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            position: relative;
            overflow: hidden;
        }
        
        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(30px, -30px);
        }
        
        .page-title {
            margin: 0;
            font-weight: 600;
            font-size: 1.75rem;
        }
        
        .page-subtitle {
            margin: 0.5rem 0 0;
            opacity: 0.9;
            font-weight: 300;
        }
        
        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-soft);
            transition: all 0.3s ease;
        }
        
        .card:hover {
            box-shadow: var(--shadow-hover);
            transform: translateY(-2px);
        }
        
        .card-header {
            background: rgba(248, 249, 250, 0.8);
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding: 1.25rem;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
        }
        
        .card-title {
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn {
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.2s ease;
            border: none;
        }
        
        .btn-gradient-primary {
            background: var(--primary-gradient);
            color: white;
        }
        
        .btn-gradient-primary:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-soft);
            color: white;
        }
        
        .btn-gradient-success {
            background: var(--success-gradient);
            color: white;
        }
        
        .btn-gradient-success:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-soft);
            color: white;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.15);
        }
        
        .list-group-item {
            border: none;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            padding: 1.25rem;
            transition: all 0.2s ease;
            background: rgba(255, 255, 255, 0.8);
        }
        
        .list-group-item:hover {
            background: rgba(102, 126, 234, 0.05);
            transform: scale(1.005);
        }
        
        .list-group-item:last-child {
            border-bottom: none;
        }
        
        .form-check-input {
            border-radius: 1rem;
        }
        
        .form-check-input:checked {
            background-color: #10b981;
            border-color: #10b981;
        }
        
        .form-switch .form-check-input {
            width: 2.5em;
            height: 1.25em;
        }
        
        .alert {
            border: none;
            border-radius: var(--border-radius);
            padding: 1rem 1.25rem;
        }
        
        .mode-card {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.05) 0%, rgba(118, 75, 162, 0.05) 100%);
        }
        
        .day-item {
            transition: all 0.3s ease;
        }
        
        .day-item.enabled {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(5, 150, 105, 0.1) 100%);
            border-left: 4px solid #10b981;
        }
        
        .day-item.disabled {
            background: linear-gradient(135deg, rgba(156, 163, 175, 0.1) 0%, rgba(107, 114, 128, 0.1) 100%);
            border-left: 4px solid #9ca3af;
            opacity: 0.7;
        }
        
        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-weight: 600;
        }
        
        .status-enabled {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }
        
        .status-disabled {
            background: rgba(156, 163, 175, 0.1);
            color: #6b7280;
        }
    </style>
        <?php
        // Compute API URLs robustly for subfolder deployments
        $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        $parentDir = preg_replace('#/[^/]+$#', '', $scriptDir);
        if ($parentDir === '') { $parentDir = '/'; }
        $apiToggleUrl = rtrim($parentDir, '/') . '/api/toggle_day.php';
            ?>
            <script>
                const API_TOGGLE_DAY_URL = <?= json_encode($apiToggleUrl) ?>;
                const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;
            </script>
        ?>
        <script>
            const API_TOGGLE_DAY_URL = <?= json_encode($apiToggleUrl) ?>;
            const API_ADD_DAYS_URL = <?= json_encode($apiAddDaysUrl) ?>;
            const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;
        </script>
</head>
<body>
    <div class="container-fluid">
        <div class="main-container">
            <div class="page-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="page-title"><i class="bi bi-calendar3 me-2"></i>Function Room Days</h1>
                        <p class="page-subtitle">Control access mode and toggle day availability</p>
                    </div>
                    <a href="Admin_dashboard.php" class="btn btn-light btn-lg">
                        <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
                    </a>
                </div>
            </div>
            
            <div class="p-4">
                                <?php if ($flash): ?>
                                    <div class="alert alert-<?= $flashType === 'danger' ? 'danger' : 'success' ?>">
                                        <?= htmlspecialchars($flash) ?>
                                    </div>
                                <?php endif; ?>

                <!-- Mode Selection Card -->
                <div class="card mode-card mb-4">
                    <div class="card-header">
                        <div class="d-flex align-items-center">
                                            <form method="post" class="row g-3 align-items-end">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="add_day" value="1">
                                                <div class="col-md-4">
                                                    <label class="form-label">Date</label>
                                                    <input type="date" name="day_date" class="form-control" required />
                                                </div>
                                                <div class="col-md-5">
                                                    <label class="form-label">Label (optional)</label>
                                                    <input type="text" name="label" class="form-control" placeholder="e.g., Day 1" />
                                                </div>
                                                <div class="col-md-3">
                                                    <button type="submit" class="btn btn-gradient-success w-100"><i class="bi bi-plus-lg me-1"></i>Add Day</button>
                                                </div>
                                            </form>
                                            <hr class="my-4">
                                            <form method="post" class="row g-3">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="bulk_add" value="1">
                                                <div class="col-12">
                                                    <label class="form-label">Bulk add (one per line: YYYY-MM-DD[, Label])</label>
                                                    <textarea name="bulk_days_text" class="form-control" rows="4" placeholder="2025-10-01, Day 1&#10;2025-10-02, Day 2&#10;2025-10-03"></textarea>
                                                </div>
                                                <div class="col-md-3 ms-auto">
                                                    <button type="submit" class="btn btn-outline-primary w-100"><i class="bi bi-upload me-1"></i>Add Multiple</button>
                                                </div>
                                            </form>
                                    <div>
                                        <button type="submit" class="btn btn-gradient-primary">
                                            <i class="bi bi-check-lg me-2"></i>Update Mode
                                        </button>
                                    </div>
                                </form>
                            </div>
                            <div class="col-md-4">
                                <div class="p-3 bg-light rounded-3 h-100">
                                    <div class="small">
                                        <strong class="text-primary">Current Mode:</strong><br>
                                        <?php if ($currentMode === 'admin_only'): ?>
                                            <i class="bi bi-lock-fill text-warning me-1"></i>
                                            <span class="text-dark">Admin access only - all days controlled manually</span>
                                        <?php else: ?>
                                            <i class="bi bi-calendar-check text-success me-1"></i>
                                            <span class="text-dark">Date-based access with day toggles</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Days Toggle Card -->
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-toggles me-2 text-success fs-5"></i>
                            <h5 class="card-title mb-0">Day Availability Control</h5>
                            <span class="badge bg-secondary ms-auto"><?= count($days) ?> days</span>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($days)): ?>
                            <div class="text-center text-muted py-5">
                                <i class="bi bi-calendar-x fs-1 mb-3 d-block text-light"></i>
                                <h6>No days configured</h6>
                                <p class="mb-0">Configure function room days to enable day toggling.</p>
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($days as $d): ?>
                                    <?php 
                                    $isEnabled = (int)$d['is_enabled'] === 1;
                                    $date = new DateTime($d['day_date']);
                                    $dayClass = $isEnabled ? 'enabled' : 'disabled';
                                    ?>
                                    <div class="list-group-item day-item <?= $dayClass ?> d-flex justify-content-between align-items-center">
                                        <div class="d-flex align-items-center">
                                            <div class="me-3">
                                                <?php if ($isEnabled): ?>
                                                    <i class="bi bi-calendar-check-fill text-success fs-4"></i>
                                                <?php else: ?>
                                                    <i class="bi bi-calendar-x text-muted fs-4"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <div class="fw-semibold text-dark">
                                                    <?= htmlspecialchars($d['label']) ?>
                                                </div>
                                                <div class="small text-muted">
                                                    <i class="bi bi-calendar3 me-1"></i>
                                                    <?= $date->format('F j, Y') ?>
                                                    <span class="mx-2">•</span>
                                                    <i class="bi bi-clock me-1"></i>
                                                    <?= $date->format('l') ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-center gap-3">
                                            <span class="status-badge status-<?= $isEnabled ? 'enabled' : 'disabled' ?>">
                                                <?= $isEnabled ? 'Active' : 'Inactive' ?>
                                            </span>
                                            <div class="form-check form-switch">
                                                <input class="form-check-input toggle-day" type="checkbox"
                                                       data-day-id="<?= (int)$d['id'] ?>"
                                                       <?= $isEnabled ? 'checked' : '' ?>
                                                       title="<?= $isEnabled ? 'Click to disable' : 'Click to enable' ?>">
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                                <!-- Add Day(s) Card -->
                                                <div class="card mt-4">
                                    <div class="card-header">
                                        <div class="d-flex align-items-center">
                                            <i class="bi bi-calendar-plus me-2 text-primary fs-5"></i>
                                            <h5 class="card-title mb-0">Add Day(s)</h5>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                                        <form method="post" class="row g-3 align-items-end">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                            <div class="col-md-4">
                                                                <label class="form-label">Date</label>
                                                                <input type="date" name="add_day_date" class="form-control" required />
                                                            </div>
                                                            <div class="col-md-5">
                                                                <label class="form-label">Label (optional)</label>
                                                                <input type="text" name="add_day_label" class="form-control" placeholder="e.g., Day 1" />
                                                            </div>
                                                            <div class="col-md-3">
                                                                <button name="add_day" value="1" class="btn btn-gradient-success w-100"><i class="bi bi-plus-lg me-1"></i>Add Day</button>
                                                            </div>
                                                        </form>
                                        <hr class="my-4">
                                                        <form method="post" class="row g-3">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                                            <div class="col-12">
                                                                <label class="form-label">Bulk add (one per line: YYYY-MM-DD[, Label])</label>
                                                                <textarea name="bulk_days_text" class="form-control" rows="4" placeholder="2025-10-01, Day 1&#10;2025-10-02, Day 2&#10;2025-10-03"></textarea>
                                                            </div>
                                                            <div class="col-md-3 ms-auto">
                                                                <button name="add_bulk" value="1" class="btn btn-outline-primary w-100"><i class="bi bi-upload me-1"></i>Add Multiple</button>
                                                            </div>
                                                        </form>
                                    </div>
                                </div>
                
                <!-- Help Card -->
                <div class="card mt-4">
                    <div class="card-body text-center">
                        <i class="bi bi-lightbulb text-warning fs-3 mb-2"></i>
                        <h6 class="fw-semibold mb-2">Quick Guide</h6>
                        <ul class="list-unstyled text-muted small">
                            <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i><strong>Date Strict:</strong> Access follows scheduled dates + toggles</li>
                            <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i><strong>Admin Only:</strong> Manual control - ignores dates</li>
                            <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i>Toggle switches instantly update day availability</li>
                        </ul>
                        <hr class="my-3">
                        <div class="d-flex gap-2 justify-content-center">
                            <a href="admin_manage_function_rooms.php" class="btn btn-sm btn-gradient-primary">
                                <i class="bi bi-door-open me-1"></i>Manage Rooms
                            </a>
                            <a href="admin_speaker_manage.php" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-people me-1"></i>Manage Speakers
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>

// Enhanced day toggle functionality with visual feedback
document.querySelectorAll('.toggle-day').forEach(cb => {
    cb.addEventListener('change', function() {
        const dayId = this.dataset.dayId;
        const isEnabled = this.checked ? 1 : 0;
        const listItem = this.closest('.day-item');
        const statusBadge = listItem.querySelector('.status-badge');
        const icon = listItem.querySelector('i');
        
        // Disable the switch during request
        this.disabled = true;
        
        // Add loading state
        listItem.style.opacity = '0.6';
        
        // Try primary then fallback
        const urlPrimary = new URL(API_TOGGLE_DAY_URL, window.location.origin).toString();
        const urlFallback = new URL('../api/toggle_day.php', window.location.href).toString();
        fetch(urlPrimary, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': CSRF_TOKEN
                    },
                    cache: 'no-store',
                    body: JSON.stringify({ 
                        day_id: dayId, 
                        is_enabled: isEnabled, 
                        csrf_token: CSRF_TOKEN
                    })
                })
        .then(async r => {
            if(!r.ok){
              // try fallback
              return fetch(urlFallback, { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF_TOKEN}, cache:'no-store', body: JSON.stringify({day_id:dayId,is_enabled:isEnabled,csrf_token:CSRF_TOKEN}) });
            }
            return r;
        })
        .then(r => r.json())
        .then(json => {
            if (json.success) {
                // Update visual state
                updateDayVisualState(listItem, isEnabled, statusBadge, icon);
                
                // Show success feedback
                        showToast('Day status updated successfully!', 'success');
                    } else if(json.debug_role) {
                        showToast('Role not permitted ('+ json.debug_role +')', 'error');
            } else {
                // Revert the toggle on error
                this.checked = !this.checked;
                showToast('Error: ' + (json.error || 'Could not update'), 'error');
            }
        })
        .catch(() => {
            // Revert the toggle on network error
            this.checked = !this.checked;
            showToast('Network error - please try again', 'error');
        })
        .finally(() => {
            // Re-enable the switch and remove loading state
            this.disabled = false;
            listItem.style.opacity = '1';
        });
    });
});

function updateDayVisualState(listItem, isEnabled, statusBadge, icon) {
    // Update classes
    listItem.classList.remove('enabled', 'disabled');
    listItem.classList.add(isEnabled ? 'enabled' : 'disabled');
    
    // Update status badge
    statusBadge.classList.remove('status-enabled', 'status-disabled');
    statusBadge.classList.add(isEnabled ? 'status-enabled' : 'status-disabled');
    statusBadge.textContent = isEnabled ? 'Active' : 'Inactive';
    
    // Update icon
    icon.className = isEnabled ? 'bi bi-calendar-check-fill text-success fs-4' : 'bi bi-calendar-x text-muted fs-4';
    
    // Add animation
    listItem.style.transform = 'scale(1.02)';
    setTimeout(() => { listItem.style.transform = 'scale(1)'; }, 150);
    // Force a lightweight state refresh in case of stale UI (optional):
    // Could re-query backend for this day to ensure DB state matches toggle.
}

function showToast(message, type) {
    // Create toast container if it doesn't exist
    let toastContainer = document.getElementById('toast-container');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.id = 'toast-container';
        toastContainer.className = 'position-fixed top-0 end-0 p-3';
        toastContainer.style.zIndex = '1050';
        document.body.appendChild(toastContainer);
    }
    
    // Create toast
    const toast = document.createElement('div');
    toast.className = `alert alert-${type === 'success' ? 'success' : 'danger'} alert-dismissible fade show`;
    toast.innerHTML = `
        <i class="bi bi-${type === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'} me-2"></i>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    toastContainer.appendChild(toast);
    
    // Auto remove after 3 seconds
    setTimeout(() => {
        if (toast.parentNode) {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 150);
        }
    }, 3000);
}

// Add loading animations to cards
document.addEventListener('DOMContentLoaded', function() {
    // Animate cards on load
    const cards = document.querySelectorAll('.card');
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        
        setTimeout(() => {
            card.style.transition = 'all 0.5s ease';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });
});

// (Form-based add implemented; no JS required for adding days.)
</script>
</body>
</html>
