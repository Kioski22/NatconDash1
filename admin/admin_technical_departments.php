<?php
session_start();
require_once __DIR__ . '/../db.php';
if (isset($conn) && $conn instanceof mysqli) { $db = $conn; } elseif (isset($mysqli) && $mysqli instanceof mysqli) { $db = $mysqli; } else { $db=null; }
if(!($db instanceof mysqli)){ http_response_code(500); echo 'DB unavailable'; exit; }
if (!isset($_SESSION['user_role']) || $_SESSION['user_role']!=='admin') { header('Location: admin_login.php'); exit; }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token']=bin2hex(random_bytes(16)); }
function e($s){ return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }

// Ensure table exists
$db->query("CREATE TABLE IF NOT EXISTS technical_departments (\n id INT AUTO_INCREMENT PRIMARY KEY,\n name VARCHAR(100) NOT NULL UNIQUE,\n description TEXT NULL,\n is_active TINYINT(1) NOT NULL DEFAULT 1,\n created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$errors=[];$success='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  $token=$_POST['csrf_token']??''; if(!hash_equals($_SESSION['csrf_token'],$token)){ $errors[]='Invalid CSRF token.'; }
  else {
    $action=$_POST['action']??'';
    if($action==='add'){
      $name=trim($_POST['name']??''); $desc=trim($_POST['description']??'');
      if($name==='') $errors[]='Name required';
      if(empty($errors)){
        $st=$db->prepare('INSERT INTO technical_departments(name,description) VALUES(?,?)');
        if($st){ $st->bind_param('ss',$name,$desc); if($st->execute()) $success='Department added.'; else $errors[]='Insert failed: '.$st->error; $st->close(); }
        else $errors[]='DB error: '.$db->error;
      }
    } elseif($action==='update'){
      $id=(int)($_POST['id']??0); $name=trim($_POST['name']??''); $desc=trim($_POST['description']??''); $active=isset($_POST['is_active'])?1:0;
      if($id<=0) $errors[]='Invalid id'; if($name==='') $errors[]='Name required';
      if(empty($errors)){
        $st=$db->prepare('UPDATE technical_departments SET name=?, description=?, is_active=? WHERE id=? LIMIT 1');
        if($st){ $st->bind_param('ssii',$name,$desc,$active,$id); if($st->execute()) $success='Department updated.'; else $errors[]='Update failed: '.$st->error; $st->close(); }
        else $errors[]='DB error: '.$db->error;
      }
    } elseif($action==='delete'){
      $id=(int)($_POST['id']??0); if($id<=0) $errors[]='Invalid id';
      if(empty($errors)){
        // If referenced by speakers, soft disable instead
        $ref=$db->prepare('SELECT COUNT(*) FROM speakers WHERE technical_department_id=?');
        if($ref){ $ref->bind_param('i',$id); $ref->execute(); $c=$ref->get_result()->fetch_row()[0]??0; $ref->close(); }
        if($c>0){ $db->query('UPDATE technical_departments SET is_active=0 WHERE id='.(int)$id); $success='Department disabled (in use by speakers).'; }
        else { $db->query('DELETE FROM technical_departments WHERE id='.(int)$id.' LIMIT 1'); $success='Department deleted.'; }
      }
    } else { $errors[]='Unknown action'; }
  }
  $_SESSION['csrf_token']=bin2hex(random_bytes(16));
  $_SESSION['techdept_messages']=['errors'=>$errors,'success'=>$success];
  header('Location: admin_technical_departments.php'); exit;
}
$msg=$_SESSION['techdept_messages']??null; if($msg){ $errors=$msg['errors']??[]; $success=$msg['success']??''; unset($_SESSION['techdept_messages']); }

$departments=[]; $res=$db->query('SELECT id,name,description,is_active,created_at FROM technical_departments ORDER BY name ASC');
if($res){ while($row=$res->fetch_assoc()) $departments[]=$row; }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Technical Departments</title><meta name="viewport" content="width=device-width,initial-scale=1"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"><style>body{background:#f8f9fa;} .badge-inactive{background:#adb5bd;} textarea{resize:vertical;}</style></head><body class="p-3">
<div class="container">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4>Technical Departments</h4>
    <a href="Admin_dashboard.php" class="btn btn-secondary btn-sm">Back</a>
  </div>
  <?php if(!empty($errors)): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>
  <div class="row">
    <div class="col-lg-7">
      <div class="card mb-3"><div class="card-body">
        <h6 class="card-title">Departments</h6>
        <?php if(empty($departments)): ?><div class="text-muted">No departments yet.</div><?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead><tr><th>#</th><th>Name</th><th>Description</th><th>Status</th><th></th></tr></thead>
              <tbody>
              <?php foreach($departments as $d): ?>
                <tr>
                  <td><?= (int)$d['id'] ?></td>
                  <td><?= e($d['name']) ?></td>
                  <td style="max-width:220px"><small><?= e($d['description']??'') ?></small></td>
                  <td><?= $d['is_active']?'<span class="badge bg-success">Active</span>':'<span class="badge badge-inactive">Disabled</span>' ?></td>
                  <td class="text-end">
                    <form method="post" class="d-inline">
                      <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                      <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete / disable this department?');">Delete</button>
                    </form>
                    <button class="btn btn-sm btn-primary" onclick='fillEdit(<?= (int)$d['id'] ?>,<?= json_encode($d['name']) ?>,<?= json_encode($d['description']) ?>,<?= (int)$d['is_active'] ?>)'>Edit</button>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div></div>
    </div>
    <div class="col-lg-5">
      <div class="card mb-3"><div class="card-body">
        <h6 class="card-title" id="formTitle">Add Department</h6>
        <form method="post" id="deptForm" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
          <input type="hidden" name="action" value="add" id="actionField">
          <input type="hidden" name="id" value="" id="idField">
          <div class="mb-2"><label class="form-label">Name</label><input name="name" class="form-control" required></div>
          <div class="mb-2"><label class="form-label">Description</label><textarea name="description" rows="3" class="form-control"></textarea></div>
          <div class="form-check mb-2 d-none" id="activeWrapper"><input class="form-check-input" type="checkbox" id="is_active" name="is_active" checked><label class="form-check-label" for="is_active">Active</label></div>
          <div class="d-grid gap-2"><button class="btn btn-success" id="submitBtn">Add Department</button><button type="button" class="btn btn-link" id="resetBtn">Reset</button></div>
        </form>
      </div></div>
    </div>
  </div>
</div>
<script>
function fillEdit(id,name,description,is_active){
  document.getElementById('formTitle').textContent='Edit Department #'+id;
  document.getElementById('actionField').value='update';
  document.getElementById('idField').value=id;
  document.querySelector('input[name="name"]').value=name||'';
  document.querySelector('textarea[name="description"]').value=description||'';
  document.getElementById('activeWrapper').classList.remove('d-none');
  document.getElementById('is_active').checked = !!is_active;
  document.getElementById('submitBtn').textContent='Update Department';
}
const resetBtn=document.getElementById('resetBtn');
if(resetBtn){ resetBtn.addEventListener('click',()=>{ location.href='admin_technical_departments.php'; }); }
</script>
</body></html>