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
  async function fetchDepartmentProgressStatus(deptId, dayId=0){
    try {
      const params = new URLSearchParams({ department_id: String(deptId) });
      if (dayId > 0) params.append('day_id', String(dayId));
      const r = await fetch('../api/sub_department_progress.php?'+params.toString(),{credentials:'same-origin'});
      if(!r.ok) return null; return await r.json();
    } catch(e){ return null; }
  }

  function buildProgressBar(completed,total,label){
    const pct = total>0 ? Math.round((completed/total)*100) : 0;
    return '<div class="mb-2"><div class="d-flex justify-content-between small"><span>'+label+'</span><span>'+completed+'/'+total+'</span></div>'+
      '<div class="progress" style="height:8px;background:rgba(255,255,255,0.15);"><div class="progress-bar bg-warning" role="progressbar" style="width:'+pct+'%" aria-valuenow="'+pct+'" aria-valuemin="0" aria-valuemax="100"></div></div></div>';
  }

  function summarizePending(pending){
    if(!Array.isArray(pending) || pending.length===0) return '';
    var maxShow = 3;
    var items = pending.slice(0,maxShow).map(function(p){
      var hasLabel = p && typeof p.speaker_label==='string' && p.speaker_label.trim()!=='';
      var fallbackId = (p && typeof p.speaker_id==='number') ? p.speaker_id : '?';
      var label = hasLabel ? p.speaker_label.trim() : ('Speaker #'+fallbackId);
      var needs=[];
      if(p && p.needs_eval) needs.push('evaluation');
      if(p && p.needs_quiz) needs.push('quiz');
      var suffix = needs.length?(' ('+needs.join(' & ')+')'):'';
      return label+suffix;
    });
    var msg = 'Still needed: '+items.join(', ');
    if(pending.length>maxShow){ msg += ', +'+(pending.length-maxShow)+' more'; }
    return msg;
  }

  function summarizeSkipped(skippedSummary){
    if(!skippedSummary || typeof skippedSummary!=='object') return '';
    var labels={
      'day_future':'future sessions',
      'day_disabled':'disabled days',
      'room_inactive':'inactive rooms',
      'room_missing':'unscheduled sessions',
      'day_other':'sessions on other days',
      'day_unassigned':'unscheduled day'
    };
    var entries = Object.keys(skippedSummary);
    if(!entries.length) return '';
    var parts = entries.map(function(key){
      var count = skippedSummary[key];
      if(typeof count!=='number') count = 0;
      var label = labels[key] || key.replace(/_/g,' ');
      return count+' '+label;
    });
    return 'Waiting on '+parts.join(', ');
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
  const possible = document.querySelector('.dept-block [data-dept-block="'+id+'"]');
  const block = possible ? possible.closest('.dept-block') : document.querySelector('.dept-block[data-dept-id="'+id+'"]');
  const container = block ? block.querySelector('.dept-progress') : null;
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
    const dayLabel = (data.day.label||'').toString().trim();
    const selectedText = dayLabel ? ('Selected ' + dayLabel) : ('Selected Day ' + String(<?= (int)$dayId ?>));
    titleEl.textContent='Sub Program - ' + (dayLabel || '');
    subEl.textContent= selectedText + ' • Select a department to view its speakers.';
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
        +'<div class="text-end"><span class="badge bg-warning text-dark small">Progress</span></div>'
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
                const finished = (sp.finished === 1 || sp.finished === '1' || sp.finished === true);
                const badge = finished ? '<span class="badge bg-success ms-2">Finished</span>' : '';
                card.innerHTML='<div class="d-flex justify-content-between align-items-start mb-1">'
                  +'<div class="d-flex align-items-center"><strong>'+esc(sp.name||'')+'</strong>'+(badge?badge:'')+'</div>'
                  +'</div>'
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

      // Fetch certificate status for summary display
      const statusEl = block.querySelector('[data-status]');
    const status = await fetchDepartmentProgressStatus(deptId, dayId);
      if(status && status.ok){
        const prog = status.progress || {};
        const progressWrap = block.querySelector('.dept-progress');
        if (progressWrap) {
          const qp = Number(prog.quiz_completed ?? 0);
          const qt = Number(prog.quiz_total ?? 0);
          const ep = Number(prog.eval_completed ?? 0);
          const et = Number(prog.eval_total ?? 0);
          progressWrap.innerHTML = buildProgressBar(qp, qt, 'Quiz Completion') + buildProgressBar(ep, et, 'Evaluation Completion');
        }

        const summary = status.summary || {};
        const pendingMsg = summarizePending(prog.pending_detail);
        const skippedMsg = summarizeSkipped(prog.skipped_summary);
        const defaultProgressMsg = 'Progress ' + (prog.completed ?? 0) + ' / ' + (prog.total ?? 0) + ' complete.';
        let messageText = (typeof summary.text === 'string' && summary.text.trim() !== '') ? summary.text.trim() : defaultProgressMsg;

        if(messageText === defaultProgressMsg){
          if(pendingMsg){
            messageText = pendingMsg;
          } else if((prog.total ?? 0) === 0){
            messageText = skippedMsg || 'No active sessions available yet.';
          } else if(skippedMsg){
            messageText += ' ' + skippedMsg;
          }
        }

        const allowedVariants = ['success','warning','info','danger','light','muted'];
        let variant = typeof summary.variant === 'string' ? summary.variant.trim().toLowerCase() : '';
        if(!allowedVariants.includes(variant)){ variant = 'light'; }
        const variantClass = variant === 'muted' ? 'text-muted' : ('text-' + variant);

        statusEl.textContent = messageText;
        statusEl.className = 'dept-cert-status small mt-2 ' + variantClass;
      } else {
        statusEl.textContent = 'Unable to load certificate status.';
        statusEl.className = 'dept-cert-status small mt-2 text-danger';
      }
      if(focusDept && String(focusDept) === String(deptId)){
        focusElement = block.querySelector('.dept-header');
        // auto-open after status + speaker population
  setTimeout(()=>{ if(focusElement){ focusElement.click(); block.scrollIntoView({behavior:'smooth',block:'start'}); } }, 120);
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
