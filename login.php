<?php
session_start();
require_once 'config.php';

$error = '';
$success = '';

// Check for success messages
if (isset($_GET['reset']) && $_GET['reset'] === 'success') {
    $success = "Password reset successfully! Please login with your new password.";
}

if (isset($_GET['registered']) && $_GET['registered'] === 'success') {
    $success = "Registration successful! Please login to your account.";
}

/* ======================
LOGIN HANDLER WITH ROLE CHECK
====================== */
if (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    // Select user with role and signatory information
    $stmt = $conn->prepare(
        "SELECT id, uname, password, role, email, signatory_title FROM users WHERE uname = ?"
    );
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        if (password_verify($password, $user['password'])) {
            $_SESSION['user_id']         = $user['id'];
            $_SESSION['uname']           = $user['uname'];
            $_SESSION['role']            = $user['role'];
            $_SESSION['email']           = $user['email'];
            $_SESSION['signatory_title'] = $user['signatory_title'];

            // Redirect based on role and signatory_title
            if ($user['role'] === 'super_admin') {
                // Check if user is a signatory
                if (!empty($user['signatory_title'])) {
                    if ($user['signatory_title'] === 'Dept_Head') {
                        header("Location: programhead_dashboard.php");
                    } elseif ($user['signatory_title'] === 'VP_Admin') {
                        header("Location: vp_dashboard.php");
                    } else {
                        header("Location: super_admin_dashboard.php");
                    }
                } else {
                    header("Location: super_admin_dashboard.php");
                }
            } elseif ($user['role'] === 'admin') {
                header("Location: admin_dashboard.php");
            } else {
                header("Location: dashboard.php");
            }
            exit;
        } else {
            $error = "Invalid username or password.";
        }
    } else {
        $error = "Invalid username or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Login | Reserve & Borrow</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<style>
/* ======= RED & BLACK GRADIENT THEME ======= */
* {
    box-sizing: border-box;
    font-family: 'Poppins', sans-serif;
    margin: 0;
    padding: 0;
}

body {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #0a0a0a 0%, #1a0a0a 25%, #2d0a0a 50%, #1a0a0a 75%, #0a0a0a 100%);
    padding: 20px;
}

.login-card {
    width: 100%;
    max-width: 440px;
    padding: 45px 40px;
    border-radius: 24px;
    background: rgba(20, 20, 20, 0.95);
    border: 2px solid #8B0000;
    box-shadow: 0 0 40px rgba(139, 0, 0, 0.3), 0 0 80px rgba(139, 0, 0, 0.1);
    text-align: center;
}

.login-header h1 {
    color: #DC143C;
    font-size: 32px;
    margin-bottom: 8px;
    font-weight: 700;
    text-shadow: 0 0 20px rgba(220, 20, 60, 0.3);
}

.login-header p {
    color: #888;
    font-size: 14px;
    margin-bottom: 30px;
}

.form-group {
    margin-bottom: 22px;
    text-align: left;
}

.form-label {
    display: block;
    color: #ccc;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 8px;
}

.form-input {
    width: 100%;
    padding: 14px 20px;
    border-radius: 50px;
    border: 2px solid #333;
    background: rgba(30, 30, 30, 0.8);
    color: #fff;
    font-size: 15px;
    transition: all 0.3s ease;
}

.form-input::placeholder {
    color: #666;
}

.form-input:focus {
    outline: none;
    border-color: #DC143C;
    box-shadow: 0 0 15px rgba(220, 20, 60, 0.4);
}

.login-btn {
    width: 100%;
    padding: 15px;
    border: none;
    border-radius: 50px;
    background: linear-gradient(135deg, #DC143C, #8B0000);
    color: #fff;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.login-btn:hover {
    background: linear-gradient(135deg, #FF1744, #B71C1C);
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(220, 20, 60, 0.4);
}

.divider {
    margin: 25px 0;
    color: #555;
    position: relative;
    display: flex;
    align-items: center;
    gap: 15px;
}

.divider::before,
.divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: linear-gradient(to right, transparent, #333, transparent);
}

.social-btn {
    width: 100%;
    padding: 14px;
    border-radius: 50px;
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    font-weight: 600;
    margin-bottom: 12px;
    transition: all 0.3s ease;
    font-size: 14px;
}

.social-btn.google {
    background: rgba(40, 40, 40, 0.8);
    color: #fff;
    border: 2px solid #444;
}

.social-btn.google:hover {
    border-color: #DC143C;
    background: rgba(60, 60, 60, 0.8);
}

.social-btn.facebook {
    background: linear-gradient(135deg, #1877F2, #0D47A1);
    color: white;
    border: none;
}

.social-btn.facebook:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 20px rgba(24, 119, 242, 0.3);
}

.footer-link {
    margin-top: 18px;
    color: #888;
    font-size: 14px;
}

.footer-link a {
    color: #DC143C;
    cursor: pointer;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s ease;
}

.footer-link a:hover {
    color: #FF1744;
    text-decoration: underline;
}

.alert {
    padding: 14px 18px;
    border-radius: 12px;
    margin-bottom: 20px;
    font-size: 14px;
    text-align: left;
}

.alert-error {
    background: rgba(139, 0, 0, 0.2);
    color: #FF6B6B;
    border: 1px solid rgba(220, 20, 60, 0.3);
}

.alert-success {
    background: rgba(0, 100, 0, 0.2);
    color: #90EE90;
    border: 1px solid rgba(0, 128, 0, 0.3);
}

.google-icon {
    width: 20px;
    height: 20px;
}
</style>
</head>

<body>
<div class="login-card">
    <div class="login-header">
        <h1>Reserve & Borrow</h1>
        <p>Welcome back! Please login to your account</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label class="form-label">Username</label>
            <input type="text" name="username" class="form-input" placeholder="Enter your username" required autofocus>
        </div>

        <div class="form-group">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-input" placeholder="Enter your password" required>
        </div>

        <button type="submit" name="login" class="login-btn">Sign In</button>

        <div class="divider"><span>OR</span></div>

        <a href="google-auth.php" class="social-btn google">
            <svg class="google-icon" viewBox="0 0 24 24">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
            </svg>
            Continue with Google
        </a>

        <a href="facebook-login.php" class="social-btn facebook">
            <i class="fa-brands fa-facebook-f"></i> Continue with Facebook
        </a>

        <div class="footer-link">
            <a href="forgot_password.php">Forgot your password?</a>
        </div>
    </form>

    <div class="footer-link">
        Don't have an account? <a href="register.php">Register here</a>
    </div>
</div>

</body>
</html>