<?php
session_start();
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json; charset=utf-8');

function fail($msg, $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }

// Auth: admin only
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    fail('Unauthorized', 401);
}

// CSRF (header)
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfHeader)) {
    fail('Invalid CSRF token', 403);
}

// DB normalize
if (isset($conn) && $conn instanceof mysqli) { $db = $conn; }
elseif (isset($mysqli) && $mysqli instanceof mysqli) { $db = $mysqli; }
else { fail('DB unavailable', 500); }

$page = max(1, (int)($_GET['page'] ?? 1));
$per = min(200, max(1, (int)($_GET['per_page'] ?? 20)));
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

// total
$total = 0;
if ($where === '') {
    if ($r = $db->query('SELECT COUNT(*) FROM users')) { $total = (int)$r->fetch_row()[0]; }
} else {
    if ($st = $db->prepare('SELECT COUNT(*) FROM users '.$where)) {
        $st->bind_param($types, ...$params);
        $st->execute(); $st->bind_result($total); $st->fetch(); $st->close();
    }
}

$pages = (int)ceil(($total ?: 1) / $per);
$offset = ($page - 1) * $per;

// data
$data = [];
if ($where === '') {
    $sql = 'SELECT id,name,email,eligible_certificate_attendance,eligible_certificate_appearance,eligible_cpd_certificate,eligibility_updated_at FROM users ORDER BY id DESC LIMIT ? OFFSET ?';
    if ($st = $db->prepare($sql)) {
        $st->bind_param('ii', $per, $offset);
        $st->execute(); $res = $st->get_result();
        while ($row = $res->fetch_assoc()) $data[] = $row;
        $st->close();
    }
} else {
    $sql = 'SELECT id,name,email,eligible_certificate_attendance,eligible_certificate_appearance,eligible_cpd_certificate,eligibility_updated_at FROM users '.$where.' ORDER BY id DESC LIMIT ? OFFSET ?';
    if ($st = $db->prepare($sql)) {
        $types2 = $types . 'ii';
        $params2 = array_merge($params, [$per, $offset]);
        $st->bind_param($types2, ...$params2);
        $st->execute(); $res = $st->get_result();
        while ($row = $res->fetch_assoc()) $data[] = $row;
        $st->close();
    }
}

echo json_encode(['ok'=>true,'data'=>$data,'total'=>$total,'page'=>$page,'pages'=>$pages]);
exit;
?>
