<?php
session_start();
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json; charset=utf-8');

function fail($msg,$code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }

// Auth
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') fail('Unauthorized', 401);

// CSRF
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfHeader)) fail('Invalid CSRF token', 403);

// DB
if (isset($conn) && $conn instanceof mysqli) { $db = $conn; }
elseif (isset($mysqli) && $mysqli instanceof mysqli) { $db = $mysqli; }
else fail('DB unavailable', 500);

$raw = file_get_contents('php://input');
$data = json_decode($raw,true);
if(!is_array($data)) fail('Invalid payload');
$id = (int)($data['id'] ?? 0);
if($id<=0) fail('Invalid user id');

// Prevent self-delete of currently logged in admin account if it's a shared users table
if(isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $id) {
    fail('You cannot delete your own account while logged in.', 400);
}

// Delete user (soft delete alternative could be implemented)
if($st=$db->prepare('DELETE FROM users WHERE id=? LIMIT 1')){
    $st->bind_param('i',$id);
    if(!$st->execute()) { $err=$st->error; $st->close(); fail('Delete failed: '.$err, 500); }
    $affected = $st->affected_rows; $st->close();
    echo json_encode(['ok'=>true,'deleted'=>$affected]);
    exit;
}

fail('Query error', 500);
?>
