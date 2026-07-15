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

$message = "";

// Handle Form Submission
if (isset($_POST['submit_event'])) {
    $title = mysqli_real_escape_string($conn, $_POST['event_title']);
    $short_code = mysqli_real_escape_string($conn, $_POST['short_code']);
    $venue = mysqli_real_escape_string($conn, $_POST['venue']);
    $description = mysqli_real_escape_string($conn, $_POST['description']); 
    $organizer = mysqli_real_escape_string($conn, $_POST['organizer']);
    $contact_email = mysqli_real_escape_string($conn, $_POST['contact_email']);
    $event_type = mysqli_real_escape_string($conn, $_POST['event_type']);
    $max_attendees = intval($_POST['max_attendees']);
    $start_date = mysqli_real_escape_string($conn, $_POST['start_date']);
    $end_date = mysqli_real_escape_string($conn, $_POST['end_date']);
    $start_time = mysqli_real_escape_string($conn, $_POST['start_time']);
    $end_time = mysqli_real_escape_string($conn, $_POST['end_time']);
    $event_date = $start_date . ' ' . $start_time;

    $banner_name = "";
    if (isset($_FILES['event_banner']) && $_FILES['event_banner']['error'] == 0) {
        $target_dir = "uploads/";
        if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }
        $file_extension = pathinfo($_FILES["event_banner"]["name"], PATHINFO_EXTENSION);
        $banner_name = time() . '_' . uniqid() . '.' . $file_extension;
        $target_file = $target_dir . $banner_name;
        if (!move_uploaded_file($_FILES["event_banner"]["tmp_name"], $target_file)) { $banner_name = ""; }
    }

    $sql = "INSERT INTO events (title, short_code, description, venue, event_date, start_date, end_date, start_time, end_time, event_type, max_attendees, organizer, contact_email, banner, created_at) 
            VALUES ('$title', '$short_code', '$description', '$venue', '$event_date', '$start_date', '$end_date', '$start_time', '$end_time', '$event_type', $max_attendees, '$organizer', '$contact_email', '$banner_name', NOW())";

    if (mysqli_query($conn, $sql)) {
        $_SESSION['flash_msg'] = "Success! Event '" . htmlspecialchars($title) . "' has been published.";
        header("Location: home.php");
        exit();
    } else {
        $message = "<div class='alert error'><i class='fa-solid fa-circle-exclamation'></i> Error saving event: " . mysqli_error($conn) . "</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Event - Smart Event Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background-color: #f8fafc; color: #1e293b; display: flex; min-height: 100vh; transition: background-color 0.3s, color 0.3s; }

        .sidebar { width: 286px; background: #0b132b; color: #ffffff; position: fixed; inset: 0 auto 0 0; height: 100vh; padding: 32px 14px; z-index: 100; overflow-y: auto; display: flex; flex-direction: column; }
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

        .wrapper-main { flex: 1; margin-left: 286px; width: calc(100% - 286px); display: flex; flex-direction: column; }
        
        .top-navbar { height: 70px; background: #ffffff; display: flex; align-items: center; justify-content: space-between; padding: 0 40px; border-bottom: 1px solid #e2e8f0; position: sticky; top: 0; z-index: 90; transition: background-color 0.3s; }
        .nav-left { display: flex; align-items: center; gap: 15px; }
        .menu-btn { width: 36px; height: 36px; border: 1px solid #e2e8f0; border-radius: 8px; display: flex; align-items: center; justify-content: center; cursor: pointer; color: #475569; }
        
        .nav-right { display: flex; align-items: center; gap: 15px; color: #64748b; font-size: 1.25rem; }
        .nav-icon { cursor: pointer; position: relative; width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; transition: color 0.2s, background-color 0.2s; border: none; background: transparent; }
        .nav-icon:hover { color: #1e293b; background-color: rgba(0,0,0,0.03); }
        .nav-icon i { font-size: inherit; }
        .badge { position: absolute; top: -4px; right: -4px; background: #ef4444; color: white; font-size: 0.65rem; font-weight: bold; min-width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid #ffffff; z-index: 2;}
        .mini-avatar { width: 40px; height: 40px; border-radius: 50%; background: #3b82f6; color: white; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; font-weight: 700; text-transform: uppercase; margin-left: 10px; }

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
        .container { width: 100%; max-width: 1200px; margin: 0 auto; }
        
        .header-row { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 30px; }
        .page-header h1 { font-size: 1.8rem; font-weight: 800; color: #0f172a; margin-bottom: 5px; }
        .page-header p { color: #64748b; font-size: 0.95rem; }
        .breadcrumb-pill { background: #eff6ff; color: #2563eb; padding: 8px 16px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; display: flex; align-items: center; gap: 8px; }

        .split-layout-form { display: grid; grid-template-columns: 1.6fr 1fr; gap: 25px; align-items: start; }
        .form-left-panel, .form-right-panel { display: flex; flex-direction: column; gap: 25px; }
        
        .form-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 25px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.02); transition: background-color 0.3s, border-color 0.3s; }
        .card-title-sub { font-size: 0.95rem; font-weight: 700; color: #0f172a; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; transition: color 0.3s;}
        .card-title-sub i { color: #3b82f6; font-size: 1.1rem; }
        
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .form-row-uneven { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 20px; }
        
        .form-group { display: flex; flex-direction: column; gap: 6px; position: relative; margin-bottom: 20px; }
        .form-group label { font-size: 0.8rem; font-weight: 600; color: #1e293b; transition: color 0.3s;}
        
        .input-wrapper { position: relative; display: flex; align-items: center; }
        .input-wrapper input, .input-wrapper select { width: 100%; padding: 12px 16px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.9rem; outline: none; transition: 0.2s; background: #ffffff; }
        .input-wrapper input:focus, .input-wrapper select:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
        
        .input-icon { position: absolute; right: 14px; color: #94a3b8; font-size: 1rem; pointer-events: none; }
        .clickable-icon { pointer-events: auto; cursor: pointer; transition: color 0.2s; }
        .clickable-icon:hover { color: #2563eb; }

        .wysiwyg-toolbar { display: flex; align-items: center; gap: 4px; padding: 10px 14px; background: #f8fafc; border: 1px solid #cbd5e1; border-bottom: none; border-top-left-radius: 8px; border-top-right-radius: 8px; transition: 0.3s;}
        .wysiwyg-btn { padding: 6px 10px; color: #475569; cursor: pointer; border-radius: 4px; font-size: 0.9rem; border: none; background: transparent; transition: background 0.2s; }
        .wysiwyg-btn:hover { background: #e2e8f0; color: #0f172a; }
        
        .rich-text-editor { min-height: 160px; padding: 16px; border: 1px solid #cbd5e1; border-bottom-left-radius: 8px; border-bottom-right-radius: 8px; font-size: 0.95rem; background: #ffffff; overflow-y: auto; outline: none; transition: 0.2s; }
        .rich-text-editor:focus { border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1); }
        .rich-text-editor p { margin-bottom: 10px; }

        .stepper-input { display: flex; align-items: center; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden; background: #fff; transition: 0.3s;}
        .stepper-input input { border: none; text-align: center; border-radius: 0; padding: 12px 0; background: transparent; }
        .stepper-input input:focus { box-shadow: none; }
        .stepper-btn { padding: 0 15px; background: #f8fafc; color: #64748b; cursor: pointer; border: none; font-size: 1.2rem; display: flex; align-items: center; justify-content: center; transition: 0.3s;}
        .stepper-btn:hover { background: #e2e8f0; }

        .inspiration-banner { background: linear-gradient(135deg, #8b5cf6 0%, #ec4899 100%); border-radius: 16px; padding: 25px; color: white; display: flex; justify-content: space-between; align-items: center; }
        .banner-info { display: flex; gap: 15px; align-items: center; }
        .banner-icon { font-size: 1.8rem; color: #fef08a; }
        .banner-text h3 { font-size: 1.1rem; font-weight: 700; margin-bottom: 2px; }
        .banner-text p { font-size: 0.85rem; color: rgba(255,255,255,0.9); }
        .banner-pills { display: flex; gap: 10px; margin-top: 15px; flex-wrap: wrap; }
        .pill { background: white; padding: 8px 14px; border-radius: 8px; font-size: 0.8rem; font-weight: 600; color: #1e293b; display: flex; align-items: center; gap: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); cursor: pointer; border: none; transition: 0.2s;}
        .pill i.purple { color: #8b5cf6; } .pill i.blue { color: #3b82f6; } .pill i.pink { color: #ec4899; }

        .upload-dropzone { border: 2px dashed #8b5cf6; background: #f5f3ff; border-radius: 12px; padding: 30px 20px; text-align: center; color: #475569; cursor: pointer; transition: all 0.2s; }
        .upload-dropzone:hover { background: #ede9fe; }
        .upload-dropzone i { font-size: 2rem; color: #8b5cf6; margin-bottom: 10px; }
        .upload-dropzone p { font-size: 0.9rem; font-weight: 600; color: #4338ca; }
        .upload-dropzone span { font-size: 0.75rem; color: #64748b; display: block; margin-top: 5px; }

        .form-actions { display: flex; justify-content: space-between; align-items: center; grid-column: span 2; margin-top: 10px; border-top: 1px solid #e2e8f0; padding-top: 20px; transition: 0.3s;}
        .btn { padding: 12px 24px; border-radius: 8px; font-size: 0.9rem; font-weight: 600; cursor: pointer; border: none; display: flex; align-items: center; gap: 8px; text-decoration: none; transition: 0.2s;}
        .btn-outline { background: #ffffff; border: 1px solid #cbd5e1; color: #475569; }
        .btn-outline:hover { background: #f8fafc; }
        .btn-primary { background: #2563eb; color: white; }
        .btn-primary:hover { background: #1d4ed8; }
        .action-right { display: flex; gap: 15px; }

        .alert { padding: 16px; border-radius: 12px; font-size: 0.9rem; font-weight: 600; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .alert.error { background: #fee2e2; color: #ef4444; border: 1px solid #fca5a5; }

        /* =========================================
           GLOBAL DARK MODE CSS 
           ========================================= */
        body.dark-theme { background-color: #0f172a; color: #f8fafc; }
        body.dark-theme .top-navbar { background-color: #1e293b; border-bottom: 1px solid #334155; }
        body.dark-theme .page-header h1 { color: #f8fafc; }
        body.dark-theme .form-card { background-color: #1e293b; border-color: #334155; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
        body.dark-theme .card-title-sub { color: #f8fafc; }
        body.dark-theme label { color: #94a3b8; }
        
        body.dark-theme .input-wrapper input, 
        body.dark-theme .input-wrapper select { background-color: #0f172a; border-color: #475569; color: #f8fafc; }
        body.dark-theme .input-wrapper input:focus, 
        body.dark-theme .input-wrapper select:focus { border-color: #3b82f6; background-color: #1e293b; }
        
        body.dark-theme .wysiwyg-toolbar { background-color: #0f172a; border-color: #475569; }
        body.dark-theme .wysiwyg-btn { color: #94a3b8; }
        body.dark-theme .wysiwyg-btn:hover { background-color: #1e293b; color: #f8fafc; }
        body.dark-theme .rich-text-editor { background-color: #0f172a; border-color: #475569; color: #f8fafc; }
        body.dark-theme .rich-text-editor:focus { background-color: #1e293b; border-color: #3b82f6; }
        
        body.dark-theme .stepper-input { background-color: #0f172a; border-color: #475569; }
        body.dark-theme .stepper-input input { color: #f8fafc; }
        body.dark-theme .stepper-btn { background-color: #1e293b; color: #94a3b8; }
        body.dark-theme .stepper-btn:hover { background-color: #334155; color: #f8fafc; }

        body.dark-theme .upload-dropzone { background-color: #0f172a; border-color: #8b5cf6; }
        body.dark-theme .upload-dropzone:hover { background-color: #1e293b; }
        
        body.dark-theme .form-actions { border-top-color: #334155; }
        body.dark-theme .btn-outline { background-color: #1e293b; border-color: #475569; color: #f8fafc; }
        body.dark-theme .btn-outline:hover { background-color: #334155; }
        
        body.dark-theme .menu-btn, 
        body.dark-theme .nav-icon { border-color: #475569; color: #f8fafc; }
    </style>
    <link rel="stylesheet" href="smart_event_clean_theme.css?v=20260630">
</head>
<body class="<?php echo (isset($_SESSION['dark_mode']) && $_SESSION['dark_mode'] == 1) ? 'dark-theme' : ''; ?>">

    <aside class="sidebar">
        <div class="profile">
            <div class="avatar"><?php echo substr(htmlspecialchars($_SESSION['user_name']), 0, 1); ?></div>
            <h3><?php echo htmlspecialchars($_SESSION['user_name']); ?></h3>
            <p>BSIT-2026 / Admin</p>
            <div class="status-indicator online" id="networkStatus"><i class="fa-solid fa-circle"></i> <span id="networkText">Online</span></div>
        </div>

        <nav class="menu">
            <a href="home.php"><i class="fa-solid fa-house menu-icon"></i> <span>Index Portal</span></a>
            <a href="create_event.php" class="active"><i class="fa-solid fa-calendar-plus menu-icon"></i> <span>Create Event</span></a>
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
                
                <div class="header-row">
                    <div class="page-header">
                        <h1>Create New Event</h1>
                        <p>Fill out the parameters below to launch a brand new event module.</p>
                    </div>
                    <div class="breadcrumb-pill">
                        Dashboard <span><i class="fa-solid fa-chevron-right" style="font-size: 0.6rem; margin: 0 4px;"></i></span> Create Event
                    </div>
                </div>

                <?php if (!empty($message)) echo $message; ?>

                <form id="eventForm" action="create_event.php" method="POST" enctype="multipart/form-data">
                    <div class="split-layout-form">
                        <div class="form-left-panel">
                            <div class="form-card">
                                <div class="card-title-sub"><i class="fa-solid fa-calendar-day"></i> Primary Information</div>
                                
                                <div class="form-row-uneven">
                                    <div class="form-group">
                                        <label>Event Title</label>
                                        <div class="input-wrapper"><input type="text" id="input_title" name="event_title" placeholder="e.g., Tech Summit 2026: Innovations in OOP" required></div>
                                    </div>
                                    <div class="form-group">
                                        <label>Short Code</label>
                                        <div class="input-wrapper"><input type="text" name="short_code" placeholder="e.g., TECH2026"></div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Venue / Location</label>
                                    <div class="input-wrapper">
                                        <input type="text" id="input_venue" name="venue" placeholder="Enter venue or click pin to locate..." required>
                                        <i class="fa-solid fa-location-dot input-icon clickable-icon" id="getLocationBtn" title="Click to auto-locate using OpenStreet Maps"></i>
                                    </div>
                                </div>

                                <div class="form-group" style="margin-bottom: 25px;">
                                    <label>Event Description</label>
                                    <div class="wysiwyg-toolbar">
                                        <button type="button" class="wysiwyg-btn" style="font-weight:bold;" onclick="document.execCommand('bold',false,null);" title="Bold Text (Ctrl+B)">B</button>
                                        <button type="button" class="wysiwyg-btn" style="font-style:italic;" onclick="document.execCommand('italic',false,null);" title="Italicize Text (Ctrl+I)">I</button>
                                        <button type="button" class="wysiwyg-btn" style="text-decoration:underline;" onclick="document.execCommand('underline',false,null);" title="Underline Text (Ctrl+U)">U</button>
                                        <span style="color:#cbd5e1; margin:0 4px;">|</span>
                                        <button type="button" class="wysiwyg-btn" onclick="document.execCommand('insertUnorderedList',false,null);" title="Bullet List"><i class="fa-solid fa-list-ul"></i></button>
                                        <button type="button" class="wysiwyg-btn" onclick="document.execCommand('justifyLeft',false,null);" title="Align Left"><i class="fa-solid fa-align-left"></i></button>
                                        <button type="button" class="wysiwyg-btn" onclick="document.execCommand('justifyCenter',false,null);" title="Align Center"><i class="fa-solid fa-align-center"></i></button>
                                    </div>
                                    <div id="editor" class="rich-text-editor" contenteditable="true">Write a brief overview outlining timelines, keynote items, or target objectives...</div>
                                    <input type="hidden" name="description" id="hidden_description">
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Organizer <i class="fa-regular fa-circle-question" style="color:#94a3b8;"></i></label>
                                        <div class="input-wrapper">
                                            <input type="text" name="organizer" value="<?php echo htmlspecialchars($_SESSION['user_name']); ?>" placeholder="e.g., Event Management Team">
                                            <i class="fa-regular fa-user input-icon"></i>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>Contact Email <i class="fa-regular fa-circle-question" style="color:#94a3b8;"></i></label>
                                        <div class="input-wrapper">
                                            <input type="email" name="contact_email" placeholder="e.g., events@example.com">
                                            <i class="fa-regular fa-envelope input-icon"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="inspiration-banner">
                                <div class="banner-info">
                                    <div class="banner-icon"><i class="fa-regular fa-lightbulb"></i></div>
                                    <div class="banner-text">
                                        <h3>Need inspiration?</h3>
                                        <p>Use a template to get started quickly.</p>
                                        <div class="banner-pills">
                                            <button type="button" class="pill" data-type="Conference" data-title="Annual Tech Conference 2026" data-desc="Join us for a multi-day conference featuring industry leaders."><i class="fa-regular fa-calendar purple"></i> Conference</button>
                                            <button type="button" class="pill" data-type="Workshop" data-title="Interactive Training Workshop" data-desc="A hands-on, intensive workshop designed to upskill participants."><i class="fa-solid fa-briefcase blue"></i> Workshop</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-right-panel">
                            <div class="form-card">
                                <div class="card-title-sub purple"><i class="fa-solid fa-gear"></i> Event Settings</div>
                                <div class="form-group">
                                    <label>Event Type</label>
                                    <div class="input-wrapper">
                                        <select id="input_type" name="event_type" required>
                                            <option value="General Event">General Event</option>
                                            <option value="Conference">Conference</option>
                                            <option value="Workshop">Workshop</option>
                                            <option value="Launch">Product Launch</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Maximum Seats / Slots</label>
                                        <div class="stepper-input">
                                            <button type="button" class="stepper-btn" id="btn-minus">-</button>
                                            <input type="number" name="max_attendees" value="300" min="1" required>
                                            <button type="button" class="stepper-btn" id="btn-plus">+</button>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>Start Date</label>
                                        <div class="input-wrapper"><input type="date" name="start_date" required></div>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Start Time</label>
                                        <div class="input-wrapper"><input type="time" name="start_time" required></div>
                                    </div>
                                    <div class="form-group">
                                        <label>End Date</label>
                                        <div class="input-wrapper"><input type="date" name="end_date" required></div>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label>End Time</label>
                                        <div class="input-wrapper"><input type="time" name="end_time" required></div>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label>Time Zone</label>
                                    <div class="input-wrapper">
                                        <select name="timezone">
                                            <option>(GMT+08:00) Asia/Manila</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="form-card">
                                <div class="card-title-sub purple"><i class="fa-solid fa-paperclip"></i> Event Attachments</div>
                                <div class="form-group" style="margin-bottom:0;">
                                    <p style="font-size:0.8rem; color:#64748b; margin-bottom:10px;">Upload banner, program flow, or other supporting files.</p>
                                    <input type="file" id="event_banner" name="event_banner" accept="image/*,application/pdf" style="display: none;">
                                    <div class="upload-dropzone" id="dropzone">
                                        <i class="fa-solid fa-cloud-arrow-up"></i>
                                        <p id="dropzone-text">Click or drag files here to upload</p>
                                        <span>PNG, JPG, PDF up to 10MB</span>
                                        <div class="file-selected-text" id="file-info"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-actions">
                            <a href="home.php" class="btn btn-outline"><i class="fa-solid fa-xmark"></i> Cancel</a>
                            <div class="action-right">
                                <button type="button" class="btn btn-outline"><i class="fa-regular fa-file-lines"></i> Save Draft</button>
                                <button type="submit" name="submit_event" class="btn btn-primary"><i class="fa-solid fa-rocket"></i> Publish Event</button>
                            </div>
                        </div>

                    </div>
                </form>
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

        // Event Form Logic
        const form = document.getElementById('eventForm');
        const editor = document.getElementById('editor');
        const hiddenDesc = document.getElementById('hidden_description');
        editor.addEventListener('focus', function() { if (this.innerText.includes('Write a brief overview')) { this.innerHTML = ''; } });
        form.addEventListener('submit', function() { hiddenDesc.value = editor.innerHTML; });

        document.getElementById('getLocationBtn').addEventListener('click', function() {
            const venueInput = document.getElementById('input_venue');
            venueInput.value = "Locating GPS...";
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(position => {
                    const lat = position.coords.latitude;
                    const lon = position.coords.longitude;
                    fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lon}`)
                        .then(response => response.json())
                        .then(data => { venueInput.value = data.display_name || "Location found, address unavailable"; })
                        .catch(err => { venueInput.value = `Lat: ${lat}, Lon: ${lon}`; });
                }, () => { venueInput.value = "Location access denied."; });
            } else { venueInput.value = "Geolocation is not supported."; }
        });

        const pills = document.querySelectorAll('.pill');
        pills.forEach(pill => {
            pill.addEventListener('click', function() {
                document.getElementById('input_title').value = this.getAttribute('data-title');
                document.getElementById('input_type').value = this.getAttribute('data-type');
                editor.innerHTML = `<h3>${this.getAttribute('data-title')}</h3><p>${this.getAttribute('data-desc')}</p><br><ul><li>Item 1</li></ul>`;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });

        const dropzone = document.getElementById('dropzone');
        const fileInput = document.getElementById('event_banner');
        const fileInfo = document.getElementById('file-info');
        dropzone.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', () => {
            if (fileInput.files.length > 0) {
                document.getElementById('dropzone-text').innerText = "File Selected";
                fileInfo.innerHTML = `<i class="fa-solid fa-circle-check"></i> <strong>${fileInput.files[0].name}</strong>`;
                fileInfo.style.display = 'block';
            }
        });

        document.getElementById('btn-minus').addEventListener('click', () => {
            let val = parseInt(document.querySelector('input[name="max_attendees"]').value) || 0;
            if (val > 1) document.querySelector('input[name="max_attendees"]').value = val - 1;
        });
        document.getElementById('btn-plus').addEventListener('click', () => {
            let val = parseInt(document.querySelector('input[name="max_attendees"]').value) || 0;
            document.querySelector('input[name="max_attendees"]').value = val + 1;
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
