<?php
// Read-only endpoint to expose per-department sub program progress totals.
// GET ?department_id=<id>[&day_id=<day>] or ?speaker_id=<id>

header('Content-Type: application/json; charset=utf-8');
session_start();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/lib/sub_department_progress.php';

if (isset($conn) && $conn instanceof mysqli)      $db = $conn;
elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli;
else { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB unavailable']); exit; }

$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$user_email = trim((string)($_SESSION['user_email'] ?? $_SESSION['email'] ?? ''));
if ($user_id <= 0) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Login required']); exit; }

function fetch_department(mysqli $db, int $dept_id): ?array {
    if ($dept_id <= 0) return null;
    if ($st = $db->prepare('SELECT id, name FROM technical_departments WHERE id=? LIMIT 1')) {
        $st->bind_param('i', $dept_id);
        $st->execute();
        $st->bind_result($id, $name);
        if ($st->fetch()) {
            $st->close();
            return ['id'=>$id,'name'=>$name];
        }
        $st->close();
    }
    return null;
}

function fetch_speaker_with_dept(mysqli $db, int $speaker_id): ?array {
    if ($speaker_id <= 0) return null;
    if ($st = $db->prepare('SELECT id, technical_department_id FROM speakers WHERE id=? LIMIT 1')) {
        $st->bind_param('i', $speaker_id);
        $st->execute();
        $st->bind_result($id, $deptId);
        if ($st->fetch()) {
            $st->close();
            return ['id'=>$id,'technical_department_id'=>$deptId];
        }
        $st->close();
    }
    return null;
}

function resolve_department_id(mysqli $db, array $input): array {
    $dept_id = isset($input['department_id']) ? (int)$input['department_id'] : 0;
    if (!$dept_id && !empty($input['speaker_id'])) {
        $sp = fetch_speaker_with_dept($db, (int)$input['speaker_id']);
        if ($sp) $dept_id = (int)($sp['technical_department_id'] ?? 0);
    }
    if ($dept_id <= 0) return [0, null, 'Department not found'];
    $dept = fetch_department($db, $dept_id);
    if (!$dept) return [0, null, 'Department not found'];
    return [$dept_id, $dept, null];
}

function summarize_pending(array $pending): string {
    if (!$pending) return '';
    $items = [];
    foreach ($pending as $entry) {
        if (count($items) >= 3) break;
        $label = isset($entry['speaker_label']) && trim((string)$entry['speaker_label']) !== ''
            ? trim((string)$entry['speaker_label'])
            : ('Speaker #' . ((int)($entry['speaker_id'] ?? 0)));
        $needs = [];
        if (!empty($entry['needs_eval'])) $needs[] = 'evaluation';
        if (!empty($entry['needs_quiz'])) $needs[] = 'quiz';
        if ($needs) {
            $label .= ' (' . implode(' & ', $needs) . ')';
        }
        $items[] = $label;
    }
    if (!$items) return '';
    $message = 'Still needed: ' . implode(', ', $items);
    $remaining = count($pending) - count($items);
    if ($remaining > 0) {
        $message .= ', +' . $remaining . ' more';
    }
    return $message;
}

function summarize_skipped(array $summary): string {
    if (!$summary) return '';
    $labels = [
        'day_future'   => 'future sessions',
        'day_disabled' => 'disabled days',
        'room_inactive'=> 'inactive rooms',
        'room_missing' => 'unscheduled sessions',
        'day_other'    => 'sessions on other days',
        'day_unassigned'=> 'unscheduled day'
    ];
    $parts = [];
    foreach ($summary as $key => $count) {
        $count = (int)$count;
        $label = $labels[$key] ?? str_replace('_', ' ', (string)$key);
        $parts[] = $count . ' ' . $label;
    }
    if (!$parts) return '';
    return 'Waiting on ' . implode(', ', $parts);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (strtoupper($method) !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Method not allowed']);
    exit;
}

$input = [
    'department_id' => isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0,
    'speaker_id'    => isset($_GET['speaker_id']) ? (int)$_GET['speaker_id'] : 0,
    'day_id'        => isset($_GET['day_id']) ? (int)$_GET['day_id'] : 0,
];

[$dept_id, $dept, $err] = resolve_department_id($db, $input);
if ($err) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>$err]); exit; }

$dayFilter = ($input['day_id'] ?? 0) > 0 ? (int)$input['day_id'] : null;
$progress = compute_progress($db, $dept_id, $user_id, $user_email, $dayFilter);

$completed = (int)($progress['completed'] ?? 0);
$total = (int)($progress['total'] ?? 0);
$pendingMsg = summarize_pending($progress['pending_detail'] ?? []);
$skippedMsg = summarize_skipped($progress['skipped_summary'] ?? []);

$message = 'Progress ' . $completed . ' / ' . $total . ' complete.';
$variant = 'light';

if (!empty($progress['complete'])) {
    $message = 'All requirements met for this department.';
    $variant = 'success';
} elseif ($pendingMsg !== '') {
    $message = $pendingMsg;
    $variant = 'warning';
} elseif ($total <= 0) {
    $message = $skippedMsg !== '' ? $skippedMsg : 'No active sessions available yet.';
    $variant = 'warning';
} elseif ($skippedMsg !== '') {
    $message .= ' ' . $skippedMsg;
}

$response = [
    'ok' => true,
    'department' => $dept,
    'progress' => $progress,
    'summary' => [
        'text' => $message,
        'variant' => $variant
    ],
    'rules' => [
        'require_eval' => !empty($progress['require_eval']),
        'require_quiz' => !empty($progress['require_quiz'])
    ]
];

echo json_encode($response);
