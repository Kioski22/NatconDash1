<?php
session_start();
require_once __DIR__ . '/../db.php';

// normalize DB connection ($conn or $mysqli)
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

// require admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: admin_login.php');
    exit;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

// helper
if (!function_exists('e')) {
    function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
// Add: asset URL normalizer
if (!function_exists('asset_url')) {
    function asset_url($path) {
        $path = (string)$path;
        if ($path === '') return '';
        if (preg_match('#^https?://#i', $path)) return $path; // already absolute URL
        // project base (e.g. /NatconDash1)
        $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
        if ($base === '' || $base === '.' ) $base = '';
        return $base . '/' . ltrim($path, '/');
    }
}

$errors = [];
$success = '';

// helper: handle photo upload, returns relative path or null
if (!function_exists('handle_photo_upload')) {
    function handle_photo_upload($fileField = 'photo_file') {
        if (empty($_FILES[$fileField]) || $_FILES[$fileField]['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $f = $_FILES[$fileField];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            return null;
        }
    $maxBytes = 8 * 1024 * 1024; // 8MB
    if ($f['size'] > $maxBytes) {
      return ['error' => 'Photo too large (max 8MB).'];
        }
        $allowed = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/gif'=>'gif'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $f['tmp_name']);
        finfo_close($finfo);
        if (!isset($allowed[$mime])) {
            return ['error' => 'Invalid photo type. Allowed: jpg, png, gif.'];
        }
        $ext = $allowed[$mime];
        $uploadsDir = __DIR__ . '/../uploads/speakers';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }
        $filename = bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $uploadsDir . '/' . $filename;
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            return ['error' => 'Failed to move uploaded file.'];
        }
        // return web relative path
        return 'uploads/speakers/' . $filename;
    }
}

// NEW: helper: handle presentation upload (ppt/pptx/pdf), returns relative path or null
if (!function_exists('handle_presentation_upload')) {
    function handle_presentation_upload($fileField = 'presentation_file') {
        if (empty($_FILES[$fileField]) || $_FILES[$fileField]['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $f = $_FILES[$fileField];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            return ['error' => 'Upload error for presentation file.'];
        }
        $maxBytes = 20 * 1024 * 1024; // 20 MB
        if ($f['size'] > $maxBytes) {
            return ['error' => 'Presentation too large (max 20MB).'];
        }

        $allowed = [
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/pdf' => 'pdf',
        ];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $f['tmp_name']);
        finfo_close($finfo);

        // Fallback by extension if mime is ambiguous
        $extByName = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!isset($allowed[$mime])) {
            if (in_array($extByName, ['pptx','ppt','pdf'], true)) {
                $mime = array_search($extByName, $allowed, true) ?: 'application/pdf';
            } else {
                return ['error' => 'Invalid presentation type. Allowed: .pptx, .ppt, .pdf'];
            }
        }
        $ext = $allowed[$mime] ?? $extByName;

        $uploadsDir = __DIR__ . '/../uploads/presentations';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }
        $filename = bin2hex(random_bytes(12)) . '.' . $ext;
        $dest = $uploadsDir . '/' . $filename;
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            return ['error' => 'Failed to store presentation file.'];
        }
        // return web relative path
        return 'uploads/presentations/' . $filename;
    }
}

// handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], (string)$token)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';

    if ($action === 'add') {
            $name = trim($_POST['name'] ?? '');
            $title = trim($_POST['title'] ?? '');
            $room_id = isset($_POST['room_id']) && is_numeric($_POST['room_id']) ? (int)$_POST['room_id'] : 0;
            $start_time = trim($_POST['start_time'] ?? '') ?: null;
            $end_time = trim($_POST['end_time'] ?? '') ?: null;
            $bio = trim($_POST['bio'] ?? '');
            $photo_url = trim($_POST['photo_url'] ?? null);
      $program_type = ($_POST['program_type'] ?? 'main') === 'sub' ? 'sub' : 'main'; // NEW
            // NEW
            $presentation_url = null;

            if ($name === '') $errors[] = 'Speaker name is required.';
            if ($room_id <= 0) $errors[] = 'Please choose a function room.';
            if ($start_time !== null && $start_time !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start_time)) {
                $errors[] = 'Invalid start time format. Use HH:MM or HH:MM:SS.';
            }
            if ($end_time !== null && $end_time !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end_time)) {  // NEW
                $errors[] = 'Invalid end time format. Use HH:MM or HH:MM:SS.';
            }

            // handle uploads
            $upload = handle_photo_upload('photo_file');
            if (is_array($upload) && isset($upload['error'])) {
                $errors[] = $upload['error'];
            } elseif (is_string($upload)) {
                $photo_url = $upload;
            }

            // NEW: presentation upload (optional)
            $pres = handle_presentation_upload('presentation_file');
            if (is_array($pres) && isset($pres['error'])) {
                $errors[] = $pres['error'];
            } elseif (is_string($pres)) {
                $presentation_url = $pres;
            }

            if (empty($errors)) {
                // CHANGED: include presentation_url
    $technical_department_id = null;
    if ($program_type === 'sub') {
      $technical_department_id = isset($_POST['technical_department_id']) && is_numeric($_POST['technical_department_id']) ? (int)$_POST['technical_department_id'] : 0;
      if ($technical_department_id <= 0) { $errors[] = 'Technical Department is required for Sub Program speaker.'; }
    }
    if (empty($errors)) {
      $stmt = $db->prepare("INSERT INTO speakers (room_id, name, title, start_time, end_time, photo_url, presentation_url, bio, program_type, technical_department_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
      if ($stmt) {
        $stmt->bind_param('issssssssi', $room_id, $name, $title, $start_time, $end_time, $photo_url, $presentation_url, $bio, $program_type, $technical_department_id);
        if ($stmt->execute()) {
          $success = 'Speaker added.';
        } else {
          $errors[] = 'Insert failed: ' . $stmt->error;
        }
        $stmt->close();
      } else {
        $errors[] = 'DB error: ' . $db->error;
      }
    }
            }

    } elseif ($action === 'update') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $name = trim($_POST['name'] ?? '');
            $title = trim($_POST['title'] ?? '');
            $room_id = isset($_POST['room_id']) && is_numeric($_POST['room_id']) ? (int)$_POST['room_id'] : 0;
            $start_time = trim($_POST['start_time'] ?? '') ?: null;
            $end_time = trim($_POST['end_time'] ?? '') ?: null;
            $bio = trim($_POST['bio'] ?? '');
            $photo_url = trim($_POST['photo_url'] ?? null);
      $program_type = ($_POST['program_type'] ?? 'main') === 'sub' ? 'sub' : 'main'; // NEW
            // NEW: keep existing unless replaced
            $presentation_url = trim($_POST['presentation_url'] ?? '') ?: null;

            if ($id <= 0) $errors[] = 'Invalid speaker id.';
            if ($name === '') $errors[] = 'Speaker name is required.';
            if ($room_id <= 0) $errors[] = 'Please choose a function room.';
            if ($start_time !== null && $start_time !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start_time)) {
                $errors[] = 'Invalid start time format. Use HH:MM or HH:MM:SS.';
            }
            if ($end_time !== null && $end_time !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end_time)) {  // NEW
                $errors[] = 'Invalid end time format. Use HH:MM or HH:MM:SS.';
            }

            // photo upload (optional replace)
            $upload = handle_photo_upload('photo_file');
            if (is_array($upload) && isset($upload['error'])) {
                $errors[] = $upload['error'];
            } elseif (is_string($upload)) {
                $photo_url = $upload;
            }

            // NEW: presentation upload (optional replace)
            $pres = handle_presentation_upload('presentation_file');
            if (is_array($pres) && isset($pres['error'])) {
                $errors[] = $pres['error'];
            } elseif (is_string($pres)) {
                $presentation_url = $pres;
            }

            if (empty($errors)) {
                // CHANGED: include presentation_url
    $technical_department_id = null;
    if ($program_type === 'sub') {
      $technical_department_id = isset($_POST['technical_department_id']) && is_numeric($_POST['technical_department_id']) ? (int)$_POST['technical_department_id'] : 0;
      if ($technical_department_id <= 0) { $errors[] = 'Technical Department is required for Sub Program speaker.'; }
    }
    if (empty($errors)) {
      $stmt = $db->prepare("UPDATE speakers SET room_id = ?, name = ?, title = ?, start_time = ?, end_time = ?, photo_url = ?, presentation_url = ?, bio = ?, program_type = ?, technical_department_id = ? WHERE id = ? LIMIT 1");
      if ($stmt) {
        $stmt->bind_param('isssssssssii', $room_id, $name, $title, $start_time, $end_time, $photo_url, $presentation_url, $bio, $program_type, $technical_department_id, $id);
        if ($stmt->execute()) {
          $success = 'Speaker updated.';
        } else {
          $errors[] = 'Update failed: ' . $stmt->error;
        }
        $stmt->close();
      } else {
        $errors[] = 'DB error: ' . $db->error;
      }
    }
            }

        } elseif ($action === 'delete') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id <= 0) $errors[] = 'Invalid id for delete.';
            if (empty($errors)) {
                $stmt = $db->prepare("DELETE FROM speakers WHERE id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('i', $id);
                    if ($stmt->execute()) {
                        $success = 'Speaker deleted.';
                    } else {
                        $errors[] = 'Delete failed: ' . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $errors[] = 'DB error: ' . $db->error;
                }
            }
        } else {
            $errors[] = 'Unknown action.';
        }
    }

    // regenerate token and redirect to avoid repost
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    $_SESSION['spk_messages'] = ['errors' => $errors, 'success' => $success];
    header('Location: admin_speaker_manage.php');
    exit;
}

