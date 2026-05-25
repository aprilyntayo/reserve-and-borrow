<?php
/**
 * Admin History - AssetEase
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'config.php';
require_once 'notification_handler.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'super_admin')) {
    header("Location: login.php");
    exit;
}

$adminId = $_SESSION['user_id'];

// Fetch admin name
$adminStmt = $conn->prepare("SELECT uname FROM users WHERE id = ?");
$adminStmt->bind_param("i", $adminId);
$adminStmt->execute();
$adminRow  = $adminStmt->get_result()->fetch_assoc();
$adminName = $adminRow['uname'] ?? 'Admin';

// ==========================================
// HANDLE DELETE
// ==========================================
$msg     = '';
$msgType = '';

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM room_reservations WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: admin_history.php?msg=deleted");
    exit;
}

// ==========================================
// STATS (same four as dashboard)
// ==========================================
$totalBookings = $conn->query("SELECT COUNT(*) as total FROM room_reservations")->fetch_assoc()['total'];
$pendingCount  = $conn->query("SELECT COUNT(*) as total FROM room_reservations WHERE status = 'Pending'")->fetch_assoc()['total'];
$approvedCount = $conn->query("SELECT COUNT(*) as total FROM room_reservations WHERE status = 'Approved'")->fetch_assoc()['total'];
$rejectedCount = $conn->query("SELECT COUNT(*) as total FROM room_reservations WHERE status = 'Rejected'")->fetch_assoc()['total'];

// ==========================================
// FILTERING + SEARCH
// ==========================================
$currentFilter = $_GET['filter']    ?? 'All';
$searchQuery   = trim($_GET['search'] ?? '');
$dateFrom      = $_GET['date_from'] ?? '';
$dateTo        = $_GET['date_to']   ?? '';
$sortCol       = $_GET['sort']      ?? 'created_at';
$sortDir       = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

$allowedSorts = ['id','user_name','room_name','booking_date','status','created_at'];
if (!in_array($sortCol, $allowedSorts)) $sortCol = 'created_at';

$sql    = "SELECT id, user_name, room_name, booking_date, end_date, start_time, end_time, purpose, status, created_at
           FROM room_reservations WHERE 1=1";
$params = [];
$types  = '';

if ($currentFilter === 'Pending')  { $sql .= " AND status = 'Pending'"; }
elseif ($currentFilter === 'Approved') { $sql .= " AND status = 'Approved'"; }
elseif ($currentFilter === 'Rejected') { $sql .= " AND status = 'Rejected'"; }
else { $sql .= " AND status != 'Pending'"; }

if ($searchQuery) {
    $like    = '%' . $searchQuery . '%';
    $sql    .= " AND (user_name LIKE ? OR room_name LIKE ? OR purpose LIKE ?)";
    $params  = array_merge($params, [$like, $like, $like]);
    $types  .= 'sss';
}
if ($dateFrom) { $sql .= " AND booking_date >= ?"; $params[] = $dateFrom; $types .= 's'; }
if ($dateTo)   { $sql .= " AND booking_date <= ?"; $params[] = $dateTo;   $types .= 's'; }

$sql .= " ORDER BY $sortCol $sortDir";

if ($params) {
    $roomStmt = $conn->prepare($sql);
    $roomStmt->bind_param($types, ...$params);
    $roomStmt->execute();
    $roomReservations = $roomStmt->get_result();
} else {
    $roomReservations = $conn->query($sql);
}

// ==========================================
// CSV EXPORT
// ==========================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="reservations_history_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','User Name','Room','Booking Date','End Date','Start Time','End Time','Purpose','Status','Created At']);
    while ($row = $roomReservations->fetch_assoc()) {
        fputcsv($out, [
            $row['id'], $row['user_name'], $row['room_name'],
            $row['booking_date'], $row['end_date'],
            date('h:i A', strtotime($row['start_time'])),
            date('h:i A', strtotime($row['end_time'])),
            $row['purpose'], $row['status'],
            $row['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

// ==========================================
// NOTIFICATIONS
// ==========================================
$adminNotifQuery = $conn->prepare("SELECT id, message, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$adminNotifQuery->bind_param("i", $adminId);
$adminNotifQuery->execute();
$adminNotifs = $adminNotifQuery->get_result();

$adminNotifList  = [];
while ($n = $adminNotifs->fetch_assoc()) { $adminNotifList[] = $n; }
$adminNotifCount = count($adminNotifList);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservation History — AssetEase</title>
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
    padding: 14px 22px 5px;
    font-size: 9.5px; font-weight: 700; letter-spacing: 2.5px;
    color: rgba(255,255,255,0.38); text-transform: uppercase;
}

.sidebar a {
    display: flex; align-items: center; gap: 12px;
    padding: 11px 22px; color: rgba(255,255,255,0.78);
    text-decoration: none; font-size: 0.855rem; font-weight: 500;
    border-left: 3px solid transparent;
    transition: all 0.22s ease; margin: 1px 8px; border-radius: 8px;
}
.sidebar a .nav-icon {
    width: 30px; height: 30px;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; border-radius: 7px;
    background: rgba(255,255,255,0.06); flex-shrink: 0; transition: all 0.22s;
}
.sidebar a:hover { background: rgba(255,255,255,0.10); color: #fff; border-left-color: rgba(255,255,255,0.3); }
.sidebar a.active { background: rgba(255,255,255,0.15); color: #fff; border-left-color: var(--gold); font-weight: 600; }
.sidebar a.active .nav-icon { background: rgba(255,215,0,0.18); color: var(--gold); }
.sidebar a:hover .nav-icon { background: rgba(255,255,255,0.12); }
.sidebar a .nav-badge {
    margin-left: auto; background: var(--red); color: #fff;
    font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 10px; min-width: 20px; text-align: center;
}
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

.search-box {
    display: flex; align-items: center; gap: 9px;
    background: var(--bg); border-radius: 25px; padding: 9px 16px;
    width: 230px; border: 1.5px solid transparent; transition: 0.2s;
}
.search-box:focus-within { border-color: var(--red); background: #fff; box-shadow: 0 0 0 3px rgba(149,18,44,0.07); }
.search-box i { color: #bbb; font-size: 13px; }
.search-box input { border: none; background: transparent; font-size: 13px; font-family: 'Poppins'; color: var(--dark); outline: none; width: 100%; }
.search-box input::placeholder { color: #ccc; }

.notif-wrap { position: relative; }
.notif-btn {
    width: 40px; height: 40px; border-radius: 50%; background: var(--bg); border: none;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: var(--dark); font-size: 16px; transition: 0.2s;
}
.notif-btn:hover { background: #ede6e3; }
.notif-badge {
    position: absolute; top: -1px; right: -1px; background: #dc3545; color: #fff;
    font-size: 9px; font-weight: 700; width: 17px; height: 17px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center; border: 2px solid #fff;
}
.notif-dropdown {
    position: absolute; top: 50px; right: 0; width: 360px;
    background: #fff; border-radius: var(--radius); box-shadow: var(--shadow-md);
    z-index: 2000; display: none; max-height: 390px; overflow-y: auto;
    border: 1px solid rgba(0,0,0,0.07);
}
.notif-dropdown.active { display: block; animation: fadeDown 0.18s ease; }
@keyframes fadeDown { from { opacity:0; transform: translateY(-6px); } to { opacity:1; transform: translateY(0); } }
.notif-header {
    padding: 14px 18px 10px; font-weight: 700; font-size: 13px; color: var(--dark);
    border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center;
}
.notif-item { padding: 12px 18px; border-bottom: 1px solid #f8f8f8; cursor: pointer; display: flex; gap: 10px; }
.notif-item:hover { background: #fafafa; }
.notif-item .ni-icon { color: var(--red); font-size: 13px; margin-top: 2px; flex-shrink: 0; }
.notif-item .ni-msg { font-size: 12.5px; color: #444; line-height: 1.4; }
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
.content { padding: 28px 36px 50px; flex: 1; }

/* ══ ALERT ══════════════════════════════════════════════════════════════════ */
.alert {
    padding: 13px 18px; border-radius: 10px;
    margin-bottom: 22px; border-left: 4px solid;
    display: flex; align-items: center; gap: 10px; font-size: 13px;
}
.alert-success { background: #edfaf1; color: #155724; border-color: #28a745; }
.alert-error   { background: #fdf0f0; color: #721c24; border-color: #dc3545; }

/* ══ STAT CARDS ═════════════════════════════════════════════════════════════ */
.stats-row {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; margin-bottom: 24px;
}
.stat-card {
    background: var(--white); border-radius: var(--radius); padding: 22px;
    box-shadow: var(--shadow); display: flex; justify-content: space-between;
    align-items: flex-start; transition: transform 0.2s, box-shadow 0.2s;
    border-top: 3px solid transparent;
}
.stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
.stat-card:nth-child(1) { border-top-color: var(--red); }
.stat-card:nth-child(2) { border-top-color: #f39c12; }
.stat-card:nth-child(3) { border-top-color: #22c55e; }
.stat-card:nth-child(4) { border-top-color: #dc3545; }
.stat-card-body .sc-label { font-size: 12px; color: #999; font-weight: 500; margin-bottom: 7px; text-transform: uppercase; letter-spacing: 0.4px; }
.stat-card-body .sc-value { font-size: 32px; font-weight: 700; color: var(--dark); line-height: 1; }
.stat-card-body .sc-sub   { font-size: 11.5px; color: #aaa; margin-top: 5px; }
.stat-icon { width: 48px; height: 48px; border-radius: 11px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
.si-red   { background: #fef2f2; color: var(--red); }
.si-amber { background: #fff7ed; color: #f97316; }
.si-green { background: #f0fdf4; color: #22c55e; }
.si-rose  { background: #fff1f2; color: #dc3545; }

/* ══ CARD ═══════════════════════════════════════════════════════════════════ */
.card { background: var(--white); border-radius: var(--radius); padding: 24px 26px; box-shadow: var(--shadow); margin-bottom: 22px; }
.card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
.card-title { font-size: 15px; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 8px; }
.card-title i { color: var(--red); }

/* ══ FILTER & SEARCH BAR ════════════════════════════════════════════════════ */
.table-controls { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.filter-pill {
    padding: 7px 16px; border-radius: 25px; border: 1.5px solid #e0d8d5;
    background: #fff; color: #666; font-size: 12.5px; font-weight: 500;
    cursor: pointer; transition: all 0.2s; font-family: 'Poppins';
    display: flex; align-items: center; gap: 6px;
}
.filter-pill:hover  { border-color: var(--red); color: var(--red); }
.filter-pill.active { background: var(--red); color: #fff; border-color: var(--red); box-shadow: 0 2px 8px rgba(149,18,44,0.2); }

.adv-search-bar {
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
    background: var(--bg); padding: 14px 18px; border-radius: 12px; margin-bottom: 18px;
}
.adv-search-bar label { font-size: 12px; font-weight: 600; color: #666; white-space: nowrap; }
.adv-input {
    padding: 8px 14px; border: 1.5px solid #e0d8d5; border-radius: 8px;
    font-size: 13px; font-family: 'Poppins'; color: var(--dark);
    outline: none; transition: border-color 0.2s; background: #fff;
}
.adv-input:focus { border-color: var(--red); }
.adv-search-input-wrap {
    display: flex; align-items: center; gap: 8px; border: 1.5px solid #e0d8d5;
    border-radius: 8px; padding: 7px 13px; background: #fff; transition: 0.2s;
}
.adv-search-input-wrap:focus-within { border-color: var(--red); }
.adv-search-input-wrap i { color: #bbb; font-size: 13px; }
.adv-search-input-wrap input {
    border: none; outline: none; font-size: 13px;
    font-family: 'Poppins'; color: var(--dark); width: 190px; background: transparent;
}
.btn-apply {
    padding: 8px 18px; border-radius: 8px; border: none;
    background: var(--red); color: #fff; font-size: 13px; font-weight: 600;
    cursor: pointer; font-family: 'Poppins'; transition: 0.2s;
    display: flex; align-items: center; gap: 6px; text-decoration: none;
}
.btn-apply:hover { background: var(--red-dark); }
.btn-clear {
    padding: 8px 14px; border-radius: 8px; border: 1.5px solid #e0d8d5;
    background: #fff; color: #777; font-size: 12.5px; font-weight: 500;
    cursor: pointer; font-family: 'Poppins'; transition: 0.2s; text-decoration: none;
    display: flex; align-items: center; gap: 6px;
}
.btn-clear:hover { border-color: #aaa; }

/* Export button */
.btn-export {
    padding: 8px 16px; border-radius: 8px; border: 1.5px solid #22c55e;
    background: #edfaf1; color: #1a7a3c; font-size: 12.5px; font-weight: 600;
    cursor: pointer; font-family: 'Poppins'; transition: 0.2s;
    display: inline-flex; align-items: center; gap: 6px; text-decoration: none;
}
.btn-export:hover { background: #22c55e; color: #fff; }

/* ══ TABLE ══════════════════════════════════════════════════════════════════ */
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; min-width: 800px; }
thead tr { border-bottom: 2px solid #f0ebe8; }
th {
    padding: 11px 14px; text-align: left;
    font-size: 11px; font-weight: 700; letter-spacing: 0.6px;
    text-transform: uppercase; color: #aaa;
}
th a { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
th a:hover { color: var(--red); }
th .sort-icon { font-size: 10px; opacity: 0.5; }
th.sorted a { color: var(--red); }
th.sorted .sort-icon { opacity: 1; color: var(--red); }
tbody tr { border-bottom: 1px solid #f7f3f1; transition: background 0.15s; }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: #fdf9f8; }
td { padding: 13px 14px; font-size: 13px; color: #444; vertical-align: middle; }
td strong { color: var(--dark); }

.badge-status {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 11px; border-radius: 20px; font-size: 11.5px; font-weight: 600;
}
.badge-status::before { content: ''; width: 6px; height: 6px; border-radius: 50%; display: inline-block; }
.status-Pending  { background: #fff8e6; color: #b07800; }
.status-Pending::before  { background: #f39c12; }
.status-Approved { background: #edfaf1; color: #1a7a3c; }
.status-Approved::before { background: #28a745; }
.status-Rejected { background: #fdf0f0; color: #9b1c1c; }
.status-Rejected::before { background: #dc3545; }

/* ══ ACTION BUTTONS ═════════════════════════════════════════════════════════ */
.btn-act {
    padding: 5px 11px; border-radius: 7px; border: none;
    font-size: 12px; font-weight: 600; cursor: pointer;
    font-family: 'Poppins'; transition: all 0.18s;
    display: inline-flex; align-items: center; gap: 5px; text-decoration: none;
}
.btn-view   { background: #eff6ff; color: #3b82f6; }
.btn-view:hover { background: #3b82f6; color: #fff; }
.btn-delete { background: #f3f3f3; color: #555; }
.btn-delete:hover { background: #343a40; color: #fff; }

/* ══ MODAL ══════════════════════════════════════════════════════════════════ */
.modal-overlay {
    display: none; position: fixed; z-index: 1500;
    inset: 0; background: rgba(0,0,0,0.45);
    align-items: center; justify-content: center;
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: #fff; border-radius: 18px; padding: 32px;
    max-width: 540px; width: 92%;
    box-shadow: 0 16px 48px rgba(0,0,0,0.18);
    animation: modalIn 0.22s ease;
}
@keyframes modalIn { from { transform: scale(0.94); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.modal-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 20px; padding-bottom: 14px; border-bottom: 1px solid #f0ebe8;
}
.modal-header h2 { font-size: 18px; color: var(--dark); font-weight: 700; }
.modal-close { background: none; border: none; cursor: pointer; color: #bbb; font-size: 20px; transition: color 0.2s; }
.modal-close:hover { color: #555; }

.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.detail-item { display: flex; flex-direction: column; gap: 3px; }
.detail-item .di-label { font-size: 11px; font-weight: 700; color: #aaa; text-transform: uppercase; letter-spacing: 0.5px; }
.detail-item .di-val   { font-size: 13.5px; color: var(--dark); font-weight: 500; }
.detail-item.full { grid-column: 1 / -1; }

.modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; }
.btn-modal { padding: 10px 22px; border-radius: 9px; border: none; font-size: 13px; font-weight: 600; font-family: 'Poppins'; cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 6px; }
.btn-modal-cancel  { background: #f0ebe8; color: #555; }
.btn-modal-cancel:hover { background: #e0d8d5; }
.btn-modal-danger  { background: #dc3545; color: #fff; }
.btn-modal-danger:hover { background: #b02a37; }

/* ══ PAGINATION ═════════════════════════════════════════════════════════════ */
.pagination { display: flex; align-items: center; justify-content: space-between; margin-top: 20px; flex-wrap: wrap; gap: 12px; }
.pagination-info { font-size: 13px; color: #999; }
.pagination-btns { display: flex; gap: 6px; }
.page-btn {
    padding: 6px 13px; border-radius: 8px; border: 1.5px solid #e0d8d5;
    background: #fff; color: #666; font-size: 12.5px; font-weight: 500;
    cursor: pointer; font-family: 'Poppins'; transition: 0.2s;
}
.page-btn.active { background: var(--red); color: #fff; border-color: var(--red); }
.page-btn:hover:not(.active) { border-color: var(--red); color: var(--red); }

/* No results */
.no-results { text-align: center; padding: 40px 20px; color: #ccc; }
.no-results i { font-size: 32px; margin-bottom: 10px; display: block; color: #ddd; }
.no-results p { font-size: 14px; }

/* ══ RESPONSIVE ═════════════════════════════════════════════════════════════ */
@media (max-width: 1100px) { .stats-row { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 768px) {
    :root { --sidebar-w: 0px; }
    .sidebar { display: none; }
    .main-wrap { margin-left: 0; }
    .content { padding: 20px 16px 40px; }
    .top-header { padding: 0 18px; }
    .stats-row { grid-template-columns: 1fr 1fr; }
    .detail-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<!-- ═══════════════════ SIDEBAR ═════════════════════════════════════════════ -->
<div class="sidebar">
    <a href="admin_dashboard.php" class="sidebar-logo" style="border-left:none!important;background:none!important;border-radius:0;margin:0;padding:26px 22px 20px;">
        <div class="logo-img-wrap">
            <img src="image/logo.png" alt="AssetEase Logo">
            <div class="logo-glow"></div>
        </div>
        <div class="sidebar-logo-text">
            <h2>ASSETEASE</h2>
            <span>Admin Portal</span>
        </div>
    </a>

    <!-- Admin badge -->
    <div class="admin-badge">
        <img src="https://ui-avatars.com/api/?name=<?= urlencode($adminName) ?>&background=95122C&color=fff&rounded=true" alt="Admin Avatar">
        <div class="admin-badge-info">
            <div class="ab-name"><?= htmlspecialchars(strlen($adminName)>16 ? substr($adminName,0,16).'…' : $adminName) ?></div>
            <div class="ab-role"><?= htmlspecialchars($_SESSION['role'] ?? 'Admin') ?></div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="admin_dashboard.php">
            <div class="nav-icon"><i class="fas fa-table-cells-large"></i></div> Dashboard
        </a>
        <a href="admin_history.php" class="active">
            <div class="nav-icon"><i class="fas fa-clock-rotate-left"></i></div> History
        </a>
        <a href="admin_dashboard.php?section=analytics">
            <div class="nav-icon"><i class="fas fa-chart-line"></i></div> Analytics
            <?php if($pendingCount>0): ?><span class="nav-badge"><?= $pendingCount ?></span><?php endif; ?>
        </a>

        <hr>
        <div class="nav-section-label">Management</div>
        <a href="admin_dashboard.php?section=users">
            <div class="nav-icon"><i class="fas fa-users"></i></div> User Management
        </a>

        <hr>
        <div class="nav-section-label">System</div>
        <a href="admin_settings.php">
            <div class="nav-icon"><i class="fas fa-gear"></i></div> Settings
        </a>
        <a href="logout.php">
            <div class="nav-icon"><i class="fas fa-right-from-bracket"></i></div> Logout
        </a>
    </nav>

    <div class="sidebar-promo">
        <strong>Need help?</strong>
        <p>Check the admin guide or contact support for assistance.</p>
        <a href="admin_settings.php"><i class="fas fa-arrow-right"></i> Go to Settings</a>
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
                <h1>Reservation History</h1>
                <p>Welcome back, <strong><?= htmlspecialchars($adminName) ?></strong> — <?= date('l, F j, Y') ?></p>
            </div>
        </div>
        <div class="header-right">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="globalSearch" placeholder="Quick search…" oninput="liveSearch(this.value)">
            </div>

            <!-- Notification bell -->
            <div class="notif-wrap">
                <button class="notif-btn" onclick="toggleNotif(event)">
                    <i class="fas fa-bell"></i>
                    <?php if($adminNotifCount > 0): ?>
                        <span class="notif-badge"><?= $adminNotifCount ?></span>
                    <?php endif; ?>
                </button>
                <div class="notif-dropdown" id="notifDropdown">
                    <div class="notif-header">
                        <span><i class="fas fa-bell" style="color:var(--red);margin-right:6px;"></i>Notifications</span>
                        <span style="font-size:11px;color:#bbb;"><?= $adminNotifCount ?> items</span>
                    </div>
                    <?php if (!empty($adminNotifList)): ?>
                        <?php foreach($adminNotifList as $notif): ?>
                        <div class="notif-item">
                            <i class="fas fa-bell ni-icon"></i>
                            <div>
                                <div class="ni-msg"><?= htmlspecialchars($notif['message']) ?></div>
                                <div class="ni-time"><?= date('M d, h:i A', strtotime($notif['created_at'])) ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="notif-empty"><i class="fas fa-bell-slash" style="font-size:24px;display:block;margin-bottom:8px;color:#ddd;"></i>No notifications yet</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Profile pill -->
            <div class="profile-pill" onclick="window.location='admin_settings.php'">
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($adminName) ?>&background=95122C&color=fff&rounded=true" alt="">
                <div>
                    <span><?= htmlspecialchars(strlen($adminName)>14 ? substr($adminName,0,14).'…' : $adminName) ?></span>
                    <span class="pp-role"><?= htmlspecialchars($_SESSION['role'] ?? 'Admin') ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- CONTENT AREA -->
    <div class="content">

        <?php if(isset($_GET['msg']) && $_GET['msg']==='deleted'): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> Reservation deleted successfully.</div>
        <?php endif; ?>

        <!-- STAT CARDS -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="sc-label">Total Reservations</div>
                    <div class="sc-value"><?= $totalBookings ?></div>
                    <div class="sc-sub">All time</div>
                </div>
                <div class="stat-icon si-red"><i class="fas fa-calendar-check"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="sc-label">Pending</div>
                    <div class="sc-value"><?= $pendingCount ?></div>
                    <div class="sc-sub">Awaiting action</div>
                </div>
                <div class="stat-icon si-amber"><i class="fas fa-hourglass-half"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="sc-label">Approved</div>
                    <div class="sc-value"><?= $approvedCount ?></div>
                    <div class="sc-sub">Confirmed bookings</div>
                </div>
                <div class="stat-icon si-green"><i class="fas fa-circle-check"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="sc-label">Rejected</div>
                    <div class="sc-value"><?= $rejectedCount ?></div>
                    <div class="sc-sub">Declined requests</div>
                </div>
                <div class="stat-icon si-rose"><i class="fas fa-circle-xmark"></i></div>
            </div>
        </div>

        <!-- HISTORY TABLE CARD -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-clock-rotate-left"></i> Full Reservation Log</div>
                <div class="table-controls">
                    <?php
                        // Build export URL preserving current filters
                        $exportParams = http_build_query(array_filter([
                            'export'    => 'csv',
                            'filter'    => $currentFilter !== 'All' ? $currentFilter : '',
                            'search'    => $searchQuery,
                            'date_from' => $dateFrom,
                            'date_to'   => $dateTo,
                        ]));
                    ?>
                    <a href="admin_history.php?<?= $exportParams ?>" class="btn-export">
                        <i class="fas fa-file-csv"></i> Export CSV
                    </a>
                    <button class="filter-pill <?= $currentFilter==='All'      ? 'active' : '' ?>" onclick="setFilter('All')">All</button>
                    <button class="filter-pill <?= $currentFilter==='Pending'  ? 'active' : '' ?>" onclick="setFilter('Pending')"><i class="fas fa-hourglass-half" style="font-size:10px;"></i> Pending</button>
                    <button class="filter-pill <?= $currentFilter==='Approved' ? 'active' : '' ?>" onclick="setFilter('Approved')"><i class="fas fa-check" style="font-size:10px;"></i> Approved</button>
                    <button class="filter-pill <?= $currentFilter==='Rejected' ? 'active' : '' ?>" onclick="setFilter('Rejected')"><i class="fas fa-xmark" style="font-size:10px;"></i> Rejected</button>
                </div>
            </div>

            <!-- Advanced search/filter bar -->
            <form method="GET" action="admin_history.php" id="filterForm">
                <input type="hidden" name="filter" value="<?= htmlspecialchars($currentFilter) ?>">
                <input type="hidden" name="sort"   value="<?= htmlspecialchars($sortCol) ?>">
                <input type="hidden" name="dir"    value="<?= htmlspecialchars($sortDir) ?>">
                <div class="adv-search-bar">
                    <label><i class="fas fa-search" style="margin-right:5px;color:var(--red);"></i> Search &amp; Filter</label>
                    <div class="adv-search-input-wrap">
                        <i class="fas fa-search"></i>
                        <input type="text" name="search" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="Name, room, purpose…">
                    </div>
                    <label>From</label>
                    <input type="date" class="adv-input" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                    <label>To</label>
                    <input type="date" class="adv-input" name="date_to"   value="<?= htmlspecialchars($dateTo) ?>">
                    <button type="submit" class="btn-apply"><i class="fas fa-filter"></i> Apply</button>
                    <?php if($searchQuery || $dateFrom || $dateTo || $currentFilter !== 'All'): ?>
                    <a href="admin_history.php" class="btn-clear"><i class="fas fa-xmark"></i> Clear</a>
                    <?php endif; ?>
                </div>
            </form>

            <?php
                // Helper to build sortable column header link
                function sortLink($col, $label, $currentCol, $currentDir, $currentFilter, $searchQuery, $dateFrom, $dateTo) {
                    $newDir = ($currentCol === $col && $currentDir === 'ASC') ? 'DESC' : 'ASC';
                    $icon   = ($currentCol === $col) ? ($currentDir === 'ASC' ? '↑' : '↓') : '↕';
                    $active = ($currentCol === $col) ? ' sorted' : '';
                    $params = http_build_query(array_filter([
                        'sort'      => $col,
                        'dir'       => $newDir,
                        'filter'    => ($currentFilter !== 'All' && $currentFilter !== 'Pending') ? $currentFilter : '',
                        'search'    => $searchQuery,
                        'date_from' => $dateFrom,
                        'date_to'   => $dateTo,
                    ]));
                    return "<th class=\"$active\"><a href=\"admin_history.php?$params\">$label <span class=\"sort-icon\">$icon</span></a></th>";
                }
            ?>

            <div class="table-wrap">
                <table id="historyTable">
                    <thead>
                        <tr>
                            <?= sortLink('id',           '#',        $sortCol, $sortDir, $currentFilter, $searchQuery, $dateFrom, $dateTo) ?>
                            <?= sortLink('user_name',    'User',     $sortCol, $sortDir, $currentFilter, $searchQuery, $dateFrom, $dateTo) ?>
                            <?= sortLink('room_name',    'Room',     $sortCol, $sortDir, $currentFilter, $searchQuery, $dateFrom, $dateTo) ?>
                            <?= sortLink('booking_date', 'Date',     $sortCol, $sortDir, $currentFilter, $searchQuery, $dateFrom, $dateTo) ?>
                            <th>Time</th>
                            <th>Purpose</th>
                            <?= sortLink('status',       'Status',   $sortCol, $sortDir, $currentFilter, $searchQuery, $dateFrom, $dateTo) ?>
                            <?= sortLink('created_at',   'Submitted',$sortCol, $sortDir, $currentFilter, $searchQuery, $dateFrom, $dateTo) ?>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                        $rowCount = 0;
                        if ($roomReservations && $roomReservations->num_rows > 0):
                            while($row = $roomReservations->fetch_assoc()):
                                $rowCount++;
                                $startDate   = date('M d, Y', strtotime($row['booking_date']));
                                $endDate     = date('M d, Y', strtotime($row['end_date']));
                                $dateDisplay = ($row['booking_date'] === $row['end_date']) ? $startDate : "$startDate – $endDate";
                                $timeDisplay = date('h:i A', strtotime($row['start_time'])) . ' – ' . date('h:i A', strtotime($row['end_time']));

                                $purposeTxt   = $row['purpose'];
                                $department   = '';
                                if (preg_match('/Department:\s*([^|]+)/i', $purposeTxt, $matches)) {
                                    $department = trim($matches[1]);
                                }
                                $purposeTxt   = preg_replace('/Department:\s*[^|]+(\||$)/i', '', $purposeTxt);
                                $purposeTxt   = preg_replace('/Location:\s*[^|]+(\||$)/i', '', $purposeTxt);
                                $purposeTxt   = preg_replace('/Room:\s*[^|]+(\||$)/i', '', $purposeTxt);
                                $purposeTxt   = preg_replace('/Purpose:\s*/i', '', $purposeTxt);
                                $purposeTxt   = trim(str_replace('|', '', $purposeTxt));
                                $purposeFull  = htmlspecialchars(trim($purposeTxt));
                                $purposeDisp  = htmlspecialchars(substr(trim($purposeTxt), 0, 45));
                    ?>
                    <tr>
                        <td style="color:#bbb;font-size:12px;"><?= $row['id'] ?></td>
                        <td>
                            <strong><?= htmlspecialchars($row['user_name']) ?></strong>
                            <?php if ($department): ?>
                                <div style="font-size:12px;color:#777;margin-top:4px;">Dept: <?= htmlspecialchars($department) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($row['room_name']) ?></td>
                        <td style="white-space:nowrap;"><?= $dateDisplay ?></td>
                        <td style="white-space:nowrap;color:#777;"><?= $timeDisplay ?></td>
                        <td style="color:#777;"><?= $purposeDisp ? $purposeDisp . (strlen(trim($purposeTxt))>45 ? '…' : '') : '<em style="color:#ccc;">—</em>' ?></td>
                        <td><span class="badge-status status-<?= $row['status'] ?>"><?= $row['status'] ?></span></td>
                        <td style="color:#999;font-size:12px;white-space:nowrap;"><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                        <td style="white-space:nowrap;">
                            <button type="button" class="btn-act btn-view"
                                onclick="viewDetail(
                                    <?= $row['id'] ?>,
                                    '<?= htmlspecialchars(addslashes($row['user_name'])) ?>',
                                    '<?= htmlspecialchars(addslashes($department)) ?>',
                                    '<?= htmlspecialchars(addslashes($row['room_name'])) ?>',
                                    '<?= $dateDisplay ?>',
                                    '<?= $timeDisplay ?>',
                                    '<?= htmlspecialchars(addslashes($purposeFull)) ?>',
                                    '<?= $row['status'] ?>',
                                    '<?= date('M d, Y h:i A', strtotime($row['created_at'])) ?>'
                                )">
                                <i class="fas fa-eye"></i> View
                            </button>
                            <a href="admin_history.php?delete=<?= $row['id'] ?><?= $searchQuery ? '&search='.urlencode($searchQuery) : '' ?><?= $dateFrom ? '&date_from='.urlencode($dateFrom) : '' ?><?= $dateTo ? '&date_to='.urlencode($dateTo) : '' ?>&filter=<?= urlencode($currentFilter) ?>"
                               class="btn-act btn-delete"
                               onclick="return confirm('Permanently delete Booking #<?= $row['id'] ?>? This cannot be undone.')">
                                <i class="fas fa-trash"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="9">
                        <div class="no-results">
                            <i class="fas fa-inbox"></i>
                            <p>No reservation records found<?= $searchQuery ? " for &ldquo;" . htmlspecialchars($searchQuery) . "&rdquo;" : '' ?>.</p>
                        </div>
                    </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination info -->
            <div class="pagination">
                <div class="pagination-info">
                    Showing <strong id="visibleCount"><?= $rowCount ?></strong> of <strong><?= $totalBookings ?></strong> total records
                    <?php if($currentFilter !== 'All' || $searchQuery || $dateFrom || $dateTo): ?>
                        <span style="color:var(--red);"> (filtered)</span>
                    <?php endif; ?>
                </div>
                <div class="pagination-btns" id="paginationBtns"></div>
            </div>
        </div>

    </div><!-- /content -->
</div><!-- /main-wrap -->


<!-- ═══════════════════ VIEW DETAIL MODAL ═══════════════════════════════════ -->
<div id="viewModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h2><i class="fas fa-calendar-check" style="color:var(--red);margin-right:8px;"></i>Reservation Detail</h2>
            <button class="modal-close" onclick="closeView()"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="detail-grid">
            <div class="detail-item">
                <span class="di-label"><i class="fas fa-hashtag"></i> Booking ID</span>
                <span class="di-val" id="dId">—</span>
            </div>
            <div class="detail-item">
                <span class="di-label"><i class="fas fa-circle-dot"></i> Status</span>
                <span class="di-val" id="dStatus">—</span>
            </div>
            <div class="detail-item">
                <span class="di-label"><i class="fas fa-user"></i> User</span>
                <span class="di-val" id="dUser">—</span>
            </div>
            <div class="detail-item">
                <span class="di-label"><i class="fas fa-building"></i> Department</span>
                <span class="di-val" id="dDepartment">—</span>
            </div>
            <div class="detail-item">
                <span class="di-label"><i class="fas fa-door-open"></i> Room</span>
                <span class="di-val" id="dRoom">—</span>
            </div>
            <div class="detail-item">
                <span class="di-label"><i class="fas fa-calendar"></i> Date</span>
                <span class="di-val" id="dDate">—</span>
            </div>
            <div class="detail-item">
                <span class="di-label"><i class="fas fa-clock"></i> Time</span>
                <span class="di-val" id="dTime">—</span>
            </div>
            <div class="detail-item full">
                <span class="di-label"><i class="fas fa-pen-to-square"></i> Purpose</span>
                <span class="di-val" id="dPurpose" style="color:#555;">—</span>
            </div>
            <div class="detail-item full">
                <span class="di-label"><i class="fas fa-clock-rotate-left"></i> Submitted On</span>
                <span class="di-val" id="dCreated" style="color:#999;font-size:12.5px;">—</span>
            </div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-modal btn-modal-cancel" onclick="closeView()">Close</button>
        </div>
    </div>
</div>

<script>
/* ── Status filter ──────────────────────────────────────────────────────── */
function setFilter(filter) {
    const url = new URL(window.location.href);
    url.searchParams.set('filter', filter);
    window.location.href = url.toString();
}

/* ── Live inline search ──────────────────────────────────────────────────── */
function liveSearch(query) {
    const q = query.toLowerCase();
    let visible = 0;
    document.querySelectorAll('#historyTable tbody tr').forEach(tr => {
        const show = tr.textContent.toLowerCase().includes(q);
        tr.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    const vc = document.getElementById('visibleCount');
    if (vc) vc.textContent = visible;
}

/* ── Notifications ──────────────────────────────────────────────────────── */
function toggleNotif(e) {
    e.stopPropagation();
    document.getElementById('notifDropdown').classList.toggle('active');
}
document.addEventListener('click', () => {
    document.getElementById('notifDropdown')?.classList.remove('active');
});

/* ── View detail modal ───────────────────────────────────────────────────── */
const statusColors = {
    Approved: '#1a7a3c',
    Pending:  '#b07800',
    Rejected: '#9b1c1c',
};

function viewDetail(id, user, department, room, date, time, purpose, status, created) {
    document.getElementById('dId').textContent         = '#' + id;
    document.getElementById('dUser').textContent       = user;
    document.getElementById('dDepartment').textContent = department || '—';
    document.getElementById('dRoom').textContent       = room;
    document.getElementById('dDate').textContent       = date;
    document.getElementById('dTime').textContent       = time;
    document.getElementById('dPurpose').textContent    = purpose || '—';
    document.getElementById('dCreated').textContent    = created;

    const statusEl = document.getElementById('dStatus');
    statusEl.innerHTML =
        `<span class="badge-status status-${status}">${status}</span>`;

    document.getElementById('viewModal').classList.add('open');
}
function closeView() { document.getElementById('viewModal').classList.remove('open'); }

window.addEventListener('click', e => {
    if (e.target.classList.contains('modal-overlay')) closeView();
});

/* ── Client-side pagination ─────────────────────────────────────────────── */
(function() {
    const ROWS_PER_PAGE = 20;
    const tbody  = document.querySelector('#historyTable tbody');
    if (!tbody) return;
    const rows   = Array.from(tbody.querySelectorAll('tr'));
    const total  = rows.length;
    if (total <= ROWS_PER_PAGE) return;

    let currentPage = 1;
    const totalPages = Math.ceil(total / ROWS_PER_PAGE);

    function showPage(page) {
        currentPage = page;
        const start = (page - 1) * ROWS_PER_PAGE;
        const end   = start + ROWS_PER_PAGE;
        rows.forEach((r, i) => { r.style.display = (i >= start && i < end) ? '' : 'none'; });
        document.getElementById('visibleCount').textContent = Math.min(end, total) - start;
        renderButtons();
    }

    function renderButtons() {
        const container = document.getElementById('paginationBtns');
        if (!container) return;
        container.innerHTML = '';
        const makeBtn = (label, page, active) => {
            const b = document.createElement('button');
            b.className = 'page-btn' + (active ? ' active' : '');
            b.textContent = label;
            b.onclick = () => showPage(page);
            container.appendChild(b);
        };
        if (currentPage > 1) makeBtn('‹ Prev', currentPage - 1, false);
        for (let p = 1; p <= totalPages; p++) {
            if (totalPages <= 7 || p === 1 || p === totalPages || Math.abs(p - currentPage) <= 1) {
                makeBtn(p, p, p === currentPage);
            } else if (Math.abs(p - currentPage) === 2) {
                const dots = document.createElement('span');
                dots.style.cssText = 'padding:6px 8px;color:#bbb;font-size:13px;';
                dots.textContent = '…';
                container.appendChild(dots);
            }
        }
        if (currentPage < totalPages) makeBtn('Next ›', currentPage + 1, false);
    }

    showPage(1);
})();
</script>
</body>
</html>