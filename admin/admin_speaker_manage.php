<?php
session_start();
require_once __DIR__ . '/../db.php';

// normalize DB connection ($conn or $mysqli)
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

// require admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: admin_login.php');
    exit;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

// helper
if (!function_exists('e')) {
    function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
// Add: asset URL normalizer
if (!function_exists('asset_url')) {
    function asset_url($path) {
        $path = (string)$path;
        if ($path === '') return '';
        if (preg_match('#^https?://#i', $path)) return $path; // already absolute URL
        // project base (e.g. /NatconDash1)
        $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
        if ($base === '' || $base === '.' ) $base = '';
        return $base . '/' . ltrim($path, '/');
    }
}

$errors = [];
$success = '';

// helper: handle photo upload, returns relative path or null
if (!function_exists('handle_photo_upload')) {
    function handle_photo_upload($fileField = 'photo_file') {
        if (empty($_FILES[$fileField]) || $_FILES[$fileField]['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $f = $_FILES[$fileField];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            return null;
        }
        $maxBytes = 2 * 1024 * 1024; // 2MB
        if ($f['size'] > $maxBytes) {
            return ['error' => 'Photo too large (max 2MB).'];
        }
        $allowed = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/gif'=>'gif'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $f['tmp_name']);
        finfo_close($finfo);
        if (!isset($allowed[$mime])) {
            return ['error' => 'Invalid photo type. Allowed: jpg, png, gif.'];
        }
        $ext = $allowed[$mime];
        $uploadsDir = __DIR__ . '/../uploads/speakers';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }
        $filename = bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $uploadsDir . '/' . $filename;
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            return ['error' => 'Failed to move uploaded file.'];
        }
        // return web relative path
        return 'uploads/speakers/' . $filename;
    }
}

// NEW: helper: handle presentation upload (ppt/pptx/pdf), returns relative path or null
if (!function_exists('handle_presentation_upload')) {
    function handle_presentation_upload($fileField = 'presentation_file') {
        if (empty($_FILES[$fileField]) || $_FILES[$fileField]['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $f = $_FILES[$fileField];
        if ($f['error'] !== UPLOAD_ERR_OK) {
            return ['error' => 'Upload error for presentation file.'];
        }
        $maxBytes = 20 * 1024 * 1024; // 20 MB
        if ($f['size'] > $maxBytes) {
            return ['error' => 'Presentation too large (max 20MB).'];
        }

        $allowed = [
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/pdf' => 'pdf',
        ];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $f['tmp_name']);
        finfo_close($finfo);

        // Fallback by extension if mime is ambiguous
        $extByName = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!isset($allowed[$mime])) {
            if (in_array($extByName, ['pptx','ppt','pdf'], true)) {
                $mime = array_search($extByName, $allowed, true) ?: 'application/pdf';
            } else {
                return ['error' => 'Invalid presentation type. Allowed: .pptx, .ppt, .pdf'];
            }
        }
        $ext = $allowed[$mime] ?? $extByName;

        $uploadsDir = __DIR__ . '/../uploads/presentations';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }
        $filename = bin2hex(random_bytes(12)) . '.' . $ext;
        $dest = $uploadsDir . '/' . $filename;
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            return ['error' => 'Failed to store presentation file.'];
        }
        // return web relative path
        return 'uploads/presentations/' . $filename;
    }
}

// handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], (string)$token)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $name = trim($_POST['name'] ?? '');
            $title = trim($_POST['title'] ?? '');
            $room_id = isset($_POST['room_id']) && is_numeric($_POST['room_id']) ? (int)$_POST['room_id'] : 0;
            $start_time = trim($_POST['start_time'] ?? '') ?: null;
            $end_time = trim($_POST['end_time'] ?? '') ?: null;
            $bio = trim($_POST['bio'] ?? '');
            $photo_url = trim($_POST['photo_url'] ?? null);
            // NEW
            $presentation_url = null;

            if ($name === '') $errors[] = 'Speaker name is required.';
            if ($room_id <= 0) $errors[] = 'Please choose a function room.';
            if ($start_time !== null && $start_time !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start_time)) {
                $errors[] = 'Invalid start time format. Use HH:MM or HH:MM:SS.';
            }
            if ($end_time !== null && $end_time !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end_time)) {  // NEW
                $errors[] = 'Invalid end time format. Use HH:MM or HH:MM:SS.';
            }

            // handle uploads
            $upload = handle_photo_upload('photo_file');
            if (is_array($upload) && isset($upload['error'])) {
                $errors[] = $upload['error'];
            } elseif (is_string($upload)) {
                $photo_url = $upload;
            }

            // NEW: presentation upload (optional)
            $pres = handle_presentation_upload('presentation_file');
            if (is_array($pres) && isset($pres['error'])) {
                $errors[] = $pres['error'];
            } elseif (is_string($pres)) {
                $presentation_url = $pres;
            }

            if (empty($errors)) {
                // CHANGED: include presentation_url
                $stmt = $db->prepare("INSERT INTO speakers (room_id, name, title, start_time, end_time, photo_url, presentation_url, bio) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param('isssssss', $room_id, $name, $title, $start_time, $end_time, $photo_url, $presentation_url, $bio);
                    if ($stmt->execute()) {
                        $success = 'Speaker added.';
                    } else {
                        $errors[] = 'Insert failed: ' . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $errors[] = 'DB error: ' . $db->error;
                }
            }

        } elseif ($action === 'update') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $name = trim($_POST['name'] ?? '');
            $title = trim($_POST['title'] ?? '');
            $room_id = isset($_POST['room_id']) && is_numeric($_POST['room_id']) ? (int)$_POST['room_id'] : 0;
            $start_time = trim($_POST['start_time'] ?? '') ?: null;
            $end_time = trim($_POST['end_time'] ?? '') ?: null;
            $bio = trim($_POST['bio'] ?? '');
            $photo_url = trim($_POST['photo_url'] ?? null);
            // NEW: keep existing unless replaced
            $presentation_url = trim($_POST['presentation_url'] ?? '') ?: null;

            if ($id <= 0) $errors[] = 'Invalid speaker id.';
            if ($name === '') $errors[] = 'Speaker name is required.';
            if ($room_id <= 0) $errors[] = 'Please choose a function room.';
            if ($start_time !== null && $start_time !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start_time)) {
                $errors[] = 'Invalid start time format. Use HH:MM or HH:MM:SS.';
            }
            if ($end_time !== null && $end_time !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end_time)) {  // NEW
                $errors[] = 'Invalid end time format. Use HH:MM or HH:MM:SS.';
            }

            // photo upload (optional replace)
            $upload = handle_photo_upload('photo_file');
            if (is_array($upload) && isset($upload['error'])) {
                $errors[] = $upload['error'];
            } elseif (is_string($upload)) {
                $photo_url = $upload;
            }

            // NEW: presentation upload (optional replace)
            $pres = handle_presentation_upload('presentation_file');
            if (is_array($pres) && isset($pres['error'])) {
                $errors[] = $pres['error'];
            } elseif (is_string($pres)) {
                $presentation_url = $pres;
            }

            if (empty($errors)) {
                // CHANGED: include presentation_url
                $stmt = $db->prepare("UPDATE speakers SET room_id = ?, name = ?, title = ?, start_time = ?, end_time = ?, photo_url = ?, presentation_url = ?, bio = ? WHERE id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('isssssssi', $room_id, $name, $title, $start_time, $end_time, $photo_url, $presentation_url, $bio, $id);
                    if ($stmt->execute()) {
                        $success = 'Speaker updated.';
                    } else {
                        $errors[] = 'Update failed: ' . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $errors[] = 'DB error: ' . $db->error;
                }
            }

        } elseif ($action === 'delete') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id <= 0) $errors[] = 'Invalid id for delete.';
            if (empty($errors)) {
                $stmt = $db->prepare("DELETE FROM speakers WHERE id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('i', $id);
                    if ($stmt->execute()) {
                        $success = 'Speaker deleted.';
                    } else {
                        $errors[] = 'Delete failed: ' . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $errors[] = 'DB error: ' . $db->error;
                }
            }
        } else {
            $errors[] = 'Unknown action.';
        }
    }

    // regenerate token and redirect to avoid repost
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    $_SESSION['spk_messages'] = ['errors' => $errors, 'success' => $success];
    header('Location: admin_speaker_manage.php');
    exit;
}

