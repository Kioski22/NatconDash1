<?php
session_start();
require_once __DIR__ . '/../db.php';

if (isset($conn) && $conn instanceof mysqli) {
    $db = $conn;
} elseif (isset($mysqli) && $mysqli instanceof mysqli) {
    $db = $mysqli;
} else {
    http_response_code(500);
    echo "Database connection not available.";
    exit;
}

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: admin_login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

if (!function_exists('e')) {
    function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return 0 === strncmp((string)$haystack, (string)$needle, strlen((string)$needle));
    }
}
if (!function_exists('asset_url')) {
  function asset_url($path){
    $path = (string)$path;
    if ($path==='') return '';
    if (preg_match('#^https?://#i',$path)) return $path;
    // normalize root relative
    $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
    if ($base === '.' || $base === '/') $base = '';
    return $base . '/' . ltrim($path,'/');
  }
}

function get_setting(mysqli $db, string $key, $default = '') {
    $stmt = $db->prepare("SELECT setting_value FROM admin_settings WHERE setting_key = ? LIMIT 1");
    if (!$stmt) return $default;
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->bind_result($val);
    if ($stmt->fetch()) {
        $stmt->close();
        return $val;
    }
    $stmt->close();
    return $default;
}
function set_setting(mysqli $db, string $key, $val): bool {
    $stmt = $db->prepare("INSERT INTO admin_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $key, $val);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

$errors = [];
$success = '';

$tab = isset($_GET['tab']) ? trim((string)$_GET['tab']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], (string)$token)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? 'save';
    if ($action === 'save_rules') {
      // Global controls (master enable + global activation window)
      $enabled = isset($_POST['cert_generation_enabled']) ? '1' : '0';
      $autoDate = trim((string)($_POST['cert_auto_enable_date'] ?? ''));

      // Main Program specific rules
      $mainRequireQuiz = isset($_POST['main_cert_require_quiz']) ? '1' : '0';
      $mainRequireEval = isset($_POST['main_cert_require_eval']) ? '1' : '0';
      $mainMinScore   = isset($_POST['main_cert_min_score']) ? max(0, min(100, (int)$_POST['main_cert_min_score'])) : 0;

      // Sub Program specific rules
      $subRequireQuiz = isset($_POST['sub_cert_require_quiz']) ? '1' : '0';
      $subRequireEval = isset($_POST['sub_cert_require_eval']) ? '1' : '0';
      $subMinScore    = isset($_POST['sub_cert_min_score']) ? max(0, min(100, (int)$_POST['sub_cert_min_score'])) : 0;

      $ok = true;
      // Save global controls
      $ok = $ok && set_setting($db, 'cert_generation_enabled', $enabled);
      $ok = $ok && set_setting($db, 'cert_auto_enable_date', $autoDate);

      // Save program-specific rules
      $ok = $ok && set_setting($db, 'main_cert_require_quiz', $mainRequireQuiz);
      $ok = $ok && set_setting($db, 'main_cert_require_eval', $mainRequireEval);
      $ok = $ok && set_setting($db, 'main_cert_min_score', (string)$mainMinScore);

      $ok = $ok && set_setting($db, 'sub_cert_require_quiz', $subRequireQuiz);
      $ok = $ok && set_setting($db, 'sub_cert_require_eval', $subRequireEval);
      $ok = $ok && set_setting($db, 'sub_cert_min_score', (string)$subMinScore);

      // Backwards compatibility: mirror Main Program rules to legacy keys used elsewhere
      $ok = $ok && set_setting($db, 'cert_require_quiz', $mainRequireQuiz);
      $ok = $ok && set_setting($db, 'cert_require_eval', $mainRequireEval);
      $ok = $ok && set_setting($db, 'cert_min_score', (string)$mainMinScore);

      if ($ok) $success = 'Eligibility rules saved.'; else $errors[] = 'Failed to save rules.';
      $tab = 'rules';
    } elseif ($action === 'delete_cert') {
      $cid = isset($_POST['cert_id']) ? (int)$_POST['cert_id'] : 0;
      if ($cid > 0) {
        // Fetch asset paths for cleanup (bg + signatures)
        $bg = '';
        if ($st = $db->prepare("SELECT bg_url FROM certificates WHERE id=? LIMIT 1")) {
          $st->bind_param('i', $cid); $st->execute(); $st->bind_result($bg); $st->fetch(); $st->close();
        }
        $sigPaths = [];
        if ($rs = $db->prepare("SELECT signature_url FROM certificate_signatures WHERE certificate_id=?")) {
          $rs->bind_param('i', $cid); $rs->execute(); $res = $rs->get_result();
          while ($row = $res->fetch_assoc()) { if (!empty($row['signature_url'])) $sigPaths[] = $row['signature_url']; }
          $rs->close();
        }
        // Delete signatures first
        if ($delS = $db->prepare("DELETE FROM certificate_signatures WHERE certificate_id=?")) { $delS->bind_param('i',$cid); $delS->execute(); $delS->close(); }
        // Delete certificate
        if ($delC = $db->prepare("DELETE FROM certificates WHERE id=? LIMIT 1")) { $delC->bind_param('i',$cid); $delOk = $delC->execute(); $delC->close(); }
        if (!empty($delOk)) {
          // Attempt file cleanup (ignore failures)
          $tryUnlink = function($rel){
            $rel = (string)$rel; if ($rel==='') return; if (preg_match('#^https?://#i',$rel)) return; $abs = realpath(__DIR__ . '/../' . $rel); if ($abs && strpos($abs, realpath(__DIR__.'/..'))===0 && is_file($abs)) { @unlink($abs); }
          };
          $tryUnlink($bg);
          foreach ($sigPaths as $p) { $tryUnlink($p); }
          $success = 'Certificate deleted.';
        } else {
          $errors[] = 'Delete failed or certificate not found.';
        }
      } else {
        $errors[] = 'Invalid certificate id.';
      }
      $tab = '';
        } else {
            $errors[] = 'Unknown action.';
        }
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    $_SESSION['cert_messages'] = ['errors'=>$errors, 'success'=>$success];
    header('Location: admin_certificate_settings.php?tab=' . urlencode($tab));
    exit;
}

