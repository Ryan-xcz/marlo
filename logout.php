<?php
session_start();
include 'database.php';

if (isset($_SESSION['user_name'])) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN IF NOT EXISTS is_online TINYINT(1) NOT NULL DEFAULT 0");
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN IF NOT EXISTS last_activity DATETIME NULL");

    $fullname = $_SESSION['user_name'];

    $stmt = mysqli_prepare($conn, "UPDATE users SET is_online = 0, last_activity = NULL WHERE fullname = ?");
    mysqli_stmt_bind_param($stmt, "s", $fullname);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

$_SESSION = array();

session_destroy();

header("Location: login.php");
exit();
?>
