<?php
// Returns generic per-speaker evaluation question set (active or all) with auto-migration & seeding
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../db.php';

if (isset($conn) && $conn instanceof mysqli) $db = $conn; elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli; else { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB unavailable']); exit; }

$all = isset($_GET['all']) && $_GET['all']=='1';
$requestedSetId = isset($_GET['set_id']) ? (int)$_GET['set_id'] : 0; // optional explicit set

function table_exists(mysqli $db, string $t): bool { $res = $db->query("SHOW TABLES LIKE '".$db->real_escape_string($t)."'"); return ($res && $res->num_rows>0); }

$created = [];$errors=[];
// NEW: question sets table for versioning
if (!table_exists($db,'speaker_eval_question_sets')) {
  $sql = "CREATE TABLE speaker_eval_question_sets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    set_name VARCHAR(150) NOT NULL,
    version_label VARCHAR(50) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  if ($db->query($sql)) $created[]='speaker_eval_question_sets'; else $errors[]=$db->error;
}
if (!table_exists($db,'speaker_eval_questions')) {
  $sql = "CREATE TABLE speaker_eval_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_set_id INT NOT NULL DEFAULT 1,
    question_text TEXT NOT NULL,
    question_type VARCHAR(64) NOT NULL DEFAULT 'scale',
    is_required TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 10,
    min_scale INT NULL,
    max_scale INT NULL,
    scale_label_left VARCHAR(100) NULL,
    scale_label_right VARCHAR(100) NULL,
    KEY idx_qset (question_set_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  if ($db->query($sql)) $created[]='speaker_eval_questions'; else $errors[]=$db->error;
} else {
  // Ensure column question_set_id exists (migration for legacy installs)
  $colRes = $db->query("SHOW COLUMNS FROM speaker_eval_questions LIKE 'question_set_id'");
  if ($colRes && $colRes->num_rows===0) {
    $db->query("ALTER TABLE speaker_eval_questions ADD COLUMN question_set_id INT NOT NULL DEFAULT 1 AFTER id, ADD KEY idx_qset (question_set_id)");
  }
}
if (!table_exists($db,'speaker_eval_options')) {
  $sql = "CREATE TABLE speaker_eval_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_id INT NOT NULL,
    option_text VARCHAR(255) NOT NULL,
    KEY qid_idx (question_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  if ($db->query($sql)) $created[]='speaker_eval_options'; else $errors[]=$db->error;
}
if (!table_exists($db,'speaker_eval_responses')) {
  $sql = "CREATE TABLE speaker_eval_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    speaker_id INT NOT NULL,
    room_id INT NULL,
    day_id INT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_speaker (user_id,speaker_id),
    KEY spk_idx (speaker_id),
    KEY user_idx (user_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  if ($db->query($sql)) $created[]='speaker_eval_responses'; else $errors[]=$db->error;
}
if (!table_exists($db,'speaker_eval_response_items')) {
  $sql = "CREATE TABLE speaker_eval_response_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    response_id INT NOT NULL,
    question_id INT NOT NULL,
    numeric_value INT NULL,
    option_id INT NULL,
    text_answer TEXT NULL,
    checkbox_values TEXT NULL,
    KEY resp_idx (response_id),
    KEY q_idx (question_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
  if ($db->query($sql)) $created[]='speaker_eval_response_items'; else $errors[]=$db->error;
}

$seeded=false;
// Seed default set if none present
$setCount = 0;
if ($res = $db->query('SELECT COUNT(*) FROM speaker_eval_question_sets')) { $setCount = (int)$res->fetch_row()[0]; $res->close(); }
if ($setCount===0) {
  if ($db->query("INSERT INTO speaker_eval_question_sets (set_name,version_label,is_active) VALUES ('Default Question Set','v1',1)")) {
    $seeded = true;
  }
}
$activeSetId = 0;
if ($requestedSetId>0) {
  $chk = $db->prepare('SELECT id FROM speaker_eval_question_sets WHERE id=? LIMIT 1');
  if ($chk){ $chk->bind_param('i',$requestedSetId); $chk->execute(); $chk->bind_result($sid); if($chk->fetch()) $activeSetId=(int)$sid; $chk->close(); }
}
if ($activeSetId===0) {
  if ($res = $db->query('SELECT id FROM speaker_eval_question_sets WHERE is_active=1 ORDER BY id DESC LIMIT 1')) { if($row=$res->fetch_row()) $activeSetId=(int)$row[0]; $res->close(); }
  if ($activeSetId===0) { // fallback create
    $db->query("INSERT INTO speaker_eval_question_sets (set_name,version_label,is_active) VALUES ('Auto Created Set','v1',1)");
    $activeSetId = (int)$db->insert_id;
  }
}
// Seed default questions if this set has none
$qCount=0; if ($res=$db->query('SELECT COUNT(*) FROM speaker_eval_questions WHERE question_set_id='.(int)$activeSetId)){ $qCount=(int)$res->fetch_row()[0]; $res->close(); }
if ($qCount===0) {
  $defaults = [
    ['Content Quality','scale',1,1,10,1,5,'Poor','Excellent'],
    ['Speaker Clarity','scale',1,1,20,1,5,'Poor','Excellent'],
    ['Engagement','scale',1,1,30,1,5,'Low','High'],
    ['Overall Rating','scale',1,1,40,1,5,'Poor','Excellent'],
    ['Comments','textarea',0,1,50,null,null,null,null]
  ];
  $st = $db->prepare('INSERT INTO speaker_eval_questions (question_set_id,question_text,question_type,is_required,is_active,sort_order,min_scale,max_scale,scale_label_left,scale_label_right) VALUES (?,?,?,?,?,?,?,?,?,?)');
  if ($st) {
    foreach ($defaults as $d) { [$qt,$type,$req,$act,$order,$min,$max,$l,$r] = $d; $st->bind_param('issiiissss',$activeSetId,$qt,$type,$req,$act,$order,$min,$max,$l,$r); $st->execute(); }
    $st->close(); $seeded=true;
  }
}

$sql = "SELECT id,question_set_id,question_text,question_type,is_required,is_active,sort_order,min_scale,max_scale,scale_label_left,scale_label_right FROM speaker_eval_questions";
$where=[];
if(!$all) $where[]='is_active=1';
if($activeSetId>0) $where[]='question_set_id='.(int)$activeSetId;
if($where) $sql.=' WHERE '.implode(' AND ',$where);
$sql .= ' ORDER BY sort_order ASC, id ASC';
$questions=[];
if ($r=$db->query($sql)) {
  while($row=$r->fetch_assoc()) {
  $row['id']=(int)$row['id'];
  $row['question_set_id']=(int)$row['question_set_id'];
    $row['is_required']=(int)$row['is_required'];
    $row['is_active']=(int)$row['is_active'];
    $row['sort_order']=(int)$row['sort_order'];
    $row['min_scale']=$row['min_scale']!==null?(int)$row['min_scale']:null;
    $row['max_scale']=$row['max_scale']!==null?(int)$row['max_scale']:null;
    $questions[]=$row;
  }
}

$withOpts = array_filter($questions, fn($q)=> in_array($q['question_type'],['multiple_choice_single','multiple_choice_multi','dropdown'],true));
if ($withOpts) {
  $ids = implode(',', array_map('intval', array_column($withOpts,'id')));
  if ($ro=$db->query("SELECT id,question_id,option_text FROM speaker_eval_options WHERE question_id IN ($ids) ORDER BY id ASC")) {
    $map=[]; while($o=$ro->fetch_assoc()) { $qid=(int)$o['question_id']; if(!isset($map[$qid])) $map[$qid]=[]; $map[$qid][]= ['id'=>(int)$o['id'],'option_text'=>$o['option_text']]; }
    foreach ($questions as &$q) if(isset($map[$q['id']])) $q['options']=$map[$q['id']];
  }
}

// include current set meta
$setMeta=null; if($activeSetId){ if($r=$db->query('SELECT id,set_name,version_label,is_active FROM speaker_eval_question_sets WHERE id='.(int)$activeSetId.' LIMIT 1')){ $setMeta=$r->fetch_assoc(); if($setMeta){ $setMeta['id']=(int)$setMeta['id']; $setMeta['is_active']=(int)$setMeta['is_active']; } } }
echo json_encode(['ok'=>true,'questions'=>$questions,'active_set_id'=>$activeSetId,'set'=>$setMeta,'created'=>$created,'seeded'=>$seeded]);
