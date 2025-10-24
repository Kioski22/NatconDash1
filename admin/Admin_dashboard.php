<?php

session_start();
require_once __DIR__ . '/../db.php';

// normalize DB connection
if (isset($conn) && $conn instanceof mysqli) { $db = $conn; }
elseif (isset($mysqli) && $mysqli instanceof mysqli) { $db = $mysqli; }
else { $db = null; }
if (!($db instanceof mysqli)) { http_response_code(500); echo "Database connection not available."; exit; }

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(16));

// auth
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') { header('Location: admin_login.php'); exit; }
$admin_name = $_SESSION['user_name'] ?? 'Admin';

// helper
if (!function_exists('dash_count')) { function dash_count(mysqli $db, string $sql): int { $r=$db->query($sql); if(!$r) return 0; $row=$r->fetch_row(); return (int)($row[0]??0); } }
function table_exists(mysqli $db,string $name): bool { $name=$db->real_escape_string($name); $r=$db->query("SHOW TABLES LIKE '{$name}'"); return $r && $r->num_rows>0; }

// metrics
$roomsCount    = dash_count($db,"SELECT COUNT(*) FROM function_rooms");
$daysCount     = dash_count($db,"SELECT COUNT(*) FROM function_room_days");
$speakersCount = dash_count($db,"SELECT COUNT(*) FROM speakers");
$quizzesCount=0; if($q=$db->query("SELECT COUNT(*) FROM quizzes")){ $quizzesCount=(int)$q->fetch_row()[0]; }
$quizRespCount=0; if($q2=$db->query("SELECT COUNT(*) FROM quiz_responses")){ $quizRespCount=(int)$q2->fetch_row()[0]; }
$evalCount=0; if($qe=$db->query("SELECT COUNT(*) FROM speaker_evaluations")){ $evalCount=(int)$qe->fetch_row()[0]; }
$dynEvalResponses=0; $dynActiveSetQuestions=0; $dynActiveSetId=0; $dynActiveSetName='';
if (table_exists($db,'speaker_eval_question_sets') && table_exists($db,'speaker_eval_questions')) {
    if($r=$db->query("SELECT id,set_name FROM speaker_eval_question_sets WHERE is_active=1 LIMIT 1")) { if($row=$r->fetch_assoc()){ $dynActiveSetId=(int)$row['id']; $dynActiveSetName=$row['set_name']; } }
    if($dynActiveSetId>0) {
        if($r2=$db->query("SELECT COUNT(*) FROM speaker_eval_questions WHERE question_set_id=".$dynActiveSetId." AND is_active=1")) { $dynActiveSetQuestions=(int)$r2->fetch_row()[0]; }
    }
}
if (table_exists($db,'speaker_eval_responses')) { if($r3=$db->query("SELECT COUNT(*) FROM speaker_eval_responses")) { $dynEvalResponses=(int)$r3->fetch_row()[0]; } }
$speakersNoQuiz=0; if($qr=$db->query("SELECT COUNT(*) FROM speakers s LEFT JOIN quizzes q ON q.speaker_id=s.id WHERE q.id IS NULL")){ $speakersNoQuiz=(int)$qr->fetch_row()[0]; }
$quizCoverage = ($speakersCount>0)? round((($speakersCount-$speakersNoQuiz)/$speakersCount)*100):0;

// recent quizzes
$recentQuizzes=[]; if($rq=$db->query("SELECT q.id,q.created_at,s.name AS speaker_name FROM quizzes q JOIN speakers s ON s.id=q.speaker_id ORDER BY q.created_at DESC LIMIT 8")){ while($row=$rq->fetch_assoc()) $recentQuizzes[]=$row; }

// attendance
$hasAttendanceMain = table_exists($db,'attendance');
$hasSpeakerAttendance = table_exists($db,'speaker_attendance');
$hasDayNumberCol = false;
if($hasAttendanceMain){ if($rc=$db->query("SHOW COLUMNS FROM attendance LIKE 'day_number'")){ $hasDayNumberCol = $rc->num_rows>0; $rc->close(); } }

