<?php
// Diagnostic page for NatCon evaluation submission issues.
// Shows table structures and performs a dry-run insert to detect schema mismatches.
// Admin only.
session_start();
require_once __DIR__ . '/../db.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') { http_response_code(403); echo 'Admin only'; exit; }

if (isset($conn) && $conn instanceof mysqli) $db = $conn; elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli; else { die('DB unavailable'); }

header('Content-Type: text/html; charset=utf-8');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }

$tables = ['natcon_eval_questions','natcon_eval_options','natcon_eval_responses','natcon_eval_response_items'];
$structures = [];
foreach ($tables as $t) {
  $info = ['exists'=>false,'create'=>null,'columns'=>[]];
  $res = $db->query("SHOW TABLES LIKE '".$db->real_escape_string($t)."'");
  if ($res && $res->num_rows) {
    $info['exists']=true;
    if ($c = $db->query("SHOW CREATE TABLE `$t`")) { $row = $c->fetch_assoc(); $info['create'] = $row['Create Table'] ?? ''; }
    if ($cols = $db->query("SHOW COLUMNS FROM `$t`")) {
      while ($row = $cols->fetch_assoc()) { $info['columns'][] = $row; }
    }
  }
  $structures[$t] = $info;
}

$testResult = [];
try {
  $db->begin_transaction();
  // minimal insert into responses
  $stmt = $db->prepare('INSERT INTO natcon_eval_responses (user_id, submitted_at) VALUES (?, NOW())');
  if (!$stmt) throw new Exception('Prepare responses failed: '.$db->error);
  $uid = (int)($_SESSION['user_id'] ?? 1);
  $stmt->bind_param('i',$uid);
  if (!$stmt->execute()) throw new Exception('Insert responses failed: '.$db->error);
  $rid = $stmt->insert_id; $stmt->close();

  // attempt insert into items with all nullable fields null
  $stmt2 = $db->prepare('INSERT INTO natcon_eval_response_items (response_id, question_id, numeric_value, option_id, text_answer, checkbox_values) VALUES (?,?,?,?,?,?)');
  if (!$stmt2) throw new Exception('Prepare items failed: '.$db->error);
  $qid = 999999; // deliberately non-existing question id for dry run
  $num = null; $opt = null; $txt = null; $chk = null;
  if (!$stmt2->bind_param('iiisss',$rid,$qid,$num,$opt,$txt,$chk)) throw new Exception('Bind failed: '.$stmt2->error);
  if (!$stmt2->execute()) throw new Exception('Execute item failed: '.$stmt2->error);
  $stmt2->close();
  $db->rollback(); // rollback dry run
  $testResult = ['ok'=>true,'message'=>'Dry-run inserts succeeded (rolled back).'];
} catch (Throwable $e) {
  $db->rollback();
  $testResult = ['ok'=>false,'message'=>$e->getMessage()];
}

echo '<!doctype html><html><head><title>NatCon Eval Diagnostics</title><meta name="viewport" content="width=device-width,initial-scale=1">';
echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">';
echo '</head><body class="p-4">';
echo '<h3>NatCon Evaluation Diagnostics</h3>';
echo '<p class="text-muted">Use this page to identify schema issues causing 500 errors during submission.</p>';

echo '<h5 class="mt-3">Dry Run Result</h5>';
if ($testResult['ok']) {
  echo '<div class="alert alert-success">'.h($testResult['message']).'</div>';
} else {
  echo '<div class="alert alert-danger"><strong>Dry run failed:</strong> '.h($testResult['message']).'</div>';
}

echo '<h5 class="mt-4">Table Structures</h5>';
foreach ($structures as $name=>$info) {
  echo '<div class="mb-4">';
  echo '<h6>'.h($name).' '.($info['exists']?'<span class="badge bg-success">exists</span>':'<span class="badge bg-danger">missing</span>').'</h6>';
  if ($info['exists']) {
    echo '<details class="mb-2" open><summary class="small text-muted">CREATE TABLE</summary><pre style="white-space:pre-wrap;font-size:.75rem;background:#f8f9fa;padding:8px;border:1px solid #e1e4e8;">'.h($info['create']).'</pre></details>';
    echo '<div class="table-responsive"><table class="table table-sm table-bordered"><thead><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead><tbody>';
    foreach ($info['columns'] as $col) {
      echo '<tr>';
      foreach (['Field','Type','Null','Key','Default','Extra'] as $k) {
        $v = $col[$k]; if ($v===null||$v==='') $v = '<span class="text-muted">—</span>';
        echo '<td>'.h($v).'</td>';
      }
      echo '</tr>';
    }
    echo '</tbody></table></div>';
  }
  echo '</div>';
}

echo '<a href="admin_eval_natcon.php" class="btn btn-secondary">Back to Eval Admin</a> ';
echo '<a href="../dashboard/natcon_evaluation.php" class="btn btn-primary">Go to Public Form</a>';
echo '</body></html>';