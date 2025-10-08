<?php
// Submit per-speaker dynamic evaluation answers
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../db.php';

if (isset($conn) && $conn instanceof mysqli) $db=$conn; elseif (isset($mysqli) && $mysqli instanceof mysqli) $db=$mysqli; else { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB unavailable']); exit; }

$user_id = isset($_SESSION['user_id'])?(int)$_SESSION['user_id']:0;
if ($user_id<=0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Login required']); exit; }

$raw = file_get_contents('php://input');
$data = json_decode($raw,true);
if(!is_array($data)){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid JSON']); exit; }

$speaker_id = isset($data['speaker_id'])?(int)$data['speaker_id']:0;
if ($speaker_id<=0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid speaker']); exit; }

$room_id=null; $day_id=null;
if ($st=$db->prepare('SELECT s.room_id, fr.day_id FROM speakers s LEFT JOIN function_rooms fr ON fr.id=s.room_id WHERE s.id=? LIMIT 1')) {
  $st->bind_param('i',$speaker_id); $st->execute(); $st->bind_result($r,$d); if($st->fetch()){ $room_id=(int)$r; $day_id=(int)$d; } else { $st->close(); http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Speaker not found']); exit; } $st->close();
} else { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB prepare failed']); exit; }

$quizId=0; if($q=$db->prepare('SELECT id FROM quizzes WHERE speaker_id=? LIMIT 1')) { $q->bind_param('i',$speaker_id); $q->execute(); $q->bind_result($quizId); $hasQuiz=$q->fetch(); $q->close(); if($hasQuiz && $quizId>0){ if($chk=$db->prepare('SELECT 1 FROM quiz_responses WHERE quiz_id=? AND user_id=? LIMIT 1')){ $chk->bind_param('ii',$quizId,$user_id); $chk->execute(); $chk->bind_result($one); if(!$chk->fetch()){ $chk->close(); http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Complete the quiz before evaluation']); exit; } $chk->close(); } } }

if ($st=$db->prepare('SELECT 1 FROM speaker_eval_responses WHERE user_id=? AND speaker_id=? LIMIT 1')) { $st->bind_param('ii',$user_id,$speaker_id); $st->execute(); $st->bind_result($o); if($st->fetch()){ $st->close(); http_response_code(409); echo json_encode(['ok'=>false,'error'=>'Already evaluated']); exit; } $st->close(); }
if ($st=$db->prepare('SELECT 1 FROM speaker_evaluations WHERE user_id=? AND speaker_id=? LIMIT 1')) { $st->bind_param('ii',$user_id,$speaker_id); $st->execute(); $st->bind_result($o); if($st->fetch()){ $st->close(); http_response_code(409); echo json_encode(['ok'=>false,'error'=>'Already evaluated (legacy)']); exit; } $st->close(); }

$answers = $data['answers'] ?? null; if(!is_array($answers) || !$answers){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'No answers']); exit; }

$qs=[]; if($res=$db->query('SELECT id,question_type,is_required,min_scale,max_scale FROM speaker_eval_questions WHERE is_active=1')){ while($row=$res->fetch_assoc()){ $qs[(int)$row['id']]=[
  'type'=>$row['question_type'],
  'req'=>(int)$row['is_required']===1,
  'min'=>$row['min_scale']!==null?(int)$row['min_scale']:null,
  'max'=>$row['max_scale']!==null?(int)$row['max_scale']:null
]; } }
if(!$qs){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'No active questions']); exit; }

$optMap=[]; if($o=$db->query('SELECT id,question_id FROM speaker_eval_options')){ while($r=$o->fetch_assoc()){ $qid=(int)$r['question_id']; $oid=(int)$r['id']; if(!isset($optMap[$qid])) $optMap[$qid]=[]; $optMap[$qid][]=$oid; } }

$clean=[]; $provided=[];
foreach($answers as $a){ if(!isset($a['question_id'])) continue; $qid=(int)$a['question_id']; if(!isset($qs[$qid])) continue; $type=$qs[$qid]['type']; $val=$a['value']??null; $provided[$qid]=true; $entry=['question_id'=>$qid];
  if($type==='scale'){ $n=(int)$val; if($n < $qs[$qid]['min'] || $n > $qs[$qid]['max']){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Scale out of range']); exit; } $entry['numeric_value']=$n; }
  elseif(in_array($type,['text','textarea'])){ $s=trim((string)$val); if($s===''){ if($qs[$qid]['req']){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Text required']); exit; } continue; } $entry['text_answer']=mb_substr($s,0,2000); }
  elseif(in_array($type,['multiple_choice_single','dropdown'])){ $oid=(int)$val; if(!in_array($oid,$optMap[$qid]??[],true)){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid option']); exit; } $entry['option_id']=$oid; }
  elseif($type==='multiple_choice_multi'){ if(!is_array($val)){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Expected array']); exit; } $sel=array_values(array_unique(array_map('intval',$val))); if(!$sel && $qs[$qid]['req']){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'At least one option required']); exit; } foreach($sel as $oid) if(!in_array($oid,$optMap[$qid]??[],true)){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid option']); exit; } if($sel) $entry['checkbox_values']=json_encode($sel,JSON_UNESCAPED_UNICODE); else continue; }
  elseif($type==='checkbox'){ $entry['numeric_value'] = ($val?1:0); }
  $clean[]=$entry; }

foreach($qs as $qid=>$meta){ if($meta['req'] && !isset($provided[$qid])){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Required question missing']); exit; } }
if(!$clean){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'No valid answers']); exit; }

$db->begin_transaction();
try {
  $ins = $db->prepare('INSERT INTO speaker_eval_responses (user_id,speaker_id,room_id,day_id) VALUES (?,?,?,?)');
  if(!$ins) throw new Exception('Prepare response failed: '.$db->error);
  $ins->bind_param('iiii',$user_id,$speaker_id,$room_id,$day_id);
  if(!$ins->execute()) throw new Exception('Insert response failed: '.$db->error);
  $rid=$ins->insert_id; $ins->close();
  $item = $db->prepare('INSERT INTO speaker_eval_response_items (response_id,question_id,numeric_value,option_id,text_answer,checkbox_values) VALUES (?,?,?,?,?,?)');
  if(!$item) throw new Exception('Prepare item failed: '.$db->error);
  foreach($clean as $c){ $qid=$c['question_id']; $num=$c['numeric_value']??null; $opt=$c['option_id']??null; $txt=$c['text_answer']??null; $chk=$c['checkbox_values']??null; $item->bind_param('iiisss',$rid,$qid,$num,$opt,$txt,$chk); if(!$item->execute()) throw new Exception('Insert item failed: '.$db->error); }
  $item->close();
  $db->commit();
  echo json_encode(['ok'=>true,'response_id'=>$rid]);
} catch(Throwable $e){ $db->rollback(); http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Save failed','debug'=>$e->getMessage()]); }
