<?php
/**
 * User Management for Super Admin - Equipment Management Dashboard
 * Allows super admin to create user accounts for the equipment management system
 */
session_start();
require 'config.php'; // $conn = new mysqli(...)

error_reporting(E_ALL);
ini_set('display_errors', 1);

$error   = '';
$success = '';

// Only super_admin can access this page
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    die("Access denied! Only Super Admins can access this page.");
}

$superAdminId = $_SESSION['user_id'] ?? 0;

// Fetch super admin's current name and signatory info from database
$superAdminStmt = $conn->prepare("SELECT uname, signatory_title FROM users WHERE id = ?");
$superAdminStmt->bind_param("i", $superAdminId);
$superAdminStmt->execute();
$superAdminResult = $superAdminStmt->get_result();
$superAdminRow    = $superAdminResult->fetch_assoc();
$superAdminName   = $superAdminRow['uname']           ?? 'Super Admin';
$signatoryTitle   = $superAdminRow['signatory_title'] ?? null;

// Human-readable label for the sidebar / header badge
if ($signatoryTitle === 'Dept_Head') {
    $signatoryLabel = ($userProgramCode ? strtoupper($userProgramCode) . ' ' : '') . 'Program Head';
} else {
    $signatoryLabel = match($signatoryTitle) {
        'MMIT_Director' => 'MMIT Director',
        'VP_Admin'      => 'VP for Admin & Finance',
        default         => 'Super Admin',
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $uname           = trim($_POST['username']  ?? '');
    $fullname        = trim($_POST['full_name'] ?? '');
    $email           = trim($_POST['email']     ?? '');
    $password        = $_POST['password']       ?? '';
    $role            = trim($_POST['role']      ?? 'user');
    $signatoryType   = trim($_POST['signatory_type'] ?? '');

    // Handle profile picture upload
    $profile_picture = 'default.png';
    if (!empty($_FILES['profile_picture']['name'])) {
        $uploadDir  = 'uploads/profiles/';
        
        // Create directory if it doesn't exist
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $ext        = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
        $allowed    = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (in_array($ext, $allowed)) {
            $newName = uniqid('pfp_', true) . '.' . $ext;
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $uploadDir . $newName)) {
                $profile_picture = $newName;
            }
        }
    }

    if (!$uname || !$fullname || !$email || !$password) {

        $error = "All fields are required.";

    } else {

        // Check duplicate username or email
        $stmt = $conn->prepare("SELECT id FROM users WHERE uname = ? OR email = ?");
        $stmt->bind_param("ss", $uname, $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {

            $error = "Username or email already exists.";

        } else {

            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Determine signatory_title based on selection
            $signatory_title = null;
            
            if ($role === 'super_admin' && $signatoryType) {
                if ($signatoryType === 'program_head') {
                    $signatory_title = 'Dept_Head';
                } elseif ($signatoryType === 'vp_admin') {
                    $signatory_title = 'VP_Admin';
                }
            }

            $insert = $conn->prepare("
                INSERT INTO users
                (uname, fullname, email, password, role, profile_picture, signatory_title, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $insert->bind_param("sssssss", $uname, $fullname, $email, $hashed_password, $role, $profile_picture, $signatory_title);

            if ($insert->execute()) {
                if ($signatory_title) {
                    $roleText = match($signatory_title) {
                        'Dept_Head' => 'Program Head',
                        'VP_Admin'  => 'VP for Admin & Finance',
                        default     => 'Super Admin',
                    };
                } else {
                    $roleText = ($role === 'super_admin') ? 'Super Admin' : (($role === 'admin') ? 'Admin' : 'User');
                }
                $success  = $roleText . " account for <strong>" . htmlspecialchars($uname) . "</strong> was created successfully!";
            } else {
                $error = "Insert failed: " . $insert->error;
            }

            $insert->close();
        }

        $stmt->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management — AssetEase (Super Admin)</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* ══ RESET & BASE ══════════════════════════════════════════════════════════ */
:root {
    --red:       #95122C;
    --red-dark:  #7a0c23;
    --dark:      #100C08;
    --gold:      #FFD700;
    --bg:        #F5EFED;
    --white:     #ffffff;
    --shadow:    0 4px 20px rgba(0,0,0,0.08);
    --shadow-md: 0 8px 30px rgba(0,0,0,0.12);
    --radius:    14px;
    --sidebar-w: 268px;
}
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Poppins', sans-serif; background: var(--bg); display: flex; min-height: 100vh; color: var(--dark); }

/* ══ SIDEBAR ═══════════════════════════════════════════════════════════════ */
.sidebar {
    width: var(--sidebar-w);
    height: 100vh;
    background: linear-gradient(180deg, var(--dark) 0%, var(--red) 100%);
    color: #fff;
    position: fixed;
    left: 0; top: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    z-index: 1001;
    transition: width 0.3s ease;
}

.sidebar-logo {
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 26px 22px 20px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    flex-shrink: 0;
    text-decoration: none;
    border-left: none !important;
    background: none !important;
    border-radius: 0;
    margin: 0;
}
.logo-img-wrap { position: relative; width: 44px; height: 44px; flex-shrink: 0; }
.logo-img-wrap img {
    width: 44px; height: 44px; object-fit: cover;
    border-radius: 10px; background: #fff; padding: 2px;
    border: 2px solid rgba(255,215,0,0.5);
}
.logo-img-wrap .logo-glow {
    position: absolute; inset: -4px; border-radius: 14px;
    background: radial-gradient(circle, rgba(255,215,0,0.25) 0%, transparent 70%);
    pointer-events: none;
}
.sidebar-logo-text h2 { color: var(--gold); font-size: 18px; font-weight: 700; letter-spacing: 2px; line-height: 1; }
.sidebar-logo-text span { font-size: 10px; color: rgba(255,255,255,0.5); letter-spacing: 1px; text-transform: uppercase; }

.admin-badge {
    margin: 14px 16px 6px;
    display: flex; align-items: center; gap: 10px;
    padding: 10px 14px;
    background: rgba(255,215,0,0.12);
    border: 1px solid rgba(255,215,0,0.25);
    border-radius: 10px; flex-shrink: 0;
}
.admin-badge img { width: 34px; height: 34px; border-radius: 50%; border: 2px solid var(--gold); object-fit: cover; }
.admin-badge-info .ab-name { font-size: 12px; font-weight: 700; color: #fff; line-height: 1; }
.admin-badge-info .ab-role { font-size: 10px; color: var(--gold); text-transform: uppercase; letter-spacing: 0.8px; margin-top: 3px; }

.sidebar-nav { flex: 1; overflow-y: auto; padding: 10px 0 8px; scrollbar-width: none; }
.sidebar-nav::-webkit-scrollbar { display: none; }

.nav-section-label {
    padding: 14px 22px 5px; font-size: 9.5px; font-weight: 700;
    letter-spacing: 2.5px; color: rgba(255,255,255,0.38); text-transform: uppercase;
}

.sidebar a {
    display: flex; align-items: center; gap: 12px;
    padding: 11px 22px; color: rgba(255,255,255,0.78);
    text-decoration: none; font-size: 0.855rem; font-weight: 500;
    border-left: 3px solid transparent; transition: all 0.22s ease;
    margin: 1px 8px; border-radius: 8px;
}
.sidebar a .nav-icon {
    width: 30px; height: 30px; display: flex; align-items: center;
    justify-content: center; font-size: 14px; border-radius: 7px;
    background: rgba(255,255,255,0.06); flex-shrink: 0; transition: all 0.22s;
}
.sidebar a:hover { background: rgba(255,255,255,0.10); color: #fff; border-left-color: rgba(255,255,255,0.3); }
.sidebar a.active { background: rgba(255,255,255,0.15); color: #fff; border-left-color: var(--gold); font-weight: 600; }
.sidebar a.active .nav-icon { background: rgba(255,215,0,0.18); color: var(--gold); }
.sidebar a:hover .nav-icon { background: rgba(255,255,255,0.12); }

.sidebar hr { border: none; border-top: 1px solid rgba(255,255,255,0.09); margin: 8px 18px; }

.sidebar-promo {
    margin: 8px 14px 18px;
    padding: 14px 15px;
    background: linear-gradient(135deg, rgba(255,215,0,0.18), rgba(255,255,255,0.06));
    border: 1px solid rgba(255,215,0,0.28);
    border-radius: 12px; flex-shrink: 0;
}
.sidebar-promo p { font-size: 11.5px; color: rgba(255,255,255,0.82); line-height: 1.5; }
.sidebar-promo strong { color: var(--gold); }
.sidebar-promo a {
    display: inline-flex; align-items: center; gap: 6px;
    margin-top: 9px; padding: 6px 14px;
    background: var(--gold) !important; color: var(--dark);
    border-radius: 7px !important; font-size: 11px; font-weight: 700;
    text-decoration: none; border: none !important; border-left: none !important; margin-left: 0 !important;
}
.sidebar-promo a:hover { opacity: 0.9; transform: none; background: var(--gold) !important; }

/* ══ MAIN LAYOUT ════════════════════════════════════════════════════════════ */
.main-wrap { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

/* ══ TOP HEADER ════════════════════════════════════════════════════════════ */
.top-header {
    position: sticky; top: 0; background: var(--white);
    display: flex; justify-content: space-between; align-items: center;
    padding: 0 36px; height: 68px;
    box-shadow: 0 1px 0 rgba(0,0,0,0.07), 0 2px 12px rgba(0,0,0,0.04);
    z-index: 999; gap: 16px;
}
.header-left { display: flex; align-items: center; gap: 14px; min-width: 0; }
.header-logo-mini { display: flex; align-items: center; gap: 9px; padding-right: 14px; border-right: 1px solid #eee; }
.header-logo-mini img { width: 34px; height: 34px; border-radius: 8px; object-fit: cover; border: 1.5px solid rgba(149,18,44,0.2); }
.header-logo-mini span { font-size: 14px; font-weight: 700; color: var(--red); letter-spacing: 1.5px; }
.header-title-area h1 { font-size: 19px; font-weight: 700; color: var(--dark); line-height: 1.1; }
.header-title-area p  { font-size: 12px; color: #999; margin-top: 1px; }
.header-right { display: flex; align-items: center; gap: 11px; flex-shrink: 0; }

.profile-pill {
    display: flex; align-items: center; gap: 9px;
    background: var(--bg); border-radius: 30px; padding: 5px 14px 5px 6px;
    cursor: pointer; transition: 0.2s; border: 1px solid transparent;
}
.profile-pill:hover { background: #ede6e3; border-color: rgba(149,18,44,0.15); }
.profile-pill img { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; border: 2px solid var(--red); }
.profile-pill .pp-name { font-size: 13px; font-weight: 600; color: var(--dark); }
.profile-pill .pp-role { font-size: 10px; color: #999; display: block; }

/* ══ CONTENT ════════════════════════════════════════════════════════════════ */
.content { padding: 28px 36px 50px; flex: 1; }

/* Page heading */
.page-heading {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 24px; flex-wrap: wrap; gap: 12px;
}
.page-heading-left h2 { font-size: 22px; font-weight: 700; color: var(--dark); }
.page-heading-left p  { font-size: 13px; color: #999; margin-top: 2px; }
.breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #bbb; margin-bottom: 4px; }
.breadcrumb a { color: var(--red); text-decoration: none; font-weight: 500; }
.breadcrumb a:hover { text-decoration: underline; }
.breadcrumb span { color: #ccc; }

/* ══ ALERT ══════════════════════════════════════════════════════════════════ */
.alert {
    padding: 13px 18px; border-radius: 10px;
    margin-bottom: 22px; border-left: 4px solid;
    display: flex; align-items: center; gap: 10px; font-size: 13.5px;
}
.alert-success { background: #edfaf1; color: #155724; border-color: #28a745; }
.alert-error   { background: #fdf0f0; color: #721c24; border-color: #dc3545; }
.alert i       { font-size: 15px; flex-shrink: 0; }

/* ══ CARD ═══════════════════════════════════════════════════════════════════ */
.card {
    background: var(--white);
    border-radius: var(--radius);
    padding: 28px 30px;
    box-shadow: var(--shadow);
    margin-bottom: 22px;
}
.card-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 24px; padding-bottom: 18px;
    border-bottom: 1.5px solid #f0ebe8;
    flex-wrap: wrap; gap: 12px;
}
.card-title { font-size: 15px; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 9px; }
.card-title i { color: var(--red); font-size: 16px; }
.card-title .ct-sub { font-size: 12px; color: #aaa; font-weight: 400; }

/* ══ FORM GRID ══════════════════════════════════════════════════════════════ */
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px 20px;
}
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-group.form-full { grid-column: 1 / -1; }

.form-group label {
    font-size: 12.5px; font-weight: 600; color: #555;
    display: flex; align-items: center; gap: 5px;
}
.form-group label .req { color: var(--red); }

.form-group input,
.form-group select {
    padding: 11px 14px;
    border: 1.5px solid #e0d8d5;
    border-radius: 9px;
    font-family: 'Poppins'; font-size: 13px;
    color: var(--dark); outline: none;
    background: #fff;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.form-group input:focus,
.form-group select:focus {
    border-color: var(--red);
    box-shadow: 0 0 0 3px rgba(149,18,44,0.07);
}
.form-group input::placeholder { color: #bbb; }
.form-group select { cursor: pointer; }

/* Password wrapper */
.pw-wrap { position: relative; }
.pw-wrap input { padding-right: 44px; width: 100%; }
.pw-toggle {
    position: absolute; right: 13px; top: 50%; transform: translateY(-50%);
    background: none; border: none; cursor: pointer; color: #aaa;
    font-size: 14px; padding: 0; transition: color 0.2s;
}
.pw-toggle:hover { color: var(--red); }

/* Role cards */
.role-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.role-card {
    border: 2px solid #e0d8d5; border-radius: 10px; padding: 14px 16px;
    cursor: pointer; transition: all 0.2s; background: #faf8f7;
    display: flex; align-items: flex-start; gap: 12px;
}
.role-card:hover { border-color: var(--red); }
.role-card.selected { border-color: var(--red); background: #fff8f9; }
.role-card input[type="radio"] { display: none; }
.role-card-icon {
    width: 38px; height: 38px; border-radius: 9px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 17px;
}
.rc-user-icon  { background: #eff6ff; color: #3b82f6; }
.rc-admin-icon { background: #fff7ed; color: #f97316; }
.rc-super-icon { background: #fef2f2; color: var(--red); }
.rc-program-icon { background: #f0fdf4; color: #22c55e; }
.rc-vp-icon { background: #faf5ff; color: #a855f7; }
.role-card-body .rc-name { font-size: 13px; font-weight: 700; color: var(--dark); }
.role-card-body .rc-desc { font-size: 11.5px; color: #999; margin-top: 2px; line-height: 1.4; }
.role-card.selected .rc-name { color: var(--red); }

/* Signatory section */
.signatory-section { 
    display: none; 
    grid-column: 1/-1; 
    margin-top: 8px;
    padding: 16px;
    background: #f8f6f5;
    border-radius: 10px;
    border: 1px solid #e0d8d5;
}
.signatory-section.show { display: block; }
.signatory-section h4 { font-size: 13px; font-weight: 700; color: var(--dark); margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
.signatory-section h4 i { color: var(--red); }
.signatory-cards { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; }

/* File upload */
.file-upload-area {
    border: 2px dashed #e0d8d5; border-radius: 10px;
    padding: 18px 20px; text-align: center;
    cursor: pointer; transition: all 0.2s; background: #faf8f7;
    display: flex; align-items: center; gap: 14px;
}
.file-upload-area:hover { border-color: var(--red); background: #fff8f9; }
.file-upload-area i { font-size: 24px; color: #ccc; flex-shrink: 0; transition: color 0.2s; }
.file-upload-area:hover i { color: var(--red); }
.file-upload-text .fu-title { font-size: 13px; font-weight: 600; color: #555; }
.file-upload-text .fu-sub   { font-size: 11.5px; color: #aaa; margin-top: 2px; }
.file-upload-area input[type="file"] { display: none; }
#previewWrap { display: none; align-items: center; gap: 12px; }
#previewWrap img { width: 50px; height: 50px; border-radius: 50%; object-fit: cover; border: 2px solid var(--red); }
#previewWrap .preview-name { font-size: 13px; font-weight: 600; color: var(--dark); }
#previewWrap .preview-change { font-size: 11.5px; color: var(--red); cursor: pointer; }
#previewWrap .preview-change:hover { text-decoration: underline; }

/* Submit row */
.form-actions { display: flex; align-items: center; gap: 12px; margin-top: 6px; padding-top: 20px; border-top: 1.5px solid #f0ebe8; }
.btn-primary {
    padding: 12px 28px; border-radius: 10px; border: none;
    background: var(--red); color: #fff; font-size: 13.5px; font-weight: 600;
    cursor: pointer; font-family: 'Poppins'; transition: 0.2s;
    display: flex; align-items: center; gap: 8px;
}
.btn-primary:hover { background: var(--red-dark); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(149,18,44,0.25); }
.btn-secondary {
    padding: 12px 22px; border-radius: 10px; border: 1.5px solid #e0d8d5;
    background: #fff; color: #666; font-size: 13px; font-weight: 500;
    cursor: pointer; font-family: 'Poppins'; transition: 0.2s;
    display: flex; align-items: center; gap: 7px;
    text-decoration: none;
}
.btn-secondary:hover { background: var(--bg); border-color: #ccc; color: var(--dark); }

/* Divider hint */
.section-hint { font-size: 12px; color: #bbb; grid-column: 1/-1; margin-top: 4px; padding-top: 14px; border-top: 1px solid #f0ebe8; font-weight: 500; letter-spacing: 0.3px; }
.section-hint i { color: var(--red); margin-right: 4px; }

/* ══ RESPONSIVE ═════════════════════════════════════════════════════════════ */
@media (max-width: 900px) {
    .form-grid { grid-template-columns: 1fr; }
    .form-group.form-full { grid-column: 1; }
    .role-cards { grid-template-columns: 1fr 1fr; }
    .signatory-cards { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 600px) {
    .role-cards { grid-template-columns: 1fr; }
    .signatory-cards { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
    :root { --sidebar-w: 0px; }
    .sidebar { display: none; }
    .main-wrap { margin-left: 0; }
    .content { padding: 20px 16px 40px; }
    .top-header { padding: 0 18px; }
}
</style>
</head>
<body>

<!-- ═══════════════════ SIDEBAR ═════════════════════════════════════════════ -->
<div class="sidebar">
    <a href="super_admin_dashboard.php" class="sidebar-logo">
        <div class="logo-img-wrap">
            <img src="image/logo.png" alt="AssetEase Logo">
            <div class="logo-glow"></div>
        </div>
        <div class="sidebar-logo-text">
            <h2>ASSETEASE</h2>
            <span>Super Admin Portal</span>
        </div>
    </a>

    <div class="admin-badge">
        <img src="https://ui-avatars.com/api/?name=<?= urlencode($superAdminName) ?>&background=95122C&color=fff&rounded=true" alt="Super Admin Avatar">
        <div class="admin-badge-info">
            <div class="ab-name"><?= htmlspecialchars(strlen($superAdminName) > 16 ? substr($superAdminName, 0, 16) . '…' : $superAdminName) ?></div>
            <div class="ab-role"><?= htmlspecialchars($signatoryLabel) ?></div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="super_admin_dashboard.php">
            <div class="nav-icon"><i class="fas fa-table-cells-large"></i></div> Dashboard
        </a>
        <?php if (!$signatoryTitle): ?>
        <a href="super_analytics.php">
            <div class="nav-icon"><i class="fas fa-chart-bar"></i></div> Analytics
        </a>
        <a href="super_history.php">
            <div class="nav-icon"><i class="fas fa-clock-rotate-left"></i></div> History
        </a>
        <?php endif; ?>

        <hr>
        <div class="nav-section-label">Management</div>
        <a href="create_user.php" class="active">
            <div class="nav-icon"><i class="fas fa-users"></i></div> User Management
        </a>

        <hr>
        <div class="nav-section-label">System</div>
        <?php if (!$signatoryTitle): ?>
        <a href="super_settings.php">
            <div class="nav-icon"><i class="fas fa-gear"></i></div> Settings
        </a>
        <?php endif; ?>
        <a href="logout.php">
            <div class="nav-icon"><i class="fas fa-right-from-bracket"></i></div> Logout
        </a>
    </nav>

    <div class="sidebar-promo">
        <strong>Need help?</strong>
        <p>Check the admin guide or contact support for assistance.</p>
        <a href="super_settings.php"><i class="fas fa-arrow-right"></i> Go to Settings</a>
    </div>
</div>

<!-- ═══════════════════ MAIN WRAPPER ════════════════════════════════════════ -->
<div class="main-wrap">

    <!-- TOP HEADER -->
    <div class="top-header">
        <div class="header-left">
            <div class="header-logo-mini">
                <img src="image/logo.png" alt="AssetEase">
                <span>ASSETEASE</span>
            </div>
            <div class="header-title-area">
                <h1>User Management</h1>
                <p>Welcome back, <strong><?= htmlspecialchars($superAdminName) ?></strong> — <?= date('l, F j, Y') ?></p>
            </div>
        </div>
        <div class="header-right">
            <div class="profile-pill">
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($superAdminName) ?>&background=95122C&color=fff&rounded=true" alt="Avatar">
                <div>
                    <span class="pp-name"><?= htmlspecialchars($superAdminName) ?></span>
                    <span class="pp-role"><?= htmlspecialchars($signatoryLabel) ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- CONTENT -->
    <div class="content">

        <!-- Breadcrumb + page heading -->
        <div class="page-heading">
            <div class="page-heading-left">
                <div class="breadcrumb">
                    <a href="super_admin_dashboard.php"><i class="fas fa-table-cells-large"></i> Dashboard</a>
                    <span>/</span>
                    <span>User Management</span>
                </div>
                <h2>Create New Account</h2>
                <p>Register a new user, admin, or super admin for the equipment management system.</p>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-circle-exclamation"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-circle-check"></i>
                <?= $success ?>
            </div>
        <?php endif; ?>

        <!-- Form Card -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-user-plus"></i>
                    Account Details
                    <span class="ct-sub">— Fill in all required fields</span>
                </div>
            </div>

            <form method="POST" enctype="multipart/form-data" id="createForm">

                <div class="form-grid">

                    <!-- Username -->
                    <div class="form-group">
                        <label for="username">Username <span class="req">*</span></label>
                        <input type="text" id="username" name="username" placeholder="e.g. jdoe" required
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                    </div>

                    <!-- Full Name -->
                    <div class="form-group">
                        <label for="full_name">Full Name <span class="req">*</span></label>
                        <input type="text" id="full_name" name="full_name" placeholder="e.g. John Doe" required
                               value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
                    </div>

                    <!-- Email -->
                    <div class="form-group">
                        <label for="email">Email Address <span class="req">*</span></label>
                        <input type="email" id="email" name="email" placeholder="e.g. jdoe@example.com" required
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>

                    <!-- Password -->
                    <div class="form-group">
                        <label for="password">Password <span class="req">*</span></label>
                        <div class="pw-wrap">
                            <input type="password" id="password" name="password" placeholder="Create a strong password" required>
                            <button type="button" class="pw-toggle" onclick="togglePw()" title="Show/hide password">
                                <i class="fas fa-eye" id="pwIcon"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Role -->
                    <div class="form-group form-full">
                        <label>User Role <span class="req">*</span></label>
                        <div class="role-cards">
                            <label class="role-card <?= (($_POST['role'] ?? 'user') === 'user') ? 'selected' : '' ?>" id="rcUser">
                                <input type="radio" name="role" value="user" <?= (($_POST['role'] ?? 'user') === 'user') ? 'checked' : '' ?> onchange="selectRole(this)">
                                <div class="role-card-icon rc-user-icon"><i class="fas fa-user"></i></div>
                                <div class="role-card-body">
                                    <div class="rc-name">User</div>
                                    <div class="rc-desc">Can browse and submit equipment borrow requests.</div>
                                </div>
                            </label>
                            <label class="role-card <?= (($_POST['role'] ?? '') === 'admin') ? 'selected' : '' ?>" id="rcAdmin">
                                <input type="radio" name="role" value="admin" <?= (($_POST['role'] ?? '') === 'admin') ? 'checked' : '' ?> onchange="selectRole(this)">
                                <div class="role-card-icon rc-admin-icon"><i class="fas fa-user-shield"></i></div>
                                <div class="role-card-body">
                                    <div class="rc-name">Admin</div>
                                    <div class="rc-desc">Can approve and manage room reservations.</div>
                                </div>
                            </label>
                            <label class="role-card <?= (($_POST['role'] ?? '') === 'super_admin' && empty($_POST['signatory_type'])) ? 'selected' : '' ?>" id="rcSuper">
                                <input type="radio" name="role" value="super_admin" <?= (($_POST['role'] ?? '') === 'super_admin' && empty($_POST['signatory_type'])) ? 'checked' : '' ?> onchange="selectRole(this)">
                                <div class="role-card-icon rc-super-icon"><i class="fas fa-crown"></i></div>
                                <div class="role-card-body">
                                    <div class="rc-name">Super Admin</div>
                                    <div class="rc-desc">Full system control — users, equipment, settings.</div>
                                </div>
                            </label>
                            <label class="role-card <?= ($_POST['signatory_type'] ?? '') === 'signatory' ? 'selected' : '' ?>" id="rcSignatory">
                                <input type="radio" name="role" value="super_admin" data-signatory="true" <?= !empty($_POST['signatory_type']) ? 'checked' : '' ?> onchange="selectRole(this)">
                                <div class="role-card-icon rc-program-icon"><i class="fas fa-file-signature"></i></div>
                                <div class="role-card-body">
                                    <div class="rc-name">Signatory</div>
                                    <div class="rc-desc">Program Head or VP for equipment approval.</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Signatory Type Selection (shown when Signatory is selected) -->
                    <div class="form-group form-full signatory-section <?= !empty($_POST['signatory_type']) ? 'show' : '' ?>" id="signatorySection">
                        <h4><i class="fas fa-id-badge"></i> Select Signatory Type</h4>
                        <div class="signatory-cards role-cards" style="grid-template-columns: 1fr 1fr;">
                            <label class="role-card <?= ($_POST['signatory_type'] ?? '') === 'program_head' ? 'selected' : '' ?>">
                                <input type="radio" name="signatory_type" value="program_head" <?= ($_POST['signatory_type'] ?? '') === 'program_head' ? 'checked' : '' ?> onchange="selectSignatory(this)">
                                <div class="role-card-icon rc-program-icon"><i class="fas fa-user-tie"></i></div>
                                <div class="role-card-body">
                                    <div class="rc-name">Program Head</div>
                                    <div class="rc-desc">Recommends equipment requests for their program.</div>
                                </div>
                            </label>
                            <label class="role-card <?= ($_POST['signatory_type'] ?? '') === 'vp_admin' ? 'selected' : '' ?>">
                                <input type="radio" name="signatory_type" value="vp_admin" <?= ($_POST['signatory_type'] ?? '') === 'vp_admin' ? 'checked' : '' ?> onchange="selectSignatory(this)">
                                <div class="role-card-icon rc-vp-icon"><i class="fas fa-user-graduate"></i></div>
                                <div class="role-card-body">
                                    <div class="rc-name">VP for Admin & Finance</div>
                                    <div class="rc-desc">Final approval of equipment requests.</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Profile Picture -->
                    <div class="form-group form-full">
                        <div class="section-hint"><i class="fas fa-image"></i> Profile Picture (Optional)</div>
                    </div>
                    <div class="form-group form-full">
                        <label class="file-upload-area" id="uploadArea" for="profile_picture">
                            <i class="fas fa-cloud-arrow-up" id="uploadIcon"></i>
                            <div class="file-upload-text" id="uploadText">
                                <div class="fu-title">Click to upload profile picture</div>
                                <div class="fu-sub">JPG, PNG, GIF or WEBP — max 2 MB</div>
                            </div>
                            <div id="previewWrap">
                                <img id="previewImg" src="" alt="Preview">
                                <div>
                                    <div class="preview-name" id="previewName"></div>
                                    <div class="preview-change">Click to change</div>
                                </div>
                            </div>
                            <input type="file" id="profile_picture" name="profile_picture" accept="image/*" onchange="previewFile(this)">
                        </label>
                    </div>

                </div><!-- /form-grid -->

                <div class="form-actions">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-plus"></i> Create Account
                    </button>
                    <a href="super_admin_dashboard.php" class="btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>

            </form>
        </div><!-- /card -->

    </div><!-- /content -->
</div><!-- /main-wrap -->

<script>
/* ── Password toggle ─────────────────────────────────────────────────── */
function togglePw() {
    const inp  = document.getElementById('password');
    const icon = document.getElementById('pwIcon');
    if (inp.type === 'password') {
        inp.type  = 'text';
        icon.className = 'fas fa-eye-slash';
    } else {
        inp.type  = 'password';
        icon.className = 'fas fa-eye';
    }
}

/* ── Role card selection ──────────────────────────────────────────────── */
function selectRole(radio) {
    document.querySelectorAll('.role-cards > .role-card').forEach(c => c.classList.remove('selected'));
    radio.closest('.role-card').classList.add('selected');
    
    // Show/hide signatory section
    const signatorySection = document.getElementById('signatorySection');
    const isSignatory = radio.dataset.signatory === 'true';
    
    if (isSignatory) {
        signatorySection.classList.add('show');
    } else {
        signatorySection.classList.remove('show');
        // Clear signatory selections when not signatory
        document.querySelectorAll('input[name="signatory_type"]').forEach(r => r.checked = false);
        document.querySelectorAll('#signatorySection .role-card').forEach(c => c.classList.remove('selected'));
    }
}

/* ── Signatory type selection ─────────────────────────────────────────── */
function selectSignatory(radio) {
    document.querySelectorAll('#signatorySection .role-card').forEach(c => c.classList.remove('selected'));
    radio.closest('.role-card').classList.add('selected');
}

/* ── File preview ────────────────────────────────────────────────────── */
function previewFile(input) {
    if (!input.files || !input.files[0]) return;
    const file   = input.files[0];
    const reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('uploadIcon').style.display    = 'none';
        document.getElementById('uploadText').style.display    = 'none';
        const wrap = document.getElementById('previewWrap');
        wrap.style.display = 'flex';
        document.getElementById('previewImg').src  = e.target.result;
        document.getElementById('previewName').textContent = file.name;
    };
    reader.readAsDataURL(file);
}

/* ── Form validation ─────────────────────────────────────────────────── */
document.getElementById('createForm').addEventListener('submit', function(e) {
    const signatorySection = document.getElementById('signatorySection');
    if (signatorySection.classList.contains('show')) {
        const signatoryType = document.querySelector('input[name="signatory_type"]:checked');
        if (!signatoryType) {
            e.preventDefault();
            alert('Please select a signatory type.');
            return false;
        }
    }
});
</script>
</body>
</html>