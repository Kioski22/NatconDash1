<?php
session_start();
require_once __DIR__ . '/../db.php';

// Normalize DB connection
if (isset($conn) && $conn instanceof mysqli) { $db = $conn; }
elseif (isset($mysqli) && $mysqli instanceof mysqli) { $db = $mysqli; }
else { $db = null; }
if (!($db instanceof mysqli)) { http_response_code(500); echo 'Database connection not available.'; exit; }

// Auth guard: only admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: admin_login.php');
    exit;
}

// CSRF token for API calls
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];
$admin_name = $_SESSION['user_name'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Users Management</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: #667eea;
      --primary-dark: #5a6fd8;
      --primary-light: rgba(102, 126, 234, 0.1);
      --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      --success: #10b981;
      --success-light: rgba(16, 185, 129, 0.1);
      --danger: #ef4444;
      --danger-light: rgba(239, 68, 68, 0.1);
      --warning: #f59e0b;
      --warning-light: rgba(245, 158, 11, 0.1);
      --bg-primary: #ffffff;
      --bg-secondary: #f8fafc;
      --bg-tertiary: #f1f5f9;
      --text-primary: #1e293b;
      --text-secondary: #64748b;
      --text-tertiary: #94a3b8;
      --border-color: #e2e8f0;
      --border-light: #cbd5e1;
      --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
      --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
      --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
      --radius-sm: 0.375rem;
      --radius-md: 0.5rem;
      --radius-lg: 0.75rem;
      --transition-fast: 0.15s ease-in-out;
      --transition-normal: 0.3s ease-in-out;
    }

    body {
      background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
      color: var(--text-primary);
      min-height: 100vh;
    }

    .sidebar {
      background: var(--bg-primary);
      border-right: 1px solid var(--border-color);
      min-height: 100vh;
      box-shadow: var(--shadow-sm);
    }

    .sidebar .nav-link {
      color: var(--text-secondary);
      border-radius: var(--radius-md);
      margin: 2px 12px;
      padding: 10px 16px;
      transition: all var(--transition-fast);
      font-weight: 500;
      font-size: 14px;
      border: 1px solid transparent;
      display: flex;
      align-items: center;
    }

    .sidebar .nav-link:hover {
      color: var(--text-primary);
      background: var(--bg-tertiary);
      border-color: var(--border-light);
      text-decoration: none;
    }

    .sidebar .nav-link.active {
      color: var(--primary);
      background: linear-gradient(135deg, var(--primary-light), rgba(59, 130, 246, 0.1));
      border-color: rgba(59, 130, 246, 0.2);
      font-weight: 600;
    }

    .sidebar .nav-link i { 
      margin-right: 12px; 
      width: 16px;
      font-size: 16px;
      text-align: center;
    }

    .welcome-section {
      background: var(--primary-gradient);
      border-radius: var(--radius-lg);
      padding: 20px;
      margin: 16px;
      color: white;
      box-shadow: var(--shadow-md);
      position: relative;
      overflow: hidden;
    }
    
    .welcome-section::before {
      content: '';
      position: absolute;
      top: 0;
      right: 0;
      width: 80px;
      height: 80px;
      background: rgba(255, 255, 255, 0.1);
      border-radius: 50%;
      transform: translate(25px, -25px);
    }

    .page-header {
      background: var(--bg-primary);
      border: 1px solid var(--border-color);
      border-radius: var(--radius-lg);
      padding: 24px;
      margin-bottom: 24px;
      box-shadow: var(--shadow-sm);
    }

    .page-title {
      color: var(--text-primary);
      font-weight: 700;
      font-size: 1.875rem;
      line-height: 1.2;
      margin: 0 0 4px 0;
    }

    .card {
      background: var(--bg-primary);
      border: 1px solid var(--border-color);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-sm);
      transition: all var(--transition-normal);
    }

    .card:hover {
      box-shadow: var(--shadow-md);
    }

    .table-card {
      box-shadow: var(--shadow-md);
    }

    .table {
      border-radius: var(--radius-md);
      overflow: hidden;
      background: var(--bg-primary) !important;
      border: none;
      margin: 0;
    }

    .table thead th {
      background: var(--bg-secondary) !important;
      color: var(--text-primary) !important;
      border: none;
      font-weight: 600;
      text-transform: uppercase;
      font-size: 11px;
      letter-spacing: 0.05em;
      padding: 14px 16px;
      border-bottom: 2px solid var(--border-light) !important;
      position: sticky;
      top: 0;
      z-index: 10;
    }

    .table tbody tr {
      transition: all var(--transition-fast);
      border: none;
      background: var(--bg-primary) !important;
    }

    .table tbody tr:hover {
      background: var(--bg-tertiary) !important;
      transform: translateX(2px);
    }

    .table tbody td {
      border-color: var(--border-light) !important;
      padding: 14px 16px;
      color: var(--text-primary) !important;
      vertical-align: middle;
    }

    .badge {
      font-weight: 600;
      font-size: 11px;
      padding: 4px 10px;
      border-radius: 12px;
      letter-spacing: 0.02em;
    }

    .badge-soft {
      background: var(--bg-tertiary);
      color: var(--text-secondary);
      border: 1px solid var(--border-light);
    }

    .badge.bg-success {
      background: var(--success) !important;
      box-shadow: 0 2px 4px rgba(16, 185, 129, 0.2);
    }

    .badge.bg-danger {
      background: var(--danger) !important;
      box-shadow: 0 2px 4px rgba(239, 68, 68, 0.2);
    }

    .btn {
      border-radius: var(--radius-md);
      font-weight: 500;
      font-size: 14px;
      padding: 8px 16px;
      transition: all var(--transition-fast);
      border: 1px solid transparent;
    }

    .btn:hover {
      transform: translateY(-1px);
      box-shadow: var(--shadow-md);
    }

    .btn-primary {
      background: var(--primary-gradient);
      border: none;
      color: white;
    }

    .btn-primary:hover {
      background: var(--primary-gradient);
      transform: translateY(-2px);
      box-shadow: var(--shadow-lg);
    }

    .btn-outline-primary {
      color: var(--primary);
      border-color: var(--primary);
    }

    .btn-outline-primary:hover {
      background: var(--primary-gradient);
      border: none;
      color: white;
    }

    .btn-outline-secondary {
      color: var(--text-secondary);
      border-color: var(--border-light);
    }

    .btn-outline-secondary:hover {
      background: var(--text-secondary);
      color: white;
      border-color: var(--text-secondary);
    }

    .btn-outline-danger {
      color: var(--danger);
      border-color: var(--danger);
    }

    .btn-outline-danger:hover {
      background: var(--danger);
      color: white;
    }

    .form-control, .form-select {
      border: 1px solid var(--border-color);
      border-radius: var(--radius-md);
      padding: 8px 12px;
      transition: all var(--transition-fast);
    }

    .form-control:focus, .form-select:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px var(--primary-light);
    }

    .input-group-text {
      border: 1px solid var(--border-color);
      background: var(--bg-secondary);
      color: var(--text-secondary);
    }

    .pagination {
      gap: 4px;
    }

    .page-link {
      border: 1px solid var(--border-light);
      color: var(--text-secondary);
      border-radius: var(--radius-sm);
      padding: 6px 12px;
      transition: all var(--transition-fast);
    }

    .page-link:hover {
      background: var(--primary-light);
      border-color: var(--primary);
      color: var(--primary);
    }

    .page-item.active .page-link {
      background: var(--primary-gradient);
      border: none;
      color: white;
      box-shadow: var(--shadow-sm);
    }

    .page-item.disabled .page-link {
      background: var(--bg-tertiary);
      border-color: var(--border-light);
      color: var(--text-tertiary);
    }

    .modal-content {
      border: none;
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-lg);
    }

    .modal-header {
      background: var(--bg-secondary);
      border-bottom: 1px solid var(--border-color);
      border-radius: var(--radius-lg) var(--radius-lg) 0 0;
      padding: 20px 24px;
    }

    .modal-title {
      font-weight: 700;
      color: var(--text-primary);
    }

    .modal-body {
      padding: 24px;
    }

    .modal-footer {
      background: var(--bg-secondary);
      border-top: 1px solid var(--border-color);
      border-radius: 0 0 var(--radius-lg) var(--radius-lg);
      padding: 16px 24px;
    }

    .stats-card {
      background: linear-gradient(135deg, var(--primary-light), rgba(59, 130, 246, 0.05));
      border: 1px solid rgba(102, 126, 234, 0.2);
      border-radius: var(--radius-lg);
      padding: 16px 20px;
    }

    .stat-number {
      font-size: 2rem;
      font-weight: 700;
      background: var(--primary-gradient);
      background-clip: text;
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      line-height: 1;
    }

    .fade-in {
      animation: fadeIn 0.5s ease-out;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .cursor-pointer { cursor: pointer; }

    @media (max-width: 768px) {
      .page-header { padding: 16px; margin-bottom: 16px; }
      .page-title { font-size: 1.5rem; }
      .table tbody tr:hover { transform: none; }
    }
  </style>
  <meta name="csrf-token" content="<?= htmlspecialchars($csrf) ?>">
  <script>
    window.ADMIN_CSRF = '<?= htmlspecialchars($csrf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>';
  </script>
  </head>
<body>
  <div class="container-fluid">
    <div class="row">
      <nav class="col-md-3 col-lg-2 d-md-block sidebar collapse p-0">
        <div class="welcome-section">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <div class="text-white-50 small">Welcome back,</div>
              <div class="text-white fw-bold"><?= htmlspecialchars($admin_name) ?></div>
            </div>
            <i class="bi bi-person-circle text-white" style="font-size: 2rem; opacity: 0.8;"></i>
          </div>
        </div>
        <ul class="nav flex-column px-2">
          <li class="nav-item"><a class="nav-link" href="Admin_dashboard.php"><i class="bi bi-speedometer2"></i>Dashboard</a></li>
          <li class="nav-item"><a class="nav-link active" href="admin_users.php"><i class="bi bi-people"></i>Users</a></li>
          <li class="nav-item"><a class="nav-link" href="admin_speaker_manage.php"><i class="bi bi-person-video3"></i>Speakers</a></li>
          <li class="nav-item"><a class="nav-link" href="admin_quiz_manage.php"><i class="bi bi-ui-checks-grid"></i>Quizzes</a></li>
          <li class="nav-item"><a class="nav-link" href="admin_certificate_settings.php"><i class="bi bi-award"></i>Certificates</a></li>
          <li class="nav-item mt-3"><a class="nav-link text-secondary" href="admin_logout.php"><i class="bi bi-box-arrow-right"></i>Logout</a></li>
        </ul>
      </nav>

      <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-3">
        <div class="page-header fade-in">
          <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center">
            <div>
              <h1 class="page-title">Users Management</h1>
              <p class="text-secondary mb-0">View, update, and manage delegate accounts</p>
            </div>
            <div class="d-flex gap-2">
              <button class="btn btn-outline-secondary" id="btn-refresh">
                <i class="bi bi-arrow-clockwise me-1"></i>Refresh
              </button>
              <button class="btn btn-outline-primary" id="btn-export">
                <i class="bi bi-download me-1"></i>Export CSV
              </button>
            </div>
          </div>
        </div>

        <div class="card mb-3 fade-in">
          <div class="card-body">
            <div class="row g-3 align-items-center">
              <div class="col-sm-8 col-lg-6">
                <div class="input-group">
                  <span class="input-group-text"><i class="bi bi-search"></i></span>
                  <input type="text" id="search" class="form-control" placeholder="Search by name or email...">
                </div>
              </div>
              <div class="col-sm-4 col-lg-3">
                <select id="perPage" class="form-select">
                  <option value="10">10 per page</option>
                  <option value="20" selected>20 per page</option>
                  <option value="50">50 per page</option>
                  <option value="100">100 per page</option>
                </select>
              </div>
              <div class="col-12 col-lg-3">
                <div class="stats-card text-center">
                  <div class="small text-secondary mb-1 fw-semibold">Total Users</div>
                  <div class="stat-number" id="totalUsersCount">-</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="card table-card fade-in">
          <div class="table-responsive" style="max-height: 60vh;">
            <table class="table table-hover align-middle mb-0" id="usersTable">
              <thead>
                <tr>
                  <th style="width:80px;">ID</th>
                  <th>Name</th>
                  <th>Email</th>
                  <th class="text-center" style="width:110px;">Attendance</th>
                  <th class="text-center" style="width:110px;">Appearance</th>
                  <th class="text-center" style="width:90px;">CPD</th>
                  <th style="width:150px;">Updated</th>
                  <th style="width:140px;" class="text-center">Actions</th>
                </tr>
              </thead>
              <tbody></tbody>
            </table>
          </div>
          <div class="card-body border-top">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
              <div class="small text-secondary" id="resultsInfo">&nbsp;</div>
              <nav>
                <ul class="pagination pagination-sm mb-0" id="pagination"></ul>
              </nav>
            </div>
          </div>
        </div>

      </main>
    </div>
  </div>

  <!-- Edit Modal -->
  <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit User</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form id="editForm">
            <input type="hidden" id="editId">
            <div class="mb-3">
              <label class="form-label fw-semibold small text-secondary">Full Name</label>
              <input type="text" class="form-control" id="editName" required placeholder="Enter full name">
            </div>
            <div class="mb-3">
              <label class="form-label fw-semibold small text-secondary">Email Address</label>
              <input type="email" class="form-control" id="editEmail" required placeholder="user@example.com">
            </div>
            
            <div class="mb-3">
              <label class="form-label fw-semibold small text-secondary d-block mb-2">
                <i class="bi bi-shield-check me-1"></i>Certificate Eligibility Flags
              </label>
              <div class="row g-2">
                <div class="col-4">
                  <label class="form-label small">Attendance</label>
                  <select id="editEligibleAttendance" class="form-select">
                    <option value="0">No</option>
                    <option value="1">Yes</option>
                  </select>
                </div>
                <div class="col-4">
                  <label class="form-label small">Appearance</label>
                  <select id="editEligibleAppearance" class="form-select">
                    <option value="0">No</option>
                    <option value="1">Yes</option>
                  </select>
                </div>
                <div class="col-4">
                  <label class="form-label small">CPD</label>
                  <select id="editEligibleCPD" class="form-select">
                    <option value="0">No</option>
                    <option value="1">Yes</option>
                  </select>
                </div>
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold small text-secondary">
                <i class="bi bi-key me-1"></i>Reset Password <span class="text-muted fw-normal">(optional)</span>
              </label>
              <input type="password" class="form-control" id="editPassword" placeholder="Leave blank to keep current password">
              <small class="text-secondary d-block mt-1">Only fill this if you want to change the user's password</small>
            </div>

            <div class="mb-2">
              <label class="form-label fw-semibold small text-secondary">
                <i class="bi bi-journal-text me-1"></i>Admin Note <span class="text-muted fw-normal">(optional)</span>
              </label>
              <textarea id="editNote" class="form-control" rows="2" placeholder="Internal notes about eligibility or account status"></textarea>
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
            <i class="bi bi-x-circle me-1"></i>Cancel
          </button>
          <button type="button" class="btn btn-primary" id="btnSave">
            <i class="bi bi-check-circle me-1"></i>Save Changes
          </button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const $ = (sel, ctx=document) => ctx.querySelector(sel);
    const $$ = (sel, ctx=document) => Array.from(ctx.querySelectorAll(sel));

    let state = { page: 1, perPage: 20, q: '' };

    function badge(val) {
      const cls = Number(val) === 1 ? 'bg-success' : 'badge-soft';
      const text = Number(val) === 1 ? 'Yes' : 'No';
      return `<span class="badge ${cls}">${text}</span>`;
    }

    async function fetchUsers() {
      const params = new URLSearchParams({ page: String(state.page), per_page: String(state.perPage), q: state.q });
      const res = await fetch(`../api/admin_users_list.php?${params}`, { headers: { 'X-CSRF-Token': window.ADMIN_CSRF }});
      const data = await res.json();
      if (!data.ok) { throw new Error(data.error || 'Failed to load'); }
      renderTable(data);
    }

    function renderTable(data) {
      const tbody = $('#usersTable tbody');
      tbody.innerHTML = '';
      
      // Update total count
      $('#totalUsersCount').textContent = String(data.total);
      
      for (const u of data.data) {
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td class="text-muted fw-semibold">#${u.id}</td>
          <td>
            <div class="d-flex align-items-center">
              <div class="avatar-sm bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-2" style="width:32px;height:32px;font-size:14px;font-weight:600;">
                ${escapeHtml((u.name || 'U')[0].toUpperCase())}
              </div>
              <div>
                <div class="fw-semibold">${escapeHtml(u.name || '')}</div>
              </div>
            </div>
          </td>
          <td><span class="text-secondary">${escapeHtml(u.email || '')}</span></td>
          <td class="text-center">${badge(u.eligible_certificate_attendance)}</td>
          <td class="text-center">${badge(u.eligible_certificate_appearance)}</td>
          <td class="text-center">${badge(u.eligible_cpd_certificate)}</td>
          <td><small class="text-secondary">${u.eligibility_updated_at ? new Date(u.eligibility_updated_at).toLocaleString('en-US', {month:'short', day:'numeric', year:'numeric', hour:'2-digit', minute:'2-digit'}) : '-'}</small></td>
          <td class="text-center">
            <div class="btn-group btn-group-sm" role="group">
              <button class="btn btn-outline-primary" data-action="edit" data-id="${u.id}" title="Edit user">
                <i class="bi bi-pencil-square"></i>
              </button>
              <button class="btn btn-outline-danger" data-action="delete" data-id="${u.id}" title="Delete user">
                <i class="bi bi-trash"></i>
              </button>
            </div>
          </td>`;
        tbody.appendChild(tr);
      }

      // results info
      const start = data.data.length > 0 ? ((data.page - 1) * state.perPage) + 1 : 0;
      const end = Math.min(start + data.data.length - 1, data.total);
      $('#resultsInfo').textContent = `Showing ${start}–${end} of ${data.total} users`;

      // pagination
      renderPagination(data.page, data.pages);
    }

    function renderPagination(page, pages) {
      const ul = $('#pagination');
      ul.innerHTML = '';
      function add(label, p, disabled=false, active=false) {
        const li = document.createElement('li');
        li.className = `page-item ${disabled? 'disabled':''} ${active? 'active':''}`;
        const a = document.createElement('a'); a.className='page-link'; a.href='#'; a.textContent=label; a.addEventListener('click', e=>{ e.preventDefault(); if(!disabled){ state.page=p; fetchUsers().catch(alert); }});
        li.appendChild(a); ul.appendChild(li);
      }
      add('«', 1, page<=1);
      add('‹', Math.max(1, page-1), page<=1);
      const start = Math.max(1, page-2), end=Math.min(pages, page+2);
      for (let p=start; p<=end; p++) add(String(p), p, false, p===page);
      add('›', Math.min(pages, page+1), page>=pages);
      add('»', pages, page>=pages);
    }

    function escapeHtml(str) {
      return (str||'').replace(/[&<>"]/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[s]));
    }

    document.addEventListener('click', async (e) => {
      const btn = e.target.closest('button[data-action]');
      if (!btn) return;
      const id = Number(btn.getAttribute('data-id'));
      const action = btn.getAttribute('data-action');
      if (action === 'edit') {
        openEdit(id);
      } else if (action === 'delete') {
        if (confirm('Delete this user? This cannot be undone.')) {
          await deleteUser(id);
          await fetchUsers();
        }
      }
    });

    async function deleteUser(id) {
      const res = await fetch('../api/admin_user_delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.ADMIN_CSRF },
        body: JSON.stringify({ id })
      });
      const out = await res.json();
      if (!out.ok) {
        alert('❌ ' + (out.error || 'Delete failed'));
      } else {
        // Show success feedback
        const tempDiv = document.createElement('div');
        tempDiv.className = 'alert alert-success position-fixed top-0 start-50 translate-middle-x mt-3';
        tempDiv.style.zIndex = '9999';
        tempDiv.innerHTML = '<i class="bi bi-check-circle me-2"></i>User deleted successfully';
        document.body.appendChild(tempDiv);
        setTimeout(() => tempDiv.remove(), 2000);
      }
    }

    async function openEdit(id) {
      // find in current table data (quick) or fetch single
      const row = $('#usersTable tbody').querySelector(`button[data-action="edit"][data-id="${id}"]`)?.closest('tr');
      if (!row) return;
      $('#editId').value = String(id);
      $('#editName').value = row.children[1].textContent.trim();
      $('#editEmail').value = row.children[2].textContent.trim();
      $('#editEligibleAttendance').value = row.children[3].textContent.includes('Yes') ? '1' : '0';
      $('#editEligibleAppearance').value = row.children[4].textContent.includes('Yes') ? '1' : '0';
      $('#editEligibleCPD').value = row.children[5].textContent.includes('Yes') ? '1' : '0';
      $('#editPassword').value = '';
      $('#editNote').value = '';
      const modal = new bootstrap.Modal(document.getElementById('editModal'));
      modal.show();
    }

    $('#btnSave').addEventListener('click', async () => {
      const payload = {
        id: Number($('#editId').value),
        name: $('#editName').value.trim(),
        email: $('#editEmail').value.trim(),
        eligible_certificate_attendance: Number($('#editEligibleAttendance').value),
        eligible_certificate_appearance: Number($('#editEligibleAppearance').value),
        eligible_cpd_certificate: Number($('#editEligibleCPD').value),
        password: $('#editPassword').value,
        eligibility_note: $('#editNote').value.trim()
      };
      
      // Validation
      if (!payload.name || !payload.email) {
        alert('❌ Name and email are required');
        return;
      }
      
      const res = await fetch('../api/admin_user_update.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.ADMIN_CSRF },
        body: JSON.stringify(payload)
      });
      const out = await res.json();
      if (!out.ok) { 
        alert('❌ ' + (out.error || 'Update failed')); 
        return; 
      }
      
      // Success feedback
      const tempDiv = document.createElement('div');
      tempDiv.className = 'alert alert-success position-fixed top-0 start-50 translate-middle-x mt-3';
      tempDiv.style.zIndex = '9999';
      tempDiv.innerHTML = '<i class="bi bi-check-circle me-2"></i>User updated successfully';
      document.body.appendChild(tempDiv);
      setTimeout(() => tempDiv.remove(), 2000);
      
      bootstrap.Modal.getInstance(document.getElementById('editModal'))?.hide();
      await fetchUsers();
    });

    $('#btn-refresh').addEventListener('click', () => { fetchUsers().catch(alert); });
    $('#btn-export').addEventListener('click', () => {
      const params = new URLSearchParams({ q: state.q || '', csrf: window.ADMIN_CSRF, format: 'csv' });
      const url = `../api/admin_users_export.php?${params.toString()}`;
      window.open(url, '_blank');
    });
    $('#perPage').addEventListener('change', () => { state.perPage = Number($('#perPage').value)||20; state.page=1; fetchUsers().catch(alert); });
    $('#search').addEventListener('input', (e) => { state.q = e.target.value.trim(); state.page=1; fetchUsers().catch(()=>{}); });

    // initial load
    fetchUsers().catch(alert);
  </script>
</body>
</html>
