<?php

session_start();
require_once __DIR__ . '/../db.php';

// normalize DB connection (support $conn or $mysqli)
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

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

$errors = [];
$success = '';

// Helper to escape
if (!function_exists('e')) {
    function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// --- Load list of days for the Day selector ---
$days = [];
if ($dr = $db->query("SELECT id, label, day_date FROM function_room_days ORDER BY day_date ASC")) {
    while ($row = $dr->fetch_assoc()) $days[] = $row;
}

// Handle POST actions: add, update, delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], (string)$token)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';
  if ($action === 'add') {
            $name = trim($_POST['name'] ?? '');
            $label = trim($_POST['label'] ?? '');
            $room_number = trim($_POST['room_number'] ?? '');   // NEW
            $day_number = is_numeric($_POST['day_number'] ?? null) ? (int)$_POST['day_number'] : null;
            $day_date = trim($_POST['day_date'] ?? '') ?: null;
            $description = trim($_POST['description'] ?? '');
            $day_id = isset($_POST['day_id']) && is_numeric($_POST['day_id']) ? (int)$_POST['day_id'] : null;
      $program_type = ($_POST['program_type'] ?? 'main') === 'sub' ? 'sub' : 'main'; // NEW

            if ($name === '') $errors[] = 'Name is required.';
            if ($room_number === '') $errors[] = 'Room number is required.'; // NEW
            if ($day_number === null) $errors[] = 'Day number is required.';
      if (empty($errors)) {
        // Insert initially for the selected (or null) day
        $stmt = $db->prepare("INSERT INTO function_rooms (name, label, room_number, day_number, day_date, description, day_id, program_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
          if ($day_id !== null && $day_id <= 0) $day_id = null;
          $stmt->bind_param('sssissis', $name, $label, $room_number, $day_number, $day_date, $description, $day_id, $program_type);
          if ($stmt->execute()) {
            $primaryId = $stmt->insert_id;
            $stmt->close();
            // Now replicate this function room across ALL days so it is linked to each day.
            if ($resDays = $db->query("SELECT id, label, day_date FROM function_room_days")) {
              // Prepare once for speed
              if ($insAll = $db->prepare("INSERT INTO function_rooms (name, label, room_number, day_number, day_date, description, day_id, program_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")) {
                while ($drow = $resDays->fetch_assoc()) {
                  $allDayId = (int)$drow['id'];
                  // Skip if this row already uses that day_id (avoid duplicate of initial if day_id chosen)
                  if ($day_id !== null && $allDayId === (int)$day_id) continue;
                  $tmpDayNumber = $day_number; // Keep same ordering number
                  $tmpDate = $drow['day_date'];
                  $insAll->bind_param('sssissis', $name, $label, $room_number, $tmpDayNumber, $tmpDate, $description, $allDayId, $program_type);
                  $insAll->execute();
                }
                $insAll->close();
              }
              $resDays->close();
            }
            $success = 'Function room added and linked to all days.';
          } else {
            $errors[] = 'Insert failed: ' . $stmt->error;
            $stmt->close();
          }
        } else {
          $errors[] = 'DB prepare failed: ' . $db->error;
        }
      }
    } elseif ($action === 'update') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $name = trim($_POST['name'] ?? '');
            $label = trim($_POST['label'] ?? '');
            $room_number = trim($_POST['room_number'] ?? '');   // NEW
            $day_number = is_numeric($_POST['day_number'] ?? null) ? (int)$_POST['day_number'] : null;
            $day_date = trim($_POST['day_date'] ?? '') ?: null;
            $description = trim($_POST['description'] ?? '');
            $day_id = isset($_POST['day_id']) && is_numeric($_POST['day_id']) ? (int)$_POST['day_id'] : null;
      $program_type = ($_POST['program_type'] ?? 'main') === 'sub' ? 'sub' : 'main'; // NEW

            if ($id <= 0) $errors[] = 'Invalid room id.';
            if ($name === '') $errors[] = 'Name is required.';
            if ($room_number === '') $errors[] = 'Room number is required.'; // NEW
            if ($day_number === null) $errors[] = 'Day number is required.';

      if (empty($errors)) {
        $stmt = $db->prepare("UPDATE function_rooms SET name = ?, label = ?, room_number = ?, day_number = ?, day_date = ?, description = ?, day_id = ?, program_type = ? WHERE id = ? LIMIT 1"); // NEW
                if ($stmt) {
                    if ($day_id !== null && $day_id <= 0) $day_id = null;
          $stmt->bind_param('sssissisi', $name, $label, $room_number, $day_number, $day_date, $description, $day_id, $program_type, $id); // FIXED
                    if ($stmt->execute()) {
                        $success = 'Function room updated.';
                    } else {
                        $errors[] = 'Update failed: ' . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $errors[] = 'DB prepare failed: ' . $db->error;
                }
            }
    } elseif ($action === 'delete') {
      $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
      if ($id <= 0) $errors[] = 'Invalid id for delete.';
      if (empty($errors)) {
        // Manual cascade delete (since schema likely lacks FK ON DELETE CASCADE)
        // Scope: speakers in this room, their quizzes, quiz responses, attendance, evaluations, sub session certificates.
        // We intentionally DO NOT delete user-level certificates (main program, etc.) because they are not tied strictly to a room.
        $db->begin_transaction();
        try {
          // Collect speaker IDs in this room
          $speakerIds = [];
          if ($resSp = $db->prepare('SELECT id FROM speakers WHERE room_id=?')) {
            $resSp->bind_param('i', $id);
            $resSp->execute();
            $resR = $resSp->get_result();
            while ($row = $resR->fetch_assoc()) { $speakerIds[] = (int)$row['id']; }
            $resSp->close();
          }

          // Collect quiz IDs either assigned directly to the room or to its speakers
          $quizIds = [];
          $quizWhere = 'room_id = ?';
          if (!empty($speakerIds)) {
            $in = implode(',', $speakerIds); // safe, ints only
            $quizWhere = "(room_id = ? OR speaker_id IN ($in))";
          }
          if ($resQ = $db->prepare("SELECT id FROM quizzes WHERE $quizWhere")) {
            $resQ->bind_param('i', $id);
            $resQ->execute();
            $qr = $resQ->get_result();
            while ($row = $qr->fetch_assoc()) { $quizIds[] = (int)$row['id']; }
            $resQ->close();
          }

          // Helper to run raw deletion (no user input variables aside from sanitized lists)
          $runRaw = function(string $sql) use ($db){ if(!$db->query($sql)){ throw new Exception('Delete step failed: '. $db->error); } };

          // Track counts for summary
          $counts = [
            'quiz_responses' => 0,
            'quizzes' => 0,
            'speaker_evaluations' => 0,
            'speaker_attendance' => 0,
            'sub_session_certificates' => 0,
            'speakers' => 0
          ];
          $subCertPaths = [];

          // Delete quiz responses first (depends on quiz_id & speaker_id & room_id)
          if (!empty($quizIds)) {
            $runRaw('DELETE FROM quiz_responses WHERE quiz_id IN (' . implode(',', $quizIds) . ')');
            $counts['quiz_responses'] += $db->affected_rows;
          }
          // Some responses might have been recorded referencing speaker only (defensive cleanup)
          if (!empty($speakerIds)) {
            $runRaw('DELETE FROM quiz_responses WHERE speaker_id IN (' . implode(',', $speakerIds) . ')');
            $counts['quiz_responses'] += $db->affected_rows;
          }

          // Delete quizzes
          if (!empty($quizIds)) {
            $runRaw('DELETE FROM quizzes WHERE id IN (' . implode(',', $quizIds) . ')');
            $counts['quizzes'] += $db->affected_rows;
          }

          // Delete speaker evaluations
          if (!empty($speakerIds)) {
            $runRaw('DELETE FROM speaker_evaluations WHERE speaker_id IN (' . implode(',', $speakerIds) . ')');
            $counts['speaker_evaluations'] += $db->affected_rows;
          }

          // Delete speaker attendance
          if (!empty($speakerIds)) {
            $runRaw('DELETE FROM speaker_attendance WHERE speaker_id IN (' . implode(',', $speakerIds) . ')');
            $counts['speaker_attendance'] += $db->affected_rows;
          }

          // Delete sub session certificates (issued per speaker session)
          if (!empty($speakerIds)) {
            // Collect file paths for unlink AFTER commit
            if ($resSC = $db->query('SELECT file_path FROM sub_session_certificates WHERE speaker_id IN (' . implode(',', $speakerIds) . ')')) {
              while ($sc = $resSC->fetch_assoc()) { if (!empty($sc['file_path'])) $subCertPaths[] = $sc['file_path']; }
              $resSC->close();
            }
            $runRaw('DELETE FROM sub_session_certificates WHERE speaker_id IN (' . implode(',', $speakerIds) . ')');
            $counts['sub_session_certificates'] += $db->affected_rows;
          }

          // Potential legacy/optional tables: quiz_questions referencing speaker_id
          if (!empty($speakerIds)) {
            // Only run if table exists
            if ($chk = $db->query("SHOW TABLES LIKE 'quiz_questions'")) { if ($chk->num_rows>0) { $runRaw('DELETE FROM quiz_questions WHERE speaker_id IN (' . implode(',', $speakerIds) . ')'); } $chk->close(); }
          }

          // Finally delete speakers themselves
          if (!empty($speakerIds)) {
            $runRaw('DELETE FROM speakers WHERE id IN (' . implode(',', $speakerIds) . ')');
            $counts['speakers'] += $db->affected_rows;
          }

          // Delete the function room
          if ($stmtDel = $db->prepare('DELETE FROM function_rooms WHERE id = ? LIMIT 1')) {
            $stmtDel->bind_param('i', $id);
            if (!$stmtDel->execute()) { throw new Exception('Function room delete failed: '. $stmtDel->error); }
            $stmtDel->close();
          } else {
            throw new Exception('Prepare failed deleting function room: '.$db->error);
          }

          $db->commit();
          // Attempt filesystem cleanup (outside transaction)
          if (!empty($subCertPaths)) {
            foreach ($subCertPaths as $fp) {
              // Basic safety: only allow relative paths under project
              if (strpos($fp, '..') === false && is_file($fp)) { @unlink($fp); }
            }
          }
          $success = 'Function room and related data deleted.';
          // Append counts summary (only show non-zero)
          $parts = [];
          foreach ($counts as $k=>$v) { if ($v>0) { $parts[] = str_replace('_',' ', $k).': '.$v; } }
          if ($parts) { $success .= ' ('.implode('; ', $parts).')'; }
        } catch (Exception $ex) {
          $db->rollback();
          $errors[] = 'Cascade delete failed: ' . $ex->getMessage();
        }
      }
    } else {
            $errors[] = 'Unknown action.';
        }
    }

    // regenerate token and redirect to avoid form resubmission and keep messages
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    $_SESSION['mf_messages'] = ['errors' => $errors, 'success' => $success];
    header('Location: admin_manage_function_rooms.php');
    exit;
}

