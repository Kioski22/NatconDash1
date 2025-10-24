<?php
// API endpoint for Main Program Day speakers by room (includes per-user completion flags)
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../db.php';
if (isset($conn) && $conn instanceof mysqli) { $db = $conn; } elseif (isset($mysqli) && $mysqli instanceof mysqli) { $db = $mysqli; } else { http_response_code(500); echo json_encode(['error'=>'DB unavailable']); exit; }

$dayId = isset($_GET['day_id']) ? (int)$_GET['day_id'] : 0;
if ($dayId <= 0) { http_response_code(400); echo json_encode(['error'=>'Invalid day']); exit; }

// Fetch day label/date
$day = null;
if ($st = $db->prepare('SELECT id,label,day_date,is_enabled FROM function_room_days WHERE id=? LIMIT 1')) {
  $st->bind_param('i',$dayId); $st->execute(); $r=$st->get_result(); $day=$r->fetch_assoc(); $st->close();
}
if (!$day) { http_response_code(404); echo json_encode(['error'=>'Day not found']); exit; }

// Speakers for this day (joined via function_rooms)
$speakers = [];
$sql = "SELECT s.id,s.name,s.title,s.topic,s.start_time,s.end_time,s.photo_url,fr.name AS room_name,fr.id AS room_id
  FROM speakers s
  LEFT JOIN function_rooms fr ON fr.id = s.room_id
  WHERE fr.day_id = ? AND s.program_type='main'
  ORDER BY fr.name ASC, s.start_time ASC, s.name ASC";
if ($st = $db->prepare($sql)) {
  $st->bind_param('i',$dayId); $st->execute(); $res=$st->get_result();
  while($row=$res->fetch_assoc()) { $speakers[]=$row; }
  $st->close();
}

