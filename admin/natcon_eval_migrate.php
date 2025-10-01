<?php
// One-off migration helper for natcon_eval_questions table
// Adds missing columns used by the new evaluation system.
// Secure: admin-only, manual invocation.

session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
  http_response_code(403); echo 'Admin only'; exit;
}

if (isset($conn) && $conn instanceof mysqli) $db = $conn; elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli; else { die('DB unavailable'); }

$required = [
  'question_text'      => 'ALTER TABLE natcon_eval_questions ADD COLUMN question_text TEXT NOT NULL',
  'question_type'      => 'ALTER TABLE natcon_eval_questions ADD COLUMN question_type VARCHAR(64) NOT NULL DEFAULT "scale"',
  'is_required'        => 'ALTER TABLE natcon_eval_questions ADD COLUMN is_required TINYINT(1) NOT NULL DEFAULT 1',
  'is_active'          => 'ALTER TABLE natcon_eval_questions ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1',
  'sort_order'         => 'ALTER TABLE natcon_eval_questions ADD COLUMN sort_order INT NOT NULL DEFAULT 10',
  'min_scale'          => 'ALTER TABLE natcon_eval_questions ADD COLUMN min_scale INT NULL',
  'max_scale'          => 'ALTER TABLE natcon_eval_questions ADD COLUMN max_scale INT NULL',
  'scale_label_left'   => 'ALTER TABLE natcon_eval_questions ADD COLUMN scale_label_left VARCHAR(100) NULL',
  'scale_label_right'  => 'ALTER TABLE natcon_eval_questions ADD COLUMN scale_label_right VARCHAR(100) NULL'
];

$existing = [];
if ($res = $db->query("SHOW COLUMNS FROM natcon_eval_questions")) {
  while ($row = $res->fetch_assoc()) { $existing[] = $row['Field']; }
} else {
  echo '<h3>Error: Table natcon_eval_questions not found.</h3>';
  echo '<p>Create it first, e.g.:</p><pre>CREATE TABLE natcon_eval_questions (\n  id INT AUTO_INCREMENT PRIMARY KEY\n) ENGINE=InnoDB;</pre>';
  exit;
}

$executed = [];
$errors = [];
foreach ($required as $col => $ddl) {
  if (!in_array($col, $existing, true)) {
    if ($db->query($ddl)) { $executed[] = $ddl; } else { $errors[] = $ddl.' -- ERROR: '.$db->error; }
  }
}

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><title>NatCon Eval Migration</title><meta name="viewport" content="width=device-width,initial-scale=1">';
echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="p-4">';
echo '<h4>NatCon Evaluation Migration</h4>';
if ($executed) {
  echo '<div class="alert alert-success"><strong>Added '.count($executed).' column(s)</strong></div>';
  echo '<details class="mb-3"><summary>Executed DDL</summary><pre>'.htmlspecialchars(implode(";\n", $executed), ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</pre></details>';
} else {
  echo '<div class="alert alert-info">No new columns were needed. Schema already up to date.</div>';
}
if ($errors) {
  echo '<div class="alert alert-danger"><strong>Errors:</strong><ul>'; foreach ($errors as $e) echo '<li>'.htmlspecialchars($e,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</li>'; echo '</ul></div>';
}

// Show final structure
if ($res2 = $db->query("SHOW COLUMNS FROM natcon_eval_questions")) {
  echo '<h6>Current Columns</h6><div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead><tbody>';
  while ($r = $res2->fetch_assoc()) {
    echo '<tr>';
    foreach (['Field','Type','Null','Key','Default','Extra'] as $k) {
      $v = $r[$k];
      if ($v === null) $v = '<span class="text-muted">NULL</span>';
      echo '<td>'.htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</td>';
    }
    echo '</tr>';
  }
  echo '</tbody></table></div>';
}

echo '<a class="btn btn-primary mt-3" href="admin_eval_natcon.php">Back to Evaluation Admin</a>';
echo '</body></html>';