// show messages if any
$messages = $_SESSION['mf_messages'] ?? null;
if ($messages) {
    $errors = array_merge($errors, $messages['errors'] ?? []);
    if (!empty($messages['success'])) $success = $messages['success'];
    unset($_SESSION['mf_messages']);
}

// fetch rooms list
$rooms = [];
// Ensure program_type column exists
$cRes = $db->query("SHOW COLUMNS FROM function_rooms LIKE 'program_type'");
if ($cRes && $cRes->num_rows === 0) {
  @ $db->query("ALTER TABLE function_rooms ADD COLUMN program_type ENUM('main','sub') NOT NULL DEFAULT 'main'");
}
$cRes && $cRes->close();

$res = $db->query("SELECT id, name, label, room_number, day_number, day_date, description, day_id, program_type FROM function_rooms ORDER BY day_number ASC, id ASC"); // NEW
if ($res) {
    while ($r = $res->fetch_assoc()) $rooms[] = $r;
}

// load single room for edit if ?edit=id
$editRoom = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
  $stmt = $db->prepare("SELECT id, name, label, room_number, day_number, day_date, description, day_id, program_type FROM function_rooms WHERE id = ? LIMIT 1"); // NEW
    if ($stmt) {
        $stmt->bind_param('i', $eid);
        $stmt->execute();
        $editRoom = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Manage Function Rooms</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
      --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
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
      padding: 0.5rem 1rem;
      transition: all 0.2s ease;
      border: none;
    }
    
    .btn-primary {
      background: var(--primary-gradient);
    }
    
    .btn-success {
      background: var(--success-gradient);
    }
    
    .btn:hover {
      transform: translateY(-1px);
      box-shadow: var(--shadow-hover);
    }
    
    .table {
      border-radius: var(--border-radius);
      overflow: hidden;
    }
    
    .table thead th {
      background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
      border: none;
      font-weight: 600;
      color: #495057;
      padding: 1rem 0.75rem;
    }
    
    .table tbody tr {
      transition: all 0.2s ease;
    }
    
    .table tbody tr:hover {
      background: rgba(102, 126, 234, 0.05);
      transform: scale(1.01);
    }
    
    .form-control, .form-select {
      border: 2px solid rgba(0, 0, 0, 0.05);
      border-radius: 8px;
      padding: 0.75rem;
      transition: all 0.2s ease;
    }
    
    .form-control:focus, .form-select:focus {
      border-color: #667eea;
      box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.15);
    }
    
    .badge {
      padding: 0.5rem 0.75rem;
      font-weight: 500;
      border-radius: 20px;
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
    
    .stats-card {
      background: var(--primary-gradient);
      color: white;
      text-align: center;
      padding: 1.5rem;
      border-radius: var(--border-radius);
      margin-bottom: 1.5rem;
    }
    
    .stats-number {
      font-size: 2rem;
      font-weight: 700;
      margin-bottom: 0.5rem;
    }
    
    .function-card {
      transition: all 0.3s ease;
      border: 1px solid rgba(0, 0, 0, 0.08);
    }
    
    .function-card:hover {
      transform: translateY(-3px);
      box-shadow: var(--shadow-hover);
    }
    
    .btn-gradient-primary {
      background: var(--primary-gradient);
      border: none;
      color: white;
      transition: all 0.3s ease;
    }
    
    .btn-gradient-primary:hover {
      transform: translateY(-1px);
      box-shadow: var(--shadow-soft);
      color: white;
    }
    
    .btn-gradient-success {
      background: var(--success-gradient);
      border: none;
      color: white;
      transition: all 0.3s ease;
    }
    
    .btn-gradient-success:hover {
      transform: translateY(-1px);
      box-shadow: var(--shadow-soft);
      color: white;
    }
    
    .btn-gradient-warning {
      background: var(--warning-gradient);
      border: none;
      color: white;
      transition: all 0.3s ease;
    }
    
    .btn-gradient-warning:hover {
      transform: translateY(-1px);
      box-shadow: var(--shadow-soft);
      color: white;
    }
    
    .btn-gradient-danger {
      background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
      border: none;
      color: white;
      transition: all 0.3s ease;
    }
    
    .btn-gradient-danger:hover {
      transform: translateY(-1px);
      box-shadow: var(--shadow-soft);
      color: white;
    }
    
    .table-hover tbody tr:hover {
      background-color: rgba(102, 126, 234, 0.05);
      transform: scale(1.01);
      transition: all 0.2s ease;
    }
    
    .badge {
      font-weight: 500;
      border-radius: 20px;
    }
    
    .bg-gradient-primary {
      background: var(--primary-gradient) !important;
    }
    
    .bg-gradient-warning {
      background: var(--warning-gradient) !important;
    }
  </style>
</head>
<body>
<div class="container-fluid">
  <div class="main-container">
    <div class="page-header">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <h1 class="page-title"><i class="bi bi-door-open me-2"></i>Function Rooms</h1>
          <p class="page-subtitle">Manage and organize function rooms across all days</p>
        </div>
        <a href="Admin_dashboard.php" class="btn btn-light btn-lg">
          <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
        </a>
      </div>
    </div>
    
    <div class="p-4">
      <!-- Stats Card -->
      <div class="row mb-4">
        <div class="col-md-3">
          <div class="stats-card">
            <div class="stats-number"><?= count($rooms) ?></div>
            <div>Total Rooms</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="stats-card" style="background: var(--success-gradient);">
            <div class="stats-number"><?= count(array_filter($rooms, fn($r) => ($r['program_type'] ?? 'main') === 'main')) ?></div>
            <div>Main Program</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="stats-card" style="background: var(--warning-gradient);">
            <div class="stats-number"><?= count(array_filter($rooms, fn($r) => ($r['program_type'] ?? 'main') === 'sub')) ?></div>
            <div>Sub Program</div>
          </div>
        </div>
        <div class="col-md-3">
          <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
            <div class="stats-number"><?= count($days) ?></div>
            <div>Active Days</div>
          </div>
        </div>
      </div>

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

      <!-- Function Rooms List -->
      <div class="row">
        <div class="col-lg-8">
          <div class="card function-card">
            <div class="card-header">
              <div class="d-flex align-items-center">
                <i class="bi bi-list-ul me-2 text-primary fs-5"></i>
                <h5 class="card-title mb-0">Existing Function Rooms</h5>
                <span class="badge bg-secondary ms-auto"><?= count($rooms) ?> rooms</span>
              </div>
            </div>
            <div class="card-body p-0">
              <?php if (empty($rooms)): ?>
                <div class="text-center text-muted py-5">
                  <i class="bi bi-inbox fs-1 mb-3 d-block text-light"></i>
                  <h6>No function rooms found</h6>
                  <p class="mb-0">Create your first function room using the form.</p>
                </div>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-hover mb-0">
                    <thead class="table-light">
                      <tr>
                        <th class="fw-semibold">#</th>
                        <th class="fw-semibold"><i class="bi bi-building me-1"></i>Name</th>
                        <th class="fw-semibold"><i class="bi bi-tag me-1"></i>Label</th>
                        <th class="fw-semibold"><i class="bi bi-door-closed me-1"></i>Room No.</th>
                        <th class="fw-semibold"><i class="bi bi-diagram-3 me-1"></i>Program</th>
                        <th class="fw-semibold"><i class="bi bi-calendar-day me-1"></i>Day</th>
                        <th class="fw-semibold"><i class="bi bi-calendar me-1"></i>Date</th>
                        <th class="fw-semibold"><i class="bi bi-link me-1"></i>Linked</th>
                        <th class="fw-semibold text-center">Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($rooms as $r): ?>
                        <tr>
                          <td class="text-muted"><?= (int)$r['id'] ?></td>
                          <td class="fw-medium"><?= e($r['name']) ?></td>
                          <td><?= e($r['label']) ?></td>
                          <td><span class="badge bg-light text-dark"><?= e($r['room_number']) ?></span></td>
                          <td>
                            <span class="badge bg-gradient-<?php echo ($r['program_type']??'main')==='sub'?'warning':'primary'; ?> d-flex align-items-center" style="width: fit-content;">
                              <?php if (($r['program_type']??'main')==='sub'): ?>
                                <i class="bi bi-diagram-2 me-1"></i>SUB
                              <?php else: ?>
                                <i class="bi bi-house me-1"></i>MAIN
                              <?php endif; ?>
                            </span>
                          </td>
                          <td><strong><?= e($r['day_number']) ?></strong></td>
                          <td class="text-muted"><?= e($r['day_date']) ?: 'Not set' ?></td>
                          <td>
                            <?php if ($r['day_id']): ?>
                              <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>#<?= (int)$r['day_id'] ?></span>
                            <?php else: ?>
                              <span class="badge bg-light text-muted">None</span>
                            <?php endif; ?>
                          </td>
                          <td>
                            <div class="btn-group-actions justify-content-center">
                              <a href="admin_manage_function_rooms.php?edit=<?= (int)$r['id'] ?>" 
                                 class="btn btn-sm btn-gradient-primary" 
                                 title="Edit Room">
                                <i class="bi bi-pencil"></i>
                              </a>
                              <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this room? This action cannot be undone.');">
                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-gradient-danger" title="Delete Room">
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
          <div class="card function-card">
            <div class="card-header">
              <?php if ($editRoom): ?>
                <div class="d-flex align-items-center">
                  <i class="bi bi-pencil-square me-2 text-warning fs-5"></i>
                  <h5 class="card-title mb-0">Edit Function Room</h5>
                  <span class="badge bg-warning ms-auto">#<?= (int)$editRoom['id'] ?></span>
                </div>
              <?php else: ?>
                <div class="d-flex align-items-center">
                  <i class="bi bi-plus-circle me-2 text-success fs-5"></i>
                  <h5 class="card-title mb-0">Add Function Room</h5>
                </div>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <form method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <?php if ($editRoom): ?>
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="id" value="<?= (int)$editRoom['id'] ?>">
                <?php else: ?>
                  <input type="hidden" name="action" value="add">
                <?php endif; ?>

                <div class="mb-3">
                  <label class="form-label fw-semibold">
                    <i class="bi bi-building me-1 text-primary"></i>Room Name
                  </label>
                  <input name="name" class="form-control" required 
                         value="<?= $editRoom ? e($editRoom['name']) : '' ?>"
                         placeholder="Enter room name">
                </div>

                <div class="mb-3">
                  <label class="form-label fw-semibold">
                    <i class="bi bi-tag me-1 text-secondary"></i>Label
                  </label>
                  <input name="label" class="form-control" 
                         value="<?= $editRoom ? e($editRoom['label']) : '' ?>"
                         placeholder="Short label/code">
                </div>

                <div class="mb-3">
                  <label class="form-label fw-semibold">
                    <i class="bi bi-door-closed me-1 text-info"></i>Room Number
                  </label>
                  <input name="room_number" class="form-control" required 
                         value="<?= $editRoom ? e($editRoom['room_number']) : '' ?>"
                         placeholder="e.g., A-101, B-205">
                </div>

                <div class="row mb-3">
                  <div class="col-md-6">
                    <label class="form-label fw-semibold">
                      <i class="bi bi-calendar-day me-1 text-warning"></i>Day Number
                    </label>
                    <input name="day_number" type="number" min="1" class="form-control" required 
                           value="<?= $editRoom ? e($editRoom['day_number']) : '' ?>"
                           placeholder="1, 2, 3...">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label fw-semibold">
                      <i class="bi bi-calendar me-1 text-success"></i>Date
                    </label>
                    <input name="day_date" type="date" class="form-control" 
                           value="<?= $editRoom ? e($editRoom['day_date']) : '' ?>">
                  </div>
                </div>

                <div class="mb-3">
                  <label class="form-label fw-semibold">
                    <i class="bi bi-diagram-3 me-1 text-purple"></i>Program Type
                  </label>
                  <select name="program_type" class="form-select" required>
                    <option value="main" <?= (!$editRoom || ($editRoom['program_type'] ?? 'main')==='main') ? 'selected' : '' ?>>
                      🏢 Main Program
                    </option>
                    <option value="sub" <?= ($editRoom && ($editRoom['program_type'] ?? 'main')==='sub') ? 'selected' : '' ?>>
                      🏗️ Sub Program
                    </option>
                  </select>
                </div>

                <div class="mb-3">
                  <label class="form-label fw-semibold">
                    <i class="bi bi-link me-1 text-primary"></i>Linked Day
                  </label>
                  <select name="day_id" class="form-select">
                    <option value="">No specific day link</option>
                    <?php foreach ($days as $d): ?>
                      <option value="<?= (int)$d['id'] ?>" 
                              <?= ($editRoom && (int)$editRoom['day_id'] === (int)$d['id']) ? 'selected' : '' ?>>
                        <?= e($d['label']) ?><?= $d['day_date'] ? ' - ' . e($d['day_date']) : '' ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <small class="form-text text-muted mt-1">
                    <i class="bi bi-info-circle me-1"></i>Links room to specific day for filtering
                  </small>
                </div>

                <div class="mb-4">
                  <label class="form-label fw-semibold">
                    <i class="bi bi-card-text me-1 text-secondary"></i>Description
                  </label>
                  <textarea name="description" class="form-control" rows="3" 
                            placeholder="Optional room description or notes"><?= $editRoom ? e($editRoom['description']) : '' ?></textarea>
                </div>

                <div class="d-grid gap-2">
                  <?php if ($editRoom): ?>
                    <button type="submit" class="btn btn-gradient-warning btn-lg">
                      <i class="bi bi-check-lg me-2"></i>Update Room
                    </button>
                    <a href="admin_manage_function_rooms.php" class="btn btn-outline-secondary">
                      <i class="bi bi-arrow-left me-2"></i>Cancel Edit
                    </a>
                  <?php else: ?>
                    <button type="submit" class="btn btn-gradient-success btn-lg">
                      <i class="bi bi-plus-lg me-2"></i>Add Room
                    </button>
                  <?php endif; ?>
                </div>
              </form>
            </div>
          </div>

          <!-- Help Card -->
          <div class="card function-card mt-3">
            <div class="card-body text-center">
              <i class="bi bi-lightbulb text-warning fs-3 mb-2"></i>
              <h6 class="fw-semibold mb-2">Quick Tips</h6>
              <ul class="list-unstyled text-muted small">
                <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i>Rooms are auto-linked to all days when created</li>
                <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i>Use meaningful room numbers for easy identification</li>
                <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i>Assign speakers after creating rooms</li>
              </ul>
              <hr class="my-2">
              <a href="admin_speaker_manage.php" class="btn btn-sm btn-gradient-primary">
                <i class="bi bi-person-plus me-1"></i>Manage Speakers
              </a>
            </div>
          </div>
        </div>
    </div>
  </div>
</div>

</body>
</html>