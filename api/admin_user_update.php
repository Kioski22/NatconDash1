<?php
session_start();
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json; charset=utf-8');

function fail($msg, $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }

// admin auth
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') fail('Unauthorized', 401);

// csrf
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfHeader)) fail('Invalid CSRF token', 403);

// DB
if (isset($conn) && $conn instanceof mysqli) { $db = $conn; }
elseif (isset($mysqli) && $mysqli instanceof mysqli) { $db = $mysqli; }
else fail('DB unavailable', 500);

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) fail('Invalid payload');

$id = (int)($data['id'] ?? 0);
$name = trim((string)($data['name'] ?? ''));
$email = strtolower(trim((string)($data['email'] ?? '')));
$att = (int)($data['eligible_certificate_attendance'] ?? 0);
$app = (int)($data['eligible_certificate_appearance'] ?? 0);
$cpd = (int)($data['eligible_cpd_certificate'] ?? 0);
$password = (string)($data['password'] ?? '');
$note = trim((string)($data['eligibility_note'] ?? ''));

if ($id<=0 || $name==='' || $email==='') fail('Missing required fields');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Invalid email');

// ensure email unique per other users
if ($st=$db->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1')) {
    $st->bind_param('si',$email,$id); $st->execute(); $st->store_result();
    if ($st->num_rows>0) { $st->close(); fail('Email already in use'); }
    $st->close();
}

$parts = ['name=?','email=?','eligible_certificate_attendance=?','eligible_certificate_appearance=?','eligible_cpd_certificate=?','eligibility_updated_at=NOW()'];
$types = 'ssiii';
$vals = [$name,$email,$att,$app,$cpd];
if ($note !== '') { $parts[]='eligibility_note=?'; $types.='s'; $vals[]=$note; }
if ($password !== '') { $parts[]='password_hash=?'; $types.='s'; $vals[] = password_hash($password, PASSWORD_DEFAULT); }
$types .= 'i'; $vals[]=$id;

$sql = 'UPDATE users SET '.implode(',',$parts).' WHERE id = ? LIMIT 1';
if(!$st=$db->prepare($sql)) fail('Query error', 500);
$st->bind_param($types, ...$vals);
if(!$st->execute()) { $err=$st->error; $st->close(); fail('Update failed: '.$err, 500); }
$st->close();

echo json_encode(['ok'=>true]);
exit;
?>
