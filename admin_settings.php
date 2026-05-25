<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'config.php';
require_once 'notification_handler.php';
require_once 'email_notification.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'super_admin')) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

$adminStmt = $conn->prepare("SELECT uname FROM users WHERE id = ?");
$adminStmt->bind_param("i", $user_id);
$adminStmt->execute();
$adminRow  = $adminStmt->get_result()->fetch_assoc();
$adminName = $adminRow['uname'] ?? 'Admin';

$success_message = '';
$error_message   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_room_reservation   = isset($_POST['new_room_reservation'])   ? 1 : 0;
    $approval_notification  = isset($_POST['approval_notification'])  ? 1 : 0;
    $rejection_notification = isset($_POST['rejection_notification']) ? 1 : 0;
    $reminder_notification  = isset($_POST['reminder_notification'])  ? 1 : 0;
    $return_notification    = isset($_POST['return_notification'])    ? 1 : 0;
    $conflict_notification  = isset($_POST['conflict_notification'])  ? 1 : 0;

    $check = $conn->prepare("SELECT id FROM notification_preferences WHERE user_id = ?");
    $check->bind_param("i", $user_id);
    $check->execute();
    $checkResult = $check->get_result();

    if ($checkResult->num_rows > 0) {
        $stmt = $conn->prepare("UPDATE notification_preferences SET
            new_room_reservation   = ?,
            approval_notification  = ?,
            rejection_notification = ?,
            reminder_notification  = ?,
            return_notification    = ?,
            conflict_notification  = ?
            WHERE user_id = ?");
        $stmt->bind_param("iiiiiii",
            $new_room_reservation, $approval_notification, $rejection_notification,
            $reminder_notification, $return_notification, $conflict_notification,
            $user_id);
    } else {
        $stmt = $conn->prepare("INSERT INTO notification_preferences
            (user_id, new_room_reservation, approval_notification, rejection_notification,
             reminder_notification, return_notification, conflict_notification)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiiiiii",
            $user_id, $new_room_reservation, $approval_notification, $rejection_notification,
            $reminder_notification, $return_notification, $conflict_notification);
    }

    if ($stmt->execute()) {
        $success_message = "Notification preferences saved successfully.";
    } else {
        $error_message = "Error saving preferences: " . $stmt->error;
    }
}

