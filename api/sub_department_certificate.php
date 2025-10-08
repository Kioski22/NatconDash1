<?php
// Generate (or fetch existing) Sub Program Technical Department completion certificate.
// A user earns this after completing (evaluating) ALL sub-program speakers within a technical department.
// Modes:
//   GET  ?department_id=<id>&mode=status             -> progress + existing certificate if any
//   POST JSON { department_id: <id> }                -> generate if criteria met
//   (Backward convenience) GET ?speaker_id=<id>&mode=status -> infer department from speaker
//   POST JSON { speaker_id: <id> }                   -> infer department then attempt generation
// Response JSON fields on status:
//   { ok:true, progress:{completed:<int>, total:<int>}, complete:<bool>, certificate:{file_path,generated_at}|null }
// On incomplete generation attempt -> 403 + progress info

header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vendor/autoload.php';
use Dompdf\Dompdf;

if (isset($conn) && $conn instanceof mysqli)      $db = $conn;
elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli;
else { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB unavailable']); exit; }

$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($user_id <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Login required']); exit; }

// Auto-migrate storage table (generated certificates)
@$db->query("CREATE TABLE IF NOT EXISTS sub_department_certificates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  technical_department_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_dept (user_id, technical_department_id),
  KEY idx_dept (technical_department_id),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Helper: fetch speaker (with dept)
function fetch_speaker_with_dept(mysqli $db, int $speaker_id): ?array {
    if ($st = $db->prepare("SELECT id,name,title,program_type,technical_department_id FROM speakers WHERE id=? LIMIT 1")) {
        $st->bind_param('i',$speaker_id); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); return $r ?: null;
    }
    return null;
}

// Helper: fetch department name
function fetch_department(mysqli $db, int $dept_id): ?array {
    if ($dept_id <= 0) return null;
    if ($st = $db->prepare("SELECT id, name FROM technical_departments WHERE id=? LIMIT 1")) {
        $st->bind_param('i',$dept_id); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); return $r ?: null;
    }
    return null;
}

// Compute progress (evaluations + quiz submissions) and return granular data.
function compute_progress(mysqli $db, int $dept_id, int $user_id): array {
    $totalSpeakers = 0;
    $evalCompleted = 0; $evalRemaining = [];
    $quizTotal = 0; $quizCompleted = 0; $quizRemaining = [];
    // Detect existence of quiz_questions table (legacy fallback)
    $hasQuizQuestions = false;
    if ($res = $db->query("SHOW TABLES LIKE 'quiz_questions'")) { $hasQuizQuestions = $res->num_rows > 0; $res->close(); }

    if ($st = $db->prepare("SELECT id FROM speakers WHERE program_type='sub' AND technical_department_id=?")) {
        $st->bind_param('i',$dept_id); $st->execute(); $res=$st->get_result();
        while($row=$res->fetch_assoc()) {
            $spId = (int)$row['id'];
            $totalSpeakers++;
            // Evaluation status
            $evalDone = false;
            if ($chk = $db->prepare("SELECT 1 FROM speaker_evaluations WHERE speaker_id=? AND user_id=? LIMIT 1")) { $chk->bind_param('ii',$spId,$user_id); $chk->execute(); $chk->bind_result($d); if($chk->fetch()) $evalDone=true; $chk->close(); }
            if (!$evalDone && $chk = $db->prepare("SELECT 1 FROM speaker_eval_responses WHERE speaker_id=? AND user_id=? LIMIT 1")) { $chk->bind_param('ii',$spId,$user_id); $chk->execute(); $chk->bind_result($dd); if($chk->fetch()) $evalDone=true; $chk->close(); }
            if ($evalDone) $evalCompleted++; else $evalRemaining[] = $spId;

            // Quiz requirement detection
            $hasQuiz = false; $userQuizDone = false; $quizId = 0;
            if ($qst = $db->prepare("SELECT id FROM quizzes WHERE speaker_id=? LIMIT 1")) {
                $qst->bind_param('i',$spId); $qst->execute(); $qst->bind_result($quizId); if ($qst->fetch()) { $hasQuiz = true; } $qst->close();
            }
            if (!$hasQuiz && $hasQuizQuestions) { // legacy fallback
                if ($qq = $db->prepare("SELECT 1 FROM quiz_questions WHERE speaker_id=? LIMIT 1")) {
                    $qq->bind_param('i',$spId); $qq->execute(); $qq->bind_result($d); if ($qq->fetch()) $hasQuiz = true; $qq->close();
                }
            }
            if ($hasQuiz) {
                $quizTotal++;
                if ($quizId > 0) {
                    if ($qr = $db->prepare("SELECT 1 FROM quiz_responses WHERE quiz_id=? AND user_id=? LIMIT 1")) { $qr->bind_param('ii',$quizId,$user_id); $qr->execute(); $qr->bind_result($d); if($qr->fetch()) $userQuizDone=true; $qr->close(); }
                }
                if (!$userQuizDone) { // speaker_id keyed responses fallback
                    if ($qr2 = $db->prepare("SELECT 1 FROM quiz_responses WHERE speaker_id=? AND user_id=? LIMIT 1")) { $qr2->bind_param('ii',$spId,$user_id); $qr2->execute(); $qr2->bind_result($d); if($qr2->fetch()) $userQuizDone=true; $qr2->close(); }
                }
                if ($userQuizDone) $quizCompleted++; else $quizRemaining[] = $spId;
            }
        }
        $st->close();
    }
    // Completed condition: all evaluations and all required quizzes
    $complete = ($totalSpeakers>0 && $evalCompleted === $totalSpeakers && $quizCompleted === $quizTotal);
    return [
        'total'=>$totalSpeakers,
        'completed'=>$evalCompleted, // backward compatibility (evaluations)
        'remaining'=>$evalRemaining,
        'complete'=>$complete,
        'eval_total'=>$totalSpeakers,
        'eval_completed'=>$evalCompleted,
        'eval_remaining'=>$evalRemaining,
        'quiz_total'=>$quizTotal,
        'quiz_completed'=>$quizCompleted,
        'quiz_remaining'=>$quizRemaining
    ];
}

// Resolve department_id from request (dept or speaker)
function resolve_department_id(mysqli $db, array $input): array {
    $dept_id = isset($input['department_id']) ? (int)$input['department_id'] : 0;
    if (!$dept_id && !empty($input['speaker_id'])) {
        $sp = fetch_speaker_with_dept($db, (int)$input['speaker_id']);
        if ($sp) $dept_id = (int)($sp['technical_department_id'] ?? 0);
    }
    if ($dept_id <= 0) return [0,null,'Department not found'];
    $dept = fetch_department($db,$dept_id);
    if (!$dept) return [0,null,'Department not found'];
    return [$dept_id,$dept,null];
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $input = [
        'department_id' => isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0,
        'speaker_id' => isset($_GET['speaker_id']) ? (int)$_GET['speaker_id'] : 0,
    ];
    [$dept_id,$dept,$err] = resolve_department_id($db,$input);
    if ($err) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>$err]); exit; }
    $progress = compute_progress($db,$dept_id,$user_id);

    // Existing completion certificate check
    $existing = null;
    if ($st = $db->prepare('SELECT file_path, generated_at FROM sub_department_certificates WHERE user_id=? AND technical_department_id=? LIMIT 1')) {
        $st->bind_param('ii',$user_id,$dept_id); $st->execute(); $st->bind_result($fp,$gen); if ($st->fetch()) $existing=['file_path'=>$fp,'generated_at'=>$gen,'kind'=>'completion']; $st->close();
    }

    // Attendance table (auto-migrate)
    @ $db->query("CREATE TABLE IF NOT EXISTS sub_department_attendance_certificates (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      technical_department_id INT NOT NULL,
      file_path VARCHAR(255) NOT NULL,
      generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_user_dept_att (user_id, technical_department_id),
      KEY idx_dept_att (technical_department_id),
      KEY idx_user_att (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Existing attendance certificate
    $attExisting = null;
    if ($st = $db->prepare('SELECT file_path, generated_at FROM sub_department_attendance_certificates WHERE user_id=? AND technical_department_id=? LIMIT 1')) {
        $st->bind_param('ii',$user_id,$dept_id); $st->execute(); $st->bind_result($afp,$agen); if($st->fetch()) $attExisting=['file_path'=>$afp,'generated_at'=>$agen,'kind'=>'attendance']; $st->close();
    }

    // Attendance template presence (department specific first)
    $attTemplate = null;
    if ($st = $db->prepare("SELECT id FROM certificates WHERE type='sub_program_attendance' AND scope_binding='sub_program' AND (scope_identifier=? OR scope_identifier='' OR scope_identifier IS NULL) ORDER BY CASE WHEN scope_identifier=? THEN 0 ELSE 1 END LIMIT 1")) {
        $st->bind_param('ii',$dept_id,$dept_id); $st->execute(); $st->bind_result($tid); if($st->fetch()) $attTemplate=$tid; $st->close();
    }

        // Completion template presence (row in department_certificate_templates)
        $completionTemplateAssigned = false;
        if ($chk = $db->prepare('SELECT 1 FROM department_certificate_templates WHERE technical_department_id=? LIMIT 1')) {
                $chk->bind_param('i',$dept_id); $chk->execute(); $chk->bind_result($dummy); if($chk->fetch()) $completionTemplateAssigned=true; $chk->close();
        }

        echo json_encode([
                'ok'=>true,
                'department'=>['id'=>$dept_id,'name'=>$dept['name']],
                'progress'=>$progress,
                'complete'=>$progress['complete'],
                'certificate'=>$existing,
                'completion_template_assigned'=>$completionTemplateAssigned,
                'attendance'=>[
                    'template_assigned'=> $attTemplate ? true:false,
                    'certificate'=>$attExisting
                ]
        ]);
    exit;
}

// POST generate
$raw = file_get_contents('php://input');
$data = json_decode($raw,true) ?: [];
[$dept_id,$dept,$err] = resolve_department_id($db,$data);
if ($err) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>$err]); exit; }

