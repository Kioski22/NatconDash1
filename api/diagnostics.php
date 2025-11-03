<?php
// Simple diagnostic script to identify JSON formatting issues in cpanel
header('Content-Type: application/json; charset=utf-8');

// Capture any unexpected output
ob_start();

$diagnostics = [
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION,
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
    'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'unknown',
    'script_name' => $_SERVER['SCRIPT_NAME'] ?? 'unknown',
];

// Test basic functionality
try {
    // Check if we're getting any unwanted output
    $captured = ob_get_contents();
    if (!empty($captured)) {
        $diagnostics['unexpected_output'] = $captured;
    }
    
    // Check file paths
    $diagnostics['current_dir'] = __DIR__;
    $diagnostics['db_file_exists'] = file_exists(__DIR__ . '/../db.php');
    $diagnostics['cpanel_db_exists'] = file_exists(__DIR__ . '/../db.cpanel.php');
    $diagnostics['vendor_exists'] = file_exists(__DIR__ . '/../vendor/autoload.php');
    
    // Check session capability
    if (!session_id()) {
        session_start();
        $diagnostics['session_started'] = true;
    }
    
    // Check for common extensions
    $diagnostics['extensions'] = [
        'mysqli' => extension_loaded('mysqli'),
        'openssl' => extension_loaded('openssl'),
        'mbstring' => extension_loaded('mbstring'),
        'gd' => extension_loaded('gd'),
        'curl' => extension_loaded('curl'),
        'zip' => extension_loaded('zip'),
        'dom' => extension_loaded('dom'),
    ];
    
    // Check environment variables
    $diagnostics['env_vars'] = [
        'has_db_host' => !empty(getenv('DB_HOST')),
        'has_mail_host' => !empty(getenv('MAIL_HOST')),
        'has_smtp_host' => !empty(getenv('SMTP_HOST')),
    ];
    
    ob_end_clean();
    
    // Output clean JSON
    echo json_encode([
        'status' => 'success',
        'message' => 'Diagnostics completed successfully',
        'data' => $diagnostics
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
} catch (Throwable $e) {
    $captured = ob_get_contents();
    ob_end_clean();
    
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'unexpected_output' => $captured,
        'partial_diagnostics' => $diagnostics
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
?>