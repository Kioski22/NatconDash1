<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../db.php';

// Admin auth
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Admin required']); exit; }

// DB
if (isset($conn) && $conn instanceof mysqli) $db = $conn; elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli; else { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB unavailable']); exit; }

// Inputs
$type = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
$scope_id = isset($_GET['scope_id']) && $_GET['scope_id']!=='' ? (int)$_GET['scope_id'] : null;
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$page = isset($_GET['page']) ? max(1,(int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? max(1,min(100,(int)$_GET['limit'])) : 25;
$offset = ($page-1)*$limit;

// Helpers
function likeWrap(string $s){ return '%'.str_replace(['%','_'],['\%','\_'],$s).'%'; }

// Supported types mapping to SQL
$rows = [];
$total = 0;
$type_label = '';

// Build query based on type
switch($type){
  case 'main_program_overall':
    $type_label = 'Main Program — Overall';
    // Total
    if($q!==''){
      if($st=$db->prepare("SELECT COUNT(*) FROM main_program_certificates c JOIN users u ON u.id=c.user_id WHERE (u.name LIKE ? OR u.email LIKE ?)")){
        $kw=likeWrap($q); $st->bind_param('ss',$kw,$kw); $st->execute(); $st->bind_result($total); $st->fetch(); $st->close();
      }
    } else { if($r=$db->query("SELECT COUNT(*) FROM main_program_certificates")){ $total=(int)$r->fetch_row()[0]; } }
    // Rows
    if($q!==''){
      if($st=$db->prepare("SELECT c.id,c.user_id,u.name,u.email,c.file_path,c.generated_at FROM main_program_certificates c JOIN users u ON u.id=c.user_id WHERE (u.name LIKE ? OR u.email LIKE ?) ORDER BY c.generated_at DESC LIMIT ?,?")){
        $kw=likeWrap($q); $st->bind_param('ssii',$kw,$kw,$offset,$limit); $st->execute(); $st->bind_result($id,$uid,$uname,$uemail,$fp,$gen);
        while($st->fetch()){ $rows[] = ['id'=>$id,'user_id'=>$uid,'user_name'=>$uname,'user_email'=>$uemail,'file_path'=>$fp,'generated_at'=>$gen,'type'=>'main_program_overall','type_label'=>$type_label,'scope_label'=>'—']; }
        $st->close();
      }
    } else {
      if($st=$db->prepare("SELECT c.id,c.user_id,u.name,u.email,c.file_path,c.generated_at FROM main_program_certificates c JOIN users u ON u.id=c.user_id ORDER BY c.generated_at DESC LIMIT ?,?")){
        $st->bind_param('ii',$offset,$limit); $st->execute(); $st->bind_result($id,$uid,$uname,$uemail,$fp,$gen);
        while($st->fetch()){ $rows[] = ['id'=>$id,'user_id'=>$uid,'user_name'=>$uname,'user_email'=>$uemail,'file_path'=>$fp,'generated_at'=>$gen,'type'=>'main_program_overall','type_label'=>$type_label,'scope_label'=>'—']; }
        $st->close();
      }
    }
    break;

  case 'main_program_day':
    $type_label = 'Main Program — Day';
    $where = '';
    if($scope_id){ $where = ' WHERE c.day_id='.(int)$scope_id; }
    if($q!==''){
      $sqlCnt = "SELECT COUNT(*) FROM main_program_day_certificates c JOIN users u ON u.id=c.user_id".$where." AND (u.name LIKE ? OR u.email LIKE ?)";
      if(!$scope_id){ $sqlCnt = "SELECT COUNT(*) FROM main_program_day_certificates c JOIN users u ON u.id=c.user_id WHERE (u.name LIKE ? OR u.email LIKE ?)"; }
      if($st=$db->prepare($sqlCnt)){
        $kw=likeWrap($q); $st->bind_param('ss',$kw,$kw); $st->execute(); $st->bind_result($total); $st->fetch(); $st->close();
      }
    } else { if($r=$db->query("SELECT COUNT(*) FROM main_program_day_certificates c".($scope_id? (" WHERE c.day_id=".(int)$scope_id):''))){ $total=(int)$r->fetch_row()[0]; } }
    $sql = "SELECT c.id,c.user_id,u.name,u.email,c.file_path,c.generated_at,c.day_id,fd.label,fd.day_date FROM main_program_day_certificates c JOIN users u ON u.id=c.user_id LEFT JOIN function_room_days fd ON fd.id=c.day_id".$where;
    $sql .= $q!=='' ? " AND (u.name LIKE ? OR u.email LIKE ?) ORDER BY c.generated_at DESC LIMIT ?,?" : " ORDER BY c.generated_at DESC LIMIT ?,?";
    if(!$scope_id){ $sql = str_replace(' WHERE ',' WHERE ', str_replace(' AND (u.name',' WHERE (u.name', $sql)); }
    if($st=$db->prepare($sql)){
      if($q!==''){ $kw=likeWrap($q); $st->bind_param('ssii',$kw,$kw,$offset,$limit); }
      else { $st->bind_param('ii',$offset,$limit); }
      $st->execute(); $st->bind_result($id,$uid,$uname,$uemail,$fp,$gen,$dayid,$dlabel,$ddate);
      while($st->fetch()){
        $label = ($dlabel? $dlabel : ('Day #'.$dayid)); if($ddate) $label .= ' • '.$ddate;
        $rows[] = ['id'=>$id,'user_id'=>$uid,'user_name'=>$uname,'user_email'=>$uemail,'file_path'=>$fp,'generated_at'=>$gen,'type'=>'main_program_day','type_label'=>$type_label,'scope_label'=>$label,'day_id'=>$dayid];
      }
      $st->close();
    }
    break;

  case 'main_program_attendance':
    $type_label = 'Main Program — Attendance';
    if($q!==''){
      if($st=$db->prepare("SELECT COUNT(*) FROM main_program_attendance_certificates c JOIN users u ON u.id=c.user_id WHERE (u.name LIKE ? OR u.email LIKE ?)")){
        $kw=likeWrap($q); $st->bind_param('ss',$kw,$kw); $st->execute(); $st->bind_result($total); $st->fetch(); $st->close();
      }
    } else { if($r=$db->query("SELECT COUNT(*) FROM main_program_attendance_certificates")){ $total=(int)$r->fetch_row()[0]; } }
    if($q!==''){
      if($st=$db->prepare("SELECT c.id,c.user_id,u.name,u.email,c.file_path,c.generated_at FROM main_program_attendance_certificates c JOIN users u ON u.id=c.user_id WHERE (u.name LIKE ? OR u.email LIKE ?) ORDER BY c.generated_at DESC LIMIT ?,?")){
        $kw=likeWrap($q); $st->bind_param('ssii',$kw,$kw,$offset,$limit); $st->execute(); $st->bind_result($id,$uid,$uname,$uemail,$fp,$gen);
        while($st->fetch()){ $rows[]=['id'=>$id,'user_id'=>$uid,'user_name'=>$uname,'user_email'=>$uemail,'file_path'=>$fp,'generated_at'=>$gen,'type'=>'main_program_attendance','type_label'=>$type_label,'scope_label'=>'—']; }
        $st->close();
      }
    } else {
      if($st=$db->prepare("SELECT c.id,c.user_id,u.name,u.email,c.file_path,c.generated_at FROM main_program_attendance_certificates c JOIN users u ON u.id=c.user_id ORDER BY c.generated_at DESC LIMIT ?,?")){
        $st->bind_param('ii',$offset,$limit); $st->execute(); $st->bind_result($id,$uid,$uname,$uemail,$fp,$gen);
        while($st->fetch()){ $rows[]=['id'=>$id,'user_id'=>$uid,'user_name'=>$uname,'user_email'=>$uemail,'file_path'=>$fp,'generated_at'=>$gen,'type'=>'main_program_attendance','type_label'=>$type_label,'scope_label'=>'—']; }
        $st->close();
      }
    }
    break;

  case 'sub_department':
    $type_label = 'Sub Program — Department Completion';
    $where = '';
    if($scope_id){ $where = ' WHERE c.technical_department_id='.(int)$scope_id; }
    if($q!==''){
      $sqlCnt="SELECT COUNT(*) FROM sub_department_certificates c JOIN users u ON u.id=c.user_id".$where." AND (u.name LIKE ? OR u.email LIKE ?)"; if(!$scope_id){ $sqlCnt="SELECT COUNT(*) FROM sub_department_certificates c JOIN users u ON u.id=c.user_id WHERE (u.name LIKE ? OR u.email LIKE ?)"; }
      if($st=$db->prepare($sqlCnt)){ $kw=likeWrap($q); $st->bind_param('ss',$kw,$kw); $st->execute(); $st->bind_result($total); $st->fetch(); $st->close(); }
    } else { if($r=$db->query("SELECT COUNT(*) FROM sub_department_certificates c".($scope_id? (" WHERE c.technical_department_id=".(int)$scope_id):''))){ $total=(int)$r->fetch_row()[0]; } }
    $sql = "SELECT c.id,c.user_id,u.name,u.email,c.file_path,c.generated_at,c.technical_department_id,td.name FROM sub_department_certificates c JOIN users u ON u.id=c.user_id LEFT JOIN technical_departments td ON td.id=c.technical_department_id".$where;
    $sql .= $q!=='' ? " AND (u.name LIKE ? OR u.email LIKE ?) ORDER BY c.generated_at DESC LIMIT ?,?" : " ORDER BY c.generated_at DESC LIMIT ?,?";
    if(!$scope_id){ $sql = str_replace(' WHERE ',' WHERE ', str_replace(' AND (u.name',' WHERE (u.name', $sql)); }
    if($st=$db->prepare($sql)){
      if($q!==''){ $kw=likeWrap($q); $st->bind_param('ssii',$kw,$kw,$offset,$limit); } else { $st->bind_param('ii',$offset,$limit); }
      $st->execute(); $st->bind_result($id,$uid,$uname,$uemail,$fp,$gen,$deptId,$deptName);
      while($st->fetch()){
        $rows[]=['id'=>$id,'user_id'=>$uid,'user_name'=>$uname,'user_email'=>$uemail,'file_path'=>$fp,'generated_at'=>$gen,'type'=>'sub_department','type_label'=>$type_label,'scope_label'=>($deptName?:('Dept #'.$deptId)),'department_id'=>$deptId];
      }
      $st->close();
    }
    break;

  case 'sub_department_attendance':
    $type_label = 'Sub Program — Department Attendance';
    $where = '';
    if($scope_id){ $where = ' WHERE c.technical_department_id='.(int)$scope_id; }
    if($q!==''){
      $sqlCnt="SELECT COUNT(*) FROM sub_department_attendance_certificates c JOIN users u ON u.id=c.user_id".$where." AND (u.name LIKE ? OR u.email LIKE ?)"; if(!$scope_id){ $sqlCnt="SELECT COUNT(*) FROM sub_department_attendance_certificates c JOIN users u ON u.id=c.user_id WHERE (u.name LIKE ? OR u.email LIKE ?)"; }
      if($st=$db->prepare($sqlCnt)){ $kw=likeWrap($q); $st->bind_param('ss',$kw,$kw); $st->execute(); $st->bind_result($total); $st->fetch(); $st->close(); }
    } else { if($r=$db->query("SELECT COUNT(*) FROM sub_department_attendance_certificates c".($scope_id? (" WHERE c.technical_department_id=".(int)$scope_id):''))){ $total=(int)$r->fetch_row()[0]; } }
    $sql = "SELECT c.id,c.user_id,u.name,u.email,c.file_path,c.generated_at,c.technical_department_id,td.name FROM sub_department_attendance_certificates c JOIN users u ON u.id=c.user_id LEFT JOIN technical_departments td ON td.id=c.technical_department_id".$where;
    $sql .= $q!=='' ? " AND (u.name LIKE ? OR u.email LIKE ?) ORDER BY c.generated_at DESC LIMIT ?,?" : " ORDER BY c.generated_at DESC LIMIT ?,?";
    if(!$scope_id){ $sql = str_replace(' WHERE ',' WHERE ', str_replace(' AND (u.name',' WHERE (u.name', $sql)); }
    if($st=$db->prepare($sql)){
      if($q!==''){ $kw=likeWrap($q); $st->bind_param('ssii',$kw,$kw,$offset,$limit); } else { $st->bind_param('ii',$offset,$limit); }
      $st->execute(); $st->bind_result($id,$uid,$uname,$uemail,$fp,$gen,$deptId,$deptName);
      while($st->fetch()){
        $rows[]=['id'=>$id,'user_id'=>$uid,'user_name'=>$uname,'user_email'=>$uemail,'file_path'=>$fp,'generated_at'=>$gen,'type'=>'sub_department_attendance','type_label'=>$type_label,'scope_label'=>($deptName?:('Dept #'.$deptId)),'department_id'=>$deptId];
      }
      $st->close();
    }
    break;

  case 'sub_session':
    $type_label = 'Sub Program — Session';
    $where = '';
    if($scope_id){ $where = ' WHERE c.speaker_id='.(int)$scope_id; }
    if($q!==''){
      $sqlCnt="SELECT COUNT(*) FROM sub_session_certificates c JOIN users u ON u.id=c.user_id".$where." AND (u.name LIKE ? OR u.email LIKE ?)"; if(!$scope_id){ $sqlCnt="SELECT COUNT(*) FROM sub_session_certificates c JOIN users u ON u.id=c.user_id WHERE (u.name LIKE ? OR u.email LIKE ?)"; }
      if($st=$db->prepare($sqlCnt)){ $kw=likeWrap($q); $st->bind_param('ss',$kw,$kw); $st->execute(); $st->bind_result($total); $st->fetch(); $st->close(); }
    } else { if($r=$db->query("SELECT COUNT(*) FROM sub_session_certificates c".($scope_id? (" WHERE c.speaker_id=".(int)$scope_id):''))){ $total=(int)$r->fetch_row()[0]; } }
    $sql = "SELECT c.id,c.user_id,u.name,u.email,c.file_path,c.generated_at,c.speaker_id,s.name FROM sub_session_certificates c JOIN users u ON u.id=c.user_id LEFT JOIN speakers s ON s.id=c.speaker_id".$where;
    $sql .= $q!=='' ? " AND (u.name LIKE ? OR u.email LIKE ?) ORDER BY c.generated_at DESC LIMIT ?,?" : " ORDER BY c.generated_at DESC LIMIT ?,?";
    if(!$scope_id){ $sql = str_replace(' WHERE ',' WHERE ', str_replace(' AND (u.name',' WHERE (u.name', $sql)); }
    if($st=$db->prepare($sql)){
      if($q!==''){ $kw=likeWrap($q); $st->bind_param('ssii',$kw,$kw,$offset,$limit); } else { $st->bind_param('ii',$offset,$limit); }
      $st->execute(); $st->bind_result($id,$uid,$uname,$uemail,$fp,$gen,$spid,$spname);
      while($st->fetch()){
        $rows[]=['id'=>$id,'user_id'=>$uid,'user_name'=>$uname,'user_email'=>$uemail,'file_path'=>$fp,'generated_at'=>$gen,'type'=>'sub_session','type_label'=>$type_label,'scope_label'=>($spname?:('Speaker #'.$spid)),'speaker_id'=>$spid];
      }
      $st->close();
    }
    break;

  default:
    http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Unsupported type']); exit;
}

echo json_encode(['ok'=>true,'rows'=>$rows,'total'=>$total,'page'=>$page,'limit'=>$limit]);
?>
