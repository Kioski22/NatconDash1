<?php
session_start();
require_once __DIR__ . '/../db.php';

// Normalize DB connection
if (isset($conn) && $conn instanceof mysqli) { $db = $conn; }
elseif (isset($mysqli) && $mysqli instanceof mysqli) { $db = $mysqli; }
else { $db = null; }
if (!($db instanceof mysqli)) { http_response_code(500); echo "Database connection not available."; exit; }

// Authn/authz
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') { header('Location: admin_login.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

// Helper: prefetch options for scopes (days, departments, speakers)
function fetch_days(mysqli $db): array { $rows=[]; if($r=$db->query("SELECT id,label,day_date FROM function_room_days ORDER BY day_date ASC")){ while($row=$r->fetch_assoc()) $rows[]=$row; $r->close(); } return $rows; }
function fetch_departments(mysqli $db): array { $rows=[]; if($r=$db->query("SELECT id,name FROM technical_departments ORDER BY name ASC")){ while($row=$r->fetch_assoc()) $rows[]=$row; $r->close(); } return $rows; }
function fetch_sub_speakers(mysqli $db): array { $rows=[]; if($r=$db->query("SELECT id,name,title FROM speakers WHERE program_type='sub' ORDER BY name ASC")){ while($row=$r->fetch_assoc()) $rows[]=$row; $r->close(); } return $rows; }

$days = fetch_days($db);
$departments = fetch_departments($db);
$subSpeakers = fetch_sub_speakers($db);

