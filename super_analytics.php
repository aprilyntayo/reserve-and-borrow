<?php
/**
 * Super Admin Analytics - AssetEase
 */
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: login.php");
    exit;
}

$superAdminId = $_SESSION['user_id'];

$superAdminStmt = $conn->prepare("SELECT uname FROM users WHERE id = ?");
$superAdminStmt->bind_param("i", $superAdminId);
$superAdminStmt->execute();
$superAdminName = $superAdminStmt->get_result()->fetch_assoc()['uname'] ?? 'Super Admin';

// ── Notifications ────────────────────────────────────────────────────────────
$notifStmt = $conn->prepare("SELECT id, message, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$notifStmt->bind_param("i", $superAdminId);
$notifStmt->execute();
$notifList  = [];
while ($n = $notifStmt->get_result()->fetch_assoc()) $notifList[] = $n;
$notifCount = count($notifList);

// ── Booking stats (from room_reservations) ───────────────────────────────────
$total_bookings    = $conn->query("SELECT COUNT(*) as c FROM room_reservations")->fetch_assoc()['c'];
$pending_bookings  = $conn->query("SELECT COUNT(*) as c FROM room_reservations WHERE status='Pending'")->fetch_assoc()['c'];
$approved_bookings = $conn->query("SELECT COUNT(*) as c FROM room_reservations WHERE status='Approved'")->fetch_assoc()['c'];
$rejected_bookings = $conn->query("SELECT COUNT(*) as c FROM room_reservations WHERE status='Rejected'")->fetch_assoc()['c'];

// ── Borrow stats ─────────────────────────────────────────────────────────────
$total_borrows    = $conn->query("SELECT COUNT(*) as c FROM borrows")->fetch_assoc()['c'];
$pending_borrows  = $conn->query("SELECT COUNT(*) as c FROM borrows WHERE status='Pending'")->fetch_assoc()['c'];
$approved_borrows = $conn->query("SELECT COUNT(*) as c FROM borrows WHERE status='Approved'")->fetch_assoc()['c'];
$rejected_borrows = $conn->query("SELECT COUNT(*) as c FROM borrows WHERE status='Rejected'")->fetch_assoc()['c'];
$returned_borrows = $conn->query("SELECT COUNT(*) as c FROM borrows WHERE status='Returned'")->fetch_assoc()['c'];

// ── Resource counts ──────────────────────────────────────────────────────────
$total_rooms     = $conn->query("SELECT COUNT(DISTINCT room_name) as c FROM room_reservations")->fetch_assoc()['c'];
$total_equipment = $conn->query("SELECT COUNT(*) as c FROM equipment")->fetch_assoc()['c'];
$total_users     = $conn->query("SELECT COUNT(*) as c FROM users WHERE role='user'")->fetch_assoc()['c'];
$active_users    = $conn->query("SELECT COUNT(DISTINCT user_id) as c FROM room_reservations")->fetch_assoc()['c'];

// ── Monthly room bookings – last 6 months ────────────────────────────────────
$monthlyRoomsRaw = $conn->query("
    SELECT DATE_FORMAT(booking_date,'%b') as lbl,
           DATE_FORMAT(booking_date,'%Y-%m') as ym,
           COUNT(*) as cnt
    FROM room_reservations
    WHERE booking_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY ym, lbl ORDER BY ym
")->fetch_all(MYSQLI_ASSOC);

// ── Monthly borrows – last 6 months ─────────────────────────────────────────
$monthlyBorrowsRaw = $conn->query("
    SELECT DATE_FORMAT(borrow_date,'%b') as lbl,
           DATE_FORMAT(borrow_date,'%Y-%m') as ym,
           COUNT(*) as cnt
    FROM borrows
    WHERE borrow_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY ym, lbl ORDER BY ym
")->fetch_all(MYSQLI_ASSOC);

// ── Top borrowed equipment ───────────────────────────────────────────────────
$topEquipment = $conn->query("
    SELECT equipment_name, COUNT(*) as borrow_count
    FROM borrows GROUP BY equipment_name
    ORDER BY borrow_count DESC LIMIT 7
")->fetch_all(MYSQLI_ASSOC);

// ── Top rooms ────────────────────────────────────────────────────────────────
$topRooms = $conn->query("
    SELECT room_name, COUNT(*) as booking_count
    FROM room_reservations GROUP BY room_name
    ORDER BY booking_count DESC LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// ── Top users by bookings (rooms + borrows combined) ────────────────────────
$topUsers = $conn->query("
    SELECT user_name, COUNT(*) as booking_count
    FROM room_reservations
    GROUP BY user_name
    ORDER BY booking_count DESC LIMIT 5
")->fetch_all(MYSQLI_ASSOC);
$maxUserCount = !empty($topUsers) ? max(array_column($topUsers,'booking_count')) : 1;

// ── Status breakdown for doughnut (rooms) ────────────────────────────────────
$roomStatusData   = ['Pending'=>$pending_bookings,  'Approved'=>$approved_bookings,  'Rejected'=>$rejected_bookings];
$borrowStatusData = ['Pending'=>$pending_borrows,   'Approved'=>$approved_borrows,   'Rejected'=>$rejected_borrows, 'Returned'=>$returned_borrows];

// ── JS-ready arrays ──────────────────────────────────────────────────────────
$roomLabels   = array_column($monthlyRoomsRaw,   'lbl');
$roomCounts   = array_map('intval', array_column($monthlyRoomsRaw,   'cnt'));
$borrowLabels = array_column($monthlyBorrowsRaw, 'lbl');
$borrowCounts = array_map('intval', array_column($monthlyBorrowsRaw, 'cnt'));
$equipLabels  = array_column($topEquipment, 'equipment_name');
$equipCounts  = array_map('intval', array_column($topEquipment, 'borrow_count'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics — AssetEase</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
/* ══ RESET & TOKENS ════════════════════════════════════════════════════════ */
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
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'Poppins',sans-serif; background:var(--bg); display:flex; min-height:100vh; color:var(--dark); }

/* ══ SIDEBAR ════════════════════════════════════════════════════════════════ */
.sidebar {
    width:var(--sidebar-w); height:100vh;
    background:linear-gradient(180deg,var(--dark) 0%,var(--red) 100%);
    color:#fff; position:fixed; left:0; top:0;
    display:flex; flex-direction:column; overflow:hidden; z-index:1001;
}
.sidebar-logo {
    display:flex; align-items:center; gap:13px;
    padding:26px 22px 20px; border-bottom:1px solid rgba(255,255,255,0.1);
    flex-shrink:0; text-decoration:none;
}
.logo-img-wrap { position:relative; width:44px; height:44px; flex-shrink:0; }
.logo-img-wrap img { width:44px; height:44px; object-fit:cover; border-radius:10px; background:#fff; padding:2px; border:2px solid rgba(255,215,0,0.5); }
.logo-img-wrap .logo-glow { position:absolute; inset:-4px; border-radius:14px; background:radial-gradient(circle,rgba(255,215,0,0.25) 0%,transparent 70%); pointer-events:none; }
.sidebar-logo-text h2 { color:var(--gold); font-size:18px; font-weight:700; letter-spacing:2px; line-height:1; }
.sidebar-logo-text span { font-size:10px; color:rgba(255,255,255,0.5); letter-spacing:1px; text-transform:uppercase; }

.admin-badge {
    margin:14px 16px 6px; display:flex; align-items:center; gap:10px;
    padding:10px 14px; background:rgba(255,215,0,0.12);
    border:1px solid rgba(255,215,0,0.25); border-radius:10px; flex-shrink:0;
}
.admin-badge img { width:34px; height:34px; border-radius:50%; border:2px solid var(--gold); object-fit:cover; }
.admin-badge-info .ab-name { font-size:12px; font-weight:700; color:#fff; line-height:1; }
.admin-badge-info .ab-role { font-size:10px; color:var(--gold); text-transform:uppercase; letter-spacing:0.8px; margin-top:3px; }

.sidebar-nav { flex:1; overflow-y:auto; padding:10px 0 8px; scrollbar-width:none; }
.sidebar-nav::-webkit-scrollbar { display:none; }
.nav-section-label { padding:14px 22px 5px; font-size:9.5px; font-weight:700; letter-spacing:2.5px; color:rgba(255,255,255,0.38); text-transform:uppercase; }
.sidebar a {
    display:flex; align-items:center; gap:12px; padding:11px 22px;
    color:rgba(255,255,255,0.78); text-decoration:none; font-size:0.855rem; font-weight:500;
    border-left:3px solid transparent; transition:all 0.22s; margin:1px 8px; border-radius:8px;
}
.sidebar a .nav-icon { width:30px; height:30px; display:flex; align-items:center; justify-content:center; font-size:14px; border-radius:7px; background:rgba(255,255,255,0.06); flex-shrink:0; transition:all 0.22s; }
.sidebar a:hover { background:rgba(255,255,255,0.10); color:#fff; border-left-color:rgba(255,255,255,0.3); }
.sidebar a.active { background:rgba(255,255,255,0.15); color:#fff; border-left-color:var(--gold); font-weight:600; }
.sidebar a.active .nav-icon { background:rgba(255,215,0,0.18); color:var(--gold); }
.sidebar a:hover .nav-icon { background:rgba(255,255,255,0.12); }
.sidebar hr { border:none; border-top:1px solid rgba(255,255,255,0.09); margin:8px 18px; }
.sidebar-promo {
    margin:8px 14px 18px; padding:14px 15px;
    background:linear-gradient(135deg,rgba(255,215,0,0.18),rgba(255,255,255,0.06));
    border:1px solid rgba(255,215,0,0.28); border-radius:12px; flex-shrink:0;
}
.sidebar-promo p { font-size:11.5px; color:rgba(255,255,255,0.82); line-height:1.5; }
.sidebar-promo strong { color:var(--gold); }
.sidebar-promo a {
    display:inline-flex; align-items:center; gap:6px; margin-top:9px;
    padding:6px 14px; background:var(--gold) !important; color:var(--dark) !important;
    border-radius:7px !important; font-size:11px; font-weight:700;
    text-decoration:none; border:none !important; border-left:none !important; margin-left:0 !important;
}

/* ══ MAIN WRAP ══════════════════════════════════════════════════════════════ */
.main-wrap { margin-left:var(--sidebar-w); flex:1; display:flex; flex-direction:column; min-height:100vh; }

/* ══ TOP HEADER ═════════════════════════════════════════════════════════════ */
.top-header {
    position:sticky; top:0; background:var(--white);
    display:flex; justify-content:space-between; align-items:center;
    padding:0 36px; height:68px;
    box-shadow:0 1px 0 rgba(0,0,0,0.07),0 2px 12px rgba(0,0,0,0.04);
    z-index:999; gap:16px;
}
.header-left { display:flex; align-items:center; gap:14px; min-width:0; }
.header-logo-mini { display:flex; align-items:center; gap:9px; padding-right:14px; border-right:1px solid #eee; }
.header-logo-mini img { width:34px; height:34px; border-radius:8px; object-fit:cover; border:1.5px solid rgba(149,18,44,0.2); }
.header-logo-mini span { font-size:14px; font-weight:700; color:var(--red); letter-spacing:1.5px; }
.header-title-area h1 { font-size:19px; font-weight:700; color:var(--dark); line-height:1.1; }
.header-title-area p  { font-size:12px; color:#999; margin-top:1px; }
.header-right { display:flex; align-items:center; gap:11px; flex-shrink:0; }

.notif-wrap { position:relative; }
.notif-btn { width:40px; height:40px; border-radius:50%; background:var(--bg); border:none; display:flex; align-items:center; justify-content:center; cursor:pointer; color:var(--dark); font-size:16px; transition:0.2s; }
.notif-btn:hover { background:#ede6e3; }
.notif-badge { position:absolute; top:-1px; right:-1px; background:#dc3545; color:#fff; font-size:9px; font-weight:700; width:17px; height:17px; border-radius:50%; display:flex; align-items:center; justify-content:center; border:2px solid #fff; }
.notif-dropdown { position:absolute; top:50px; right:0; width:360px; background:#fff; border-radius:var(--radius); box-shadow:var(--shadow-md); z-index:2000; display:none; max-height:390px; overflow-y:auto; border:1px solid rgba(0,0,0,0.07); }
.notif-dropdown.active { display:block; animation:fadeDown 0.18s ease; }
@keyframes fadeDown { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:translateY(0)} }
.notif-header { padding:14px 18px 10px; font-weight:700; font-size:13px; color:var(--dark); border-bottom:1px solid #f0f0f0; display:flex; justify-content:space-between; align-items:center; }
.notif-item { padding:12px 18px; border-bottom:1px solid #f8f8f8; cursor:pointer; display:flex; gap:10px; }
.notif-item:hover { background:#fafafa; }
.notif-item .ni-icon { color:var(--red); font-size:13px; margin-top:2px; flex-shrink:0; }
.notif-item .ni-msg  { font-size:12.5px; color:#444; line-height:1.4; }
.notif-item .ni-time { font-size:11px; color:#bbb; margin-top:2px; }
.notif-empty { padding:28px; text-align:center; color:#bbb; font-size:13px; }
.profile-pill { display:flex; align-items:center; gap:9px; background:var(--bg); border-radius:30px; padding:5px 14px 5px 6px; cursor:pointer; transition:0.2s; border:1px solid transparent; }
.profile-pill:hover { background:#ede6e3; border-color:rgba(149,18,44,0.15); }
.profile-pill img { width:32px; height:32px; border-radius:50%; object-fit:cover; border:2px solid var(--red); }
.profile-pill span { font-size:13px; font-weight:600; color:var(--dark); }
.profile-pill .pp-role { font-size:10px; color:#999; display:block; }

/* ══ CONTENT ════════════════════════════════════════════════════════════════ */
.content { padding:32px 36px 48px; flex:1; }

/* ── Page header ── */
.page-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:28px; gap:16px; flex-wrap:wrap; }
.page-header-left h2 { font-size:22px; font-weight:700; color:var(--dark); display:flex; align-items:center; gap:10px; }
.page-header-left h2 i { width:38px; height:38px; background:linear-gradient(135deg,var(--red),var(--red-dark)); color:#fff; border-radius:10px; display:inline-flex; align-items:center; justify-content:center; font-size:16px; }
.page-header-left p { font-size:13px; color:#999; margin-top:4px; padding-left:48px; }
.date-badge { display:flex; align-items:center; gap:7px; background:#fff; border:1px solid #eee; border-radius:25px; padding:8px 16px; font-size:12.5px; color:#888; box-shadow:var(--shadow); }
.date-badge i { color:var(--red); }

/* ── Stat cards ── */
.stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:18px; margin-bottom:24px; }
.stat-card { background:var(--white); border-radius:var(--radius); padding:22px; box-shadow:var(--shadow); display:flex; justify-content:space-between; align-items:flex-start; transition:transform 0.2s,box-shadow 0.2s; border-top:3px solid transparent; }
.stat-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-md); }
.stat-card:nth-child(1) { border-top-color:var(--red); }
.stat-card:nth-child(2) { border-top-color:#f39c12; }
.stat-card:nth-child(3) { border-top-color:#22c55e; }
.stat-card:nth-child(4) { border-top-color:#3b82f6; }
.stat-card:nth-child(5) { border-top-color:#8b5cf6; }
.stat-card:nth-child(6) { border-top-color:#ec4899; }
.stat-card:nth-child(7) { border-top-color:#14b8a6; }
.stat-card:nth-child(8) { border-top-color:#f97316; }
.sc-label { font-size:11.5px; color:#999; font-weight:500; margin-bottom:6px; text-transform:uppercase; letter-spacing:0.4px; }
.sc-value { font-size:30px; font-weight:700; color:var(--dark); line-height:1; }
.sc-sub   { font-size:11.5px; color:#aaa; margin-top:5px; }
.stat-icon { width:46px; height:46px; border-radius:11px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
.si-red    { background:#fef2f2; color:var(--red); }
.si-amber  { background:#fff7ed; color:#f97316; }
.si-green  { background:#f0fdf4; color:#22c55e; }
.si-blue   { background:#eff6ff; color:#3b82f6; }
.si-purple { background:#f5f3ff; color:#8b5cf6; }
.si-pink   { background:#fdf2f8; color:#ec4899; }
.si-teal   { background:#f0fdfa; color:#14b8a6; }
.si-orange { background:#fff7ed; color:#f97316; }

/* ── Section divider ── */
.section-label { font-size:11px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:#bbb; margin:28px 0 14px; display:flex; align-items:center; gap:10px; }
.section-label::after { content:''; flex:1; height:1px; background:#ede8e5; }

/* ── Card ── */
.card { background:var(--white); border-radius:var(--radius); padding:24px 26px; box-shadow:var(--shadow); margin-bottom:22px; }
.card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px; }
.card-title { font-size:15px; font-weight:700; color:var(--dark); display:flex; align-items:center; gap:8px; }
.card-title i { color:var(--red); }

/* ── Grid layouts ── */
.charts-grid   { display:grid; grid-template-columns:1fr 1fr; gap:22px; margin-bottom:22px; }
.summary-grid  { display:grid; grid-template-columns:1fr 1fr; gap:22px; }
.chart-wrap    { position:relative; height:280px; }

/* ── Tables ── */
.table-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; }
thead tr { border-bottom:2px solid #f0ebe8; }
th { padding:11px 14px; text-align:left; font-size:11px; font-weight:700; letter-spacing:0.6px; text-transform:uppercase; color:#aaa; }
tbody tr { border-bottom:1px solid #f7f3f1; transition:background 0.15s; }
tbody tr:last-child { border-bottom:none; }
tbody tr:hover { background:#fdf9f8; }
td { padding:13px 14px; font-size:13px; color:#444; vertical-align:middle; }
td strong { color:var(--dark); }

/* ── Progress bars ── */
.prog-bar-wrap { display:flex; align-items:center; gap:10px; }
.prog-bar { flex:1; height:7px; background:#f0ebe8; border-radius:10px; overflow:hidden; }
.prog-fill { height:100%; border-radius:10px; background:linear-gradient(90deg,var(--red),#c0392b); }
.prog-fill.blue   { background:linear-gradient(90deg,#3b82f6,#1d4ed8); }
.prog-fill.green  { background:linear-gradient(90deg,#22c55e,#15803d); }
.prog-fill.amber  { background:linear-gradient(90deg,#f97316,#b45309); }
.prog-fill.purple { background:linear-gradient(90deg,#8b5cf6,#6d28d9); }
.prog-pct { font-size:11.5px; font-weight:700; color:#888; min-width:36px; text-align:right; }

/* ── Rank badges ── */
.rank-badge { display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px; border-radius:50%; font-size:11px; font-weight:700; }
.rank-1 { background:#FFF8DC; color:#b8860b; border:1.5px solid #FFD700; }
.rank-2 { background:#F5F5F5; color:#888;    border:1.5px solid #ccc; }
.rank-3 { background:#FFF0E6; color:#b05000; border:1.5px solid #f4a460; }
.rank-n { background:#f5f5f5; color:#aaa;    border:1.5px solid #e5e5e5; }

/* ── Status pills ── */
.status-pill { display:inline-flex; align-items:center; gap:5px; padding:4px 12px; border-radius:20px; font-size:11.5px; font-weight:600; }
.sp-pending  { background:#fff8e6; color:#b07800; }
.sp-approved { background:#edfaf1; color:#1a7a3c; }
.sp-returned { background:#e0f0ff; color:#004085; }
.sp-rejected { background:#fdf0f0; color:#9b1c1c; }

/* ── Responsive ── */
@media (max-width:1100px) { .stats-row { grid-template-columns:repeat(3,1fr); } .charts-grid,.summary-grid { grid-template-columns:1fr; } }
@media (max-width:768px) {
    :root { --sidebar-w:0px; }
    .sidebar { display:none; }
    .main-wrap { margin-left:0; }
    .content { padding:20px 16px 40px; }
    .top-header { padding:0 18px; }
    .stats-row { grid-template-columns:1fr 1fr; }
}
</style>
</head>
<body>

<!-- ═══════════════════ SIDEBAR ═════════════════════════════════════════════ -->
<div class="sidebar">
    <a href="super_admin_dashboard.php" class="sidebar-logo" style="border-left:none!important;background:none!important;border-radius:0;margin:0;padding:26px 22px 20px;">
        <div class="logo-img-wrap">
            <img src="image/logo.png" alt="AssetEase">
            <div class="logo-glow"></div>
        </div>
        <div class="sidebar-logo-text">
            <h2>ASSETEASE</h2>
            <span>Super Admin Portal</span>
        </div>
    </a>

    <div class="admin-badge">
        <img src="https://ui-avatars.com/api/?name=<?= urlencode($superAdminName) ?>&background=95122C&color=fff&rounded=true" alt="Avatar">
        <div class="admin-badge-info">
            <div class="ab-name"><?= htmlspecialchars(strlen($superAdminName) > 16 ? substr($superAdminName,0,16).'…' : $superAdminName) ?></div>
            <div class="ab-role">Super Admin</div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="super_admin_dashboard.php">
            <div class="nav-icon"><i class="fas fa-table-cells-large"></i></div> Dashboard
        </a>
        <a href="super_analytics.php" class="active">
            <div class="nav-icon"><i class="fas fa-chart-bar"></i></div> Analytics
        </a>
        <a href="super_history.php">
            <div class="nav-icon"><i class="fas fa-clock-rotate-left"></i></div> History
        </a>
        <hr>
        <div class="nav-section-label">System</div>
        <a href="super_settings.php">
            <div class="nav-icon"><i class="fas fa-gear"></i></div> Settings
        </a>
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
                <h1>Reports &amp; Analytics</h1>
                <p>Welcome back, <strong><?= htmlspecialchars($superAdminName) ?></strong> — <?= date('l, F j, Y') ?></p>
            </div>
        </div>
        <div class="header-right">

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

            <div class="profile-pill">
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($superAdminName) ?>&background=95122C&color=fff&rounded=true" alt="Avatar">
                <div>
                    <span><?= htmlspecialchars(strlen($superAdminName) > 14 ? substr($superAdminName,0,14).'…' : $superAdminName) ?></span>
                    <span class="pp-role">Super Admin</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ═════════════════════ CONTENT ════════════════════════════════════════ -->
    <div class="content">

        <div class="page-header">
            <div class="page-header-left">
                <h2><i class="fas fa-chart-bar"></i> Reports &amp; Analytics</h2>
                <p>System-wide overview of bookings, borrows, users and resources.</p>
            </div>
            <div class="date-badge"><i class="fas fa-calendar-day"></i><?= date('F j, Y') ?></div>
        </div>

        <!-- ── BOOKING STATS ────────────────────────────────────────────── -->
        <div class="section-label">Booking Overview</div>
        <div class="stats-row">
            <div class="stat-card">
                <div>
                    <div class="sc-label">Total Bookings</div>
                    <div class="sc-value"><?= number_format($total_bookings) ?></div>
                    <div class="sc-sub">All room reservations</div>
                </div>
                <div class="stat-icon si-red"><i class="fas fa-calendar-check"></i></div>
            </div>
            <div class="stat-card">
                <div>
                    <div class="sc-label">Pending</div>
                    <div class="sc-value"><?= number_format($pending_bookings) ?></div>
                    <div class="sc-sub">Awaiting review</div>
                </div>
                <div class="stat-icon si-amber"><i class="fas fa-hourglass-half"></i></div>
            </div>
            <div class="stat-card">
                <div>
                    <div class="sc-label">Approved</div>
                    <div class="sc-value"><?= number_format($approved_bookings) ?></div>
                    <div class="sc-sub">Room reservations</div>
                </div>
                <div class="stat-icon si-green"><i class="fas fa-circle-check"></i></div>
            </div>
            <div class="stat-card">
                <div>
                    <div class="sc-label">Rejected</div>
                    <div class="sc-value"><?= number_format($rejected_bookings) ?></div>
                    <div class="sc-sub">Declined requests</div>
                </div>
                <div class="stat-icon si-blue"><i class="fas fa-circle-xmark"></i></div>
            </div>
        </div>

        <!-- ── RESOURCE STATS ───────────────────────────────────────────── -->
        <div class="section-label">Resources &amp; Users</div>
        <div class="stats-row">
            <div class="stat-card">
                <div>
                    <div class="sc-label">Total Rooms</div>
                    <div class="sc-value"><?= number_format($total_rooms) ?></div>
                    <div class="sc-sub">Distinct rooms booked</div>
                </div>
                <div class="stat-icon si-red"><i class="fas fa-door-open"></i></div>
            </div>
            <div class="stat-card">
                <div>
                    <div class="sc-label">Equipment Items</div>
                    <div class="sc-value"><?= number_format($total_equipment) ?></div>
                    <div class="sc-sub">In inventory</div>
                </div>
                <div class="stat-icon si-purple"><i class="fas fa-toolbox"></i></div>
            </div>
            <div class="stat-card">
                <div>
                    <div class="sc-label">Total Users</div>
                    <div class="sc-value"><?= number_format($total_users) ?></div>
                    <div class="sc-sub">Registered accounts</div>
                </div>
                <div class="stat-icon si-green"><i class="fas fa-users"></i></div>
            </div>
            <div class="stat-card">
                <div>
                    <div class="sc-label">Active Users</div>
                    <div class="sc-value"><?= number_format($active_users) ?></div>
                    <div class="sc-sub">Made at least 1 booking</div>
                </div>
                <div class="stat-icon si-teal"><i class="fas fa-user-check"></i></div>
            </div>
        </div>

        <!-- ── CHARTS ───────────────────────────────────────────────────── -->
        <div class="section-label">Trends</div>
        <div class="charts-grid">
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fas fa-chart-line"></i> Monthly Room Bookings</div>
                    <span style="font-size:11.5px;color:#bbb;">Last 6 months</span>
                </div>
                <div class="chart-wrap"><canvas id="roomChart"></canvas></div>
            </div>
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fas fa-chart-bar"></i> Monthly Equipment Borrows</div>
                    <span style="font-size:11.5px;color:#bbb;">Last 6 months</span>
                </div>
                <div class="chart-wrap"><canvas id="borrowChart"></canvas></div>
            </div>
        </div>

        <div class="charts-grid">
            <div class="card" style="margin-bottom:0;">
                <div class="card-header">
                    <div class="card-title"><i class="fas fa-chart-pie"></i> Bookings by Status</div>
                </div>
                <div class="chart-wrap" style="height:240px;"><canvas id="statusChart"></canvas></div>
            </div>
            <div class="card" style="margin-bottom:0;">
                <div class="card-header">
                    <div class="card-title"><i class="fas fa-chart-pie"></i> Equipment Borrow Distribution</div>
                </div>
                <div class="chart-wrap" style="height:240px;"><canvas id="equipDoughnut"></canvas></div>
            </div>
        </div>

        <!-- ── TABLES ───────────────────────────────────────────────────── -->
        <div class="section-label" style="margin-top:28px;">Rankings</div>
        <div class="summary-grid">

            <!-- Top Equipment -->
            <div class="card" style="margin-bottom:0;">
                <div class="card-header">
                    <div class="card-title"><i class="fas fa-trophy"></i> Top Borrowed Equipment</div>
                </div>
                <?php $maxE = !empty($equipCounts) ? max($equipCounts) : 1; $eColors = ['red','blue','green','amber','purple','red','blue']; ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th style="width:36px;">#</th><th>Equipment</th><th style="width:72px;text-align:center;">Count</th><th style="width:150px;">Usage</th></tr></thead>
                        <tbody>
                        <?php foreach ($topEquipment as $i => $item):
                            $pct   = $maxE > 0 ? round($item['borrow_count']/$maxE*100) : 0;
                            $rank  = ['rank-1','rank-2','rank-3'][$i] ?? 'rank-n';
                            $color = $eColors[$i % count($eColors)];
                        ?>
                        <tr>
                            <td><span class="rank-badge <?= $rank ?>"><?= $i+1 ?></span></td>
                            <td><strong><?= htmlspecialchars($item['equipment_name']) ?></strong></td>
                            <td style="text-align:center;font-weight:700;"><?= $item['borrow_count'] ?></td>
                            <td><div class="prog-bar-wrap"><div class="prog-bar"><div class="prog-fill <?= $color !== 'red' ? $color : '' ?>" style="width:<?= $pct ?>%"></div></div><span class="prog-pct"><?= $pct ?>%</span></div></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($topEquipment)): ?>
                        <tr><td colspan="4" style="text-align:center;padding:30px;color:#ccc;"><i class="fas fa-box-open" style="font-size:24px;display:block;margin-bottom:8px;"></i>No borrow data yet</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Top Rooms -->
            <div class="card" style="margin-bottom:0;">
                <div class="card-header">
                    <div class="card-title"><i class="fas fa-door-open"></i> Top Booked Rooms</div>
                </div>
                <?php $maxR = !empty($topRooms) ? max(array_column($topRooms,'booking_count')) : 1; ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th style="width:36px;">#</th><th>Room</th><th style="width:72px;text-align:center;">Count</th><th style="width:150px;">Usage</th></tr></thead>
                        <tbody>
                        <?php foreach ($topRooms as $i => $room):
                            $pct  = $maxR > 0 ? round($room['booking_count']/$maxR*100) : 0;
                            $rank = ['rank-1','rank-2','rank-3'][$i] ?? 'rank-n';
                        ?>
                        <tr>
                            <td><span class="rank-badge <?= $rank ?>"><?= $i+1 ?></span></td>
                            <td><strong><?= htmlspecialchars($room['room_name']) ?></strong></td>
                            <td style="text-align:center;font-weight:700;"><?= $room['booking_count'] ?></td>
                            <td><div class="prog-bar-wrap"><div class="prog-bar"><div class="prog-fill blue" style="width:<?= $pct ?>%"></div></div><span class="prog-pct"><?= $pct ?>%</span></div></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($topRooms)): ?>
                        <tr><td colspan="4" style="text-align:center;padding:30px;color:#ccc;"><i class="fas fa-calendar-xmark" style="font-size:24px;display:block;margin-bottom:8px;"></i>No booking data yet</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div><!-- /.summary-grid -->

        <!-- Top Users -->
        <div class="card" style="margin-top:22px;">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-star"></i> Top 5 Users by Bookings</div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th style="width:36px;">#</th><th>User Name</th><th style="width:100px;text-align:center;">Bookings</th><th>Activity</th></tr></thead>
                    <tbody>
                    <?php foreach ($topUsers as $i => $user):
                        $pct  = $maxUserCount > 0 ? round($user['booking_count']/$maxUserCount*100) : 0;
                        $rank = ['rank-1','rank-2','rank-3'][$i] ?? 'rank-n';
                        $barColors = ['red','blue','green','amber','purple'];
                        $color = $barColors[$i % count($barColors)];
                    ?>
                    <tr>
                        <td><span class="rank-badge <?= $rank ?>"><?= $i+1 ?></span></td>
                        <td><strong><?= htmlspecialchars($user['user_name']) ?></strong></td>
                        <td style="text-align:center;font-weight:700;"><?= $user['booking_count'] ?></td>
                        <td><div class="prog-bar-wrap"><div class="prog-bar"><div class="prog-fill <?= $color !== 'red' ? $color : '' ?>" style="width:<?= $pct ?>%"></div></div><span class="prog-pct"><?= $pct ?>%</span></div></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($topUsers)): ?>
                    <tr><td colspan="4" style="text-align:center;padding:30px;color:#ccc;"><i class="fas fa-users" style="font-size:24px;display:block;margin-bottom:8px;"></i>No user data available</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Status summary pills -->
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px;">
            <span class="status-pill sp-pending"><i class="fas fa-clock" style="font-size:10px;"></i> <?= $pending_bookings + $pending_borrows ?> Pending</span>
            <span class="status-pill sp-approved"><i class="fas fa-check" style="font-size:10px;"></i> <?= $approved_bookings + $approved_borrows ?> Approved</span>
            <span class="status-pill sp-returned"><i class="fas fa-rotate-left" style="font-size:10px;"></i> <?= $returned_borrows ?> Returned</span>
            <span class="status-pill sp-rejected"><i class="fas fa-xmark" style="font-size:10px;"></i> <?= $rejected_bookings + $rejected_borrows ?> Rejected</span>
        </div>

    </div><!-- /.content -->
</div><!-- /.main-wrap -->

<script>
/* ── Notifications ─────────────────────────────────────────────────────── */
function toggleNotif(e) {
    e.stopPropagation();
    document.getElementById('notifDropdown').classList.toggle('active');
}
document.addEventListener('click', () => {
    document.getElementById('notifDropdown')?.classList.remove('active');
});

/* ── Chart.js defaults ─────────────────────────────────────────────────── */
Chart.defaults.font.family = "'Poppins', sans-serif";
Chart.defaults.color = '#aaa';
Chart.defaults.plugins.legend.display = false;

const RED = '#95122C', RED_LIGHT = 'rgba(149,18,44,0.10)';

/* ── Monthly Room Bookings – line ──────────────────────────────────────── */
new Chart(document.getElementById('roomChart'), {
    type: 'line',
    data: {
        labels: <?= json_encode($roomLabels ?: ['No data']) ?>,
        datasets: [{ data: <?= json_encode($roomCounts ?: [0]) ?>, borderColor:RED, backgroundColor:RED_LIGHT, borderWidth:2.5, pointBackgroundColor:RED, pointRadius:5, pointHoverRadius:7, fill:true, tension:0.4 }]
    },
    options: { responsive:true, maintainAspectRatio:false, scales:{ y:{beginAtZero:true,grid:{color:'#f5f0ee'},ticks:{precision:0}}, x:{grid:{display:false}} }, plugins:{tooltip:{callbacks:{label:c=>' '+c.parsed.y+' bookings'}}} }
});

/* ── Monthly Borrows – bar ─────────────────────────────────────────────── */
new Chart(document.getElementById('borrowChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($borrowLabels ?: ['No data']) ?>,
        datasets: [{ data: <?= json_encode($borrowCounts ?: [0]) ?>, backgroundColor:['rgba(149,18,44,0.75)','rgba(249,115,22,0.75)','rgba(34,197,94,0.75)','rgba(59,130,246,0.75)','rgba(139,92,246,0.75)','rgba(236,72,153,0.75)'], borderRadius:8, borderSkipped:false }]
    },
    options: { responsive:true, maintainAspectRatio:false, scales:{ y:{beginAtZero:true,grid:{color:'#f5f0ee'},ticks:{precision:0}}, x:{grid:{display:false}} }, plugins:{tooltip:{callbacks:{label:c=>' '+c.parsed.y+' borrows'}}} }
});

/* ── Bookings by Status – doughnut ────────────────────────────────────── */
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: ['Pending','Approved','Rejected'],
        datasets: [{ data:[<?= $pending_bookings ?>,<?= $approved_bookings ?>,<?= $rejected_bookings ?>], backgroundColor:['#FFC107','#22c55e','#dc3545'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]
    },
    options: { responsive:true, maintainAspectRatio:false, cutout:'60%', plugins:{ legend:{ display:true, position:'bottom', labels:{ boxWidth:12, padding:14, font:{size:11} } } } }
});

/* ── Equipment Distribution – doughnut ────────────────────────────────── */
new Chart(document.getElementById('equipDoughnut'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($equipLabels ?: ['No data']) ?>,
        datasets: [{ data: <?= json_encode($equipCounts ?: [1]) ?>, backgroundColor:['#95122C','#f97316','#22c55e','#3b82f6','#8b5cf6','#ec4899','#14b8a6'], borderWidth:2, borderColor:'#fff', hoverOffset:8 }]
    },
    options: { responsive:true, maintainAspectRatio:false, cutout:'60%', plugins:{ legend:{ display:true, position:'bottom', labels:{ boxWidth:12, padding:10, font:{size:11} } } } }
});
</script>
</body>
</html>