<?php
session_start();
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json; charset=utf-8');

$response = ['status' => 'error', 'message' => ''];

try {
    // normalize DB connection
    if (isset($conn) && $conn instanceof mysqli) {
        $db = $conn;
    } elseif (isset($mysqli) && $mysqli instanceof mysqli) {
        $db = $mysqli;
    } else {
        throw new Exception('DB unavailable');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method.');
    }

    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($name === '' || $email === '' || $password === '') {
        throw new Exception('All fields are required.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email address.');
    }
    if (strlen($password) < 8) {
        throw new Exception('Password must be at least 8 characters.');
    }

    // check duplicate email
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    if (!$stmt) throw new Exception('DB error.');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->close();
        throw new Exception('Email already exists.');
    }
    $stmt->close();

    $hash = password_hash($password, PASSWORD_DEFAULT);

    // insert user matching your schema (eligibility flags default to 0)
    $sql = 'INSERT INTO users
        (name, email, password_hash, created_at,
         eligible_certificate_attendance, eligible_certificate_appearance, eligible_cpd_certificate,
         eligibility_updated_at, eligibility_note)
        VALUES (?, ?, ?, NOW(), 0, 0, 0, NULL, NULL)';

    $ins = $db->prepare($sql);
    if (!$ins) throw new Exception('DB error.');
    $ins->bind_param('sss', $name, $email, $hash);
    if (!$ins->execute()) {
        // handle duplicate email race condition
        if ($ins->errno === 1062) {
            $ins->close();
            throw new Exception('Email already exists.');
        }
        $err = $ins->error;
        $ins->close();
        throw new Exception('Signup failed: ' . $err);
    }
    $uid = (int)$ins->insert_id;
    $ins->close();

    // Do NOT auto-login. Require explicit login after signup.
    // Provide a session-based toast for the login page.
    $_SESSION['toast'] = [
        'msg' => 'Account created successfully. Please log in.',
        'type' => 'success'
    ];

    $response['status'] = 'success';
    $response['message'] = 'Signup successful. Login required.';
    $response['redirect'] = '../Credentials/login.php';
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
?>