$progress = compute_progress($db,$dept_id,$user_id);

// Attendance table ensure (used if completion not yet ready or explicitly requested)
@ $db->query("CREATE TABLE IF NOT EXISTS sub_department_attendance_certificates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  technical_department_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_dept_att (user_id, technical_department_id),
  KEY idx_dept_att (technical_department_id),
  KEY idx_user_att (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Helper to generate attendance certificate (generic certificates table template)
function generate_attendance_certificate(mysqli $db, int $user_id, int $dept_id): array {
    // Select best template for this department
    if (!$st = $db->prepare("SELECT * FROM certificates WHERE type='sub_program_attendance' AND scope_binding='sub_program' AND (scope_identifier=? OR scope_identifier='' OR scope_identifier IS NULL) ORDER BY CASE WHEN scope_identifier=? THEN 0 ELSE 1 END LIMIT 1")) {
        return ['error'=>'Template query failed'];
    }
    $st->bind_param('ii',$dept_id,$dept_id); $st->execute(); $res=$st->get_result(); $tpl=$res->fetch_assoc(); $st->close();
    if(!$tpl) return ['error'=>'No attendance template'];

    // Check existing
    if($chk=$db->prepare('SELECT file_path FROM sub_department_attendance_certificates WHERE user_id=? AND technical_department_id=? LIMIT 1')){
        $chk->bind_param('ii',$user_id,$dept_id); $chk->execute(); $chk->bind_result($efp); if($chk->fetch()){ $chk->close(); return ['already'=>true,'file_path'=>$efp]; } $chk->close();
    }

    // Load user
    $user_name='Participant';
    if($su=$db->prepare('SELECT name FROM users WHERE id=? LIMIT 1')){ $su->bind_param('i',$user_id); $su->execute(); $su->bind_result($user_name); $su->fetch(); $su->close(); }
    // Department name
    $deptName='Department'; if($sd=$db->prepare('SELECT name FROM technical_departments WHERE id=? LIMIT 1')){ $sd->bind_param('i',$dept_id); $sd->execute(); $sd->bind_result($deptName); $sd->fetch(); $sd->close(); }

    // Prepare tokens
    // Safe access with null coalesce to avoid notices contaminating JSON output
    $fmt = $tpl['cert_number_format'] ?? 'NC-{YYYY}-{SEQ4}';
    $seq4 = str_pad((string)random_int(0,9999),4,'0',STR_PAD_LEFT);
    $certNumber = str_replace(
        ['{YYYY}','{MM}','{DD}','{SEQ4}','{USER_ID}'],
        [date('Y'),date('m'),date('d'),$seq4,$user_id],
        $fmt
    );

    $awardeeRaw = $tpl['cert_awardee_name'] ?? '{USER_NAME}';
    $seminarRaw = $tpl['seminar_title'] ?? '{DEPARTMENT_NAME}';
    $awardeeText = str_replace(['{USER_NAME}','{NAME}','{FULL_NAME}'],$user_name,$awardeeRaw);
    $seminarText = str_replace(['{DEPARTMENT_NAME}','{DEPARTMENT}','{DEPT}'],$deptName,$seminarRaw);
    $seminarText = str_replace(['{CERT_NUMBER}'],$certNumber,$seminarText);
    $awardeeText = str_replace(['{CERT_NUMBER}'],$certNumber,$awardeeText);

    $bgRel = $tpl['bg_url'] ?? '';
    $bgData=''; if($bgRel){ $abs=__DIR__.'/../'.str_replace('..','',$bgRel); if(is_file($abs)){ $data=@file_get_contents($abs); if($data!==false){ $mime = str_ends_with(strtolower($bgRel),'.png')?'image/png':'image/jpeg'; $bgData='data:'.$mime.';base64,'.base64_encode($data); } } }

    // Font + positions
    $showAwardee = (int)($tpl['show_awardee']??1)===1; $showSeminar=(int)($tpl['show_seminar']??1)===1; $showNumber=(int)($tpl['show_number']??1)===1;
    $ax=(float)($tpl['pos_cert_awardee_name_x']??0); $ay=(float)($tpl['pos_cert_awardee_name_y']??0);
    $sx=(float)($tpl['pos_seminar_title_x']??0); $sy=(float)($tpl['pos_seminar_title_y']??0);
    $nx=(float)($tpl['pos_cert_number_x']??0); $ny=(float)($tpl['pos_cert_number_y']??0);
    $faFam=$tpl['font_awardee_family']??'Helvetica'; $faSize=(int)($tpl['font_awardee_size_px']??46); $faCol=$tpl['font_awardee_color']??'#000000';
    $fsFam=$tpl['font_seminar_family']??'Helvetica'; $fsSize=(int)($tpl['font_seminar_size_px']??24); $fsCol=$tpl['font_seminar_color']??'#000000';
    $fnFam=$tpl['font_number_family']??'Helvetica'; $fnSize=(int)($tpl['font_number_size_px']??16); $fnCol=$tpl['font_number_color']??'#000000';

    // Signatures for this certificate template
    $sigRows=[]; if($sg=$db->prepare('SELECT indicator,signature_url,pos_x,pos_y,width_pct FROM certificate_signatures WHERE certificate_id=? AND enabled=1')){ $sg->bind_param('i',$tpl['id']); $sg->execute(); $res=$sg->get_result(); while($row=$res->fetch_assoc()) $sigRows[]=$row; $sg->close(); }

    $sigHtml=''; foreach($sigRows as $sg){ $srel=$sg['signature_url']; $sdata=''; if($srel){ $sabs=__DIR__.'/../'.str_replace('..','',$srel); if(is_file($sabs)){ $d=@file_get_contents($sabs); if($d!==false){ $mime=str_ends_with(strtolower($srel),'.png')?'image/png':'image/jpeg'; $sdata='data:'.$mime.';base64,'.base64_encode($d); } } } if($sdata){ $sigHtml.='<img src="'.htmlspecialchars($sdata).'" style="position:absolute;left:'.(float)$sg['pos_x'].'%;top:'.(float)$sg['pos_y'].'%;width:'.(float)$sg['width_pct'].'%;height:auto;" />'; } }

    $html='<!DOCTYPE html><html><head><meta charset="utf-8"><style>@page{margin:0;}body{margin:0;font-family:Helvetica,Arial,sans-serif;} .cv{position:relative;width:100%;min-height:600px;} .bg{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;} .txt{position:absolute;white-space:pre-wrap;line-height:1.15;font-weight:500;} </style></head><body><div class="cv">';
    if($bgData) $html.='<img class="bg" src="'.htmlspecialchars($bgData).'" />';
    if($showAwardee) $html.='<div class="txt" style="left:'.$ax.'%;top:'.$ay.'%;font-family:'.htmlspecialchars($faFam).';font-size:'.$faSize.'px;color:'.htmlspecialchars($faCol).';">'.htmlspecialchars($awardeeText).'</div>';
    if($showSeminar) $html.='<div class="txt" style="left:'.$sx.'%;top:'.$sy.'%;font-family:'.htmlspecialchars($fsFam).';font-size:'.$fsSize.'px;color:'.htmlspecialchars($fsCol).';">'.htmlspecialchars($seminarText).'</div>';
    if($showNumber) $html.='<div class="txt" style="left:'.$nx.'%;top:'.$ny.'%;font-family:'.htmlspecialchars($fnFam).';font-size:'.$fnSize.'px;color:'.htmlspecialchars($fnCol).';">'.htmlspecialchars($certNumber).'</div>';
    $html.=$sigHtml;
    $html.='<div style="position:absolute;right:14px;bottom:10px;font-size:11px;color:#444;">Attendance Certificate</div>';
    $html.='</div></body></html>';

    $dom = new Dompdf(['defaultFont'=>'Helvetica']);
    $dom->setPaper('A4','landscape');
    $dom->loadHtml($html,'UTF-8');
    $dom->render();
    $dir = __DIR__.'/../uploads/certificates/sub_departments_attendance'; if(!is_dir($dir)) @mkdir($dir,0775,true);
    $file='subdept_att_'.$dept_id.'_'.$user_id.'_'.substr(sha1($user_id.$dept_id.microtime(true)),0,8).'.pdf';
    if(file_put_contents($dir.'/'.$file,$dom->output())===false) return ['error'=>'Save failed'];
    $rel='uploads/certificates/sub_departments_attendance/'.$file;
    if($ins=$db->prepare('INSERT INTO sub_department_attendance_certificates (user_id,technical_department_id,file_path) VALUES (?,?,?)')){ $ins->bind_param('iis',$user_id,$dept_id,$rel); if(!$ins->execute()) { $ins->close(); return ['error'=>'DB insert failed']; } $ins->close(); }
    return ['file_path'=>$rel,'already'=>false];
}

// Explicit kind handling
$requestedKind = isset($data['kind']) ? strtolower(trim((string)$data['kind'])) : '';
if ($requestedKind === 'attendance') {
    if(!$progress['complete']) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Attendance criteria not met','kind'=>'attendance','progress'=>$progress,'complete'=>$progress['complete']]); exit; }
    $attendanceResult = generate_attendance_certificate($db,$user_id,$dept_id);
    if (!empty($attendanceResult['error'])) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>$attendanceResult['error'],'kind'=>'attendance','progress'=>$progress,'complete'=>$progress['complete']]); }
    else { echo json_encode(['ok'=>true,'kind'=>'attendance'] + $attendanceResult + ['progress'=>$progress,'complete'=>$progress['complete']]); }
    exit;
} elseif ($requestedKind === 'completion') {
    if (!$progress['complete']) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'error'=>'Completion criteria not met','kind'=>'completion','progress'=>$progress,'complete'=>false]);
        exit;
    }
    // proceed to completion generation below
} else {
    // Auto mode (backward compatibility). Now strictly require completion; no attendance fallback here.
    if (!$progress['complete']) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Department incomplete','progress'=>$progress,'complete'=>false]); exit; }
    // else fall through to completion generation below
}

