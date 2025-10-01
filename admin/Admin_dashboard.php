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

// NEW: evaluations count (if table exists)
$evalCount = 0;
$qe = $db->query("SELECT COUNT(*) FROM speaker_evaluations");
if ($qe) { $evalCount = (int)$qe->fetch_row()[0]; }

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

// Attendance monitoring (safe if table doesn't exist)
function table_exists(mysqli $db, string $name): bool {
    $name = $db->real_escape_string($name);
    $res = $db->query("SHOW TABLES LIKE '{$name}'");
    return $res && $res->num_rows > 0;
}

function get_table_columns(mysqli $db, string $table): array {
    $cols = [];
    if ($res = $db->query("SHOW COLUMNS FROM `{$table}`")) {
        while ($row = $res->fetch_assoc()) {
            if (!empty($row['Field'])) $cols[] = $row['Field'];
        }
    }
    return $cols;
}

function attendance_count_expr(mysqli $db): string {
    $cols = get_table_columns($db, 'speaker_attendance');
    if (empty($cols)) return 'COUNT(*)';
    // Prefer a user identifier column
    foreach (['user_id','attendee_id','account_id','participant_id','member_id'] as $c) {
        if (in_array($c, $cols, true)) return "COUNT(DISTINCT sa.`{$c}`)";
    }
    // Fall back to PK id if present
    if (in_array('id', $cols, true)) return "COUNT(sa.`id`)";
    return "COUNT(*)";
}

$hasAttendanceTable = table_exists($db, 'speaker_attendance');

// days (for attendance by day only)
$days = [];
if ($r = $db->query("SELECT id, label, day_date FROM function_room_days ORDER BY day_date ASC")) {
    while ($row = $r->fetch_assoc()) $days[] = $row;
}

// NatCon Evaluation (normalized) questions / responses counts
$natconQuestionCount = 0; $natconResponseCount = 0;
if (table_exists($db, 'natcon_eval_questions')) {
    $rQ = $db->query("SELECT COUNT(*) FROM natcon_eval_questions");
    if ($rQ) $natconQuestionCount = (int)$rQ->fetch_row()[0];
}
if (table_exists($db, 'natcon_eval_responses')) {
    $rR = $db->query("SELECT COUNT(*) FROM natcon_eval_responses");
    if ($rR) $natconResponseCount = (int)$rR->fetch_row()[0];
}

