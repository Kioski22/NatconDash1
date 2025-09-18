<?php

session_start();
require_once __DIR__ . '/../db.php';

// normalize DB connection (support $conn or $mysqli)
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

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

$errors = [];
$success = '';

// Helper to escape
if (!function_exists('e')) {
    function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// Handle POST actions: add, update, delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], (string)$token)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'add') {
            $name = trim($_POST['name'] ?? '');
            $label = trim($_POST['label'] ?? '');
            $day_number = is_numeric($_POST['day_number'] ?? null) ? (int)$_POST['day_number'] : null;
            $day_date = trim($_POST['day_date'] ?? '') ?: null;
            $description = trim($_POST['description'] ?? '');

            if ($name === '') $errors[] = 'Name is required.';
            if ($day_number === null) $errors[] = 'Day number is required.';
            if (empty($errors)) {
                $stmt = $db->prepare("INSERT INTO function_rooms (name, label, day_number, day_date, description) VALUES (?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param('ssiss', $name, $label, $day_number, $day_date, $description);
                    if ($stmt->execute()) {
                        $success = 'Function room added.';
                    } else {
                        $errors[] = 'Insert failed: ' . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $errors[] = 'DB prepare failed: ' . $db->error;
                }
            }
        } elseif ($action === 'update') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $name = trim($_POST['name'] ?? '');
            $label = trim($_POST['label'] ?? '');
            $day_number = is_numeric($_POST['day_number'] ?? null) ? (int)$_POST['day_number'] : null;
            $day_date = trim($_POST['day_date'] ?? '') ?: null;
            $description = trim($_POST['description'] ?? '');

            if ($id <= 0) $errors[] = 'Invalid room id.';
            if ($name === '') $errors[] = 'Name is required.';
            if ($day_number === null) $errors[] = 'Day number is required.';

            if (empty($errors)) {
                $stmt = $db->prepare("UPDATE function_rooms SET name = ?, label = ?, day_number = ?, day_date = ?, description = ? WHERE id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('ssisii', $name, $label, $day_number, $day_date, $description, $id);
                    // note: bind_param types adjusted below because day_date/description are strings - fix types:
                    $stmt->close();
                    // correct prepared statement
                    $stmt = $db->prepare("UPDATE function_rooms SET name = ?, label = ?, day_number = ?, day_date = ?, description = ? WHERE id = ? LIMIT 1");
                    $stmt->bind_param('ssissi', $name, $label, $day_number, $day_date, $description, $id);
                    if ($stmt->execute()) {
                        $success = 'Function room updated.';
                    } else {
                        $errors[] = 'Update failed: ' . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $errors[] = 'DB prepare failed: ' . $db->error;
                }
            }
        } elseif ($action === 'delete') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id <= 0) $errors[] = 'Invalid id for delete.';
            if (empty($errors)) {
                $stmt = $db->prepare("DELETE FROM function_rooms WHERE id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('i', $id);
                    if ($stmt->execute()) {
                        $success = 'Function room deleted.';
                    } else {
                        $errors[] = 'Delete failed: ' . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $errors[] = 'DB prepare failed: ' . $db->error;
                }
            }
        } else {
            $errors[] = 'Unknown action.';
        }
    }

    // regenerate token and redirect to avoid form resubmission and keep messages
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    $_SESSION['mf_messages'] = ['errors' => $errors, 'success' => $success];
    header('Location: admin_manage_function_rooms.php');
    exit;
}

// show messages if any
$messages = $_SESSION['mf_messages'] ?? null;
if ($messages) {
    $errors = array_merge($errors, $messages['errors'] ?? []);
    if (!empty($messages['success'])) $success = $messages['success'];
    unset($_SESSION['mf_messages']);
}

// fetch rooms list
$rooms = [];
$res = $db->query("SELECT id, name, label, day_number, day_date, description FROM function_rooms ORDER BY day_number ASC, id ASC");
if ($res) {
    while ($r = $res->fetch_assoc()) $rooms[] = $r;
}

