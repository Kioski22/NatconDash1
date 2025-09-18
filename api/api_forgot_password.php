<?php
session_start();
ob_start();
ini_set('display_errors','0');     // never echo PHP warnings
ini_set('log_errors','1');         // log to php_error_log

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

header('Content-Type: application/json; charset=utf-8');

// Central responders
function respond(array $p){
  while (ob_get_level()) ob_end_clean();     // hard clean all buffers
  header('Content-Type: application/json; charset=utf-8', true);
  echo json_encode($p);
  exit;
}

// Log but don’t echo PHP errors
set_error_handler(function($sev,$msg,$file,$line){
  error_log("PHP error [$sev] $msg @ $file:$line");
  return true; // handled
});
set_exception_handler(function($ex){
  error_log("Uncaught exception: " . $ex->getMessage());
  respond(['ok'=>false,'message'=>'Server error']);
});
register_shutdown_function(function(){
  $e = error_get_last();
  if ($e && !headers_sent()) {
    error_log("Fatal shutdown: {$e['message']} @ {$e['file']}:{$e['line']}");
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8', true);
    echo json_encode(['ok'=>false,'message'=>'Server error']);
  }
});

// Load .env
if (class_exists(\Dotenv\Dotenv::class)) {
  \Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

// fallback helper: prefer .env, else use hard-coded default
function envd(string $key, $default = null) {
  $v = $_ENV[$key] ?? null;
  return isset($v) && $v !== '' ? $v : $default;
}

// Hard-coded defaults for local testing ONLY
$APP_ENV   = envd('APP_ENV', 'local');
$MAIL_DEBUG= (int)envd('MAIL_DEBUG', 1);

// Normalize DB connection
if (isset($conn) && $conn instanceof mysqli)      $db = $conn;
elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli;
else respond(['ok'=>false,'message'=>'DB unavailable']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['ok'=>false,'message'=>'Invalid method']);

// CSRF
$token = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$token)) {
  respond(['ok'=>false,'message'=>'Invalid token']);
}

$action = $_POST['action'] ?? '';
$email  = strtolower(trim($_POST['email'] ?? ''));

