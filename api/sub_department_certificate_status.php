<?php
// Department-level certificate status + readiness endpoint (read-only).
// Returns progress, template availability, gating flags, and existing issuance metadata.

header('Content-Type: application/json; charset=utf-8');
session_start();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/lib/sub_department_progress.php';

if (isset($conn) && $conn instanceof mysqli) {
    $db = $conn;
} elseif (isset($mysqli) && $mysqli instanceof mysqli) {
    $db = $mysqli;
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB unavailable']);
    exit;
}

$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$user_email = trim((string)($_SESSION['user_email'] ?? $_SESSION['email'] ?? ''));
if ($user_id <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Login required']);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

function fetch_department(mysqli $db, int $dept_id): ?array {
    if ($dept_id <= 0) return null;
    if ($st = $db->prepare('SELECT id, name FROM technical_departments WHERE id=? LIMIT 1')) {
        $st->bind_param('i', $dept_id);
        $st->execute();
        $st->bind_result($id, $name);
        if ($st->fetch()) {
            $st->close();
            return ['id' => $id, 'name' => $name];
        }
        $st->close();
    }
    return null;
}

function fetch_speaker_dept(mysqli $db, int $speaker_id): ?int {
    if ($speaker_id <= 0) return null;
    if ($st = $db->prepare('SELECT technical_department_id FROM speakers WHERE id=? LIMIT 1')) {
        $st->bind_param('i', $speaker_id);
        $st->execute();
        $st->bind_result($dept);
        if ($st->fetch()) {
            $st->close();
            return (int)$dept;
        }
        $st->close();
    }
    return null;
}

function resolve_department_id(mysqli $db, array $input): array {
    $dept_id = isset($input['department_id']) ? (int)$input['department_id'] : 0;
    if ($dept_id <= 0 && !empty($input['speaker_id'])) {
        $maybe = fetch_speaker_dept($db, (int)$input['speaker_id']);
        if ($maybe) $dept_id = $maybe;
    }
    if ($dept_id <= 0) {
        return [0, null, 'Department not found'];
    }
    $dept = fetch_department($db, $dept_id);
    if (!$dept) {
        return [0, null, 'Department not found'];
    }
    return [$dept_id, $dept, null];
}

function ensure_certificate_storage(mysqli $db): void {
    @$db->query("CREATE TABLE IF NOT EXISTS sub_department_certificates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        technical_department_id INT NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        cert_number VARCHAR(120) DEFAULT NULL,
        template_id INT DEFAULT NULL,
        template_updated_at TIMESTAMP NULL DEFAULT NULL,
        version INT NOT NULL DEFAULT 1,
        generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_dept (user_id, technical_department_id),
        KEY idx_dept (technical_department_id),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ($res = $db->query('SHOW COLUMNS FROM sub_department_certificates')) {
        $cols = [];
        while ($row = $res->fetch_assoc()) {
            $cols[$row['Field']] = true;
        }
        $res->close();
        if (!isset($cols['cert_number'])) {
            @$db->query("ALTER TABLE sub_department_certificates ADD COLUMN cert_number VARCHAR(120) DEFAULT NULL AFTER file_path");
        }
        if (!isset($cols['template_id'])) {
            @$db->query("ALTER TABLE sub_department_certificates ADD COLUMN template_id INT DEFAULT NULL AFTER cert_number");
        }
        if (!isset($cols['template_updated_at'])) {
            @$db->query("ALTER TABLE sub_department_certificates ADD COLUMN template_updated_at TIMESTAMP NULL DEFAULT NULL AFTER template_id");
        }
        if (!isset($cols['version'])) {
            @$db->query("ALTER TABLE sub_department_certificates ADD COLUMN version INT NOT NULL DEFAULT 1 AFTER template_updated_at");
        }
    }
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
        'day_future'    => 'future sessions',
        'day_disabled'  => 'disabled days',
        'room_inactive' => 'inactive rooms',
        'room_missing'  => 'unscheduled sessions',
        'day_other'     => 'sessions on other days',
        'day_unassigned'=> 'unscheduled day',
    ];
    $parts = [];
    foreach ($summary as $key => $count) {
        $label = $labels[$key] ?? str_replace('_', ' ', (string)$key);
        $parts[] = ((int)$count) . ' ' . $label;
    }
    if (!$parts) return '';
    return 'Waiting on ' . implode(', ', $parts);
}

function fetch_template(mysqli $db, int $dept_id): ?array {
    if ($st = $db->prepare('SELECT id, updated_at FROM department_certificate_templates WHERE technical_department_id=? LIMIT 1')) {
        $st->bind_param('i', $dept_id);
        $st->execute();
        $res = $st->get_result();
        $row = $res->fetch_assoc();
        $st->close();
        return $row ?: null;
    }
    return null;
}

$input = [
    'department_id' => isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0,
    'speaker_id'    => isset($_GET['speaker_id']) ? (int)$_GET['speaker_id'] : 0,
    'day_id'        => isset($_GET['day_id']) ? (int)$_GET['day_id'] : 0,
];

[$dept_id, $dept, $err] = resolve_department_id($db, $input);
if ($err) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $err]);
    exit;
}

