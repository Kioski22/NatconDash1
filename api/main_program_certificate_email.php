<?php
// Send the user's Overall Main Program certificate via email (generate or reuse).
// POST (optionally with JSON {regenerate:1, send_only:1}) or GET (?regenerate=1)
// Returns JSON { ok, sent, file_path, cert_number, version, regenerated }

header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer; use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id']<=0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Not authenticated']); exit; }
$user_id = (int)$_SESSION['user_id'];
$db = isset($conn) && $conn instanceof mysqli ? $conn : (isset($mysqli)&&$mysqli instanceof mysqli ? $mysqli : null);
if(!$db){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB unavailable']); exit; }

// Helper to call generation endpoint logic internally (avoid HTTP round-trip)
function obtain_certificate(mysqli $db, int $user_id, bool $forceRegen=false): array {
    // We replicate minimal logic by including the generation script in-process would cause double headers; instead we re-run required portions.
    // We'll call the generation endpoint via internal include capturing output, passing regenerate flag through POST body simulation.
    $tmpInput = ['regenerate' => $forceRegen ? 1 : 0];
    $json = json_encode($tmpInput);
    // Use a temporary stream wrapper technique: set a global var consumed by generation file reading php://input
    // Simpler approach: directly query the DB for existing row then if needed include generation file after setting a global override for raw body.
    // To keep it simple & robust, just perform an HTTP-like fetch using local file include after defining a shim for file_get_contents('php://input').
    // But overriding built-in functions is complex; instead, we will duplicate minimal logic: check completion, load template, reuse code path if already implemented.

    // Re-run the aggregate logic from main_program_certificate.php (duplicated for isolation)
    $speakers=[]; $hasQQ=false; 
    $res = $db->query("SELECT s.id,s.name FROM speakers s JOIN function_rooms fr ON fr.id=s.room_id WHERE (s.program_type IS NULL OR LOWER(s.program_type)<>'sub')");
    if($res){ while($row=$res->fetch_assoc()) $speakers[]=['id'=>(int)$row['id'],'name'=>$row['name']]; $res->close(); }
    if(!$speakers) return ['ok'=>false,'error'=>'No speakers found'];
    if($chk=$db->query("SHOW TABLES LIKE 'quiz_questions'")){ $hasQQ=$chk->num_rows>0; $chk->close(); }
    $total=count($speakers); $evalCompleted=0; $quizRequired=0; $quizCompleted=0; $missing=[];
    foreach($speakers as $sp){ $sid=$sp['id']; $sname=$sp['name']; $evalDone=false; $quizDone=false; $requiresQuiz=false; $quizId=0;
        if($q=$db->prepare('SELECT id FROM quizzes WHERE speaker_id=? LIMIT 1')){ $q->bind_param('i',$sid); $q->execute(); $q->bind_result($quizId); if($q->fetch()) $requiresQuiz=true; $q->close(); }
        if(!$requiresQuiz && $hasQQ){ if($q2=$db->prepare('SELECT 1 FROM quiz_questions WHERE speaker_id=? LIMIT 1')){ $q2->bind_param('i',$sid); $q2->execute(); $q2->bind_result($d); if($q2->fetch()) $requiresQuiz=true; $q2->close(); }}
        if($requiresQuiz) $quizRequired++;
        if($requiresQuiz){ if($quizId>0){ if($qr=$db->prepare('SELECT 1 FROM quiz_responses WHERE quiz_id=? AND user_id=? LIMIT 1')){ $qr->bind_param('ii',$quizId,$user_id); $qr->execute(); $qr->bind_result($d); if($qr->fetch()) $quizDone=true; $qr->close(); }} if(!$quizDone){ if($qr2=$db->prepare('SELECT 1 FROM quiz_responses WHERE speaker_id=? AND user_id=? LIMIT 1')){ $qr2->bind_param('ii',$sid,$user_id); $qr2->execute(); $qr2->bind_result($d); if($qr2->fetch()) $quizDone=true; $qr2->close(); }} }
        if($quizDone) $quizCompleted++;
  if($ev=$db->prepare('SELECT 1 FROM speaker_evaluations WHERE speaker_id=? AND user_id=? LIMIT 1')){ $ev->bind_param('ii',$sid,$user_id); $ev->execute(); $ev->bind_result($d); if($ev->fetch()) $evalDone=true; $ev->close(); }
  if(!$evalDone && $ev2=$db->prepare('SELECT 1 FROM speaker_eval_responses WHERE speaker_id=? AND user_id=? LIMIT 1')){ $ev2->bind_param('ii',$sid,$user_id); $ev2->execute(); $ev2->bind_result($dd); if($ev2->fetch()) $evalDone=true; $ev2->close(); }
        if($evalDone) $evalCompleted++;
        if(!$evalDone || ($requiresQuiz && !$quizDone)) { $missing[]=['speaker_id'=>$sid,'speaker_name'=>$sname]; }
    }
    $complete = ($evalCompleted===$total && $quizCompleted===$quizRequired);
    if(!$complete) return ['ok'=>false,'error'=>'Main Program incomplete'];

    // Fetch existing row
    $existing=null; if($st=$db->prepare('SELECT file_path,cert_number,template_id,template_updated_at,version FROM main_program_certificates WHERE user_id=? LIMIT 1')){ $st->bind_param('i',$user_id); $st->execute(); $existing=$st->get_result()->fetch_assoc(); $st->close(); }

    // Load current template meta for freshness decision
    $tpl=null; if($st=$db->prepare("SELECT id,updated_at FROM certificates WHERE LOWER(type)='main_program_overall' ORDER BY updated_at DESC,id DESC LIMIT 1")){ $st->execute(); $tpl=$st->get_result()->fetch_assoc(); $st->close(); }
    if(!$tpl) return ['ok'=>false,'error'=>'Template not set'];
    $tplId=(int)$tpl['id']; $tplUpdated=$tpl['updated_at']; $tplUpdatedTs=$tplUpdated?strtotime($tplUpdated):0;
    $regenNeeded = false;
    if($existing){
        $exTplId=(int)($existing['template_id']??0); $exTplUpdatedTs = isset($existing['template_updated_at']) && $existing['template_updated_at']? strtotime($existing['template_updated_at']):0;
        $regenNeeded = $forceRegen || ($tplId && $tplId!==$exTplId) || ($tplUpdatedTs>$exTplUpdatedTs);
        if(!$regenNeeded){
            return ['ok'=>true,'file_path'=>$existing['file_path'],'cert_number'=>$existing['cert_number'],'version'=>(int)$existing['version'],'regenerated'=>false];
        }
    }
    // If regeneration or none exists, call generation endpoint properly to avoid duplicating full PDF logic.
    // We'll perform an internal HTTP-like call using cURL to self (works if server accessible) else fallback to include.
    // Simpler: perform a POST fetch using file_get_contents with stream context to same script. Determine base URL relative path.
    $self = __DIR__ . '/main_program_certificate.php';
    // Provide regenerate flag by setting $_GET for the include
    $_GET['regenerate'] = '1';
    // Buffer output
    ob_start();
    include $self; // script will echo JSON and exit. We cannot continue after include if it exits.
    // If script exited we will never reach here. If not, capture.
    $raw = ob_get_clean();
    $parsed = json_decode($raw,true);
    if(!is_array($parsed)) return ['ok'=>false,'error'=>'Generation call failed'];
    return $parsed;
}

$forceRegen = false; $sendOnly=false; $rawBody=file_get_contents('php://input'); if($rawBody){ $in=json_decode($rawBody,true); if(is_array($in)){ if(!empty($in['regenerate'])) $forceRegen=true; if(!empty($in['send_only'])) $sendOnly=true; }}
if(isset($_GET['regenerate']) && $_GET['regenerate']=='1') $forceRegen=true;

$getSetting = function(mysqli $db, string $key, $default=null){ if(!$st=$db->prepare('SELECT setting_value FROM admin_settings WHERE setting_key=? LIMIT 1')) return $default; $st->bind_param('s',$key); $st->execute(); $st->bind_result($val); $out=$default; if($st->fetch()){ $out=$val; } $st->close(); return $out; };
$certEnabled = ((int)($getSetting($db,'cert_generation_enabled','0') ?? '0') === 1);
$autoEnableStr = (string)($getSetting($db,'cert_auto_enable_date','') ?? ''); if(strpos($autoEnableStr,'T')!==false){ $autoEnableStr=str_replace('T',' ',$autoEnableStr); }
$autoEnableTs = $autoEnableStr!=='' ? @strtotime($autoEnableStr) : null; $canGenerateNow = $certEnabled || ($autoEnableTs !== null && time() >= $autoEnableTs);
if(!$canGenerateNow){ http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Certificate generation is not yet enabled','activation_at'=>$autoEnableStr]); exit; }

$cert = obtain_certificate($db,$user_id,$forceRegen);
if(!$cert || empty($cert['ok'])){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>$cert['error']??'Unable to obtain certificate']); exit; }

