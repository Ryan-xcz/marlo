<?php
session_start();
include 'database.php';

if (!isset($_SESSION['user_name'])) {
    header("Location: index.php");
    exit();
}

$current_user = $_SESSION['user_name'];
$current_user_safe = mysqli_real_escape_string($conn, $current_user);

mysqli_query($conn, "ALTER TABLE users ADD COLUMN IF NOT EXISTS last_activity DATETIME NULL");
mysqli_query($conn, "ALTER TABLE users ADD COLUMN IF NOT EXISTS is_online TINYINT(1) NOT NULL DEFAULT 0");

$stmt = mysqli_prepare($conn, "UPDATE users SET is_online = 1, last_activity = NOW() WHERE fullname = ?");
mysqli_stmt_bind_param($stmt, "s", $current_user);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

$user_settings_query = mysqli_query($conn, "SELECT email_notif, event_reminders FROM users WHERE fullname = '$current_user_safe'");
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

/*
    FIX:
    Your table does not have event_time.
    This checks event date only.
    If event date is today or future = Available.
    If event date is past = Ended.
*/
$events = mysqli_query($conn, "
    SELECT *,
    CASE
        WHEN DATE(COALESCE(start_date, event_date)) >= CURDATE()
        THEN 'Available'
        ELSE 'Ended'
    END AS event_status
    FROM events
    ORDER BY id DESC
");

$table_exists = mysqli_query($conn, "SHOW TABLES LIKE 'registrations'");
$registrations = null;

if ($table_exists && mysqli_num_rows($table_exists) > 0) {
    $registrations = mysqli_query($conn, "
        SELECT 
            r.*,
            u.is_online,
            u.last_activity,
            CASE 
                WHEN u.is_online = 1 
                AND u.last_activity >= (NOW() - INTERVAL 2 MINUTE)
                THEN 'Online'
                ELSE 'Offline'
            END AS user_status
        FROM registrations r
        LEFT JOIN users u ON r.student_name = u.fullname
        ORDER BY r.id DESC
    ");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

<style>
* { margin:0; padding:0; box-sizing:border-box; font-family:'Inter', sans-serif; }

body {
    background:#f4f7fe;
    color:#1e293b;
    display:flex;
    min-height:100vh;
}

.sidebar {
    width:286px;
    background:#0b132b;
    color:white;
    position:fixed;
    height:100vh;
    padding:32px 14px;
    display:flex;
    flex-direction:column;
}

.profile {
    text-align:center;
    margin-bottom:24px;
}

.avatar {
    width:70px;
    height:70px;
    border-radius:50%;
    margin:0 auto 12px;
    background:#3b82f6;
    display:grid;
    place-items:center;
    font-size:28px;
    font-weight:900;
    text-transform:uppercase;
}

.profile h3 {
    font-size:20px;
    font-weight:800;
}

.profile p {
    margin-top:5px;
    font-size:13px;
    color:#8297bb;
}

.status-indicator {
    display:inline-flex;
    align-items:center;
    gap:6px;
    margin-top:10px;
    font-size:.75rem;
    font-weight:700;
    padding:4px 12px;
    border-radius:20px;
    color:#22c55e;
    background:rgba(34,197,94,.1);
}

.menu {
    display:flex;
    flex-direction:column;
    gap:6px;
    flex:1;
}

.menu a {
    text-decoration:none;
    color:#a9bddc;
    height:46px;
    padding:0 20px;
    border-radius:12px;
    display:flex;
    align-items:center;
    gap:16px;
    font-size:15px;
    font-weight:700;
}

.menu a:hover,
.menu a.active {
    background:#2563eb;
    color:white;
}

.menu-icon {
    width:22px;
    text-align:center;
}

.logout-link {
    margin-top:auto;
    color:#ef4444 !important;
}

.wrapper-main {
    flex:1;
    margin-left:286px;
    width:calc(100% - 286px);
}

.top-navbar {
    height:70px;
    background:white;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 40px;
    border-bottom:1px solid #e2e8f0;
    position:sticky;
    top:0;
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
    display:flex;
    align-items:center;
    justify-content:center;
    color:#64748b;
    text-decoration:none;
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
}

.mini-avatar {
    width:40px;
    height:40px;
    border-radius:50%;
    background:#3b82f6;
    color:white;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:700;
    text-transform:uppercase;
}

.main-content {
    padding:40px 50px;
}

.container {
    width:100%;
    max-width:1200px;
    margin:0 auto;
}

.section-title {
    margin-bottom:20px;
    font-weight:800;
    font-size:1.4rem;
}

.table-container {
    background:white;
    border-radius:16px;
    overflow:hidden;
    margin-bottom:40px;
    border:1px solid #dce5f1;
}

table {
    width:100%;
    border-collapse:collapse;
}

th, td {
    padding:18px 24px;
    text-align:left;
    border-bottom:1px solid #e2e8f0;
}

th {
    background:#f8fafc;
    color:#64748b;
    font-weight:700;
    font-size:.85rem;
    text-transform:uppercase;
}

tr:hover td {
    background:#f8fafc;
}

.status-badge {
    padding:6px 14px;
    border-radius:20px;
    font-size:.8rem;
    font-weight:700;
    display:inline-block;
}

.online-badge,
.available-badge {
    background:#dcfce7;
    color:#166534;
}

.offline-badge,
.ended-badge {
    background:#fee2e2;
    color:#991b1b;
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
    <link rel="stylesheet" href="smart_event_clean_theme.css?v=20260630">
</head>

<body>

<aside class="sidebar">
    <div class="profile">
        <div class="avatar"><?php echo substr(htmlspecialchars($_SESSION['user_name']), 0, 1); ?></div>
        <h3><?php echo htmlspecialchars($_SESSION['user_name']); ?></h3>
        <p>BSIT-2026 / Admin</p>
        <div class="status-indicator">
            <i class="fa-solid fa-circle"></i> Online
        </div>
    </div>

    <nav class="menu">
        <a href="home.php"><i class="fa-solid fa-house menu-icon"></i>Index Portal</a>
        <a href="create_event.php"><i class="fa-solid fa-calendar-plus menu-icon"></i>Create Event</a>
        <a href="register_event.php"><i class="fa-solid fa-user-check menu-icon"></i>Register for Event</a>
        <a href="dashboard.php" class="active"><i class="fa-solid fa-chart-line menu-icon"></i>Full Dashboard</a>
        <a href="analytics.php"><i class="fa-solid fa-chart-bar menu-icon"></i>Analytics</a>
        <a href="xml_report.php"><i class="fa-solid fa-file-code menu-icon"></i>XML Report</a>
        <a href="settings.php"><i class="fa-solid fa-gear menu-icon"></i>Settings</a>
        <a href="help.php"><i class="fa-solid fa-circle-question menu-icon"></i>Help & Support</a>
        <a href="logout.php" class="logout-link"><i class="fa-solid fa-arrow-right-from-bracket menu-icon"></i>Logout</a>
    </nav>
</aside>

<div class="wrapper-main">
<header class="top-navbar">
    <div><i class="fa-solid fa-bars"></i></div>

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

<h3 class="section-title">Upcoming Events Directory</h3>

<div class="table-container">
<table>
<thead>
<tr>
    <th>ID</th>
    <th>Event Title</th>
    <th>Venue</th>
    <th>Date</th>
    <th>Status</th>
</tr>
</thead>

<tbody>
<?php if ($events && mysqli_num_rows($events) > 0): ?>
    <?php while ($event = mysqli_fetch_assoc($events)): ?>
        <tr>
            <td>#<?php echo htmlspecialchars($event['id']); ?></td>
            <td><strong><?php echo htmlspecialchars($event['title']); ?></strong></td>
            <td><?php echo htmlspecialchars($event['venue']); ?></td>
            <td>
                <i class="fa-regular fa-calendar" style="color:#64748b; margin-right:8px;"></i>
                <?php echo isset($event['start_date']) ? htmlspecialchars($event['start_date']) : htmlspecialchars($event['event_date']); ?>
            </td>
            <td>
                <?php if ($event['event_status'] === 'Available'): ?>
                    <span class="status-badge available-badge">Available</span>
                <?php else: ?>
                    <span class="status-badge ended-badge">Ended</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endwhile; ?>
<?php else: ?>
    <tr>
        <td colspan="5" style="text-align:center; padding:30px; color:#64748b;">
            No events exist.
        </td>
    </tr>
<?php endif; ?>
</tbody>
</table>
</div>

<h3 class="section-title">Recent User Registrations</h3>

<div class="table-container">
<table>
<thead>
<tr>
    <th>Student Name</th>
    <th>Student ID</th>
    <th>Course</th>
    <th>Status</th>
</tr>
</thead>

<tbody>
<?php if ($registrations && mysqli_num_rows($registrations) > 0): ?>
    <?php while ($reg = mysqli_fetch_assoc($registrations)): ?>
        <tr>
            <td><strong><?php echo htmlspecialchars($reg['student_name']); ?></strong></td>
            <td><?php echo htmlspecialchars($reg['student_id']); ?></td>
            <td><?php echo htmlspecialchars($reg['course']); ?></td>
            <td>
                <?php if ($reg['user_status'] === 'Online'): ?>
                    <span class="status-badge online-badge">Online</span>
                <?php else: ?>
                    <span class="status-badge offline-badge">Offline</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endwhile; ?>
<?php else: ?>
    <tr>
        <td colspan="4" style="text-align:center; padding:30px; color:#64748b;">
            No registrations found.
        </td>
    </tr>
<?php endif; ?>
</tbody>
</table>
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
