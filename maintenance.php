<?php
// Emit proper 503 headers for maintenance mode
http_response_code(503);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
// Optional: advise clients when to retry (in seconds)
header('Retry-After: 3600'); // 1 hour

// Robust NATCON logo handling for cPanel subfolder deployments
$logoCandidates = [
  __DIR__ . '/image/natcon.jpg',
  __DIR__ . '/natcon.jpg'
];
$logoDataUri = null;
foreach ($logoCandidates as $candidate) {
  if (is_readable($candidate)) {
    $data = @file_get_contents($candidate);
    if ($data !== false) {
      $logoDataUri = 'data:image/jpeg;base64,' . base64_encode($data);
      break;
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>We'll be back soon - Site Maintenance</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    :root {
      --bg-start: #0f3460;
      --bg-end: #16213e;
      --card-bg: rgba(255, 255, 255, 0.06);
      --card-border: rgba(255, 255, 255, 0.12);
      --text-primary: #ffffff;
      --text-secondary: #e4e9f2;
      --text-muted: #94a3b8;
      --accent-primary: #e94560;
      --accent-secondary: #533483;
      --accent-glow: rgba(233, 69, 96, 0.28);
      --shadow-lg: 0 24px 60px -18px rgba(0, 0, 0, 0.65);
    }
    html, body { height: 100%; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
      background: linear-gradient(130deg, var(--bg-start) 0%, var(--bg-end) 100%);
      color: var(--text-primary);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
      position: relative;
      overflow: hidden;
    }
    body::before {
      content: '';
      position: absolute;
      inset: -50%;
      background: radial-gradient(circle at 28% 18%, rgba(233, 69, 96, 0.12) 0%, transparent 45%),
                  radial-gradient(circle at 72% 78%, rgba(83, 52, 131, 0.13) 0%, transparent 50%);
      animation: drift 20s ease-in-out infinite;
      pointer-events: none;
    }
    @keyframes drift {
      0%, 100% { transform: translate(0, 0) rotate(0deg); }
      50% { transform: translate(28px, 30px) rotate(1.5deg); }
    }
    .container {
      position: relative;
      z-index: 1;
      width: min(640px, 100%);
      animation: fadeIn 0.8s ease-out;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(20px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .card {
      background: var(--card-bg);
      backdrop-filter: blur(22px) saturate(180%);
      border: 1px solid var(--card-border);
      border-radius: 26px;
      padding: clamp(32px, 6vw, 54px);
      box-shadow: var(--shadow-lg);
      position: relative;
      overflow: hidden;
    }
    .card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 1px;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,0.18), transparent);
    }
    .logo-wrapper {
      width: 130px;
      height: 130px;
      margin: 0 auto 26px;
      border-radius: 22px;
      background: #ffffff;
      display: grid;
      place-items: center;
      box-shadow: 0 10px 36px var(--accent-glow);
      overflow: hidden;
      padding: 14px;
      animation: pulse 3.2s ease-in-out infinite;
    }
    @keyframes pulse {
      0%, 100% { transform: scale(1); box-shadow: 0 10px 36px var(--accent-glow); }
      50% { transform: scale(1.02); box-shadow: 0 16px 42px var(--accent-glow); }
    }
    .logo-wrapper img {
      width: 100%;
      height: 100%;
      object-fit: contain;
    }
    .brand-text {
      text-align: center;
      margin-bottom: 22px;
      font-size: 0.85rem;
      font-weight: 600;
      letter-spacing: 0.22em;
      text-transform: uppercase;
      color: var(--text-muted);
    }
    h1 {
      text-align: center;
      margin-bottom: 18px;
      font-size: clamp(1.9rem, 4.5vw, 2.25rem);
      font-weight: 700;
      background: linear-gradient(135deg, var(--text-primary), var(--text-secondary));
      -webkit-background-clip: text;
      background-clip: text;
      -webkit-text-fill-color: transparent;
      letter-spacing: -0.6px;
    }
    .subtitle {
      text-align: center;
      color: var(--text-secondary);
      font-size: 1.05rem;
      line-height: 1.65;
      margin: 0 auto 38px;
      max-width: 480px;
    }
    .status-grid {
      display: grid;
      gap: 16px;
      margin-bottom: 32px;
      padding: 24px;
      background: rgba(8, 11, 24, 0.35);
      border-radius: 18px;
      border: 1px solid rgba(255, 255, 255, 0.08);
    }
    .status-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      padding: 12px 0;
      border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    }
    .status-item:last-child { border-bottom: 0; }
    .status-label {
      color: var(--text-muted);
      font-size: 0.95rem;
      font-weight: 500;
    }
    .status-value {
      color: var(--text-primary);
      font-weight: 600;
      text-align: right;
    }
    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      padding: 6px 14px;
      border-radius: 10px;
      background: rgba(251, 191, 36, 0.1);
      border: 1px solid rgba(251, 191, 36, 0.28);
      color: #fbbf24;
      font-weight: 600;
      font-size: 0.9rem;
    }
    .status-dot {
      width: 9px;
      height: 9px;
      border-radius: 50%;
      background: currentColor;
      animation: blink 2s ease-in-out infinite;
    }
    @keyframes blink {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.35; }
    }
    .contact-link {
      color: var(--accent-primary);
      text-decoration: none;
      font-weight: 500;
      transition: color 0.2s ease;
    }
    .contact-link:hover {
      color: var(--accent-secondary);
      text-decoration: underline;
    }
    .actions {
      display: flex;
      gap: 12px;
      justify-content: center;
      flex-wrap: wrap;
      margin-bottom: 26px;
    }
    .btn {
      position: relative;
      overflow: hidden;
      border: none;
      border-radius: 12px;
      padding: 14px 28px;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      transition: transform 0.3s ease;
    }
    .btn::before {
      content: '';
      position: absolute;
      top: 50%; left: 50%;
      width: 0; height: 0;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.2);
      transform: translate(-50%, -50%);
      transition: width 0.55s ease, height 0.55s ease;
    }
    .btn:hover::before {
      width: 260px;
      height: 260px;
    }
    .btn span { position: relative; z-index: 1; }
    .btn-primary {
      color: #ffffff;
      background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
      box-shadow: 0 8px 24px var(--accent-glow);
    }
    .btn-primary:hover { transform: translateY(-2px); }
    .btn-secondary {
      color: var(--text-primary);
      background: rgba(255, 255, 255, 0.06);
      border: 1px solid rgba(255, 255, 255, 0.12);
    }
    .btn-secondary:hover { transform: translateY(-2px); }
    .note {
      text-align: center;
      color: var(--text-muted);
      font-size: 0.88rem;
      line-height: 1.55;
    }
    .progress-bar {
      margin-top: 26px;
      height: 3px;
      background: rgba(255, 255, 255, 0.07);
      border-radius: 3px;
      overflow: hidden;
    }
    .progress-fill {
      height: 100%;
      background: linear-gradient(90deg, var(--accent-primary), var(--accent-secondary));
      animation: progress 3s ease-in-out infinite;
    }
    @keyframes progress {
      0% { width: 0%; }
      50% { width: 65%; }
      100% { width: 100%; }
    }
    @media (max-width: 640px) {
      .card { padding: 32px 24px; }
      .logo-wrapper { width: 104px; height: 104px; }
      .actions { flex-direction: column; }
      .btn { width: 100%; }
    }
  </style>
