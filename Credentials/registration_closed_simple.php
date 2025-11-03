<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="robots" content="noindex, nofollow">
    <title>73RD PSME NATCON - Registration Closed</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root{
            --primary: #111e6c;
            --secondary: #e27900;
            --natcon-gradient: linear-gradient(135deg, #111e6c 0%, #e27900 100%);
        }
        body{
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Poppins', system-ui, sans-serif;
            padding: 1rem;
        }
        .card-wrap{
            width: min(800px, 100%);
            background: rgba(255,255,255,0.97);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.4);
            border-radius: 24px;
            box-shadow: 0 20px 50px rgba(0,0,0,0.15);
            overflow: hidden;
        }
        .hero{
            background: linear-gradient(135deg, rgba(17,30,108,0.08), rgba(226,121,0,0.08));
            border-bottom: 1px solid rgba(17,30,108,0.15);
            padding: 2rem;
            text-align: center;
        }
        .hero h1{
            margin: 0 0 0.5rem;
            font-weight: 800;
            font-size: clamp(1.8rem, 2vw + 1rem, 3rem);
            background: var(--natcon-gradient);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .badge-closed {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
            font-weight: 700;
            letter-spacing: 0.5px;
            padding: 0.8rem 1.5rem;
            font-size: 1rem;
        }
        .content { padding: 2rem; }
        .alert-box {
            background: linear-gradient(135deg, #fef3c7, #fed7aa);
            border: 2px solid #f59e0b;
            border-radius: 16px;
            padding: 2rem;
            margin: 2rem 0;
            text-align: center;
        }
        .alert-box h2 {
            color: #92400e;
            font-weight: 700;
            margin-bottom: 1rem;
        }
        .alert-box p {
            color: #a16207;
            font-size: 1.1rem;
            font-weight: 500;
            margin-bottom: 1.5rem;
        }
        .contact-info {
            background: linear-gradient(135deg, #f3e8ff, #e9d5ff);
            border: 2px solid #a855f7;
            border-radius: 16px;
            padding: 2rem;
            margin: 2rem 0;
            text-align: center;
        }
        .contact-info h3 {
            color: #6b21a8;
            font-weight: 700;
            margin-bottom: 1rem;
        }
        .btn-pill {
            border-radius: 999px;
            padding: 1rem 2rem;
            font-weight: 700;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            margin: 0.5rem;
        }
        .btn-primary-natcon {
            background: var(--natcon-gradient);
            border: none;
            color: white;
        }
        .btn-primary-natcon:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(17,30,108,0.4);
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
            transform: translateY(-2px);
        }
        .delegate-note {
            background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
            border: 1px solid #0ea5e9;
            border-radius: 12px;
            padding: 1.5rem;
            margin: 1.5rem 0;
            color: #0c4a6e;
            font-weight: 500;
            text-align: center;
        }
        @media (max-width: 768px) {
            .hero, .content {
                padding: 1.5rem;
            }
            .btn-pill {
                display: block;
                width: 100%;
                margin: 0.5rem 0;
            }
        }
    </style>
</head>
<body>
    <main class="card-wrap">
        <div class="hero">
            <div class="d-inline-flex align-items-center gap-2 mb-3">
                <span class="badge rounded-pill badge-closed">
                    <i class="bi bi-exclamation-triangle me-2"></i> Registration Closed
                </span>
            </div>
            <h1>73RD PSME NATCON</h1>
            <p class="mb-0 text-muted fs-5">National Convention Registration</p>
        </div>

        <div class="content">
            <div class="alert-box">
                <div class="mb-3">
                    <i class="bi bi-info-circle-fill" style="font-size: 3rem; color: #d97706;"></i>
                </div>
                <h2>Registration is Now Closed</h2>
                <p>New participant registration for the 73rd PSME National Convention is no longer available at this time.</p>
                
                <div class="delegate-note">
                    <i class="bi bi-shield-check me-2"></i>
                    <strong>Existing registered delegates can continue to access all convention features</strong>
                </div>
            </div>

            <div class="contact-info">
                <div class="mb-3">
                    <i class="bi bi-headset" style="font-size: 2.5rem; color: #a855f7;"></i>
                </div>
                <h3>Need Assistance?</h3>
                <p class="text-muted mb-3">
                    <strong>If you are a qualified 73RD PSME NATCON DELEGATE</strong><br>
                    please contact the developer for assistance with your account or registration issues.
                </p>
                
                <div class="d-flex justify-content-center flex-wrap gap-2">
                    <a href="mailto:developer@psmenatcon.org" class="btn btn-primary-natcon btn-pill">
                        <i class="bi bi-envelope-fill me-2"></i> Contact Developer
                    </a>
                    <a href="tel:+639123456789" class="btn btn-outline-natcon btn-pill">
                        <i class="bi bi-telephone-fill me-2"></i> Call Support
                    </a>
                </div>
            </div>

            <div class="text-center mt-4">
                <a class="btn btn-outline-natcon btn-pill" href="login.php">
                    <i class="bi bi-box-arrow-in-right me-2"></i> Delegate Login
                </a>
                <a class="btn btn-outline-natcon btn-pill" href="../dashboard/dashboard.php">
                    <i class="bi bi-house me-2"></i> Back to Home
                </a>
            </div>

            <div class="text-center mt-4 small text-muted">
                <i class="bi bi-info-circle me-1"></i>
                For technical support and delegate assistance, please use the contact information above.
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>