<?php

session_start();
require_once __DIR__ . '/../db.php';

// normalize DB connection
if (isset($conn) && $conn instanceof mysqli)      $db = $conn;
elseif (isset($mysqli) && $mysqli instanceof mysqli) $db = $mysqli;
else { http_response_code(500); echo "Database not available."; exit; }

// require admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
  header('Location: admin_login.php'); exit;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

// helpers
if (!function_exists('e')) {
  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
}

$errors = [];
$success = '';

// Handle POST actions (delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = $_POST['csrf_token'] ?? '';
  if (!hash_equals($_SESSION['csrf_token'], (string)$token)) {
    $errors[] = 'Invalid CSRF token.';
  } else {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
      $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
      if ($id <= 0) {
        $errors[] = 'Invalid evaluation id.';
      } else {
        $st = $db->prepare("DELETE FROM speaker_evaluations WHERE id = ? LIMIT 1");
        if ($st) {
          $st->bind_param('i', $id);
          if ($st->execute()) { $success = 'Evaluation deleted.'; }
          else { $errors[] = 'Delete failed: ' . $st->error; }
          $st->close();
        } else {
          $errors[] = 'DB error: ' . $db->error;
        }
      }
    } else {
      $errors[] = 'Unknown action.';
    }
  }
  // redirect to avoid repost
  $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
  $_SESSION['eval_messages'] = ['errors'=>$errors, 'success'=>$success];
  header('Location: admin_evaluations.php?' . http_build_query($_GET));
  exit;
}

// Show messages after redirect
$messages = $_SESSION['eval_messages'] ?? null;
if ($messages) {
  $errors = array_merge($errors, $messages['errors'] ?? []);
  if (!empty($messages['success'])) $success = $messages['success'];
  unset($_SESSION['eval_messages']);
}

// Fetch filters
$day_id = isset($_GET['day_id']) ? (int)$_GET['day_id'] : 0;
$room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
$speaker_id = isset($_GET['speaker_id']) ? (int)$_GET['speaker_id'] : 0;

// Dropdown data
$days = [];
if ($r = $db->query("SELECT id, label, day_date FROM function_room_days ORDER BY day_date ASC")) {
  while ($row = $r->fetch_assoc()) $days[] = $row;
}

