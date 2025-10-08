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
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Technical Departments | 73rd PSME National Convention</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    /* CSS Custom Properties */
    :root {
      --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      --success-gradient: linear-gradient(135deg, #10b981 0%, #059669 100%);
      --warning-gradient: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
      --danger-gradient: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
      --info-gradient: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
      --shadow-soft: 0 2px 15px rgba(0,0,0,0.08);
      --shadow-hover: 0 8px 30px rgba(0,0,0,0.12);
      --border-radius: 12px;
    }
    
    body {
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
      background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
      min-height: 100vh;
    }
    
    .main-container {
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(10px);
      border-radius: var(--border-radius);
      box-shadow: var(--shadow-soft);
      margin-top: 2rem;
      margin-bottom: 2rem;
    }
    
    .page-header {
      background: var(--primary-gradient);
      color: white;
      padding: 2rem;
      border-radius: var(--border-radius) var(--border-radius) 0 0;
      position: relative;
      overflow: hidden;
    }
    
    .page-header::before {
      content: '';
      position: absolute;
      top: 0;
      right: 0;
      width: 100px;
      height: 100px;
      background: rgba(255, 255, 255, 0.1);
      border-radius: 50%;
      transform: translate(30px, -30px);
    }
    
    .page-title {
      margin: 0;
      font-weight: 600;
      font-size: 1.75rem;
    }
    
    .page-subtitle {
      margin: 0.5rem 0 0;
      opacity: 0.9;
      font-weight: 300;
    }
    
    .card {
      border: none;
      border-radius: var(--border-radius);
      box-shadow: var(--shadow-soft);
      transition: all 0.3s ease;
    }
    
    .card:hover {
      box-shadow: var(--shadow-hover);
      transform: translateY(-2px);
    }
    
    .card-header {
      background: rgba(248, 249, 250, 0.8);
      border-bottom: 1px solid rgba(0, 0, 0, 0.05);
      padding: 1.25rem;
      border-radius: var(--border-radius) var(--border-radius) 0 0;
    }
    
    .card-title {
      font-weight: 600;
      color: #2d3748;
      margin-bottom: 0;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    
    .btn {
      border-radius: 8px;
      font-weight: 500;
      transition: all 0.2s ease;
      border: none;
    }
    
    .btn-gradient-primary {
      background: var(--primary-gradient);
      color: white;
    }
    
    .btn-gradient-primary:hover {
      transform: translateY(-1px);
      box-shadow: var(--shadow-soft);
      color: white;
    }
    
    .btn-gradient-success {
      background: var(--success-gradient);
      color: white;
    }
    
    .btn-gradient-success:hover {
      transform: translateY(-1px);
      box-shadow: var(--shadow-soft);
      color: white;
    }
    
    .btn-gradient-warning {
      background: var(--warning-gradient);
      color: white;
    }
    
    .btn-gradient-warning:hover {
      transform: translateY(-1px);
      box-shadow: var(--shadow-soft);
      color: white;
    }
    
    .btn-gradient-danger {
      background: var(--danger-gradient);
      color: white;
    }
    
    .btn-gradient-danger:hover {
      transform: translateY(-1px);
      box-shadow: var(--shadow-soft);
      color: white;
    }
    
    .form-control:focus, .form-select:focus {
      border-color: #667eea;
      box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.15);
    }
    
    .table-hover tbody tr:hover {
      background-color: rgba(102, 126, 234, 0.05);
      transform: scale(1.005);
      transition: all 0.2s ease;
    }
    
    .badge {
      padding: 0.5rem 0.75rem;
      font-weight: 500;
      border-radius: 20px;
    }
    
    .badge-inactive {
      background: linear-gradient(135deg, #9ca3af 0%, #6b7280 100%);
      color: white;
    }
    
    .alert {
      border: none;
      border-radius: var(--border-radius);
      padding: 1rem 1.25rem;
    }
    
    .btn-group-actions {
      display: flex;
      gap: 0.25rem;
    }
    
    textarea {
      resize: vertical;
    }
    
    .form-check-input:checked {
      background-color: #10b981;
      border-color: #10b981;
    }
    
    .department-card {
      transition: all 0.3s ease;
      border: 1px solid rgba(0, 0, 0, 0.08);
    }
    
    .department-card:hover {
      transform: translateY(-3px);
      box-shadow: var(--shadow-hover);
    }
    
    .status-active {
      background: rgba(16, 185, 129, 0.1);
      color: #10b981;
      border: 1px solid rgba(16, 185, 129, 0.2);
    }
    
    .status-inactive {
      background: rgba(156, 163, 175, 0.1);
      color: #6b7280;
      border: 1px solid rgba(156, 163, 175, 0.2);
    }
  </style>
</head><body>
  <div class="container-fluid">
    <div class="main-container">
      <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <h1 class="page-title"><i class="bi bi-diagram-3-fill me-2"></i>Technical Departments</h1>
            <p class="page-subtitle">Manage technical departments for sub-program speakers</p>
          </div>
          <a href="Admin_dashboard.php" class="btn btn-light btn-lg">
            <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
          </a>
        </div>
      </div>
      
      <div class="p-4">
        <!-- Alerts -->
        <?php if (!empty($errors)): ?>
          <div class="alert alert-danger d-flex align-items-center">
            <i class="bi bi-exclamation-triangle-fill me-2 fs-4"></i>
            <div>
              <strong>Please fix the following issues:</strong>
              <ul class="mb-0 mt-1"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($success): ?>
          <div class="alert alert-success d-flex align-items-center">
            <i class="bi bi-check-circle-fill me-2 fs-4"></i>
            <div><?= e($success) ?></div>
          </div>
        <?php endif; ?>
        <!-- Departments List -->
        <div class="row">
          <div class="col-lg-8">
            <div class="card department-card">
              <div class="card-header">
                <div class="d-flex align-items-center">
                  <i class="bi bi-list-ul me-2 text-primary fs-5"></i>
                  <h5 class="card-title mb-0">Technical Departments</h5>
                  <span class="badge bg-secondary ms-auto"><?= count($departments) ?> departments</span>
                </div>
              </div>
              <div class="card-body p-0">
                <?php if (empty($departments)): ?>
                  <div class="text-center text-muted py-5">
                    <i class="bi bi-diagram-3 fs-1 mb-3 d-block text-light"></i>
                    <h6>No technical departments created yet</h6>
                    <p class="mb-0">Create your first department using the form.</p>
                  </div>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                      <thead class="table-light">
                        <tr>
                          <th class="fw-semibold">#</th>
                          <th class="fw-semibold"><i class="bi bi-diagram-3 me-1"></i>Name</th>
                          <th class="fw-semibold"><i class="bi bi-card-text me-1"></i>Description</th>
                          <th class="fw-semibold"><i class="bi bi-toggle-on me-1"></i>Status</th>
                          <th class="fw-semibold"><i class="bi bi-calendar me-1"></i>Created</th>
                          <th class="fw-semibold text-center">Actions</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($departments as $d): ?>
                          <tr>
                            <td class="text-muted"><?= (int)$d['id'] ?></td>
                            <td>
                              <div class="fw-medium"><?= e($d['name']) ?></div>
                            </td>
                            <td style="max-width: 250px;">
                              <?php if (!empty($d['description'])): ?>
                                <small class="text-muted"><?= e($d['description']) ?></small>
                              <?php else: ?>
                                <small class="text-muted fst-italic">No description</small>
                              <?php endif; ?>
                            </td>
                            <td>
                              <?php if ($d['is_active']): ?>
                                <span class="badge status-active d-flex align-items-center" style="width: fit-content;">
                                  <i class="bi bi-check-circle me-1"></i>Active
                                </span>
                              <?php else: ?>
                                <span class="badge status-inactive d-flex align-items-center" style="width: fit-content;">
                                  <i class="bi bi-x-circle me-1"></i>Inactive
                                </span>
                              <?php endif; ?>
                            </td>
                            <td class="small text-muted">
                              <?= date('M j, Y', strtotime($d['created_at'])) ?>
                            </td>
                            <td>
                              <div class="btn-group-actions justify-content-center">
                                <button class="btn btn-sm btn-gradient-primary" 
                                        onclick='fillEdit(<?= (int)$d['id'] ?>,<?= json_encode($d['name']) ?>,<?= json_encode($d['description']) ?>,<?= (int)$d['is_active'] ?>)'
                                        title="Edit Department">
                                  <i class="bi bi-pencil"></i>
                                </button>
                                <form method="post" class="d-inline">
                                  <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                  <input type="hidden" name="action" value="delete">
                                  <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                                  <button class="btn btn-sm btn-gradient-danger" 
                                          onclick="return confirm('Are you sure? This will delete or disable the department if it\'s in use by speakers.')"
                                          title="Delete Department">
                                    <i class="bi bi-trash"></i>
                                  </button>
                                </form>
                              </div>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <!-- Add/Edit Form -->
          <div class="col-lg-4">
            <div class="card department-card">
              <div class="card-header">
                <div class="d-flex align-items-center" id="formHeaderContent">
                  <i class="bi bi-plus-circle me-2 text-success fs-5"></i>
                  <h5 class="card-title mb-0" id="formTitle">Add Department</h5>
                </div>
              </div>
              <div class="card-body">
                <form method="post" id="deptForm" novalidate>
                  <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                  <input type="hidden" name="action" value="add" id="actionField">
                  <input type="hidden" name="id" value="" id="idField">
                  
                  <div class="mb-3">
                    <label class="form-label fw-semibold">
                      <i class="bi bi-diagram-3 me-1 text-primary"></i>Department Name
                    </label>
                    <input name="name" class="form-control" required placeholder="Enter department name">
                  </div>
                  
                  <div class="mb-3">
                    <label class="form-label fw-semibold">
                      <i class="bi bi-card-text me-1 text-secondary"></i>Description
                    </label>
                    <textarea name="description" rows="4" class="form-control" placeholder="Optional description of the department's role and responsibilities"></textarea>
                  </div>
                  
                  <div class="form-check mb-4 d-none" id="activeWrapper">
                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" checked>
                    <label class="form-check-label fw-semibold" for="is_active">
                      <i class="bi bi-toggle-on me-1 text-success"></i>Active Status
                    </label>
                    <small class="form-text text-muted d-block mt-1">
                      <i class="bi bi-info-circle me-1"></i>Inactive departments won't appear in speaker forms
                    </small>
                  </div>
                  
                  <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-gradient-success btn-lg" id="submitBtn">
                      <i class="bi bi-plus-lg me-2"></i>Add Department
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="resetBtn">
                      <i class="bi bi-arrow-clockwise me-2"></i>Reset Form
                    </button>
                  </div>
                </form>
              </div>
            </div>

            <!-- Help Card -->
            <div class="card department-card mt-3">
              <div class="card-body text-center">
                <i class="bi bi-lightbulb text-warning fs-3 mb-2"></i>
                <h6 class="fw-semibold mb-2">Department Guidelines</h6>
                <ul class="list-unstyled text-muted small">
                  <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i>Required for sub-program speakers</li>
                  <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i>Used for department-specific certificates</li>
                  <li class="mb-1"><i class="bi bi-check2 text-success me-2"></i>Can be disabled if referenced by speakers</li>
                </ul>
                <hr class="my-2">
                <div class="d-flex gap-1 justify-content-center">
                  <a href="admin_speaker_manage.php" class="btn btn-sm btn-gradient-primary">
                    <i class="bi bi-people me-1"></i>Speakers
                  </a>
                  <a href="admin_department_certificate_templates.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-award me-1"></i>Certificates
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Enhanced form handling with visual feedback
function fillEdit(id, name, description, is_active) {
  // Update form header
  const formHeaderContent = document.getElementById('formHeaderContent');
  formHeaderContent.innerHTML = `
    <i class="bi bi-pencil-square me-2 text-warning fs-5"></i>
    <h5 class="card-title mb-0">Edit Department</h5>
    <span class="badge bg-warning ms-auto">#${id}</span>
  `;
  
  // Update form fields
  document.getElementById('actionField').value = 'update';
  document.getElementById('idField').value = id;
  document.querySelector('input[name="name"]').value = name || '';
  document.querySelector('textarea[name="description"]').value = description || '';
  
  // Show and set active checkbox
  document.getElementById('activeWrapper').classList.remove('d-none');
  document.getElementById('is_active').checked = !!is_active;
  
  // Update submit button
  const submitBtn = document.getElementById('submitBtn');
  submitBtn.innerHTML = '<i class="bi bi-check-lg me-2"></i>Update Department';
  submitBtn.className = 'btn btn-gradient-warning btn-lg';
  
  // Add visual feedback
  const card = document.querySelector('.col-lg-4 .card');
  card.style.transform = 'scale(1.02)';
  setTimeout(() => {
    card.style.transform = 'scale(1)';
  }, 200);
  
  // Scroll to form
  card.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// Reset form function
function resetForm() {
  // Reset form header
  const formHeaderContent = document.getElementById('formHeaderContent');
  formHeaderContent.innerHTML = `
    <i class="bi bi-plus-circle me-2 text-success fs-5"></i>
    <h5 class="card-title mb-0">Add Department</h5>
  `;
  
  // Reset form fields
  document.getElementById('actionField').value = 'add';
  document.getElementById('idField').value = '';
  document.querySelector('input[name="name"]').value = '';
  document.querySelector('textarea[name="description"]').value = '';
  
  // Hide active checkbox
  document.getElementById('activeWrapper').classList.add('d-none');
  document.getElementById('is_active').checked = true;
  
  // Reset submit button
  const submitBtn = document.getElementById('submitBtn');
  submitBtn.innerHTML = '<i class="bi bi-plus-lg me-2"></i>Add Department';
  submitBtn.className = 'btn btn-gradient-success btn-lg';
}

// Event listeners
document.addEventListener('DOMContentLoaded', function() {
  const resetBtn = document.getElementById('resetBtn');
  if (resetBtn) {
    resetBtn.addEventListener('click', resetForm);
  }
  
  // Form validation feedback
  const form = document.getElementById('deptForm');
  const nameInput = document.querySelector('input[name="name"]');
  
  if (form && nameInput) {
    form.addEventListener('submit', function(e) {
      if (!nameInput.value.trim()) {
        e.preventDefault();
        nameInput.classList.add('is-invalid');
        showToast('Department name is required', 'error');
      } else {
        nameInput.classList.remove('is-invalid');
      }
    });
    
    nameInput.addEventListener('input', function() {
      if (this.value.trim()) {
        this.classList.remove('is-invalid');
      }
    });
  }
  
  // Animate cards on load
  const cards = document.querySelectorAll('.card');
  cards.forEach((card, index) => {
    card.style.opacity = '0';
    card.style.transform = 'translateY(20px)';
    
    setTimeout(() => {
      card.style.transition = 'all 0.5s ease';
      card.style.opacity = '1';
      card.style.transform = 'translateY(0)';
    }, index * 100);
  });
});

// Toast notification system
function showToast(message, type) {
  // Create toast container if it doesn't exist
  let toastContainer = document.getElementById('toast-container');
  if (!toastContainer) {
    toastContainer = document.createElement('div');
    toastContainer.id = 'toast-container';
    toastContainer.className = 'position-fixed top-0 end-0 p-3';
    toastContainer.style.zIndex = '1050';
    document.body.appendChild(toastContainer);
  }
  
  // Create toast
  const toast = document.createElement('div');
  toast.className = `alert alert-${type === 'success' ? 'success' : 'danger'} alert-dismissible fade show`;
  toast.innerHTML = `
    <i class="bi bi-${type === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'} me-2"></i>
    ${message}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  `;
  
  toastContainer.appendChild(toast);
  
  // Auto remove after 3 seconds
  setTimeout(() => {
    if (toast.parentNode) {
      toast.classList.remove('show');
      setTimeout(() => toast.remove(), 150);
    }
  }, 3000);
}
</script>
</body></html>