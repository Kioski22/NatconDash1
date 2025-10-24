<?php
session_start();
if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); } }
$dayId = isset($_GET['day_id']) ? (int)$_GET['day_id'] : 0; if ($dayId <= 0) { http_response_code(400); echo 'Invalid day'; exit; }
// Page now fetches data from ../api/main_program_day.php to avoid duplicating server logic.

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Main Program </title>
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
 .room-block { background:rgba(255,255,255,0.07); border:1px solid rgba(255,255,255,0.2); border-radius:14px; padding:22px 22px; margin-bottom:28px; }
 .speaker-item { background:rgba(255,255,255,0.1); border:1px solid rgba(255,255,255,0.25); border-radius:10px; padding:14px 16px; transition:.25s; cursor:pointer; }
 .speaker-item:hover { background:rgba(255,255,255,0.18); transform:translateY(-4px); }
 .speaker-time { font-size:.75rem; letter-spacing:.5px; opacity:.7; }
 .speaker-title { font-size:.85rem; opacity:.85; }
 .badge-room { background:linear-gradient(135deg,#1d4ed8,#1e3a8a); }
 
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
          <li class="nav-item"><a class="nav-link fw-semibold px-3" href="programs.php">Programs</a></li>
          <li class="nav-item"><a class="nav-link fw-semibold px-3" href="dashboard.php">Dashboard</a></li>
        </ul>
      </div>
    </div>
  </nav>

<div class="container py-5">
  <div id="pageHeader" class="text-center mb-4">
    <div class="mb-3 text-start">
      <a href="programs.php" class="btn btn-sm btn-outline-light rounded-pill">&lt; Back to Programs</a>
    </div>
    <h1 class="display-6 fw-bold mb-1" id="dayTitle">Main Program</h1>
    <p class="text-center mb-4 small opacity-75" id="daySubtitle">Loading day information...</p>
  </div>
  <div id="roomsContainer"></div>
  <div id="emptyState" class="alert alert-warning text-dark d-none">No speakers scheduled for this day yet.</div>
  <div id="completionBlock" class="mt-5 d-none">
    <div class="p-4 rounded-4" style="background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.25);">
      <h4 class="fw-bold mb-2">Day Progress</h4>
      <p class="small mb-2 opacity-75" id="completionText">Checking your progress...</p>
      <p class="small mb-3 opacity-50" id="quizText"></p>
      <div class="progress mb-2" style="height:10px;">
        <div id="completionBar" class="progress-bar bg-success" style="width:0%"></div>
      </div>
      <div class="alert alert-info text-dark mt-3 mb-0 py-2 px-3 small" id="overallHint" style="background:rgba(255,255,255,0.85);">
        Complete all required evaluations (and quizzes where present) for <strong>every Main Program speaker across all days</strong> to unlock the Overall Main Program Certificate. Go back to the Main Program overview to generate it.
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const dayId = <?= (int)$dayId ?>;
  function esc(str){ return document.createTextNode(str ?? '').textContent; }

  function renderData(data){
    const titleEl = document.getElementById('dayTitle');
    const subEl = document.getElementById('daySubtitle');
    if(!data.day){ titleEl.textContent='Day Not Found'; subEl.textContent=''; return; }
  const dayLabel = (data.day.label || '').toString().trim();
  const selectedText = dayLabel ? ('Selected ' + dayLabel) : ('Selected Day ' + String(<?= (int)$dayId ?>));
  titleEl.textContent = 'Main Program - ' + (dayLabel || '');
  subEl.textContent = selectedText + ' • Select a speaker to open their session page (quiz, evaluation, and resources).';
    const rooms = data.rooms || {};
    const keys = Object.keys(rooms);
    if(keys.length === 0){ document.getElementById('emptyState').classList.remove('d-none'); return; }
    const container = document.getElementById('roomsContainer');
    keys.sort();
    keys.forEach(k => {
      const info = rooms[k];
      const block = document.createElement('div');
      block.className='room-block';
      block.innerHTML = '<h4 class="mb-3">Room: ' + esc(info.room_name || 'Room') + '</h4>';
      const row = document.createElement('div'); row.className='row g-3';
      (info.speakers||[]).forEach(sp => {
        const col = document.createElement('div'); col.className='col-md-6 col-lg-4';
        const item = document.createElement('div'); item.className='speaker-item'; item.dataset.id = sp.id;
        let timeTxt = 'Time TBA';
        if(sp.start_time || sp.end_time){
          const st = (sp.start_time||'').substring(0,5);
          const et = (sp.end_time||'');
            timeTxt = st + (et ? ' - ' + et.substring(0,5) : '');
        }
        const finished = (sp.finished === 1 || sp.finished === '1' || sp.finished === true);
        const badge = finished ? '<span class="badge bg-success ms-2">Finished</span>' : '';
        item.innerHTML = '<div class="d-flex justify-content-between align-items-start mb-1">'
          + '<strong>' + esc(sp.name || '') + '</strong>'
          + (badge ? '<div>' + badge + '</div>' : '')
          + '</div>'
          + (sp.topic? '<div class="speaker-title mb-1">'+esc(sp.topic)+'</div>' : (sp.title? '<div class="speaker-title mb-1">'+esc(sp.title)+'</div>' : ''))
          + '<div class="speaker-time">'+esc(timeTxt)+'</div>';
        item.addEventListener('click', ()=> { window.location = 'speaker_form.php?id=' + encodeURIComponent(sp.id); });
        col.appendChild(item); row.appendChild(col);
      });
      block.appendChild(row); container.appendChild(block);
    });
  }

  fetch('../api/main_program_day.php?day_id=' + encodeURIComponent(dayId))
    .then(r=>{ if(!r.ok) throw new Error('Failed to load'); return r.json(); })
    .then(renderData)
    .catch(err => {
      document.getElementById('dayTitle').textContent='Error';
      document.getElementById('daySubtitle').textContent = 'Unable to load day data.';
      console.error(err);
    });

  // Fetch completion status
  function loadCompletion(){
    fetch('../api/main_program_day_completion.php?day_id=' + encodeURIComponent(dayId), {credentials:'same-origin'})
      .then(r=> r.json())
      .then(data => {
        if(!data || !data.ok) return;
        const block = document.getElementById('completionBlock'); block.classList.remove('d-none');
        const evalPct = data.eval_total>0 ? Math.round(data.eval_completed * 100 / data.eval_total) : 0;
        document.getElementById('completionBar').style.width = evalPct + '%';
        document.getElementById('completionText').textContent = data.eval_completed + ' of ' + data.eval_total + ' evaluations completed.';
        document.getElementById('quizText').textContent = 'Quizzes: ' + data.quiz_completed + ' of ' + data.quiz_required + ' completed';
  // No per-day certificate anymore; overall certificate handled on programs.php (integrated panel)
      })
      .catch(()=>{});
  }
  loadCompletion();

  // Refresh progress periodically
  setInterval(loadCompletion,15000);
</script>
</body>
</html>
