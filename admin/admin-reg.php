<?php
// Start session only if not already active
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../db.php';

// normalize mysqli instance
$mysqli = null;
if (isset($mysqli) && $mysqli instanceof mysqli) {
    // already set by db.php
} elseif (isset($conn) && $conn instanceof mysqli) {
    $mysqli = $conn;
} elseif (isset($db) && $db instanceof mysqli) {
    $mysqli = $db;
}

// simple CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

$errors = [];
$success = false;
$name = '';
$email = '';
$role = 'guest'; // default per schema

// allow logged-in admin to set role
$canSetRole = (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], (string)$token)) {
        $errors[] = 'Invalid request.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';   
        $password2 = $_POST['password2'] ?? '';
        if ($canSetRole) {
            $role = trim($_POST['role'] ?? 'guest');
        } else {
            $role = 'guest';
        }

        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email is required.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if ($password !== $password2) {
            $errors[] = 'Passwords do not match.';
        }
        if (!($mysqli instanceof mysqli)) {
            $errors[] = 'Database connection not available.';
        }

        if (empty($errors)) {
            // check uniqueness
            $sql = "SELECT id FROM admin WHERE email = ? LIMIT 1";
            if ($stmt = $mysqli->prepare($sql)) {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows > 0) {
                    $errors[] = 'Email is already registered.';
                }
                $stmt->close();
            } else {
                $errors[] = 'Database error.';
            }
        }

        if (empty($errors)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $ins = "INSERT INTO admin (name, email, password, role) VALUES (?, ?, ?, ?)";
            if ($stmt = $mysqli->prepare($ins)) {
                $stmt->bind_param('ssss', $name, $email, $hash, $role);
                if ($stmt->execute()) {
                    $success = true;
                    // reset form values
                    $name = $email = '';
                    $role = 'guest';
                } else {
                    $errors[] = 'Failed to create account.';
                }
                $stmt->close();
            } else {
                $errors[] = 'Database error.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Admin Registration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h4 class="card-title mb-3">Register Admin</h4>

                    <?php if ($success): ?>
                        <div class="alert alert-success">Account created. <a href="admin_login.php">Login</a></div>
                    <?php endif; ?>

                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                <?php foreach ($errors as $e): ?>
                                    <li><?= htmlspecialchars($e) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="post" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <div class="mb-3">
                            <label class="form-label">Name</label>
                            <input class="form-control" name="name" required value="<?= htmlspecialchars($name) ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input class="form-control" name="email" type="email" required value="<?= htmlspecialchars($email) ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input class="form-control" name="password" type="password" required>
                            <div class="form-text">At least 8 characters.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm Password</label>
                            <input class="form-control" name="password2" type="password" required>
                        </div>

                        <?php if ($canSetRole): ?>
                            <div class="mb-3">
                                <label class="form-label">Role</label>
                                <select class="form-select" name="role">
                                    <option value="guest" <?= $role === 'guest' ? 'selected' : '' ?>>guest</option>
                                    <option value="admin" <?= $role === 'admin' ? 'selected' : '' ?>>admin</option>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="d-grid">
                            <button class="btn btn-primary">Create account</button>
                        </div>
                    </form>

                    <div class="mt-3 small text-muted">
                       
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>