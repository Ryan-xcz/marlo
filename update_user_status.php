<?php
session_start();
include 'database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_name'])) {
    echo json_encode(["status" => "error"]);
    exit();
}

mysqli_query($conn, "ALTER TABLE users ADD COLUMN IF NOT EXISTS last_activity DATETIME NULL");
mysqli_query($conn, "ALTER TABLE users ADD COLUMN IF NOT EXISTS is_online TINYINT(1) NOT NULL DEFAULT 0");

$fullname = $_SESSION['user_name'];

$stmt = mysqli_prepare($conn, "UPDATE users SET is_online = 1, last_activity = NOW() WHERE fullname = ?");
mysqli_stmt_bind_param($stmt, "s", $fullname);

if (mysqli_stmt_execute($stmt)) {
    echo json_encode(["status" => "success"]);
} else {
    echo json_encode(["status" => "failed"]);
}

mysqli_stmt_close($stmt);
?>
