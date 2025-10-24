<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vendor/autoload.php';
use Dompdf\Dompdf;

if (isset($conn) && $conn instanceof mysqli) $db = $conn; elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli; else { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB unavailable']); exit; }
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0; if ($user_id<=0){ http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Login required']); exit; }

// Input & validation
$raw = file_get_contents('php://input');
$data = json_decode($raw,true);
$type = strtolower(trim((string)($data['type'] ?? '')));
$allowed = ['attendance','appearance','cpd'];
if (!in_array($type,$allowed,true)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid certificate type']); exit; }

// Acquire a short MySQL advisory lock to avoid concurrent duplicate generations for same user/type
$lockKey = "cert:".$type.":".$user_id;
$gotLock = 0; $lk = $db->prepare('SELECT GET_LOCK(?, 10)');
if ($lk) { $lk->bind_param('s',$lockKey); $lk->execute(); $lk->bind_result($gotLock); $lk->fetch(); $lk->close(); }
if ((int)$gotLock !== 1) { http_response_code(429); echo json_encode(['ok'=>false,'error'=>'Busy generating certificate, please retry']); exit; }

// Map to users table column
$colMap = [ 'attendance'=>'eligible_certificate_attendance', 'appearance'=>'eligible_certificate_appearance', 'cpd'=>'eligible_cpd_certificate' ];
$col = $colMap[$type];

$eligible = 0; $name=''; $email='';
if ($st = $db->prepare("SELECT name,email,$col FROM users WHERE id=? LIMIT 1")) {
  $st->bind_param('i',$user_id); $st->execute(); $st->bind_result($name,$email,$eligible); $st->fetch(); $st->close();
}
if ((int)$eligible !== 1) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Not eligible for this certificate']); exit; }

// Already issued? (return existing)
if ($chk = $db->prepare('SELECT file_path FROM certificates_issued WHERE user_id=? AND cert_type=? LIMIT 1')) {
  $chk->bind_param('is',$user_id,$type); $chk->execute(); $chk->bind_result($fp); if ($chk->fetch()) { $chk->close();
    // Release advisory lock before returning
    if ($rl = $db->prepare('SELECT RELEASE_LOCK(?)')) { $rl->bind_param('s',$lockKey); $rl->execute(); $rl->close(); }
    echo json_encode(['ok'=>true,'already'=>true,'file_path'=>$fp]); exit; }
  $chk->close();
}

// Fetch template (pick most recently updated certificate row for this type)
$tpl = null;
// Ensure font columns exist (silent auto-migration if missing)
$fontCols = [
  'font_awardee_family' => "ALTER TABLE certificates ADD COLUMN font_awardee_family VARCHAR(60) DEFAULT 'Helvetica'",
  'font_awardee_size_px' => "ALTER TABLE certificates ADD COLUMN font_awardee_size_px INT DEFAULT 46",
  'font_awardee_color' => "ALTER TABLE certificates ADD COLUMN font_awardee_color VARCHAR(15) DEFAULT '#000000'",
  'font_seminar_family' => "ALTER TABLE certificates ADD COLUMN font_seminar_family VARCHAR(60) DEFAULT 'Helvetica'",
  'font_seminar_size_px' => "ALTER TABLE certificates ADD COLUMN font_seminar_size_px INT DEFAULT 24",
  'font_seminar_color' => "ALTER TABLE certificates ADD COLUMN font_seminar_color VARCHAR(15) DEFAULT '#000000'",
  'font_number_family' => "ALTER TABLE certificates ADD COLUMN font_number_family VARCHAR(60) DEFAULT 'Helvetica'",
  'font_number_size_px' => "ALTER TABLE certificates ADD COLUMN font_number_size_px INT DEFAULT 16",
  'font_number_color' => "ALTER TABLE certificates ADD COLUMN font_number_color VARCHAR(15) DEFAULT '#000000'",
];
$colRes = $db->query("SHOW COLUMNS FROM certificates"); $haveCols=[]; if($colRes){ while($cr=$colRes->fetch_assoc()){ $haveCols[$cr['Field']]=true; } $colRes->close(); }
foreach($fontCols as $c=>$ddl){ if(!isset($haveCols[$c])) { @$db->query($ddl); } }

if ($st = $db->prepare("SELECT id,type,bg_url,cert_awardee_name,seminar_title,cert_number_format,
  pos_cert_awardee_name_x,pos_cert_awardee_name_y,
  pos_cert_number_x,pos_cert_number_y,
  pos_seminar_title_x,pos_seminar_title_y,
  show_awardee,show_seminar,show_number,updated_at,
  font_awardee_family,font_awardee_size_px,font_awardee_color,
  font_seminar_family,font_seminar_size_px,font_seminar_color,
  font_number_family,font_number_size_px,font_number_color
  FROM certificates WHERE LOWER(type)=LOWER(?) ORDER BY updated_at DESC, id DESC LIMIT 1")) {
  $st->bind_param('s',$type); $st->execute(); $res=$st->get_result(); $tpl=$res->fetch_assoc(); $st->close();
}
if (!$tpl) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Template not configured for this type']); exit; }

// Extract font settings after template is loaded
$faFam = $tpl['font_awardee_family'] ?? 'Helvetica';
$faSize = (int)($tpl['font_awardee_size_px'] ?? 46); if($faSize<6)$faSize=6; if($faSize>200)$faSize=200;
$faColor = preg_match('/^#[0-9A-Fa-f]{6}$/',$tpl['font_awardee_color'] ?? '') ? strtoupper($tpl['font_awardee_color']) : '#000000';
$fsFam = $tpl['font_seminar_family'] ?? 'Helvetica';
$fsSize = (int)($tpl['font_seminar_size_px'] ?? 24); if($fsSize<6)$fsSize=6; if($fsSize>200)$fsSize=200;
$fsColor = preg_match('/^#[0-9A-Fa-f]{6}$/',$tpl['font_seminar_color'] ?? '') ? strtoupper($tpl['font_seminar_color']) : '#000000';
$fnFam = $tpl['font_number_family'] ?? 'Helvetica';
$fnSize = (int)($tpl['font_number_size_px'] ?? 16); if($fnSize<6)$fnSize=6; if($fnSize>200)$fnSize=200;
$fnColor = preg_match('/^#[0-9A-Fa-f]{6}$/',$tpl['font_number_color'] ?? '') ? strtoupper($tpl['font_number_color']) : '#000000';

$cert_id = (int)$tpl['id'];

// Load signatures for this template
$signatures = [];
if ($sig = $db->prepare('SELECT indicator, signature_url, pos_x, pos_y, width_pct FROM certificate_signatures WHERE certificate_id=? AND enabled=1')) {
  $sig->bind_param('i',$cert_id); $sig->execute(); $r=$sig->get_result();
  while($row=$r->fetch_assoc()){ $signatures[]=$row; }
  $sig->close();
}

// Generate certificate number (if template has {SEQ4} etc.)
function next_sequence(mysqli $db, string $type): string {
  // naive sequence per type using certificates_issued count + 1 (safe enough for low contention)
  $seq = 1;
  if ($c = $db->prepare('SELECT COUNT(*) FROM certificates_issued WHERE cert_type=?')) { $c->bind_param('s',$type); $c->execute(); $c->bind_result($cnt); if ($c->fetch()) { $seq = (int)$cnt + 1; } $c->close(); }
  return (string)$seq;
}

function build_cert_number(string $fmt, mysqli $db, string $type, int $user_id): string {
  $seq = next_sequence($db,$type);
  $repl = [
    '{YYYY}' => date('Y'),
    '{MM}'   => date('m'),
    '{DD}'   => date('d'),
    '{USER_ID}' => (string)$user_id,
  ];
  // sequence tokens variable length
  if (strpos($fmt,'{SEQ') !== false) {
    if (preg_match_all('/\{SEQ(\d+)\}/',$fmt,$m,PREG_SET_ORDER)) {
      foreach ($m as $match) {
        $w = (int)$match[1];
        $repl[$match[0]] = str_pad($seq,$w,'0',STR_PAD_LEFT);
      }
    }
  }
  return strtr($fmt,$repl);
}

$cert_number = build_cert_number($tpl['cert_number_format'] ?? 'NC-{YYYY}-{SEQ4}',$db,$type,$user_id);

// Substitute placeholders for visible fields
$awardeeText = $tpl['cert_awardee_name'] ?: '{USER_NAME}';
$seminarText = $tpl['seminar_title'] ?: '{SEMINAR_TITLE}';
$awardeeText = str_replace(['{USER_NAME}','{NAME}','{FULL_NAME}'], $name, $awardeeText);
$seminarRuntime = 'National Convention'; // TODO: derive dynamically
$seminarText = str_replace(['{SEMINAR_TITLE}','{TITLE}'], $seminarRuntime, $seminarText); // TODO: dynamic seminar name per session if needed
// Allow tokens for cert number inside template text too
$awardeeText = str_replace(['{CERT_NUMBER}','{CERT_NO}'],$cert_number,$awardeeText);
$seminarText = str_replace(['{CERT_NUMBER}','{CERT_NO}'],$cert_number,$seminarText);

// Base64 embed background image (for portability) OR use absolute path; we'll embed for self-contained PDF
function file_to_data_uri($rel){
  $abs = __DIR__ . '/../' . $rel;
  if (!is_file($abs)) return '';
  $data = @file_get_contents($abs); if ($data===false) return '';
  $mime = 'image/'. (str_ends_with(strtolower($rel),'.png')?'png':'jpeg');
  return 'data:'.$mime.';base64,'.base64_encode($data);
}

$bg_rel = $tpl['bg_url'];
$bg_uri = $bg_rel ? file_to_data_uri($bg_rel) : '';

// Build absolutely positioned layers using the original background image's intrinsic dimensions
function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }

$show_awardee = (int)$tpl['show_awardee']===1;
$show_seminar = (int)$tpl['show_seminar']===1;
$show_number  = (int)$tpl['show_number']===1;

// Build signatures HTML
$sigHtml = '';
foreach ($signatures as $sg){
  $sigUrl = $sg['signature_url'];
  $sigUri = $sigUrl ? file_to_data_uri($sigUrl) : '';
  $wPct = (float)$sg['width_pct'];
  $x = (float)$sg['pos_x'];
  $y = (float)$sg['pos_y'];
  if ($sigUri) {
    $sigHtml .= '<img src="'.esc($sigUri).'" style="position:absolute;left:'.$x.'%;top:'.$y.'%;width:'.$wPct.'%;height:auto;" />';
  }
}

$issuedAt = date('Y-m-d H:i');

// Determine intrinsic background image size for accurate positioning
$imgW = 1600; $imgH = 1131; // defaults
if ($bg_rel) {
  $absBg = __DIR__ . '/../' . $bg_rel;
  if (is_file($absBg)) {
    $info = @getimagesize($absBg);
    if ($info && !empty($info[0]) && !empty($info[1])) { $imgW = (int)$info[0]; $imgH = (int)$info[1]; }
  }
}
// Cap extremely large images to avoid Dompdf memory issues
if ($imgW > 2400) { $scale = 2400 / $imgW; $imgW = (int)round($imgW*$scale); $imgH = (int)round($imgH*$scale); }

// Paper size in points (1 pt = 1/72 in). Assume 96 CSS px = 72 pt mapping.
$paperWpt = $imgW * 72 / 96; $paperHpt = $imgH * 72 / 96;

$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
  .'@page { margin:0; }'
  .'html,body{margin:0;padding:0;font-family:Helvetica,Arial,sans-serif;}'
  .'.page{position:relative;width:'.$imgW.'px;height:'.$imgH.'px;overflow:hidden;}'
  .'.bg{position:absolute;left:0;top:0;width:100%;height:100%;}'
  .'.txt{position:absolute;font-weight:500;letter-spacing:.5px;white-space:nowrap;}'
  .'.awardee{font-weight:600;}'
  .'</style></head><body>';
$html .= '<div class="page">';
if ($bg_uri) { $html .= '<img class="bg" src="'.esc($bg_uri).'" style="image-rendering:auto;" />'; }
// Positions (percent) relative to original editor logic (top-left anchor — matches admin drag tool)
// Clamp positional percentages to [0,100] to preserve layout and avoid overflow
function clamp_pct($v){ $f=(float)$v; if($f<0)$f=0; if($f>100)$f=100; return $f; }
$awardee_left_pct = clamp_pct($tpl['pos_cert_awardee_name_x']);
$awardee_top_pct  = clamp_pct($tpl['pos_cert_awardee_name_y']);
$seminar_left_pct = clamp_pct($tpl['pos_seminar_title_x']);
$seminar_top_pct  = clamp_pct($tpl['pos_seminar_title_y']);
$number_left_pct  = clamp_pct($tpl['pos_cert_number_x']);
$number_top_pct   = clamp_pct($tpl['pos_cert_number_y']);

$placeholders = [
  ['enabled'=>$show_awardee,'class'=>'awardee','left'=>$awardee_left_pct,'top'=>$awardee_top_pct,'text'=>$awardeeText,'fam'=>$faFam,'size'=>$faSize,'color'=>$faColor],
  ['enabled'=>$show_seminar,'class'=>'seminar','left'=>$seminar_left_pct,'top'=>$seminar_top_pct,'text'=>$seminarText,'fam'=>$fsFam,'size'=>$fsSize,'color'=>$fsColor],
  ['enabled'=>$show_number,'class'=>'number','left'=>$number_left_pct,'top'=>$number_top_pct,'text'=>$cert_number,'fam'=>$fnFam,'size'=>$fnSize,'color'=>$fnColor],
];
foreach ($placeholders as $ph) {
  if (!$ph['enabled']) continue;
  $html .= '<div class="txt '.$ph['class'].'" style="left:'.$ph['left'].'%;top:'.$ph['top'].'%;font-family:'.esc($ph['fam']).';font-size:'.$ph['size'].'px;color:'.esc($ph['color']).';">'.esc($ph['text']).'</div>';
}
$html .= $sigHtml;
$html .= '<div style="position:absolute;right:18px;bottom:14px;font-size:10px;color:#444;">Issued '.$issuedAt.'</div>';
$html .= '</div></body></html>';

// Replace any leftover tokens
$html = str_replace(['{CERT_NUMBER}','{CERT_NO}'],$cert_number,$html);

// Render
// Render with consistent DPI so px->pt mapping matches the admin editor math (96 CSS px = 72 pt)
$dompdf = new Dompdf(['defaultFont'=>'Helvetica']);
$dompdf->set_option('dpi', 96);
$dompdf->set_option('isHtml5ParserEnabled', true);
$dompdf->set_option('isRemoteEnabled', true);
$dompdf->setPaper([0,0,$paperWpt,$paperHpt]);
$dompdf->loadHtml($html,'UTF-8');
$dompdf->render();

// Persist file
$outDir = __DIR__ . '/../uploads/certificates/generated';
if (!is_dir($outDir)) @mkdir($outDir,0775,true);
$fileName = $user_id . '_' . $type . '_' . substr(sha1($user_id.$type.$issuedAt.random_bytes(4)),0,10) . '.pdf';
$absPath = $outDir . '/' . $fileName;
file_put_contents($absPath, $dompdf->output());
$relPath = 'uploads/certificates/generated/' . $fileName;

// Record issuance, include cert_number if column exists
$hasCertNumCol = false;
if ($colCheck = $db->query("SHOW COLUMNS FROM certificates_issued LIKE 'cert_number'")) { $hasCertNumCol = $colCheck->num_rows>0; $colCheck->close(); }
if ($hasCertNumCol) {
  if ($ins = $db->prepare('INSERT INTO certificates_issued (user_id, cert_type, file_path, cert_number) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE file_path=VALUES(file_path), cert_number=VALUES(cert_number), generated_at=NOW()')) {
    $ins->bind_param('isss',$user_id,$type,$relPath,$cert_number);
    if (!$ins->execute()) { 
      // Release lock before erroring
      if ($rl = $db->prepare('SELECT RELEASE_LOCK(?)')) { $rl->bind_param('s',$lockKey); $rl->execute(); $rl->close(); }
      http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Failed to record issuance']); exit; }
    $ins->close();
  }
} else {
  if ($ins = $db->prepare('INSERT INTO certificates_issued (user_id, cert_type, file_path) VALUES (?,?,?) ON DUPLICATE KEY UPDATE file_path=VALUES(file_path), generated_at=NOW()')) {
    $ins->bind_param('iss',$user_id,$type,$relPath);
    if (!$ins->execute()) { 
      if ($rl = $db->prepare('SELECT RELEASE_LOCK(?)')) { $rl->bind_param('s',$lockKey); $rl->execute(); $rl->close(); }
      http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Failed to record issuance']); exit; }
    $ins->close();
  }
}

// Release advisory lock after successful issuance record
if ($rl = $db->prepare('SELECT RELEASE_LOCK(?)')) { $rl->bind_param('s',$lockKey); $rl->execute(); $rl->close(); }

echo json_encode(['ok'=>true,'file_path'=>$relPath,'already'=>false,'cert_number'=>$cert_number]);