// If send_only flag was passed, don't resend email each time unless forced
// Proceed to email
$email=''; $name=''; if($st=$db->prepare('SELECT email,name FROM users WHERE id=? LIMIT 1')){ $st->bind_param('i',$user_id); $st->execute(); $st->bind_result($email,$name); $st->fetch(); $st->close(); }
if(!$email){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'User email not found','certificate'=>$cert]); exit; }

$file_path = $cert['file_path'] ?? null; if(!$file_path || !file_exists(__DIR__.'/../'.$file_path)){ http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Certificate file missing on disk','certificate'=>$cert]); exit; }
$abs = realpath(__DIR__.'/../'.$file_path);

// Mail configuration helper
$get = fn($p,$f='') => getenv($p) ?: ($f?getenv($f):'');
$cfg = [
  'host'=>$get('MAIL_HOST','SMTP_HOST'),
  'user'=>$get('MAIL_USERNAME','SMTP_USER'),
  'pass'=>$get('MAIL_PASSWORD','SMTP_PASS'),
  'port'=>(int)($get('MAIL_PORT','SMTP_PORT') ?: 587),
  'from_addr'=>$get('MAIL_FROM_ADDRESS','SMTP_FROM') ?: 'noreply@example.com',
  'from_name'=>$get('MAIL_FROM_NAME','SMTP_FROM_NAME') ?: 'Natcon Dashboard',
  'enc'=>strtolower($get('MAIL_ENCRYPTION','SMTP_SECURE') ?: 'tls'),
  'debug'=>(int)(getenv('MAIL_DEBUG') ?: 0),
  'allow_alias'=>(int)(getenv('MAIL_ALLOW_ALIAS') ?: getenv('SMTP_ALLOW_ALIAS') ?: 0),
];

if($cfg['host']===''||$cfg['user']===''||$cfg['pass']===''){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Mail server not configured (host/user/pass missing)','certificate'=>$cert]);
  exit;
}

$appEnv = strtolower(getenv('APP_ENV') ?: ''); $forceDebug = ($cfg['debug']>0)||$appEnv==='local'||isset($_GET['diag']);

$mail = new PHPMailer(true);
try{
  $mail->isSMTP(); $mail->Host=$cfg['host']; $mail->SMTPAuth=true; $mail->Username=$cfg['user']; $mail->Password=$cfg['pass'];
  if(in_array($cfg['enc'],['tls','starttls'],true)) $mail->SMTPSecure=PHPMailer::ENCRYPTION_STARTTLS; elseif(in_array($cfg['enc'],['ssl','smtps'],true)){ $mail->SMTPSecure=PHPMailer::ENCRYPTION_SMTPS; if($cfg['port']===587) $cfg['port']=465; } else $mail->SMTPSecure=false;
  $mail->Port=$cfg['port'];
  if($forceDebug){ $mail->SMTPDebug=2; $dbg=''; $mail->Debugoutput=function($str,$lvl) use (&$dbg){ $dbg.="[L$lvl] $str\n"; }; }
  $authDom=strtolower(substr(strrchr($cfg['user'],'@')?:'',1)); $fromDom=strtolower(substr(strrchr($cfg['from_addr'],'@')?:'',1));
  if($cfg['host']==='smtp.gmail.com' && $authDom && $fromDom && $authDom!==$fromDom && !$cfg['allow_alias']){ $mail->setFrom($cfg['user'],$cfg['from_name'].' (via)'); $mail->addReplyTo($cfg['from_addr'],$cfg['from_name']); } else { $mail->setFrom($cfg['from_addr'],$cfg['from_name']); }
  $display = trim($name) ?: $email; $mail->addAddress($email,$display);
  $mail->Subject='Your Overall Main Program Certificate';
  $mail->Body = "Dear $display,\n\nAttached is your Overall Main Program Certificate.\nCertificate No: ".($cert['cert_number']??'N/A')."\n\nThank you.";
  $mail->addAttachment($abs, basename($file_path));
  $mail->send();
  $resp=['ok'=>true,'sent'=>true,'file_path'=>$file_path,'cert_number'=>$cert['cert_number']??null,'version'=>$cert['version']??null,'regenerated'=>$cert['regenerated']??false];
  if($forceDebug){ unset($cfg['pass']); $resp['debug']=$dbg??''; $resp['config']=$cfg; }
  echo json_encode($resp);
} catch(Exception $e){
  $err=$mail->ErrorInfo ?: $e->getMessage(); $lc=strtolower($err); $class='unknown';
  if(str_contains($lc,'authenticate')) $class='auth_failed'; elseif(str_contains($lc,'connect')) $class='connect_failed'; elseif(str_contains($lc,'invalid address')) $class='invalid_address';
  http_response_code(500); $resp=['ok'=>false,'error'=>'Failed to send email','smtp_error'=>$err,'classification'=>$class,'certificate'=>$cert]; if($forceDebug){ unset($cfg['pass']); $resp['config']=$cfg; }
  echo json_encode($resp);
}
