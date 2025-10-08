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
    // New separated name fields
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $middleName = trim((string)($_POST['middle_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    // PRC / License related
    $prcNo = preg_replace('/\D+/', '', (string)($_POST['prc_license_number'] ?? '')); // digits only
    $birthday = trim((string)($_POST['birthday'] ?? ''));
    $prcExp = trim((string)($_POST['prc_license_expiry'] ?? ''));
    $prcType= trim((string)($_POST['prc_license_type'] ?? ''));
    $contact= trim((string)($_POST['contact_number'] ?? ''));
    if ($firstName === '' || $lastName === '') {
      $attendanceMsg = ['type'=>'danger','text'=>'First and Last name are required.'];
    } elseif (!preg_match('/^\d{1,7}$/', $prcNo)) {
      $attendanceMsg = ['type'=>'danger','text'=>'PRC License Number must be 1 to 7 digits.'];
    } else if (!($conn instanceof mysqli)) {
      $attendanceMsg = ['type'=>'danger','text'=>'Database unavailable.'];
    } else {
      // Compose full name (UPPERCASE) "LAST NAME, FIRST NAME MIDDLE NAME"
      $fullNameParts = [];
      // Format as FIRST MIDDLE LAST (or any order requested). We'll use FIRST MIDDLE LAST for clarity
      $combined = trim($firstName . ' ' . ($middleName ? $middleName . ' ' : '') . $lastName);
      $fullName = strtoupper($combined);
      // We need day_number (positional) after we have loaded days list (done later) – temporary store values for later insert attempt
      $_SESSION['_att_pending'] = [
        'day_id'=>$postedDayId,
        'full_name'=>$fullName,
        'first_name'=>strtoupper($firstName),
        'middle_name'=>strtoupper($middleName),
        'last_name'=>strtoupper($lastName),
        'prc_license_number'=>$prcNo,
        'birthday'=>$birthday,
        'prc_license_expiry'=>$prcExp,
        'prc_license_type'=>strtoupper($prcType),
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

  // Migration: rename prc_registration_date -> birthday if not already done
  if ($conn instanceof mysqli) {
    $colBirthday = $conn->query("SHOW COLUMNS FROM attendance LIKE 'birthday'");
    if ($colBirthday && $colBirthday->num_rows === 0) {
      $colReg = $conn->query("SHOW COLUMNS FROM attendance LIKE 'prc_registration_date'");
      if ($colReg && $colReg->num_rows === 1) {
        // Attempt rename (suppress errors to avoid fatal in production)
        @$conn->query("ALTER TABLE attendance CHANGE prc_registration_date birthday DATE NULL DEFAULT NULL");
      }
      $colReg && $colReg->close();
    }
    $colBirthday && $colBirthday->close();

    // Migration: replace full_name with first_name/middle_name/last_name if not yet migrated
    $needNameMigration = false; $hasFullNameCol = false; $hasFirstNameCol = false;
    if ($r = $conn->query("SHOW COLUMNS FROM attendance LIKE 'full_name'")) { $hasFullNameCol = $r->num_rows>0; $r->close(); }
    if ($r = $conn->query("SHOW COLUMNS FROM attendance LIKE 'first_name'")) { $hasFirstNameCol = $r->num_rows>0; $r->close(); }
    if ($hasFullNameCol && !$hasFirstNameCol) { $needNameMigration = true; }
    if ($needNameMigration) {
      // Add new columns
      @ $conn->query("ALTER TABLE attendance ADD COLUMN first_name VARCHAR(120) NULL AFTER day_number");
      @ $conn->query("ALTER TABLE attendance ADD COLUMN middle_name VARCHAR(120) NULL AFTER first_name");
      @ $conn->query("ALTER TABLE attendance ADD COLUMN last_name VARCHAR(120) NULL AFTER middle_name");
      // Migrate existing data from full_name (basic splitting: first token, last token, middle rest)
      if ($resMig = $conn->query("SELECT id, full_name FROM attendance WHERE full_name IS NOT NULL AND full_name <> ''")) {
        if ($stmtUpd = $conn->prepare("UPDATE attendance SET first_name=?, middle_name=?, last_name=? WHERE id=?")) {
          while ($rowMig = $resMig->fetch_assoc()) {
            $fid = (int)$rowMig['id'];
            $fnRaw = trim($rowMig['full_name']);
            if ($fnRaw==='') continue;
            $parts = preg_split('/\s+/', $fnRaw);
            $first = strtoupper(array_shift($parts));
            $last = strtoupper(count($parts)>0 ? array_pop($parts) : '');
            $middle = strtoupper(implode(' ', $parts));
            if ($first==='') $first = $last; // fallback
            $stmtUpd->bind_param('sssi', $first, $middle, $last, $fid);
            $stmtUpd->execute();
          }
          $stmtUpd->close();
        }
        $resMig->close();
      }
      // Drop old column
      @ $conn->query("ALTER TABLE attendance DROP COLUMN full_name");
    }
  }

  // If pending attendance data stored earlier, attempt insert now (after we know positional day number)
  if (!$attendanceOk && isset($_SESSION['_att_pending'])) {
    $pending = $_SESSION['_att_pending'];
    if (($pending['day_id'] ?? 0) == $passDayId && $positionalDayNum!==null && $userId>0 && ($conn instanceof mysqli)) {
      if (!$userHasAttendance) {
        // Insert using birthday column (after migration). If birthday column missing fallback to prc_registration_date.
        $hasBirthdayCol = false;
        if ($rCol = $conn->query("SHOW COLUMNS FROM attendance LIKE 'birthday'")) { $hasBirthdayCol = $rCol->num_rows>0; $rCol->close(); }
        $birthdayColName = $hasBirthdayCol ? 'birthday' : 'prc_registration_date';
        // Determine if new name columns exist
        $hasFirstNameCol = false; if ($rCol2 = $conn->query("SHOW COLUMNS FROM attendance LIKE 'first_name'")) { $hasFirstNameCol = $rCol2->num_rows>0; $rCol2->close(); }
        if ($hasFirstNameCol) {
          $sqlIns = "INSERT INTO attendance (user_id, day_number, first_name, middle_name, last_name, prc_license_number, $birthdayColName, prc_license_expiry, prc_license_type, contact_number) VALUES (?,?,?,?,?,NULLIF(?,''),NULLIF(?,''),NULLIF(?,''),NULLIF(?,''),NULLIF(?,''))";
          if ($st = $conn->prepare($sqlIns)) {
            $st->bind_param('iissssssss', $userId, $positionalDayNum, $pending['first_name'], $pending['middle_name'], $pending['last_name'], $pending['prc_license_number'], $pending['birthday'], $pending['prc_license_expiry'], $pending['prc_license_type'], $pending['contact_number']);
          }
        } else {
          // Fallback to legacy full_name column path (should rarely execute after migration)
          $sqlIns = "INSERT INTO attendance (user_id, day_number, full_name, prc_license_number, $birthdayColName, prc_license_expiry, prc_license_type, contact_number) VALUES (?,?,?,?,NULLIF(?,''),NULLIF(?,''),NULLIF(?,''),NULLIF(?,''))";
          if ($st = $conn->prepare($sqlIns)) {
            $st->bind_param('iissssss', $userId, $positionalDayNum, $pending['full_name'], $pending['prc_license_number'], $pending['birthday'], $pending['prc_license_expiry'], $pending['prc_license_type'], $pending['contact_number']);
          }
        }
        if ($st) {
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
    <!-- Overall Main Program Progress (relocated below day cards) -->
    <div id="overallProgress" class="mb-5 d-none" style="max-width:900px;margin-left:auto;margin-right:auto;">
      <div class="p-4 rounded-4" style="background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.25);">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-2">
          <h4 class="fw-bold mb-2 mb-sm-0" style="font-size:1.15rem;">Main Program Overall Progress</h4>
          <div class="small opacity-75" id="overallEvalText">Loading...</div>
        </div>
        <div class="small mb-2 opacity-50" id="overallQuizText"></div>
        <div class="progress mb-3" style="height:10px;">
          <div id="overallBar" class="progress-bar bg-success" style="width:0%"></div>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <button id="btnGenerateOverall" class="btn btn-warning btn-sm fw-semibold px-3 rounded-pill d-none" type="button">Generate Main Program Certificate</button>
          <button id="btnEmailOverall" class="btn btn-outline-light btn-sm rounded-pill d-none" type="button">Email Certificate</button>
          <button id="btnGenerateAttendance" class="btn btn-info btn-sm fw-semibold px-3 rounded-pill d-none" type="button">Generate Attendance Certificate</button>
          <button id="btnEmailAttendance" class="btn btn-outline-light btn-sm rounded-pill d-none" type="button">Email Attendance</button>
          <button id="btnGenerateAll" class="btn btn-success btn-sm fw-semibold px-3 rounded-pill d-none" type="button">Generate All</button>
          <button id="btnRefreshOverall" class="btn btn-outline-secondary btn-sm rounded-pill" type="button">Refresh</button>
        </div>
        <div class="small opacity-50 mt-2" id="overallAttendanceText"></div>
        <div id="overallAlert" class="small mt-3"></div>
      </div>
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
    // Overall progress logic
    async function loadOverall(){
      try {
        const [rComp, rAtt] = await Promise.all([
          fetch('../api/main_program_completion.php',{credentials:'same-origin'}),
          fetch('../api/main_program_attendance_certificate.php?progress=1',{credentials:'same-origin'})
        ]);
        const data = await rComp.json();
        let attData = {};
        try { attData = await rAtt.json(); } catch(e){}
        if(!data || !data.ok) return;
        const wrap = document.getElementById('overallProgress');
        wrap.classList.remove('d-none');
        const evalPct = data.eval_total>0 ? Math.round(data.eval_completed*100/data.eval_total) : 0;
        document.getElementById('overallBar').style.width = evalPct + '%';
        document.getElementById('overallEvalText').textContent = data.eval_completed + ' / ' + data.eval_total + ' evaluations';
        document.getElementById('overallQuizText').textContent = 'Quizzes: ' + data.quiz_completed + ' / ' + data.quiz_required;
        const btnGen = document.getElementById('btnGenerateOverall');
        const btnEmail = document.getElementById('btnEmailOverall');
        const btnAtt = document.getElementById('btnGenerateAttendance');
        const btnAll = document.getElementById('btnGenerateAll');
        const alertEl = document.getElementById('overallAlert');
        const attText = document.getElementById('overallAttendanceText');
        if(attData && attData.ok){
          const req = (attData.required_days||[]).length;
            const attended = (attData.attended||[]).filter(dn => (attData.required_days||[]).includes(dn)).length;
          attText.textContent = 'Attendance: ' + attended + ' / ' + req + ' required days';
          const attComplete = attData.complete_strict || attData.complete_relaxed;
          const btnEmailAtt = document.getElementById('btnEmailAttendance');
          if(attComplete){ btnAtt.classList.remove('d-none'); btnEmailAtt.classList.remove('d-none'); } else { btnAtt.classList.add('d-none'); btnEmailAtt.classList.add('d-none'); }
        } else {
          attText.textContent = '';
          btnAtt.classList.add('d-none');
        }
        if (data.complete || data.complete_relaxed){
          btnGen.classList.remove('d-none');
          btnEmail.classList.remove('d-none');
          if(!btnAtt.classList.contains('d-none')){ btnAll.classList.remove('d-none'); }
          else { btnAll.classList.add('d-none'); }
        } else {
          btnGen.classList.add('d-none');
          btnEmail.classList.add('d-none');
          btnAll.classList.add('d-none');
        }
        if(!data.complete && data.complete_relaxed){
          alertEl.innerHTML = '<span class="text-warning">Partial early completion allowed (remaining speakers are future-day).</span>';
        }
      } catch(e){ /* ignore */ }
    }
    loadOverall();
    setInterval(loadOverall, 20000);
    document.getElementById('btnRefreshOverall')?.addEventListener('click', loadOverall);
    document.getElementById('btnGenerateOverall')?.addEventListener('click', async function(){
      const btn=this; const alertEl=document.getElementById('overallAlert'); const orig=btn.textContent; btn.disabled=true; btn.textContent='Generating...'; alertEl.textContent='';
      try{
        const res=await fetch('../api/main_program_certificate.php',{method:'POST',credentials:'same-origin'});
        const ct=(res.headers.get('content-type')||'').toLowerCase(); const raw=await res.text(); let data={}; try{data=ct.includes('json')?JSON.parse(raw):{};}catch(e){}
        if(!res.ok||!data.ok) throw new Error(data.error||'Generation failed');
        alertEl.innerHTML='<span class="text-success">Ready: <a class="link-light" target="_blank" href="../'+data.file_path+'">Open PDF</a></span>';
        btn.textContent='Regenerate';
      }catch(err){ alertEl.innerHTML='<span class="text-danger">'+err.message+'</span>'; btn.textContent=orig; }
      btn.disabled=false; loadOverall();
    });
    document.getElementById('btnEmailOverall')?.addEventListener('click', async function(){
      const btn=this; const alertEl=document.getElementById('overallAlert'); const orig=btn.textContent; btn.disabled=true; btn.textContent='Emailing...';
      try{
        const res=await fetch('../api/main_program_certificate_email.php',{method:'POST',credentials:'same-origin'});
        const data=await res.json(); if(!res.ok||!data.ok) throw new Error(data.error||'Email failed');
        alertEl.innerHTML='<span class="text-success">Email sent. Certificate No: '+(data.cert_number||'')+'</span>';
      }catch(e){ alertEl.innerHTML='<span class="text-danger">'+(e.message||'Email failed')+'</span>'; }
      btn.disabled=false; btn.textContent=orig; loadOverall();
    });
    document.getElementById('btnGenerateAttendance')?.addEventListener('click', async function(){
      const btn=this; const alertEl=document.getElementById('overallAlert'); const orig=btn.textContent; btn.disabled=true; btn.textContent='Generating Attendance...'; alertEl.textContent='';
      try{
        const res=await fetch('../api/main_program_attendance_certificate.php',{method:'POST',credentials:'same-origin'});
        const ct=(res.headers.get('content-type')||'').toLowerCase(); const raw=await res.text(); let data={}; try{data=ct.includes('json')?JSON.parse(raw):{};}catch(e){}
        if(!res.ok||!data.ok) throw new Error(data.error||'Generation failed');
        alertEl.innerHTML='<span class="text-success">Attendance Ready: <a class="link-light" target="_blank" href="../'+data.file_path+'">Open PDF</a></span>';
        btn.textContent='Regenerate Attendance';
      }catch(err){ alertEl.innerHTML='<span class="text-danger">'+err.message+'</span>'; btn.textContent=orig; }
      btn.disabled=false; loadOverall();
    });
    document.getElementById('btnEmailAttendance')?.addEventListener('click', async function(){
      const btn=this; const alertEl=document.getElementById('overallAlert'); const orig=btn.textContent; btn.disabled=true; btn.textContent='Emailing Attendance...';
      try{
        const res=await fetch('../api/main_program_attendance_certificate_email.php',{method:'POST',credentials:'same-origin'});
        const data=await res.json(); if(!res.ok||!data.ok) throw new Error(data.error||'Email failed');
        alertEl.innerHTML='<span class="text-success">Attendance Email sent. Certificate No: '+(data.cert_number||'')+'</span>';
      }catch(e){ alertEl.innerHTML='<span class="text-danger">'+(e.message||'Email failed')+'</span>'; }
      btn.disabled=false; btn.textContent=orig; loadOverall();
    });
    document.getElementById('btnGenerateAll')?.addEventListener('click', async function(){
      const btn=this; const alertEl=document.getElementById('overallAlert'); const orig=btn.textContent; btn.disabled=true; btn.textContent='Generating All...'; alertEl.textContent='';
      try {
        const [resOverall, resAttendance] = await Promise.all([
          fetch('../api/main_program_certificate.php',{method:'POST',credentials:'same-origin'}),
          fetch('../api/main_program_attendance_certificate.php',{method:'POST',credentials:'same-origin'})
        ]);
        const overallRaw = await resOverall.text(); const attRaw = await resAttendance.text();
        let overall={}, att={};
        try{overall = (resOverall.headers.get('content-type')||'').includes('json')?JSON.parse(overallRaw):{};}catch(e){}
        try{att = (resAttendance.headers.get('content-type')||'').includes('json')?JSON.parse(attRaw):{};}catch(e){}
        if(!overall.ok) throw new Error('Overall failed: '+(overall.error||resOverall.status));
        if(!att.ok) throw new Error('Attendance failed: '+(att.error||resAttendance.status));
        alertEl.innerHTML='<span class="text-success">All Ready: <a class="link-light" target="_blank" href="../'+overall.file_path+'">Overall PDF</a> &middot; <a class="link-light" target="_blank" href="../'+att.file_path+'">Attendance PDF</a></span>';
      } catch(err){ alertEl.innerHTML='<span class="text-danger">'+err.message+'</span>'; }
      btn.disabled=false; btn.textContent=orig; loadOverall();
    });
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
            <div class="row g-2 mb-2">
              <div class="col-4">
                <label class="form-label">First Name <span class="text-danger">*</span></label>
                <input type="text" name="first_name" class="form-control form-control-sm" required style="text-transform:uppercase;">
              </div>
              <div class="col-4">
                <label class="form-label">Last Name <span class="text-danger">*</span></label>
                <input type="text" name="last_name" class="form-control form-control-sm" required style="text-transform:uppercase;">
              </div>
              <div class="col-4">
                <label class="form-label">Middle Name</label>
                <input type="text" name="middle_name" class="form-control form-control-sm" style="text-transform:uppercase;" placeholder="Optional">
              </div>
            </div>
            <div class="mb-2">
              <label class="form-label">PRC License Number <span class="text-danger">*</span></label>
              <input type="text" name="prc_license_number" maxlength="7" pattern="\\d{1,7}" class="form-control form-control-sm" required placeholder="Up to 7 digits">
              <div class="form-text text-warning">Enter 1 to 7 digits (numbers only).</div>
            </div>
            <div class="row g-2">
              <div class="col-6">
                <label class="form-label">Birthday</label>
                <input type="date" name="birthday" class="form-control form-control-sm">
              </div>
              <div class="col-6">
                <label class="form-label">License Expiry</label>
                <input type="date" name="prc_license_expiry" class="form-control form-control-sm">
              </div>
            </div>
            <div class="mb-2 mt-2">
              <label class="form-label">License Type</label>
              <select name="prc_license_type" class="form-select form-select-sm">
                <option value="">-- Select Type --</option>
                <option value="Professional Mechanical Engineer">Professional Mechanical Engineer</option>
                <option value="Registered Mechanical Engineer">Registered Mechanical Engineer</option>
                <option value="Certified Plant Mechanic">Certified Plant Mechanic</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Contact Number</label>
              <input type="text" name="contact_number" class="form-control form-control-sm" placeholder="09xxxxxxxxx">
            </div>
            <div class="d-flex justify-content-end gap-2">
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
