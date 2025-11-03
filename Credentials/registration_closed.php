<?php
// 73RD PSME NATCON Registration Closed Page
// Shows account information for qualified delegates
session_start();

// Check if user is logged in to show account info
$user_logged_in = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
$user_info = null;

if ($user_logged_in) {
    // Get user information
    require_once __DIR__ . '/../db.php';
    if (isset($conn) && $conn instanceof mysqli) {
        $user_id = (int)$_SESSION['user_id'];
        if ($stmt = $conn->prepare('SELECT name, email, affiliation, position FROM users WHERE id = ? LIMIT 1')) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $stmt->bind_result($name, $email, $affiliation, $position);
            if ($stmt->fetch()) {
                $user_info = [
                    'name' => $name,
                    'email' => $email,
                    'affiliation' => $affiliation,
                    'position' => $position
                ];
            }
            $stmt->close();
        }
    }
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="robots" content="noindex, nofollow">
    <title>73RD PSME NATCON - Registration Closed</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root{
            --primary: #111e6c;
            --secondary: #e27900;
            --primary-dark: #0f1a5c;
            --card-bg: rgba(255,255,255,0.97);
            --shadow: 0 20px 50px rgba(0,0,0,0.15);
            --natcon-gradient: linear-gradient(135deg, #111e6c 0%, #e27900 100%);
        }
        body{
            min-height: 100vh;
            margin: 0;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Poppins', system-ui, -apple-system, sans-serif;
            padding-top: 80px;
        }
        
        .navbar {
            backdrop-filter: blur(20px);
            background: rgba(255,255,255,0.95)!important;
            border-bottom: 1px solid #e5e7eb;
        }
        .navbar-brand span {
            background: var(--natcon-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .nav-link {
            font-weight: 500;
            color: #374151!important;
        }
        .nav-link:hover {
            color: var(--primary)!important;
        }
        
        .main-container {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: calc(100vh - 80px);
            padding: 2rem 1rem;
        }
        
        .card-wrap{
            width: min(900px, 95vw);
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.4);
            border-radius: 24px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        
        .hero{
            background: linear-gradient(135deg, rgba(17,30,108,0.08), rgba(226,121,0,0.08));
            border-bottom: 1px solid rgba(17,30,108,0.15);
            padding: 2rem 2.5rem;
            text-align: center;
        }
        .hero h1{
            margin: 0 0 0.5rem;
            font-weight: 800;
            font-size: clamp(1.6rem, 1.4rem + 1.5vw, 2.5rem);
            background: var(--natcon-gradient);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .hero .subtitle {
            color: #64748b;
            font-size: 1.1rem;
            font-weight: 500;
        }
        
        .badge-closed {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
            font-weight: 700;
            letter-spacing: 0.5px;
            padding: 0.7rem 1.2rem;
            font-size: 0.9rem;
        }
        
        .content {
            padding: 2rem 2.5rem;
        }
        
        .alert-message {
            background: linear-gradient(135deg, #fef3c7, #fed7aa);
            border: 1px solid #f59e0b;
            border-radius: 16px;
            padding: 1.5rem;
            margin: 1.5rem 0;
        }
        .alert-message .icon {
            color: #d97706;
            font-size: 1.5rem;
        }
        .alert-message h3 {
            color: #92400e;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        .alert-message p {
            color: #a16207;
            margin: 0;
            font-weight: 500;
        }
        
        .account-info {
            background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
            border: 1px solid #0ea5e9;
            border-radius: 16px;
            padding: 1.5rem;
            margin: 1.5rem 0;
        }
        .account-info h3 {
            color: #0c4a6e;
            font-weight: 700;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .account-info .info-item {
            display: flex;
            align-items: flex-start;
            gap: 0.8rem;
            margin: 0.8rem 0;
            padding: 0.6rem;
            background: rgba(255,255,255,0.6);
            border-radius: 10px;
        }
        .account-info .info-item .icon {
            color: #0284c7;
            margin-top: 2px;
        }
        .account-info .info-item .label {
            font-weight: 600;
            color: #0c4a6e;
            min-width: 80px;
        }
        .account-info .info-item .value {
            color: #164e63;
            word-break: break-word;
        }
        
        .developer-contact {
            background: linear-gradient(135deg, #f3e8ff, #e9d5ff);
            border: 1px solid #a855f7;
            border-radius: 16px;
            padding: 1.5rem;
            margin: 1.5rem 0;
            text-align: center;
        }
        .developer-contact h3 {
            color: #6b21a8;
            font-weight: 700;
            margin-bottom: 1rem;
        }
        .developer-contact p {
            color: #7c2d92;
            margin-bottom: 1rem;
            font-weight: 500;
        }
        
        .btn-pill {
            border-radius: 999px;
            padding: 0.8rem 1.5rem;
            font-weight: 700;
            transition: all 0.3s ease;
        }
        .btn-primary-natcon {
            background: var(--natcon-gradient);
            border: none;
            color: white;
        }
        .btn-primary-natcon:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(17,30,108,0.3);
            color: white;
        }
        .btn-outline-natcon {
            border: 2px solid var(--primary);
            color: var(--primary);
            background: transparent;
        }
        .btn-outline-natcon:hover {
            background: var(--primary);
            color: white;
            transform: translateY(-1px);
        }
        
        .links {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 1rem;
            margin-top: 2rem;
        }
        .links .btn {
            min-width: 180px;
        }
        
        .small-note {
            color: #64748b;
            font-size: 0.9rem;
            margin-top: 1.5rem;
        }
        
        @media (max-width: 768px) {
            .hero, .content {
                padding: 1.5rem;
            }
            .card-wrap {
                margin: 1rem;
                width: calc(100vw - 2rem);
            }
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light shadow-sm fixed-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="../dashboard/dashboard.php">
                <img src="../image/natcon.jpg" alt="PSME Logo" class="me-3 rounded-circle" style="height:55px;">
                <div>
                    <span class="fs-5 fw-bold d-block">73rd PSME</span>
                    <small class="d-none d-md-block fw-medium">National Convention</small>
                </div>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navMain">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item"><a class="nav-link" href="../dashboard/dashboard.php">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="../dashboard/programs.php">Programs</a></li>
                    <?php if (!$user_logged_in): ?>
                        <li class="nav-item ms-2">
                            <a href="login.php" class="btn btn-outline-natcon rounded-pill px-4 py-2">
                                <i class="bi bi-box-arrow-in-right me-1"></i> Login
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item ms-2">
                            <a href="../dashboard/dashboard.php" class="btn btn-primary-natcon rounded-pill px-4 py-2">
                                <i class="bi bi-speedometer2 me-1"></i> Dashboard
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-container">
        <main class="card-wrap">
            <div class="hero">
                <div class="d-inline-flex align-items-center gap-2 mb-3">
                    <span class="badge rounded-pill badge-closed">
                        <i class="bi bi-exclamation-triangle me-1"></i> Registration Closed
                    </span>
                </div>
                <h1>73RD PSME NATCON</h1>
                <div class="subtitle">National Convention Registration</div>
            </div>

            <div class="content">
                <div class="alert-message">
                    <div class="d-flex align-items-start gap-3">
                        <i class="bi bi-info-circle-fill icon"></i>
                        <div>
                            <h3>Registration is Now Closed</h3>
                            <p>New participant registration for the 73rd PSME National Convention is no longer available.</p>
                        </div>
                    </div>
                </div>

                <?php if ($user_logged_in && $user_info): ?>
                    <div class="account-info">
                        <h3>
                            <i class="bi bi-person-check-fill"></i>
                            Your Delegate Account
                        </h3>
                        <div class="info-item">
                            <i class="bi bi-person-fill icon"></i>
                            <span class="label">Name:</span>
                            <span class="value"><?= htmlspecialchars($user_info['name'] ?: 'Not provided') ?></span>
                        </div>
                        <div class="info-item">
                            <i class="bi bi-envelope-fill icon"></i>
                            <span class="label">Email:</span>
                            <span class="value"><?= htmlspecialchars($user_info['email'] ?: 'Not provided') ?></span>
                        </div>
                        <?php if ($user_info['affiliation']): ?>
                            <div class="info-item">
                                <i class="bi bi-building-fill icon"></i>
                                <span class="label">Affiliation:</span>
                                <span class="value"><?= htmlspecialchars($user_info['affiliation']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($user_info['position']): ?>
                            <div class="info-item">
                                <i class="bi bi-briefcase-fill icon"></i>
                                <span class="label">Position:</span>
                                <span class="value"><?= htmlspecialchars($user_info['position']) ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="text-center mt-3">
                            <span class="badge bg-success rounded-pill px-3 py-2">
                                <i class="bi bi-check-circle me-1"></i> Qualified Delegate
                            </span>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="developer-contact">
                    <h3><i class="bi bi-headset me-2"></i>Need Assistance?</h3>
                    <p><strong>If you are a qualified 73RD PSME NATCON DELEGATE</strong><br>
                       and need assistance with your account or registration</p>
                    <div class="d-flex justify-content-center gap-2 flex-wrap">
                        <a href="mailto:developer@psmenatcon.org" class="btn btn-primary-natcon btn-pill">
                            <i class="bi bi-envelope-fill me-1"></i> Contact Developer
                        </a>
                        <a href="tel:+1234567890" class="btn btn-outline-natcon btn-pill">
                            <i class="bi bi-telephone-fill me-1"></i> Call Support
                        </a>
                    </div>
                </div>

                <div class="links">
                    <?php if (!$user_logged_in): ?>
                        <a class="btn btn-primary-natcon btn-pill" href="login.php">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Delegate Login
                        </a>
                    <?php else: ?>
                        <a class="btn btn-primary-natcon btn-pill" href="../dashboard/dashboard.php">
                            <i class="bi bi-speedometer2 me-1"></i> Go to Dashboard
                        </a>
                        <a class="btn btn-outline-natcon btn-pill" href="../dashboard/programs.php">
                            <i class="bi bi-calendar-event me-1"></i> View Programs
                        </a>
                    <?php endif; ?>
                    <a class="btn btn-outline-natcon btn-pill" href="../dashboard/dashboard.php">
                        <i class="bi bi-house me-1"></i> Back to Home
                    </a>
                </div>

                <div class="small-note text-center">
                    <i class="bi bi-shield-check me-1"></i>
                    Existing registered delegates can continue to access all convention features and programs.
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>