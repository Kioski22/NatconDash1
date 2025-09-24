<?php

header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../db.php';

if (isset($conn) && $conn instanceof mysqli) $db = $conn;
elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli;
else { http_response_code(500); echo json_encode(['error'=>'DB unavailable']); exit; }

$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($user_id <= 0) { http_response_code(401); echo json_encode(['error'=>'Login required']); exit; }

$speaker_id = isset($_POST['speaker_id']) ? (int)$_POST['speaker_id'] : 0;
$cq = isset($_POST['content_quality']) ? (int)$_POST['content_quality'] : 0;
$sc = isset($_POST['speaker_clarity']) ? (int)$_POST['speaker_clarity'] : 0;
$eg = isset($_POST['engagement']) ? (int)$_POST['engagement'] : 0;
$ov = isset($_POST['overall']) ? (int)$_POST['overall'] : 0;
$comments = trim((string)($_POST['comments'] ?? ''));

if ($speaker_id <= 0) { http_response_code(400); echo json_encode(['error'=>'Invalid speaker']); exit; }
foreach ([$cq,$sc,$eg,$ov] as $v) {
  if ($v < 1 || $v > 5) { http_response_code(400); echo json_encode(['error'=>'Ratings must be 1-5']); exit; }
}

// Resolve room_id and day_id from speaker
$stmt = $db->prepare("SELECT s.room_id, fr.day_id FROM speakers s LEFT JOIN function_rooms fr ON fr.id = s.room_id WHERE s.id = ? LIMIT 1");
if (!$stmt) { http_response_code(500); echo json_encode(['error'=>'DB prepare failed']); exit; }
$stmt->bind_param('i', $speaker_id);
$stmt->execute();
$stmt->bind_result($room_id, $day_id);
if (!$stmt->fetch()) { $stmt->close(); http_response_code(404); echo json_encode(['error'=>'Speaker not found']); exit; }
$stmt->close();
$room_id = (int)$room_id; $day_id = (int)$day_id;

// Optional gate: require completed quiz if a quiz exists for this speaker
$requireQuizCompletion = true;
if ($requireQuizCompletion) {
  $quizId = 0;
  if ($st = $db->prepare("SELECT id FROM quizzes WHERE speaker_id = ? LIMIT 1")) {
    $st->bind_param('i', $speaker_id);
    $st->execute();
    $st->bind_result($quizId);
    $hasQuiz = $st->fetch();
    $st->close();
    if ($hasQuiz && $quizId > 0) {
      // check quiz_responses
      if ($st = $db->prepare("SELECT 1 FROM quiz_responses WHERE quiz_id = ? AND user_id = ? LIMIT 1")) {
        $st->bind_param('ii', $quizId, $user_id);
        $st->execute();
        $st->bind_result($one);
        $completed = $st->fetch();
        $st->close();
        if (!$completed) { http_response_code(403); echo json_encode(['error'=>'Complete the quiz before evaluation']); exit; }
      }
    }
  }
}

// Insert (dedupe by user+speaker)
$ins = $db->prepare("INSERT INTO speaker_evaluations (user_id, speaker_id, room_id, day_id, content_quality, speaker_clarity, engagement, overall, comments)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
if (!$ins) { http_response_code(500); echo json_encode(['error'=>'DB prepare failed']); exit; }
$ins->bind_param('iiiiiiiis', $user_id, $speaker_id, $room_id, $day_id, $cq, $sc, $eg, $ov, $comments);
if ($ins->execute()) {
  echo json_encode(['ok'=>true]);
} else {
  if ($db->errno == 1062) { http_response_code(409); echo json_encode(['error'=>'You already submitted an evaluation']); }
  else { http_response_code(500); echo json_encode(['error'=>'Save failed']); }
}
$ins->close();