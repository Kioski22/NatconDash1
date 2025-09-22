<?php

header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../db.php';

if (isset($conn) && $conn instanceof mysqli) $db = $conn;
elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli;
else { http_response_code(500); echo json_encode(['error'=>'DB unavailable']); exit; }

$speaker_id = isset($_GET['speaker_id']) ? (int)$_GET['speaker_id'] : 0;
if ($speaker_id <= 0) { http_response_code(400); echo json_encode(['error'=>'Invalid speaker']); exit; }

$stmt = $db->prepare("SELECT id, speaker_id, room_id, day_id, questions, options FROM quizzes WHERE speaker_id = ? LIMIT 1");
$stmt->bind_param('i', $speaker_id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$res) { http_response_code(404); echo json_encode(['error'=>'Quiz not found']); exit; }

echo json_encode([
  'quiz_id'   => (int)$res['id'],
  'speaker_id'=> (int)$res['speaker_id'],
  'room_id'   => $res['room_id'] !== null ? (int)$res['room_id'] : null,
  'day_id'    => $res['day_id'] !== null ? (int)$res['day_id'] : null,
  'questions' => json_decode($res['questions'], true),
  'options'   => json_decode($res['options'], true),
]);