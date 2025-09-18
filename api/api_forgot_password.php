<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../vendor/autoload.php';
header('Content-Type: application/json; charset=utf-8');

// load .env safely
if (class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

// normalize DB
if (isset($conn) && $conn instanceof mysqli)      $db = $conn;
elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli;
else { echo json_encode(['ok'=>false,'message'=>'DB unavailable']); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['ok'=>false,'message'=>'Invalid method']); exit; }

// CSRF
$token = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$token)) {
    echo json_encode(['ok'=>false,'message'=>'Invalid token']); exit;
}

$action = $_POST['action'] ?? '';
$email = strtolower(trim($_POST['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['ok'=>false,'message'=>'Invalid email']); exit; }

date_default_timezone_set('Asia/Manila');

// Rate limit per session
if (!isset($_SESSION['fp_last'])) $_SESSION['fp_last'] = 0;
if ($action === 'request' && (time() - (int)$_SESSION['fp_last']) < 15) {
    echo json_encode(['ok'=>true]); exit; // silent success
}

if ($action === 'request') {
    // Always respond success (no user enumeration)
    $stmt = $db->prepare('SELECT id, name FROM users WHERE email = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user) {
            // Create/reset OTP
            $otp = random_int(100000, 999999);
            $hash = password_hash((string)$otp, PASSWORD_DEFAULT);
            $expires = (new DateTime('+10 minutes'))->format('Y-m-d H:i:s');

            $sql = "INSERT INTO password_resets (user_id, email, otp_hash, expires_at, attempts, last_sent_at)
                    VALUES (?, ?, ?, ?, 0, NOW())
                    ON DUPLICATE KEY UPDATE otp_hash=VALUES(otp_hash), expires_at=VALUES(expires_at), attempts=0, last_sent_at=NOW()";
            if ($ins = $db->prepare($sql)) {
                $uid = (int)$user['id'];
                $ins->bind_param('isss', $uid, $email, $hash, $expires);
                $ins->execute();
                $ins->close();
            }

            // Send email via PHPMailer SMTP
            try {
                $host   = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
                $port   = (int)($_ENV['SMTP_PORT'] ?? 587);
                $secure = ($_ENV['SMTP_SECURE'] ?? 'tls');
                $uname  = $_ENV['SMTP_USER'] ?? '';
                $pass   = $_ENV['SMTP_PASS'] ?? '';
                $from   = $_ENV['SMTP_FROM'] ?? $uname;
                $fromN  = $_ENV['SMTP_FROM_NAME'] ?? 'NatCon Support';

                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $host;
                $mail->SMTPAuth = true;
                $mail->Username = $uname;
                $mail->Password = $pass;
                $mail->Port = $port;
                $mail->SMTPSecure = ($secure === 'ssl')
                    ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
                    : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

                $mail->setFrom($from ?: $uname, $fromN);
                $mail->addAddress($email, $user['name']);
                $mail->Subject = 'Your OTP Code';
                $mail->Body = "Hello {$user['name']},\n\nYour OTP is: {$otp}\nIt expires in 10 minutes.\n\nIf you did not request this, ignore this email.";

                $mail->send();
            } catch (Throwable $e) {
                error_log('OTP email failed: ' . $e->getMessage());
                // Still return generic success
            }
        }
    }

    $_SESSION['fp_last'] = time();
    echo json_encode(['ok'=>true]);
    exit;
}

if ($action === 'reset') {
    $otp = trim($_POST['otp'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    if (!preg_match('/^\d{6}$/', $otp)) { echo json_encode(['ok'=>false,'message'=>'Invalid OTP']); exit; }
    if (strlen($new) < 8) { echo json_encode(['ok'=>false,'message'=>'Weak password']); exit; }

    $stmt = $db->prepare("SELECT pr.user_id, pr.otp_hash, pr.expires_at, pr.attempts
                          FROM password_resets pr
                          WHERE pr.email = ? LIMIT 1");
    if (!$stmt) { echo json_encode(['ok'=>false,'message'=>'Server error']); exit; }
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) { echo json_encode(['ok'=>false,'message'=>'Invalid or expired OTP']); exit; }
    if ((int)$row['attempts'] >= 5) { echo json_encode(['ok'=>false,'message'=>'Too many attempts. Try later.']); exit; }
    if ((new DateTime()) > new DateTime($row['expires_at'])) {
        if ($up = $db->prepare("UPDATE password_resets SET attempts = attempts + 1 WHERE email = ?")) {
            $up->bind_param('s', $email); $up->execute(); $up->close();
        }
        echo json_encode(['ok'=>false,'message'=>'Invalid or expired OTP']); exit;
    }

    if (!password_verify($otp, $row['otp_hash'])) {
        if ($up = $db->prepare("UPDATE password_resets SET attempts = attempts + 1 WHERE email = ?")) {
            $up->bind_param('s', $email); $up->execute(); $up->close();
        }
        echo json_encode(['ok'=>false,'message'=>'Invalid OTP']); exit;
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
            echo json_encode(['ok'=>true]); exit;
        }
    }

    echo json_encode(['ok'=>false,'message'=>'Failed to update password']); exit;
}

echo json_encode(['ok'=>false,'message'=>'Unknown action']);