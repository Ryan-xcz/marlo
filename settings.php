<?php
session_start();
include 'database.php';

if (!isset($_SESSION['user_name'])) {
    header("Location: login.php");
    exit();
}

$current_user = $_SESSION['user_name'];
$message = "";
$message_type = "";

/* =========================================================
   SAFE DATABASE COLUMN CHECKER
   ========================================================= */
function ensure_column($conn, $table, $column, $definition) {
    $table_safe = mysqli_real_escape_string($conn, $table);
    $column_safe = mysqli_real_escape_string($conn, $column);

    $check = mysqli_query($conn, "SHOW COLUMNS FROM `$table_safe` LIKE '$column_safe'");
    if ($check && mysqli_num_rows($check) == 0) {
        mysqli_query($conn, "ALTER TABLE `$table_safe` ADD COLUMN `$column_safe` $definition");
    }
}

/* =========================================================
   REQUIRED COLUMNS
   ========================================================= */
ensure_column($conn, "users", "student_id", "VARCHAR(50) NULL");
ensure_column($conn, "users", "course", "VARCHAR(100) NULL");
ensure_column($conn, "users", "year_level", "VARCHAR(50) NULL");
ensure_column($conn, "users", "contact_number", "VARCHAR(30) NULL");
ensure_column($conn, "users", "last_activity", "DATETIME NULL");
ensure_column($conn, "users", "is_online", "TINYINT(1) NOT NULL DEFAULT 0");
ensure_column($conn, "users", "notification_seen_at", "DATETIME NULL");
ensure_column($conn, "users", "email_notif", "TINYINT(1) NOT NULL DEFAULT 1");
ensure_column($conn, "users", "event_reminders", "TINYINT(1) NOT NULL DEFAULT 1");
ensure_column($conn, "users", "dark_mode", "TINYINT(1) NOT NULL DEFAULT 0");

/* =========================================================
   UPDATE ONLINE STATUS
   ========================================================= */
$stmt_online = mysqli_prepare($conn, "UPDATE users SET is_online = 1, last_activity = NOW() WHERE fullname = ?");
mysqli_stmt_bind_param($stmt_online, "s", $current_user);
mysqli_stmt_execute($stmt_online);
mysqli_stmt_close($stmt_online);

/* =========================================================
   FETCH CURRENT USER
   ========================================================= */
