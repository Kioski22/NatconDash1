<?php
// Adds font configuration columns to certificates table if missing.
session_start();
require_once __DIR__ . '/../db.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role']!=='admin') { http_response_code(403); echo "Forbidden"; exit; }

if (isset($conn) && $conn instanceof mysqli) $db = $conn; elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli; else { die('DB unavailable'); }

$needed = [
  'font_awardee_family' => "ADD COLUMN font_awardee_family VARCHAR(60) DEFAULT 'Helvetica'",
  'font_awardee_size_px' => "ADD COLUMN font_awardee_size_px INT DEFAULT 46",
  'font_awardee_color' => "ADD COLUMN font_awardee_color VARCHAR(15) DEFAULT '#000000'",
  'font_seminar_family' => "ADD COLUMN font_seminar_family VARCHAR(60) DEFAULT 'Helvetica'",
  'font_seminar_size_px' => "ADD COLUMN font_seminar_size_px INT DEFAULT 24",
  'font_seminar_color' => "ADD COLUMN font_seminar_color VARCHAR(15) DEFAULT '#000000'",
  'font_number_family' => "ADD COLUMN font_number_family VARCHAR(60) DEFAULT 'Helvetica'",
  'font_number_size_px' => "ADD COLUMN font_number_size_px INT DEFAULT 16",
  'font_number_color' => "ADD COLUMN font_number_color VARCHAR(15) DEFAULT '#000000'"
];

$existing = [];
$res = $db->query("SHOW COLUMNS FROM certificates");
if ($res) { while($r=$res->fetch_assoc()){ $existing[$r['Field']]=true; } $res->close(); }

$executed = [];
foreach ($needed as $col=>$ddl) {
  if (!isset($existing[$col])) {
    $sql = "ALTER TABLE certificates $ddl";
    if ($db->query($sql)) { $executed[] = "Added $col"; } else { $executed[] = "Failed $col: ".$db->error; }
  } else {
    $executed[] = "$col exists";
  }
}
header('Content-Type: application/json');
echo json_encode(['ok'=>true,'changes'=>$executed]);