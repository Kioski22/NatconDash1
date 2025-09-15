<?php
// toggle_day_admin.php
session_start();
require_once __DIR__ . '/../db.php';

// // Only admin can toggle
// if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
//     die("Access denied");
// }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dayId = (int)$_POST['day_id'];
    $newStatus = (int)$_POST['new_status'];

    $stmt = $conn->prepare("UPDATE function_room_days SET is_enabled = ? WHERE id = ?");
    $stmt->bind_param('ii', $newStatus, $dayId);
    $stmt->execute();
    $stmt->close();

    header("Location: ../admin/admin_function_days.php");
    exit;
}
