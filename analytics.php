<?php
session_start();
include 'database.php';

if (!isset($_SESSION['user_name'])) {
    header("Location: index.php");
    exit();
}

$current_user = mysqli_real_escape_string($conn, $_SESSION['user_name']);

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

$total_events = 0;
$total_registrations = 0;
$active_users = 0;

$event_query = mysqli_query($conn, "SHOW TABLES LIKE 'events'");
$has_events = ($event_query && mysqli_num_rows($event_query) > 0);

if ($has_events) {
    $res = mysqli_query($conn, "SELECT COUNT(*) as count FROM events");
    if ($res) $total_events = mysqli_fetch_assoc($res)['count'];
}

$reg_query = mysqli_query($conn, "SHOW TABLES LIKE 'registrations'");
$has_regs = ($reg_query && mysqli_num_rows($reg_query) > 0);

if ($has_regs) {
    $res = mysqli_query($conn, "SELECT COUNT(*) as count FROM registrations");
    if ($res) $total_registrations = mysqli_fetch_assoc($res)['count'];
}

$user_query = mysqli_query($conn, "SHOW TABLES LIKE 'users'");
if ($user_query && mysqli_num_rows($user_query) > 0) {
    $res = mysqli_query($conn, "SELECT COUNT(*) as count FROM users");
    if ($res) $active_users = mysqli_fetch_assoc($res)['count'];
}

$recent_activities = [];

if ($has_regs) {
    $act_sql = mysqli_query($conn, "SELECT student_name, event_name, attendance_status FROM registrations ORDER BY id DESC LIMIT 4");
    if ($act_sql) {
        while ($row = mysqli_fetch_assoc($act_sql)) {
            $recent_activities[] = $row;
        }
    }
}

$event_performance = [];
$chart_labels = [];
$chart_data = [];