$attendanceByDay=[];
// Build day list in order
$daysList=[]; if($rs=$db->query("SELECT id,label FROM function_room_days ORDER BY day_date ASC")){ while($r=$rs->fetch_assoc()) $daysList[]=$r; }

if($hasAttendanceMain && $hasDayNumberCol){
    // Count by attendance.day_number (users submitting via modal)
    $counts=[];
    if($rc=$db->query("SELECT day_number, COUNT(*) AS c FROM attendance GROUP BY day_number")){
        while($row=$rc->fetch_assoc()){
            $dn = (int)$row['day_number']; $counts[$dn] = (int)$row['c'];
        }
        $rc->close();
    }
    // Map ordered days to 1-based day_number
    $idx=0; foreach($daysList as $d){ $idx++; $attendanceByDay[] = ['id'=>(int)$d['id'], 'label'=>$d['label'], 'attendees'=> (int)($counts[$idx] ?? 0) ]; }
}
elseif($hasSpeakerAttendance){
    // Fallback to speaker_attendance-derived counts (by day via speakers->rooms)
    $sqlDay = "SELECT fd.id, fd.label, COALESCE(cnt.c,0) AS attendees
               FROM function_room_days fd
               LEFT JOIN (
                   SELECT fr.day_id AS day_id, COUNT(*) AS c
                   FROM speaker_attendance sa
                   JOIN speakers s ON sa.speaker_id = s.id
                   JOIN function_rooms fr ON s.room_id = fr.id
                   GROUP BY fr.day_id
               ) cnt ON cnt.day_id = fd.id
               ORDER BY fd.day_date ASC";
    if($rs=$db->query($sqlDay)){
        while($r=$rs->fetch_assoc()) $attendanceByDay[]=$r;
    }
} else {
    // No attendance sources; list days with zero counts
    foreach($daysList as $d){ $attendanceByDay[] = ['id'=>(int)$d['id'], 'label'=>$d['label'], 'attendees'=>0]; }
}
$attendanceTotalAllDays=0; foreach($attendanceByDay as $d){ $attendanceTotalAllDays += (int)($d['attendees']??0); }

// natcon eval stats
$natconQuestionCount=0; if(table_exists($db,'natcon_eval_questions')){ $r=$db->query("SELECT COUNT(*) FROM natcon_eval_questions"); if($r) $natconQuestionCount=(int)$r->fetch_row()[0]; }
$natconResponseCount=0; if(table_exists($db,'natcon_eval_responses')){ $r=$db->query("SELECT COUNT(*) FROM natcon_eval_responses"); if($r) $natconResponseCount=(int)$r->fetch_row()[0]; }

// speakers missing quiz & department certificate template
$speakersMissingQuizCert=[]; $sqlMissing="SELECT s.id,s.name,s.title,s.topic, s.technical_department_id, COALESCE(td.name,'(No Department)') AS dept_name
    FROM speakers s
    LEFT JOIN quizzes q ON q.speaker_id=s.id
    LEFT JOIN technical_departments td ON td.id=s.technical_department_id
    LEFT JOIN department_certificate_templates dct ON dct.technical_department_id=s.technical_department_id
    WHERE s.program_type='sub' AND q.id IS NULL AND dct.id IS NULL ORDER BY td.name ASC, s.name ASC";
