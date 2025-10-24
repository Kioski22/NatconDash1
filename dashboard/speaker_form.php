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
  SELECT s.*, fr.name AS room_name, fr.id AS room_id, fr.day_number, fr.day_id AS day_id, fd.label AS day_label
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

// Best-effort migration: ensure score column exists for pass checks
$db->query("ALTER TABLE quiz_responses ADD COLUMN IF NOT EXISTS score INT NULL");

if ($hasQuiz && $user_id > 0 && $quizId > 0) {
  // Determine if the user has PASSED (if passing_score is set) or simply submitted (legacy with no passing requirement)
  if ($st = $db->prepare("SELECT IFNULL(q.passing_score, NULL) AS ps, IFNULL(qr.score, NULL) AS sc FROM quiz_responses qr JOIN quizzes q ON q.id = qr.quiz_id WHERE qr.quiz_id = ? AND qr.user_id = ? LIMIT 1")) {
    $st->bind_param('ii', $quizId, $user_id);
    $st->execute();
    $st->bind_result($ps, $sc);
    if ($st->fetch()) {
      if ($ps === null) { $hasSubmitted = true; }
      else { $hasSubmitted = (is_numeric($sc) && is_numeric($ps) && (int)$sc >= (int)$ps); }
    }
    $st->close();
  }
}
// Removed legacy session fallback to avoid false positives (pass-only save means no row on fail)

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
  <?php if (!empty($photoUrl)): ?>
  <link rel="preload" as="image" href="<?= e($photoUrl) ?>">
  <?php endif; ?>

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
    
  .hero{background:#fff;color:#0b2a59;border-radius:14px;padding:32px;display:flex;gap:30px;align-items:flex-start;margin-bottom:30px;box-shadow:0 6px 22px -6px rgba(0,0,0,.12);}  
  .avatar{width:110px;height:110px;border-radius:999px;overflow:hidden;background:#f1f3f5;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
  .avatar img{width:100%;height:100%;object-fit:cover}
  .hero h1{font-size:1.75rem;font-weight:700;margin:0 0 8px;line-height:1.25;background:linear-gradient(90deg,#111e6c,#1d4ed8);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
  .hero h2{font-size:1.25rem;font-weight:600;margin:0 0 4px;color:#0f235f}
  .session-meta{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}
  .badge-room{background:#111e6c;color:#fff;font-size:.8rem;padding:8px 14px;border-radius:40px;letter-spacing:.5px;display:inline-flex;align-items:center;gap:6px;box-shadow:0 2px 6px rgba(17,30,108,.35)}
  .badge-day{background:#e0e7ff;color:#1e3a8a;font-weight:600;font-size:.72rem;padding:6px 12px;border-radius:30px}
  .time-chip{background:#fff;border:2px solid #111e6c;color:#111e6c;font-size:.72rem;padding:6px 12px;border-radius:30px;font-weight:600}
  
  /* Mobile responsive layout */
  @media (max-width: 768px) {
    .hero {
      flex-direction: column;
      align-items: center;
      text-align: center;
      padding: 24px 20px;
    }
    .avatar {
      width: 140px;
      height: 140px;
      margin-bottom: 16px;
    }
    .hero h1 {
      font-size: 1.4rem;
      word-wrap: break-word;
      overflow-wrap: break-word;
      hyphens: auto;
    }
    .hero h2 {
      font-size: 1.1rem;
    }
    .session-meta {
      justify-content: center;
      margin-top: 12px;
    }
  }
  
  @media (max-width: 576px) {
    .hero {
      padding: 20px 16px;
    }
    .avatar {
      width: 120px;
      height: 120px;
    }
    .hero h1 {
      font-size: 1.25rem;
    }
    .hero h2 {
      font-size: 1rem;
    }
    .badge-room, .badge-day, .time-chip {
      font-size: 0.7rem;
      padding: 6px 10px;
    }
  }
  
.panel{background:rgba(255,255,255,0.07);padding:26px;border-radius:14px;text-align:center}
.panel{background:rgba(255,255,255,0.07);padding:26px;border-radius:14px;text-align:center}
.actions .btn{width:250px;margin:8px auto;border-radius:22px;padding:10px 16px;font-weight:600;}

@media (max-width: 576px) {
  .panel {
    padding: 20px 16px;
  }
  .actions .btn {
    width: 100%;
    max-width: 280px;
  }
}

.btn-yellow{background:#f6c10f;border:none;color:#0b2a59;font-weight:700}
.btn-gray{background:#6b7280;border:none;color:#fff}
.att-card{max-width:560px;margin:18px auto}
/* NEW: locked state visual */
.btn-locked{opacity:.55; cursor:not-allowed; pointer-events:none;}  /* NEW: block clicks */
/* NEW: unlocked (clickable) state */
.btn-ready{background:#22c55e;border:none;color:#fff}
.btn-ready:hover{filter:brightness(0.95)}
.btn-warning{background:linear-gradient(135deg,#f59e0b,#d97706);border:none;color:#0b2a59;font-weight:600}
.btn-warning:hover{filter:brightness(.95)}

/* Visible back button */
.btn-back{background:rgba(255,255,255,0.9);border:2px solid rgba(17,30,108,0.35);color:#0b2a59;font-weight:700}
.btn-back:hover{background:#ffffff;transform:translateY(-1px)}

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

@media (max-width: 576px) {
  .steps {
    gap: 8px;
    margin-bottom: 16px;
  }
  .step {
    min-width: 80px;
    padding: 8px 12px;
    font-size: 0.65rem;
  }
  .step .icon {
    width: 14px;
    height: 14px;
    font-size: 0.6rem;
  }
}
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
            <?php
              // Derive main program day link for returning to the correct day page
              $dayIdBack = isset($s['day_id']) ? (int)$s['day_id'] : 0;
              if ($dayIdBack <= 0 && !empty($s['room_id'])) {
                if ($stTmp = $db->prepare("SELECT day_id FROM function_rooms WHERE id=? LIMIT 1")) {
                  $stTmp->bind_param('i', $s['room_id']);
                  if ($stTmp->execute()) { $stTmp->bind_result($tmpDid); if ($stTmp->fetch()) { $dayIdBack = (int)$tmpDid; } }
                  $stTmp->close();
                }
              }
              $backLink = 'main_program_day.php' . ($dayIdBack > 0 ? ('?day_id=' . $dayIdBack) : '');
            ?>
            <a class="nav-link fw-semibold d-md-block px-3" href="<?= e($backLink) ?>">&lt; Back to Day</a>
          </li>
        </ul>
      </div>
    </div>
  </nav>

<div class="container py-4">
  <div class="hero">
    <div class="avatar">
      <?php if (!empty($photoUrl)): ?>
        <img src="<?= e($photoUrl) ?>" alt="Speaker" width="110" height="110" decoding="async" fetchpriority="high">
      <?php else: ?>
        <svg width="110" height="110" viewBox="0 0 24 24"><rect width="24" height="24" rx="4" fill="#e5e7eb"/><path d="M12 12a3 3 0 100-6 3 3 0 000 6z" fill="#9ca3af"/><path d="M4 20c0-3.314 2.686-6 6-6h4c3.314 0 6 2.686 6 6" fill="#9ca3af"/></svg>
      <?php endif; ?>
    </div>
    <div style="flex:1;min-width:0">
      <h1><?= e(($s['topic'] ?? '') !== '' ? $s['topic'] : ($s['title'] ?: 'Session')) ?></h1>
      <h2><?= e($s['name']) ?></h2>
      <div class="session-meta">
        <div class="badge-room" title="Function Room">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21V8a2 2 0 012-2h3l2-3h4l2 3h3a2 2 0 012 2v13"/><path d="M16 21V11H8v10"/></svg>
          <?= e($s['room_name'] ?: 'Room TBA') ?>
        </div>
        <div class="badge-day"><?= e($s['day_label'] ?: ('Day '.((int)$s['day_number']))) ?></div>
        <?php if(!empty($s['start_time'])): ?>
          <div class="time-chip"><?= fmt_time($s['start_time']) ?><?= !empty($s['end_time']) ? ' - '.fmt_time($s['end_time']) : '' ?></div>
        <?php endif; ?>
      </div>
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
    <h5 class="mb-3">Session Progress</h5>
    <ul class="steps" id="stepTracker">
      <li class="step" data-step="quiz"><span class="icon">1</span>Quiz</li>
      <li class="step" data-step="evaluation"><span class="icon">2</span>Evaluation</li>
      <li class="step" data-step="presentation"><span class="icon">3</span>Presentation</li>
    </ul>
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
         <?= $hasPresentation ? 'Download Presentation' : 'Presentation Unavailable' ?>
      </a>

    <?php $backLink2 = isset($backLink) ? $backLink : 'programs.php'; ?>
    <a id="btnBackPrograms"
      class="btn btn-back"
      href="<?= e($backLink2) ?>">
      <i class="bi bi-arrow-left me-1"></i> Back to Day
    </a>
    </div>
    <p class="small opacity-75 mt-3 mb-0 text-start" style="max-width:640px;margin-left:auto;margin-right:auto;">
      Flow: Complete the quiz (if available) → Submit the evaluation → Download the presentation.
    </p>
    <?php if ($user_id <= 0): ?><div class="alert alert-warning text-dark mt-3 mb-0 p-2 small">Please log in to proceed through the steps.</div><?php endif; ?>
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
let hasEval = <?= $hasEvaluation ? 'true':'false' ?>;
const hasQuiz = <?= $hasQuiz ? 'true':'false' ?>;
const quizSubmitted = <?= $hasSubmitted ? 'true':'false' ?>;
let hasPresentation = <?= $hasPresentation ? 'true':'false' ?>;
const speakerId = <?= (int)$speakerId ?>;

function showToast(msg) {
  const el = document.getElementById('toastRequiredEmail');
  const body = document.getElementById('toastRequiredEmailMsg');
  if (body) body.textContent = msg || 'Email is required.';
  const t = bootstrap.Toast.getOrCreateInstance(el);
  t.show();
}

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

// Update step tracker
function updateSteps(){
  const map = {
    quiz: !hasQuiz ? 'na' : (quizSubmitted ? 'done' : 'active'),
    evaluation: hasEval ? 'done' : ((hasQuiz && !quizSubmitted) ? 'locked' : 'active'),
    presentation: hasEval ? (hasPresentation ? 'active' : 'locked') : 'locked'
  };
  
  document.querySelectorAll('#stepTracker .step').forEach((el,i)=>{
    const key = el.getAttribute('data-step');
    const state = map[key] || 'locked';
    el.classList.remove('step-active','step-done','step-locked','step-na');
    el.classList.add('step-'+state);
    const icon = el.querySelector('.icon');
    if(icon){ 
      if(!icon.dataset.num) icon.dataset.num=(i+1).toString(); 
      icon.textContent = state==='done' ? '✓' : icon.dataset.num; 
    }
  });
}

updateSteps();

// Poll evaluation completion
if(!hasEval){
  const pollMs = 6000;
  const poll = setInterval(async () => {
    try {
      const r = await fetch('../api/eval_get.php?speaker_id='+speakerId,{credentials:'same-origin'});
      if(!r.ok) return;
      const j = await r.json();
      if(j && j.found){
        hasEval = true;
        clearInterval(poll);
        const btnEval = document.getElementById('btnEvaluation');
        if(btnEval){
          btnEval.textContent='Evaluation Completed';
          btnEval.classList.add('btn-gray','btn-locked');
          btnEval.setAttribute('data-locked','1');
        }
        if(hasPresentation){
          const btnPres = document.getElementById('btnPresentation');
          if(btnPres){
            btnPres.classList.remove('btn-gray','btn-locked');
            btnPres.classList.add('btn-ready');
            btnPres.removeAttribute('data-locked');
            btnPres.setAttribute('target','_blank');
            btnPres.setAttribute('rel','noopener');
          }
        }
        updateSteps();
      }
    } catch(e){}
  }, pollMs);
}
</script>
</body>
</html>