// Build absolute-ish URLs for API endpoints based on current script path to handle subfolder deployments (e.g., /Testing/...)
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/'); // e.g., /NatconDash1/admin or /Testing/admin
// parent of /admin
$parentDir = preg_replace('#/[^/]+$#', '', $scriptDir); // e.g., /NatconDash1 or /Testing
if ($parentDir === '') { $parentDir = '/'; }
$apiListUrl = rtrim($parentDir, '/') . '/api/admin_certificates_list.php';
$apiDeleteUrl = rtrim($parentDir, '/') . '/api/admin_certificate_delete.php';
$apiDeleteAllUrl = rtrim($parentDir, '/') . '/api/admin_certificates_delete_all.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Issued Certificates — Admin</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background:#f6f7fb; font-family:system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial, "Noto Sans", "Apple Color Emoji", "Segoe UI Emoji"; }
    .card { border-radius:12px; }
    .toolbar .form-select, .toolbar .form-control { border-radius:10px; }
    .table thead th { font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
    .badge-soft { background:#eef2ff; color:#4f46e5; border:1px solid #e0e7ff; }
  </style>
  <script>
    const CSRF = <?= json_encode($csrf) ?>;
    const API_LIST_URL = <?= json_encode($apiListUrl) ?>; // e.g., /Testing/api/admin_certificates_list.php
    const API_DELETE_URL = <?= json_encode($apiDeleteUrl) ?>;
    const API_DELETE_ALL_URL = <?= json_encode($apiDeleteAllUrl) ?>;
  </script>
  <script>
  document.addEventListener('DOMContentLoaded',()=>{
    const typeSel = document.getElementById('certType');
    const scopeWraps = {
      day: document.getElementById('scope-day'),
      dept: document.getElementById('scope-dept'),
      speaker: document.getElementById('scope-speaker')
    };
    const searchInp = document.getElementById('search');
    const tbody = document.getElementById('resultsBody');
    const pager = document.getElementById('pager');

    function updateScopeVisibility(){
      const t = typeSel.value;
      scopeWraps.day.classList.add('d-none');
      scopeWraps.dept.classList.add('d-none');
      scopeWraps.speaker.classList.add('d-none');
      if(t==='main_program_day') scopeWraps.day.classList.remove('d-none');
      else if(t==='sub_department' || t==='sub_department_attendance') scopeWraps.dept.classList.remove('d-none');
      else if(t==='sub_session') scopeWraps.speaker.classList.remove('d-none');
    }

    function getScopeParams(){
      const t = typeSel.value; let scopeId=null;
      if(t==='main_program_day') scopeId = document.getElementById('dayId').value || null;
      if(t==='sub_department' || t==='sub_department_attendance') scopeId = document.getElementById('deptId').value || null;
      if(t==='sub_session') scopeId = document.getElementById('speakerId').value || null;
      return { type: t, scope_id: scopeId };
    }

    async function loadPage(page=1){
      const { type, scope_id } = getScopeParams();
      const q = searchInp.value.trim();
      // Build primary and fallback URLs to be resilient to subfolder deployments and CDN rewrites
      const urlPrimary = new URL(API_LIST_URL, window.location.origin);
      const urlFallback = new URL('../api/admin_certificates_list.php', window.location.href);
      let url = urlPrimary;
      url.searchParams.set('type', type);
      if(scope_id) url.searchParams.set('scope_id', scope_id);
      if(q) url.searchParams.set('q', q);
      url.searchParams.set('page', String(page));
      url.searchParams.set('limit', '25');
      tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></td></tr>';
      try{
        let r = await fetch(url.toString(), { credentials:'same-origin' });
        let j = await r.json().catch(()=>null);
        if(!r.ok || !j || !j.ok){
          // Try fallback once if 404 or network
          const tried = url.toString();
          url = urlFallback;
          url.searchParams.set('type', type);
          if(scope_id) url.searchParams.set('scope_id', scope_id);
          if(q) url.searchParams.set('q', q);
          url.searchParams.set('page', String(page));
          url.searchParams.set('limit', '25');
          r = await fetch(url.toString(), { credentials:'same-origin' });
          j = await r.json().catch(()=>null);
          if(!r.ok || !j || !j.ok){
            tbody.innerHTML = '<tr><td colspan="6" class="text-danger">Failed to load ('+(j && j.error ? j.error : r.status)+')'+
              '<div class="small text-muted mt-1">Tried: '+tried+'; Fallback: '+url.toString()+'</div></td></tr>';
            pager.textContent='';
            return;
          }
        }
        const rows = j.rows || [];
        if(rows.length===0){ tbody.innerHTML='<tr><td colspan="6" class="text-center text-secondary py-3">No issued certificates for this selection.</td></tr>'; }
        else{
          tbody.innerHTML = rows.map((r)=>{
            const link = r.file_path ? ('../'+r.file_path) : '#';
            const scope = r.scope_label || '';
            const who = (r.user_name || 'User') + (r.user_email? ('<div class="small text-secondary">'+r.user_email+'</div>'):'');
            return '<tr>'+
              '<td>#'+(r.id||'')+'</td>'+
              '<td>'+who+'</td>'+
              '<td>'+(scope)+'</td>'+
              '<td><span class="badge badge-soft">'+(r.type_label||'')+'</span></td>'+
              '<td>'+(r.generated_at||'')+'</td>'+
              '<td class="text-end">'+
                (r.file_path?('<a class="btn btn-sm btn-outline-primary me-2" href="'+link+'" target="_blank"><i class="bi bi-box-arrow-up-right"></i> View</a>'):'')+
                '<button class="btn btn-sm btn-outline-danger" data-delete data-id="'+r.id+'" data-type="'+(r.type||'')+'"><i class="bi bi-trash"></i> Delete</button>'+
              '</td>'+
            '</tr>';
          }).join('');
        }
        // Pagination
        const total = j.total || rows.length;
        const pageSize = j.limit || 25; const cur=j.page || 1; const pages = Math.max(1, Math.ceil(total/pageSize));
        pager.innerHTML = '';
        if(pages>1){
          const nav = document.createElement('nav'); nav.setAttribute('aria-label','Pages');
          const ul = document.createElement('ul'); ul.className='pagination pagination-sm mb-0';
          function addBtn(p,label,disabled=false,active=false){
            const li=document.createElement('li'); li.className='page-item'+(disabled?' disabled':'')+(active?' active':''); const a=document.createElement('a'); a.className='page-link'; a.href='#'; a.textContent=label; a.addEventListener('click',(e)=>{ e.preventDefault(); if(!disabled && !active) loadPage(p); }); li.appendChild(a); ul.appendChild(li); }
          addBtn(Math.max(1,cur-1),'«',cur<=1,false);
          for(let p=1;p<=pages;p++){ if(p===1 || p===pages || Math.abs(p-cur)<=2){ addBtn(p,String(p),false,p===cur); } else if(p===2 || p===pages-1){ const li=document.createElement('li'); li.className='page-item disabled'; const span=document.createElement('span'); span.className='page-link'; span.textContent='…'; li.appendChild(span); ul.appendChild(li);} }
          addBtn(Math.min(pages,cur+1),'»',cur>=pages,false);
          nav.appendChild(ul); pager.appendChild(nav);
        }
      } catch(e){
        tbody.innerHTML = '<tr><td colspan="6" class="text-danger">Network error</td></tr>';
        pager.textContent='';
      }
    }

    async function doDelete(id,type){
      if(!confirm('Delete this issued certificate record? This allows the user to regenerate.')) return;
      try{
        const primaryUrl = new URL(API_DELETE_URL, window.location.origin).toString();
        const fallbackUrl = new URL('../api/admin_certificate_delete.php', window.location.href).toString();
        let r = await fetch(primaryUrl,{
          method:'POST',
          credentials:'same-origin',
          headers:{ 'Content-Type':'application/json' },
          body: JSON.stringify({ id: Number(id), type: String(type||''), csrf: CSRF })
        });
        let j = await r.json().catch(()=>null);
        if(!r.ok || !j || !j.ok){
          // fallback
          r = await fetch(fallbackUrl,{
            method:'POST', credentials:'same-origin', headers:{ 'Content-Type':'application/json' },
            body: JSON.stringify({ id: Number(id), type: String(type||''), csrf: CSRF })
          });
          j = await r.json().catch(()=>null);
          if(!r.ok || !j || !j.ok){ alert('Delete failed: '+(j && j.error ? j.error : r.status)+'\nTried: '+primaryUrl+'\nFallback: '+fallbackUrl); return; }
        }
        loadPage();
      }catch(e){ alert('Network error'); }
    }

    async function doDeleteAll(){
      const t = typeSel.value;
      const { scope_id } = getScopeParams();
      const q = searchInp.value.trim();
      // Build a human-readable scope for confirmation
      const typeLabel = typeSel.options[typeSel.selectedIndex]?.text || t;
      let scopeLabel = '';
      if(t==='main_program_day'){ const opt = document.getElementById('dayId').selectedOptions[0]; if(opt && opt.value) scopeLabel = ' — '+opt.text; }
      if(t==='sub_department' || t==='sub_department_attendance'){ const opt = document.getElementById('deptId').selectedOptions[0]; if(opt && opt.value) scopeLabel = ' — '+opt.text; }
      if(t==='sub_session'){ const opt = document.getElementById('speakerId').selectedOptions[0]; if(opt && opt.value) scopeLabel = ' — '+opt.text; }
      const qLabel = q ? ` — search "${q}"` : '';
      const msg = `Delete ALL issued certificates for\n${typeLabel}${scopeLabel}${qLabel}?\n\nThis will remove all matching records${q?' (filtered by search)':''}. Files will also be removed.`;
      if(!confirm(msg)) return;
      try{
        const primaryUrl = new URL(API_DELETE_ALL_URL, window.location.origin).toString();
        const fallbackUrl = new URL('../api/admin_certificates_delete_all.php', window.location.href).toString();
        let r = await fetch(primaryUrl,{
          method:'POST',
          credentials:'same-origin',
          headers:{ 'Content-Type':'application/json' },
          body: JSON.stringify({ type: t, scope_id: scope_id ?? null, q, csrf: CSRF, delete_file: true })
        });
        let j = await r.json().catch(()=>null);
        if(!r.ok || !j || !j.ok){
          // fallback attempt
          r = await fetch(fallbackUrl,{
            method:'POST', credentials:'same-origin', headers:{ 'Content-Type':'application/json' },
            body: JSON.stringify({ type: t, scope_id: scope_id ?? null, q, csrf: CSRF, delete_file: true })
          });
          j = await r.json().catch(()=>null);
          if(!r.ok || !j || !j.ok){ alert('Bulk delete failed: '+(j && j.error ? j.error : r.status)+'\nTried: '+primaryUrl+'\nFallback: '+fallbackUrl); return; }
        }
        alert(`Deleted ${j.deleted||0} record(s).`);
        loadPage(1);
      }catch(e){ alert('Network error'); }
    }

    document.getElementById('resultsBody').addEventListener('click',(e)=>{
      const btn = e.target.closest('[data-delete]'); if(!btn) return;
      doDelete(btn.dataset.id, btn.dataset.type);
    });

    typeSel.addEventListener('change',()=>{ updateScopeVisibility(); loadPage(1); });
    document.getElementById('dayId').addEventListener('change',()=>loadPage(1));
    document.getElementById('deptId').addEventListener('change',()=>loadPage(1));
    document.getElementById('speakerId').addEventListener('change',()=>loadPage(1));
    document.getElementById('search').addEventListener('keydown',(e)=>{ if(e.key==='Enter'){ e.preventDefault(); loadPage(1); }});
    document.getElementById('btnSearch').addEventListener('click',()=>loadPage(1));
  document.getElementById('btnDeleteAll').addEventListener('click',()=>doDeleteAll());

    updateScopeVisibility();
    loadPage(1);
  });
  </script>
</head>
<body>
  <div class="container py-4">
    <div class="d-flex align-items-center mb-3">
      <h3 class="mb-0">Issued Certificates</h3>
      <div class="ms-auto"><a class="btn btn-sm btn-outline-secondary" href="Admin_dashboard.php"><i class="bi bi-arrow-left"></i> Back</a></div>
    </div>
    <div class="card shadow-sm mb-3">
      <div class="card-body toolbar">
        <div class="row g-2 align-items-end">
          <div class="col-sm-4 col-md-3">
            <label class="form-label small">Certificate Type</label>
            <select id="certType" class="form-select">
              <option value="main_program_overall">Main Program — Overall</option>
              <option value="main_program_day">Main Program — Day</option>
              <option value="main_program_attendance">Main Program — Attendance</option>
              <option value="sub_department">Sub Program — Department Completion</option>
              <option value="sub_department_attendance">Sub Program — Department Attendance</option>
              <option value="sub_session">Sub Program — Session</option>
            </select>
          </div>
          <div class="col-sm-4 col-md-3 d-none" id="scope-day">
            <label class="form-label small">Day</label>
            <select id="dayId" class="form-select">
              <option value="">All days</option>
              <?php foreach($days as $d): $label = trim((string)($d['label'] ?? '')); if($label==='') $label = 'Day #'.$d['id']; ?>
                <option value="<?= (int)$d['id'] ?>"><?= htmlspecialchars($label.' • '.($d['day_date'] ?? ''), ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-4 col-md-3 d-none" id="scope-dept">
            <label class="form-label small">Technical Department</label>
            <select id="deptId" class="form-select">
              <option value="">All departments</option>
              <?php foreach($departments as $dep): ?>
                <option value="<?= (int)$dep['id'] ?>"><?= htmlspecialchars($dep['name'] ?? 'Department', ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6 col-md-4 d-none" id="scope-speaker">
            <label class="form-label small">Sub Program Speaker</label>
            <select id="speakerId" class="form-select">
              <option value="">All speakers</option>
              <?php foreach($subSpeakers as $sp): $lab = trim((string)($sp['title'] ?? '')); if($lab!=='') $lab = ' — '.$lab; ?>
                <option value="<?= (int)$sp['id'] ?>"><?= htmlspecialchars(($sp['name'] ?? 'Speaker').$lab, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6 col-md-3 ms-auto">
            <label class="form-label small">Search user</label>
            <div class="input-group">
              <input id="search" class="form-control" placeholder="Name or email">
              <button id="btnSearch" class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
            </div>
          </div>
          <div class="col-sm-12 col-md-auto d-flex justify-content-end">
            <button id="btnDeleteAll" class="btn btn-outline-danger"><i class="bi bi-trash"></i> Delete All (filtered)</button>
          </div>
        </div>
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead>
              <tr>
                <th>ID</th>
                <th>User</th>
                <th>Scope</th>
                <th>Type</th>
                <th>Issued At</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody id="resultsBody"></tbody>
          </table>
        </div>
      </div>
      <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="small text-secondary">Deleting a record does not remove user data; it only clears the issued certificate so the user can regenerate.</div>
        <div id="pager"></div>
      </div>
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
