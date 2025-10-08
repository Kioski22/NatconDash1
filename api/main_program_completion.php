<?php
// Aggregate completion across ALL main program days (non-sub speakers).
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../db.php';
if (isset($conn) && $conn instanceof mysqli) $db = $conn; elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli; else { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB unavailable']); exit; }
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0; $user_email = trim((string)($_SESSION['user_email'] ?? $_SESSION['email'] ?? ''));
if ($user_id<=0 && $user_email===''){ http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Login required']); exit; }

// Collect speakers (exclude sub program) with day mapping + day dates
$speakers=[]; $byDay=[]; $speakerDayDate=[]; $today=date('Y-m-d');
$sql = "SELECT s.id, fr.day_id, d.day_date FROM speakers s JOIN function_rooms fr ON fr.id=s.room_id LEFT JOIN function_room_days d ON d.id=fr.day_id WHERE (s.program_type IS NULL OR LOWER(s.program_type)<> 'sub')";
if ($res = $db->query($sql)) {
  while($row=$res->fetch_assoc()){
    $sid=(int)$row['id']; $speakers[]=$sid; $day=(int)$row['day_id']; $byDay[$day]=$byDay[$day]??[]; $byDay[$day][]=$sid; $speakerDayDate[$sid]=$row['day_date'];
  }
  $res->close();
}
$total = count($speakers);
if ($total===0){ echo json_encode(['ok'=>true,'total'=>0,'eval_completed'=>0,'quiz_required'=>0,'quiz_completed'=>0,'complete'=>true,'complete_relaxed'=>true,'days'=>[]]); exit; }

$hasQuizQuestions=false; if($chk=$db->query("SHOW TABLES LIKE 'quiz_questions'")){ $hasQuizQuestions=$chk->num_rows>0; $chk->close(); }
$evalCompleted=0; $quizRequired=0; $quizCompleted=0; $details=[]; $incompleteFutureOnly=true; // assume we can relax until we find an incomplete whose day is today/past

foreach($speakers as $sid){
  $evalDone=false; $quizDone=false; $requiresQuiz=false; $quizId=0;
  if($q=$db->prepare('SELECT id FROM quizzes WHERE speaker_id=? LIMIT 1')){ $q->bind_param('i',$sid); $q->execute(); $q->bind_result($quizId); if($q->fetch()) $requiresQuiz=true; $q->close(); }
  if(!$requiresQuiz && $hasQuizQuestions){ if($q2=$db->prepare('SELECT 1 FROM quiz_questions WHERE speaker_id=? LIMIT 1')){ $q2->bind_param('i',$sid); $q2->execute(); $q2->bind_result($d); if($q2->fetch()) $requiresQuiz=true; $q2->close(); } }
  if($requiresQuiz) $quizRequired++;
  if($requiresQuiz){
    if($user_id>0 && $quizId>0){ if($qr=$db->prepare('SELECT 1 FROM quiz_responses WHERE quiz_id=? AND user_id=? LIMIT 1')){ $qr->bind_param('ii',$quizId,$user_id); $qr->execute(); $qr->bind_result($d); if($qr->fetch()) $quizDone=true; $qr->close(); }}
    if(!$quizDone && $user_id>0){ if($qr2=$db->prepare('SELECT 1 FROM quiz_responses WHERE speaker_id=? AND user_id=? LIMIT 1')){ $qr2->bind_param('ii',$sid,$user_id); $qr2->execute(); $qr2->bind_result($d); if($qr2->fetch()) $quizDone=true; $qr2->close(); }}
    if(!$quizDone && $user_email!==''){ if($qr3=$db->prepare('SELECT 1 FROM quiz_responses WHERE speaker_id=? AND (user_email=? OR email=?) LIMIT 1')){ $qr3->bind_param('iss',$sid,$user_email,$user_email); $qr3->execute(); $qr3->bind_result($d); if($qr3->fetch()) $quizDone=true; $qr3->close(); }} }
  if($quizDone) $quizCompleted++;
  if($user_id>0){ if($ev=$db->prepare('SELECT 1 FROM speaker_evaluations WHERE speaker_id=? AND user_id=? LIMIT 1')){ $ev->bind_param('ii',$sid,$user_id); $ev->execute(); $ev->bind_result($d); if($ev->fetch()) $evalDone=true; $ev->close(); } if(!$evalDone && $evx=$db->prepare('SELECT 1 FROM speaker_eval_responses WHERE speaker_id=? AND user_id=? LIMIT 1')){ $evx->bind_param('ii',$sid,$user_id); $evx->execute(); $evx->bind_result($dd); if($evx->fetch()) $evalDone=true; $evx->close(); }}
  if(!$evalDone && $user_email!==''){ if($ev2=$db->prepare('SELECT 1 FROM speaker_evaluations WHERE speaker_id=? AND (user_email=? OR email=?) LIMIT 1')){ $ev2->bind_param('iss',$sid,$user_email,$user_email); $ev2->execute(); $ev2->bind_result($d); if($ev2->fetch()) $evalDone=true; $ev2->close(); } }
  if($evalDone) $evalCompleted++;
  $isCompleteForSpeaker = $evalDone && (!$requiresQuiz || $quizDone);
  if(!$isCompleteForSpeaker){
    $dDate = $speakerDayDate[$sid] ?? null;
    if(!$dDate || $dDate <= $today){ $incompleteFutureOnly=false; }
  }
  $details[]=['speaker_id'=>$sid,'evaluation'=>$evalDone,'quiz_required'=>$requiresQuiz,'quiz_done'=>$quizDone,'day_date'=>$speakerDayDate[$sid] ?? null];
}
$complete = ($evalCompleted===$total && $quizCompleted===$quizRequired);
$complete_relaxed = $complete || $incompleteFutureOnly; // relaxed if only future-day speakers remain

// Summarize per-day quick stats
$days=[]; foreach($byDay as $dId=>$spIds){ $dTotal=count($spIds); $dEval=0; $dQuizReq=0; $dQuizDone=0; foreach($spIds as $spId){ foreach($details as $row){ if($row['speaker_id']===$spId){ if($row['evaluation']) $dEval++; if($row['quiz_required']){ $dQuizReq++; if($row['quiz_done']) $dQuizDone++; } } } } $days[]=['day_id'=>$dId,'eval_completed'=>$dEval,'eval_total'=>$dTotal,'quiz_required'=>$dQuizReq,'quiz_completed'=>$dQuizDone]; }

echo json_encode(['ok'=>true,'total'=>$total,'eval_completed'=>$evalCompleted,'eval_total'=>$total,'quiz_required'=>$quizRequired,'quiz_completed'=>$quizCompleted,'complete'=>$complete,'complete_relaxed'=>$complete_relaxed,'details'=>$details,'days'=>$days]);
