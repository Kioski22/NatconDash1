<?php
session_start();
if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); } }
$dayId = isset($_GET['day_id']) ? (int)$_GET['day_id'] : 0; if ($dayId <= 0) { http_response_code(400); echo 'Invalid day'; exit; }
// Page now fetches data from ../api/sub_program_day.php to centralize server logic.
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Sub Program - Day</title>
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
 body { background: var(--primary-color); color:#fff; font-family:'Poppins',sans-serif; padding-top:70px; }
 .room-block { background:rgba(255,255,255,0.07); border:1px solid rgba(255,255,255,0.2); border-radius:14px; padding:22px 22px; margin-bottom:28px; }
 .speaker-item { background:rgba(255,255,255,0.1); border:1px solid rgba(255,255,255,0.25); border-radius:10px; padding:14px 16px; transition:.25s; cursor:pointer; }
 .speaker-item:hover { background:rgba(255,255,255,0.18); transform:translateY(-4px); }
 .speaker-time { font-size:.75rem; letter-spacing:.5px; opacity:.7; }
 .speaker-title { font-size:.85rem; opacity:.85; }
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
            <a class="nav-link fw-semibold px-3" href="programs.php?day_id=<?= (int)$dayId ?>">Programs</a>
          </li>
          <li class="nav-item">
            <a class="nav-link fw-semibold px-3" href="dashboard.php">Dashboard</a>
          </li>
        </ul>
      </div>
    </div>
  </nav>
  
<div class="container py-5">
  <div id="pageHeader" class="text-center mb-4">
    <h1 class="display-6 fw-bold mb-1" id="dayTitle">Sub Program</h1>
    <p class="text-center mb-4 small opacity-75" id="daySubtitle">Loading day information...</p>
  </div>
  <div id="departmentsContainer"></div>
  <div id="emptyState" class="alert alert-warning text-dark d-none">No technical departments with speakers for this day.</div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
  const dayId = <?= (int)$dayId ?>;
  function esc(str){ return document.createTextNode(str ?? '').textContent; }
  async function fetchDepartmentCertificateStatus(deptId){
    try {
      const r = await fetch('../api/sub_department_certificate.php?department_id='+deptId,{credentials:'same-origin'});
      if(!r.ok) return null; return await r.json();
    } catch(e){ return null; }
  }

  function buildProgressBar(completed,total,label){
    const pct = total>0 ? Math.round((completed/total)*100) : 0;
    return '<div class="mb-2"><div class="d-flex justify-content-between small"><span>'+label+'</span><span>'+completed+'/'+total+'</span></div>'+
      '<div class="progress" style="height:8px;background:rgba(255,255,255,0.15);"><div class="progress-bar bg-warning" role="progressbar" style="width:'+pct+'%" aria-valuenow="'+pct+'" aria-valuemin="0" aria-valuemax="100"></div></div></div>';
  }

  // Maintain an in-memory snapshot to enable incremental UI refresh without full rebuild
  let departmentSnapshot = {}; // deptId -> { quizCompleted, quizTotal, evalCompleted, evalTotal }
  let speakersIndex = {}; // deptId -> array of speaker objects

  async function refreshProgress(deptId){
    // Fetch only departments data again (lightweight endpoint reuse)
    try {
      const r = await fetch('../api/sub_program_departments.php?day_id='+encodeURIComponent(dayId),{credentials:'same-origin',cache:'no-store'});
      if(!r.ok) return;
      const data = await r.json();
      const depts = data.departments || {};
      Object.keys(depts).forEach(k=>{
        const d = depts[k];
        const id = d.department_id || d.id || k;
        const speakers = d.speakers||[];
        // Recompute progress for department id
        let quizCompleted=0, quizTotal=0, evalCompleted=0, evalTotal=0;
        speakers.forEach(sp=>{
          if(sp.has_quiz){ quizTotal++; if(sp.quiz_submitted) quizCompleted++; }
          if(sp.requires_evaluation || sp.has_evaluation){ evalTotal++; if(sp.evaluated) evalCompleted++; }
        });
        if(quizTotal===0){ quizTotal=speakers.length; quizCompleted=speakers.filter(s=>s.quiz_submitted).length; }
        if(evalTotal===0){ evalTotal=speakers.length; evalCompleted=speakers.filter(s=>s.evaluated).length; }
        departmentSnapshot[id] = {quizCompleted,quizTotal,evalCompleted,evalTotal};
        // Update existing progress bars in DOM
        const block = document.querySelector('.dept-block [data-dept-block="'+id+'"]')?.closest('.dept-block') || document.querySelector('.dept-block[data-dept-id="'+id+'"]');
        const container = block?.querySelector('.dept-progress');
        if(container){
          container.innerHTML = buildProgressBar(quizCompleted,quizTotal,'Quiz Completion') + buildProgressBar(evalCompleted,evalTotal,'Evaluation Completion');
        }
        // Update individual speaker badges if expanded
        if(block){
          const speakersWrap = block.querySelector('.dept-speakers');
            if(speakersWrap && speakersWrap.classList.contains('show')){
              speakers.forEach(sp=>{
                const item = speakersWrap.querySelector('.speaker-item[data-id="'+sp.id+'"]');
                if(item){
                  const statusLine = item.querySelector('.small.mt-1');
                  if(statusLine){ statusLine.textContent = 'Quiz: '+(sp.quiz_submitted?'✅':'—')+' | Eval: '+(sp.evaluated?'✅':'—'); }
                }
              });
            }
        }
      });
    } catch(e){ /* silent */ }
  }

  function startRealtimeUpdates(){
    // Poll every 25s; can tweak interval based on load
    setInterval(()=>{ refreshProgress(); }, 25000);
    // Also add a visibility listener to refresh when tab returns to front
    document.addEventListener('visibilitychange',()=>{ if(!document.hidden) refreshProgress(); });
  }

  async function renderDepartments(data){
    const titleEl=document.getElementById('dayTitle'); const subEl=document.getElementById('daySubtitle');
    if(!data.day){ titleEl.textContent='Day Not Found'; subEl.textContent=''; return; }
    titleEl.textContent='Sub Program - Day ' + (data.day.label||'');
    subEl.textContent='Select a department to view its speakers.';
    const depts=data.departments||{}; const keys=Object.keys(depts);
    if(keys.length===0){ document.getElementById('emptyState').classList.remove('d-none'); return; }
    const container=document.getElementById('departmentsContainer'); container.innerHTML='';
    keys.sort((a,b)=>{ const an=(depts[a].department_name||'').toLowerCase(); const bn=(depts[b].department_name||'').toLowerCase(); return an.localeCompare(bn); });
    const urlParams = new URLSearchParams(window.location.search);
    const focusDept = urlParams.get('dept');
    const focusSpeaker = urlParams.get('speaker');
    let focusElement = null;
    keys.forEach(async k=>{
      const info=depts[k];
      const deptId = info.department_id || info.id || k;
      const block=document.createElement('div'); block.className='room-block mb-3 dept-block position-relative';
      block.setAttribute('data-dept-id',deptId);
      const speakerList = info.speakers||[];
      const speakerCount=speakerList.length;

      // Compute quiz/eval progress client side (requires flags in API; fallback simple counts)
      let quizCompleted=0, quizTotal=0, evalCompleted=0, evalTotal=0;
      speakerList.forEach(sp=>{
        if(sp.has_quiz){ quizTotal++; if(sp.quiz_submitted) quizCompleted++; }
        if(sp.requires_evaluation || sp.has_evaluation){ evalTotal++; if(sp.evaluated) evalCompleted++; }
      });
      // If API didn't send quiz flags, fallback: treat all speakers w/quiz_submitted flag
      if(quizTotal===0){ quizTotal=speakerCount; quizCompleted=speakerList.filter(s=>s.quiz_submitted).length; }
      if(evalTotal===0){ evalTotal=speakerCount; evalCompleted=speakerList.filter(s=>s.evaluated).length; }

      block.innerHTML='<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center dept-header gap-3" style="cursor:pointer;">'
        +'<div class="flex-grow-1"><h4 class="mb-1 fs-5">'+esc(info.department_name||'Department')+'</h4>'
        +'<div class="small text-light opacity-75">'+speakerCount+' Speaker'+(speakerCount===1?'':'s')+'</div></div>'
        +'<div class="text-end">'
  +'<div class="d-flex flex-column gap-2">'
  +'<div class="btn-group" role="group" style="position:relative;">'
  +'<button class="btn btn-sm btn-outline-warning d-flex align-items-center gap-2 generate-attendance-cert d-none" data-dept="'+deptId+'" disabled><span class="spinner-border spinner-border-sm d-none text-light" data-spin="att" role="status" aria-hidden="true"></span><span data-label="att">Attendance</span></button>'
  +'<button class="btn btn-sm btn-outline-warning d-flex align-items-center gap-2 generate-completion-cert d-none" data-dept="'+deptId+'" disabled><span class="spinner-border spinner-border-sm d-none text-light" data-spin="comp" role="status" aria-hidden="true"></span><span data-label="comp">Completion</span></button>'
  +'<div class="position-absolute top-0 start-0 w-100 h-100 bg-dark bg-opacity-25 d-none" data-overlay="group"></div>'
  +'</div>'
  +'<div class="d-flex flex-column gap-1">'
  +'<button class="btn btn-sm btn-outline-light email-att-cert d-none" data-dept="'+deptId+'" disabled>Email Attendance</button>'
  +'<button class="btn btn-sm btn-outline-light email-comp-cert d-none" data-dept="'+deptId+'" disabled>Email Completion</button>'
  +'</div>'
  +'</div>'
        +'</div>'
        +'</div>'
        +'<div class="dept-progress mt-3">'
        + buildProgressBar(quizCompleted,quizTotal,'Quiz Completion')
        + buildProgressBar(evalCompleted,evalTotal,'Evaluation Completion')
        +'</div>'
        +'<div class="dept-cert-status small mt-2" data-status="'+deptId+'"></div>'
        +'<div class="dept-speakers mt-3 collapse"></div>';
      const speakersWrap=block.querySelector('.dept-speakers');
      const header=block.querySelector('.dept-header');
      header.addEventListener('click',e=>{
        if(e.target.closest('.generate-dept-cert')|| e.target.closest('.email-dept-cert')) return; // ignore button clicks
        const isOpen=speakersWrap.classList.contains('show');
        document.querySelectorAll('.dept-speakers.show').forEach(el=>el.classList.remove('show'));
        if(!isOpen){
          if(!speakersWrap.dataset.rendered){
            const list=document.createElement('div'); list.className='row g-3';
            speakerList.forEach(sp=>{
              const col=document.createElement('div'); col.className='col-md-6 col-lg-4';
              const card=document.createElement('div'); card.className='speaker-item'; card.dataset.id=sp.id;
              let timeTxt='Time TBA';
              if(sp.start_time||sp.end_time){ const st=(sp.start_time||'').substring(0,5); const et=(sp.end_time||''); timeTxt=st+(et?' - '+et.substring(0,5):''); }
              card.innerHTML='<strong class="d-block mb-1">'+esc(sp.name||'')+'</strong>'
                +(sp.topic?'<div class="speaker-title mb-1">'+esc(sp.topic)+'</div>':(sp.title?'<div class="speaker-title mb-1">'+esc(sp.title)+'</div>':''))
                +'<div class="speaker-time mb-1">'+esc(timeTxt)+'</div>'
                +'<div class="small opacity-75">Room: '+esc(sp.room_name||'')+'</div>'
                +'<div class="small mt-1">Quiz: '+(sp.quiz_submitted?'✅':'—')+' | Eval: '+(sp.evaluated?'✅':'—')+'</div>';
              card.addEventListener('click',()=>{ window.location='sub_speaker_form.php?id='+encodeURIComponent(sp.id)+'&dept='+encodeURIComponent(deptId); });
              col.appendChild(card); list.appendChild(col);
            });
            speakersWrap.appendChild(list); speakersWrap.dataset.rendered='1';
          }
          speakersWrap.classList.add('show');
          if(focusSpeaker){
            // Try highlight the specific speaker after render
            setTimeout(()=>{
              const target = speakersWrap.querySelector('.speaker-item[data-id="'+CSS.escape(focusSpeaker)+'"]');
              if(target){ target.classList.add('border','border-3','border-warning'); target.scrollIntoView({behavior:'smooth',block:'center'}); }
            },120);
          }
          // On open, force immediate refresh for this department to show latest statuses
          refreshProgress(deptId);
        }
      });
      container.appendChild(block);

      // Fetch certificate status + enable generate/email if complete
      const statusEl = block.querySelector('[data-status]');
  const btnAtt = block.querySelector('.generate-attendance-cert');
  const btnComp = block.querySelector('.generate-completion-cert');
  const btnEmailAtt = block.querySelector('.email-att-cert');
  const btnEmailComp = block.querySelector('.email-comp-cert');
  const overlay = block.querySelector('[data-overlay="group"]');
      const status = await fetchDepartmentCertificateStatus(deptId);
      if(status && status.ok){
        const prog = status.progress || {completed:0,total:0};
  const attendanceTemplateAssigned = !!(status.attendance && status.attendance.template_assigned);
        const attendanceGenerated = !!(status.attendance && status.attendance.certificate);
        const completionGenerated = !!status.certificate;
  const completionTemplateAssigned = !!status.completion_template_assigned;

        // Completion button only if template exists
        if(completionTemplateAssigned){
          btnComp.classList.remove('d-none');
          if(status.complete){
            btnComp.disabled=false; btnComp.classList.remove('btn-outline-warning'); btnComp.classList.add('btn-warning');
            if(completionGenerated){
              btnComp.textContent='View Completion';
              btnComp.dataset.file='../'+status.certificate.file_path;
            } else {
              btnComp.textContent='Generate Completion';
            }
          } else {
            btnComp.disabled=true; btnComp.textContent='Completion Locked';
          }
        }
        // Attendance gated by full completion (progress complete AND attendance template)
        if(attendanceTemplateAssigned && status.complete){
          btnAtt.classList.remove('d-none');
          if(attendanceGenerated){
            btnAtt.disabled=false; btnAtt.classList.remove('btn-outline-warning'); btnAtt.classList.add('btn-warning'); btnAtt.textContent='View Attendance'; btnAtt.dataset.file='../'+status.attendance.certificate.file_path;
          } else {
            btnAtt.disabled=false; btnAtt.classList.remove('btn-outline-warning'); btnAtt.classList.add('btn-warning'); btnAtt.textContent='Generate Attendance';
          }
        }
        // Status summary line
        if(completionGenerated){
          statusEl.innerHTML='<span class="text-info">Completion certificate generated.</span>';
        } else if(status.complete && completionTemplateAssigned){
          statusEl.innerHTML='<span class="text-success">All requirements met. Generate completion certificate.</span>';
        } else if(status.complete && !completionTemplateAssigned){
          statusEl.innerHTML='<span class="text-warning">Completion layout not assigned for this department.</span>';
        } else {
          statusEl.innerHTML='<span class="text-light">Progress '+prog.completed+' / '+prog.total+' complete.</span>';
        }
        if(attendanceGenerated){ btnEmailAtt.classList.remove('d-none'); btnEmailAtt.disabled=false; }
        if(completionGenerated){ btnEmailComp.classList.remove('d-none'); btnEmailComp.disabled=false; }
      } else {
        statusEl.textContent='Unable to load certificate status.';
      }

      // Attendance generation/view
      function setBusy(kind,busy){
        const spin = block.querySelector('[data-spin="'+kind+'"]');
        const label = block.querySelector('[data-label="'+kind+'"]');
        const btn = kind==='att'?btnAtt:btnComp;
        if(!spin || !label) return;
        if(busy){
          if(!label.dataset.orig) label.dataset.orig = label.textContent;
          spin.classList.remove('d-none');
          label.textContent = 'Generating...';
          label.classList.add('opacity-75');
          overlay?.classList.remove('d-none');
          // Disable both buttons to avoid overlapping generation
          btnAtt.disabled=true; btnComp.disabled=true;
          btn.setAttribute('aria-busy','true');
        } else {
          spin.classList.add('d-none');
          btn.removeAttribute('aria-busy');
          if(!btn.dataset.file && label.dataset.orig){
            // Only restore original text if we did not convert to View state yet
            label.textContent = label.dataset.orig;
          }
          label.classList.remove('opacity-75');
          if(!(block.querySelector('[data-spin="att"]:not(.d-none)')||block.querySelector('[data-spin="comp"]:not(.d-none)'))){
            overlay?.classList.add('d-none');
          }
        }
      }

      async function syncStatus(){
        const s = await fetchDepartmentCertificateStatus(deptId).catch(()=>null);
        if(!s || !s.ok) return;
  const attendanceTemplateAssigned = !!(s.attendance && s.attendance.template_assigned);
        const attendanceGenerated = !!(s.attendance && s.attendance.certificate);
        const completionGenerated = !!s.certificate;
  const completionTemplateAssigned = !!s.completion_template_assigned;
        // Attendance
        // Completion visibility
        if(completionTemplateAssigned){
          btnComp.classList.remove('d-none');
          if(s.complete){
            btnComp.classList.remove('btn-outline-warning'); btnComp.classList.add('btn-warning');
            if(completionGenerated){ btnComp.disabled=false; btnComp.dataset.file='../'+s.certificate.file_path; btnComp.querySelector('[data-label="comp"]').textContent='View Completion'; btnEmailComp.classList.remove('d-none'); btnEmailComp.disabled=false; }
            else { btnComp.disabled=false; btnComp.querySelector('[data-label="comp"]').textContent='Generate Completion'; }
          } else {
            btnComp.disabled=true; btnComp.querySelector('[data-label="comp"]').textContent='Completion Locked';
          }
        }
        // Attendance gated
        if(attendanceTemplateAssigned && s.complete){
          btnAtt.classList.remove('d-none');
          if(attendanceGenerated){ btnAtt.disabled=false; btnAtt.classList.remove('btn-outline-warning'); btnAtt.classList.add('btn-warning'); btnAtt.dataset.file='../'+s.attendance.certificate.file_path; btnAtt.querySelector('[data-label="att"]').textContent='View Attendance'; btnEmailAtt.classList.remove('d-none'); btnEmailAtt.disabled=false; }
          else { btnAtt.disabled=false; btnAtt.classList.remove('btn-outline-warning'); btnAtt.classList.add('btn-warning'); btnAtt.querySelector('[data-label="att"]').textContent='Generate Attendance'; }
        }
      }

      btnAtt.addEventListener('click', async ()=>{
        if(btnAtt.dataset.file){ window.open(btnAtt.dataset.file,'_blank'); return; }
        if(btnAtt.disabled) return;
  btnAtt.disabled=true; setBusy('att',true); statusEl.innerHTML='<span class="text-light">Generating attendance certificate...</span>';
        try {
          const r = await fetch('../api/sub_department_certificate.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({department_id:deptId,kind:'attendance'})});
          let j=null; try { j = await r.clone().json(); } catch(parseErr){ const raw=await r.text().catch(()=>'(no body)'); console.error('Attendance raw response:',raw); }
          if(!r.ok || !j || !j.ok){
            // Fallback reconciliation: maybe generated but response polluted by warnings
            const follow = await fetchDepartmentCertificateStatus(deptId);
            if(follow && follow.attendance && follow.attendance.certificate){
              btnAtt.dataset.file='../'+follow.attendance.certificate.file_path; btnAtt.disabled=false; btnAtt.querySelector('[data-label="att"]').textContent='View Attendance'; statusEl.innerHTML='<span class="text-warning">Attendance generated (detected) despite response error.</span>'; btnEmailAtt.classList.remove('d-none'); btnEmailAtt.disabled=false; syncStatus();
            } else {
              btnAtt.disabled=false; statusEl.innerHTML='<span class="text-danger">'+(j && j.error?j.error:'Attendance generation failed')+'</span>';
            }
          } else {
            btnAtt.dataset.file='../'+j.file_path; btnAtt.disabled=false; btnAtt.querySelector('[data-label="att"]').textContent='View Attendance'; statusEl.innerHTML='<span class="text-info">Attendance certificate generated.</span>'; btnEmailAtt.classList.remove('d-none'); btnEmailAtt.disabled=false; syncStatus();
          }
        } catch(e){
          console.error('Attendance fetch error',e);
          // Reconcile after network exception
            const follow = await fetchDepartmentCertificateStatus(deptId).catch(()=>null);
            if(follow && follow.attendance && follow.attendance.certificate){
              btnAtt.dataset.file='../'+follow.attendance.certificate.file_path; btnAtt.disabled=false; btnAtt.querySelector('[data-label="att"]').textContent='View Attendance'; statusEl.innerHTML='<span class="text-warning">Attendance generated (detected) after network error.</span>'; btnEmailAtt.classList.remove('d-none'); btnEmailAtt.disabled=false; syncStatus();
            } else {
              btnAtt.disabled=false; statusEl.innerHTML='<span class="text-danger">Network error (attendance)</span>';
            }
        }
  finally { setBusy('att',false); syncStatus(); }
      });

      // Completion generation/view
      btnComp.addEventListener('click', async ()=>{
        if(btnComp.dataset.file){ window.open(btnComp.dataset.file,'_blank'); return; }
        if(btnComp.disabled || btnComp.textContent.includes('Locked')) return;
  btnComp.disabled=true; setBusy('comp',true); statusEl.innerHTML='<span class="text-light">Generating completion certificate...</span>';
        try {
          const r = await fetch('../api/sub_department_certificate.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({department_id:deptId,kind:'completion'})});
          let j=null; try { j = await r.clone().json(); } catch(parseErr){ const raw=await r.text().catch(()=>'(no body)'); console.error('Completion raw response:',raw); }
          if(!r.ok || !j || !j.ok){
            const follow = await fetchDepartmentCertificateStatus(deptId);
            if(follow && follow.certificate){
              btnComp.dataset.file='../'+follow.certificate.file_path; btnComp.disabled=false; btnComp.querySelector('[data-label="comp"]').textContent='View Completion'; statusEl.innerHTML='<span class="text-warning">Completion generated (detected) despite response error.</span>'; btnEmailComp.classList.remove('d-none'); btnEmailComp.disabled=false; syncStatus();
            } else {
              btnComp.disabled=false; statusEl.innerHTML='<span class="text-danger">'+(j && j.error?j.error:'Completion generation failed')+'</span>';
            }
          } else {
            btnComp.dataset.file='../'+j.file_path; btnComp.disabled=false; btnComp.querySelector('[data-label="comp"]').textContent='View Completion'; statusEl.innerHTML='<span class="text-info">Completion certificate generated.</span>'; btnEmailComp.classList.remove('d-none'); btnEmailComp.disabled=false; syncStatus();
          }
        } catch(e){
          console.error('Completion fetch error',e);
            const follow = await fetchDepartmentCertificateStatus(deptId).catch(()=>null);
            if(follow && follow.certificate){
              btnComp.dataset.file='../'+follow.certificate.file_path; btnComp.disabled=false; btnComp.querySelector('[data-label="comp"]').textContent='View Completion'; statusEl.innerHTML='<span class="text-warning">Completion generated (detected) after network error.</span>'; btnEmailComp.classList.remove('d-none'); btnEmailComp.disabled=false; syncStatus();
            } else {
              btnComp.disabled=false; statusEl.innerHTML='<span class="text-danger">Network error (completion)</span>';
            }
        }
  finally { setBusy('comp',false); syncStatus(); }
      });
      btnEmailAtt.addEventListener('click', async ()=>{
        if(btnEmailAtt.disabled) return; const orig=btnEmailAtt.textContent; btnEmailAtt.textContent='Sending...'; btnEmailAtt.disabled=true;
        try { const r=await fetch('../api/sub_department_attendance_certificate_email.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({department_id:deptId})}); const j=await r.json(); if(!r.ok||!j.ok){ btnEmailAtt.textContent=orig; btnEmailAtt.disabled=false; statusEl.innerHTML='<span class="text-danger">'+(j.error||'Attendance email failed')+'</span>'; return;} btnEmailAtt.textContent='Sent'; } catch(e){ btnEmailAtt.textContent=orig; btnEmailAtt.disabled=false; statusEl.innerHTML='<span class="text-danger">Network error email attendance</span>'; }
      });
      btnEmailComp.addEventListener('click', async ()=>{
        if(btnEmailComp.disabled) return; const orig=btnEmailComp.textContent; btnEmailComp.textContent='Sending...'; btnEmailComp.disabled=true;
        try { const r=await fetch('../api/sub_department_certificate_email.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({department_id:deptId})}); const j=await r.json(); if(!r.ok||!j.ok){ btnEmailComp.textContent=orig; btnEmailComp.disabled=false; statusEl.innerHTML='<span class="text-danger">'+(j.error||'Completion email failed')+'</span>'; return;} btnEmailComp.textContent='Sent'; } catch(e){ btnEmailComp.textContent=orig; btnEmailComp.disabled=false; statusEl.innerHTML='<span class="text-danger">Network error email completion</span>'; }
      });
      if(focusDept && String(focusDept) === String(deptId)){
        focusElement = block.querySelector('.dept-header');
        // auto-open after status + speaker population
        setTimeout(()=>{ focusElement?.click(); block.scrollIntoView({behavior:'smooth',block:'start'}); }, 120);
      }
    });
  }
  fetch('../api/sub_program_departments.php?day_id='+encodeURIComponent(dayId))
    .then(r=>{ if(!r.ok) throw new Error('Failed to load'); return r.json(); })
    .then(d=>{ renderDepartments(d); startRealtimeUpdates(); })
    .catch(err=>{ console.error(err); document.getElementById('daySubtitle').textContent='Failed to load departments.'; });
</script>
</body>
</html>
