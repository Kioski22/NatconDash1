<?php
require "../db.php"; // Adjust path if needed
session_start();
header('Content-Type: application/json');

// Get input values
$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';

// Basic validation
if (empty($email) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
    exit;
}

// Check if email exists
$sql = "SELECT id, email, password FROM users WHERE email = ? LIMIT 1";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "s", $email);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if ($result && mysqli_num_rows($result) === 1) {
    $user = mysqli_fetch_assoc($result);

    // Check if password matches
    if (password_verify($password, $user['password'])) {
        // Start session
        $_SESSION['user'] = [
            'id' => $user['id'],
            'email' => $user['email']
        ];

        // Redirect to dashboard
        header("Location: ../dashboard/dashboard.php");
        exit;
    } else {
        // Email exists but password is incorrect
        echo json_encode(['success' => false, 'message' => 'Incorrect password.']);
    }
} else {
    // Email does not exist
    echo json_encode(['success' => false, 'message' => 'User with this email does not exist.']);
}

mysqli_stmt_close($stmt);
mysqli_close($conn);
