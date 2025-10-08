<?php
// Returns completion status for all main program speakers for a given day for the current user
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../db.php';
if (isset($conn) && $conn instanceof mysqli) { $db = $conn; }
elseif (isset($mysqli) && $mysqli instanceof mysqli) { $db = $mysqli; }
else { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB unavailable']); exit; }

$dayId = isset($_GET['day_id']) ? (int)$_GET['day_id'] : 0;
if ($dayId <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid day']); exit; }

$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$user_email = trim((string)($_SESSION['user_email'] ?? $_SESSION['email'] ?? ''));
if ($user_id <= 0 && $user_email === '') { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Login required']); exit; }

// Fetch speakers for the day (main program only: program_type not 'sub')
$speakers = [];
$sql = "SELECT s.id FROM speakers s JOIN function_rooms fr ON fr.id = s.room_id WHERE fr.day_id = ? AND (s.program_type IS NULL OR LOWER(s.program_type) <> 'sub')";
if ($st = $db->prepare($sql)) {
  $st->bind_param('i',$dayId); $st->execute(); $r=$st->get_result();
  while($row=$r->fetch_assoc()){ $speakers[] = (int)$row['id']; }
  $st->close();
}
$total = count($speakers);
if ($total === 0) { echo json_encode(['ok'=>true,'total'=>0,'completed'=>0,'all_done'=>false,'details'=>[]]); exit; }

// Determine quiz presence per speaker
$hasQuizQuestions = false; if ($res = $db->query("SHOW TABLES LIKE 'quiz_questions'")) { $hasQuizQuestions = $res->num_rows>0; $res->close(); }

$evalCompleted = 0; $quizRequired = 0; $quizCompleted = 0; $details=[];
foreach ($speakers as $sid) {
  $evalDone=false; $quizDone=false; $requiresQuiz=false; $quizId=0;
  // Detect quiz existence
  if ($q = $db->prepare('SELECT id FROM quizzes WHERE speaker_id=? LIMIT 1')) { $q->bind_param('i',$sid); $q->execute(); $q->bind_result($quizId); if($q->fetch()) { $requiresQuiz=true; } $q->close(); }
  if(!$requiresQuiz && $hasQuizQuestions) {
    if ($q2 = $db->prepare('SELECT 1 FROM quiz_questions WHERE speaker_id=? LIMIT 1')) { $q2->bind_param('i',$sid); $q2->execute(); $q2->bind_result($d); if($q2->fetch()) $requiresQuiz=true; $q2->close(); }
  }
  if ($requiresQuiz) $quizRequired++;
  // Quiz completion
  if ($requiresQuiz) {
    if ($user_id>0 && $quizId>0) {
      if ($qr = $db->prepare('SELECT 1 FROM quiz_responses WHERE quiz_id=? AND user_id=? LIMIT 1')) { $qr->bind_param('ii',$quizId,$user_id); $qr->execute(); $qr->bind_result($d); if($qr->fetch()) $quizDone=true; $qr->close(); }
    }
    if(!$quizDone && $user_id>0) {
      if ($qr2 = $db->prepare('SELECT 1 FROM quiz_responses WHERE speaker_id=? AND user_id=? LIMIT 1')) { $qr2->bind_param('ii',$sid,$user_id); $qr2->execute(); $qr2->bind_result($d); if($qr2->fetch()) $quizDone=true; $qr2->close(); }
    }
    if(!$quizDone && $user_email!=='') {
      if ($qr3 = $db->prepare('SELECT 1 FROM quiz_responses qr JOIN quizzes qz ON qz.id=qr.quiz_id WHERE qz.speaker_id=? AND (qr.user_email=? OR qr.email=?) LIMIT 1')) { $qr3->bind_param('iss',$sid,$user_email,$user_email); $qr3->execute(); $qr3->bind_result($d); if($qr3->fetch()) $quizDone=true; $qr3->close(); }
    }
    if(!$quizDone && $user_email!=='') {
      if ($qr4 = $db->prepare('SELECT 1 FROM quiz_responses WHERE speaker_id=? AND (user_email=? OR email=?) LIMIT 1')) { $qr4->bind_param('iss',$sid,$user_email,$user_email); $qr4->execute(); $qr4->bind_result($d); if($qr4->fetch()) $quizDone=true; $qr4->close(); }
    }
    if ($quizDone) $quizCompleted++;
  }
  // Evaluation completion
  if ($user_id>0) {
    if ($ev = $db->prepare('SELECT 1 FROM speaker_evaluations WHERE speaker_id=? AND user_id=? LIMIT 1')) { $ev->bind_param('ii',$sid,$user_id); $ev->execute(); $ev->bind_result($d); if($ev->fetch()) $evalDone=true; $ev->close(); }
    if (!$evalDone && $evx = $db->prepare('SELECT 1 FROM speaker_eval_responses WHERE speaker_id=? AND user_id=? LIMIT 1')) { $evx->bind_param('ii',$sid,$user_id); $evx->execute(); $evx->bind_result($dd); if($evx->fetch()) $evalDone=true; $evx->close(); }
  }
  if(!$evalDone && $user_email!=='') {
    if ($ev2 = $db->prepare('SELECT 1 FROM speaker_evaluations WHERE speaker_id=? AND (user_email=? OR email=?) LIMIT 1')) { $ev2->bind_param('iss',$sid,$user_email,$user_email); $ev2->execute(); $ev2->bind_result($d); if($ev2->fetch()) $evalDone=true; $ev2->close(); }
  }
  if ($evalDone) $evalCompleted++;
  $details[] = [
    'speaker_id'=>$sid,
    'evaluation'=>$evalDone,
    'quiz_required'=>$requiresQuiz,
    'quiz_done'=>$quizDone
  ];
}

$complete = ($evalCompleted === $total && $quizCompleted === $quizRequired);
echo json_encode([
  'ok'=>true,
  'total'=>$total,
  'eval_completed'=>$evalCompleted,
  'eval_total'=>$total,
  'quiz_required'=>$quizRequired,
  'quiz_completed'=>$quizCompleted,
  'complete'=>$complete,
  'details'=>$details
]);