if ($has_events && $has_regs) {
    $perf_sql = mysqli_query($conn, "
        SELECT 
            e.title, 
            e.max_attendees, 
            COUNT(r.id) AS reg_count,
            CASE 
                WHEN COUNT(r.id) >= e.max_attendees THEN 'Full'
                ELSE 'Available'
            END AS fill_status
        FROM events e
        LEFT JOIN registrations r ON e.title = r.event_name
        GROUP BY e.id, e.title, e.max_attendees
        ORDER BY reg_count DESC
        LIMIT 5
    ");

    if ($perf_sql) {
        while ($row = mysqli_fetch_assoc($perf_sql)) {
            $event_performance[] = $row;
            $chart_labels[] = $row['title'];
            $chart_data[] = $row['reg_count'];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Analytics Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
* { margin:0; padding:0; box-sizing:border-box; font-family:'Inter', sans-serif; }
body { display:flex; min-height:100vh; background:#f0f4f8; }
.sidebar { width:286px; background:#0b132b; color:white; position:fixed; height:100vh; padding:32px 14px; display:flex; flex-direction:column; }
.profile { text-align:center; margin-bottom:24px; }
.avatar { width:70px; height:70px; border-radius:50%; margin:0 auto 12px; background:#3b82f6; display:flex; align-items:center; justify-content:center; font-size:28px; font-weight:900; }
.profile h3 { font-size:20px; font-weight:800; }
.profile p { color:#8297bb; font-size:13px; margin-top:5px; }
.menu { display:flex; flex-direction:column; gap:6px; flex:1; }
.menu a { text-decoration:none; color:#a9bddc; height:46px; padding:0 20px; border-radius:12px; display:flex; align-items:center; gap:16px; font-size:15px; font-weight:700; }
.menu a:hover, .menu a.active { background:#2563eb; color:white; }
.menu-icon { width:22px; text-align:center; }
.logout-link { margin-top:auto; color:#ef4444 !important; }
.wrapper-main { flex:1; margin-left:286px; display:flex; flex-direction:column; }
.top-navbar { height:70px; background:white; display:flex; align-items:center; justify-content:space-between; padding:0 40px; border-bottom:1px solid #e2e8f0; position:sticky; top:0; z-index:90; }
.nav-right { display:flex; align-items:center; gap:15px; color:#64748b; font-size:1.25rem; }
.nav-icon { position:relative; width:36px; height:36px; display:flex; align-items:center; justify-content:center; color:#64748b; text-decoration:none; }
.badge { position:absolute; top:-4px; right:-4px; background:#ef4444; color:white; font-size:0.65rem; min-width:18px; height:18px; border-radius:50%; display:flex; align-items:center; justify-content:center; }
.mini-avatar { width:40px; height:40px; border-radius:50%; background:#3b82f6; color:white; display:flex; align-items:center; justify-content:center; font-weight:700; }
.main-content { padding:30px; }
.top-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:30px; background:white; padding:15px 25px; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,0.05); }
.page-title h1 { font-size:24px; color:#1e293b; font-weight:800; }
.page-title p { color:#64748b; font-size:14px; }
.btn-export { padding:10px 20px; background:#3b82f6; color:white; border:none; border-radius:8px; cursor:pointer; font-weight:600; }
.stats-grid { display:grid; grid-template-columns:repeat(4, 1fr); gap:20px; margin-bottom:30px; }
.stat-card { background:white; padding:25px; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,0.05); border-left:4px solid #3b82f6; }
.stat-card.green { border-left-color:#22c55e; }
.stat-card.purple { border-left-color:#8b5cf6; }
.stat-card.orange { border-left-color:#f97316; }
.stat-label { font-size:13px; color:#64748b; font-weight:700; text-transform:uppercase; margin-bottom:10px; }
.stat-value { font-size:32px; font-weight:800; color:#1e293b; }
.charts-grid { display:grid; grid-template-columns:2fr 1fr; gap:20px; margin-bottom:30px; }
.chart-card { background:white; padding:25px; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,0.05); }
.chart-title { font-size:16px; font-weight:700; color:#1e293b; margin-bottom:20px; }
.tables-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.table-card { background:white; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,0.05); overflow:hidden; }
.table-header { padding:20px 25px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; }
.table-title { font-size:16px; font-weight:700; color:#1e293b; }
.view-all { color:#3b82f6; font-size:13px; font-weight:600; text-decoration:none; }
table { width:100%; border-collapse:collapse; }
th, td { padding:15px 25px; text-align:left; border-bottom:1px solid #f1f5f9; }
th { background:#f8fafc; font-size:12px; color:#64748b; text-transform:uppercase; }
td { font-size:14px; color:#1e293b; font-weight:500; }
.status-badge { padding:4px 10px; border-radius:20px; font-size:12px; font-weight:700; display:inline-flex; align-items:center; gap:6px; }
.status-badge.online, .status-badge.available { background:#dcfce7; color:#166534; }
.status-badge.offline, .status-badge.full { background:#fee2e2; color:#991b1b; }
.progress-bar { width:100%; height:8px; background:#e2e8f0; border-radius:4px; overflow:hidden; }
.progress-fill { height:100%; background:linear-gradient(90deg,#3b82f6,#8b5cf6); border-radius:4px; }

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
        <div class="avatar"><?php echo substr(htmlspecialchars($_SESSION['user_name'] ?? 'R'), 0, 1); ?></div>
        <h3><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?></h3>
        <p>BSIT-2026 / Admin</p>
    </div>

    <nav class="menu">
        <a href="home.php"><i class="fa-solid fa-house menu-icon"></i>Index Portal</a>
        <a href="create_event.php"><i class="fa-solid fa-calendar-plus menu-icon"></i>Create Event</a>
        <a href="register_event.php"><i class="fa-solid fa-user-check menu-icon"></i>Register for Event</a>
        <a href="dashboard.php"><i class="fa-solid fa-chart-line menu-icon"></i>Full Dashboard</a>
        <a href="analytics.php" class="active"><i class="fa-solid fa-chart-bar menu-icon"></i>Analytics</a>
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
    <div class="top-header">
        <div class="page-title">
            <h1>Analytics Dashboard</h1>
            <p>Monitor event performance and user engagement in real-time.</p>
        </div>
        <button class="btn-export" onclick="window.print()">
            <i class="fa-solid fa-download"></i> Print / Export
        </button>
    </div>

    <div class="stats-grid">
        <div class="stat-card"><div class="stat-label">Total Events</div><div class="stat-value"><?php echo $total_events; ?></div></div>
        <div class="stat-card green"><div class="stat-label">Total Registrations</div><div class="stat-value"><?php echo $total_registrations; ?></div></div>
        <div class="stat-card purple"><div class="stat-label">Active System Users</div><div class="stat-value"><?php echo $active_users; ?></div></div>
        <div class="stat-card orange"><div class="stat-label">System Health</div><div class="stat-value">100%</div></div>
    </div>

    <div class="charts-grid">
        <div class="chart-card">
            <div class="chart-title">Registration Trends</div>
            <canvas id="lineChart" height="100"></canvas>
        </div>

        <div class="chart-card">
            <div class="chart-title">Top Events by Registration</div>
            <canvas id="doughnutChart" height="180"></canvas>
        </div>
    </div>

    <div class="tables-grid">
        <div class="table-card">
            <div class="table-header">
                <div class="table-title">Recent Registrations</div>
                <a href="dashboard.php" class="view-all">View All →</a>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>User Name</th>
                        <th>Target Event</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($recent_activities)): ?>
                    <tr><td colspan="3" style="text-align:center;">No recent activity found.</td></tr>
                <?php else: ?>
                    <?php foreach ($recent_activities as $activity): ?>
                        <?php
                        $logged_user = strtolower(trim($_SESSION['user_name'] ?? ''));
                        $row_user = strtolower(trim($activity['student_name'] ?? ''));
                        $is_online = ($logged_user === $row_user);
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($activity['student_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($activity['event_name']); ?></td>
                            <td>
                                <?php if ($is_online): ?>
                                    <span class="status-badge online"><i class="fa-solid fa-circle"></i>Online</span>
                                <?php else: ?>
                                    <span class="status-badge offline"><i class="fa-solid fa-circle-xmark"></i>Offline</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="table-card">
            <div class="table-header">
                <div class="table-title">Event Fill Rate Performance</div>
                <a href="dashboard.php" class="view-all">View All →</a>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Event Title</th>
                        <th>Registered / Max</th>
                        <th>Status</th>
                        <th>Fill Rate Bar</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($event_performance)): ?>
                    <tr><td colspan="4" style="text-align:center;">No events found.</td></tr>
                <?php else: ?>
                    <?php foreach ($event_performance as $perf): ?>
                        <?php
                        $reg_count = intval($perf['reg_count']);
                        $max_display = intval($perf['max_attendees']);
                        $max_calc = ($max_display <= 0) ? 1 : $max_display;
                        $percentage = min(100, round(($reg_count / $max_calc) * 100));
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($perf['title']); ?></td>
                            <td><?php echo $reg_count; ?> / <?php echo htmlspecialchars($perf['max_attendees']); ?></td>
                            <td>
                                <?php if ($perf['fill_status'] === 'Full'): ?>
                                    <span class="status-badge full">Full</span>
                                <?php else: ?>
                                    <span class="status-badge available">Available</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="progress-bar" title="<?php echo $percentage; ?>%">
                                    <div class="progress-fill" style="width: <?php echo $percentage; ?>%"></div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
</div>

<script>
new Chart(document.getElementById('lineChart'), {
    type: 'line',
    data: {
        labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
        datasets: [{
            label: 'Registrations',
            data: [2, 5, 3, 8, 12, 6, <?php echo $total_registrations; ?>],
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59,130,246,0.1)',
            borderWidth: 3,
            fill: true,
            tension: 0.4
        }]
    }
});

new Chart(document.getElementById('doughnutChart'), {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode(empty($chart_labels) ? ['No Events'] : $chart_labels); ?>,
        datasets: [{
            data: <?php echo json_encode(empty($chart_data) ? [1] : $chart_data); ?>,
            backgroundColor: ['#3b82f6', '#22c55e', '#8b5cf6', '#f97316', '#eab308']
        }]
    },
    options: {
        plugins: {
            legend: { position: 'bottom' }
        },
        cutout: '65%'
    }
});
</script>

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