// Existing certificate reuse (re-check complete with updated quiz requirement)
if ($st = $db->prepare('SELECT file_path FROM sub_department_certificates WHERE user_id=? AND technical_department_id=? LIMIT 1')) {
    $st->bind_param('ii',$user_id,$dept_id); $st->execute(); $st->bind_result($fp); if ($st->fetch()) { $st->close(); echo json_encode(['ok'=>true,'already'=>true,'file_path'=>$fp]); exit; } $st->close();
}

// Fetch user name
$user_name='Participant';
if ($st = $db->prepare('SELECT name FROM users WHERE id=? LIMIT 1')) { $st->bind_param('i',$user_id); $st->execute(); $st->bind_result($user_name); $st->fetch(); $st->close(); }

// Try load department-specific layout (new system) OR legacy template_html
@$db->query("CREATE TABLE IF NOT EXISTS department_certificate_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    technical_department_id INT NOT NULL UNIQUE,
    template_html MEDIUMTEXT NULL,
    bg_url VARCHAR(255) DEFAULT '',
    user_name_placeholder VARCHAR(120) DEFAULT '{USER_NAME}',
    department_name_placeholder VARCHAR(160) DEFAULT '{DEPARTMENT_NAME}',
    speakers_list_placeholder VARCHAR(160) DEFAULT '{SPEAKERS_LIST}',
    pos_user_name_x DOUBLE DEFAULT 0, pos_user_name_y DOUBLE DEFAULT 0,
    pos_department_name_x DOUBLE DEFAULT 0, pos_department_name_y DOUBLE DEFAULT 0,
    pos_speakers_list_x DOUBLE DEFAULT 0, pos_speakers_list_y DOUBLE DEFAULT 0,
    show_user_name TINYINT(1) DEFAULT 1,
    show_department_name TINYINT(1) DEFAULT 1,
    show_speakers_list TINYINT(1) DEFAULT 1,
    font_user_name_family VARCHAR(60) DEFAULT 'Helvetica', font_user_name_size_px INT DEFAULT 40, font_user_name_color VARCHAR(15) DEFAULT '#000000',
    font_department_name_family VARCHAR(60) DEFAULT 'Helvetica', font_department_name_size_px INT DEFAULT 28, font_department_name_color VARCHAR(15) DEFAULT '#000000',
    font_speakers_list_family VARCHAR(60) DEFAULT 'Helvetica', font_speakers_list_size_px INT DEFAULT 16, font_speakers_list_color VARCHAR(15) DEFAULT '#000000',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$layoutRow = null; $legacyHtml = null;
if ($st = $db->prepare('SELECT * FROM department_certificate_templates WHERE technical_department_id=? LIMIT 1')) {
    $st->bind_param('i',$dept_id); $st->execute(); $res=$st->get_result(); $layoutRow=$res->fetch_assoc(); $st->close();
    if ($layoutRow) { $legacyHtml = $layoutRow['template_html'] ?? null; }
}

// Load signatures (enabled) for this department (new system)
$deptSignatures=[];
if ($sig = $db->prepare('SELECT indicator,signature_url,pos_x,pos_y,width_pct FROM department_certificate_signatures WHERE technical_department_id=? AND enabled=1')) {
    $sig->bind_param('i',$dept_id); $sig->execute(); $r=$sig->get_result(); while($row=$r->fetch_assoc()) $deptSignatures[]=$row; $sig->close();
}

// Build certificate HTML (department completion, with template override)
$eventName = '73rd PSME National Convention';
$issuedAt = date('Y-m-d H:i');
$deptName = $dept['name'];

function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }

// Build aggregated speakers list (titles/designations) for the department
$speakerTitles = [];
if ($stSp = $db->prepare("SELECT DISTINCT TRIM(CONCAT(COALESCE(NULLIF(title,''),''), CASE WHEN title<>'' THEN ' - ' ELSE '' END, COALESCE(NULLIF(name,''),'') )) as label
    FROM speakers WHERE program_type='sub' AND technical_department_id=? ORDER BY name ASC")) {
    $stSp->bind_param('i',$dept_id); $stSp->execute(); $res = $stSp->get_result();
    while($row = $res->fetch_assoc()) { if(!empty($row['label'])) $speakerTitles[] = $row['label']; }
    $stSp->close();
}
$speakers_plain = $speakerTitles ? implode(', ', array_map('esc',$speakerTitles)) : '';
$speakers_list_html = '';
if ($speakerTitles) {
    $speakers_list_html = '<ul style="margin:8px 0 0 28px;padding:0;font-size:14px;">';
    foreach ($speakerTitles as $lab) { $speakers_list_html .= '<li>'.esc($lab).'</li>'; }
    $speakers_list_html .= '</ul>';
}

// Decide rendering pathway:
// 1. New layout (bg_url or positioning columns present)
// 2. Legacy HTML template_html (if not empty)
// 3. Fallback styled default

$html='';
if ($layoutRow && (!empty($layoutRow['bg_url']) || !empty($layoutRow['user_name_placeholder']) )) {
    // New layout rendering
    $bgRel = $layoutRow['bg_url'] ?? '';
    $bgAbs = $bgRel ? (__DIR__.'/../'.$bgRel) : '';
    $bgData='';
    if ($bgRel && is_file($bgAbs)) {
        $data=@file_get_contents($bgAbs); if($data!==false){ $mime = str_ends_with(strtolower($bgRel),'.png')?'image/png':'image/jpeg'; $bgData='data:'.$mime.';base64,'.base64_encode($data); }
    }
    $phUser = $layoutRow['user_name_placeholder'] ?? '{USER_NAME}';
    $phDept = $layoutRow['department_name_placeholder'] ?? '{DEPARTMENT_NAME}';
    $phSpeakers = $layoutRow['speakers_list_placeholder'] ?? '{SPEAKERS_LIST}';

    $showUser = (int)($layoutRow['show_user_name']??1)===1;
    $showDept = (int)($layoutRow['show_department_name']??1)===1;
    $showSpeakers = (int)($layoutRow['show_speakers_list']??1)===1;

    // Substitute runtime values
    $txtUser = str_replace(['{USER_NAME}','{NAME}','{FULL_NAME}'],$user_name,$phUser);
    $txtDept = str_replace(['{DEPARTMENT_NAME}','{DEPT}','{DEPARTMENT}'],$deptName,$phDept);
    $txtSpeakers = str_replace(['{SPEAKERS_LIST}','{SPEAKERS}'],$speakers_plain,$phSpeakers);

    // Font settings
    $fuFam = $layoutRow['font_user_name_family'] ?? 'Helvetica';
    $fuSize = (int)($layoutRow['font_user_name_size_px'] ?? 40);
    $fuCol  = $layoutRow['font_user_name_color'] ?? '#000000';
    $fdFam = $layoutRow['font_department_name_family'] ?? 'Helvetica';
    $fdSize = (int)($layoutRow['font_department_name_size_px'] ?? 28);
    $fdCol  = $layoutRow['font_department_name_color'] ?? '#000000';
    $fsFam = $layoutRow['font_speakers_list_family'] ?? 'Helvetica';
    $fsSize = (int)($layoutRow['font_speakers_list_size_px'] ?? 16);
    $fsCol  = $layoutRow['font_speakers_list_color'] ?? '#000000';

    // Positions
    $ux = (float)($layoutRow['pos_user_name_x'] ?? 0); $uy = (float)($layoutRow['pos_user_name_y'] ?? 0);
    $dx = (float)($layoutRow['pos_department_name_x'] ?? 0); $dy = (float)($layoutRow['pos_department_name_y'] ?? 0);
    $sx = (float)($layoutRow['pos_speakers_list_x'] ?? 0); $sy = (float)($layoutRow['pos_speakers_list_y'] ?? 0);

    // Signatures layer
    $sigHtml='';
    foreach($deptSignatures as $sg){
        $sigUrl=$sg['signature_url']; $sigAbs = $sigUrl? (__DIR__.'/../'.$sigUrl):''; $sigData='';
        if ($sigUrl && is_file($sigAbs)) { $d=@file_get_contents($sigAbs); if($d!==false){ $mime=str_ends_with(strtolower($sigUrl),'.png')?'image/png':'image/jpeg'; $sigData='data:'.$mime.';base64,'.base64_encode($d);} }
        $w=(float)($sg['width_pct']??25); $px=(float)($sg['pos_x']??0); $py=(float)($sg['pos_y']??0);
        if($sigData){ $sigHtml.='<img src="'.esc($sigData).'" style="position:absolute;left:'.$px.'%;top:'.$py.'%;width:'.$w.'%;height:auto;" />'; }
    }

    $html='<!DOCTYPE html><html><head><meta charset="utf-8"><style>@page{margin:0;}body{margin:0;font-family:Helvetica,Arial,sans-serif;} .canvas{position:relative;width:100%;height:100%;min-height:600px;overflow:hidden;} .bg{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;} .txt{position:absolute;white-space:pre-wrap;line-height:1.15;font-weight:500;} .speakers{max-width:70%;}</style></head><body>';
    $html.='<div class="canvas">';
    if($bgData){ $html.='<img class="bg" src="'.esc($bgData).'"/>'; }
    if($showUser) $html.='<div class="txt" style="left:'.$ux.'%;top:'.$uy.'%;font-family:'.esc($fuFam).';font-size:'.$fuSize.'px;color:'.esc($fuCol).';">'.esc($txtUser).'</div>';
    if($showDept) $html.='<div class="txt" style="left:'.$dx.'%;top:'.$dy.'%;font-family:'.esc($fdFam).';font-size:'.$fdSize.'px;color:'.esc($fdCol).';">'.esc($txtDept).'</div>';
    if($showSpeakers){
        $html.='<div class="txt speakers" style="left:'.$sx.'%;top:'.$sy.'%;font-family:'.esc($fsFam).';font-size:'.$fsSize.'px;color:'.esc($fsCol).';width:50%;">'.esc($txtSpeakers).'</div>';
    }
    $html.=$sigHtml;
    $html.='<div style="position:absolute;right:14px;bottom:10px;font-size:11px;color:#444;">Issued '.esc($issuedAt).'</div>';
    $html.='</div></body></html>';
} elseif (!empty($legacyHtml)) {
    // Legacy HTML path
    $repl = [
        '{{user_name}}'=>esc($user_name),
        '{{department_name}}'=>esc($deptName),
        '{{event_name}}'=>esc($eventName),
        '{{issued_at}}'=>esc($issuedAt),
        '{{department_speakers}}'=>$speakers_plain,
        '{{department_speakers_list}}'=>$speakers_list_html
    ];
    $html = str_replace(array_keys($repl), array_values($repl), $legacyHtml);
    if (strpos($html,'{{department_speakers') === false && $speakers_list_html) {
        $html .= '<div style="margin-top:20px;">Speakers / Designations:'.$speakers_list_html.'</div>'; }
    if (stripos($html,'<html') === false) { $html='<!DOCTYPE html><html><head><meta charset="utf-8"><title>Certificate</title></head><body>'.$html.'</body></html>'; }
} else {
    // Fallback simple design
    $html='<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
      .'@page { margin:0; } body{ font-family:DejaVu Sans, Arial, sans-serif; margin:0; }'
      .'.wrap{ width:100%; height:100%; padding:60px 60px; box-sizing:border-box; position:relative; }'
      .'.border{ position:absolute; inset:0; border:10px double #92400e; }'
      .'h1{ text-align:center; font-size:40px; margin:16px 0 0; letter-spacing:1px; color:#7c2d12; }'
      .'h2{ text-align:center; font-size:22px; margin:4px 0 28px; font-weight:600; color:#991b1b; }'
      .'.name{ text-align:center; font-size:34px; font-weight:700; margin:20px 0 10px; color:#111827; }'
      .'.dept{ text-align:center; font-size:18px; margin:14px 0 30px; color:#374151; }'
      .'.meta{ text-align:center; font-size:14px; margin-top:10px; color:#475569; }'
      .'.footer{ position:absolute; bottom:26px; left:0; right:0; text-align:center; font-size:11px; letter-spacing:.5px; color:#475569; }'
      .'</style></head><body><div class="border"></div><div class="wrap">'
      .'<h1>Certificate of Completion</h1>'
      .'<h2>'.esc($eventName).'</h2>'
      .'<div class="name">'.esc($user_name).'</div>'
      .'<div style="text-align:center;font-size:16px;">has successfully completed all required Sub Program sessions under the</div>'
      .'<div class="dept">&ldquo;'.esc($deptName).'&rdquo; Technical Department</div>'
      .($speakers_list_html ? '<div style="font-size:14px;margin-top:10px;">Speakers / Designations:'.$speakers_list_html.'</div>' : '')
      .'<div class="meta">Issued '.esc($issuedAt).'</div>'
      .'<div class="footer">Automated Department Certificate • Sub Program</div>'
      .'</div></body></html>';
}

$dompdf = new Dompdf(['defaultFont'=>'Helvetica']);
$dompdf->setPaper('A4','landscape');
$dompdf->loadHtml($html,'UTF-8');
$dompdf->render();

$outDir = __DIR__ . '/../uploads/certificates/sub_departments';
if (!is_dir($outDir)) @mkdir($outDir,0775,true);
$fileName = 'subdept_'.$dept_id.'_'.$user_id.'_'.substr(sha1($user_id.$dept_id.microtime(true)),0,8).'.pdf';
$absPath = $outDir.'/'.$fileName;
if (file_put_contents($absPath,$dompdf->output()) === false) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Failed to save PDF']); exit; }
$relPath = 'uploads/certificates/sub_departments/'.$fileName;

if ($ins = $db->prepare('INSERT INTO sub_department_certificates (user_id,technical_department_id,file_path) VALUES (?,?,?)')) {
    $ins->bind_param('iis',$user_id,$dept_id,$relPath);
    if (!$ins->execute()) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Failed to record certificate']); exit; }
    $ins->close();
}

echo json_encode(['ok'=>true,'already'=>false,'file_path'=>$relPath,'progress'=>$progress,'kind'=>'completion']);