// show messages
$messages = $_SESSION['spk_messages'] ?? null;
if ($messages) {
    $errors = array_merge($errors, $messages['errors'] ?? []);
    if (!empty($messages['success'])) $success = $messages['success'];
    unset($_SESSION['spk_messages']);
}

// fetch days and rooms for form dropdowns
$days = [];
$r = $db->query("SELECT id, label, day_date FROM function_room_days ORDER BY day_date ASC");
if ($r) {
    while ($row = $r->fetch_assoc()) $days[] = $row;
}

// fetch all rooms (we'll use JS to filter by day if needed)
$rooms = [];
$r2 = $db->query("
  SELECT fr.id, fr.name,
         fd.id AS day_id, fd.label AS day_label, fd.day_date
  FROM function_rooms fr
  LEFT JOIN function_room_days fd ON fd.id = fr.day_id
  ORDER BY fd.day_date ASC, fr.id ASC
");
if ($r2) {
    while ($row = $r2->fetch_assoc()) {
        // derive day_number if label like "Day 1"
        $row['day_number'] = null;
        if (!empty($row['day_label']) && preg_match('/(\d+)/', $row['day_label'], $m)) {
            $row['day_number'] = (int)$m[1];
        }
        $rooms[] = $row;
    }
}

// fetch speakers list with room + day info
$speakers = [];
// Ensure program_type column exists (auto-migrate if missing)
$colRes = $db->query("SHOW COLUMNS FROM speakers LIKE 'program_type'");
if ($colRes && $colRes->num_rows === 0) {
  @ $db->query("ALTER TABLE speakers ADD COLUMN program_type ENUM('main','sub') NOT NULL DEFAULT 'main'");
}
$colRes && $colRes->close();
// Ensure technical_departments table exists
$tblRes = $db->query("SHOW TABLES LIKE 'technical_departments'");
if ($tblRes && $tblRes->num_rows === 0) {
  $db->query("CREATE TABLE technical_departments (\n    id INT AUTO_INCREMENT PRIMARY KEY,\n    name VARCHAR(100) NOT NULL UNIQUE,\n    description TEXT NULL,\n    is_active TINYINT(1) NOT NULL DEFAULT 1,\n    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $db->query("INSERT INTO technical_departments (name) VALUES ('HVAC'),('Power Plant'),('Maintenance'),('Automation'),('Design & Standards')");
}
$tblRes && $tblRes->close();
// Ensure technical_department_id column exists
$colRes2 = $db->query("SHOW COLUMNS FROM speakers LIKE 'technical_department_id'");
if ($colRes2 && $colRes2->num_rows === 0) {
  @ $db->query("ALTER TABLE speakers ADD COLUMN technical_department_id INT NULL AFTER program_type");
  @ $db->query("ALTER TABLE speakers ADD CONSTRAINT fk_speakers_techdept FOREIGN KEY (technical_department_id) REFERENCES technical_departments(id) ON DELETE SET NULL ON UPDATE CASCADE");
}
$colRes2 && $colRes2->close();
// Fetch technical departments
$technical_departments = [];
$tdRes = $db->query("SELECT id,name FROM technical_departments WHERE is_active=1 ORDER BY name ASC");
if ($tdRes) { while($r=$tdRes->fetch_assoc()) $technical_departments[]=$r; }
$sql = "
  SELECT s.id, s.name, s.title, s.start_time, s.end_time, s.photo_url, s.presentation_url, s.room_id,
       s.program_type,
       s.technical_department_id,
       td.name AS technical_department_name,
       fr.name AS room_name,
       fd.id AS day_id, fd.label AS day_label, fd.day_date
  FROM speakers s
  LEFT JOIN function_rooms fr ON fr.id = s.room_id
  LEFT JOIN function_room_days fd ON fd.id = fr.day_id
  LEFT JOIN technical_departments td ON td.id = s.technical_department_id
  ORDER BY fd.day_date ASC, fr.id ASC, s.start_time ASC
";
$res = $db->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) $speakers[] = $row;
}

