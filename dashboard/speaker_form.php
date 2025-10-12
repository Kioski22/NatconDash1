<?php

session_start();
require_once __DIR__ . '/../db.php';

// normalize DB connection
if (isset($conn) && $conn instanceof mysqli) { $db = $conn; }
elseif (isset($mysqli) && $mysqli instanceof mysqli) { $db = $mysqli; }
else { http_response_code(500); exit('DB unavailable'); }

// NEW: helpers (escape + asset URL) to fix fatal error
if (!function_exists('e')) {
    function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('asset_url')) {
    function asset_url($path) {
        $path = (string)$path;
        if ($path === '') return '';
        if (preg_match('#^https?://#i', $path)) return $path;
        $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/\\'); // -> /NatconDash1
        if ($base === '' || $base === '.') $base = '';
        return $base . '/' . ltrim($path, '/');
    }
}

// NEW: safe table-exists helper to avoid prepare() errors on missing tables
function db_table_exists(mysqli $db, string $table): bool {
    $sql = "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1";
    $st = $db->prepare($sql);
    if (!$st) return false;
    $st->bind_param('s', $table);
    $st->execute();
    $st->bind_result($d);
    $ok = $st->fetch();
    $st->close();
    return (bool)$ok;
}

// --- NEW: per-speaker gating helpers ---
function current_user_identity(): array {
    $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $email = trim((string)($_SESSION['user_email'] ?? $_SESSION['email'] ?? '')); // adjust if you store email differently
    return [$uid, $email];
}

function has_attendance(mysqli $db, int $speakerId, int $userId, string $email): bool {
    // Try by user_id
    if ($userId > 0) {
        if ($st = $db->prepare("SELECT 1 FROM speaker_attendance WHERE speaker_id = ? AND user_id = ? LIMIT 1")) {
            $st->bind_param('ii', $speakerId, $userId);
            $st->execute(); $st->bind_result($d); $ok = $st->fetch(); $st->close();
            if ($ok) return true;
        }
    }
    // Fallback by email
    if ($email !== '') {
        if ($st = $db->prepare("SELECT 1 FROM speaker_attendance WHERE speaker_id = ? AND attendee_email = ? LIMIT 1")) {
            $st->bind_param('is', $speakerId, $email);
            $st->execute(); $st->bind_result($d); $ok = $st->fetch(); $st->close();
            if ($ok) return true;
        }
    }
    // Session fallback (legacy)
    return !empty($_SESSION['attended_speakers'][$speakerId]);
}

function has_quiz_submission(mysqli $db, int $speakerId, int $userId, string $email): bool {
    // Preferred: via quizzes table (quiz -> speaker)
    if ($userId > 0) {
        if ($st = $db->prepare("SELECT 1 FROM quiz_responses qr JOIN quizzes q ON q.id = qr.quiz_id WHERE q.speaker_id = ? AND qr.user_id = ? LIMIT 1")) {
            $st->bind_param('ii', $speakerId, $userId);
            $st->execute(); $st->bind_result($d); $ok = $st->fetch(); $st->close();
            if ($ok) return true;
        }
    }
    // Fallback: if quiz_responses has speaker_id
    if ($userId > 0) {
        if ($st = $db->prepare("SELECT 1 FROM quiz_responses WHERE speaker_id = ? AND user_id = ? LIMIT 1")) {
            $st->bind_param('ii', $speakerId, $userId);
            $st->execute(); $st->bind_result($d); $ok = $st->fetch(); $st->close();
            if ($ok) return true;
        }
    }
    // Email fallback
    if ($email !== '') {
        if ($st = $db->prepare("SELECT 1 FROM quiz_responses qr JOIN quizzes q ON q.id = qr.quiz_id WHERE q.speaker_id = ? AND (qr.user_email = ? OR qr.email = ?) LIMIT 1")) {
            $st->bind_param('iss', $speakerId, $email, $email);
            $st->execute(); $st->bind_result($d); $ok = $st->fetch(); $st->close();
            if ($ok) return true;
        }
        if ($st = $db->prepare("SELECT 1 FROM quiz_responses WHERE speaker_id = ? AND (user_email = ? OR email = ?) LIMIT 1")) {
            $st->bind_param('iss', $speakerId, $email, $email);
            $st->execute(); $st->bind_result($d); $ok = $st->fetch(); $st->close();
            if ($ok) return true;
        }
    }
    return false;
}

