<?php
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';
require_once 'config.php';

$error = '';
$success = '';

/* ======================
FORGOT PASSWORD (SEND OTP)
====================== */
if (isset($_POST['send_otp'])) {

    $email = trim($_POST['email']);

    if ($email === '') {
        $error = "Please enter your email address.";
    } else {

        // Check if email exists
        $stmt = $conn->prepare("SELECT id, uname FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {

            // Generate OTP
            $otp = random_int(100000, 999999);
            $otp_hash = password_hash($otp, PASSWORD_DEFAULT);
            $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

            // Save OTP in database
            $stmt2 = $conn->prepare("
                INSERT INTO password_resets (user_id, otp_hash, expires_at)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE otp_hash=?, expires_at=?
            ");
            $stmt2->bind_param("issss", $user['id'], $otp_hash, $expires, $otp_hash, $expires);
            $stmt2->execute();

            // Save session
            $_SESSION['reset_user_id'] = $user['id'];
            $_SESSION['reset_email'] = $email;

            // Send Email
            $mail = new PHPMailer(true);

            try {
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'aprilyntayo@gmail.com'; // Your email
                $mail->Password   = 'wwes zxfx ghgl ptsm';    // Your app password
                $mail->SMTPSecure = 'tls';
                $mail->Port       = 587;

                $mail->setFrom('aprilyntayo10@gmail.com', 'reserve and borrow System');
                $mail->addAddress($email, $user['uname']);

                $mail->isHTML(true);
                $mail->Subject = 'Your OTP Code - reserve and borrow System';
                $mail->Body = "
                    <div style='font-family: Arial, sans-serif; max-width: 500px; margin: 0 auto; padding: 30px; background: #1a1a1a; border-radius: 15px; border: 2px solid #8B0000;'>
                        <h2 style='color: #DC143C; margin-bottom: 20px;'>Password Reset Request</h2>
                        <p style='color: #ccc;'>Hello <strong>{$user['uname']}</strong>,</p>
                        <p style='color: #ccc;'>Your OTP code is:</p>
                        <div style='background: linear-gradient(135deg, #DC143C, #8B0000); padding: 20px; border-radius: 10px; text-align: center; margin: 20px 0;'>
                            <h1 style='color: #fff; margin: 0; letter-spacing: 8px; font-size: 36px;'>$otp</h1>
                        </div>
                        <p style='color: #888; font-size: 14px;'>This code will expire in 10 minutes.</p>
                        <p style='color: #666; font-size: 12px; margin-top: 30px;'>If you did not request this, please ignore this email.</p>
                    </div>
                ";

                $mail->send();

                // Redirect to OTP page
                header("Location: verify_otp.php");
                exit();

            } catch (Exception $e) {
                $error = "Mailer Error: " . $mail->ErrorInfo;
            }

        } else {
            $error = "Email not found.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password | reserve and borrow System</title>
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
    margin-bottom: 30px;
}

.form-group {
    margin-bottom: 25px;
    text-align: left;
}

label {
    display: block;
    color: #ccc;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 10px;
}

input {
    width: 100%;
    padding: 15px 20px;
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
        <i class="fa-solid fa-lock"></i>
    </div>
    
    <h2>Forgot Password</h2>
    <p>Enter your email address and we'll send you an OTP to reset your password</p>

    <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" placeholder="Enter your email address" required autofocus>
        </div>
        <button type="submit" name="send_otp">
            <i class="fa-solid fa-paper-plane"></i> Send OTP
        </button>
    </form>

    <div class="footer-link">
        <a href="login.php"><i class="fa-solid fa-arrow-left"></i> Back to Login</a>
    </div>
</div>
</body>
</html>