$prefs_query = $conn->prepare("SELECT * FROM notification_preferences WHERE user_id = ?");
$prefs_query->bind_param("i", $user_id);
$prefs_query->execute();
$prefs = $prefs_query->get_result()->fetch_assoc() ?: [
    'new_room_reservation'   => 1,
    'approval_notification'  => 1,
    'rejection_notification' => 1,
    'reminder_notification'  => 1,
    'return_notification'    => 1,
    'conflict_notification'  => 1,
];
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
}
.sidebar-logo {
    display: flex; align-items: center; gap: 13px;
    padding: 26px 22px 20px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    flex-shrink: 0; text-decoration: none;
    border-left: none !important; background: none !important;
    border-radius: 0; margin: 0;
}
.logo-img-wrap { position: relative; width: 44px; height: 44px; flex-shrink: 0; }
.logo-img-wrap img { width: 44px; height: 44px; object-fit: cover; border-radius: 10px; background: #fff; padding: 2px; border: 2px solid rgba(255,215,0,0.5); }
.logo-img-wrap .logo-glow { position: absolute; inset: -4px; border-radius: 14px; background: radial-gradient(circle, rgba(255,215,0,0.25) 0%, transparent 70%); pointer-events: none; }
.sidebar-logo-text h2 { color: var(--gold); font-size: 18px; font-weight: 700; letter-spacing: 2px; line-height: 1; }
.sidebar-logo-text span { font-size: 10px; color: rgba(255,255,255,0.5); letter-spacing: 1px; text-transform: uppercase; }

.admin-badge {
    margin: 14px 16px 6px; display: flex; align-items: center; gap: 10px;
    padding: 10px 14px; background: rgba(255,215,0,0.12);
    border: 1px solid rgba(255,215,0,0.25); border-radius: 10px; flex-shrink: 0;
}
.admin-badge img { width: 34px; height: 34px; border-radius: 50%; border: 2px solid var(--gold); object-fit: cover; }
.admin-badge-info .ab-name { font-size: 12px; font-weight: 700; color: #fff; line-height: 1; }
.admin-badge-info .ab-role { font-size: 10px; color: var(--gold); text-transform: uppercase; letter-spacing: 0.8px; margin-top: 3px; }

.sidebar-nav { flex: 1; overflow-y: auto; padding: 10px 0 8px; scrollbar-width: none; }
.sidebar-nav::-webkit-scrollbar { display: none; }
.nav-section-label { padding: 14px 22px 5px; font-size: 9.5px; font-weight: 700; letter-spacing: 2.5px; color: rgba(255,255,255,0.38); text-transform: uppercase; }

.sidebar a {
    display: flex; align-items: center; gap: 12px; padding: 11px 22px;
    color: rgba(255,255,255,0.78); text-decoration: none; font-size: 0.855rem; font-weight: 500;
    border-left: 3px solid transparent; transition: all 0.22s ease;
    margin: 1px 8px; border-radius: 8px;
}
.sidebar a .nav-icon { width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; font-size: 14px; border-radius: 7px; background: rgba(255,255,255,0.06); flex-shrink: 0; transition: all 0.22s; }
.sidebar a:hover { background: rgba(255,255,255,0.10); color: #fff; border-left-color: rgba(255,255,255,0.3); }
.sidebar a.active { background: rgba(255,255,255,0.15); color: #fff; border-left-color: var(--gold); font-weight: 600; }
.sidebar a.active .nav-icon { background: rgba(255,215,0,0.18); color: var(--gold); }
.sidebar a:hover .nav-icon { background: rgba(255,255,255,0.12); }
.sidebar hr { border: none; border-top: 1px solid rgba(255,255,255,0.09); margin: 8px 18px; }

.sidebar-promo {
    margin: 8px 14px 18px; padding: 14px 15px;
    background: linear-gradient(135deg, rgba(255,215,0,0.18), rgba(255,255,255,0.06));
    border: 1px solid rgba(255,215,0,0.28); border-radius: 12px; flex-shrink: 0;
}
.sidebar-promo p { font-size: 11.5px; color: rgba(255,255,255,0.82); line-height: 1.5; }
.sidebar-promo strong { color: var(--gold); }
.sidebar-promo a {
    display: inline-flex; align-items: center; gap: 6px; margin-top: 9px;
    padding: 6px 14px; background: var(--gold) !important; color: var(--dark);
    border-radius: 7px !important; font-size: 11px; font-weight: 700;
    text-decoration: none; border: none !important; border-left: none !important; margin-left: 0 !important;
}
.sidebar-promo a:hover { opacity: 0.9; transform: none; }

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

.page-heading { margin-bottom: 24px; }
.breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #bbb; margin-bottom: 4px; }
.breadcrumb a { color: var(--red); text-decoration: none; font-weight: 500; }
.breadcrumb a:hover { text-decoration: underline; }
.breadcrumb span { color: #ccc; }
.page-heading h2 { font-size: 22px; font-weight: 700; color: var(--dark); }
.page-heading p  { font-size: 13px; color: #999; margin-top: 2px; }

/* ══ ALERTS ═════════════════════════════════════════════════════════════════ */
.alert {
    padding: 13px 18px; border-radius: 10px; margin-bottom: 22px;
    border-left: 4px solid; display: flex; align-items: center; gap: 10px; font-size: 13.5px;
}
.alert-success { background: #edfaf1; color: #155724; border-color: #28a745; }
.alert-error   { background: #fdf0f0; color: #721c24; border-color: #dc3545; }

/* ══ CARD ═══════════════════════════════════════════════════════════════════ */
.card {
    background: var(--white); border-radius: var(--radius);
    padding: 28px 30px; box-shadow: var(--shadow); margin-bottom: 22px;
}
.card-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 24px; padding-bottom: 18px;
    border-bottom: 1.5px solid #f0ebe8; flex-wrap: wrap; gap: 12px;
}
.card-title { font-size: 15px; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 9px; }
.card-title i { color: var(--red); font-size: 16px; }
.card-title .ct-sub { font-size: 12px; color: #aaa; font-weight: 400; }

/* ══ SETTING ITEMS ══════════════════════════════════════════════════════════ */
.setting-item {
    display: flex; justify-content: space-between; align-items: center;
    padding: 18px 20px; border: 1.5px solid #f0ebe8; border-radius: 11px;
    margin-bottom: 12px; transition: border-color 0.2s, box-shadow 0.2s; background: #faf8f7;
}
.setting-item:last-of-type { margin-bottom: 0; }
.setting-item:hover { border-color: rgba(149,18,44,0.18); box-shadow: 0 2px 10px rgba(149,18,44,0.05); }

.setting-item-left { display: flex; align-items: center; gap: 14px; }
.setting-icon {
    width: 40px; height: 40px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; flex-shrink: 0;
}
.si-red    { background: #fef2f2; color: var(--red); }
.si-green  { background: #edfaf1; color: #1a7a3c; }
.si-amber  { background: #fff8e6; color: #b07800; }
.si-blue   { background: #eff6ff; color: #3b82f6; }
.si-purple { background: #f5f3ff; color: #7c3aed; }
.si-teal   { background: #f0fdfa; color: #0d9488; }

.setting-info .si-title { font-size: 13.5px; font-weight: 600; color: var(--dark); margin-bottom: 3px; }
.setting-info .si-desc  { font-size: 12px; color: #999; line-height: 1.4; }

/* ══ TOGGLE ═════════════════════════════════════════════════════════════════ */
.toggle { position: relative; display: inline-block; width: 50px; height: 26px; flex-shrink: 0; }
.toggle input { opacity: 0; width: 0; height: 0; }
.slider {
    position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
    background: #ddd; transition: .3s; border-radius: 34px;
}
.slider::before {
    position: absolute; content: ""; height: 19px; width: 19px;
    left: 4px; bottom: 3.5px; background: #fff; transition: .3s; border-radius: 50%;
    box-shadow: 0 1px 4px rgba(0,0,0,0.15);
}
input:checked + .slider { background: var(--red); }
input:checked + .slider::before { transform: translateX(23px); }

/* ══ SAVE BUTTON ════════════════════════════════════════════════════════════ */
.form-actions { display: flex; align-items: center; gap: 12px; margin-top: 24px; padding-top: 20px; border-top: 1.5px solid #f0ebe8; }
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
    display: flex; align-items: center; gap: 7px; text-decoration: none;
}
.btn-secondary:hover { background: var(--bg); border-color: #ccc; color: var(--dark); }

/* ══ RESPONSIVE ═════════════════════════════════════════════════════════════ */
@media (max-width: 768px) {
    :root { --sidebar-w: 0px; }
    .sidebar { display: none; }
    .main-wrap { margin-left: 0; }
    .content { padding: 20px 16px 40px; }
    .top-header { padding: 0 18px; }
    .setting-item { flex-direction: column; align-items: flex-start; gap: 14px; }
}
</style>
</head>
<body>

<!-- ═══════════════════ SIDEBAR ═════════════════════════════════════════════ -->
<div class="sidebar">
    <a href="admin_dashboard.php" class="sidebar-logo">
        <div class="logo-img-wrap">
            <img src="image/logo.png" alt="AssetEase Logo">
            <div class="logo-glow"></div>
        </div>
        <div class="sidebar-logo-text">
            <h2>ASSETEASE</h2>
            <span>Admin Portal</span>
        </div>
    </a>

    <div class="admin-badge">
        <img src="https://ui-avatars.com/api/?name=<?= urlencode($adminName) ?>&background=95122C&color=fff&rounded=true" alt="Admin Avatar">
        <div class="admin-badge-info">
            <div class="ab-name"><?= htmlspecialchars(strlen($adminName) > 16 ? substr($adminName, 0, 16) . '…' : $adminName) ?></div>
            <div class="ab-role"><?= htmlspecialchars($_SESSION['role'] ?? 'Admin') ?></div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="admin_dashboard.php">
            <div class="nav-icon"><i class="fas fa-table-cells-large"></i></div> Dashboard
        </a>
        <a href="admin_history.php">
            <div class="nav-icon"><i class="fas fa-clock-rotate-left"></i></div> History
        </a>
        <a href="admin_dashboard.php?section=analytics">
            <div class="nav-icon"><i class="fas fa-chart-line"></i></div> Analytics
        </a>

        <hr>
        <div class="nav-section-label">Management</div>
        <a href="create_admin.php">
            <div class="nav-icon"><i class="fas fa-users"></i></div> User Management
        </a>

        <hr>
        <div class="nav-section-label">System</div>
        <a href="admin_settings.php" class="active">
            <div class="nav-icon"><i class="fas fa-gear"></i></div> Settings
        </a>
        <a href="logout.php">
            <div class="nav-icon"><i class="fas fa-right-from-bracket"></i></div> Logout
        </a>
    </nav>

    <div class="sidebar-promo">
        <strong>Need help?</strong>
        <p>Check the admin guide or contact support for assistance.</p>
        <a href="#"><i class="fas fa-arrow-right"></i> View Guide</a>
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
                <h1>Settings</h1>
                <p>Welcome back, <strong><?= htmlspecialchars($adminName) ?></strong> — <?= date('l, F j, Y') ?></p>
            </div>
        </div>
        <div class="header-right">
            <div class="profile-pill">
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($adminName) ?>&background=95122C&color=fff&rounded=true" alt="Avatar">
                <div>
                    <span class="pp-name"><?= htmlspecialchars($adminName) ?></span>
                    <span class="pp-role"><?= htmlspecialchars($_SESSION['role'] ?? 'Admin') ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- CONTENT -->
    <div class="content">

        <div class="page-heading">
            <div class="breadcrumb">
                <a href="admin_dashboard.php"><i class="fas fa-table-cells-large"></i> Dashboard</a>
                <span>/</span>
                <span>Settings</span>
            </div>
            <h2>Notification Settings</h2>
            <p>Manage how you receive notifications about reservations and bookings.</p>
        </div>

        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <i class="fas fa-circle-check"></i>
                <?= htmlspecialchars($success_message) ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <i class="fas fa-circle-exclamation"></i>
                <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-bell"></i>
                    Notification Preferences
                    <span class="ct-sub">— Toggle which alerts you receive</span>
                </div>
            </div>

            <form method="POST">

                <div class="setting-item">
                    <div class="setting-item-left">
                        <div class="setting-icon si-red"><i class="fas fa-calendar-plus"></i></div>
                        <div class="setting-info">
                            <div class="si-title">New Room Reservation Alert</div>
                            <div class="si-desc">Get notified immediately when a user submits a room reservation request.</div>
                        </div>
                    </div>
                    <label class="toggle">
                        <input type="checkbox" name="new_room_reservation" <?= !empty($prefs['new_room_reservation']) ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="setting-item">
                    <div class="setting-item-left">
                        <div class="setting-icon si-green"><i class="fas fa-circle-check"></i></div>
                        <div class="setting-info">
                            <div class="si-title">Approval Notification</div>
                            <div class="si-desc">Receive an email when a reservation or borrow request is approved.</div>
                        </div>
                    </div>
                    <label class="toggle">
                        <input type="checkbox" name="approval_notification" <?= !empty($prefs['approval_notification']) ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="setting-item">
                    <div class="setting-item-left">
                        <div class="setting-icon si-amber"><i class="fas fa-circle-xmark"></i></div>
                        <div class="setting-info">
                            <div class="si-title">Rejection Notification</div>
                            <div class="si-desc">Receive an email when a reservation or borrow request is rejected.</div>
                        </div>
                    </div>
                    <label class="toggle">
                        <input type="checkbox" name="rejection_notification" <?= !empty($prefs['rejection_notification']) ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="setting-item">
                    <div class="setting-item-left">
                        <div class="setting-icon si-blue"><i class="fas fa-clock"></i></div>
                        <div class="setting-info">
                            <div class="si-title">Booking Reminder</div>
                            <div class="si-desc">Receive a reminder before your scheduled reservation or equipment borrow.</div>
                        </div>
                    </div>
                    <label class="toggle">
                        <input type="checkbox" name="reminder_notification" <?= !empty($prefs['reminder_notification']) ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="setting-item">
                    <div class="setting-item-left">
                        <div class="setting-icon si-purple"><i class="fas fa-rotate-left"></i></div>
                        <div class="setting-info">
                            <div class="si-title">Equipment Return Reminder</div>
                            <div class="si-desc">Get reminded when borrowed equipment is due for return.</div>
                        </div>
                    </div>
                    <label class="toggle">
                        <input type="checkbox" name="return_notification" <?= !empty($prefs['return_notification']) ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="setting-item">
                    <div class="setting-item-left">
                        <div class="setting-icon si-teal"><i class="fas fa-triangle-exclamation"></i></div>
                        <div class="setting-info">
                            <div class="si-title">Schedule Conflict Warning</div>
                            <div class="si-desc">Get alerted when there is a conflict in booking schedules.</div>
                        </div>
                    </div>
                    <label class="toggle">
                        <input type="checkbox" name="conflict_notification" <?= !empty($prefs['conflict_notification']) ? 'checked' : '' ?>>
                        <span class="slider"></span>
                    </label>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-floppy-disk"></i> Save Changes
                    </button>
                    <a href="admin_dashboard.php" class="btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>

            </form>
        </div>

    </div><!-- /content -->
</div><!-- /main-wrap -->

</body>
</html>