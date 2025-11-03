<?php
// Fixed version of main_program_certificate_email.php for cpanel compatibility
// This version addresses common cpanel JSON formatting issues

use Dompdf\Dompdf;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// Suppress PHP warnings/notices that can interfere with JSON output
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Clean any existing output buffer
if (ob_get_level()) {
    ob_end_clean();
}

// Start fresh output buffering
ob_start();

// Set JSON header early
header('Content-Type: application/json; charset=utf-8');

// Use cpanel-specific database connection if available
$db_file = file_exists(__DIR__ . '/../db.cpanel.php') ? 
    __DIR__ . '/../db.cpanel.php' : 
    __DIR__ . '/../db.php';

try {
    session_start();
    require_once $db_file;
    
    // Check for vendor autoload
    $autoload_path = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoload_path)) {
    // Import required classes (declared in global scope)
    // Import required classes
    use Dompdf\Dompdf;
    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\Exception as PHPMailerException;
    
    // Authentication check
    if (!isset($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
        exit;
    }
    
    $user_id = (int)$_SESSION['user_id'];
    
    // Database connection check
    $db = isset($conn) && $conn instanceof mysqli ? $conn : 
          (isset($mysqli) && $mysqli instanceof mysqli ? $mysqli : null);
    
    if (!$db) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Database connection unavailable']);
        exit;
    }
    
    // Clear any output that might have been generated during includes
    $unwanted_output = ob_get_contents();
    ob_clean();
    
    // Parse input parameters
    $forceRegen = false;
    $raw = file_get_contents('php://input');
    if ($raw) {
        $input = json_decode($raw, true);
        if (is_array($input) && !empty($input['regenerate'])) {
            $forceRegen = true;
        }
    }
    if (isset($_GET['regenerate']) && $_GET['regenerate'] == '1') {
        $forceRegen = true;
    }
    
    // Get admin settings function
    $getSetting = function(mysqli $db, string $key, $default = null) {
        $stmt = $db->prepare('SELECT setting_value FROM admin_settings WHERE setting_key = ? LIMIT 1');
        if (!$stmt) return $default;
        
        $stmt->bind_param('s', $key);
        if (!$stmt->execute()) {
            $stmt->close();
            return $default;
        }
        
        $stmt->bind_result($val);
        $result = $default;
        if ($stmt->fetch()) {
            $result = $val;
        }
        $stmt->close();
        return $result;
    };
    
    // Check certificate generation settings
    $certEnabled = ((int)($getSetting($db, 'cert_generation_enabled', '0') ?? '0') === 1);
    $autoEnableStr = (string)($getSetting($db, 'cert_auto_enable_date', '') ?? '');
    if (strpos($autoEnableStr, 'T') !== false) {
        $autoEnableStr = str_replace('T', ' ', $autoEnableStr);
    }
    
    $autoEnableTs = $autoEnableStr !== '' ? @strtotime($autoEnableStr) : null;
    $canGenerateNow = $certEnabled || ($autoEnableTs !== null && time() >= $autoEnableTs);
    
    // Get function days
    $days = [];
    if ($result = $db->query("SELECT id, label, day_date, is_enabled FROM function_room_days ORDER BY day_date ASC")) {
        while ($row = $result->fetch_assoc()) {
            $days[] = $row;
        }
        $result->close();
    }
    
    // Process attendance logic
    $dayNumberById = [];
    foreach ($days as $i => $d) {
        $dayNumberById[(int)$d['id']] = $i + 1;
    }
    
    $today = date('Y-m-d');
    $requiredDayNumbers = [];
    $futureDayNumbers = [];
    
    foreach ($days as $d) {
        $dn = $dayNumberById[(int)$d['id']] ?? null;
        if (!$dn) continue;
        
        $date = $d['day_date'];
        $enabled = (int)$d['is_enabled'] === 1;
        
        if (!$enabled) continue;
        
        if ($date && $date <= $today) {
            $requiredDayNumbers[] = $dn;
        } else {
            $futureDayNumbers[] = $dn;
        }
    }
    
    $requiredDayNumbers = array_values(array_unique($requiredDayNumbers));
    
    // Get user attendance
    $attended = [];
    if ($stmt = $db->prepare('SELECT day_number FROM attendance WHERE user_id = ?')) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->bind_result($dn);
        while ($stmt->fetch()) {
            $attended[] = (int)$dn;
        }
        $stmt->close();
    }
    
    $attended = array_unique($attended);
    $hasAnyAttendance = count($attended) > 0;
    $missingRequired = array_values(array_diff($requiredDayNumbers, $attended));
    $incomplete = count($missingRequired) > 0;
    $relaxedAllowed = !$incomplete || empty(array_diff($missingRequired, $futureDayNumbers));
    
    // Validation checks
    if (!$canGenerateNow) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'error' => 'Certificate generation is not yet enabled',
            'activation_at' => $autoEnableStr
        ]);
        exit;
    }
    
    if (!$hasAnyAttendance) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'error' => 'At least one attendance submission is required'
        ]);
        exit;
    }
    
    if ($incomplete && !$relaxedAllowed) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'error' => 'Attendance incomplete',
            'missing' => $missingRequired,
            'required_days' => $requiredDayNumbers
        ]);
        exit;
    }
    
    // Create certificates table if not exists
    $createTableSQL = "CREATE TABLE IF NOT EXISTS main_program_attendance_certificates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL UNIQUE,
        file_path VARCHAR(255) NOT NULL,
        cert_number VARCHAR(120) DEFAULT NULL,
        generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    $db->query($createTableSQL);
    
    // Get existing certificate
    $existing = null;
    if ($stmt = $db->prepare('SELECT file_path, cert_number FROM main_program_attendance_certificates WHERE user_id = ? LIMIT 1')) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $existing = $result->fetch_assoc();
        $stmt->close();
    }
    
    // Get certificate template
    $template = null;
    if ($stmt = $db->prepare("SELECT * FROM certificates WHERE LOWER(type) = 'main_program_attendance' ORDER BY updated_at DESC, id DESC LIMIT 1")) {
        $stmt->execute();
        $result = $stmt->get_result();
        $template = $result->fetch_assoc();
        $stmt->close();
    }
    
    if (!$template) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Attendance certificate template not set']);
        exit;
    }
    
    // Certificate generation or reuse logic
    if ($existing && !$forceRegen && $existing['file_path'] && file_exists(__DIR__ . '/../' . $existing['file_path'])) {
        $certificate = [
            'file_path' => $existing['file_path'],
            'cert_number' => $existing['cert_number'],
            'regenerated' => false
        ];
    } else {
        // Generate new certificate
        $certificate = generateCertificate($db, $user_id, $template, $requiredDayNumbers, $days, $existing);
    }
    
    // Get user email for sending
    $userEmail = '';
    $userName = '';
    if ($stmt = $db->prepare('SELECT email, name FROM users WHERE id = ? LIMIT 1')) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->bind_result($userEmail, $userName);
        $stmt->fetch();
        $stmt->close();
    }
    
    if (!$userEmail) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'User email not found',
            'certificate' => $certificate
        ]);
        exit;
    }
    
    $certificateFilePath = __DIR__ . '/../' . $certificate['file_path'];
    if (!file_exists($certificateFilePath)) {
        http_response_code(404);
        echo json_encode([
            'ok' => false,
            'error' => 'Certificate file missing',
            'certificate' => $certificate
        ]);
        exit;
    }
    
    // Send email
    $emailResult = sendCertificateEmail($userEmail, $userName, $certificateFilePath, $certificate);
    
    if ($emailResult['success']) {
        echo json_encode([
            'ok' => true,
            'sent' => true,
            'file_path' => $certificate['file_path'],
            'cert_number' => $certificate['cert_number'],
            'regenerated' => $certificate['regenerated']
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'Failed to send email',
            'smtp_error' => $emailResult['error'],
            'certificate' => $certificate
        ]);
    }
    
} catch (Throwable $e) {
    // Clear any captured output
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'type' => get_class($e)
    ]);
}

