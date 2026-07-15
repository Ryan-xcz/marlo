<?php
// config.php
include 'database.php';
if (!isset($_SESSION)) { session_start(); }

if (isset($_SESSION['user_name'])) {
    $uname = mysqli_real_escape_string($conn, $_SESSION['user_name']);
    $query = mysqli_query($conn, "SELECT dark_mode, email_notif, event_reminders FROM users WHERE fullname = '$uname'");
    if ($query && mysqli_num_rows($query) > 0) {
        $prefs = mysqli_fetch_assoc($query);
        $_SESSION['dark_mode'] = $prefs['dark_mode'];
        $_SESSION['email_notif'] = $prefs['email_notif'];
        $_SESSION['event_reminders'] = $prefs['event_reminders'];
    }
}
?>
