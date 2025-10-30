<?php
// Generate (or fetch existing) Sub Program department completion certificate.
// POST JSON { department_id, day_id?, regenerate? }

session_start();
header('Content-Type: application/json; charset=utf-8');
if (function_exists('ini_set')) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT);
session_start();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/sub_department_progress.php';

use Dompdf\Dompdf;

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

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = [];

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

function fetch_template_full(mysqli $db, int $dept_id): ?array {
    if ($st = $db->prepare('SELECT id, template_html, bg_url, user_name_placeholder, department_name_placeholder, speakers_list_placeholder,
        pos_user_name_x, pos_user_name_y, pos_department_name_x, pos_department_name_y, pos_speakers_list_x, pos_speakers_list_y,
        show_user_name, show_department_name, show_speakers_list,
        font_user_name_family, font_user_name_size_px, font_user_name_color,
        font_department_name_family, font_department_name_size_px, font_department_name_color,
        font_speakers_list_family, font_speakers_list_size_px, font_speakers_list_color,
        updated_at
        FROM department_certificate_templates WHERE technical_department_id=? LIMIT 1')) {
        $st->bind_param('i', $dept_id);
        $st->execute();
        $res = $st->get_result();
        $row = $res->fetch_assoc();
        $st->close();
        return $row ?: null;
    }
    return null;
}

function fetch_department_signatures(mysqli $db, int $dept_id): array {
    $signatures = [];
    if ($st = $db->prepare('SELECT indicator, signature_url, pos_x, pos_y, width_pct FROM department_certificate_signatures WHERE technical_department_id=? AND enabled=1')) {
        $st->bind_param('i', $dept_id);
        $st->execute();
        $st->bind_result($indicator, $url, $px, $py, $width);
        while ($st->fetch()) {
            $signatures[] = [
                'indicator' => $indicator,
                'signature_url' => $url,
                'pos_x' => $px,
                'pos_y' => $py,
                'width_pct' => $width
            ];
        }
        $st->close();
    }
    return $signatures;
}

function fetch_existing_certificate(mysqli $db, int $user_id, int $dept_id): ?array {
    if ($st = $db->prepare('SELECT file_path, cert_number, template_id, template_updated_at, version, generated_at FROM sub_department_certificates WHERE user_id=? AND technical_department_id=? LIMIT 1')) {
        $st->bind_param('ii', $user_id, $dept_id);
        $st->execute();
        $res = $st->get_result();
        $row = $res->fetch_assoc();
        $st->close();
        return $row ?: null;
    }
    return null;
}

function esc($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function build_speaker_lists(mysqli $db, int $dept_id): array {
    $titles = [];
    if ($st = $db->prepare("SELECT DISTINCT TRIM(CONCAT(COALESCE(NULLIF(title,''),''), CASE WHEN title<>'' THEN ' - ' ELSE '' END, COALESCE(NULLIF(name,''),'') )) AS label FROM speakers WHERE program_type='sub' AND technical_department_id=? ORDER BY name ASC")) {
        $st->bind_param('i', $dept_id);
        $st->execute();
        $st->bind_result($label);
        while ($st->fetch()) {
            if (!empty($label)) {
                $titles[] = $label;
            }
        }
        $st->close();
    }
    $plain = $titles ? implode(', ', $titles) : '';
    $html = '';
    if ($titles) {
        $html = '<ul style="margin:8px 0 0 28px;padding:0;font-size:14px;">';
        foreach ($titles as $title) {
            $html .= '<li>' . esc($title) . '</li>';
        }
        $html .= '</ul>';
    }
    return [$plain, $html];
}

function load_asset_data_uri(string $relativePath): string {
    if ($relativePath === '') return '';
    $sanitized = str_replace('..', '', $relativePath);
    $abs = __DIR__ . '/../' . ltrim($sanitized, '/');
    if (!is_file($abs)) return '';
    $content = @file_get_contents($abs);
    if ($content === false) return '';
    $lower = strtolower($sanitized);
    $mime = str_ends_with($lower, '.png') ? 'image/png' : 'image/jpeg';
    return 'data:' . $mime . ';base64,' . base64_encode($content);
}

function measure_image_dimensions(string $relativePath): array {
    $sanitized = str_replace('..', '', $relativePath);
    $abs = __DIR__ . '/../' . ltrim($sanitized, '/');
    if (is_file($abs)) {
        $info = @getimagesize($abs);
        if ($info && isset($info[0], $info[1])) {
            return [(int)$info[0], (int)$info[1]];
        }
    }
    return [1600, 1131];
}

[$dept_id, $dept, $err] = resolve_department_id($db, $data);
if ($err) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $err]);
    exit;
}

