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

// =========================================================
// XML GENERATION & DOWNLOAD LOGIC (Triggered by the button)
// =========================================================
if (isset($_GET['download']) && $_GET['download'] == 'true') {
    
    $xml = new DOMDocument('1.0', 'UTF-8');
    $xml->formatOutput = true;
    
    $root = $xml->createElement('EventManagementReport');
    $root->setAttribute('generatedAt', date('c'));
    $root->setAttribute('systemName', 'Smart Event Management System');
    $xml->appendChild($root);
    
    // Metadata
    $metadata = $xml->createElement('ReportMetadata');
    $metadata->appendChild($xml->createElement('GeneratedBy', htmlspecialchars($_SESSION['user_name'])));
    $metadata->appendChild($xml->createElement('Role', 'Admin'));
    $root->appendChild($metadata);
    
    // Summary
    $summary = $xml->createElement('Summary');
    $eventCount = 0; $regCount = 0;
    
    $table_check = mysqli_query($conn, "SHOW TABLES LIKE 'events'");
    if (mysqli_num_rows($table_check) > 0) {
        $eventCount = $conn->query("SELECT COUNT(*) as total FROM events")->fetch_assoc()['total'];
    }
    
    $reg_check = mysqli_query($conn, "SHOW TABLES LIKE 'registrations'");
    if (mysqli_num_rows($reg_check) > 0) {
        $regCount = $conn->query("SELECT COUNT(*) as total FROM registrations")->fetch_assoc()['total'];
    }
    
    $summary->appendChild($xml->createElement('TotalEvents', $eventCount));
    $summary->appendChild($xml->createElement('TotalRegistrations', $regCount));
    $root->appendChild($summary);
    
    // Events Data
    $eventsNode = $xml->createElement('Events');
    if (mysqli_num_rows($table_check) > 0) {
        $eventsQuery = $conn->query("SELECT * FROM events ORDER BY id DESC");
        while ($event = $eventsQuery->fetch_assoc()) {
            $eventNode = $xml->createElement('Event');
            $eventNode->setAttribute('id', $event['id']);
            $eventNode->appendChild($xml->createElement('Title', htmlspecialchars($event['title'] ?? '')));
            $eventNode->appendChild($xml->createElement('Venue', htmlspecialchars($event['venue'] ?? '')));
            $eventNode->appendChild($xml->createElement('Date', htmlspecialchars($event['start_date'] ?? '')));
            $eventsNode->appendChild($eventNode);
        }
    }
    $root->appendChild($eventsNode);
    
    // Registrations Data
    $regsNode = $xml->createElement('Registrations');
    if (mysqli_num_rows($reg_check) > 0) {
        $regQuery = $conn->query("SELECT * FROM registrations ORDER BY id DESC");
        while ($reg = $regQuery->fetch_assoc()) {
            $regNode = $xml->createElement('Registration');
            $regNode->setAttribute('id', $reg['id']);
            $regNode->appendChild($xml->createElement('StudentName', htmlspecialchars($reg['student_name'] ?? '')));
            $regNode->appendChild($xml->createElement('Course', htmlspecialchars($reg['course'] ?? '')));
            $regNode->appendChild($xml->createElement('EventTarget', htmlspecialchars($reg['event_name'] ?? '')));
            $regsNode->appendChild($regNode);
        }
    }
    $root->appendChild($regsNode);
    
    // Output the XML to Browser
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="system_report_' . date('Y-m-d') . '.xml"');
    echo $xml->saveXML();
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>XML Report - Smart Event Management</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background-color: #f8fafc; color: #1e293b; display: flex; min-height: 100vh; transition: background-color 0.3s, color 0.3s; }

        /* --- UNIFIED SIDEBAR CSS --- */
        .sidebar { width: 286px; background: #0b132b; color: #ffffff; position: fixed; inset: 0 auto 0 0; height: 100vh; padding: 32px 14px; z-index: 20; overflow-y: auto; display: flex; flex-direction: column;}
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

        /* Main Content Layout */
        .wrapper-main { flex: 1; margin-left: 286px; display: flex; flex-direction: column; }
        .top-navbar { height: 70px; background: #ffffff; display: flex; align-items: center; justify-content: space-between; padding: 0 40px; border-bottom: 1px solid #e2e8f0; position: sticky; top: 0; z-index: 90; transition: background-color 0.3s, border-color 0.3s;}
        .nav-left { display: flex; align-items: center; gap: 15px; }
        .menu-btn { cursor: pointer; color: #64748b; font-size: 1.2rem; transition: color 0.3s; }
        .nav-right { display: flex; align-items: center; gap: 15px; color: #64748b; font-size: 1.25rem; }
        .nav-icon { cursor: pointer; position: relative; width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; transition: color 0.2s, background-color 0.2s; border: none; background: transparent; }
        .nav-icon:hover { color: #1e293b; background-color: rgba(0,0,0,0.03); }
        .nav-icon i { font-size: inherit; }
        .badge { position: absolute; top: -4px; right: -4px; background: #ef4444; color: white; font-size: 0.65rem; font-weight: bold; min-width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid #ffffff; z-index: 2;}
        .mini-avatar { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; background: #3b82f6; color: white; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; font-weight: 700; text-transform: uppercase; margin-left: 10px; }

        /* Dropdown CSS */
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

        .main-content { padding: 40px; }
        .container { width: 100%; max-width: 900px; margin: 0 auto; }
        
        .page-header h1 { font-size: 2.2rem; font-weight: 800; color: #1e293b; margin-bottom: 5px; transition: color 0.3s; }
        .page-header p { color: #64748b; font-size: 1.05rem; margin-bottom: 40px; transition: color 0.3s; }

        /* XML Report Card */
        .report-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 20px; padding: 40px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.02); text-align: center; transition: background-color 0.3s, border-color 0.3s; }
        .report-icon { font-size: 4rem; color: #ea580c; background: #fff7ed; width: 100px; height: 100px; border-radius: 24px; display: flex; align-items: center; justify-content: center; margin: 0 auto 25px; transition: background-color 0.3s; }
        .report-card h2 { font-size: 1.8rem; font-weight: 800; color: #1e293b; margin-bottom: 10px; transition: color 0.3s; }
        .report-card p { font-size: 1.05rem; color: #64748b; line-height: 1.6; margin-bottom: 30px; max-width: 600px; margin-left: auto; margin-right: auto; transition: color 0.3s; }
        
        .download-btn { display: inline-flex; align-items: center; gap: 10px; background: #2563eb; color: #ffffff; padding: 16px 32px; border-radius: 12px; font-size: 1.1rem; font-weight: 700; text-decoration: none; transition: 0.2s ease; border: none; cursor: pointer; box-shadow: 0 4px 10px rgba(37, 99, 235, 0.2); }
        .download-btn:hover { background: #1d4ed8; transform: translateY(-2px); }

        .meta-info { margin-top: 30px; border-top: 1px solid #e2e8f0; padding-top: 25px; display: flex; justify-content: center; gap: 40px; transition: border-color 0.3s; }
        .meta-item { display: flex; flex-direction: column; gap: 5px; }
        .meta-label { font-size: 0.85rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; transition: color 0.3s; }
        .meta-value { font-size: 1rem; font-weight: 600; color: #1e293b; transition: color 0.3s; }

        /* =========================================
           GLOBAL DARK MODE CSS 
           ========================================= */
        body.dark-theme { background-color: #0f172a; color: #f8fafc; }
        body.dark-theme .top-navbar { background-color: #1e293b; border-bottom: 1px solid #334155; }
        body.dark-theme .page-header h1 { color: #f8fafc; }
        body.dark-theme .page-header p { color: #94a3b8; }
        
        body.dark-theme .report-card { background-color: #1e293b; border-color: #334155; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
        body.dark-theme .report-card h2 { color: #f8fafc; }
        body.dark-theme .report-card p { color: #94a3b8; }
        
        body.dark-theme .report-icon { background-color: #0f172a; color: #f97316; }
        body.dark-theme .meta-info { border-top-color: #334155; }
        body.dark-theme .meta-label { color: #64748b; }
        body.dark-theme .meta-value { color: #e2e8f0; }
        
        body.dark-theme .menu-btn, 
        body.dark-theme .nav-icon { color: #f8fafc; border-color: #475569;}
        
        /* Dropdown Dark Mode overrides */
        body.dark-theme .dropdown-menu { background-color: #1e293b; border: 1px solid #334155; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
        body.dark-theme .dropdown-header { background-color: #0f172a; color: #f8fafc; border-bottom: 1px solid #334155; }
        body.dark-theme .notif-item { border-bottom: 1px solid #334155; }
        body.dark-theme .notif-item:hover { background-color: #334155; }
        body.dark-theme .notif-title { color: #f8fafc; }
        body.dark-theme .search-container input { background: #0f172a; border-color: #475569; color: #f8fafc; }
        body.dark-theme .search-container input:focus { border-color: #3b82f6; }
    </style>
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
            <a href="home.php"><i class="fa-solid fa-house menu-icon"></i> <span>Index Portal</span></a>
            <a href="create_event.php"><i class="fa-solid fa-calendar-plus menu-icon"></i> <span>Create Event</span></a>
            <a href="register_event.php"><i class="fa-solid fa-user-check menu-icon"></i> <span>Register for Event</span></a>
            <a href="dashboard.php"><i class="fa-solid fa-chart-line menu-icon"></i> <span>Full Dashboard</span></a>
            <a href="analytics.php"><i class="fa-solid fa-chart-bar menu-icon"></i> <span>Analytics</span></a>
            <a href="xml_report.php" class="active"><i class="fa-solid fa-file-code menu-icon"></i> <span>XML Report</span></a>
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
                <div class="page-header">
                    <h1>XML Data Export</h1>
                    <p>Generate standardized XML backups for system integration and reporting.</p>
                </div>

                <div class="report-card">
                    <div class="report-icon">
                        <i class="fa-solid fa-file-export"></i>
                    </div>
                    <h2>System Data Backup</h2>
                    <p>Clicking the button below will generate a real-time XML file containing all current events, active registrations, and user metadata stored in your database.</p>
                    
                    <a href="xml_report.php?download=true" class="download-btn">
                        <i class="fa-solid fa-download"></i> Generate & Download XML
                    </a>

                    <div class="meta-info">
                        <div class="meta-item">
                            <span class="meta-label">Format</span>
                            <span class="meta-value">XML 1.0 (UTF-8)</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Generated By</span>
                            <span class="meta-value"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?></span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Date</span>
                            <span class="meta-value"><?php echo date('M d, Y'); ?></span>
                        </div>
                    </div>
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
