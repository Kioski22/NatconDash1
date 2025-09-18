<?php
session_start();
require_once __DIR__ . '/../db.php'; // adjust path if needed

// Current user (set these from your login system)
$userId   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$userRole = isset($_SESSION['user_role']) ? $_SESSION['user_role'] : 'guest';

// Server timezone
date_default_timezone_set('Asia/Manila');
$today = (new DateTime())->format('Y-m-d');

// --- Load room mode ---
$roomMode = 'date_strict'; // fallback
$res = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key='function_room_mode'");
if ($res && $row = $res->fetch_assoc()) {
    $roomMode = $row['setting_value'];
}

// --- Fetch function-room days ---
$days = [];
$sql = "SELECT id, label, day_date, is_enabled FROM function_room_days ORDER BY day_date ASC";
if ($stmt = $conn->prepare($sql)) {
    $stmt->execute();
    $res = $stmt->get_result();
    $days = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// --- Get last convention date ---
$lastConventionDate = null;
if ($r = $conn->query("SELECT MAX(day_date) AS last_date FROM function_room_days")) {
    $row = $r->fetch_assoc();
    $lastConventionDate = $row['last_date'] ?? null;
}

// --- Helpers ---
function isConventionOver($today, $lastDate) {
    if (!$lastDate) return false;
    return (new DateTime($today)) > (new DateTime($lastDate));
}

function userHasCompletedAny($conn, $userId) {
    if (!$userId) return false;
    $sql = "SELECT COUNT(*) AS cnt FROM attendance_records WHERE user_id = ? AND completed = 1";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)$res['cnt'] > 0;
    }
    return false;
}

// NEW: fetch user eligibility flags from users table (with fallback to common typo)
function fetchUserEligibility($conn, $userId) {
    $defaults = [
        'attendance' => 0,
        'appearance' => 0,
        'cpd'        => 0,
    ];
    if (!$userId) return $defaults;

    $sql = "SELECT * FROM users WHERE id = ? LIMIT 1";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) return $defaults;

        // Support both correct and possible typo column name
        $att = isset($row['eligible_certificate_attendance'])
            ? (int)$row['eligible_certificate_attendance']
            : (isset($row['eligible_certifcate_attendance']) ? (int)$row['eligible_certifcate_attendance'] : 0);

        return [
            'attendance' => $att,
            'appearance' => isset($row['eligible_certificate_appearance']) ? (int)$row['eligible_certificate_appearance'] : 0,
            'cpd'        => isset($row['eligible_cpd_certificate']) ? (int)$row['eligible_cpd_certificate'] : 0,
        ];
    }

    return $defaults;
}

$conventionOver = isConventionOver($today, $lastConventionDate);
$userCompletedAny = userHasCompletedAny($conn, $userId);

// NEW: Load eligibility once on page load
$userEligibility = fetchUserEligibility($conn, $userId);
$eligMap = [
    'attendance' => ($userId && $userEligibility['attendance'] === 1),
    'appearance' => ($userId && $userEligibility['appearance'] === 1),
    'cpd'        => ($userId && $userEligibility['cpd'] === 1),
];

// Current styling constants
$THEME = [
    'primary' => '#0B2A59',
    'accent' => '#1a4189',
    'text' => '#FFFFFF',
    'text-muted' => '#D6E0FF'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>73rd PEME National Convention</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: <?= $THEME['primary'] ?>;
            color: <?= $THEME['text'] ?>;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto;
        }
        .nav-header {
            background: <?= $THEME['accent'] ?>;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .nav-header img.logo {
            height: 40px;
        }
        .nav-header .back-link {
            color: <?= $THEME['text'] ?>;
            text-decoration: none;
        }
        .page-title {
            background: white;
            color: black;
            padding: 15px;
            text-align: center;
            font-weight: 600;
            margin-bottom: 30px;
        }
        .function-room-card {
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.2);
        }
        .function-room-card:hover:not(.disabled) {
            transform: translateY(-5px);
            background: rgba(255,255,255,0.15);
        }
        .function-room-card.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .function-room-date {
            color: <?= $THEME['text-muted'] ?>;
            font-size: 0.9rem;
        }
        .certificate-section {
            margin-top: 40px;
            text-align: center;
        }
        .certificate-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .certificate-item {
            background: rgba(255,255,255,0.1);
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }
        .certificate-item.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .certificate-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 15px;
            background: white;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>
