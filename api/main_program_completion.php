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

// Load passing threshold from admin settings (percentage). Default 0 if not configured.
$passingPercent = 0;
if ($chk = $db->query("SHOW TABLES LIKE 'admin_settings'")) { if ($chk->num_rows>0) {
  // Prefer Main Program-specific threshold; fallback to legacy/global if missing
  $val = null;
  if ($st1 = $db->prepare("SELECT setting_value FROM admin_settings WHERE setting_key='main_cert_min_score' LIMIT 1")) { $st1->execute(); $st1->bind_result($val); $st1->fetch(); $st1->close(); }
  if ($val===null || $val==='') {
    if ($st2 = $db->prepare("SELECT setting_value FROM admin_settings WHERE setting_key='cert_min_score' LIMIT 1")) { $st2->execute(); $st2->bind_result($val); $st2->fetch(); $st2->close(); }
  }
  if ($val!==null && $val!=='') { $passingPercent = (int)$val; }
} $chk->close(); }

$hasQuizQuestions=false; if($chk=$db->query("SHOW TABLES LIKE 'quiz_questions'")){ $hasQuizQuestions=$chk->num_rows>0; $chk->close(); }
$evalCompleted=0; $quizRequired=0; $quizCompleted=0; $details=[]; $incompleteFutureOnly=true; // assume we can relax until we find an incomplete whose day is today/past

foreach($speakers as $sid){
  $evalDone=false; $quizDone=false; $requiresQuiz=false; $quizId=0; $quizPassed=false;
  if($q=$db->prepare('SELECT id FROM quizzes WHERE speaker_id=? LIMIT 1')){ $q->bind_param('i',$sid); $q->execute(); $q->bind_result($quizId); if($q->fetch()) $requiresQuiz=true; $q->close(); }
  if(!$requiresQuiz && $hasQuizQuestions){ if($q2=$db->prepare('SELECT 1 FROM quiz_questions WHERE speaker_id=? LIMIT 1')){ $q2->bind_param('i',$sid); $q2->execute(); $q2->bind_result($d); if($q2->fetch()) $requiresQuiz=true; $q2->close(); } }
  if($requiresQuiz) $quizRequired++;
  if($requiresQuiz){
    // Determine if the user has a response and whether it PASSES threshold
    $userHasResponse=false; $userScore=null;
    if($user_id>0 && $quizId>0){ if($qr=$db->prepare('SELECT score FROM quiz_responses WHERE quiz_id=? AND user_id=? LIMIT 1')){ $qr->bind_param('ii',$quizId,$user_id); $qr->execute(); $qr->bind_result($sc); if($qr->fetch()){ $userHasResponse=true; $userScore=(int)$sc; } $qr->close(); }}
    if(!$userHasResponse && $user_id>0){ if($qr2=$db->prepare('SELECT score FROM quiz_responses WHERE speaker_id=? AND user_id=? LIMIT 1')){ $qr2->bind_param('ii',$sid,$user_id); $qr2->execute(); $qr2->bind_result($sc); if($qr2->fetch()){ $userHasResponse=true; $userScore=(int)$sc; } $qr2->close(); }}
    if(!$userHasResponse && $user_email!==''){ if($qr3=$db->prepare('SELECT score FROM quiz_responses WHERE speaker_id=? AND (user_email=? OR email=?) LIMIT 1')){ $qr3->bind_param('iss',$sid,$user_email,$user_email); $qr3->execute(); $qr3->bind_result($sc); if($qr3->fetch()){ $userHasResponse=true; $userScore=(int)$sc; } $qr3->close(); }}

    if($userHasResponse){
      $quizDone=true;
      // Load total gradable questions from quizzes.answers (fallback: quizzes.questions length)
      $totalGradable=0; $ansJson=null; $qJson=null;
      if($quizId>0){ if($qq=$db->prepare('SELECT answers,questions FROM quizzes WHERE id=? LIMIT 1')){ $qq->bind_param('i',$quizId); $qq->execute(); $qq->bind_result($ansJson,$qJson); if($qq->fetch()){ /* no-op */ } $qq->close(); } }
      if($ansJson){ $arr=json_decode((string)$ansJson,true); if(is_array($arr)) $totalGradable=count($arr); }
      if($totalGradable<=0 && $qJson){ $arr=json_decode((string)$qJson,true); if(is_array($arr)) $totalGradable=count($arr); }
      if($totalGradable>0){ $pct = ($userScore*100.0)/$totalGradable; $quizPassed = ((int)round($pct) >= (int)$passingPercent); }
      else {
        // If we cannot determine total, consider response as not passed to be safe
        $quizPassed = false;
      }
    }
    if($quizPassed) $quizCompleted++;
  }
  if($user_id>0){ if($ev=$db->prepare('SELECT 1 FROM speaker_evaluations WHERE speaker_id=? AND user_id=? LIMIT 1')){ $ev->bind_param('ii',$sid,$user_id); $ev->execute(); $ev->bind_result($d); if($ev->fetch()) $evalDone=true; $ev->close(); } if(!$evalDone && $evx=$db->prepare('SELECT 1 FROM speaker_eval_responses WHERE speaker_id=? AND user_id=? LIMIT 1')){ $evx->bind_param('ii',$sid,$user_id); $evx->execute(); $evx->bind_result($dd); if($evx->fetch()) $evalDone=true; $evx->close(); }}
  if(!$evalDone && $user_email!==''){ if($ev2=$db->prepare('SELECT 1 FROM speaker_evaluations WHERE speaker_id=? AND (user_email=? OR email=?) LIMIT 1')){ $ev2->bind_param('iss',$sid,$user_email,$user_email); $ev2->execute(); $ev2->bind_result($d); if($ev2->fetch()) $evalDone=true; $ev2->close(); } }
  if($evalDone) $evalCompleted++;
  $isCompleteForSpeaker = $evalDone && (!$requiresQuiz || $quizPassed);
  if(!$isCompleteForSpeaker){
    $dDate = $speakerDayDate[$sid] ?? null;
    if(!$dDate || $dDate <= $today){ $incompleteFutureOnly=false; }
  }
  $details[]=['speaker_id'=>$sid,'evaluation'=>$evalDone,'quiz_required'=>$requiresQuiz,'quiz_done'=>$quizDone,'quiz_passed'=>$quizPassed,'day_date'=>$speakerDayDate[$sid] ?? null];
}
$complete = ($evalCompleted===$total && $quizCompleted===$quizRequired);
$complete_relaxed = $complete || $incompleteFutureOnly; // relaxed if only future-day speakers remain

