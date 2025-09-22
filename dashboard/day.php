<?php
session_start();
require_once __DIR__ . '/../db.php';

// normalize DB connection variable
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

date_default_timezone_set('Asia/Manila');

// Normalize asset URLs to project root (/NatconDash1)
if (!function_exists('asset_url')) {
    function asset_url($path) {
        $path = (string)$path;
        if ($path === '') return '';
        if (preg_match('#^https?://#i', $path)) return $path;
        $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\'); // -> /NatconDash1
        if ($base === '' || $base === '.') $base = '';
        return $base . '/' . ltrim($path, '/');
    }
}

// determine day number (try GET['day'] first, otherwise derive from GET['day_id'] or by matching today's date)
$dayNum = null;
$today  = (new DateTime())->format('Y-m-d');

if (isset($_GET['day']) && is_numeric($_GET['day'])) {
    $dayNum = (int) $_GET['day'];
} elseif (isset($_GET['day_id']) && is_numeric($_GET['day_id'])) {
    $dayId = (int) $_GET['day_id'];

    // Try to resolve day number from function_room_days by using the ordered position of the day_date
    $stmt = $db->prepare("SELECT day_date FROM function_room_days WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $dayId);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!empty($r['day_date'])) {
            $dayDate = $r['day_date'];
            // day number = 1-based index of this date among ordered function_room_days
            $stmt2 = $db->prepare("SELECT COUNT(*) AS num FROM function_room_days WHERE day_date <= ?");
            if ($stmt2) {
                $stmt2->bind_param('s', $dayDate);
                $stmt2->execute();
                $res2 = $stmt2->get_result()->fetch_assoc();
                $stmt2->close();
                if (isset($res2['num'])) {
                    $dayNum = max(1, (int)$res2['num']);
                }
            }
        }
    }

    // fallback: if function_rooms has a day_number column for rooms referencing this day_id
    if ($dayNum === null) {
        $stmt3 = $db->prepare("SELECT DISTINCT fr.day_number FROM function_rooms fr WHERE fr.day_id = ? LIMIT 1");
        if ($stmt3) {
            $stmt3->bind_param('i', $dayId);
            $stmt3->execute();
            $r3 = $stmt3->get_result()->fetch_assoc();
            $stmt3->close();
            if (!empty($r3['day_number'])) {
                $dayNum = (int)$r3['day_number'];
            }
        }
    }
}

// If still null, try to resolve by today's date in function_room_days
if ($dayNum === null) {
    $stmt = $db->prepare("SELECT id, day_date FROM function_room_days WHERE day_date = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $today);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!empty($r['day_date'])) {
            // compute index as above
            $stmt2 = $db->prepare("SELECT COUNT(*) AS num FROM function_room_days WHERE day_date <= ?");
            if ($stmt2) {
                $stmt2->bind_param('s', $r['day_date']);
                $stmt2->execute();
                $res2 = $stmt2->get_result()->fetch_assoc();
                $stmt2->close();
                if (isset($res2['num'])) {
                    $dayNum = max(1, (int)$res2['num']);
                }
            }
        }
    }
}

// final fallback default to 1
if ($dayNum === null || $dayNum <= 0) {
    $dayNum = 1;
}

// fetch function rooms for the resolved day number and their speakers
$sql = "
    SELECT
      fr.id AS room_id,
      fr.name AS room_name,
      fr.label AS room_label,
      s.id AS speaker_id,
      s.name AS speaker_name,
      s.title AS speaker_title,
      s.start_time AS speaker_time,
      s.photo_url AS speaker_photo
    FROM function_rooms fr
    LEFT JOIN speakers s ON s.room_id = fr.id
    WHERE fr.day_number = ?
    ORDER BY fr.id ASC, COALESCE(s.start_time, '00:00:00') ASC, s.id ASC
";

