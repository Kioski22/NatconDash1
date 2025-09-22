<?php
// Start session if not already active
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../db.php';

// normalize mysqli instance provided by db.php
$mysqli = null;
if (isset($mysqli) && $mysqli instanceof mysqli) {
    // already provided
} elseif (isset($conn) && $conn instanceof mysqli) {
    $mysqli = $conn;
} elseif (isset($db) && $db instanceof mysqli) {
    $mysqli = $db;
}

$error = '';
$postedEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedEmail = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($postedEmail === '' || $password === '') {
        $error = 'Please provide email and password.';
    } elseif (!($mysqli instanceof mysqli)) {
        $error = 'Database connection not available.';
    } else {
        // Use the admin schema: email, password, role
        $sql = "SELECT id, name, email, password, role FROM admin WHERE email = ? LIMIT 1";
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param('s', $postedEmail);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $role = $row['role'] ?? '';
                if ($role !== 'super') {
                    $error = 'Unauthorized.';
                } else {
                    $hash = $row['password'] ?? '';
                    if ($hash === '') {
                        $error = 'Invalid account configuration.';
                    } elseif (password_verify($password, $hash)) {
                        // Successful login
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = (int)$row['id'];
                        $_SESSION['user_name'] = $row['name'];
                        $_SESSION['user_email'] = $row['email'];
                        $_SESSION['user_role'] = 'admin';
                        header('Location: admin_dashboard.php');
                        exit;
                    } else {
                        $error = 'Invalid credentials.';
                    }
                }
            } else {
                $error = 'Invalid credentials.';
            }
            $stmt->close();
        } else {
            $error = 'Query error.';
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Admin Login</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
</head>
<body class="bg-light d-flex align-items-center justify-content-center vh-100">
    <div class="card shadow p-4" style="width: 360px;">
        <h3 class="text-center mb-3">Admin Login</h3>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" required autofocus value="<?= htmlspecialchars($postedEmail) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Login</button>
        </form>
    </div>
</body>
</html>
