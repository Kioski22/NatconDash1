<?php
session_start();
require_once __DIR__ . '/../db.php';

// normalize DB variable
if (isset($conn) && $conn instanceof mysqli) $db = $conn;
elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli;
else { http_response_code(500); echo 'DB unavailable'; exit; }

function back_to_speaker($sid, $msg) {
    $_SESSION['att_msg'] = $msg;
    header('Location: ../dashboard/speaker_form.php?id=' . (int)$sid);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

$speaker_id = isset($_POST['speaker_id']) ? (int)$_POST['speaker_id'] : 0;
$name = trim($_POST['attendee_name'] ?? '');
$emailRaw = trim($_POST['attendee_email'] ?? '');
$email = $emailRaw !== '' ? strtolower($emailRaw) : '';

if ($speaker_id <= 0) back_to_speaker(0, ['error' => 'Invalid speaker.']);
if ($name === '') back_to_speaker($speaker_id, ['error' => 'Name is required.']);

// Email now required
if ($email === '') {
    back_to_speaker($speaker_id, ['error' => 'Email is required.']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    back_to_speaker($speaker_id, ['error' => 'Invalid email address.']);
}

// If email provided, block duplicates by email+speaker_id
$stmt = $db->prepare('SELECT id FROM speaker_attendance WHERE speaker_id = ? AND attendee_email = ? LIMIT 1');
if ($stmt) {
    $stmt->bind_param('is', $speaker_id, $email);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->close();
        back_to_speaker($speaker_id, ['error' => 'Attendance already recorded for this email for this speaker.']);
    }
    $stmt->close();
}

// Insert attendance
$stmt = $db->prepare('INSERT INTO speaker_attendance (speaker_id, attendee_name, attendee_email, created_at, ip_addr, user_agent) VALUES (?, ?, ?, NOW(), ?, ?)');
if (!$stmt) {
    back_to_speaker($speaker_id, ['error' => 'DB error: ' . $db->error]);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$stmt->bind_param('issss', $speaker_id, $name, $email, $ip, $ua);

if ($stmt->execute()) {
    // Mark session fingerprint to prevent re-submission without email
    $_SESSION['attend_fp'][$speaker_id] = true;
    $_SESSION['attended_speakers'] = $_SESSION['attended_speakers'] ?? [];
    $_SESSION['attended_speakers'][(int)$_POST['speaker_id']] = 1;
    $_SESSION['att_msg'] = ['success' => 'Attendance recorded.'];
    header('Location: ../dashboard/speaker_form.php?id='.(int)$_POST['speaker_id']);
    exit;
} else {
    // If unique index blocks duplicates, show friendly message
    if ($db->errno == 1062) {
        back_to_speaker($speaker_id, ['error' => 'Attendance already recorded for this email for this speaker.']);
    }
    back_to_speaker($speaker_id, ['error' => 'Save failed: ' . $db->error]);
}