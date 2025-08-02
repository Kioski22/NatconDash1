<?php
require '../db.php';
header("Content-Type: application/json"); // Let frontend know it's JSON

$response = ["status" => "error", "message" => ""];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim($_POST["name"] ?? '');
    $email = trim($_POST["email"] ?? '');
    $password = $_POST["password"] ?? '';

    if (!$name || !$email || !$password) {
        $response["message"] = "All fields are required.";
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $response["message"] = "Email already exists.";
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $name, $email, $hashedPassword);

            if ($stmt->execute()) {
                $response["status"] = "success";
                $response["message"] = "Signup successful.";
                $response["redirect"] = "http://localhost/NatconDash1/api/api_login.php";
            } else {
                $response["message"] = "Signup failed: " . $stmt->error;
            }
        }
        $stmt->close();
    }
}

echo json_encode($response);
?>