// Attendance aggregated by day only
$attendanceByDay = [];
if ($hasAttendanceTable) {
    $countExpr = attendance_count_expr($db);
    $sqlDay = "
        SELECT fd.id AS day_id, fd.label AS day_label, {$countExpr} AS attendees
        FROM function_room_days fd
        LEFT JOIN function_rooms fr ON fr.day_id = fd.id
        LEFT JOIN speakers s ON s.room_id = fr.id
        LEFT JOIN speaker_attendance sa ON sa.speaker_id = s.id
        GROUP BY fd.id, fd.label
        ORDER BY fd.day_date ASC";
    if ($res = $db->query($sqlDay)) {
        while ($row = $res->fetch_assoc()) $attendanceByDay[] = $row;
    }
}
$attendanceTotalAllDays = 0; 
foreach ($attendanceByDay as $d) { 
    $attendanceTotalAllDays += (int)($d['attendees'] ?? 0); 
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
            --primary: #3b82f6;
            --primary-dark: #2563eb;
            --primary-light: #93c5fd;
            --success: #10b981;
            --success-light: #d1fae5;
            --warning: #f59e0b;  
            --warning-light: #fef3c7;
            --danger: #ef4444;
            --danger-light: #fee2e2;
            --info: #06b6d4;
            --info-light: #cffafe;
            
            /* Modern Design System */
            --bg-primary: #ffffff;
            --bg-secondary: #f8fafc;
            --bg-tertiary: #f1f5f9;
            --border-color: #e2e8f0;
            --border-light: #f1f5f9;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-tertiary: #64748b;
            
            /* Shadows */
            --shadow-xs: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-sm: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
            
            /* Radius & Transitions */
            --radius-sm: 0.375rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
            --transition-fast: 150ms cubic-bezier(0.4, 0, 0.2, 1);
            --transition-normal: 200ms cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { 
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body { 
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 14px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        /* Modern Sidebar */
        .sidebar {
            background: var(--bg-primary);
            border-right: 1px solid var(--border-color);
            min-height: 100vh;
            box-shadow: var(--shadow-sm);
        }

        .sidebar .nav-link {
            color: var(--text-secondary);
            border-radius: var(--radius-md);
            margin: 2px 12px;
            padding: 10px 16px;
            transition: all var(--transition-fast);
            font-weight: 500;
            font-size: 14px;
            border: 1px solid transparent;
            display: flex;
            align-items: center;
        }

        .sidebar .nav-link:hover {
            color: var(--text-primary);
            background: var(--bg-tertiary);
            border-color: var(--border-light);
            text-decoration: none;
        }

        .sidebar .nav-link.active {
            color: var(--primary);
            background: linear-gradient(135deg, var(--primary-light), rgba(59, 130, 246, 0.1));
            border-color: rgba(59, 130, 246, 0.2);
            font-weight: 600;
        }

        .sidebar .nav-link i { 
            margin-right: 12px; 
            width: 16px;
            font-size: 16px;
            text-align: center;
        }

        /* Welcome Card */
        .welcome-section {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: var(--radius-lg);
            padding: 20px;
            margin: 16px;
            color: white;
            box-shadow: var(--shadow-md);
        }

        .welcome-section .text-white-50 {
            opacity: 0.8;
        }

        /* Enhanced Cards */
        .stat-card {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            transition: all var(--transition-normal);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            border-color: var(--border-light);
        }

        .stat-card .card-body { 
            padding: 24px; 
        }

        .stat-number {
            font-size: 2.25rem;
            font-weight: 700;
            color: var(--primary);
            line-height: 1;
            margin-bottom: 4px;
        }

        .stat-card .icon {
            font-size: 1.5rem;
            color: var(--text-tertiary);
        }

        /* Modern Progress Bars */
        .progress {
            height: 6px;
            border-radius: 3px;
            background: var(--bg-tertiary);
            overflow: hidden;
        }

        .progress-bar {
            background: linear-gradient(90deg, var(--success), var(--info));
            border-radius: 3px;
            transition: width 0.6s ease;
        }

        /* Enhanced Badges */
        .badge-soft { 
            background: var(--bg-tertiary);
            color: var(--text-secondary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            padding: 4px 8px;
            font-weight: 500;
            font-size: 12px;
        }
        
        .badge-soft-success { 
            background: var(--success-light);
            color: var(--success);
            border-color: rgba(16, 185, 129, 0.2);
        }
        
        .badge-soft-warning { 
            background: var(--warning-light);
            color: var(--warning);
            border-color: rgba(245, 158, 11, 0.2);
        }
        
        .badge-soft-danger { 
            background: var(--danger-light);
            color: var(--danger);
            border-color: rgba(239, 68, 68, 0.2);
        }

        /* Page Header */
        .page-header {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
        }

        .page-title {
            color: var(--text-primary);
            font-weight: 700;
            font-size: 1.875rem;
            line-height: 1.2;
            margin: 0;
        }

        /* Enhanced Cards */
        .enhanced-card {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            transition: all var(--transition-normal);
            overflow: hidden;
        }

        .enhanced-card:hover {
            box-shadow: var(--shadow-md);
            border-color: var(--border-light);
        }

        .enhanced-card .card-header {
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-color);
            padding: 16px 20px;
        }

        .enhanced-card .card-body {
            padding: 20px;
        }

        /* Buttons */
        .btn {
            border-radius: var(--radius-md);
            font-weight: 500;
            font-size: 14px;
            padding: 8px 16px;
            transition: all var(--transition-fast);
            border: 1px solid transparent;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
            color: white;
        }

        .btn-outline-primary {
            color: var(--primary);
            border-color: var(--primary);
            background: transparent;
        }

        .btn-outline-primary:hover {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        /* Tables */
        .table {
            border-radius: var(--radius-md);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
        }

        .table thead th {
            background: var(--bg-secondary);
            color: var(--text-primary);
            border: none;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.05em;
            padding: 12px 16px;
        }

        .table tbody tr {
            transition: all var(--transition-fast);
            border: none;
        }

        .table tbody tr:hover {
            background: var(--bg-tertiary);
        }

        .table tbody td {
            border-color: var(--border-light);
            padding: 12px 16px;
        }

        /* List Groups */
        .list-group-item {
            border: none;
            border-bottom: 1px solid var(--border-light);
            padding: 12px 16px;
            transition: all var(--transition-fast);
            background: transparent;
        }

        .list-group-item:hover {
            background: var(--bg-tertiary);
        }

        .list-group-item:last-child {
            border-bottom: none;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .stat-card .card-body {
                padding: 16px;
            }
            
            .page-header {
                padding: 16px;
                margin-bottom: 16px;
            }

            .page-title {
                font-size: 1.5rem;
            }
        }

        /* Animation Classes */
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }

        .slide-up {
            animation: slideUp 0.5s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <!-- Modern Sidebar -->
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
                            <span class="badge bg-primary text-white ms-2"><?= (int)$roomsCount ?></span>
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
                                        <span class="badge bg-primary text-white ms-1"><?= (int)$daysCount ?></span>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </li>

                    <!-- Speakers Section -->
                    <li class="nav-item mt-2">
                        <a class="nav-link" data-bs-toggle="collapse" href="#speakersMenu" role="button" aria-expanded="false" aria-controls="speakersMenu">
                            <i class="bi bi-person-video3"></i> Speakers
                            <span class="badge bg-primary text-white ms-2"><?= (int)$speakersCount ?></span>
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
                                        <span class="badge bg-primary text-white ms-1"><?= (int)$quizzesCount ?></span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link py-2" href="admin_export_responses.php">
                                        <i class="bi bi-filetype-csv"></i> Export Responses (per Speaker)
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </li>

                    <!-- Evaluations Section -->
                    <li class="nav-item mt-2">
                        <a class="nav-link" data-bs-toggle="collapse" href="#evaluationsMenu" role="button" aria-expanded="false" aria-controls="evaluationsMenu">
                            <i class="bi bi-list-check"></i> Evaluations
                            <i class="bi bi-chevron-down float-end"></i>
                        </a>
                        <div class="collapse" id="evaluationsMenu">
                            <ul class="nav flex-column ms-3">
                                <li class="nav-item">
                                    <a class="nav-link py-2" href="admin_evaluations.php">
                                        <i class="bi bi-clipboard-data"></i> Speaker Evaluations
                                        <span class="badge bg-primary text-white ms-1"><?= (int)$evalCount ?></span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link py-2" href="admin_eval_natcon.php">
                                        <i class="bi bi-list-check"></i> NatCon Evaluation
                                        <span class="badge bg-primary text-white ms-1">Q<?= (int)$natconQuestionCount ?></span>
                                        <span class="badge bg-success text-white ms-1">R<?= (int)$natconResponseCount ?></span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link py-2" href="admin_eval_natcon_reports.php">
                                        <i class="bi bi-graph-up"></i> Eval Reports
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </li>

                    <!-- Certificates -->
                    <li class="nav-item mt-2">
                        <a class="nav-link" href="admin_certificate_settings.php">
                            <i class="bi bi-award"></i> Certificate Settings
                        </a>
                    </li>

                    <li class="nav-item mt-4">
                        <a class="nav-link text-secondary" href="admin_logout.php">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>
        </nav>

        <!-- Main Content -->
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="page-header fade-in">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center">
                    <div>
                        <h1 class="page-title">Admin Dashboard</h1>
                        <p class="text-secondary mb-0">Manage speakers, quizzes, and convention settings</p>
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

            <!-- Modern Stat Cards -->
            <div class="row g-4 mb-4 slide-up">
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-secondary small fw-bold text-uppercase">Function Rooms</div>
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
                                <div class="text-secondary small fw-bold text-uppercase">Speakers</div>
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
                                    <div class="text-secondary small fw-bold text-uppercase">Quizzes</div>
                                    <div class="stat-number"><?= (int)$quizzesCount ?></div>
                                </div>
                                <i class="bi bi-ui-checks-grid icon"></i>
                            </div>
                            <div>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="small text-secondary fw-bold">Coverage</span>
                                    <span class="badge <?= $quizCoverage >= 70 ? 'badge-soft-success' : ($quizCoverage >= 30 ? 'badge-soft-warning' : 'badge-soft-danger') ?>"><?= (int)$quizCoverage ?>%</span>
                                </div>
                                <div class="progress mb-2">
                                    <div class="progress-bar" role="progressbar" style="width: <?= (int)$quizCoverage ?>%;" aria-valuenow="<?= (int)$quizCoverage ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <div class="small text-secondary"><?= max(0, $speakersCount - $speakersNoQuiz) ?>/<?= (int)$speakersCount ?> speakers have quizzes</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card">
                        <div class="card-body d-flex align-items-center justify-content-between">
                            <div>
                                <div class="text-secondary small fw-bold text-uppercase">Quiz Responses</div>
                                <div class="stat-number"><?= (int)$quizRespCount ?></div>
                            </div>
                            <i class="bi bi-clipboard-check icon"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Content Cards -->
            <div class="row g-4 mb-4">
              <div class="col-xl-6">
                <div class="enhanced-card slide-up">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 d-flex align-items-center"><i class="bi bi-graph-up text-info me-2"></i>Evaluation Summary</h5>
                    <div class="d-flex gap-2">
                      <a href="admin_eval_natcon.php" class="btn btn-sm btn-outline-primary">Manage</a>
                      <a href="admin_eval_natcon_reports.php" class="btn btn-sm btn-primary">Reports</a>
                    </div>
                  </div>
                  <div class="card-body">
                    <div class="row g-3">
                      <div class="col-6">
                        <div class="p-3 bg-light rounded-3 h-100">
                          <div class="small text-secondary fw-bold">Questions</div>
                          <div class="display-6 mb-0" style="font-size:2.2rem;"><?= (int)$natconQuestionCount ?></div>
                        </div>
                      </div>
                      <div class="col-6">
                        <div class="p-3 bg-light rounded-3 h-100">
                          <div class="small text-secondary fw-bold">Responses</div>
                          <div class="display-6 mb-0" style="font-size:2.2rem;"><?= (int)$natconResponseCount ?></div>
                        </div>
                      </div>
                      <div class="col-12">
                        <div class="small text-secondary">Responses grow as attendees submit the NatCon evaluation form. Use <strong>Reports</strong> for detailed analytics & exports.</div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="col-xl-6">
                <div class="enhanced-card slide-up">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 d-flex align-items-center"><i class="bi bi-calendar2-check text-primary me-2"></i>Attendance by Day</h5>
                    <?php if ($hasAttendanceTable): ?><span class="badge badge-soft">Total: <?= (int)$attendanceTotalAllDays ?></span><?php endif; ?>
                  </div>
                  <div class="card-body">
                    <?php if (!$hasAttendanceTable): ?>
                      <div class="text-secondary">Waiting for attendance setup.</div>
                    <?php else: ?>
                      <?php if (empty($attendanceByDay)): ?>
                        <div class="text-secondary">No attendance yet.</div>
                      <?php else: ?>
                        <div class="list-group list-group-flush">
                          <?php foreach ($attendanceByDay as $d): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                              <div class="fw-semibold"><?= htmlspecialchars($d['day_label'] ?? 'Day', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                              <span class="badge badge-soft"><?= (int)($d['attendees'] ?? 0) ?> attendee<?= ((int)($d['attendees'] ?? 0) === 1) ? '' : 's' ?></span>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-8">
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
                                    <i class="bi bi-ui-checks text-secondary mb-2" style="font-size: 3rem; opacity: 0.3;"></i>
                                    <div class="text-secondary">No quizzes created yet</div>
                                    <a href="admin_quiz_manage.php" class="btn btn-sm btn-primary mt-2">Create Your First Quiz</a>
                                </div>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($recentQuizzes as $q): ?>
                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                            <div class="d-flex align-items-center">
                                                <div class="avatar-sm bg-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 32px; height: 32px;">
                                                    <i class="bi bi-ui-checks text-white small"></i>
                                                </div>
                                                <div>
                                                    <div class="fw-semibold"><?= htmlspecialchars($q['speaker_name'] ?? 'Speaker', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                                    <small class="text-secondary">Quiz #<?= (int)$q['id'] ?></small>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <small class="text-secondary"><?= date('M j, Y', strtotime($q['created_at'] ?? '')) ?></small>
                                                <div><small class="text-secondary"><?= date('g:i A', strtotime($q['created_at'] ?? '')) ?></small></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="enhanced-card slide-up">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="bi bi-lightning-fill text-warning me-2"></i>
                                Quick Actions
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
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
                                <a href="admin_certificate_settings.php" class="btn btn-outline-success">
                                    <i class="bi bi-award me-2"></i>
                                    Certificate Settings
                                </a>
                                <a href="admin_eval_natcon.php" class="btn btn-outline-info">
                                    <i class="bi bi-list-check me-2"></i>
                                    NatCon Evaluation (Questions)
                                </a>
                                <a href="admin_eval_natcon_reports.php" class="btn btn-outline-dark">
                                    <i class="bi bi-graph-up me-2"></i>
                                    Evaluation Reports
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
// Modern interactions
document.addEventListener('DOMContentLoaded', function() {
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

    // Add subtle hover effects to stat cards
    document.querySelectorAll('.stat-card').forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-4px)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });
});
</script>
</body>
</html>