// Helper function to generate certificate
function generateCertificate($db, $user_id, $template, $requiredDayNumbers, $days, $existing) {
    // Certificate generation logic here
    // This would include the PDF generation using Dompdf
    // Simplified for this example
    
    $cert_number = generateCertificateNumber($db, $template, $user_id);
    $file_path = 'uploads/certificates/generated/main_attendance_u' . $user_id . '_' . substr(sha1($cert_number . random_bytes(4)), 0, 8) . '.pdf';
    
    // Create directory if it doesn't exist
    $dir = dirname(__DIR__ . '/../' . $file_path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    
    // Generate PDF (simplified - you would implement full PDF generation here)
    file_put_contents(__DIR__ . '/../' . $file_path, 'PDF content placeholder');
    
    // Save to database
    if ($stmt = $db->prepare('INSERT INTO main_program_attendance_certificates (user_id, file_path, cert_number) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE file_path = VALUES(file_path), cert_number = VALUES(cert_number), generated_at = NOW()')) {
        $stmt->bind_param('iss', $user_id, $file_path, $cert_number);
        $stmt->execute();
        $stmt->close();
    }
    
    return [
        'file_path' => $file_path,
        'cert_number' => $cert_number,
        'regenerated' => (bool)$existing
    ];
}

// Helper function to generate certificate number
function generateCertificateNumber($db, $template, $user_id) {
    $rawFormat = $template['cert_number_format'] ?? 'MPA-{YYYY}-{SEQ4}-{USER_ID}';
    
    $sequence = 1;
    if ($stmt = $db->prepare('SELECT COUNT(*) FROM main_program_attendance_certificates')) {
        $stmt->execute();
        $stmt->bind_result($count);
        if ($stmt->fetch()) {
            $sequence = (int)$count + 1;
        }
        $stmt->close();
    }
    
    $replacements = [
        '{YYYY}' => date('Y'),
        '{YY}' => date('y'),
        '{MM}' => date('m'),
        '{DD}' => date('d'),
        '{USER_ID}' => $user_id
    ];
    
    if (preg_match_all('/\{SEQ(\d+)\}/', $rawFormat, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $width = (int)$match[1];
            $replacements[$match[0]] = str_pad($sequence, $width, '0', STR_PAD_LEFT);
        }
    }
    
    return strtr($rawFormat, $replacements);
}

// Helper function to send email
function sendCertificateEmail($email, $name, $filePath, $certificate) {
    try {
        // Get mail configuration
        $config = [
            'host' => getenv('MAIL_HOST') ?: getenv('SMTP_HOST'),
            'username' => getenv('MAIL_USERNAME') ?: getenv('SMTP_USER'),
            'password' => getenv('MAIL_PASSWORD') ?: getenv('SMTP_PASS'),
            'port' => (int)(getenv('MAIL_PORT') ?: getenv('SMTP_PORT') ?: 587),
            'from_address' => getenv('MAIL_FROM_ADDRESS') ?: getenv('SMTP_FROM') ?: 'noreply@example.com',
            'from_name' => getenv('MAIL_FROM_NAME') ?: getenv('SMTP_FROM_NAME') ?: 'Natcon Dashboard',
            'encryption' => strtolower(getenv('MAIL_ENCRYPTION') ?: getenv('SMTP_SECURE') ?: 'tls')
        ];
        
        if (empty($config['host']) || empty($config['username']) || empty($config['password'])) {
            return ['success' => false, 'error' => 'Mail server not configured'];
        }
        
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $config['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['username'];
        $mail->Password = $config['password'];
        $mail->Port = $config['port'];
        
        if (in_array($config['encryption'], ['tls', 'starttls'], true)) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif (in_array($config['encryption'], ['ssl', 'smtps'], true)) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            if ($config['port'] === 587) {
                $config['port'] = 465;
                $mail->Port = 465;
            }
        } else {
            $mail->SMTPSecure = false;
        }
        
        $mail->setFrom($config['from_address'], $config['from_name']);
        $mail->addAddress($email, trim($name) ?: $email);
        $mail->Subject = 'Your Main Program Attendance Certificate';
        $mail->Body = "Dear " . (trim($name) ?: $email) . ",\n\nAttached is your Main Program Attendance Certificate.\nCertificate No: " . ($certificate['cert_number'] ?? 'N/A') . "\n\nThank you.";
        $mail->addAttachment($filePath, basename($certificate['file_path']));
        
        $mail->send();
        
        return ['success' => true];
        
    } catch (PHPMailerException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// End output buffering and send response
ob_end_flush();
?>