$messages = $_SESSION['cert_messages'] ?? null;
if ($messages) {
    $errors = array_merge($errors, $messages['errors'] ?? []);
    if (!empty($messages['success'])) $success = $messages['success'];
    unset($_SESSION['cert_messages']);
}

$cert_generation_enabled = get_setting($db, 'cert_generation_enabled', '0') === '1';
$cert_require_quiz = get_setting($db, 'cert_require_quiz', '1') === '1'; // legacy/global default
$cert_require_eval = get_setting($db, 'cert_require_eval', '0') === '1'; // legacy/global default
$cert_min_score = (int)(get_setting($db, 'cert_min_score', '0') ?: 0);   // legacy/global default
$cert_auto_enable_date = get_setting($db, 'cert_auto_enable_date', '');

// Program-specific rules (fallback to legacy/global defaults when not set)
$main_cert_require_quiz = (get_setting($db, 'main_cert_require_quiz', $cert_require_quiz ? '1' : '0') === '1');
$main_cert_require_eval = (get_setting($db, 'main_cert_require_eval', $cert_require_eval ? '1' : '0') === '1');
$main_cert_min_score    = (int)(get_setting($db, 'main_cert_min_score', (string)$cert_min_score) ?: $cert_min_score);

$sub_cert_require_quiz  = (get_setting($db, 'sub_cert_require_quiz', $cert_require_quiz ? '1' : '0') === '1');
$sub_cert_require_eval  = (get_setting($db, 'sub_cert_require_eval', $cert_require_eval ? '1' : '0') === '1');
$sub_cert_min_score     = (int)(get_setting($db, 'sub_cert_min_score', (string)$cert_min_score) ?: $cert_min_score);

