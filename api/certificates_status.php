<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../db.php';
if (isset($conn) && $conn instanceof mysqli) $db = $conn; elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli; else { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB unavailable']); exit; }
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($user_id<=0){ http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Login required']); exit; }

$u = $db->prepare('SELECT eligible_certificate_attendance, eligible_certificate_appearance, eligible_cpd_certificate FROM users WHERE id=? LIMIT 1');
$elig = ['attendance'=>0,'appearance'=>0,'cpd'=>0];
if ($u){ $u->bind_param('i',$user_id); $u->execute(); $u->bind_result($a,$b,$c); if ($u->fetch()){ $elig=['attendance'=>(int)$a,'appearance'=>(int)$b,'cpd'=>(int)$c]; } $u->close(); }

$issued = [];
if ($res = $db->prepare('SELECT cert_type, file_path, generated_at FROM certificates_issued WHERE user_id=?')) {
  $res->bind_param('i',$user_id); $res->execute(); $r=$res->get_result();
  while ($row=$r->fetch_assoc()) { $issued[$row['cert_type']] = ['file_path'=>$row['file_path'],'generated_at'=>$row['generated_at']]; }
  $res->close();
}

echo json_encode(['ok'=>true,'eligibility'=>$elig,'issued'=>$issued]);