function has_evaluation(mysqli $db, int $speakerId, int $userId, string $email): bool {
    if ($userId > 0) {
        if ($st = $db->prepare("SELECT 1 FROM speaker_evaluations WHERE speaker_id = ? AND user_id = ? LIMIT 1")) {
            $st->bind_param('ii', $speakerId, $userId);
            $st->execute(); $st->bind_result($d); $ok = $st->fetch(); $st->close();
            if ($ok) return true;
        }
    }
    if ($email !== '') {
        if ($st = $db->prepare("SELECT 1 FROM speaker_evaluations WHERE speaker_id = ? AND (user_email = ? OR email = ?) LIMIT 1")) {
            $st->bind_param('iss', $speakerId, $email, $email);
            $st->execute(); $st->bind_result($d); $ok = $st->fetch(); $st->close();
            if ($ok) return true;
        }
    }
    // Session fallback (legacy)
    return !empty($_SESSION['evaluated_speakers'][$speakerId]);
}
// --- END helpers ---

$speakerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($speakerId <= 0) { echo "Speaker not found."; exit; }

// fetch speaker + room/day
$stmt = $db->prepare("
  SELECT s.*, fr.name AS room_name, fr.id AS room_id, fr.day_number, fd.label AS day_label
  FROM speakers s
  LEFT JOIN function_rooms fr ON fr.id = s.room_id
  LEFT JOIN function_room_days fd ON fd.id = fr.day_id
  WHERE s.id = ? LIMIT 1
");
$stmt->bind_param('i',$speakerId);
$stmt->execute();
$s = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$s) { echo "Speaker not found."; exit; }

// message from API (via session) 
$attMsg = $_SESSION['att_msg'] ?? null;
unset($_SESSION['att_msg']);

// resolve photo path via asset_url (admin saves "uploads/..." relative path)
$photoUrl = '';
if (!empty($s['photo_url'])) {
    $photoUrl = asset_url($s['photo_url']);
}

// NEW: quiz gating — find quiz and submission
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$quizId = 0; $hasQuiz = false; $hasSubmitted = false;
if ($st = $db->prepare("SELECT id FROM quizzes WHERE speaker_id = ? LIMIT 1")) {
    $st->bind_param('i', $speakerId);
    $st->execute();
    $st->bind_result($quizId);
    if ($st->fetch()) { $hasQuiz = true; }
    $st->close();
}
// NEW: fallback if you only have quiz_questions table
if (!$hasQuiz && db_table_exists($db, 'quiz_questions')) {            // CHANGED
    if ($st = $db->prepare("SELECT 1 FROM quiz_questions WHERE speaker_id = ? LIMIT 1")) {
        $st->bind_param('i', $speakerId);
        $st->execute();
        $st->bind_result($dummy);
        if ($st->fetch()) { $hasQuiz = true; }
        $st->close();
    }
}

if ($hasQuiz && $user_id > 0 && $quizId > 0) {
    if ($st = $db->prepare("SELECT 1 FROM quiz_responses WHERE quiz_id = ? AND user_id = ? LIMIT 1")) {
        $st->bind_param('ii', $quizId, $user_id);
        $st->execute();
        $st->bind_result($dummy);
        if ($st->fetch()) { $hasSubmitted = true; }
        $st->close();
    }
}
// session fallback for guests
if (!$hasSubmitted && !empty($_SESSION['quiz_submitted'][$speakerId])) {
    $hasSubmitted = true;
}

// Optional: presentation link if uploaded by admin
$presentationUrl = !empty($s['presentation_url']) ? asset_url($s['presentation_url']) : '';
$evaluationUrl = "evaluation.php?speaker_id=" . (int)$speakerId;
$hasPresentation = !empty($presentationUrl);

// NEW: attendance status (per speaker)
$hasAttendance = false;
if ($user_id > 0) {
    if ($st = $db->prepare("SELECT 1 FROM speaker_attendance WHERE speaker_id = ? AND user_id = ? LIMIT 1")) {
        $st->bind_param('ii', $speakerId, $user_id);
        $st->execute(); $st->bind_result($d);
        if ($st->fetch()) $hasAttendance = true;
        $st->close();
    }
}
if (!$hasAttendance && !empty($_SESSION['attended_speakers'][$speakerId])) {
    $hasAttendance = true;
}

