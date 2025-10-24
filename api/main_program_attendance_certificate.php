<?php
// Generate or fetch Main Program Attendance certificate (attendance across enabled days)
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vendor/autoload.php';
use Dompdf\Dompdf;

if (isset($conn) && $conn instanceof mysqli) $db = $conn; elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli; else { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB unavailable']); exit; }
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0; if ($user_id<=0){ http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Login required']); exit; }

// Progress only mode
$progressOnly = isset($_GET['progress']) ? true : false;

// Admin-configurable rules (admin_settings table):
//  - attendance_min_days_required: int (0=disabled; default 0)
//  - attendance_require_past_enabled_only: bool (1/0; default 1)
//  - attendance_enforce_strict: bool (1/0; default 0)

$getSetting = function(string $key, $default=null) use ($db){
  if(!$stmt=$db->prepare('SELECT `setting_value` FROM admin_settings WHERE `setting_key`=? LIMIT 1')) return $default;
  $stmt->bind_param('s',$key); $stmt->execute(); $stmt->bind_result($val); $out=$default; if($stmt->fetch()){ $out=$val; } $stmt->close(); return $out;
};

$minDaysRequired = (int)($getSetting('attendance_min_days_required', '0') ?? '0'); if($minDaysRequired<0) $minDaysRequired=0;
$requirePastEnabledOnly = (int)($getSetting('attendance_require_past_enabled_only', '1') ?? '1') === 1;
$enforceStrict = (int)($getSetting('attendance_enforce_strict', '0') ?? '0') === 1;

// Global certificate enable or scheduled activation
$certEnabled = (int)($getSetting('cert_generation_enabled','0') ?? '0') === 1;
$autoEnableStr = (string)($getSetting('cert_auto_enable_date','') ?? '');
if(strpos($autoEnableStr,'T')!==false){ $autoEnableStr = str_replace('T',' ', $autoEnableStr); }
$autoEnableTs = $autoEnableStr !== '' ? strtotime($autoEnableStr) : null;
$nowTs = time();
$canGenerateNow = $certEnabled || ($autoEnableTs !== null && $nowTs >= $autoEnableTs);

// Fetch days (ordered) to derive day_number mapping
$days = [];
if($res = $db->query("SELECT id,label,day_date,is_enabled FROM function_room_days ORDER BY day_date ASC")){
  while($row=$res->fetch_assoc()){ $days[]=$row; }
  $res->close();
}
$dayNumberById = []; foreach($days as $i=>$d){ $dayNumberById[(int)$d['id']] = $i+1; }

// Determine required days: enabled and (optionally) day_date <= today
$today = date('Y-m-d');
$requiredDayNumbers = [];
$futureDayNumbers = [];
foreach($days as $d){
  $dn = $dayNumberById[(int)$d['id']] ?? null; if(!$dn) continue;
  $date = $d['day_date']; $enabled = (int)$d['is_enabled']===1; if(!$enabled) continue;
  $isPastOrToday = !$date || $date <= $today;
  if(!$requirePastEnabledOnly || $isPastOrToday){ $requiredDayNumbers[]=$dn; }
  else { $futureDayNumbers[] = $dn; }
}
$requiredDayNumbers = array_values(array_unique($requiredDayNumbers));

// Get attendance rows for user
$attended = [];
if($st=$db->prepare('SELECT day_number FROM attendance WHERE user_id=?')){ $st->bind_param('i',$user_id); $st->execute(); $st->bind_result($dn); while($st->fetch()){ $attended[] = (int)$dn; } $st->close(); }
$attended = array_unique($attended);
// Hard minimum: require at least one attendance to be considered
$hasAnyAttendance = count($attended) > 0;

// Compute counts
$requiredCount = count($requiredDayNumbers);
$attendedRequired = 0; foreach($requiredDayNumbers as $dn){ if(in_array($dn,$attended,true)) $attendedRequired++; }
$missingRequired = array_values(array_diff($requiredDayNumbers,$attended));
$incomplete = count($missingRequired) > 0;
$relaxedAllowed = $incomplete && empty(array_diff($missingRequired, $futureDayNumbers));
if($requiredCount === 0){ $relaxedAllowed = true; $incomplete = false; }

// Eligibility per admin rules
$eligibleStrict = !$incomplete; // all required met
$eligibleMin = ($minDaysRequired > 0 && $attendedRequired >= $minDaysRequired);
$eligible = $enforceStrict ? $eligibleStrict : ($eligibleStrict || $eligibleMin);

if($progressOnly){
  echo json_encode([
    'ok'=>true,
    'required_days'=>$requiredDayNumbers,
    'attended'=>$attended,
    'missing'=>$missingRequired,
    'complete_strict'=>!$incomplete,
    'complete_relaxed'=>(!$incomplete)||$relaxedAllowed,
    'eligible'=>$eligible,
    'eligible_strict'=>$eligibleStrict,
    'eligible_min'=>$eligibleMin,
    'min_days_required'=>$minDaysRequired,
    'rules'=>[ 'require_past_only'=>$requirePastEnabledOnly, 'enforce_strict'=>$enforceStrict ],
    'can_generate_now'=>$canGenerateNow,
    'activation_at'=>$autoEnableStr,
    'has_any_attendance'=>$hasAnyAttendance
  ]);
  exit;
}

if(!$canGenerateNow){ http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Certificate generation is not yet enabled','activation_at'=>$autoEnableStr]); exit; }
if(!$eligible){ http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Not eligible for attendance certificate yet','required_days'=>$requiredDayNumbers,'attended'=>$attended,'missing'=>$missingRequired,'min_days_required'=>$minDaysRequired,'rules'=>['require_past_only'=>$requirePastEnabledOnly,'enforce_strict'=>$enforceStrict]]); exit; }
// Enforce hard minimum attendance: at least 1 submission
if(!$hasAnyAttendance){ http_response_code(403); echo json_encode(['ok'=>false,'error'=>'At least one attendance submission is required']); exit; }

// Table for issued attendance certificates
@$db->query("CREATE TABLE IF NOT EXISTS main_program_attendance_certificates (\n  id INT AUTO_INCREMENT PRIMARY KEY,\n  user_id INT NOT NULL UNIQUE,\n  file_path VARCHAR(255) NOT NULL,\n  cert_number VARCHAR(120) DEFAULT NULL,\n  generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n  KEY idx_user(user_id)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Reuse if exists (unless regenerate requested)
$forceRegen = false; if($_SERVER['REQUEST_METHOD']==='POST'){ $raw=file_get_contents('php://input'); if($raw){ $j=json_decode($raw,true); if(!empty($j['regenerate'])) $forceRegen=true; }} if(isset($_GET['regenerate']) && $_GET['regenerate']=='1') $forceRegen=true;
if(!$forceRegen){ if($st=$db->prepare('SELECT file_path, cert_number FROM main_program_attendance_certificates WHERE user_id=? LIMIT 1')){ $st->bind_param('i',$user_id); $st->execute(); $st->bind_result($fp,$cn); if($st->fetch()){ $st->close(); echo json_encode(['ok'=>true,'already'=>true,'file_path'=>$fp,'cert_number'=>$cn]); exit; } $st->close(); }}

// Load template for attendance type
$tpl=null; if($st=$db->prepare("SELECT * FROM certificates WHERE LOWER(type)='main_program_attendance' ORDER BY updated_at DESC,id DESC LIMIT 1")){ $st->execute(); $r=$st->get_result(); $tpl=$r->fetch_assoc(); $st->close(); }
if(!$tpl){ http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Attendance certificate template not set (type main_program_attendance)']); exit; }

function esc($s){ return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function file_to_data_uri($rel){ $abs=__DIR__.'/../'.$rel; if(!is_file($abs)) return ''; $data=@file_get_contents($abs); if($data===false) return ''; $mime=str_ends_with(strtolower($rel),'.png')?'image/png':'image/jpeg'; return 'data:'.$mime.';base64,'.base64_encode($data); }

$bg_rel=$tpl['bg_url']; $bg_uri=$bg_rel?file_to_data_uri($bg_rel):''; $imgW=1600; $imgH=1131; if($bg_rel){ $absBg=__DIR__.'/../'.$bg_rel; if(is_file($absBg)){ $info=@getimagesize($absBg); if($info){ $imgW=(int)$info[0]; $imgH=(int)$info[1]; } }} if($imgW>2400){ $scale=2400/$imgW; $imgW=(int)round($imgW*$scale); $imgH=(int)round($imgH*$scale); }
$paperWpt=$imgW*72/96; $paperHpt=$imgH*72/96; $issuedAt=date('Y-m-d H:i');

// Cert number format
$rawFmt = $tpl['cert_number_format'] ?? 'MPA-{YYYY}-{SEQ4}-{USER_ID}';
$seq=1; if($c=$db->prepare('SELECT COUNT(*) FROM main_program_attendance_certificates')){ $c->execute(); $c->bind_result($cnt); if($c->fetch()) $seq=(int)$cnt+1; $c->close(); }
$repl=[ '{YYYY}'=>date('Y'), '{YY}'=>date('y'), '{MM}'=>date('m'), '{DD}'=>date('d'), '{USER_ID}'=>$user_id ];
if(preg_match_all('/\{SEQ(\d+)\}/',$rawFmt,$m,PREG_SET_ORDER)){ foreach($m as $match){ $w=(int)$match[1]; $repl[$match[0]]=str_pad($seq,$w,'0',STR_PAD_LEFT); } }
$cert_number=strtr($rawFmt,$repl);

// User name
$user_name='Participant'; if($st=$db->prepare('SELECT name FROM users WHERE id=? LIMIT 1')){ $st->bind_param('i',$user_id); $st->execute(); $st->bind_result($user_name); $st->fetch(); $st->close(); }
$awardeeTemplate = $tpl['cert_awardee_name'] ?: '{USER_NAME}';
$awardee=str_replace(['{USER_NAME}','{NAME}','{FULL_NAME}'],$user_name,$awardeeTemplate);
$awardee=str_replace(['{CERT_NUMBER}'],$cert_number,$awardee);
$seminarTitle = $tpl['seminar_title'] ?: 'Main Program Attendance';
$seminarTitle=str_replace(['{CERT_NUMBER}'],$cert_number,$seminarTitle);
$seminarTitle=str_replace(['{TOTAL_DAYS}'], (string)max($requiredCount, count($days)), $seminarTitle);

$show_awardee=(int)$tpl['show_awardee']===1; $show_seminar=(int)$tpl['show_seminar']===1; $show_number=(int)$tpl['show_number']===1;
$awardee_left=(float)$tpl['pos_cert_awardee_name_x']; $awardee_top=(float)$tpl['pos_cert_awardee_name_y']; $seminar_left=(float)$tpl['pos_seminar_title_x']; $seminar_top=(float)$tpl['pos_seminar_title_y']; $num_left=(float)$tpl['pos_cert_number_x']; $num_top=(float)$tpl['pos_cert_number_y'];

// Fonts
$faFam=$tpl['font_awardee_family']??'Helvetica'; $faSize=(int)($tpl['font_awardee_size_px']??46); if($faSize<6)$faSize=6; if($faSize>200)$faSize=200; $faColor=$tpl['font_awardee_color']??'#000000';
$fsFam=$tpl['font_seminar_family']??'Helvetica'; $fsSize=(int)($tpl['font_seminar_size_px']??24); if($fsSize<6)$fsSize=6; if($fsSize>200)$fsSize=200; $fsColor=$tpl['font_seminar_color']??'#000000';
$fnFam=$tpl['font_number_family']??'Helvetica'; $fnSize=(int)($tpl['font_number_size_px']??16); if($fnSize<6)$fnSize=6; if($fnSize>200)$fnSize=200; $fnColor=$tpl['font_number_color']??'#000000';

// Signatures
$sigHtml=''; if(isset($tpl['id'])){ $cid=(int)$tpl['id']; if($sig=$db->prepare('SELECT signature_url,pos_x,pos_y,width_pct FROM certificate_signatures WHERE certificate_id=? AND enabled=1')){ $sig->bind_param('i',$cid); $sig->execute(); $rs=$sig->get_result(); while($row=$rs->fetch_assoc()){ $sUri=file_to_data_uri($row['signature_url']); if($sUri){ $sx=(float)$row['pos_x']; $sy=(float)$row['pos_y']; $sw=(float)$row['width_pct']; $sigHtml.='<img src="'.esc($sUri).'" style="position:absolute;left:'.$sx.'%;top:'.$sy.'%;width:'.$sw.'%;height:auto;" />'; } } $sig->close(); } }

$html='<!DOCTYPE html><html><head><meta charset="utf-8"><style>@page{margin:0;}html,body{margin:0;padding:0;} .page{position:relative;width:'.$imgW.'px;height:'.$imgH.'px;} .bg{position:absolute;left:0;top:0;width:100%;height:100%;} .txt{position:absolute;white-space:nowrap;}</style></head><body><div class="page">'; if($bg_uri){ $html.='<img class="bg" src="'.esc($bg_uri).'" />'; }
if($show_awardee){ $html.='<div class="txt" style="left:'.$awardee_left.'%;top:'.$awardee_top.'%;font-family:'.esc($faFam).';font-size:'.$faSize.'px;color:'.esc($faColor).';font-weight:600;">'.esc($awardee).'</div>'; }
if($show_seminar){ $html.='<div class="txt" style="left:'.$seminar_left.'%;top:'.$seminar_top.'%;font-family:'.esc($fsFam).';font-size:'.$fsSize.'px;color:'.esc($fsColor).';">'.esc($seminarTitle).'</div>'; }
if($show_number){ $html.='<div class="txt" style="left:'.$num_left.'%;top:'.$num_top.'%;font-family:'.esc($fnFam).';font-size:'.$fnSize.'px;color:'.esc($fnColor).';">'.esc($cert_number).'</div>'; }
$html.=$sigHtml;
$html.='<div style="position:absolute;right:16px;bottom:12px;font-size:10px;color:#333;">Issued '.$issuedAt.'</div></div></body></html>';

$dompdf=new Dompdf(['defaultFont'=>'Helvetica']); $dompdf->setPaper([0,0,$paperWpt,$paperHpt]); $dompdf->loadHtml($html,'UTF-8'); $dompdf->render();
$outDir=__DIR__.'/../uploads/certificates/generated'; if(!is_dir($outDir)) @mkdir($outDir,0775,true); $fileName='main_attendance_u'.$user_id.'_'.substr(sha1($cert_number.random_bytes(4)),0,8).'.pdf'; $abs=$outDir.'/'.$fileName; file_put_contents($abs,$dompdf->output()); $rel='uploads/certificates/generated/'.$fileName;

if($ins=$db->prepare('INSERT INTO main_program_attendance_certificates (user_id,file_path,cert_number) VALUES (?,?,?) ON DUPLICATE KEY UPDATE file_path=VALUES(file_path), cert_number=VALUES(cert_number), generated_at=NOW()')){ $ins->bind_param('iss',$user_id,$rel,$cert_number); $ins->execute(); $ins->close(); }

echo json_encode(['ok'=>true,'file_path'=>$rel,'cert_number'=>$cert_number,'already'=>false,'attendance'=>['required'=>$requiredDayNumbers,'attended'=>$attended,'missing'=>$missingRequired]]);