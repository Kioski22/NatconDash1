<?php
session_start();
require_once __DIR__ . '/../db.php';

// normalize DB connection
if (isset($conn) && $conn instanceof mysqli)      $db = $conn;
elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli;
else { $db = null; }
if (!($db instanceof mysqli)) { http_response_code(500); echo "DB unavailable"; exit; }

// Require login before accessing the quiz UI (evaluation already enforced similarly)
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($user_id <= 0) {
  // Optional: remember intended page
  $_SESSION['intended_url'] = $_SERVER['REQUEST_URI'] ?? 'dashboard.php';
  $_SESSION['login_notice'] = 'Please log in to take the quiz.';
  header('Location: ../Credentials/login.php');
  exit;
}

// helpers
if (!function_exists('e')) {
  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

$speaker_id = isset($_GET['speaker_id']) && is_numeric($_GET['speaker_id']) ? (int)$_GET['speaker_id'] : 0;
if ($speaker_id <= 0) { http_response_code(400); echo "Invalid speaker."; exit; }

// fetch speaker details
$speakerName = 'Speaker';
$speakerTitle = '';
$speakerTopic = '';
$programType = '';
if ($st = $db->prepare("SELECT name, title, topic, program_type FROM speakers WHERE id = ? LIMIT 1")) {
  $st->bind_param('i', $speaker_id);
  $st->execute();
  $st->bind_result($speakerName, $speakerTitle, $speakerTopic, $programType);
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
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: #111e6c;
      --primary-light: #1116ec;
      --success: #09ff00ff;
      --warning: #f59e0b;
      --danger: #ef4444;
      --info: #06b6d4;
      --dark: #1e293b;
      --light: #f8fafc;
      --quiz-gradient: linear-gradient(135deg, #111e6c 0%, #6567d3ff 100%);
      --card-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.15);
      --border-radius: 20px;
    }

    * { font-family: 'Inter', system-ui, -apple-system, sans-serif; }

    body {
      background: var(--quiz-gradient);
      min-height: 100vh;
      padding: 1rem 0;
      color: #fff;
    }

    .quiz-container {
      max-width: 1200px;
      margin: 0 auto;
      padding: 0 1rem;
    }

    /* Header */
    .quiz-header {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(20px);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: var(--border-radius);
      padding: 2rem;
      margin-bottom: 2rem;
      box-shadow: var(--card-shadow);
      text-align: center;
      color: var(--dark);
    }

    .quiz-title {
      background: linear-gradient(135deg, var(--primary), var(--primary-light));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      font-weight: 700;
      font-size: 2.2rem;
      margin: 0;
    }

    .quiz-subtitle {
      color: #64748b;
      font-size: 1.1rem;
      margin-top: 0.5rem;
      font-weight: 500;
    }

    .back-button {
      background: rgba(255, 255, 255, 0.2);
      color: var(--dark);
      border: 2px solid #00000060;
      border-radius: 15px;
      padding: 8px 20px;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.3s ease;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }

    .back-button:hover {
      background: rgba(255, 255, 255, 0.3);
      color: var(--dark);
      transform: translateY(-2px);
    }

    /* Enhanced Cards */
    .quiz-card {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(20px);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: var(--border-radius);
      box-shadow: var(--card-shadow);
      overflow: hidden;
      margin-bottom: 2rem;
      color: var(--dark);
    }

    .quiz-card-body {
      padding: 2.5rem;
    }

    /* Loading State */
    .loading-container {
      text-align: center;
      padding: 4rem 2rem;
    }

    .loading-spinner {
      width: 3rem;
      height: 3rem;
      border: 3px solid rgba(79, 70, 229, 0.2);
      border-top-color: var(--primary);
      border-radius: 50%;
      animation: spin 1s linear infinite;
      margin: 0 auto 1rem;
    }

    @keyframes spin {
      to { transform: rotate(360deg); }
    }

    .loading-text {
      color: #64748b;
      font-size: 1.1rem;
      font-weight: 500;
    }

    /* Questions */
    .question-container {
      background: white;
      border: 2px solid rgba(79, 70, 229, 0.1);
      border-radius: 16px;
      padding: 2rem;
      margin-bottom: 2rem;
      box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
      transition: all 0.3s ease;
    }

    .question-container:hover {
      border-color: rgba(79, 70, 229, 0.2);
      transform: translateY(-2px);
      box-shadow: 0 12px 35px rgba(0, 0, 0, 0.12);
    }

    .question-header {
      display: flex;
      align-items: center;
      gap: 1rem;
      margin-bottom: 1.5rem;
      padding-bottom: 1rem;
      border-bottom: 2px solid rgba(79, 70, 229, 0.1);
    }

    .question-number {
      background: linear-gradient(135deg, var(--primary), var(--primary-light));
      color: white;
      padding: 10px 16px;
      border-radius: 20px;
      font-weight: 700;
      font-size: 0.9rem;
      min-width: 60px;
      text-align: center;
      flex-shrink: 0;
    }

    .question-text {
      font-size: 1.2rem;
      font-weight: 600;
      color: var(--dark);
      line-height: 1.6;
      flex: 1;
    }

    /* MCQ Options */
    .mcq-options {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .mcq-option {
      background: rgba(79, 70, 229, 0.05);
      border: 2px solid rgba(79, 70, 229, 0.1);
      border-radius: 12px;
      padding: 1rem 1.5rem;
      transition: all 0.3s ease;
      cursor: pointer;
    }

    .mcq-option:hover {
      background: rgba(79, 70, 229, 0.1);
      border-color: rgba(79, 70, 229, 0.2);
      transform: translateX(4px);
    }

    .mcq-option.selected {
      background: linear-gradient(135deg, rgba(79, 70, 229, 0.15), rgba(139, 92, 246, 0.15));
      border-color: var(--primary);
      transform: translateX(4px);
    }

    .mcq-option input[type="radio"],
    .mcq-option input[type="checkbox"] {
      width: 20px;
      height: 20px;
      margin-right: 12px;
      accent-color: var(--primary);
    }

    .mcq-option label {
      font-weight: 500;
      color: var(--dark);
      cursor: pointer;
      display: flex;
      align-items: center;
      margin: 0;
      width: 100%;
    }

    /* Paragraph Questions */
    .paragraph-container {
      background: rgba(6, 182, 212, 0.05);
      border: 2px dashed rgba(6, 182, 212, 0.2);
      border-radius: 12px;
      padding: 1.5rem;
    }

    .paragraph-icon {
      text-align: center;
      margin-bottom: 1rem;
    }

    .paragraph-icon i {
      font-size: 2.5rem;
      color: var(--info);
      opacity: 0.7;
    }

    .paragraph-textarea {
      background: rgba(255, 255, 255, 0.8);
      border: 2px solid rgba(6, 182, 212, 0.2);
      border-radius: 12px;
      padding: 1rem;
      font-size: 1rem;
      font-weight: 500;
      color: var(--dark);
      resize: vertical;
      min-height: 120px;
      transition: all 0.3s ease;
      width: 100%;
    }

    .paragraph-textarea:focus {
      background: rgba(255, 255, 255, 0.95);
      border-color: var(--info);
      box-shadow: 0 0 0 4px rgba(6, 182, 212, 0.1);
      outline: none;
    }

    .paragraph-textarea::placeholder {
      color: #94a3b8;
      font-weight: 400;
    }

    /* Submit Button */
    .submit-container {
      text-align: center;
      margin-top: 3rem;
    }

    .submit-btn {
      background: linear-gradient(135deg, var(--warning), #f97316);
      color: white;
      border: none;
      border-radius: 15px;
      padding: 1rem 3rem;
      font-size: 1.1rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      box-shadow: 0 8px 25px rgba(245, 158, 11, 0.3);
      transition: all 0.3s ease;
      cursor: pointer;
      min-width: 200px;
    }

    .retry-btn {
      margin-top: 0.75rem;
      border-radius: 12px;
      border: 2px solid rgba(100,116,139,0.35);
      background: rgba(255,255,255,0.65);
      color: #334155;
      padding: 0.6rem 1.25rem;
      font-weight: 600;
      transition: all 0.2s ease;
    }

    .retry-btn:hover {
      background: rgba(255,255,255,0.9);
      transform: translateY(-1px);
    }

    .submit-btn:hover:not(:disabled) {
      transform: translateY(-3px);
      box-shadow: 0 12px 35px rgba(245, 158, 11, 0.4);
    }

    .submit-btn:disabled {
      opacity: 0.7;
      cursor: not-allowed;
      transform: none;
    }

    /* Result Card */
    .result-card {
      background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(5, 150, 105, 0.1));
      border: 2px solid rgba(16, 185, 129, 0.2);
      border-radius: var(--border-radius);
      padding: 2.5rem;
      text-align: center;
      color: var(--dark);
      animation: slideInUp 0.6s ease-out;
    }

    .result-icon {
      font-size: 4rem;
      color: var(--success);
      margin-bottom: 1rem;
    }

    .result-title {
      font-size: 1.8rem;
      font-weight: 700;
      color: var(--success);
      margin-bottom: 1rem;
    }

    .result-score {
      font-size: 2.5rem;
      font-weight: 800;
      background: linear-gradient(135deg, var(--success), #059669);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      margin-bottom: 1rem;
    }

    /* Status Alerts */
    .status-alert {
      border-radius: 15px;
      border: none;
      padding: 1rem 1.5rem;
      margin-bottom: 1.5rem;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 12px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    }

    .status-alert.alert-info {
      background: linear-gradient(135deg, rgba(6, 182, 212, 0.1), rgba(14, 165, 233, 0.1));
      color: var(--info);
      border: 2px solid rgba(6, 182, 212, 0.2);
    }

    .status-alert.alert-warning {
      background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(249, 115, 22, 0.1));
      color: var(--warning);
      border: 2px solid rgba(245, 158, 11, 0.2);
    }

    .status-alert.alert-danger {
      background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(220, 38, 38, 0.1));
      color: var(--danger);
      border: 2px solid rgba(239, 68, 68, 0.2);
    }

    /* Animations */
    @keyframes slideInUp {
      from {
        opacity: 0;
        transform: translateY(30px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .fade-in {
      animation: fadeIn 0.6s ease-out;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(20px); }
      to { opacity: 1; transform: translateY(0); }
    }

    /* Progress Indicator */
    .progress-container {
      background: rgba(255, 255, 255, 0.1);
      border-radius: 10px;
      height: 8px;
      margin: 1rem 0;
      overflow: hidden;
    }

    .progress-bar {
      background: linear-gradient(90deg, var(--primary), var(--primary-light));
      height: 100%;
      border-radius: 10px;
      transition: width 0.3s ease;
    }

    /* Responsive */
    @media (max-width: 768px) {
      .quiz-container {
        padding: 0 0.5rem;
      }
      
      .quiz-header {
        padding: 1.5rem;
      }
      
      .quiz-title {
        font-size: 1.8rem;
      }
      
      .quiz-card-body {
        padding: 1.5rem;
      }
      
      .question-container {
        padding: 1.5rem;
      }
      
      .question-header {
        /* Keep desktop layout - don't stack */
        flex-direction: row;
        align-items: center;
        gap: 1rem;
      }
      
      .question-number {
        /* Keep same size and position */
        flex-shrink: 0;
      }
      
      .question-text {
        font-size: 1.05rem;
        flex: 1;
      }
      
      .submit-btn {
        padding: 0.8rem 2rem;
        font-size: 1rem;
      }
    }

    /* Dark Mode Support */
  @media (prefers-color-scheme: dark) {
      body, .quiz-container, .quiz-card {
        background-color: #1a1a2e !important;
        color: #e2e8f0 !important;
      }
      
      .quiz-header {
        background: linear-gradient(135deg, #2d3561, #1f2544) !important;
      }
      
      .quiz-title, .quiz-subtitle {
        color: #ffffff !important;
      }
      
      .question-container {
        background: rgba(255, 255, 255, 0.05) !important;
        border-color: rgba(255, 255, 255, 0.1) !important;
      }
      
      .question-text {
        color: #f1f5f9 !important;
      }
      
      .mcq-option input[type="radio"],
      .mcq-option input[type="checkbox"] {
        /* Force visible outline for dark mode */
        filter: invert(1) hue-rotate(180deg);
        border: 2px solid #ffffff;
        background-color: #ffffff;
      }
      
      .mcq-option input[type="radio"]:checked,
      .mcq-option input[type="checkbox"]:checked {
        filter: invert(0) hue-rotate(0deg);
        background-color: var(--primary);
        border-color: var(--primary);
      }
      
      .mcq-option {
        background: rgba(255, 255, 255, 0.08) !important;
        border-color: rgba(255, 255, 255, 0.3) !important;
      }
      
      .mcq-option:hover {
        background: rgba(255, 255, 255, 0.15) !important;
        border-color: rgba(255, 255, 255, 0.4) !important;
      }
      
      .mcq-option.selected {
        background: linear-gradient(135deg, rgba(79, 70, 229, 0.4), rgba(139, 92, 246, 0.4)) !important;
        border-color: var(--primary) !important;
      }
      
      .mcq-option label {
        color: #f1f5f9 !important;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
      }
      
      /* Ensure text inside label is also visible */
      .mcq-option label * {
        color: #f1f5f9 !important;
      }
      
      .paragraph-container {
        background: rgba(255, 255, 255, 0.05) !important;
        border-color: rgba(255, 255, 255, 0.2) !important;
      }
      
      .paragraph-textarea {
        background: rgba(255, 255, 255, 0.1) !important;
        color: #f1f5f9 !important;
        border-color: rgba(255, 255, 255, 0.3) !important;
      }
      
      .paragraph-textarea:focus {
        background: rgba(255, 255, 255, 0.15) !important;
        border-color: var(--info) !important;
      }
      
      .paragraph-textarea::placeholder {
        color: #94a3b8;
      }
    }

    /* No Quiz State */
    .no-quiz-container {
      text-align: center;
      padding: 4rem 2rem;
      color: #64748b;
    }

    .no-quiz-icon {
      font-size: 5rem;
      opacity: 0.3;
      margin-bottom: 1.5rem;
    }

    .no-quiz-title {
      font-size: 1.5rem;
      font-weight: 600;
      margin-bottom: 0.5rem;
    }

    .no-quiz-text {
      font-size: 1rem;
      opacity: 0.8;
    }
  </style>
</head>
<body>
  <div class="quiz-container">
    <!-- Header -->
    <div class="quiz-header fade-in">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="flex-grow-1">
          <h1 class="quiz-title">Interactive Quiz</h1>
          <p class="quiz-subtitle"><?= e(strtoupper($speakerName)) ?></p>
        </div>
        <a class="back-button" href="speaker_form.php?id=<?= (int)$speaker_id ?>">
          <i class="bi bi-arrow-left"></i>
          Back to Speaker
        </a>
      </div>
      <div class="progress-container">
        <div class="progress-bar" id="progressBar" style="width: 0%"></div>
      </div>
    </div>

    <!-- Status Messages -->
    <div id="status" class="status-alert d-none" role="alert">
      <i class="bi bi-info-circle-fill"></i>
      <span id="statusText"></span>
    </div>

    <!-- Quiz Card -->
    <div id="quizCard" class="quiz-card fade-in">
      <div class="quiz-card-body">
        <!-- Loading State -->
        <div id="loading" class="loading-container">
          <div class="loading-spinner"></div>
          <div class="loading-text">Loading your quiz...</div>
        </div>

        <!-- Quiz Form -->
        <form id="quizForm" class="d-none">
          <input type="hidden" name="quiz_id" id="quiz_id">
          <input type="hidden" name="speaker_id" id="speaker_id" value="<?= (int)$speaker_id ?>">

          <div id="questionsWrap"></div>

          <div class="submit-container">
            <button type="submit" id="submitBtn" class="submit-btn">
              <i class="bi bi-check-circle-fill me-2"></i>
              Submit Answers
            </button>
            <div>
              <button type="button" id="retryBtn" class="retry-btn">
                <i class="bi bi-arrow-counterclockwise me-1"></i> Retry (Clear Answers)
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- Result Card -->
    <div id="resultCard" class="result-card d-none">
      <div class="result-icon">
        <i class="bi bi-trophy-fill"></i>
      </div>
      <h2 class="result-title">Quiz Completed!</h2>
      <div class="result-score" id="resultText">You scored 0 out of 0</div>
      <p class="mb-0 text-white">Thank you for participating in this quiz!</p>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
  const speakerId = <?= (int)$speaker_id ?>;
  const statusEl = document.getElementById('status');
  const statusText = document.getElementById('statusText');
  const loadingEl = document.getElementById('loading');
  const quizForm = document.getElementById('quizForm');
  const questionsWrap = document.getElementById('questionsWrap');
  const submitBtn = document.getElementById('submitBtn');
  const resultCard = document.getElementById('resultCard');
  const resultText = document.getElementById('resultText');
  const progressBar = document.getElementById('progressBar');
  const retryBtn = document.getElementById('retryBtn');

  // Modal: quiz result
  const modalHtml = `
    <div class="modal fade" id="quizResultModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:16px;">
          <div class="modal-header" style="border:none;">
            <h5 class="modal-title text-dark" id="quizResultTitle">Quiz Result</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body text-dark">
            <div class="d-flex align-items-center gap-3 mb-2">
              <i id="quizResultIcon" class="bi bi-info-circle" style="font-size:1.6rem;"></i>
              <div>
                <div id="quizResultSummary" class="fw-bold"></div>
                <div id="quizResultDetail" class="text-muted small"></div>
              </div>
            </div>
          </div>
          <div class="modal-footer" style="border:none;">
            <a id="backLink" href="speaker_form.php?id=<?= (int)$speaker_id ?>" class="btn btn-outline-secondary">
              <i class="bi bi-arrow-left me-1"></i> Back to Speaker
            </a>
            <a id="modalBackProgramsBtn" href="programs.php" class="btn btn-outline-primary d-none">
              <i class="bi bi-arrow-left me-1"></i> Back to Speakers
            </a>
            <button type="button" class="btn btn-primary" id="modalRetryBtn">
              <i class="bi bi-arrow-counterclockwise me-1"></i> Retry
            </button>
          </div>
        </div>
      </div>
    </div>`;
  document.body.insertAdjacentHTML('beforeend', modalHtml);
  const quizResultModal = new bootstrap.Modal(document.getElementById('quizResultModal'));
  const quizResultTitle = document.getElementById('quizResultTitle');
  const quizResultSummary = document.getElementById('quizResultSummary');
  const quizResultDetail = document.getElementById('quizResultDetail');
  const quizResultIcon = document.getElementById('quizResultIcon');
  const modalRetryBtn = document.getElementById('modalRetryBtn');
  const modalBackProgramsBtn = document.getElementById('modalBackProgramsBtn');
  const programType = <?= json_encode(strtolower((string)$programType)) ?>; // 'main' or 'sub' (expected)
  let listHref = 'programs.php';

  let totalQuestions = 0;

  function showStatus(msg, type='info') {
    const icons = {
      'info': 'bi-info-circle-fill',
      'warning': 'bi-exclamation-triangle-fill', 
      'danger': 'bi-x-circle-fill',
      'success': 'bi-check-circle-fill'
    };
    
    statusText.innerHTML = `<i class="bi ${icons[type] || icons.info}"></i> ${msg}`;
    statusEl.className = `status-alert alert-${type}`;
    statusEl.classList.remove('d-none');
    statusEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function hideStatus(){ 
    statusEl.classList.add('d-none'); 
  }

  function updateProgress() {
    if (totalQuestions === 0) return;
    
    const answered = Array.from(questionsWrap.querySelectorAll('.question-container')).filter(q => {
      const radios = q.querySelectorAll('input[type="radio"]');
      const checks = q.querySelectorAll('input[type="checkbox"]');
      const textarea = q.querySelector('textarea');

      if (radios.length > 0) {
        return Array.from(radios).some(r => r.checked);
      } else if (checks.length > 0) {
        return Array.from(checks).some(c => c.checked);
      } else if (textarea) {
        return textarea.value.trim() !== '';
      }
      return false;
    }).length;
    
    const percentage = (answered / totalQuestions) * 100;
    progressBar.style.width = percentage + '%';
  }

  function escapeHtml(s){ 
    return String(s ?? '').replace(/[&<>"']/g, m => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[m])); 
  }

  async function loadQuiz() {
    try {
      const res = await fetch('../api/quiz_get.php?speaker_id=' + encodeURIComponent(speakerId) + '&ts=' + Date.now(), { 
        credentials: 'same-origin',
        cache: 'no-store'
      });
      
      if (res.status === 404) { 
        loadingEl.innerHTML = `
          <div class="no-quiz-container">
            <div class="no-quiz-icon">
              <i class="bi bi-ui-checks"></i>
            </div>
            <h3 class="no-quiz-title">No Quiz Available</h3>
            <p class="no-quiz-text">This speaker doesn't have a quiz configured yet.</p>
          </div>
        `;
        return; 
      }
      
      if (!res.ok) { 
        loadingEl.innerHTML = `
          <div class="no-quiz-container">
            <div class="no-quiz-icon">
              <i class="bi bi-exclamation-triangle"></i>
            </div>
            <h3 class="no-quiz-title">Failed to Load Quiz</h3>
            <p class="no-quiz-text">Please try again later.</p>
          </div>
        `;
        return; 
      }
      
      const data = await res.json();
      // Build list (speakers) URL based on program type and day
      const dayId = data?.day_id;
      const listPage = (programType === 'sub') ? 'sub_program_day.php' : 'main_program_day.php';
      if (dayId) {
        listHref = `${listPage}?day_id=${encodeURIComponent(dayId)}`;
        modalBackProgramsBtn.setAttribute('href', listHref);
      } else {
        listHref = 'programs.php';
        modalBackProgramsBtn.setAttribute('href', listHref);
      }
      renderQuiz(data);
    } catch (e) {
      loadingEl.innerHTML = `
        <div class="no-quiz-container">
          <div class="no-quiz-icon">
            <i class="bi bi-wifi-off"></i>
          </div>
          <h3 class="no-quiz-title">Connection Error</h3>
          <p class="no-quiz-text">Please check your internet connection and try again.</p>
        </div>
      `;
    }
  }

  function renderQuiz(data) {
    loadingEl.classList.add('d-none');
  const { quiz_id, questions, options, multiple } = data || {};
    
    if (!quiz_id || !Array.isArray(questions) || !Array.isArray(options) || questions.length === 0) {
      showStatus('Quiz is not configured correctly.', 'danger');
      return;
    }
    
    document.getElementById('quiz_id').value = quiz_id;
    totalQuestions = questions.length;

    questionsWrap.innerHTML = '';
    
    for (let i = 0; i < questions.length; i++) {
      const q = String(questions[i] ?? '');
      const opts = Array.isArray(options[i]) ? options[i] : [];
      
      const questionDiv = document.createElement('div');
      questionDiv.className = 'question-container';
      questionDiv.innerHTML = `
        <div class="question-header">
          <div class="question-number">Q${i+1}</div>
          <div class="question-text">${escapeHtml(q)}</div>
        </div>
        <div id="opts_${i}"></div>
      `;
      
      questionsWrap.appendChild(questionDiv);
      const optsWrap = questionDiv.querySelector('#opts_' + i);

      if (opts.length >= 2) {
        // MCQ
        const mcqContainer = document.createElement('div');
        mcqContainer.className = 'mcq-options';
        const isMulti = Array.isArray(multiple) ? !!multiple[i] : false;
        
        opts.forEach((opt, idx) => {
          const id = `q${i}_opt${idx}`;
          const optionDiv = document.createElement('div');
          optionDiv.className = 'mcq-option';
          optionDiv.innerHTML = `
            <label for="${id}">
              <input class="form-check-input" type="${isMulti ? 'checkbox' : 'radio'}" name="q${i}${isMulti ? '[]' : ''}" id="${id}" value="${idx}">
              ${escapeHtml(String(opt ?? ''))}
            </label>
          `;
          
          // Add click handler for visual feedback
          const inputEl = optionDiv.querySelector('input');
          const label = optionDiv.querySelector('label');
          
          inputEl.addEventListener('change', () => {
            if (isMulti) {
              // Toggle only this option based on checkbox state
              if (inputEl.checked) optionDiv.classList.add('selected');
              else optionDiv.classList.remove('selected');
            } else {
              // Single-select: clear others, set this
              mcqContainer.querySelectorAll('.mcq-option').forEach(opt => {
                opt.classList.remove('selected');
              });
              if (inputEl.checked) optionDiv.classList.add('selected');
            }
            updateProgress();
          });
          
          mcqContainer.appendChild(optionDiv);
        });
        
        optsWrap.appendChild(mcqContainer);
      } else {
        // Paragraph
        const paraContainer = document.createElement('div');
        paraContainer.className = 'paragraph-container';
        paraContainer.innerHTML = `
          <div class="paragraph-icon">
            <i class="bi bi-textarea-t"></i>
          </div>
          <textarea class="paragraph-textarea" name="q${i}_text" rows="4" 
                    placeholder="Share your thoughts in detail. Take your time to provide a comprehensive answer..."></textarea>
        `;
        
        const textarea = paraContainer.querySelector('textarea');
        textarea.addEventListener('input', updateProgress);
        
        optsWrap.appendChild(paraContainer);
      }
    }
    
    quizForm.classList.remove('d-none');
    updateProgress();

    // Retry (clear all answers)
    retryBtn?.addEventListener('click', (e) => {
      e.preventDefault();
      clearAnswers();
      hideStatus();
    });
  }

  quizForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    hideStatus();

    // Collect and validate answers
    const qGroups = Array.from(questionsWrap.querySelectorAll('.question-container'));
    const answers = [];
    
    for (let i = 0; i < qGroups.length; i++) {
      const radios = qGroups[i].querySelectorAll(`input[type="radio"][name="q${i}"]`);
      const checks = qGroups[i].querySelectorAll(`input[type="checkbox"][name="q${i}[]"]`);
      
      if (radios.length > 0) {
        const chosen = Array.from(radios).find(r => r.checked);
        if (!chosen) { 
          showStatus(`Please answer question ${i+1}.`, 'warning'); 
          qGroups[i].scrollIntoView({ behavior: 'smooth', block: 'center' });
          return; 
        }
        answers.push(parseInt(chosen.value, 10));
      } else if (checks.length > 0) {
        const selected = Array.from(checks).filter(c => c.checked).map(c => parseInt(c.value, 10));
        if (selected.length === 0) {
          showStatus(`Please select at least one option for question ${i+1}.`, 'warning');
          qGroups[i].scrollIntoView({ behavior: 'smooth', block: 'center' });
          return;
        }
        answers.push(selected);
      } else {
        const ta = qGroups[i].querySelector('textarea');
        const txt = ta ? ta.value.trim() : '';
        if (txt === '') { 
          showStatus(`Please provide an answer for question ${i+1}.`, 'warning'); 
          qGroups[i].scrollIntoView({ behavior: 'smooth', block: 'center' });
          ta?.focus();
          return; 
        }
        answers.push(txt);
      }
    }

    // Submit with loading state
    submitBtn.disabled = true;
    submitBtn.innerHTML = `
      <div class="spinner-border spinner-border-sm me-2" role="status">
        <span class="visually-hidden">Loading...</span>
      </div>
      Submitting...
    `;

    try {
      const fd = new FormData();
      fd.append('quiz_id', document.getElementById('quiz_id').value);
      fd.append('speaker_id', document.getElementById('speaker_id').value);
  fd.append('answers_json', JSON.stringify(answers));

      const res = await fetch('../api/quiz_submit.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
      });

      const isJson = (res.headers.get('content-type') || '').includes('application/json');
      const data = isJson ? await res.json() : {};
      
      if (res.status === 401) { 
        showStatus('Please log in to take the quiz.', 'danger'); 
        return; 
      }
      if (res.status === 409) { 
        showStatus('You have already submitted this quiz.', 'warning'); 
        return; 
      }
      if (!res.ok) { 
        showStatus(data.error || 'Submission failed. Please try again.', 'danger'); 
        return; 
      }

      // Handle pass-only save logic
      const total = data.total ?? answers.length;
      if (data.passed === false) {
        // Did not meet passing score; show modal and allow retry without saving
        const req = (data.required != null) ? `${data.required}` : '';
        quizResultTitle.textContent = 'Please Try Again';
        quizResultIcon.className = 'bi bi-x-circle-fill text-danger';
        quizResultSummary.textContent = `You scored ${data.score}/${total}.`;
        quizResultDetail.textContent = req ? `Passing requires at least ${req} correct answers.` : '';
        quizResultModal.show();
        // Show Retry + Back to Speaker; hide Back to Programs
        document.getElementById('modalBackProgramsBtn').classList.add('d-none');
        document.getElementById('backLink').classList.remove('d-none');
        modalRetryBtn.classList.remove('d-none');
        modalRetryBtn.onclick = () => { quizResultModal.hide(); clearAnswers(); };
        submitBtn.blur();
        return;
      }

      // Passed or no passing threshold: show results and hide form
      progressBar.style.width = '100%';
      quizForm.classList.add('d-none');
      resultCard.classList.remove('d-none');
      resultText.textContent = `You scored ${data.score} out of ${total}`;
      // Configure modal based on presence of a passing requirement
      const hasRequirement = (data.required != null);
      if (!hasRequirement) {
        // No required passing score: minimal modal with only score and Back to Programs
        quizResultTitle.textContent = 'Quiz Submitted';
        quizResultIcon.className = 'bi bi-check-circle-fill text-success';
        quizResultSummary.textContent = `You scored ${data.score}/${total}.`;
        quizResultDetail.textContent = '';
        // Show Back to Programs only
        modalBackProgramsBtn.classList.remove('d-none');
        document.getElementById('backLink').classList.add('d-none');
        modalRetryBtn.classList.add('d-none');
        quizResultModal.show();
      } else {
        // Has requirement and passed: show standard success modal (no retry)
        quizResultTitle.textContent = 'Great Job!';
        quizResultIcon.className = 'bi bi-check-circle-fill text-success';
        quizResultSummary.textContent = `You scored ${data.score}/${total}.`;
        quizResultDetail.textContent = '';
        document.getElementById('modalBackProgramsBtn').classList.add('d-none');
        document.getElementById('backLink').classList.remove('d-none');
        modalRetryBtn.classList.add('d-none');
        quizResultModal.show();
      }
      resultCard.scrollIntoView({ behavior: 'smooth', block: 'center' });

    } catch (err) {
      showStatus('Network error. Please check your connection and try again.', 'danger');
    } finally {
      submitBtn.disabled = false;
      submitBtn.innerHTML = `
        <i class="bi bi-check-circle-fill me-2"></i>
        Submit Answers
      `;
    }
  });

  // Initialize
  loadQuiz();

  function clearAnswers() {
    // Radios: uncheck and remove selected class
    questionsWrap.querySelectorAll('input[type="radio"]').forEach(r => { r.checked = false; });
    questionsWrap.querySelectorAll('input[type="checkbox"]').forEach(c => { c.checked = false; });
    questionsWrap.querySelectorAll('.mcq-option').forEach(div => div.classList.remove('selected'));
    // Paragraphs
    questionsWrap.querySelectorAll('textarea').forEach(t => t.value = '');
    updateProgress();
  }
  </script>
</body>
</html>