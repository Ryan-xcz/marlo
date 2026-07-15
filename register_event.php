<?php
session_start();
include 'database.php';

if (!isset($_SESSION['user_name'])) {
    header("Location: index.php");
    exit();
}

$current_user = mysqli_real_escape_string($conn, $_SESSION['user_name']);

// Make sure columns exist
mysqli_query($conn, "ALTER TABLE users ADD COLUMN IF NOT EXISTS student_id VARCHAR(50) NULL");
mysqli_query($conn, "ALTER TABLE users ADD COLUMN IF NOT EXISTS course VARCHAR(100) NULL");
mysqli_query($conn, "ALTER TABLE users ADD COLUMN IF NOT EXISTS year_level VARCHAR(50) NULL");
mysqli_query($conn, "ALTER TABLE users ADD COLUMN IF NOT EXISTS contact_number VARCHAR(30) NULL");
mysqli_query($conn, "ALTER TABLE users ADD COLUMN IF NOT EXISTS last_activity DATETIME NULL");
mysqli_query($conn, "ALTER TABLE users ADD COLUMN IF NOT EXISTS is_online TINYINT(1) NOT NULL DEFAULT 0");

// Update online status
$stmt_online = mysqli_prepare($conn, "UPDATE users SET is_online = 1, last_activity = NOW() WHERE fullname = ?");
mysqli_stmt_bind_param($stmt_online, "s", $_SESSION['user_name']);
mysqli_stmt_execute($stmt_online);
mysqli_stmt_close($stmt_online);

// Fetch User Settings
$user_settings_query = mysqli_query($conn, "SELECT email_notif, event_reminders FROM users WHERE fullname = '$current_user'");
$user_settings = mysqli_fetch_assoc($user_settings_query);
$show_notifications = ($user_settings && $user_settings['email_notif'] == 1);


// ==========================================
// NOTIFICATION DATA FOR TOP NAV
// FINAL STEADY FIX:
// - Red badge count shows ONLY NEW registrations/events since user clicked the bell.
// - Dropdown still shows recent activity even when the badge number is gone.
// ==========================================
$notif_count = 0;
$recent_registrations = [];

// Make sure the notification timestamp column exists.
$check_seen_col = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'notification_seen_at'");
if ($check_seen_col && mysqli_num_rows($check_seen_col) == 0) {
    mysqli_query($conn, "ALTER TABLE users ADD COLUMN notification_seen_at DATETIME NULL");
}