// NEW: evaluation status (per speaker)
$hasEvaluation = false;
if ($user_id > 0) {
    if ($st = $db->prepare("SELECT 1 FROM speaker_evaluations WHERE speaker_id = ? AND user_id = ? LIMIT 1")) {
        $st->bind_param('ii', $speakerId, $user_id);
        $st->execute(); $st->bind_result($dummy);
        if ($st->fetch()) { $hasEvaluation = true; }
        $st->close();
    }
}
if (!$hasEvaluation && !empty($_SESSION['evaluated_speakers'][$speakerId])) {
    $hasEvaluation = true;
}
// NEW: also consider dynamic evaluation system (speaker_eval_responses)
if (!$hasEvaluation && db_table_exists($db, 'speaker_eval_responses')) {
  $user_email = trim((string)($_SESSION['user_email'] ?? $_SESSION['email'] ?? ''));
  if ($user_id > 0) {
    if ($st = $db->prepare("SELECT 1 FROM speaker_eval_responses WHERE speaker_id = ? AND user_id = ? LIMIT 1")) {
      $st->bind_param('ii', $speakerId, $user_id);
      $st->execute(); $st->bind_result($d);
      if ($st->fetch()) { $hasEvaluation = true; }
      $st->close();
    }
  }
  if (!$hasEvaluation && $user_email !== '') {
    if ($st = $db->prepare("SELECT 1 FROM speaker_eval_responses WHERE speaker_id = ? AND (user_email = ? OR email = ?) LIMIT 1")) {
      $st->bind_param('iss', $speakerId, $user_email, $user_email);
      $st->execute(); $st->bind_result($d);
      if ($st->fetch()) { $hasEvaluation = true; }
      $st->close();
    }
  }
}

// NEW: Locks (per speaker, strictly sequential)
$quizLocked = $hasSubmitted || !$hasQuiz;                 // Quiz first; unlocked if not yet submitted
$evalLocked = (!$hasSubmitted) || $hasEvaluation;         // Eval unlocks only after quiz is submitted
$presLocked = (!$hasEvaluation) || (!$hasPresentation);   // Presentation unlocks only after evaluation and file exists

