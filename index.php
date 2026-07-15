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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authentication - Smart Event Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #1e293b; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
        .main-content { flex: 1; padding: 20px; background-image: url('data:image/svg+xml;utf8,<svg width="100%" height="100%" xmlns="http://www.w3.org/2000/svg"><defs><pattern id="dotPattern" x="0" y="0" width="20" height="20" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r="1.5" fill="rgba(255,255,255,0.02)" /></pattern></defs><rect width="100%" height="100%" fill="url(#dotPattern)" /></svg>'), radial-gradient(circle at 50% 50%, rgba(59, 130, 246, 0.08) 0%, rgba(0,0,0,0) 50%); background-position: center; background-repeat: repeat, no-repeat; display: flex; flex-direction: column; align-items: center; justify-content: center; width: 100%; }
        
        .auth-card { background: #ffffff; width: 100%; max-width: 420px; padding: 45px; border-radius: 24px; box-shadow: 0 10px 40px rgba(0,0,0,0.3); text-align: center; display: none; animation: fadeIn 0.4s ease forwards; }
        .auth-card.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }
        
        .logo-text { font-size: 1.8rem; font-weight: 800; color: #1e293b; margin-bottom: 5px; }
        .logo-text span { color: #2563eb; }
        .subtitle { color: #64748b; font-size: 0.95rem; margin-bottom: 25px; }
        
        .input-group { text-align: left; margin-bottom: 20px; position: relative; }
        .input-group label { display: block; font-size: 0.85rem; font-weight: 600; color: #475569; margin-bottom: 8px; }
        .input-group input { width: 100%; padding: 14px 16px 14px 45px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; font-size: 1rem; color: #1e293b; transition: all 0.3s; }
        .input-group input:focus { border-color: #2563eb; background: #ffffff; outline: none; box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1); }
        .input-group i { position: absolute; left: 16px; top: 40px; color: #94a3b8; }
        
        /* Eye Icon CSS */
        .input-group.has-toggle input { padding-right: 45px; } 
        .input-group .toggle-password { left: auto !important; right: 16px; cursor: pointer; pointer-events: auto; transition: color 0.2s ease; color: #94a3b8; }
        .input-group .toggle-password:hover { color: #1e293b !important; }

        .options { display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem; margin-bottom: 30px; }
        .options label { display: flex; align-items: center; gap: 8px; color: #64748b; cursor: pointer; }
        .options a { color: #2563eb; font-weight: 600; text-decoration: none; cursor: pointer; }
        
        .btn-primary { width: 100%; padding: 14px; background: #2563eb; color: #ffffff; border: none; border-radius: 12px; font-size: 1rem; font-weight: 600; cursor: pointer; transition: background 0.3s; margin-bottom: 25px; }
        .btn-primary:hover { background: #1d4ed8; }
        
        .footer-link { font-size: 0.9rem; color: #64748b; }
        .footer-link a { color: #2563eb; font-weight: 600; text-decoration: none; cursor: pointer; }
        .logo-icon { font-size: 2.5rem; color: #2563eb; margin-bottom: 15px; }
        
        /* API Alert Styles */
        .alert { padding: 14px; border-radius: 12px; font-size: 0.9rem; font-weight: 600; margin-bottom: 20px; text-align: left; display: flex; align-items: center; gap: 8px;}
        .alert.error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .alert.success { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
    </style>
</head>
<body>

    <main class="main-content">

        <div id="login-interface" class="auth-card active">
            <div class="logo-text">Smart<span>Event</span></div>
            <p class="subtitle">Welcome ka TATA! Please sign in.</p>
            <form id="loginForm"> 
                <div class="input-group">
                    <label>Email</label>
                    <i class="fa-solid fa-envelope"></i>
                    <input type="email" name="email" placeholder="Enter your email" required>
                </div>
                <div class="input-group has-toggle">
                    <label>Password</label>
                    <i class="fa-solid fa-lock"></i>
                    <input type="password" name="password" placeholder="••••••••" required>
                    <i class="fa-solid fa-eye toggle-password"></i>
                </div>
                <div class="options">
                    <label><input type="checkbox"> Remember me</label>
                    <a onclick="showView('forgot-interface')">Forgot password?</a>
                </div>
                <button type="submit" class="btn-primary">Sign In</button>
            </form>
            <div class="footer-link">
                Don't have an account? <a onclick="showView('register-interface')">Sign up</a>
            </div>
        </div>

        <div id="register-interface" class="auth-card">
            <div class="logo-text">Create<span>Account</span></div>
            <p class="subtitle">Join us to manage your events easily.</p>
            <form id="registerForm"> 
                <div class="input-group">
                    <label>Full Name</label>
                    <i class="fa-solid fa-user"></i>
                    <input type="text" name="fullname" placeholder="Ryan" required>
                </div>
                <div class="input-group">
                    <label>Email</label>
                    <i class="fa-solid fa-envelope"></i>
                    <input type="email" name="email" placeholder="Enter your email" required>
                </div>
                <div class="input-group has-toggle">
                    <label>Password</label>
                    <i class="fa-solid fa-lock"></i>
                    <input type="password" name="password" placeholder="Create a password" required>
                    <i class="fa-solid fa-eye toggle-password"></i>
                </div>
                <button type="submit" class="btn-primary">Sign Up</button>
            </form>
            <div class="footer-link">
                Already have an account? <a onclick="showView('login-interface')">Sign in</a>
            </div>
        </div>

        <div id="forgot-interface" class="auth-card">
            <i class="fa-solid fa-unlock-keyhole logo-icon"></i>
            <div class="logo-text">Reset<span>Password</span></div>
            <p class="subtitle">Enter your email for reset instructions.</p>
            
            <form id="forgotForm"> 
                <div class="input-group">
                    <label>Email Address</label>
                    <i class="fa-solid fa-envelope"></i>
                    <input type="email" name="email_reset" placeholder="Enter your email" required>
                </div>
                <button type="submit" class="btn-primary">Reset Password</button>
            </form>

            <div class="footer-link">
                <a onclick="showView('login-interface')"><i class="fa-solid fa-arrow-left"></i> Back to sign in</a>
            </div>
        </div>

        <div id="new-password-interface" class="auth-card">
            <i class="fa-solid fa-key logo-icon"></i>
            <div class="logo-text">Create<span>New Password</span></div>
            <p class="subtitle">Enter a new secure password.</p>
            
            <form id="newPasswordForm"> 
                <div class="input-group has-toggle">
                    <label>New Password</label>
                    <i class="fa-solid fa-lock"></i>
                    <input type="password" name="new_password" placeholder="••••••••" required>
                    <i class="fa-solid fa-eye toggle-password"></i>
                </div>
                <div class="input-group has-toggle">
                    <label>Confirm Password</label>
                    <i class="fa-solid fa-lock"></i>
                    <input type="password" name="confirm_password" placeholder="••••••••" required>
                    <i class="fa-solid fa-eye toggle-password"></i>
                </div>
                <button type="submit" class="btn-primary">Save New Password</button>
            </form>

            <div class="footer-link">
                <a href="index.php"><i class="fa-solid fa-xmark"></i> Cancel</a>
            </div>
        </div>

    </main>

    <script>
        // -----------------------------------------------------
        // 1. UI LOGIC (Toggling views, alerts, passwords)
        // -----------------------------------------------------
        function showView(targetId) {
            document.querySelectorAll('.auth-card').forEach(card => card.classList.remove('active'));
            // If the element exists, show it
            const targetCard = document.getElementById(targetId);
            if(targetCard) {
                targetCard.classList.add('active');
            }
            document.querySelectorAll('.alert').forEach(alert => alert.remove()); // Clear old errors
        }

        function showAlert(cardId, type, message) {
            const card = document.getElementById(cardId);
            if (!card) return;
            document.querySelectorAll('.alert').forEach(alert => alert.remove()); // Clear old errors

            const alertDiv = document.createElement('div');
            alertDiv.className = `alert ${type}`;
            const icon = type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check';
            alertDiv.innerHTML = `<i class="fa-solid ${icon}"></i> <span style="width: 100%;">${message}</span>`;
            card.querySelector('.subtitle').insertAdjacentElement('afterend', alertDiv);
        }

        // Show/Hide Password Toggle
        document.querySelectorAll('.toggle-password').forEach(icon => {
            icon.addEventListener('click', function() {
                const input = this.parentElement.querySelector('input');
                if (input.type === 'password') {
                    input.type = 'text';
                    this.classList.replace('fa-eye', 'fa-eye-slash');
                } else {
                    input.type = 'password';
                    this.classList.replace('fa-eye-slash', 'fa-eye');
                }
            });
        });

        // -----------------------------------------------------
        // 2. API FETCH LOGIC
        // -----------------------------------------------------
        async function handleAPI(payload, formId, successCallback) {
            const btn = document.querySelector(`#${formId} button`);
            const oldText = btn.innerText;
            btn.innerText = "Processing...";
            btn.disabled = true;
            const currentCardId = document.querySelector(`#${formId}`).closest('.auth-card').id;

            try {
                const response = await fetch('api_auth.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                
                const rawText = await response.text(); 
                
                try {
                    const result = JSON.parse(rawText);
                    if (result.status === 'success') {
                        successCallback(result);
                    } else {
                        showAlert(currentCardId, 'error', result.message);
                    }
                } catch (jsonError) {
                    console.error("Server Error Output:", rawText);
                    let cleanError = rawText.replace(/(<([^>]+)>)/gi, ""); 
                    showAlert(currentCardId, 'error', `Backend PHP Error: Check console or file. Details: ${cleanError.substring(0,60)}`);
                }

            } catch (networkError) {
                showAlert(currentCardId, 'error', "Cannot reach server. Ensure api_auth.php is in the folder.");
            } finally {
                btn.innerText = oldText;
                btn.disabled = false;
            }
        }

        // --- Event Listeners for API Forms ---
        document.getElementById('loginForm').addEventListener('submit', (e) => {
            e.preventDefault(); 
            handleAPI({ action: "login", email: e.target.email.value, password: e.target.password.value }, 'loginForm', () => window.location.href = "home.php");
        });

        document.getElementById('registerForm').addEventListener('submit', (e) => {
            e.preventDefault(); 
            handleAPI({ action: "register", fullname: e.target.fullname.value, email: e.target.email.value, password: e.target.password.value }, 'registerForm', () => window.location.href = "home.php");
        });

        // The updated Reset Password form behavior
        document.getElementById('forgotForm').addEventListener('submit', (e) => {
            e.preventDefault(); 
            handleAPI({ action: "verify_email", email: e.target.email_reset.value }, 'forgotForm', (res) => {
                showAlert('forgot-interface', 'success', res.message);
                document.getElementById('forgotForm').reset();
            });
        });

        // The new password update API logic
        document.getElementById('newPasswordForm').addEventListener('submit', (e) => {
            e.preventDefault(); 
            handleAPI({ action: "update_password", new_password: e.target.new_password.value, confirm_password: e.target.confirm_password.value }, 'newPasswordForm', (res) => {
                showView('login-interface');
                showAlert('login-interface', 'success', res.message);
                document.getElementById('newPasswordForm').reset();
            });
        });
    </script>
</body>
</html>
