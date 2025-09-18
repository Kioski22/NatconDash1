<?php

session_start();
require_once __DIR__ . '/../db.php';

if (isset($conn) && $conn instanceof mysqli) { $db = $conn; }
elseif (isset($mysqli) && $mysqli instanceof mysqli) { $db = $mysqli; }
else { http_response_code(500); echo json_encode(['error'=>'DB unavailable']); exit; }

// helper to detect AJAX
function is_ajax() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

$speaker_id = isset($_POST['speaker_id']) ? (int)$_POST['speaker_id'] : 0;
$name = trim($_POST['attendee_name'] ?? '');
$email = trim($_POST['attendee_email'] ?? '');

$referer = $_SERVER['HTTP_REFERER'] ?? ('../dashboard/speaker_form.php?id=' . urlencode((string)$speaker_id));

if ($speaker_id <= 0 || $name === '') {
    $msg = 'Invalid attendance data.';
    if (is_ajax()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $msg]);
        exit;
    }
    $_SESSION['att_msg'] = ['error' => $msg];
    header('Location: ' . $referer);
    exit;
}

$stmt = $db->prepare("INSERT INTO attendance (speaker_id, attendee_name, attendee_email) VALUES (?, ?, ?)");
if (!$stmt) {
    $msg = 'DB prepare failed: ' . $db->error;
    if (is_ajax()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $msg]);
        exit;
    }
    $_SESSION['att_msg'] = ['error' => $msg];
    header('Location: ' . $referer);
    exit;
}

$stmt->bind_param('iss', $speaker_id, $name, $email);
$ok = $stmt->execute();
$err = $stmt->error;
$stmt->close();

if ($ok) {
    if (is_ajax()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true]);
        exit;
    }
    $_SESSION['att_msg'] = ['success' => 'Attendance recorded.'];
    header('Location: ' . $referer);
    exit;
}

// failure
$msg = 'Insert failed: ' . $err;
if (is_ajax()) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $msg]);
    exit;
}
$_SESSION['att_msg'] = ['error' => $msg];
header('Location: ' . $referer);
exit;