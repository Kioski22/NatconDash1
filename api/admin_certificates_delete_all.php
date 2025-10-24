<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../db.php';

// Admin auth
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admin required']); exit; }

// Input (JSON)
$in = json_decode(file_get_contents('php://input'), true) ?: [];
if (empty($in['csrf']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string)$in['csrf'])){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid CSRF']); exit; }
$type = isset($in['type']) ? trim((string)$in['type']) : '';
$scope_id = isset($in['scope_id']) && $in['scope_id']!=='' ? (int)$in['scope_id'] : null;
$q = isset($in['q']) ? trim((string)$in['q']) : '';
$deleteFile = isset($in['delete_file']) ? (bool)$in['delete_file'] : true;
if($type===''){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Type required']); exit; }

// DB normalize
if (isset($conn) && $conn instanceof mysqli) $db = $conn; elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli; else { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB unavailable']); exit; }

function likeWrap(string $s){ return '%'.str_replace(['%','_'],['\\%','\\_'],$s).'%'; }

// Safe delete helper
function safe_delete_file(string $rel): bool {
    $rel = str_replace(['..','\\'], ['','/'], $rel);
    if (strpos($rel, 'uploads/certificates/') !== 0) return false;
    $abs = __DIR__ . '/../' . $rel;
    if (is_file($abs)) { @unlink($abs); return !file_exists($abs); }
    return true;
}

$table = '';
$where = '1=1';
$params = [];
$paramTypes = '';
$joinUsers = false;

switch($type){
  case 'main_program_overall':
    $table = 'main_program_certificates';
    if($q!==''){ $joinUsers = true; $where .= ' AND (u.name LIKE ? OR u.email LIKE ?)'; $kw=likeWrap($q); $params[]=$kw; $params[]=$kw; $paramTypes.='ss'; }
    break;
  case 'main_program_day':
    $table = 'main_program_day_certificates';
    if($scope_id){ $where .= ' AND c.day_id=?'; $params[]=$scope_id; $paramTypes.='i'; }
    if($q!==''){ $joinUsers = true; $where .= ' AND (u.name LIKE ? OR u.email LIKE ?)'; $kw=likeWrap($q); $params[]=$kw; $params[]=$kw; $paramTypes.='ss'; }
    break;
  case 'main_program_attendance':
    $table = 'main_program_attendance_certificates';
    if($q!==''){ $joinUsers = true; $where .= ' AND (u.name LIKE ? OR u.email LIKE ?)'; $kw=likeWrap($q); $params[]=$kw; $params[]=$kw; $paramTypes.='ss'; }
    break;
  case 'sub_department':
    $table = 'sub_department_certificates';
    if($scope_id){ $where .= ' AND c.technical_department_id=?'; $params[]=$scope_id; $paramTypes.='i'; }
    if($q!==''){ $joinUsers = true; $where .= ' AND (u.name LIKE ? OR u.email LIKE ?)'; $kw=likeWrap($q); $params[]=$kw; $params[]=$kw; $paramTypes.='ss'; }
    break;
  case 'sub_department_attendance':
    $table = 'sub_department_attendance_certificates';
    if($scope_id){ $where .= ' AND c.technical_department_id=?'; $params[]=$scope_id; $paramTypes.='i'; }
    if($q!==''){ $joinUsers = true; $where .= ' AND (u.name LIKE ? OR u.email LIKE ?)'; $kw=likeWrap($q); $params[]=$kw; $params[]=$kw; $paramTypes.='ss'; }
    break;
  case 'sub_session':
    $table = 'sub_session_certificates';
    if($scope_id){ $where .= ' AND c.speaker_id=?'; $params[]=$scope_id; $paramTypes.='i'; }
    if($q!==''){ $joinUsers = true; $where .= ' AND (u.name LIKE ? OR u.email LIKE ?)'; $kw=likeWrap($q); $params[]=$kw; $params[]=$kw; $paramTypes.='ss'; }
    break;
  default:
    http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Unsupported type']); exit;
}

// Collect matching ids + file paths
$sqlSel = 'SELECT c.id,c.file_path FROM '.$table.' c';
if($joinUsers){ $sqlSel .= ' JOIN users u ON u.id=c.user_id'; }
$sqlSel .= ' WHERE '.$where;
$ids = [];
$filePaths = [];
if($st = $db->prepare($sqlSel)){
  if($paramTypes !== ''){ $st->bind_param($paramTypes, ...$params); }
  $st->execute();
  $st->bind_result($rid,$rfile);
  while($st->fetch()){ $ids[]=(int)$rid; if($rfile!==null && $rfile!=='') $filePaths[]=$rfile; }
  $st->close();
}

if(empty($ids)){ echo json_encode(['ok'=>true,'deleted'=>0]); exit; }

$db->begin_transaction();
try{
  if($deleteFile){ foreach($filePaths as $fp){ safe_delete_file($fp); } }
  // Delete in chunks to avoid too-long IN lists
  $deletedTotal = 0;
  $chunkSize = 500;
  for($i=0;$i<count($ids);$i+=$chunkSize){
    $chunk = array_slice($ids,$i,$chunkSize);
    $ph = implode(',', array_fill(0, count($chunk), '?'));
    $sqlDel = 'DELETE FROM '.$table.' WHERE id IN ('.$ph.')';
    $types = str_repeat('i', count($chunk));
    if($st=$db->prepare($sqlDel)){
      $st->bind_param($types, ...$chunk);
      $st->execute();
      $deletedTotal += $st->affected_rows;
      $st->close();
    }
  }
  $db->commit();
  echo json_encode(['ok'=>true,'deleted'=>$deletedTotal]);
} catch(Throwable $e){
  $db->rollback();
  http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Bulk delete failed']);
}
?>
