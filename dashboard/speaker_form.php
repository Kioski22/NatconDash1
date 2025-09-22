<?php

session_start();
require_once __DIR__ . '/../db.php';

// normalize DB connection
if (isset($conn) && $conn instanceof mysqli) {
    $db = $conn;
} elseif (isset($mysqli) && $mysqli instanceof mysqli) {
    $db = $mysqli;
} else {
    $db = null;
}
if (!($db instanceof mysqli)) { http_response_code(500); echo "DB unavailable"; exit; }

if (!function_exists('e')) {
    function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// Add: asset URL normalizer (works from any subfolder, e.g., /dashboard)
if (!function_exists('asset_url')) {
    function asset_url($path) {
        $path = (string)$path;
        if ($path === '') return '';
        if (preg_match('#^https?://#i', $path)) return $path; // absolute URL
        $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\'); // -> /NatconDash1
        if ($base === '' || $base === '.') $base = '';
        return $base . '/' . ltrim($path, '/'); // -> /NatconDash1/uploads/...
    }
}

$sid = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
if ($sid <= 0) { echo "Speaker not found."; exit; }

// fetch speaker + room/day
$stmt = $db->prepare("
  SELECT s.*, fr.name AS room_name, fr.id AS room_id, fr.day_number, fd.label AS day_label
  FROM speakers s
  LEFT JOIN function_rooms fr ON fr.id = s.room_id
  LEFT JOIN function_room_days fd ON fd.id = fr.day_id
  WHERE s.id = ? LIMIT 1
");
$stmt->bind_param('i',$sid);
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

function fmt_time($t){ if (!$t) return ''; $dt=@date_create($t); return $dt?date_format($dt,'g:i A'):htmlspecialchars((string)$t,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title><?= e($s['name']) ?> — Speaker</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:#0b2a59;color:#fff;font-family:system-ui,Segoe UI,Roboto}
.topbar{padding:12px 18px;color:#fff;display:flex;justify-content:space-between;align-items:center}
.hero{background:#fff;color:#0b2a59;border-radius:8px;padding:28px;display:flex;gap:24px;align-items:center;margin-bottom:22px}
.avatar{width:120px;height:120px;border-radius:999px;overflow:hidden;background:#f1f3f5;display:flex;align-items:center;justify-content:center}
.avatar img{width:100%;height:100%;object-fit:cover}
.panel{background:rgba(255,255,255,0.06);padding:28px;border-radius:14px;text-align:center}
.btn-yellow{background:#f6c10f;border:none;color:#0b2a59;font-weight:700}
.btn-gray{background:#7f8793;border:none;color:#fff}
.actions .btn{width:260px;margin:8px auto;border-radius:20px;padding:10px 18px}
.att-card{max-width:560px;margin:18px auto}
</style>
</head>
<body>
<div class="container py-4">
  <div class="topbar">
    <div><strong>73RD PSME NATIONAL CONVENTION</strong></div>
    <div><a href="day.php?day=<?= urlencode((string)$s['day_number']?:'1') ?>" style="color:#fff;text-decoration:none">&lt; Back to Day</a></div>
  </div>

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

    <div class="actions d-flex flex-column align-items-center">
      <button id="btnAttendance" class="btn btn-yellow">Attendance</button>
      <a class="btn btn-gray" href="#">Speaker Evaluation</a>
      <a class="btn btn-gray" href="#">Presentation</a>
      <div class="mt-3 d-flex gap-2">
        <a class="btn btn-warning" href="quiz.php?speaker_id=<?= (int)($_GET['id'] ?? 0) ?>">Take Quiz</a>
      </div>
      <!-- certificate button may be added by admin toggle elsewhere -->
    </div>
  </div>

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
document.getElementById('btnAttendance').addEventListener('click', function(){
  const formWrap = document.getElementById('attendanceForm');
  formWrap.style.display='block';
  window.scrollTo({top: formWrap.offsetTop-40, behavior:'smooth'});
});

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
</script>
</body>
</html>