<?php
// Department certificate layout preview
// Supports two modes:
//  - GET /?dept_id=## (&format=html|pdf) to preview persisted layout
//  - POST with layout_preview JSON (unsaved editor state) and dept_id to preview ad-hoc
// For legacy compatibility, POST custom_html still accepted but deprecated.

session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vendor/autoload.php';
use Dompdf\Dompdf;

if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') { http_response_code(403); echo 'Forbidden'; exit; }

// CSRF only enforced for POST mutative preview (when custom content is provided).
$isPost = $_SERVER['REQUEST_METHOD']==='POST';
if ($isPost && !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) { http_response_code(400); echo 'Bad CSRF'; exit; }

$dept_id = isset($_REQUEST['dept_id']) ? (int)$_REQUEST['dept_id'] : 0;
if ($dept_id <= 0) { http_response_code(400); echo 'Missing dept_id'; exit; }

if (isset($conn) && $conn instanceof mysqli) { $db = $conn; }
elseif (isset($mysqli) && $mysqli instanceof mysqli) { $db = $mysqli; }
else { echo 'DB unavailable'; exit; }

function e($s){ return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function asset_url($path){
  $path=(string)$path; if($path==='') return '';
  if(preg_match('#^https?://#i',$path)) return $path;
  $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
  if($base==='.'||$base==='/') $base='';
  return $base . '/' . ltrim($path,'/');
}

// Try to load saved layout
$layout=null;
if($st=$db->prepare("SELECT * FROM department_certificate_templates WHERE technical_department_id=? LIMIT 1")){
  $st->bind_param('i',$dept_id); $st->execute(); $res=$st->get_result(); $layout=$res->fetch_assoc(); $st->close();
}

// If POST provided live JSON override
$liveJson = null;
if($isPost && !empty($_POST['layout_preview'])){
  $decoded = json_decode($_POST['layout_preview'], true);
  if(is_array($decoded)) $liveJson = $decoded;
}

// Legacy HTML (fallback) if still provided
$legacy_html = '';
if($isPost && isset($_POST['custom_html'])){
  $legacy_html = trim((string)$_POST['custom_html']);
}

// Sample data for placeholders (layout approach uses tokens stored in layout)
$sampleUser = 'Sample Participant';
$sampleDept = $layout['department_name_placeholder'] ?? '{DEPARTMENT_NAME}';
$sampleDept = str_replace(['{DEPARTMENT_NAME}','{DEPT}','{DEPARTMENT}'],'Mechanical Engineering Department',$sampleDept);
$sampleSpeakersHtml = '<ul style="margin:4px 0 0 18px;padding:0;font-size:14px;line-height:1.3;">'
  .'<li>Engr. Alice A. – Lead</li>'
  .'<li>Engr. Bob B. – Coordinator</li>'
  .'<li>Engr. Carol C. – Panelist</li>'
  .'</ul>';

// Decide rendering strategy
if($legacy_html !== ''){
  // Legacy path (raw HTML template)
  $html = $legacy_html;
  $reps = [
    '{{user_name}}'=>$sampleUser,
    '{{department_name}}'=>'Mechanical Engineering Department',
    '{{event_name}}'=>'73rd PSME National Convention',
    '{{issued_at}}'=>date('Y-m-d H:i'),
    '{{department_speakers}}'=>'Engr. Alice (Lead), Engr. Bob (Coordinator)',
    '{{department_speakers_list}}'=>$sampleSpeakersHtml
  ];
  foreach($reps as $k=>$v){ $html = str_replace($k,$v,$html); }
  if(stripos($html,'<html')===false){
    $html='<!DOCTYPE html><html><head><meta charset="utf-8"><title>Preview</title>'
      .'<style>body{margin:24px;font-family:Arial,Helvetica,sans-serif;}</style></head><body>'.$html.'</body></html>';
  }
  $format = $_GET['format'] ?? 'html';
  if($format==='pdf'){
    try{ $dompdf = new Dompdf(['defaultFont'=>'Helvetica']); $dompdf->setPaper('A4','landscape'); $dompdf->loadHtml($html,'UTF-8'); $dompdf->render(); header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="dept_template_legacy_preview.pdf"'); echo $dompdf->output(); } catch(Throwable $e){ header('Content-Type:text/plain'); echo 'Legacy preview failed: '.$e->getMessage(); }
  } else { header('Content-Type:text/html; charset=utf-8'); echo $html; }
  exit;
}

// Layout path --------------------------------------------------------------
// Merge live JSON override into $layout for unsaved preview
if($liveJson){
  // Only override known keys
  foreach(['user_name_placeholder','department_name_placeholder','speakers_list_placeholder'] as $k){ if(isset($liveJson[$k])) $layout[$k]=$liveJson[$k]; }
  if(isset($liveJson['show_user_name'])) $layout['show_user_name']=(int)$liveJson['show_user_name'];
  if(isset($liveJson['show_department_name'])) $layout['show_department_name']=(int)$liveJson['show_department_name'];
  if(isset($liveJson['show_speakers_list'])) $layout['show_speakers_list']=(int)$liveJson['show_speakers_list'];
  if(isset($liveJson['positions'])){
    $p=$liveJson['positions'];
    if(isset($p['user'])){ $layout['pos_user_name_x']=$p['user']['x']??0; $layout['pos_user_name_y']=$p['user']['y']??0; }
    if(isset($p['department'])){ $layout['pos_department_name_x']=$p['department']['x']??0; $layout['pos_department_name_y']=$p['department']['y']??0; }
    if(isset($p['speakers'])){ $layout['pos_speakers_list_x']=$p['speakers']['x']??0; $layout['pos_speakers_list_y']=$p['speakers']['y']??0; }
  }
}

if(!$layout){
  header('Content-Type:text/plain'); echo 'No layout saved for department.'; exit;
}

// Build positioned HTML
$bgUrl = !empty($layout['bg_url']) ? asset_url($layout['bg_url']) : '';

$userPh = $layout['user_name_placeholder'] ?? '{USER_NAME}';
$deptPh = $layout['department_name_placeholder'] ?? '{DEPARTMENT_NAME}';
$speakersPh = $layout['speakers_list_placeholder'] ?? '{SPEAKERS_LIST}';

// Replace tokens for preview display only (not saved)
$userText = str_replace(['{USER_NAME}','{NAME}','{AWARDEE}'],$sampleUser,$userPh);
$deptText = str_replace(['{DEPARTMENT_NAME}','{DEPT}','{DEPARTMENT}'],'Mechanical Engineering Department',$deptPh);
$speakersText = str_replace(['{SPEAKERS_LIST}','{SPEAKERS}'],$sampleSpeakersHtml,$speakersPh);

// Font styling
function fontCSS($fam,$size,$color){
  $safeFam = preg_replace('/[^a-zA-Z0-9 ,\-]/','',$fam ?: 'Helvetica');
  $size = (int)($size ?: 16); if($size<6)$size=6; if($size>200)$size=200; $color = preg_match('/^#[0-9A-Fa-f]{6}$/',$color)?$color:'#000000';
  return "font-family:$safeFam; font-size:{$size}px; color:$color;";
}

$cssUser = fontCSS($layout['font_user_name_family']??'Helvetica',$layout['font_user_name_size_px']??40,$layout['font_user_name_color']??'#000000');
$cssDept = fontCSS($layout['font_department_name_family']??'Helvetica',$layout['font_department_name_size_px']??28,$layout['font_department_name_color']??'#000000');
$cssSpeakers = fontCSS($layout['font_speakers_list_family']??'Helvetica',$layout['font_speakers_list_size_px']??16,$layout['font_speakers_list_color']??'#000000');

// Dimensions / fallbacks
$wrapperStyle = 'position:relative;display:inline-block;'.($bgUrl?''.'':'width:1200px;height:675px;background:#f5f7fa;');

$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Department Layout Preview</title>';
$html .= '<style>body{margin:16px;font-family:Arial,Helvetica,sans-serif;background:#eef1f5;} .canvas-wrap{box-shadow:0 8px 28px -10px rgba(0,0,0,.35);border-radius:8px;overflow:hidden;}'
  .'.bg-img{display:block;width:100%;height:auto;}'
  .'.layer{position:absolute;inset:0;}'
  .'.blk{position:absolute;white-space:nowrap;padding:4px 8px;border-radius:6px;background:rgba(255,255,255,.55);backdrop-filter:blur(6px);}'
  .'.blk.list{white-space:normal;max-width:50%;}'
  .'ul{margin:4px 0 0 18px;padding:0;} li{margin:0 0 2px 0;}'
  .'</style></head><body>';
$html .= '<h3 style="margin:0 0 12px;font-weight:600;color:#334155;">Department Certificate Layout Preview</h3>';
$html .= '<div class="canvas-wrap" style="'.$wrapperStyle.'">';
if($bgUrl){ $html .= '<img class="bg-img" src="'.e($bgUrl).'" alt="Background">'; }
$html .= '<div class="layer">';
if(!empty($layout['show_user_name'])){
  $html .= '<div class="blk" style="left:'.(float)$layout['pos_user_name_x'].'%;top:'.(float)$layout['pos_user_name_y'].'%;'.$cssUser.'">'.e($userText).'</div>';
}
if(!empty($layout['show_department_name'])){
  $html .= '<div class="blk" style="left:'.(float)$layout['pos_department_name_x'].'%;top:'.(float)$layout['pos_department_name_y'].'%;'.$cssDept.'">'.e($deptText).'</div>';
}
if(!empty($layout['show_speakers_list'])){
  $html .= '<div class="blk list" style="left:'.(float)$layout['pos_speakers_list_x'].'%;top:'.(float)$layout['pos_speakers_list_y'].'%;'.$cssSpeakers.'">'.$speakersText.'</div>';
}
$html .= '</div></div>';
$html .= '<div style="margin-top:14px;font-size:12px;color:#64748b;">This preview uses sample content. Actual certificate generation will substitute real participant, department, and speakers.</div>';
$html .= '</body></html>';

$format = $_GET['format'] ?? 'html';
if($format==='pdf'){
  try {
    $dompdf = new Dompdf(['defaultFont'=>'Helvetica']);
    // Landscape A4; if background large you may adapt
    $dompdf->setPaper('A4','landscape');
    $dompdf->loadHtml($html,'UTF-8');
    $dompdf->render();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="department_layout_preview.pdf"');
    echo $dompdf->output();
  } catch(Throwable $e){ header('Content-Type:text/plain'); echo 'PDF render failed: '.$e->getMessage(); }
  exit;
}
header('Content-Type:text/html; charset=utf-8');
echo $html; 
