<?php
// Public NatCon Evaluation Form (frontend only; logic in /api endpoints)
// Requirements:
//  - Authenticated user (session user_id)
//  - Fetch active questions from natcon_eval_questions.php
//  - Render dynamically based on question_type
//  - Client-side validation for required fields
//  - Submit answers to natcon_eval_submit.php as JSON
//  - Handle duplicate submission (409) and success state
//  - Basic resilience + localStorage draft caching

session_start();
require_once __DIR__ . '/../db.php';

if (isset($conn) && $conn instanceof mysqli) $db = $conn; elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli; else { http_response_code(500); echo 'DB unavailable'; exit; }

$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($user_id <= 0) { header('Location: ../Credentials/login.php'); exit; }

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(16)); }
$csrf = $_SESSION['csrf_token'];

if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); } }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>NatCon Evaluation</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary-color: #111e6c;
      --primary-dark: #1116ec;
      --primary-light: #dbeafe;
      --secondary-color: #e27900ff;
      --secondary-dark: #ce6e00ff;
      --success-color: #10b981;
      --dark-color: #111e6c;
      --light-gray: #f8fafc;
      --border-color: #e5e7eb;
    }
    
    * {
      font-family: 'Poppins', sans-serif;
    }
    
    .navbar {
      backdrop-filter: blur(20px);
      background: rgba(255, 255, 255, 0.95) !important;
      border-bottom: 1px solid var(--border-color);
    }
    
    .navbar-brand span {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    
    .btn-primary {
      background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
      border: none;
      box-shadow: 0 4px 15px rgba(37, 99, 235, 0.3);
    }
    
    .btn-primary:hover {
      background: linear-gradient(135deg, var(--primary-dark), var(--primary-color));
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(37, 99, 235, 0.4);
    }
    
    .btn-outline-primary {
      border: 2px solid var(--primary-color);
      color: var(--primary-color);
    }
    
    .btn-outline-primary:hover {
      background: var(--primary-color);
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(37, 99, 235, 0.3);
    }
    
    .btn-warning {
      background: linear-gradient(135deg, var(--secondary-color), var(--secondary-dark));
      border: none;
      box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3);
    }
    
    .btn-warning:hover {
      background: linear-gradient(135deg, var(--secondary-dark), var(--secondary-color));
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(245, 158, 11, 0.4);
    }
        
    body { 
      background: var(--primary-color); 
      color:#fff;
      padding-top: 70px;
    }

    .text-gradient {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .eval-container{max-width:860px;margin:0 auto 60px;padding:0 18px;}
    .card-eval{background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.08);border-radius:14px;margin-bottom:18px;overflow:hidden;}
    .card-eval .card-body{padding:24px 26px;}
    .req::after{content:' *';color:#ffd700;}
    .question{margin-bottom:28px;}
    .q-title{font-weight:600;margin:0 0 12px;font-size:1.1rem;color:#fff;}
    .scale-chips{display:flex;flex-wrap:wrap;gap:10px;margin-top:8px;}
    .scale-chips input{position:absolute;opacity:0;}
    .chip{min-width:46px;height:44px;padding:0 14px;border-radius:12px;background:rgba(255,255,255,0.1);border:2px solid rgba(255,255,255,0.2);display:inline-flex;align-items:center;justify-content:center;font-weight:500;cursor:pointer;transition:.16s;user-select:none;color:#fff;}
    .chip:hover{background:rgba(255,255,255,0.15);}
    .scale-chips input:checked + .chip{background:var(--secondary-color);border-color:var(--secondary-dark);color:#fff;box-shadow:0 0 0 1px var(--secondary-dark) inset;}
    .opt-list{list-style:none;padding:0;margin:4px 0 0;}
    .opt-item{display:flex;align-items:flex-start;gap:10px;margin-bottom:8px;}
    .opt-item input{margin-top:4px;}
    .opt-item label{color:#fff;}
    .small-hint{font-size:.75rem;color:#ccc;margin-top:4px;}
    textarea.form-control, input.form-control, select.form-control{min-height:44px;background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);color:#fff;border-radius:8px;}
    textarea.form-control{min-height:110px;resize:vertical;}
    textarea.form-control::placeholder, input.form-control::placeholder{color:#ccc;}
    textarea.form-control:focus, input.form-control:focus, select.form-control:focus{background:rgba(255,255,255,0.15);border-color:var(--secondary-color);box-shadow:0 0 0 2px rgba(226,121,0,0.2);color:#fff;}
    .range-scale{display:flex;align-items:center;gap:14px;margin-top:8px;}
    .badge-scale-label{font-size:.7rem;font-weight:500;text-transform:uppercase;letter-spacing:.5px;color:#fff;background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);padding:4px 8px;border-radius:20px;}
    .submit-bar{display:flex;align-items:center;gap:14px;flex-wrap:wrap;}
    .btn-primary.eval{background:linear-gradient(135deg,var(--secondary-color),var(--secondary-dark));border:none;font-weight:600;padding:.65rem 1.4rem;border-radius:10px;}
    .btn-primary.eval:hover{background:linear-gradient(135deg,var(--secondary-dark),var(--secondary-color));transform:translateY(-2px);}
    .btn-outline-secondary{border:2px solid rgba(255,255,255,0.3);color:#fff;background:transparent;}
    .btn-outline-secondary:hover{background:rgba(255,255,255,0.1);border-color:rgba(255,255,255,0.5);}
    .status-msg{font-size:.9rem;color:#ccc;}
    .done-state{background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.3);border-radius:14px;padding:28px;text-align:center;color:#fff;}
    .done-state h4{font-weight:600;margin-bottom:8px;color:#fff;}
    .skeleton{background:linear-gradient(90deg,rgba(255,255,255,0.05),rgba(255,255,255,0.1),rgba(255,255,255,0.05));background-size:200% 100%;animation:shimmer 1.2s linear infinite;border-radius:6px;height:16px;margin-bottom:10px;}
    .form-check-input{background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.3);}
    .form-check-input:checked{background:var(--secondary-color);border-color:var(--secondary-dark);}
    .form-check-label{color:#fff;}
    select.form-control option{background:var(--primary-color);color:#fff;}
    @keyframes shimmer{0%{background-position:200% 0;}100%{background-position:-200% 0;}}
  </style>
</head>
<body>
  <!-- Header Section -->
  <nav class="navbar navbar-expand-lg navbar-light shadow-custom fixed-top">
    <div class="container">
      <!-- Logo + Title -->
      <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
        <img src="../image/natcon.jpg" alt="PSME Logo" class="me-3 rounded-circle" style="height: 55px;">
        <div>
          <span class="fs-5 fw-bold d-block">73rd PSME</span>
          <small class="d-none d-md-block fw-medium">National Convention</small>
        </div>
      </a>

      <!-- Navbar Toggle -->
      <button class="navbar-toggler border-0 shadow-sm" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
        <span class="navbar-toggler-icon"></span>
      </button>

      <!-- Navbar Links -->
      <div class="collapse navbar-collapse" id="navbarNav">
        <ul class="navbar-nav ms-auto align-items-center">
          <li class="nav-item">
            <a class="nav-link fw-semibold px-3" href="function_rooms.php">< Back to Function Rooms</a>
          </li>
        </ul>
      </div>
    </div>
  </nav>

  <div class="container container-wrap mt-5" id="main">
    <div class="display-6 fw-bold m-3 text-white text-center">NATCON PARTICIPANT EVALUATION</div>
    <div class="text-center mb-4">
      <p class="text-white mb-1">Your feedback helps us improve future conventions.</p>
      <p class="small" style="color:#ccc;">Estimated time: <strong>3–4 minutes</strong>. One submission per account.</p>
    </div>

    <main class="eval-container">
      <div id="alertContainer"></div>
      <div id="formCard" class="card-eval">
        <div class="card-body">
          <form id="evalForm" novalidate>
            <div id="questionsWrap"></div>
            <div class="submit-bar mt-2">
              <button type="submit" id="submitBtn" class="btn btn-primary eval">Submit Evaluation</button>
              <button type="button" id="saveDraftBtn" class="btn btn-outline-secondary">Save Draft</button>
              <span id="statusMsg" class="status-msg"></span>
            </div>
          </form>
        </div>
      </div>
      <div id="doneCard" class="done-state d-none">
        <h4>Thank you!</h4>
        <p class="mb-2">Your responses have been recorded. We appreciate your time.</p>
        <a href="dashboard.php" class="btn btn-primary eval">Return to Dashboard</a>
      </div>
    </main>
  </div>

  <script>
  const CSRF = <?= json_encode($csrf) ?>;
  const questionsWrap = document.getElementById('questionsWrap');
  const formEl = document.getElementById('evalForm');
  const submitBtn = document.getElementById('submitBtn');
  const statusMsg = document.getElementById('statusMsg');
  const doneCard = document.getElementById('doneCard');
  const formCard = document.getElementById('formCard');
  const alertContainer = document.getElementById('alertContainer');
  const saveDraftBtn = document.getElementById('saveDraftBtn');

  const DRAFT_KEY = 'natcon_eval_draft_v1';
  let questions = [];
  let hasSubmitted = false;

  function showAlert(msg, type='info', dismissMs=5000){
    const id='al-'+Date.now();
    const div=document.createElement('div');
    div.className='alert alert-'+type+' alert-dismissible fade show';
    div.innerHTML='<div>'+msg+'</div><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
    alertContainer.appendChild(div);
    if (dismissMs) setTimeout(()=>{ try{bootstrap.Alert.getOrCreateInstance(div).close();}catch(e){} }, dismissMs);
  }

  function skeleton(count=5){
    let html='';
    for (let i=0;i<count;i++){ html+='<div class="skeleton" style="height:'+(18+Math.random()*12)+'px"></div>'; }
    return html;
  }

  async function loadQuestions(force=false){
    // Basic localStorage cache (5 min) to reduce load for 3000 users -> fewer DB hits
    if (!force){
      const cached = localStorage.getItem('natcon_eval_questions_cache');
      if (cached){
        try{
          const obj=JSON.parse(cached);
            if (Date.now() - obj.time < 5*60*1000){
              questions = obj.data || [];
              renderQuestions();
              return;
            }
        }catch(_){/* ignore */}
      }
    }
    questionsWrap.innerHTML = skeleton(6);
    try {
  const res = await fetch('../api/natcon_eval_questions.php', {credentials:'same-origin'});
      const raw = await res.text();
      let data; try { data = JSON.parse(raw); } catch(parseErr){ throw new Error('Questions fetch failed (non-JSON).'); }
      if (!data.ok) throw new Error(data.error||'Failed to load questions');
      questions = data.questions||[];
      localStorage.setItem('natcon_eval_questions_cache', JSON.stringify({time:Date.now(), data:questions}));
      renderQuestions();
      restoreDraft();
    } catch(e){
      questionsWrap.innerHTML = '<div class="text-danger">'+e.message+'</div>';
    }
  }

  function renderQuestions(){
    if (!questions.length){
      questionsWrap.innerHTML = '<p class="text-muted">No evaluation questions are currently active.</p>';
      submitBtn.disabled = true;
      return;
    }
    const frag = document.createDocumentFragment();
    questions.forEach(q => frag.appendChild(renderQuestion(q)) );
    questionsWrap.innerHTML='';
    questionsWrap.appendChild(frag);
  }

  function renderQuestion(q){
    const wrap=document.createElement('div');
    wrap.className='question';
    wrap.dataset.qid = q.id;
    const req = q.is_required==1;
    const title = '<div class="q-title'+(req?' req':'')+'">'+escapeHtml(q.question_text)+'</div>';
    let control='';
    switch(q.question_type){
      case 'scale': control = renderScale(q); break;
      case 'text': control = '<input type="text" class="form-control" name="q_'+q.id+'" '+(req?'required':'')+' maxlength="500">'; break;
      case 'textarea': control = '<textarea class="form-control" name="q_'+q.id+'" '+(req?'required':'')+' maxlength="2000" rows="4"></textarea>'; break;
      case 'multiple_choice_single': control = renderOptions(q,'radio'); break;
      case 'multiple_choice_multi': control = renderOptions(q,'checkbox'); break;
      case 'dropdown': control = renderDropdown(q); break;
      case 'checkbox': control = '<div class="form-check"><input class="form-check-input" type="checkbox" name="q_'+q.id+'" id="chk_'+q.id+'"><label class="form-check-label" for="chk_'+q.id+'">Yes</label></div>'; break;
      default: control = '<div class="text-muted">Unsupported type</div>';
    }
    wrap.innerHTML = title + control;
    return wrap;
  }

  function renderScale(q){
    const min = q.min_scale ?? 1; const max = q.max_scale ?? 5;
    const left = escapeHtml(q.scale_label_left||'');
    const right = escapeHtml(q.scale_label_right||'');
    let chips='';
    for (let i=min;i<=max;i++){
      const id='q_'+q.id+'_'+i;
      chips += `<input id="${id}" type="radio" name="q_${q.id}" value="${i}" ${(q.is_required==1?'required':'')} class="visually-hidden">`+
               `<label for="${id}" class="chip">${i}</label>`;
    }
    return `<div class="scale-chips">${chips}</div><div class="range-scale"><span class="badge-scale-label">${left}</span><div class="flex-grow-1" style="height:2px;background:#e2e8f0;position:relative;top:-2px;"></div><span class="badge-scale-label">${right}</span></div>`;
  }

  function renderOptions(q, mode){
    const opts = q.options||[]; if(!opts.length) return '<div class="text-muted small">No options defined.</div>';
    const type = mode==='radio'?'radio':'checkbox';
    let html='<ul class="opt-list">';
    opts.forEach(o=>{
      const id='q_'+q.id+'_opt_'+o.id;
      html += `<li class="opt-item"><input class="form-check-input" type="${type}" name="q_${q.id}${type==='checkbox'?'[]':''}" value="${o.id}" id="${id}" ${(q.is_required==1 && type==='radio')?'required':''}>`+
              `<label class="form-check-label" for="${id}">${escapeHtml(o.option_text)}</label></li>`;
    });
    html+='</ul>';
    return html;
  }
  function renderDropdown(q){
    const opts = q.options||[]; if(!opts.length) return '<div class="text-muted small">No options defined.</div>';
    let html = `<select class="form-select" name="q_${q.id}" ${(q.is_required==1)?'required':''}><option value="">-- Select --</option>`;
    opts.forEach(o=>{ html += `<option value="${o.id}">${escapeHtml(o.option_text)}</option>`; });
    html += '</select>';
    return html;
  }

  function escapeHtml(s){ return (s||'').replace(/[&<>"']/g,c=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;' }[c])); }

  function collectAnswers(){
    const answers=[]; let valid=true; let firstInvalid=null;
    for (const q of questions){
      const qid = q.id;
      const name = 'q_'+qid;
      let value = null;
      if (q.question_type==='scale' || q.question_type==='multiple_choice_single' || q.question_type==='dropdown') {
        const el = formEl.querySelector(`[name="${name}"]:checked`) || formEl.querySelector(`select[name="${name}"]`);
        if (q.question_type==='dropdown') {
          value = el?.value||''; if (value==='') value=null; else value=parseInt(value,10);
        } else {
          value = el? el.value : null; if (value!==null) value = parseInt(value,10);
        }
        if (q.is_required==1 && value===null){ valid=false; firstInvalid = firstInvalid||formEl.querySelector(`[name="${name}"]`); }
        else if (value!==null) answers.push({question_id: qid, value});
      } else if (q.question_type==='text' || q.question_type==='textarea') {
        const el = formEl.querySelector(`[name="${name}"]`);
        value = el.value.trim(); if (value==='' && q.is_required==1){ valid=false; firstInvalid=firstInvalid||el; }
        else if (value!=='') answers.push({question_id: qid, value});
      } else if (q.question_type==='multiple_choice_multi') {
        const els=[...formEl.querySelectorAll(`input[name="${name}[]"]:checked`)];
        const arr = els.map(e=>parseInt(e.value,10));
        if (q.is_required==1 && arr.length===0){ valid=false; firstInvalid=firstInvalid||formEl.querySelector(`input[name="${name}[]"]`); }
        else if (arr.length) answers.push({question_id: qid, value: arr});
      } else if (q.question_type==='checkbox') {
        const el=formEl.querySelector(`[name="${name}"]`); answers.push({question_id: qid, value: el.checked?1:0});
      }
    }
    return {answers, valid, firstInvalid};
  }

  function saveDraft(){
    const {answers} = collectAnswers();
    localStorage.setItem(DRAFT_KEY, JSON.stringify({time:Date.now(), answers}));
    showAlert('Draft saved locally.', 'success', 2500);
  }
  function clearDraft(){ localStorage.removeItem(DRAFT_KEY); }
  function restoreDraft(){
    const raw = localStorage.getItem(DRAFT_KEY); if(!raw) return;
    try{ const obj=JSON.parse(raw); if(!obj.answers) return; for(const a of obj.answers){
      const q = questions.find(x=>x.id===a.question_id); if(!q) continue;
      const base='q_'+a.question_id;
      if (Array.isArray(a.value)){
        a.value.forEach(v=>{ const el=formEl.querySelector(`input[name="${base}[]"][value="${v}"]`); if(el) el.checked=true; });
      } else if (q.question_type==='text' || q.question_type==='textarea') {
        const el=formEl.querySelector(`[name="${base}"]`); if(el) el.value = a.value;
      } else if (q.question_type==='checkbox') {
        const el=formEl.querySelector(`[name="${base}"]`); if(el) el.checked = !!a.value;
      } else {
        const el=formEl.querySelector(`[name="${base}"][value="${a.value}"]`) || formEl.querySelector(`select[name="${base}"] option[value="${a.value}"]`);
        if (el){ if (el.tagName==='OPTION') el.selected=true; else el.checked=true; }
      }
    } }catch(e){}
  }

  formEl.addEventListener('submit', async (e)=>{
    e.preventDefault(); if (hasSubmitted) return; statusMsg.textContent='';
    const {answers, valid, firstInvalid} = collectAnswers();
    if (!valid){ showAlert('Please complete all required questions.', 'danger'); if(firstInvalid) firstInvalid.focus(); return; }
    if (!answers.length){ showAlert('No answers to submit.', 'warning'); return; }
    submitBtn.disabled = true; submitBtn.textContent='Submitting...';
    statusMsg.textContent='Sending...';
    try {
      const payload = {answers};
      console.log('[NatConEval] Submitting payload', payload);
      const res = await fetch('../api/natcon_eval_submit.php', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, body: JSON.stringify(payload) });
      const raw = await res.text(); let data={}; try{ data=JSON.parse(raw);}catch(parseErr){ console.error('[NatConEval] Non-JSON response:', raw); }
      console.log('[NatConEval] Response status', res.status, 'data', data);
      if (res.status===409){ showAlert('You have already submitted this evaluation.', 'warning'); formCard.classList.add('d-none'); doneCard.classList.remove('d-none'); hasSubmitted=true; clearDraft(); return; }
      if (res.status===401){ showAlert('Your session expired. Please log in again.', 'danger'); return; }
      if (!res.ok || !data.ok){
        const msg = (data && data.error) ? data.error : ('Submission failed (HTTP '+res.status+')');
        showAlert(msg, 'danger');
        if (!data.ok) console.error('[NatConEval] Error payload/raw:', data, raw);
        return;
      }
      hasSubmitted=true; formCard.classList.add('d-none'); doneCard.classList.remove('d-none'); clearDraft(); showAlert('Evaluation submitted successfully.','success',6000);
    } catch(err){
      showAlert('Network error, your draft is saved locally. Retry later.', 'danger'); saveDraft();
    } finally { submitBtn.disabled=false; submitBtn.textContent='Submit Evaluation'; statusMsg.textContent=''; }
  });

  saveDraftBtn.addEventListener('click', (e)=>{ saveDraft(); });

  window.addEventListener('beforeunload', ()=>{ if(!hasSubmitted) saveDraft(); });

  loadQuestions();
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
