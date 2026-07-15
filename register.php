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

// Add needed columns
mysqli_query($conn, "ALTER TABLE users ADD COLUMN IF NOT EXISTS student_id VARCHAR(50) NULL");
mysqli_query($conn, "ALTER TABLE users ADD COLUMN IF NOT EXISTS course VARCHAR(100) NULL");
mysqli_query($conn, "ALTER TABLE users ADD COLUMN IF NOT EXISTS year_level VARCHAR(50) NULL");
mysqli_query($conn, "ALTER TABLE users ADD COLUMN IF NOT EXISTS contact_number VARCHAR(30) NULL");
mysqli_query($conn, "ALTER TABLE users ADD COLUMN IF NOT EXISTS last_activity DATETIME NULL");
mysqli_query($conn, "ALTER TABLE users ADD COLUMN IF NOT EXISTS is_online TINYINT(1) NOT NULL DEFAULT 0");

// Update online status
$stmt_online = mysqli_prepare($conn, "UPDATE users SET is_online = 1, last_activity = NOW() WHERE fullname = ?");
mysqli_stmt_bind_param($stmt_online, "s", $current_user);
mysqli_stmt_execute($stmt_online);
mysqli_stmt_close($stmt_online);

// Save profile
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $student_id = trim($_POST['student_id']);
    $course = trim($_POST['course']);
    $year_level = trim($_POST['year_level']);
    $contact_number = trim($_POST['contact_number']);

    if (empty($student_id)) {
        $message = "Student ID is required.";
        $message_type = "error";
    } else {
        // Check if Student ID is already used by another user
        $check = mysqli_prepare($conn, "SELECT fullname FROM users WHERE student_id = ? AND fullname != ?");
        mysqli_stmt_bind_param($check, "ss", $student_id, $current_user);
        mysqli_stmt_execute($check);
        $check_result = mysqli_stmt_get_result($check);

        if (mysqli_num_rows($check_result) > 0) {
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
            } else {
                $message = "Failed to save profile information.";
                $message_type = "error";
            }

            mysqli_stmt_close($update);
        }

        mysqli_stmt_close($check);
    }
}

// Fetch user info
$stmt = mysqli_prepare($conn, "SELECT fullname, email, student_id, course, year_level, contact_number FROM users WHERE fullname = ?");
mysqli_stmt_bind_param($stmt, "s", $current_user);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$user = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);
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
        }

        .tab {
            padding: 16px 28px;
            color: #64748b;
            font-weight: 700;
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .tab.active {
            background: #eff6ff;
            color: #2563eb;
            border-left: 4px solid #2563eb;
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
        }

        input[readonly] {
            background: #e2e8f0;
            color: #64748b;
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
    </style>
</head>

<body>

<aside class="sidebar">
    <div class="profile">
        <div class="avatar"><?php echo strtoupper(substr(htmlspecialchars($user['fullname']), 0, 1)); ?></div>
        <h3><?php echo htmlspecialchars($user['fullname']); ?></h3>
        <p>BSIT-2026 / Admin</p>
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
        <div>
            <i class="fa-regular fa-bell"></i>
            &nbsp;&nbsp;
            <?php echo htmlspecialchars($user['fullname']); ?>
        </div>
    </header>

    <main class="content">
        <div class="header-card">
            <h1>Account Settings</h1>
            <p>Manage your profile, personal information, and account security.</p>
        </div>

        <div class="settings-layout">
            <div class="tabs">
                <div class="tab active"><i class="fa-regular fa-user"></i> My Profile</div>
                <div class="tab"><i class="fa-solid fa-shield-halved"></i> Security & Passwords</div>
                <div class="tab"><i class="fa-solid fa-sliders"></i> System Preferences</div>
            </div>

            <div class="settings-card">
                <h2>Personal Information</h2>
                <p>Set your personal details. Student ID cannot be used by another account.</p>
                <div class="divider"></div>

                <?php if (!empty($message)): ?>
                    <div class="alert <?php echo $message_type; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
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
                            >
                        </div>
                    </div>

                    <div class="row">
                        <div>
                            <label>Year Level</label>
                            <select name="year_level">
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
            </div>
        </div>
    </main>
</div>

<script>
function updateUserActivity() {
    fetch('update_user_status.php').catch(() => {});
}

updateUserActivity();
setInterval(updateUserActivity, 30000);
</script>

</body>
</html>
