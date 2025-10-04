<?php
session_start();
require_once __DIR__ . '/../db.php';

function is_ajax() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

// normalize DB connection
if (isset($conn) && $conn instanceof mysqli) {
    $db = $conn;
} elseif (isset($mysqli) && $mysqli instanceof mysqli) {
    $db = $mysqli;
} else {
    $_SESSION['toast'] = ['type' => 'error', 'msg' => 'Database unavailable.'];
    // For AJAX, return JSON; otherwise, redirect back to login
    if (is_ajax()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false,'message'=>'Database unavailable.']);
        exit;
    }
    header('Location: ../Credentials/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../Credentials/login.php');
    exit;
}

// input
$email = strtolower(trim($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($email === '' || $password === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['toast'] = ['type' => 'error', 'msg' => 'Invalid email or password.'];
    if (is_ajax()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false,'message'=>'Invalid email or password.']);
        exit;
    }
    header('Location: ../Credentials/login.php');
    exit;
}

// fetch user (prepared => SQL injection safe)
$sql = "SELECT id, name, email, password_hash FROM users WHERE email = ? LIMIT 1";
if (!$stmt = $db->prepare($sql)) {
    $_SESSION['toast'] = ['type' => 'error', 'msg' => 'Server error.'];
    if (is_ajax()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false,'message'=>'Server error.']);
        exit;
    }
    header('Location: ../Credentials/login.php');
    exit;
}
$stmt->bind_param('s', $email);
$stmt->execute();
$res = $stmt->get_result();
$user = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$user || !password_verify($password, $user['password_hash'])) {
    $_SESSION['toast'] = ['type' => 'error', 'msg' => 'Invalid email or password.'];
    if (is_ajax()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false,'message'=>'Invalid email or password.']);
        exit;
    }
    header('Location: ../Credentials/login.php');
    exit;
}

// set session
$_SESSION['user_id'] = (int)$user['id'];
$_SESSION['user_email'] = $user['email'];
$_SESSION['user_name'] = $user['name'] ?? '';
$_SESSION['user_role'] = $_SESSION['user_role'] ?? 'delegate';

// increment BOTH eligibility flags (cap to 1 so they behave as booleans)
if ($upd = $db->prepare("
    UPDATE users
    SET eligible_certificate_appearance = LEAST(eligible_certificate_appearance + 1, 1),
        eligible_certificate_attendance = LEAST(eligible_certificate_attendance + 1, 1),
        eligibility_updated_at = NOW()
    WHERE id = ?
")) {
    $uid = (int)$user['id'];
    $upd->bind_param('i', $uid);
    $upd->execute();
    $upd->close();
}

// Success: choose redirect target (intended_url has precedence if set and looks internal)
$target = '../dashboard/dashboard.php';
if (!empty($_SESSION['intended_url'])) {
    $raw = (string)$_SESSION['intended_url'];
    // Basic safety: only allow relative URLs (no scheme/host)
    if (!preg_match('#^https?://#i', $raw)) {
        $target = $raw;
    }
    unset($_SESSION['intended_url']);
}
$_SESSION['toast'] = ['type' => 'success', 'msg' => 'Login successful. Redirecting…'];
session_write_close();

if (is_ajax()) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success'=>true,'redirect'=>$target]);
    exit;
}

header('Location: ' . $target, true, 303);
exit;