</head>
<body>
  <div class="container">
    <main class="card" role="main" aria-label="Site maintenance notice">
      <div class="logo-wrapper">
        <img
          src="image/natcon.jpg"
          alt="NATCON Logo"
          data-alt="0"
          <?php if ($logoDataUri): ?>
          onerror="if(this.dataset.alt==='0'){this.dataset.alt='1';this.src='natcon.jpg';}else{this.onerror=null;this.src='<?php echo htmlspecialchars($logoDataUri, ENT_QUOTES); ?>';}"
          <?php else: ?>
          onerror="this.onerror=null; this.src='natcon.jpg'"
          <?php endif; ?>
        />
      </div>
      <div class="brand-text">NATCON</div>
      <h1>We'll be back soon</h1>
      <p class="subtitle">We're performing scheduled maintenance to improve your experience. Thanks for your patience and understanding.</p>
      <div class="status-grid">
        <div class="status-item">
          <span class="status-label">Status</span>
          <span class="status-badge">
            <span class="status-dot"></span>
            Under Maintenance
          </span>
        </div>
        <div class="status-item">
          <span class="status-label">Started</span>
          <span class="status-value" id="started-time">Just now</span>
        </div>
        <div class="status-item">
          <span class="status-label">Support Contact</span>
          <a href="mailto:delegates@psmeinc.org.ph" class="contact-link">delegates@psmeinc.org.ph</a>
        </div>
      </div>
      <div class="actions">
        <button class="btn btn-primary" onclick="location.reload()"><span>Refresh Page</span></button>
        <button class="btn btn-secondary" onclick="window.history.length > 1 ? history.back() : location.reload()"><span>Go Back</span></button>
      </div>
      <p class="note">We'll automatically clear this page once maintenance wraps up. If you continue to see this message after a while, please reach out to our support team.</p>
      <div class="progress-bar" aria-hidden="true">
        <div class="progress-fill"></div>
      </div>
    </main>
  </div>
  <script>
    (function () {
      try {
        var startedEl = document.getElementById('started-time');
        if (startedEl) {
          var now = new Date();
          startedEl.textContent = now.toLocaleString(undefined, {
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
          });
        }
      } catch (err) {
        console.warn('Unable to format maintenance timestamp', err);
      }
    })();
  </script>
</body>
</html>
