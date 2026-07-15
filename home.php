<?php
session_start();
include 'database.php';

// Secure Gate
if (!isset($_SESSION['user_name'])) {
    header("Location: index.php");
    exit();
}

$current_user = mysqli_real_escape_string($conn, $_SESSION['user_name']);

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

// 1. TOTAL EVENTS
$event_count = 0;
$event_query = "SHOW TABLES LIKE 'events'";
$table_exists = mysqli_query($conn, $event_query);
$has_events_table = (mysqli_num_rows($table_exists) > 0);

if ($has_events_table) {
    $sql_events = "SELECT COUNT(*) AS total_events FROM events";
    $res_events = mysqli_query($conn, $sql_events);
    if ($res_events) {
        $row_events = mysqli_fetch_assoc($res_events);
        $event_count = $row_events['total_events'];
    }
}

// 2. REGISTRATIONS
$registration_count = 0;
if ($reg_table_check && mysqli_num_rows($reg_table_check) > 0) {
    $sql_regs = "SELECT COUNT(*) AS total_regs FROM registrations";
    $res_regs = mysqli_query($conn, $sql_regs);
    if ($res_regs) {
        $row_regs = mysqli_fetch_assoc($res_regs);
        $registration_count = $row_regs['total_regs'];
    }
}

// 3. USER COUNT
$user_count = 0;
$user_table_check = mysqli_query($conn, "SHOW TABLES LIKE 'users'");
if (mysqli_num_rows($user_table_check) > 0) {
    $res_users = mysqli_query($conn, "SELECT COUNT(*) AS total_users FROM users");
    if ($res_users) {
        $row_users = mysqli_fetch_assoc($res_users);
        $user_count = $row_users['total_users'];
    }
}

// 4. FETCH UPCOMING EVENTS 
$upcoming_events = [];
if ($has_events_table) {
    $sql_fetch_events = "SELECT * FROM events ORDER BY event_date ASC LIMIT 5";
    $res_fetch_events = mysqli_query($conn, $sql_fetch_events);
    if ($res_fetch_events) {
        while ($row = mysqli_fetch_assoc($res_fetch_events)) {
            $upcoming_events[] = $row;
        }
    }
}