// show messages
$messages = $_SESSION['spk_messages'] ?? null;
if ($messages) {
    $errors = array_merge($errors, $messages['errors'] ?? []);
    if (!empty($messages['success'])) $success = $messages['success'];
    unset($_SESSION['spk_messages']);
}

// fetch days and rooms for form dropdowns
$days = [];
$r = $db->query("SELECT id, label, day_date FROM function_room_days ORDER BY day_date ASC");
if ($r) {
    while ($row = $r->fetch_assoc()) $days[] = $row;
}

// fetch all rooms (we'll use JS to filter by day if needed)
$rooms = [];
$r2 = $db->query("
  SELECT fr.id, fr.name,
         fd.id AS day_id, fd.label AS day_label, fd.day_date
  FROM function_rooms fr
  LEFT JOIN function_room_days fd ON fd.id = fr.day_id
  ORDER BY fd.day_date ASC, fr.id ASC
");
if ($r2) {
    while ($row = $r2->fetch_assoc()) {
        // derive day_number if label like "Day 1"
        $row['day_number'] = null;
        if (!empty($row['day_label']) && preg_match('/(\d+)/', $row['day_label'], $m)) {
            $row['day_number'] = (int)$m[1];
        }
        $rooms[] = $row;
    }
}

// fetch speakers list with room + day info
$speakers = [];
$sql = "
    SELECT s.id, s.name, s.title, s.start_time, s.end_time, s.photo_url, s.presentation_url, s.room_id,   -- CHANGED
           fr.name AS room_name,
           fd.id AS day_id, fd.label AS day_label, fd.day_date
    FROM speakers s
    LEFT JOIN function_rooms fr ON fr.id = s.room_id
    LEFT JOIN function_room_days fd ON fd.id = fr.day_id
    ORDER BY fd.day_date ASC, fr.id ASC, s.start_time ASC
";
$res = $db->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) $speakers[] = $row;
}

