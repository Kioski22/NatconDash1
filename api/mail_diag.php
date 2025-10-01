<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php'; // loads dotenv

$keys = [
  'APP_ENV','MAIL_HOST','MAIL_PORT','MAIL_ENCRYPTION','MAIL_USERNAME','MAIL_PASSWORD',
  'MAIL_FROM_ADDRESS','MAIL_FROM_NAME','MAIL_DEBUG','MAIL_EXPOSE_ERRORS',
  'SMTP_HOST','SMTP_PORT','SMTP_SECURE','SMTP_USER','SMTP_PASS','SMTP_FROM','SMTP_FROM_NAME'
];
$env = [];
foreach ($keys as $k) {
    $v = getenv($k);
    if ($v === false) { $env[$k] = null; continue; }
    if (stripos($k,'PASS') !== false || stripos($k,'PASSWORD') !== false) {
        $len = strlen($v);
        if ($len <= 4) { $masked = str_repeat('*',$len); } else { $masked = substr($v,0,2) . str_repeat('*',$len-4) . substr($v,-2); }
        $env[$k] = $masked;    
    } else {
        $env[$k] = $v;
    }
}
$openssl = extension_loaded('openssl');
$timeOk = abs(time() - strtotime(gmdate('Y-m-d H:i:s'))) < 10; // naive clock sanity

echo json_encode([
  'ok'=>true,
  'env'=>$env,
  'openssl_loaded'=>$openssl,
  'php_version'=>PHP_VERSION,
  'cwd'=>getcwd(),
]);
