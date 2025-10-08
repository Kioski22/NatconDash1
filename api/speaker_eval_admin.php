<?php
// Admin CRUD + reorder + activate/deactivate + create new versioned sets
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../db.php';
if (isset($conn) && $conn instanceof mysqli) $db=$conn; elseif (isset($mysqli) && $mysqli instanceof mysqli) $db=$mysqli; else { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB unavailable']); exit; }
if (empty($_SESSION['user_role']) || $_SESSION['user_role']!=='admin') { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }
$method = $_SERVER['REQUEST_METHOD'];
$inputRaw = file_get_contents('php://input');
$payload = json_decode($inputRaw,true);
if($inputRaw!=='' && !is_array($payload)) $payload=[];

function jerr($m,$code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$m]); exit; }

// Ensure minimal tables (idempotent include of versioning already in questions API)
$db->query("CREATE TABLE IF NOT EXISTS speaker_eval_question_sets (
 id INT AUTO_INCREMENT PRIMARY KEY,
 set_name VARCHAR(150) NOT NULL,
 version_label VARCHAR(50) NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->query("CREATE TABLE IF NOT EXISTS speaker_eval_questions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->query("CREATE TABLE IF NOT EXISTS speaker_eval_options (
 id INT AUTO_INCREMENT PRIMARY KEY,
 question_id INT NOT NULL,
 option_text VARCHAR(255) NOT NULL,
 KEY qid_idx (question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$action = $_GET['action'] ?? ($payload['action'] ?? 'list');

if ($action==='list_sets') {
  $rows=[]; if($r=$db->query('SELECT id,set_name,version_label,is_active,created_at FROM speaker_eval_question_sets ORDER BY id DESC')){ while($o=$r->fetch_assoc()){ $o['id']=(int)$o['id']; $o['is_active']=(int)$o['is_active']; $rows[]=$o; } }
  echo json_encode(['ok'=>true,'sets'=>$rows]); exit;
}
if ($action==='create_set') {
  $name=trim($payload['set_name']??''); if($name==='') jerr('set_name required');
  $ver=trim($payload['version_label']??'');
  $st=$db->prepare('INSERT INTO speaker_eval_question_sets (set_name,version_label,is_active) VALUES (?,?,0)');
  if(!$st) jerr('DB error: '.$db->error,500);
  $st->bind_param('ss',$name,$ver); $st->execute(); $id=$st->insert_id; $st->close();
  echo json_encode(['ok'=>true,'set_id'=>$id]); exit;
}
if ($action==='activate_set') {
  $id=(int)($payload['set_id']??0); if($id<=0) jerr('Invalid set id');
  $db->query('UPDATE speaker_eval_question_sets SET is_active=0');
  $db->query('UPDATE speaker_eval_question_sets SET is_active=1 WHERE id='.(int)$id.' LIMIT 1');
  echo json_encode(['ok'=>true]); exit;
}
if ($action==='clone_set') {
  $id=(int)($payload['set_id']??0); if($id<=0) jerr('Invalid set id');
  $newName=trim($payload['set_name']??('Cloned Set '.$id)); $ver=trim($payload['version_label']??'v'.time());
  $db->begin_transaction();
  try {
    $db->query("INSERT INTO speaker_eval_question_sets (set_name,version_label,is_active) SELECT '".$db->real_escape_string($newName)."','".$db->real_escape_string($ver)."',0");
    $newId=(int)$db->insert_id;
    $db->query("INSERT INTO speaker_eval_questions (question_set_id,question_text,question_type,is_required,is_active,sort_order,min_scale,max_scale,scale_label_left,scale_label_right)
      SELECT $newId,question_text,question_type,is_required,is_active,sort_order,min_scale,max_scale,scale_label_left,scale_label_right FROM speaker_eval_questions WHERE question_set_id=".(int)$id);
    // options
    if($r=$db->query('SELECT id FROM speaker_eval_questions WHERE question_set_id='.(int)$newId)){
      $mapOldToNew=[]; $oldQs=[]; $i=0; // Build mapping by position / question_text
    }
    // simpler: re-clone options by question_text matching
    $orig=$db->query('SELECT question_text,id FROM speaker_eval_questions WHERE question_set_id='.(int)$id);
    $new=$db->query('SELECT question_text,id FROM speaker_eval_questions WHERE question_set_id='.(int)$newId);
    $oMap=[]; $nMap=[]; while($row=$orig->fetch_assoc()) $oMap[$row['question_text']]=$row['id']; while($row=$new->fetch_assoc()) $nMap[$row['question_text']]=$row['id'];
    foreach($oMap as $qt=>$oldQid){ if(!isset($nMap[$qt])) continue; $newQid=(int)$nMap[$qt]; $db->query('INSERT INTO speaker_eval_options (question_id,option_text) SELECT '.$newQid.',option_text FROM speaker_eval_options WHERE question_id='.(int)$oldQid); }
    $db->commit();
    echo json_encode(['ok'=>true,'new_set_id'=>$newId]);
  } catch(Throwable $e){ $db->rollback(); jerr('Clone failed: '.$e->getMessage(),500); }
  exit;
}
if ($action==='list_questions') {
  $setId=(int)($_GET['set_id']??0); if($setId<=0) jerr('set_id required');
  $rows=[]; if($r=$db->query('SELECT * FROM speaker_eval_questions WHERE question_set_id='.(int)$setId.' ORDER BY sort_order ASC,id ASC')){ while($o=$r->fetch_assoc()){ $o['id']=(int)$o['id']; $o['is_required']=(int)$o['is_required']; $o['is_active']=(int)$o['is_active']; $rows[]=$o; } }
  // attach options
  if($rows){ $ids=implode(',',array_column($rows,'id')); if($o=$db->query('SELECT id,question_id,option_text FROM speaker_eval_options WHERE question_id IN ('.$ids.') ORDER BY id ASC')){ $map=[]; while($p=$o->fetch_assoc()){ $map[(int)$p['question_id']][]=['id'=>(int)$p['id'],'option_text'=>$p['option_text']]; } foreach($rows as &$q){ if(isset($map[$q['id']])) $q['options']=$map[$q['id']]; } } }
  echo json_encode(['ok'=>true,'questions'=>$rows]); exit;
}
if ($action==='create_question') {
  $setId=(int)($payload['question_set_id']??0); if($setId<=0) jerr('question_set_id required');
  $qt=trim($payload['question_text']??''); if($qt==='') jerr('question_text required');
  $type=trim($payload['question_type']??'scale');
  $req=!empty($payload['is_required'])?1:0; $act=!empty($payload['is_active'])?1:0;
  $order=(int)($payload['sort_order']??10);
  $min=$payload['min_scale']!==null?(int)$payload['min_scale']:null; $max=$payload['max_scale']!==null?(int)$payload['max_scale']:null;
  $l=trim($payload['scale_label_left']??''); $r=trim($payload['scale_label_right']??'');
  $st=$db->prepare('INSERT INTO speaker_eval_questions (question_set_id,question_text,question_type,is_required,is_active,sort_order,min_scale,max_scale,scale_label_left,scale_label_right) VALUES (?,?,?,?,?,?,?,?,?,?)');
  if(!$st) jerr('DB error: '.$db->error,500);
  $st->bind_param('issiiissss',$setId,$qt,$type,$req,$act,$order,$min,$max,$l,$r); $st->execute(); $qid=$st->insert_id; $st->close();
  // options
  if(in_array($type,['multiple_choice_single','multiple_choice_multi','dropdown'])){
    $opts=$payload['options']??[]; if(is_array($opts)){ $ins=$db->prepare('INSERT INTO speaker_eval_options (question_id,option_text) VALUES (?,?)'); if($ins){ foreach($opts as $op){ $val=trim((string)$op); if($val==='') continue; $ins->bind_param('is',$qid,$val); $ins->execute(); } $ins->close(); } }
  }
  echo json_encode(['ok'=>true,'question_id'=>$qid]); exit;
}
if ($action==='update_question') {
  $qid=(int)($payload['id']??0); if($qid<=0) jerr('id required');
  $qt=trim($payload['question_text']??''); if($qt==='') jerr('question_text required');
  $type=trim($payload['question_type']??'scale'); $req=!empty($payload['is_required'])?1:0; $act=!empty($payload['is_active'])?1:0; $order=(int)($payload['sort_order']??10);
  $min=$payload['min_scale']!==null?(int)$payload['min_scale']:null; $max=$payload['max_scale']!==null?(int)$payload['max_scale']:null; $l=trim($payload['scale_label_left']??''); $r=trim($payload['scale_label_right']??'');
  $st=$db->prepare('UPDATE speaker_eval_questions SET question_text=?,question_type=?,is_required=?,is_active=?,sort_order=?,min_scale=?,max_scale=?,scale_label_left=?,scale_label_right=? WHERE id=? LIMIT 1');
  if(!$st) jerr('DB error: '.$db->error,500);
  $st->bind_param('ssiiiisssi',$qt,$type,$req,$act,$order,$min,$max,$l,$r,$qid); $ok=$st->execute(); $st->close();
  // replace options if choice type
  if(in_array($type,['multiple_choice_single','multiple_choice_multi','dropdown'])){
    $db->query('DELETE FROM speaker_eval_options WHERE question_id='.(int)$qid);
    $opts=$payload['options']??[]; if(is_array($opts)){ $ins=$db->prepare('INSERT INTO speaker_eval_options (question_id,option_text) VALUES (?,?)'); if($ins){ foreach($opts as $op){ $val=trim((string)$op); if($val==='') continue; $ins->bind_param('is',$qid,$val); $ins->execute(); } $ins->close(); } }
  } else { $db->query('DELETE FROM speaker_eval_options WHERE question_id='.(int)$qid); }
  echo json_encode(['ok'=>true]); exit;
}
if ($action==='reorder') {
  $items=$payload['order']??[]; if(!is_array($items)) jerr('order array required');
  $st=$db->prepare('UPDATE speaker_eval_questions SET sort_order=? WHERE id=?'); if(!$st) jerr('DB error',500);
  foreach($items as $row){ $id=(int)($row['id']??0); $ord=(int)($row['sort_order']??0); if($id>0){ $st->bind_param('ii',$ord,$id); $st->execute(); } }
  $st->close(); echo json_encode(['ok'=>true]); exit;
}
if ($action==='delete_question') {
  $qid=(int)($payload['id']??0); if($qid<=0) jerr('id required');
  // Soft delete: set is_active=0 to preserve historical responses
  $db->query('UPDATE speaker_eval_questions SET is_active=0 WHERE id='.(int)$qid.' LIMIT 1');
  echo json_encode(['ok'=>true]); exit;
}
if ($action==='export_csv') {
  // speaker averages per question for a given set
  $setId=(int)($_GET['set_id']??0); if($setId<=0) jerr('set_id required');
  header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="speaker_eval_set_'.$setId.'_export.csv"');
  $out=fopen('php://output','w'); fputcsv($out,['Speaker ID','Question ID','Question','Responses','Avg Numeric','Std Dev']);
  $sql = "SELECT q.id qid,q.question_text,ri.question_id,ri.numeric_value,r.speaker_id FROM speaker_eval_response_items ri
          JOIN speaker_eval_responses r ON r.id=ri.response_id
          JOIN speaker_eval_questions q ON q.id=ri.question_id
          WHERE q.question_set_id=".(int)$setId." AND q.question_type='scale'"; // only numeric for avg
  $stats=[]; if($res=$db->query($sql)){ while($row=$res->fetch_assoc()){ $sp=(int)$row['speaker_id']; $qid=(int)$row['qid']; $key=$sp.'-'.$qid; if(!isset($stats[$key])) $stats[$key]=['sp'=>$sp,'qid'=>$qid,'q'=>$row['question_text'],'vals'=>[]]; if($row['numeric_value']!==null) $stats[$key]['vals'][]=(int)$row['numeric_value']; } }
  foreach($stats as $stt){ $vals=$stt['vals']; $n=count($vals); if($n===0) continue; $avg=array_sum($vals)/$n; $var=0; foreach($vals as $v){ $var+=($v-$avg)**2; } $std=$n>1 ? sqrt($var/($n-1)) : 0; fputcsv($out,[$stt['sp'],$stt['qid'],$stt['q'],$n,number_format($avg,2),number_format($std,2)]); }
  fclose($out); exit;
}
if ($action==='analytics_summary') {
  $setId=(int)($_GET['set_id']??0); if($setId<=0) jerr('set_id required');
  // Average numeric answers per question overall
  $sql="SELECT q.id,q.question_text,COUNT(ri.id) responses,AVG(ri.numeric_value) avg_value FROM speaker_eval_questions q
        LEFT JOIN speaker_eval_response_items ri ON ri.question_id=q.id
        WHERE q.question_set_id=".(int)$setId." AND q.question_type='scale' GROUP BY q.id ORDER BY q.sort_order";
  $rows=[]; if($res=$db->query($sql)){ while($r=$res->fetch_assoc()){ $rows[]=['question_id'=>(int)$r['id'],'question'=>$r['question_text'],'responses'=>(int)$r['responses'],'avg_value'=>$r['avg_value']!==null?round((float)$r['avg_value'],2):null]; } }
  echo json_encode(['ok'=>true,'questions'=>$rows]); exit;
}
// fallback default: list sets
$rows=[]; if($r=$db->query('SELECT id,set_name,version_label,is_active FROM speaker_eval_question_sets ORDER BY id DESC')){ while($o=$r->fetch_assoc()){ $o['id']=(int)$o['id']; $o['is_active']=(int)$o['is_active']; $rows[]=$o; } }
echo json_encode(['ok'=>true,'sets'=>$rows]);
