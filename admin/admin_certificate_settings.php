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
            $enabled = isset($_POST['cert_generation_enabled']) ? '1' : '0';
            $requireQuiz = isset($_POST['cert_require_quiz']) ? '1' : '0';
            $requireEval = isset($_POST['cert_require_eval']) ? '1' : '0';
            $minScore = isset($_POST['cert_min_score']) ? max(0, min(100, (int)$_POST['cert_min_score'])) : 0;
            $autoDate = trim((string)($_POST['cert_auto_enable_date'] ?? ''));
            $ok = true;
            $ok = $ok && set_setting($db, 'cert_generation_enabled', $enabled);
            $ok = $ok && set_setting($db, 'cert_require_quiz', $requireQuiz);
            $ok = $ok && set_setting($db, 'cert_require_eval', $requireEval);
            $ok = $ok && set_setting($db, 'cert_min_score', (string)$minScore);
            $ok = $ok && set_setting($db, 'cert_auto_enable_date', $autoDate);
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
$cert_require_quiz = get_setting($db, 'cert_require_quiz', '1') === '1';
$cert_require_eval = get_setting($db, 'cert_require_eval', '0') === '1';
$cert_min_score = (int)(get_setting($db, 'cert_min_score', '0') ?: 0);
$cert_auto_enable_date = get_setting($db, 'cert_auto_enable_date', '');

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
  <title>Certificate Center — Admin</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f8f9fa; }
    .option-card{ transition:transform .12s ease, box-shadow .12s ease; border:1px solid #e9ecef; }
    .option-card:hover{ transform:translateY(-2px); box-shadow:0 6px 22px rgba(0,0,0,.08); }
  </style>
</head>
<body class="p-3">
  <div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4>Certificate Center</h4>
      <a href="Admin_dashboard.php" class="btn btn-secondary btn-sm">Back</a>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <?php if ($tab === ''): ?>
      <div class="row g-3">
        <div class="col-md-6">
          <div class="card option-card h-100">
            <div class="card-body d-flex flex-column">
              <h5 class="card-title">Eligibility & Rules</h5>
              <p class="text-muted mb-4">Enable certificates, set minimum score, and require quiz/evaluation.</p>
              <a href="?tab=rules" class="btn btn-primary mt-auto">Open Rules</a>
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="card option-card h-100">
            <div class="card-body d-flex flex-column">
              <h5 class="card-title">Edit a Certificate</h5>
              <p class="text-muted mb-4">Edit specific certificate content for Attendance, Appearance, or CPD.</p>
              <a href="admin_certificate_edit.php" class="btn btn-success mt-auto">Go to Edit Form</a>
            </div>
          </div>
        </div>
      </div>

      <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h6 class="mb-0">Existing Certificate Templates</h6>
          <a href="admin_certificate_edit.php" class="btn btn-sm btn-outline-primary">+ New Template</a>
        </div>
        <div class="card-body p-0">
          <?php if (empty($certificate_rows)): ?>
            <div class="p-3 text-muted">No certificate templates created yet.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                  <tr>
                    <th style="width:60px">ID</th>
                    <th>Type</th>
                    <th>Preview</th>
                    <th>Awardee Placeholder</th>
                    <th>Seminar Title Placeholder</th>
                    <th class="text-center">Signatures</th>
                    <th>Created</th>
                    <th style="width:140px" class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($certificate_rows as $row): $cid=(int)$row['id']; ?>
                  <tr>
                    <td><?= $cid ?></td>
                    <td><?= e($row['type']) ?></td>
                    <td>
                      <?php if (!empty($row['bg_url'])): $thumb = asset_url($row['bg_url']); ?>
                        <a href="admin_certificate_preview.php?id=<?= $cid ?>" title="Open Preview" target="_blank">
                          <img src="<?= e($thumb) ?>" alt="bg" style="width:80px;height:40px;object-fit:cover;border:1px solid #dee2e6;border-radius:4px;">
                        </a>
                      <?php else: ?>
                        <span class="text-muted">(none)</span>
                      <?php endif; ?>
                    </td>
                    <td><small class="d-block text-truncate" style="max-width:160px;" title="<?= e($row['cert_awardee_name']) ?>"><?= e($row['cert_awardee_name']) ?></small></td>
                    <td><small class="d-block text-truncate" style="max-width:160px;" title="<?= e($row['seminar_title']) ?>"><?= e($row['seminar_title']) ?></small></td>
                    <td class="text-center">
                      <span class="badge bg-secondary"><?= isset($sigCounts[$cid]) ? (int)$sigCounts[$cid] : 0 ?></span>
                    </td>
                    <td><small><?= e($row['created_at'] ?? '') ?></small></td>
                    <td class="text-end">
                      <a class="btn btn-sm btn-outline-primary" href="admin_certificate_preview.php?id=<?= $cid ?>" target="_blank">Preview</a>
                      <a class="btn btn-sm btn-outline-success" href="admin_certificate_edit.php?id=<?= $cid ?>">Edit</a>
                      <form method="post" class="d-inline" onsubmit="return confirm('Delete certificate #<?= $cid ?>? This cannot be undone.');">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="delete_cert">
                        <input type="hidden" name="cert_id" value="<?= $cid ?>">
                        <button class="btn btn-sm btn-outline-danger">Delete</button>
                      </form>
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
      <div class="card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h6 class="mb-0">Eligibility Rules</h6>
          <a href="admin_certificate_settings.php" class="btn btn-sm btn-outline-secondary">Back to Options</a>
        </div>
        <div class="card-body">
          <form method="post" class="row g-3">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="save_rules">
            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" id="cert_enabled" name="cert_generation_enabled" <?= $cert_generation_enabled ? 'checked' : '' ?>>
              <label class="form-check-label" for="cert_enabled">Enable certificate generation</label>
            </div>
            <div class="row g-2">
              <div class="col-md-6">
                <label class="form-label">Auto-enable on date/time</label>
                <input type="datetime-local" name="cert_auto_enable_date" class="form-control" value="<?= e($cert_auto_enable_date) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Minimum quiz score (%)</label>
                <input type="number" name="cert_min_score" class="form-control" min="0" max="100" value="<?= (int)$cert_min_score ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label d-block">Requirements</label>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="require_quiz" name="cert_require_quiz" <?= $cert_require_quiz ? 'checked' : '' ?>>
                  <label class="form-check-label" for="require_quiz">Require quiz</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="require_eval" name="cert_require_eval" <?= $cert_require_eval ? 'checked' : '' ?>>
                  <label class="form-check-label" for="require_eval">Require evaluation</label>
                </div>
              </div>
            </div>
            <div class="d-flex gap-2 mt-2">
              <button class="btn btn-primary">Save Rules</button>
              <a href="admin_certificate_settings.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
