<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../db.php';
if (isset($conn) && $conn instanceof mysqli) $db=$conn; elseif (isset($mysqli) && $mysqli instanceof mysqli) $db=$mysqli; else { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB unavailable']); exit; }
$user_id = isset($_SESSION['user_id'])?(int)$_SESSION['user_id']:0; if($user_id<=0){ http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Login required']); exit; }
$speaker_id = isset($_GET['speaker_id'])?(int)$_GET['speaker_id']:0; if($speaker_id<=0){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid speaker']); exit; }
$dyn=false; if($st=$db->prepare('SELECT id FROM speaker_eval_responses WHERE user_id=? AND speaker_id=? LIMIT 1')){ $st->bind_param('ii',$user_id,$speaker_id); $st->execute(); $st->bind_result($rid); if($st->fetch()) $dyn=true; $st->close(); }
$legacy=false; if(!$dyn && $st=$db->prepare('SELECT id FROM speaker_evaluations WHERE user_id=? AND speaker_id=? LIMIT 1')){ $st->bind_param('ii',$user_id,$speaker_id); $st->execute(); $st->bind_result($id); if($st->fetch()) $legacy=true; $st->close(); }
echo json_encode(['ok'=>true,'evaluated'=>($dyn||$legacy),'mode'=>$dyn?'dynamic':($legacy?'legacy':null)]);