$stmt = $db->prepare($sql);
if ($stmt === false) {
    http_response_code(500);
    echo "Query prepare failed: " . htmlspecialchars($db->error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    exit;
}
$stmt->bind_param('i', $dayNum);
$stmt->execute();
$res = $stmt->get_result();

$rooms = [];
while ($row = $res->fetch_assoc()) {
    $rid = (int)$row['room_id'];
    if (!isset($rooms[$rid])) {
        $rooms[$rid] = [
            'id' => $rid,
            'name' => $row['room_name'] ?: ('Function Room ' . $rid),
            'label' => $row['room_label'] ?: '',
            'speakers' => []
        ];
    }
    if (!empty($row['speaker_id'])) {
        $rooms[$rid]['speakers'][] = [
            'id'    => (int)$row['speaker_id'],
            'name'  => $row['speaker_name'],
            'title' => $row['speaker_title'],
            'time'  => $row['speaker_time'],
            'photo' => $row['speaker_photo']
        ];
    }
}
$stmt->close();

// helper to format time (guard against redeclare)
if (!function_exists('fmt_time')) {
    function fmt_time($t) {
        if (!$t) return '';
        $dt = @date_create($t);
        if ($dt) return date_format($dt, 'g:i A');
        return htmlspecialchars((string)$t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Day <?= htmlspecialchars((string)$dayNum, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> — Function Rooms</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
     <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
    :root {
      --primary-color: #111e6c;
      --primary-dark: #1116ec;
      --primary-light: #dbeafe;
      --secondary-color: #e27900ff;
      --secondary-dark: #ce6e00ff;
      --success-color: #10b981;
      --dark-color: #111e6c;
      --light-gray: #f8fafc;
      --border-color: #e5e7eb;
    }
    
    * {
      font-family: 'Poppins', sans-serif;
    }
    
    .navbar {
      backdrop-filter: blur(20px);
      background: rgba(255, 255, 255, 0.95) !important;
      border-bottom: 1px solid var(--border-color);
    }
    
    .navbar-brand span {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    
    .btn-primary {
      background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
      border: none;
      box-shadow: 0 4px 15px rgba(37, 99, 235, 0.3);
    }
    
    .btn-primary:hover {
      background: linear-gradient(135deg, var(--primary-dark), var(--primary-color));
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(37, 99, 235, 0.4);
    }
    
    .btn-outline-primary {
      border: 2px solid var(--primary-color);
      color: var(--primary-color);
    }
    
    .btn-outline-primary:hover {
      background: var(--primary-color);
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(37, 99, 235, 0.3);
    }
    
    .btn-warning {
      background: linear-gradient(135deg, var(--secondary-color), var(--secondary-dark));
      border: none;
      box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3);
    }
    
    .btn-warning:hover {
      background: linear-gradient(135deg, var(--secondary-dark), var(--secondary-color));
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(245, 158, 11, 0.4);
    }
        
    body { background: var(--primary-color); color:#fff;}

    .text-gradient {
      background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .page-title { background:#fff; color:#0b2a59; text-align:center; padding:12px; font-weight:700; margin-bottom:18px; border-radius:4px; }
    .room-card { background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.08); padding:18px; border-radius:10px; margin-bottom:16px; }
    .room-header { font-weight:700; }
    .speaker-item { display:flex; align-items:center; gap:12px; padding:10px 0; border-bottom:1px dashed rgba(255,255,255,0.04); cursor:pointer; }
    .speaker-item:last-child { border-bottom:0; }
    .speaker-photo { width:100px; height:100px; border-radius:100px; background:#fff3; display:flex; align-items:center; justify-content:center; overflow:hidden; }
    .speaker-photo img { width:100%; height:100%; object-fit:cover; display:block; }
    .speaker-meta { flex:1; }
    .speaker-time { min-width:90px; text-align:right; color:#ffd700; font-weight:600; }
    .container-wrap { padding:26px; }
    .col-right { background:#fff; color:#0b2a59; border-radius:10px; padding:18px; }
    .empty-msg { padding:30px; background:rgba(255,255,255,0.04); border-radius:8px; text-align:center; color:#e6eefc; }
    </style>
</head>
<body>
<!-- Header Section -->
  <nav class="navbar navbar-expand-lg navbar-light shadow-custom fixed-top">
    <div class="container">
      <!-- Logo + Title -->
      <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
        <img src="../image/natcon.jpg" alt="PSME Logo" class="me-3 rounded-circle" style="height: 55px;">
        <div>
          <span class="fs-5 fw-bold d-block">73rd PSME</span>
          <small class="d-none d-md-block fw-medium">National Convention</small>
        </div>
      </a>

      <!-- Navbar Toggle -->
      <button class="navbar-toggler border-0 shadow-sm" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
        <span class="navbar-toggler-icon"></span>
      </button>

      <!-- Navbar Links -->
      <div class="collapse navbar-collapse" id="navbarNav">
        <ul class="navbar-nav ms-auto align-items-center">
          <li class="nav-item">
            <a class="nav-link fw-semibold px-3" href="function_rooms.php">< Back to Function Rooms</a>
          </li>
        </ul>
      </div>
    </div>
  </nav>

  <div class="container container-wrap mt-5" id="main">
        <div class="display-6 fw-bold m-5 text-white text-center">DAY <?= htmlspecialchars((string)$dayNum, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> — SPEAKERS & SCHEDULE</div>

        <div class="row g-4">
            <div class="col-lg-12">
                <?php if (empty($rooms)): ?>
                    <div class="empty-msg text-white">
                        No function rooms or speakers found for Day <?= htmlspecialchars((string)$dayNum, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>.
                        <div class="mt-2 small text-white">Please add rooms and speakers in the admin panel or check the database.</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($rooms as $room): ?>
                        <div class="room-card">
                            <div class="d-flex text-white justify-content-between align-items-center mb-2">
                                <div>
                                    <div class="room-header"><?= htmlspecialchars((string)$room['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                    <?php if (!empty($room['label'])): ?>
                                        <div class="text-white small"><?= htmlspecialchars((string)$room['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="text-white small">Room #<?= (int)$room['id'] ?></div>
                            </div>
                            <div class="mt-2">
                                <?php if (!empty($room['speakers'])): ?>
                                    <?php foreach ($room['speakers'] as $sp): ?>
                                        <?php $speakerId = isset($sp['id']) ? (int)$sp['id'] : 0; ?>
                                        <?php if ($speakerId > 0): ?>
                                        <a class="speaker-item text-white text-reset text-decoration-none" href="speaker_form.php?id=<?= $speakerId ?>">
                                            <div class="speaker-photo">
                                                <?php $photoUrl = !empty($sp['photo']) ? asset_url($sp['photo']) : ''; ?>
                                                <?php if ($photoUrl): ?>
                                                    <img src="<?= htmlspecialchars($photoUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="">
                                                <?php else: ?>
                                                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                      <rect width="24" height="24" rx="4" fill="#e9ecef"/>
                                                      <path d="M12 12a3 3 0 100-6 3 3 0 000 6z" fill="#adb5bd"/>
                                                      <path d="M4 20a8 8 0 1116 0H4z" fill="#dee2e6"/>
                                                    </svg>
                                                <?php endif; ?>
                                            </div>
                                            <div class="speaker-meta">
                                                <div style="font-weight:700; color:#fff;"><?= htmlspecialchars((string)$sp['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                                <?php if (!empty($sp['title'])): ?>
                                                    <div class="small text-white"><?= htmlspecialchars((string)$sp['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="speaker-time"><?= fmt_time($sp['time']) ?></div>
                                        </a>
                                        <?php else: ?>
                                        <div class="speaker-item">
                                            <div class="speaker-photo">
                                                <?php $photoUrl = !empty($sp['photo']) ? asset_url($sp['photo']) : ''; ?>
                                                <?php if ($photoUrl): ?>
                                                    <img src="<?= htmlspecialchars($photoUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="">
                                                <?php else: ?>
                                                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                      <rect width="24" height="24" rx="4" fill="#e9ecef"/>
                                                      <path d="M12 12a3 3 0 100-6 3 3 0 000 6z" fill="#adb5bd"/>
                                                      <path d="M4 20a8 8 0 1116 0H4z" fill="#dee2e6"/>
                                                    </svg>
                                                <?php endif; ?>
                                            </div>
                                            <div class="speaker-meta">
                                                <div style="font-weight:700; color:#fff;"><?= htmlspecialchars((string)$sp['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                                <?php if (!empty($sp['title'])): ?>
                                                    <div class="small text-white"><?= htmlspecialchars((string)$sp['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="speaker-time"><?= fmt_time($sp['time']) ?></div>
                                        </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-white small">No speakers scheduled for this room.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    // delegate click to speaker items to go to speaker detail (if id exists)
    document.addEventListener('click', function(e){
        const el = e.target.closest('.speaker-item');
        if (!el) return;
        const spId = el.getAttribute('data-speaker-id');
        if (spId) location.href = 'speaker.php?id=' + encodeURIComponent(spId);
    });
    </script>
</body>
</html>