if($res=$db->query($sqlMissing)){ while($row=$res->fetch_assoc()) $speakersMissingQuizCert[]=$row; }
$missingQuizCertCount=count($speakersMissingQuizCert);
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
        /* CSS Custom Properties (Variables) */
        :root {
            --primary: #667eea;
            --primary-dark: #5a6fd8;
            --primary-light: rgba(102, 126, 234, 0.1);
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success: #10b981;
            --success-light: rgba(16, 185, 129, 0.1);
            --success-gradient: linear-gradient(135deg, #10b981 0%, #059669 100%);
            --warning: #f59e0b;
            --warning-light: rgba(245, 158, 11, 0.1);
            --warning-gradient: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            --danger: #ef4444;
            --danger-light: rgba(239, 68, 68, 0.1);
            --danger-gradient: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            --info: #06b6d4;
            --info-light: rgba(6, 182, 212, 0.1);
            --info-gradient: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            
            --bg-primary: #ffffff;
            --bg-secondary: #f8fafc;
            --bg-tertiary: #f1f5f9;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --text-tertiary: #94a3b8;
            --border-color: #e2e8f0;
            --border-light: #cbd5e1;
            
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            
            --radius-sm: 0.375rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
            
            --transition-fast: 0.15s ease-in-out;
            --transition-normal: 0.3s ease-in-out;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            color: var(--text-primary);
            min-height: 100vh;
        }

        main {
            background: var(--bg-secondary);
        }

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
            background: var(--primary-gradient);
            border-radius: var(--radius-lg);
            padding: 20px;
            margin: 16px;
            color: white;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
        }
        
        .welcome-section::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: translate(25px, -25px);
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
            background: var(--primary-gradient);
            background-clip: text;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            line-height: 1;
            margin-bottom: 4px;
        }

        .stat-card .icon {
            font-size: 1.5rem;
            color: var(--text-tertiary);
        }

        /* Modern Progress Bars */
        .progress {
            height: 8px;
            border-radius: 4px;
            background: var(--bg-tertiary);
            overflow: hidden;
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);
        }

        .progress-bar {
            background: var(--success-gradient);
            border-radius: 4px;
            transition: width 0.8s ease;
            position: relative;
        }
        
        .progress-bar::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.2) 50%, transparent 100%);
            animation: shimmer 2s infinite;
        }
        
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
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

        /* Ensure Bootstrap badges are visible */
        .badge {
            font-weight: 600;
        }

        .bg-primary {
            background-color: var(--primary) !important;
        }

        .bg-light {
            background-color: var(--bg-tertiary) !important;
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

        /* Enhanced Buttons */
        .btn {
            border-radius: var(--radius-md);
            font-weight: 500;
            font-size: 14px;
            padding: 8px 16px;
            transition: all var(--transition-fast);
            border: 1px solid transparent;
            position: relative;
            overflow: hidden;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .btn-primary {
            background: var(--primary-gradient);
            border: none;
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-gradient);
            color: white;
            transform: translateY(-2px);
        }

        .btn-outline-primary {
            color: var(--primary);
            border-color: var(--primary);
            background: transparent;
        }

        .btn-outline-primary:hover {
            background: var(--primary-gradient);
            border: none;
            color: white;
        }
        
        .btn-outline-success:hover {
            background: var(--success-gradient);
            border: none;
            color: white;
        }
        
        .btn-outline-info:hover {
            background: var(--info-gradient);
            border: none;
            color: white;
        }

        /* Tables */
        .table {
            border-radius: var(--radius-md);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            background: var(--bg-primary) !important;
            border: 1px solid var(--border-color);
        }

        .table thead th {
            background: var(--bg-secondary) !important;
            color: var(--text-primary) !important;
            border: none;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.05em;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-light) !important;
        }

        .table tbody tr {
            transition: all var(--transition-fast);
            border: none;
            background: var(--bg-primary) !important;
        }

        .table tbody tr:hover {
            background: var(--bg-tertiary) !important;
        }

        .table tbody td {
            border-color: var(--border-light) !important;
            padding: 12px 16px;
            color: var(--text-primary) !important;
        }

        /* List Groups */
        .list-group-item {
            border: none;
            border-bottom: 1px solid var(--border-light);
            padding: 12px 16px;
            transition: all var(--transition-fast);
            background: var(--bg-primary);
        }

        .list-group-item:hover {
            background: var(--bg-tertiary);
        }

        .list-group-item:last-child {
            border-bottom: none;
        }

        .list-group-flush .list-group-item {
            background: transparent;
        }

        .list-group-flush .list-group-item:hover {
            background: var(--bg-tertiary);
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
        
        /* Section Headers */
        .section-header {
            margin-top: 2rem;
            margin-bottom: 1.5rem;
        }
        
        .section-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: rgba(102, 126, 234, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Enhanced stat cards with gradients */
        .stat-card .card-body {
            background: linear-gradient(135deg, rgba(255,255,255,0.9) 0%, rgba(248,250,252,0.9) 100%);
            backdrop-filter: blur(10px);
            border-radius: var(--radius-lg);
        }
        
        /* Enhanced sidebar navigation */
        .sidebar .nav-link .badge {
            font-size: 10px;
            padding: 3px 6px;
            border-radius: 10px;
        }
        
        /* Enhanced progress coverage styling */
        .progress {
            position: relative;
        }
        
        .progress::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, rgba(255,255,255,0) 0%, rgba(255,255,255,0.3) 50%, rgba(255,255,255,0) 100%);
            border-radius: 4px;
            animation: progressShine 3s ease-in-out infinite;
        }
        
        @keyframes progressShine {
            0%, 100% { opacity: 0; transform: translateX(-100%); }
            50% { opacity: 1; transform: translateX(100%); }
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
                    <li class="nav-item">
                        <a class="nav-link" href="admin_users.php">
                            <i class="bi bi-people"></i> Users
                        </a>
                    </li>

                    <!-- Function Rooms Section -->
                    <li class="nav-item mt-3">
                        <a class="nav-link" data-bs-toggle="collapse" href="#functionRoomsMenu" role="button" aria-expanded="false" aria-controls="functionRoomsMenu">
                            <i class="bi bi-door-open"></i> Function Rooms 
                            <span class="badge bg-primary text-white ms-2"><?= (int)$roomsCount ?></span>
                            <i class="bi bi-chevron-down float-end"> </i>
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
                            <i class="bi bi-chevron-down float-end"> </i>
                        </a>
                        <div class="collapse" id="speakersMenu">
                            <ul class="nav flex-column ms-3">
                                <li class="nav-item">
                                    <a class="nav-link py-2" href="admin_speaker_manage.php">
                                        <i class="bi bi-person-gear"></i> Speaker Management
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link py-2" href="admin_technical_departments.php">
                                        <i class="bi bi-diagram-3"></i> Technical Departments
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
                            <i class="bi bi-chevron-down float-end"> </i>
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
                                    <a class="nav-link py-2" href="admin_speaker_eval_questions.php">
                                        <i class="bi bi-sliders"></i> Eval Question Sets
                                        <?php if($dynActiveSetQuestions>0): ?>
                                          <span class="badge bg-success text-white ms-1"><?= (int)$dynActiveSetQuestions ?></span>
                                        <?php endif; ?>
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
                    <li class="nav-item mt-1">
                        <a class="nav-link" href="admin_department_certificate_templates.php">
                            <i class="bi bi-award-fill"></i> Dept Cert Templates
                        </a>
                    </li>
                    <li class="nav-item mt-1">
                        <a class="nav-link" href="admin_certificates_issued.php">
                            <i class="bi bi-collection"></i> Issued Certificates
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
            <div class="page-header fade-in mt-3">
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

                    <!-- SECTION: Structure & Attendance -->
                    <div class="section-header d-flex align-items-center mb-3">
                        <div class="section-icon me-3">
                            <i class="bi bi-building text-primary fs-4"></i>
                        </div>
                        <h4 class="mb-0 text-secondary fw-bold small text-uppercase">Structure & Attendance</h4>
                        <div class="flex-grow-1 ms-3">
                            <hr class="my-0 opacity-25">
                        </div>
                    </div>
                    <div class="row g-4 mb-4 slide-up">
                        <div class="col-md-4 col-lg-3">
                            <div class="stat-card h-100">
                                <div class="card-body d-flex align-items-center justify-content-between">
                                    <div>
                                        <div class="text-secondary small fw-bold text-uppercase">Function Rooms</div>
                                        <div class="stat-number"><?= (int)$roomsCount ?></div>
                                        <div class="small text-secondary mt-1">Days: <?= (int)$daysCount ?></div>
                                    </div>
                                    <i class="bi bi-door-open icon"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-8 col-lg-5">
                            <div class="enhanced-card h-100">
                                <div class="card-header d-flex justify-content-between align-items-center py-2">
                                    <h6 class="mb-0 d-flex align-items-center"><i class="bi bi-calendar2-check text-primary me-2"></i>Attendance by Day</h6>
                                                                                                            <?php if (!empty($attendanceByDay)): ?>
                                                                                                                <span class="badge badge-soft">Total: <?= (int)$attendanceTotalAllDays ?></span>
                                                                                                            <?php else: ?>
                                                                                                                <span class="badge badge-soft">No attendance configured</span>
                                                                                                            <?php endif; ?>
                                </div>
                                <div class="card-body" style="max-height:180px;overflow:auto;">
                                                                        <?php if ($daysCount === 0): ?>
                                                                            <div class="text-secondary small">No function room days configured yet.</div>
                                                                        <?php else: ?>
                                                                            <?php if (empty($attendanceByDay)): ?>
                                                                                <div class="text-secondary small">No attendance yet.</div>
                                                                            <?php else: ?>
                                                                                <div class="list-group list-group-flush">
                                                                                    <?php foreach ($attendanceByDay as $d): ?>
                                                                                        <?php $did = (int)($d['id'] ?? 0); $dlabel = $d['label'] ?? ($d['day_label'] ?? 'Day'); $count = (int)($d['attendees'] ?? 0); ?>
                                                                                        <div class="list-group-item d-flex justify-content-between align-items-center py-2">
                                                                                            <div class="fw-semibold small"><?= htmlspecialchars($dlabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                                                                            <div class="d-flex align-items-center gap-2">
                                                                                                <span class="badge badge-soft small"><?= $count ?></span>
                                                                                                                                                                                                                                                                                                <?php if ($did > 0): ?>
                                                                                                                                                                                                                                                                                                    <?php if ($hasAttendanceMain || $hasSpeakerAttendance): ?>
                                                                                                                                                                                                        <a class="btn btn-sm btn-outline-primary" href="../api/admin_attendance_export.php?day_id=<?= $did ?>" title="Export attendance CSV for this day">
                                                                                                                                                                                                                <i class="bi bi-download"></i>
                                                                                                                                                                                                        </a>
                                                                                                                                                                                                    <?php else: ?>
                                                                                                                                                                                                        <button class="btn btn-sm btn-outline-secondary" type="button" title="Configure attendance first" disabled>
                                                                                                                                                                                                                <i class="bi bi-download"></i>
                                                                                                                                                                                                        </button>
                                                                                                                                                                                                    <?php endif; ?>
                                                                                                                                                                                                <?php endif; ?>
                                                                                            </div>
                                                                                        </div>
                                                                                    <?php endforeach; ?>
                                                                                </div>
                                                                            <?php endif; ?>
                                                                        <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-12 col-lg-4">
                            <div class="enhanced-card h-100">
                                <div class="card-header py-2"><h6 class="mb-0 d-flex align-items-center"><i class="bi bi-graph-up text-info me-2"></i>Evaluation Summary</h6></div>
                                <div class="card-body">
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <div class="p-2 bg-light rounded-3 h-100 text-center">
                                                <div class="small text-secondary fw-bold">Questions</div>
                                                <div class="display-6 mb-0" style="font-size:1.6rem;line-height:1;">
                                                    <?= (int)$natconQuestionCount ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="p-2 bg-light rounded-3 h-100 text-center">
                                                <div class="small text-secondary fw-bold">Responses</div>
                                                <div class="display-6 mb-0" style="font-size:1.6rem;line-height:1;">
                                                    <?= (int)$natconResponseCount ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <div class="small text-secondary mt-1">Speaker eval records: <strong><?= (int)$evalCount ?></strong></div>
                                            <div class="small text-secondary">Dynamic set <?= $dynActiveSetId? '#'.$dynActiveSetId.' ('.htmlspecialchars($dynActiveSetName,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').')':'' ?> questions: <strong><?= (int)$dynActiveSetQuestions ?></strong></div>
                                            <div class="small text-secondary">Dynamic responses: <strong><?= (int)$dynEvalResponses ?></strong></div>
                                            <div class="small text-secondary">Use reports & set manager for deeper analytics.</div>
                                            <div class="mt-2 d-flex gap-1">
                                                <a href="admin_eval_natcon.php" class="btn btn-sm btn-outline-primary flex-fill">Manage</a>
                                                <a href="admin_eval_natcon_reports.php" class="btn btn-sm btn-primary flex-fill">Reports</a>
                                                <a href="admin_speaker_eval_questions.php" class="btn btn-sm btn-outline-success flex-fill">Sets</a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SECTION: Speakers & Quizzes -->
                    <div class="section-header d-flex align-items-center mb-3">
                        <div class="section-icon me-3">
                            <i class="bi bi-people-fill text-info fs-4"></i>
                        </div>
                        <h4 class="mb-0 text-secondary fw-bold small text-uppercase">Speakers & Quizzes</h4>
                        <div class="flex-grow-1 ms-3">
                            <hr class="my-0 opacity-25">
                        </div>
                    </div>
                    <div class="row g-4 mb-4 slide-up">
                        <div class="col-sm-6 col-lg-3">
                            <div class="stat-card h-100">
                                <div class="card-body d-flex align-items-center justify-content-between">
                                    <div>
                                        <div class="text-secondary small fw-bold text-uppercase">Speakers</div>
                                        <div class="stat-number" style="font-size:2rem;">
                                            <?= (int)$speakersCount ?>
                                        </div>
                                    </div>
                                    <i class="bi bi-people icon"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="stat-card h-100">
                                <div class="card-body">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <div>
                                            <div class="text-secondary small fw-bold text-uppercase">Quizzes</div>
                                            <div class="stat-number" style="font-size:2rem;"><?= (int)$quizzesCount ?></div>
                                        </div>
                                        <i class="bi bi-ui-checks-grid icon"></i>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="small text-secondary fw-bold">Coverage</span>
                                        <span class="badge <?= $quizCoverage >= 70 ? 'badge-soft-success' : ($quizCoverage >= 30 ? 'badge-soft-warning' : 'badge-soft-danger') ?>"><?= (int)$quizCoverage ?>%</span>
                                    </div>
                                    <div class="progress mb-1" style="height:5px;">
                                        <div class="progress-bar" role="progressbar" style="width: <?= (int)$quizCoverage ?>%;"></div>
                                    </div>
                                    <div class="small text-secondary"><?= max(0, $speakersCount - $speakersNoQuiz) ?>/<?= (int)$speakersCount ?> with quizzes</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="stat-card h-100">
                                <div class="card-body d-flex align-items-center justify-content-between">
                                    <div>
                                        <div class="text-secondary small fw-bold text-uppercase">Quiz Responses</div>
                                        <div class="stat-number" style="font-size:2rem;"><?= (int)$quizRespCount ?></div>
                                    </div>
                                    <i class="bi bi-clipboard-check icon"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="enhanced-card h-100">
                                <div class="card-header py-2"><h6 class="mb-0 d-flex align-items-center"><i class="bi bi-clock-fill text-info me-2"></i>Recent Quizzes</h6></div>
                                <div class="card-body p-2" style="max-height:220px;overflow:auto;">
                                    <?php if (empty($recentQuizzes)): ?>
                                        <div class="text-center py-3">
                                            <i class="bi bi-ui-checks text-secondary mb-2" style="font-size:2.2rem; opacity:0.25;"></i>
                                            <div class="text-secondary small">No quizzes yet</div>
                                            <a href="admin_quiz_manage.php" class="btn btn-sm btn-primary mt-2">Create</a>
                                        </div>
                                    <?php else: ?>
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($recentQuizzes as $q): ?>
                                                <div class="list-group-item py-2 px-2 d-flex justify-content-between align-items-center">
                                                    <div class="d-flex align-items-center">
                                                        <div class="avatar-sm bg-primary rounded-circle d-flex align-items-center justify-content-center me-2" style="width:28px;height:28px;">
                                                            <i class="bi bi-ui-checks text-white small"></i>
                                                        </div>
                                                        <div>
                                                            <div class="fw-semibold small mb-0"><?= htmlspecialchars($q['speaker_name'] ?? 'Speaker', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                                            <small class="text-secondary">#<?= (int)$q['id'] ?> • <?= date('M j', strtotime($q['created_at'] ?? '')) ?></small>
                                                        </div>
                                                    </div>
                                                    <div class="text-end small text-secondary">
                                                        <?= date('g:i A', strtotime($q['created_at'] ?? '')) ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- SECTION: Certificates & Gaps -->
                    <div class="section-header d-flex align-items-center mb-3 mt-5">
                        <div class="section-icon me-3">
                            <i class="bi bi-award-fill text-warning fs-4"></i>
                        </div>
                        <h4 class="mb-0 text-secondary fw-bold small text-uppercase">Certificates & Gaps</h4>
                        <div class="flex-grow-1 ms-3">
                            <hr class="my-0 opacity-25">
                        </div>
                    </div>

                        <!-- Speakers Missing Quiz & Dept Certificate Template (moved into Certificates & Gaps section) -->
                        <div class="row g-4 mt-1 mb-5">
                            <div class="col-12">
                                <div class="enhanced-card slide-up">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <h5 class="mb-0 d-flex align-items-center"><i class="bi bi-exclamation-triangle text-danger me-2"></i>Sub Program Speakers Without Quiz & Department Certificate</h5>
                                        <span class="badge <?= $missingQuizCertCount>0 ? 'badge-soft-danger':'badge-soft-success' ?>"><?= (int)$missingQuizCertCount ?> missing</span>
                                    </div>
                                    <div class="card-body">
                                        <?php if ($missingQuizCertCount === 0): ?>
                                            <div class="text-secondary">All sub program speakers either have a quiz or their department has a certificate template configured.</div>
                                        <?php else: ?>
                                            <div class="table-responsive" style="max-height:320px;overflow:auto;">
                                                <table class="table table-sm align-middle mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th style="min-width:60px;">ID</th>
                                                            <th>Speaker</th>
                                                            <th style="min-width:160px;">Title / Topic</th>
                                                            <th style="min-width:160px;">Department</th>
                                                            <th>Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                    <?php foreach($speakersMissingQuizCert as $sp): ?>
                                                        <tr>
                                                            <td class="text-muted">#<?= (int)$sp['id'] ?></td>
                                                            <td><?= htmlspecialchars($sp['name'] ?? 'Speaker', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                                                            <td><small><?= htmlspecialchars(!empty($sp['topic']) ? $sp['topic'] : ($sp['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small></td>
                                                            <td><span class="badge badge-soft <?= empty($sp['dept_name']) || $sp['dept_name']==='(No Department)' ? 'badge-soft-danger' : '' ?>"><?= htmlspecialchars($sp['dept_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></td>
                                                            <td>
                                                                <a href="admin_quiz_manage.php?speaker_id=<?= (int)$sp['id'] ?>" class="btn btn-sm btn-outline-primary me-1" title="Create Quiz"><i class="bi bi-ui-checks-grid"></i></a>
                                                                <?php if (!empty($sp['dept_name']) && $sp['dept_name'] !== '(No Department)'): ?>
                                                                    <a href="admin_department_certificate_templates.php?dept_id=<?= (int)($sp['technical_department_id'] ?? 0) ?>" class="btn btn-sm btn-outline-success" title="Create Dept Certificate Template"><i class="bi bi-award"></i></a>
                                                                <?php else: ?>
                                                                    <span class="text-secondary small">Assign Dept</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <div class="small text-secondary mt-2">A speaker appears here if no quiz row exists AND their technical department lacks a certificate template.</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- SECTION: Administration -->
                        <div class="section-header d-flex align-items-center mb-3">
                            <div class="section-icon me-3">
                                <i class="bi bi-gear-fill text-success fs-4"></i>
                            </div>
                            <h4 class="mb-0 text-secondary fw-bold small text-uppercase">Administration</h4>
                            <div class="flex-grow-1 ms-3">
                                <hr class="my-0 opacity-25">
                            </div>
                        </div>
                        <div class="row g-4 mb-5">
                            <div class="col-lg-6 col-xl-4">
                                <div class="enhanced-card slide-up h-100">
                                    <div class="card-header py-2"><h6 class="mb-0 d-flex align-items-center"><i class="bi bi-lightning-fill text-warning me-2"></i>Quick Actions</h6></div>
                                    <div class="card-body">
                                        <div class="row g-2 mb-3">
                                            <div class="col-6">
                                                <a href="admin_speaker_manage.php" class="btn btn-outline-primary btn-sm w-100 d-flex align-items-center justify-content-center">
                                                    <i class="bi bi-person-video3 me-1"></i> Speakers
                                                </a>
                                            </div>
                                            <div class="col-6">
                                                <a href="admin_quiz_manage.php" class="btn btn-primary btn-sm w-100 d-flex align-items-center justify-content-center">
                                                    <i class="bi bi-ui-checks-grid me-1"></i> Quizzes
                                                </a>
                                            </div>
                                            <div class="col-6">
                                                <a href="admin_technical_departments.php" class="btn btn-outline-secondary btn-sm w-100 d-flex align-items-center justify-content-center">
                                                    <i class="bi bi-diagram-3 me-1"></i> Departments
                                                </a>
                                            </div>
                                            <div class="col-6">
                                                <a href="admin_manage_function_rooms.php" class="btn btn-outline-secondary btn-sm w-100 d-flex align-items-center justify-content-center">
                                                    <i class="bi bi-door-open me-1"></i> Rooms
                                                </a>
                                            </div>
                                            <div class="col-6">
                                                <a href="admin_certificate_settings.php" class="btn btn-outline-success btn-sm w-100 d-flex align-items-center justify-content-center">
                                                    <i class="bi bi-award me-1"></i> Certificates
                                                </a>
                                            </div>
                                            <div class="col-6">
                                                <a href="admin_department_certificate_templates.php" class="btn btn-outline-success btn-sm w-100 d-flex align-items-center justify-content-center">
                                                    <i class="bi bi-award-fill me-1"></i> Dept Certs
                                                </a>
                                            </div>
                                            <div class="col-6">
                                                <a href="admin_eval_natcon.php" class="btn btn-outline-info btn-sm w-100 d-flex align-items-center justify-content-center">
                                                    <i class="bi bi-list-check me-1"></i> NatCon Eval
                                                </a>
                                            </div>
                                            <div class="col-6">
                                                <a href="admin_eval_natcon_reports.php" class="btn btn-outline-dark btn-sm w-100 d-flex align-items-center justify-content-center">
                                                    <i class="bi bi-graph-up me-1"></i> Reports
                                                </a>
                                            </div>
                                            <div class="col-6">
                                                <a href="admin_speaker_eval_questions.php" class="btn btn-outline-success btn-sm w-100 d-flex align-items-center justify-content-center">
                                                    <i class="bi bi-sliders me-1"></i> Eval Sets
                                                </a>
                                            </div>
                                        </div>
                                        <div class="p-2 bg-primary bg-opacity-10 rounded-3 small">
                                            <div class="d-flex align-items-start">
                                                <i class="bi bi-lightbulb-fill text-warning me-2 mt-1"></i>
                                                <div>
                                                    <strong class="text-primary">Pro Tip:</strong><br>
                                                    100% quiz coverage enables consistent evaluation & feedback.
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