<?php
session_start();
require_once __DIR__ . '/../db.php';

// normalize DB
if (isset($conn) && $conn instanceof mysqli) $db = $conn;
elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli;
else { $db = null; }
if (!($db instanceof mysqli)) { http_response_code(500); echo "Database connection not available."; exit; }

// require admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
  header('Location: admin_login.php'); exit;
}

// helpers
if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); } }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));

$errors = [];
$success = '';
$preselectSpeakerId = isset($_GET['speaker_id']) && is_numeric($_GET['speaker_id']) ? (int)$_GET['speaker_id'] : 0;

// fetch speakers for dropdown (with room/day context)
$speakers = [];
$q = $db->query("
  SELECT s.id, s.name, s.title,
         fr.id AS room_id, fr.name AS room_name,
         fd.id AS day_id, fd.label AS day_label, fd.day_date
  FROM speakers s
  LEFT JOIN function_rooms fr ON fr.id = s.room_id
  LEFT JOIN function_room_days fd ON fd.id = fr.day_id
  ORDER BY fd.day_date ASC, fr.id ASC, s.name ASC
");
if ($q) { while ($r = $q->fetch_assoc()) $speakers[] = $r; }

// handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = $_POST['csrf_token'] ?? '';
  if (!hash_equals($_SESSION['csrf_token'], (string)$token)) {
    $errors[] = 'Invalid CSRF token.';
  } else {
    $action = $_POST['action'] ?? '';
    if ($action === 'add' || $action === 'update') {
      $speaker_id = isset($_POST['speaker_id']) && is_numeric($_POST['speaker_id']) ? (int)$_POST['speaker_id'] : 0;
      $questions_json = trim($_POST['questions'] ?? '[]');
      $options_json   = trim($_POST['options'] ?? '[]');
      $answers_json   = trim($_POST['answers'] ?? '[]');

      if ($speaker_id <= 0) $errors[] = 'Select a speaker.';
      $questions = json_decode($questions_json, true);
      $options   = json_decode($options_json, true);
      $answers   = json_decode($answers_json, true);

      if (!is_array($questions) || empty($questions)) $errors[] = 'Add at least one question.';
      if (!is_array($options)) $errors[] = 'Options invalid.';
      if (!is_array($answers)) $errors[] = 'Answers invalid.';

      // validate structure
      $nq = is_array($questions) ? count($questions) : 0;
      if ($nq > 0) {
        if (!is_array($options) || count($options) !== $nq) $errors[] = 'Options length must match questions length.';
        if (!is_array($answers) || count($answers) !== $nq) $errors[] = 'Answers length must match questions length.';
        for ($i=0;$i<$nq;$i++){
          $qTxt = isset($questions[$i]) ? trim((string)$questions[$i]) : '';
          if ($qTxt === '') { $errors[] = "Question ".($i+1)." text is required."; break; }

          $opt = $options[$i] ?? null;
          // Paragraph type: options = [] (zero), answers = null
          if (is_array($opt) && count($opt) === 0) {
            if (array_key_exists($i, $answers) && !is_null($answers[$i])) {
              $errors[] = "Question ".($i+1).": paragraph-type must have no correct answer.";
              break;
            }
            continue; // valid paragraph question
          }

          // Multiple-choice: options >= 2, answers[i] is valid index
          if (!is_array($opt) || count($opt) < 2) {
            $errors[] = "Question ".($i+1).": add at least 2 options or switch to Paragraph type.";
            break;
          }
          foreach ($opt as $optTxt) {
            if (trim((string)$optTxt) === '') { $errors[] = "Question ".($i+1).": option text cannot be empty."; break 2; }
          }
          if (!isset($answers[$i]) || !is_int($answers[$i]) || $answers[$i] < 0 || $answers[$i] >= count($opt)) {
            $errors[] = "Question ".($i+1).": select a valid correct option.";
            break;
          }
        }
      }

      // resolve room_id/day_id from speaker
      $room_id = null; $day_id = null;
      if ($speaker_id > 0) {
        if ($st = $db->prepare("
          SELECT s.room_id, fd.id AS day_id
          FROM speakers s
          LEFT JOIN function_rooms fr ON fr.id = s.room_id
          LEFT JOIN function_room_days fd ON fd.id = fr.day_id
          WHERE s.id = ? LIMIT 1
        ")) {
          $st->bind_param('i', $speaker_id);
          $st->execute();
          $st->bind_result($room_id, $day_id);
          $st->fetch();
          $st->close();
        }
      }

      if (empty($errors)) {
        if ($action === 'add') {
          $stmt = $db->prepare("INSERT INTO quizzes (speaker_id, room_id, day_id, questions, options, answers) VALUES (?, ?, ?, ?, ?, ?)");
          if ($stmt) {
            $qj = json_encode($questions, JSON_UNESCAPED_UNICODE);
            $oj = json_encode($options, JSON_UNESCAPED_UNICODE);
            $aj = json_encode($answers, JSON_UNESCAPED_UNICODE);
            $stmt->bind_param('iiisss', $speaker_id, $room_id, $day_id, $qj, $oj, $aj);
            if ($stmt->execute()) $success = 'Quiz created.';
            else $errors[] = ($db->errno == 1062) ? 'A quiz already exists for this speaker.' : ('Insert failed: '.$stmt->error);
            $stmt->close();
          } else {
            $errors[] = 'DB prepare failed: '.$db->error;
          }
        } else { // update
          $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
          if ($id <= 0) $errors[] = 'Invalid quiz id.';
          if (empty($errors)) {
            $stmt = $db->prepare("UPDATE quizzes SET speaker_id=?, room_id=?, day_id=?, questions=?, options=?, answers=? WHERE id=? LIMIT 1");
            if ($stmt) {
              $qj = json_encode($questions, JSON_UNESCAPED_UNICODE);
              $oj = json_encode($options, JSON_UNESCAPED_UNICODE);
              $aj = json_encode($answers, JSON_UNESCAPED_UNICODE);
              $stmt->bind_param('iiisssi', $speaker_id, $room_id, $day_id, $qj, $oj, $aj, $id);
              if ($stmt->execute()) $success = 'Quiz updated.';
              else $errors[] = 'Update failed: '.$stmt->error;
              $stmt->close();
            } else {
              $errors[] = 'DB prepare failed: '.$db->error;
            }
          }
        }
      }
    } elseif ($action === 'delete') {
      $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
      if ($id <= 0) $errors[] = 'Invalid id.';
      if (empty($errors)) {
        $stmt = $db->prepare("DELETE FROM quizzes WHERE id = ? LIMIT 1");
        if ($stmt) {
          $stmt->bind_param('i', $id);
          if ($stmt->execute()) $success = 'Quiz deleted.';
          else $errors[] = 'Delete failed: '.$stmt->error;
          $stmt->close();
        } else {
          $errors[] = 'DB prepare failed: '.$db->error;
        }
      }
    } else {
      $errors[] = 'Unknown action.';
    }
  }

  $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
  $_SESSION['quiz_messages'] = ['errors'=>$errors,'success'=>$success];
  header('Location: admin_quiz_manage.php');
  exit;
}

// messages
$messages = $_SESSION['quiz_messages'] ?? null;
if ($messages) {
  $errors = array_merge($errors, $messages['errors'] ?? []);
  if (!empty($messages['success'])) $success = $messages['success'];
  unset($_SESSION['quiz_messages']);
}

// edit record
$edit = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
  $eid = (int)$_GET['edit'];
  $st = $db->prepare("SELECT * FROM quizzes WHERE id = ? LIMIT 1");
  if ($st) {
    $st->bind_param('i', $eid);
    $st->execute();
    $edit = $st->get_result()->fetch_assoc();
    $st->close();
  }
}