// load speaker for edit if requested
$edit = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $stmt = $db->prepare("SELECT id, name, title, start_time, end_time, photo_url, presentation_url, bio, room_id FROM speakers WHERE id = ? LIMIT 1"); // CHANGED
    if ($stmt) {
        $stmt->bind_param('i', $eid);
        $stmt->execute();
        $edit = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Speakers — Manage</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f8f9fa; }
    .speaker-photo { width:48px;height:48px;border-radius:6px;object-fit:cover; }
    .small-muted { font-size:0.85rem; color:#6c757d; }
  </style>
</head>
<body class="p-3">
  <div class="container">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4>Speaker Management</h4>
      <a href="Admin_dashboard.php" class="btn btn-secondary btn-sm">Back</a>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

    <div class="row">
      <div class="col-lg-7">
        <div class="card mb-3">
          <div class="card-body">
            <h6 class="card-title">Speakers</h6>
            <?php if (empty($speakers)): ?>
              <div class="text-muted">No speakers added yet.</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle">
                  <thead>
                    <tr><th>#</th><th>Photo</th><th>Name</th><th>Title</th><th>Day</th><th>Room</th><th>Time</th><th></th></tr>
                  </thead>
                  <tbody>
                    <?php foreach ($speakers as $s): ?>
                      <tr>
                        <td><?= (int)$s['id'] ?></td>
                        <td>
                          <?php if (!empty($s['photo_url'])): ?>
                            <img src="<?= e(asset_url($s['photo_url'])) ?>" class="speaker-photo" alt="">
                          <?php else: ?>
                            <div class="speaker-photo" style="background:#e9ecef"></div>
                          <?php endif; ?>
                        </td>
                        <td><?= e($s['name']) ?></td>
                        <td><?= e($s['title']) ?></td>
                        <td><?= e($s['day_label'] ?: '-') ?></td>
                        <td><?= e($s['room_name'] ?: '-') ?></td>
                        <td>
                          <?php
                            $t1 = $s['start_time'] ?? '';
                            $t2 = $s['end_time'] ?? '';
                            $timeText = $t1 . ($t2 ? ' - ' . $t2 : '');
                            echo e($timeText ?: '-');
                          ?>
                        </td>
                        <td class="text-end">
                          <a href="admin_speaker_manage.php?edit=<?= (int)$s['id'] ?>" class="btn btn-sm btn-primary">Edit</a>
                          <form method="post" class="d-inline" onsubmit="return confirm('Delete speaker?');">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <button class="btn btn-sm btn-danger">Delete</button>
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

      <div class="col-lg-5">
        <div class="card mb-3">
          <div class="card-body">
            <?php if ($edit): ?>
              <h6 class="card-title">Edit Speaker #<?= (int)$edit['id'] ?></h6>
              <form method="post" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">

                <div class="mb-2">
                  <label class="form-label">Name</label>
                  <input name="name" class="form-control" required value="<?= e($edit['name']) ?>">
                </div>

                <div class="mb-2">
                  <label class="form-label">Title / Designation</label>
                  <input name="title" class="form-control" value="<?= e($edit['title']) ?>">
                </div>

                <div class="mb-2">
                  <label class="form-label">Day</label>
                  <select id="daySelectEdit" class="form-select mb-2">
                    <option value="">-- select day --</option>
                    <?php foreach ($days as $d): ?>
                      <option value="<?= (int)$d['id'] ?>" <?= '' ?>><?= e($d['label']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <label class="form-label">Function Room</label>
                  <select name="room_id" id="roomSelectEdit" class="form-select" required>
                    <option value="">-- select room --</option>
                    <?php foreach ($rooms as $rm): ?>
                      <option value="<?= (int)$rm['id'] ?>" <?= $edit['room_id'] == $rm['id'] ? 'selected' : '' ?>><?= e($rm['name']) ?> <?= $rm['day_number'] ? ' (Day '.$rm['day_number'].')' : '' ?></option>
                    <?php endforeach; ?>
                  </select>
                  <small id="noRoomsEdit" class="text-danger d-none">No rooms found for selected day.</small>
                </div>

                <div class="mb-2">
                  <label class="form-label">Start Time</label>
                  <input name="start_time" type="time" class="form-control" value="<?= e($edit['start_time']) ?>">
                </div>
                <div class="mb-2"> <!-- NEW -->
                  <label class="form-label">End Time</label>
                  <input name="end_time" type="time" class="form-control" value="<?= e($edit['end_time'] ?? '') ?>">
                </div>

                <div class="mb-2">
                  <label class="form-label">Photo (upload to replace)</label>
                  <?php if (!empty($edit['photo_url'])): ?>
                    <div class="mb-2"><img src="<?= e(asset_url($edit['photo_url'])) ?>" class="speaker-photo" alt=""></div>
                  <?php endif; ?>
                  <input name="photo_file" type="file" accept="image/*" class="form-control">
                  <input type="hidden" name="photo_url" value="<?= e($edit['photo_url']) ?>">
                </div>

                <div class="mb-2">
                  <label class="form-label">Presentation (optional, upload to replace)</label>
                  <?php if (!empty($edit['presentation_url'])): ?>
                    <div class="mb-2"><a href="<?= e(asset_url($edit['presentation_url'])) ?>" target="_blank" class="btn btn-sm btn-outline-primary">View Current</a></div>
                  <?php endif; ?>
                  <input name="presentation_file" type="file" accept=".ppt,.pptx,.pdf" class="form-control">
                  <input type="hidden" name="presentation_url" value="<?= e($edit['presentation_url']) ?>">
                </div>

                <div class="mb-2">
                  <label class="form-label">Short Bio</label>
                  <textarea name="bio" class="form-control" rows="4"><?= e($edit['bio'] ?? '') ?></textarea>
                </div>

                <div class="d-grid">
                  <button class="btn btn-primary">Update Speaker</button>
                  <a href="admin_speaker_manage.php" class="btn btn-link mt-2">Cancel</a>
                </div>
              </form>

            <?php else: ?>
              <h6 class="card-title">Add Speaker</h6>
              <form method="post" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="add">

                <div class="mb-2">
                  <label class="form-label">Name</label>
                  <input name="name" class="form-control" required value="">
                </div>

                <div class="mb-2">
                  <label class="form-label">Title / Designation</label>
                  <input name="title" class="form-control" value="">
                </div>

                <div class="mb-2">
                  <label class="form-label">Day</label>
                  <select id="daySelect" class="form-select mb-2">
                    <option value="">-- select day --</option>
                    <?php foreach ($days as $d): ?>
                      <option value="<?= (int)$d['id'] ?>"><?= e($d['label']) ?></option>
                    <?php endforeach; ?>
                  </select>

                  <label class="form-label">Function Room</label>
                  <select name="room_id" id="roomSelect" class="form-select" required>
                    <option value="">-- select room --</option>
                    <?php foreach ($rooms as $rm): ?>
                      <option value="<?= (int)$rm['id'] ?>"><?= e($rm['name']) ?> <?= $rm['day_number'] ? ' (Day '.$rm['day_number'].')' : '' ?></option>
                    <?php endforeach; ?>
                  </select>
                  <small id="noRoomsAdd" class="text-danger d-none">No rooms found for selected day.</small>
                </div>

                <div class="mb-2">
                  <label class="form-label">Start Time</label>
                  <input name="start_time" type="time" class="form-control" value="">
                </div>
                <div class="mb-2"> <!-- NEW -->
                  <label class="form-label">End Time</label>
                  <input name="end_time" type="time" class="form-control" value="">
                </div>

                <div class="mb-2">
                  <label class="form-label">Photo (optional)</label>
                  <input name="photo_file" type="file" accept="image/*" class="form-control">
                </div>

                <div class="mb-2">
                  <label class="form-label">Presentation (optional)</label>
                  <input name="presentation_file" type="file" accept=".ppt,.pptx,.pdf" class="form-control">
                </div>

                <div class="mb-2">
                  <label class="form-label">Short Bio</label>
                  <textarea name="bio" class="form-control" rows="4"></textarea>
                </div>

                <div class="d-grid">
                  <button class="btn btn-success">Add Speaker</button>
                </div>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <div class="card">
          <div class="card-body small-muted">
            Use the Day selector to help pick the function room. Rooms list contains day info but you can filter with the Day selector.
          </div>
        </div>
      </div>
    </div>
  </div>

<script>
const rooms = <?= json_encode($rooms, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

function filterRoomsFor(selectEl, dayId, selectedRoomId = null, noRoomsEl = null) {
  // Reset options
  selectEl.innerHTML = '<option value="">-- select room --</option>';
  let count = 0;

  rooms.forEach(r => {
    if (!dayId || String(r.day_id) === String(dayId)) {
      const opt = document.createElement('option');
      const suffix = r.day_label ? (' (' + r.day_label + ')') : (r.day_number ? ' (Day ' + r.day_number + ')' : '');
      opt.value = r.id;
      opt.textContent = r.name + suffix;
      if (selectedRoomId && String(selectedRoomId) === String(r.id)) opt.selected = true;
      selectEl.appendChild(opt);
      count++;
    }
  });

  if (noRoomsEl) noRoomsEl.classList.toggle('d-none', count > 0);
}

document.addEventListener('DOMContentLoaded', () => {
  // ADD form wiring
  const daySelect = document.getElementById('daySelect');
  const roomSelect = document.getElementById('roomSelect');
  const noRoomsAdd = document.getElementById('noRoomsAdd');
  if (daySelect && roomSelect) {
    daySelect.addEventListener('change', () => {
      filterRoomsFor(roomSelect, daySelect.value, null, noRoomsAdd);
    });
  }

  // EDIT form wiring
  const daySelectEdit = document.getElementById('daySelectEdit');
  const roomSelectEdit = document.getElementById('roomSelectEdit');
  const noRoomsEdit = document.getElementById('noRoomsEdit');

  if (daySelectEdit && roomSelectEdit) {
    // Determine the currently selected room (from PHP-rendered selected option)
    const selectedRoomId = roomSelectEdit.value || null;
    const roomObj = rooms.find(r => String(r.id) === String(selectedRoomId));

    // Preselect day based on the room's day_id, then filter rooms and keep selection
    if (roomObj && roomObj.day_id) {
      daySelectEdit.value = String(roomObj.day_id);
      filterRoomsFor(roomSelectEdit, daySelectEdit.value, selectedRoomId, noRoomsEdit);
    } else {
      // No current room or day mapping; leave as-is but wire change
      filterRoomsFor(roomSelectEdit, daySelectEdit.value || '', selectedRoomId, noRoomsEdit);
    }

    // When day changes, refilter and clear selection if not available
    daySelectEdit.addEventListener('change', () => {
      filterRoomsFor(roomSelectEdit, daySelectEdit.value, null, noRoomsEdit);
    });
  }
});
</script>
</body>
</html>