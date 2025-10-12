<?php
session_start();
require_once __DIR__ . '/../db.php';
if (isset($conn) && $conn instanceof mysqli) { $db = $conn; }
elseif (isset($mysqli) && $mysqli instanceof mysqli) { $db = $mysqli; }
else { http_response_code(500); exit('DB unavailable'); }
if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); } }

$speakerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($speakerId <= 0) { echo 'Speaker not found.'; exit; }

$stmt = $db->prepare("SELECT s.*, fr.name AS room_name, fr.id AS room_id, fr.day_number, fr.day_id AS day_id, fd.label AS day_label FROM speakers s LEFT JOIN function_rooms fr ON fr.id = s.room_id LEFT JOIN function_room_days fd ON fd.id = fr.day_id WHERE s.id = ? LIMIT 1");
$stmt->bind_param('i',$speakerId); $stmt->execute(); $s = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$s) { echo 'Speaker not found.'; exit; }
if (strcasecmp($s['program_type'] ?? '', 'sub') !== 0) { echo 'This speaker is not part of the Sub Program.'; exit; }

$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0; // may be 0 (guest)
$evaluationUrl = 'evaluation.php?speaker_id='.(int)$speakerId.'&sub=1';

// If guest, prepare gentle notice for login page when they attempt gated actions
if ($user_id <= 0) {
  // store where they came from so we can redirect after login
  $_SESSION['intended_url'] = $_SERVER['REQUEST_URI'] ?? 'dashboard.php';
  // Provide a generic notice; specific per-action toasts handled client-side
  $_SESSION['login_notice'] = 'Please log in to continue.';
}

// --- Quiz discovery & submission status ---
$quizId = 0; $hasQuiz = false; $hasSubmitted = false;
if ($st = $db->prepare("SELECT id FROM quizzes WHERE speaker_id = ? LIMIT 1")) {
  $st->bind_param('i',$speakerId);
  $st->execute();
  $st->bind_result($quizId);
  if ($st->fetch()) { $hasQuiz = true; }
  $st->close();
}
// fallback to quiz_questions table if exists and no quizzes row
if (!$hasQuiz) {
  if ($res = $db->query("SHOW TABLES LIKE 'quiz_questions'")) { if ($res->num_rows>0) { $res->close();
    if ($st = $db->prepare("SELECT 1 FROM quiz_questions WHERE speaker_id=? LIMIT 1")) {
      $st->bind_param('i',$speakerId); $st->execute(); $st->bind_result($d); if ($st->fetch()) $hasQuiz = true; $st->close();
    }
  } else { $res->close(); }}
}
// submission status (prefer quiz_id path)
if ($hasQuiz && $user_id > 0) {
  if ($quizId > 0 && $st = $db->prepare("SELECT 1 FROM quiz_responses WHERE quiz_id=? AND user_id=? LIMIT 1")) { $st->bind_param('ii',$quizId,$user_id); $st->execute(); $st->bind_result($d); if ($st->fetch()) $hasSubmitted=true; $st->close(); }
  if (!$hasSubmitted && $st = $db->prepare("SELECT 1 FROM quiz_responses WHERE speaker_id=? AND user_id=? LIMIT 1")) { $st->bind_param('ii',$speakerId,$user_id); $st->execute(); $st->bind_result($d); if ($st->fetch()) $hasSubmitted=true; $st->close(); }
}
if (!$hasSubmitted && !empty($_SESSION['quiz_submitted'][$speakerId])) $hasSubmitted = true; // guest fallback

// Evaluation status
$hasEvaluation = false;
if ($user_id > 0) {
  if ($st = $db->prepare('SELECT 1 FROM speaker_evaluations WHERE speaker_id=? AND user_id=? LIMIT 1')) { $st->bind_param('ii',$speakerId,$user_id); $st->execute(); $st->bind_result($d); if ($st->fetch()) $hasEvaluation = true; $st->close(); }
}
// Removed session fallback for evaluation to prevent false positives

// Presentation (optional reuse of existing column)
$presentationUrl = !empty($s['presentation_url']) ? $s['presentation_url'] : '';
$hasPresentation = !empty($presentationUrl);

// Gating (all buttons visible; locked by attributes)
$quizLocked = ($hasSubmitted || !$hasQuiz);
$evalLocked = (($hasQuiz && !$hasSubmitted) || $hasEvaluation);
$presentationLocked = (!$hasEvaluation) || !$hasPresentation;
$certLocked = !$hasEvaluation;
$emailLocked = true;