$dayFilter = ($input['day_id'] ?? 0) > 0 ? (int)$input['day_id'] : null;
$progress = compute_progress($db, $dept_id, $user_id, $user_email, $dayFilter);

$sumPending = summarize_pending($progress['pending_detail'] ?? []);
$sumSkipped = summarize_skipped($progress['skipped_summary'] ?? []);
$completed = (int)($progress['completed'] ?? 0);
$total = (int)($progress['total'] ?? 0);

$templateRow = fetch_template($db, $dept_id);
$templateAssigned = $templateRow !== null;
$templateUpdatedAt = $templateRow['updated_at'] ?? null;
$templateId = $templateRow['id'] ?? null;

$certEnabled = (int)(get_setting($db, 'cert_generation_enabled', '0') ?? '0') === 1;
$autoEnable = trim((string)(get_setting($db, 'cert_auto_enable_date', '') ?? ''));
$autoEnableTs = $autoEnable !== '' ? @strtotime($autoEnable) : null;
$canGenerateNow = $certEnabled || ($autoEnableTs !== null && time() >= $autoEnableTs);

ensure_certificate_storage($db);

$existing = null;
if ($st = $db->prepare('SELECT file_path, cert_number, template_id, template_updated_at, version, generated_at FROM sub_department_certificates WHERE user_id=? AND technical_department_id=? LIMIT 1')) {
    $st->bind_param('ii', $user_id, $dept_id);
    $st->execute();
    $res = $st->get_result();
    $existing = $res->fetch_assoc();
    $st->close();
}

$summaryMessage = 'Progress ' . $completed . ' / ' . $total . ' complete.';
$summaryVariant = 'light';
if (!empty($progress['complete'])) {
    $summaryMessage = 'All requirements met for this department.';
    $summaryVariant = 'success';
} elseif ($sumPending !== '') {
    $summaryMessage = $sumPending;
    $summaryVariant = 'warning';
} elseif ($total <= 0) {
    $summaryMessage = $sumSkipped !== '' ? $sumSkipped : 'No active sessions available yet.';
    $summaryVariant = 'warning';
} elseif ($sumSkipped !== '') {
    $summaryMessage .= ' ' . $sumSkipped;
}

$blockedReason = null;
if (empty($progress['complete'])) {
    $blockedReason = 'Complete all required sessions to enable certificate generation.';
} elseif (!$templateAssigned) {
    $blockedReason = 'Certificate layout not yet assigned by the admin.';
} elseif (!$canGenerateNow) {
    $blockedReason = $autoEnable ? ('Certificates unlock on ' . $autoEnable . '.') : 'Certificate generation is not enabled yet.';
}

$downloadUrl = null;
if ($existing && !empty($existing['file_path'])) {
    $downloadUrl = '../' . ltrim($existing['file_path'], '/');
}

$response = [
    'ok' => true,
    'department' => [
        'id' => $dept_id,
        'name' => $dept['name']
    ],
    'progress' => $progress,
    'summary' => [
        'text' => $summaryMessage,
        'variant' => $summaryVariant
    ],
    'complete' => !empty($progress['complete']),
    'can_generate_now' => $canGenerateNow,
    'template' => [
        'assigned' => $templateAssigned,
        'id' => $templateId ? (int)$templateId : null,
        'updated_at' => $templateUpdatedAt
    ],
    'certificate' => $existing ? [
        'file_path' => $existing['file_path'],
        'download_url' => $downloadUrl,
        'cert_number' => $existing['cert_number'] ?? null,
        'template_id' => $existing['template_id'] ?? null,
        'template_updated_at' => $existing['template_updated_at'] ?? null,
        'version' => isset($existing['version']) ? (int)$existing['version'] : null,
        'generated_at' => $existing['generated_at'] ?? null
    ] : null,
    'actions' => [
        'can_generate' => empty($blockedReason),
        'can_download' => $existing && !empty($existing['file_path']),
        'can_email' => $existing && !empty($existing['file_path'])
    ],
    'blocked_reason' => $blockedReason,
    'activation_at' => $autoEnable ?: null
];

echo json_encode($response);
