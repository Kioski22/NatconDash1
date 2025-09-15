<?php
session_start();
require_once __DIR__ . '/../db.php';

// Only allow admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    die("Access denied");
}

// Handle mode change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mode'])) {
    $mode = $_POST['mode'] === 'admin_only' ? 'admin_only' : 'date_strict';
    $stmt = $conn->prepare("INSERT INTO app_settings (setting_key, setting_value) 
                             VALUES ('function_room_mode', ?) 
                             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->bind_param("s", $mode);
    $stmt->execute();
    $stmt->close();
    header("Location: admin_function_days.php");
    exit;
}

// Load current mode
$currentMode = 'date_strict';
$res = $conn->query("SELECT setting_value FROM app_settings WHERE setting_key='function_room_mode'");
if ($res && $row = $res->fetch_assoc()) {
    $currentMode = $row['setting_value'];
}

// Fetch days
$days = [];
$sql = "SELECT id, label, day_date, is_enabled FROM function_room_days ORDER BY day_date ASC";
if ($stmt = $conn->prepare($sql)) {
    $stmt->execute();
    $res = $stmt->get_result();
    $days = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin — Function Room Days</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
  <h2>Admin — Function Room Settings</h2>

  <form method="post" class="mb-4">
    <label class="form-label">Function Room Mode:</label>
    <select name="mode" class="form-select w-auto d-inline-block">
      <option value="date_strict" <?= $currentMode === 'date_strict' ? 'selected' : '' ?>>Date Strict</option>
      <option value="admin_only" <?= $currentMode === 'admin_only' ? 'selected' : '' ?>>Admin Only</option>
    </select>
    <button type="submit" class="btn btn-primary">Save Mode</button>
  </form>

  <h4>Toggle Days</h4>
  <div class="list-group">
    <?php foreach ($days as $d): ?>
      <div class="list-group-item d-flex justify-content-between align-items-center">
        <div>
          <strong><?= htmlspecialchars($d['label']) ?></strong>
          <div class="text-muted"><?= (new DateTime($d['day_date']))->format('F j, Y') ?></div>
        </div>
        <div class="form-check form-switch">
          <input class="form-check-input toggle-day" type="checkbox"
                 data-day-id="<?= (int)$d['id'] ?>" <?= ((int)$d['is_enabled'] === 1) ? 'checked' : '' ?>>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
document.querySelectorAll('.toggle-day').forEach(cb => {
  cb.addEventListener('change', function(){
    const dayId = this.dataset.dayId;
    const isEnabled = this.checked ? 1 : 0;
    fetch('../api/toggle_day.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ day_id: dayId, is_enabled: isEnabled })
    }).then(r=>r.json()).then(json=>{
      if (!json.success) {
        alert('Error: ' + (json.error || 'Could not update'));
        this.checked = !this.checked;
      }
    }).catch(()=>{
      alert('Network error');
      this.checked = !this.checked;
    });
  });
});
</script>
</body>
</html>
