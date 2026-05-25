<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: login.php");
    exit;
}

$superAdminId = $_SESSION['user_id'];

$superAdminStmt = $conn->prepare("SELECT uname FROM users WHERE id = ?");
$superAdminStmt->bind_param("i", $superAdminId);
$superAdminStmt->execute();
$superAdminName = $superAdminStmt->get_result()->fetch_assoc()['uname'] ?? 'Super Admin';

// Notifications
$notifStmt = $conn->prepare("SELECT id, message, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$notifStmt->bind_param("i", $superAdminId);
$notifStmt->execute();
$notifList  = [];
while ($n = $notifStmt->get_result()->fetch_assoc()) $notifList[] = $n;
$notifCount = count($notifList);

$success = '';
$error   = '';

// Handle settings save (placeholder — wire to DB as needed)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $success = "Settings saved successfully!";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings — AssetEase</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* ══ RESET & BASE ══════════════════════════════════════════════════════════ */
:root {
    --red:        #95122C;
    --red-dark:   #7a0c23;
    --dark:       #100C08;
    --gold:       #FFD700;
    --bg:         #F5EFED;
    --white:      #ffffff;
    --shadow:     0 4px 20px rgba(0,0,0,0.08);
    --shadow-md:  0 8px 30px rgba(0,0,0,0.12);
    --radius:     14px;
    --sidebar-w:  268px;
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
}
.logo-img-wrap {
    position: relative;
    width: 44px;
    height: 44px;
    flex-shrink: 0;
}
.logo-img-wrap img {
    width: 44px;
    height: 44px;
    object-fit: cover;
    border-radius: 10px;
    background: #fff;
    padding: 2px;
    border: 2px solid rgba(255,215,0,0.5);
}
.logo-img-wrap .logo-glow {
    position: absolute;
    inset: -4px;
    border-radius: 14px;
    background: radial-gradient(circle, rgba(255,215,0,0.25) 0%, transparent 70%);
    pointer-events: none;
}
.sidebar-logo-text h2 {
    color: var(--gold);
    font-size: 18px;
    font-weight: 700;
    letter-spacing: 2px;
    line-height: 1;
}
.sidebar-logo-text span {
    font-size: 10px;
    color: rgba(255,255,255,0.5);
    letter-spacing: 1px;
    text-transform: uppercase;
}
.admin-badge {
    margin: 14px 16px 6px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    background: rgba(255,215,0,0.12);
    border: 1px solid rgba(255,215,0,0.25);
    border-radius: 10px;
    flex-shrink: 0;
}
.admin-badge img {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    border: 2px solid var(--gold);
    object-fit: cover;
}
.admin-badge-info .ab-name { font-size: 12px; font-weight: 700; color: #fff; line-height: 1; }
.admin-badge-info .ab-role { font-size: 10px; color: var(--gold); text-transform: uppercase; letter-spacing: 0.8px; margin-top: 3px; }

.sidebar-nav { flex: 1; overflow-y: auto; padding: 10px 0 8px; scrollbar-width: none; }
.sidebar-nav::-webkit-scrollbar { display: none; }

.nav-section-label {
    padding: 14px 22px 5px;
    font-size: 9.5px;
    font-weight: 700;
    letter-spacing: 2.5px;
    color: rgba(255,255,255,0.38);
    text-transform: uppercase;
}
.sidebar a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 22px;
    color: rgba(255,255,255,0.78);
    text-decoration: none;
    font-size: 0.855rem;
    font-weight: 500;
    border-left: 3px solid transparent;
    transition: all 0.22s ease;
    margin: 1px 8px;
    border-radius: 8px;
}
.sidebar a .nav-icon {
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    border-radius: 7px;
    background: rgba(255,255,255,0.06);
    flex-shrink: 0;
    transition: all 0.22s;
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
    border-radius: 12px;
    flex-shrink: 0;
}
.sidebar-promo p { font-size: 11.5px; color: rgba(255,255,255,0.82); line-height: 1.5; }
.sidebar-promo strong { color: var(--gold); }
.sidebar-promo a {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 9px;
    padding: 6px 14px;
    background: var(--gold) !important;
    color: var(--dark) !important;
    border-radius: 7px !important;
    font-size: 11px;
    font-weight: 700;
    text-decoration: none;
    border: none !important;
    border-left: none !important;
    margin-left: 0 !important;
}
.sidebar-promo a:hover { opacity: 0.9; }

/* ══ MAIN LAYOUT ════════════════════════════════════════════════════════════ */
.main-wrap { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

/* ══ TOP HEADER ════════════════════════════════════════════════════════════ */
.top-header {
    position: sticky;
    top: 0;
    background: var(--white);
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0 36px;
    height: 68px;
    box-shadow: 0 1px 0 rgba(0,0,0,0.07), 0 2px 12px rgba(0,0,0,0.04);
    z-index: 999;
    gap: 16px;
}
.header-left { display: flex; align-items: center; gap: 14px; min-width: 0; }
.header-logo-mini { display: flex; align-items: center; gap: 9px; padding-right: 14px; border-right: 1px solid #eee; }
.header-logo-mini img { width: 34px; height: 34px; border-radius: 8px; object-fit: cover; border: 1.5px solid rgba(149,18,44,0.2); }
.header-logo-mini span { font-size: 14px; font-weight: 700; color: var(--red); letter-spacing: 1.5px; }
.header-title-area h1 { font-size: 19px; font-weight: 700; color: var(--dark); line-height: 1.1; }
.header-title-area p  { font-size: 12px; color: #999; margin-top: 1px; }
.header-right { display: flex; align-items: center; gap: 11px; flex-shrink: 0; }

.notif-wrap { position: relative; }
.notif-btn {
    width: 40px; height: 40px; border-radius: 50%;
    background: var(--bg); border: none;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: var(--dark); font-size: 16px; transition: 0.2s;
}
.notif-btn:hover { background: #ede6e3; }
.notif-badge {
    position: absolute; top: -1px; right: -1px;
    background: #dc3545; color: #fff;
    font-size: 9px; font-weight: 700;
    width: 17px; height: 17px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    border: 2px solid #fff;
}
.notif-dropdown {
    position: absolute; top: 50px; right: 0; width: 360px;
    background: #fff; border-radius: var(--radius);
    box-shadow: var(--shadow-md);
    z-index: 2000; display: none; max-height: 390px; overflow-y: auto;
    border: 1px solid rgba(0,0,0,0.07);
}
.notif-dropdown.active { display: block; animation: fadeDown 0.18s ease; }
@keyframes fadeDown { from { opacity:0; transform: translateY(-6px); } to { opacity:1; transform: translateY(0); } }
.notif-header { padding: 14px 18px 10px; font-weight: 700; font-size: 13px; color: var(--dark); border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
.notif-item { padding: 12px 18px; border-bottom: 1px solid #f8f8f8; cursor: pointer; display: flex; gap: 10px; }
.notif-item:hover { background: #fafafa; }
.notif-item .ni-icon { color: var(--red); font-size: 13px; margin-top: 2px; flex-shrink: 0; }
.notif-item .ni-msg  { font-size: 12.5px; color: #444; line-height: 1.4; }
.notif-item .ni-time { font-size: 11px; color: #bbb; margin-top: 2px; }
.notif-empty { padding: 28px; text-align: center; color: #bbb; font-size: 13px; }

.profile-pill {
    display: flex; align-items: center; gap: 9px;
    background: var(--bg); border-radius: 30px; padding: 5px 14px 5px 6px;
    cursor: pointer; transition: 0.2s; border: 1px solid transparent;
}
.profile-pill:hover { background: #ede6e3; border-color: rgba(149,18,44,0.15); }
.profile-pill img { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; border: 2px solid var(--red); }
.profile-pill span { font-size: 13px; font-weight: 600; color: var(--dark); }
.profile-pill .pp-role { font-size: 10px; color: #999; display: block; }

/* ══ CONTENT ════════════════════════════════════════════════════════════════ */
.content { padding: 32px 36px 48px; flex: 1; }

/* ══ PAGE HEADER ════════════════════════════════════════════════════════════ */
.page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 28px;
    gap: 16px;
}
.page-header-left h2 {
    font-size: 22px;
    font-weight: 700;
    color: var(--dark);
    display: flex;
    align-items: center;
    gap: 10px;
}
.page-header-left h2 i {
    width: 38px; height: 38px;
    background: linear-gradient(135deg, var(--red), var(--red-dark));
    color: #fff;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
}
.page-header-left p { font-size: 13px; color: #999; margin-top: 4px; padding-left: 48px; }

/* ══ ALERT BANNERS ══════════════════════════════════════════════════════════ */
.alert { padding: 13px 18px; border-radius: 10px; font-size: 13.5px; font-weight: 500; margin-bottom: 22px; display: flex; align-items: center; gap: 10px; }
.alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
.alert-error   { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }

/* ══ SETTINGS SECTIONS ══════════════════════════════════════════════════════ */
.settings-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 22px;
}
.settings-card {
    background: var(--white);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    overflow: hidden;
}
.settings-card.full-width { grid-column: 1 / -1; }
.card-head {
    padding: 20px 24px 16px;
    border-bottom: 1px solid #f0ebe8;
    display: flex;
    align-items: center;
    gap: 12px;
}
.card-head-icon {
    width: 36px; height: 36px;
    border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px;
    flex-shrink: 0;
}
.card-head-icon.red   { background: rgba(149,18,44,0.1);  color: var(--red); }
.card-head-icon.blue  { background: rgba(0,102,204,0.1);  color: #0066cc; }
.card-head-icon.green { background: rgba(34,197,94,0.1);  color: #16a34a; }
.card-head-icon.amber { background: rgba(245,158,11,0.1); color: #d97706; }
.card-head h3 { font-size: 14.5px; font-weight: 700; color: var(--dark); line-height: 1.1; }
.card-head p  { font-size: 11.5px; color: #aaa; margin-top: 2px; }
.card-body { padding: 20px 24px 24px; }

/* ══ TOGGLE ROWS ════════════════════════════════════════════════════════════ */
.setting-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 0;
    border-bottom: 1px solid #f5f0ee;
    gap: 16px;
}
.setting-row:last-child { border-bottom: none; padding-bottom: 0; }
.setting-row:first-child { padding-top: 0; }
.setting-info { flex: 1; min-width: 0; }
.setting-info h4 { font-size: 13.5px; font-weight: 600; color: var(--dark); }
.setting-info p  { font-size: 12px; color: #aaa; margin-top: 2px; line-height: 1.4; }

/* Toggle switch */
.toggle-wrap { flex-shrink: 0; }
.toggle { position: relative; display: inline-block; width: 44px; height: 24px; }
.toggle input { opacity: 0; width: 0; height: 0; }
.toggle-slider {
    position: absolute; cursor: pointer;
    inset: 0;
    background: #ddd;
    border-radius: 24px;
    transition: 0.25s;
}
.toggle-slider::before {
    content: '';
    position: absolute;
    width: 18px; height: 18px;
    left: 3px; bottom: 3px;
    background: #fff;
    border-radius: 50%;
    transition: 0.25s;
    box-shadow: 0 1px 4px rgba(0,0,0,0.18);
}
.toggle input:checked + .toggle-slider { background: var(--red); }
.toggle input:checked + .toggle-slider::before { transform: translateX(20px); }

/* ══ INPUT ROWS ═════════════════════════════════════════════════════════════ */
.input-row { margin-bottom: 16px; }
.input-row:last-child { margin-bottom: 0; }
.input-row label { display: block; font-size: 12.5px; font-weight: 600; color: var(--dark); margin-bottom: 6px; }
.input-row input,
.input-row select,
.input-row textarea {
    width: 100%;
    padding: 10px 13px;
    border: 1.5px solid #e0d8d5;
    border-radius: 9px;
    font-family: 'Poppins';
    font-size: 13px;
    color: var(--dark);
    background: #fdfbfa;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.input-row input:focus,
.input-row select:focus,
.input-row textarea:focus {
    border-color: var(--red);
    box-shadow: 0 0 0 3px rgba(149,18,44,0.07);
    background: #fff;
}
.input-row textarea { resize: vertical; min-height: 80px; }
.input-hint { font-size: 11px; color: #bbb; margin-top: 5px; }

/* ══ SAVE BAR ═══════════════════════════════════════════════════════════════ */
.save-bar {
    margin-top: 28px;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    align-items: center;
}
.btn-reset {
    padding: 11px 24px;
    border-radius: 9px;
    border: 1.5px solid #e0d8d5;
    background: #fff;
    color: #888;
    font-family: 'Poppins';
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.2s;
    display: flex;
    align-items: center;
    gap: 7px;
}
.btn-reset:hover { background: var(--bg); color: var(--dark); }
.btn-save {
    padding: 11px 28px;
    border-radius: 9px;
    border: none;
    background: linear-gradient(135deg, var(--red), var(--red-dark));
    color: #fff;
    font-family: 'Poppins';
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: 0.2s;
    display: flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 14px rgba(149,18,44,0.3);
}
.btn-save:hover { opacity: 0.9; transform: translateY(-1px); }
.btn-save:active { transform: none; }

/* ══ DANGER ZONE ════════════════════════════════════════════════════════════ */
.danger-zone {
    background: #fff5f5;
    border: 1.5px solid #fca5a5;
    border-radius: var(--radius);
    padding: 22px 24px;
    margin-top: 22px;
}
.danger-zone h4 { font-size: 14px; font-weight: 700; color: #dc3545; margin-bottom: 4px; display: flex; align-items: center; gap: 8px; }
.danger-zone p  { font-size: 12.5px; color: #888; margin-bottom: 16px; }
.danger-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.btn-danger-outline {
    padding: 9px 18px;
    border-radius: 8px;
    border: 1.5px solid #dc3545;
    background: transparent;
    color: #dc3545;
    font-family: 'Poppins';
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    transition: 0.2s;
    display: flex;
    align-items: center;
    gap: 6px;
}
.btn-danger-outline:hover { background: #dc3545; color: #fff; }

/* ══ RESPONSIVE ═════════════════════════════════════════════════════════════ */
@media (max-width: 960px) { .settings-grid { grid-template-columns: 1fr; } .settings-card.full-width { grid-column: 1; } }
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
    <a href="super_admin_dashboard.php" class="sidebar-logo" style="border-left:none!important;background:none!important;border-radius:0;margin:0;padding:26px 22px 20px;">
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
            <div class="ab-role">Super Admin</div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="super_admin_dashboard.php">
            <div class="nav-icon"><i class="fas fa-table-cells-large"></i></div> Dashboard
        </a>
        <a href="super_analytics.php">
            <div class="nav-icon"><i class="fas fa-chart-bar"></i></div> Analytics
        </a>
        <a href="super_history.php">
            <div class="nav-icon"><i class="fas fa-clock-rotate-left"></i></div> History
        </a>

        <hr>
        <div class="nav-section-label">System</div>
        <a href="super_settings.php" class="active">
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
                <h1>System Settings</h1>
                <p>Welcome back, <strong><?= htmlspecialchars($superAdminName) ?></strong> — <?= date('l, F j, Y') ?></p>
            </div>
        </div>
        <div class="header-right">

            <!-- Notification bell -->
            <div class="notif-wrap">
                <button class="notif-btn" onclick="toggleNotif(event)">
                    <i class="fas fa-bell"></i>
                    <?php if ($notifCount > 0): ?>
                        <span class="notif-badge"><?= $notifCount ?></span>
                    <?php endif; ?>
                </button>
                <div class="notif-dropdown" id="notifDropdown">
                    <div class="notif-header">
                        <span><i class="fas fa-bell" style="color:var(--red);margin-right:6px;"></i>Notifications</span>
                        <span style="font-size:11px;color:#bbb;"><?= $notifCount ?> items</span>
                    </div>
                    <?php if (!empty($notifList)): ?>
                        <?php foreach ($notifList as $notif): ?>
                        <div class="notif-item">
                            <i class="fas fa-bell ni-icon"></i>
                            <div>
                                <div class="ni-msg"><?= htmlspecialchars($notif['message']) ?></div>
                                <div class="ni-time"><?= date('M d, h:i A', strtotime($notif['created_at'])) ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="notif-empty"><i class="fas fa-bell-slash" style="font-size:22px;margin-bottom:6px;display:block;"></i>No notifications</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Profile pill -->
            <div class="profile-pill">
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($superAdminName) ?>&background=95122C&color=fff&rounded=true" alt="Avatar">
                <div>
                    <span><?= htmlspecialchars(strlen($superAdminName) > 14 ? substr($superAdminName, 0, 14) . '…' : $superAdminName) ?></span>
                    <span class="pp-role">Super Admin</span>
                </div>
            </div>
        </div>
    </div>

    <!-- CONTENT -->
    <div class="content">

        <!-- Page heading -->
        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-gear"></i> Global System Settings</h2>
                <p>Manage system-wide configurations and preferences for AssetEase.</p>
            </div>
        </div>

        <!-- Alerts -->
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-circle-check"></i> <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">

            <div class="settings-grid">

                <!-- ── Access & Registration ── -->
                <div class="settings-card">
                    <div class="card-head">
                        <div class="card-head-icon red"><i class="fas fa-users"></i></div>
                        <div>
                            <h3>Access &amp; Registration</h3>
                            <p>Control who can access the system</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="setting-row">
                            <div class="setting-info">
                                <h4>Enable User Registration</h4>
                                <p>Allow new users to create accounts via the sign-up page</p>
                            </div>
                            <div class="toggle-wrap">
                                <label class="toggle">
                                    <input type="checkbox" name="user_registration" checked>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-info">
                                <h4>Require Admin Approval</h4>
                                <p>New accounts must be approved before they can log in</p>
                            </div>
                            <div class="toggle-wrap">
                                <label class="toggle">
                                    <input type="checkbox" name="require_approval">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-info">
                                <h4>Maintenance Mode</h4>
                                <p>Temporarily disable public access while you make changes</p>
                            </div>
                            <div class="toggle-wrap">
                                <label class="toggle">
                                    <input type="checkbox" name="maintenance_mode">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Notifications ── -->
                <div class="settings-card">
                    <div class="card-head">
                        <div class="card-head-icon blue"><i class="fas fa-envelope"></i></div>
                        <div>
                            <h3>Email Notifications</h3>
                            <p>Configure automated email alerts</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="setting-row">
                            <div class="setting-info">
                                <h4>Email Notifications</h4>
                                <p>Send approval and rejection emails to users automatically</p>
                            </div>
                            <div class="toggle-wrap">
                                <label class="toggle">
                                    <input type="checkbox" name="email_notifications" checked>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-info">
                                <h4>Admin Alert Emails</h4>
                                <p>Notify super admin when new requests are submitted</p>
                            </div>
                            <div class="toggle-wrap">
                                <label class="toggle">
                                    <input type="checkbox" name="admin_alerts" checked>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                        <div class="setting-row">
                            <div class="setting-info">
                                <h4>Return Reminder Emails</h4>
                                <p>Send reminders to borrowers before their return deadline</p>
                            </div>
                            <div class="toggle-wrap">
                                <label class="toggle">
                                    <input type="checkbox" name="return_reminders">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Booking Rules ── -->
                <div class="settings-card">
                    <div class="card-head">
                        <div class="card-head-icon green"><i class="fas fa-calendar-check"></i></div>
                        <div>
                            <h3>Booking Rules</h3>
                            <p>Set limits and constraints for reservations</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="input-row">
                            <label>Max Borrow Duration (days)</label>
                            <input type="number" name="max_borrow_days" value="7" min="1" max="365">
                            <div class="input-hint">Maximum number of days a user can borrow equipment</div>
                        </div>
                        <div class="input-row">
                            <label>Max Active Bookings per User</label>
                            <input type="number" name="max_bookings_per_user" value="3" min="1" max="50">
                            <div class="input-hint">Limit simultaneous active reservations per account</div>
                        </div>
                        <div class="input-row">
                            <label>Advance Booking Window (days)</label>
                            <input type="number" name="advance_booking_days" value="30" min="1" max="365">
                            <div class="input-hint">How far in advance users can book rooms or equipment</div>
                        </div>
                    </div>
                </div>

                <!-- ── System Info ── -->
                <div class="settings-card">
                    <div class="card-head">
                        <div class="card-head-icon amber"><i class="fas fa-sliders"></i></div>
                        <div>
                            <h3>System Information</h3>
                            <p>Customize platform identity and details</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="input-row">
                            <label>System Name</label>
                            <input type="text" name="system_name" value="AssetEase">
                        </div>
                        <div class="input-row">
                            <label>Support Email Address</label>
                            <input type="email" name="support_email" placeholder="support@example.com">
                        </div>
                        <div class="input-row">
                            <label>System Announcement</label>
                            <textarea name="announcement" placeholder="Optional message shown to all users on login…"></textarea>
                            <div class="input-hint">Leave blank to show no announcement</div>
                        </div>
                    </div>
                </div>

            </div><!-- /.settings-grid -->

            <!-- Save / Reset bar -->
            <div class="save-bar">
                <button type="reset" class="btn-reset"><i class="fas fa-rotate-left"></i> Reset</button>
                <button type="submit" name="save_settings" class="btn-save"><i class="fas fa-floppy-disk"></i> Save All Settings</button>
            </div>

        </form>

        <!-- Danger zone -->
        <div class="danger-zone">
            <h4><i class="fas fa-triangle-exclamation"></i> Danger Zone</h4>
            <p>These actions are irreversible. Proceed with extreme caution.</p>
            <div class="danger-actions">
                <button class="btn-danger-outline" onclick="return confirm('Clear all pending requests? This cannot be undone.')">
                    <i class="fas fa-trash-can"></i> Clear Pending Requests
                </button>
                <button class="btn-danger-outline" onclick="return confirm('Purge all notifications? This cannot be undone.')">
                    <i class="fas fa-bell-slash"></i> Purge Notifications
                </button>
                <button class="btn-danger-outline" onclick="return confirm('Reset all settings to defaults? This cannot be undone.')">
                    <i class="fas fa-arrow-rotate-left"></i> Reset to Defaults
                </button>
            </div>
        </div>

    </div><!-- /.content -->
</div><!-- /.main-wrap -->

<script>
function toggleNotif(e) {
    e.stopPropagation();
    document.getElementById('notifDropdown').classList.toggle('active');
}
document.addEventListener('click', () => {
    document.getElementById('notifDropdown')?.classList.remove('active');
});
</script>
</body>
</html>