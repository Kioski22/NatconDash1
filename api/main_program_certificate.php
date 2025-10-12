<?php
// Generate or fetch overall Main Program completion certificate (all days, non-sub speakers).
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vendor/autoload.php';
use Dompdf\Dompdf;
if (isset($conn) && $conn instanceof mysqli) $db = $conn; elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli; else { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB unavailable']); exit; }
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0; if ($user_id<=0){ http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Login required']); exit; }

// Table for issued overall main program certificates (single active row per user; regenerations overwrite)
@$db->query("CREATE TABLE IF NOT EXISTS main_program_certificates (\n  id INT AUTO_INCREMENT PRIMARY KEY,\n  user_id INT NOT NULL UNIQUE,\n  file_path VARCHAR(255) NOT NULL,\n  cert_number VARCHAR(120) DEFAULT NULL,\n  template_id INT DEFAULT NULL,\n  template_updated_at TIMESTAMP NULL DEFAULT NULL,\n  version INT NOT NULL DEFAULT 1,\n  generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n  KEY idx_user (user_id),\n  KEY idx_template (template_id)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
// Silent schema migrations for new columns (idempotent)
$colRes = $db->query("SHOW COLUMNS FROM main_program_certificates"); $haveCols=[]; if($colRes){ while($r=$colRes->fetch_assoc()){ $haveCols[$r['Field']]=true; } $colRes->close(); }
if(!isset($haveCols['template_id']))            { @$db->query("ALTER TABLE main_program_certificates ADD COLUMN template_id INT NULL AFTER cert_number"); }
if(!isset($haveCols['template_updated_at']))    { @$db->query("ALTER TABLE main_program_certificates ADD COLUMN template_updated_at TIMESTAMP NULL DEFAULT NULL AFTER template_id"); }
if(!isset($haveCols['version']))                { @$db->query("ALTER TABLE main_program_certificates ADD COLUMN version INT NOT NULL DEFAULT 1 AFTER template_updated_at"); }

// Parse optional regenerate flag (JSON body or query string)
$forceRegen = false;
if($_SERVER['REQUEST_METHOD']==='POST'){
  $rawIn = file_get_contents('php://input');
  if($rawIn){ $jsonIn = json_decode($rawIn,true); if(is_array($jsonIn) && !empty($jsonIn['regenerate'])) $forceRegen=true; }
}
if(isset($_GET['regenerate']) && $_GET['regenerate']=='1') $forceRegen=true;

// Compute completion using existing aggregation endpoint logic (inline to avoid extra HTTP)
function aggregate(mysqli $db, int $user_id): array {
  $speakers=[]; $hasQuizQuestions=false; $user_email='';
  if(isset($_SESSION['user_email'])) $user_email=trim((string)$_SESSION['user_email']);
  $res = $db->query("SELECT s.id,s.name,s.title, fr.day_id, d.day_date FROM speakers s JOIN function_rooms fr ON fr.id=s.room_id LEFT JOIN function_room_days d ON d.id=fr.day_id WHERE (s.program_type IS NULL OR LOWER(s.program_type)<> 'sub') ORDER BY d.day_date ASC, s.id ASC");
  if($res){ while($row=$res->fetch_assoc()) $speakers[]=[ 'id'=>(int)$row['id'], 'name'=>$row['name'], 'title'=>$row['title'] ?? '', 'day_date'=>$row['day_date']]; $res->close(); }
  if(!$speakers) return ['total'=>0,'eval_completed'=>0,'quiz_required'=>0,'quiz_completed'=>0,'complete'=>true,'complete_relaxed'=>true,'missing'=>[], 'titles'=>[], 'completed_titles'=>[]];
  if($chk=$db->query("SHOW TABLES LIKE 'quiz_questions'")){ $hasQuizQuestions=$chk->num_rows>0; $chk->close(); }
  // Passing threshold (percentage) from admin settings
  $passingPercent=0; if($chk=$db->query("SHOW TABLES LIKE 'admin_settings'")){ if($chk->num_rows>0){
      // Prefer Main Program-specific threshold, fallback to legacy/global
      $val=null; if($st=$db->prepare("SELECT setting_value FROM admin_settings WHERE setting_key='main_cert_min_score' LIMIT 1")){ $st->execute(); $st->bind_result($val); $st->fetch(); $st->close(); }
      if($val===null || $val===''){
        if($st2=$db->prepare("SELECT setting_value FROM admin_settings WHERE setting_key='cert_min_score' LIMIT 1")){ $st2->execute(); $st2->bind_result($val); $st2->fetch(); $st2->close(); }
      }
      if($val!==null && $val!=='') $passingPercent=(int)$val;
    } $chk->close(); }
  $total=count($speakers); $evalCompleted=0; $quizRequired=0; $quizCompleted=0; $missing=[]; $incompleteFutureOnly=true; $today=date('Y-m-d');
  $titles=[]; // all main program speaker titles (fallback to name)
  $completedTitles=[]; // only those the user fully completed
  foreach($speakers as $sp){ $sid=$sp['id']; $sname=$sp['name']; $dayDate=$sp['day_date']; $sTitle=trim((string)$sp['title']); $displayTitle = $sTitle!=='' ? $sTitle : $sname; $titles[]=$displayTitle;
    $evalDone=false; $quizDone=false; $requiresQuiz=false; $quizId=0; $quizPassed=false;
    if($q=$db->prepare('SELECT id FROM quizzes WHERE speaker_id=? LIMIT 1')){ $q->bind_param('i',$sid); $q->execute(); $q->bind_result($quizId); if($q->fetch()) $requiresQuiz=true; $q->close(); }
    if(!$requiresQuiz && $hasQuizQuestions){ if($q2=$db->prepare('SELECT 1 FROM quiz_questions WHERE speaker_id=? LIMIT 1')){ $q2->bind_param('i',$sid); $q2->execute(); $q2->bind_result($d); if($q2->fetch()) $requiresQuiz=true; $q2->close(); } }
    if($requiresQuiz) $quizRequired++;
    if($requiresQuiz){
      // Check response and evaluate PASS based on threshold
      $userHasResponse=false; $userScore=null;
      if($quizId>0){ if($qr=$db->prepare('SELECT score FROM quiz_responses WHERE quiz_id=? AND user_id=? LIMIT 1')){ $qr->bind_param('ii',$quizId,$user_id); $qr->execute(); $qr->bind_result($sc); if($qr->fetch()){ $userHasResponse=true; $userScore=(int)$sc; } $qr->close(); }}
      if(!$userHasResponse){ if($qr2=$db->prepare('SELECT score FROM quiz_responses WHERE speaker_id=? AND user_id=? LIMIT 1')){ $qr2->bind_param('ii',$sid,$user_id); $qr2->execute(); $qr2->bind_result($sc); if($qr2->fetch()){ $userHasResponse=true; $userScore=(int)$sc; } $qr2->close(); }}
      if($userHasResponse){
        $quizDone=true;
        // Determine total questions
        $totalGradable=0; $ansJson=null; $qJson=null;
        if($quizId>0){ if($qq=$db->prepare('SELECT answers,questions FROM quizzes WHERE id=? LIMIT 1')){ $qq->bind_param('i',$quizId); $qq->execute(); $qq->bind_result($ansJson,$qJson); if($qq->fetch()){ /*noop*/ } $qq->close(); } }
        if($ansJson){ $arr=json_decode((string)$ansJson,true); if(is_array($arr)) $totalGradable=count($arr); }
        if($totalGradable<=0 && $qJson){ $arr=json_decode((string)$qJson,true); if(is_array($arr)) $totalGradable=count($arr); }
        if($totalGradable>0){ $pct = ($userScore*100.0)/$totalGradable; $quizPassed = ((int)round($pct) >= (int)$passingPercent); }
      }
      if($quizPassed) $quizCompleted++;
    }
  if($ev=$db->prepare('SELECT 1 FROM speaker_evaluations WHERE speaker_id=? AND user_id=? LIMIT 1')){ $ev->bind_param('ii',$sid,$user_id); $ev->execute(); $ev->bind_result($d); if($ev->fetch()) $evalDone=true; $ev->close(); }
  if(!$evalDone && $ev2=$db->prepare('SELECT 1 FROM speaker_eval_responses WHERE speaker_id=? AND user_id=? LIMIT 1')){ $ev2->bind_param('ii',$sid,$user_id); $ev2->execute(); $ev2->bind_result($dd); if($ev2->fetch()) $evalDone=true; $ev2->close(); }
    if($evalDone) $evalCompleted++;
    $isCompleteForSpeaker = $evalDone && (!$requiresQuiz || $quizPassed);
    if($isCompleteForSpeaker){ $completedTitles[]=$displayTitle; }
    if(!$isCompleteForSpeaker){
      if(!$dayDate || $dayDate <= $today){ $incompleteFutureOnly=false; }
      $missing[]=['speaker_id'=>$sid,'speaker_name'=>$sname,'needs_evaluation'=>!$evalDone,'needs_quiz'=>$requiresQuiz && !$quizPassed,'day_date'=>$dayDate];
    }
  }
  // Deduplicate titles while preserving order
  $seen=[]; $titlesOrdered=[]; foreach($titles as $t){ if($t==='') continue; $k=strtolower($t); if(isset($seen[$k])) continue; $seen[$k]=1; $titlesOrdered[]=$t; }
  $seen2=[]; $completedOrdered=[]; foreach($completedTitles as $t){ if($t==='') continue; $k=strtolower($t); if(isset($seen2[$k])) continue; $seen2[$k]=1; $completedOrdered[]=$t; }
  $complete = ($evalCompleted===$total && $quizCompleted===$quizRequired);
  $complete_relaxed = $complete || $incompleteFutureOnly;
  return ['total'=>$total,'eval_completed'=>$evalCompleted,'quiz_required'=>$quizRequired,'quiz_completed'=>$quizCompleted,'complete'=>$complete,'complete_relaxed'=>$complete_relaxed,'missing'=>$missing,'titles'=>$titlesOrdered,'completed_titles'=>$completedOrdered];
}

$progress = aggregate($db,$user_id);
if(!$progress['complete_relaxed']){ http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Main Program incomplete','progress'=>$progress,'missing'=>$progress['missing']]); exit; }

// Load template first (to compare update time)
$tpl=null; if($st=$db->prepare("SELECT * FROM certificates WHERE LOWER(type)='main_program_overall' ORDER BY updated_at DESC,id DESC LIMIT 1")){ $st->execute(); $r=$st->get_result(); $tpl=$r->fetch_assoc(); $st->close(); }
if(!$tpl){ http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Overall main program certificate template not set']); exit; }
$tplId = (int)($tpl['id'] ?? 0); $tplUpdated = $tpl['updated_at'] ?? null; $tplUpdatedTs = $tplUpdated ? strtotime($tplUpdated) : 0;

// Check existing issuance
$existing=null; if($st=$db->prepare('SELECT file_path, cert_number, template_id, template_updated_at, version FROM main_program_certificates WHERE user_id=? LIMIT 1')){ $st->bind_param('i',$user_id); $st->execute(); $res=$st->get_result(); $existing=$res->fetch_assoc(); $st->close(); }
if($existing){
  $exTplId = (int)($existing['template_id'] ?? 0);
  $exTplUpdated = $existing['template_updated_at'] ?? null; $exTplUpdatedTs = $exTplUpdated ? strtotime($exTplUpdated) : 0;
  $needsRegen = $forceRegen || ($tplId && ($tplId !== $exTplId)) || ($tplUpdatedTs > $exTplUpdatedTs && $tplUpdatedTs>0);
  if(!$needsRegen){
    echo json_encode([
      'ok'=>true,
      'already'=>true,
      'file_path'=>$existing['file_path'],
      'cert_number'=>$existing['cert_number'],
      'version'=>(int)$existing['version'],
      'progress'=>$progress,
      'template_id'=>$tplId,
      'template_updated_at'=>$tplUpdated,
      'regenerated'=>false
    ]); exit;
  }
}
// If we reach here: generate new (first issuance or regeneration)

function esc($s){ return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function file_to_data_uri($rel){ $abs=__DIR__.'/../'.$rel; if(!is_file($abs)) return ''; $data=@file_get_contents($abs); if($data===false) return ''; $mime=str_ends_with(strtolower($rel),'.png')?'image/png':'image/jpeg'; return 'data:'.$mime.';base64,'.base64_encode($data); }

$bg_rel=$tpl['bg_url']; $bg_uri=$bg_rel?file_to_data_uri($bg_rel):''; $imgW=1600; $imgH=1131; if($bg_rel){ $absBg=__DIR__.'/../'.$bg_rel; if(is_file($absBg)){ $info=@getimagesize($absBg); if($info){ $imgW=(int)$info[0]; $imgH=(int)$info[1]; } }} if($imgW>2400){ $scale=2400/$imgW; $imgW=(int)round($imgW*$scale); $imgH=(int)round($imgH*$scale); }
$paperWpt=$imgW*72/96; $paperHpt=$imgH*72/96; $issuedAt=date('Y-m-d H:i');
// Derive certificate number using template cert_number_format if present
$rawFmt = $tpl['cert_number_format'] ?? 'MPO-{YYYY}-{SEQ4}-{USER_ID}';
// naive sequence (count table +1)
$seq=1; if($c=$db->prepare('SELECT COUNT(*) FROM main_program_certificates')){ $c->execute(); $c->bind_result($cnt); if($c->fetch()) $seq=(int)$cnt+1; $c->close(); }
$repl=[ '{YYYY}'=>date('Y'), '{YY}'=>date('y'), '{MM}'=>date('m'), '{DD}'=>date('d'), '{USER_ID}'=>$user_id ];
if(preg_match_all('/\{SEQ(\d+)\}/',$rawFmt,$m,PREG_SET_ORDER)){ foreach($m as $match){ $w=(int)$match[1]; $repl[$match[0]]=str_pad($seq,$w,'0',STR_PAD_LEFT); } }
$cert_number=strtr($rawFmt,$repl);
// Texts
$user_name='Participant'; if($st=$db->prepare('SELECT name FROM users WHERE id=? LIMIT 1')){ $st->bind_param('i',$user_id); $st->execute(); $st->bind_result($user_name); $st->fetch(); $st->close(); }
$awardeeTemplate = $tpl['cert_awardee_name'] ?: '{USER_NAME}';
$awardee= str_replace(['{USER_NAME}','{NAME}','{FULL_NAME}'],$user_name,$awardeeTemplate);
$awardee=str_replace(['{CERT_NUMBER}'],$cert_number,$awardee);
$seminarTitle = $tpl['seminar_title'] ?: 'Main Program Overall Completion';
$seminarTitle=str_replace(['{TOTAL_SESSIONS}','{TOTAL_SPEAKERS}'],$progress['total'],$seminarTitle);
$seminarTitle=str_replace(['{CERT_NUMBER}'],$cert_number,$seminarTitle);
// Compose speaker titles block
$speakerTitlesList = $progress['titles'] ?? [];
$speakerTitlesText = implode("; ", $speakerTitlesList);
$completedTitlesList = $progress['completed_titles'] ?? [];
$completedTitlesText = implode("; ", $completedTitlesList);
// Token replacement in dynamic fields (awardee/seminar) if template wants to embed
$awardee = str_replace('{SPEAKER_TITLES}', $speakerTitlesText, $awardee);
$seminarTitle = str_replace('{SPEAKER_TITLES}', $speakerTitlesText, $seminarTitle);
$awardee = str_replace('{COMPLETED_SPEAKER_TITLES}', $completedTitlesText, $awardee);
$seminarTitle = str_replace('{COMPLETED_SPEAKER_TITLES}', $completedTitlesText, $seminarTitle);
// Backwards compatibility: if template still uses legacy {SEMINAR_TITLE} token, map it to aggregated (or completed if incomplete) titles
if(strpos($seminarTitle,'{SEMINAR_TITLE}')!==false){
  // Build multi-line list: each title per line, comma after each except last ends with period.
  $listSource = $progress['complete'] ? ($speakerTitlesList ?: []) : ($completedTitlesList ?: []);
  $replacement = '';
  if($listSource){
    $lastIdx = count($listSource)-1;
    foreach($listSource as $i=>$t){
      $line = $t;
      if($i < $lastIdx) $line .= ','; else $line .= '.';
      $replacement .= $line . "\n";
    }
    $replacement = rtrim($replacement); // remove trailing newline
  } else {
    $replacement = $progress['complete'] ? 'Main Program Overall Completion' : 'Main Program Overall Progress';
  }
  $seminarTitle = str_replace('{SEMINAR_TITLE}', $replacement, $seminarTitle);
}
$show_awardee=(int)$tpl['show_awardee']===1; $show_seminar=(int)$tpl['show_seminar']===1; $show_number=(int)$tpl['show_number']===1;
$awardee_left=(float)$tpl['pos_cert_awardee_name_x']; $awardee_top=(float)$tpl['pos_cert_awardee_name_y']; $seminar_left=(float)$tpl['pos_seminar_title_x']; $seminar_top=(float)$tpl['pos_seminar_title_y']; $num_left=(float)$tpl['pos_cert_number_x']; $num_top=(float)$tpl['pos_cert_number_y'];
// Fonts (fall back if columns missing)
$faFam=$tpl['font_awardee_family']??'Helvetica'; $faSize=(int)($tpl['font_awardee_size_px']??46); if($faSize<6)$faSize=6; if($faSize>200)$faSize=200; $faColor=$tpl['font_awardee_color']??'#000000';
$fsFam=$tpl['font_seminar_family']??'Helvetica'; $fsSize=(int)($tpl['font_seminar_size_px']??24); if($fsSize<6)$fsSize=6; if($fsSize>200)$fsSize=200; $fsColor=$tpl['font_seminar_color']??'#000000';
$fnFam=$tpl['font_number_family']??'Helvetica'; $fnSize=(int)($tpl['font_number_size_px']??16); if($fnSize<6)$fnSize=6; if($fnSize>200)$fnSize=200; $fnColor=$tpl['font_number_color']??'#000000';
// Signatures (reuse certificate_signatures if attached to this template id?)
$sigHtml=''; if(isset($tpl['id'])){ $cid=(int)$tpl['id']; if($sig=$db->prepare('SELECT signature_url,pos_x,pos_y,width_pct FROM certificate_signatures WHERE certificate_id=? AND enabled=1')){ $sig->bind_param('i',$cid); $sig->execute(); $rs=$sig->get_result(); while($row=$rs->fetch_assoc()){ $sUri=file_to_data_uri($row['signature_url']); if($sUri){ $sx=(float)$row['pos_x']; $sy=(float)$row['pos_y']; $sw=(float)$row['width_pct']; $sigHtml.='<img src="'.esc($sUri).'" style="position:absolute;left:'.$sx.'%;top:'.$sy.'%;width:'.$sw.'%;height:auto;" />'; } } $sig->close(); } }
$html='<!DOCTYPE html><html><head><meta charset="utf-8"><style>@page{margin:0;}html,body{margin:0;padding:0;} .page{position:relative;width:'.$imgW.'px;height:'.$imgH.'px;} .bg{position:absolute;left:0;top:0;width:100%;height:100%;} .txt{position:absolute;white-space:nowrap;} .titles-block{position:absolute;left:5%;right:5%;top:85%;font-family:'.esc($fsFam).';font-size:12px;line-height:1.25;color:#000;}</style></head><body><div class="page">'; if($bg_uri){ $html.='<img class="bg" src="'.esc($bg_uri).'" />'; }
if($show_awardee){ $html.='<div class="txt" style="left:'.$awardee_left.'%;top:'.$awardee_top.'%;font-family:'.esc($faFam).';font-size:'.$faSize.'px;color:'.esc($faColor).';font-weight:600;">'.esc($awardee).'</div>'; }
// Detect multi-line seminar content to allow wrapping
$seminarMultiline = strpos($seminarTitle,"\n") !== false;
if($show_seminar){ $html.='<div class="txt" style="left:'.$seminar_left.'%;top:'.$seminar_top.'%;font-family:'.esc($fsFam).';font-size:'.$fsSize.'px;color:'.esc($fsColor).';'.($seminarMultiline?'white-space:pre-line;':'').'">'.esc($seminarTitle).'</div>'; }
if($show_number){ $html.='<div class="txt" style="left:'.$num_left.'%;top:'.$num_top.'%;font-family:'.esc($fnFam).';font-size:'.$fnSize.'px;color:'.esc($fnColor).';">'.esc($cert_number).'</div>'; }
$html.=$sigHtml;
// If template did not already place {SPEAKER_TITLES}, optionally append block at bottom (simple heuristic)
if(
  strpos($tpl['seminar_title'] ?? '', '{SPEAKER_TITLES}')===false &&
  strpos($tpl['cert_awardee_name'] ?? '', '{SPEAKER_TITLES}')===false &&
  strpos($tpl['seminar_title'] ?? '', '{COMPLETED_SPEAKER_TITLES}')===false &&
  strpos($tpl['cert_awardee_name'] ?? '', '{COMPLETED_SPEAKER_TITLES}')===false &&
  // Skip auto block if legacy {SEMINAR_TITLE} token was present (we already expanded it) to avoid duplicates
  strpos($tpl['seminar_title'] ?? '', '{SEMINAR_TITLE}')===false
){
  $blockText = $progress['complete'] ? $speakerTitlesText : $completedTitlesText;
  if(!empty($blockText)){
    $html.='<div class="titles-block">'.esc($blockText).'</div>';
  }
}
$html.='<div style="position:absolute;right:16px;bottom:12px;font-size:10px;color:#333;">Issued '.$issuedAt.'</div></div></body></html>';
$dompdf=new Dompdf(['defaultFont'=>'Helvetica']); $dompdf->setPaper([0,0,$paperWpt,$paperHpt]); $dompdf->loadHtml($html,'UTF-8'); $dompdf->render();
$outDir=__DIR__.'/../uploads/certificates/generated'; if(!is_dir($outDir)) @mkdir($outDir,0775,true); $fileName='main_overall_u'.$user_id.'_'.substr(sha1($cert_number.random_bytes(4)),0,8).'.pdf'; $abs=$outDir.'/'.$fileName; file_put_contents($abs,$dompdf->output()); $rel='uploads/certificates/generated/'.$fileName;

// Upsert (insert or update existing) with version increment on regeneration
if($existing){
  $newVersion = (int)$existing['version'] + 1;
  if($up=$db->prepare('UPDATE main_program_certificates SET file_path=?, cert_number=?, template_id=?, template_updated_at=?, version=?, generated_at=NOW() WHERE user_id=? LIMIT 1')){
    $tplUpdatedStr = $tplUpdated ?: date('Y-m-d H:i:s');
    $up->bind_param('ssissi',$rel,$cert_number,$tplId,$tplUpdatedStr,$newVersion,$user_id);
    if(!$up->execute()){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Failed to update certificate']); exit; }
    $up->close();
    echo json_encode(['ok'=>true,'file_path'=>$rel,'already'=>false,'cert_number'=>$cert_number,'version'=>$newVersion,'progress'=>$progress,'regenerated'=>true,'template_id'=>$tplId,'template_updated_at'=>$tplUpdated]);
    exit;
  } else { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Prepare failed updating']); exit; }
} else {
  if($ins=$db->prepare('INSERT INTO main_program_certificates (user_id,file_path,cert_number,template_id,template_updated_at,version) VALUES (?,?,?,?,?,1)')){ $tplUpdatedStr=$tplUpdated ?: date('Y-m-d H:i:s'); $ins->bind_param('issis',$user_id,$rel,$cert_number,$tplId,$tplUpdatedStr); if(!$ins->execute()){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Failed to record certificate']); exit; } $ins->close(); }
  echo json_encode(['ok'=>true,'file_path'=>$rel,'already'=>false,'cert_number'=>$cert_number,'version'=>1,'progress'=>$progress,'regenerated'=>false,'template_id'=>$tplId,'template_updated_at'=>$tplUpdated]);
}
