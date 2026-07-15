<?php
session_start();
include 'database.php';

$error_msg = "";

if (!isset($_SESSION['reset_email'])) {
    header("Location: forgot_password.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    $email = mysqli_real_escape_string($conn, $_SESSION['reset_email']);

    if ($new_password !== $confirm_password) {
        $error_msg = "Passwords do not match. Please try again.";
    } elseif (strlen($new_password) < 6) {
        $error_msg = "Password must be at least 6 characters.";
    } else {
        // This project currently logs in by comparing plain text passwords.
        $stored_password = mysqli_real_escape_string($conn, $new_password);
        $update = mysqli_query($conn, "UPDATE users SET password = '$stored_password' WHERE email = '$email'");

        if ($update) {
            unset($_SESSION['reset_email']);
            header("Location: login.php?success=Password successfully updated! You can now sign in.");
            exit();
        }

        $error_msg = "Error updating password. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Password - Smart Event</title>

    <link rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #1e293b;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        .auth-card {
            background: #ffffff;
            width: 100%;
            max-width: 472px;
            padding: 45px 50px;
            border-radius: 24px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            text-align: center;
        }

        .logo-icon {
            font-size: 2.8rem;
            color: #2563eb;
            margin-bottom: 18px;
        }

        .logo-text {
            font-size: 1.85rem;
            font-weight: 800;
            color: #1e293b;
            margin-bottom: 10px;
        }

        .logo-text span {
            color: #2563eb;
        }

        .subtitle {
            color: #64748b;
            font-size: 1rem;
            margin-bottom: 28px;
            line-height: 1.5;
        }

        .alert {
            padding: 18px;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 25px;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 12px;
            line-height: 1.5;
        }

        .alert-error {
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fca5a5;
        }

        .alert-success {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #86efac;
        }

        .input-group {
            text-align: left;
            margin-bottom: 22px;
            position: relative;
        }

        .input-group label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            color: #475569;
            margin-bottom: 8px;
        }

        .input-group input {
            width: 100%;
            padding: 14px 50px 14px 45px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 1rem;
            color: #1e293b;
            transition: all 0.3s;
        }

        .input-group input:focus {
            border-color: #2563eb;
            background: #ffffff;
            outline: none;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
        }

        .left-icon {
            position: absolute;
            left: 16px;
            top: 42px;
            color: #94a3b8;
        }

        .toggle-password {
            position: absolute;
            right: 16px;
            top: 42px;
            color: #94a3b8;
            cursor: pointer;
            transition: color 0.2s;
        }

        .toggle-password:hover {
            color: #2563eb;
        }

        .btn-primary {
            width: 100%;
            padding: 15px;
            background: #2563eb;
            color: #ffffff;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.3s;
            margin: 0 0 28px;
        }

        .btn-primary:hover {
            background: #1d4ed8;
        }

        .footer-link {
            font-size: 0.9rem;
            color: #64748b;
        }

        .footer-link a {
            color: #2563eb;
            font-weight: 700;
            text-decoration: none;
        }

        .footer-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>

<div class="auth-card">

    <i class="fa-solid fa-key logo-icon"></i>

    <div class="logo-text">
        Create<span>New Password</span>
    </div>

    <p class="subtitle">
        Enter a new secure password.
    </p>

    <?php if(!empty($error_msg)) { ?>

        <div class="alert alert-error">
            <i class="fa-solid fa-circle-exclamation"></i>
            <?php echo htmlspecialchars($error_msg); ?>
        </div>

    <?php } else { ?>

        <div class="alert alert-success">
            <i class="fa-solid fa-circle-check"></i>
            Account verified! Please enter your new password.
        </div>

    <?php } ?>

    <form action="" method="POST">

        <div class="input-group">

            <label>New Password</label>

            <i class="fa-solid fa-lock left-icon"></i>

            <input
                type="password"
                name="new_password"
                id="newPassword"
                placeholder="Enter new password"
                required
            >

            <i
                class="fa-solid fa-eye toggle-password"
                data-target="newPassword">
            </i>

        </div>

        <div class="input-group">

            <label>Confirm Password</label>

            <i class="fa-solid fa-lock left-icon"></i>

            <input
                type="password"
                name="confirm_password"
                id="confirmPassword"
                placeholder="Confirm new password"
                required
            >

            <i
                class="fa-solid fa-eye toggle-password"
                data-target="confirmPassword">
            </i>

        </div>

        <button type="submit" class="btn-primary">
            Save New Password
        </button>

    </form>

    <div class="footer-link">
        <a href="login.php">
            <i class="fa-solid fa-xmark"></i>
            Cancel
        </a>
    </div>

</div>

<script>
    document.querySelectorAll(".toggle-password").forEach(function(icon) {

        icon.addEventListener("click", function() {

            const input = document.getElementById(this.dataset.target);

            const isPassword = input.getAttribute("type") === "password";

            input.setAttribute("type", isPassword ? "text" : "password");

            this.classList.toggle("fa-eye");
            this.classList.toggle("fa-eye-slash");

        });

    });
</script>

</body>
</html>
