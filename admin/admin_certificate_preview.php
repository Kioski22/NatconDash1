<?php
// Lightweight visual preview (HTML) of a certificate template (no PDF) for admin.
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403); echo 'Forbidden'; exit;
}

if (isset($conn) && $conn instanceof mysqli) { $db = $conn; }
elseif (isset($mysqli) && $mysqli instanceof mysqli) { $db = $mysqli; }
else { http_response_code(500); echo 'DB unavailable'; exit; }

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function asset_url($path){
    $path = (string)$path; if ($path==='') return '';
    if (preg_match('#^https?://#i',$path)) return $path;
    $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
    if ($base === '.' || $base === '/') $base = '';
    return $base . '/' . ltrim($path,'/');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); echo 'Missing id'; exit; }

$tpl = null; $sigs = [];
$st = $db->prepare("SELECT id,type,bg_url,cert_awardee_name,seminar_title,cert_number_format,
  pos_cert_awardee_name_x,pos_cert_awardee_name_y,pos_cert_number_x,pos_cert_number_y,
  pos_seminar_title_x,pos_seminar_title_y,show_awardee,show_seminar,show_number,
  font_awardee_family,font_awardee_size_px,font_awardee_color,
  font_seminar_family,font_seminar_size_px,font_seminar_color,
  font_number_family,font_number_size_px,font_number_color
  FROM certificates WHERE id=? LIMIT 1");
if ($st){ $st->bind_param('i',$id); $st->execute(); $res=$st->get_result(); $tpl=$res->fetch_assoc(); $st->close(); }
if (!$tpl){ http_response_code(404); echo 'Template not found'; exit; }

$ss = $db->prepare("SELECT id,indicator,signature_url,pos_x,pos_y,width_pct,enabled FROM certificate_signatures WHERE certificate_id=? ORDER BY id ASC");
if ($ss){ $ss->bind_param('i',$id); $ss->execute(); $r=$ss->get_result(); $sigs=$r->fetch_all(MYSQLI_ASSOC); $ss->close(); }

$bg = $tpl['bg_url'] ? asset_url($tpl['bg_url']) : '';
// Fake resolved placeholders for preview
$awardee = $tpl['cert_awardee_name'] ?: '{USER_NAME}';
$seminar = $tpl['seminar_title'] ?: '{SEMINAR_TITLE}';
$certnum = $tpl['cert_number_format'] ?: 'NC-{YYYY}-{SEQ4}';

// Debug visibility flags
error_log("Template visibility flags - Awardee: " . ($tpl['show_awardee'] ?? 'NULL') . ", Seminar: " . ($tpl['show_seminar'] ?? 'NULL') . ", Number: " . ($tpl['show_number'] ?? 'NULL'));
error_log("Position values - Awardee: " . ($tpl['pos_cert_awardee_name_x'] ?? 'NULL') . "," . ($tpl['pos_cert_awardee_name_y'] ?? 'NULL'));
error_log("Position values - Seminar: " . ($tpl['pos_seminar_title_x'] ?? 'NULL') . "," . ($tpl['pos_seminar_title_y'] ?? 'NULL'));
error_log("Position values - Number: " . ($tpl['pos_cert_number_x'] ?? 'NULL') . "," . ($tpl['pos_cert_number_y'] ?? 'NULL'));

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Certificate Preview — <?= e($tpl['type']) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root {
  --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  --surface-color: #ffffff;
  --bg-subtle: #f8fafc;
  --border-light: #e2e8f0;
  --text-primary: #1e293b;
  --text-secondary: #64748b;
  --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
  --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -2px rgb(0 0 0 / 0.05);
  --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 10px 10px -5px rgb(0 0 0 / 0.04);
}

body {
  background: var(--bg-subtle);
  font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
  color: var(--text-primary);
  line-height: 1.6;
}

.header {
  background: var(--primary-gradient);
  color: white;
  padding: 2rem 0;
  margin-bottom: 2rem;
  box-shadow: var(--shadow-lg);
}

.header h1 {
  font-size: 1.75rem;
  font-weight: 600;
  margin: 0;
}

.header .subtitle {
  opacity: 0.9;
  font-size: 0.95rem;
  margin-top: 0.25rem;
}

.preview-container {
  max-width: 1400px;
  margin: 0 auto;
  padding: 0 1rem;
}

.preview-layout {
  display: grid;
  grid-template-columns: 1fr 320px;
  gap: 2rem;
  align-items: start;
}

.canvas-section {
  background: var(--surface-color);
  border-radius: 12px;
  padding: 2rem;
  box-shadow: var(--shadow-lg);
  border: 1px solid var(--border-light);
}

.canvas-wrapper {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 400px;
  position: relative;
}

.canvas {
  position: relative;
  display: inline-block;
  background: #fff;
  border-radius: 8px;
  box-shadow: var(--shadow-xl);
  overflow: hidden;
  transition: transform 0.3s ease;
  max-width: 100%;
}

.canvas:hover {
  transform: scale(1.02);
}

.canvas img.bg {
  display: block;
  max-width: 100%;
  height: auto;
  border-radius: 8px;
}

.sidebar {
  background: var(--surface-color);
  border-radius: 12px;
  padding: 1.5rem;
  box-shadow: var(--shadow-lg);
  border: 1px solid var(--border-light);
  height: fit-content;
  position: sticky;
  top: 2rem;
}

.sidebar-section {
  margin-bottom: 2rem;
}

.sidebar-section:last-child {
  margin-bottom: 0;
}

.sidebar-title {
  font-size: 1.1rem;
  font-weight: 600;
  color: var(--text-primary);
  margin-bottom: 1rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.info-item {
  display: flex;
  justify-content: space-between;
  padding: 0.75rem 0;
  border-bottom: 1px solid #f1f5f9;
}

.info-item:last-child {
  border-bottom: none;
}

.info-label {
  font-weight: 500;
  color: var(--text-secondary);
  font-size: 0.875rem;
}

.info-value {
  font-weight: 500;
  color: var(--text-primary);
  font-size: 0.875rem;
  text-align: right;
  max-width: 180px;
  word-break: break-word;
}

.btn-modern {
  border-radius: 8px;
  font-weight: 500;
  padding: 0.75rem 1.5rem;
  transition: all 0.2s ease;
  border: none;
  box-shadow: var(--shadow-sm);
}

.btn-modern:hover {
  transform: translateY(-1px);
  box-shadow: var(--shadow-lg);
}

.btn-primary-modern {
  background: var(--primary-gradient);
  color: white;
}

.btn-outline-modern {
  background: var(--surface-color);
  border: 1px solid var(--border-light);
  color: var(--text-primary);
}

.btn-outline-modern:hover {
  background: var(--bg-subtle);
  color: var(--text-primary);
}

.action-buttons {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.zoom-controls {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 1rem;
}

.zoom-btn {
  width: 36px;
  height: 36px;
  border-radius: 6px;
  border: 1px solid var(--border-light);
  background: var(--surface-color);
  color: var(--text-primary);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.2s ease;
}

.zoom-btn:hover {
  background: var(--bg-subtle);
  transform: scale(1.05);
}

.zoom-level {
  font-family: 'SF Mono', Monaco, 'Cascadia Code', monospace;
  font-size: 0.875rem;
  color: var(--text-secondary);
  min-width: 60px;
  text-align: center;
}

/* Simplified placeholder styling for accurate visual size */
.ph {
  position: absolute;
  white-space: nowrap;
  z-index: 10;
  line-height: 1.1;
  font-weight: 500;
  padding: 0; /* remove extra space that makes it look larger */
  margin: 0;
  background: transparent;
  border: none;
  box-shadow: none;
  display: block !important;
  transform-origin: top left;
}

/* Optional debug style (enable by adding class debug-placeholders to body) */
body.debug-placeholders .ph {
  outline: 1px dashed rgba(99,102,241,0.6);
  background: rgba(99,102,241,0.08);
  border-radius: 4px;
  padding: 2px 4px;
}

.sig {
  position: absolute;
  transition: all 0.2s ease;
}

.sig:hover {
  transform: scale(1.05);
  z-index: 10;
}

.sig img {
  border-radius: 4px;
  box-shadow: var(--shadow-sm);
}

.empty-state {
  text-align: center;
  padding: 3rem;
  color: var(--text-secondary);
}

.empty-state i {
  font-size: 3rem;
  margin-bottom: 1rem;
  opacity: 0.5;
}

.status-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.25rem 0.75rem;
  border-radius: 9999px;
  font-size: 0.75rem;
  font-weight: 500;
}

.status-enabled {
  background: #dcfce7;
  color: #166534;
}

.status-disabled {
  background: #fef2f2;
  color: #991b1b;
}

@media (max-width: 1024px) {
  .preview-layout {
    grid-template-columns: 1fr;
    gap: 1.5rem;
  }
  
  .sidebar {
    position: static;
  }
  
  .canvas-section {
    padding: 1.5rem;
  }
}

@media (max-width: 640px) {
  .header {
    padding: 1.5rem 0;
  }
  
  .preview-container {
    padding: 0 0.75rem;
  }
  
  .canvas-section {
    padding: 1rem;
  }
  
  .sidebar {
    padding: 1rem;
  }
}
</style>
</head>
<body>
  <div class="header">
    <div class="preview-container">
      <h1><i class="bi bi-award"></i> Certificate Preview</h1>
      <div class="subtitle">Template: <?= e($tpl['type']) ?> • ID: <?= (int)$id ?></div>
    </div>
  </div>

  <div class="preview-container">
    <div class="preview-layout">
      <div class="canvas-section">
        <div class="zoom-controls">
          <button class="zoom-btn" id="zoomOut" title="Zoom Out"><i class="bi bi-dash"></i></button>
          <div class="zoom-level" id="zoomLevel">100%</div>
          <button class="zoom-btn" id="zoomIn" title="Zoom In"><i class="bi bi-plus"></i></button>
          <button class="zoom-btn" id="zoomFit" title="Fit to Screen"><i class="bi bi-arrows-fullscreen"></i></button>
        </div>

        <div class="canvas-wrapper">
          <?php if (!$bg): ?>
            <div class="empty-state">
              <i class="bi bi-image"></i>
              <h5>No Background Image</h5>
              <p>Upload a background image in the template editor</p>
            </div>
          <?php else: ?>
            <div id="canvas" class="canvas">
              <img src="<?= e($bg) ?>" alt="Background" class="bg" id="bgImg">
              
              <?php if (isset($tpl['show_awardee']) ? (int)$tpl['show_awardee'] : 1): ?>
                <div class="ph" id="ph-awardee"
                     data-base-font-size="<?= (int)($tpl['font_awardee_size_px'] ?? 46) ?>"
                     style="left:0;top:0;font-family:<?= e($tpl['font_awardee_family'] ?? 'Helvetica') ?>;color:<?= e($tpl['font_awardee_color'] ?? '#000000') ?>;"
                     title="Awardee Name • Position: <?= (float)($tpl['pos_cert_awardee_name_x'] ?? 0) ?>%, <?= (float)($tpl['pos_cert_awardee_name_y'] ?? 0) ?>%">
                  <?= e($awardee) ?>
                </div>
              <?php endif; ?>

              <?php if (isset($tpl['show_seminar']) ? (int)$tpl['show_seminar'] : 1): ?>
                <div class="ph" id="ph-seminar"
                     data-base-font-size="<?= (int)($tpl['font_seminar_size_px'] ?? 24) ?>"
                     style="left:0;top:0;font-family:<?= e($tpl['font_seminar_family'] ?? 'Helvetica') ?>;color:<?= e($tpl['font_seminar_color'] ?? '#000000') ?>;"
                     title="Seminar Title • Position: <?= (float)($tpl['pos_seminar_title_x'] ?? 0) ?>%, <?= (float)($tpl['pos_seminar_title_y'] ?? 0) ?>%">
                  <?= e($seminar) ?>
                </div>
              <?php endif; ?>

              <?php if (isset($tpl['show_number']) ? (int)$tpl['show_number'] : 1): ?>
                <div class="ph" id="ph-number"
                     data-base-font-size="<?= (int)($tpl['font_number_size_px'] ?? 16) ?>"
                     style="left:0;top:0;font-family:<?= e($tpl['font_number_family'] ?? 'Helvetica') ?>;color:<?= e($tpl['font_number_color'] ?? '#000000') ?>;"
                     title="Certificate Number • Position: <?= (float)($tpl['pos_cert_number_x'] ?? 0) ?>%, <?= (float)($tpl['pos_cert_number_y'] ?? 0) ?>%">
                  <?= e($certnum) ?>
                </div>
              <?php endif; ?>
              
              <?php foreach ($sigs as $sg): if (!(int)$sg['enabled']) continue; $sp = $sg['signature_url']?asset_url($sg['signature_url']):''; ?>
                <div class="sig" 
                     style="left:0;top:0;width:<?= (float)($sg['width_pct'] ?? 25) ?>%;" 
                     title="<?= e($sg['indicator']) ?> • Position: <?= (float)$sg['pos_x'] ?>%, <?= (float)$sg['pos_y'] ?>% • Width: <?= (float)($sg['width_pct'] ?? 25) ?>%">
                  <?php if ($sp): ?>
                    <img src="<?= e($sp) ?>" alt="<?= e($sg['indicator']) ?>" style="width:100%;height:auto;display:block;">
                  <?php else: ?>
                    <div class="ph-box" style="width:100%;text-align:center;min-height:40px;display:flex;align-items:center;justify-content:center;">
                      <i class="bi bi-pen me-1"></i><?= e($sg['indicator']) ?>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="sidebar">
        <div class="sidebar-section">
          <div class="sidebar-title">
            <i class="bi bi-gear"></i>
            Quick Actions
          </div>
          <div class="action-buttons">
            <a href="admin_certificate_edit.php?id=<?= (int)$id ?>" class="btn btn-primary-modern btn-modern">
              <i class="bi bi-pencil-square me-2"></i>Edit Layout
            </a>
            <a href="admin_certificate_settings.php" class="btn btn-outline-modern btn-modern">
              <i class="bi bi-arrow-left me-2"></i>Back to Settings
            </a>
            <button class="btn btn-outline-modern btn-modern" id="generateSample">
              <i class="bi bi-file-earmark-pdf me-2"></i>Generate Sample PDF
            </button>
          </div>
        </div>

        <div class="sidebar-section">
          <div class="sidebar-title">
            <i class="bi bi-info-circle"></i>
            Template Info
          </div>
          <div class="info-item">
            <span class="info-label">Template Type</span>
            <span class="info-value"><?= e($tpl['type']) ?></span>
          </div>
          <div class="info-item">
            <span class="info-label">Template ID</span>
            <span class="info-value">#<?= (int)$id ?></span>
          </div>
          <div class="info-item">
            <span class="info-label">Background</span>
            <span class="info-value"><?= $bg ? '<i class="bi bi-check-circle text-success"></i> Uploaded' : '<i class="bi bi-x-circle text-danger"></i> Missing' ?></span>
          </div>
          <div class="info-item">
            <span class="info-label">Signatures</span>
            <span class="info-value"><?= count(array_filter($sigs, function($s){ return (int)$s['enabled']; })) ?> active</span>
          </div>
        </div>

        <div class="sidebar-section">
          <div class="sidebar-title">
            <i class="bi bi-eye"></i>
            Visible Elements
          </div>
          <div class="info-item">
            <span class="info-label">Awardee Name</span>
            <span class="info-value">
              <?php if ($tpl['show_awardee']): ?>
                <span class="status-badge status-enabled"><i class="bi bi-check-circle"></i> Visible</span>
              <?php else: ?>
                <span class="status-badge status-disabled"><i class="bi bi-eye-slash"></i> Hidden</span>
              <?php endif; ?>
            </span>
          </div>
          <div class="info-item">
            <span class="info-label">Seminar Title</span>
            <span class="info-value">
              <?php if ($tpl['show_seminar']): ?>
                <span class="status-badge status-enabled"><i class="bi bi-check-circle"></i> Visible</span>
              <?php else: ?>
                <span class="status-badge status-disabled"><i class="bi bi-eye-slash"></i> Hidden</span>
              <?php endif; ?>
            </span>
          </div>
          <div class="info-item">
            <span class="info-label">Certificate Number</span>
            <span class="info-value">
              <?php if ($tpl['show_number']): ?>
                <span class="status-badge status-enabled"><i class="bi bi-check-circle"></i> Visible</span>
              <?php else: ?>
                <span class="status-badge status-disabled"><i class="bi bi-eye-slash"></i> Hidden</span>
              <?php endif; ?>
            </span>
          </div>
        </div>

        <?php if (!empty($sigs)): ?>
        <div class="sidebar-section">
          <div class="sidebar-title">
            <i class="bi bi-pen"></i>
            Signatures
          </div>
          <?php foreach ($sigs as $sg): ?>
          <div class="info-item">
            <span class="info-label"><?= e($sg['indicator']) ?></span>
            <span class="info-value">
              <?php if ((int)$sg['enabled']): ?>
                <span class="status-badge status-enabled"><i class="bi bi-check-circle"></i> Active</span>
              <?php else: ?>
                <span class="status-badge status-disabled"><i class="bi bi-eye-slash"></i> Disabled</span>
              <?php endif; ?>
            </span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
<script>
(function(){
  var bg = document.getElementById('bgImg');
  var canvas = document.getElementById('canvas');
  var zoomLevel = 1.0;
  var minZoom = 0.25;
  var maxZoom = 3.0;
  // New helpers for more reliable positioning
  var pendingRecalc = null; // debounce timer id
  var lastDims = {w:0,h:0};
  
  // Initialize
  if (!bg) { 
    positionAll(); 
    return; 
  }
  
  bg.addEventListener('load', positionAll);
  if (bg.complete) {
    positionAll();
  } else {
    // Fallback if image doesn't load immediately
    setTimeout(positionAll, 100);
  }
  window.addEventListener('resize', positionAll);
  
  // Also position on DOM ready
  document.addEventListener('DOMContentLoaded', positionAll);
  
  // Force positioning after a short delay as fallback
  setTimeout(function() {
    positionAll();
  }, 500);
  
  // Zoom controls
  var zoomInBtn = document.getElementById('zoomIn');
  var zoomOutBtn = document.getElementById('zoomOut');
  var zoomFitBtn = document.getElementById('zoomFit');
  var zoomLevelEl = document.getElementById('zoomLevel');
  
  if (zoomInBtn) zoomInBtn.addEventListener('click', function() { setZoom(zoomLevel * 1.25); });
  if (zoomOutBtn) zoomOutBtn.addEventListener('click', function() { setZoom(zoomLevel / 1.25); });
  if (zoomFitBtn) zoomFitBtn.addEventListener('click', function(){ fitToScreen(); positionAll(true); });
  
  function setZoom(newZoom) {
    zoomLevel = Math.max(minZoom, Math.min(maxZoom, newZoom));
    if (canvas) {
      canvas.style.transform = 'scale(' + zoomLevel + ')';
      canvas.style.transformOrigin = 'center center';
    }
    if (zoomLevelEl) zoomLevelEl.textContent = Math.round(zoomLevel * 100) + '%';
    // Recalculate element positioning (percentage mapping preserved)
    positionAll(false);
  }
  
  function fitToScreen() {
    if (!canvas || !bg) return;
    var wrapper = canvas.parentElement;
    var wrapperRect = wrapper.getBoundingClientRect();
    var canvasRect = canvas.getBoundingClientRect();
    
    var scaleX = (wrapperRect.width - 40) / (canvasRect.width / zoomLevel);
    var scaleY = (wrapperRect.height - 40) / (canvasRect.height / zoomLevel);
    var newZoom = Math.min(scaleX, scaleY, 1.0);
    
    setZoom(newZoom);
  }
  
  function getDisplayedDims(){
    if (!bg) return {w:800,h:600};
    var w = bg.clientWidth;
    var h = bg.clientHeight;
    if (w < 10 || h < 10){
      // fallback to natural dimensions or canvas while waiting for layout
      w = w || bg.naturalWidth || canvas.clientWidth || 800;
      h = h || bg.naturalHeight || canvas.clientHeight || 600;
      if (!pendingRecalc){
        pendingRecalc = setTimeout(function(){ pendingRecalc=null; positionAll(true); }, 60);
      }
    }
    return {w:w,h:h};
  }

  function place(el, xPct, yPct, dims) {
    if (!el) return;
    var W = dims.w, H = dims.h;
    var leftPx = Math.round((xPct/100) * W);
    var topPx = Math.round((yPct/100) * H);
    el.style.position = 'absolute';
    el.style.left = leftPx + 'px';
    el.style.top = topPx + 'px';
    el.style.zIndex = '10';
    el.style.visibility = 'visible';
    el.style.opacity = '1';
    el.style.display = 'block';
  }

  function scaleFonts(dims){
    if(!bg || !bg.naturalWidth) return; // wait for load
    var nw = bg.naturalWidth;
    if (nw < 10){
      if(!pendingRecalc){ pendingRecalc = setTimeout(function(){ pendingRecalc=null; positionAll(true); }, 80); }
      return;
    }
    var ratio = dims.w / nw;
    document.querySelectorAll('.ph').forEach(function(el){
      var base = parseFloat(el.getAttribute('data-base-font-size')||'16');
      var scaled = base * ratio;
      if (scaled < 6) scaled = 6; // clamp
      el.style.fontSize = scaled + 'px';
    });
  }

  function positionAll(force){
    if(!canvas) return;
    var dims = getDisplayedDims();
    if(!force && dims.w === lastDims.w && dims.h === lastDims.h){ /* no size change */ }
    else { lastDims = dims; }
    var aw = document.getElementById('ph-awardee');
    if (aw) { place(aw, <?= (float)($tpl['pos_cert_awardee_name_x'] ?? 0) ?>, <?= (float)($tpl['pos_cert_awardee_name_y'] ?? 0) ?>, dims); }
    var sm = document.getElementById('ph-seminar');
    if (sm) { place(sm, <?= (float)($tpl['pos_seminar_title_x'] ?? 0) ?>, <?= (float)($tpl['pos_seminar_title_y'] ?? 0) ?>, dims); }
    var nm = document.getElementById('ph-number');
    if (nm) { place(nm, <?= (float)($tpl['pos_cert_number_x'] ?? 0) ?>, <?= (float)($tpl['pos_cert_number_y'] ?? 0) ?>, dims); }
    var sigDivs = document.querySelectorAll('.sig');
    <?php $i=0; foreach($sigs as $sg){ if(!(int)$sg['enabled']) continue; ?>
      if (sigDivs[<?= $i ?>]) { place(sigDivs[<?= $i ?>], <?= (float)$sg['pos_x'] ?>, <?= (float)$sg['pos_y'] ?>, dims); }
    <?php $i++; } ?>
    scaleFonts(dims);
  }
  
  // Generate sample PDF functionality
  var generateBtn = document.getElementById('generateSample');
  if (generateBtn) {
    generateBtn.addEventListener('click', function() {
      this.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Generating...';
      this.disabled = true;
      
      // Create a temporary form to hit the existing generation API
      var form = document.createElement('form');
      form.method = 'POST';
      form.action = '../api/certificates_generate.php';
      form.style.display = 'none';
      
      var typeInput = document.createElement('input');
      typeInput.name = 'type';
      typeInput.value = '<?= e($tpl['type']) ?>';
      form.appendChild(typeInput);
      
      var previewInput = document.createElement('input');
      previewInput.name = 'preview_mode';
      previewInput.value = '1';
      form.appendChild(previewInput);
      
      document.body.appendChild(form);
      
      fetch(form.action, {
        method: 'POST',
        body: new FormData(form)
      })
      .then(response => response.json())
      .then(data => {
        if (data.success && data.download_url) {
          window.open(data.download_url, '_blank');
        } else {
          alert('Error generating PDF: ' + (data.error || 'Unknown error'));
        }
      })
      .catch(error => {
        alert('Error: ' + error.message);
      })
      .finally(() => {
        this.innerHTML = '<i class="bi bi-file-earmark-pdf me-2"></i>Generate Sample PDF';
        this.disabled = false;
        document.body.removeChild(form);
      });
    });
  }
  
  // Keyboard shortcuts
  document.addEventListener('keydown', function(e) {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
    
    switch(e.key) {
      case '=':
      case '+':
        e.preventDefault();
        setZoom(zoomLevel * 1.25);
        break;
      case '-':
        e.preventDefault();
        setZoom(zoomLevel / 1.25);
        break;
      case '0':
        e.preventDefault();
        setZoom(1.0);
        break;
      case 'f':
        e.preventDefault();
        fitToScreen();
        break;
    }
  });
  
  // Initialize zoom
  setZoom(1.0);
  
  // Auto-fit on load if canvas is too large
  setTimeout(function() {
    if (canvas && bg.complete) {
      var wrapper = canvas.parentElement;
      var wrapperRect = wrapper.getBoundingClientRect();
      var canvasRect = canvas.getBoundingClientRect();
      
      if (canvasRect.width > wrapperRect.width - 40 || canvasRect.height > wrapperRect.height - 40) {
        fitToScreen();
      }
    }
  }, 100);
})();
</script>
</body>
</html>