$stmt = mysqli_prepare($conn, "
    SELECT 
        fullname,
        email,
        password,
        student_id,
        course,
        year_level,
        contact_number,
        email_notif,
        event_reminders,
        dark_mode,
        notification_seen_at
    FROM users 
    WHERE fullname = ?
    LIMIT 1
");
mysqli_stmt_bind_param($stmt, "s", $current_user);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$show_notifications = (!isset($user['email_notif']) || $user['email_notif'] == 1);

/* =========================================================
   SAVE SETTINGS FORMS
   ========================================================= */
$active_tab = "profile";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $form_type = $_POST['form_type'] ?? 'profile';

    /* -------------------------
       PROFILE INFORMATION
       ------------------------- */
    if ($form_type === "profile") {

        $active_tab = "profile";

        $student_id = trim($_POST['student_id'] ?? '');
        $course = trim($_POST['course'] ?? '');
        $year_level = trim($_POST['year_level'] ?? '');
        $contact_number = trim($_POST['contact_number'] ?? '');

        if ($student_id === "") {
            $message = "Student ID is required.";
            $message_type = "error";
        } elseif ($course === "") {
            $message = "Course is required.";
            $message_type = "error";
        } elseif ($year_level === "") {
            $message = "Year Level is required.";
            $message_type = "error";
        } else {

            $check = mysqli_prepare($conn, "
                SELECT id 
                FROM users 
                WHERE student_id = ? 
                AND fullname != ?
                LIMIT 1
            ");
            mysqli_stmt_bind_param($check, "ss", $student_id, $current_user);
            mysqli_stmt_execute($check);
            $check_result = mysqli_stmt_get_result($check);

            if ($check_result && mysqli_num_rows($check_result) > 0) {
                $message = "This Student ID is already used by another account.";
                $message_type = "error";
            } else {
                $update = mysqli_prepare($conn, "
                    UPDATE users 
                    SET student_id = ?, course = ?, year_level = ?, contact_number = ?
                    WHERE fullname = ?
                ");
                mysqli_stmt_bind_param($update, "sssss", $student_id, $course, $year_level, $contact_number, $current_user);

                if (mysqli_stmt_execute($update)) {
                    $message = "Profile information saved successfully.";
                    $message_type = "success";
                    $user['student_id'] = $student_id;
                    $user['course'] = $course;
                    $user['year_level'] = $year_level;
                    $user['contact_number'] = $contact_number;
                } else {
                    $message = "Failed to save profile information.";
                    $message_type = "error";
                }

                mysqli_stmt_close($update);
            }

            mysqli_stmt_close($check);
        }
    }

    /* -------------------------
       SECURITY PASSWORD
       ------------------------- */
    if ($form_type === "security") {

        $active_tab = "security";

        $current_password = trim($_POST['current_password'] ?? '');
        $new_password = trim($_POST['new_password'] ?? '');
        $confirm_password = trim($_POST['confirm_password'] ?? '');

        if ($current_password === "" || $new_password === "" || $confirm_password === "") {
            $message = "Please complete all password fields.";
            $message_type = "error";
        } elseif ($new_password !== $confirm_password) {
            $message = "New password and confirm password do not match.";
            $message_type = "error";
        } elseif (strlen($new_password) < 6) {
            $message = "New password must be at least 6 characters.";
            $message_type = "error";
        } else {

            $stored_password = $user['password'] ?? "";

            $password_ok = false;

            if (password_get_info($stored_password)['algo'] !== 0) {
                $password_ok = password_verify($current_password, $stored_password);
            } else {
                // fallback for old plain-text passwords in your database
                $password_ok = hash_equals($stored_password, $current_password);
            }

            if (!$password_ok) {
                $message = "Current password is incorrect.";
                $message_type = "error";
            } else {

                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                $update_pass = mysqli_prepare($conn, "
                    UPDATE users
                    SET password = ?
                    WHERE fullname = ?
                ");
                mysqli_stmt_bind_param($update_pass, "ss", $hashed_password, $current_user);

                if (mysqli_stmt_execute($update_pass)) {
                    $message = "Password changed successfully.";
                    $message_type = "success";
                    $user['password'] = $hashed_password;
                } else {
                    $message = "Failed to change password.";
                    $message_type = "error";
                }

                mysqli_stmt_close($update_pass);
            }
        }
    }

    /* -------------------------
       SYSTEM PREFERENCES
       ------------------------- */
    if ($form_type === "preferences") {

        $active_tab = "preferences";

        $email_notif = isset($_POST['email_notif']) ? 1 : 0;
        $event_reminders = isset($_POST['event_reminders']) ? 1 : 0;
        $dark_mode = isset($_POST['dark_mode']) ? 1 : 0;

        $update_pref = mysqli_prepare($conn, "
            UPDATE users
            SET email_notif = ?, event_reminders = ?, dark_mode = ?
            WHERE fullname = ?
        ");
        mysqli_stmt_bind_param($update_pref, "iiis", $email_notif, $event_reminders, $dark_mode, $current_user);

        if (mysqli_stmt_execute($update_pref)) {
            $message = "System preferences saved successfully.";
            $message_type = "success";
            $user['email_notif'] = $email_notif;
            $user['event_reminders'] = $event_reminders;
            $user['dark_mode'] = $dark_mode;
            $show_notifications = ($email_notif == 1);
        } else {
            $message = "Failed to save system preferences.";
            $message_type = "error";
        }

        mysqli_stmt_close($update_pref);
    }
}

/* =========================================================
   NOTIFICATION DATA FOR TOP NAV
   - Badge count only shows NEW registrations/events
   - Dropdown always shows recent activity
   ========================================================= */
$notif_count = 0;
$recent_registrations = [];

$reg_table_check = mysqli_query($conn, "SHOW TABLES LIKE 'registrations'");
if ($reg_table_check && mysqli_num_rows($reg_table_check) > 0) {
    ensure_column($conn, "registrations", "created_at", "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
}

$event_table_check = mysqli_query($conn, "SHOW TABLES LIKE 'events'");
if ($event_table_check && mysqli_num_rows($event_table_check) > 0) {
    ensure_column($conn, "events", "created_at", "DATETIME NULL");
    mysqli_query($conn, "UPDATE events SET created_at = COALESCE(created_at, start_date, event_date, NOW()) WHERE created_at IS NULL");
}

$notification_seen_at = $user['notification_seen_at'] ?? null;

if ($show_notifications) {

    $seen_condition = "";
    if (!empty($notification_seen_at)) {
        $safe_seen = mysqli_real_escape_string($conn, $notification_seen_at);
        $seen_condition = "WHERE created_at > '$safe_seen'";
    }

    if ($reg_table_check && mysqli_num_rows($reg_table_check) > 0) {
        $n_reg_query = mysqli_query($conn, "SELECT COUNT(*) AS total FROM registrations $seen_condition");
        if ($n_reg_query) {
            $notif_count += (int) mysqli_fetch_assoc($n_reg_query)['total'];
        }
    }

    if ($event_table_check && mysqli_num_rows($event_table_check) > 0) {
        $n_event_query = mysqli_query($conn, "SELECT COUNT(*) AS total FROM events $seen_condition");
        if ($n_event_query) {
            $notif_count += (int) mysqli_fetch_assoc($n_event_query)['total'];
        }
    }
}

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

if ($event_table_check && mysqli_num_rows($event_table_check) > 0) {
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

$initial = strtoupper(substr($user['fullname'] ?? $current_user, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Settings - Smart Event Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', Arial, sans-serif;
        }

        body {
            display: flex;
            background: #f4f7fe;
            color: #0f172a;
            min-height: 100vh;
        }

        body.dark {
            background: #0f172a;
            color: #e5e7eb;
        }

        .sidebar {
            width: 286px;
            background: #0b132b;
            color: white;
            height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
            padding: 32px 14px;
            display: flex;
            flex-direction: column;
        }

        .profile {
            text-align: center;
            margin-bottom: 30px;
        }

        .avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: #3b82f6;
            display: grid;
            place-items: center;
            margin: 0 auto 12px;
            font-size: 30px;
            font-weight: 900;
        }

        .profile h3 {
            font-size: 20px;
            font-weight: 800;
        }

        .profile p {
            color: #93a4c7;
            font-size: 13px;
            margin-top: 5px;
        }

        .status {
            margin-top: 10px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            background: rgba(34, 197, 94, 0.15);
            color: #22c55e;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        .menu {
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex: 1;
        }

        .menu a {
            color: #b8cae8;
            text-decoration: none;
            height: 46px;
            border-radius: 12px;
            padding: 0 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            font-weight: 700;
        }

        .menu a.active,
        .menu a:hover {
            background: #2563eb;
            color: white;
        }

        .logout-link {
            color: #ef4444 !important;
            margin-top: auto;
        }

        .main {
            margin-left: 286px;
            width: calc(100% - 286px);
        }

        .topbar {
            height: 70px;
            background: white;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 40px;
        }

        body.dark .topbar,
        body.dark .header-card,
        body.dark .settings-card,
        body.dark .tabs {
            background: #1e293b;
            color: #e5e7eb;
            border-color: #334155;
        }

        .content {
            padding: 45px 70px;
        }

        .header-card,
        .settings-card {
            background: white;
            border-radius: 14px;
            padding: 24px 28px;
            box-shadow: 0 6px 20px rgba(15, 23, 42, 0.05);
        }

        .header-card {
            margin-bottom: 28px;
        }

        .header-card h1 {
            font-size: 28px;
            font-weight: 900;
        }

        .header-card p {
            color: #64748b;
            margin-top: 5px;
        }

        .settings-layout {
            display: grid;
            grid-template-columns: 260px 1fr;
            gap: 30px;
        }

        .tabs {
            background: white;
            border-radius: 14px;
            padding: 14px 0;
            height: fit-content;
            box-shadow: 0 6px 20px rgba(15, 23, 42, 0.04);
        }

        .tab {
            padding: 16px 28px;
            color: #64748b;
            font-weight: 700;
            display: flex;
            gap: 12px;
            align-items: center;
            cursor: pointer;
            user-select: none;
        }

        .tab.active {
            background: #eff6ff;
            color: #2563eb;
            border-left: 4px solid #2563eb;
        }

        body.dark .tab.active {
            background: #172554;
            color: #93c5fd;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .settings-card h2 {
            font-size: 22px;
            font-weight: 900;
        }

        .settings-card p {
            color: #64748b;
            margin-top: 5px;
            margin-bottom: 18px;
        }

        .divider {
            height: 1px;
            background: #e2e8f0;
            margin-bottom: 24px;
        }

        body.dark .divider {
            background: #334155;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 800;
            font-size: 14px;
        }

        input, select {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            font-size: 16px;
            margin-bottom: 18px;
            background: white;
        }

        body.dark input,
        body.dark select {
            background: #0f172a;
            border-color: #334155;
            color: #e5e7eb;
        }

        input[readonly] {
            background: #e2e8f0;
            color: #64748b;
        }

        body.dark input[readonly] {
            background: #334155;
            color: #cbd5e1;
        }

        .row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 22px;
        }

        button {
            background: #2563eb;
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 8px;
            font-weight: 800;
            cursor: pointer;
        }

        button:hover {
            background: #1d4ed8;
        }

        .alert {
            padding: 14px 18px;
            border-radius: 8px;
            font-weight: 700;
            margin-bottom: 18px;
        }

        .alert.success {
            background: #dcfce7;
            color: #166534;
        }

        .alert.error {
            background: #fee2e2;
            color: #991b1b;
        }

        .pref-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 14px;
            display: flex;
            justify-content: space-between;
            gap: 15px;
            align-items: center;
        }

        body.dark .pref-card {
            border-color: #334155;
        }

        .pref-card h4 {
            font-size: 16px;
            margin-bottom: 4px;
        }

        .pref-card small {
            color: #64748b;
            font-weight: 600;
        }

        .switch input {
            width: 22px;
            height: 22px;
            margin: 0;
        }

        .nav-right {
            display:flex;
            align-items:center;
            gap:15px;
            color:#64748b;
            font-size:1.25rem;
        }

        .nav-icon {
            position:relative;
            width:36px;
            height:36px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            color:#64748b;
            text-decoration:none;
            cursor:pointer;
        }

        .badge {
            position:absolute;
            top:-4px;
            right:-4px;
            background:#ef4444;
            color:white;
            font-size:.65rem;
            min-width:18px;
            height:18px;
            border-radius:50%;
            display:flex;
            align-items:center;
            justify-content:center;
            font-weight:800;
        }

        .dropdown-menu {
            display:none;
            position:absolute;
            top:50px;
            right:0;
            background:#ffffff;
            width:320px;
            border-radius:12px;
            box-shadow:0 10px 25px rgba(0,0,0,.1);
            border:1px solid #e2e8f0;
            z-index:1000;
            overflow:hidden;
            text-align:left;
        }

        body.dark .dropdown-menu {
            background: #1e293b;
            border-color: #334155;
        }

        .dropdown-menu.active {
            display:block;
        }

        .dropdown-header {
            padding:15px 20px;
            border-bottom:1px solid #e2e8f0;
            font-weight:800;
            color:#1e293b;
            background:#f8fafc;
        }

        body.dark .dropdown-header {
            background: #0f172a;
            color: #e5e7eb;
            border-color: #334155;
        }

        .dropdown-body {
            max-height:350px;
            overflow-y:auto;
            padding:10px 0;
        }

        .notif-item {
            padding:12px 20px;
            border-bottom:1px solid #f1f5f9;
        }

        body.dark .notif-item {
            border-color: #334155;
        }

        .notif-title {
            font-size:.85rem;
            font-weight:700;
            color:#1e293b;
        }

        body.dark .notif-title {
            color:#e5e7eb;
        }

        .notif-desc {
            font-size:.75rem;
            color:#64748b;
            margin-top:4px;
        }

        .notif-badge {
            display:inline-block;
            padding:2px 8px;
            border-radius:4px;
            font-size:.65rem;
            font-weight:700;
            margin-right:6px;
        }

        .badge-reg {
            background:#dcfce7;
            color:#166534;
        }

        .badge-event {
            background:#e0e7ff;
            color:#3730a3;
        }

        @media (max-width: 900px) {
            .settings-layout {
                grid-template-columns: 1fr;
            }

            .row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body class="<?php echo ((int)($user['dark_mode'] ?? 0) === 1) ? 'dark' : ''; ?>">

<aside class="sidebar">
    <div class="profile">
        <div class="avatar"><?php echo htmlspecialchars($initial); ?></div>
        <h3><?php echo htmlspecialchars($user['fullname']); ?></h3>
        <p><?php echo htmlspecialchars(($user['course'] ?: 'BSIT') . '-2026 / Admin'); ?></p>
        <div class="status">
            <i class="fa-solid fa-circle"></i> Online
        </div>
    </div>

    <nav class="menu">
        <a href="home.php"><i class="fa-solid fa-house"></i> Index Portal</a>
        <a href="create_event.php"><i class="fa-solid fa-calendar-plus"></i> Create Event</a>
        <a href="register_event.php"><i class="fa-solid fa-user-check"></i> Register for Event</a>
        <a href="dashboard.php"><i class="fa-solid fa-chart-line"></i> Full Dashboard</a>
        <a href="analytics.php"><i class="fa-solid fa-chart-bar"></i> Analytics</a>
        <a href="xml_report.php"><i class="fa-solid fa-file-code"></i> XML Report</a>
        <a href="settings.php" class="active"><i class="fa-solid fa-gear"></i> Settings</a>
        <a href="help.php"><i class="fa-solid fa-circle-question"></i> Help & Support</a>
        <a href="logout.php" class="logout-link"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
    </nav>
</aside>

<div class="main">
    <header class="topbar">
        <i class="fa-solid fa-bars"></i>

        <div class="nav-right">
            <div class="nav-icon" id="notifToggle" title="Notifications">
                <i class="fa-regular fa-bell"></i>

                <?php if ($notif_count > 0 && $show_notifications): ?>
                    <div class="badge" id="notifBadge"><?php echo (int)$notif_count; ?></div>
                <?php endif; ?>

                <div class="dropdown-menu" id="notifDropdown">
                    <div class="dropdown-header">Recent Activity</div>
                    <div class="dropdown-body" id="notifBody">
                        <?php if (!empty($recent_registrations)): ?>
                            <?php foreach ($recent_registrations as $reg): ?>
                                <div class="notif-item">
                                    <?php if (($reg['notif_type'] ?? '') === 'event'): ?>
                                        <div class="notif-title">
                                            <span class="notif-badge badge-event">New Event</span>
                                            <?php echo htmlspecialchars($reg['event_name']); ?>
                                        </div>
                                        <div class="notif-desc">
                                            Created by: <?php echo htmlspecialchars($reg['student_name']); ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="notif-title">
                                            <span class="notif-badge badge-reg">New Reg</span>
                                            <?php echo htmlspecialchars($reg['student_name']); ?>
                                        </div>
                                        <div class="notif-desc">
                                            Registered for: <?php echo htmlspecialchars($reg['event_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="notif-item">
                                <div class="notif-desc" style="text-align:center;">No recent activity.</div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <span><?php echo htmlspecialchars($user['fullname']); ?></span>
        </div>
    </header>

    <main class="content">
        <div class="header-card">
            <h1>Account Settings</h1>
            <p>Manage your profile, personal information, and account security.</p>
        </div>

        <div class="settings-layout">
            <div class="tabs">
                <div class="tab <?php echo $active_tab === 'profile' ? 'active' : ''; ?>" data-tab="profile">
                    <i class="fa-regular fa-user"></i> My Profile
                </div>
                <div class="tab <?php echo $active_tab === 'security' ? 'active' : ''; ?>" data-tab="security">
                    <i class="fa-solid fa-shield-halved"></i> Security & Passwords
                </div>
                <div class="tab <?php echo $active_tab === 'preferences' ? 'active' : ''; ?>" data-tab="preferences">
                    <i class="fa-solid fa-sliders"></i> System Preferences
                </div>
            </div>

            <div class="settings-card">

                <?php if (!empty($message)): ?>
                    <div class="alert <?php echo htmlspecialchars($message_type); ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <!-- MY PROFILE TAB -->
                <section class="tab-content <?php echo $active_tab === 'profile' ? 'active' : ''; ?>" id="tab-profile">
                    <h2>Personal Information</h2>
                    <p>Set your personal details. Student ID cannot be used by another account.</p>
                    <div class="divider"></div>

                    <form method="POST">
                        <input type="hidden" name="form_type" value="profile">

                        <label>Full Name</label>
                        <input type="text" value="<?php echo htmlspecialchars($user['fullname']); ?>" readonly>

                        <label>Registered Email Address</label>
                        <input type="email" value="<?php echo htmlspecialchars($user['email']); ?>" readonly>

                        <div class="row">
                            <div>
                                <label>Student ID</label>
                                <input 
                                    type="text" 
                                    name="student_id" 
                                    placeholder="e.g. 2024-0811"
                                    value="<?php echo htmlspecialchars($user['student_id'] ?? ''); ?>"
                                    required
                                >
                            </div>

                            <div>
                                <label>Course</label>
                                <input 
                                    type="text" 
                                    name="course" 
                                    placeholder="e.g. BSIT"
                                    value="<?php echo htmlspecialchars($user['course'] ?? ''); ?>"
                                    required
                                >
                            </div>
                        </div>

                        <div class="row">
                            <div>
                                <label>Year Level</label>
                                <select name="year_level" required>
                                    <option value="">Select Year Level</option>
                                    <option value="1st Year" <?php if (($user['year_level'] ?? '') == '1st Year') echo 'selected'; ?>>1st Year</option>
                                    <option value="2nd Year" <?php if (($user['year_level'] ?? '') == '2nd Year') echo 'selected'; ?>>2nd Year</option>
                                    <option value="3rd Year" <?php if (($user['year_level'] ?? '') == '3rd Year') echo 'selected'; ?>>3rd Year</option>
                                    <option value="4th Year" <?php if (($user['year_level'] ?? '') == '4th Year') echo 'selected'; ?>>4th Year</option>
                                </select>
                            </div>

                            <div>
                                <label>Contact Number</label>
                                <input 
                                    type="text" 
                                    name="contact_number" 
                                    placeholder="e.g. 09123456789"
                                    value="<?php echo htmlspecialchars($user['contact_number'] ?? ''); ?>"
                                >
                            </div>
                        </div>

                        <button type="submit">Save Changes</button>
                    </form>
                </section>

                <!-- SECURITY TAB -->
                <section class="tab-content <?php echo $active_tab === 'security' ? 'active' : ''; ?>" id="tab-security">
                    <h2>Security & Passwords</h2>
                    <p>Change your account password securely.</p>
                    <div class="divider"></div>

                    <form method="POST">
                        <input type="hidden" name="form_type" value="security">

                        <label>Current Password</label>
                        <input type="password" name="current_password" placeholder="Enter current password" required>

                        <div class="row">
                            <div>
                                <label>New Password</label>
                                <input type="password" name="new_password" placeholder="Enter new password" required>
                            </div>

                            <div>
                                <label>Confirm New Password</label>
                                <input type="password" name="confirm_password" placeholder="Confirm new password" required>
                            </div>
                        </div>

                        <button type="submit">Update Password</button>
                    </form>
                </section>

                <!-- PREFERENCES TAB -->
                <section class="tab-content <?php echo $active_tab === 'preferences' ? 'active' : ''; ?>" id="tab-preferences">
                    <h2>System Preferences</h2>
                    <p>Control notifications, event reminders, and appearance.</p>
                    <div class="divider"></div>

                    <form method="POST">
                        <input type="hidden" name="form_type" value="preferences">

                        <div class="pref-card">
                            <div>
                                <h4>Email Notifications</h4>
                                <small>Allow the system to show notification updates.</small>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="email_notif" <?php echo ((int)($user['email_notif'] ?? 1) === 1) ? 'checked' : ''; ?>>
                            </label>
                        </div>

                        <div class="pref-card">
                            <div>
                                <h4>Event Reminders</h4>
                                <small>Enable reminders for event-related updates.</small>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="event_reminders" <?php echo ((int)($user['event_reminders'] ?? 1) === 1) ? 'checked' : ''; ?>>
                            </label>
                        </div>

                        <div class="pref-card">
                            <div>
                                <h4>Dark Mode</h4>
                                <small>Use a dark appearance for the settings page.</small>
                            </div>
                            <label class="switch">
                                <input type="checkbox" name="dark_mode" <?php echo ((int)($user['dark_mode'] ?? 0) === 1) ? 'checked' : ''; ?>>
                            </label>
                        </div>

                        <button type="submit">Save Preferences</button>
                    </form>
                </section>

            </div>
        </div>
    </main>
</div>

<script>
/* USER ONLINE ACTIVITY */
function updateUserActivity() {
    fetch('update_user_status.php').catch(() => {});
}
updateUserActivity();
setInterval(updateUserActivity, 30000);
</script>

<script>
/* SETTINGS TABS */
const tabs = document.querySelectorAll('.tab');
const contents = document.querySelectorAll('.tab-content');

tabs.forEach(tab => {
    tab.addEventListener('click', () => {
        const target = tab.getAttribute('data-tab');

        tabs.forEach(t => t.classList.remove('active'));
        contents.forEach(c => c.classList.remove('active'));

        tab.classList.add('active');

        const content = document.getElementById('tab-' + target);
        if (content) {
            content.classList.add('active');
        }
    });
});
</script>

<script>
/* NOTIFICATION DROPDOWN */
const notifToggle = document.getElementById('notifToggle');
const notifDropdown = document.getElementById('notifDropdown');
const notifBadge = document.getElementById('notifBadge');

if (notifToggle && notifDropdown) {
    notifToggle.addEventListener('click', function(e) {
        if (e.target.closest('.dropdown-menu')) return;

        notifDropdown.classList.toggle('active');

        if (notifDropdown.classList.contains('active')) {
            fetch('mark_notifications_seen.php', { method: 'POST' })
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success' && notifBadge) {
                        notifBadge.remove();
                    }
                })
                .catch(() => {});
        }
    });

    document.addEventListener('click', function(e) {
        if (!notifToggle.contains(e.target)) {
            notifDropdown.classList.remove('active');
        }
    });
}
</script>

</body>
</html>
