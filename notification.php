<?php
session_start();
include 'database.php';

if (!isset($_SESSION['user_name'])) {
    exit();
}

$current_user = $_SESSION['user_name'];

/*
|--------------------------------------------------------------------------
| AUTO CREATE COLUMNS IF MISSING
|--------------------------------------------------------------------------
*/

$check1 = mysqli_query($conn, "SHOW COLUMNS FROM users LIKE 'notification_seen_at'");

if (mysqli_num_rows($check1) == 0) {
    mysqli_query($conn, "
        ALTER TABLE users
        ADD notification_seen_at DATETIME NULL
    ");
}

$check2 = mysqli_query($conn, "SHOW COLUMNS FROM registrations LIKE 'created_at'");

if (mysqli_num_rows($check2) == 0) {
    mysqli_query($conn, "
        ALTER TABLE registrations
        ADD created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ");
}

/*
|--------------------------------------------------------------------------
| MARK AS SEEN
|--------------------------------------------------------------------------
*/

$stmt = mysqli_prepare($conn, "
    UPDATE users
    SET notification_seen_at = NOW()
    WHERE fullname = ?
");

mysqli_stmt_bind_param($stmt, "s", $current_user);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

/*
|--------------------------------------------------------------------------
| GET NOTIFICATIONS
|--------------------------------------------------------------------------
*/

$notifications = [];

$query = mysqli_query($conn, "
    SELECT *
    FROM registrations
    ORDER BY created_at DESC
    LIMIT 10
");

if ($query) {
    while ($row = mysqli_fetch_assoc($query)) {
        $notifications[] = $row;
    }
}
?>

<div style="width:100%; background:white;">

    <div style="
        padding:18px 20px;
        font-size:22px;
        font-weight:800;
        border-bottom:1px solid #e5e7eb;
    ">
        Recent Activity
    </div>

    <div style="
        max-height:400px;
        overflow-y:auto;
    ">

        <?php if(count($notifications) > 0): ?>

            <?php foreach($notifications as $notif): ?>

                <div style="
                    display:flex;
                    gap:12px;
                    padding:16px 20px;
                    border-bottom:1px solid #f1f5f9;
                ">

                    <div style="
                        background:#dcfce7;
                        color:#15803d;
                        padding:4px 8px;
                        border-radius:6px;
                        font-size:11px;
                        font-weight:700;
                        height:fit-content;
                    ">
                        New Reg
                    </div>

                    <div>

                        <div style="
                            font-size:16px;
                            font-weight:700;
                            color:#0f172a;
                        ">
                            <?php echo htmlspecialchars($notif['student_name']); ?>
                        </div>

                        <div style="
                            margin-top:4px;
                            font-size:14px;
                            color:#475569;
                        ">
                            Registered for:
                            <?php echo htmlspecialchars($notif['event_name']); ?>
                        </div>

                        <div style="
                            margin-top:5px;
                            font-size:12px;
                            color:#94a3b8;
                        ">
                            <?php echo date("M d, Y h:i A", strtotime($notif['created_at'])); ?>
                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        <?php else: ?>

            <div style="
                padding:30px;
                text-align:center;
                color:#94a3b8;
                font-weight:600;
            ">
                No new notifications.
            </div>

        <?php endif; ?>

    </div>

</div>
