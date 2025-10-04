<?php

session_start();
require_once __DIR__ . '/../db.php';

// DB normalize
if (isset($conn) && $conn instanceof mysqli) $db = $conn;
elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli;
else { http_response_code(500); echo "DB unavailable"; exit; }

if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); } }

$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($user_id <= 0) { header('Location: ../Credentials/login.php'); exit; }

$speaker_id = isset($_GET['speaker_id']) ? (int)$_GET['speaker_id'] : 0;
if ($speaker_id <= 0) { http_response_code(400); echo "Invalid speaker."; exit; }

// Fetch speaker
$spk = ['name'=>'Speaker','title'=>'','program_type'=>''];
if ($st = $db->prepare("SELECT name, title, program_type FROM speakers WHERE id = ? LIMIT 1")) {
  $st->bind_param('i', $speaker_id);
  $st->execute();
  $st->bind_result($spk['name'], $spk['title'], $spk['program_type']);
  $st->fetch(); $st->close();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Speaker Evaluation — <?= e($spk['name']) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;700&display=swap" rel="stylesheet">
  <style>
    :root{
      --gf-primary:#111e6c;      /* Google Forms purple */
      --gf-primary-d:#111e6c;
      --gf-bg:#f1f3f4;
      --gf-card:#ffffff;
      --gf-text:#202124;
      --gf-muted:#5f6368;
      --gf-chip:#e8eaed;
      --gf-chip-text:#202124;
      --gf-chip-active:#e8def8;
      --gf-danger:#d93025;
    }
    body.gf-body{ background:var(--gf-bg); color:var(--gf-text); font-family:'Poppins';}
    .gf-header{
      background:linear-gradient(var(--gf-primary));
      color:#fff; min-height:160px; display:flex; align-items:flex-end;
    }
    .gf-header .inner{ width:100%; max-width:720px; margin:0 auto; padding:24px 16px; }
    .gf-header .back-link{ color:#fff; opacity:.9; text-decoration:none; }
    .gf-header .back-link:hover{ opacity:1; text-decoration:underline; }

    .gf-wrap{ max-width:720px; margin:-64px auto 40px; padding:0 16px; padding-top:70px;}
    .gf-card{
      background:var(--gf-card);
      border-radius:8px;
      box-shadow:0 1px 2px rgba(0,0,0,.08), 0 1px 3px rgba(0,0,0,.06);
      margin-bottom:14px;
      overflow:hidden;
    }
    .gf-card .section{ padding:22px 24px; }
    .gf-title{ font-size:1.6rem; font-weight:700; margin:0 0 6px; }
    .gf-sub{ color:var(--gf-muted); margin:0; }
    .gf-required-note{ color:var(--gf-danger); font-size:.9rem; margin-top:10px; }

    .q-title{ font-weight:500; margin-bottom:10px; }
    .q-required::after{ content:" *"; color:var(--gf-danger); }
    .chip-group{ display:flex; flex-wrap:wrap; gap:10px; }
    .chip-input{ position:absolute; opacity:0; pointer-events:none; }
    .chip{
      display:inline-flex; align-items:center; justify-content:center;
      min-width:44px; height:40px; padding:0 14px; border-radius:20px;
      background:var(--gf-chip); color:var(--gf-chip-text);
      border:2px solid transparent; cursor:pointer; user-select:none;
      transition:all .15s ease;
      font-weight:500;
    }
    .chip:hover{ filter:brightness(.98); }
    .chip-input:checked + .chip{
      background:var(--gf-chip-active);
      border-color:var(--gf-primary);
      color:var(--gf-text);
      box-shadow:inset 0 0 0 1px var(--gf-primary);
    }

    .gf-textarea{ resize:vertical; min-height:100px; }

    .gf-actions{ padding:16px 24px 24px; display:flex; justify-content:flex-start; gap:12px; }
    .btn-primary.gf{
      background:var(--gf-primary); border-color:var(--gf-primary);
      border-radius:24px; padding:.6rem 1.3rem; font-weight:600;
    }
    .btn-primary.gf:hover{ background:var(--gf-primary-d); border-color:var(--gf-primary-d); }

    .gf-alert{ margin-bottom:12px; border-radius:8px; }
    .done-card{ border:1px solid #a5d6a7; background:#e8f5e9; color:#1b5e20; }
  </style>
</head>
<body class="gf-body">
  <div class="gf-header">
    <div class="inner">
  <?php $isSub = (isset($_GET['sub']) && $_GET['sub']=='1') || strcasecmp($spk['program_type'],'sub')===0; ?>
  <a class="back-link small" href="<?= $isSub ? 'sub_speaker_form.php' : 'speaker_form.php' ?>?id=<?= (int)$speaker_id ?>">&lt; Back to session</a>
      <h1 class="mt-2 mb-0">Speaker Evaluation</h1>
      <p class="mb-0"><?= e($spk['name']) ?><?= $spk['title'] ? ' — ' . e($spk['title']) : '' ?></p>
    </div>
  </div>

  <div class="gf-wrap">
    <div id="alert" class="alert d-none gf-alert" role="alert"></div>

    <!-- Intro card (like Google Forms header card) -->
    <div class="gf-card mt-5">
      <div class="section">
        <div class="gf-title">Session Feedback</div>
        <p class="gf-sub">Please rate the speaker and share any comments to help us improve.</p>
        <div class="gf-required-note">* Required</div>
      </div>
    </div>

    <!-- Form card -->
    <div class="gf-card">
      <form id="evalForm" class="section">
        <input type="hidden" name="speaker_id" value="<?= (int)$speaker_id ?>">

        <?php
          $fields = [
            'content_quality' => 'Content Quality',
            'speaker_clarity' => 'Speaker Clarity',
            'engagement' => 'Engagement',
            'overall' => 'Overall Rating'
          ];
          foreach ($fields as $name => $label):
        ?>
          <div class="mb-4">
            <div class="q-title q-required"><?= e($label) ?></div>
            <div class="chip-group">
              <?php for ($i=1; $i<=5; $i++): $id = $name.'_'.$i; ?>
                <input class="chip-input" type="radio" name="<?= e($name) ?>" id="<?= e($id) ?>" value="<?= $i ?>">
                <label class="chip" for="<?= e($id) ?>"><?= $i ?></label>
              <?php endfor; ?>
            </div>
          </div>
        <?php endforeach; ?>

        <div class="mb-1">
          <div class="q-title">Comments (optional)</div>
          <textarea class="form-control gf-textarea" name="comments" placeholder="Share your feedback..."></textarea>
        </div>

        <div class="gf-actions">
          <button class="btn btn-primary gf" type="submit" id="submitBtn">Submit</button>
          <a class="btn btn-light" href="<?= $isSub ? 'sub_speaker_form.php' : 'speaker_form.php' ?>?id=<?= (int)$speaker_id ?>">Cancel</a>
        </div>
      </form>
    </div>

    <div id="doneCard" class="gf-card d-none">
      <div class="section done-card">
        <h5 class="mb-1">Thank you!</h5>
        <p class="mb-0">Your evaluation has been recorded.</p>
      </div>
    </div>
  </div>

<script>
const alertEl = document.getElementById('alert');
function showAlert(msg, type='info'){
  alertEl.className = 'alert alert-' + type + ' gf-alert';
  alertEl.textContent = msg;
  alertEl.classList.remove('d-none');
}

const form = document.getElementById('evalForm');
const btn = document.getElementById('submitBtn');
const doneCard = document.getElementById('doneCard');

// Prefill if already submitted
(async function preload(){
  try {
    const res = await fetch('../api/eval_get.php?speaker_id=' + <?= (int)$speaker_id ?>, { credentials: 'same-origin' });
    if (res.status === 404) return;
    if (!res.ok) return;
    const data = await res.json();
    if (data && data.found && data.evaluation) {
      form.classList.add('d-none');
      doneCard.classList.remove('d-none');
      showAlert('You already submitted an evaluation for this speaker.', 'warning');
    }
  } catch(e){}
})();

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  alertEl.classList.add('d-none');

  const required = ['content_quality','speaker_clarity','engagement','overall'];
  for (const name of required) {
    if (!form.querySelector(`input[name="${name}"]:checked`)) {
      showAlert('Please rate all required items (1-5).', 'warning');
      return;
    }
  }

  const fd = new FormData(form);
  btn.disabled = true; btn.textContent = 'Submitting...';
  try {
    const res = await fetch('../api/eval_submit.php', { method:'POST', body: fd, credentials:'same-origin' });
    const isJson = (res.headers.get('content-type') || '').includes('application/json');
    const data = isJson ? await res.json() : {};
    if (res.status === 403) { showAlert(data.error || 'Please complete the quiz first.', 'danger'); return; }
    if (res.status === 409) { showAlert('You already submitted an evaluation for this speaker.', 'warning'); form.classList.add('d-none'); doneCard.classList.remove('d-none'); return; }
    if (!res.ok) { showAlert(data.error || 'Submission failed.', 'danger'); return; }

    form.classList.add('d-none');
    doneCard.classList.remove('d-none');
    showAlert('Evaluation submitted successfully.', 'success');

    // Mark speaker as evaluated in session
    <?php
    $_SESSION['evaluated_speakers'] = $_SESSION['evaluated_speakers'] ?? [];
    $_SESSION['evaluated_speakers'][(int)$_GET['speaker_id']] = 1;
    ?>
  } catch(err) {
    showAlert('Network error. Please try again.', 'danger');
  } finally {
    btn.disabled = false; btn.textContent = 'Submit';
  }
});
</script>
</body>
</html>