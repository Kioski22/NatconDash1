<?php
session_start();
require_once __DIR__ . '/../db.php';

function fail_csv($msg, $code=400){
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg; exit;
}

// Auth: admin only
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    fail_csv('Unauthorized', 401);
}

// CSRF: allow token via query to support direct download
$csrfQuery = $_GET['csrf'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfQuery)) {
    fail_csv('Invalid CSRF token', 403);
}

// DB normalize
if (isset($conn) && $conn instanceof mysqli) { $db = $conn; }
elseif (isset($mysqli) && $mysqli instanceof mysqli) { $db = $mysqli; }
else { fail_csv('DB unavailable', 500); }

$q = trim((string)($_GET['q'] ?? ''));
$where = '';
$params = [];
$types = '';
if ($q !== '') {
    $where = 'WHERE (name LIKE ? OR email LIKE ?)';
    $like = '%' . $q . '%';
    $params = [$like, $like];
    $types = 'ss';
}

$filename = 'users_export_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// UTF-8 BOM for Excel compatibility
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');

$headers = [
    'ID','Name','Email',
    'Attendance Eligible','Appearance Eligible','CPD Eligible',
    'Eligibility Updated','Admin Note'
];
fputcsv($out, $headers);

$sql = 'SELECT id,name,email,eligible_certificate_attendance,eligible_certificate_appearance,eligible_cpd_certificate,eligibility_updated_at,eligibility_note FROM users ' . $where . ' ORDER BY id DESC';
if ($where === '') {
    if ($res = $db->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            fputcsv($out, [
                $row['id'],
                $row['name'],
                $row['email'],
                (int)$row['eligible_certificate_attendance'] === 1 ? 'Yes' : 'No',
                (int)$row['eligible_certificate_appearance'] === 1 ? 'Yes' : 'No',
                (int)$row['eligible_cpd_certificate'] === 1 ? 'Yes' : 'No',
                $row['eligibility_updated_at'] ?? '',
                $row['eligibility_note'] ?? ''
            ]);
        }
        $res->free();
    }
} else {
    if ($st = $db->prepare($sql)) {
        $st->bind_param($types, ...$params);
        $st->execute();
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            fputcsv($out, [
                $row['id'],
                $row['name'],
                $row['email'],
                (int)$row['eligible_certificate_attendance'] === 1 ? 'Yes' : 'No',
                (int)$row['eligible_certificate_appearance'] === 1 ? 'Yes' : 'No',
                (int)$row['eligible_cpd_certificate'] === 1 ? 'Yes' : 'No',
                $row['eligibility_updated_at'] ?? '',
                $row['eligibility_note'] ?? ''
            ]);
        }
        $st->close();
    }
}

fclose($out);
exit;
