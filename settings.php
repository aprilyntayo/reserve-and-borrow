<?php
        /**
         * Settings Page — AssetEase
         */
        session_start();
        require_once 'config.php';
        require_once 'vendor/autoload.php';

        use PHPMailer\PHPMailer\PHPMailer;
        use PHPMailer\PHPMailer\Exception;

        if (!isset($_SESSION['user_id'])) {
            header("Location: login.php");
            exit;
        }

        $userId    = $_SESSION['user_id'];

        // Always fetch current user info fresh from database
        $stmt = $conn->prepare("SELECT uname, email FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user   = $result->fetch_assoc();
        $userName  = $user['uname']  ?? ($_SESSION['uname']  ?? $_SESSION['user_name'] ?? 'User');
        $userEmail = $user['email'] ?? ($_SESSION['email']  ?? $_SESSION['email']     ?? '');

        // Unread notifications count
        $stmt = $conn->prepare("SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $notifRow         = $stmt->get_result()->fetch_assoc();
        $unread_count     = $notifRow['unread'] ?? 0;

        // Recent notifications for dropdown
        $recentStmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
        $recentStmt->bind_param("i", $userId);
        $recentStmt->execute();
        $notifications = $recentStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $success             = '';
        $error               = '';
        $passwordChangeMsg   = '';
        $passwordChangeError = '';
        $passwordChanged     = false;

        $isPasswordChangeAjax = $_SERVER['REQUEST_METHOD'] === 'POST'
            && ($_POST['action'] ?? '') === 'change_password'
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

        // ── Handle POST ──────────────────────────────────────────────────────────────
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

            if ($_POST['action'] === 'send_otp') {
                $otp        = random_int(100000, 999999);
                $otp_hash   = md5((string)$otp);
                $otp_expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));

                $stmt = $conn->prepare("INSERT INTO password_resets (user_id, otp_hash, expires_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE otp_hash = ?, expires_at = ?");
                if ($stmt) {
                    $stmt->bind_param("issss", $userId, $otp_hash, $otp_expires, $otp_hash, $otp_expires);
                    $db_ok = $stmt->execute();
                } else { $db_ok = false; }

                $_SESSION['otp_hash']    = $otp_hash;
                $_SESSION['otp_expires'] = $otp_expires;

                if ($db_ok || isset($_SESSION['otp_hash'])) {
                    $mail = new PHPMailer(true);
                    try {
                        $mail->isSMTP();
                        $mail->Host       = 'smtp.gmail.com';
                        $mail->SMTPAuth   = true;
                        $mail->Username   = 'aprilyntayo@gmail.com';
                        $mail->Password   = 'wwes zxfx ghgl ptsm';
                        $mail->SMTPSecure = 'tls';
                        $mail->Port       = 587;
                        $mail->setFrom('aprilyntayo10@gmail.com', 'AssetEase System');
                        $mail->addAddress($userEmail, $userName);
                        $mail->isHTML(true);
                        $mail->Subject = 'Password Change Verification Code — AssetEase';
                        $mail->Body    = "
        <html><body style='font-family:Arial,sans-serif;background:#f4f4f4;padding:20px;'>
        <div style='max-width:600px;margin:auto;background:#fff;border-radius:8px;padding:30px;box-shadow:0 2px 10px rgba(0,0,0,.1);'>
        <h2 style='color:#333;text-align:center;margin-bottom:20px;'>Password Reset Verification</h2>
        <p style='color:#666;font-size:16px;line-height:1.6;'>Hello <strong>" . htmlspecialchars($userName) . "</strong>,</p>
        <p style='color:#666;font-size:16px;line-height:1.6;margin-bottom:30px;'>Use the verification code below to proceed with your password change.</p>
        <div style='background:#f0f0f0;border:2px solid #951c2c;border-radius:8px;padding:20px;text-align:center;margin-bottom:30px;'>
            <p style='color:#666;font-size:14px;margin:0 0 10px 0;'>Your Verification Code:</p>
            <p style='color:#951c2c;font-size:36px;font-weight:bold;letter-spacing:5px;margin:0;'>$otp</p>
        </div>
        <p style='color:#666;font-size:14px;'>⏱️ <strong>Note:</strong> This code expires in 10 minutes.</p>
        <hr style='border:none;border-top:1px solid #e0e0e0;margin:30px 0;'>
        <p style='color:#999;font-size:12px;text-align:center;'>Automated message from AssetEase. Please do not reply.</p>
        </div></body></html>";
                        $mail->AltBody = "Your verification code is: $otp. Expires in 10 minutes.";
                        $mail->send();
                        $_SESSION['otp_sent']            = true;
                        $_SESSION['password_reset_step'] = 1;
                        $passwordChangeMsg = "OTP has been sent to your email address.";
                    } catch (Exception $e) {
                        $passwordChangeError = "Failed to send OTP. Error: " . $mail->ErrorInfo;
                    }
                } else {
                    $passwordChangeError = "Failed to process request. Please try again.";
                }

            } elseif ($_POST['action'] === 'verify_otp') {
                $otp          = trim($_POST['otp'] ?? '');
                $otp_verified = false;
                $otp_expired  = false;

                if (empty($otp)) {
                    $passwordChangeError = "Please enter the verification code.";
                } else {
                    $otp_hash = md5((string)$otp);

                    if (isset($_SESSION['otp_hash'], $_SESSION['otp_expires'])) {
                        if (strtotime($_SESSION['otp_expires']) > time()) {
                            if ($otp_hash === $_SESSION['otp_hash']) $otp_verified = true;
                        } else { $otp_expired = true; }
                    }

                    if (!$otp_verified) {
                        $stmt = $conn->prepare("SELECT otp_hash, expires_at FROM password_resets WHERE user_id = ? ORDER BY id DESC LIMIT 1");
                        if ($stmt) {
                            $stmt->bind_param("i", $userId);
                            $stmt->execute();
                            $otpData = $stmt->get_result()->fetch_assoc();
                            if ($otpData) {
                                if (strtotime($otpData['expires_at']) < time()) {
                                    $otp_expired = true;
                                } elseif ($otp_hash === $otpData['otp_hash']) {
                                    $otp_verified = true;
                                }
                            }
                        }
                    }

                    if ($otp_verified) {
                        $_SESSION['password_reset_step'] = 2;
                        $_SESSION['otp_verified']        = true;
                        $passwordChangeMsg = "OTP verified successfully. Please set your new password.";
                    } elseif ($otp_expired) {
                        $passwordChangeError = "Verification code has expired. Please request a new one.";
                    } else {
                        $passwordChangeError = "Invalid verification code. Please check and try again.";
                    }
                }

            } elseif ($_POST['action'] === 'change_password') {
                if (!isset($_SESSION['otp_verified']) || !$_SESSION['otp_verified']) {
                    $passwordChangeError = "Please verify your OTP first.";
                } else {
                    $newPassword     = $_POST['new_password']     ?? '';
                    $confirmPassword = $_POST['confirm_password'] ?? '';
                    if (empty($newPassword)) {
                        $passwordChangeError = "Please enter a new password.";
                    } elseif (strlen($newPassword) < 8) {
                        $passwordChangeError = "Password must be at least 8 characters long.";
                    } elseif (!preg_match('/[A-Z]/', $newPassword)) {
                        $passwordChangeError = "Password must contain at least one uppercase letter.";
                    } elseif (!preg_match('/[a-z]/', $newPassword)) {
                        $passwordChangeError = "Password must contain at least one lowercase letter.";
                    } elseif (!preg_match('/[0-9]/', $newPassword)) {
                        $passwordChangeError = "Password must contain at least one number.";
                    } elseif ($newPassword !== $confirmPassword) {
                        $passwordChangeError = "Passwords do not match.";
                    } else {
                        $hashed = password_hash($newPassword, PASSWORD_BCRYPT);
                        $stmt   = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $stmt->bind_param("si", $hashed, $userId);
                        if ($stmt->execute()) {
                            unset($_SESSION['password_reset_step'], $_SESSION['otp_verified'],
                                $_SESSION['otp_sent'], $_SESSION['otp_hash'], $_SESSION['otp_expires']);
                            $passwordChangeMsg = "Your password has been changed successfully.";
                            $passwordChanged   = true;
                        } else {
                            $passwordChangeError = "Failed to update password. Please try again.";
                        }
                    }
                }
            }
        }

        if ($isPasswordChangeAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => $passwordChanged, 'message' => $passwordChanged ? $passwordChangeMsg : $passwordChangeError]);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['settings_type'])) {
            if ($_POST['settings_type'] === 'notifications') {
                $a = isset($_POST['approval_notification'])  ? 1 : 0;
                $r = isset($_POST['rejection_notification']) ? 1 : 0;
                $m = isset($_POST['reminder_notification'])  ? 1 : 0;
                $t = isset($_POST['return_notification'])    ? 1 : 0;
                $c = isset($_POST['conflict_notification'])  ? 1 : 0;
                $stmt = $conn->prepare("INSERT INTO notification_preferences (user_id, approval_notification, rejection_notification, reminder_notification, return_notification, conflict_notification) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE approval_notification=?,rejection_notification=?,reminder_notification=?,return_notification=?,conflict_notification=?");
                $stmt->bind_param("iiiiiiiiiii", $userId,$a,$r,$m,$t,$c,$a,$r,$m,$t,$c);
                $success = $stmt->execute() ? "Notification preferences saved successfully." : "Failed to update preferences.";
                if (!$stmt->execute()) $error = "Failed to update preferences. Please try again.";
            } elseif ($_POST['settings_type'] === 'security') {
                $tf = isset($_POST['two_factor_auth'])   ? 1 : 0;
                $la = isset($_POST['login_alerts'])      ? 1 : 0;
                $st = isset($_POST['session_timeout'])   ? 1 : 0;
                $dm = isset($_POST['device_management']) ? 1 : 0;
                $stmt = $conn->prepare("INSERT INTO security_settings (user_id, two_factor_auth, login_alerts, session_timeout, device_management) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE two_factor_auth=?,login_alerts=?,session_timeout=?,device_management=?");
                $stmt->bind_param("iiiiiiiii", $userId,$tf,$la,$st,$dm,$tf,$la,$st,$dm);
                $success = $stmt->execute() ? "Security settings saved successfully." : "";
                if (!$stmt->execute()) $error = "Failed to update security settings. Please try again.";
            }
        }

        // Pull current prefs
        $prefs = null;
        try {
            $stmt = $conn->prepare("SELECT * FROM notification_preferences WHERE user_id = ?");
            if ($stmt) { $stmt->bind_param("i",$userId); $stmt->execute(); $prefs = $stmt->get_result()->fetch_assoc(); }
        } catch (Throwable $e) {}
        if (!$prefs) $prefs = ['approval_notification'=>1,'rejection_notification'=>1,'reminder_notification'=>1,'return_notification'=>1,'conflict_notification'=>1];

        $security = null;
        try {
            $stmt = $conn->prepare("SELECT * FROM security_settings WHERE user_id = ?");
            if ($stmt) { $stmt->bind_param("i",$userId); $stmt->execute(); $security = $stmt->get_result()->fetch_assoc(); }
        } catch (Throwable $e) {}
        if (!$security) $security = ['two_factor_auth'=>0,'login_alerts'=>1,'session_timeout'=>1,'device_management'=>1];
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Settings — AssetEase</title>
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
        /* ══ RESET & BASE ══════════════════════════════════════════════════════════ */
        :root {
        --red:        #95122C;
        --red-dark:   #7a0c23;
        --dark:       #100C08;
        --bg:         #F5EFED;
        --white:      #ffffff;
        --shadow:     0 4px 20px rgba(0,0,0,0.08);
        --shadow-md:  0 8px 30px rgba(0,0,0,0.12);
        --radius:     14px;
        --sidebar-w:  260px;
        }
        *{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif;}
        body{background:var(--bg);display:flex;min-height:100vh;}

        /* ══ SIDEBAR ═══════════════════════════════════════════════════════════════ */
        .sidebar{
        width:var(--sidebar-w);height:100vh;
        background:linear-gradient(180deg,var(--dark) 0%,var(--red) 100%);
        color:#fff;padding:0;position:fixed;z-index:1001;overflow-y:auto;
        display:flex;flex-direction:column;
        }
        .sidebar-logo{
        padding:28px 25px 22px;display:flex;align-items:center;gap:12px;
        border-bottom:1px solid rgba(255,255,255,0.1);
        }
        .logo-image{
        width:42px;height:42px;object-fit:cover;border-radius:10px;
        background:#fff;padding:2px;
        }
        .sidebar-logo h2{color:#FFD700;font-weight:700;font-size:20px;letter-spacing:1.5px;}
        .sidebar-section-label{
        padding:18px 25px 6px;font-size:10px;font-weight:600;
        letter-spacing:2px;color:rgba(255,255,255,0.45);text-transform:uppercase;
        }
        .sidebar a{
        display:flex;align-items:center;padding:13px 25px;
        color:rgba(255,255,255,0.8);text-decoration:none;
        transition:all 0.25s ease;font-size:0.9rem;font-weight:500;
        border-left:3px solid transparent;margin:1px 0;
        }
        .sidebar a i{width:32px;font-size:16px;opacity:0.85;}
        .sidebar a:hover{background:rgba(255,255,255,0.1);color:#fff;border-left-color:rgba(255,255,255,0.4);}
        .sidebar a.active{background:rgba(255,255,255,0.18);color:#fff;border-left-color:#FFD700;font-weight:600;}
        .sidebar a.active i{opacity:1;}
        .sidebar-divider{border:none;border-top:1px solid rgba(255,255,255,0.1);margin:10px 20px;}
        .sidebar-promo{
        margin:auto 15px 20px;padding:18px;
        background:linear-gradient(135deg,rgba(255,215,0,0.2),rgba(255,255,255,0.08));
        border:1px solid rgba(255,215,0,0.3);border-radius:12px;
        }
        .sidebar-promo p{font-size:12px;color:rgba(255,255,255,0.85);line-height:1.5;}
        .sidebar-promo strong{color:#FFD700;font-size:13px;}
        .sidebar-promo a{
        display:inline-block;margin-top:10px;padding:7px 16px;
        background:#FFD700;color:var(--dark);border-radius:8px;
        font-size:11px;font-weight:700;text-decoration:none;border:none;
        }

        /* ══ MAIN LAYOUT ════════════════════════════════════════════════════════════ */
        .main{margin-left:var(--sidebar-w);width:calc(100% - var(--sidebar-w));display:flex;flex-direction:column;min-height:100vh;}

        /* ══ TOP HEADER ════════════════════════════════════════════════════════════ */
        .top-header{
        position:fixed;top:0;right:0;
        width:calc(100% - var(--sidebar-w));
        background:var(--white);
        display:flex;justify-content:space-between;align-items:center;
        padding:14px 36px;z-index:1000;
        box-shadow:0 1px 0 rgba(0,0,0,0.07);
        }
        .header-left h1{font-size:22px;font-weight:700;color:var(--dark);}
        .header-left p{font-size:13px;color:#888;margin-top:1px;}
        .header-right{display:flex;align-items:center;gap:12px;}
        .search-box{
        display:flex;align-items:center;gap:10px;
        background:#F5EFED;border-radius:25px;padding:9px 18px;
        width:240px;border:1.5px solid transparent;transition:0.2s;
        }
        .search-box:focus-within{border-color:var(--red);background:#fff;}
        .search-box i{color:#aaa;font-size:14px;}
        .search-box input{border:none;background:transparent;font-size:13px;font-family:'Poppins';color:var(--dark);outline:none;width:100%;}
        .search-box input::placeholder{color:#bbb;}
        .notif-wrap{position:relative;}
        .notif-btn{
        width:42px;height:42px;border-radius:50%;background:#F5EFED;border:none;
        display:flex;align-items:center;justify-content:center;cursor:pointer;
        color:var(--dark);font-size:17px;transition:0.2s;
        }
        .notif-btn:hover{background:#ede6e3;}
        .notif-badge{
        position:absolute;top:-2px;right:-2px;background:#dc3545;color:#fff;
        font-size:10px;font-weight:700;width:18px;height:18px;
        border-radius:50%;display:flex;align-items:center;justify-content:center;
        border:2px solid #fff;
        }
        .notif-dropdown{
        position:absolute;top:52px;right:0;width:340px;background:#fff;
        border-radius:var(--radius);box-shadow:var(--shadow-md);z-index:2000;
        display:none;max-height:380px;overflow-y:auto;
        }
        .notif-dropdown.active{display:block;}
        .notif-header{padding:14px 18px 10px;font-weight:700;font-size:13px;color:var(--dark);border-bottom:1px solid #f0f0f0;}
        .notif-item{padding:12px 18px;border-bottom:1px solid #f8f8f8;cursor:pointer;}
        .notif-item:hover{background:#fafafa;}
        .notif-item .ni-msg{font-size:13px;color:#444;line-height:1.4;}
        .notif-item .ni-time{font-size:11px;color:#bbb;margin-top:3px;}
        .notif-empty{padding:24px;text-align:center;color:#bbb;font-size:13px;}
        .profile-pill{
        display:flex;align-items:center;gap:10px;
        background:#F5EFED;border-radius:30px;padding:6px 14px 6px 8px;
        cursor:pointer;transition:0.2s;
        }
        .profile-pill:hover{background:#ede6e3;}
        .profile-pill img{width:34px;height:34px;border-radius:50%;object-fit:cover;border:2px solid var(--red);}
        .profile-pill span{font-size:13px;font-weight:600;color:var(--dark);}

        /* ══ CONTENT ════════════════════════════════════════════════════════════════ */
        .content{padding:90px 36px 40px;flex:1;}

        /* ══ SETTINGS TABS ══════════════════════════════════════════════════════════ */
        .tabs-row{display:flex;gap:10px;margin-bottom:24px;flex-wrap:wrap;}
        .tab-btn{
        display:flex;align-items:center;gap:8px;padding:10px 20px;
        background:#fff;border:1.5px solid #e8e0de;border-radius:25px;
        font-size:13px;font-weight:500;color:#555;cursor:pointer;transition:0.2s;
        }
        .tab-btn:hover{border-color:var(--red);color:var(--red);}
        .tab-btn.active{background:var(--red);color:#fff;border-color:var(--red);}

        /* ══ SETTINGS CARD ══════════════════════════════════════════════════════════ */
        .settings-section{display:none;}
        .settings-section.active{display:block;}
        .settings-card{
        background:#fff;border-radius:var(--radius);
        box-shadow:var(--shadow);padding:28px 32px;margin-bottom:22px;
        }
        .settings-card-header{
        display:flex;align-items:center;gap:14px;margin-bottom:6px;
        }
        .settings-card-header .sc-icon{
        width:46px;height:46px;border-radius:11px;
        display:flex;align-items:center;justify-content:center;font-size:20px;
        }
        .sc-icon.red{background:#fef2f2;color:var(--red);}
        .sc-icon.blue{background:#eff6ff;color:#3b82f6;}
        .sc-icon.green{background:#f0fdf4;color:#22c55e;}
        .settings-card-header h3{font-size:17px;font-weight:700;color:var(--dark);}
        .settings-card-sub{font-size:13px;color:#888;margin-bottom:22px;padding-left:60px;}
        .settings-divider{border:none;border-top:1px solid #f0f0f0;margin:18px 0;}

        /* ══ SETTING ITEM ═══════════════════════════════════════════════════════════ */
        .setting-item{
        display:flex;align-items:center;justify-content:space-between;
        padding:14px 0;border-bottom:1px solid #f7f5f4;gap:20px;
        }
        .setting-item:last-of-type{border-bottom:none;}
        .setting-text h4{font-size:14px;font-weight:600;color:var(--dark);margin-bottom:3px;}
        .setting-text p{font-size:12px;color:#888;}

        /* Toggle switch */
        .toggle{position:relative;display:inline-block;width:50px;height:28px;flex-shrink:0;}
        .toggle input{opacity:0;width:0;height:0;}
        .slider{
        position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;
        background:#d1d5db;transition:0.3s;border-radius:28px;
        }
        .slider:before{
        position:absolute;content:"";height:22px;width:22px;
        left:3px;bottom:3px;background:#fff;transition:0.3s;border-radius:50%;
        box-shadow:0 1px 3px rgba(0,0,0,0.2);
        }
        input:checked + .slider{background:var(--red);}
        input:checked + .slider:before{transform:translateX(22px);}

        /* Alerts */
        .alert{
        display:flex;align-items:center;gap:10px;padding:14px 18px;
        border-radius:10px;margin-bottom:20px;font-size:13px;font-weight:500;
        }
        .alert-success{background:#dcfce7;color:#15803d;border-left:4px solid #22c55e;}
        .alert-error{background:#fee2e2;color:#b91c1c;border-left:4px solid #ef4444;}

        /* Save button */
        .btn-save{
        display:flex;align-items:center;gap:8px;
        background:var(--red);color:#fff;border:none;padding:12px 28px;
        border-radius:25px;font-size:13px;font-weight:600;cursor:pointer;
        transition:0.2s;margin-top:20px;
        }
        .btn-save:hover{background:var(--red-dark);}

        /* ══ ACCOUNT CARD ═══════════════════════════════════════════════════════════ */
        .account-info-row{
        display:flex;align-items:center;gap:18px;padding:18px;
        background:#faf8f7;border-radius:12px;margin-bottom:22px;
        }
        .account-avatar{
        width:64px;height:64px;border-radius:50%;border:3px solid var(--red);
        object-fit:cover;
        }
        .account-info-body h4{font-size:16px;font-weight:700;color:var(--dark);margin-bottom:4px;}
        .account-info-body p{font-size:13px;color:#888;}
        .btn-change-pass{
        display:inline-flex;align-items:center;gap:8px;
        background:var(--red);color:#fff;border:none;
        padding:11px 22px;border-radius:25px;font-size:13px;font-weight:600;
        cursor:pointer;transition:0.2s;margin-top:16px;
        }
        .btn-change-pass:hover{background:var(--red-dark);}

        /* ══ MODAL ══════════════════════════════════════════════════════════════════ */
        .modal{
        display:none;position:fixed;z-index:3000;inset:0;
        background:rgba(0,0,0,0.6);animation:fadeInModal 0.25s;
        }
        @keyframes fadeInModal{from{opacity:0}to{opacity:1}}
        .modal-content{
        background:var(--dark);margin:0 auto;padding:36px 40px;
        border-radius:16px;width:90%;max-width:480px;
        position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);
        color:#fff;animation:slideDownModal 0.3s;
        }
        @keyframes slideDownModal{from{transform:translate(-50%,-60%);opacity:0}to{transform:translate(-50%,-50%);opacity:1}}
        .modal-head{display:flex;justify-content:space-between;align-items:center;
        margin-bottom:22px;padding-bottom:14px;border-bottom:1px solid rgba(255,255,255,0.1);}
        .modal-head h3{font-size:18px;color:#f3c5c5;margin:0;}
        .modal-close{background:none;border:none;color:#fff;font-size:22px;cursor:pointer;transition:0.2s;}
        .modal-close:hover{color:var(--red);}

        .step-indicators{display:flex;gap:8px;margin-bottom:26px;}
        .step-ind{
        flex:1;padding:10px;text-align:center;background:#1e1a18;
        color:#666;border-radius:8px;font-size:12px;font-weight:600;transition:0.3s;
        }
        .step-ind.active{background:#2a1418;color:#fff;border:1px solid var(--red);}
        .step-ind.completed{background:var(--red);color:#fff;}

        .password-step{display:none;}
        .password-step.visible{display:block;}

        .modal-form-group{margin-bottom:18px;}
        .modal-form-group label{display:block;margin-bottom:8px;color:#ccc;font-weight:600;font-size:13px;}
        .modal-form-group input{
        width:100%;padding:12px 14px;border:1px solid #444;border-radius:8px;
        background:#1e1a18;color:#fff;font-size:13px;font-family:'Poppins';transition:0.2s;
        }
        .modal-form-group input:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 3px rgba(149,18,44,0.15);}
        .modal-form-group input::placeholder{color:#666;}
        .modal-form-group input[readonly]{opacity:0.6;cursor:not-allowed;}

        .pw-wrap{position:relative;}
        .pw-wrap input{padding-right:44px;}
        .pw-toggle{
        position:absolute;right:10px;top:50%;transform:translateY(-50%);
        background:none;border:none;color:#aaa;cursor:pointer;font-size:15px;transition:0.2s;
        }
        .pw-toggle:hover{color:#fff;}

        .modal-info{display:flex;align-items:center;gap:8px;font-size:12px;color:#888;margin:14px 0;}
        .modal-info i{color:var(--red);}

        .strength-meter{width:100%;height:7px;background:#333;border-radius:4px;margin-bottom:6px;overflow:hidden;}
        .strength-fill{height:100%;width:25%;border-radius:4px;transition:all 0.3s;}
        .strength-fill.weak{width:25%;background:#ef4444;}
        .strength-fill.medium{width:60%;background:#eab308;}
        .strength-fill.strong{width:100%;background:#22c55e;}
        .strength-text{font-size:12px;color:#888;text-align:right;font-weight:600;margin-bottom:12px;}

        .pw-reqs{background:#1e1a18;border:1px solid #333;border-radius:8px;padding:12px;margin-bottom:14px;}
        .req-item{font-size:12px;color:#666;display:flex;align-items:center;gap:7px;margin-bottom:6px;}
        .req-item:last-child{margin-bottom:0;}
        .req-item.met{color:#22c55e;}
        .req-item i{font-size:10px;}

        .pw-match{
        display:none;align-items:center;gap:7px;background:#1a3320;color:#22c55e;
        padding:9px 12px;border-radius:7px;margin-bottom:12px;font-size:12px;
        border-left:3px solid #22c55e;
        }

        .modal-btns{display:flex;gap:12px;margin-top:22px;}
        .btn-mcancel{flex:1;padding:11px;background:#333;color:#fff;border:1px solid #555;border-radius:8px;cursor:pointer;font-weight:600;font-size:13px;transition:0.2s;}
        .btn-mcancel:hover{background:#444;}
        .btn-msubmit{flex:1;padding:11px;background:#d4a5a5;color:var(--dark);border:none;border-radius:8px;cursor:pointer;font-weight:700;font-size:13px;transition:0.2s;}
        .btn-msubmit:hover{background:#e5b5b5;}
        .btn-msubmit:disabled{opacity:0.45;cursor:not-allowed;background:#888;}

        .modal-msg{padding:11px 14px;border-radius:8px;margin-bottom:14px;font-size:13px;display:none;}
        .modal-msg.success{background:#1a3320;color:#22c55e;border-left:4px solid #22c55e;}
        .modal-msg.error{background:#3b0a14;color:#f87171;border-left:4px solid #ef4444;}

        /* ══ MOBILE RESPONSIVE ══════════════════════════════════════════════════ */
        .mobile-menu-btn{display:none;position:fixed;top:13px;left:14px;z-index:1200;background:var(--red);color:#fff;border:none;width:44px;height:44px;border-radius:10px;font-size:20px;cursor:pointer;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,0.3);}
        .sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1100;}
        .sidebar-overlay.open{display:block;}
        .bottom-nav{display:none;position:fixed;bottom:0;left:0;right:0;background:linear-gradient(90deg,#100C08 0%,#95122C 100%);z-index:1050;box-shadow:0 -2px 10px rgba(0,0,0,0.25);}
        .bottom-nav-inner{display:flex;width:100%;}
        .bottom-nav a{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:rgba(255,255,255,0.65);text-decoration:none;padding:10px 0 8px;font-size:10px;gap:3px;transition:color 0.2s;font-family:'Poppins',sans-serif;}
        .bottom-nav a i{font-size:18px;}
        .bottom-nav a.active,.bottom-nav a:hover{color:#FFD700;}
        @media(max-width:768px){
        .sidebar{transform:translateX(-260px);transition:transform 0.3s ease;z-index:1150!important;}
        .sidebar.open{transform:translateX(0);}
        .mobile-menu-btn{display:flex;}
        .main{margin-left:0!important;width:100%!important;}
        .top-header{left:0!important;right:0!important;width:100%!important;padding:11px 14px 11px 68px!important;}
        .header-left h1{font-size:16px!important;}
        .header-left p{font-size:11px!important;}
        .search-box{display:none!important;}
        .profile-pill span{display:none!important;}
        .profile-pill{padding:4px!important;gap:0!important;}
        .content{padding:80px 14px 90px!important;}
        .bottom-nav{display:block;}
        .notif-dropdown{width:min(300px,90vw)!important;}
        .tabs-row{gap:8px;}
        .tab-btn{padding:8px 14px;font-size:12px;}
        .settings-card{padding:18px 16px!important;}
        .settings-card-sub{padding-left:0!important;}
        .account-info-row{flex-direction:column;text-align:center;}
        .setting-item{flex-direction:column;align-items:flex-start;gap:10px;}
        .modal-content{padding:24px 18px!important;width:94%!important;}
        }
        </style>
        </head>
        <body>

        <!-- ═══════════════════ SIDEBAR ═══════════════════════════════════════════ -->
        <button class="mobile-menu-btn" id="mobileMenuBtn" onclick="toggleMobileSidebar()"><i class="fas fa-bars"></i></button>
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleMobileSidebar()"></div>
        <div class="sidebar">
        <div class="sidebar-logo">
            <img src="image/logo.png" alt="AssetEase Logo" class="logo-image">
            <h2>ASSETEASE</h2>
        </div>

        <div class="sidebar-section-label">Main</div>
        <a href="dashboard.php"><i class="fas fa-th-large"></i> Overview</a>
        <a href="reservation_form.php"><i class="fas fa-plus-circle"></i> Reserve and Request</a>
        <a href="view_reservations.php"><i class="fas fa-list-check"></i> My Reservations</a>

        <hr class="sidebar-divider">
        <div class="sidebar-section-label">Support</div>
        <a href="notification.php">
            <i class="fas fa-bell"></i> Notifications
            <?php if($unread_count > 0): ?>
            <span style="background:#dc3545;color:#fff;font-size:10px;padding:1px 7px;border-radius:10px;margin-left:auto;"><?= $unread_count ?></span>
            <?php endif; ?>
        </a>
        <a href="settings.php" class="active"><i class="fas fa-cog"></i> Settings</a>
        <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>

        <div class="sidebar-promo">
            <strong>Need help?</strong>
            <p>Contact admin or check our help center for assistance.</p>
            <a href="#">Help Center</a>
        </div>
        </div>

        <!-- ═══════════════════ MAIN ═══════════════════════════════════════════════ -->
        <div class="main">

        <!-- TOP HEADER -->
        <div class="top-header">
            <div class="header-left">
            <h1>Settings</h1>
            <p>Manage your preferences and account security.</p>
            </div>
            <div class="header-right">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search settings...">
            </div>
            <div class="notif-wrap">
                <button class="notif-btn" onclick="toggleNotifDropdown(event)">
                <i class="fas fa-bell"></i>
                <?php if($unread_count > 0): ?>
                    <span class="notif-badge"><?= $unread_count ?></span>
                <?php endif; ?>
                </button>
                <div class="notif-dropdown" id="notifDropdown">
                <div class="notif-header">Notifications</div>
                <?php if(!empty($notifications)): ?>
                    <?php foreach($notifications as $n): ?>
                    <div class="notif-item">
                        <div class="ni-msg"><?= htmlspecialchars($n['message']) ?></div>
                        <div class="ni-time"><?= date('M d, h:i A', strtotime($n['created_at'])) ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="notif-empty"><i class="fas fa-bell-slash" style="font-size:24px;display:block;margin-bottom:8px;"></i>No notifications yet</div>
                <?php endif; ?>
                </div>
            </div>
            <div class="profile-pill">
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($userName) ?>&background=95122C&color=fff&rounded=true" alt="">
                <span><?= htmlspecialchars(strlen($userName) > 12 ? substr($userName,0,12).'…' : $userName) ?></span>
            </div>
            </div>
        </div>

        <!-- CONTENT -->
        <div class="content">

            <?php if($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i><?= $success ?></div>
            <?php endif; ?>
            <?php if($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?= $error ?></div>
            <?php endif; ?>

            <!-- Tabs -->
            <div class="tabs-row">
            <button class="tab-btn active" onclick="switchTab('notifications', this)">
                <i class="fas fa-bell"></i> Notifications
            </button>
            <button class="tab-btn" onclick="switchTab('security', this)">
                <i class="fas fa-shield-alt"></i> Security
            </button>
            <button class="tab-btn" onclick="switchTab('account', this)">
                <i class="fas fa-user-circle"></i> Account
            </button>
            </div>

            <!-- ── NOTIFICATIONS TAB ─────────────────────────────────────────────── -->
            <div class="settings-section active" id="tab-notifications">
            <div class="settings-card">
                <div class="settings-card-header">
                <div class="sc-icon red"><i class="fas fa-bell"></i></div>
                <h3>Notification Preferences</h3>
                </div>
                <p class="settings-card-sub">Manage how you receive notifications about your reservations and bookings.</p>

                <form method="POST">
                <input type="hidden" name="settings_type" value="notifications">

                <div class="setting-item">
                    <div class="setting-text">
                    <h4>Booking Confirmation</h4>
                    <p>Receive a notification when your reservation is approved</p>
                    </div>
                    <label class="toggle">
                    <input type="checkbox" name="approval_notification" <?= $prefs['approval_notification'] ? 'checked' : '' ?>>
                    <span class="slider"></span>
                    </label>
                </div>

                <div class="setting-item">
                    <div class="setting-text">
                    <h4>Booking Rejection</h4>
                    <p>Receive a notification when your reservation is rejected</p>
                    </div>
                    <label class="toggle">
                    <input type="checkbox" name="rejection_notification" <?= $prefs['rejection_notification'] ? 'checked' : '' ?>>
                    <span class="slider"></span>
                    </label>
                </div>

                <div class="setting-item">
                    <div class="setting-text">
                    <h4>Meeting Reminder</h4>
                    <p>Receive a reminder the day before your reservation</p>
                    </div>
                    <label class="toggle">
                    <input type="checkbox" name="reminder_notification" <?= $prefs['reminder_notification'] ? 'checked' : '' ?>>
                    <span class="slider"></span>
                    </label>
                </div>

                <div class="setting-item">
                    <div class="setting-text">
                    <h4>Return Reminder</h4>
                    <p>Receive a reminder to return equipment on time</p>
                    </div>
                    <label class="toggle">
                    <input type="checkbox" name="return_notification" <?= $prefs['return_notification'] ? 'checked' : '' ?>>
                    <span class="slider"></span>
                    </label>
                </div>

                <div class="setting-item">
                    <div class="setting-text">
                    <h4>Schedule Conflict Warning</h4>
                    <p>Get alerted if there's a conflict in your booking schedule</p>
                    </div>
                    <label class="toggle">
                    <input type="checkbox" name="conflict_notification" <?= $prefs['conflict_notification'] ? 'checked' : '' ?>>
                    <span class="slider"></span>
                    </label>
                </div>

                <button type="submit" class="btn-save">
                    <i class="fas fa-save"></i> Save Preferences
                </button>
                </form>
            </div>
            </div>

            <!-- ── SECURITY TAB ──────────────────────────────────────────────────── -->
            <div class="settings-section" id="tab-security">
            <div class="settings-card">
                <div class="settings-card-header">
                <div class="sc-icon blue"><i class="fas fa-shield-alt"></i></div>
                <h3>Security Settings</h3>
                </div>
                <p class="settings-card-sub">Protect your account with advanced security features and preferences.</p>

                <form method="POST">
                <input type="hidden" name="settings_type" value="security">

                <div class="setting-item">
                    <div class="setting-text">
                    <h4>Two-Factor Authentication</h4>
                    <p>Add an extra layer of security to your account</p>
                    </div>
                    <label class="toggle">
                    <input type="checkbox" name="two_factor_auth" <?= $security['two_factor_auth'] ? 'checked' : '' ?>>
                    <span class="slider"></span>
                    </label>
                </div>

                <div class="setting-item">
                    <div class="setting-text">
                    <h4>Login Alerts</h4>
                    <p>Get notified when someone logs into your account</p>
                    </div>
                    <label class="toggle">
                    <input type="checkbox" name="login_alerts" <?= $security['login_alerts'] ? 'checked' : '' ?>>
                    <span class="slider"></span>
                    </label>
                </div>

                <div class="setting-item">
                    <div class="setting-text">
                    <h4>Session Timeout</h4>
                    <p>Automatically log out after 30 minutes of inactivity</p>
                    </div>
                    <label class="toggle">
                    <input type="checkbox" name="session_timeout" <?= $security['session_timeout'] ? 'checked' : '' ?>>
                    <span class="slider"></span>
                    </label>
                </div>

                <div class="setting-item">
                    <div class="setting-text">
                    <h4>Device Management</h4>
                    <p>View and manage devices connected to your account</p>
                    </div>
                    <label class="toggle">
                    <input type="checkbox" name="device_management" <?= $security['device_management'] ? 'checked' : '' ?>>
                    <span class="slider"></span>
                    </label>
                </div>

                <button type="submit" class="btn-save">
                    <i class="fas fa-save"></i> Save Security Settings
                </button>
                </form>
            </div>
            </div>

            <!-- ── ACCOUNT TAB ───────────────────────────────────────────────────── -->
            <div class="settings-section" id="tab-account">
            <div class="settings-card">
                <div class="settings-card-header">
                <div class="sc-icon green"><i class="fas fa-user-circle"></i></div>
                <h3>Account Information</h3>
                </div>
                <p class="settings-card-sub">View your account details and manage your password.</p>

                <div class="account-info-row">
                <img class="account-avatar"
                    src="https://ui-avatars.com/api/?name=<?= urlencode($userName) ?>&background=95122C&color=fff&size=128"
                    alt="Avatar">
                <div class="account-info-body">
                    <h4><?= htmlspecialchars($userName) ?></h4>
                    <p><i class="fas fa-envelope" style="margin-right:6px;color:var(--red);"></i><?= htmlspecialchars($userEmail) ?></p>
                </div>
                </div>

                <div class="setting-item">
                <div class="setting-text">
                    <h4>Password</h4>
                    <p>Update your password regularly to keep your account secure</p>
                </div>
                <button type="button" class="btn-save" onclick="openPasswordModal()" style="margin-top:0;">
                    <i class="fas fa-lock"></i> Change Password
                </button>
                </div>
            </div>
            </div>

        </div><!-- /content -->
        </div><!-- /main -->

        <!-- ═══════════════════ PASSWORD MODAL ════════════════════════════════════ -->
        <div id="passwordModal" class="modal">
        <div class="modal-content">
            <div class="modal-head">
            <h3><i class="fas fa-lock" style="margin-right:8px;"></i>Change Password</h3>
            <button class="modal-close" onclick="closePasswordModal()">&times;</button>
            </div>

            <div class="step-indicators">
            <div class="step-ind" id="si-1">Step 1</div>
            <div class="step-ind" id="si-2">Step 2</div>
            <div class="step-ind" id="si-3">Step 3</div>
            </div>

            <?php if($passwordChangeMsg): ?>
            <div class="modal-msg success" style="display:flex;"><i class="fas fa-check-circle"></i>&nbsp;<?= htmlspecialchars($passwordChangeMsg) ?></div>
            <?php endif; ?>
            <?php if($passwordChangeError): ?>
            <div class="modal-msg error" style="display:flex;"><i class="fas fa-exclamation-circle"></i>&nbsp;<?= htmlspecialchars($passwordChangeError) ?></div>
            <?php endif; ?>
            <div id="dynamicMsg" class="modal-msg"></div>

            <!-- Step 1: Send OTP -->
            <div class="password-step" id="step1">
            <form method="POST" id="form1" onsubmit="submitStep1(event)">
                <input type="hidden" name="action" value="send_otp">
                <div class="modal-form-group">
                <label>Email Address</label>
                <input type="email" readonly value="<?= htmlspecialchars($userEmail) ?>">
                </div>
                <div class="modal-info"><i class="fas fa-info-circle"></i> A 6-digit verification code will be sent to your email.</div>
                <div class="modal-btns">
                <button type="button" class="btn-mcancel" onclick="closePasswordModal()">Cancel</button>
                <button type="submit" class="btn-msubmit"><i class="fas fa-paper-plane"></i> Send Code</button>
                </div>
            </form>
            </div>

            <!-- Step 2: Verify OTP -->
            <div class="password-step" id="step2">
            <form method="POST" id="form2" onsubmit="submitStep2(event)">
                <input type="hidden" name="action" value="verify_otp">
                <div class="modal-form-group">
                <label>Verification Code</label>
                <input type="text" name="otp" id="otpInput" placeholder="Enter 6-digit code" maxlength="6" pattern="[0-9]{6}" autocomplete="off">
                </div>
                <div class="modal-info"><i class="fas fa-clock"></i> Check your email for the code — it expires in 10 minutes.</div>
                <div class="modal-btns">
                <button type="button" class="btn-mcancel" onclick="goToStep(1)">Back</button>
                <button type="submit" class="btn-msubmit"><i class="fas fa-check"></i> Verify</button>
                </div>
            </form>
            </div>

            <!-- Step 3: New Password -->
            <div class="password-step" id="step3">
            <form method="POST" id="form3" onsubmit="submitStep3(event)">
                <input type="hidden" name="action" value="change_password">
                <div class="modal-form-group">
                <label>New Password</label>
                <div class="pw-wrap">
                    <input type="password" id="newPassword" name="new_password" placeholder="Enter new password" minlength="8">
                    <button type="button" class="pw-toggle" onclick="togglePw('newPassword','pwEye1')"><i id="pwEye1" class="fas fa-eye"></i></button>
                </div>
                </div>
                <div class="strength-meter"><div class="strength-fill" id="strengthBar"></div></div>
                <div id="strengthText" class="strength-text">Strength: Weak</div>
                <div class="pw-reqs">
                <div class="req-item" id="req-length"><i class="fas fa-circle"></i> At least 8 characters</div>
                <div class="req-item" id="req-upper"><i class="fas fa-circle"></i> At least one uppercase letter</div>
                <div class="req-item" id="req-lower"><i class="fas fa-circle"></i> At least one lowercase letter</div>
                <div class="req-item" id="req-number"><i class="fas fa-circle"></i> At least one number</div>
                </div>
                <div class="modal-form-group">
                <label>Confirm Password</label>
                <div class="pw-wrap">
                    <input type="password" id="confirmPassword" name="confirm_password" placeholder="Confirm new password" minlength="8">
                    <button type="button" class="pw-toggle" onclick="togglePw('confirmPassword','pwEye2')"><i id="pwEye2" class="fas fa-eye"></i></button>
                </div>
                </div>
                <div class="pw-match" id="pwMatch"><i class="fas fa-check-circle"></i> Passwords match</div>
                <div class="modal-info"><i class="fas fa-shield-alt"></i> Use a strong password with uppercase, lowercase, and numbers.</div>
                <div class="modal-btns">
                <button type="button" class="btn-mcancel" onclick="goToStep(2)">Back</button>
                <button type="submit" class="btn-msubmit" id="submitBtn" disabled><i class="fas fa-check-circle"></i> Update Password</button>
                </div>
            </form>
            </div>

        </div>
        </div><!-- /modal -->

        <script>
        // ── Tab switching ─────────────────────────────────────────────────────────
        function switchTab(name, btn) {
        document.querySelectorAll('.settings-section').forEach(s => s.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('tab-' + name).classList.add('active');
        btn.classList.add('active');
        }

        // ── Notification dropdown ─────────────────────────────────────────────────
        function toggleNotifDropdown(e) {
        e.stopPropagation();
        document.getElementById('notifDropdown').classList.toggle('active');
        }
        document.addEventListener('click', function() {
        document.getElementById('notifDropdown').classList.remove('active');
        });

        // ── Modal ─────────────────────────────────────────────────────────────────
        let currentStep = 1;
        let isSubmitting = false;

        function openPasswordModal() {
        <?php if(isset($_SESSION['password_reset_step'])): ?>
            currentStep = <?= $_SESSION['password_reset_step'] + 1 ?>;
        <?php else: ?>
            currentStep = 1;
        <?php endif; ?>
        document.getElementById('passwordModal').style.display = 'block';
        showStep(currentStep);
        updateStepIndicators();
        if (currentStep === 3) setTimeout(initStrengthChecker, 100);
        }

        function closePasswordModal() {
        document.getElementById('passwordModal').style.display = 'none';
        currentStep = 1;
        isSubmitting = false;
        hideDynMsg();
        document.querySelectorAll('.password-step form').forEach(f => f.reset());
        }

        function goToStep(n) {
        currentStep = n;
        showStep(n);
        updateStepIndicators();
        if (n === 3) setTimeout(initStrengthChecker, 100);
        }

        function showStep(n) {
        document.querySelectorAll('.password-step').forEach(s => s.classList.remove('visible'));
        document.getElementById('step' + n).classList.add('visible');
        }

        function updateStepIndicators() {
        for (let i = 1; i <= 3; i++) {
            const el = document.getElementById('si-' + i);
            el.classList.remove('active','completed');
            if (i === currentStep) el.classList.add('active');
            else if (i < currentStep) el.classList.add('completed');
        }
        }

        function showDynMsg(type, msg) {
        const el = document.getElementById('dynamicMsg');
        el.className = 'modal-msg ' + type;
        el.innerHTML = '<i class="fas ' + (type==='success' ? 'fa-check-circle' : 'fa-exclamation-circle') + '"></i>&nbsp;' + msg;
        el.style.display = 'flex';
        }
        function hideDynMsg() {
        const el = document.getElementById('dynamicMsg');
        el.style.display = 'none';
        el.textContent = '';
        }

        function submitStep1(e) {
        e.preventDefault();
        const btn = e.currentTarget.querySelector('.btn-msubmit');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
        hideDynMsg();
        fetch('settings.php', { method:'POST', body: new FormData(e.currentTarget) })
            .then(r => r.text()).then(() => { goToStep(2); showDynMsg('success','Code sent! Check your email.'); })
            .catch(() => showDynMsg('error','Failed to send code. Try again.'))
            .finally(() => { btn.disabled=false; btn.innerHTML='<i class="fas fa-paper-plane"></i> Send Code'; });
        }

        function submitStep2(e) {
        e.preventDefault();
        const btn = e.currentTarget.querySelector('.btn-msubmit');
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';
        hideDynMsg();
        fetch('settings.php', { method:'POST', body: new FormData(e.currentTarget) })
            .then(r => r.text()).then(() => { goToStep(3); showDynMsg('success','OTP verified! Set your new password.'); })
            .catch(() => showDynMsg('error','Verification failed. Try again.'))
            .finally(() => { btn.disabled=false; btn.innerHTML='<i class="fas fa-check"></i> Verify'; });
        }

        function submitStep3(e) {
        e.preventDefault();
        const submitBtn = document.getElementById('submitBtn');
        if (submitBtn.disabled) { showDynMsg('error','Please meet all password requirements.'); return; }
        const orig = submitBtn.innerHTML;
        submitBtn.disabled = true; submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
        isSubmitting = true; hideDynMsg();
        fetch('settings.php', { method:'POST', body: new FormData(e.currentTarget), headers: {'X-Requested-With':'XMLHttpRequest'} })
            .then(r => r.json())
            .then(data => {
            showDynMsg(data.success ? 'success' : 'error', data.message);
            if (data.success) { e.currentTarget.reset(); initStrengthChecker(); setTimeout(closePasswordModal, 1800); }
            else { submitBtn.disabled = false; }
            })
            .catch(() => { showDynMsg('error','Update failed. Please try again.'); submitBtn.disabled = false; })
            .finally(() => { submitBtn.innerHTML = orig; isSubmitting = false; initStrengthChecker(); });
        }

        // ── Password strength checker ─────────────────────────────────────────────
        function togglePw(inputId, iconId) {
        const inp = document.getElementById(inputId);
        const icn = document.getElementById(iconId);
        const hide = inp.type === 'password';
        inp.type = hide ? 'text' : 'password';
        icn.classList.toggle('fa-eye', !hide);
        icn.classList.toggle('fa-eye-slash', hide);
        }

        function initStrengthChecker() {
        const pw = document.getElementById('newPassword');
        const cp = document.getElementById('confirmPassword');
        if (!pw || !cp) return;

        const checks = {
            'req-length': v => v.length >= 8,
            'req-upper':  v => /[A-Z]/.test(v),
            'req-lower':  v => /[a-z]/.test(v),
            'req-number': v => /[0-9]/.test(v),
        };

        function validate() {
            const val = pw.value, conf = cp.value;
            let score = 0;
            Object.entries(checks).forEach(([id, fn]) => {
            const el = document.getElementById(id);
            if (el) { if (fn(val)) { el.classList.add('met'); score++; } else { el.classList.remove('met'); } }
            });
            const bar = document.getElementById('strengthBar');
            const txt = document.getElementById('strengthText');
            const btn = document.getElementById('submitBtn');
            bar.className = 'strength-fill';
            if (score < 3)      { bar.classList.add('weak');   txt.textContent='Strength: Weak'; }
            else if (score < 4) { bar.classList.add('medium'); txt.textContent='Strength: Medium'; }
            else                { bar.classList.add('strong'); txt.textContent='Strength: Strong'; }

            const matchEl = document.getElementById('pwMatch');
            if (val && conf && val === conf) matchEl.style.display = 'flex';
            else matchEl.style.display = 'none';

            btn.disabled = !(score === 4 && val === conf && val !== '');
        }

        pw.oninput = cp.oninput = validate;
        validate();
        }

        // Close modal on outside click
        window.onclick = function(e) {
        const modal = document.getElementById('passwordModal');
        const otp  = document.getElementById('otpInput');
        const pw   = document.getElementById('newPassword');
        const cpw  = document.getElementById('confirmPassword');
        const typed = (otp && otp.value.trim()) || (pw && pw.value.trim()) || (cpw && cpw.value.trim());
        if (e.target === modal && !isSubmitting && !typed) closePasswordModal();
        };

        // Open modal if session says we're in the middle of a flow
        <?php if(isset($_SESSION['password_reset_step']) || $passwordChangeMsg || $passwordChangeError): ?>
        openPasswordModal();
        <?php endif; ?>

        // Mobile sidebar toggle
        function toggleMobileSidebar() {
        document.querySelector('.sidebar').classList.toggle('open');
        document.getElementById('sidebarOverlay').classList.toggle('open');
        }
        </script>

        <!-- Bottom navigation for mobile -->
        <nav class="bottom-nav">
        <div class="bottom-nav-inner">
            <a href="dashboard.php"><i class="fas fa-house"></i>Home</a>
            <a href="reservation_form.php"><i class="fas fa-plus-circle"></i>Reserve</a>
            <a href="view_reservations.php"><i class="fas fa-list-check"></i>Bookings</a>
            <a href="notification.php"><i class="fas fa-bell"></i>Alerts</a>
            <a href="settings.php" class="active"><i class="fas fa-cog"></i>Settings</a>
        </div>
        </nav>

        </body>
        </html>