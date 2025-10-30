<?php
// Export attendance for a given day as CSV (admin-only), sourced from the main `attendance` table.
// Hardened to avoid invalid responses: robust error logging, clear early failures,
// and only send CSV headers after validation.

// Robust error logging (to temp file on server)
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@ini_set('error_reporting', (string)E_ALL);
$__export_log = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'attendance_export.log';
@ini_set('error_log', $__export_log);

set_error_handler(function ($errno, $errstr, $errfile, $errline) use ($__export_log) {
  // Respect error_reporting level
  if (!(error_reporting() & $errno)) { return false; }
  error_log(date('c') . " [PHP $errno] $errstr in $errfile:$errline");
  if (!headers_sent()) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
  }
  echo "Server error";
  return true;
});

set_exception_handler(function ($ex) use ($__export_log) {
  error_log(date('c') . ' [EXCEPTION] ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine());
  if (!headers_sent()) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
  }
  echo 'Server exception';
});

session_start();
require_once __DIR__ . '/../db.php';

// Normalize DB connection
if (isset($conn) && $conn instanceof mysqli)      { $db = $conn; }
elseif (isset($mysqli) && $mysqli instanceof mysqli) { $db = $mysqli; }
else {
  if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
  http_response_code(500); echo 'DB unavailable'; exit;
}

// Admin auth required
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
  if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
  http_response_code(403); echo 'Forbidden'; exit;
}

$day_id = isset($_GET['day_id']) ? (int)$_GET['day_id'] : 0;
if ($day_id <= 0) { if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8'); http_response_code(400); echo 'Invalid day'; exit; }

// Ensure attendance table with day_number exists
$hasAttendanceTable = false; if ($r=$db->query("SHOW TABLES LIKE 'attendance'")) { $hasAttendanceTable = $r->num_rows>0; $r->close(); }
$hasDayNumberCol = false; if ($hasAttendanceTable) { if ($r=$db->query("SHOW COLUMNS FROM attendance LIKE 'day_number'")) { $hasDayNumberCol = $r->num_rows>0; $r->close(); } }
if (!($hasAttendanceTable && $hasDayNumberCol)) {
  if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
  http_response_code(404); echo 'Attendance not configured'; exit;
}

// Fetch day label/date
$day_label = 'Day';
$r_day_date_pre = '';
if ($st = $db->prepare('SELECT label, day_date FROM function_room_days WHERE id=? LIMIT 1')) {
  $st->bind_param('i',$day_id);
  $st->execute();
  $st->bind_result($lab,$day_date_val);
  if ($st->fetch()) { $day_label=(string)$lab; $r_day_date_pre=(string)$day_date_val; }
  $st->close();
}

// Map day_id to attendance.day_number using chronological order of function_room_days
$dayIndex = null;
if ($rs=$db->query("SELECT id FROM function_room_days ORDER BY day_date ASC")) {
  $i=0; while($d=$rs->fetch_assoc()){ $i++; if((int)$d['id']===$day_id){ $dayIndex=$i; break; } }
  $rs->close();
}
if ($dayIndex===null) {
  if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
  http_response_code(404); echo 'Day not found'; exit;
}

// At this point, validation passed — send CSV headers now.
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.('attendance_day_'.$day_id.'_'.date('Ymd_His')).'.csv"');
header('Pragma: no-cache');
header('Expires: 0');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Best effort: clear any existing output buffers to avoid corrupting CSV
while (function_exists('ob_get_level') && ob_get_level() > 0) { @ob_end_clean(); }

$out = fopen('php://output', 'w');
// UTF-8 BOM for Excel
fwrite($out, "\xEF\xBB\xBF");

// Detect attendance columns dynamically
$lookupColumn = function(array $names) use ($db): ?string {
  foreach ($names as $name) {
    $q = $db->query("SHOW COLUMNS FROM attendance LIKE '".$db->real_escape_string($name)."'");
    if ($q && $q->num_rows>0) { $q->close(); return $name; }
    if ($q) $q->close();
  }
  return null;
};

$qualify = function(?string $name) {
  return $name ? ('a.`'.str_replace('`','``',$name).'`') : "''";
};

$createdCol = $lookupColumn(['created_at','created','submitted_at']);
$nameCol    = $lookupColumn(['attendee_name','name']);
$emailCol   = $lookupColumn(['attendee_email','email']);
$ipCol      = $lookupColumn(['ip_addr','ip']);
$uaCol      = $lookupColumn(['user_agent','ua']);
$idCol      = $lookupColumn(['id']);

$createdExpr = $qualify($createdCol);
$nameExpr    = $qualify($nameCol);
$emailExpr   = $qualify($emailCol);
$ipExpr      = $qualify($ipCol);
$uaExpr      = $qualify($uaCol);
$orderExpr   = $createdCol ? $createdExpr : ($idCol ? 'a.`'.str_replace('`','``',$idCol).'`' : '1');

// Determine additional user-input columns (beyond the base set) to include
$allCols = [];
if ($res = $db->query("SHOW COLUMNS FROM attendance")) {
  while ($c = $res->fetch_assoc()) { $allCols[] = (string)$c['Field']; }
  $res->close();
}
$baseCols = [];
if ($createdCol) $baseCols[] = $createdCol;
if ($nameCol)    $baseCols[] = $nameCol;
if ($emailCol)   $baseCols[] = $emailCol;
if ($ipCol)      $baseCols[] = $ipCol;
if ($uaCol)      $baseCols[] = $uaCol;

// Exclude internal columns and those already represented in baseCols
$exclude = array_merge(['id','day_number'], $baseCols);
$extraCols = [];
foreach ($allCols as $c) {
  if (!in_array($c, $exclude, true)) { $extraCols[] = $c; }
}

// Header row (attendance-only + all user-input columns)
$header = [
  'Day ID', 'Day Label', 'Day Date', 'Recorded At',
  'Attendee Name','Input Email','Account Email','IP Address','User Agent'
];
foreach ($extraCols as $cname) { $header[] = $cname; }
fputcsv($out, $header);

// Query attendance rows for the mapped day_number, including extra columns
$selectExtra = '';
if (!empty($extraCols)) {
  $selectExtra = ', '.implode(', ', array_map(function($c) {
    return 'a.`'.str_replace('`','``',$c).'`';
  }, $extraCols));
}

$sql = "SELECT ".$createdExpr." AS created_at,
               ".$nameExpr." AS attendee_name,
               ".$emailExpr." AS input_email,
               u.email AS account_email,
               ".$ipExpr." AS ip_addr,
               ".$uaExpr." AS user_agent".$selectExtra.
       " FROM attendance a
        LEFT JOIN users u ON u.id = a.user_id
        WHERE a.day_number = ".$dayIndex.
       " ORDER BY ".$orderExpr." ASC";

if ($rs = $db->query($sql)) {
  while ($rec = $rs->fetch_assoc()) {
    $row = [
      (int)$day_id,
      (string)$day_label,
      (string)$r_day_date_pre,
      (string)($rec['created_at'] ?? ''),
      (string)($rec['attendee_name'] ?? ''),
      (string)($rec['input_email'] ?? ''),
      (string)($rec['account_email'] ?? ''),
      (string)($rec['ip_addr'] ?? ''),
      (string)($rec['user_agent'] ?? ''),
    ];
    foreach ($extraCols as $cname) {
      $row[] = isset($rec[$cname]) ? (string)$rec[$cname] : '';
    }
    fputcsv($out, $row);
  }
  $rs->close();
}

fclose($out);
exit;
?>