if ($action === 'request') {
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['ok'=>true]);
  if (!isset($_SESSION['fp_last'])) $_SESSION['fp_last'] = 0;
  if ((time() - (int)$_SESSION['fp_last']) < 15) respond(['ok'=>true]);

  // lookup user
  $user = null;
  if ($st = $db->prepare('SELECT id, name FROM users WHERE email = ? LIMIT 1')) {
    $st->bind_param('s', $email);
    $st->execute();
    $user = $st->get_result()->fetch_assoc();
    $st->close();
  }

  if ($user) {
    // upsert OTP
    $otp     = random_int(100000, 999999);
    $otpHash = password_hash((string)$otp, PASSWORD_DEFAULT);
    $expires = (new DateTime('+10 minutes'))->format('Y-m-d H:i:s');

    $sql = "INSERT INTO password_resets (user_id,email,otp_hash,expires_at,attempts,last_sent_at)
            VALUES (?,?,?,?,0,NOW())
            ON DUPLICATE KEY UPDATE otp_hash=VALUES(otp_hash), expires_at=VALUES(expires_at), attempts=0, last_sent_at=NOW()";
    if ($ins = $db->prepare($sql)) {
      $uid = (int)$user['id'];
      $ins->bind_param('isss', $uid, $email, $otpHash, $expires);
      $ins->execute();
      $ins->close();
    }

    // send mail (debug to error_log only)
    try {
      // Use .env or hard-coded defaults
      $host  = envd('SMTP_HOST',   'smtp.gmail.com');
      $port  = (int)envd('SMTP_PORT',   587);
      $sec   = envd('SMTP_SECURE', 'tls');
      $u     = envd('SMTP_USER',   'icto@psmeinc.org.ph');
      $p     = envd('SMTP_PASS',   'jsul vibg pvtp zkjf');
      $fromN = envd('SMTP_FROM_NAME', 'NatCon Support');

      // Gmail usually enforces From = authenticated account.
      // Use Reply-To for your desired address.
      $desiredFrom = envd('SMTP_FROM', 'info@psmeinc.org.ph');
      $from  = $u;
      $reply = $desiredFrom;

      if (!$host || !$port || !$u || !$p) {
        error_log('OTP email skipped: missing SMTP env/defaults');
        if ($APP_ENV === 'local') {
          @mkdir(dirname(__DIR__) . '/storage/logs', 0777, true);
          @file_put_contents(dirname(__DIR__) . '/storage/logs/mail.log',
            sprintf("[%s] OTP for %s: %s\n", date('Y-m-d H:i:s'), $email, $otp), FILE_APPEND);
        }
      } else {
        $mail = new PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        if ($MAIL_DEBUG) { $mail->SMTPDebug = SMTP::DEBUG_SERVER; $mail->Debugoutput = 'error_log'; }

        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = true;
        $mail->Username = $u;
        $mail->Password = $p;
        $mail->Port = $port;
        $mail->SMTPSecure = ($sec === 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        if ($APP_ENV === 'local') {
          $mail->SMTPOptions = ['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false,'allow_self_signed'=>true]];
        }

        $mail->setFrom($from, $fromN);
        if ($reply) $mail->addReplyTo($reply, $fromN);
        $mail->addAddress($email, $user['name']);
        $mail->Subject = 'Your OTP Code';
        $mail->isHTML(false);
        $mail->Body = "Hello {$user['name']},\n\nYour OTP is: {$otp}\nIt expires in 10 minutes.\n\nIf you did not request this, ignore this email.";
        $mail->send();
      }
    } catch (Throwable $e) {
      error_log('OTP email failed: ' . $e->getMessage());
    }
  }

  $_SESSION['fp_last'] = time();
  respond(['ok'=>true]);
}

if ($action === 'reset') {
  $otp = trim($_POST['otp'] ?? '');
  $new = (string)($_POST['new_password'] ?? '');
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['ok'=>false,'message'=>'Invalid email']);
  if (!preg_match('/^\d{6}$/', $otp)) respond(['ok'=>false,'message'=>'Invalid OTP']);
  if (strlen($new) < 8) respond(['ok'=>false,'message'=>'Weak password']);

  $st = $db->prepare("SELECT user_id, otp_hash, expires_at, attempts FROM password_resets WHERE email = ? LIMIT 1");
  if (!$st) respond(['ok'=>false,'message'=>'Server error']);
  $st->bind_param('s', $email);
  $st->execute();
  $row = $st->get_result()->fetch_assoc();
  $st->close();

  if (!$row) respond(['ok'=>false,'message'=>'Invalid or expired OTP']);
  if ((int)$row['attempts'] >= 5) respond(['ok'=>false,'message'=>'Too many attempts. Try later.']);
  if ((new DateTime()) > new DateTime($row['expires_at'])) {
    if ($up = $db->prepare("UPDATE password_resets SET attempts = attempts + 1 WHERE email = ?")) {
      $up->bind_param('s', $email); $up->execute(); $up->close();
    }
    respond(['ok'=>false,'message'=>'Invalid or expired OTP']);
  }

  if (!password_verify($otp, $row['otp_hash'])) {
    if ($up = $db->prepare("UPDATE password_resets SET attempts = attempts + 1 WHERE email = ?")) {
      $up->bind_param('s', $email); $up->execute(); $up->close();
    }
    respond(['ok'=>false,'message'=>'Invalid OTP']);
  }

  $hash = password_hash($new, PASSWORD_DEFAULT);
  if ($uu = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?")) {
    $uid = (int)$row['user_id'];
    $uu->bind_param('si', $hash, $uid);
    $ok = $uu->execute();
    $uu->close();
    if ($ok) {
      if ($del = $db->prepare("DELETE FROM password_resets WHERE email = ?")) {
        $del->bind_param('s', $email); $del->execute(); $del->close();
      }
      respond(['ok'=>true]);
    }
  }
  respond(['ok'=>false,'message'=>'Failed to update password']);
}

respond(['ok'=>false,'message'=>'Unknown action']);
// no closing PHP tag