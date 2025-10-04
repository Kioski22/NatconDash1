<?php
session_start();
require_once __DIR__ . '/../db.php';
if (!function_exists('e')) { function e($s){ return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); } }

// Load days and counts of sub program technical departments
$days = [];
$sql = "SELECT d.id,d.label,d.day_date,
  COUNT(DISTINCT s.technical_department_id) AS dept_count
  FROM function_room_days d
  LEFT JOIN function_rooms fr ON fr.day_id = d.id AND fr.program_type='sub'
  LEFT JOIN speakers s ON s.room_id = fr.id AND s.program_type='sub' AND s.technical_department_id IS NOT NULL
  GROUP BY d.id,d.label,d.day_date
  ORDER BY d.day_date ASC";
$q = $conn->query($sql);
if ($q) { while($r=$q->fetch_assoc()) $days[]=$r; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Sub Program - 73rd PSME</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
      --primary-color: #000e6c;
      --primary-dark: #0006ec;
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
 body { background: var(--primary-color); color:#fff; font-family:'Poppins',sans-serif; padding-top:70px; }
  .placeholder-box { background:rgba(255,255,255,0.08); border:1px dashed rgba(255,255,255,0.35); border-radius:14px; padding:40px 30px; text-align:center; }
</style>
</head>
<body>
<!-- Header Section -->
  <nav class="navbar navbar-expand-lg navbar-light shadow-custom fixed-top">
      <div class="container">
        <!-- Logo + Title -->
        <a class="navbar-brand d-flex align-items-center" href="#">
          <img src="../image/natcon.jpg" alt="PSME Logo" class="me-3 rounded-circle" style="height:55px;">
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
            <a class="nav-link fw-semibold px-3" href="programs.php?day_id=<?= (int)$dayId ?>">Programs</a>
          </li>
          <li class="nav-item">
            <a class="nav-link fw-semibold px-3" href="dashboard.php">Dashboard</a>
          </li>
        </ul>
      </div>
    </div>
  </nav>
<div class="container py-5">
  <h1 class="display-6 fw-bold text-center mb-4">SUB PROGRAM</h1>
  <p class="text-center opacity-75 mb-5 small">Select a day to view Sub Program rooms & speakers.</p>
  <div class="row g-4 justify-content-center">
    <?php if (empty($days)): ?>
      <div class="col-12"><div class="alert alert-warning text-dark">No days configured.</div></div>
    <?php endif; ?>
    <?php foreach ($days as $d): ?>
      <div class="col-md-4 col-lg-3">
        <div class="placeholder-box" style="cursor:pointer; border-style:solid;" onclick="location.href='sub_program_day.php?day_id=<?= (int)$d['id'] ?>'">
          <h5 class="mb-2 d-flex justify-content-center align-items-center gap-2 flex-wrap">
            <span><?= e($d['label']) ?></span>
            <?php if ((int)($d['dept_count']??0) > 0): ?>
              <span class="badge bg-info text-dark"><?= (int)$d['dept_count'] ?> Dept<?= ((int)$d['dept_count']===1)?'':'s' ?></span>
            <?php endif; ?>
          </h5>
          <div class="small mb-2" style="opacity:.8;"><?= e((new DateTime($d['day_date']))->format('M j, Y')) ?></div>
          <div class="small text-uppercase" style="letter-spacing:1px;opacity:.6;">Open</div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