ensure_certificate_storage($db);

$dayFilter = isset($data['day_id']) && (int)$data['day_id'] > 0 ? (int)$data['day_id'] : null;
$progress = compute_progress($db, $dept_id, $user_id, $user_email, $dayFilter);

if (empty($progress['complete'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Department requirements not yet complete', 'progress' => $progress]);
    exit;
}

$certEnabled = (int)(get_setting($db, 'cert_generation_enabled', '0') ?? '0') === 1;
$autoEnable = trim((string)(get_setting($db, 'cert_auto_enable_date', '') ?? ''));
$autoEnableTs = $autoEnable !== '' ? @strtotime($autoEnable) : null;
$canGenerateNow = $certEnabled || ($autoEnableTs !== null && time() >= $autoEnableTs);

if (!$canGenerateNow) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => ($autoEnable ? 'Certificate generation opens on ' . $autoEnable : 'Certificate generation is disabled'), 'progress' => $progress]);
    exit;
}

$template = fetch_template_full($db, $dept_id);
if (!$template) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'No certificate layout assigned for this department', 'progress' => $progress]);
    exit;
}

$forceRegen = !empty($data['regenerate']);
$existing = fetch_existing_certificate($db, $user_id, $dept_id);
$templateId = (int)$template['id'];
$templateUpdatedAt = $template['updated_at'] ?? null;
$templateUpdatedTs = $templateUpdatedAt ? @strtotime($templateUpdatedAt) : 0;

$needsRegen = $forceRegen || !$existing;
if ($existing) {
    $matchTemplate = isset($existing['template_id']) && (int)$existing['template_id'] === $templateId;
    $existingUpdatedTs = isset($existing['template_updated_at']) && $existing['template_updated_at'] ? @strtotime($existing['template_updated_at']) : 0;
    if (!$forceRegen && $matchTemplate && $templateUpdatedTs <= $existingUpdatedTs && !empty($existing['file_path'])) {
        $abs = __DIR__ . '/../' . ltrim(str_replace('..', '', $existing['file_path']), '/');
        if (is_file($abs)) {
            echo json_encode([
                'ok' => true,
                'already' => true,
                'file_path' => $existing['file_path'],
                'download_url' => '../' . ltrim($existing['file_path'], '/'),
                'cert_number' => $existing['cert_number'] ?? null,
                'version' => isset($existing['version']) ? (int)$existing['version'] : 1,
                'progress' => $progress,
                'template_id' => $templateId,
                'template_updated_at' => $templateUpdatedAt,
                'regenerated' => false
            ]);
            exit;
        }
        $needsRegen = true;
    } else {
        $needsRegen = true;
    }
}

$user_name = 'Participant';
if ($st = $db->prepare('SELECT name FROM users WHERE id=? LIMIT 1')) {
    $st->bind_param('i', $user_id);
    $st->execute();
    $st->bind_result($nm);
    if ($st->fetch()) {
        $user_name = $nm ?: $user_name;
    }
    $st->close();
}

[$speakerPlain, $speakerListHtml] = build_speaker_lists($db, $dept_id);
$signatures = fetch_department_signatures($db, $dept_id);

$bgUrl = trim((string)($template['bg_url'] ?? ''));
$bgDataUri = $bgUrl !== '' ? load_asset_data_uri($bgUrl) : '';
[$imgW, $imgH] = $bgUrl ? measure_image_dimensions($bgUrl) : [1600, 1131];
if ($imgW > 2400) {
    $scale = 2400 / $imgW;
    $imgW = (int)round($imgW * $scale);
    $imgH = (int)round($imgH * $scale);
}
$paperWpt = $imgW * 72 / 96;
$paperHpt = $imgH * 72 / 96;

$issuedAt = date('Y-m-d H:i');
$deptName = $dept['name'];
$eventName = '73rd PSME National Convention';

$certNumber = 'SUB-' . date('Y') . '-' . $dept_id . '-' . substr(sha1($user_id . $dept_id . microtime(true)), 0, 6);

$layoutHtml = trim((string)($template['template_html'] ?? ''));
$userPlaceholder = $template['user_name_placeholder'] ?? '{USER_NAME}';
$deptPlaceholder = $template['department_name_placeholder'] ?? '{DEPARTMENT_NAME}';
$speakersPlaceholder = $template['speakers_list_placeholder'] ?? '{SPEAKERS_LIST}';

