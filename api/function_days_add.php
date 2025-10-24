<?php
// api/function_days_add.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
session_start();

// Include DB
require_once __DIR__ . '/../db.php';

// Auth: admin or super
$role = strtolower((string)($_SESSION['user_role'] ?? ''));
if (!in_array($role, ['admin','super','super_admin'], true)) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Admin required']);
    exit;
}

// CSRF
$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid JSON']); exit; }
$csrf = (string)($in['csrf'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid CSRF']); exit; }

// DB normalize
if (isset($conn) && $conn instanceof mysqli) { $db = $conn; }
elseif (isset($mysqli) && $mysqli instanceof mysqli) { $db = $mysqli; }
else { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB unavailable']); exit; }

// Input formats:
// 1) Single: { label: string, day_date: 'YYYY-MM-DD', is_enabled?: 0|1 }
// 2) Bulk: { days: [{label, day_date, is_enabled?}, ...] }
// 3) Bulk text (optional): { bulk_text: "YYYY-MM-DD[, label]\n...", default_label_prefix?: string }

function parse_date(string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;
    // Accept YYYY-MM-DD only for safety
    if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $s)) return $s;
    $ts = strtotime($s);
    if ($ts === false) return null;
    return date('Y-m-d', $ts);
}

$rows = [];
if (isset($in['days']) && is_array($in['days'])) {
    foreach ($in['days'] as $d) {
        if (!is_array($d)) continue;
        $date = isset($d['day_date']) ? parse_date((string)$d['day_date']) : null;
        $label = trim((string)($d['label'] ?? ''));
        $enabled = isset($d['is_enabled']) ? (int)(bool)$d['is_enabled'] : 1;
        if ($date) { $rows[] = ['day_date'=>$date, 'label'=>($label !== '' ? $label : $date), 'is_enabled'=>$enabled]; }
    }
} elseif (!empty($in['bulk_text']) && is_string($in['bulk_text'])) {
    $prefix = trim((string)($in['default_label_prefix'] ?? 'Day'));
    $i = 1;
    foreach (preg_split('/\r?\n/', $in['bulk_text']) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        // format: YYYY-MM-DD[, Label]
        $parts = array_map('trim', explode(',', $line, 2));
        $date = parse_date($parts[0] ?? '');
        if (!$date) continue;
        $label = $parts[1] ?? ($prefix.' '.$i);
        $rows[] = ['day_date'=>$date, 'label'=>$label, 'is_enabled'=>1];
        $i++;
    }
} else {
    $date = isset($in['day_date']) ? parse_date((string)$in['day_date']) : null;
    $label = trim((string)($in['label'] ?? ''));
    $enabled = isset($in['is_enabled']) ? (int)(bool)$in['is_enabled'] : 1;
    if ($date) { $rows[] = ['day_date'=>$date, 'label'=>($label !== '' ? $label : $date), 'is_enabled'=>$enabled]; }
}

if (empty($rows)) { echo json_encode(['ok'=>false,'error'=>'No valid days provided']); exit; }

// Insert, skipping duplicates by date if needed (optional unique constraint)
$ins = $db->prepare("INSERT INTO function_room_days (label, day_date, is_enabled) VALUES (?,?,?)");
if (!$ins) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB prepare failed']); exit; }

$added = 0; $duplicates = 0; $errs = 0; $ids = [];
foreach ($rows as $r) {
    // Check existing by exact date
    if ($st = $db->prepare("SELECT id FROM function_room_days WHERE day_date=? LIMIT 1")){
        $st->bind_param('s', $r['day_date']);
        $st->execute();
        $st->bind_result($eid);
        if ($st->fetch()) { $duplicates++; $st->close(); continue; }
        $st->close();
    }
    $ins->bind_param('ssi', $r['label'], $r['day_date'], $r['is_enabled']);
    if ($ins->execute()) { $added++; $ids[] = $ins->insert_id; }
    else { $errs++; }
}
$ins->close();

echo json_encode(['ok'=>true,'added'=>$added,'duplicates'=>$duplicates,'errors'=>$errs,'ids'=>$ids]);
