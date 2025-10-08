<?php
session_start();
require_once __DIR__ . '/../db.php';
if (isset($conn) && $conn instanceof mysqli) $db=$conn; elseif (isset($mysqli) && $mysqli instanceof mysqli) $db=$mysqli; else { http_response_code(500); echo 'DB unavailable'; exit; }
if (empty($_SESSION['user_role']) || $_SESSION['user_role']!=='admin'){ header('Location: admin_login.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(16));
function e($s){ return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Evaluation Question Sets | 73rd PSME National Convention</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
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
    
    .question-card {
      border: 1px solid #e2e8f0;
      border-radius: var(--border-radius);
      padding: 1.5rem;
      margin-bottom: 1rem;
      background: white;
      transition: all 0.3s ease;
      position: relative;
    }
    
    .question-card:hover {
      box-shadow: var(--shadow-hover);
      transform: translateY(-2px);
      border-color: #667eea;
    }
    
    .question-card.dragging {
      opacity: 0.5;
      transform: rotate(2deg);
    }
    
    .drag-handle {
      cursor: grab;
      color: #94a3b8;
      font-size: 1.2rem;
      transition: color 0.2s ease;
    }
    
    .drag-handle:hover {
      color: #667eea;
    }
    
    .drag-handle:active {
      cursor: grabbing;
    }
    
    .chip {
      display: inline-block;
      padding: 0.25rem 0.75rem;
      background: linear-gradient(135deg, #e0f2fe 0%, #f0f9ff 100%);
      border: 1px solid #0ea5e9;
      color: #0369a1;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 500;
      margin: 0.2rem 0.25rem 0 0;
    }
    
    .set-card {
      border: 1px solid #e2e8f0;
      border-radius: var(--border-radius);
      padding: 1rem;
      margin-bottom: 0.75rem;
      background: white;
      transition: all 0.2s ease;
      cursor: pointer;
    }
    
    .set-card:hover {
      box-shadow: var(--shadow-soft);
      border-color: #667eea;
      transform: translateY(-1px);
    }
    
    .set-card.active {
      background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(16, 185, 129, 0.1));
      border-color: #10b981;
    }
    
    .floating-save {
      position: fixed;
      bottom: 2rem;
      right: 2rem;
      z-index: 1000;
      display: none;
      box-shadow: var(--shadow-hover);
    }
    
    .question-type-badge {
      font-size: 0.7rem;
      padding: 0.2rem 0.5rem;
      border-radius: 10px;
      font-weight: 600;
      text-transform: uppercase;
    }
    
    .type-scale { background: #fef3c7; color: #92400e; }
    .type-text { background: #dbeafe; color: #1e40af; }
    .type-textarea { background: #e0f2fe; color: #0369a1; }
    .type-dropdown { background: #f3e8ff; color: #7c3aed; }
    .type-multiple_choice_single { background: #ecfdf5; color: #065f46; }
    .type-multiple_choice_multi { background: #f0fdf4; color: #166534; }
    .type-checkbox { background: #fef2f2; color: #b91c1c; }
    
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
      gap: 1rem;
      margin-bottom: 1rem;
    }
    
    .stat-item {
      text-align: center;
      padding: 1rem;
      background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
      border-radius: 8px;
      border: 1px solid #e2e8f0;
    }
    
    .stat-number {
      font-size: 1.5rem;
      font-weight: 700;
      color: #667eea;
      margin-bottom: 0.25rem;
    }
    
    .stat-label {
      font-size: 0.75rem;
      color: #64748b;
      font-weight: 500;
      text-transform: uppercase;
    }
  </style>
</head>
<body>
  <div class="container-fluid">
    <div class="main-container">
      <div class="page-header">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <h1 class="page-title"><i class="bi bi-sliders me-2"></i>Evaluation Question Sets</h1>
            <p class="page-subtitle">Manage versioned evaluation questions and analytics</p>
          </div>
          <a href="Admin_dashboard.php" class="btn btn-light btn-lg">
            <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
          </a>
        </div>
      </div>
      
      <div class="p-4">
        <div class="row g-4">
          <!-- Left Column: Sets & Form -->
          <div class="col-lg-4">
            <!-- Question Sets Card -->
            <div class="card mb-4">
              <div class="card-header">
                <div class="d-flex align-items-center">
                  <i class="bi bi-collection me-2 text-primary fs-5"></i>
                  <h5 class="card-title mb-0">Question Sets</h5>
                  <button class="btn btn-sm btn-gradient-primary ms-auto" id="btnNewSet">
                    <i class="bi bi-plus-lg me-1"></i>New Set
                  </button>
                </div>
              </div>
              <div class="card-body p-0">
                <div id="setsList"></div>
              </div>
            </div>

            <!-- Question Form Card -->
            <div class="card mb-4">
              <div class="card-header">
                <div class="d-flex align-items-center" id="formHeaderContent">
                  <i class="bi bi-plus-circle me-2 text-success fs-5"></i>
                  <h5 class="card-title mb-0" id="formMode">Add Question</h5>
                </div>
              </div>
              <div class="card-body">
                <form id="questionForm" novalidate>
                  <input type="hidden" id="qId" value="">
                  
                  <div class="mb-3">
                    <label class="form-label fw-semibold">
                      <i class="bi bi-chat-square-text me-1 text-primary"></i>Question Text
                      <span class="text-danger">*</span>
                    </label>
                    <textarea class="form-control" id="qText" rows="3" required 
                              placeholder="Enter your evaluation question..."></textarea>
                  </div>
                  
                  <div class="row g-2 mb-3">
                    <div class="col-6">
                      <label class="form-label fw-semibold">
                        <i class="bi bi-gear me-1 text-secondary"></i>Type
                      </label>
                      <select id="qType" class="form-select">
                        <option value="scale">📊 Scale (1-5)</option>
                        <option value="text">📝 Text Input</option>
                        <option value="textarea">📄 Long Text</option>
                        <option value="dropdown">📋 Dropdown</option>
                        <option value="multiple_choice_single">⚪ Single Choice</option>
                        <option value="multiple_choice_multi">☑️ Multi Choice</option>
                        <option value="checkbox">✅ Checkbox</option>
                      </select>
                    </div>
                    <div class="col-6">
                      <label class="form-label fw-semibold">
                        <i class="bi bi-asterisk me-1 text-warning"></i>Required
                      </label>
                      <select id="qRequired" class="form-select">
                        <option value="1">Yes</option>
                        <option value="0">No</option>
                      </select>
                    </div>
                  </div>
                  
                  <!-- Scale Configuration -->
                  <div id="scaleConfig" class="mb-3">
                    <div class="row g-2">
                      <div class="col-6">
                        <label class="form-label">Min Value</label>
                        <input type="number" class="form-control" id="scaleMin" value="1">
                      </div>
                      <div class="col-6">
                        <label class="form-label">Max Value</label>
                        <input type="number" class="form-control" id="scaleMax" value="5">
                      </div>
                      <div class="col-6">
                        <label class="form-label">Left Label</label>
                        <input type="text" class="form-control" id="scaleLeft" placeholder="Poor">
                      </div>
                      <div class="col-6">
                        <label class="form-label">Right Label</label>
                        <input type="text" class="form-control" id="scaleRight" placeholder="Excellent">
                      </div>
                    </div>
                  </div>
                  
                  <!-- Options Configuration -->
                  <div id="optionsConfig" class="mb-3" style="display:none">
                    <label class="form-label fw-semibold">
                      <i class="bi bi-list-ul me-1 text-info"></i>Options (one per line)
                    </label>
                    <textarea id="qOptions" class="form-control" rows="4" 
                              placeholder="Excellent&#10;Good&#10;Average&#10;Poor"></textarea>
                  </div>
                  
                  <div class="row g-2 mb-3">
                    <div class="col-6">
                      <label class="form-label fw-semibold">
                        <i class="bi bi-toggle-on me-1 text-success"></i>Status
                      </label>
                      <select id="qActive" class="form-select">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                      </select>
                    </div>
                    <div class="col-6">
                      <label class="form-label fw-semibold">
                        <i class="bi bi-sort-numeric-up me-1 text-secondary"></i>Order
                      </label>
                      <input type="number" class="form-control" id="qOrder" value="10">
                    </div>
                  </div>
                  
                  <div class="d-grid gap-2">
                    <button class="btn btn-gradient-success btn-lg" id="btnSaveQuestion" type="submit">
                      <i class="bi bi-check2 me-2"></i><span id="saveLabel">Add Question</span>
                    </button>
                    <button type="button" class="btn btn-outline-secondary d-none" id="btnCancelEdit">
                      <i class="bi bi-x me-2"></i>Cancel Edit
                    </button>
                  </div>
                </form>
              </div>
            </div>

            <!-- Analytics Card -->
            <div class="card">
              <div class="card-header">
                <div class="d-flex align-items-center">
                  <i class="bi bi-graph-up me-2 text-info fs-5"></i>
                  <h5 class="card-title mb-0">Analytics</h5>
                  <button class="btn btn-sm btn-outline-primary ms-auto" id="btnRefreshAnalytics">
                    <i class="bi bi-arrow-clockwise"></i>
                  </button>
                </div>
              </div>
              <div class="card-body">
                <div id="analyticsSummary" class="text-muted">Loading...</div>
                <div class="d-grid gap-2 mt-3">
                  <button class="btn btn-outline-success" id="btnExportCsv">
                    <i class="bi bi-file-earmark-spreadsheet me-2"></i>Export CSV
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Right Column: Questions List -->
          <div class="col-lg-8">
            <div class="card">
              <div class="card-header">
                <div class="d-flex align-items-center justify-content-between">
                  <div class="d-flex align-items-center">
                    <i class="bi bi-list-ol me-2 text-primary fs-5"></i>
                    <h5 class="card-title mb-0">Questions in Set</h5>
                    <span id="activeSetLabel" class="badge bg-primary ms-2"></span>
                  </div>
                  <div class="d-flex align-items-center gap-2">
                    <span id="setMeta" class="small text-muted"></span>
                    <div class="vr"></div>
                    <span class="small text-muted">
                      <i class="bi bi-info-circle me-1"></i>Drag to reorder
                    </span>
                  </div>
                </div>
              </div>
              <div class="card-body">
                <div id="questionsList"></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
  
  <!-- Floating Save Button -->
  <button class="btn btn-gradient-primary floating-save" id="btnSaveOrder">
    <i class="bi bi-save me-2"></i>Save Order
  </button>
<script>
let activeSetId=0; let sets=[]; let questions=[]; let dragSrcEl=null; let orderDirty=false;
function api(path,opts={}){ return fetch('../api/speaker_eval_admin.php'+path, Object.assign({credentials:'same-origin'},opts)).then(r=>r.json()); }
function loadSets(){ api('?action=list_sets').then(d=>{ if(!d.ok) return; sets=d.sets||[]; renderSets(); if(!activeSetId && sets.length){ const active=sets.find(s=>s.is_active==1)||sets[0]; activeSetId=active.id; loadQuestions(); loadAnalytics(); } }); }
function renderSets(){ 
  const wrap=document.getElementById('setsList'); 
  if(!sets.length){ 
    wrap.innerHTML='<div class="text-center text-muted py-4"><i class="bi bi-collection fs-1 mb-2 d-block opacity-25"></i><p class="mb-0">No question sets yet</p></div>'; 
    return;
  } 
  wrap.innerHTML=''; 
  sets.forEach(s=>{ 
    const div=document.createElement('div'); 
    div.className='set-card'+(s.is_active?' active':''); 
    div.innerHTML=`
      <div class="d-flex align-items-center">
        <div class="flex-grow-1">
          <div class="fw-semibold">${escapeHtml(s.set_name)}</div>
          <div class="small text-muted">${s.version_label||'No version'} • Created ${new Date(s.created_at).toLocaleDateString()}</div>
        </div>
        <div class="d-flex gap-1">
          ${s.is_active?'<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Active</span>':`<button class='btn btn-sm btn-gradient-success' data-act='activate' data-id='${s.id}' title='Activate Set'><i class="bi bi-power"></i></button>`}
          <button class='btn btn-sm btn-outline-secondary' data-act='clone' data-id='${s.id}' title='Clone Set'><i class="bi bi-files"></i></button>
        </div>
      </div>
    `; 
    div.addEventListener('click',e=>{ 
      if(e.target.dataset.act==='activate'){ 
        activateSet(s.id); 
        e.stopPropagation(); 
        return;
      } 
      if(e.target.dataset.act==='clone'){ 
        cloneSet(s.id); 
        e.stopPropagation(); 
        return;
      } 
      if(e.target.closest('button')) return;
      activeSetId=s.id; 
      loadQuestions(); 
      loadAnalytics();
      // Update visual selection
      document.querySelectorAll('.set-card').forEach(c=>c.classList.remove('active'));
      div.classList.add('active');
    }); 
    wrap.appendChild(div); 
  }); 
}
function activateSet(id){ api('',{method:'POST',body:JSON.stringify({action:'activate_set',set_id:id})}).then(r=>{ if(r.ok){ sets.forEach(s=>s.is_active = s.id===id?1:0); renderSets(); } }); }
function cloneSet(id){ const name=prompt('New set name?','New Set'); if(!name) return; api('',{method:'POST',body:JSON.stringify({action:'clone_set',set_id:id,set_name:name,version_label:'v'+Date.now()})}).then(r=>{ if(r.ok){ loadSets(); } }); }
function newSet(){ const name=prompt('Set name?','Question Set'); if(!name) return; api('',{method:'POST',body:JSON.stringify({action:'create_set',set_name:name,version_label:'v'+Date.now()})}).then(r=>{ if(r.ok){ loadSets(); } }); }
function loadQuestions(){ api('?action=list_questions&set_id='+activeSetId).then(d=>{ if(!d.ok) return; questions=d.questions||[]; renderQuestions(); const act=sets.find(s=>s.id===activeSetId); document.getElementById('activeSetLabel').textContent= act? act.set_name : activeSetId; document.getElementById('setMeta').textContent= act? (act.is_active?'ACTIVE':'INACTIVE') : ''; }); }
function renderQuestions(){ 
  const wrap=document.getElementById('questionsList'); 
  if(!questions.length){ 
    wrap.innerHTML='<div class="text-center text-muted py-5"><i class="bi bi-question-circle fs-1 mb-3 d-block opacity-25"></i><h6>No questions in this set</h6><p class="mb-0">Add your first evaluation question using the form.</p></div>'; 
    return;
  } 
  wrap.innerHTML=''; 
  questions.forEach((q,index)=>{ 
    const card=document.createElement('div'); 
    card.className='question-card q-row'; 
    card.draggable=true; 
    card.dataset.id=q.id; 
    const typeClass = `type-${q.question_type}`;
    card.innerHTML=`
      <div class='d-flex align-items-start'>
        <div class='drag-handle me-3 mt-1'>
          <i class="bi bi-grip-vertical"></i>
        </div>
        <div class='flex-grow-1'>
          <div class='d-flex align-items-start justify-content-between mb-2'>
            <div class='d-flex align-items-center gap-2'>
              <span class='badge badge-secondary'>#${index + 1}</span>
              <span class='question-type-badge ${typeClass}'>${q.question_type}</span>
              ${q.is_required==1?'<span class="badge bg-warning text-dark"><i class="bi bi-asterisk me-1"></i>Required</span>':''}
              ${q.is_active==0?'<span class="badge badge-inactive"><i class="bi bi-pause-circle me-1"></i>Inactive</span>':''}
            </div>
            <div class='d-flex gap-1'>
              <button class='btn btn-sm btn-gradient-primary' onclick='editQuestion(${q.id})' title='Edit Question'>
                <i class="bi bi-pencil"></i>
              </button>
              <button class='btn btn-sm btn-gradient-danger' onclick='deleteQuestion(${q.id})' title='Deactivate Question'>
                <i class="bi bi-pause"></i>
              </button>
            </div>
          </div>
          <div class='fw-semibold mb-2'>${escapeHtml(q.question_text)}</div>
          ${renderQuestionDetails(q)}
          ${renderOptionChips(q)}
        </div>
      </div>
    `; 
    addDnDHandlers(card); 
    wrap.appendChild(card); 
  }); 
}
function renderOptionChips(q){ 
  if(!q.options) return ''; 
  return `<div class='mt-2'><div class='small text-muted mb-1'>Options:</div>${q.options.map(o=>`<span class='chip'>${escapeHtml(o.option_text)}</span>`).join('')}</div>`; 
}

function renderQuestionDetails(q) {
  if(q.question_type === 'scale') {
    return `<div class='small text-muted mb-1'>Scale: ${q.min_scale||1} to ${q.max_scale||5} ${q.scale_label_left||q.scale_label_right ? `(${q.scale_label_left||''} ↔ ${q.scale_label_right||''})` : ''}</div>`;
  }
  return '';
}
function addDnDHandlers(el){ el.addEventListener('dragstart',e=>{ dragSrcEl=el; el.classList.add('dragging'); e.dataTransfer.effectAllowed='move'; }); el.addEventListener('dragend',()=>{ el.classList.remove('dragging'); }); el.addEventListener('dragover',e=>{ e.preventDefault(); const target=e.currentTarget; if(target===dragSrcEl) return; const wrap=target.parentNode; const rect=target.getBoundingClientRect(); const next = (e.clientY - rect.top)/(rect.height) > .5; wrap.insertBefore(dragSrcEl, next? target.nextSibling : target); orderDirty=true; syncOrderButton(); }); }
function syncOrderButton(){ document.getElementById('btnSaveOrder').style.display = orderDirty? 'block':'none'; }
function saveOrder(){ const rows=[...document.querySelectorAll('.q-row')]; const payload=rows.map((r,i)=>({id:parseInt(r.dataset.id,10),sort_order:(i+1)*10})); api('',{method:'POST',body:JSON.stringify({action:'reorder',order:payload})}).then(r=>{ if(r.ok){ orderDirty=false; syncOrderButton(); loadQuestions(); } }); }
function editQuestion(id){ 
  const q=questions.find(x=>x.id===id); 
  if(!q) return; 
  
  // Update form header
  const formHeaderContent = document.getElementById('formHeaderContent');
  formHeaderContent.innerHTML = `
    <i class="bi bi-pencil-square me-2 text-warning fs-5"></i>
    <h5 class="card-title mb-0">Edit Question</h5>
    <span class="badge bg-warning ms-auto">#${q.id}</span>
  `;
  
  document.getElementById('formMode').textContent='Edit Question'; 
  document.getElementById('saveLabel').textContent='Update Question'; 
  document.getElementById('btnCancelEdit').classList.remove('d-none'); 
  document.getElementById('qId').value=q.id; 
  document.getElementById('qText').value=q.question_text; 
  document.getElementById('qType').value=q.question_type; 
  document.getElementById('qRequired').value=q.is_required; 
  document.getElementById('qActive').value=q.is_active; 
  document.getElementById('qOrder').value=q.sort_order; 
  document.getElementById('scaleMin').value=q.min_scale||1; 
  document.getElementById('scaleMax').value=q.max_scale||5; 
  document.getElementById('scaleLeft').value=q.scale_label_left||''; 
  document.getElementById('scaleRight').value=q.scale_label_right||''; 
  if(q.options){ 
    document.getElementById('qOptions').value=q.options.map(o=>o.option_text).join('\n'); 
  } else document.getElementById('qOptions').value=''; 
  typeToggled();
  
  // Scroll to form
  document.getElementById('questionForm').scrollIntoView({ behavior: 'smooth', block: 'center' });
}
function deleteQuestion(id){ if(!confirm('Deactivate this question? (It will be marked inactive)')) return; api('',{method:'POST',body:JSON.stringify({action:'delete_question',id})}).then(r=>{ if(r.ok){ loadQuestions(); } }); }
function resetForm(){ 
  // Reset form header
  const formHeaderContent = document.getElementById('formHeaderContent');
  formHeaderContent.innerHTML = `
    <i class="bi bi-plus-circle me-2 text-success fs-5"></i>
    <h5 class="card-title mb-0">Add Question</h5>
  `;
  
  document.getElementById('formMode').textContent='Add Question'; 
  document.getElementById('saveLabel').textContent='Add Question'; 
  document.getElementById('btnCancelEdit').classList.add('d-none'); 
  document.getElementById('qId').value=''; 
  document.getElementById('questionForm').reset(); 
  typeToggled(); 
}
function typeToggled(){ const t=document.getElementById('qType').value; document.getElementById('scaleConfig').style.display = t==='scale'? 'block':'none'; document.getElementById('optionsConfig').style.display = ['dropdown','multiple_choice_single','multiple_choice_multi'].includes(t)?'block':'none'; }
function submitQuestion(e){ e.preventDefault(); const id=document.getElementById('qId').value; const payload={ action: id? 'update_question':'create_question', question_set_id: activeSetId, id: id? parseInt(id,10):undefined, question_text: document.getElementById('qText').value.trim(), question_type: document.getElementById('qType').value, is_required: parseInt(document.getElementById('qRequired').value,10), is_active: parseInt(document.getElementById('qActive').value,10), sort_order: parseInt(document.getElementById('qOrder').value,10), min_scale: parseInt(document.getElementById('scaleMin').value,10), max_scale: parseInt(document.getElementById('scaleMax').value,10), scale_label_left: document.getElementById('scaleLeft').value, scale_label_right: document.getElementById('scaleRight').value }; if(['dropdown','multiple_choice_single','multiple_choice_multi'].includes(payload.question_type)){ const opts=document.getElementById('qOptions').value.split(/\n+/).map(s=>s.trim()).filter(Boolean); payload.options=opts; } api('',{method:'POST',body:JSON.stringify(payload)}).then(r=>{ if(!r.ok){ alert(r.error||'Save failed'); return;} resetForm(); loadQuestions(); }); }
function loadAnalytics(){ 
  api('?action=analytics_summary&set_id='+activeSetId).then(d=>{ 
    const box=document.getElementById('analyticsSummary'); 
    if(!d.ok){ 
      box.innerHTML='<div class="text-center text-danger"><i class="bi bi-exclamation-triangle"></i> Error loading analytics</div>'; 
      return; 
    } 
    if(!d.questions.length){ 
      box.innerHTML='<div class="text-center text-muted"><i class="bi bi-graph-up fs-2 mb-2 d-block opacity-25"></i><p class="mb-0">No responses yet</p><small>Analytics will appear when users submit evaluations</small></div>'; 
      return;
    } 
    
    // Create stats grid
    const totalResponses = d.questions.reduce((sum, q) => sum + q.responses, 0);
    const avgQuestions = d.questions.length;
    const avgResponseRate = avgQuestions > 0 ? Math.round(totalResponses / avgQuestions) : 0;
    
    box.innerHTML = `
      <div class="stats-grid mb-3">
        <div class="stat-item">
          <div class="stat-number">${avgQuestions}</div>
          <div class="stat-label">Questions</div>
        </div>
        <div class="stat-item">
          <div class="stat-number">${totalResponses}</div>
          <div class="stat-label">Total Responses</div>
        </div>
        <div class="stat-item">
          <div class="stat-number">${avgResponseRate}</div>
          <div class="stat-label">Avg per Question</div>
        </div>
      </div>
      <div class="small">
        ${d.questions.map(q=>`
          <div class="d-flex justify-content-between align-items-center py-1">
            <div class="fw-medium">${escapeHtml(q.question.substring(0,30))}</div>
            <div class="text-end">
              <div class="fw-bold text-success">${q.avg_value ? q.avg_value.toFixed(1) : '-'}</div>
              <small class="text-muted">${q.responses} responses</small>
            </div>
          </div>
        `).join('<hr class="my-1">')}
      </div>
    `; 
  }); 
}
function exportCsv(){ window.location='../api/speaker_eval_admin.php?action=export_csv&set_id='+activeSetId; }
function escapeHtml(s){ return (s||'').replace(/[&<>"']/g,c=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;' }[c])); }
// Events
 document.getElementById('questionForm').addEventListener('submit',submitQuestion); document.getElementById('qType').addEventListener('change',typeToggled); document.getElementById('btnCancelEdit').addEventListener('click',resetForm); document.getElementById('btnSaveOrder').addEventListener('click',saveOrder); document.getElementById('btnNewSet').addEventListener('click',newSet); document.getElementById('btnRefreshAnalytics').addEventListener('click',loadAnalytics); document.getElementById('btnExportCsv').addEventListener('click',exportCsv);
loadSets(); typeToggled();
</script>
</body>
</html>