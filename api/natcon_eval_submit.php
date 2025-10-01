<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

// TEMP: enable verbose debug for all users (set to false in production)
$NATCON_EVAL_DEBUG = true;
require_once __DIR__ . '/../db.php';

if (isset($conn) && $conn instanceof mysqli) $db = $conn; elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli; else { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB unavailable']); exit; }

$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($user_id <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Login required']); exit; }

// Preflight: ensure required tables exist (avoid silent 500 if schema missing)
function table_exists(mysqli $db, string $t): bool {
  $res = $db->query("SHOW TABLES LIKE '".$db->real_escape_string($t)."'");
  return ($res && $res->num_rows > 0);
}
foreach (['natcon_eval_questions','natcon_eval_responses','natcon_eval_response_items'] as $tbl) {
  if (!table_exists($db,$tbl)) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Missing table: '.$tbl,'hint'=>'Create table or run migration/DDL.']);
    exit;
  }
}

// Ensure natcon_eval_response_items has required nullable columns
$requiredItemCols = [
  'numeric_value'   => 'ADD COLUMN numeric_value INT NULL',
  'option_id'       => 'ADD COLUMN option_id INT NULL',
  'text_answer'     => 'ADD COLUMN text_answer TEXT NULL',
  'checkbox_values' => 'ADD COLUMN checkbox_values TEXT NULL'
];
$existingItemCols = [];
if ($cRes = $db->query("SHOW COLUMNS FROM natcon_eval_response_items")) {
  while ($r = $cRes->fetch_assoc()) { $existingItemCols[] = $r['Field']; }
}
$missingItemCols = array_diff(array_keys($requiredItemCols), $existingItemCols);
if ($missingItemCols) {
  $attempted = [];$errors=[];$added=[];
  foreach ($missingItemCols as $mc) {
    $sql = 'ALTER TABLE natcon_eval_response_items '.$requiredItemCols[$mc];
    $attempted[] = $sql;
    if ($db->query($sql)) { $added[] = $mc; } else { $errors[] = $mc.': '.$db->error; }
  }
  // Re-check after attempted auto migration
  $existingItemCols = [];
  if ($cRes2 = $db->query("SHOW COLUMNS FROM natcon_eval_response_items")) while ($r2=$cRes2->fetch_assoc()) $existingItemCols[]=$r2['Field'];
  $stillMissing = array_diff(array_keys($requiredItemCols), $existingItemCols);
  if ($stillMissing) {
    http_response_code(500);
    echo json_encode([
      'ok'=>false,
      'error'=>'Response items schema incomplete: missing columns: '.implode(', ',$stillMissing),
      'auto_attempted'=>true,
      'auto_added'=>$added,
      'auto_errors'=>$errors
    ]);
    exit;
  }
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid JSON']); exit; }

// Duplicate submission check (one per user overall) - adjust if per-day etc. needed
$dupCheck = $db->prepare('SELECT id FROM natcon_eval_responses WHERE user_id=? LIMIT 1');
if (!$dupCheck) {
  http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Prepare duplicate check failed']); exit;
}
$dupCheck->bind_param('i',$user_id);
$dupCheck->execute();
$dupCheck->store_result();
if ($dupCheck->num_rows > 0) { http_response_code(409); echo json_encode(['ok'=>false,'error'=>'Already submitted']); $dupCheck->close(); exit; }
$dupCheck->close();

$answers = $data['answers'] ?? null; // array of {question_id:..., value:...}
if (!is_array($answers) || !$answers) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'No answers']); exit; }

// Load active questions
$qs = [];
if ($res = $db->query("SELECT id, question_type, is_required, min_scale, max_scale FROM natcon_eval_questions WHERE is_active=1")) {
  while ($row = $res->fetch_assoc()) {
    $id = (int)$row['id'];
    $qs[$id] = [
      'type' => $row['question_type'],
      'req'  => (int)$row['is_required'] === 1,
      'min'  => $row['min_scale'] !== null ? (int)$row['min_scale'] : null,
      'max'  => $row['max_scale'] !== null ? (int)$row['max_scale'] : null,
    ];
  }
} else {
  http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Failed to load questions','db_error'=>$db->error]); exit;
}
if (!$qs) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'No active questions']); exit; }

