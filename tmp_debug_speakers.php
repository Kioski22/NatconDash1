<?php
require __DIR__ . '/db.php';
$db = isset($conn) ? $conn : (isset($mysqli) ? $mysqli : null);
if (!($db instanceof mysqli)) {
    fwrite(STDERR, "No DB connection.\n");
    exit(1);
}
$deptId = (int)($argv[1] ?? 0);
if ($deptId <= 0) {
    fwrite(STDERR, "Usage: php tmp_debug_speakers.php <department_id>\n");
    exit(1);
}
$sql = "SELECT id,name,program_type,technical_department_id,room_id FROM speakers WHERE technical_department_id=? ORDER BY id";
if (!$stmt = $db->prepare($sql)) {
    fwrite(STDERR, "Prepare failed: {$db->error}\n");
    exit(1);
}
$stmt->bind_param('i', $deptId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE), PHP_EOL;
}
$stmt->close();
