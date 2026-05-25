<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['reset_user_id'])) {
    header("Location: forgot_password.php");
    exit();
}

$error = '';
$email = isset($_SESSION['reset_email']) ? $_SESSION['reset_email'] : '';

if (isset($_POST['verify_otp'])) {

    // Combine OTP digits
    $otp = '';
    for ($i = 1; $i <= 6; $i++) {
        $otp .= isset($_POST['otp' . $i]) ? $_POST['otp' . $i] : '';
    }
    
    $user_id = $_SESSION['reset_user_id'];

    if (strlen($otp) !== 6) {
        $error = "Please enter all 6 digits.";
    } else {

        $stmt = $conn->prepare("
            SELECT otp_hash, expires_at 
            FROM password_resets 
            WHERE user_id = ? AND expires_at > NOW()
            LIMIT 1
        ");

        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {

            $row = $result->fetch_assoc();

            if (password_verify($otp, $row['otp_hash'])) {

                $_SESSION['reset_verified'] = true;

                // Delete OTP after success
                $del = $conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
                $del->bind_param("i", $user_id);
                $del->execute();

                header("Location: reset_password.php");
                exit();

            } else {
                $error = "Invalid OTP. Please try again.";
            }

        } else {
            $error = "OTP expired or not found. Please request again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Verify OTP | AssetEase System</title>
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

.card {
    width: 100%;
    max-width: 450px;
    padding: 45px 40px;
    border-radius: 24px;
    background: rgba(20, 20, 20, 0.95);
    border: 2px solid #8B0000;
    box-shadow: 0 0 40px rgba(139, 0, 0, 0.3), 0 0 80px rgba(139, 0, 0, 0.1);
    text-align: center;
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
    text-shadow: 0 0 20px rgba(220, 20, 60, 0.3);
}

p {
    color: #888;
    font-size: 14px;
    margin-bottom: 10px;
}

.email-display {
    color: #DC143C;
    font-weight: 600;
    margin-bottom: 30px;
    font-size: 15px;
}

.otp-inputs {
    display: flex;
    gap: 10px;
    justify-content: center;
    margin-bottom: 30px;
}

.otp-inputs input {
    width: 50px;
    height: 60px;
    border-radius: 12px;
    border: 2px solid #333;
    background: rgba(30, 30, 30, 0.8);
    color: #fff;
    font-size: 24px;
    font-weight: 700;
    text-align: center;
    transition: all 0.3s ease;
}

.otp-inputs input:focus {
    outline: none;
    border-color: #DC143C;
    box-shadow: 0 0 15px rgba(220, 20, 60, 0.4);
}

.otp-inputs input::placeholder {
    color: #444;
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
}

button:hover {
    background: linear-gradient(135deg, #FF1744, #B71C1C);
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(220, 20, 60, 0.4);
}

.alert {
    padding: 14px 18px;
    border-radius: 12px;
    margin-bottom: 20px;
    font-size: 14px;
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

.resend-link {
    margin-top: 20px;
    color: #666;
    font-size: 13px;
}

.resend-link a {
    color: #DC143C;
    text-decoration: none;
    font-weight: 600;
}

.resend-link a:hover {
    text-decoration: underline;
}
</style>
</head>

<body>

<div class="card">
    <div class="icon-wrapper">
        <i class="fa-solid fa-mobile-screen"></i>
    </div>
    
    <h2>Verify OTP</h2>
    <p>Enter the 6-digit code sent to</p>
    <div class="email-display"><?= htmlspecialchars($email) ?></div>

    <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="otp-inputs">
            <input type="text" name="otp1" maxlength="1" required autofocus>
            <input type="text" name="otp2" maxlength="1" required>
            <input type="text" name="otp3" maxlength="1" required>
            <input type="text" name="otp4" maxlength="1" required>
            <input type="text" name="otp5" maxlength="1" required>
            <input type="text" name="otp6" maxlength="1" required>
        </div>
        <button type="submit" name="verify_otp">
            <i class="fa-solid fa-check"></i> Verify OTP
        </button>
    </form>

    <div class="resend-link">
        Didn't receive the code? <a href="forgot_password.php">Resend OTP</a>
    </div>

    <div class="footer-link">
        <a href="login.php"><i class="fa-solid fa-arrow-left"></i> Back to Login</a>
    </div>
</div>

<script>
// Auto-focus next input
const inputs = document.querySelectorAll('.otp-inputs input');

inputs.forEach((input, index) => {
    input.addEventListener('input', (e) => {
        if (e.target.value.length === 1 && index < inputs.length - 1) {
            inputs[index + 1].focus();
        }
    });
    
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace' && e.target.value === '' && index > 0) {
            inputs[index - 1].focus();
        }
    });
    
    // Only allow numbers
    input.addEventListener('input', (e) => {
        e.target.value = e.target.value.replace(/[^0-9]/g, '');
    });
});

// Paste handling
inputs[0].addEventListener('paste', (e) => {
    e.preventDefault();
    const pastedData = e.clipboardData.getData('text').replace(/[^0-9]/g, '').slice(0, 6);
    pastedData.split('').forEach((char, i) => {
        if (inputs[i]) inputs[i].value = char;
    });
    if (inputs[pastedData.length - 1]) inputs[pastedData.length - 1].focus();
});
</script>

</body>
</html>
