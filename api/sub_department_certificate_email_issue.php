<?php
// Email a previously generated Sub Program department certificate.
// POST JSON { department_id, regenerate_if_missing? }

header('Content-Type: application/json; charset=utf-8');
session_start();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

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

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$dept_id = isset($payload['department_id']) ? (int)$payload['department_id'] : 0;
if ($dept_id <= 0 && !empty($payload['speaker_id'])) {
    if ($st = $db->prepare('SELECT technical_department_id FROM speakers WHERE id=? LIMIT 1')) {
        $sid = (int)$payload['speaker_id'];
        $st->bind_param('i', $sid);
        $st->execute();
        $st->bind_result($dept); 
        if ($st->fetch()) {
            $dept_id = (int)$dept;
        }
        $st->close();
    }
}
if ($dept_id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid department']);
    exit;
}

$certificate = null;
if ($st = $db->prepare('SELECT c.file_path, c.generated_at, td.name FROM sub_department_certificates c JOIN technical_departments td ON td.id=c.technical_department_id WHERE c.user_id=? AND c.technical_department_id=? LIMIT 1')) {
    $st->bind_param('ii', $user_id, $dept_id);
    $st->execute();
    $st->bind_result($file_path, $generated_at, $dept_name);
    if ($st->fetch()) {
        $certificate = [
            'file_path' => $file_path,
            'generated_at' => $generated_at,
            'department' => $dept_name
        ];
    }
    $st->close();
}

if (!$certificate || empty($certificate['file_path'])) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Certificate not generated yet']);
    exit;
}

$abs = __DIR__ . '/../' . ltrim(str_replace('..', '', $certificate['file_path']), '/');
if (!is_file($abs)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Certificate file missing on server']);
    exit;
}

$email = '';
$displayName = 'Participant';
if ($st = $db->prepare('SELECT email, name FROM users WHERE id=? LIMIT 1')) {
    $st->bind_param('i', $user_id);
    $st->execute();
    $st->bind_result($email, $displayName);
    $st->fetch();
    $st->close();
}
if (!$email) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'User email unavailable']);
    exit;
}

$cfg = [
    'host' => getenv('MAIL_HOST') ?: getenv('SMTP_HOST') ?: '',
    'user' => getenv('MAIL_USERNAME') ?: getenv('SMTP_USER') ?: '',
    'pass' => getenv('MAIL_PASSWORD') ?: getenv('SMTP_PASS') ?: '',
    'port' => (int)(getenv('MAIL_PORT') ?: getenv('SMTP_PORT') ?: 587),
    'from_addr' => getenv('MAIL_FROM_ADDRESS') ?: getenv('SMTP_FROM') ?: 'noreply@example.com',
    'from_name' => getenv('MAIL_FROM_NAME') ?: getenv('SMTP_FROM_NAME') ?: 'Natcon Dashboard',
    'enc' => strtolower(getenv('MAIL_ENCRYPTION') ?: getenv('SMTP_SECURE') ?: 'tls'),
    'debug' => (int)(getenv('MAIL_DEBUG') ?: 0),
    'allow_alias' => (int)(getenv('MAIL_ALLOW_ALIAS') ?: getenv('SMTP_ALLOW_ALIAS') ?: 0)
];
if ($cfg['host'] === '' || $cfg['user'] === '' || $cfg['pass'] === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Mail server not configured']);
    exit;
}

$mail = new PHPMailer(true);
$debugBuffer = '';
$appEnv = strtolower(getenv('APP_ENV') ?: '');
$forceDebug = ($cfg['debug'] > 0) || $appEnv === 'local';

try {
    $mail->isSMTP();
    $mail->Host = $cfg['host'];
    $mail->SMTPAuth = true;
    $mail->Username = $cfg['user'];
    $mail->Password = $cfg['pass'];

    if (in_array($cfg['enc'], ['tls', 'starttls'], true)) {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } elseif (in_array($cfg['enc'], ['ssl', 'smtps'], true)) {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        if ($cfg['port'] === 587) $cfg['port'] = 465;
    } elseif ($cfg['enc'] === 'none' || $cfg['enc'] === 'off') {
        $mail->SMTPSecure = false;
    } else {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    }
    $mail->Port = $cfg['port'];

    if ($forceDebug) {
        $mail->SMTPDebug = 2;
        $mail->Debugoutput = function ($str, $level) use (&$debugBuffer) {
            $debugBuffer .= "[L$level] $str\n";
        };
    }

    $authDomain = strtolower(substr(strrchr($cfg['user'], '@') ?: '', 1));
    $fromDomain = strtolower(substr(strrchr($cfg['from_addr'], '@') ?: '', 1));
    if ($cfg['host'] === 'smtp.gmail.com' && $authDomain && $fromDomain && $authDomain !== $fromDomain && !$cfg['allow_alias']) {
        $mail->setFrom($cfg['user'], $cfg['from_name'] . ' (via)');
        $mail->addReplyTo($cfg['from_addr'], $cfg['from_name']);
    } else {
        $mail->setFrom($cfg['from_addr'], $cfg['from_name']);
    }

    $mail->addAddress($email, $displayName ?: $email);
    $departmentName = $certificate['department'] ?: 'Technical Department';
    $mail->Subject = 'Your Sub Program Department Certificate';
    $mail->Body = "Dear $displayName,\n\nAttached is your Sub Program certificate for: $departmentName.\n\nThank you.";
    $mail->addAttachment($abs, basename($certificate['file_path']));

    $mail->send();
    $response = ['ok' => true];
    if ($forceDebug) {
        $cfgSafe = $cfg;
        unset($cfgSafe['pass']);
        $response['debug'] = $debugBuffer;
        $response['config'] = $cfgSafe;
    }
    echo json_encode($response);
} catch (Exception $e) {
    $err = $mail->ErrorInfo ?: $e->getMessage();
    error_log('Sub department cert email error: ' . $err);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to send email']);
}