// Count speakers where both evaluation is done and quiz (if required) is PASSED
$bothCompleted = 0; foreach($details as $row){ if(!empty($row['evaluation']) && (!empty($row['quiz_required']) ? !empty($row['quiz_passed']) : true)) $bothCompleted++; }

// Summarize per-day quick stats (quiz_completed counts PASSED quizzes)
$days=[]; foreach($byDay as $dId=>$spIds){ $dTotal=count($spIds); $dEval=0; $dQuizReq=0; $dQuizPassed=0; foreach($spIds as $spId){ foreach($details as $row){ if($row['speaker_id']===$spId){ if($row['evaluation']) $dEval++; if($row['quiz_required']){ $dQuizReq++; if(!empty($row['quiz_passed'])) $dQuizPassed++; } } } } $days[]=['day_id'=>$dId,'eval_completed'=>$dEval,'eval_total'=>$dTotal,'quiz_required'=>$dQuizReq,'quiz_completed'=>$dQuizPassed]; }

echo json_encode(['ok'=>true,'total'=>$total,'eval_completed'=>$evalCompleted,'eval_total'=>$total,'quiz_required'=>$quizRequired,'quiz_completed'=>$quizCompleted,'both_completed'=>$bothCompleted,'complete'=>$complete,'complete_relaxed'=>$complete_relaxed,'details'=>$details,'days'=>$days]);