$userText = str_replace(['{USER_NAME}', '{NAME}', '{FULL_NAME}'], $user_name, $userPlaceholder);
$userText = str_replace(['{CERT_NUMBER}'], $certNumber, $userText);
$deptText = str_replace(['{DEPARTMENT_NAME}', '{DEPARTMENT}', '{DEPT}'], $deptName, $deptPlaceholder);
$deptText = str_replace(['{CERT_NUMBER}'], $certNumber, $deptText);

$speakersText = ($speakerPlain !== '') ? $speakerPlain : 'Sub Program Speakers';
$speakersHtml = $speakerListHtml;

$html = '';

if ($bgDataUri !== '' || $layoutHtml === '') {
    $showUser = (int)($template['show_user_name'] ?? 1) === 1;
    $showDept = (int)($template['show_department_name'] ?? 1) === 1;
    $showSpeakers = (int)($template['show_speakers_list'] ?? 1) === 1;

    $userPosX = (float)($template['pos_user_name_x'] ?? 45);
    $userPosY = (float)($template['pos_user_name_y'] ?? 52);
    $deptPosX = (float)($template['pos_department_name_x'] ?? 45);
    $deptPosY = (float)($template['pos_department_name_y'] ?? 60);
    $speakersPosX = (float)($template['pos_speakers_list_x'] ?? 15);
    $speakersPosY = (float)($template['pos_speakers_list_y'] ?? 72);

    $userFontFamily = $template['font_user_name_family'] ?? 'Helvetica';
    $userFontSize = (int)($template['font_user_name_size_px'] ?? 42);
    $userFontColor = $template['font_user_name_color'] ?? '#000000';

    $deptFontFamily = $template['font_department_name_family'] ?? 'Helvetica';
    $deptFontSize = (int)($template['font_department_name_size_px'] ?? 24);
    $deptFontColor = $template['font_department_name_color'] ?? '#000000';

    $speakersFontFamily = $template['font_speakers_list_family'] ?? 'Helvetica';
    $speakersFontSize = (int)($template['font_speakers_list_size_px'] ?? 16);
    $speakersFontColor = $template['font_speakers_list_color'] ?? '#000000';

    $userLen = function_exists('mb_strlen') ? mb_strlen($userText) : strlen($userText);
    if ($userLen > 32) $userFontSize = max((int)round($userFontSize * 0.9), 16);
    if ($userLen > 48) $userFontSize = max((int)round($userFontSize * 0.85), 14);
    if ($userLen > 64) $userFontSize = max((int)round($userFontSize * 0.8), 12);

    $sigLayer = '';
    foreach ($signatures as $sig) {
        $sigUri = !empty($sig['signature_url']) ? load_asset_data_uri($sig['signature_url']) : '';
        if ($sigUri === '') continue;
        $sigWidth = (float)($sig['width_pct'] ?? 25);
        $sigX = (float)($sig['pos_x'] ?? 0);
        $sigY = (float)($sig['pos_y'] ?? 0);
        $sigLayer .= '<img src="' . esc($sigUri) . '" style="position:absolute;left:' . $sigX . '%;top:' . $sigY . '%;width:' . $sigWidth . '%;height:auto;" />';
    }

    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
        . '@page{margin:0;}html,body{margin:0;padding:0;}'
        . '.page{position:relative;width:' . $imgW . 'px;height:' . $imgH . 'px;font-family:Helvetica,Arial,sans-serif;overflow:hidden;}'
        . '.bg{position:absolute;left:0;top:0;width:100%;height:100%;}'
        . '.txt{position:absolute;white-space:nowrap;line-height:1.15;font-weight:500;}'
        . '.txt.multiline{white-space:pre-wrap;max-width:70%;}'
        . '.meta{position:absolute;right:14px;bottom:10px;font-size:11px;color:#444;}'
        . '.speaker-list{position:absolute;max-width:70%;}'
        . '</style></head><body><div class="page">';
    if ($bgDataUri !== '') {
        $html .= '<img class="bg" src="' . esc($bgDataUri) . '" />';
    }
    if ($showUser) {
        $html .= '<div class="txt" style="left:' . $userPosX . '%;top:' . $userPosY . '%;font-family:' . esc($userFontFamily)
            . ';font-size:' . $userFontSize . 'px;color:' . esc($userFontColor) . ';">' . esc($userText) . '</div>';
    }
    if ($showDept) {
        $html .= '<div class="txt" style="left:' . $deptPosX . '%;top:' . $deptPosY . '%;font-family:' . esc($deptFontFamily)
            . ';font-size:' . $deptFontSize . 'px;color:' . esc($deptFontColor) . ';">' . esc($deptText) . '</div>';
    }
    if ($showSpeakers && $speakersHtml !== '') {
        $html .= '<div class="txt multiline speaker-list" style="left:' . $speakersPosX . '%;top:' . $speakersPosY . '%;font-family:' . esc($speakersFontFamily)
            . ';font-size:' . $speakersFontSize . 'px;color:' . esc($speakersFontColor) . ';">' . $speakersHtml . '</div>';
    }
    $html .= $sigLayer;
    $html .= '<div class="meta">Issued ' . esc($issuedAt) . '</div>';
    $html .= '</div></body></html>';
} else {
    $repl = [
        '{{user_name}}' => esc($user_name),
        '{{department_name}}' => esc($deptName),
        '{{event_name}}' => esc($eventName),
        '{{issued_at}}' => esc($issuedAt),
        '{{department_speakers}}' => esc($speakersText),
        '{{department_speakers_list}}' => $speakerListHtml ?: esc($speakersText),
        '{{cert_number}}' => esc($certNumber),
    ];
    $html = str_replace(array_keys($repl), array_values($repl), $layoutHtml);
    if (stripos($html, '<html') === false) {
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Certificate</title></head><body>' . $html . '</body></html>';
    }
}

