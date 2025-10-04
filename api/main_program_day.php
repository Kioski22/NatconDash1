<?php
// API endpoint for Main Program Day speakers by room
header('Content-Type: application/json');
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
$sql = "SELECT s.id,s.name,s.title,s.start_time,s.end_time,s.photo_url,fr.name AS room_name,fr.id AS room_id
  FROM speakers s
  LEFT JOIN function_rooms fr ON fr.id = s.room_id
  WHERE fr.day_id = ? AND s.program_type='main'
  ORDER BY fr.name ASC, s.start_time ASC, s.name ASC";
if ($st = $db->prepare($sql)) {
  $st->bind_param('i',$dayId); $st->execute(); $res=$st->get_result();
  while($row=$res->fetch_assoc()) { $speakers[]=$row; }
  $st->close();
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
