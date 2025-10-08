<?php
session_start();
require_once __DIR__ . '/../db.php';
if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); } }
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$userName = trim((string)($_SESSION['user_name'] ?? $_SESSION['name'] ?? ''));

// Load days (reuse function_room_days) - expecting up to Day 4
$days = [];
if ($st = $conn->prepare('SELECT id,label,day_date,is_enabled FROM function_room_days ORDER BY day_date ASC')) {
  $st->execute();
  $res = $st->get_result();
  while($row=$res->fetch_assoc()) { $days[]=$row; }
  $st->close();
}

// Map id->day number
$dayNum = 0; $dayNumberById = [];
foreach ($days as $i=>$d) { $dayNumberById[(int)$d['id']] = $i+1; }

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Main Program Days - 73rd PSME</title>
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
  .day-card { background:rgba(255,255,255,0.1); border:1px solid rgba(255,255,255,0.25); border-radius:12px; padding:20px 18px; cursor:pointer; transition:.3s; position:relative; }
  .day-card.disabled { opacity:.4; cursor:not-allowed; }
  .day-card:hover:not(.disabled) { transform:translateY(-6px); background:rgba(255,255,255,0.18); }
  .badge-small { font-size:.65rem; letter-spacing:0.5px; }
</style>
</head>
<body>
  <!-- Header Section -->
  <nav class="navbar navbar-expand-lg navbar-light shadow-custom fixed-top">
      <div class="container">
        <!-- Logo + Title -->
        <a class="navbar-brand d-flex align-items-center" href="#">
          <img src="../image/natcon.jpg" alt="PSME Logo" class="me-3 rounded-circle" style="height:55px;">
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
            <a class="nav-link fw-semibold px-3" href="programs.php">&lt; Programs</a>
          </li>
          <li class="nav-item">
            <a class="nav-link fw-semibold px-3" href="dashboard.php">Dashboard</a>
          </li>
        </ul>
      </div>
    </div>
  </nav>

<div class="container py-5">
  <h1 class="display-6 fw-bold text-center mb-4">MAIN PROGRAM</h1>
  <p class="text-center mb-5 opacity-75 small">Select a day to see its speakers and rooms for the Main Program.</p>
  <div class="row g-4 justify-content-center">
    <?php foreach ($days as $d):
      $id = (int)$d['id'];
      $label = $d['label'];
      $enabled = (int)$d['is_enabled'] === 1;
      $dayNumber = $dayNumberById[$id] ?? null;
    ?>
      <div class="col-md-5 col-lg-4">
        <div class="day-card <?= $enabled ? '' : 'disabled' ?>" data-id="<?= $id ?>" data-enabled="<?= $enabled?1:0 ?>">
          <h4 class="mb-1">Day <?= e($dayNumber) ?>: <?= e($label) ?></h4>
          <div class="small mb-2">Date: <?= (new DateTime($d['day_date']))->format('F j, Y') ?></div>
          <?php if (!$enabled): ?><span class="badge bg-secondary badge-small">COMING SOON</span><?php else: ?><span class="badge bg-success badge-small">OPEN</span><?php endif; ?>
          <div class="small mt-2" style="opacity:.6;">Click to view sessions & speakers</div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <div id="overallProgress" class="mt-5 d-none" style="max-width:760px;margin-left:auto;margin-right:auto;">
    <div class="p-4 rounded-4" style="background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.25);">
      <h4 class="fw-bold mb-2">Overall Main Program Progress</h4>
      <p class="small mb-2 opacity-75" id="overallEvalText">Loading...</p>
      <p class="small mb-3 opacity-50" id="overallQuizText"></p>
      <div class="progress mb-3" style="height:10px;">
        <div id="overallBar" class="progress-bar bg-success" style="width:0%"></div>
      </div>
      <button id="btnGenerateOverall" class="btn btn-warning fw-semibold px-4 rounded-pill d-none" type="button">Generate Overall Main Program Certificate</button>
      <div id="overallAlert" class="small mt-3"></div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.day-card').forEach(function(card){
      card.addEventListener('click', function(){
        if (this.getAttribute('data-enabled') !== '1') return;
        const dayId = this.getAttribute('data-id');
        // Navigate to a speakers listing page (to be implemented) e.g. main_program_day.php?day_id=...
        window.location = 'main_program_day.php?day_id=' + encodeURIComponent(dayId);
      });
    });
  });

  // Overall progress + certificate
  async function loadOverall(){
    try {
      const r = await fetch('../api/main_program_completion.php', {credentials:'same-origin'});
      const data = await r.json();
      if(!data || !data.ok) return;
      const wrap = document.getElementById('overallProgress');
      wrap.classList.remove('d-none');
      const evalPct = data.eval_total>0 ? Math.round(data.eval_completed*100/data.eval_total) : 0;
      document.getElementById('overallBar').style.width = evalPct + '%';
      document.getElementById('overallEvalText').textContent = data.eval_completed + ' of ' + data.eval_total + ' evaluations completed.';
      document.getElementById('overallQuizText').textContent = 'Quizzes: ' + data.quiz_completed + ' of ' + data.quiz_required + ' completed';
      const btn = document.getElementById('btnGenerateOverall');
      // Allow relaxed completion (only future-day speakers remaining)
      if (data.complete || data.complete_relaxed) { btn.classList.remove('d-none'); }
      else { btn.classList.add('d-none'); }
      if (!data.complete && data.complete_relaxed) {
        document.getElementById('overallAlert').innerHTML = '<span class="text-warning">Note: Some remaining speakers are scheduled for future days; certificate generation is allowed early.</span>';
      }
    } catch(e) { /* silent */ }
  }
  loadOverall();
  setInterval(loadOverall, 20000);

  document.getElementById('btnGenerateOverall').addEventListener('click', async function(){
    const btn = this; const alertEl = document.getElementById('overallAlert');
    btn.disabled=true; const orig = btn.textContent; btn.textContent='Generating...'; alertEl.textContent='';
    try {
      const res = await fetch('../api/main_program_certificate.php', {method:'POST',credentials:'same-origin'});
      const ct=(res.headers.get('content-type')||'').toLowerCase(); const raw=await res.text(); let data={}; try{ data=ct.includes('json')?JSON.parse(raw):{};}catch(e){}
      if(!res.ok || !data.ok) throw new Error(data.error||'Generation failed');
      alertEl.innerHTML = '<span class="text-success">Certificate ready. <a class="link-light" target="_blank" href="../'+data.file_path+'">Open PDF</a></span>';
      btn.textContent='Regenerate'; btn.disabled=false;
    } catch(err){ alertEl.innerHTML='<span class="text-danger">'+(err.message||'Failed to generate')+'</span>'; btn.disabled=false; btn.textContent=orig; }
  });
</script>
</body>
</html>
