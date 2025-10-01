<?php
session_start();
require_once __DIR__ . '/../db.php';
if (isset($conn) && $conn instanceof mysqli) $db = $conn; elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli; else { http_response_code(500); echo 'DB unavailable'; exit; }
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0; if ($user_id<=0){ header('Location: ../Credentials/login.php'); exit; }
function e($s){ return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Certificates</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {--primary-color:#111e6c;--secondary-color:#e27900ff;--secondary-dark:#ce6e00ff;--border-color:#1e2e7a;}
    body{background:#0d1b52;color:#fff;font-family:'Poppins',sans-serif;padding-top:70px;}
    .navbar{backdrop-filter:blur(20px);background:rgba(255,255,255,.95)!important;border-bottom:1px solid #e5e7eb;}
    .certificate-card{background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);border-radius:14px;padding:22px;display:flex;flex-direction:column;gap:10px;position:relative;}
    .badge-status{position:absolute;top:14px;right:14px;padding:6px 10px;font-size:.7rem;letter-spacing:.5px;}
    .btn-generate{background:linear-gradient(135deg,var(--secondary-color),var(--secondary-dark));border:none;font-weight:600;}
    .btn-generate:hover{background:linear-gradient(135deg,var(--secondary-dark),var(--secondary-color));}
    a.download-link{color:#ffe08a;text-decoration:none;font-weight:500;}
    a.download-link:hover{text-decoration:underline;}
  </style>
</head>
<body>
  <nav class="navbar navbar-expand-lg navbar-light shadow-custom fixed-top">
    <div class="container">
      <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
        <img src="../image/natcon.jpg" alt="Logo" class="me-3 rounded-circle" style="height:55px;">
        <div><span class="fs-5 fw-bold d-block">73rd PSME</span><small class="d-none d-md-block fw-medium">National Convention</small></div>
      </a>
      <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button>
      <div id="nav" class="collapse navbar-collapse"><ul class="navbar-nav ms-auto"><li class="nav-item"><a class="nav-link fw-semibold" href="function_rooms.php">< Back</a></li></ul></div>
    </div>
  </nav>

  <div class="container mt-5 pt-2">
    <div class="text-center mb-4">
      <h1 class="display-6 fw-bold">Certificates</h1>
      <p class="text-white-50 mb-0">Generate and download your available certificates once you become eligible.</p>
    </div>
    <div id="alertZone"></div>
    <div class="row g-4" id="certGrid"></div>
  </div>

  <script>
  const certMeta = {
    attendance: {title:'Certificate of Attendance', desc:'Issued after verified participation & required evaluations.'},
    appearance: {title:'Certificate of Appearance', desc:'Issued upon confirmation of presence.'},
    cpd: {title:'CPD Form', desc:'Issued after meeting CPD criteria.'}
  };
  const grid = document.getElementById('certGrid');
  const alertZone = document.getElementById('alertZone');

  function showAlert(msg,type='info',dismiss=4000){
    const id='al-'+Date.now();
    const div=document.createElement('div');
    div.className='alert alert-'+type+' alert-dismissible fade show';
    div.innerHTML='<div>'+msg+'</div><button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    alertZone.appendChild(div);
    if(dismiss)setTimeout(()=>{try{bootstrap.Alert.getOrCreateInstance(div).close();}catch(e){}},dismiss);
  }

  async function loadStatus(){
    grid.innerHTML='<div class="col-12 text-center text-white-50">Loading...</div>';
    try {
      const data = await safeFetchJSON('../api/certificates_status.php');
      if(!data.ok) throw new Error(data.error||'Failed');
      render(data);
    } catch(e){
      grid.innerHTML='<div class="col-12 text-danger">'+escapeHtml(e.message)+'</div>';
    }
  }

  function render(data){
    grid.innerHTML='';
    const elig=data.eligibility||{}; const issued=data.issued||{};
    for (const key of Object.keys(certMeta)){
      const meta=certMeta[key];
      const isElig = (elig[key]===1);
      const wasIssued = !!issued[key];
      const col=document.createElement('div'); col.className='col-md-4';
      const card=document.createElement('div'); card.className='certificate-card h-100';
      const badgeClass = wasIssued? 'bg-success' : (isElig? 'bg-primary':'bg-secondary');
      const badgeText = wasIssued? 'ISSUED' : (isElig? 'ELIGIBLE':'NOT ELIGIBLE');
      card.innerHTML = `<span class="badge badge-status ${badgeClass}">${badgeText}</span>
        <h5 class="mb-1">${meta.title}</h5>
        <p class="small mb-2 text-white-50">${meta.desc}</p>
        <div class="mt-auto" data-actions></div>`;
      const actions = card.querySelector('[data-actions]');
      if (wasIssued){
        const fp = issued[key].file_path;
        actions.innerHTML = `<a class="btn btn-outline-light btn-sm me-2" href="../${fp}" target="_blank">Download</a>`;
        // Add Send to Email button
        const emailBtn = document.createElement('button');
        emailBtn.type = 'button';
        emailBtn.className = 'btn btn-secondary btn-sm';
        emailBtn.textContent = 'Send to Email';
        emailBtn.onclick = () => sendToEmail(key, card, emailBtn);
        actions.appendChild(emailBtn);
      } else if (isElig){
        const btn=document.createElement('button'); btn.type='button'; btn.className='btn btn-generate btn-sm'; btn.textContent='Generate'; btn.onclick=()=>generate(key,card,btn); actions.appendChild(btn);
      } else {
        actions.innerHTML = '<span class="text-white-50 small">Awaiting eligibility</span>';
      }
  async function sendToEmail(type, card, btn){
    btn.disabled = true;
    const orig = btn.textContent;
    btn.textContent = 'Sending...';
    try {
      const data = await safeFetchJSON('../api/certificates_send_email.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type })
      });
      if (!data.ok) throw new Error(data.error || 'Failed to send email');
      showAlert('Certificate sent to your email.','success');
    } catch(e) {
      showAlert(e.message, 'danger', 6000);
    } finally {
      btn.disabled = false;
      btn.textContent = orig;
    }
  }
      col.appendChild(card); grid.appendChild(col);
    }
  }

  async function generate(type, card, btn){
    btn.disabled=true; const orig=btn.textContent; btn.textContent='Generating...';
    try {
      const data = await safeFetchJSON('../api/certificates_generate.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({type})});
      if(!data.ok){ throw new Error(data.error||'Failed'); }
      showAlert('Generated '+type+' certificate.','success');
      loadStatus();
    } catch(e){ showAlert(e.message,'danger',6000); btn.disabled=false; btn.textContent=orig; }
  }

  async function safeFetchJSON(url, options={}){
    const res = await fetch(url, options);
    const ct = (res.headers.get('content-type')||'').toLowerCase();
    const raw = await res.text();
    let data;
    try { data = ct.includes('application/json') ? JSON.parse(raw) : JSON.parse(raw); }
    catch(parseErr){
      // Provide context snippet
      const snippet = raw.slice(0,200).replace(/</g,'&lt;');
      throw new Error('Non-JSON response (status '+res.status+'): '+snippet);
    }
    // If HTTP not ok but JSON parsed, bubble error structure
    if(!res.ok && !data.error){ data.error = 'Request failed ('+res.status+')'; }
    return data;
  }

  function escapeHtml(s){ return (s||'').replace(/[&<>"']/g,c=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;' }[c])); }

  loadStatus();
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