// load speaker for edit if requested
$edit = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
  $stmt = $db->prepare("SELECT id, name, title, start_time, end_time, photo_url, presentation_url, bio, room_id, program_type, technical_department_id FROM speakers WHERE id = ? LIMIT 1"); // CHANGED
    if ($stmt) {
        $stmt->bind_param('i', $eid);
        $stmt->execute();
        $edit = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Speakers — Manage | 73rd PSME National Convention</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
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
    
    .speaker-photo {
      width: 48px;
      height: 48px;
      border-radius: 8px;
      object-fit: cover;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      transition: all 0.2s ease;
    }
    
    .speaker-photo:hover {
      transform: scale(1.05);
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    
    .empty-photo {
      width: 48px;
      height: 48px;
      border-radius: 8px;
      background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #64748b;
    }
    
    .small-muted {
      font-size: 0.85rem;
      color: #6c757d;
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
    
    .btn-gradient-danger {
      background: var(--danger-gradient);
      color: white;
    }
    
    .btn-gradient-danger:hover {
      transform: translateY(-1px);
      box-shadow: var(--shadow-soft);
      color: white;
    }
    
    .form-control:focus, .form-select:focus {
      border-color: #667eea;
      box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.15);
    }
    
    .table-hover tbody tr:hover {
      background-color: rgba(102, 126, 234, 0.05);
      transform: scale(1.005);
      transition: all 0.2s ease;
    }
    
    .badge {
      padding: 0.5rem 0.75rem;
      font-weight: 500;
      border-radius: 20px;
    }
    
    .bg-gradient-primary {
      background: var(--primary-gradient) !important;
    }
    
    .bg-gradient-warning {
      background: var(--warning-gradient) !important;
    }
    
    .alert {
      border: none;
      border-radius: var(--border-radius);
      padding: 1rem 1.25rem;
    }
    
    .btn-group-actions {
      display: flex;
      gap: 0.25rem;
    }
  </style>
</head>
<body class="p-3">
  <div class="container-fluid">
    <div class="main-container">
      <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <h1 class="page-title"><i class="bi bi-people-fill me-2"></i>Speaker Management</h1>
            <p class="page-subtitle">Manage speakers, presentations, and schedule assignments</p>
          </div>
          <a href="Admin_dashboard.php" class="btn btn-light btn-lg">
            <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
          </a>
        </div>
      </div>
      
      <div class="p-4">

        <!-- Alerts -->
        <?php if (!empty($errors)): ?>
          <div class="alert alert-danger d-flex align-items-center">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-4"></i>
            <div>
              <strong>Please fix the following issues:</strong>
              <ul class="mb-0 mt-1"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($success): ?>
          <div class="alert alert-success d-flex align-items-center">
            <i class="bi bi-check-circle-fill me-2 fs-4"></i>
            <div><?= e($success) ?></div>
          </div>
        <?php endif; ?>

        <!-- Speakers List -->
        <div class="row">
          <div class="col-lg-8">
            <div class="card">
              <div class="card-header">
                <div class="d-flex align-items-center">
                  <i class="bi bi-list-ul me-2 text-primary fs-5"></i>
                  <h5 class="card-title mb-0">Speakers</h5>
                  <span class="badge bg-secondary ms-auto"><?= count($speakers) ?> speakers</span>
                </div>
              </div>
              <div class="card-body p-0">
                <?php if (empty($speakers)): ?>
                  <div class="text-center text-muted py-5">
                    <i class="bi bi-person-plus fs-1 mb-3 d-block text-light"></i>
                    <h6>No speakers added yet</h6>
                    <p class="mb-0">Create your first speaker using the form.</p>
                  </div>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                      <thead class="table-light">
                        <tr>
                          <th class="fw-semibold">#</th>
                          <th class="fw-semibold"><i class="bi bi-person-circle me-1"></i>Photo</th>
                          <th class="fw-semibold"><i class="bi bi-person me-1"></i>Name</th>
                          <th class="fw-semibold"><i class="bi bi-briefcase me-1"></i>Title</th>
                          <th class="fw-semibold"><i class="bi bi-diagram-3 me-1"></i>Program</th>
                          <th class="fw-semibold"><i class="bi bi-building me-1"></i>Tech Dept</th>
                          <th class="fw-semibold"><i class="bi bi-calendar-day me-1"></i>Day</th>
                          <th class="fw-semibold"><i class="bi bi-door-open me-1"></i>Room</th>
                          <th class="fw-semibold"><i class="bi bi-clock me-1"></i>Time</th>
                          <th class="fw-semibold text-center">Actions</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($speakers as $s): ?>
                          <tr>
                            <td class="text-muted"><?= (int)$s['id'] ?></td>
                            <td>
                              <?php if (!empty($s['photo_url'])): ?>
                                <img src="<?= e(asset_url($s['photo_url'])) ?>" class="speaker-photo" alt="<?= e($s['name']) ?>">
                              <?php else: ?>
                                <div class="empty-photo">
                                  <i class="bi bi-person fs-5"></i>
                                </div>
                              <?php endif; ?>
                            </td>
                            <td>
                              <div class="fw-medium"><?= e($s['name']) ?></div>
                              <?php if (!empty($s['presentation_url'])): ?>
                                <small class="text-success">
                                  <i class="bi bi-file-earmark-slides me-1"></i>Has presentation
                                </small>
                              <?php endif; ?>
                            </td>
                            <td class="small"><?= e($s['title']) ?></td>
                            <td>
                              <span class="badge bg-gradient-<?php echo ($s['program_type']??'main')==='sub'?'warning':'primary'; ?> d-flex align-items-center" style="width: fit-content;">
                                <?php if (($s['program_type']??'main')==='sub'): ?>
                                  <i class="bi bi-diagram-2 me-1"></i>SUB
                                <?php else: ?>
                                  <i class="bi bi-house me-1"></i>MAIN
                                <?php endif; ?>
                              </span>
                            </td>
                            <td class="small"><?= e($s['technical_department_name'] ?? ($s['program_type']==='sub' ? 'Not assigned' : '—')) ?></td>
                            <td><strong><?= e($s['day_label'] ?: '-') ?></strong></td>
                            <td><?= e($s['room_name'] ?: '-') ?></td>
                            <td class="small">
                              <?php
                                $t1 = $s['start_time'] ?? '';
                                $t2 = $s['end_time'] ?? '';
                                $timeText = $t1 . ($t2 ? ' - ' . $t2 : '');
                                echo e($timeText ?: '-');
                              ?>
                            </td>
                            <td>
                              <div class="btn-group-actions justify-content-center">
                                <a href="admin_speaker_manage.php?edit=<?= (int)$s['id'] ?>" 
                                   class="btn btn-sm btn-gradient-primary" 
                                   title="Edit Speaker">
                                  <i class="bi bi-pencil"></i>
                                </a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this speaker? This action cannot be undone.');">
                                  <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                  <input type="hidden" name="action" value="delete">
                                  <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                  <button class="btn btn-sm btn-gradient-danger" title="Delete Speaker">
                                    <i class="bi bi-trash"></i>
                                  </button>
                                </form>
                              </div>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- Add/Edit Form -->
          <div class="col-lg-4">
            <div class="card">
              <div class="card-header">
                <?php if ($edit): ?>
                  <div class="d-flex align-items-center">
                    <i class="bi bi-pencil-square me-2 text-warning fs-5"></i>
                    <h5 class="card-title mb-0">Edit Speaker</h5>
                    <span class="badge bg-warning ms-auto">#<?= (int)$edit['id'] ?></span>
                  </div>
                <?php else: ?>
                  <div class="d-flex align-items-center">
                    <i class="bi bi-person-plus me-2 text-success fs-5"></i>
                    <h5 class="card-title mb-0">Add Speaker</h5>
                  </div>
                <?php endif; ?>
              </div>
              <div class="card-body">
                <?php if ($edit): ?>
              <form method="post" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">

                  <div class="mb-3">
                    <label class="form-label fw-semibold">
                      <i class="bi bi-person me-1 text-primary"></i>Speaker Name
                    </label>
                    <input name="name" class="form-control" required value="<?= e($edit['name']) ?>" placeholder="Enter speaker's full name">
                  </div>

                  <div class="mb-3">
                    <label class="form-label fw-semibold">
                      <i class="bi bi-briefcase me-1 text-secondary"></i>Title / Designation
                    </label>
                    <input name="title" class="form-control" value="<?= e($edit['title']) ?>" placeholder="Job title or designation">
                  </div>

                  <div class="mb-3">
                    <label class="form-label fw-semibold">
                      <i class="bi bi-diagram-3 me-1 text-info"></i>Program Type
                    </label>
                    <select name="program_type" class="form-select" required>
                      <option value="main" <?= ($edit['program_type'] ?? 'main')==='main'?'selected':'' ?>>🏢 Main Program</option>
                      <option value="sub" <?= ($edit['program_type'] ?? 'main')==='sub'?'selected':'' ?>>🏗️ Sub Program</option>
                    </select>
                  </div>

                  <div class="mb-3" id="techDeptWrapperEdit">
                    <label class="form-label fw-semibold">
                      <i class="bi bi-building me-1 text-warning"></i>Technical Department
                    </label>
                    <select name="technical_department_id" class="form-select" id="technical_department_id_edit">
                      <option value="">Select department</option>
                      <?php foreach ($technical_departments as $td): ?>
                        <option value="<?= (int)$td['id'] ?>" <?= (isset($edit['technical_department_id']) && (int)$edit['technical_department_id']===$td['id'])?'selected':'' ?>>
                          <?= e($td['name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <small class="form-text text-muted mt-1">
                      <i class="bi bi-info-circle me-1"></i>Required for Sub Program speakers
                    </small>
                  </div>

                  <div class="mb-3">
                    <label class="form-label fw-semibold">
                      <i class="bi bi-calendar-day me-1 text-success"></i>Day Filter
                    </label>
                    <select id="daySelectEdit" class="form-select">
                      <option value="">All days</option>
                      <?php foreach ($days as $d): ?>
                        <option value="<?= (int)$d['id'] ?>"><?= e($d['label']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  
                  <div class="mb-3">
                    <label class="form-label fw-semibold">
                      <i class="bi bi-door-open me-1 text-primary"></i>Function Room
                    </label>
                    <select name="room_id" id="roomSelectEdit" class="form-select" required>
                      <option value="">Select room</option>
                      <?php foreach ($rooms as $rm): ?>
                        <option value="<?= (int)$rm['id'] ?>" <?= $edit['room_id'] == $rm['id'] ? 'selected' : '' ?>>
                          <?= e($rm['name']) ?> <?= $rm['day_number'] ? ' (Day '.$rm['day_number'].')' : '' ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <small id="noRoomsEdit" class="text-danger d-none">No rooms found for selected day.</small>
                  </div>

                  <div class="row mb-3">
                    <div class="col-md-6">
                      <label class="form-label fw-semibold">
                        <i class="bi bi-clock me-1 text-success"></i>Start Time
                      </label>
                      <input name="start_time" type="time" class="form-control" value="<?= e($edit['start_time']) ?>">
                    </div>
                    <div class="col-md-6">
                      <label class="form-label fw-semibold">
                        <i class="bi bi-clock-history me-1 text-warning"></i>End Time
                      </label>
                      <input name="end_time" type="time" class="form-control" value="<?= e($edit['end_time'] ?? '') ?>">
                    </div>
                  </div>

                  <div class="mb-3">
                    <label class="form-label fw-semibold">
                      <i class="bi bi-camera me-1 text-info"></i>Photo
                    </label>
                    <?php if (!empty($edit['photo_url'])): ?>
                      <div class="mb-2 d-flex align-items-center">
                        <img src="<?= e(asset_url($edit['photo_url'])) ?>" class="speaker-photo me-2" alt="">
                        <small class="text-success"><i class="bi bi-check-circle me-1"></i>Current photo</small>
                      </div>
                    <?php endif; ?>
                    <input name="photo_file" type="file" accept="image/*" class="form-control">
                    <input type="hidden" name="photo_url" value="<?= e($edit['photo_url']) ?>">
                    <small class="form-text text-muted mt-1">Upload to replace current photo (JPG, PNG, GIF - max 8MB)</small>
                  </div>

                  <div class="mb-3">
                    <label class="form-label fw-semibold">
                      <i class="bi bi-file-earmark-slides me-1 text-primary"></i>Presentation
                    </label>
                    <?php if (!empty($edit['presentation_url'])): ?>
                      <div class="mb-2">
                        <a href="<?= e(asset_url($edit['presentation_url'])) ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                          <i class="bi bi-eye me-1"></i>View Current
                        </a>
                      </div>
                    <?php endif; ?>
                    <input name="presentation_file" type="file" accept=".ppt,.pptx,.pdf" class="form-control">
                    <input type="hidden" name="presentation_url" value="<?= e($edit['presentation_url']) ?>">
                    <small class="form-text text-muted mt-1">Upload to replace (PPT, PPTX, PDF - max 20MB)</small>
                  </div>

                  <div class="mb-4">
                    <label class="form-label fw-semibold">
                      <i class="bi bi-card-text me-1 text-secondary"></i>Short Bio
                    </label>
                    <textarea name="bio" class="form-control" rows="4" placeholder="Brief biography or description"><?= e($edit['bio'] ?? '') ?></textarea>
                  </div>

                  <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-gradient-primary btn-lg">
                      <i class="bi bi-check-lg me-2"></i>Update Speaker
                    </button>
                    <a href="admin_speaker_manage.php" class="btn btn-outline-secondary">
                      <i class="bi bi-arrow-left me-2"></i>Cancel Edit
                    </a>
                  </div>
              </form>

                <?php else: ?>
                  <form method="post" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="add">

                    <div class="mb-3">
                      <label class="form-label fw-semibold">
                        <i class="bi bi-person me-1 text-primary"></i>Speaker Name
                      </label>
                      <input name="name" class="form-control" required value="" placeholder="Enter speaker's full name">
                    </div>

                    <div class="mb-3">
                      <label class="form-label fw-semibold">
                        <i class="bi bi-briefcase me-1 text-secondary"></i>Title / Designation
                      </label>
                      <input name="title" class="form-control" value="" placeholder="Job title or designation">
                    </div>

                    <div class="mb-3">
                      <label class="form-label fw-semibold">
                        <i class="bi bi-diagram-3 me-1 text-info"></i>Program Type
                      </label>
                      <select name="program_type" class="form-select" required>
                        <option value="main" selected>🏢 Main Program</option>
                        <option value="sub">🏗️ Sub Program</option>
                      </select>
                    </div>

                    <div class="mb-3" id="techDeptWrapperAdd">
                      <label class="form-label fw-semibold">
                        <i class="bi bi-building me-1 text-warning"></i>Technical Department
                      </label>
                      <select name="technical_department_id" class="form-select" id="technical_department_id_add">
                        <option value="">Select department</option>
                        <?php foreach ($technical_departments as $td): ?>
                          <option value="<?= (int)$td['id'] ?>"><?= e($td['name']) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <small class="form-text text-muted mt-1" id="techDeptHintAdd">
                        <i class="bi bi-info-circle me-1"></i>Required for Sub Program speakers
                      </small>
                    </div>

                    <div class="mb-3">
                      <label class="form-label fw-semibold">
                        <i class="bi bi-calendar-day me-1 text-success"></i>Day Filter
                      </label>
                      <select id="daySelect" class="form-select">
                        <option value="">All days</option>
                        <?php foreach ($days as $d): ?>
                          <option value="<?= (int)$d['id'] ?>"><?= e($d['label']) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>

                    <div class="mb-3">
                      <label class="form-label fw-semibold">
                        <i class="bi bi-door-open me-1 text-primary"></i>Function Room
                      </label>
                      <select name="room_id" id="roomSelect" class="form-select" required>
                        <option value="">Select room</option>
                        <?php foreach ($rooms as $rm): ?>
                          <option value="<?= (int)$rm['id'] ?>"><?= e($rm['name']) ?> <?= $rm['day_number'] ? ' (Day '.$rm['day_number'].')' : '' ?></option>
                        <?php endforeach; ?>
                      </select>
                      <small id="noRoomsAdd" class="text-danger d-none">No rooms found for selected day.</small>
                    </div>

                    <div class="row mb-3">
                      <div class="col-md-6">
                        <label class="form-label fw-semibold">
                          <i class="bi bi-clock me-1 text-success"></i>Start Time
                        </label>
                        <input name="start_time" type="time" class="form-control" value="">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label fw-semibold">
                          <i class="bi bi-clock-history me-1 text-warning"></i>End Time
                        </label>
                        <input name="end_time" type="time" class="form-control" value="">
                      </div>
                    </div>

                    <div class="mb-3">
                      <label class="form-label fw-semibold">
                        <i class="bi bi-camera me-1 text-info"></i>Photo
                      </label>
                      <input name="photo_file" type="file" accept="image/*" class="form-control">
                      <small class="form-text text-muted mt-1">Optional (JPG, PNG, GIF - max 8MB)</small>
                    </div>

                    <div class="mb-3">
                      <label class="form-label fw-semibold">
                        <i class="bi bi-file-earmark-slides me-1 text-primary"></i>Presentation
                      </label>
                      <input name="presentation_file" type="file" accept=".ppt,.pptx,.pdf" class="form-control">
                      <small class="form-text text-muted mt-1">Optional (PPT, PPTX, PDF - max 20MB)</small>
                    </div>

                    <div class="mb-4">
                      <label class="form-label fw-semibold">
                        <i class="bi bi-card-text me-1 text-secondary"></i>Short Bio
                      </label>
                      <textarea name="bio" class="form-control" rows="4" placeholder="Brief biography or description"></textarea>
                    </div>

                    <div class="d-grid">
                      <button type="submit" class="btn btn-gradient-success btn-lg">
                        <i class="bi bi-plus-lg me-2"></i>Add Speaker
                      </button>
                    </div>
                  </form>
                <?php endif; ?>
            </div>

            <!-- Help Card -->
            <div class="card mt-3">
              <div class="card-body text-center">
                <i class="bi bi-lightbulb text-warning fs-3 mb-2"></i>
                <h6 class="fw-semibold mb-2">Quick Tips</h6>
                <ul class="list-unstyled text-muted small">
                  <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i>Use Day filter to find relevant rooms</li>
                  <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i>Photos and presentations enhance speaker profiles</li>
                  <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i>Technical departments are required for Sub Program</li>
                </ul>
                <hr class="my-2">
                <a href="admin_quiz_manage.php" class="btn btn-sm btn-gradient-primary">
                  <i class="bi bi-ui-checks-grid me-1"></i>Manage Quizzes
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

<script>
const rooms = <?= json_encode($rooms, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

function filterRoomsFor(selectEl, dayId, selectedRoomId = null, noRoomsEl = null) {
  // Reset options
  selectEl.innerHTML = '<option value="">-- select room --</option>';
  let count = 0;

  rooms.forEach(r => {
    if (!dayId || String(r.day_id) === String(dayId)) {
      const opt = document.createElement('option');
      const suffix = r.day_label ? (' (' + r.day_label + ')') : (r.day_number ? ' (Day ' + r.day_number + ')' : '');
      opt.value = r.id;
      opt.textContent = r.name + suffix;
      if (selectedRoomId && String(selectedRoomId) === String(r.id)) opt.selected = true;
      selectEl.appendChild(opt);
      count++;
    }
  });

  if (noRoomsEl) noRoomsEl.classList.toggle('d-none', count > 0);
}

document.addEventListener('DOMContentLoaded', () => {
  // ADD form wiring
  const daySelect = document.getElementById('daySelect');
  const roomSelect = document.getElementById('roomSelect');
  const noRoomsAdd = document.getElementById('noRoomsAdd');
  if (daySelect && roomSelect) {
    daySelect.addEventListener('change', () => {
      filterRoomsFor(roomSelect, daySelect.value, null, noRoomsAdd);
    });
  }

  // EDIT form wiring
  const daySelectEdit = document.getElementById('daySelectEdit');
  const roomSelectEdit = document.getElementById('roomSelectEdit');
  const noRoomsEdit = document.getElementById('noRoomsEdit');

  if (daySelectEdit && roomSelectEdit) {
    // Determine the currently selected room (from PHP-rendered selected option)
    const selectedRoomId = roomSelectEdit.value || null;
    const roomObj = rooms.find(r => String(r.id) === String(selectedRoomId));

    // Preselect day based on the room's day_id, then filter rooms and keep selection
    if (roomObj && roomObj.day_id) {
      daySelectEdit.value = String(roomObj.day_id);
      filterRoomsFor(roomSelectEdit, daySelectEdit.value, selectedRoomId, noRoomsEdit);
    } else {
      // No current room or day mapping; leave as-is but wire change
      filterRoomsFor(roomSelectEdit, daySelectEdit.value || '', selectedRoomId, noRoomsEdit);
    }

    // When day changes, refilter and clear selection if not available
    daySelectEdit.addEventListener('change', () => {
      filterRoomsFor(roomSelectEdit, daySelectEdit.value, null, noRoomsEdit);
    });
  }

  // Tech Department toggling
  const addForm = document.querySelector('form input[name="action"][value="add"]')?.closest('form');
  const editForm = document.querySelector('form input[name="action"][value="update"]')?.closest('form');
  const techDeptWrapperAdd = document.getElementById('techDeptWrapperAdd');
  const techDeptWrapperEdit = document.getElementById('techDeptWrapperEdit');
  function initToggle(form, wrapper){
    if(!form || !wrapper) return;
    const progSelect = form.querySelector('select[name="program_type"]');
    const deptSelect = wrapper.querySelector('select[name="technical_department_id"]');
    const hint = wrapper.querySelector('small#techDeptHintAdd') || wrapper.querySelector('small');
    function sync(){
      if (!progSelect || !deptSelect) return;
      if (progSelect.value === 'sub') {
        deptSelect.disabled = false;
        if (hint) hint.textContent = 'Required for Sub Program.';
        wrapper.classList.remove('opacity-50');
      } else {
        deptSelect.value = '';
        deptSelect.disabled = true;
        if (hint) hint.textContent = 'Disabled (only for Sub Program).';
        wrapper.classList.add('opacity-50');
      }
    }
    if (progSelect) progSelect.addEventListener('change', sync);
    sync();
  }
  initToggle(addForm, techDeptWrapperAdd);
  initToggle(editForm, techDeptWrapperEdit);
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>