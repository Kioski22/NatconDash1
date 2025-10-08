<?php
// Generate (or fetch existing) Main Program Day completion certificate for given day.
// Criteria: all main program speakers (non-sub) of the day evaluated, and all quizzes (if any) completed.
// GET  ?day_id=ID&mode=status -> progress + existing certificate if any
// POST JSON { day_id: ID }    -> generate if complete
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vendor/autoload.php';
use Dompdf\Dompdf;
if (isset($conn) && $conn instanceof mysqli) $db = $conn; elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli; else { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB unavailable']); exit; }
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0; if ($user_id<=0){ http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Login required']); exit; }

$method = $_SERVER['REQUEST_METHOD'];

// Auto-migrate table
@$db->query("CREATE TABLE IF NOT EXISTS main_program_day_certificates (\n  id INT AUTO_INCREMENT PRIMARY KEY,\n  user_id INT NOT NULL,\n  day_id INT NOT NULL,\n  file_path VARCHAR(255) NOT NULL,\n  generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n  UNIQUE KEY uniq_user_day (user_id, day_id),\n  KEY idx_day (day_id),\n  KEY idx_user (user_id)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function compute_day_progress(mysqli $db, int $day_id, int $user_id): array {
  $speakers=[]; if ($st=$db->prepare("SELECT s.id FROM speakers s JOIN function_rooms fr ON fr.id=s.room_id WHERE fr.day_id=? AND (s.program_type IS NULL OR LOWER(s.program_type) <> 'sub')")){ $st->bind_param('i',$day_id); $st->execute(); $r=$st->get_result(); while($row=$r->fetch_assoc()) $speakers[]=(int)$row['id']; $st->close(); }
  $total=count($speakers); if($total===0) return ['total'=>0,'complete'=>false,'eval_completed'=>0,'quiz_required'=>0,'quiz_completed'=>0,'details'=>[]];
  $hasQuizQuestions=false; if($res=$db->query("SHOW TABLES LIKE 'quiz_questions'")){ $hasQuizQuestions=$res->num_rows>0; $res->close(); }
  $evalCompleted=0; $quizRequired=0; $quizCompleted=0; $details=[];
  foreach($speakers as $sid){
    $evalDone=false; $quizDone=false; $requiresQuiz=false; $quizId=0;
    if($q=$db->prepare('SELECT id FROM quizzes WHERE speaker_id=? LIMIT 1')){ $q->bind_param('i',$sid); $q->execute(); $q->bind_result($quizId); if($q->fetch()) $requiresQuiz=true; $q->close(); }
    if(!$requiresQuiz && $hasQuizQuestions){ if($q2=$db->prepare('SELECT 1 FROM quiz_questions WHERE speaker_id=? LIMIT 1')){ $q2->bind_param('i',$sid); $q2->execute(); $q2->bind_result($d); if($q2->fetch()) $requiresQuiz=true; $q2->close(); } }
    if($requiresQuiz) $quizRequired++;
    if($requiresQuiz){ if($quizId>0){ if($qr=$db->prepare('SELECT 1 FROM quiz_responses WHERE quiz_id=? AND user_id=? LIMIT 1')){ $qr->bind_param('ii',$quizId,$user_id); $qr->execute(); $qr->bind_result($d); if($qr->fetch()) $quizDone=true; $qr->close(); }} if(!$quizDone){ if($qr2=$db->prepare('SELECT 1 FROM quiz_responses WHERE speaker_id=? AND user_id=? LIMIT 1')){ $qr2->bind_param('ii',$sid,$user_id); $qr2->execute(); $qr2->bind_result($d); if($qr2->fetch()) $quizDone=true; $qr2->close(); }} if($quizDone) $quizCompleted++; }
  if($ev=$db->prepare('SELECT 1 FROM speaker_evaluations WHERE speaker_id=? AND user_id=? LIMIT 1')){ $ev->bind_param('ii',$sid,$user_id); $ev->execute(); $ev->bind_result($d); if($ev->fetch()) $evalDone=true; $ev->close(); }
  if(!$evalDone && $evx=$db->prepare('SELECT 1 FROM speaker_eval_responses WHERE speaker_id=? AND user_id=? LIMIT 1')){ $evx->bind_param('ii',$sid,$user_id); $evx->execute(); $evx->bind_result($dd); if($evx->fetch()) $evalDone=true; $evx->close(); }
    if($evalDone) $evalCompleted++;
    $details[]=['speaker_id'=>$sid,'evaluation'=>$evalDone,'quiz_required'=>$requiresQuiz,'quiz_done'=>$quizDone];
  }
  $complete = ($evalCompleted===$total && $quizCompleted===$quizRequired);
  return [ 'total'=>$total, 'eval_completed'=>$evalCompleted, 'eval_total'=>$total, 'quiz_required'=>$quizRequired, 'quiz_completed'=>$quizCompleted, 'complete'=>$complete, 'details'=>$details ];
}

if($method==='GET'){
  $day_id = isset($_GET['day_id']) ? (int)$_GET['day_id'] : 0; if($day_id<=0){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid day']); exit; }
  $progress = compute_day_progress($db,$day_id,$user_id);
  $existing=null; if($st=$db->prepare('SELECT file_path, generated_at FROM main_program_day_certificates WHERE user_id=? AND day_id=? LIMIT 1')){ $st->bind_param('ii',$user_id,$day_id); $st->execute(); $st->bind_result($fp,$gen); if($st->fetch()) $existing=['file_path'=>$fp,'generated_at'=>$gen]; $st->close(); }
  echo json_encode(['ok'=>true,'progress'=>$progress,'complete'=>$progress['complete'],'certificate'=>$existing]); exit;
}

// POST generate
$raw=file_get_contents('php://input'); $data=json_decode($raw,true) ?: [];
$day_id = isset($data['day_id']) ? (int)$data['day_id'] : 0; if($day_id<=0){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid day']); exit; }
$progress = compute_day_progress($db,$day_id,$user_id);
if(!$progress['complete']){ http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Day incomplete','progress'=>$progress]); exit; }
// Reuse existing
if($st=$db->prepare('SELECT file_path FROM main_program_day_certificates WHERE user_id=? AND day_id=? LIMIT 1')){ $st->bind_param('ii',$user_id,$day_id); $st->execute(); $st->bind_result($fp); if($st->fetch()){ $st->close(); echo json_encode(['ok'=>true,'already'=>true,'file_path'=>$fp]); exit; } $st->close(); }
// Fetch user name
$user_name='Participant'; if($st=$db->prepare('SELECT name FROM users WHERE id=? LIMIT 1')){ $st->bind_param('i',$user_id); $st->execute(); $st->bind_result($user_name); $st->fetch(); $st->close(); }

// Attempt to load a main program certificate layout from certificates table using type 'main_program_day'
$tpl=null; if($st=$db->prepare("SELECT * FROM certificates WHERE LOWER(type)='main_program_day' ORDER BY updated_at DESC,id DESC LIMIT 1")){ $st->execute(); $r=$st->get_result(); $tpl=$r->fetch_assoc(); $st->close(); }
if(!$tpl){ http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Main program day certificate template not set']); exit; }

function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function file_to_data_uri($rel){ $abs = __DIR__.'/../'.$rel; if(!is_file($abs)) return ''; $data=@file_get_contents($abs); if($data===false) return ''; $mime = str_ends_with(strtolower($rel),'.png')?'image/png':'image/jpeg'; return 'data:'.$mime.';base64,'.base64_encode($data); }

$bg_rel = $tpl['bg_url']; $bg_uri = $bg_rel ? file_to_data_uri($bg_rel) : '';
$imgW=1600; $imgH=1131; if($bg_rel){ $absBg=__DIR__.'/../'.$bg_rel; if(is_file($absBg)){ $info=@getimagesize($absBg); if($info){ $imgW=(int)$info[0]; $imgH=(int)$info[1]; } } }
if($imgW>2400){ $scale=2400/$imgW; $imgW=(int)round($imgW*$scale); $imgH=(int)round($imgH*$scale); }
$paperWpt=$imgW*72/96; $paperHpt=$imgH*72/96;
$issuedAt = date('Y-m-d H:i');
$cert_number = 'MPD-'.date('Ymd').'-'.$user_id.'-'.$day_id; // simple unique number
$awardee = str_replace(['{USER_NAME}','{NAME}'],$user_name,$tpl['cert_awardee_name'] ?: $user_name);
$seminarTitle = $tpl['seminar_title'] ?: 'Main Program Day';
$seminarTitle = str_replace(['{DAY_ID}','{CERT_NUMBER}'], [$day_id,$cert_number], $seminarTitle);
$show_awardee = (int)$tpl['show_awardee']===1; $show_seminar=(int)$tpl['show_seminar']===1; $show_number=(int)$tpl['show_number']===1;

$html='<!DOCTYPE html><html><head><meta charset="utf-8"><style>@page{margin:0;}html,body{margin:0;padding:0;} .page{position:relative;width:'.$imgW.'px;height:'.$imgH.'px;} .bg{position:absolute;left:0;top:0;width:100%;height:100%;} .txt{position:absolute;white-space:nowrap;font-family:Helvetica,Arial,sans-serif;font-weight:500;}</style></head><body>'; $html.='<div class="page">'; if($bg_uri){ $html.='<img class="bg" src="'.esc($bg_uri).'" />'; }
$awardee_left=(float)$tpl['pos_cert_awardee_name_x']; $awardee_top=(float)$tpl['pos_cert_awardee_name_y']; $seminar_left=(float)$tpl['pos_seminar_title_x']; $seminar_top=(float)$tpl['pos_seminar_title_y']; $num_left=(float)$tpl['pos_cert_number_x']; $num_top=(float)$tpl['pos_cert_number_y'];
if($show_awardee){ $html.='<div class="txt" style="left:'.$awardee_left.'%;top:'.$awardee_top.'%;font-size:46px;">'.esc($awardee).'</div>'; }
if($show_seminar){ $html.='<div class="txt" style="left:'.$seminar_left.'%;top:'.$seminar_top.'%;font-size:24px;">'.esc($seminarTitle).'</div>'; }
if($show_number){ $html.='<div class="txt" style="left:'.$num_left.'%;top:'.$num_top.'%;font-size:16px;">'.esc($cert_number).'</div>'; }
$html.='<div style="position:absolute;right:16px;bottom:12px;font-size:10px;color:#333;">Issued '.$issuedAt.'</div>';
$html.='</div></body></html>';
$dompdf=new Dompdf(['defaultFont'=>'Helvetica']); $dompdf->setPaper([0,0,$paperWpt,$paperHpt]); $dompdf->loadHtml($html,'UTF-8'); $dompdf->render();
$outDir=__DIR__.'/../uploads/certificates/generated'; if(!is_dir($outDir)) @mkdir($outDir,0775,true); $fileName='main_day_'.$day_id.'_u'.$user_id.'_'.substr(sha1($cert_number.random_bytes(4)),0,8).'.pdf'; $abs=$outDir.'/'.$fileName; file_put_contents($abs,$dompdf->output()); $rel='uploads/certificates/generated/'.$fileName;
if($ins=$db->prepare('INSERT INTO main_program_day_certificates (user_id,day_id,file_path) VALUES (?,?,?)')){ $ins->bind_param('iis',$user_id,$day_id,$rel); if(!$ins->execute()){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Failed to record certificate']); exit; } $ins->close(); }
echo json_encode(['ok'=>true,'file_path'=>$rel,'already'=>false,'progress'=>$progress,'cert_number'=>$cert_number]);