<body>
    <!-- Navigation Header -->
    <div class="nav-header">
        <img src="../assets/logo.png" alt="PEME Logo" class="logo">
        <a href="dashboard.php" class="back-link">← Back to Home</a>
    </div>

    <!-- Page Title -->
    <div class="page-title">
        FUNCTION ROOMS
    </div>

    <!-- Acknowledgement of day.php changes -->
    <div class="container mb-3">
        <div class="alert alert-info small">
            <strong>Note:</strong> The Day page (dashboard/day.php) was updated.
            Changes include:
            <ul class="mb-0">
                <li>day.php now resolves the requested day from <code>?day</code>, <code>?day_id</code> or by matching today's date in the DB.</li>
                <li>function rooms and speakers are fetched dynamically from the <code>function_rooms</code> and <code>speakers</code> tables.</li>
                <li>Time formatting helper (fmt_time) and safe HTML escaping are used.</li>
                <li>Links on this page point to <code>day.php?day_id=&lt;id&gt;</code> so the new resolver logic is used.</li>
            </ul>
        </div>
    </div>
    
    <div class="container">
        <div class="row">
            <!-- Function Room Cards -->
            <div class="col-md-8">
                <p class="text-muted mb-4">
                    Explore the seminars for Day 1 to Day 3.<br>
                    Each day features different speakers and technical topics. Please complete the attendance, quiz, and evaluation for each session to ensure your participation is recorded.
                </p>

                <?php foreach ($days as $d):
                    $dayId = (int)$d['id'];
                    $label = htmlspecialchars($d['label']);
                    $dayDate = $d['day_date'];
                    $isEnabled = ((int)$d['is_enabled'] === 1);
                    $isToday = ($today === $dayDate);
                    $isActive = ($roomMode === 'date_strict') ? ($isToday && $isEnabled) : $isEnabled;
                ?>
                <div class="function-room-card <?= $isActive ? '' : 'disabled' ?>" 
                     onclick="<?= $isActive ? "window.location='day.php?day_id={$dayId}'" : '' ?>">
                    <h4><?= $label ?></h4>
                    <div class="function-room-date">
                        <?= (new DateTime($dayDate))->format('F j, Y') ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- Evaluation Card -->
                <div class="function-room-card">
                    <h4>NATIONAL CONVENTION EVALUATION</h4>
                    <div class="function-room-date">Required for certificate generation</div>
                </div>
            </div>

            <!-- Certificates Section -->
            <div class="col-md-4">
                <div class="certificate-section">
                    <h4 class="mb-4">GENERATE YOUR CERTIFICATES</h4>
                    
                    <?php
                    $certificates = [
                        ['key' => 'attendance', 'label' => 'Certificate of Attendance', 'icon' => 'CA'],
                        ['key' => 'appearance', 'label' => 'Certificate of Appearance', 'icon' => 'CP'],
                        ['key' => 'cpd', 'label' => 'CPD Form Certificate', 'icon' => 'CPD']
                    ];
                    ?>

                    <div class="certificate-grid">
                        <?php foreach ($certificates as $cert): 
                            $isEligible = !empty($eligMap[$cert['key']]);
                        ?>
                        <div class="certificate-item <?= $isEligible ? '' : 'disabled' ?>"
                             data-type="<?= htmlspecialchars($cert['key']) ?>"
                             data-enabled="<?= $isEligible ? '1' : '0' ?>"
                             <?= $isEligible ? "onclick=\"generateCertificate('{$cert['key']}')\"" : "title='Not eligible yet'" ?>>
                            <div class="certificate-icon">
                                <?= $cert['icon'] ?>
                            </div>
                            <div class="certificate-label">
                                <?= htmlspecialchars($cert['label']) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!$userId): ?>
                        <div class="text-warning small mt-2">Please log in to generate certificates.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
    // client-side guard (in case someone clicks disabled)
    const ELIG = <?= json_encode($eligMap, JSON_UNESCAPED_SLASHES) ?>;
    function generateCertificate(type) {
        if (!ELIG[type]) {
            alert('You are not yet eligible for this certificate.');
            return;
        }
        window.location.href = 'generate_certificate.php?type=' + encodeURIComponent(type);
    }
    </script>
</body>
</html>
