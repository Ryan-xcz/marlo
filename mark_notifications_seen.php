<?php
session_start();
include 'database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_name'])) {
    echo json_encode(["status" => "error", "message" => "Not logged in"]);
    exit();
}

$current_user = $_SESSION['user_name'];

$check = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'notification_seen_at'");
if ($check && mysqli_num_rows($check) == 0) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN notification_seen_at DATETIME NULL");
}

$stmt = mysqli_prepare($conn, "UPDATE users SET notification_seen_at = NOW() WHERE fullname = ?");
if (!$stmt) {
    echo json_encode(["status" => "failed", "message" => mysqli_error($conn)]);
    exit();
}

mysqli_stmt_bind_param($stmt, "s", $current_user);
$ok = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

echo json_encode(["status" => $ok ? "success" : "failed"]);
?>
