<?php
session_start();
require_once __DIR__ . '/../db.php';
if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); } }
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// Simple CSRF token
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token']=bin2hex(random_bytes(16)); }
$csrfToken = $_SESSION['csrf_token'];

// Attendance submission handling
$attendanceMsg = null; $attendanceOk = false;
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['attendance_submit'])) {
  if ($userId <= 0) {
    header('Location: ../Credentials/login.php?redirect=' . urlencode('dashboard/programs.php?day_id='.(int)($_POST['day_id'] ?? 0)) );
    exit;
  }
  if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    $attendanceMsg = ['type'=>'danger','text'=>'Security token mismatch. Please refresh and try again.'];
  } else {
    $postedDayId = isset($_POST['day_id']) ? (int)$_POST['day_id'] : 0;
    // We'll recompute positional day_number below after loading days; store raw for reuse later in page
    $_GET['day_id'] = (string)$postedDayId; // so existing logic continues
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $prcNo = trim((string)($_POST['prc_license_number'] ?? ''));
    $prcReg = trim((string)($_POST['prc_registration_date'] ?? ''));
    $prcExp = trim((string)($_POST['prc_license_expiry'] ?? ''));
    $prcType= trim((string)($_POST['prc_license_type'] ?? ''));
    $contact= trim((string)($_POST['contact_number'] ?? ''));
    if ($fullName === '') {
      $attendanceMsg = ['type'=>'danger','text'=>'Full name is required.'];
    } else if (!($conn instanceof mysqli)) {
      $attendanceMsg = ['type'=>'danger','text'=>'Database unavailable.'];
    } else {
      // We need day_number (positional) after we have loaded days list (done later) – temporary store values for later insert attempt
      $_SESSION['_att_pending'] = [
        'day_id'=>$postedDayId,
        'full_name'=>$fullName,
        'prc_license_number'=>$prcNo,
        'prc_registration_date'=>$prcReg,
        'prc_license_expiry'=>$prcExp,
        'prc_license_type'=>$prcType,
        'contact_number'=>$contact
      ];
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Programs - 73rd PSME National Convention</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
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
 body { background: var(--primary-color); color:#fff; font-family:'Poppins',sans-serif; padding-top:70px; }
  .program-card { background:rgba(255,255,255,0.1); border:1px solid rgba(255,255,255,0.25); border-radius:14px; padding:28px 24px; transition:.3s; cursor:pointer; height:100%; position:relative; overflow:hidden; }
  .program-card:hover { transform:translateY(-6px); background:rgba(255,255,255,0.18); }
  .program-card h3 { font-weight:600; font-size:1.4rem; }
  .gradient-text { background: linear-gradient(135deg,#fff,#dbeafe); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
  .stretched-link { position:absolute; inset:0; z-index:3; }
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
            <a class="nav-link fw-semibold px-3" href="dashboard.php">&lt; Back to Home</a>
          </li>
        </ul>
      </div>
    </div>
  </nav>

<div class="container py-5">
  <h1 class="display-6 fw-bold text-center mb-5 gradient-text">CONVENTION DAYS</h1>
  <?php
    $passDayId = isset($_GET['day_id']) ? (int)$_GET['day_id'] : 0;
    $dayValid = true;
    $days = [];
    // Fetch days for fallback selection if no day provided or invalid
    if ($conn instanceof mysqli) {
      if ($st = $conn->prepare('SELECT id,label,day_date,is_enabled FROM function_room_days ORDER BY day_date ASC')) {
        $st->execute(); $res=$st->get_result(); while($row=$res->fetch_assoc()){ $days[]=$row; } $st->close();
      }
      if ($passDayId > 0) {
        $found = false; foreach($days as $d){ if ((int)$d['id']===$passDayId){ $found=true; break; } }
        if (!$found) { $dayValid=false; $passDayId=0; }
      }
    }
  // Map day_id to positional day_number (1-based) & detect user attendance
  $positionalDayNum = null; $userHasAttendance=false; $selectedDayIdForAttendance=null;
  if ($passDayId>0) {
    foreach($days as $idx=>$d){ if ((int)$d['id']===$passDayId){ $positionalDayNum = $idx+1; $selectedDayIdForAttendance=$passDayId; break; } }
    if ($userId>0 && ($conn instanceof mysqli) && $positionalDayNum!==null) {
      if ($st = $conn->prepare('SELECT 1 FROM attendance WHERE user_id=? AND day_number=? LIMIT 1')) {
        $st->bind_param('ii',$userId,$positionalDayNum); $st->execute(); $st->bind_result($dummy); if ($st->fetch()) $userHasAttendance=true; $st->close();
      }
    }
  }

  // If pending attendance data stored earlier, attempt insert now (after we know positional day number)
  if (!$attendanceOk && isset($_SESSION['_att_pending'])) {
    $pending = $_SESSION['_att_pending'];
    if (($pending['day_id'] ?? 0) == $passDayId && $positionalDayNum!==null && $userId>0 && ($conn instanceof mysqli)) {
      if (!$userHasAttendance) {
        if ($st = $conn->prepare('INSERT INTO attendance (user_id, day_number, full_name, prc_license_number, prc_registration_date, prc_license_expiry, prc_license_type, contact_number) VALUES (?,?,?,?,NULLIF(?,\'\'),NULLIF(?,\'\'),NULLIF(?,\'\'),NULLIF(?,\'\'))')) {
          $st->bind_param('iissssss', $userId, $positionalDayNum, $pending['full_name'], $pending['prc_license_number'], $pending['prc_registration_date'], $pending['prc_license_expiry'], $pending['prc_license_type'], $pending['contact_number']);
          if ($st->execute()) {
            $attendanceOk=true; $attendanceMsg=['type'=>'success','text'=>'Attendance recorded.']; $userHasAttendance=true;
          } else {
            if ($conn->errno==1062) { $attendanceMsg=['type'=>'warning','text'=>'You already marked attendance for this day.']; $userHasAttendance=true; }
            else { $attendanceMsg=['type'=>'danger','text'=>'Failed to save attendance.']; }
          }
          $st->close();
        }
      } else {
        $attendanceMsg = $attendanceMsg ?? ['type'=>'warning','text'=>'Attendance already recorded.'];
      }
    }
    unset($_SESSION['_att_pending']);
  }
  ?>
  <?php if ($passDayId===0): ?>
    <div class="alert alert-info text-center small mb-4">Select a Day first to view Main or Sub Program sessions.</div>
    <div class="row g-3 justify-content-center mb-5">
      <?php foreach ($days as $d): $enabled=(int)$d['is_enabled']===1; ?>
        <div class="col-sm-6 col-md-4 col-lg-3">
          <div class="program-card" style="padding:18px;" data-day-id="<?= (int)$d['id'] ?>" data-enabled="<?= $enabled?1:0 ?>">
            <div class="stretched-link" onclick="selectDay(<?= (int)$d['id'] ?>, <?= $enabled? 'true':'false' ?>)"></div>
            <h5 class="mb-1"><?= htmlspecialchars($d['label']) ?></h5>
            <div class="small opacity-75 mb-1"><?= (new DateTime($d['day_date']))->format('M j, Y') ?></div>
            <span class="badge bg-<?= $enabled? 'success':'secondary' ?>"><?= $enabled? 'Active':'Locked' ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php 
    // Resolve positional day number (1-based by ordering) for display
    $selectedDayNumber = null;
    if ($passDayId > 0) {
      foreach ($days as $idx => $d) { if ((int)$d['id'] === $passDayId) { $selectedDayNumber = $idx + 1; break; } }
    }
  ?>
  <?php if ($passDayId>0): ?>
    <div class="text-center mb-5">
      <h5 class="fw-semibold mb-1 text-uppercase">SELECTED DAY <?= e($selectedDayNumber ?? $passDayId) ?></h5>
      <div class="mb-4 small opacity-75 text-uppercase" style="letter-spacing:1px;">CHOOSE PROGRAM</div>
      <?php if ($attendanceMsg): ?>
        <div class="alert alert-<?= e($attendanceMsg['type']) ?> py-2 small mx-auto" style="max-width:520px;"><?= e($attendanceMsg['text']) ?></div>
      <?php endif; ?>
      <?php if ($userId>0 && !$userHasAttendance && $positionalDayNum!==null): ?>
        <div class="mb-3">
          <button class="btn btn-outline-light btn-sm" data-bs-toggle="modal" data-bs-target="#attendanceModal">Mark Attendance for Day <?= (int)$positionalDayNum ?></button>
        </div>
      <?php elseif ($userId>0 && $userHasAttendance): ?>
        <div class="mb-3 small text-success fw-semibold">Attendance already recorded for this day.</div>
      <?php else: ?>
        <div class="mb-3 small text-warning">Login to mark attendance for this day.</div>
      <?php endif; ?>
      <div class="row g-4 justify-content-center">
        <div class="col-md-5 col-lg-4">
          <div class="program-card position-relative" style="border:1px solid rgba(37,99,235,0.35);">
            <div class="stretched-link" onclick="location.href='main_program_day.php?day_id=<?= (int)$passDayId ?>'"></div>
            <h3 class="mb-2" style="font-size:1.15rem;">Main Program Sessions</h3>
            <p class="mb-0 small opacity-75">Rooms & speakers schedule for this convention day.</p>
            <span class="badge bg-primary mt-3">Main</span>
          </div>
        </div>
        <div class="col-md-5 col-lg-4">
          <div class="program-card position-relative" style="border:1px solid rgba(245,158,11,0.45);">
            <div class="stretched-link" onclick="location.href='sub_program_day.php?day_id=<?= (int)$passDayId ?>'"></div>
            <h3 class="mb-2" style="font-size:1.15rem;">Sub Program Sessions</h3>
            <p class="mb-0 small opacity-75">Department / track based parallel sessions.</p>
            <span class="badge bg-warning text-dark mt-3">Sub</span>
          </div>
        </div>
      </div>
      <div class="mt-4"><a class="link-light small text-decoration-underline" href="programs.php">Change Day</a></div>
    </div>
  <?php endif; ?>
  <script>
    function selectDay(id, enabled){
      if(!enabled) return;
      window.location = 'programs.php?day_id=' + id;
    }
    // Auto-open attendance modal if day selected, user logged in, and no attendance yet
    <?php if ($passDayId>0 && $userId>0 && !$userHasAttendance && $positionalDayNum!==null && !$attendanceOk && empty($attendanceMsg)): ?>
      document.addEventListener('DOMContentLoaded', ()=>{
        const modalEl = document.getElementById('attendanceModal');
        if (modalEl) {
          const m = new bootstrap.Modal(modalEl);
          m.show();
        }
      });
    <?php endif; ?>
  </script>
</div>
<?php if ($passDayId>0): ?>
<!-- Attendance Modal -->
<div class="modal fade" id="attendanceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="background:#0b2a59;color:#fff;">
      <div class="modal-header border-0">
        <h6 class="modal-title">Day <?= e($positionalDayNum ?? '?') ?> Attendance</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" style="background:#103b74;">
        <?php if ($userId<=0): ?>
          <div class="alert alert-warning small">Please log in to mark attendance.</div>
        <?php elseif ($userHasAttendance): ?>
          <div class="alert alert-success small">You already marked attendance for this day.</div>
        <?php else: ?>
          <form method="post" class="small" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="day_id" value="<?= (int)$passDayId ?>">
            <input type="hidden" name="attendance_submit" value="1">
            <div class="mb-2">
              <label class="form-label">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="full_name" class="form-control form-control-sm" required>
            </div>
            <div class="mb-2">
              <label class="form-label">PRC License Number</label>
              <input type="text" name="prc_license_number" class="form-control form-control-sm" placeholder="Optional">
            </div>
            <div class="row g-2">
              <div class="col-6">
                <label class="form-label">Registration Date</label>
                <input type="date" name="prc_registration_date" class="form-control form-control-sm">
              </div>
              <div class="col-6">
                <label class="form-label">License Expiry</label>
                <input type="date" name="prc_license_expiry" class="form-control form-control-sm">
              </div>
            </div>
            <div class="mb-2 mt-2">
              <label class="form-label">License Type</label>
              <input type="text" name="prc_license_type" class="form-control form-control-sm" placeholder="Mechanical Engineer">
            </div>
            <div class="mb-3">
              <label class="form-label">Contact Number</label>
              <input type="text" name="contact_number" class="form-control form-control-sm" placeholder="09xxxxxxxxx">
            </div>
            <div class="d-flex justify-content-end gap-2">
              <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-sm btn-primary">Save Attendance</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
