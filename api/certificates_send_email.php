<?php
// POST {type: 'attendance'|'appearance'|'cpd'}
// Sends the user's issued certificate as a PDF attachment to their email
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vendor/autoload.php';

if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Not authenticated']);
    exit;
}
$user_id = (int)$_SESSION['user_id'];
$type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (is_array($input) && isset($input['type'])) {
        $type = preg_replace('/[^a-z_]/i','',$input['type']);
    } elseif (isset($_POST['type'])) { // fallback for form-encoded submissions
        $type = preg_replace('/[^a-z_]/i','',$_POST['type']);
    }
}
if (!$type) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Missing type']);
    exit;
}

$db = isset($conn) && $conn instanceof mysqli ? $conn : (isset($mysqli) && $mysqli instanceof mysqli ? $mysqli : null);
if (!$db) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'DB unavailable']);
    exit;
}

// Validate type against allowed list (keeps consistent with generation endpoint)
$allowed = ['attendance','appearance','cpd'];
if (!in_array($type,$allowed,true)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Invalid certificate type']);
    exit;
}

// Get user email & display name (users table uses `name` not `full_name`)
$email = $full_name = null;
if ($st = $db->prepare('SELECT email, name AS full_name FROM users WHERE id=? LIMIT 1')) {
    $st->bind_param('i', $user_id);
    if ($st->execute()) {
        $st->bind_result($email, $full_name);
        $st->fetch();
    }
    $st->close();
} else {
    error_log('certificates_send_email user lookup prepare failed: '.$db->error);
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Server error looking up user']);
    exit;
}
if (!$email) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'No email found for user']);
    exit;
}

// Normalize legacy type aliases if any (extend as needed)
$typeMap = [
  'attendance' => 'attendance',
  'appearance' => 'appearance',
  'cpd' => 'cpd'
];
$lookupType = $typeMap[$type] ?? $type;

// Find issued certificate file (correct table: certificates_issued, column cert_type)
$file_path = null;
if ($st = $db->prepare('SELECT file_path FROM certificates_issued WHERE user_id=? AND cert_type=? AND file_path IS NOT NULL AND file_path != "" LIMIT 1')) {
    $st->bind_param('is', $user_id, $lookupType);
    if ($st->execute()) {
        $st->bind_result($file_path);
        $st->fetch();
    }
    $st->close();
} else {
    error_log('certificates_send_email prepare failed: '.$db->error);
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Server error preparing certificate lookup']);
    exit;
}

if (!$file_path || !file_exists(__DIR__.'/../'.$file_path)) {
    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'Certificate file not found or not yet generated']);
    exit;
}
$abs_path = realpath(__DIR__ . '/../' . $file_path);