function fmt_time($t){ if (!$t) return ''; $dt=@date_create($t); return $dt?date_format($dt,'g:i A'):htmlspecialchars((string)$t,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title><?= e($s['name']) ?> — Speaker</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- Google Fonts -->
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
    
    .btn-primary {
      background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
      border: none;
      box-shadow: 0 4px 15px rgba(37, 99, 235, 0.3);
    }
    
    .btn-primary:hover {
      background: linear-gradient(135deg, var(--primary-dark), var(--primary-color));
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(37, 99, 235, 0.4);
    }
    
    .btn-outline-primary {
      border: 2px solid var(--primary-color);
      color: var(--primary-color);
    }
    
    .btn-outline-primary:hover {
      background: var(--primary-color);
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(37, 99, 235, 0.3);
    }
    
    .btn-warning {
      background: linear-gradient(135deg, var(--secondary-color), var(--secondary-dark));
      border: none;
      box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3);
    }
    
    .btn-warning:hover {
      background: linear-gradient(135deg, var(--secondary-dark), var(--secondary-color));
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(245, 158, 11, 0.4);
    }

    body { 
      background: var(--primary-color); 
      color:#fff;
      padding-top: 70px;
    }
    
.hero{background:#fff;color:#0b2a59;border-radius:8px;padding:28px;display:flex;gap:24px;align-items:center;margin-bottom:22px}
.avatar{width:120px;height:120px;border-radius:999px;overflow:hidden;background:#f1f3f5;display:flex;align-items:center;justify-content:center}
.avatar img{width:100%;height:100%;object-fit:cover}
.panel{background:rgba(255,255,255,0.06);padding:28px;border-radius:14px;text-align:center}
.btn-yellow{background:#f6c10f;border:none;color:#0b2a59;font-weight:700}
.btn-gray{background:#7f8793;border:none;color:#fff}
.actions .btn{width:260px;margin:8px auto;border-radius:20px;padding:10px 18px}
.att-card{max-width:560px;margin:18px auto}
/* NEW: locked state visual */
.btn-locked{opacity:.6; cursor:not-allowed; pointer-events:none;}  /* NEW: block clicks */
/* NEW: unlocked (clickable) state */
.btn-ready{background:#22c55e;border:none;color:#fff}
.btn-ready:hover{filter:brightness(0.95)}
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
            <a class="nav-link fw-semibold d-md-block px-3" href="programs.php">&lt; Back to Programs</a></div>
          </li>
        </ul>
      </div>
    </div>
  </nav>

<div class="container py-4">
  <div class="hero">
    <div class="avatar">
      <?php if (!empty($photoUrl)): ?>
        <img src="<?= e($photoUrl) ?>" alt="">
      <?php else: ?>
        <svg width="120" height="120" viewBox="0 0 24 24"><rect width="24" height="24" rx="4" fill="#e9ecef"/><path d="M12 12a3 3 0 100-6 3 3 0 000 6z" fill="#adb5bd"/></svg>
      <?php endif; ?>
    </div>
    <div style="flex:1">
      <h2><?= e($s['name']) ?></h2>
      <?php if (!empty($s['topic'])): ?>
        <p class="mb-0 fw-semibold"><?= e($s['topic']) ?></p>
      <?php endif; ?>
      <p class="mb-0"><?= e($s['title']) ?></p>
      <div class="mt-2 small text-muted"><?= e($s['room_name']?:'Function Room') ?> — <?= e($s['day_label']?:('Day '.((int)$s['day_number']))) ?> — <?= fmt_time($s['start_time']) ?></div>
    </div>
  </div>

  <?php if ($attMsg): ?>
    <div class="mb-3">
      <?php if (!empty($attMsg['success'])): ?>
        <div class="alert alert-success"><?= e($attMsg['success']) ?></div>
      <?php elseif (!empty($attMsg['error'])): ?>
        <div class="alert alert-danger"><?= e($attMsg['error']) ?></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="panel">
    <h5 style="color:#fff;margin-bottom:14px">Some Possible and Fit Sample Title</h5>
    <hr>
    <div class="actions d-flex flex-column align-items-center">
      <?php if (false): ?> 
        <!-- Attendance moved out of this page; kept for reference -->
        <?php if (!$hasAttendance): ?>
          <button id="btnAttendance" class="btn btn-yellow">Attendance</button>
        <?php else: ?>
          <button class="btn btn-ready btn-locked" type="button" disabled>Attendance Completed</button>
        <?php endif; ?>
      <?php endif; ?>

      <a id="btnQuiz"
         class="btn <?= $quizLocked ? 'btn-gray btn-locked' : 'btn-warning' ?>"
         href="<?= $quizLocked ? '#' : ('quiz.php?speaker_id=' . (int)$speakerId) ?>"
         <?= $quizLocked ? 'data-locked="1" aria-disabled="true" tabindex="-1" role="button"' : '' ?>>
         <?= $hasQuiz ? ($hasSubmitted ? 'Quiz Completed' : 'Take Quiz') : 'Quiz Unavailable' ?>
      </a>

      <a id="btnEvaluation"
         class="btn <?= $evalLocked ? 'btn-gray btn-locked' : 'btn-ready' ?>"
         href="<?= $evalLocked ? '#' : e($evaluationUrl) ?>"
         <?= $evalLocked ? 'data-locked="1" aria-disabled="true" tabindex="-1" role="button"' : '' ?>>
         <?= $hasEvaluation ? 'Evaluation Completed' : 'Speaker Evaluation' ?>
      </a>

      <a id="btnPresentation"
         class="btn <?= $presLocked ? 'btn-gray btn-locked' : 'btn-ready' ?>"
         href="<?= $presLocked ? '#' : e($presentationUrl) ?>"
         <?= $presLocked ? 'data-locked="1" aria-disabled="true" tabindex="-1" role="button"' : 'target="_blank" rel="noopener"' ?>>
         Download Speaker's Presentation
      </a>
    </div>
  </div>

  <?php if (false): ?>
  <div id="attendanceForm" class="att-card" style="display:none">
    <div class="card" style="border-radius:12px;">
      <div class="card-body">
        <h6 class="card-title">Mark Attendance</h6>
        <form action="../api/attend.php" method="post" class="mt-3" novalidate>
          <div class="mb-2">
            <label class="form-label">Name</label>
            <input type="text" name="attendee_name" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="attendee_email" class="form-control" required>
          </div>
          <input type="hidden" name="speaker_id" value="<?= (int)($_GET['id'] ?? 0) ?>">
          <button class="btn btn-primary">Submit Attendance</button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Toast container -->
<div class="position-fixed top-0 end-0 p-3" style="z-index:1080">
  <div id="toastRequiredEmail" class="toast align-items-center text-bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="2500">
    <div class="d-flex">
      <div class="toast-body" id="toastRequiredEmailMsg">Email is required.</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>

<!-- Bootstrap JS (needed for toast) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<script>
const btnAttend = document.getElementById('btnAttendance');  // NEW guard
if (btnAttend) {
  btnAttend.addEventListener('click', function(){
    const formWrap = document.getElementById('attendanceForm');
    formWrap.style.display='block';
    window.scrollTo({top: formWrap.offsetTop-40, behavior:'smooth'});
  });
}

// Guard: only bind if cancel button exists
const cancelBtn = document.getElementById('cancelAttend');
if (cancelBtn) {
  cancelBtn.addEventListener('click', function(){
    document.getElementById('attendanceForm').style.display='none';
  });
}

function showToast(msg) {
  const el = document.getElementById('toastRequiredEmail');
  const body = document.getElementById('toastRequiredEmailMsg');
  if (body) body.textContent = msg || 'Email is required.';
  const t = bootstrap.Toast.getOrCreateInstance(el);
  t.show();
}

(function(){
  const form = document.querySelector('#attendanceForm form');
  if (!form) return;

  form.addEventListener('submit', function(e){
    // Use HTML5 validation but show custom toast instead of page refresh
    const email = form.querySelector('input[name="attendee_email"]');
    const isEmpty = !email || email.value.trim() === '';

    // When novalidate is set, checkValidity() returns false if any required/format invalid
    if (isEmpty) {
      e.preventDefault();
      e.stopPropagation();
      showToast('Email is required.');
      return;
    }
    if (!email.checkValidity()) {
      e.preventDefault();
      e.stopPropagation();
      showToast('Enter a valid email address.');
      return;
    }
    // Optionally validate name too (already required)
    const name = form.querySelector('input[name="attendee_name"]');
    if (!name || name.value.trim() === '') {
      e.preventDefault();
      e.stopPropagation();
      showToast('Name is required.');
      return;
    }
    // If all good, allow submit
  });
})();

// Gate clicks for locked buttons; show toast feedback
(function(){
  const evalBtn = document.getElementById('btnEvaluation');
  const presBtn = document.getElementById('btnPresentation');
  const quizBtn = document.getElementById('btnQuiz');
  function handleLockedClick(e, el) {
    if (!el || !el.hasAttribute('data-locked')) return;
    e.preventDefault(); e.stopPropagation();
    const id = el.id || '';
    let msg = 'This action is currently unavailable.';
    if (id === 'btnQuiz') {
      <?php if ($hasSubmitted): ?>
        msg = 'You already completed the quiz.';
      <?php elseif (!$hasQuiz): ?>
        msg = 'Quiz is unavailable.';
      <?php else: ?>
        msg = 'Quiz is locked.';
      <?php endif; ?>
    }
    if (id === 'btnEvaluation') msg = 'Please complete the quiz first.';
    if (id === 'btnPresentation') {
      <?php if (!$hasEvaluation): ?>
        msg = 'Please complete the evaluation first.';
      <?php elseif (!$hasPresentation): ?>
        msg = 'Presentation is not available yet.';
      <?php endif; ?>
    }
    showToast(msg);
  }
  if (evalBtn) evalBtn.addEventListener('click', e => handleLockedClick(e, evalBtn));
  if (presBtn) presBtn.addEventListener('click', e => handleLockedClick(e, presBtn));
  if (quizBtn) quizBtn.addEventListener('click', e => handleLockedClick(e, quizBtn));
})();
</script>
</body>
</html>