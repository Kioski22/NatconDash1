<?php
// Generate (or fetch existing) per-sub-program speaker session certificate after evaluation completion
// Modes:
//   POST JSON { speaker_id: <id> } => generate if not exists (requires evaluation)
//   GET  ?speaker_id=<id>&mode=status => returns status only
header('Content-Type: application/json; charset=utf-8');
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vendor/autoload.php';
use Dompdf\Dompdf;

// Normalize DB connection
if (isset($conn) && $conn instanceof mysqli)      $db = $conn;
elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli;
else { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB unavailable']); exit; }

$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($user_id <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Login required']); exit; }

// Auto-migrate table if missing
@$db->query("CREATE TABLE IF NOT EXISTS sub_session_certificates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  speaker_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_user_speaker (user_id, speaker_id),
  KEY idx_speaker (speaker_id),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Helper fetch speaker + ensure it's sub program
function fetch_speaker(mysqli $db, int $speaker_id): ?array {
    if ($st = $db->prepare("SELECT id,name,title,program_type FROM speakers WHERE id=? LIMIT 1")) {
        $st->bind_param('i',$speaker_id); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close();
        return $r ?: null;
    }
    return null;
}

// GET status mode
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $speaker_id = isset($_GET['speaker_id']) ? (int)$_GET['speaker_id'] : 0;
    if ($speaker_id <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid speaker']); exit; }
    $sp = fetch_speaker($db,$speaker_id);
    if (!$sp) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Speaker not found']); exit; }
    if (strcasecmp((string)$sp['program_type'],'sub') !== 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Not a sub program speaker']); exit; }

    $existing = null;
    if ($st = $db->prepare('SELECT file_path, generated_at FROM sub_session_certificates WHERE user_id=? AND speaker_id=? LIMIT 1')) {
        $st->bind_param('ii',$user_id,$speaker_id); $st->execute(); $st->bind_result($fp,$gen); if ($st->fetch()) { $existing=['file_path'=>$fp,'generated_at'=>$gen]; } $st->close();
    }
    echo json_encode(['ok'=>true,'generated'=> (bool)$existing, 'certificate'=>$existing]);
    exit;
}

// POST generate
$raw = file_get_contents('php://input');
$data = json_decode($raw,true);
$speaker_id = isset($data['speaker_id']) ? (int)$data['speaker_id'] : 0;
if ($speaker_id <= 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid speaker']); exit; }

$sp = fetch_speaker($db,$speaker_id);
if (!$sp) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Speaker not found']); exit; }
if (strcasecmp((string)$sp['program_type'],'sub') !== 0) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Not a sub program speaker']); exit; }

// Check evaluation completion
$evaluated = false;
if ($st = $db->prepare('SELECT 1 FROM speaker_evaluations WHERE speaker_id=? AND user_id=? LIMIT 1')) { $st->bind_param('ii',$speaker_id,$user_id); $st->execute(); $st->bind_result($d); if ($st->fetch()) $evaluated=true; $st->close(); }
if (!$evaluated && $st = $db->prepare('SELECT 1 FROM speaker_eval_responses WHERE speaker_id=? AND user_id=? LIMIT 1')) { $st->bind_param('ii',$speaker_id,$user_id); $st->execute(); $st->bind_result($d); if ($st->fetch()) $evaluated=true; $st->close(); }
if (!$evaluated) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Evaluation incomplete']); exit; }

// Existing certificate?
if ($st = $db->prepare('SELECT file_path FROM sub_session_certificates WHERE user_id=? AND speaker_id=? LIMIT 1')) {
    $st->bind_param('ii',$user_id,$speaker_id); $st->execute(); $st->bind_result($fp); if ($st->fetch()) { $st->close(); echo json_encode(['ok'=>true,'already'=>true,'file_path'=>$fp]); exit; } $st->close();
}

// Fetch user name & email
$user_name='Participant'; $user_email='';
if ($st = $db->prepare('SELECT name,email FROM users WHERE id=? LIMIT 1')) { $st->bind_param('i',$user_id); $st->execute(); $st->bind_result($user_name,$user_email); $st->fetch(); $st->close(); }

// Simple on-the-fly certificate (self-contained)
$eventName = '73rd PSME National Convention';
$sessionTitle = trim($sp['title']) !== '' ? $sp['title'] : ('Session by '.$sp['name']);
$issuedAt = date('Y-m-d H:i');

function esc($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
    .'@page { margin:0; } body{ font-family:DejaVu Sans, Arial, sans-serif; margin:0; }'
    .'.wrap{ width:100%; height:100%; padding:60px 50px; box-sizing:border-box; }'
    .'.border{ position:absolute; inset:0; border:8px double #1e3a8a; }'
    .'h1{ text-align:center; font-size:42px; margin:10px 0 5px; letter-spacing:1px; color:#1e3a8a; }'
    .'h2{ text-align:center; font-size:22px; margin:0 0 30px; font-weight:normal; color:#334155; }'
    .'.name{ text-align:center; font-size:34px; font-weight:700; margin:24px 0 8px; color:#111827; }'
    .'.session{ text-align:center; font-size:18px; margin:6px 0 30px; color:#374151; }'
    .'.footer{ position:absolute; bottom:28px; left:0; right:0; text-align:center; font-size:12px; color:#475569; }'
    .'</style></head><body><div class="border"></div><div class="wrap">'
    .'<h1>Certificate of Participation</h1>'
    .'<h2>'.esc($eventName).'</h2>'
    .'<div class="name">'.esc($user_name).'</div>'
    .'<div style="text-align:center;font-size:16px;">has successfully completed the session</div>'
    .'<div class="session">&ldquo;'.esc($sessionTitle).'&rdquo; by '.esc($sp['name']).'</div>'
    .'<div style="text-align:center;font-size:14px;">Issued '.esc($issuedAt).'</div>'
    .'<div class="footer">Automated certificate • Sub Program Session</div>'
    .'</div></body></html>';

$dompdf = new Dompdf(['defaultFont'=>'Helvetica']);
$dompdf->setPaper('A4','landscape');
$dompdf->loadHtml($html,'UTF-8');
$dompdf->render();

$outDir = __DIR__ . '/../uploads/certificates/sub_sessions';
if (!is_dir($outDir)) @mkdir($outDir,0775,true);
$fileName = 'subsess_'.$speaker_id.'_'.$user_id.'_'.substr(sha1($user_id.$speaker_id.microtime(true)),0,8).'.pdf';
$absPath = $outDir . '/' . $fileName;
if (file_put_contents($absPath,$dompdf->output()) === false) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Failed to save PDF']); exit; }
$relPath = 'uploads/certificates/sub_sessions/'.$fileName;

if ($ins = $db->prepare('INSERT INTO sub_session_certificates (user_id,speaker_id,file_path) VALUES (?,?,?)')) {
    $ins->bind_param('iis',$user_id,$speaker_id,$relPath);
    if (!$ins->execute()) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Failed to record certificate']); exit; }
    $ins->close();
}

echo json_encode(['ok'=>true,'already'=>false,'file_path'=>$relPath]);
?>