// Send email with PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);
try {
    // Collect config with support for both MAIL_* and legacy SMTP_* variable names
    $get = fn($primary,$fallback='') => getenv($primary) ?: ($fallback ? getenv($fallback) : '');
    $cfg = [
        'host' => $get('MAIL_HOST','SMTP_HOST'),
        'user' => $get('MAIL_USERNAME','SMTP_USER'),
        'pass' => $get('MAIL_PASSWORD','SMTP_PASS'),
        'port' => (int)($get('MAIL_PORT','SMTP_PORT') ?: 587),
        'from_addr' => $get('MAIL_FROM_ADDRESS','SMTP_FROM') ?: 'noreply@example.com',
        'from_name' => $get('MAIL_FROM_NAME','SMTP_FROM_NAME') ?: 'Natcon Dashboard',
        'enc'  => strtolower($get('MAIL_ENCRYPTION','SMTP_SECURE') ?: 'tls'),
        'debug' => (int)(getenv('MAIL_DEBUG') ?: 0),
        'allow_alias' => (int)(getenv('MAIL_ALLOW_ALIAS') ?: getenv('SMTP_ALLOW_ALIAS') ?: 0),
    ];

    $appEnv = strtolower(getenv('APP_ENV') ?: '');
    $forceDebug = ($cfg['debug'] > 0) || $appEnv === 'local' || isset($_GET['diag']);

    // Basic validation of critical fields
    if ($cfg['host'] === '' || $cfg['user'] === '' || $cfg['pass'] === '') {
        $missing = [];
        if ($cfg['host'] === '') $missing[] = 'host (MAIL_HOST or SMTP_HOST)';
        if ($cfg['user'] === '') $missing[] = 'username (MAIL_USERNAME or SMTP_USER)';
        if ($cfg['pass'] === '') $missing[] = 'password (MAIL_PASSWORD or SMTP_PASS)';
        http_response_code(500);
        echo json_encode([
            'ok'=>false,
            'error'=>'Mail server not configured: missing '.implode(', ',$missing),
            'hint'=>'Set variables in .env e.g. SMTP_HOST, SMTP_USER, SMTP_PASS (or MAIL_HOST, MAIL_USERNAME, MAIL_PASSWORD)'
        ]);
        return; // stop before PHPMailer tries
    }

    $mail->isSMTP();
    $mail->Host = $cfg['host'];
    $mail->SMTPAuth = true;
    $mail->Username = $cfg['user'];
    $mail->Password = $cfg['pass'];
    // Encryption mapping
    if (in_array($cfg['enc'], ['tls','starttls'], true)) {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } elseif (in_array($cfg['enc'], ['ssl','smtps'], true)) {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        if ($cfg['port'] === 587) { $cfg['port'] = 465; }
    } elseif ($cfg['enc'] === 'none' || $cfg['enc'] === 'off') {
        $mail->SMTPSecure = false; // no encryption (not recommended)
    } else { // default
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    }
    $mail->Port = $cfg['port'];

    $debugBuffer = '';
    if ($forceDebug) {
        $mail->SMTPDebug = 2; // client + server messages
        $mail->Debugoutput = function($str,$level) use (&$debugBuffer) { $debugBuffer .= "[L$level] $str\n"; };
    }

    // If using Gmail (smtp.gmail.com) and from domain differs from auth user domain, fallback unless alias allowed
    $authDomain = strtolower(substr(strrchr($cfg['user'],'@') ?: '',1));
    $fromDomain = strtolower(substr(strrchr($cfg['from_addr'],'@') ?: '',1));
    if ($cfg['host'] === 'smtp.gmail.com' && $authDomain && $fromDomain && $authDomain !== $fromDomain && !$cfg['allow_alias']) {
        // Keep original as reply-to, but send from authenticated account to avoid rejection
        $mail->setFrom($cfg['user'], $cfg['from_name'].' (via)');
        $mail->addReplyTo($cfg['from_addr'], $cfg['from_name']);
    } else {
        $mail->setFrom($cfg['from_addr'], $cfg['from_name']);
    }
    $displayName = trim((string)$full_name);
    if ($displayName === '') $displayName = $email;
    $mail->addAddress($email, $displayName);
    $mail->Subject = 'Your Certificate: ' . ucfirst($type);
    $mail->Body = "Dear $displayName,\n\nAttached is your $type certificate from the 73rd PSME National Convention.\n\nThank you.";
    $mail->addAttachment($abs_path, basename($file_path));
    $mail->send();
    $resp = ['ok'=>true];
    if ($forceDebug) {
        $cfgSafe = $cfg; unset($cfgSafe['pass']);
        $cfgSafe['env'] = $appEnv;
        $resp['debug'] = $debugBuffer;
        $resp['config'] = $cfgSafe;
    }
    echo json_encode($resp);
} catch (Exception $e) {
    $err = $mail->ErrorInfo ?: $e->getMessage();
    error_log('Mail error: ' . $err);
    // Classify common SMTP issues
    $classification = 'unknown';
    $lcErr = strtolower($err);
    if (str_contains($lcErr,'could not authenticate') || str_contains($lcErr,'authentication failed')) $classification='auth_failed';
    elseif (str_contains($lcErr,'failed to connect') || str_contains($lcErr,'connection refused')) $classification='connect_failed';
    elseif (str_contains($lcErr,'certificate verify failed')) $classification='tls_cert_verify_failed';
    elseif (str_contains($lcErr,'invalid address')) $classification='invalid_address';
    elseif (str_contains($lcErr,'data not accepted')) $classification='data_rejected';
    elseif (str_contains($lcErr,'password') && str_contains($lcErr,'application-specific')) $classification='gmail_app_password_required';
    elseif (str_contains($lcErr,'535') && str_contains($lcErr,'gmail')) $classification='gmail_auth_535';
    elseif (str_contains($lcErr,'browser') && str_contains($lcErr,'log in')) $classification='gmail_web_login_required';

    $hints = [
      'auth_failed' => 'Check SMTP_USER/SMTP_PASS (for Gmail use a valid 16-char app password with 2FA enabled).',
      'connect_failed' => 'Verify host/port, firewall, and that openssl extension is enabled for TLS.',
      'tls_cert_verify_failed' => 'Server certificate issue. Try ssl on port 465 or ensure local time is correct.',
      'invalid_address' => 'FROM or TO address rejected by server. Ensure the authenticated account can send as FROM.',
      'data_rejected' => 'Content rejected (SPF/DKIM/DMARC). Ensure domain DNS records are correct.',
      'gmail_app_password_required' => 'Create a Google App Password and use it as SMTP_PASS.',
    'gmail_auth_535' => 'Gmail 535 error. Double-check app password and that account has not blocked sign-in.',
    'gmail_web_login_required' => 'Log into the Gmail account via web to clear a security check, then retry.',
      'unknown' => 'Enable MAIL_EXPOSE_ERRORS=1 for raw error; verify SMTP settings.'
    ];

    $payload = [
        'ok'=>false,
        'error'=>'Failed to send email.',
        'classification'=>$classification,
        'hint'=>$hints[$classification] ?? 'See logs.'
    ];
    if ($forceDebug) {
        $cfgSafe = $cfg; unset($cfgSafe['pass']);
        $cfgSafe['env'] = $appEnv;
        $payload['smtp_debug'] = $debugBuffer;
        $payload['config'] = $cfgSafe;
    }
    if ($forceDebug || (int)(getenv('MAIL_EXPOSE_ERRORS') ?: 0) === 1) { // expose in local/diag
        $payload['smtp_error'] = $err;
    }
    http_response_code(500);
    echo json_encode($payload);
}
