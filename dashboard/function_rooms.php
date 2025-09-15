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

$conventionOver = isConventionOver($today, $lastConventionDate);
$userCompletedAny = userHasCompletedAny($conn, $userId);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Function Rooms — National Convention</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <style>
    body { background: #0b2a59; color: #fff; font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial; }
    .hero { padding: 48px 0; }
    .card-day { border-radius: 14px; overflow: hidden; display:flex; align-items:center; justify-content:space-between; padding:18px 22px; background:rgba(255,255,255,0.05); border: 2px solid rgba(255,255,255,0.18); cursor:pointer; transition: transform .12s ease, box-shadow .12s ease; }
    .card-day.disabled { opacity: 0.45; cursor: default; transform: none !important; box-shadow: none !important; }
    .card-day.active { transform: translateY(-6px); box-shadow: 0 12px 30px rgba(0,0,0,0.35); }
    .small-note { color: #d6e0ff; max-width: 420px; }
    .cert-icon { width:160px; height:160px; border-radius:16px; background:#f2f2f2; color:#222; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:22px; box-shadow: 0 6px 18px rgba(0,0,0,0.12); cursor:pointer; }
    .cert-icon.disabled { opacity: 0.4; cursor: default; }
    .admin-panel { background:#fff; color:#222; border-radius:8px; padding:16px; }
  </style>
</head>
<body>
  <div class="container hero">
    <div class="row align-items-start">
      <div class="col-lg-8">
        <h1 class="text-center mb-4" style="color:#fff; font-weight:800;">FUNCTION ROOMS</h1>

        <div class="row">
          <div class="col-md-6">
            <p class="small-note">
              Explore the seminars for Day 1 to Day 3. Cards are clickable based on admin settings.
            </p>

            <div class="list-group mt-3">
              <?php foreach ($days as $d):
                $dayId = (int)$d['id'];
                $label = htmlspecialchars($d['label']);
                $dayDate = $d['day_date'];
                $isEnabled = ((int)$d['is_enabled'] === 1);
                $isToday   = ($today === $dayDate);

                if ($roomMode === 'date_strict') {
                    $isActive = $isToday && $isEnabled;
                } else {
                    $isActive = $isEnabled;
                }

                $cardClasses = "card-day mb-3 " . ($isActive ? "active" : "disabled");
                $targetUrl = $isActive ? "function_room_detail.php?day_id={$dayId}" : "#";
              ?>
                <a href="<?= $isActive ? htmlspecialchars($targetUrl) : '#' ?>" class="text-decoration-none">
                  <div class="<?= $cardClasses ?>" role="button">
                    <div style="font-weight:800; font-size:16px;"><?= $label ?></div>
                    <div style="opacity:0.9; font-size:14px;"><?= (new DateTime($dayDate))->format('F j, Y') ?></div>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="col-md-6 text-center">
            <h4 style="font-weight:700; color:#fff; margin-top:6px;">GENERATE YOUR CERTIFICATES</h4>
            <div class="d-flex justify-content-center gap-3 flex-wrap mt-3">
              <?php
                $certs = [
                  ['key'=>'attendance','label'=>'Certificate of Attendance'],
                  ['key'=>'appearance','label'=>'Certificate of Appearance'],
                  ['key'=>'cpd','label'=>'CPD Form Certificate']
                ];
                foreach ($certs as $c):
                  $canGenerate = $conventionOver && $userCompletedAny && $userId;
              ?>
                <div class="text-center">
                  <div class="cert-icon <?= $canGenerate ? '' : 'disabled' ?>"
                       data-cert="<?= htmlspecialchars($c['key']) ?>"
                       title="<?= $canGenerate ? 'Click to generate' : 'Not yet available' ?>">
                    <?= strtoupper(substr($c['key'],0,2)) ?>
                  </div>
                  <div style="margin-top:10px; color:#e8eefc; font-size:14px;"><?= htmlspecialchars($c['label']) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

<script>
(function(){
  document.addEventListener('click', function(e){
    const el = e.target.closest('.cert-icon');
    if (!el) return;
    if (el.classList.contains('disabled')) {
      alert(el.title || 'Not available');
      return;
    }
    const type = el.dataset.cert;
    window.location.href = 'generate_certificate.php?type=' + encodeURIComponent(type);
  });
})();
</script>
</body>
</html>
