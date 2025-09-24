<?php

header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../db.php';

if (isset($conn) && $conn instanceof mysqli) $db = $conn;
elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli;
else { http_response_code(500); echo json_encode(['error'=>'DB unavailable']); exit; }

$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($user_id <= 0) { http_response_code(401); echo json_encode(['error'=>'Login required']); exit; }

$speaker_id = isset($_GET['speaker_id']) ? (int)$_GET['speaker_id'] : 0;
if ($speaker_id <= 0) { http_response_code(400); echo json_encode(['error'=>'Invalid speaker']); exit; }

$stmt = $db->prepare("SELECT id, room_id, day_id, content_quality, speaker_clarity, engagement, overall, comments, created_at
                      FROM speaker_evaluations WHERE user_id = ? AND speaker_id = ? LIMIT 1");
if (!$stmt) { http_response_code(500); echo json_encode(['error'=>'DB prepare failed']); exit; }
$stmt->bind_param('ii', $user_id, $speaker_id);
$stmt->execute();
$res = $stmt->get_result();
if ($row = $res->fetch_assoc()) {
  echo json_encode(['found'=>true, 'evaluation'=>$row]);
} else {
  http_response_code(404);
  echo json_encode(['found'=>false]);
}
$stmt->close();