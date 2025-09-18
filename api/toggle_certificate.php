<?php

session_start();
require_once __DIR__ . '/../db.php';

// normalize DB connection
if (isset($conn) && $conn instanceof mysqli) { $db = $conn; }
elseif (isset($mysqli) && $mysqli instanceof mysqli) { $db = $mysqli; }
else { http_response_code(500); echo "DB unavailable"; exit; }

// require admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403); echo "Forbidden"; exit;
}

// CSRF check (simple)
$token = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$token)) {
    http_response_code(400); echo "Invalid CSRF token"; exit;
}

$enabled = (isset($_POST['cert_enabled']) && $_POST['cert_enabled'] == '1') ? '1' : '0';
$autoDate = trim($_POST['cert_auto_date'] ?? '');

// basic validation for date (allow empty)
if ($autoDate !== '') {
    $d = DateTime::createFromFormat('Y-m-d', $autoDate);
    if (!$d || $d->format('Y-m-d') !== $autoDate) {
        $_SESSION['admin_msg'] = ['error' => 'Invalid auto-enable date format.'];
        header('Location: ../admin/Admin_dashboard.php');
        exit;
    }
}

// upsert setting helper
$upsert = function($key, $value) use ($db) {
    $stmt = $db->prepare("INSERT INTO admin_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    if ($stmt) {
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
        $stmt->close();
    }
};

$upsert('cert_generation_enabled', $enabled);
$upsert('cert_auto_enable_date', $autoDate);

// feedback and redirect back to admin
$_SESSION['admin_msg'] = ['success' => 'Certificate settings updated.'];
header('Location: ../admin/Admin_dashboard.php');
exit;