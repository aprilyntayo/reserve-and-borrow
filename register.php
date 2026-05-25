<?php
/**
 * Registration Page
 * * Handles user registration with username, email, password.
 * Uses the same red and black gradient theme as login.php
 */
session_start();
require_once 'config.php';

// Handle registration form submission (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    
    // Set JSON header FIRST before any output
    header('Content-Type: application/json');
    
    $response = [
        'success' => false,
        'error'   => ''
    ];
    
    // Check database connection
    if (!isset($db_connected) || !$db_connected) {
        $response['error'] = 'Database connection failed. Please try again later.';
        echo json_encode($response);
        exit;
    }
    
    // Get and sanitize form data
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';
    
    // ===== VALIDATION =====
    
    // Check all required fields
    if ($username === '' || $email === '' || $password === '' || $confirm === '') {
        $response['error'] = 'All fields are required.';
        echo json_encode($response);
        exit;
    }
    
    // Validate username length
    if (strlen($username) < 3) {
        $response['error'] = 'Username must be at least 3 characters.';
        echo json_encode($response);
        exit;
    }
    
    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $response['error'] = 'Invalid email format.';
        echo json_encode($response);
        exit;
    }
    
    // Check password match
    if ($password !== $confirm) {
        $response['error'] = 'Passwords do not match.';
        echo json_encode($response);
        exit;
    }
    
    // Validate password strength
    if (
        strlen($password) < 8 ||
        !preg_match('/[A-Z]/', $password) ||
        !preg_match('/[a-z]/', $password) ||
        !preg_match('/[0-9]/', $password)
    ) {
        $response['error'] = 'Password must be at least 8 characters with uppercase, lowercase, and numbers.';
        echo json_encode($response);
        exit;
    }
    
    try {
        // ===== CHECK IF USERNAME EXISTS =====
        $checkUser = $conn->prepare("SELECT id FROM users WHERE uname = ?");
        if ($checkUser === false) {
            $response['error'] = 'Database error. Please try again.';
            echo json_encode($response);
            exit;
        }
        
        $checkUser->bind_param("s", $username);
        $checkUser->execute();
        $result = $checkUser->get_result();
        
        if ($result->num_rows > 0) {
            $response['error'] = 'Username already taken. Please choose another.';
            echo json_encode($response);
            exit;
        }
        $checkUser->close();
        
        // ===== CHECK IF EMAIL EXISTS =====
        $checkEmail = $conn->prepare("SELECT id FROM users WHERE email = ?");
        if ($checkEmail === false) {
            $response['error'] = 'Database error. Please try again.';
            echo json_encode($response);
            exit;
        }
        
        $checkEmail->bind_param("s", $email);
        $checkEmail->execute();
        $result = $checkEmail->get_result();
        
        if ($result->num_rows > 0) {
            $response['error'] = 'Email already registered. Please use a different email.';
            echo json_encode($response);
            exit;
        }
        $checkEmail->close();
        
        // ===== INSERT NEW USER =====
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("INSERT INTO users (uname, email, password) VALUES (?, ?, ?)");
        
        if ($stmt === false) {
            $response['error'] = 'Database error. Please try again.';
            echo json_encode($response);
            exit;
        }
        
        $stmt->bind_param("sss", $username, $email, $hashedPassword);
        
        if ($stmt->execute()) {
            $response['success'] = true;
        } else {
            $response['error'] = 'Error creating account. Please try again.';
        }
        
        $stmt->close();
        
    } catch (Exception $e) {
        $response['error'] = 'An unexpected error occurred. Please try again.';
    }
    
    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Register | CRM System</title>
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

.register-container {
    width: 100%;
    max-width: 480px;
}

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 15px;
    color: #DC143C;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s ease;
}

.back-link:hover {
    color: #FF1744;
}

.register-card {
    width: 100%;
    padding: 45px 40px;
    border-radius: 24px;
    background: rgba(20, 20, 20, 0.95);
    border: 2px solid #8B0000;
    box-shadow: 0 0 40px rgba(139, 0, 0, 0.3), 0 0 80px rgba(139, 0, 0, 0.1);
    text-align: center;
}

