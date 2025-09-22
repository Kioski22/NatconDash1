<?php
session_start();
require_once __DIR__ . '/../db.php';

// normalize DB connection
if (isset($conn) && $conn instanceof mysqli)      $db = $conn;
elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli;
else { $db = null; }
if (!($db instanceof mysqli)) { http_response_code(500); echo "DB unavailable"; exit; }

// helpers
if (!function_exists('e')) {
  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

$speaker_id = isset($_GET['speaker_id']) && is_numeric($_GET['speaker_id']) ? (int)$_GET['speaker_id'] : 0;
if ($speaker_id <= 0) { http_response_code(400); echo "Invalid speaker."; exit; }

// optional: fetch speaker name for header
$speakerName = 'Speaker';
if ($st = $db->prepare("SELECT name FROM speakers WHERE id = ? LIMIT 1")) {
  $st->bind_param('i', $speaker_id);
  $st->execute();
  $st->bind_result($speakerName);
  $st->fetch();
  $st->close();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Quiz — <?= e($speakerName) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#0b2a59; color:#fff; font-family: system-ui, -apple-system, "Segoe UI", Roboto; }
    .wrap { max-width: 900px; margin: 24px auto; padding: 16px; }
    .card { background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.1); }
    .card .form-check-label { color:#e9eefc; }
    .muted { color:#b9c8e6; }
    .badge-soft { background: rgba(255,255,255,0.12); color:#fff; }
    textarea.form-control { background: rgba(255,255,255,0.08); color:#fff; border-color: rgba(255,255,255,0.25); }
    textarea.form-control:focus { background: rgba(255,255,255,0.12); color:#fff; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4 class="mb-0">Quiz — <?= e($speakerName) ?></h4>
      <a class="btn btn-outline-light btn-sm" href="speaker_form.php?id=<?= (int)$speaker_id ?>">← Back to Speaker</a>
    </div>

    <div id="status" class="alert d-none" role="alert"></div>

    <div id="quizCard" class="card">
      <div class="card-body">
        <div id="loading" class="muted">Loading quiz...</div>
        <form id="quizForm" class="d-none">
          <input type="hidden" name="quiz_id" id="quiz_id">
          <input type="hidden" name="speaker_id" id="speaker_id" value="<?= (int)$speaker_id ?>">

          <div id="questionsWrap"></div>

          <div class="d-grid mt-3">
            <button id="submitBtn" class="btn btn-warning">Submit Answers</button>
          </div>
        </form>
      </div>
    </div>

    <div id="resultCard" class="card mt-3 d-none">
      <div class="card-body">
        <h5 class="mb-2">Result</h5>
        <div id="resultText" class="lead"></div>
      </div>
    </div>
  </div>

  <script>
  const speakerId = <?= (int)$speaker_id ?>;
  const statusEl = document.getElementById('status');
  const loadingEl = document.getElementById('loading');
  const quizForm = document.getElementById('quizForm');
  const questionsWrap = document.getElementById('questionsWrap');
  const submitBtn = document.getElementById('submitBtn');
  const resultCard = document.getElementById('resultCard');
  const resultText = document.getElementById('resultText');

  function showStatus(msg, type='info') {
    statusEl.textContent = msg;
    statusEl.className = 'alert alert-' + type;
    statusEl.classList.remove('d-none');
  }
  function hideStatus(){ statusEl.classList.add('d-none'); }
  function escapeHtml(s){ return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

  async function loadQuiz() {
    try {
      const res = await fetch('../api/quiz_get.php?speaker_id=' + encodeURIComponent(speakerId), { credentials: 'same-origin' });
      if (res.status === 404) { loadingEl.textContent = 'No quiz available for this speaker.'; return; }
      if (!res.ok) { loadingEl.textContent = 'Failed to load quiz.'; return; }
      const data = await res.json();
      renderQuiz(data);
    } catch (e) {
      loadingEl.textContent = 'Failed to load quiz.';
    }
  }

  function renderQuiz(data) {
    loadingEl.classList.add('d-none');
    const { quiz_id, questions, options } = data || {};
    if (!quiz_id || !Array.isArray(questions) || !Array.isArray(options) || questions.length === 0) {
      showStatus('Quiz is not configured correctly.', 'danger');
      return;
    }
    document.getElementById('quiz_id').value = quiz_id;

    questionsWrap.innerHTML = '';
    for (let i = 0; i < questions.length; i++) {
      const q = String(questions[i] ?? '');
      const opts = Array.isArray(options[i]) ? options[i] : [];
      const group = document.createElement('div');
      group.className = 'mb-3';
      group.innerHTML = `
        <div class="d-flex align-items-start justify-content-between">
          <label class="form-label me-2"><span class="badge badge-soft me-2">Q${i+1}</span> ${escapeHtml(q)}</label>
        </div>
        <div id="opts_${i}"></div>
      `;
      questionsWrap.appendChild(group);
      const optsWrap = group.querySelector('#opts_' + i);

      if (opts.length >= 2) {
        // MCQ
        opts.forEach((opt, idx) => {
          const id = `q${i}_opt${idx}`;
          const div = document.createElement('div');
          div.className = 'form-check';
          div.innerHTML = `
            <input class="form-check-input" type="radio" name="q${i}" id="${id}" value="${idx}">
            <label class="form-check-label" for="${id}">${escapeHtml(String(opt ?? ''))}</label>
          `;
          optsWrap.appendChild(div);
        });
      } else {
        // Paragraph
        const ta = document.createElement('textarea');
        ta.className = 'form-control';
        ta.name = `q${i}_text`;
        ta.rows = 3;
        ta.placeholder = 'Type your answer here...';
        optsWrap.appendChild(ta);
      }
    }
    quizForm.classList.remove('d-none');
  }

  quizForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    hideStatus();

    // Collect answers (MCQ index or paragraph text). Enforce answers for all items.
    const qGroups = Array.from(questionsWrap.querySelectorAll('[id^="opts_"]'));
    const answers = [];
    for (let i = 0; i < qGroups.length; i++) {
      const radios = qGroups[i].querySelectorAll(`input[name="q${i}"]`);
      if (radios.length > 0) {
        const chosen = Array.from(radios).find(r => r.checked);
        if (!chosen) { showStatus(`Please answer question ${i+1}.`, 'warning'); return; }
        answers.push(parseInt(chosen.value, 10));
      } else {
        const ta = qGroups[i].querySelector(`textarea[name="q${i}_text"]`);
        const txt = ta ? ta.value.trim() : '';
        if (txt === '') { showStatus(`Please provide an answer for question ${i+1}.`, 'warning'); return; }
        answers.push(txt);
      }
    }

    submitBtn.disabled = true;
    submitBtn.textContent = 'Submitting...';
    try {
      const fd = new FormData();
      fd.append('quiz_id', document.getElementById('quiz_id').value);
      fd.append('speaker_id', document.getElementById('speaker_id').value);

      // Send both JSON and non-JSON forms for compatibility
      fd.append('answers_json', JSON.stringify(answers));
      answers.forEach(a => fd.append('answers[]', typeof a === 'number' ? String(a) : a));

      const res = await fetch('../api/quiz_submit.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
      });

      const isJson = (res.headers.get('content-type') || '').includes('application/json');
      const data = isJson ? await res.json() : {};
      if (res.status === 401) { showStatus('Please log in to take the quiz.', 'danger'); return; }
      if (res.status === 409) { showStatus('You already submitted this quiz.', 'warning'); return; }
      if (!res.ok) { showStatus(data.error || 'Submission failed.', 'danger'); return; }

      quizForm.classList.add('d-none');
      resultCard.classList.remove('d-none');
      const total = data.total ?? answers.length;
      resultText.textContent = `You scored ${data.score} out of ${total}.`;
    } catch (err) {
      showStatus('Network error. Please try again.', 'danger');
    } finally {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Submit Answers';
    }
  });

  loadQuiz();
  </script>
</body>
</html>