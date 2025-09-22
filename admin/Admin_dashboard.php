<?php
session_start();
require_once __DIR__ . '/../db.php';

// normalize DB connection (fixes "Undefined variable $db")
if (isset($conn) && $conn instanceof mysqli) {
    $db = $conn;
} elseif (isset($mysqli) && $mysqli instanceof mysqli) {
    $db = $mysqli;
} else {
    $db = null;
}
if (!($db instanceof mysqli)) {
    http_response_code(500);
    echo "Database connection not available.";
    exit;
}

// ensure CSRF token used by the admin form
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

// Check if user is logged in as admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: admin_login.php');
    exit;
}

$admin_name = $_SESSION['user_name'] ?? 'Admin';

// fetch current certificate settings (show in admin UI)
$cert_enabled = '0';
$cert_auto_date = '';
$stmtCert = $db->prepare("SELECT setting_value FROM admin_settings WHERE setting_key = 'cert_generation_enabled' LIMIT 1");
if ($stmtCert) {
    $stmtCert->execute();
    $r = $stmtCert->get_result()->fetch_assoc();
    $stmtCert->close();
    if (!empty($r['setting_value'])) $cert_enabled = $r['setting_value'];
}
$stmtAuto = $db->prepare("SELECT setting_value FROM admin_settings WHERE setting_key = 'cert_auto_enable_date' LIMIT 1");
if ($stmtAuto) {
    $stmtAuto->execute();
    $r2 = $stmtAuto->get_result()->fetch_assoc();
    $stmtAuto->close();
    if (!empty($r2['setting_value'])) $cert_auto_date = $r2['setting_value'];
}

// helpers
function dash_count(mysqli $db, string $sql): int {
    $res = $db->query($sql);
    if (!$res) return 0;
    $row = $res->fetch_row();
    return isset($row[0]) ? (int)$row[0] : 0;
}

// counts and indicators
$roomsCount    = dash_count($db, "SELECT COUNT(*) FROM function_rooms");
$daysCount     = dash_count($db, "SELECT COUNT(*) FROM function_room_days");
$speakersCount = dash_count($db, "SELECT COUNT(*) FROM speakers");

// quizzes table may not exist yet; handle safely
$quizzesCount = 0;
$q = $db->query("SELECT COUNT(*) FROM quizzes");
if ($q) { $quizzesCount = (int)$q->fetch_row()[0]; }

$quizRespCount = 0;
$q2 = $db->query("SELECT COUNT(*) FROM quiz_responses");
if ($q2) { $quizRespCount = (int)$q2->fetch_row()[0]; }

// speakers without quiz
$speakersNoQuiz = 0;
$qr = $db->query("SELECT COUNT(*) FROM speakers s LEFT JOIN quizzes q ON q.speaker_id = s.id WHERE q.id IS NULL");
if ($qr) { $speakersNoQuiz = (int)$qr->fetch_row()[0]; }

$quizCoverage = ($speakersCount > 0) ? round((($speakersCount - $speakersNoQuiz) / $speakersCount) * 100) : 0;