$dompdf = new Dompdf(['defaultFont' => 'Helvetica']);
if ($bgDataUri !== '') {
    $dompdf->setPaper([0, 0, $paperWpt, $paperHpt]);
} else {
    $dompdf->setPaper('A4', 'landscape');
}
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->render();

$dir = __DIR__ . '/../uploads/certificates/sub_departments';
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}
$fileName = 'subdept_' . $dept_id . '_u' . $user_id . '_' . substr(sha1($user_id . $dept_id . microtime(true)), 0, 8) . '.pdf';
$absPath = $dir . '/' . $fileName;
if (file_put_contents($absPath, $dompdf->output()) === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save PDF']);
    exit;
}

$relPath = 'uploads/certificates/sub_departments/' . $fileName;

ensure_certificate_storage($db);

if ($existing) {
    $version = isset($existing['version']) ? ((int)$existing['version'] + 1) : 2;
    if ($st = $db->prepare('UPDATE sub_department_certificates SET file_path=?, cert_number=?, template_id=?, template_updated_at=?, version=?, generated_at=NOW() WHERE user_id=? AND technical_department_id=? LIMIT 1')) {
        $tplUpdated = $templateUpdatedAt ?: date('Y-m-d H:i:s');
        $st->bind_param('ssisiii', $relPath, $certNumber, $templateId, $tplUpdated, $version, $user_id, $dept_id);
        if (!$st->execute()) {
            $st->close();
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Failed to update certificate record']);
            exit;
        }
        $st->close();
    }
    $payload = [
        'ok' => true,
        'already' => false,
        'regenerated' => true,
        'file_path' => $relPath,
        'download_url' => '../' . ltrim($relPath, '/'),
        'cert_number' => $certNumber,
        'version' => $version,
        'template_id' => $templateId,
    'template_updated_at' => $templateUpdatedAt ?: $tplUpdated,
        'progress' => $progress
    ];
    echo json_encode($payload);
    exit;
}

if ($st = $db->prepare('INSERT INTO sub_department_certificates (user_id, technical_department_id, file_path, cert_number, template_id, template_updated_at, version) VALUES (?,?,?,?,?,?,1)')) {
    $tplUpdated = $templateUpdatedAt ?: date('Y-m-d H:i:s');
    $st->bind_param('iissis', $user_id, $dept_id, $relPath, $certNumber, $templateId, $tplUpdated);
    if (!$st->execute()) {
        $st->close();
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to record certificate']);
        exit;
    }
    $st->close();
}

echo json_encode([
    'ok' => true,
    'already' => false,
    'regenerated' => false,
    'file_path' => $relPath,
    'download_url' => '../' . ltrim($relPath, '/'),
    'cert_number' => $certNumber,
    'version' => 1,
    'template_id' => $templateId,
    'template_updated_at' => $tplUpdated,
    'progress' => $progress
]);