$photoUrl = !empty($s['photo_url']) ? $s['photo_url'] : '';
// Derive day id for returning to programs listing
$dayId = isset($s['day_id']) ? (int)$s['day_id'] : 0;
if ($dayId <= 0 && !empty($s['room_id'])) {
  // Fallback: attempt to look up day_id from function_rooms if not included
  if ($stTmp = $db->prepare("SELECT day_id FROM function_rooms WHERE id=? LIMIT 1")) {
    $stTmp->bind_param('i', $s['room_id']);
    if ($stTmp->execute()) { $stTmp->bind_result($tmpDid); if ($stTmp->fetch()) { $dayId = (int)$tmpDid; } }
    $stTmp->close();
  }
}
$programsLink = 'programs.php'.($dayId > 0 ? ('?day_id='.(int)$dayId) : '');
?><!doctype html>
<html>
<head>
<meta charset="utf-8">
<title><?= e($s['name']) ?> — Sub Speaker</title>
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
  .hero{background:#fff;color:#0b2a59;border-radius:14px;padding:32px;display:flex;gap:30px;align-items:flex-start;margin-bottom:30px;box-shadow:0 6px 22px -6px rgba(0,0,0,.12);}  
  .avatar{width:110px;height:110px;border-radius:999px;overflow:hidden;background:#f1f3f5;display:flex;align-items:center;justify-content:center}
  .avatar img{width:100%;height:100%;object-fit:cover}
  .hero h1{font-size:1.75rem;font-weight:700;margin:0 0 8px;line-height:1.25;background:linear-gradient(90deg,#111e6c,#1d4ed8);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
  .hero h2{font-size:1.25rem;font-weight:600;margin:0 0 4px;color:#0f235f}
  .session-meta{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}
  .badge-room{background:#111e6c;color:#fff;font-size:.8rem;padding:8px 14px;border-radius:40px;letter-spacing:.5px;display:inline-flex;align-items:center;gap:6px;box-shadow:0 2px 6px rgba(17,30,108,.35)}
  .badge-day{background:#e0e7ff;color:#1e3a8a;font-weight:600;font-size:.72rem;padding:6px 12px;border-radius:30px}
  .time-chip{background:#fff;border:2px solid #111e6c;color:#111e6c;font-size:.72rem;padding:6px 12px;border-radius:30px;font-weight:600}
  .panel{background:rgba(255,255,255,0.07);padding:26px;border-radius:14px;text-align:center}
  .actions .btn{width:250px;margin:8px auto;border-radius:22px;padding:10px 16px;font-weight:600;}
  .btn-gray{background:#6b7280;border:none;color:#fff}
  .btn-ready{background:#22c55e;border:none;color:#fff}
  .btn-ready:hover{filter:brightness(.95)}
  .btn-warning{background:linear-gradient(135deg,#f59e0b,#d97706);border:none;color:#0b2a59;font-weight:600}
  .btn-warning:hover{filter:brightness(.95)}
  .btn-locked{opacity:.55;pointer-events:none;cursor:not-allowed}
  .cert-link{margin-top:14px;font-size:.9rem}
  .btn-outline-light{border:2px solid #ffffff;color:#fff;background:transparent;font-weight:600}
  .btn-outline-light:hover{background:#ffffff;color:#0b2a59}
  /* Step tracker */
  .steps{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin:-4px 0 18px;padding:0;list-style:none}
  .step{position:relative;padding:10px 16px;border-radius:30px;font-size:.7rem;font-weight:600;letter-spacing:.5px;display:flex;align-items:center;gap:6px;background:#1e3a8a;color:#cbd5e1;user-select:none;min-width:90px;justify-content:center}
  .step::before{content:"";width:8px;height:8px;border-radius:50%;background:#64748b}
  .step-active{background:#f59e0b;color:#0b2a59;box-shadow:0 0 0 2px #fff2}
  .step-active::before{background:#b45309}
  .step-done{background:#22c55e;color:#fff}
  .step-done::before{background:#166534}
  .step-locked{background:#334155;color:#64748b;opacity:.65}
  .step-locked::before{background:#475569}
  .step-na{background:#475569;color:#94a3b8}
  .step-na::before{background:#64748b}
  .step .icon{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;font-size:.65rem;font-weight:700}
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
            <a class="nav-link fw-semibold px-3" href="<?= e($programsLink) ?>">Programs</a>
          </li>
          <li class="nav-item">
            <a class="nav-link fw-semibold px-3" href="dashboard.php">Dashboard</a>
          </li>
        </ul>
      </div>
    </div>
  </nav>

<div class="container py-4">
  <div class="hero">
    <div class="avatar">
      <?php if ($photoUrl): ?><img src="<?= e($photoUrl) ?>" alt="Speaker"><?php else: ?>
        <svg width="110" height="110" viewBox="0 0 24 24"><rect width="24" height="24" rx="4" fill="#e5e7eb"/><path d="M12 12a3 3 0 100-6 3 3 0 000 6z" fill="#9ca3af"/><path d="M4 20c0-3.314 2.686-6 6-6h4c3.314 0 6 2.686 6 6" fill="#9ca3af"/></svg>
      <?php endif; ?>
    </div>
    <div style="flex:1;min-width:0">
  <h1><?= e(($s['topic'] ?? '') !== '' ? $s['topic'] : ($s['title'] ?: 'Session')) ?></h1>
      <h2><?= e($s['name']) ?></h2>
      <div class="session-meta">
        <div class="badge-room" title="Function Room"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21V8a2 2 0 012-2h3l2-3h4l2 3h3a2 2 0 012 2v13"/><path d="M16 21V11H8v10"/></svg><?= e($s['room_name'] ?: 'Room TBA') ?></div>
        <div class="badge-day"><?= e($s['day_label'] ?: ('Day '.((int)$s['day_number']))) ?></div>
        <?php if(!empty($s['start_time'])): ?>
          <div class="time-chip"><?= e(substr($s['start_time'],0,5)) ?><?= !empty($s['end_time']) ? ' - '.e(substr($s['end_time'],0,5)) : '' ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="panel">
    <h5 class="mb-3">Session Progress</h5>
    <ul class="steps" id="stepTracker">
      <li class="step" data-step="quiz"><span class="icon">1</span>Quiz</li>
      <li class="step" data-step="evaluation"><span class="icon">2</span>Evaluation</li>
      <li class="step" data-step="presentation"><span class="icon">3</span>Presentation</li>
      <li class="step" data-step="certificate"><span class="icon">4</span>Certificate</li>
      <li class="step" data-step="email"><span class="icon">5</span>Email</li>
    </ul>
    <div class="actions d-flex flex-column align-items-center">
      <!-- Quiz -->
      <a id="btnQuiz" class="btn <?= $quizLocked ? 'btn-gray btn-locked' : 'btn-warning' ?>" href="<?= $quizLocked ? '#' : ('quiz.php?speaker_id='.(int)$speakerId) ?>" <?= $quizLocked ? 'data-locked="1"' : '' ?>><?= $hasQuiz ? ($hasSubmitted ? 'Quiz Completed' : 'Take Quiz') : 'Quiz Unavailable' ?></a>
      <!-- Evaluation (locked until quiz done if quiz exists) -->
      <a id="btnEvaluation" class="btn <?= $evalLocked ? 'btn-gray btn-locked' : 'btn-warning' ?>" href="<?= (!$evalLocked && !$hasEvaluation) ? e($evaluationUrl) : '#' ?>" <?= $evalLocked ? 'data-locked="1"' : '' ?>><?= $hasEvaluation ? 'Evaluation Completed' : 'Speaker Evaluation' ?></a>
      <!-- Presentation preview (only enabled after evaluation AND file) -->
      <button id="btnPreviewPresentation" class="btn <?= $presentationLocked ? 'btn-gray btn-locked' : 'btn-ready' ?>" <?= $presentationLocked ? 'data-locked="1"' : 'data-bs-toggle="modal" data-bs-target="#presentationModal"' ?>>Preview Presentation</button>
  <!-- Certificate & Email removed: Department-level certificate now handled on department page -->
  <div class="alert alert-info mt-3 w-100 small" style="max-width:300px;">Department certificate available after completing all speakers in its department. Return to the department list to generate.</div>
    </div>
    <p class="small opacity-75 mt-3 mb-0 text-start" style="max-width:640px;margin-left:auto;margin-right:auto;">
      Flow: Complete the quiz (if available) → Submit the evaluation → Preview the presentation → Generate your certificate → Email it to yourself.
    </p>
    <?php if ($user_id <= 0): ?><div class="alert alert-warning text-dark mt-3 mb-0 p-2 small">Please log in to proceed through the steps.</div><?php endif; ?>
  </div>

  <!-- Presentation Modal -->
  <div class="modal fade" id="presentationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content" style="background:#111e6c;color:#fff;">
        <div class="modal-header border-0">
          <h6 class="modal-title">Presentation Preview</h6>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" style="background:#ffffff;">
          <?php if ($hasPresentation): ?>
            <?php if (preg_match('/\.pdf$/i',$presentationUrl)): ?>
              <iframe src="<?= e($presentationUrl) ?>" style="width:100%;height:70vh;border:2px solid #111e6c;border-radius:8px;background:#fff"></iframe>
            <?php else: ?>
              <div class="mb-3"><a class="btn btn-light" href="<?= e($presentationUrl) ?>" target="_blank" rel="noopener">Open Presentation File</a></div>
              <p class="small">Embedded preview only for PDF files.</p>
            <?php endif; ?>
          <?php else: ?>
            <p class="mb-0">Presentation file not available.</p>
          <?php endif; ?>
        </div>
        <div class="modal-footer border-0">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="position-fixed top-0 end-0 p-3" style="z-index:1080">
  <div id="toast" class="toast align-items-center text-bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="2400">
    <div class="d-flex">
      <div class="toast-body" id="toastMsg">Action locked.</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
let hasEval = <?= $hasEvaluation ? 'true':'false' ?>;
const hasQuiz = <?= $hasQuiz ? 'true':'false' ?>;
const quizSubmitted = <?= $hasSubmitted ? 'true':'false' ?>;
let hasPresentation = <?= $hasPresentation ? 'true':'false' ?>;
const speakerId = <?= (int)$speakerId ?>;
const toastEl = document.getElementById('toast');
const toastMsg = document.getElementById('toastMsg');
function showToast(msg){ toastMsg.textContent = msg; bootstrap.Toast.getOrCreateInstance(toastEl).show(); }

document.querySelectorAll('[data-locked]')?.forEach(el=>{
  el.addEventListener('click',e=>{
    e.preventDefault();
    if (el.id === 'btnQuiz') { if (!hasQuiz) return showToast('Quiz is unavailable for this session.'); if (quizSubmitted) return showToast('You already completed the quiz.'); return showToast('Quiz locked.'); }
    if (el.id === 'btnEvaluation') { return showToast(!hasQuiz || quizSubmitted ? 'Evaluation not available yet or already done.' : 'Complete the quiz first.'); }
    if (el.id === 'btnPreviewPresentation') { if (!hasEval) return showToast('Complete the evaluation first.'); if (!hasPresentation) return showToast('Presentation file unavailable.'); return showToast('Locked.'); }
    if (el.id === 'btnGenerateCert') { return showToast('Complete the evaluation to unlock certificate generation.'); }
    if (el.id === 'btnEmailCert') { return showToast('Generate the certificate first.'); }
    showToast('Complete the required previous steps first.');
  });
});

// Per-speaker certificate removed; references stripped
const btnGen = null; const certStatus = null; const btnEmail = null;
const btnPreview = document.getElementById('btnPreviewPresentation');
const btnEval = document.getElementById('btnEvaluation');
const btnQuiz = document.getElementById('btnQuiz');
let certGenerated = false; // deprecated per-speaker

function updateSteps(){
  const map = {
    quiz: !hasQuiz ? 'na' : (quizSubmitted ? 'done' : 'active'),
    evaluation: hasEval ? 'done' : ((hasQuiz && !quizSubmitted) ? 'locked' : 'active'),
  presentation: hasEval ? (hasPresentation ? 'active' : 'locked') : 'locked',
  certificate: 'na',
  email: 'na'
  };
  document.querySelectorAll('#stepTracker .step').forEach((el,i)=>{
    const key = el.getAttribute('data-step');
    const state = map[key] || 'locked';
    el.classList.remove('step-active','step-done','step-locked','step-na');
    el.classList.add('step-'+state);
    const icon = el.querySelector('.icon');
    if(icon){ if(!icon.dataset.num) icon.dataset.num=(i+1).toString(); icon.textContent = state==='done' ? '✓' : icon.dataset.num; }
  });
}
updateSteps();

// Certificate status fetch removed

function enableEmail(path) { /* deprecated */ }

// Per-speaker certificate actions removed

// Poll evaluation completion
if(!hasEval){
  const pollMs = 6000;
  const poll = setInterval(async () => {
    try {
      const r = await fetch('../api/eval_get.php?speaker_id='+speakerId,{credentials:'same-origin'});
      if(!r.ok) return; const j = await r.json();
      if(j && j.found){
        hasEval = true; clearInterval(poll);
        btnEval.textContent='Evaluation Completed'; btnEval.classList.add('btn-gray','btn-locked'); btnEval.setAttribute('data-locked','1');
        if(hasPresentation){
          btnPreview.classList.remove('btn-gray','btn-locked'); btnPreview.classList.add('btn-ready'); btnPreview.removeAttribute('data-locked'); btnPreview.setAttribute('data-bs-toggle','modal'); btnPreview.setAttribute('data-bs-target','#presentationModal');
        }
        btnGen.classList.remove('btn-locked'); btnGen.removeAttribute('disabled'); btnGen.removeAttribute('data-locked');
        updateSteps();
      }
    } catch(e){}
  }, pollMs);
}
</script>
</body>
</html>