// recent quizzes (for indicators list)
$recentQuizzes = [];
$rq = $db->query("
  SELECT q.id, q.created_at, s.name AS speaker_name
  FROM quizzes q
  JOIN speakers s ON s.id = q.speaker_id
  ORDER BY q.created_at DESC
  LIMIT 5
");
if ($rq) {
    while ($row = $rq->fetch_assoc()) $recentQuizzes[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard — 73rd PSME National Convention</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #06b6d4;
            --light: #f8fafc;
            --dark: #1e293b;
            --sidebar-bg: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --card-shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        * { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
        
        body { 
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }

        /* Sidebar Improvements */
        .sidebar {
            background: var(--sidebar-bg);
            min-height: 100vh;
            box-shadow: 4px 0 15px rgba(0,0,0,0.1);
            border-radius: 0 20px 20px 0;
            transition: all 0.3s ease;
        }

        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            border-radius: 12px;
            margin: 2px 8px;
            padding: 12px 16px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .sidebar .nav-link:hover {
            color: white;
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            transform: translateX(5px);
        }

        .sidebar .nav-link.active {
            color: white;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        .sidebar .nav-link i { margin-right: 8px; }

        /* Welcome Section */
        .welcome-section {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            margin: 15px;
            box-shadow: var(--card-shadow);
        }

        /* Enhanced Cards */
        .stat-card {
            background: white;
            border: none;
            border-radius: 20px;
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
            overflow: hidden;
            position: relative;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--info));
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        .stat-card .card-body { padding: 25px; }

        .stat-card .icon {
            font-size: 2rem;
            opacity: 0.8;
            background: linear-gradient(135deg, var(--primary), var(--info));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* Enhanced Badges */
        .badge-soft { 
            background: rgba(37,99,235,0.1); 
            color: var(--primary);
            border: 1px solid rgba(37,99,235,0.2);
            border-radius: 20px;
            padding: 6px 12px;
            font-weight: 500;
        }
        
        .badge-soft-success { 
            background: rgba(16,185,129,0.1); 
            color: var(--success);
            border: 1px solid rgba(16,185,129,0.2);
        }
        
        .badge-soft-warning { 
            background: rgba(245,158,11,0.1); 
            color: var(--warning);
            border: 1px solid rgba(245,158,11,0.2);
        }
        
        .badge-soft-danger { 
            background: rgba(239,68,68,0.1); 
            color: var(--danger);
            border: 1px solid rgba(239,68,68,0.2);
        }

        /* Progress Bar Enhancements */
        .progress {
            height: 8px;
            border-radius: 20px;
            background: rgba(37,99,235,0.1);
            overflow: hidden;
        }

        .progress-bar {
            background: linear-gradient(90deg, var(--success), var(--info));
            border-radius: 20px;
            transition: width 0.6s ease;
        }

        /* Enhanced Tables */
        .table {
            border-radius: 15px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            background: white;
        }

        .table thead th {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }

        .table tbody tr {
            transition: all 0.2s ease;
            border: none;
        }

        .table tbody tr:hover {
            background: rgba(37,99,235,0.05);
            transform: scale(1.01);
        }

        /* Enhanced Buttons */
        .btn {
            border-radius: 12px;
            font-weight: 500;
            transition: all 0.3s ease;
            text-transform: none;
            letter-spacing: 0.3px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border: none;
        }

        /* Header Enhancement */
        .page-header {
            background: white;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: var(--card-shadow);
            border-left: 5px solid var(--primary);
        }

        .page-title {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-weight: 700;
            margin: 0;
        }

        /* Enhanced Cards */
        .enhanced-card {
            background: white;
            border: none;
            border-radius: 20px;
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
            overflow: hidden;
        }

        .enhanced-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--card-shadow-hover);
        }

        .enhanced-card .card-header {
            background: linear-gradient(135deg, rgba(37,99,235,0.05), rgba(6,182,212,0.05));
            border-bottom: 2px solid rgba(37,99,235,0.1);
            padding: 20px 25px;
        }

        .enhanced-card .card-body {
            padding: 25px;
        }

        /* List Group Enhancement */
        .list-group-item {
            border: none;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            padding: 15px 20px;
            transition: all 0.2s ease;
        }

        .list-group-item:hover {
            background: rgba(37,99,235,0.03);
            padding-left: 25px;
        }

        .list-group-item:last-child {
            border-bottom: none;
        }

        /* Form Enhancements */
        .form-control, .form-select {
            border-radius: 12px;
            border: 2px solid rgba(0,0,0,0.1);
            padding: 12px 16px;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
        }

        /* Animation Classes */
        .fade-in {
            animation: fadeIn 0.6s ease-out;
        }

        .slide-up {
            animation: slideUp 0.6s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Responsive Enhancements */
        @media (max-width: 768px) {
            .sidebar {
                border-radius: 0;
            }
            
            .stat-card .card-body {
                padding: 20px;
            }
            
            .page-header {
                padding: 20px;
                margin-bottom: 20px;
            }
        }

        /* Loading Animation */
        .loading {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <!-- Enhanced Sidebar -->
        <nav class="col-md-3 col-lg-2 d-md-block sidebar collapse">
            <div class="position-sticky pt-3">
                <div class="welcome-section">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-white-50 small">Welcome back,</div>
                            <div class="text-white fw-bold"><?= htmlspecialchars($admin_name) ?></div>
                        </div>
                        <i class="bi bi-person-circle text-white" style="font-size: 2rem; opacity: 0.8;"></i>
                    </div>
                </div>

                <ul class="nav flex-column px-2">
                    <li class="nav-item">
                        <a class="nav-link active" href="Admin_dashboard.php">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>

                    <!-- Function Rooms Section -->
                    <li class="nav-item mt-3">
                        <a class="nav-link" data-bs-toggle="collapse" href="#functionRoomsMenu" role="button" aria-expanded="false" aria-controls="functionRoomsMenu">
                            <i class="bi bi-door-open"></i> Function Rooms
                            <span class="badge bg-white text-primary ms-2"><?= (int)$roomsCount ?></span>
                            <i class="bi bi-chevron-down float-end"></i>
                        </a>
                        <div class="collapse" id="functionRoomsMenu">
                            <ul class="nav flex-column ms-3">
                                <li class="nav-item">
                                    <a class="nav-link py-2" href="admin_manage_function_rooms.php">
                                        <i class="bi bi-gear"></i> Manage Rooms
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link py-2" href="admin_function_days.php">
                                        <i class="bi bi-calendar3"></i> Toggle Days
                                        <span class="badge bg-white text-primary ms-1"><?= (int)$daysCount ?></span>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </li>

                    <!-- Speakers Section -->
                    <li class="nav-item mt-2">
                        <a class="nav-link" data-bs-toggle="collapse" href="#speakersMenu" role="button" aria-expanded="false" aria-controls="speakersMenu">
                            <i class="bi bi-person-video3"></i> Speakers
                            <span class="badge bg-white text-primary ms-2"><?= (int)$speakersCount ?></span>
                            <i class="bi bi-chevron-down float-end"></i>
                        </a>
                        <div class="collapse" id="speakersMenu">
                            <ul class="nav flex-column ms-3">
                                <li class="nav-item">
                                    <a class="nav-link py-2" href="admin_speaker_manage.php">
                                        <i class="bi bi-person-gear"></i> Speaker Management
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link py-2" href="admin_quiz_manage.php">
                                        <i class="bi bi-ui-checks-grid"></i> Quiz Management
                                        <span class="badge bg-white text-primary ms-1"><?= (int)$quizzesCount ?></span>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </li>

                    <li class="nav-item mt-4">
                        <a class="nav-link text-white-50" href="admin_logout.php">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>
        </nav>

        <!-- Enhanced Main Content -->
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="page-header fade-in">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center">
                    <div>
                        <h1 class="page-title">Admin Dashboard</h1>
                        <p class="text-muted mb-0">Manage speakers, quizzes, and convention settings</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="admin_speaker_manage.php" class="btn btn-outline-primary">
                            <i class="bi bi-person-video3 me-1"></i> Manage Speakers
                        </a>
                        <a href="admin_quiz_manage.php" class="btn btn-primary">
                            <i class="bi bi-ui-checks-grid me-1"></i> Manage Quizzes
                        </a>
                    </div>
                </div>
            </div>

            <!-- Enhanced Stat Cards -->
            <div class="row g-4 mb-4 slide-up">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small text-uppercase fw-bold">Function Rooms</div>
                                <div class="stat-number"><?= (int)$roomsCount ?></div>
                            </div>
                            <i class="bi bi-door-open icon"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small text-uppercase fw-bold">Speakers</div>
                                <div class="stat-number"><?= (int)$speakersCount ?></div>
                            </div>
                            <i class="bi bi-people icon"></i>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="card-body">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <div>
                                    <div class="text-muted small text-uppercase fw-bold">Quizzes</div>
                                    <div class="stat-number"><?= (int)$quizzesCount ?></div>
                                </div>
                                <i class="bi bi-ui-checks-grid icon"></i>
                            </div>
                            <div>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="small text-muted fw-bold">Coverage</span>
                                    <span class="badge <?= $quizCoverage >= 70 ? 'badge-soft-success' : ($quizCoverage >= 30 ? 'badge-soft-warning' : 'badge-soft-danger') ?>"><?= (int)$quizCoverage ?>%</span>
                                </div>
                                <div class="progress mb-2">
                                    <div class="progress-bar" role="progressbar" style="width: <?= (int)$quizCoverage ?>%;" aria-valuenow="<?= (int)$quizCoverage ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <div class="small text-muted"><?= max(0, $speakersCount - $speakersNoQuiz) ?>/<?= (int)$speakersCount ?> speakers have quizzes</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-muted small text-uppercase fw-bold">Quiz Responses</div>
                                <div class="stat-number"><?= (int)$quizRespCount ?></div>
                            </div>
                            <i class="bi bi-clipboard-check icon"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Enhanced Content Grid -->
            <div class="row g-4">
                <div class="col-lg-8">
                    <!-- Speakers Without Quiz Card -->
                    <div class="enhanced-card mb-4 fade-in">
                        <div class="card-header">
                            <h5 class="mb-0 d-flex align-items-center">
                                <i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>
                                Speakers Without a Quiz
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if ($speakersNoQuiz <= 0): ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-check-circle-fill text-success mb-2" style="font-size: 3rem;"></i>
                                    <h6 class="text-success">All Set!</h6>
                                    <span class="badge badge-soft-success">All speakers have quizzes configured</span>
                                </div>
                            <?php else: ?>
                                <div class="d-flex align-items-center justify-content-between mb-3">
                                    <span class="badge badge-soft-warning fs-6"><?= (int)$speakersNoQuiz ?> speakers pending</span>
                                    <small class="text-muted">Click "Create Quiz" to add evaluations</small>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle">
                                        <thead>
                                            <tr>
                                                <th><i class="bi bi-hash me-1"></i>ID</th>
                                                <th><i class="bi bi-person me-1"></i>Speaker</th>
                                                <th><i class="bi bi-door-open me-1"></i>Room</th>
                                                <th><i class="bi bi-calendar me-1"></i>Day</th>
                                                <th width="120"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php
                                        $list = $db->query("
                                            SELECT s.id, s.name,
                                                   fr.name AS room_name,
                                                   fd.label AS day_label
                                            FROM speakers s
                                            LEFT JOIN quizzes q ON q.speaker_id = s.id
                                            LEFT JOIN function_rooms fr ON fr.id = s.room_id
                                            LEFT JOIN function_room_days fd ON fd.id = fr.day_id
                                            WHERE q.id IS NULL
                                            ORDER BY fd.day_date ASC, fr.id ASC, s.name ASC
                                            LIMIT 15
                                        ");
                                        if ($list) {
                                            while ($row = $list->fetch_assoc()):
                                        ?>
                                            <tr>
                                                <td><span class="badge bg-light text-dark"><?= (int)$row['id'] ?></span></td>
                                                <td class="fw-semibold"><?= htmlspecialchars($row['name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars($row['room_name'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td><?= htmlspecialchars($row['day_label'] ?? '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                <td>
                                                    <a href="admin_quiz_manage.php?speaker_id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-primary">
                                                        <i class="bi bi-plus-circle me-1"></i> Create
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php
                                            endwhile;
                                        } else {
                                            echo '<tr><td colspan="5" class="text-center text-muted py-4">No data available</td></tr>';
                                        }
                                        ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Quizzes Card -->
                    <div class="enhanced-card slide-up">
                        <div class="card-header">
                            <h5 class="mb-0 d-flex align-items-center">
                                <i class="bi bi-clock-fill text-info me-2"></i>
                                Recent Quiz Activity
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recentQuizzes)): ?>
                                <div class="text-center py-4">
                                    <i class="bi bi-ui-checks text-muted mb-2" style="font-size: 3rem; opacity: 0.3;"></i>
                                    <div class="text-muted">No quizzes created yet</div>
                                    <a href="admin_quiz_manage.php" class="btn btn-sm btn-primary mt-2">Create Your First Quiz</a>
                                </div>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($recentQuizzes as $q): ?>
                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm bg-primary rounded-circle d-flex align-items-center justify-content-center me-3">
                                                    <i class="bi bi-ui-checks text-white"></i>
                                                </div>
                                                <div>
                                                    <div class="fw-semibold"><?= htmlspecialchars($q['speaker_name'] ?? 'Speaker', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                                    <small class="text-muted">Quiz #<?= (int)$q['id'] ?></small>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <small class="text-muted"><?= date('M j, Y', strtotime($q['created_at'] ?? '')) ?></small>
                                                <div><small class="text-muted"><?= date('g:i A', strtotime($q['created_at'] ?? '')) ?></small></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Enhanced Right Column -->
                <div class="col-lg-4">
                    <!-- Certificate Controls -->
                    <div class="enhanced-card mb-4 fade-in">
                        <div class="card-header">
                            <div class="d-flex align-items-center justify-content-between">
                                <h5 class="mb-0">
                                    <i class="bi bi-award text-warning me-2"></i>
                                    Certificate Control
                                </h5>
                                <?php if ($cert_enabled === '1'): ?>
                                    <span class="badge badge-soft-success">
                                        <i class="bi bi-check2-circle me-1"></i>Active
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-soft-danger">
                                        <i class="bi bi-x-circle me-1"></i>Inactive
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-4">
                                <i class="bi bi-info-circle me-1"></i>
                                Control certificate generation for attendees. Set auto-enable date for automatic activation.
                            </p>

                            <form method="post" action="../api/toggle_certificate.php">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="certEnabled" name="cert_enabled" value="1" <?= ($cert_enabled === '1') ? 'checked' : '' ?>>
                                        <label class="form-check-label fw-semibold" for="certEnabled">
                                            Enable Certificate Generation
                                        </label>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-semibold">
                                        <i class="bi bi-calendar-event me-1"></i>
                                        Auto-enable Date
                                    </label>
                                    <input type="date" class="form-control" name="cert_auto_date" value="<?= htmlspecialchars($cert_auto_date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" />
                                    <small class="text-muted">Certificates will be automatically enabled from this date</small>
                                </div>

                                <button class="btn btn-primary w-100">
                                    <i class="bi bi-save me-1"></i> Save Settings
                                </button>
                            </form>

                            <div class="mt-3 p-3 bg-light rounded-3">
                                <div class="d-flex align-items-center">
                                    <i class="bi bi-info-circle text-primary me-2"></i>
                                    <div class="small">
                                        <strong>Status:</strong> <?= ($cert_enabled === '1') ? 'Enabled' : 'Disabled' ?>
                                        <?= $cert_auto_date ? '<br><strong>Auto from:</strong> ' . htmlspecialchars($cert_auto_date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="enhanced-card slide-up">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-lightning-fill text-warning me-2"></i>
                                Quick Actions
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-3">
                                <a href="admin_speaker_manage.php" class="btn btn-outline-primary">
                                    <i class="bi bi-person-video3 me-2"></i> 
                                    Add/Manage Speakers
                                </a>
                                <a href="admin_quiz_manage.php" class="btn btn-primary">
                                    <i class="bi bi-ui-checks-grid me-2"></i> 
                                    Create/Manage Quizzes
                                </a>
                                <a href="admin_manage_function_rooms.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-door-open me-2"></i> 
                                    Manage Function Rooms
                                </a>
                            </div>
                            
                            <hr class="my-4">
                            
                            <div class="p-3 bg-primary bg-opacity-10 rounded-3">
                                <div class="d-flex align-items-start">
                                    <i class="bi bi-lightbulb-fill text-warning me-2 mt-1"></i>
                                    <div class="small">
                                        <strong class="text-primary">Pro Tip:</strong><br>
                                        Aim for 100% quiz coverage to ensure all speaker sessions have proper evaluations and feedback collection.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Enhanced interactions
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });

    // Add loading states to buttons
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Saving...';
                submitBtn.disabled = true;
            }
        });
    });

    // Animate progress bars on scroll
    const progressBars = document.querySelectorAll('.progress-bar');
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const progressBar = entry.target;
                const width = progressBar.style.width;
                progressBar.style.width = '0%';
                setTimeout(() => {
                    progressBar.style.width = width;
                }, 100);
            }
        });
    }, { threshold: 0.5 });

    progressBars.forEach(bar => observer.observe(bar));

    // Add hover effects to stat cards
    document.querySelectorAll('.stat-card').forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-8px) scale(1.02)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0) scale(1)';
        });
    });
});
</script>
</body>
</html>