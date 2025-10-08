<?php
// Email the user's Main Program Attendance certificate (generate if needed)
// POST (optionally JSON: {regenerate:1}) or GET (?regenerate=1)
// Response: { ok, sent, file_path, cert_number, regenerated }

header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer; use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id']<=0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not authenticated']); exit; }
$user_id = (int)$_SESSION['user_id'];
$db = isset($conn) && $conn instanceof mysqli ? $conn : (isset($mysqli)&&$mysqli instanceof mysqli ? $mysqli : null);
if(!$db){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB unavailable']); exit; }

$forceRegen=false; $raw=file_get_contents('php://input'); if($raw){ $in=json_decode($raw,true); if(is_array($in) && !empty($in['regenerate'])) $forceRegen=true; }
if(isset($_GET['regenerate']) && $_GET['regenerate']=='1') $forceRegen=true;

// Compute attendance progress (mirrors logic in main_program_attendance_certificate.php)
$days=[]; if($res=$db->query("SELECT id,label,day_date,is_enabled FROM function_room_days ORDER BY day_date ASC")){ while($row=$res->fetch_assoc()) $days[]=$row; $res->close(); }
$dayNumberById=[]; foreach($days as $i=>$d){ $dayNumberById[(int)$d['id']]=$i+1; }
$today=date('Y-m-d'); $requiredDayNumbers=[]; $futureDayNumbers=[];
foreach($days as $d){ $dn=$dayNumberById[(int)$d['id']]??null; if(!$dn) continue; $date=$d['day_date']; $enabled=(int)$d['is_enabled']===1; if(!$enabled) continue; if($date && $date <= $today){ $requiredDayNumbers[]=$dn; } else { $futureDayNumbers[]=$dn; } }
$requiredDayNumbers=array_values(array_unique($requiredDayNumbers));
$attended=[]; if($st=$db->prepare('SELECT day_number FROM attendance WHERE user_id=?')){ $st->bind_param('i',$user_id); $st->execute(); $st->bind_result($dn); while($st->fetch()) $attended[]=(int)$dn; $st->close(); }
$attended=array_unique($attended);
$missingRequired=array_values(array_diff($requiredDayNumbers,$attended));
$incomplete=count($missingRequired)>0; $relaxedAllowed = !$incomplete || empty(array_diff($missingRequired,$futureDayNumbers));
if($incomplete && !$relaxedAllowed){ http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Attendance incomplete','missing'=>$missingRequired,'required_days'=>$requiredDayNumbers]); exit; }

// Ensure certificate table exists
@$db->query("CREATE TABLE IF NOT EXISTS main_program_attendance_certificates (\n  id INT AUTO_INCREMENT PRIMARY KEY,\n  user_id INT NOT NULL UNIQUE,\n  file_path VARCHAR(255) NOT NULL,\n  cert_number VARCHAR(120) DEFAULT NULL,\n  generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n  KEY idx_user(user_id)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Fetch existing
$existing=null; if($st=$db->prepare('SELECT file_path, cert_number FROM main_program_attendance_certificates WHERE user_id=? LIMIT 1')){ $st->bind_param('i',$user_id); $st->execute(); $existing=$st->get_result()->fetch_assoc(); $st->close(); }

// Load template
$tpl=null; if($st=$db->prepare("SELECT * FROM certificates WHERE LOWER(type)='main_program_attendance' ORDER BY updated_at DESC,id DESC LIMIT 1")){ $st->execute(); $tpl=$st->get_result()->fetch_assoc(); $st->close(); }
if(!$tpl){ http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Attendance certificate template not set']); exit; }

// Maybe reuse existing if not forcing regeneration
if($existing && !$forceRegen && $existing['file_path'] && file_exists(__DIR__.'/../'.$existing['file_path'])){
  $cert=['file_path'=>$existing['file_path'],'cert_number'=>$existing['cert_number'],'regenerated'=>false];
} else {
  // Re-generate (embed generation logic similar to main_program_attendance_certificate.php)
  function esc($s){ return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
  function file_to_data_uri($rel){ $abs=__DIR__.'/../'.$rel; if(!is_file($abs)) return ''; $data=@file_get_contents($abs); if($data===false) return ''; $mime=str_ends_with(strtolower($rel),'.png')?'image/png':'image/jpeg'; return 'data:'.$mime.';base64,'.base64_encode($data); }
  $bg_rel=$tpl['bg_url']; $bg_uri=$bg_rel?file_to_data_uri($bg_rel):''; $imgW=1600; $imgH=1131; if($bg_rel){ $absBg=__DIR__.'/../'.$bg_rel; if(is_file($absBg)){ $info=@getimagesize($absBg); if($info){ $imgW=(int)$info[0]; $imgH=(int)$info[1]; } }} if($imgW>2400){ $scale=2400/$imgW; $imgW=(int)round($imgW*$scale); $imgH=(int)round($imgH*$scale); }
  $paperWpt=$imgW*72/96; $paperHpt=$imgH*72/96; $issuedAt=date('Y-m-d H:i');
  // Cert number
  $rawFmt = $tpl['cert_number_format'] ?? 'MPA-{YYYY}-{SEQ4}-{USER_ID}';
  $seq=1; if($c=$db->prepare('SELECT COUNT(*) FROM main_program_attendance_certificates')){ $c->execute(); $c->bind_result($cnt); if($c->fetch()) $seq=(int)$cnt+1; $c->close(); }
  $repl=[ '{YYYY}'=>date('Y'), '{YY}'=>date('y'), '{MM}'=>date('m'), '{DD}'=>date('d'), '{USER_ID}'=>$user_id ];
  if(preg_match_all('/\{SEQ(\d+)\}/',$rawFmt,$m,PREG_SET_ORDER)){ foreach($m as $match){ $w=(int)$match[1]; $repl[$match[0]]=str_pad($seq,$w,'0',STR_PAD_LEFT); } }
  $cert_number=strtr($rawFmt,$repl);
  // Awardee & seminar
  $user_name='Participant'; if($st=$db->prepare('SELECT name FROM users WHERE id=? LIMIT 1')){ $st->bind_param('i',$user_id); $st->execute(); $st->bind_result($user_name); $st->fetch(); $st->close(); }
  $awardeeTemplate = $tpl['cert_awardee_name'] ?: '{USER_NAME}';
  $awardee=str_replace(['{USER_NAME}','{NAME}','{FULL_NAME}'],$user_name,$awardeeTemplate);
  $awardee=str_replace(['{CERT_NUMBER}'],$cert_number,$awardee);
  $seminarTitle = $tpl['seminar_title'] ?: 'Main Program Attendance';
  $seminarTitle=str_replace(['{CERT_NUMBER}'],$cert_number,$seminarTitle);
  $seminarTitle=str_replace(['{TOTAL_DAYS}'], (string)max(count($requiredDayNumbers), count($days)), $seminarTitle);
  $show_awardee=(int)$tpl['show_awardee']===1; $show_seminar=(int)$tpl['show_seminar']===1; $show_number=(int)$tpl['show_number']===1;
  $awardee_left=(float)$tpl['pos_cert_awardee_name_x']; $awardee_top=(float)$tpl['pos_cert_awardee_name_y']; $seminar_left=(float)$tpl['pos_seminar_title_x']; $seminar_top=(float)$tpl['pos_seminar_title_y']; $num_left=(float)$tpl['pos_cert_number_x']; $num_top=(float)$tpl['pos_cert_number_y'];
  $faFam=$tpl['font_awardee_family']??'Helvetica'; $faSize=(int)($tpl['font_awardee_size_px']??46); if($faSize<6)$faSize=6; if($faSize>200)$faSize=200; $faColor=$tpl['font_awardee_color']??'#000000';
  $fsFam=$tpl['font_seminar_family']??'Helvetica'; $fsSize=(int)($tpl['font_seminar_size_px']??24); if($fsSize<6)$fsSize=6; if($fsSize>200)$fsSize=200; $fsColor=$tpl['font_seminar_color']??'#000000';
  $fnFam=$tpl['font_number_family']??'Helvetica'; $fnSize=(int)($tpl['font_number_size_px']??16); if($fnSize<6)$fnSize=6; if($fnSize>200)$fnSize=200; $fnColor=$tpl['font_number_color']??'#000000';
  $sigHtml=''; if(isset($tpl['id'])){ $cid=(int)$tpl['id']; if($sig=$db->prepare('SELECT signature_url,pos_x,pos_y,width_pct FROM certificate_signatures WHERE certificate_id=? AND enabled=1')){ $sig->bind_param('i',$cid); $sig->execute(); $rs=$sig->get_result(); while($row=$rs->fetch_assoc()){ $sUri=file_to_data_uri($row['signature_url']); if($sUri){ $sx=(float)$row['pos_x']; $sy=(float)$row['pos_y']; $sw=(float)$row['width_pct']; $sigHtml.='<img src="'.esc($sUri).'" style="position:absolute;left:'.$sx.'%;top:'.$sy.'%;width:'.$sw.'%;height:auto;" />'; } } $sig->close(); } }
  $html='<!DOCTYPE html><html><head><meta charset="utf-8"><style>@page{margin:0;}html,body{margin:0;padding:0;} .page{position:relative;width:'.$imgW.'px;height:'.$imgH.'px;} .bg{position:absolute;left:0;top:0;width:100%;height:100%;} .txt{position:absolute;white-space:nowrap;}</style></head><body><div class="page">'; if($bg_uri){ $html.='<img class="bg" src="'.esc($bg_uri).'" />'; }
  if($show_awardee){ $html.='<div class="txt" style="left:'.$awardee_left.'%;top:'.$awardee_top.'%;font-family:'.esc($faFam).';font-size:'.$faSize.'px;color:'.esc($faColor).';font-weight:600;">'.esc($awardee).'</div>'; }
  if($show_seminar){ $html.='<div class="txt" style="left:'.$seminar_left.'%;top:'.$seminar_top.'%;font-family:'.esc($fsFam).';font-size:'.$fsSize.'px;color:'.esc($fsColor).';">'.esc($seminarTitle).'</div>'; }
  if($show_number){ $html.='<div class="txt" style="left:'.$num_left.'%;top:'.$num_top.'%;font-family:'.esc($fnFam).';font-size:'.$fnSize.'px;color:'.esc($fnColor).';">'.esc($cert_number).'</div>'; }
  $html.=$sigHtml; $html.='<div style="position:absolute;right:16px;bottom:12px;font-size:10px;color:#333;">Issued '.$issuedAt.'</div></div></body></html>';
  $dompdf=new Dompdf([ 'defaultFont'=>'Helvetica' ]); $dompdf->setPaper([0,0,$paperWpt,$paperHpt]); $dompdf->loadHtml($html,'UTF-8'); $dompdf->render();
  $outDir=__DIR__.'/../uploads/certificates/generated'; if(!is_dir($outDir)) @mkdir($outDir,0775,true); $fileName='main_attendance_u'.$user_id.'_'.substr(sha1($cert_number.random_bytes(4)),0,8).'.pdf'; $abs=$outDir.'/'.$fileName; file_put_contents($abs,$dompdf->output()); $rel='uploads/certificates/generated/'.$fileName;
  if($ins=$db->prepare('INSERT INTO main_program_attendance_certificates (user_id,file_path,cert_number) VALUES (?,?,?) ON DUPLICATE KEY UPDATE file_path=VALUES(file_path), cert_number=VALUES(cert_number), generated_at=NOW()')){ $ins->bind_param('iss',$user_id,$rel,$cert_number); $ins->execute(); $ins->close(); }
  $cert=['file_path'=>$rel,'cert_number'=>$cert_number,'regenerated'=> (bool)$existing];
}

// Email sending
$email=''; $name=''; if($st=$db->prepare('SELECT email,name FROM users WHERE id=? LIMIT 1')){ $st->bind_param('i',$user_id); $st->execute(); $st->bind_result($email,$name); $st->fetch(); $st->close(); }
if(!$email){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'User email not found','certificate'=>$cert]); exit; }
$absPath = __DIR__.'/../'.$cert['file_path']; if(!file_exists($absPath)){ http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Certificate file missing','certificate'=>$cert]); exit; }

$get = fn($p,$f='') => getenv($p) ?: ($f?getenv($f):'');
$cfg=[
  'host'=>$get('MAIL_HOST','SMTP_HOST'),
  'user'=>$get('MAIL_USERNAME','SMTP_USER'),
  'pass'=>$get('MAIL_PASSWORD','SMTP_PASS'),
  'port'=>(int)($get('MAIL_PORT','SMTP_PORT')?:587),
  'from_addr'=>$get('MAIL_FROM_ADDRESS','SMTP_FROM')?:'noreply@example.com',
  'from_name'=>$get('MAIL_FROM_NAME','SMTP_FROM_NAME')?:'Natcon Dashboard',
  'enc'=>strtolower($get('MAIL_ENCRYPTION','SMTP_SECURE')?:'tls'),
  'debug'=>(int)(getenv('MAIL_DEBUG')?:0),
  'allow_alias'=>(int)(getenv('MAIL_ALLOW_ALIAS')?:getenv('SMTP_ALLOW_ALIAS')?:0),
];
if($cfg['host']===''||$cfg['user']===''||$cfg['pass']===''){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Mail server not configured','certificate'=>$cert]); exit; }

$appEnv=strtolower(getenv('APP_ENV')?:''); $forceDebug=($cfg['debug']>0)||$appEnv==='local'||isset($_GET['diag']);
$mail=new PHPMailer(true);
try{
  $mail->isSMTP(); $mail->Host=$cfg['host']; $mail->SMTPAuth=true; $mail->Username=$cfg['user']; $mail->Password=$cfg['pass'];
  if(in_array($cfg['enc'],['tls','starttls'],true)) $mail->SMTPSecure=PHPMailer::ENCRYPTION_STARTTLS; elseif(in_array($cfg['enc'],['ssl','smtps'],true)){ $mail->SMTPSecure=PHPMailer::ENCRYPTION_SMTPS; if($cfg['port']===587) $cfg['port']=465; } else $mail->SMTPSecure=false; $mail->Port=$cfg['port'];
  if($forceDebug){ $mail->SMTPDebug=2; $dbg=''; $mail->Debugoutput=function($str,$lvl) use (&$dbg){ $dbg.="[L$lvl] $str\n"; }; }
  $authDom=strtolower(substr(strrchr($cfg['user'],'@')?:'',1)); $fromDom=strtolower(substr(strrchr($cfg['from_addr'],'@')?:'',1));
  if($cfg['host']==='smtp.gmail.com' && $authDom && $fromDom && $authDom!==$fromDom && !$cfg['allow_alias']){ $mail->setFrom($cfg['user'],$cfg['from_name'].' (via)'); $mail->addReplyTo($cfg['from_addr'],$cfg['from_name']); } else { $mail->setFrom($cfg['from_addr'],$cfg['from_name']); }
  $display=trim($name)?:$email; $mail->addAddress($email,$display);
  $mail->Subject='Your Main Program Attendance Certificate';
  $mail->Body="Dear $display,\n\nAttached is your Main Program Attendance Certificate.\nCertificate No: ".($cert['cert_number']??'N/A')."\n\nThank you.";
  $mail->addAttachment($absPath, basename($cert['file_path']));
  $mail->send();
  $resp=['ok'=>true,'sent'=>true,'file_path'=>$cert['file_path'],'cert_number'=>$cert['cert_number'],'regenerated'=>$cert['regenerated']]; if($forceDebug){ unset($cfg['pass']); $resp['debug']=$dbg??''; $resp['config']=$cfg; }
  echo json_encode($resp);
}catch(Exception $e){
  $err=$mail->ErrorInfo ?: $e->getMessage(); $lc=strtolower($err); $class='unknown'; if(str_contains($lc,'authenticate')) $class='auth_failed'; elseif(str_contains($lc,'connect')) $class='connect_failed'; elseif(str_contains($lc,'invalid address')) $class='invalid_address';
  http_response_code(500); $resp=['ok'=>false,'error'=>'Failed to send email','smtp_error'=>$err,'classification'=>$class,'certificate'=>$cert]; if($forceDebug){ unset($cfg['pass']); $resp['config']=$cfg; }
  echo json_encode($resp);
}
?>
