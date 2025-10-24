<?php
// Dedicated DB connection settings for the cPanel deployment.
// This mirrors db.php but defaults to the provided cPanel credentials.

if (!class_exists('Dotenv\Dotenv')) {
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) require_once $autoload;
}

if (class_exists('Dotenv\Dotenv')) {
    try {
        Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
    } catch (Throwable $e) {
        // Ignore load errors; explicit credentials below act as fallback.
    }
}

if (!getenv('DB_HOST') && !getenv('SMTP_HOST') && !getenv('MAIL_HOST')) {
    $envPath = __DIR__ . '/.env';
    if (is_file($envPath) && is_readable($envPath)) {
        $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines !== false) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || str_starts_with($line, ';')) continue;
                if (!preg_match('/^[A-Z0-9_]+=/i', $line)) continue;
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v);
                if ($v !== '' && ($v[0] === "'" || $v[0] === '"')) {
                    $q = $v[0];
                    if (str_ends_with($v, $q)) $v = substr($v, 1, -1);
                }
                $v = str_replace(['\\n', '\\r'], ["\n", "\r"], $v);
                if ($k !== '') {
                    putenv($k . '=' . $v);
                    $_ENV[$k] = $v;
                    $_SERVER[$k] = $v;
                }
            }
        }
    }
}

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'psmenow_dash1';
$dbUser = getenv('DB_USER') ?: getenv('DB_USERNAME') ?: 'psmenow_Randy';
$dbPass = getenv('DB_PASS') ?: getenv('DB_PASSWORD') ?: '091902Randsf2..';

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($dbHost, $dbUser, $dbPass, $dbName, (int)$dbPort);

if ($conn->connect_errno) {
    error_log('[DB-cpanel] Connection failed: ' . $conn->connect_errno . ' ' . $conn->connect_error);
    http_response_code(500);
    die('Database connection error.');
}

if (!$conn->set_charset('utf8mb4')) {
    error_log('[DB-cpanel] Failed to set charset utf8mb4: ' . $conn->error);
}
?>
