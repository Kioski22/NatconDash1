<?php
// Debug version of main_program_certificate_email.php for cpanel troubleshooting
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors to avoid mixing with JSON
ini_set('log_errors', 1);

// Capture all output to prevent mixing with JSON
ob_start();

$debug_info = [];
$start_time = microtime(true);

try {
    // Set JSON header first
    header('Content-Type: application/json; charset=utf-8');
    
    // Check session
    session_start();
    $debug_info['session_started'] = true;
    
    // Check database connection
    $db_file = __DIR__ . '/../db.php';
    $cpanel_db_file = __DIR__ . '/../db.cpanel.php';
    
    if (file_exists($cpanel_db_file)) {
        require_once $cpanel_db_file;
        $debug_info['db_file'] = 'cpanel';
    } else {
        require_once $db_file;
        $debug_info['db_file'] = 'regular';
    }
    
    // Check dependencies
    $vendor_file = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($vendor_file)) {
        throw new Exception('Vendor autoload not found');
    }
    require_once $vendor_file;
    
    // Check if classes exist
    $debug_info['dompdf_available'] = class_exists('Dompdf\\Dompdf');
    $debug_info['phpmailer_available'] = class_exists('PHPMailer\\PHPMailer\\PHPMailer');
    
    if (!$debug_info['dompdf_available']) {
        throw new Exception('Dompdf class not available');
    }
    if (!$debug_info['phpmailer_available']) {
        throw new Exception('PHPMailer class not available');
    }
    
    // Check authentication
    if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
        throw new Exception('Not authenticated', 401);
    }
    $user_id = (int)$_SESSION['user_id'];
    $debug_info['user_id'] = $user_id;
    
    // Check database connection
    $db = isset($conn) && $conn instanceof mysqli ? $conn : null;
    if (!$db) {
        throw new Exception('Database connection not available', 500);
    }
    $debug_info['db_connected'] = true;
    
    // Check certificates directory
    $cert_dir = __DIR__ . '/../uploads/certificates/generated';
    if (!is_dir($cert_dir)) {
        if (!@mkdir($cert_dir, 0775, true)) {
            throw new Exception('Cannot create certificates directory');
        }
    }
    $debug_info['cert_dir_writable'] = is_writable($cert_dir);
    
    // Check mail configuration
    $mail_config = [
        'host' => getenv('MAIL_HOST') ?: getenv('SMTP_HOST'),
        'user' => getenv('MAIL_USERNAME') ?: getenv('SMTP_USER'),
        'pass' => !empty(getenv('MAIL_PASSWORD') ?: getenv('SMTP_PASS')),
        'port' => getenv('MAIL_PORT') ?: getenv('SMTP_PORT') ?: '587'
    ];
    $debug_info['mail_config'] = $mail_config;
    $debug_info['mail_configured'] = !empty($mail_config['host']) && !empty($mail_config['user']) && $mail_config['pass'];
    
    // Test basic database query
    if ($result = $db->query("SELECT COUNT(*) as cnt FROM users WHERE id = $user_id")) {
        $row = $result->fetch_assoc();
        $debug_info['user_exists'] = $row['cnt'] > 0;
        $result->close();
    }
    
    // Clear any captured output
    $captured_output = ob_get_contents();
    ob_end_clean();
    
    if (!empty($captured_output)) {
        $debug_info['captured_output'] = $captured_output;
    }
    
    $debug_info['execution_time'] = round((microtime(true) - $start_time) * 1000, 2) . 'ms';
    $debug_info['success'] = true;
    
    echo json_encode([
        'ok' => true,
        'message' => 'Debug information collected successfully',
        'debug' => $debug_info
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    // Clear any captured output
    $captured_output = ob_get_contents();
    ob_end_clean();
    
    if (!empty($captured_output)) {
        $debug_info['captured_output'] = $captured_output;
    }
    
    $debug_info['error'] = $e->getMessage();
    $debug_info['error_code'] = $e->getCode();
    $debug_info['execution_time'] = round((microtime(true) - $start_time) * 1000, 2) . 'ms';
    
    if ($e->getCode() > 0) {
        http_response_code($e->getCode());
    } else {
        http_response_code(500);
    }
    
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'debug' => $debug_info
    ], JSON_PRETTY_PRINT);
} catch (Throwable $t) {
    // Clear any captured output
    $captured_output = ob_get_contents();
    ob_end_clean();
    
    if (!empty($captured_output)) {
        $debug_info['captured_output'] = $captured_output;
    }
    
    $debug_info['fatal_error'] = $t->getMessage();
    $debug_info['execution_time'] = round((microtime(true) - $start_time) * 1000, 2) . 'ms';
    
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Fatal error: ' . $t->getMessage(),
        'debug' => $debug_info
    ], JSON_PRETTY_PRINT);
}
?>