// Preload certificate templates list (for main tab only)
$certificate_rows = [];
if ($tab === '') {
  $q = $db->query("SELECT id, type, bg_url, cert_awardee_name, seminar_title, created_at FROM certificates ORDER BY id DESC");
  if ($q) { while($r=$q->fetch_assoc()){ $certificate_rows[]=$r; } $q->close(); }
  // Fetch signature counts
  $sigCounts = [];
  $qc = $db->query("SELECT certificate_id, COUNT(*) c FROM certificate_signatures GROUP BY certificate_id");
  if ($qc) { while($r=$qc->fetch_assoc()){ $sigCounts[(int)$r['certificate_id']] = (int)$r['c']; } $qc->close(); }
} else { $sigCounts = []; }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Certificate Management — 73rd PSME National Convention</title>
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
      --gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      --gradient-success: linear-gradient(135deg, #10b981 0%, #059669 100%);
      --gradient-warning: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
      --gradient-danger: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
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
      background: var(--gradient-primary);
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
    }

    .card-body-enhanced {
      padding: 2rem;
    }

    /* Option Cards */
    .option-card {
      background: rgba(255, 255, 255, 0.98);
      border: 2px solid rgba(255, 255, 255, 0.3);
      border-radius: var(--border-radius);
      padding: 2rem;
      text-align: center;
      transition: all 0.3s ease;
      height: 100%;
      display: flex;
      flex-direction: column;
      position: relative;
      overflow: hidden;
    }

    .option-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: var(--gradient-primary);
      transform: scaleX(0);
      transition: transform 0.3s ease;
    }

    .option-card:hover {
      transform: translateY(-8px) scale(1.02);
      box-shadow: 0 25px 50px -10px rgba(0, 0, 0, 0.2);
      border-color: rgba(37,99,235,0.3);
    }

    .option-card:hover::before {
      transform: scaleX(1);
    }

    .option-icon {
      font-size: 3rem;
      margin-bottom: 1rem;
      background: var(--gradient-primary);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .option-title {
      font-weight: 600;
      font-size: 1.25rem;
      color: var(--dark);
      margin-bottom: 0.75rem;
    }

    .option-description {
      color: #64748b;
      flex-grow: 1;
      margin-bottom: 1.5rem;
    }

    /* Form Enhancements */
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

    .form-switch .form-check-input {
      width: 3rem;
      height: 1.5rem;
      border-radius: 2rem;
    }

    .form-switch .form-check-input:checked {
      background-color: var(--success);
      border-color: var(--success);
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

    .btn-gradient-primary {
      background: var(--gradient-primary);
      color: white;
    }

    .btn-gradient-success {
      background: var(--gradient-success);
      color: white;
    }

    .btn-gradient-warning {
      background: var(--gradient-warning);
      color: white;
    }

    .btn-gradient-danger {
      background: var(--gradient-danger);
      color: white;
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
      background: var(--gradient-primary);
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
    .badge {
      padding: 0.5rem 0.75rem;
      font-weight: 500;
      border-radius: 20px;
    }

    .badge-cert {
      background: linear-gradient(135deg, rgba(16,185,129,0.15), rgba(5,150,105,0.15));
      color: #047857;
      border: 1px solid rgba(16,185,129,0.3);
    }

    /* Alerts */
    .alert {
      border-radius: var(--border-radius);
      border: none;
      padding: 1rem 1.5rem;
      margin-bottom: 1.5rem;
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

    /* Empty State */
    .empty-state {
      text-align: center;
      padding: 4rem 2rem;
      color: #64748b;
    }

    .empty-state i {
      font-size: 4rem;
      opacity: 0.3;
      margin-bottom: 1rem;
    }

    /* Settings Grid */
    .settings-grid {
      display: grid;
      gap: 1.5rem;
    }

    .setting-item {
      background: rgba(255, 255, 255, 0.8);
      border-radius: 12px;
      padding: 1.5rem;
      border: 2px solid rgba(255, 255, 255, 0.3);
      transition: all 0.3s ease;
    }

    .setting-item:hover {
      background: rgba(255, 255, 255, 0.95);
      border-color: rgba(37,99,235,0.2);
      transform: translateY(-2px);
    }

    .setting-label {
      font-weight: 600;
      color: var(--dark);
      margin-bottom: 0.5rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .setting-description {
      font-size: 0.875rem;
      color: #64748b;
      margin-bottom: 1rem;
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
      
      .card-body-enhanced {
        padding: 1.5rem;
      }
      
      .option-card {
        padding: 1.5rem;
      }
    }

    /* Animations */
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
    <h1 class="page-title">Certificate Management</h1>
    <p class="page-subtitle">Configure certificate generation, templates, and eligibility rules</p>
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
      <ul class="mb-0 mt-2">
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

  <?php if ($tab === ''): ?>
    <!-- Management Options -->
    <div class="row g-4 mb-4">
      <div class="col-lg-4">
        <div class="option-card slide-up">
          <div class="option-icon">
            <i class="bi bi-gear-fill"></i>
          </div>
          <h3 class="option-title">Eligibility & Rules</h3>
          <p class="option-description">Configure certificate generation settings, passing scores, and requirements for quiz completion and evaluations.</p>
          <a href="?tab=rules" class="btn btn-gradient-primary">
            <i class="bi bi-sliders me-2"></i>Manage Rules
          </a>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="option-card slide-up" style="animation-delay: 0.1s;">
          <div class="option-icon">
            <i class="bi bi-file-earmark-richtext"></i>
          </div>
          <h3 class="option-title">Certificate Templates</h3>
          <p class="option-description">Create and edit certificate layouts, backgrounds, signatures, and content for different program types.</p>
          <a href="admin_certificate_edit.php" class="btn btn-gradient-success">
            <i class="bi bi-plus-circle me-2"></i>Edit Templates
          </a>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="option-card slide-up" style="animation-delay: 0.2s;">
          <div class="option-icon">
            <i class="bi bi-eye-fill"></i>
          </div>
          <h3 class="option-title">Preview & Test</h3>
          <p class="option-description">Preview certificate designs and test generation with sample data before deploying to users.</p>
          <a href="admin_certificate_preview.php" class="btn btn-gradient-warning">
            <i class="bi bi-play-circle me-2"></i>Preview Certs
          </a>
        </div>
      </div>
    </div>

    <!-- Existing Templates -->
    <div class="enhanced-card slide-up">
      <div class="card-header-enhanced">
        <div class="d-flex justify-content-between align-items-center">
          <h5 class="mb-0">
            <i class="bi bi-collection me-2"></i>
            Certificate Templates
          </h5>
          <a href="admin_certificate_edit.php" class="btn btn-gradient-primary">
            <i class="bi bi-plus-circle me-2"></i>New Template
          </a>
        </div>
      </div>
      <div class="card-body-enhanced">
        <?php if (empty($certificate_rows)): ?>
          <div class="empty-state">
            <i class="bi bi-file-earmark-richtext"></i>
            <h6>No Certificate Templates</h6>
            <p class="mb-3">Create your first certificate template to get started</p>
            <a href="admin_certificate_edit.php" class="btn btn-gradient-primary">
              <i class="bi bi-plus-circle me-2"></i>Create Template
            </a>
          </div>
        <?php else: ?>
          <div class="table-container">
            <table class="table table-hover align-middle">
              <thead>
                <tr>
                  <th><i class="bi bi-hash me-1"></i>ID</th>
                  <th><i class="bi bi-tag me-1"></i>Type</th>
                  <th><i class="bi bi-image me-1"></i>Preview</th>
                  <th><i class="bi bi-person me-1"></i>Awardee</th>
                  <th><i class="bi bi-bookmark me-1"></i>Seminar</th>
                  <th><i class="bi bi-pen me-1"></i>Signatures</th>
                  <th><i class="bi bi-calendar me-1"></i>Created</th>
                  <th><i class="bi bi-gear me-1"></i>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($certificate_rows as $row): $cid=(int)$row['id']; ?>
                <tr>
                  <td><span class="badge badge-cert"><?= $cid ?></span></td>
                  <td><strong><?= e($row['type']) ?></strong></td>
                  <td>
                    <?php if (!empty($row['bg_url'])): $thumb = asset_url($row['bg_url']); ?>
                      <a href="admin_certificate_preview.php?id=<?= $cid ?>" title="Open Preview" target="_blank">
                        <img src="<?= e($thumb) ?>" alt="bg" style="width:80px;height:40px;object-fit:cover;border:2px solid #dee2e6;border-radius:8px;transition:all 0.2s ease;">
                      </a>
                    <?php else: ?>
                      <span class="text-muted"><i class="bi bi-image"></i> No image</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div style="max-width:160px;" title="<?= e($row['cert_awardee_name']) ?>">
                      <?= e(substr($row['cert_awardee_name'], 0, 25)) ?><?= strlen($row['cert_awardee_name']) > 25 ? '...' : '' ?>
                    </div>
                  </td>
                  <td>
                    <div style="max-width:160px;" title="<?= e($row['seminar_title']) ?>">
                      <?= e(substr($row['seminar_title'], 0, 25)) ?><?= strlen($row['seminar_title']) > 25 ? '...' : '' ?>
                    </div>
                  </td>
                  <td>
                    <span class="badge bg-info"><?= isset($sigCounts[$cid]) ? (int)$sigCounts[$cid] : 0 ?></span>
                  </td>
                  <td>
                    <small class="text-muted">
                      <?= date('M j, Y', strtotime($row['created_at'] ?? '')) ?>
                    </small>
                  </td>
                  <td>
                    <div class="d-flex gap-1">
                      <a class="btn btn-sm btn-outline-primary" href="admin_certificate_preview.php?id=<?= $cid ?>" target="_blank" title="Preview">
                        <i class="bi bi-eye"></i>
                      </a>
                      <a class="btn btn-sm btn-gradient-success" href="admin_certificate_edit.php?id=<?= $cid ?>" title="Edit">
                        <i class="bi bi-pencil"></i>
                      </a>
                      <form method="post" class="d-inline" onsubmit="return confirm('Delete certificate #<?= $cid ?>? This action cannot be undone.');">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="delete_cert">
                        <input type="hidden" name="cert_id" value="<?= $cid ?>">
                        <button class="btn btn-sm btn-gradient-danger" title="Delete">
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
    <?php endif; ?>

  <?php if ($tab === 'rules'): ?>
    <!-- Rules Configuration -->
    <div class="enhanced-card slide-up">
      <div class="card-header-enhanced">
        <div class="d-flex justify-content-between align-items-center">
          <h5 class="mb-0">
            <i class="bi bi-sliders me-2"></i>
            Certificate Eligibility Rules
          </h5>
          <a href="admin_certificate_settings.php" class="btn btn-outline-primary">
            <i class="bi bi-arrow-left me-2"></i>Back to Overview
          </a>
        </div>
      </div>
      <div class="card-body-enhanced">
        <form method="post" class="settings-grid">
          <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="save_rules">
          
          <!-- Master Enable -->
          <div class="setting-item">
            <div class="setting-label">
              <i class="bi bi-power text-success"></i>
              Certificate Generation
            </div>
            <div class="setting-description">
              Enable or disable certificate generation system-wide. When disabled, users cannot generate any certificates.
            </div>
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" id="cert_enabled" name="cert_generation_enabled" <?= $cert_generation_enabled ? 'checked' : '' ?>>
              <label class="form-check-label fw-semibold" for="cert_enabled">
                <?= $cert_generation_enabled ? 'Enabled' : 'Disabled' ?>
              </label>
            </div>
          </div>

          <!-- Auto Enable Date -->
          <div class="setting-item">
            <div class="setting-label">
              <i class="bi bi-calendar-event text-info"></i>
              Scheduled Activation
            </div>
            <div class="setting-description">
              Automatically enable certificate generation at a specific date and time. Leave blank to manage manually.
            </div>
            <input type="datetime-local" name="cert_auto_enable_date" class="form-control" value="<?= e($cert_auto_enable_date) ?>" placeholder="Select date and time">
          </div>

          <!-- Section: Main Program Rules -->
          <div class="setting-item">
            <div class="setting-label">
              <i class="bi bi-mortarboard text-primary"></i>
              Main Program Certificates — Rules
            </div>
            <div class="setting-description">
              Configure requirements specific to Main Program certificates. These override global defaults when set.
            </div>
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label fw-semibold">Passing Score Threshold</label>
                <div class="input-group">
                  <input type="number" name="main_cert_min_score" class="form-control" min="0" max="100" value="<?= (int)$main_cert_min_score ?>" placeholder="70">
                  <span class="input-group-text">%</span>
                </div>
              </div>
              <div class="col-md-4 d-flex align-items-end">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="main_require_quiz" name="main_cert_require_quiz" <?= $main_cert_require_quiz ? 'checked' : '' ?>>
                  <label class="form-check-label fw-semibold" for="main_require_quiz">
                    <?= $main_cert_require_quiz ? 'Required' : 'Optional' ?> — Quiz Completion
                  </label>
                </div>
              </div>
              <div class="col-md-4 d-flex align-items-end">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="main_require_eval" name="main_cert_require_eval" <?= $main_cert_require_eval ? 'checked' : '' ?>>
                  <label class="form-check-label fw-semibold" for="main_require_eval">
                    <?= $main_cert_require_eval ? 'Required' : 'Optional' ?> — Speaker Evaluations
                  </label>
                </div>
              </div>
            </div>
          </div>

          <!-- Section: Sub Program Rules -->
          <div class="setting-item">
            <div class="setting-label">
              <i class="bi bi-diagram-3 text-info"></i>
              Sub Program Certificates — Rules
            </div>
            <div class="setting-description">
              Configure requirements specific to Sub Program certificates. These override global defaults when set.
            </div>
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label fw-semibold">Passing Score Threshold</label>
                <div class="input-group">
                  <input type="number" name="sub_cert_min_score" class="form-control" min="0" max="100" value="<?= (int)$sub_cert_min_score ?>" placeholder="70">
                  <span class="input-group-text">%</span>
                </div>
              </div>
              <div class="col-md-4 d-flex align-items-end">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="sub_require_quiz" name="sub_cert_require_quiz" <?= $sub_cert_require_quiz ? 'checked' : '' ?>>
                  <label class="form-check-label fw-semibold" for="sub_require_quiz">
                    <?= $sub_cert_require_quiz ? 'Required' : 'Optional' ?> — Quiz Completion
                  </label>
                </div>
              </div>
              <div class="col-md-4 d-flex align-items-end">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" id="sub_require_eval" name="sub_cert_require_eval" <?= $sub_cert_require_eval ? 'checked' : '' ?>>
                  <label class="form-check-label fw-semibold" for="sub_require_eval">
                    <?= $sub_cert_require_eval ? 'Required' : 'Optional' ?> — Speaker Evaluations
                  </label>
                </div>
              </div>
            </div>
          </div>

          <!-- Action Buttons -->
          <div class="setting-item">
            <div class="d-flex gap-3">
              <button type="submit" class="btn btn-gradient-success flex-fill">
                <i class="bi bi-check-circle me-2"></i>Save Rules
              </button>
              <a href="admin_certificate_settings.php" class="btn btn-outline-secondary flex-fill">
                <i class="bi bi-x-circle me-2"></i>Cancel Changes
              </a>
            </div>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Enhanced interactions
document.addEventListener('DOMContentLoaded', function() {
  // Add hover effects to table rows
  const tableRows = document.querySelectorAll('.table tbody tr');
  tableRows.forEach(row => {
    row.addEventListener('mouseenter', function() {
      this.style.transform = 'scale(1.01)';
    });
    row.addEventListener('mouseleave', function() {
      this.style.transform = 'scale(1)';
    });
  });

  // Dynamic label updates for switches
  const switches = document.querySelectorAll('.form-switch .form-check-input');
  switches.forEach(switchEl => {
    const label = switchEl.nextElementSibling;
    if (label && label.classList.contains('fw-semibold')) {
      switchEl.addEventListener('change', function() {
        if (switchEl.id === 'cert_enabled') {
          label.textContent = switchEl.checked ? 'Enabled' : 'Disabled';
        } else if (switchEl.id.includes('require_quiz') || switchEl.id.includes('require_eval')) {
          // Keep any descriptive suffix on the label text after the status
          const parts = label.textContent.split('—');
          const suffix = parts.length > 1 ? ' — ' + parts.slice(1).join('—').trim() : '';
          label.textContent = (switchEl.checked ? 'Required' : 'Optional') + suffix;
        }
      });
    }
  });

  // Image hover effects
  const certImages = document.querySelectorAll('img[alt="bg"]');
  certImages.forEach(img => {
    img.addEventListener('mouseenter', function() {
      this.style.transform = 'scale(1.05)';
      this.style.borderColor = '#667eea';
    });
    img.addEventListener('mouseleave', function() {
      this.style.transform = 'scale(1)';
      this.style.borderColor = '#dee2e6';
    });
  });

  // Auto-save indication
  const form = document.querySelector('form[method="post"]');
  if (form) {
    const inputs = form.querySelectorAll('input, select, textarea');
    inputs.forEach(input => {
      input.addEventListener('change', function() {
        // Add subtle indication that changes need to be saved
        const saveBtn = form.querySelector('button[type="submit"]');
        if (saveBtn) {
          saveBtn.classList.add('btn-gradient-warning');
          saveBtn.innerHTML = '<i class="bi bi-exclamation-circle me-2"></i>Save Changes';
        }
      });
    });
  }
});
</script>
</body>
</html>