.register-header h1 {
    color: #DC143C;
    font-size: 32px;
    margin-bottom: 8px;
    font-weight: 700;
    text-shadow: 0 0 20px rgba(220, 20, 60, 0.3);
}

.register-header p {
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

.input-wrapper {
    position: relative;
}

.form-input {
    width: 100%;
    padding: 14px 20px;
    padding-right: 50px;
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

/* Password Strength Indicator */
.password-strength {
    margin-top: 12px;
    padding: 15px;
    background: rgba(30, 30, 30, 0.6);
    border-radius: 14px;
    border: 1px solid #333;
}

.strength-text {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    color: #888;
    margin-bottom: 8px;
}

.strength-text span:last-child {
    font-weight: 600;
}

.strength-bar {
    height: 8px;
    background: #222;
    border-radius: 6px;
    overflow: hidden;
}

.strength-fill {
    height: 100%;
    width: 0%;
    transition: all 0.4s ease;
    border-radius: 6px;
}

.strength-fill.weak {
    width: 33%;
    background: linear-gradient(90deg, #DC143C, #8B0000);
}

.strength-fill.medium {
    width: 66%;
    background: linear-gradient(90deg, #F0AD4E, #D98B00);
}

.strength-fill.strong {
    width: 100%;
    background: linear-gradient(90deg, #5CB85C, #3D8B3D);
}

.register-btn {
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
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.register-btn:hover:not(:disabled) {
    background: linear-gradient(135deg, #FF1744, #B71C1C);
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(220, 20, 60, 0.4);
}

.register-btn:disabled {
    background: #333;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}

/* Spinner */
.spinner {
    display: none;
    width: 18px;
    height: 18px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-top: 2px solid white;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

.register-btn.loading .spinner {
    display: inline-block;
}

@keyframes spin {
    to { transform: rotate(360deg); }
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
    animation: slideDown 0.3s ease;
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
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

/* Google icon */
.google-icon {
    width: 20px;
    height: 20px;
}

/* Responsive */
@media (max-width: 480px) {
    .register-card {
        padding: 30px 25px;
    }
    .register-header h1 {
        font-size: 26px;
    }
}
</style>
</head>

<body>
<div class="register-container">
    <a href="index.php" class="back-link">
        <i class="fas fa-arrow-left"></i> Back to Home
    </a>

    <div class="register-card">
        <div class="register-header">
            <h1><i class="fas fa-user-plus"></i> Create Account</h1>
            <p>Join us today! Fill in your details to get started</p>
        </div>

        <div id="alertContainer"></div>

        <form method="POST" autocomplete="off" id="registerForm">
            
            <div class="form-group">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-input" required minlength="3" placeholder="Choose a username">
            </div>

            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-input" required placeholder="Enter your email">
            </div>

            <div class="form-group">
                <label class="form-label">Password</label>
                <div class="input-wrapper">
                    <input type="password" id="password" name="password" class="form-input" required placeholder="Create a strong password">
                    <i class="fa-solid fa-eye toggle-password" onclick="togglePassword('password', this)"></i>
                </div>

                <div class="password-strength">
                    <div class="strength-text">
                        <span>Password Strength</span>
                        <span id="strengthText">Weak</span>
                    </div>
                    <div class="strength-bar">
                        <div class="strength-fill" id="strengthBar"></div>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Confirm Password</label>
                <div class="input-wrapper">
                    <input type="password" id="confirm_password" name="confirm_password" class="form-input" required placeholder="Confirm your password">
                    <i class="fa-solid fa-eye toggle-password" onclick="togglePassword('confirm_password', this)"></i>
                </div>
            </div>

            <input type="hidden" name="register" value="1">
            
            <button type="submit" id="registerBtn" class="register-btn" disabled>
                <span class="spinner"></span>
                <span class="btn-text">Create Account</span>
            </button>
        </form>

        <div class="divider"><span>OR</span></div>

        <a href="google-auth.php" class="social-btn google">
            <svg class="google-icon" viewBox="0 0 24 24">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
            </svg>
            Sign up with Google
        </a>

        <a href="facebook-login.php" class="social-btn facebook">
            <i class="fa-brands fa-facebook-f"></i> Sign up with Facebook
        </a>

        <div class="footer-link">
            Already have an account? <a href="login.php">Sign in here</a>
        </div>
    </div>
</div>

<script>
// DOM Elements
const passwordInput = document.getElementById('password');
const confirmInput = document.getElementById('confirm_password');
const registerBtn = document.getElementById('registerBtn');
const strengthBar = document.getElementById('strengthBar');
const strengthText = document.getElementById('strengthText');
const registerForm = document.getElementById('registerForm');
const alertContainer = document.getElementById('alertContainer');

/**
 * Toggle password visibility
 */
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

/**
 * Display alert message
 */
function showAlert(message, type) {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type}`;
    alertDiv.innerHTML = message;
    alertContainer.innerHTML = '';
    alertContainer.appendChild(alertDiv);

    // Redirect on success
    if (type === 'success') {
        setTimeout(() => {
            window.location.href = 'login.php?registered=success';
        }, 2000);
    }
}

/**
 * Update password strength indicator
 */
function updatePasswordStrength() {
    const password = passwordInput.value;

    const rules = {
        length: password.length >= 8,
        upper: /[A-Z]/.test(password),
        lower: /[a-z]/.test(password),
        number: /[0-9]/.test(password)
    };

    let score = 0;
    for (let r in rules) {
        if (rules[r]) score++;
    }

    strengthBar.className = 'strength-fill';
    
    if (score <= 2) {
        strengthBar.classList.add('weak');
        strengthText.textContent = 'Weak';
        strengthText.style.color = '#DC143C';
    } else if (score <= 3) {
        strengthBar.classList.add('medium');
        strengthText.textContent = 'Medium';
        strengthText.style.color = '#F0AD4E';
    } else {
        strengthBar.classList.add('strong');
        strengthText.textContent = 'Strong';
        strengthText.style.color = '#5CB85C';
    }

    validateForm();
}

/**
 * Validate form and enable/disable submit button
 */
function validateForm() {
    const password = passwordInput.value;
    const confirm = confirmInput.value;
    
    const validPassword =
        password.length >= 8 &&
        /[A-Z]/.test(password) &&
        /[a-z]/.test(password) &&
        /[0-9]/.test(password);
    
    const passwordsMatch = password === confirm && confirm.length > 0;

    registerBtn.disabled = !(validPassword && passwordsMatch);
}

/**
 * Handle form submission via AJAX
 */
registerForm.addEventListener('submit', function(e) {
    e.preventDefault();
    
    registerBtn.classList.add('loading');
    registerBtn.disabled = true;

    const formData = new FormData(registerForm);

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        // Check if response is ok
        if (!response.ok) {
            throw new Error('Server returned ' + response.status);
        }
        return response.text();
    })
    .then(text => {
        // Try to parse as JSON
        try {
            return JSON.parse(text);
        } catch (e) {
            // If not valid JSON, show a more helpful error
            console.error('Invalid JSON response:', text.substring(0, 200));
            throw new Error('Server returned invalid response');
        }
    })
    .then(data => {
        registerBtn.classList.remove('loading');
        
        if (data.success) {
            showAlert('<i class="fas fa-check-circle"></i> Account created successfully! Redirecting to login...', 'success');
        } else {
            showAlert('<i class="fas fa-exclamation-circle"></i> ' + (data.error || 'An error occurred. Please try again.'), 'error');
            validateForm();
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        registerBtn.classList.remove('loading');
        showAlert('<i class="fas fa-exclamation-circle"></i> ' + (error.message || 'Network error. Please try again.'), 'error');
        validateForm();
    });
});

// Event listeners
passwordInput.addEventListener('input', updatePasswordStrength);
confirmInput.addEventListener('input', validateForm);
validateForm();
</script>

</body>
</html>