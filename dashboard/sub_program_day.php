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
        +'<button class="btn btn-sm btn-outline-warning generate-dept-cert" data-dept="'+deptId+'" disabled>Generate Certificate</button><br>'
        +'<button class="btn btn-sm btn-outline-light mt-2 email-dept-cert d-none" data-dept="'+deptId+'" disabled>Email Certificate</button>'
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
                +(sp.title?'<div class="speaker-title mb-1">'+esc(sp.title)+'</div>':'')
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
        }
      });
      container.appendChild(block);

      // Fetch certificate status + enable generate/email if complete
      const statusEl = block.querySelector('[data-status]');
      const btnGen = block.querySelector('.generate-dept-cert');
      const btnEmail = block.querySelector('.email-dept-cert');
      const status = await fetchDepartmentCertificateStatus(deptId);
      if(status && status.ok){
        const prog = status.progress || {completed:0,total:0};
        if(status.complete){
          btnGen.disabled=false; btnGen.classList.remove('btn-outline-warning'); btnGen.classList.add('btn-warning');
          statusEl.innerHTML = status.certificate ? '<span class="text-info">Certificate Generated</span>' : '<span class="text-success">All sessions complete. Ready to generate.</span>';
        } else {
          statusEl.innerHTML = '<span class="text-warning">Completed '+prog.completed+' of '+prog.total+' required evaluations.</span>';
        }
        if(status.certificate){
          btnGen.textContent='View Certificate';
          btnGen.dataset.file = '../'+status.certificate.file_path;
          btnEmail.classList.remove('d-none'); btnEmail.disabled=false;
        }
      } else {
        statusEl.textContent='Unable to load certificate status.';
      }

      btnGen.addEventListener('click', async ()=>{
        if(btnGen.dataset.file){ window.open(btnGen.dataset.file,'_blank'); return; }
        if(btnGen.disabled) return;
        const orig = btnGen.textContent; btnGen.textContent='Generating...'; btnGen.disabled=true;
        try {
          const r = await fetch('../api/sub_department_certificate.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({department_id:deptId})});
          const j = await r.json();
          if(!r.ok || !j.ok){ btnGen.textContent=orig; btnGen.disabled=false; statusEl.innerHTML='<span class="text-danger">'+(j.error||'Generation failed')+'</span>'; return; }
          btnGen.textContent='View Certificate'; btnGen.dataset.file='../'+j.file_path; btnGen.disabled=false;
          statusEl.innerHTML='<span class="text-info">Certificate Generated</span>';
          btnEmail.classList.remove('d-none'); btnEmail.disabled=false;
        } catch(e){ btnGen.textContent=orig; btnGen.disabled=false; statusEl.innerHTML='<span class="text-danger">Network error</span>'; }
      });
      btnEmail.addEventListener('click', async ()=>{
        if(btnEmail.disabled) return;
        const orig = btnEmail.textContent; btnEmail.textContent='Sending...'; btnEmail.disabled=true;
        try {
          const r = await fetch('../api/sub_department_certificate_email.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({department_id:deptId})});
          const j = await r.json();
          if(!r.ok || !j.ok){ btnEmail.textContent=orig; btnEmail.disabled=false; statusEl.innerHTML='<span class="text-danger">'+(j.error||'Email failed')+'</span>'; return; }
          btnEmail.textContent='Email Sent';
        } catch(e){ btnEmail.textContent=orig; btnEmail.disabled=false; statusEl.innerHTML='<span class="text-danger">Network error sending email</span>'; }
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
    .then(renderDepartments)
    .catch(err=>{ console.error(err); document.getElementById('daySubtitle').textContent='Failed to load departments.'; });
</script>
</body>
</html>
