<?php
// Migration helper for certificates issuance tracking
// Admin only.
session_start();
require_once __DIR__ . '/../db.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') { http_response_code(403); echo 'Admin only'; exit; }

if (isset($conn) && $conn instanceof mysqli) $db = $conn; elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli; else die('DB unavailable');

header('Content-Type: text/html; charset=utf-8');
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}

$created = [];$errors=[];
$sql = "CREATE TABLE IF NOT EXISTS certificates_issued (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  cert_type VARCHAR(32) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_type (user_id, cert_type),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
if ($db->query($sql)) $created[]='certificates_issued'; else $errors[]='certificates_issued: '.$db->error;

echo '<!doctype html><html><head><title>Certificates Migration</title><meta name="viewport" content="width=device-width,initial-scale=1">';
echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="p-4">';
echo '<h4>Certificates Migration</h4>';
if ($created) echo '<div class="alert alert-success">Ensured tables: '.h(implode(', ',$created)).'</div>';
if ($errors) { echo '<div class="alert alert-danger"><ul>'; foreach ($errors as $e) echo '<li>'.h($e).'</li>'; echo '</ul></div>'; }

// Show structure
if ($res = $db->query('SHOW CREATE TABLE certificates_issued')) { $row = $res->fetch_assoc(); echo '<pre class="small">'.h($row['Create Table']).'</pre>'; }
echo '<a class="btn btn-secondary" href="Admin_dashboard.php">Back</a>';
echo '</body></html>';
