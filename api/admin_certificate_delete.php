<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../db.php';

// Admin auth
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admin required']); exit; }
// CSRF basic check
$in = json_decode(file_get_contents('php://input'), true) ?: [];
if (empty($in['csrf']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$in['csrf'])){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid CSRF']); exit; }

// DB normalize
if (isset($conn) && $conn instanceof mysqli) $db = $conn; elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli; else { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB unavailable']); exit; }

$id = isset($in['id']) ? (int)$in['id'] : 0;
$type = isset($in['type']) ? trim((string)$in['type']) : '';
$deleteFile = isset($in['delete_file']) ? (bool)$in['delete_file'] : true;
if($id<=0 || $type===''){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid input']); exit; }

// Helper to delete file safely (inside uploads/certificates only)
function safe_delete_file(string $rel): bool {
    $rel = str_replace(['..','\\'], ['','/'], $rel);
    if (strpos($rel, 'uploads/certificates/') !== 0) return false;
    $abs = __DIR__ . '/../' . $rel;
    if (is_file($abs)) { @unlink($abs); return !file_exists($abs); }
    return true;
}

// Determine table and delete
switch($type){
  case 'main_program_overall':
    // Fetch file_path and delete row
    $fp=null; if($st=$db->prepare('SELECT file_path FROM main_program_certificates WHERE id=? LIMIT 1')){ $st->bind_param('i',$id); $st->execute(); $st->bind_result($fp); $st->fetch(); $st->close(); }
    if($deleteFile && $fp) safe_delete_file($fp);
    if($st=$db->prepare('DELETE FROM main_program_certificates WHERE id=? LIMIT 1')){ $st->bind_param('i',$id); $ok=$st->execute(); $st->close(); if(!$ok){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Delete failed']); exit; } echo json_encode(['ok'=>true]); exit; }
    break;
  case 'main_program_day':
    $fp=null; if($st=$db->prepare('SELECT file_path FROM main_program_day_certificates WHERE id=? LIMIT 1')){ $st->bind_param('i',$id); $st->execute(); $st->bind_result($fp); $st->fetch(); $st->close(); }
    if($deleteFile && $fp) safe_delete_file($fp);
    if($st=$db->prepare('DELETE FROM main_program_day_certificates WHERE id=? LIMIT 1')){ $st->bind_param('i',$id); $ok=$st->execute(); $st->close(); if(!$ok){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Delete failed']); exit; } echo json_encode(['ok'=>true]); exit; }
    break;
  case 'main_program_attendance':
    $fp=null; if($st=$db->prepare('SELECT file_path FROM main_program_attendance_certificates WHERE id=? LIMIT 1')){ $st->bind_param('i',$id); $st->execute(); $st->bind_result($fp); $st->fetch(); $st->close(); }
    if($deleteFile && $fp) safe_delete_file($fp);
    if($st=$db->prepare('DELETE FROM main_program_attendance_certificates WHERE id=? LIMIT 1')){ $st->bind_param('i',$id); $ok=$st->execute(); $st->close(); if(!$ok){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Delete failed']); exit; } echo json_encode(['ok'=>true]); exit; }
    break;
  case 'sub_department':
    $fp=null; if($st=$db->prepare('SELECT file_path FROM sub_department_certificates WHERE id=? LIMIT 1')){ $st->bind_param('i',$id); $st->execute(); $st->bind_result($fp); $st->fetch(); $st->close(); }
    if($deleteFile && $fp) safe_delete_file($fp);
    if($st=$db->prepare('DELETE FROM sub_department_certificates WHERE id=? LIMIT 1')){ $st->bind_param('i',$id); $ok=$st->execute(); $st->close(); if(!$ok){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Delete failed']); exit; } echo json_encode(['ok'=>true]); exit; }
    break;
  case 'sub_department_attendance':
    $fp=null; if($st=$db->prepare('SELECT file_path FROM sub_department_attendance_certificates WHERE id=? LIMIT 1')){ $st->bind_param('i',$id); $st->execute(); $st->bind_result($fp); $st->fetch(); $st->close(); }
    if($deleteFile && $fp) safe_delete_file($fp);
    if($st=$db->prepare('DELETE FROM sub_department_attendance_certificates WHERE id=? LIMIT 1')){ $st->bind_param('i',$id); $ok=$st->execute(); $st->close(); if(!$ok){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Delete failed']); exit; } echo json_encode(['ok'=>true]); exit; }
    break;
  case 'sub_session':
    $fp=null; if($st=$db->prepare('SELECT file_path FROM sub_session_certificates WHERE id=? LIMIT 1')){ $st->bind_param('i',$id); $st->execute(); $st->bind_result($fp); $st->fetch(); $st->close(); }
    if($deleteFile && $fp) safe_delete_file($fp);
    if($st=$db->prepare('DELETE FROM sub_session_certificates WHERE id=? LIMIT 1')){ $st->bind_param('i',$id); $ok=$st->execute(); $st->close(); if(!$ok){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Delete failed']); exit; } echo json_encode(['ok'=>true]); exit; }
    break;
  default:
    http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Unsupported type']); exit;
}

http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Unhandled']);
?>
