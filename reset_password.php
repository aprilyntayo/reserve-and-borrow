<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['reset_verified']) || !isset($_SESSION['reset_user_id'])) {
    header("Location: forgot_password.php");
    exit();
}

$error = '';
$success = '';

if (isset($_POST['reset_password'])) {

    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    $user_id = $_SESSION['reset_user_id'];

    if (empty($new_password) || empty($confirm_password)) {
        $error = "Please fill in all fields.";

    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";

    } elseif (strlen($new_password) < 8) {
        $error = "Password must be at least 8 characters.";

    } else {

        $hashed = password_hash($new_password, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");

        if ($stmt) {
            $stmt->bind_param("si", $hashed, $user_id);

            if ($stmt->execute()) {

                // Delete OTP
                $stmt2 = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
                if ($stmt2) {
                    $stmt2->bind_param("i", $user_id);
                    $stmt2->execute();
                }

                // Clear session
                unset($_SESSION['reset_verified']);
                unset($_SESSION['reset_user_id']);
                unset($_SESSION['reset_email']);

                header("Location: login.php?reset=success");
                exit();

            } else {
                $error = "Failed to update password.";
            }

        } else {
            $error = "Database error: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password | AssetEase System</title>
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

.card {
    width: 100%;
    max-width: 450px;
    padding: 45px 40px;
    border-radius: 24px;
    background: rgba(20, 20, 20, 0.95);
    border: 2px solid #8B0000;
    box-shadow: 0 0 40px rgba(139, 0, 0, 0.3), 0 0 80px rgba(139, 0, 0, 0.1);
}

.icon-wrapper {
    width: 80px;
    height: 80px;
    margin: 0 auto 25px;
    background: linear-gradient(135deg, #DC143C, #8B0000);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 0 30px rgba(220, 20, 60, 0.4);
}

.icon-wrapper i {
    font-size: 36px;
    color: #fff;
}

h2 {
    color: #DC143C;
    margin-bottom: 10px;
    font-size: 28px;
    font-weight: 700;
    text-align: center;
    text-shadow: 0 0 20px rgba(220, 20, 60, 0.3);
}

.subtitle {
    color: #888;
    font-size: 14px;
    margin-bottom: 30px;
    text-align: center;
}

.form-group {
    margin-bottom: 22px;
    text-align: left;
}

label {
    display: block;
    color: #ccc;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 10px;
}

.input-wrapper {
    position: relative;
}

input {
    width: 100%;
    padding: 15px 50px 15px 20px;
    border-radius: 50px;
    border: 2px solid #333;
    background: rgba(30, 30, 30, 0.8);
    color: #fff;
    font-size: 15px;
    transition: all 0.3s ease;
}

input::placeholder {
    color: #666;
}

input:focus {
    outline: none;
    border-color: #DC143C;
    box-shadow: 0 0 15px rgba(220, 20, 60, 0.4);
}

.toggle-password {
    position: absolute;
    right: 18px;
    top: 50%;
    transform: translateY(-50%);
    color: #666;
    cursor: pointer;
    transition: color 0.3s ease;
}

.toggle-password:hover {
    color: #DC143C;
}

.strength-box {
    margin-top: 20px;
    padding: 18px;
    background: rgba(30, 30, 30, 0.6);
    border-radius: 16px;
    border: 1px solid #333;
}

.strength-bar {
    height: 8px;
    background: #222;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 12px;
}

.strength-fill {
    height: 100%;
    width: 0%;
    transition: all 0.4s ease;
    border-radius: 4px;
}

.strength-fill.weak {
    width: 33%;
    background: linear-gradient(90deg, #DC143C, #FF4444);
}

.strength-fill.medium {
    width: 66%;
    background: linear-gradient(90deg, #FFA500, #FFD700);
}

.strength-fill.strong {
    width: 100%;
    background: linear-gradient(90deg, #00C853, #69F0AE);
}

.strength-text {
    font-size: 13px;
    color: #888;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.strength-text i {
    font-size: 14px;
}

.strength-text.weak {
    color: #FF6B6B;
}

.strength-text.medium {
    color: #FFD700;
}

.strength-text.strong {
    color: #69F0AE;
}

.requirements {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #333;
}

.requirement {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 12px;
    color: #666;
    margin-bottom: 8px;
    transition: color 0.3s ease;
}

.requirement i {
    font-size: 12px;
    width: 16px;
}

.requirement.met {
    color: #69F0AE;
}

.requirement.met i {
    color: #69F0AE;
}

button {
    width: 100%;
    padding: 16px;
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
    margin-top: 25px;
}

button:hover:not(:disabled) {
    background: linear-gradient(135deg, #FF1744, #B71C1C);
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(220, 20, 60, 0.4);
}

button:disabled {
    background: #333;
    color: #666;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
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

.footer-link {
    text-align: center;
    color: #888;
    margin-top: 25px;
    font-size: 14px;
}

.footer-link a {
    color: #DC143C;
    text-decoration: none;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.footer-link a:hover {
    color: #FF1744;
    text-decoration: underline;
}
</style>
</head>
<body>
<div class="card">
    <div class="icon-wrapper">
        <i class="fa-solid fa-key"></i>
    </div>
    
    <h2>Set New Password</h2>
    <p class="subtitle">Create a strong password for your account</p>
    
    <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>
    
    <form method="POST">
        <div class="form-group">
            <label>New Password</label>
            <div class="input-wrapper">
                <input type="password" id="password" name="new_password" placeholder="Enter new password" required>
                <i class="fa-solid fa-eye toggle-password" onclick="togglePassword('password', this)"></i>
            </div>
        </div>
        
        <div class="strength-box">
            <div class="strength-bar">
                <div class="strength-fill" id="strengthBar"></div>
            </div>
            <div class="strength-text" id="strengthText">
                <i class="fa-solid fa-shield"></i> Password Strength: <span id="strengthLabel">Enter password</span>
            </div>
            
            <div class="requirements">
                <div class="requirement" id="req-length">
                    <i class="fa-solid fa-circle"></i> At least 8 characters
                </div>
                <div class="requirement" id="req-upper">
                    <i class="fa-solid fa-circle"></i> One uppercase letter
                </div>
                <div class="requirement" id="req-lower">
                    <i class="fa-solid fa-circle"></i> One lowercase letter
                </div>
                <div class="requirement" id="req-number">
                    <i class="fa-solid fa-circle"></i> One number
                </div>
                <div class="requirement" id="req-special">
                    <i class="fa-solid fa-circle"></i> One special character
                </div>
            </div>
        </div>
        
        <div class="form-group">
            <label>Confirm Password</label>
            <div class="input-wrapper">
                <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm new password" required>
                <i class="fa-solid fa-eye toggle-password" onclick="togglePassword('confirm_password', this)"></i>
            </div>
        </div>
        
        <button type="submit" name="reset_password" id="submitBtn" disabled>
            <i class="fa-solid fa-lock"></i> Reset Password
        </button>
    </form>
    
    <div class="footer-link">
        <a href="login.php"><i class="fa-solid fa-arrow-left"></i> Back to Login</a>
    </div>
</div>

<script>
const passwordInput = document.getElementById('password');
const confirmInput = document.getElementById('confirm_password');
const strengthBar = document.getElementById('strengthBar');
const strengthText = document.getElementById('strengthText');
const strengthLabel = document.getElementById('strengthLabel');
const submitBtn = document.getElementById('submitBtn');

const requirements = {
    length: document.getElementById('req-length'),
    upper: document.getElementById('req-upper'),
    lower: document.getElementById('req-lower'),
    number: document.getElementById('req-number'),
    special: document.getElementById('req-special')
};

function togglePassword(inputId, icon) {
    const input = document.getElementById(inputId);
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

function updateRequirement(element, met) {
    if (met) {
        element.classList.add('met');
        element.querySelector('i').classList.remove('fa-circle');
        element.querySelector('i').classList.add('fa-circle-check');
    } else {
        element.classList.remove('met');
        element.querySelector('i').classList.remove('fa-circle-check');
        element.querySelector('i').classList.add('fa-circle');
    }
}

function checkPasswordStrength() {
    const val = passwordInput.value;
    let score = 0;
    
    const hasLength = val.length >= 8;
    const hasUpper = /[A-Z]/.test(val);
    const hasLower = /[a-z]/.test(val);
    const hasNumber = /[0-9]/.test(val);
    const hasSpecial = /[^A-Za-z0-9]/.test(val);
    
    updateRequirement(requirements.length, hasLength);
    updateRequirement(requirements.upper, hasUpper);
    updateRequirement(requirements.lower, hasLower);
    updateRequirement(requirements.number, hasNumber);
    updateRequirement(requirements.special, hasSpecial);
    
    if (hasLength) score++;
    if (hasUpper) score++;
    if (hasLower) score++;
    if (hasNumber) score++;
    if (hasSpecial) score++;
    
    strengthBar.className = 'strength-fill';
    strengthText.className = 'strength-text';
    
    if (val.length === 0) {
        strengthLabel.textContent = 'Enter password';
        submitBtn.disabled = true;
    } else if (score <= 2) {
        strengthBar.classList.add('weak');
        strengthText.classList.add('weak');
        strengthLabel.textContent = 'Weak';
        submitBtn.disabled = true;
    } else if (score <= 4) {
        strengthBar.classList.add('medium');
        strengthText.classList.add('medium');
        strengthLabel.textContent = 'Medium';
        submitBtn.disabled = true;
    } else {
        strengthBar.classList.add('strong');
        strengthText.classList.add('strong');
        strengthLabel.textContent = 'Strong';
        checkPasswordsMatch();
    }
}

function checkPasswordsMatch() {
    if (passwordInput.value === confirmInput.value && passwordInput.value.length > 0) {
        const val = passwordInput.value;
        const score = (val.length >= 8 ? 1 : 0) + (/[A-Z]/.test(val) ? 1 : 0) + 
                      (/[a-z]/.test(val) ? 1 : 0) + (/[0-9]/.test(val) ? 1 : 0) + 
                      (/[^A-Za-z0-9]/.test(val) ? 1 : 0);
        submitBtn.disabled = score < 5;
    } else {
        submitBtn.disabled = true;
    }
}

passwordInput.addEventListener('input', checkPasswordStrength);
confirmInput.addEventListener('input', checkPasswordsMatch);
</script>
</body>
</html>
