<?php
// Dynamic per-speaker evaluation (generic question set) replacing legacy fixed 4-scale form
session_start();
require_once __DIR__ . '/../db.php';
if (isset($conn) && $conn instanceof mysqli) $db = $conn; elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli; else { http_response_code(500); echo 'DB unavailable'; exit; }
if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); } }
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0; if ($user_id<=0){ header('Location: ../Credentials/login.php'); exit; }
$speaker_id = isset($_GET['speaker_id']) ? (int)$_GET['speaker_id'] : 0; if ($speaker_id<=0){ http_response_code(400); echo 'Invalid speaker.'; exit; }
$spk = ['name'=>'Speaker','title'=>'','program_type'=>''];
if ($st=$db->prepare('SELECT name,title,program_type FROM speakers WHERE id=? LIMIT 1')) { $st->bind_param('i',$speaker_id); $st->execute(); $st->bind_result($spk['name'],$spk['title'],$spk['program_type']); $st->fetch(); $st->close(); }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Speaker Evaluation — <?= e($spk['name']) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;700&display=swap" rel="stylesheet">
  <style>
    :root{
      --gf-primary:#111e6c;      /* Google Forms purple */
      --gf-primary-d:#111e6c;
      --gf-bg:#f1f3f4;
      --gf-card:#ffffff;
      --gf-text:#202124;
      --gf-muted:#5f6368;
      --gf-chip:#e8eaed;
      --gf-chip-text:#202124;
      --gf-chip-active:#e8def8;
      --gf-danger:#d93025;
    }
    body.gf-body{ background:var(--gf-bg); color:var(--gf-text); font-family:'Poppins';}
    .gf-header{
      background:linear-gradient(var(--gf-primary));
      color:#fff; min-height:160px; display:flex; align-items:flex-end;
    }
    .gf-header .inner{ width:100%; max-width:720px; margin:0 auto; padding:24px 16px; }
    .gf-header .back-link{ color:#fff; opacity:.9; text-decoration:none; }
    .gf-header .back-link:hover{ opacity:1; text-decoration:underline; }

    .gf-wrap{ max-width:720px; margin:-64px auto 40px; padding:0 16px; padding-top:70px;}
    .gf-card{
      background:var(--gf-card);
      border-radius:8px;
      box-shadow:0 1px 2px rgba(0,0,0,.08), 0 1px 3px rgba(0,0,0,.06);
      margin-bottom:14px;
      overflow:hidden;
    }
    .gf-card .section{ padding:22px 24px; }
    .gf-title{ font-size:1.6rem; font-weight:700; margin:0 0 6px; }
    .gf-sub{ color:var(--gf-muted); margin:0; }
    .gf-required-note{ color:var(--gf-danger); font-size:.9rem; margin-top:10px; }

    .q-title{ font-weight:500; margin-bottom:10px; }
    .q-required::after{ content:" *"; color:var(--gf-danger); }
    .chip-group{ display:flex; flex-wrap:wrap; gap:10px; }
    .chip-input{ position:absolute; opacity:0; pointer-events:none; }
    .chip{
      display:inline-flex; align-items:center; justify-content:center;
      min-width:44px; height:40px; padding:0 14px; border-radius:20px;
      background:var(--gf-chip); color:var(--gf-chip-text);
      border:2px solid transparent; cursor:pointer; user-select:none;
      transition:all .15s ease;
      font-weight:500;
    }
    .chip:hover{ filter:brightness(.98); }
    .chip-input:checked + .chip{
      background:var(--gf-chip-active);
      border-color:var(--gf-primary);
      color:var(--gf-text);
      box-shadow:inset 0 0 0 1px var(--gf-primary);
    }

    .gf-textarea{ resize:vertical; min-height:100px; }

    .gf-actions{ padding:16px 24px 24px; display:flex; justify-content:flex-start; gap:12px; }
    .btn-primary.gf{
      background:var(--gf-primary); border-color:var(--gf-primary);
      border-radius:24px; padding:.6rem 1.3rem; font-weight:600;
    }
    .btn-primary.gf:hover{ background:var(--gf-primary-d); border-color:var(--gf-primary-d); }

    .gf-alert{ margin-bottom:12px; border-radius:8px; }
    .done-card{ border:1px solid #a5d6a7; background:#e8f5e9; color:#1b5e20; }
  </style>
</head>
<body class="gf-body">
  <div class="gf-header">
    <div class="inner">
  <?php $isSub = (isset($_GET['sub']) && $_GET['sub']=='1') || strcasecmp($spk['program_type'],'sub')===0; ?>
  <a class="back-link small" href="<?= $isSub ? 'sub_speaker_form.php' : 'speaker_form.php' ?>?id=<?= (int)$speaker_id ?>">&lt; Back to session</a>
      <h1 class="mt-2 mb-0">Speaker Evaluation</h1>
      <p class="mb-0"><?= e($spk['name']) ?><?= $spk['title'] ? ' — ' . e($spk['title']) : '' ?></p>
    </div>
  </div>

  <div class="gf-wrap">
    <div id="alert" class="alert d-none gf-alert" role="alert"></div>

    <!-- Intro card (like Google Forms header card) -->
    <div class="gf-card mt-5">
      <div class="section">
        <div class="gf-title mb-2">Session Feedback</div>
        <div class="small mb-2" id="speakerMeta">
          Evaluating: <strong><?= e($spk['name']) ?></strong>
          <?php if($spk['title']): ?>
            <span class="text-muted">(<?= e($spk['title']) ?>)</span>
          <?php endif; ?>
          <span class="badge bg-<?= strtolower($spk['program_type'])==='sub' ? 'warning text-dark' : 'primary' ?> ms-2" style="font-size:.65rem; letter-spacing:.5px;"><?= strtoupper($spk['program_type'] ?: 'MAIN') ?></span>
        </div>
        <p class="gf-sub mb-1">Please rate the speaker and share any comments to help us improve.</p>
        <div class="small text-muted mb-2" id="questionCount" style="font-weight:500;"></div>
        <div class="gf-required-note">* Required</div>
      </div>
    </div>

    <!-- Dynamic Form card -->
    <div class="gf-card" id="formCard">
      <div class="section">
        <form id="evalForm" novalidate>
          <input type="hidden" id="speakerId" value="<?= (int)$speaker_id ?>">
          <div id="questionsWrap"></div>
          <div class="gf-actions">
            <button class="btn btn-primary gf" type="submit" id="submitBtn">Submit</button>
            <a class="btn btn-light" href="<?= $isSub ? 'sub_speaker_form.php' : 'speaker_form.php' ?>?id=<?= (int)$speaker_id ?>">Cancel</a>
          </div>
        </form>
      </div>
    </div>
    <div id="doneCard" class="gf-card d-none">
      <div class="section done-card">
        <h5 class="mb-1">Thank you!</h5>
        <p class="mb-0">Your evaluation has been recorded.</p>
      </div>
    </div>
  </div>

<script>
const alertEl=document.getElementById('alert');
function showAlert(msg,type='info'){ alertEl.className='alert alert-'+type+' gf-alert'; alertEl.textContent=msg; alertEl.classList.remove('d-none'); }
const submitBtn=document.getElementById('submitBtn');
const doneCard=document.getElementById('doneCard');
const formCard=document.getElementById('formCard');
const form=document.getElementById('evalForm');
const questionsWrap=document.getElementById('questionsWrap');
const speakerId=<?= (int)$speaker_id ?>;
let questions=[];

async function preloadStatus(){
  try{ const r=await fetch('../api/speaker_eval_get.php?speaker_id='+speakerId,{credentials:'same-origin'}); if(!r.ok) return; const data=await r.json(); if(data.evaluated){ formCard.classList.add('d-none'); doneCard.classList.remove('d-none'); showAlert('You already submitted an evaluation for this speaker.','warning'); } }catch(e){}
}
async function loadQuestions(){ questionsWrap.innerHTML='<div class="text-muted">Loading questions...</div>'; try{ const r=await fetch('../api/speaker_eval_questions.php',{credentials:'same-origin'}); const data=await r.json(); if(!data.ok) throw new Error(data.error||'Load failed'); questions=data.questions||[]; renderQuestions(); }catch(e){ questionsWrap.innerHTML='<div class="text-danger">'+e.message+'</div>'; } }
function renderQuestions(){
  if(!questions.length){
    questionsWrap.innerHTML='<p class="text-muted">No active questions.</p>';
    submitBtn.disabled=true;
    document.getElementById('questionCount').textContent='';
    return;
  }
  submitBtn.disabled=false;
  const frag=document.createDocumentFragment();
  questions.forEach(q=>frag.appendChild(renderQuestion(q)));
  questionsWrap.innerHTML='';
  questionsWrap.appendChild(frag);
  // Update question count display
  const qc=document.getElementById('questionCount');
  if(qc){
    qc.textContent = 'Total Questions: '+questions.length;
  }
}
function renderQuestion(q){ const wrap=document.createElement('div'); wrap.className='mb-4'; const req=q.is_required==1; const title='<div class="q-title'+(req?' q-required':'')+'">'+escapeHtml(q.question_text)+'</div>'; let control=''; switch(q.question_type){ case 'scale': control=renderScale(q); break; case 'text': control='<input type="text" class="form-control" name="q_'+q.id+'" '+(req?'required':'')+' maxlength="500">'; break; case 'textarea': control='<textarea class="form-control gf-textarea" name="q_'+q.id+'" '+(req?'required':'')+' maxlength="2000"></textarea>'; break; case 'multiple_choice_single': control=renderOptions(q,'radio'); break; case 'multiple_choice_multi': control=renderOptions(q,'checkbox'); break; case 'dropdown': control=renderDropdown(q); break; case 'checkbox': control='<div class="form-check"><input class="form-check-input" type="checkbox" name="q_'+q.id+'" id="chk_'+q.id+'"><label class="form-check-label" for="chk_'+q.id+'">Yes</label></div>'; break; default: control='<div class="text-muted">Unsupported</div>'; } wrap.innerHTML=title+control; return wrap; }
function renderScale(q){ const min=q.min_scale||1,max=q.max_scale||5; const left=escapeHtml(q.scale_label_left||''); const right=escapeHtml(q.scale_label_right||''); let chips=''; for(let i=min;i<=max;i++){ const id='q_'+q.id+'_'+i; chips+=`<input class="chip-input" type="radio" name="q_${q.id}" id="${id}" value="${i}" ${(q.is_required==1)?'required':''}><label class="chip" for="${id}">${i}</label>`; } return `<div class="chip-group">${chips}</div><div class="small text-muted mt-1">${left} <span class="mx-1">↔</span> ${right}</div>`; }
function renderOptions(q,mode){ const opts=q.options||[]; if(!opts.length) return '<div class="text-muted small">No options</div>'; const type=mode==='radio'?'radio':'checkbox'; let html=''; opts.forEach(o=>{ const id='q_'+q.id+'_opt_'+o.id; html+=`<div class="form-check"><input class="form-check-input" type="${type}" name="q_${q.id}${type==='checkbox'?'[]':''}" id="${id}" value="${o.id}" ${(q.is_required==1 && type==='radio')?'required':''}><label class="form-check-label" for="${id}">${escapeHtml(o.option_text)}</label></div>`; }); return html; }
function renderDropdown(q){ const opts=q.options||[]; if(!opts.length) return '<div class="text-muted small">No options</div>'; let html=`<select class="form-select" name="q_${q.id}" ${(q.is_required==1)?'required':''}><option value="">-- Select --</option>`; opts.forEach(o=>{ html+=`<option value="${o.id}">${escapeHtml(o.option_text)}</option>`; }); html+='</select>'; return html; }
function escapeHtml(s){ return (s||'').replace(/[&<>"']/g,c=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;' }[c])); }
function collectAnswers(){ const out=[]; for(const q of questions){ const name='q_'+q.id; if(['scale','multiple_choice_single','dropdown'].includes(q.question_type)){ let el=form.querySelector(`[name="${name}"]:checked`); if(q.question_type==='dropdown') el=form.querySelector(`select[name="${name}"]`); let val=null; if(q.question_type==='dropdown'){ if(el && el.value!=='') val=parseInt(el.value,10); } else if(el) val=parseInt(el.value,10); if(val!==null) out.push({question_id:q.id,value:val}); } else if(['text','textarea'].includes(q.question_type)){ const el=form.querySelector(`[name="${name}"]`); const v=el.value.trim(); if(v!=='') out.push({question_id:q.id,value:v}); } else if(q.question_type==='multiple_choice_multi'){ const els=[...form.querySelectorAll(`input[name="${name}[]"]:checked`)]; const arr=els.map(e=>parseInt(e.value,10)); if(arr.length) out.push({question_id:q.id,value:arr}); } else if(q.question_type==='checkbox'){ const el=form.querySelector(`[name="${name}"]`); out.push({question_id:q.id,value:el.checked?1:0}); } } return out; }
form.addEventListener('submit', async e=>{ e.preventDefault(); alertEl.classList.add('d-none'); for(const q of questions){ if(q.is_required==1){ const name='q_'+q.id; let ok=false; switch(q.question_type){ case 'scale': case 'multiple_choice_single': ok=!!form.querySelector(`[name="${name}"]:checked`); break; case 'dropdown': const sel=form.querySelector(`select[name="${name}"]`); ok=sel && sel.value!==''; break; case 'text': case 'textarea': const el=form.querySelector(`[name="${name}"]`); ok=el.value.trim()!==''; break; case 'multiple_choice_multi': ok=form.querySelectorAll(`input[name="${name}[]"]:checked`).length>0; break; case 'checkbox': ok=true; break; } if(!ok){ showAlert('Please complete all required questions.','warning'); return; } } } const answers=collectAnswers(); if(!answers.length){ showAlert('No answers to submit.','warning'); return; } submitBtn.disabled=true; submitBtn.textContent='Submitting...'; try { const res=await fetch('../api/speaker_eval_submit.php',{ method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-CSRF-Token':<?= json_encode($csrf) ?>}, body: JSON.stringify({speaker_id:speakerId,answers}) }); const data=await res.json(); if(res.status===403){ showAlert(data.error||'Please complete the quiz first.','danger'); return; } if(res.status===409){ showAlert('You already submitted an evaluation for this speaker.','warning'); formCard.classList.add('d-none'); doneCard.classList.remove('d-none'); return; } if(!res.ok || !data.ok){ showAlert(data.error||'Submission failed.','danger'); return; } formCard.classList.add('d-none'); doneCard.classList.remove('d-none'); showAlert('Evaluation submitted successfully.','success'); } catch(err){ showAlert('Network error. Please try again.','danger'); } finally { submitBtn.disabled=false; submitBtn.textContent='Submit'; } });
preloadStatus();
loadQuestions();
</script>
</body>
</html>