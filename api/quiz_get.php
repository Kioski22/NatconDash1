<?php

header('Content-Type: application/json; charset=utf-8');
// Prevent CDN/browser caching mismatching quizzes across speakers/users
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
session_start();
require_once __DIR__ . '/../db.php';

if (isset($conn) && $conn instanceof mysqli) $db = $conn;
elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli;
else { http_response_code(500); echo json_encode(['error'=>'DB unavailable']); exit; }

// Enforce authentication before exposing quiz contents
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($user_id <= 0) { http_response_code(401); echo json_encode(['error'=>'Login required']); exit; }

$speaker_id = isset($_GET['speaker_id']) ? (int)$_GET['speaker_id'] : 0;
if ($speaker_id <= 0) { http_response_code(400); echo json_encode(['error'=>'Invalid speaker']); exit; }

// Try selecting with optional columns; fall back if not supported by schema
$sqlPrimary = "SELECT id, speaker_id, room_id, day_id, questions, options, answers, passing_score FROM quizzes WHERE speaker_id = ? ORDER BY id DESC LIMIT 1";
$sqlFallback = "SELECT id, speaker_id, questions, options, answers FROM quizzes WHERE speaker_id = ? ORDER BY id DESC LIMIT 1";
$res = null;
$stmt = $db->prepare($sqlPrimary);
if ($stmt) {
  $stmt->bind_param('i', $speaker_id);
  if ($stmt->execute()) {
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
      $id=$spk=$room_id=$day_id=null; $questionsJson=$optionsJson=$answersJson=null; $passingScore=null;
      $stmt->bind_result($id,$spk,$room_id,$day_id,$questionsJson,$optionsJson,$answersJson,$passingScore);
      $stmt->fetch();
      $res = [
        'id'=>(int)$id,
        'speaker_id'=>(int)$spk,
        'room_id'=> $room_id !== null ? (int)$room_id : null,
        'day_id'=> $day_id !== null ? (int)$day_id : null,
        'questions'=>$questionsJson,
        'options'=>$optionsJson,
        'answers'=>$answersJson,
        'passing_score'=>$passingScore !== null ? (int)$passingScore : null,
      ];
    }
  }
  $stmt->free_result();
  $stmt->close();
}
if ($res === null) {
  // Fallback without optional columns
  $stmt2 = $db->prepare($sqlFallback);
  if ($stmt2) {
    $stmt2->bind_param('i',$speaker_id);
    if ($stmt2->execute()) {
      $stmt2->store_result();
      if ($stmt2->num_rows > 0) {
        $id=$spk=null; $questionsJson=$optionsJson=$answersJson=null;
        $stmt2->bind_result($id,$spk,$questionsJson,$optionsJson,$answersJson);
        $stmt2->fetch();
        $res = [
          'id'=>(int)$id,
          'speaker_id'=>(int)$spk,
          'room_id'=> null,
          'day_id'=> null,
          'questions'=>$questionsJson,
          'options'=>$optionsJson,
          'answers'=>$answersJson,
          'passing_score'=> null,
        ];
      }
    }
    $stmt2->free_result();
    $stmt2->close();
  }
}

if (!$res) { http_response_code(404); echo json_encode(['error'=>'Quiz not found']); exit; }

// Build a 'multiple' array indicating which questions accept multiple answers without exposing the actual answers
$multiple = [];
if (isset($res['answers'])) {
  $ans = json_decode($res['answers'], true);
  if (is_array($ans)) {
    foreach ($ans as $a) {
      $multiple[] = is_array($a);
    }
  }
}

// Ensure length matches questions length if possible; otherwise default to false
$questionsArr = json_decode($res['questions'] ?? '[]', true);
if (!is_array($questionsArr)) { $questionsArr = []; }
if (count($multiple) !== count($questionsArr)) {
  $multiple = array_fill(0, count($questionsArr), false);
}

// Best-effort: ensure quizzes.passing_score column exists (no CI/CD)
// Try add column if missing; ignore errors (insufficient privilege etc.)
// Avoid runtime migrations here to prevent errors on hosts without privileges/older MySQL

echo json_encode([
  'quiz_id'   => (int)$res['id'],
  'speaker_id'=> (int)$res['speaker_id'],
  'room_id'   => $res['room_id'] !== null ? (int)$res['room_id'] : null,
  'day_id'    => $res['day_id'] !== null ? (int)$res['day_id'] : null,
  'questions' => json_decode($res['questions'] ?? '[]', true),
  'options'   => json_decode($res['options'] ?? '[]', true),
  'multiple'  => $multiple,
  'passing_score' => isset($res['passing_score']) && $res['passing_score'] !== null ? (int)$res['passing_score'] : null,
]);