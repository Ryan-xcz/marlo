<?php
$host = "localhost";
$user = "root";
$password = "";
$dbname = "smart_event_db";

$conn = mysqli_connect($host, $user, $password, $dbname);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");
?>
