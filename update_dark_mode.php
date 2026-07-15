<?php
session_start();
include 'database.php';

if (!isset($_SESSION['user_name'])) {
    http_response_code(401);
    exit();
}

$dark_mode = isset($_POST['dark_mode']) && $_POST['dark_mode'] == 1 ? 1 : 0;
$user = mysqli_real_escape_string($conn, $_SESSION['user_name']);

$check = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'dark_mode'");
if ($check && mysqli_num_rows($check) == 0) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN dark_mode TINYINT(1) NOT NULL DEFAULT 0");
}

mysqli_query($conn, "UPDATE users SET dark_mode = $dark_mode WHERE fullname = '$user'");
echo "ok";
?>