// list quizzes
$quizzes = [];
$qr = $db->query("
  SELECT q.*, s.name AS speaker_name, s.title AS speaker_title,
         fr.name AS room_name, fd.label AS day_label
  FROM quizzes q
  JOIN speakers s ON s.id = q.speaker_id
  LEFT JOIN function_rooms fr ON fr.id = q.room_id
  LEFT JOIN function_room_days fd ON fd.id = q.day_id
  ORDER BY q.created_at DESC, q.id DESC
");
if ($qr) while ($row = $qr->fetch_assoc()) $quizzes[] = $row;

function pretty_count($jsonStr) {
  $a = json_decode($jsonStr, true);
  return is_array($a) ? count($a) : 0;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Quiz Management — 73rd PSME National Convention</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: #2563eb;
      --primary-dark: #1d4ed8;
      --success: #10b981;
      --warning: #f59e0b;
      --danger: #ef4444;
      --info: #06b6d4;
      --light: #f8fafc;
      --dark: #1e293b;
      --card-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
      --border-radius: 16px;
    }

    * { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
    
    body { 
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      min-height: 100vh;
      padding: 2rem 0;
    }

    .main-container {
      max-width: 1400px;
      margin: 0 auto;
      padding: 0 1rem;
    }

    /* Header */
    .page-header {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(20px);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 20px;
      padding: 2rem;
      margin-bottom: 2rem;
      box-shadow: var(--card-shadow);
      text-align: center;
    }

    .page-title {
      background: linear-gradient(135deg, var(--primary), var(--primary-dark));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      font-weight: 700;
      font-size: 2.5rem;
      margin: 0;
    }

    .page-subtitle {
      color: #64748b;
      font-size: 1.1rem;
      margin-top: 0.5rem;
    }

    /* Enhanced Cards */
    .enhanced-card {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(20px);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: var(--border-radius);
      box-shadow: var(--card-shadow);
      transition: all 0.3s ease;
      overflow: hidden;
    }

    .enhanced-card:hover {
      transform: translateY(-5px);
      box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.15);
    }

    .card-header-enhanced {
      background: linear-gradient(135deg, rgba(37,99,235,0.1), rgba(6,182,212,0.1));
      border-bottom: 2px solid rgba(37,99,235,0.1);
      padding: 1.5rem 2rem;
      text-align: center;
    }

    .card-body-enhanced {
      padding: 2rem;
    }

    /* Quiz Builder Section */
    .builder-container {
      background: rgba(255, 255, 255, 0.98);
      backdrop-filter: blur(20px);
      border: 1px solid rgba(255, 255, 255, 0.3);
      border-radius: var(--border-radius);
      box-shadow: var(--card-shadow);
      padding: 2.5rem;
      margin-bottom: 2rem;
    }

    .builder-header {
      text-align: center;
      margin-bottom: 2rem;
      padding-bottom: 1.5rem;
      border-bottom: 2px solid rgba(37,99,235,0.1);
    }

    .builder-title {
      color: var(--primary);
      font-weight: 600;
      font-size: 1.5rem;
      margin-bottom: 0.5rem;
    }

    /* Question Cards */
    .q-card {
      background: white;
      border: 2px solid rgba(37,99,235,0.1);
      border-radius: 16px;
      padding: 1.5rem;
      margin-bottom: 1.5rem;
      box-shadow: 0 4px 15px rgba(0,0,0,0.08);
      transition: all 0.3s ease;
      position: relative;
    }

    .q-card:hover {
      border-color: rgba(37,99,235,0.3);
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(0,0,0,0.12);
    }

    .q-handle {
      cursor: move;
      color: var(--primary);
      background: rgba(37,99,235,0.1);
      border-radius: 8px;
      padding: 8px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: all 0.2s ease;
    }

    .q-handle:hover {
      background: rgba(37,99,235,0.2);
      transform: scale(1.1);
    }

    .q-toolbar {
      display: flex;
      gap: 8px;
      justify-content: center;
      margin-top: 1rem;
    }

    .q-toolbar .btn {
      border-radius: 10px;
      padding: 8px 12px;
      font-weight: 500;
      transition: all 0.2s ease;
    }

    .q-toolbar .btn:hover {
      transform: translateY(-2px);
    }

    .question-header {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 1rem;
      margin-bottom: 1.5rem;
      flex-wrap: wrap;
    }

    .question-number {
      background: linear-gradient(135deg, var(--primary), var(--primary-dark));
      color: white;
      padding: 8px 16px;
      border-radius: 20px;
      font-weight: 600;
      font-size: 0.9rem;
    }

    .type-select {
      border-radius: 12px;
      border: 2px solid rgba(37,99,235,0.2);
      padding: 8px 12px;
      font-weight: 500;
      transition: all 0.2s ease;
    }

    .type-select:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
    }

    /* Form Controls */
    .form-control, .form-select {
      border-radius: 12px;
      border: 2px solid rgba(0,0,0,0.1);
      padding: 12px 16px;
      font-weight: 500;
      transition: all 0.3s ease;
    }

    .form-control:focus, .form-select:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 4px rgba(37,99,235,0.1);
      transform: translateY(-1px);
    }

    .q-text {
      font-size: 1.1rem;
      font-weight: 600;
      text-align: center;
    }

    /* Option Rows */
    .opt-row {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 12px;
      padding: 8px;
      border-radius: 12px;
      transition: all 0.2s ease;
    }

    .opt-row:hover {
      background: rgba(37,99,235,0.05);
    }

    .opt-row input[type="text"] {
      flex: 1;
    }

    .opt-row .btn {
      border-radius: 8px;
      padding: 6px 10px;
    }

    /* Metadata */
    .q-meta {
      background: rgba(37,99,235,0.05);
      border-radius: 12px;
      padding: 12px 16px;
      margin-top: 1rem;
      text-align: center;
      font-size: 0.9rem;
      font-weight: 500;
    }

    .is-correct {
      color: var(--success);
      font-weight: 600;
    }

    /* Buttons */
    .btn {
      border-radius: 12px;
      font-weight: 600;
      padding: 12px 20px;
      transition: all 0.3s ease;
      border: none;
    }

    .btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 20px rgba(0,0,0,0.15);
    }

    .btn-primary {
      background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    }

    .btn-success {
      background: linear-gradient(135deg, var(--success), #059669);
    }

    .btn-outline-primary {
      border: 2px solid var(--primary);
      color: var(--primary);
      background: transparent;
    }

    .btn-outline-primary:hover {
      background: var(--primary);
      color: white;
    }

    /* Action Buttons */
    .action-buttons {
      display: flex;
      justify-content: center;
      gap: 1rem;
      margin-top: 2rem;
      flex-wrap: wrap;
    }

    /* Table Enhancements */
    .table-container {
      background: white;
      border-radius: var(--border-radius);
      overflow: hidden;
      box-shadow: var(--card-shadow);
    }

    .table {
      margin: 0;
    }

    .table thead th {
      background: linear-gradient(135deg, var(--primary), var(--primary-dark));
      color: white;
      font-weight: 600;
      text-align: center;
      border: none;
      padding: 1rem;
    }

    .table tbody tr {
      transition: all 0.2s ease;
    }

    .table tbody tr:hover {
      background: rgba(37,99,235,0.05);
      transform: scale(1.01);
    }

    .table tbody td {
      text-align: center;
      padding: 1rem;
      vertical-align: middle;
    }

    /* Badges */
    .badge-soft {
      background: rgba(37,99,235,0.1);
      color: var(--primary);
      border: 1px solid rgba(37,99,235,0.2);
      border-radius: 20px;
      padding: 6px 12px;
      font-weight: 600;
    }

    /* Alerts */
    .alert {
      border-radius: var(--border-radius);
      border: none;
      padding: 1rem 1.5rem;
      margin-bottom: 1.5rem;
      text-align: center;
      font-weight: 500;
    }

    .alert-success {
      background: linear-gradient(135deg, rgba(16,185,129,0.1), rgba(5,150,105,0.1));
      color: var(--success);
      border: 2px solid rgba(16,185,129,0.2);
    }

    .alert-danger {
      background: linear-gradient(135deg, rgba(239,68,68,0.1), rgba(220,38,38,0.1));
      color: var(--danger);
      border: 2px solid rgba(239,68,68,0.2);
    }

    /* Paragraph indicator */
    .para-wrap {
      background: rgba(6,182,212,0.1);
      border: 2px dashed rgba(6,182,212,0.3);
      border-radius: 12px;
      padding: 1.5rem;
      text-align: center;
      color: var(--info);
      font-weight: 500;
    }

    /* Responsive */
    @media (max-width: 768px) {
      .main-container {
        padding: 0 0.5rem;
      }
      
      .page-header {
        padding: 1.5rem;
      }
      
      .page-title {
        font-size: 2rem;
      }
      
      .builder-container {
        padding: 1.5rem;
      }
      
      .q-card {
        padding: 1rem;
      }
      
      .question-header {
        flex-direction: column;
        gap: 0.5rem;
      }
      
      .action-buttons {
        flex-direction: column;
      }
    }

    /* Loading and animations */
    .fade-in {
      animation: fadeIn 0.6s ease-out;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(20px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .slide-up {
      animation: slideUp 0.6s ease-out;
    }

    @keyframes slideUp {
      from { transform: translateY(30px); opacity: 0; }
      to { transform: translateY(0); opacity: 1; }
    }
  </style>
</head>
<body>
<div class="main-container">
  <!-- Page Header -->
  <div class="page-header fade-in">
    <h1 class="page-title">Quiz Management</h1>
    <p class="page-subtitle">Create and manage interactive quizzes for speakers</p>
    <div class="mt-3">
      <a href="Admin_dashboard.php" class="btn btn-outline-primary">
        <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
      </a>
    </div>
  </div>

  <!-- Messages -->
  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger fade-in">
      <i class="bi bi-exclamation-triangle-fill me-2"></i>
      <strong>Please fix the following issues:</strong>
      <ul class="mb-0 mt-2 text-start" style="max-width: 600px; margin: 0 auto;">
        <?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
  
  <?php if ($success): ?>
    <div class="alert alert-success fade-in">
      <i class="bi bi-check-circle-fill me-2"></i>
      <?= e($success) ?>
    </div>
  <?php endif; ?>

  <div class="row g-4">
    <!-- Quiz List -->
    <div class="col-xl-6">
      <div class="enhanced-card slide-up">
        <div class="card-header-enhanced">
          <h5 class="mb-0">
            <i class="bi bi-list-ul me-2"></i>
            Existing Quizzes
          </h5>
        </div>
        <div class="card-body-enhanced">
          <?php if (empty($quizzes)): ?>
            <div class="text-center py-5">
              <i class="bi bi-ui-checks text-muted mb-3" style="font-size: 4rem; opacity: 0.3;"></i>
              <h6 class="text-muted">No quizzes created yet</h6>
              <p class="text-muted small">Create your first quiz using the form on the right</p>
            </div>
          <?php else: ?>
            <div class="table-container">
              <table class="table table-hover align-middle">
                <thead>
                  <tr>
                    <th><i class="bi bi-hash me-1"></i>ID</th>
                    <th><i class="bi bi-person me-1"></i>Speaker</th>
                    <th><i class="bi bi-calendar me-1"></i>Day</th>
                    <th><i class="bi bi-door-open me-1"></i>Room</th>
                    <th><i class="bi bi-question-circle me-1"></i>Questions</th>
                    <th><i class="bi bi-clock me-1"></i>Created</th>
                    <th><i class="bi bi-gear me-1"></i>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($quizzes as $q): ?>
                    <tr>
                      <td><span class="badge bg-light text-dark"><?= (int)$q['id'] ?></span></td>
                      <td>
                        <div class="fw-bold"><?= e($q['speaker_name']) ?></div>
                        <div class="text-muted small"><?= e($q['speaker_title']) ?></div>
                      </td>
                      <td><?= e($q['day_label'] ?: '-') ?></td>
                      <td><?= e($q['room_name'] ?: '-') ?></td>
                      <td><span class="badge badge-soft"><?= pretty_count($q['questions']) ?></span></td>
                      <td><small><?= date('M j, Y', strtotime($q['created_at'])) ?></small></td>
                      <td>
                        <div class="d-flex gap-1 justify-content-center">
                          <a class="btn btn-sm btn-primary" href="admin_quiz_manage.php?edit=<?= (int)$q['id'] ?>" title="Edit">
                            <i class="bi bi-pencil"></i>
                          </a>
                          <form method="post" class="d-inline" onsubmit="return confirm('Delete this quiz? This action cannot be undone.');">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
                            <button class="btn btn-sm btn-danger" title="Delete">
                              <i class="bi bi-trash"></i>
                            </button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Quiz Builder -->
    <div class="col-xl-6">
      <div class="builder-container slide-up">
        <div class="builder-header">
          <?php if ($edit): ?>
            <h3 class="builder-title">
              <i class="bi bi-pencil-square me-2"></i>
              Edit Quiz #<?= (int)$edit['id'] ?>
            </h3>
            <p class="text-muted mb-0">Modify questions and answers for this quiz</p>
          <?php else: ?>
            <h3 class="builder-title">
              <i class="bi bi-plus-circle me-2"></i>
              Create New Quiz
            </h3>
            <p class="text-muted mb-0">Build interactive quizzes with multiple choice and paragraph questions</p>
          <?php endif; ?>
        </div>

        <form id="quizEditorForm" method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="<?= $edit ? 'update' : 'add' ?>">
          <?php if ($edit): ?>
            <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
          <?php endif; ?>

          <!-- Speaker Selection -->
          <div class="mb-4">
            <label class="form-label fw-bold text-center d-block">
              <i class="bi bi-person-video3 me-2"></i>
              Select Speaker
            </label>
            <select name="speaker_id" class="form-select" required>
              <option value="">-- Choose Speaker --</option>
              <?php foreach ($speakers as $sp): ?>
                <option value="<?= (int)$sp['id'] ?>" <?= (($edit && (int)$edit['speaker_id'] === (int)$sp['id']) || $preselectSpeakerId === (int)$sp['id']) ? 'selected' : '' ?>>
                  <?= e($sp['name']) ?>
                  <?= $sp['day_label'] ? ' — '.e($sp['day_label']) : '' ?>
                  <?= $sp['room_name'] ? ' — '.e($sp['room_name']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Hidden JSON fields -->
          <input type="hidden" name="questions" id="questionsJson">
          <input type="hidden" name="options" id="optionsJson">
          <input type="hidden" name="answers" id="answersJson">

          <!-- Quiz Builder -->
          <div id="quizBuilder" class="mb-4"></div>

          <!-- Action Buttons -->
          <div class="action-buttons">
            <button type="button" class="btn btn-outline-primary" id="addQuestionBtn">
              <i class="bi bi-plus-circle me-2"></i>Add Question
            </button>
            <?php if ($edit): ?>
              <a class="btn btn-outline-secondary" href="admin_quiz_manage.php">
                <i class="bi bi-x-circle me-2"></i>Cancel
              </a>
              <button class="btn btn-primary" id="saveQuizBtn">
                <i class="bi bi-save me-2"></i>Update Quiz
              </button>
            <?php else: ?>
              <button class="btn btn-success" id="saveQuizBtn">
                <i class="bi bi-check2-circle me-2"></i>Create Quiz
              </button>
            <?php endif; ?>
          </div>
        </form>

        <?php if (!$edit): ?>
          <div class="text-center mt-4 p-3" style="background: rgba(37,99,235,0.05); border-radius: 12px;">
            <div class="d-flex align-items-center justify-content-center gap-2 text-primary">
              <i class="bi bi-lightbulb-fill"></i>
              <strong>Pro Tips:</strong>
            </div>
            <div class="small text-muted mt-2">
              • Click radio buttons to mark correct answers<br>
              • Use "Paragraph" type for open-ended questions<br>
              • Drag questions to reorder them
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Toast for validation messages -->
<div class="position-fixed top-0 end-0 p-3" style="z-index: 1080;">
  <div id="toastMsg" class="toast align-items-center text-bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true" data-bs-delay="3000">
    <div class="d-flex">
      <div class="toast-body fw-bold" id="toastBody">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        Please fix the highlighted fields.
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  // Initial data from PHP (for edit mode)
  const initial = {
    mode: <?= $edit ? "'edit'" : "'add'" ?>,
    questions: <?= json_encode($edit ? json_decode((string)($edit['questions'] ?? '[]'), true) : [], JSON_UNESCAPED_UNICODE) ?>,
    options:   <?= json_encode($edit ? json_decode((string)($edit['options'] ?? '[]'), true)   : [], JSON_UNESCAPED_UNICODE) ?>,
    answers:   <?= json_encode($edit ? json_decode((string)($edit['answers'] ?? '[]'), true)   : [], JSON_UNESCAPED_UNICODE) ?>
  };

  // If add mode and empty, seed one question with two options
  if (initial.mode === 'add' && (!Array.isArray(initial.questions) || initial.questions.length === 0)) {
    initial.questions = [''];
    initial.options = [['Option 1','Option 2']];
    initial.answers = [0];
  }

  const builder = document.getElementById('quizBuilder');
  const addBtn = document.getElementById('addQuestionBtn');
  const form = document.getElementById('quizEditorForm');
  const toastEl = document.getElementById('toastMsg');
  const toastBody = document.getElementById('toastBody');

  function showToast(msg) {
    toastBody.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-2"></i>${msg}`;
    const t = bootstrap.Toast.getOrCreateInstance(toastEl);
    t.show();
  }

  function isParagraph(qIdx){
    const opts = initial.options?.[qIdx];
    return Array.isArray(opts) && opts.length === 0;
  }

  function makeQuestionCard(idx, text, opts, correctIndex) {
    const isPara = Array.isArray(opts) && opts.length === 0;
    const card = document.createElement('div');
    card.className = 'q-card fade-in';
    card.dataset.index = idx;

    card.innerHTML = `
      <div class="question-header">
        <div class="q-handle" title="Drag to reorder">
          <i class="bi bi-grip-vertical"></i>
        </div>
        <div class="question-number">Question ${idx+1}</div>
        <select class="form-select type-select" data-role="type">
          <option value="mcq" ${!isPara ? 'selected':''}>Multiple Choice</option>
          <option value="para" ${isPara ? 'selected':''}>Paragraph</option>
        </select>
      </div>

      <div class="mb-3">
        <input type="text" class="form-control q-text" placeholder="Enter your question here..." value="${escapeHtml(text || '')}">
      </div>

      <div class="opt-wrap ${isPara ? 'd-none':''}"></div>
      
      <div class="para-wrap ${isPara ? '':'d-none'}">
        <i class="bi bi-textarea-t" style="font-size: 2rem; margin-bottom: 1rem;"></i>
        <div><strong>Paragraph Question</strong></div>
        <div class="small mt-1">Users will provide a text response to this question</div>
      </div>

      <div class="q-meta">
        <div class="d-flex align-items-center justify-content-center gap-3 flex-wrap">
          <div>
            <i class="bi bi-list-ol me-1"></i>
            <span class="opt-count">${isPara ? 0 : (opts.length)}</span> ${isPara ? 'paragraph' : 'options'}
          </div>
          <div class="correct-flag ${(!isPara && Number.isInteger(correctIndex)) ? 'is-correct' : ''}">
            <i class="bi bi-${isPara ? 'textarea' : (Number.isInteger(correctIndex) ? 'check-circle-fill' : 'question-circle')} me-1"></i>
            ${isPara ? 'No scoring (paragraph)' : (Number.isInteger(correctIndex) ? 'Correct answer set' : 'No correct answer')}
          </div>
        </div>
      </div>

      <div class="q-toolbar">
        <button type="button" class="btn btn-outline-secondary btn-sm ${isPara ? 'd-none':''}" data-action="add-option">
          <i class="bi bi-plus-circle me-1"></i>Add Option
        </button>
        <button type="button" class="btn btn-outline-primary btn-sm" data-action="duplicate">
          <i class="bi bi-files me-1"></i>Duplicate
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-action="move-up">
          <i class="bi bi-arrow-up"></i>
        </button>
        <button type="button" class="btn btn-outline-secondary btn-sm" data-action="move-down">
          <i class="bi bi-arrow-down"></i>
        </button>
        <button type="button" class="btn btn-outline-danger btn-sm" data-action="delete">
          <i class="bi bi-trash me-1"></i>Delete
        </button>
      </div>
    `;

    const optWrap = card.querySelector('.opt-wrap');
    if (!isPara) {
      (opts || []).forEach((opt, oidx) => {
        optWrap.appendChild(makeOptionRow(idx, oidx, opt, oidx === correctIndex));
      });
    }

    return card;
  }

  function makeOptionRow(qIdx, oIdx, value, checked) {
    const row = document.createElement('div');
    row.className = 'opt-row';
    row.dataset.q = qIdx;
    row.dataset.o = oIdx;
    row.innerHTML = `
      <div class="form-check">
        <input class="form-check-input" type="radio" name="correct_${qIdx}" ${checked ? 'checked' : ''} title="Mark as correct answer">
        <label class="form-check-label small text-muted">Correct</label>
      </div>
      <input type="text" class="form-control" placeholder="Enter option ${oIdx+1}..." value="${escapeHtml(value || '')}">
      <button type="button" class="btn btn-outline-secondary btn-sm" data-action="opt-move-up" title="Move up">
        <i class="bi bi-arrow-up"></i>
      </button>
      <button type="button" class="btn btn-outline-secondary btn-sm" data-action="opt-move-down" title="Move down">
        <i class="bi bi-arrow-down"></i>
      </button>
      <button type="button" class="btn btn-outline-danger btn-sm" data-action="opt-delete" title="Delete option">
        <i class="bi bi-x-lg"></i>
      </button>
    `;
    return row;
  }

  function render() {
    builder.innerHTML = '';
    const Q = initial.questions || [];
    const O = initial.options || [];
    const A = initial.answers || [];
    for (let i = 0; i < Q.length; i++) {
      const card = makeQuestionCard(i, Q[i], O[i] || [], A[i]);
      builder.appendChild(card);
    }
    wireCardEvents();
  }

  function wireCardEvents() {
    builder.querySelectorAll('.q-card').forEach((card, idx) => {
      const qIdx = idx;
      const toolbar = card.querySelector('.q-toolbar');
      const optWrap = card.querySelector('.opt-wrap');
      const correctFlag = card.querySelector('.correct-flag');
      const optCountEl = card.querySelector('.opt-count');
      const typeSelect = card.querySelector('select[data-role="type"]');

      // Switch type
      typeSelect.addEventListener('change', () => {
        const val = typeSelect.value;
        if (val === 'para') {
          initial.options[qIdx] = [];
          initial.answers[qIdx] = null;
        } else {
          if (!Array.isArray(initial.options[qIdx]) || initial.options[qIdx].length === 0) {
            initial.options[qIdx] = ['Option 1','Option 2'];
            initial.answers[qIdx] = 0;
          }
        }
        render();
      });

      // Question text
      const qInput = card.querySelector('.q-text');
      qInput.addEventListener('input', () => {
        initial.questions[qIdx] = qInput.value;
      });

      // Toolbar actions
      toolbar.addEventListener('click', (e) => {
        const btn = e.target.closest('button');
        if (!btn) return;
        const action = btn.dataset.action;
        
        if (action === 'add-option') {
          initial.options[qIdx] = initial.options[qIdx] || [];
          initial.options[qIdx].push('');
          if (!Number.isInteger(initial.answers[qIdx])) initial.answers[qIdx] = 0;
          render();
        } else if (action === 'delete') {
          if (initial.questions.length <= 1) { showToast('At least one question is required.'); return; }
          initial.questions.splice(qIdx, 1);
          initial.options.splice(qIdx, 1);
          initial.answers.splice(qIdx, 1);
          render();
        } else if (action === 'duplicate') {
          initial.questions.splice(qIdx+1, 0, initial.questions[qIdx]);
          const isPara = isParagraph(qIdx);
          initial.options.splice(qIdx+1, 0, isPara ? [] : [...(initial.options[qIdx] || [])]);
          initial.answers.splice(qIdx+1, 0, isPara ? null : (Number.isInteger(initial.answers[qIdx]) ? initial.answers[qIdx] : 0));
          render();
        } else if (action === 'move-up' && qIdx > 0) {
          swapQuestions(qIdx, qIdx-1); render();
        } else if (action === 'move-down' && qIdx < initial.questions.length-1) {
          swapQuestions(qIdx, qIdx+1); render();
        }
      });

      // Options handling
      if (optWrap) {
        optWrap.addEventListener('click', (e) => {
          const btn = e.target.closest('button');
          if (!btn) return;
          const action = btn.dataset.action;
          const row = e.target.closest('.opt-row');
          if (!row) return;
          const oIdx = Array.from(optWrap.children).indexOf(row);
          
          if (action === 'opt-delete') {
            if ((initial.options[qIdx] || []).length <= 2) { showToast('A question needs at least 2 options.'); return; }
            initial.options[qIdx].splice(oIdx, 1);
            const ci = initial.answers[qIdx];
            if (ci === oIdx) initial.answers[qIdx] = 0;
            else if (ci > oIdx) initial.answers[qIdx] = ci - 1;
            render();
          } else if (action === 'opt-move-up' && oIdx > 0) {
            swapOptions(qIdx, oIdx, oIdx-1); render();
          } else if (action === 'opt-move-down' && oIdx < (initial.options[qIdx].length-1)) {
            swapOptions(qIdx, oIdx, oIdx+1); render();
          }
        });

        optWrap.querySelectorAll('.opt-row').forEach((row, oIdx) => {
          const text = row.querySelector('input[type="text"]');
          text.addEventListener('input', () => {
            initial.options[qIdx][oIdx] = text.value;
          });
          const radio = row.querySelector('input[type="radio"]');
          radio.addEventListener('change', () => {
            initial.answers[qIdx] = oIdx;
            render();
          });
        });
      }
    });
  }

  function swapQuestions(a, b) {
    [initial.questions[a], initial.questions[b]] = [initial.questions[b], initial.questions[a]];
    [initial.options[a], initial.options[b]] = [initial.options[b], initial.options[a]];
    [initial.answers[a], initial.answers[b]] = [initial.answers[b], initial.answers[a]];
  }

  function swapOptions(q, a, b) {
    [initial.options[q][a], initial.options[q][b]] = [initial.options[q][b], initial.options[q][a]];
    if (initial.answers[q] === a) initial.answers[q] = b;
    else if (initial.answers[q] === b) initial.answers[q] = a;
  }

  function escapeHtml(s){ return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

  // Add question
  addBtn.addEventListener('click', () => {
    initial.questions.push('');
    initial.options.push(['Option 1','Option 2']);
    initial.answers.push(0);
    render();
    setTimeout(() => builder.lastElementChild?.scrollIntoView({behavior:'smooth', block:'center'}), 100);
  });

  // Form submission
  form.addEventListener('submit', (e) => {
    const Q = initial.questions || [];
    const O = initial.options || [];
    const A = initial.answers || [];
    if (Q.length === 0) { e.preventDefault(); showToast('Add at least one question.'); return; }
    for (let i=0;i<Q.length;i++){
      if (!Q[i] || Q[i].trim() === '') { e.preventDefault(); showToast(`Question ${i+1} text is required.`); return; }
      const opts = O[i];
      if (!Array.isArray(opts)) { e.preventDefault(); showToast(`Question ${i+1}: internal structure invalid.`); return; }
      if (opts.length === 0) {
        A[i] = null;
      } else if (opts.length >= 2) {
        if (!Number.isInteger(A[i]) || A[i] < 0 || A[i] >= opts.length) {
          e.preventDefault(); showToast(`Select a correct answer for question ${i+1}.`); return;
        }
        for (let j=0;j<opts.length;j++){
          if (!opts[j] || String(opts[j]).trim() === '') { e.preventDefault(); showToast(`Question ${i+1} option ${j+1} cannot be empty.`); return; }
        }
      } else {
        e.preventDefault(); showToast(`Question ${i+1}: add 2+ options or switch to Paragraph.`); return;
      }
    }
    document.getElementById('questionsJson').value = JSON.stringify(Q);
    document.getElementById('optionsJson').value   = JSON.stringify(O);
    document.getElementById('answersJson').value   = JSON.stringify(A);
  });

  // Initial render
  if (!Array.isArray(initial.questions)) initial.questions = [];
  if (!Array.isArray(initial.options)) initial.options = initial.questions.map(()=>['Option 1','Option 2']);
  if (!Array.isArray(initial.answers)) initial.answers = initial.questions.map(()=>0);
  const len = initial.questions.length;
  initial.options.length = len;
  initial.answers.length = len;
  for (let i=0;i<len;i++){
    if (!Array.isArray(initial.options[i])) initial.options[i] = ['Option 1','Option 2'];
    if (!Number.isInteger(initial.answers[i])) initial.answers[i] = 0;
  }
  render();
})();
</script>
</body>
</html>