// 5. SEARCH ENGINE LOGIC 
$search_results = [];
$search_query = "";
if (isset($_GET['query']) && !empty(trim($_GET['query']))) {
    $search_query = mysqli_real_escape_string($conn, trim($_GET['query']));

    if ($has_events_table) {
        $event_search = mysqli_query($conn, "SELECT 'Event' as type, title as primary_text, venue as secondary_text FROM events WHERE title LIKE '%$search_query%' OR venue LIKE '%$search_query%' OR organizer LIKE '%$search_query%'");
        if ($event_search) {
            while($row = mysqli_fetch_assoc($event_search)) { $search_results[] = $row; }
        }
    }
    if ($reg_table_check && mysqli_num_rows($reg_table_check) > 0) {
        $reg_search = mysqli_query($conn, "SELECT 'Student' as type, student_name as primary_text, event_name as secondary_text FROM registrations WHERE student_name LIKE '%$search_query%' OR student_id LIKE '%$search_query%'");
        if ($reg_search) {
            while($row = mysqli_fetch_assoc($reg_search)) { $search_results[] = $row; }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Event Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background-color: #f4f7fe; color: #1e293b; display: flex; min-height: 100vh; transition: background-color 0.3s; }

        /* --- UNIFIED SIDEBAR CSS --- */
        .sidebar { width: 286px; background: #0b132b; color: #ffffff; position: fixed; inset: 0 auto 0 0; height: 100vh; padding: 32px 14px; z-index: 100; overflow-y: auto; display: flex; flex-direction: column;}
        .sidebar::-webkit-scrollbar { width: 4px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 10px; }

        .profile { text-align: center; margin-bottom: 24px; }
        .avatar { width: 70px; height: 70px; border-radius: 50%; margin: 0 auto 12px; background: #3b82f6; color: #ffffff; display: flex; align-items: center; justify-content: center; font-size: 28px; font-weight: 900; box-shadow: 0 10px 20px rgba(59, 130, 246, 0.28); text-transform: uppercase; }
        .profile h3 { margin: 0; font-size: 20px; font-weight: 800; color: #ffffff; }
        .profile p { margin: 5px 0 0; font-size: 13px; color: #8297bb; font-weight: 500; }
        
        .status-indicator { display: inline-flex; align-items: center; gap: 6px; margin-top: 10px; font-size: 0.75rem; font-weight: 700; padding: 4px 12px; border-radius: 20px; border: 1px solid transparent; transition: all 0.3s ease; }
        .status-indicator.online { color: #22c55e; background: rgba(34, 197, 94, 0.1); border-color: rgba(34, 197, 94, 0.2); }
        .status-indicator.offline { color: #ef4444; background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.2); }
        .status-indicator.online i { animation: pulse 2s infinite; }
        .status-indicator.offline i { animation: none; }
        @keyframes pulse { 0% { opacity: 1; transform: scale(1); } 50% { opacity: 0.7; transform: scale(0.8); } 100% { opacity: 1; transform: scale(1); } }

        .menu { display: flex; flex-direction: column; gap: 6px; padding-bottom: 20px; flex: 1; }
        .menu a { text-decoration: none; color: #a9bddc; height: 46px; padding: 0 20px; border-radius: 12px; display: flex; align-items: center; gap: 16px; font-size: 15px; font-weight: 700; transition: 0.2s ease; }
        .menu a:hover { color: #ffffff; background: rgba(255,255,255,0.07); }
        .menu a.active { background: #2563eb; color: #ffffff; }
        .menu-icon { width: 22px; text-align: center; font-size: 18px; display: inline-flex; align-items: center; justify-content: center; }
        
        .logout-link { margin-top: auto; border-top: 1px solid rgba(255,255,255,0.05); padding-top: 15px !important; color: #ef4444 !important; }
        .logout-link:hover { background: rgba(239, 68, 68, 0.1) !important; color: #f87171 !important; }

        .wrapper-main { flex: 1; margin-left: 286px; display: flex; flex-direction: column; }
        
        .top-navbar { height: 70px; background: #ffffff; display: flex; align-items: center; justify-content: space-between; padding: 0 40px; border-bottom: 1px solid #e2e8f0; position: sticky; top: 0; z-index: 90; transition: background-color 0.3s;}
        .nav-left { display: flex; align-items: center; gap: 15px; }
        .menu-btn { width: 36px; height: 36px; border: 1px solid #e2e8f0; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #64748b; font-size: 1.2rem; transition: 0.3s; }
        
        .nav-right { display: flex; align-items: center; gap: 15px; color: #64748b; font-size: 1.25rem; }
        .nav-icon { cursor: pointer; position: relative; width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; transition: color 0.2s, background-color 0.2s; border: none; background: transparent; }
        .nav-icon:hover { color: #1e293b; background-color: rgba(0,0,0,0.03); }
        .nav-icon i { font-size: inherit; }
        .badge { position: absolute; top: -4px; right: -4px; background: #ef4444; color: white; font-size: 0.65rem; font-weight: bold; min-width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid #ffffff; z-index: 2; }
        .mini-avatar { width: 40px; height: 40px; border-radius: 50%; background: #3b82f6; color: white; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; font-weight: 700; text-transform: uppercase; margin-left: 10px; }

        .dropdown-menu { display: none; position: absolute; top: 50px; right: 0; background: #ffffff; width: 320px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; z-index: 1000; cursor: default; overflow: hidden; text-align: left; transition: 0.3s;}
        .dropdown-menu.active { display: block; animation: fadeIn 0.2s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        
        .dropdown-header { padding: 15px 20px; border-bottom: 1px solid #e2e8f0; font-weight: 800; color: #1e293b; font-size: 0.95rem; background: #f8fafc; }
        .dropdown-body { max-height: 350px; overflow-y: auto; padding: 10px 0; }
        
        .notif-item { padding: 12px 20px; border-bottom: 1px solid #f1f5f9; display: flex; flex-direction: column; gap: 4px; transition: background 0.2s; }
        .notif-item:hover { background: #f8fafc; }
        .notif-item:last-child { border-bottom: none; }
        .notif-title { font-size: 0.85rem; font-weight: 700; color: #1e293b; }
        .notif-desc { font-size: 0.75rem; color: #64748b; }
        .notif-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 0.65rem; font-weight: 700; margin-right: 6px; }
        .badge-reg { background: #dcfce7; color: #166534; }
        .badge-event { background: #e0e7ff; color: #3730a3; }

        .search-container { padding: 15px; display: flex; gap: 10px; }
        .search-container input { flex: 1; padding: 10px 15px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem; outline: none; transition: 0.3s;}
        .search-container input:focus { border-color: #3b82f6; }
        .search-btn { background: #2563eb; color: white; border: none; padding: 0 15px; border-radius: 8px; font-weight: 700; cursor: pointer; }
        .search-btn:hover { background: #1d4ed8; }

        .main-content { padding: 40px 50px; flex: 1;}
        .container { width: 100%; max-width: 1200px; margin: 0 auto; }

        .metrics-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
        .metric-card { background: #ffffff; border-radius: 16px; padding: 25px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.01); border: 1px solid #e2e8f0; transition: 0.3s;}
        .metric-label { font-size: 0.85rem; font-weight: 700; color: #64748b; margin-bottom: 15px; }
        .metric-value { font-size: 2.2rem; font-weight: 800; color: #1e293b; }

        .action-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 25px; margin-bottom: 40px; }
        .action-card { background: #ffffff; border: 1px solid #e2e8f0; padding: 30px; border-radius: 20px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.01); display: flex; align-items: flex-start; gap: 24px; transition: all 0.3s ease; }
        .action-card:hover { transform: translateY(-4px); box-shadow: 0 15px 30px rgba(0, 0, 0, 0.05); }
        .action-card i { font-size: 2.5rem; flex-shrink: 0; padding: 15px; border-radius: 16px; }
        .action-card.blue i { background: #eff6ff; color: #2563eb; }
        .action-card.green i { background: #f0fdf4; color: #16a34a; }
        .action-card.purple i { background: #f3e8ff; color: #7c3aed; }
        .action-card.orange i { background: #fff7ed; color: #ea580c; }
        .card-text { display: flex; flex-direction: column; gap: 6px; }
        .action-card h3 { font-size: 1.15rem; font-weight: 700; color: #1e293b; }
        .action-card p { font-size: 0.9rem; color: #64748b; line-height: 1.5; margin-bottom: 8px; }
        .action-link { font-size: 0.85rem; font-weight: 700; text-transform: uppercase; color: #2563eb; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
        .action-card.green .action-link { color: #16a34a; }
        .action-card.purple .action-link { color: #7c3aed; }
        .action-card.orange .action-link { color: #ea580c; }

        .events-section { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 20px; padding: 30px; transition: 0.3s;}
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .section-header h2 { font-size: 1.35rem; font-weight: 800; color: #1e293b; }
        .view-all-btn { background: #2563eb; color: white; padding: 8px 18px; border-radius: 8px; font-size: 0.85rem; font-weight: 700; text-decoration: none; }
        .event-list { display: flex; flex-direction: column; gap: 15px; }
        .event-item { display: flex; align-items: center; justify-content: space-between; padding: 15px 0; border-bottom: 1px solid #f1f5f9; }
        .event-item:last-child { border-bottom: none; padding-bottom: 0; }
        .event-left { display: flex; align-items: center; gap: 20px; }
        .event-date-badge { background: #eff6ff; color: #2563eb; width: 55px; height: 55px; border-radius: 12px; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; }
        .date-day { font-size: 1.15rem; font-weight: 800; line-height: 1; }
        .date-month { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; margin-top: 2px; }
        .event-details h4 { font-size: 1.05rem; font-weight: 700; color: #1e293b; margin-bottom: 4px; }
        .event-details p { font-size: 0.85rem; color: #64748b; }
        .event-tag { padding: 6px 14px; border-radius: 20px; font-size: 0.8rem; font-weight: 700; }
        .tag-conference { background: #f3e8ff; color: #7c3aed; }
        .tag-workshop { background: #dcfce7; color: #16a34a; }
        .tag-general { background: #fee2e2; color: #ef4444; }
        .no-events { color: #64748b; font-size: 0.95rem; text-align: center; padding: 20px 0; font-style: italic; }

        /* =========================================
           GLOBAL DARK MODE THEME 
           ========================================= */
        body.dark-theme { background-color: #0f172a; color: #f8fafc; }
        body.dark-theme .top-navbar { background-color: #1e293b; border-bottom: 1px solid #334155; }
        body.dark-theme h1, body.dark-theme h2, body.dark-theme h3, body.dark-theme .metric-value, body.dark-theme .action-card h3, body.dark-theme .event-details h4 { color: #f8fafc !important; }
        body.dark-theme p, body.dark-theme .metric-label, body.dark-theme .action-card p { color: #94a3b8 !important; }
        body.dark-theme .panel, body.dark-theme .metric-card, body.dark-theme .action-card, body.dark-theme .events-section, body.dark-theme .dropdown-menu { background-color: #1e293b; border: 1px solid #334155; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
        body.dark-theme .dropdown-header { background-color: #0f172a; color: #f8fafc; border-bottom: 1px solid #334155; }
        body.dark-theme .notif-item { border-bottom: 1px solid #334155; }
        body.dark-theme .notif-item:hover { background-color: #334155; }
        body.dark-theme .notif-title { color: #f8fafc; }
        body.dark-theme .event-item { border-bottom: 1px solid #334155; }
        body.dark-theme .menu-btn, body.dark-theme .nav-icon { border-color: #475569; color: #f8fafc; }
        body.dark-theme .search-container input { background: #0f172a; border-color: #475569; color: #f8fafc; }
        body.dark-theme .search-container input:focus { border-color: #3b82f6; }
    </style>
    <link rel="stylesheet" href="smart_event_clean_theme.css?v=20260630">
</head>

<body class="<?php echo (isset($_SESSION['dark_mode']) && $_SESSION['dark_mode'] == 1) ? 'dark-theme' : ''; ?>">

    <aside class="sidebar">
        <div class="profile">
            <div class="avatar"><?php echo substr(htmlspecialchars($_SESSION['user_name'] ?? 'R'), 0, 1); ?></div>
            <h3><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?></h3>
            <p>BSIT-2026 / Admin</p>
            <div class="status-indicator online" id="networkStatus"><i class="fa-solid fa-circle"></i> <span id="networkText">Online</span></div>
        </div>

        <nav class="menu">
            <a href="home.php" class="active"><i class="fa-solid fa-house menu-icon"></i> <span>Index Portal</span></a>
            <a href="create_event.php"><i class="fa-solid fa-calendar-plus menu-icon"></i> <span>Create Event</span></a>
            <a href="register_event.php"><i class="fa-solid fa-user-check menu-icon"></i> <span>Register for Event</span></a>
            <a href="dashboard.php"><i class="fa-solid fa-chart-line menu-icon"></i> <span>Full Dashboard</span></a>
            <a href="analytics.php"><i class="fa-solid fa-chart-bar menu-icon"></i> <span>Analytics</span></a>
            <a href="xml_report.php"><i class="fa-solid fa-file-code menu-icon"></i> <span>XML Report</span></a> 
            <a href="settings.php"><i class="fa-solid fa-gear menu-icon"></i> <span>Settings</span></a> 
            <a href="help.php"><i class="fa-solid fa-circle-question menu-icon"></i> <span>Help & Support</span></a>
            <a href="logout.php" class="logout-link"><i class="fa-solid fa-arrow-right-from-bracket menu-icon"></i> <span>Logout</span></a>
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
                    <?php if($notif_count > 0 && $show_notifications): ?>
                        <div class="badge" id="notifBadge"><?php echo $notif_count; ?></div>
                    <?php endif; ?>
                    
                    <div class="dropdown-menu" id="notifDropdown">
                        <div class="dropdown-header">Recent Activity</div>
                        <div class="dropdown-body">
                            
                            <?php if(!empty($recent_registrations)): ?>
                                <?php foreach($recent_registrations as $reg): ?>
                                    <div class="notif-item">
                                        <?php if (($reg['notif_type'] ?? '') === 'event'): ?>
                                            <div class="notif-title"><span class="notif-badge badge-event">New Event</span> <?php echo htmlspecialchars($reg['event_name']); ?></div>
                                            <div class="notif-desc">Created by: <?php echo htmlspecialchars($reg['student_name']); ?></div>
                                        <?php else: ?>
                                            <div class="notif-title"><span class="notif-badge badge-reg">New Reg</span> <?php echo htmlspecialchars($reg['student_name']); ?></div>
                                            <div class="notif-desc">Registered for: <?php echo htmlspecialchars($reg['event_name']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <?php if(empty($recent_registrations)): ?>
                                <div class="notif-item"><div class="notif-desc" style="text-align: center;">No new notifications.</div></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <a href="settings.php" class="nav-icon" title="Settings"><i class="fa-solid fa-gear"></i></a>
                <div class="mini-avatar">
                    <?php echo substr(htmlspecialchars($_SESSION['user_name'] ?? 'RY'), 0, 2); ?>
                </div>
            </div>
        </header>

        <main class="main-content">
            <div class="container">
                
                <h1 style="font-size: 2.2rem; font-weight: 800; margin-bottom: 5px; color: #1e293b;">Smart Event Management System</h1>
                <p style="color: #64748b; margin-bottom: 40px; font-size: 1.05rem; font-weight: 500;">
                    Welcome <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?>! Select a module to begin.
                </p>

                <?php if (isset($_SESSION['flash_msg'])): ?>
                    <div style="background: #dcfce7; border: 1px solid #86efac; color: #166534; padding: 16px 20px; border-radius: 12px; margin-bottom: 25px; font-weight: 600; display: flex; align-items: center; gap: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.02);">
                        <i class="fa-solid fa-circle-check" style="font-size: 1.2rem;"></i>
                        <?php 
                            echo htmlspecialchars($_SESSION['flash_msg']); 
                            unset($_SESSION['flash_msg']); 
                        ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($search_query)): ?>
                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 25px; margin-bottom: 30px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.02);">
                        <h3 style="font-size: 1.2rem; font-weight: 800; color: #1e293b; margin-bottom: 15px;">
                            <i class="fa-solid fa-magnifying-glass" style="color: #2563eb; margin-right: 8px;"></i> 
                            Search Results for "<?php echo htmlspecialchars($search_query); ?>"
                        </h3>
                        
                        <?php if (empty($search_results)): ?>
                            <p style="color: #64748b; font-style: italic;">No events or students found matching your search.</p>
                        <?php else: ?>
                            <div style="display: flex; flex-direction: column; gap: 10px;">
                                <?php foreach($search_results as $result): ?>
                                    <div style="padding: 12px 18px; background: #f8fafc; border-radius: 8px; border-left: 4px solid <?php echo ($result['type'] == 'Event') ? '#8b5cf6' : '#10b981'; ?>;">
                                        <div style="font-weight: 700; color: #1e293b; font-size: 0.95rem;">
                                            <span style="font-size: 0.7rem; text-transform: uppercase; background: #e2e8f0; padding: 2px 8px; border-radius: 4px; margin-right: 8px; color: #475569;"><?php echo $result['type']; ?></span>
                                            <?php echo htmlspecialchars($result['primary_text']); ?>
                                        </div>
                                        <div style="color: #64748b; font-size: 0.85rem; margin-top: 4px;">
                                            <?php echo htmlspecialchars($result['secondary_text']); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <a href="home.php" style="display: inline-block; margin-top: 15px; font-size: 0.85rem; font-weight: 700; color: #ef4444; text-decoration: none;">Clear Search <i class="fa-solid fa-xmark"></i></a>
                    </div>
                <?php endif; ?>

                <div class="metrics-row">
                    <div class="metric-card">
                        <div class="metric-label">Total Events</div>
                        <div class="metric-value"><?php echo $event_count; ?></div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-label">Registrations</div>
                        <div class="metric-value"><?php echo $registration_count; ?></div>
                    </div>
                    <div class="metric-card">
                        <div class="metric-label">Users</div> 
                        <div class="metric-value"><?php echo $user_count; ?></div>
                    </div>
                </div>

                <div class="action-grid">
                    <div class="action-card blue">
                        <i class="fa-solid fa-calendar-plus"></i>
                        <div class="card-text">
                            <h3>CREATE NEW EVENT</h3>
                            <p>Configure new event details, venues, and team structure.</p>
                            <a href="create_event.php" class="action-link">Create Event <i class="fa-solid fa-chevron-right" style="font-size:0.75rem;"></i></a>
                        </div>
                    </div>

                    <div class="action-card green">
                        <i class="fa-solid fa-user-plus"></i>
                        <div class="card-text">
                            <h3>HANDLE REGISTRATIONS</h3>
                            <p>Process attendee sign-ups and manage verification pipelines.</p>
                            <a href="register_event.php" class="action-link">Register for Event <i class="fa-solid fa-chevron-right" style="font-size:0.75rem;"></i></a>
                        </div>
                    </div>

                    <div class="action-card purple">
                        <i class="fa-solid fa-chart-line"></i>
                        <div class="card-text">
                            <h3>VIEW FULL DASHBOARD</h3>
                            <p>Analyze core analytics metrics and recent engine activities.</p>
                            <a href="dashboard.php" class="action-link">Admin Dashboard <i class="fa-solid fa-chevron-right" style="font-size:0.75rem;"></i></a>
                        </div>
                    </div>

                    <div class="action-card orange">
                        <i class="fa-solid fa-file-export"></i>
                        <div class="card-text">
                            <h3>VIEW XML REPORT</h3>
                            <p>Export gathered operational logs to standardized XML format schemas.</p>
                            <a href="xml_report.php" class="action-link">View Report <i class="fa-solid fa-chevron-right" style="font-size:0.75rem;"></i></a>
                        </div>
                    </div>
                </div>

                <section class="events-section">
                    <div class="section-header">
                        <h2>Upcoming Events</h2>
                        <a href="dashboard.php" class="view-all-btn">View All</a>
                    </div>

                    <div class="event-list">
                        <?php if (empty($upcoming_events)): ?>
                            <div class="no-events">No upcoming events found. Create an event to get started!</div>
                        <?php else: ?>
                            <?php foreach ($upcoming_events as $event): 
                                $timestamp = strtotime($event['event_date']);
                                $day = date('d', $timestamp);
                                $month = date('M', $timestamp);
                                
                                $current_slots = isset($event['registered_count']) ? $event['registered_count'] : 0;
                                $max_slots = isset($event['max_attendees']) ? $event['max_attendees'] : 'unlimited';
                                
                                $event_type = isset($event['event_type']) ? strtolower($event['event_type']) : 'general';
                                $tag_class = 'tag-general';
                                if ($event_type == 'conference') $tag_class = 'tag-conference';
                                if ($event_type == 'workshop') $tag_class = 'tag-workshop';
                            ?>
                                <div class="event-item">
                                    <div class="event-left">
                                        <div class="event-date-badge">
                                            <span class="date-day"><?php echo $day; ?></span>
                                            <span class="date-month"><?php echo $month; ?></span>
                                        </div>
                                        <div class="event-details">
                                            <h4><?php echo htmlspecialchars($event['title']); ?></h4>
                                            <p><?php echo htmlspecialchars($event['venue']); ?> · <?php echo $current_slots; ?> / <?php echo $max_slots; ?> registered</p>
                                        </div>
                                    </div>
                                    <span class="event-tag <?php echo $tag_class; ?>"><?php echo ucfirst($event_type); ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

            </div>
        </main>
    </div>

    <script>
        // Notification & Dropdown Logic
        const searchToggle = document.getElementById('searchToggle');
        const searchDropdown = document.getElementById('searchDropdown');
        const notifToggle = document.getElementById('notifToggle');
        const notifDropdown = document.getElementById('notifDropdown');
        const notifBadge = document.getElementById('notifBadge');

        searchToggle.addEventListener('click', function(e) {
            if(e.target.closest('.dropdown-menu')) return; 
            searchDropdown.classList.toggle('active');
            notifDropdown.classList.remove('active'); 
        });

        notifToggle.addEventListener('click', function(e) {
            if(e.target.closest('.dropdown-menu')) return;
            notifDropdown.classList.toggle('active');
            searchDropdown.classList.remove('active'); 
            
            // IF DROPDOWN OPENS, MARK AS READ
            if(notifDropdown.classList.contains('active')) {
                fetch('mark_notifications_seen.php', { method: 'POST' })
                    .then(response => response.json())
                    .then(data => {
                        if(data.status === 'success' && notifBadge) {
                            notifBadge.style.display = 'none'; // Hide the red dot
                        }
                    });
            }
        });

        document.addEventListener('click', function(e) {
            if (!searchToggle.contains(e.target)) {
                searchDropdown.classList.remove('active');
            }
            if (!notifToggle.contains(e.target)) {
                notifDropdown.classList.remove('active');
            }
        });

        // Network Status Logic
        const networkStatus = document.getElementById('networkStatus');
        const networkText = document.getElementById('networkText');

        function updateNetworkStatus() {
            if (navigator.onLine) {
                networkStatus.classList.remove('offline');
                networkStatus.classList.add('online');
                networkText.textContent = 'Online';
            } else {
                networkStatus.classList.remove('online');
                networkStatus.classList.add('offline');
                networkText.textContent = 'Offline';
            }
        }

        window.addEventListener('online', updateNetworkStatus);
        window.addEventListener('offline', updateNetworkStatus);
        updateNetworkStatus();
    </script>
</body>
</html>
