<?php
session_start();

if (isset($_SESSION['user_name'])) {
    header("Location: home.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Login - Smart Event</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Inter', sans-serif;
}

body {
    min-height: 100vh;
    background:
        radial-gradient(circle at top left, rgba(37,99,235,0.12), transparent 30rem),
        radial-gradient(circle at bottom right, rgba(124,58,237,0.10), transparent 30rem),
        #f4f7fe;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}

.auth-card {
    width: 100%;
    max-width: 430px;
    background: #ffffff;
    border-radius: 26px;
    padding: 48px 42px;
    box-shadow: 0 24px 70px rgba(15, 23, 42, 0.10);
    border: 1px solid #eef2ff;
}

.logo {
    text-align: center;
    margin-bottom: 28px;
}

.logo h1 {
    font-size: 30px;
    font-weight: 900;
    color: #0f172a;
    letter-spacing: -1px;
}

.logo h1 span {
    color: #2563eb;
}

.logo p {
    margin-top: 8px;
    color: #64748b;
    font-size: 15px;
}

.form-box {
    display: none;
}

.form-box.active {
    display: block;
}

.input-group {
    margin-bottom: 20px;
}

.input-group label {
    display: block;
    font-size: 14px;
    font-weight: 700;
    color: #334155;
    margin-bottom: 8px;
}

.input-wrap {
    position: relative;
}

.input-wrap i {
    position: absolute;
    left: 17px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
}

.input-wrap input {
    width: 100%;
    height: 54px;
    border: 1px solid #dbe5f7;
    border-radius: 14px;
    padding: 0 48px;
    background: #edf4ff;
    outline: none;
    font-size: 15px;
    font-weight: 600;
    color: #0f172a;
}

.input-wrap input:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
    background: #ffffff;
}

.toggle-password {
    position: absolute;
    right: 16px;
    left: auto !important;
    cursor: pointer;
}

.options {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin: 6px 0 26px;
    font-size: 14px;
    color: #64748b;
}

.options a,
.switch-text a {
    color: #2563eb;
    text-decoration: none;
    font-weight: 800;
    cursor: pointer;
}

.btn {
    width: 100%;
    height: 54px;
    border: none;
    border-radius: 14px;
    background: #2563eb;
    color: white;
    font-size: 16px;
    font-weight: 800;
    cursor: pointer;
    box-shadow: 0 12px 28px rgba(37, 99, 235, 0.22);
}

.btn:hover {
    background: #1d4ed8;
}

.switch-text {
    text-align: center;
    margin-top: 26px;
    font-size: 14px;
    color: #64748b;
}

.alert {
    display: none;
    margin-bottom: 18px;
    padding: 14px 16px;
    border-radius: 14px;
    font-size: 14px;
    font-weight: 700;
}

.alert.success {
    display: block;
    background: #dcfce7;
    color: #15803d;
    border: 1px solid #86efac;
}

.alert.error {
    display: block;
    background: #fee2e2;
    color: #b91c1c;
    border: 1px solid #fca5a5;
}
</style>
</head>

<body>

<div class="auth-card">

    <div class="logo">
        <h1>Smart<span>Event</span></h1>
        <p id="formSubtitle">Welcome back! Please enter your details.</p>
    </div>

    <div id="alertBox" class="alert"></div>

    <!-- LOGIN FORM -->
    <form id="loginForm" class="form-box active">
        <div class="input-group">
            <label>Email</label>
            <div class="input-wrap">
                <i class="fa-solid fa-envelope"></i>
                <input type="email" name="email" placeholder="Enter your email" required>
            </div>
        </div>

        <div class="input-group">
            <label>Password</label>
            <div class="input-wrap">
                <i class="fa-solid fa-lock"></i>
                <input type="password" name="password" id="loginPassword" placeholder="Enter your password" required>
                <i class="fa-solid fa-eye toggle-password" onclick="togglePassword('loginPassword', this)"></i>
            </div>
        </div>

        <div class="options">
            <label>
                <input type="checkbox"> Remember me
            </label>
            <a href="forgot_password.php">Forgot password?</a>
        </div>

        <button type="submit" class="btn">Sign In</button>

        <div class="switch-text">
            Don't have an account?
            <a onclick="showSignup()">Sign up</a>
        </div>
    </form>

    <!-- SIGNUP FORM -->
    <form id="signupForm" class="form-box">
        <div class="input-group">
            <label>Full Name</label>
            <div class="input-wrap">
                <i class="fa-solid fa-user"></i>
                <input type="text" name="fullname" placeholder="Enter your full name" required>
            </div>
        </div>

        <div class="input-group">
            <label>Email</label>
            <div class="input-wrap">
                <i class="fa-solid fa-envelope"></i>
                <input type="email" name="email" placeholder="Enter your email" required>
            </div>
        </div>

        <div class="input-group">
            <label>Password</label>
            <div class="input-wrap">
                <i class="fa-solid fa-lock"></i>
                <input type="password" name="password" id="signupPassword" placeholder="Create password" required>
                <i class="fa-solid fa-eye toggle-password" onclick="togglePassword('signupPassword', this)"></i>
            </div>
        </div>

        <button type="submit" class="btn">Create Account</button>

        <div class="switch-text">
            Already have an account?
            <a onclick="showLogin()">Sign in</a>
        </div>
    </form>

</div>

<script>
const alertBox = document.getElementById("alertBox");
const loginForm = document.getElementById("loginForm");
const signupForm = document.getElementById("signupForm");
const formSubtitle = document.getElementById("formSubtitle");

function showAlert(type, message) {
    alertBox.className = "alert " + type;
    alertBox.textContent = message;
}

function clearAlert() {
    alertBox.className = "alert";
    alertBox.textContent = "";
}

function showSignup() {
    clearAlert();
    loginForm.classList.remove("active");
    signupForm.classList.add("active");
    formSubtitle.textContent = "Create your account to start managing events.";
}

function showLogin() {
    clearAlert();
    signupForm.classList.remove("active");
    loginForm.classList.add("active");
    formSubtitle.textContent = "Welcome back! Please enter your details.";
}

function togglePassword(id, icon) {
    const input = document.getElementById(id);

    if (input.type === "password") {
        input.type = "text";
        icon.classList.remove("fa-eye");
        icon.classList.add("fa-eye-slash");
    } else {
        input.type = "password";
        icon.classList.remove("fa-eye-slash");
        icon.classList.add("fa-eye");
    }
}

loginForm.addEventListener("submit", function(e) {
    e.preventDefault();

    const formData = new FormData(loginForm);

    fetch("api_auth.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/json"
        },
        body: JSON.stringify({
            action: "login",
            email: formData.get("email"),
            password: formData.get("password")
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === "success") {
            showAlert("success", data.message);
            setTimeout(() => {
                window.location.href = "home.php";
            }, 700);
        } else {
            showAlert("error", data.message);
        }
    })
    .catch(() => {
        showAlert("error", "Login failed. Please check api_auth.php.");
    });
});

signupForm.addEventListener("submit", function(e) {
    e.preventDefault();

    const formData = new FormData(signupForm);

    fetch("api_auth.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/json"
        },
        body: JSON.stringify({
            action: "register",
            fullname: formData.get("fullname"),
            email: formData.get("email"),
            password: formData.get("password")
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === "success") {
            showAlert("success", data.message);
            setTimeout(() => {
                window.location.href = "home.php";
            }, 700);
        } else {
            showAlert("error", data.message);
        }
    })
    .catch(() => {
        showAlert("error", "Signup failed. Please check api_auth.php.");
    });
});
</script>

</body>
</html>