// load single room for edit if ?edit=id
$editRoom = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $stmt = $db->prepare("SELECT id, name, label, day_number, day_date, description FROM function_rooms WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $eid);
        $stmt->execute();
        $editRoom = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Manage Function Rooms</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3>Function Rooms — Manage</h3>
    <a href="Admin_dashboard.php" class="btn btn-secondary btn-sm">Back</a>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
      <ul class="mb-0"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="alert alert-success"><?= e($success) ?></div>
  <?php endif; ?>

  <div class="row">
    <div class="col-lg-7">
      <div class="card mb-3">
        <div class="card-body">
          <h5 class="card-title">Existing Function Rooms</h5>
          <?php if (empty($rooms)): ?>
            <div class="text-muted">No function rooms found.</div>
          <?php else: ?>
            <table class="table table-striped table-sm">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Name</th>
                  <th>Label</th>
                  <th>Day</th>
                  <th>Date</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rooms as $r): ?>
                  <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><?= e($r['name']) ?></td>
                    <td><?= e($r['label']) ?></td>
                    <td><?= e($r['day_number']) ?></td>
                    <td><?= e($r['day_date']) ?></td>
                    <td class="text-end">
                      <a href="admin_manage_function_rooms.php?edit=<?= (int)$r['id'] ?>" class="btn btn-sm btn-primary">Edit</a>
                      <form method="post" class="d-inline" onsubmit="return confirm('Delete this room?');">
                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-sm btn-danger">Delete</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card mb-3">
        <div class="card-body">
          <?php if ($editRoom): ?>
            <h5 class="card-title">Edit Function Room #<?= (int)$editRoom['id'] ?></h5>
            <form method="post" novalidate>
              <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= (int)$editRoom['id'] ?>">

              <div class="mb-2">
                <label class="form-label">Name</label>
                <input name="name" class="form-control" required value="<?= e($editRoom['name']) ?>">
              </div>
              <div class="mb-2">
                <label class="form-label">Label</label>
                <input name="label" class="form-control" value="<?= e($editRoom['label']) ?>">
              </div>
              <div class="mb-2">
                <label class="form-label">Day Number</label>
                <input name="day_number" type="number" min="1" class="form-control" required value="<?= e($editRoom['day_number']) ?>">
              </div>
              <div class="mb-2">
                <label class="form-label">Day Date (YYYY-MM-DD)</label>
                <input name="day_date" type="date" class="form-control" value="<?= e($editRoom['day_date']) ?>">
              </div>
              <div class="mb-2">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="3"><?= e($editRoom['description']) ?></textarea>
              </div>

              <div class="d-grid">
                <button class="btn btn-primary">Update Room</button>
                <a href="admin_manage_function_rooms.php" class="btn btn-link mt-2">Cancel</a>
              </div>
            </form>

          <?php else: ?>
            <h5 class="card-title">Add Function Room</h5>
            <form method="post" novalidate>
              <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
              <input type="hidden" name="action" value="add">

              <div class="mb-2">
                <label class="form-label">Name</label>
                <input name="name" class="form-control" required value="">
              </div>
              <div class="mb-2">
                <label class="form-label">Label</label>
                <input name="label" class="form-control" value="">
              </div>
              <div class="mb-2">
                <label class="form-label">Day Number</label>
                <input name="day_number" type="number" min="1" class="form-control" required value="">
              </div>
              <div class="mb-2">
                <label class="form-label">Day Date (YYYY-MM-DD)</label>
                <input name="day_date" type="date" class="form-control" value="">
              </div>
              <div class="mb-2">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="3"></textarea>
              </div>

              <div class="d-grid">
                <button class="btn btn-success">Add Room</button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-body small text-muted">
          Use the table to edit or remove rooms. Add a room then assign speakers in the Speakers management page.
        </div>
      </div>
    </div>
  </div>
</div>

</body>
</html>