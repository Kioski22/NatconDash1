<?php
// Performance optimization helper for NatCon evaluation tables.
// Adds recommended indexes if missing to comfortably handle 3k+ daily submissions.
// Admin only.
session_start();
require_once __DIR__ . '/../db.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') { http_response_code(403); echo 'Admin only'; exit; }
if (isset($conn) && $conn instanceof mysqli) $db = $conn; elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli; else { die('DB unavailable'); }

header('Content-Type: text/html; charset=utf-8');

// (Legacy helper removed; inline checks used below)

$targets = [
  'natcon_eval_questions' => [ ['idx_active_sort','(is_active, sort_order)'] ],
  'natcon_eval_options' => [ ['idx_question_id','(question_id)'] ],
  'natcon_eval_responses' => [ ['idx_user_id','(user_id)'], ['idx_submitted_at','(submitted_at)'] ],
  'natcon_eval_response_items' => [ ['idx_response_id','(response_id)'], ['idx_question_id','(question_id)'], ['idx_question_option','(question_id, option_id)'] ]
];

$executed=[]; $errors=[]; $skipped=[];
foreach ($targets as $table=>$indexes){
  foreach ($indexes as [$name,$cols]){
    // MySQL 5.x has no IF NOT EXISTS for indexes -> manual existence check.
    $exists = false;
    if ($res = $db->query("SHOW INDEX FROM `$table`")) {
      while($r=$res->fetch_assoc()) { if ($r['Key_name']===$name) { $exists=true; break; } }
    }
    if ($exists){ $skipped[] = "$table.$name"; continue; }
    $sql = "ALTER TABLE `$table` ADD INDEX `$name` $cols"; // $cols already wrapped parentheses
    if ($db->query($sql)) $executed[]=$sql; else $errors[]=$sql.' -- '.$db->error;
  }
}

echo '<!doctype html><html><head><title>NatCon Evaluation Optimization</title><meta name="viewport" content="width=device-width,initial-scale=1">';
echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">';
echo '</head><body class="p-4">';
echo '<h4>NatCon Evaluation Performance Optimization</h4>';
if ($executed) {
  echo '<div class="alert alert-success">Added '.count($executed).' index(es).</div>';
  echo '<details class="mb-3"><summary>Executed SQL</summary><pre>'.htmlspecialchars(implode(";\n", $executed),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</pre></details>';
}
if ($skipped){
  echo '<div class="alert alert-info">Already present: '.htmlspecialchars(implode(', ',$skipped),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</div>';
}
if ($errors){
  echo '<div class="alert alert-danger"><strong>Errors:</strong><ul>'; foreach($errors as $e) echo '<li>'.htmlspecialchars($e,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</li>'; echo '</ul></div>';
}
echo '<p class="mt-3 small text-muted">Indexes help ensure fast lookups and insertion performance for thousands of daily writes.</p>';
echo '<a class="btn btn-primary" href="Admin_dashboard.php">Back to Dashboard</a>';
echo '</body></html>';