// Make sure registrations.created_at exists.
$reg_table_check = mysqli_query($conn, "SHOW TABLES LIKE 'registrations'");
if ($reg_table_check && mysqli_num_rows($reg_table_check) > 0) {
    $check_reg_created = mysqli_query($conn, "SHOW COLUMNS FROM registrations LIKE 'created_at'");
    if ($check_reg_created && mysqli_num_rows($check_reg_created) == 0) {
        mysqli_query($conn, "ALTER TABLE registrations ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
    }
}

// Make sure events.created_at exists.
$event_table_check_for_notif = mysqli_query($conn, "SHOW TABLES LIKE 'events'");
if ($event_table_check_for_notif && mysqli_num_rows($event_table_check_for_notif) > 0) {
    $check_event_created = mysqli_query($conn, "SHOW COLUMNS FROM events LIKE 'created_at'");
    if ($check_event_created && mysqli_num_rows($check_event_created) == 0) {
        mysqli_query($conn, "ALTER TABLE events ADD COLUMN created_at DATETIME NULL");
        mysqli_query($conn, "UPDATE events SET created_at = COALESCE(start_date, event_date, NOW()) WHERE created_at IS NULL");
    }
}

$notif_user_safe = mysqli_real_escape_string($conn, $_SESSION['user_name']);
$seen_query = mysqli_query($conn, "SELECT notification_seen_at FROM users WHERE fullname = '$notif_user_safe' LIMIT 1");
$seen_data = $seen_query ? mysqli_fetch_assoc($seen_query) : null;
$notification_seen_at = $seen_data['notification_seen_at'] ?? null;

if ($show_notifications) {
    // COUNT ONLY NEW ITEMS FOR RED BADGE
    $seen_reg_condition = $notification_seen_at ? "WHERE created_at > '$notification_seen_at'" : "";
    $seen_event_condition = $notification_seen_at ? "WHERE created_at > '$notification_seen_at'" : "";

    if ($reg_table_check && mysqli_num_rows($reg_table_check) > 0) {
        $n_reg_query = mysqli_query($conn, "SELECT COUNT(*) AS total FROM registrations $seen_reg_condition");
        if ($n_reg_query) {
            $notif_count += (int) mysqli_fetch_assoc($n_reg_query)['total'];
        }
    }

    if ($event_table_check_for_notif && mysqli_num_rows($event_table_check_for_notif) > 0) {
        $n_event_query = mysqli_query($conn, "SELECT COUNT(*) AS total FROM events $seen_event_condition");
        if ($n_event_query) {
            $notif_count += (int) mysqli_fetch_assoc($n_event_query)['total'];
        }
    }

    // DROPDOWN ALWAYS SHOWS LATEST ACTIVITY, EVEN IF BADGE IS 0
    $notification_parts = [];

    if ($reg_table_check && mysqli_num_rows($reg_table_check) > 0) {
        $notification_parts[] = "
            SELECT
                'registration' AS notif_type,
                student_name AS student_name,
                event_name AS event_name,
                created_at AS created_at
            FROM registrations
        ";
    }

    if ($event_table_check_for_notif && mysqli_num_rows($event_table_check_for_notif) > 0) {
        $notification_parts[] = "
            SELECT
                'event' AS notif_type,
                COALESCE(NULLIF(organizer, ''), 'Admin') AS student_name,
                title AS event_name,
                created_at AS created_at
            FROM events
        ";
    }

    if (!empty($notification_parts)) {
        $notification_sql = "
            SELECT * FROM (
                " . implode(" UNION ALL ", $notification_parts) . "
            ) AS notifications
            WHERE created_at IS NOT NULL
            ORDER BY created_at DESC
            LIMIT 5
        ";

        $r_query = mysqli_query($conn, $notification_sql);
        if ($r_query) {
            while ($r = mysqli_fetch_assoc($r_query)) {
                $recent_registrations[] = $r;
            }
        }
    }
}

// Fetch logged-in user's personal info
$user_query = mysqli_query($conn, "
    SELECT email, student_id, course, year_level, contact_number 
    FROM users 
    WHERE fullname = '$current_user'
");

$user_email = "";
$user_student_id = "";
$user_course = "";
$user_year_level = "";
$user_contact = "";

if ($user_query && mysqli_num_rows($user_query) > 0) {
    $user_data = mysqli_fetch_assoc($user_query);

    $user_email = $user_data['email'] ?? "";
    $user_student_id = $user_data['student_id'] ?? "";
    $user_course = $user_data['course'] ?? "";
    $user_year_level = $user_data['year_level'] ?? "";
    $user_contact = $user_data['contact_number'] ?? "";
}

// Fetch Events
$events_query = mysqli_query($conn, "SELECT id, title, event_date FROM events ORDER BY event_date ASC");

// RabbitMQ Setup
$rabbitmq_installed = false;
$amqp_connection_class = 'PhpAmqpLib\\Connection\\AMQPStreamConnection';
$amqp_message_class = 'PhpAmqpLib\\Message\\AMQPMessage';

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    $rabbitmq_installed = class_exists($amqp_connection_class) && class_exists($amqp_message_class);
}

$submitted = false;

// Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $full_name = $_SESSION['user_name'];
    $email = $user_email;

    $student_id = $user_student_id;
    $course = $user_course;
    $year_level = $user_year_level;
    $contact = $user_contact;

    $event = htmlspecialchars($_POST["event"]);
    $notes = isset($_POST["notes"]) ? htmlspecialchars($_POST["notes"]) : "";

    if (empty($student_id) || empty($course) || empty($year_level)) {
        $_SESSION['flash_error'] = "Please complete your Account Settings first before registering for an event.";
    } elseif (!preg_match('/^[0-9]{4}-[0-9]{4}$/', $student_id)) {
        $_SESSION['flash_error'] = "Security Error: Your Student ID in Account Settings must follow the format 0000-0000.";
    } elseif (!empty($contact) && !preg_match('/^[0-9]+$/', $contact)) {
        $_SESSION['flash_error'] = "Security Error: Contact Number must contain only numbers.";
    } else {

        // Check if Student ID belongs to another user
        $check_owner = mysqli_prepare($conn, "
            SELECT fullname 
            FROM users 
            WHERE student_id = ? AND fullname != ?
        ");
        mysqli_stmt_bind_param($check_owner, "ss", $student_id, $full_name);
        mysqli_stmt_execute($check_owner);
        $owner_result = mysqli_stmt_get_result($check_owner);

        if (mysqli_num_rows($owner_result) > 0) {
            $_SESSION['flash_error'] = "This Student ID belongs to another user.";
        } else {

            $db_name = mysqli_real_escape_string($conn, $full_name);
            $db_id = mysqli_real_escape_string($conn, $student_id);
            $db_course = mysqli_real_escape_string($conn, $course);
            $db_email = mysqli_real_escape_string($conn, $email);
            $db_event = mysqli_real_escape_string($conn, $event);
            $db_year = mysqli_real_escape_string($conn, $year_level);
            $db_contact = mysqli_real_escape_string($conn, $contact);
            $db_notes = mysqli_real_escape_string($conn, $notes);

            $check_dup = mysqli_query($conn, "
                SELECT id 
                FROM registrations 
                WHERE student_name = '$db_name' 
                AND event_name = '$db_event'
            ");

            if (mysqli_num_rows($check_dup) > 0) {
                $_SESSION['flash_error'] = "You are already registered for the event: " . htmlspecialchars($event);
            } else {
              $sql = "INSERT INTO registrations 
(
    student_name,
    student_id,
    course,
    year_level,
    email,
    contact_number,
    event_name,
    notes,
    attendance_status,
    created_at
)
VALUES
(
    '$db_name',
    '$db_id',
    '$db_course',
    '$db_year',
    '$db_email',
    '$db_contact',
    '$db_event',
    '$db_notes',
    'Pending',
    NOW()
)";
                if (mysqli_query($conn, $sql)) {
                    $submitted = true;
                    $_SESSION['flash_msg'] = "Registration added successfully.";

                    // Show this new registration immediately in the bell dropdown on this page.
                    if ($show_notifications) {
                        $notif_count++;
                        array_unshift($recent_registrations, [
                            'notif_type' => 'registration',
                            'student_name' => $full_name,
                            'event_name' => $event,
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                        $recent_registrations = array_slice($recent_registrations, 0, 5);
                    }

                    if ($rabbitmq_installed && isset($_SESSION['email_notif']) && $_SESSION['email_notif'] == 1) {
                        try {
                            $connectionClass = $amqp_connection_class;
                            $connection = new $connectionClass('localhost', 5672, 'guest', 'guest');
                            $channel = $connection->channel();
                            $channel->queue_declare('email_queue', false, true, false, false);

                            $payload = json_encode([
                                'action' => 'send_registration_email',
                                'student_name' => $full_name,
                                'email' => $email,
                                'event' => $event,
                                'timestamp' => date('Y-m-d H:i:s')
                            ]);

                            $messageClass = $amqp_message_class;
                            $msg = new $messageClass($payload, ['delivery_mode' => 2]);
                            $channel->basic_publish($msg, '', 'email_queue');

                            $channel->close();
                            $connection->close();
                        } catch (Exception $e) {
                            error_log("RabbitMQ Error: " . $e->getMessage());
                        }
                    }
                } else {
                    $_SESSION['flash_error'] = "Database Error: " . mysqli_error($conn);
                }
            }
        }

        mysqli_stmt_close($check_owner);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register for Event - Smart Event Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }

        body {
            background-color: #f4f7fe;
            color: #1e293b;
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 286px;
            background: #0b132b;
            color: #ffffff;
            position: fixed;
            inset: 0 auto 0 0;
            height: 100vh;
            padding: 32px 14px;
            z-index: 100;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }

        .profile {
            text-align: center;
            margin-bottom: 24px;
        }

        .avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            margin: 0 auto 12px;
            background: #3b82f6;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: 900;
            text-transform: uppercase;
        }

        .profile h3 {
            font-size: 20px;
            font-weight: 800;
            color: #ffffff;
        }

        .profile p {
            margin-top: 5px;
            font-size: 13px;
            color: #8297bb;
            font-weight: 500;
        }

        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 10px;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 12px;
            border-radius: 20px;
            color: #22c55e;
            background: rgba(34, 197, 94, 0.1);
        }

        .menu {
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex: 1;
        }

        .menu a {
            text-decoration: none;
            color: #a9bddc;
            height: 46px;
            padding: 0 20px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 15px;
            font-weight: 700;
        }

        .menu a:hover {
            color: #ffffff;
            background: rgba(255,255,255,0.07);
        }

        .menu a.active {
            background: #2563eb;
            color: #ffffff;
        }

        .menu-icon {
            width: 22px;
            text-align: center;
            font-size: 18px;
        }

        .logout-link {
            margin-top: auto;
            color: #ef4444 !important;
        }

        .wrapper-main {
            flex: 1;
            margin-left: 286px;
            display: flex;
            flex-direction: column;
            width: calc(100% - 286px);
        }

        .top-navbar {
            height: 70px;
            background: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 40px;
            border-bottom: 1px solid #e2e8f0;
            position: sticky;
            top: 0;
            z-index: 90;
        }

        .menu-btn {
            cursor: pointer;
            color: #64748b;
            font-size: 1.2rem;
        }

        .nav-right {
            display: flex;
            align-items: center;
            gap: 15px;
            color: #64748b;
            font-size: 1.25rem;
        }

        .nav-icon {
            cursor: pointer;
            position: relative;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            background: transparent;
            color: #64748b;
            text-decoration: none;
        }

        .badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: #ef4444;
            color: white;
            font-size: 0.65rem;
            font-weight: bold;
            min-width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .mini-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #3b82f6;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .main-content {
            padding: 40px 50px;
            flex: 1;
        }

        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 38px;
        }

        .page-header h1 {
            font-size: 2.2rem;
            font-weight: 800;
            color: #1e293b;
        }

        .page-header p {
            color: #64748b;
            font-size: 1.05rem;
            margin-top: 8px;
        }

        .breadcrumb {
            background: #eff6ff;
            padding: 10px 20px;
            border-radius: 20px;
            color: #2563eb;
            font-weight: 700;
            font-size: 0.85rem;
        }

        .grid {
            display: grid;
            grid-template-columns: 1.35fr 0.85fr;
            gap: 30px;
        }

        .panel {
            background: #ffffff;
            border: 1px solid #dce5f1;
            border-radius: 18px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.02);
        }

        .panel-body {
            padding: 32px;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 28px;
        }

        .icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: #eaf1ff;
            color: #2563eb;
            display: grid;
            place-items: center;
            font-size: 1.2rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 22px 24px;
        }

        .form-group.full {
            grid-column: span 2;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 800;
            color: #1e293b;
            font-size: 0.9rem;
        }

        input, select, textarea {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 14px 18px;
            font-size: 16px;
            outline: none;
            background: #f8fafc;
            color: #1e293b;
        }

        input[readonly] {
            background-color: #e2e8f0;
            color: #64748b;
            cursor: not-allowed;
        }

        textarea {
            min-height: 120px;
            resize: vertical;
        }

        .btn {
            margin-top: 22px;
            border: none;
            padding: 16px 28px;
            border-radius: 12px;
            background: #2563eb;
            color: #fff;
            font-weight: 900;
            cursor: pointer;
        }

        .btn:hover {
            background: #1d4ed8;
        }

        .success {
            margin-bottom: 28px;
            border: 1px solid #b8f0d0;
            background: #ecfdf5;
            border-radius: 16px;
            padding: 22px 26px;
            color: #065f46;
        }

        .error-banner {
            margin-bottom: 28px;
            border: 1px solid #fca5a5;
            background: #fee2e2;
            border-radius: 16px;
            padding: 16px 20px;
            color: #991b1b;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }

        .flash-success {
            background: #dcfce7;
            border: 1px solid #86efac;
            color: #166534;
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .event-banner {
            min-height: 190px;
            background: linear-gradient(135deg, #2563eb, #7c3aed);
            color: #fff;
            padding: 32px;
            border-radius: 18px 18px 0 0;
        }

        .event-badge {
            background: rgba(255,255,255,0.18);
            padding: 8px 16px;
            border-radius: 999px;
            width: fit-content;
            font-weight: 900;
            font-size: 0.85rem;
        }

        .event-banner h2 {
            margin-top: 15px;
            font-size: 1.8rem;
            font-weight: 800;
        }

        .info-list {
            padding: 28px;
        }

        .info-item {
            display: flex;
            gap: 16px;
            margin-bottom: 20px;
        }

        .info-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: #f0f6ff;
            color: #2563eb;
            display: grid;
            place-items: center;
            font-size: 1.2rem;
        }

        .info-item h4 {
            color: #1e293b;
            margin-bottom: 4px;
            font-weight: 700;
        }

        .info-item p {
            color: #64748b;
            font-size: 0.9rem;
        }

        .settings-warning {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #9a3412;
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-weight: 700;
        }

        .settings-warning a {
            color: #2563eb;
            font-weight: 900;
        }
    
.dropdown-menu {
    display: none;
    position: absolute;
    top: 50px;
    right: 0;
    background: #ffffff;
    width: 320px;
    border-radius: 12px;
    box-shadow: 0 10px 25px rgba(0,0,0,0.1);
    border: 1px solid #e2e8f0;
    z-index: 1000;
    overflow: hidden;
    text-align: left;
}
.dropdown-menu.active { display: block; }
.dropdown-header { padding: 15px 20px; border-bottom: 1px solid #e2e8f0; font-weight: 800; color: #1e293b; background: #f8fafc; }
.dropdown-body { max-height: 350px; overflow-y: auto; padding: 10px 0; }
.notif-item { padding: 12px 20px; border-bottom: 1px solid #f1f5f9; }
.notif-title { font-size: 0.85rem; font-weight: 700; color: #1e293b; }
.notif-desc { font-size: 0.75rem; color: #64748b; margin-top: 4px; }
.notif-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.65rem; font-weight: 700; margin-right: 6px; }
.badge-reg { background: #dcfce7; color: #166534; }
.badge-event { background: #e0e7ff; color: #3730a3; }
.search-container { padding: 15px; display: flex; gap: 10px; }
.search-container input { flex: 1; padding: 10px 15px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem; outline: none; }
.search-btn { background: #2563eb; color: white; border: none; padding: 0 15px; border-radius: 8px; font-weight: 700; cursor: pointer; }

</style>
</head>

<body>

<aside class="sidebar">
    <div class="profile">
        <div class="avatar"><?php echo substr(htmlspecialchars($_SESSION['user_name']), 0, 1); ?></div>
        <h3><?php echo htmlspecialchars($_SESSION['user_name']); ?></h3>
        <p>BSIT-2026 / Admin</p>
        <div class="status-indicator">
            <i class="fa-solid fa-circle"></i>
            <span>Online</span>
        </div>
    </div>

    <nav class="menu">
        <a href="home.php"><i class="fa-solid fa-house menu-icon"></i><span>Index Portal</span></a>
        <a href="create_event.php"><i class="fa-solid fa-calendar-plus menu-icon"></i><span>Create Event</span></a>
        <a href="register_event.php" class="active"><i class="fa-solid fa-user-check menu-icon"></i><span>Register for Event</span></a>
        <a href="dashboard.php"><i class="fa-solid fa-chart-line menu-icon"></i><span>Full Dashboard</span></a>
        <a href="analytics.php"><i class="fa-solid fa-chart-bar menu-icon"></i><span>Analytics</span></a>
        <a href="xml_report.php"><i class="fa-solid fa-file-code menu-icon"></i><span>XML Report</span></a>
        <a href="settings.php"><i class="fa-solid fa-gear menu-icon"></i><span>Settings</span></a>
        <a href="help.php"><i class="fa-solid fa-circle-question menu-icon"></i><span>Help & Support</span></a>
        <a href="logout.php" class="logout-link"><i class="fa-solid fa-arrow-right-from-bracket menu-icon"></i><span>Logout</span></a>
    </nav>
</aside>

<div class="wrapper-main">
    <header class="top-navbar">
        <div class="nav-left">
            <div class="menu-btn"><i class="fa-solid fa-bars"></i></div>
        </div>

    <div class="nav-right">
        <div class="nav-icon" id="searchToggle" title="Search">
            <i class="fa-solid fa-magnifying-glass"></i>
            <div class="dropdown-menu" id="searchDropdown">
                <div class="dropdown-header">Search System</div>
                <form action="home.php" method="GET" class="search-container">
                    <input type="text" name="query" placeholder="Search events or users..." required>
                    <button type="submit" class="search-btn"><i class="fa-solid fa-magnifying-glass"></i></button>
                </form>
            </div>
        </div>

        <div class="nav-icon" id="notifToggle" title="Notifications">
            <i class="fa-regular fa-bell"></i>
            <?php if ($notif_count > 0 && $show_notifications): ?>
                <div class="badge" id="notifBadge"><?php echo $notif_count; ?></div>
            <?php endif; ?>

            <div class="dropdown-menu" id="notifDropdown">
                <div class="dropdown-header">Recent Activity</div>
                <div class="dropdown-body" id="notifBody">
                    <?php if (!empty($recent_registrations)): ?>
                        <?php foreach ($recent_registrations as $reg): ?>
                            <div class="notif-item">
                                <?php if (($reg['notif_type'] ?? '') === 'event'): ?>
                                    <div class="notif-title"><span class="notif-badge badge-event">New Event</span><?php echo htmlspecialchars($reg['event_name']); ?></div>
                                    <div class="notif-desc">Created by: <?php echo htmlspecialchars($reg['student_name']); ?></div>
                                <?php else: ?>
                                    <div class="notif-title"><span class="notif-badge badge-reg">New Reg</span><?php echo htmlspecialchars($reg['student_name']); ?></div>
                                    <div class="notif-desc">Registered for: <?php echo htmlspecialchars($reg['event_name']); ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="notif-item"><div class="notif-desc" style="text-align:center;">No new notifications.</div></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <a href="settings.php" class="nav-icon" title="Settings">
            <i class="fa-solid fa-gear"></i>
        </a>

        <div class="mini-avatar"><?php echo substr(htmlspecialchars($_SESSION['user_name'] ?? 'RY'), 0, 2); ?></div>
    </div>
    </header>

    <main class="main-content">
        <div class="container">
            <div class="page-header">
                <div>
                    <h1>Register for Event</h1>
                    <p>Complete attendee details and submit registration.</p>
                </div>
                <div class="breadcrumb">Dashboard › Register Event</div>
            </div>

            <?php if (isset($_SESSION['flash_msg'])): ?>
                <div class="flash-success">
                    <i class="fa-solid fa-circle-check"></i>
                    <?php 
                        echo htmlspecialchars($_SESSION['flash_msg']); 
                        unset($_SESSION['flash_msg']); 
                    ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['flash_error'])): ?>
                <div class="error-banner">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <?php 
                        echo htmlspecialchars($_SESSION['flash_error']); 
                        unset($_SESSION['flash_error']); 
                    ?>
                </div>
            <?php endif; ?>

            <?php if (empty($user_student_id) || empty($user_course) || empty($user_year_level)): ?>
                <div class="settings-warning">
                    Please complete your Student ID, Course, and Year Level in 
                    <a href="settings.php">Account Settings</a> before registering.
                </div>
            <?php endif; ?>

            <?php if ($submitted): ?>
                <div class="success">
                    <h3>Registration Successful!</h3>
                    <p><strong>Name:</strong> <?= htmlspecialchars($full_name); ?></p>
                    <p><strong>Student ID:</strong> <?= htmlspecialchars($student_id); ?></p>
                    <p><strong>Course:</strong> <?= htmlspecialchars($course); ?></p>
                    <p><strong>Email:</strong> <?= htmlspecialchars($email); ?></p>
                    <p><strong>Event:</strong> <?= htmlspecialchars($event); ?></p>
                    <p><strong>Year Level:</strong> <?= htmlspecialchars($year_level); ?></p>
                </div>
            <?php endif; ?>

            <div class="grid">
                <div class="panel">
                    <div class="panel-body">
                        <div class="section-title">
                            <div class="icon">👥</div>
                            <h2>Attendee Information</h2>
                        </div>

                        <form method="POST">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Full Name (Locked)</label>
                                    <input type="text" value="<?php echo htmlspecialchars($_SESSION['user_name']); ?>" readonly>
                                </div>

                                <div class="form-group">
                                    <label>Student ID (From Account Settings)</label>
                                    <input type="text" name="student_id" value="<?php echo htmlspecialchars($user_student_id); ?>" readonly required>
                                </div>

                                <div class="form-group">
                                    <label>Course (From Account Settings)</label>
                                    <input type="text" name="course" value="<?php echo htmlspecialchars($user_course); ?>" readonly required>
                                </div>

                                <div class="form-group">
                                    <label>Year Level (From Account Settings)</label>
                                    <input type="text" name="year_level" value="<?php echo htmlspecialchars($user_year_level); ?>" readonly required>
                                </div>

                                <div class="form-group">
                                    <label>Email (Locked)</label>
                                    <input type="email" value="<?php echo htmlspecialchars($user_email); ?>" readonly>
                                </div>

                                <div class="form-group">
                                    <label>Contact Number (From Account Settings)</label>
                                    <input type="text" name="contact" value="<?php echo htmlspecialchars($user_contact); ?>" readonly>
                                </div>

                                <div class="form-group full">
                                    <label>Select Event</label>
                                    <select name="event" required>
                                        <option value="" disabled selected>Choose Event</option>
                                        <?php
                                        if ($events_query && mysqli_num_rows($events_query) > 0) {
                                            while ($row = mysqli_fetch_assoc($events_query)) {
                                                $title = htmlspecialchars($row['title']);
                                                echo "<option value='$title'>$title</option>";
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>

                                <div class="form-group full">
                                    <label>Notes (Optional)</label>
                                    <textarea name="notes"></textarea>
                                </div>
                            </div>

                            <button type="submit" class="btn">Submit Registration</button>
                        </form>
                    </div>
                </div>

                <aside>
                    <div class="panel">
                        <div class="event-banner">
                            <div class="event-badge">System Security</div>
                            <h2>Registration Lockdown</h2>
                        </div>

                        <div class="info-list">
                            <div class="info-item">
                                <div class="info-icon"><i class="fa-solid fa-lock"></i></div>
                                <div>
                                    <h4>Identity Protected</h4>
                                    <p>Your name and email are securely linked.</p>
                                </div>
                            </div>

                            <div class="info-item">
                                <div class="info-icon"><i class="fa-solid fa-id-card"></i></div>
                                <div>
                                    <h4>Student ID Locked</h4>
                                    <p>Your Student ID comes from Account Settings.</p>
                                </div>
                            </div>

                            <div class="info-item">
                                <div class="info-icon"><i class="fa-solid fa-shield-halved"></i></div>
                                <div>
                                    <h4>Duplicate Check</h4>
                                    <p>No other user can use your Student ID.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </main>
</div>


<script>
const searchToggle = document.getElementById('searchToggle');
const searchDropdown = document.getElementById('searchDropdown');
const notifToggle = document.getElementById('notifToggle');
const notifDropdown = document.getElementById('notifDropdown');
const notifBadge = document.getElementById('notifBadge');
const notifBody = document.getElementById('notifBody');

if (searchToggle) {
    searchToggle.addEventListener('click', function(e) {
        if (e.target.closest('.dropdown-menu')) return;
        searchDropdown.classList.toggle('active');
        if (notifDropdown) notifDropdown.classList.remove('active');
    });
}

if (notifToggle) {
    notifToggle.addEventListener('click', function(e) {
        if (e.target.closest('.dropdown-menu')) return;
        notifDropdown.classList.toggle('active');
        if (searchDropdown) searchDropdown.classList.remove('active');

        if (notifDropdown.classList.contains('active')) {
        fetch('mark_notifications_seen.php', { method: 'POST' })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        if (notifBadge) notifBadge.remove();
                    }
                })
                .catch(error => console.log(error));
        }
    });
}

document.addEventListener('click', function(e) {
    if (searchToggle && !searchToggle.contains(e.target)) searchDropdown.classList.remove('active');
    if (notifToggle && !notifToggle.contains(e.target)) notifDropdown.classList.remove('active');
});

function updateUserActivity() {
    fetch('update_user_status.php').catch(() => {});
}

updateUserActivity();
setInterval(updateUserActivity, 30000);
</script>


</body>
</html>
