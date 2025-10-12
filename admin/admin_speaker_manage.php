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
            $title = trim($_POST['title'] ?? ''); // designation
            $topic = trim($_POST['topic'] ?? ''); // session title/topic
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
      $stmt = $db->prepare("INSERT INTO speakers (room_id, name, title, topic, start_time, end_time, photo_url, presentation_url, bio, program_type, technical_department_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
      if ($stmt) {
        $stmt->bind_param('isssssssssi', $room_id, $name, $title, $topic, $start_time, $end_time, $photo_url, $presentation_url, $bio, $program_type, $technical_department_id);
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
            $title = trim($_POST['title'] ?? ''); // designation
            $topic = trim($_POST['topic'] ?? ''); // session title/topic
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
      $stmt = $db->prepare("UPDATE speakers SET room_id = ?, name = ?, title = ?, topic = ?, start_time = ?, end_time = ?, photo_url = ?, presentation_url = ?, bio = ?, program_type = ?, technical_department_id = ? WHERE id = ? LIMIT 1");
      if ($stmt) {
        // Types: i (room_id), s (name), s (title), s (topic), s (start_time), s (end_time), s (photo_url), s (presentation_url), s (bio), s (program_type), i (technical_department_id), i (id)
        $stmt->bind_param('isssssssssii', $room_id, $name, $title, $topic, $start_time, $end_time, $photo_url, $presentation_url, $bio, $program_type, $technical_department_id, $id);
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

// Ensure function_rooms has program_type for filtering
$colFR = $db->query("SHOW COLUMNS FROM function_rooms LIKE 'program_type'");
if ($colFR && $colFR->num_rows === 0) {
  @ $db->query("ALTER TABLE function_rooms ADD COLUMN program_type ENUM('main','sub') NOT NULL DEFAULT 'main'");
}
$colFR && $colFR->close();

// fetch all rooms (we'll use JS to filter by day if needed)
$rooms = [];
$r2 = $db->query("
  SELECT fr.id, fr.name, fr.program_type,
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
// Ensure topic column exists for session title/topic
$colRes3 = $db->query("SHOW COLUMNS FROM speakers LIKE 'topic'");
if ($colRes3 && $colRes3->num_rows === 0) {
  @ $db->query("ALTER TABLE speakers ADD COLUMN topic VARCHAR(255) NULL AFTER title");
}
$colRes3 && $colRes3->close();
// Fetch technical departments
$technical_departments = [];
$tdRes = $db->query("SELECT id,name FROM technical_departments WHERE is_active=1 ORDER BY name ASC");
if ($tdRes) { while($r=$tdRes->fetch_assoc()) $technical_departments[]=$r; }
$sql = "
  SELECT s.id, s.name, s.title, s.topic, s.start_time, s.end_time, s.photo_url, s.presentation_url, s.room_id,
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
  $stmt = $db->prepare("SELECT id, name, title, topic, start_time, end_time, photo_url, presentation_url, bio, room_id, program_type, technical_department_id FROM speakers WHERE id = ? LIMIT 1"); // CHANGED
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
  <title>Speaker Management — 73rd PSME National Convention</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: #2563eb;
      --primary-dark: #1d4ed8;
      --success: #10b981;
      --warning: #f59e0b;
      --danger: #ef4444;
      --info: #06b6d4;
      --light: #f8fafc;
      --dark: #1e293b;
      --card-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
      --border-radius: 16px;
      --gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      --gradient-success: linear-gradient(135deg, #10b981 0%, #059669 100%);
      --gradient-warning: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
      --gradient-danger: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
      --gradient-info: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
    }

    * { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
    
    body { 
      background: var(--gradient-primary);
      min-height: 100vh;
      padding: 2rem 0;
    }

    .main-container {
      max-width: 1600px;
      margin: 0 auto;
      padding: 0 1rem;
    }

    /* Enhanced Page Header */
    .page-header {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(20px);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 20px;
      padding: 2rem;
      margin-bottom: 2rem;
      box-shadow: var(--card-shadow);
      text-align: center;
    }

    .page-title {
      background: var(--gradient-primary);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      font-weight: 700;
      font-size: 2.5rem;
      margin: 0;
    }

    .page-subtitle {
      color: #64748b;
      font-size: 1.1rem;
      margin-top: 0.5rem;
    }

    /* Enhanced Cards */
    .enhanced-card {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(20px);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: var(--border-radius);
      box-shadow: var(--card-shadow);
      transition: all 0.3s ease;
      overflow: hidden;
    }

    .enhanced-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.15);
    }

    .card-header-enhanced {
      background: linear-gradient(135deg, rgba(37,99,235,0.1), rgba(6,182,212,0.1));
      border-bottom: 2px solid rgba(37,99,235,0.1);
      padding: 1.5rem 2rem;
    }

    .card-body-enhanced {
      padding: 2rem;
    }

    /* Speaker Photo Enhancements */
    .speaker-photo {
      width: 56px;
      height: 56px;
      border-radius: 12px;
      object-fit: cover;
      box-shadow: 0 4px 15px rgba(0,0,0,0.1);
      transition: all 0.3s ease;
      border: 2px solid rgba(255, 255, 255, 0.8);
    }

    .speaker-photo:hover {
      transform: scale(1.1);
      box-shadow: 0 8px 25px rgba(0,0,0,0.2);
      border-color: var(--primary);
    }

    .empty-photo {
      width: 56px;
      height: 56px;
      border-radius: 12px;
      background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #64748b;
      border: 2px solid rgba(255, 255, 255, 0.8);
      transition: all 0.3s ease;
    }

    .empty-photo:hover {
      transform: scale(1.05);
      border-color: var(--info);
    }

    /* Form Enhancements */
    .form-control, .form-select {
      border-radius: 12px;
      border: 2px solid rgba(0,0,0,0.1);
      padding: 12px 16px;
      font-weight: 500;
      transition: all 0.3s ease;
    }

    .form-control:focus, .form-select:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 4px rgba(37,99,235,0.1);
      transform: translateY(-1px);
    }

    .form-label {
      font-weight: 600;
      color: var(--dark);
      margin-bottom: 0.75rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    /* Enhanced Buttons */
    .btn {
      border-radius: 12px;
      font-weight: 600;
      padding: 12px 20px;
      transition: all 0.3s ease;
      border: none;
    }

    .btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 20px rgba(0,0,0,0.15);
    }

    .btn-gradient-primary {
      background: var(--gradient-primary);
      color: white;
    }

    .btn-gradient-success {
      background: var(--gradient-success);
      color: white;
    }

    .btn-gradient-warning {
      background: var(--gradient-warning);
      color: white;
    }

    .btn-gradient-danger {
      background: var(--gradient-danger);
      color: white;
    }

    .btn-gradient-info {
      background: var(--gradient-info);
      color: white;
    }

    .btn-outline-primary {
      border: 2px solid var(--primary);
      color: var(--primary);
      background: transparent;
    }

    .btn-outline-primary:hover {
      background: var(--primary);
      color: white;
    }

    /* Enhanced Table */
    .table-container {
      background: white;
      border-radius: var(--border-radius);
      /* Allow horizontal scroll when content is wide */
      overflow-x: auto;
      overflow-y: hidden;
      box-shadow: var(--card-shadow);
    }

    .table {
      margin: 0;
      /* Ensure a reasonable minimum width so columns don't crush */
      min-width: 1400px;
    }

    .table thead th {
      background: var(--gradient-primary);
      color: white;
      font-weight: 600;
      text-align: center;
      border: none;
      padding: 1rem;
    }

    .table tbody tr {
      transition: all 0.2s ease;
    }

    .table tbody tr:hover {
      background: rgba(37,99,235,0.05);
      /* Avoid transforms to preserve sticky column behavior */
    }

    .table tbody td {
      text-align: center;
      padding: 1rem;
      vertical-align: middle;
    }

    /* Column widths and truncation for long content */
  .table thead th:nth-child(1), .table tbody td:nth-child(1) { width: 70px; }   /* ID */
  .table thead th:nth-child(2), .table tbody td:nth-child(2) { width: 90px; }   /* Photo */
  .table thead th:nth-child(3), .table tbody td:nth-child(3) { width: 240px; }  /* Speaker */
  .table thead th:nth-child(4), .table tbody td:nth-child(4) { width: 280px; }  /* Topic */
  .table thead th:nth-child(5), .table tbody td:nth-child(5) { width: 140px; }  /* Program */
  .table thead th:nth-child(6), .table tbody td:nth-child(6) { width: 200px; }  /* Department */
  .table thead th:nth-child(7), .table tbody td:nth-child(7) { width: 120px; }  /* Day */
  .table thead th:nth-child(8), .table tbody td:nth-child(8) { width: 120px; }  /* Start */
  .table thead th:nth-child(9), .table tbody td:nth-child(9) { width: 120px; }  /* End */
  .table thead th:nth-child(10), .table tbody td:nth-child(10) { width: 110px; }/* Hours */
  .table thead th:nth-child(11), .table tbody td:nth-child(11) { width: 180px; }/* Room */
  .table thead th:nth-child(12), .table tbody td:nth-child(12) { width: 130px; }/* Actions */

    /* Truncate content for potentially long text columns */
    .table tbody td:nth-child(3),
    .table tbody td:nth-child(4),
    .table tbody td:nth-child(6),
    .table tbody td:nth-child(11) {
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      max-width: 100%;
    }

    /* Sticky actions column on the right */
    th.col-actions {
      position: sticky;
      right: 0;
      z-index: 3;
      background: var(--gradient-primary);
    }
    td.col-actions {
      position: sticky;
      right: 0;
      z-index: 2;
      background: #ffffff;
      box-shadow: -6px 0 10px rgba(0,0,0,0.05);
    }

    /* Enhanced Badges */
    .badge {
      padding: 0.5rem 0.75rem;
      font-weight: 600;
      border-radius: 20px;
      font-size: 0.75rem;
    }

    .badge-program-main {
      background: rgba(37,99,235,0.15);
      color: #1d4ed8;
      border: 1px solid rgba(37,99,235,0.3);
    }

    .badge-program-sub {
      background: rgba(245,158,11,0.15);
      color: #d97706;
      border: 1px solid rgba(245,158,11,0.3);
    }

    .badge-count {
      background: rgba(16,185,129,0.15);
      color: #047857;
      border: 1px solid rgba(16,185,129,0.3);
    }

    /* Enhanced Alerts */
    .alert {
      border-radius: var(--border-radius);
      border: none;
      padding: 1rem 1.5rem;
      margin-bottom: 1.5rem;
    }

    .alert-success {
      background: linear-gradient(135deg, rgba(16,185,129,0.1), rgba(5,150,105,0.1));
      color: var(--success);
      border: 2px solid rgba(16,185,129,0.2);
    }

    .alert-danger {
      background: linear-gradient(135deg, rgba(239,68,68,0.1), rgba(220,38,38,0.1));
      color: var(--danger);
      border: 2px solid rgba(239,68,68,0.2);
    }

    /* Empty State */
    .empty-state {
      text-align: center;
      padding: 4rem 2rem;
      color: #64748b;
    }

    .empty-state i {
      font-size: 4rem;
      opacity: 0.3;
      margin-bottom: 1rem;
    }

    /* Action Buttons */
    .btn-group-actions {
      display: flex;
      gap: 0.5rem;
      justify-content: center;
    }

    /* Form Sections */
    .form-section {
      background: rgba(248, 250, 252, 0.5);
      border-radius: 12px;
      padding: 1.5rem;
      margin-bottom: 1.5rem;
      border: 1px solid rgba(0, 0, 0, 0.05);
    }

    .form-section-title {
      font-weight: 600;
      color: var(--dark);
      margin-bottom: 1rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    /* Help Card */
    .help-card {
      background: linear-gradient(135deg, rgba(6,182,212,0.1), rgba(14,165,233,0.1));
      border: 2px solid rgba(6,182,212,0.2);
      border-radius: var(--border-radius);
      padding: 1.5rem;
      text-align: center;
    }

    /* Responsive */
    @media (max-width: 768px) {
      .main-container {
        padding: 0 0.5rem;
      }
      
      .page-header {
        padding: 1.5rem;
      }
      
      .page-title {
        font-size: 2rem;
      }
      
      .card-body-enhanced {
        padding: 1.5rem;
      }
      
      .table-container { overflow-x: auto; }
    }

    /* Animations */
    .fade-in {
      animation: fadeIn 0.6s ease-out;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(20px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .slide-up {
      animation: slideUp 0.6s ease-out;
    }

    @keyframes slideUp {
      from { transform: translateY(30px); opacity: 0; }
      to { transform: translateY(0); opacity: 1; }
    }

    /* File Upload Enhancement */
    .file-upload-area {
      border: 2px dashed rgba(37,99,235,0.3);
      border-radius: 12px;
      padding: 1rem;
      text-align: center;
      background: rgba(37,99,235,0.05);
      transition: all 0.3s ease;
    }

    .file-upload-area:hover {
      border-color: var(--primary);
      background: rgba(37,99,235,0.1);
    }

    .file-upload-area.has-file {
      border-color: var(--success);
      background: rgba(16,185,129,0.05);
    }
  </style>
</head>
<body>
<div class="main-container">
  <!-- Enhanced Page Header -->
  <div class="page-header fade-in">
    <h1 class="page-title">Speaker Management</h1>
    <p class="page-subtitle">Manage speakers, presentations, schedules, and technical assignments</p>
    <div class="mt-3">
      <a href="Admin_dashboard.php" class="btn btn-outline-primary">
        <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
      </a>
    </div>
  </div>

  <!-- Enhanced Messages -->
  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger fade-in">
      <i class="bi bi-exclamation-triangle-fill me-2"></i>
      <strong>Please fix the following issues:</strong>
      <ul class="mb-0 mt-2">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="alert alert-success fade-in">
      <i class="bi bi-check-circle-fill me-2"></i>
      <?= e($success) ?>
    </div>
  <?php endif; ?>

  <!-- Enhanced Speakers List -->
  <div class="row g-4 align-items-stretch">
    <div class="col-12 col-md-7 col-xl-8">
      <div class="enhanced-card slide-up">
        <div class="card-header-enhanced">
          <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
              <i class="bi bi-people me-2"></i>
              Speakers Directory
            </h5>
            <span class="badge badge-count"><?= count($speakers) ?> speakers</span>
          </div>
        </div>
        <div class="card-body-enhanced p-0">
          <?php if (empty($speakers)): ?>
            <div class="empty-state">
              <i class="bi bi-person-plus"></i>
              <h6>No Speakers Added Yet</h6>
              <p class="mb-3">Create your first speaker profile to get started</p>
              <p class="small text-muted">Use the form on the right to add speaker details, photos, and presentations</p>
            </div>
          <?php else: ?>
            <div class="table-container">
              <table class="table table-hover mb-0 align-middle">
                <thead>
                  <tr>
                    <th><i class="bi bi-hash me-1"></i>ID</th>
                    <th><i class="bi bi-camera me-1"></i>Photo</th>
                    <th><i class="bi bi-person me-1"></i>Speaker</th>
                    <th><i class="bi bi-journal-text me-1"></i>Topic</th>
                    <th><i class="bi bi-diagram-3 me-1"></i>Program</th>
                    <th><i class="bi bi-building me-1"></i>Department</th>
                    <th><i class="bi bi-calendar2-week me-1"></i>Day</th>
                    <th><i class="bi bi-clock me-1"></i>Start</th>
                    <th><i class="bi bi-clock-history me-1"></i>End</th>
                    <th><i class="bi bi-hourglass-split me-1"></i>Hours</th>
                    <th><i class="bi bi-geo-alt me-1"></i>Room</th>
                    <th class="col-actions"><i class="bi bi-gear me-1"></i>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($speakers as $s): ?>
                    <tr>
                      <td><span class="badge bg-light text-dark"><?= (int)$s['id'] ?></span></td>
                      <td>
                        <?php if (!empty($s['photo_url'])): ?>
                          <img src="<?= e(asset_url($s['photo_url'])) ?>" class="speaker-photo" alt="<?= e($s['name']) ?>" title="<?= e($s['name']) ?>">
                        <?php else: ?>
                          <div class="empty-photo" title="No photo uploaded">
                            <i class="bi bi-person"></i>
                          </div>
                        <?php endif; ?>
                      </td>
                      <td>
                        <div class="fw-bold text-truncate" style="max-width: 220px;" title="<?= e($s['name']) ?>"><?= e($s['name']) ?></div>
                        <div class="small text-muted text-truncate" style="max-width: 220px;" title="<?= e($s['title'] ?: 'No title specified') ?>"><?= e($s['title'] ?: 'No title specified') ?></div>
                        <?php if (!empty($s['presentation_url'])): ?>
                          <div class="small text-success mt-1">
                            <i class="bi bi-file-earmark-slides me-1"></i>Presentation Available
                          </div>
                        <?php endif; ?>
                      </td>
                      <td>
                        <div class="small fw-semibold text-truncate" style="max-width: 260px;" title="<?= e($s['topic'] ?: '—') ?>"><?= e($s['topic'] ?: '—') ?></div>
                      </td>
                      <td>
                        <span class="badge badge-program-<?= ($s['program_type']??'main')==='sub' ? 'sub' : 'main' ?>">
                          <?php if (($s['program_type']??'main')==='sub'): ?>
                            <i class="bi bi-diagram-2 me-1"></i>Sub Program
                          <?php else: ?>
                            <i class="bi bi-house me-1"></i>Main Program
                          <?php endif; ?>
                        </span>
                      </td>
                      <td>
                        <div class="small text-truncate" style="max-width: 180px;" title="<?= e($s['technical_department_name'] ?? ($s['program_type']==='sub' ? 'Not assigned' : '—')) ?>">
                          <?= e($s['technical_department_name'] ?? ($s['program_type']==='sub' ? 'Not assigned' : '—')) ?>
                        </div>
                      </td>
                      <td>
                        <div class="fw-semibold text-primary" title="<?= e($s['day_label'] ?: '-') ?>"><?= e($s['day_label'] ?: '-') ?></div>
                      </td>
                      <td>
                        <span class="badge bg-light text-dark" title="<?= e($s['start_time'] ?: '-') ?>"><?= e($s['start_time'] ?: '-') ?></span>
                      </td>
                      <td>
                        <span class="badge bg-light text-dark" title="<?= e($s['end_time'] ?: '-') ?>"><?= e($s['end_time'] ?: '-') ?></span>
                      </td>
                      <td>
                        <?php
                          $hrsText = '—';
                          if (!empty($s['start_time']) && !empty($s['end_time'])) {
                            $start = DateTime::createFromFormat('H:i:s', $s['start_time']) ?: DateTime::createFromFormat('H:i', $s['start_time']);
                            $end = DateTime::createFromFormat('H:i:s', $s['end_time']) ?: DateTime::createFromFormat('H:i', $s['end_time']);
                            if ($start && $end) {
                              $diffSec = $end->getTimestamp() - $start->getTimestamp();
                              if ($diffSec > 0) {
                                $hrs = $diffSec / 3600;
                                $hrsText = number_format($hrs, 2) . ' hrs';
                              } else {
                                $hrsText = '0.00 hrs';
                              }
                            }
                          }
                          echo '<span class="badge bg-light text-dark">' . e($hrsText) . '</span>';
                        ?>
                      </td>
                      <td>
                        <div class="small fw-medium text-truncate" style="max-width: 180px;" title="<?= e($s['room_name'] ?: 'No room assigned') ?>"><?= e($s['room_name'] ?: 'No room assigned') ?></div>
                      </td>
                      <td class="col-actions">
                        <div class="btn-group-actions">
                          <a href="admin_speaker_manage.php?edit=<?= (int)$s['id'] ?>" 
                             class="btn btn-sm btn-gradient-primary" 
                             title="Edit Speaker Details">
                            <i class="bi bi-pencil"></i>
                          </a>
                          <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete <?= e($s['name']) ?>? This action cannot be undone and will remove all associated data.');">
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

    <!-- Enhanced Add/Edit Speaker Form -->
  <div class="col-12 col-md-5 col-xl-4">
      <div class="enhanced-card slide-up">
        <div class="card-header-enhanced">
          <?php if ($edit): ?>
            <h5 class="mb-0">
              <i class="bi bi-pencil-square me-2"></i>
              Edit Speaker
              <span class="badge badge-count ms-2">#<?= (int)$edit['id'] ?></span>
            </h5>
          <?php else: ?>
            <h5 class="mb-0">
              <i class="bi bi-person-plus me-2"></i>
              Add New Speaker
            </h5>
          <?php endif; ?>
        </div>
        <div class="card-body-enhanced">
          <?php if ($edit): ?>
            <form method="post" enctype="multipart/form-data" class="enhanced-form">
              <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">

              <!-- Basic Information Section -->
              <div class="form-section">
                <h6 class="form-section-title">
                  <i class="bi bi-person me-2"></i>Basic Information
                </h6>
                
                <div class="form-group">
                  <label class="form-label">
                    <i class="bi bi-person me-1"></i>Speaker Name
                  </label>
                  <input name="name" class="form-control" required value="<?= e($edit['name']) ?>" placeholder="Enter speaker's full name">
                </div>

                <div class="form-group">
                  <label class="form-label">
                    <i class="bi bi-briefcase me-1"></i>Designation
                  </label>
                  <input name="title" class="form-control" value="<?= e($edit['title']) ?>" placeholder="Job title or designation">
                </div>

                <div class="form-group">
                  <label class="form-label">
                    <i class="bi bi-card-text me-1"></i>Session Title / Topic
                  </label>
                  <input name="topic" class="form-control" value="<?= e($edit['topic'] ?? '') ?>" placeholder="Talk title or session topic">
                </div>

                <div class="form-group">
                  <label class="form-label">
                    <i class="bi bi-diagram-3 me-1"></i>Program Type
                  </label>
                  <select name="program_type" class="form-select" required>
                    <option value="main" <?= ($edit['program_type'] ?? 'main')==='main'?'selected':'' ?>>🏢 Main Program</option>
                    <option value="sub" <?= ($edit['program_type'] ?? 'main')==='sub'?'selected':'' ?>>🏗️ Sub Program</option>
                  </select>
                </div>
              </div>

              <div class="form-group" id="techDeptWrapperEdit">
                <label class="form-label">
                  <i class="bi bi-building me-1"></i>Technical Department
                </label>
                <select name="technical_department_id" class="form-select" id="technical_department_id_edit">
                  <option value="">Select department</option>
                  <?php foreach ($technical_departments as $td): ?>
                    <option value="<?= (int)$td['id'] ?>" <?= (isset($edit['technical_department_id']) && (int)$edit['technical_department_id']===$td['id'])?'selected':'' ?>>
                      <?= e($td['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <div class="form-text">
                  <i class="bi bi-info-circle me-1"></i>Required for Sub Program speakers
                </div>
              </div>

              <!-- Schedule & Location Section -->
              <div class="form-section">
                <h6 class="form-section-title">
                  <i class="bi bi-calendar-event me-2"></i>Schedule & Location
                </h6>

                <div class="form-group">
                  <label class="form-label">
                    <i class="bi bi-calendar-day me-1"></i>Day Filter
                  </label>
                  <select id="daySelectEdit" class="form-select">
                    <option value="">All days</option>
                    <?php foreach ($days as $d): ?>
                      <option value="<?= (int)$d['id'] ?>"><?= e($d['label']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                
                <div class="form-group">
                  <label class="form-label">
                    <i class="bi bi-geo-alt me-1"></i>Function Room
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

                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label">
                      <i class="bi bi-clock me-1"></i>Start Time
                    </label>
                    <input name="start_time" type="time" class="form-control" value="<?= e($edit['start_time']) ?>">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">
                      <i class="bi bi-clock-history me-1"></i>End Time
                    </label>
                    <input name="end_time" type="time" class="form-control" value="<?= e($edit['end_time'] ?? '') ?>">
                  </div>
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
                        <i class="bi bi-briefcase me-1 text-secondary"></i>Designation
                      </label>
                      <input name="title" class="form-control" value="" placeholder="Job title or designation">
                    </div>

                    <div class="mb-3">
                      <label class="form-label fw-semibold">
                        <i class="bi bi-card-text me-1 text-primary"></i>Session Title / Topic
                      </label>
                      <input name="topic" class="form-control" value="" placeholder="Talk title or session topic">
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

function filterRoomsFor(selectEl, dayId, selectedRoomId = null, noRoomsEl = null, programType = null) {
  // Reset options
  selectEl.innerHTML = '<option value="">-- select room --</option>';
  let count = 0;

  rooms.forEach(r => {
    const matchesDay = !dayId || String(r.day_id) === String(dayId);
    const matchesProgram = !programType || String(r.program_type || 'main') === String(programType);
    if (matchesDay && matchesProgram) {
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
  const programTypeAdd = document.querySelector('form input[name="action"][value="add"]')?.closest('form')?.querySelector('select[name="program_type"]');
  if (daySelect && roomSelect) {
    const refilterAdd = () => {
      const pt = programTypeAdd ? programTypeAdd.value : null;
      filterRoomsFor(roomSelect, daySelect.value, null, noRoomsAdd, pt);
    };
    daySelect.addEventListener('change', refilterAdd);
    if (programTypeAdd) programTypeAdd.addEventListener('change', refilterAdd);
    // Initial population
    refilterAdd();
  }

  // EDIT form wiring
  const daySelectEdit = document.getElementById('daySelectEdit');
  const roomSelectEdit = document.getElementById('roomSelectEdit');
  const noRoomsEdit = document.getElementById('noRoomsEdit');
  const programTypeEdit = document.querySelector('form input[name="action"][value="update"]')?.closest('form')?.querySelector('select[name="program_type"]');

  if (daySelectEdit && roomSelectEdit) {
    // Determine the currently selected room (from PHP-rendered selected option)
    const selectedRoomId = roomSelectEdit.value || null;
    const roomObj = rooms.find(r => String(r.id) === String(selectedRoomId));

    // Preselect day based on the room's day_id, then filter rooms and keep selection
    if (roomObj && roomObj.day_id) {
      daySelectEdit.value = String(roomObj.day_id);
      const pt = programTypeEdit ? programTypeEdit.value : null;
      filterRoomsFor(roomSelectEdit, daySelectEdit.value, selectedRoomId, noRoomsEdit, pt);
    } else {
      // No current room or day mapping; leave as-is but wire change
      const pt = programTypeEdit ? programTypeEdit.value : null;
      filterRoomsFor(roomSelectEdit, daySelectEdit.value || '', selectedRoomId, noRoomsEdit, pt);
    }

    // When day changes, refilter and clear selection if not available
    const refilterEdit = () => {
      const pt = programTypeEdit ? programTypeEdit.value : null;
      filterRoomsFor(roomSelectEdit, daySelectEdit.value, null, noRoomsEdit, pt);
    };
    daySelectEdit.addEventListener('change', refilterEdit);
    if (programTypeEdit) programTypeEdit.addEventListener('change', refilterEdit);
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