// Enrich speakers with per-user completion if logged in
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$userEmail = trim((string)($_SESSION['user_email'] ?? $_SESSION['email'] ?? ''));
if (!empty($speakers) && ($userId > 0 || $userEmail !== '')) {
  $speakerIds = array_values(array_unique(array_map(fn($r)=> (int)$r['id'], $speakers)));
  if (!empty($speakerIds)) {
    $in = implode(',', array_fill(0, count($speakerIds), '?'));
    // Build dynamic types for IN clause
    $typesIn = str_repeat('i', count($speakerIds));

    // Map speaker -> quiz_id if any
    $quizMap = [];
    if ($st = $db->prepare("SELECT speaker_id, id FROM quizzes WHERE speaker_id IN ($in)")) {
      $st->bind_param($typesIn, ...$speakerIds);
      $st->execute(); $r = $st->get_result();
      while ($row = $r->fetch_assoc()) { $quizMap[(int)$row['speaker_id']] = (int)$row['id']; }
      $st->close();
    }

    // Quiz submissions set (by user)
    $quizDone = [];
    if ($userId > 0 && !empty($quizMap)) {
      $inQuiz = implode(',', array_fill(0, count($quizMap), '?'));
      $typesQuiz = str_repeat('i', count($quizMap));
      $quizIds = array_values($quizMap);
      if ($st = $db->prepare("SELECT q.speaker_id FROM quiz_responses qr JOIN quizzes q ON q.id = qr.quiz_id WHERE qr.user_id = ? AND qr.quiz_id IN ($inQuiz)")) {
        $st->bind_param('i'.$typesQuiz, $userId, ...$quizIds);
        $st->execute(); $r=$st->get_result();
        while ($row = $r->fetch_assoc()) { $quizDone[(int)$row['speaker_id']] = true; }
        $st->close();
      }
      // Fallback by speaker_id on quiz_responses (legacy)
      if ($st = $db->prepare("SELECT speaker_id FROM quiz_responses WHERE user_id = ? AND speaker_id IN ($in)")) {
        $st->bind_param('i'.$typesIn, $userId, ...$speakerIds);
        $st->execute(); $r=$st->get_result();
        while ($row = $r->fetch_assoc()) { $quizDone[(int)$row['speaker_id']] = true; }
        $st->close();
      }
    }
    // Email fallback for quiz
    if ($userId <= 0 && $userEmail !== '' && !empty($quizMap)) {
      $inQuiz = implode(',', array_fill(0, count($quizMap), '?'));
      $typesQuiz = str_repeat('i', count($quizMap));
      $quizIds = array_values($quizMap);
      if ($st = $db->prepare("SELECT q.speaker_id FROM quiz_responses qr JOIN quizzes q ON q.id = qr.quiz_id WHERE (qr.user_email = ? OR qr.email = ?) AND qr.quiz_id IN ($inQuiz)")) {
        $st->bind_param('ss'.$typesQuiz, $userEmail, $userEmail, ...$quizIds);
        $st->execute(); $r=$st->get_result();
        while ($row = $r->fetch_assoc()) { $quizDone[(int)$row['speaker_id']] = true; }
        $st->close();
      }
      if ($st = $db->prepare("SELECT speaker_id FROM quiz_responses WHERE (user_email = ? OR email = ?) AND speaker_id IN ($in)")) {
        $st->bind_param('ss'.$typesIn, $userEmail, $userEmail, ...$speakerIds);
        $st->execute(); $r=$st->get_result();
        while ($row = $r->fetch_assoc()) { $quizDone[(int)$row['speaker_id']] = true; }
        $st->close();
      }
    }

    // Evaluations set (by user)
    $evalDone = [];
    if ($userId > 0) {
      if ($st = $db->prepare("SELECT speaker_id FROM speaker_evaluations WHERE user_id = ? AND speaker_id IN ($in)")) {
        $st->bind_param('i'.$typesIn, $userId, ...$speakerIds);
        $st->execute(); $r=$st->get_result();
        while ($row = $r->fetch_assoc()) { $evalDone[(int)$row['speaker_id']] = true; }
        $st->close();
      }
    } elseif ($userEmail !== '') {
      if ($st = $db->prepare("SELECT speaker_id FROM speaker_evaluations WHERE (user_email = ? OR email = ?) AND speaker_id IN ($in)")) {
        $st->bind_param('ss'.$typesIn, $userEmail, $userEmail, ...$speakerIds);
        $st->execute(); $r=$st->get_result();
        while ($row = $r->fetch_assoc()) { $evalDone[(int)$row['speaker_id']] = true; }
        $st->close();
      }
    }
    // Optional dynamic evaluation table
    if ($resTbl = $db->query("SHOW TABLES LIKE 'speaker_eval_responses'")) {
      if ($resTbl->num_rows > 0) {
        $resTbl->close();
        if ($userId > 0) {
          if ($st = $db->prepare("SELECT speaker_id FROM speaker_eval_responses WHERE user_id = ? AND speaker_id IN ($in)")) {
            $st->bind_param('i'.$typesIn, $userId, ...$speakerIds);
            $st->execute(); $r=$st->get_result();
            while ($row = $r->fetch_assoc()) { $evalDone[(int)$row['speaker_id']] = true; }
            $st->close();
          }
        } elseif ($userEmail !== '') {
          if ($st = $db->prepare("SELECT speaker_id FROM speaker_eval_responses WHERE (user_email = ? OR email = ?) AND speaker_id IN ($in)")) {
            $st->bind_param('ss'.$typesIn, $userEmail, $userEmail, ...$speakerIds);
            $st->execute(); $r=$st->get_result();
            while ($row = $r->fetch_assoc()) { $evalDone[(int)$row['speaker_id']] = true; }
            $st->close();
          }
        }
      } else { $resTbl->close(); }
    }

    // Determine finished per speaker: evaluation done AND (no quiz OR quiz submitted)
    foreach ($speakers as &$sp) {
      $sid = (int)$sp['id'];
      $hasQ = isset($quizMap[$sid]);
      $done = isset($evalDone[$sid]) && (!$hasQ || isset($quizDone[$sid]));
      $sp['finished'] = $done ? 1 : 0;
    }
    unset($sp);
  }
}

// Group speakers by room
$rooms = [];
foreach ($speakers as $sp) {
  $rid = (int)$sp['room_id'];
  if (!isset($rooms[$rid])) { $rooms[$rid] = ['room_name'=>$sp['room_name'] ?: 'Room','speakers'=>[]]; }
  $rooms[$rid]['speakers'][] = $sp;
}

echo json_encode([
  'day' => $day,
  'rooms' => $rooms
]);
