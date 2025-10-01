<?php
require_once __DIR__.'/../db.php';
header('Content-Type: application/json');
$keys = ['SMTP_HOST','SMTP_USER','SMTP_PORT','SMTP_SECURE','MAIL_HOST','APP_ENV'];
$out = [];
foreach($keys as $k){ $out[$k]=getenv($k)===false?null:getenv($k); }
echo json_encode(['ok'=>true,'vars'=>$out]);
