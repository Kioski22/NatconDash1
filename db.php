<?php
// Central DB connection using environment variables.
// Supports local development (.env via phpdotenv) and AWS Elastic Beanstalk environment properties.

// Attempt to load Composer autoload if not already loaded
if (!class_exists('Dotenv\\Dotenv')) {
  $autoload = __DIR__ . '/vendor/autoload.php';
  if (file_exists($autoload)) require_once $autoload; // in case included before autoload
}

// Load .env for local dev (ignore failures in production)
if (class_exists('Dotenv\\Dotenv')) {
  try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->safeLoad();
  } catch (Throwable $e) {
    // swallow; environment variables may already be present
  }
}

// Fallback lightweight .env parser if phpdotenv not installed or values still missing
if (!getenv('DB_HOST') && !getenv('SMTP_HOST') && !getenv('MAIL_HOST')) {
  $envPath = __DIR__ . '/.env';
  if (is_file($envPath) && is_readable($envPath)) {
    $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines !== false) {
      foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || str_starts_with($line,';')) continue;
        if (!preg_match('/^[A-Z0-9_]+=/i',$line)) continue;
        [$k,$v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if ($v !== '' && ($v[0]==="'" || $v[0]=='"')) {
          $q = $v[0];
          if (str_ends_with($v,$q)) { $v = substr($v,1,-1); }
        }
        // basic unescape for common sequences
        $v = str_replace(['\\n','\\r'], ["\n","\r"], $v);
        if ($k !== '') {
          putenv($k.'='.$v);
          $_ENV[$k] = $v;
          $_SERVER[$k] = $v;
        }
      }
    }
  }
}

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'natcondash1';
$dbUser = getenv('DB_USER') ?: getenv('DB_USERNAME') ?: 'root';
$dbPass = getenv('DB_PASS') ?: getenv('DB_PASSWORD') ?: '';

mysqli_report(MYSQLI_REPORT_OFF); // suppress warnings, handle manually
$conn = @new mysqli($dbHost, $dbUser, $dbPass, $dbName, (int)$dbPort);

if ($conn->connect_errno) {
  // In production avoid leaking credentials; log minimal message
  error_log('[DB] Connection failed: ' . $conn->connect_errno . ' ' . $conn->connect_error);
  http_response_code(500);
  die('Database connection error.');
}

// Optional: set charset
if (!$conn->set_charset('utf8mb4')) {
  error_log('[DB] Failed to set charset utf8mb4: ' . $conn->error);
}
?>