// Preload options per relevant question
$optionMap = [];
$optRes = $db->query('SELECT id, question_id FROM natcon_eval_options');
if ($optRes) {
  while ($row = $optRes->fetch_assoc()) {
    $qid = (int)$row['question_id'];
    $oid = (int)$row['id'];
    if (!isset($optionMap[$qid])) $optionMap[$qid] = [];
    $optionMap[$qid][] = $oid;
  }
}

$cleanAnswers = [];
$provided = [];
foreach ($answers as $a) {
  if (!isset($a['question_id'])) continue;
  $qid = (int)$a['question_id'];
  if (!isset($qs[$qid])) continue; // ignore unknown/inactive
  $type = $qs[$qid]['type'];
  $val = $a['value'] ?? null;
  $provided[$qid] = true;
  $entry = ['question_id'=>$qid];
  if ($type==='scale') {
    $n = (int)$val; if ($n < $qs[$qid]['min'] || $n > $qs[$qid]['max']) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Scale out of range']); exit; }
    $entry['numeric_value'] = $n;
  } elseif (in_array($type,['text','textarea'])) {
    $s = trim((string)$val); if ($s==='') { if ($qs[$qid]['req']) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Text required']); exit; } continue; }
    $entry['text_answer'] = mb_substr($s,0,2000);
  } elseif (in_array($type,['multiple_choice_single','dropdown'])) {
    $oid = (int)$val; if (!in_array($oid,$optionMap[$qid]??[],true)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid option']); exit; }
    $entry['option_id'] = $oid;
  } elseif ($type==='multiple_choice_multi') {
    if (!is_array($val)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Expected array']); exit; }
    $sel = array_values(array_unique(array_map('intval',$val)));
    if (!$sel && $qs[$qid]['req']) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'At least one option required']); exit; }
    foreach ($sel as $oid) if (!in_array($oid,$optionMap[$qid]??[],true)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid option in multi']); exit; }
    if ($sel) $entry['checkbox_values'] = json_encode($sel,JSON_UNESCAPED_UNICODE);
    else continue;
  } elseif ($type==='checkbox') {
    // single binary
    $entry['numeric_value'] = ($val ? 1 : 0);
  }
  $cleanAnswers[] = $entry;
}

// Check required
foreach ($qs as $qid=>$meta) {
  if ($meta['req'] && !isset($provided[$qid])) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Required question missing']); exit; }
}
if (!$cleanAnswers) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'No valid answers']); exit; }

$db->begin_transaction();
try {
  $ins = $db->prepare('INSERT INTO natcon_eval_responses (user_id, submitted_at) VALUES (?, NOW())');
  if (!$ins) throw new Exception('Prepare main failed: '.$db->error);
  $ins->bind_param('i',$user_id);
  if (!$ins->execute()) throw new Exception('Insert response failed: '.$db->error);
  $response_id = $ins->insert_id; $ins->close();

  // Ensure destination columns allow NULL; if not, adjust field list dynamically
  $itemStmt = $db->prepare('INSERT INTO natcon_eval_response_items (response_id, question_id, numeric_value, option_id, text_answer, checkbox_values) VALUES (?,?,?,?,?,?)');
  if (!$itemStmt) throw new Exception('Prepare item failed: '.$db->error);
  foreach ($cleanAnswers as $ca) {
    $qid = $ca['question_id'];
    $num = $ca['numeric_value'] ?? null;
    $opt = $ca['option_id'] ?? null;
    $txt = $ca['text_answer'] ?? null;
    $chk = $ca['checkbox_values'] ?? null;
    // Bind by reference each loop
    $itemStmt->bind_param('iiisss',$response_id,$qid,$num,$opt,$txt,$chk);
    if (!$itemStmt->execute()) throw new Exception('Insert item failed: '.$db->error);
  }
  $itemStmt->close();
  $db->commit();
  echo json_encode(['ok'=>true,'response_id'=>$response_id]);
} catch (Throwable $e) {
  $db->rollback();
  error_log('[NatConEval] Save failed for user '.$user_id.' : '.$e->getMessage());
  http_response_code(500);
  $payload = ['ok'=>false,'error'=>'Save failed'];
  if ($NATCON_EVAL_DEBUG || (isset($_SESSION['user_role']) && $_SESSION['user_role']==='admin')) {
    $payload['debug_message'] = $e->getMessage();
  }
  echo json_encode($payload);
}
