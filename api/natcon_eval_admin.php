<?php
// Admin CRUD API for NatCon evaluation questions & options
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
  http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admin only']); exit;
}
if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
  http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Bad CSRF']); exit;
}

// Normalize DB
if (isset($conn) && $conn instanceof mysqli) $db = $conn; elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli; else { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB unavailable']); exit; }

$action = $_POST['action'] ?? '';

function respond($arr){ echo json_encode($arr); exit; }
function clean($s){ return trim((string)$s); }

if ($action === 'create_question') {
  $text = clean($_POST['question_text'] ?? '');
  $type = clean($_POST['question_type'] ?? '');
  $is_required = (int)($_POST['is_required'] ?? 0);
  $is_active = (int)($_POST['is_active'] ?? 1);
  $min_scale = (int)($_POST['min_scale'] ?? 1);
  $max_scale = (int)($_POST['max_scale'] ?? 5);
  $left = clean($_POST['scale_label_left'] ?? '');
  $right = clean($_POST['scale_label_right'] ?? '');

  if ($text === '' || $type === '') respond(['ok'=>false,'error'=>'Missing fields']);
  $validTypes = ['scale','text','textarea','multiple_choice_single','multiple_choice_multi','dropdown','checkbox'];
  if (!in_array($type, $validTypes,true)) respond(['ok'=>false,'error'=>'Invalid type']);
  if ($type==='scale') {
    if ($min_scale < 1 || $max_scale <= $min_scale) respond(['ok'=>false,'error'=>'Invalid scale range']);
  } else { $min_scale = null; $max_scale = null; $left = null; $right = null; }

  // sort_order spacing 10
  $sortOrder = 10;
  if ($r = $db->query("SELECT MAX(sort_order) m FROM natcon_eval_questions")) { if ($row=$r->fetch_assoc()) $sortOrder = (int)$row['m'] + 10; }

  $st = $db->prepare("INSERT INTO natcon_eval_questions (question_text, question_type, is_required, is_active, sort_order, min_scale, max_scale, scale_label_left, scale_label_right) VALUES (?,?,?,?,?,?,?,?,?)");
  if (!$st) respond(['ok'=>false,'error'=>'Prepare failed']);
  $st->bind_param('ssiiissss', $text,$type,$is_required,$is_active,$sortOrder,$min_scale,$max_scale,$left,$right);
  if ($st->execute()) respond(['ok'=>true,'id'=>$st->insert_id]);
  respond(['ok'=>false,'error'=>'Insert failed']);
}
elseif ($action === 'update_question') {
  $id = (int)($_POST['id'] ?? 0);
  $text = clean($_POST['question_text'] ?? '');
  $type = clean($_POST['question_type'] ?? '');
  $is_required = (int)($_POST['is_required'] ?? 0);
  $is_active = (int)($_POST['is_active'] ?? 1);
  $min_scale = (int)($_POST['min_scale'] ?? 1);
  $max_scale = (int)($_POST['max_scale'] ?? 5);
  $left = clean($_POST['scale_label_left'] ?? '');
  $right = clean($_POST['scale_label_right'] ?? '');
  if ($id<=0) respond(['ok'=>false,'error'=>'Bad id']);
  $validTypes = ['scale','text','textarea','multiple_choice_single','multiple_choice_multi','dropdown','checkbox'];
  if (!in_array($type,$validTypes,true)) respond(['ok'=>false,'error'=>'Invalid type']);
  if ($text==='') respond(['ok'=>false,'error'=>'Text required']);
  if ($type==='scale') {
    if ($min_scale < 1 || $max_scale <= $min_scale) respond(['ok'=>false,'error'=>'Invalid scale']);
  } else { $min_scale=null;$max_scale=null;$left=null;$right=null; }

  $st = $db->prepare("UPDATE natcon_eval_questions SET question_text=?, question_type=?, is_required=?, is_active=?, min_scale=?, max_scale=?, scale_label_left=?, scale_label_right=? WHERE id=? LIMIT 1");
  if (!$st) respond(['ok'=>false,'error'=>'Prepare failed']);
  $st->bind_param('ssiiisssi',$text,$type,$is_required,$is_active,$min_scale,$max_scale,$left,$right,$id);
  if ($st->execute()) respond(['ok'=>true]);
  respond(['ok'=>false,'error'=>'Update failed']);
}
elseif ($action === 'delete_question') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id<=0) respond(['ok'=>false,'error'=>'Bad id']);
  // TODO: optionally check if responses exist and block
  $st = $db->prepare("DELETE FROM natcon_eval_questions WHERE id=? LIMIT 1");
  if (!$st) respond(['ok'=>false,'error'=>'Prepare failed']);
  $st->bind_param('i',$id);
  if ($st->execute()) {
    $db->query("DELETE FROM natcon_eval_options WHERE question_id=".$id);
    respond(['ok'=>true]);
  }
  respond(['ok'=>false,'error'=>'Delete failed']);
}
elseif ($action === 'toggle_active') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id<=0) respond(['ok'=>false,'error'=>'Bad id']);
  $db->query("UPDATE natcon_eval_questions SET is_active = CASE WHEN is_active=1 THEN 0 ELSE 1 END WHERE id=".$id." LIMIT 1");
  respond(['ok'=>true]);
}
elseif ($action === 'reorder') {
  $order = $_POST['order'] ?? '';
  if ($order==='') respond(['ok'=>false,'error'=>'Missing order']);
  $ids = array_filter(array_map('intval', explode(',', $order)));
  if (!$ids) respond(['ok'=>false,'error'=>'No ids']);
  $sort = 10; $inc=10;
  $st = $db->prepare("UPDATE natcon_eval_questions SET sort_order=? WHERE id=? LIMIT 1");
  if (!$st) respond(['ok'=>false,'error'=>'Prepare failed']);
  foreach ($ids as $qid) { $st->bind_param('ii',$sort,$qid); $st->execute(); $sort += $inc; }
  respond(['ok'=>true]);
}
elseif ($action === 'add_option') {
  $question_id = (int)($_POST['question_id'] ?? 0);
  $text = clean($_POST['option_text'] ?? '');
  if ($question_id<=0 || $text==='') respond(['ok'=>false,'error'=>'Missing fields']);
  // ensure question type supports options
  $qt = null; $rq = $db->prepare("SELECT question_type FROM natcon_eval_questions WHERE id=? LIMIT 1");
  if ($rq){ $rq->bind_param('i',$question_id); $rq->execute(); $rq->bind_result($qt); if(!$rq->fetch()) respond(['ok'=>false,'error'=>'Question not found']); $rq->close(); }
  if (!in_array($qt,['multiple_choice_single','multiple_choice_multi','dropdown'],true)) respond(['ok'=>false,'error'=>'Type has no options']);
  $st = $db->prepare("INSERT INTO natcon_eval_options (question_id, option_text) VALUES (?,?)");
  if (!$st) respond(['ok'=>false,'error'=>'Prepare failed']);
  $st->bind_param('is',$question_id,$text);
  if ($st->execute()) respond(['ok'=>true,'id'=>$st->insert_id]);
  respond(['ok'=>false,'error'=>'Insert failed']);
}
elseif ($action === 'delete_option') {
  $option_id = (int)($_POST['option_id'] ?? 0);
  if ($option_id<=0) respond(['ok'=>false,'error'=>'Bad id']);
  $st = $db->prepare("DELETE FROM natcon_eval_options WHERE id=? LIMIT 1");
  if (!$st) respond(['ok'=>false,'error'=>'Prepare failed']);
  $st->bind_param('i',$option_id);
  if ($st->execute()) respond(['ok'=>true]);
  respond(['ok'=>false,'error'=>'Delete failed']);
}
else {
  respond(['ok'=>false,'error'=>'Unknown action']);
}