$rooms = [];
if ($r = $db->query("
  SELECT fr.id, fr.name, fr.day_id, d.label AS day_label, d.day_date
  FROM function_rooms fr
  LEFT JOIN function_room_days d ON d.id = fr.day_id
  ORDER BY d.day_date ASC, fr.name ASC
")) {
  while ($row = $r->fetch_assoc()) $rooms[] = $row;
}

$speakers = [];
if ($r = $db->query("SELECT id, name FROM speakers ORDER BY name ASC")) {
  while ($row = $r->fetch_assoc()) $speakers[] = $row;
}

// Build WHERE
$where = [];
if ($day_id > 0)    $where[] = "se.day_id = ".(int)$day_id;
if ($room_id > 0)   $where[] = "se.room_id = ".(int)$room_id;
if ($speaker_id > 0)$where[] = "se.speaker_id = ".(int)$speaker_id;
$whereSql = $where ? ('WHERE '.implode(' AND ', $where)) : '';

// Export CSV
if (isset($_GET['export']) && $_GET['export'] == '1') {
  $sql = "
    SELECT se.id, se.user_id, se.speaker_id, se.room_id, se.day_id,
           se.content_quality, se.speaker_clarity, se.engagement, se.overall, se.comments, se.created_at,
           s.name AS speaker_name, fr.name AS room_name, fd.label AS day_label
    FROM speaker_evaluations se
    LEFT JOIN speakers s ON s.id = se.speaker_id
    LEFT JOIN function_rooms fr ON fr.id = se.room_id
    LEFT JOIN function_room_days fd ON fd.id = se.day_id
    $whereSql
    ORDER BY se.created_at DESC, se.id DESC
  ";
  $res = $db->query($sql);
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="speaker_evaluations.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['ID','User ID','Speaker ID','Speaker Name','Room ID','Room Name','Day ID','Day Label','Content','Clarity','Engagement','Overall','Comments','Created At']);
  if ($res) {
    while ($row = $res->fetch_assoc()) {
      fputcsv($out, [
        $row['id'], $row['user_id'], $row['speaker_id'], $row['speaker_name'],
        $row['room_id'], $row['room_name'], $row['day_id'], $row['day_label'],
        $row['content_quality'], $row['speaker_clarity'], $row['engagement'], $row['overall'],
        $row['comments'], $row['created_at']
      ]);
    }
  }
  fclose($out);
  exit;
}

// Pagination
$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// Count total
$total = 0;
if ($r = $db->query("SELECT COUNT(*) AS c FROM speaker_evaluations se $whereSql")) {
  if ($row = $r->fetch_assoc()) $total = (int)$row['c'];
}
$pages = max(1, (int)ceil($total / $perPage));

// Fetch rows
$sql = "
  SELECT se.id, se.user_id, se.speaker_id, se.room_id, se.day_id,
         se.content_quality, se.speaker_clarity, se.engagement, se.overall, se.comments, se.created_at,
         s.name AS speaker_name, fr.name AS room_name, fd.label AS day_label
  FROM speaker_evaluations se
  LEFT JOIN speakers s ON s.id = se.speaker_id
  LEFT JOIN function_rooms fr ON fr.id = se.room_id
  LEFT JOIN function_room_days fd ON fd.id = se.day_id
  $whereSql
  ORDER BY se.created_at DESC, se.id DESC
  LIMIT $perPage OFFSET $offset
";
$rows = [];
if ($res = $db->query($sql)) {
  while ($row = $res->fetch_assoc()) $rows[] = $row;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Speaker Evaluations — Admin</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .comment-trunc { max-width: 360px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: inline-block; vertical-align: bottom; }
    .rating-badge { font-weight: 700; }
  </style>
</head>
<body class="p-3 bg-light">
  <div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4>Speaker Evaluations</h4>
      <div class="d-flex gap-2">
        <a href="Admin_dashboard.php" class="btn btn-secondary btn-sm">Back</a>
        <a href="?<?= e(http_build_query(array_merge($_GET, ['export'=>1]))) ?>" class="btn btn-outline-primary btn-sm">Export CSV</a>
      </div>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <form method="get" class="card mb-3">
      <div class="card-body row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Day</label>
          <select name="day_id" class="form-select">
            <option value="0">All days</option>
            <?php foreach ($days as $d): ?>
              <option value="<?= (int)$d['id'] ?>" <?= $day_id == $d['id'] ? 'selected' : '' ?>><?= e($d['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Room</label>
          <select name="room_id" class="form-select">
            <option value="0">All rooms</option>
            <?php foreach ($rooms as $rm): ?>
              <option value="<?= (int)$rm['id'] ?>" <?= $room_id == $rm['id'] ? 'selected' : '' ?>>
                <?= e($rm['name']) ?> <?= !empty($rm['day_label']) ? ' ('.e($rm['day_label']).')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-5">
          <label class="form-label">Speaker</label>
          <select name="speaker_id" class="form-select">
            <option value="0">All speakers</option>
            <?php foreach ($speakers as $sp): ?>
              <option value="<?= (int)$sp['id'] ?>" <?= $speaker_id == $sp['id'] ? 'selected' : '' ?>>#<?= (int)$sp['id'] ?> — <?= e($sp['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary">Apply Filters</button>
          <a class="btn btn-outline-secondary" href="admin_evaluations.php">Reset</a>
        </div>
      </div>
    </form>

    <div class="card">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div>Total: <strong><?= (int)$total ?></strong> evaluation<?= $total == 1 ? '' : 's' ?></div>
          <?php if ($pages > 1): ?>
            <nav>
              <ul class="pagination pagination-sm mb-0">
                <?php
                $qsBase = $_GET; unset($qsBase['page']);
                for ($p=1;$p<=$pages;$p++):
                  $qs = http_build_query(array_merge($qsBase, ['page'=>$p]));
                ?>
                  <li class="page-item <?= $p == $page ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= e($qs) ?>"><?= $p ?></a>
                  </li>
                <?php endfor; ?>
              </ul>
            </nav>
          <?php endif; ?>
        </div>

        <?php if (empty($rows)): ?>
          <div class="text-muted">No evaluations found for the selected filters.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>User ID</th>
                  <th>Speaker</th>
                  <th>Day</th>
                  <th>Room</th>
                  <th class="text-center">Content</th>
                  <th class="text-center">Clarity</th>
                  <th class="text-center">Engage</th>
                  <th class="text-center">Overall</th>
                  <th>Comments</th>
                  <th>Submitted</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $r): ?>
                  <?php
                    $ov = (int)$r['overall'];
                    $badge = $ov >= 4 ? 'bg-success' : ($ov >= 2 ? 'bg-warning text-dark' : 'bg-danger');
                  ?>
                  <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td>#<?= (int)$r['user_id'] ?></td>
                    <td>#<?= (int)$r['speaker_id'] ?> — <?= e($r['speaker_name'] ?? '') ?></td>
                    <td><?= e($r['day_label'] ?? '-') ?></td>
                    <td><?= e($r['room_name'] ?? '-') ?></td>
                    <td class="text-center"><span class="badge text-bg-secondary rating-badge"><?= (int)$r['content_quality'] ?></span></td>
                    <td class="text-center"><span class="badge text-bg-secondary rating-badge"><?= (int)$r['speaker_clarity'] ?></span></td>
                    <td class="text-center"><span class="badge text-bg-secondary rating-badge"><?= (int)$r['engagement'] ?></span></td>
                    <td class="text-center"><span class="badge <?= $badge ?> rating-badge"><?= $ov ?></span></td>
                    <td>
                      <?php $c = trim((string)($r['comments'] ?? '')); ?>
                      <?php if ($c === ''): ?>
                        <span class="text-muted">-</span>
                      <?php else: ?>
                        <span class="comment-trunc"><?= e($c) ?></span>
                        <button class="btn btn-link btn-sm p-0" data-bs-toggle="modal" data-bs-target="#commentModal" data-comment="<?= e($c) ?>">View</button>
                      <?php endif; ?>
                    </td>
                    <td><span class="small text-muted"><?= e($r['created_at']) ?></span></td>
                    <td class="text-end">
                      <form method="post" class="d-inline" onsubmit="return confirm('Delete this evaluation?');">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger">Delete</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Comments Modal -->
  <div class="modal fade" id="commentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h6 class="modal-title">Evaluation Comment</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body"><pre class="mb-0" id="commentModalBody" style="white-space:pre-wrap; font-family: inherit;"></pre></div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const commentModal = document.getElementById('commentModal');
    if (commentModal) {
      commentModal.addEventListener('show.bs.modal', function (event) {
        const btn = event.relatedTarget;
        const text = btn?.getAttribute('data-comment') || '';
        document.getElementById('commentModalBody').textContent = text;
      });
    }
  </script>
</body>
</html>