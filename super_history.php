<?php
/**
 * Super Admin History - AssetEase
 * Full history log for both Room Reservations (read-only view)
 * and Equipment Borrows (full log), with search, filters,
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'config.php';
require_once 'notification_handler.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: login.php");
    exit;
}

$superAdminId = $_SESSION['user_id'];

// Fetch super admin name
$saStmt = $conn->prepare("SELECT uname FROM users WHERE id = ?");
$saStmt->bind_param("i", $superAdminId);
$saStmt->execute();
$saRow          = $saStmt->get_result()->fetch_assoc();
$superAdminName = $saRow['uname'] ?? 'Super Admin';

// ==========================================
// ACTIVE HISTORY TAB
// ==========================================
$activeTab = $_GET['htab'] ?? 'rooms';   // 'rooms' | 'equipment'

// ==========================================
// ROOM RESERVATION STATS
// ==========================================
$roomTotal    = $conn->query("SELECT COUNT(*) as c FROM room_reservations")->fetch_assoc()['c'];
$roomPending  = $conn->query("SELECT COUNT(*) as c FROM room_reservations WHERE status='Pending'")->fetch_assoc()['c'];
$roomApproved = $conn->query("SELECT COUNT(*) as c FROM room_reservations WHERE status='Approved'")->fetch_assoc()['c'];
$roomRejected = $conn->query("SELECT COUNT(*) as c FROM room_reservations WHERE status='Rejected'")->fetch_assoc()['c'];

// ==========================================
// EQUIPMENT BORROW STATS
// ==========================================
$equipTotal    = $conn->query("SELECT COUNT(*) as c FROM borrows")->fetch_assoc()['c'];
$equipPending  = $conn->query("SELECT COUNT(*) as c FROM borrows WHERE status='Pending'")->fetch_assoc()['c'];
$equipApproved = $conn->query("SELECT COUNT(*) as c FROM borrows WHERE status='Approved'")->fetch_assoc()['c'];
$equipReturned = $conn->query("SELECT COUNT(*) as c FROM borrows WHERE status='Returned'")->fetch_assoc()['c'];
$equipRejected = $conn->query("SELECT COUNT(*) as c FROM borrows WHERE status='Rejected'")->fetch_assoc()['c'];

// ==========================================
// ROOM FILTERING + SEARCH
// ==========================================
$rFilter  = $_GET['rfilter']    ?? 'All';
$rSearch  = trim($_GET['rsearch'] ?? '');
$rFrom    = $_GET['rdate_from'] ?? '';
$rTo      = $_GET['rdate_to']   ?? '';
$rSort    = $_GET['rsort']      ?? 'created_at';
$rDir     = strtoupper($_GET['rdir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$rAllowed = ['id','user_name','room_name','booking_date','status','created_at'];
if (!in_array($rSort, $rAllowed)) $rSort = 'created_at';

$rSql    = "SELECT id, user_name, room_name, booking_date, end_date, start_time, end_time, purpose, status, created_at FROM room_reservations WHERE 1=1";
$rParams = []; $rTypes = '';
if ($rFilter === 'Pending')  $rSql .= " AND status = 'Pending'";
elseif ($rFilter === 'Approved') $rSql .= " AND status = 'Approved'";
elseif ($rFilter === 'Rejected') $rSql .= " AND status = 'Rejected'";
if ($rSearch) {
    $like = '%'.$rSearch.'%';
    $rSql .= " AND (user_name LIKE ? OR room_name LIKE ? OR purpose LIKE ?)";
    $rParams = array_merge($rParams, [$like,$like,$like]); $rTypes .= 'sss';
}
if ($rFrom) { $rSql .= " AND booking_date >= ?"; $rParams[] = $rFrom; $rTypes .= 's'; }
if ($rTo)   { $rSql .= " AND booking_date <= ?"; $rParams[] = $rTo;   $rTypes .= 's'; }
$rSql .= " ORDER BY $rSort $rDir";

if ($rParams) {
    $rs = $conn->prepare($rSql); $rs->bind_param($rTypes, ...$rParams); $rs->execute();
    $roomRows = $rs->get_result();
} else {
    $roomRows = $conn->query($rSql);
}

// ==========================================
// ROOM CSV EXPORT
// ==========================================
if (isset($_GET['export']) && $_GET['export'] === 'rooms_csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="room_reservations_history_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','User Name','Room','Booking Date','End Date','Start Time','End Time','Purpose','Status','Created At']);
    while ($row = $roomRows->fetch_assoc()) {
        fputcsv($out, [$row['id'],$row['user_name'],$row['room_name'],$row['booking_date'],$row['end_date'],
            date('h:i A',strtotime($row['start_time'])), date('h:i A',strtotime($row['end_time'])),
            $row['purpose'],$row['status'],$row['created_at']]);
    }
    fclose($out); exit;
}

// ==========================================
// EQUIPMENT FILTERING + SEARCH
// ==========================================
$eFilter  = $_GET['efilter']    ?? 'All';
$eSearch  = trim($_GET['esearch'] ?? '');
$eFrom    = $_GET['edate_from'] ?? '';
$eTo      = $_GET['edate_to']   ?? '';
$eSort    = $_GET['esort']      ?? 'created_at';
$eDir     = strtoupper($_GET['edir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$eAllowed = ['id','user_name','equipment_name','borrow_date','status','created_at'];
if (!in_array($eSort, $eAllowed)) $eSort = 'created_at';

$eSql    = "SELECT id, user_name, equipment_name, quantity, borrow_date, return_date, start_time, end_time, purpose, status, created_at FROM borrows WHERE 1=1";
$eParams = []; $eTypes = '';
if ($eFilter === 'Pending')  $eSql .= " AND status = 'Pending'";
elseif ($eFilter === 'Approved') $eSql .= " AND status = 'Approved'";
elseif ($eFilter === 'Returned') $eSql .= " AND status = 'Returned'";
elseif ($eFilter === 'Rejected') $eSql .= " AND status = 'Rejected'";
if ($eSearch) {
    $like = '%'.$eSearch.'%';
    $eSql .= " AND (user_name LIKE ? OR equipment_name LIKE ? OR purpose LIKE ?)";
    $eParams = array_merge($eParams, [$like,$like,$like]); $eTypes .= 'sss';
}
if ($eFrom) { $eSql .= " AND borrow_date >= ?"; $eParams[] = $eFrom; $eTypes .= 's'; }
if ($eTo)   { $eSql .= " AND borrow_date <= ?"; $eParams[] = $eTo;   $eTypes .= 's'; }
$eSql .= " ORDER BY $eSort $eDir";

if ($eParams) {
    $es = $conn->prepare($eSql); $es->bind_param($eTypes, ...$eParams); $es->execute();
    $equipRows = $es->get_result();
} else {
    $equipRows = $conn->query($eSql);
}

// ==========================================
// EQUIPMENT CSV EXPORT
// ==========================================
if (isset($_GET['export']) && $_GET['export'] === 'equip_csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="equipment_borrows_history_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','User Name','Equipment','Qty','Borrow Date','Return Date','Start Time','End Time','Purpose','Status','Created At']);
    while ($row = $equipRows->fetch_assoc()) {
        fputcsv($out, [$row['id'],$row['user_name'],$row['equipment_name'],$row['quantity'],
            $row['borrow_date'],$row['return_date'],
            date('h:i A',strtotime($row['start_time'])), date('h:i A',strtotime($row['end_time'])),
            $row['purpose'],$row['status'],$row['created_at']]);
    }
    fclose($out); exit;
}

// ==========================================
// NOTIFICATIONS
// ==========================================
$nStmt = $conn->prepare("SELECT id, message, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$nStmt->bind_param("i", $superAdminId);
$nStmt->execute();
$notifList  = [];
while ($n = $nStmt->get_result()->fetch_assoc()) $notifList[] = $n;
$notifCount = count($notifList);

// ==========================================
// HELPER FUNCTIONS
// ==========================================
function fmtDate($s, $e) {
    return $s === $e ? date('M d, Y', strtotime($s)) : date('M d, Y', strtotime($s)) . ' – ' . date('M d, Y', strtotime($e));
}
function fmtTime($s, $e) {
    return date('h:i A', strtotime($s)) . ' – ' . date('h:i A', strtotime($e));
}
function buildSortLink($page, $col, $label, $curCol, $curDir, $extras = []) {
    $newDir = ($curCol === $col && $curDir === 'ASC') ? 'DESC' : 'ASC';
    $icon   = ($curCol === $col) ? ($curDir === 'ASC' ? '↑' : '↓') : '↕';
    $active = ($curCol === $col) ? ' sorted' : '';
    $params = array_merge($extras, ['sort_col' => $col, 'sort_dir' => $newDir]);
    $qs     = http_build_query($params);
    return "<th class=\"$active\"><a href=\"$page?$qs\">$label <span class=\"sort-icon\">$icon</span></a></th>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>History — AssetEase Super Admin</title>
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
    width: var(--sidebar-w); height: 100vh;
    background: linear-gradient(180deg, var(--dark) 0%, var(--red) 100%);
    color: #fff; position: fixed; left: 0; top: 0;
    display: flex; flex-direction: column; overflow: hidden; z-index: 1001;
}
.sidebar-logo {
    display: flex; align-items: center; gap: 13px;
    padding: 26px 22px 20px; border-bottom: 1px solid rgba(255,255,255,0.1);
    flex-shrink: 0; text-decoration: none;
}
.logo-img-wrap { position: relative; width: 44px; height: 44px; flex-shrink: 0; }
.logo-img-wrap img { width: 44px; height: 44px; object-fit: cover; border-radius: 10px; background: #fff; padding: 2px; border: 2px solid rgba(255,215,0,0.5); }
.logo-img-wrap .logo-glow { position: absolute; inset: -4px; border-radius: 14px; background: radial-gradient(circle, rgba(255,215,0,0.25) 0%, transparent 70%); pointer-events: none; }
.sidebar-logo-text h2 { color: var(--gold); font-size: 18px; font-weight: 700; letter-spacing: 2px; line-height: 1; }
.sidebar-logo-text span { font-size: 10px; color: rgba(255,255,255,0.5); letter-spacing: 1px; text-transform: uppercase; }

.admin-badge { margin: 14px 16px 6px; display: flex; align-items: center; gap: 10px; padding: 10px 14px; background: rgba(255,215,0,0.12); border: 1px solid rgba(255,215,0,0.25); border-radius: 10px; flex-shrink: 0; }
.admin-badge img { width: 34px; height: 34px; border-radius: 50%; border: 2px solid var(--gold); object-fit: cover; }
.admin-badge-info .ab-name { font-size: 12px; font-weight: 700; color: #fff; line-height: 1; }
.admin-badge-info .ab-role { font-size: 10px; color: var(--gold); text-transform: uppercase; letter-spacing: 0.8px; margin-top: 3px; }

.sidebar-nav { flex: 1; overflow-y: auto; padding: 10px 0 8px; scrollbar-width: none; }
.sidebar-nav::-webkit-scrollbar { display: none; }
.nav-section-label { padding: 14px 22px 5px; font-size: 9.5px; font-weight: 700; letter-spacing: 2.5px; color: rgba(255,255,255,0.38); text-transform: uppercase; }

.sidebar a { display: flex; align-items: center; gap: 12px; padding: 11px 22px; color: rgba(255,255,255,0.78); text-decoration: none; font-size: 0.855rem; font-weight: 500; border-left: 3px solid transparent; transition: all 0.22s ease; margin: 1px 8px; border-radius: 8px; }
.sidebar a .nav-icon { width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; font-size: 14px; border-radius: 7px; background: rgba(255,255,255,0.06); flex-shrink: 0; transition: all 0.22s; }
.sidebar a:hover { background: rgba(255,255,255,0.10); color: #fff; border-left-color: rgba(255,255,255,0.3); }
.sidebar a.active { background: rgba(255,255,255,0.15); color: #fff; border-left-color: var(--gold); font-weight: 600; }
.sidebar a.active .nav-icon { background: rgba(255,215,0,0.18); color: var(--gold); }
.sidebar a:hover .nav-icon { background: rgba(255,255,255,0.12); }
.sidebar hr { border: none; border-top: 1px solid rgba(255,255,255,0.09); margin: 8px 18px; }

.sidebar-promo { margin: 8px 14px 18px; padding: 14px 15px; background: linear-gradient(135deg, rgba(255,215,0,0.18), rgba(255,255,255,0.06)); border: 1px solid rgba(255,215,0,0.28); border-radius: 12px; flex-shrink: 0; }
.sidebar-promo p { font-size: 11.5px; color: rgba(255,255,255,0.82); line-height: 1.5; }
.sidebar-promo strong { color: var(--gold); }
.sidebar-promo a { display: inline-flex; align-items: center; gap: 6px; margin-top: 9px; padding: 6px 14px; background: var(--gold) !important; color: var(--dark) !important; border-radius: 7px !important; font-size: 11px; font-weight: 700; text-decoration: none; border: none !important; border-left: none !important; margin-left: 0 !important; }
.sidebar-promo a:hover { opacity: 0.9; }

/* ══ MAIN LAYOUT ════════════════════════════════════════════════════════════ */
.main-wrap { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

/* ══ TOP HEADER ════════════════════════════════════════════════════════════ */
.top-header { position: sticky; top: 0; background: var(--white); display: flex; justify-content: space-between; align-items: center; padding: 0 36px; height: 68px; box-shadow: 0 1px 0 rgba(0,0,0,0.07), 0 2px 12px rgba(0,0,0,0.04); z-index: 999; gap: 16px; }
.header-left { display: flex; align-items: center; gap: 14px; min-width: 0; }
.header-logo-mini { display: flex; align-items: center; gap: 9px; padding-right: 14px; border-right: 1px solid #eee; }
.header-logo-mini img { width: 34px; height: 34px; border-radius: 8px; object-fit: cover; border: 1.5px solid rgba(149,18,44,0.2); }
.header-logo-mini span { font-size: 14px; font-weight: 700; color: var(--red); letter-spacing: 1.5px; }
.header-title-area h1 { font-size: 19px; font-weight: 700; color: var(--dark); line-height: 1.1; }
.header-title-area p  { font-size: 12px; color: #999; margin-top: 1px; }
.header-right { display: flex; align-items: center; gap: 11px; flex-shrink: 0; }

.search-box { display: flex; align-items: center; gap: 9px; background: var(--bg); border-radius: 25px; padding: 9px 16px; width: 230px; border: 1.5px solid transparent; transition: 0.2s; }
.search-box:focus-within { border-color: var(--red); background: #fff; box-shadow: 0 0 0 3px rgba(149,18,44,0.07); }
.search-box i { color: #bbb; font-size: 13px; }
.search-box input { border: none; background: transparent; font-size: 13px; font-family: 'Poppins'; color: var(--dark); outline: none; width: 100%; }
.search-box input::placeholder { color: #ccc; }

.notif-wrap { position: relative; }
.notif-btn { width: 40px; height: 40px; border-radius: 50%; background: var(--bg); border: none; display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--dark); font-size: 16px; transition: 0.2s; }
.notif-btn:hover { background: #ede6e3; }
.notif-badge { position: absolute; top: -1px; right: -1px; background: #dc3545; color: #fff; font-size: 9px; font-weight: 700; width: 17px; height: 17px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid #fff; }
.notif-dropdown { position: absolute; top: 50px; right: 0; width: 360px; background: #fff; border-radius: var(--radius); box-shadow: var(--shadow-md); z-index: 2000; display: none; max-height: 390px; overflow-y: auto; border: 1px solid rgba(0,0,0,0.07); }
.notif-dropdown.active { display: block; animation: fadeDown 0.18s ease; }
@keyframes fadeDown { from { opacity:0; transform: translateY(-6px); } to { opacity:1; transform: translateY(0); } }
.notif-header { padding: 14px 18px 10px; font-weight: 700; font-size: 13px; color: var(--dark); border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; }
.notif-item { padding: 12px 18px; border-bottom: 1px solid #f8f8f8; cursor: pointer; display: flex; gap: 10px; }
.notif-item:hover { background: #fafafa; }
.notif-item .ni-icon { color: var(--red); font-size: 13px; margin-top: 2px; flex-shrink: 0; }
.notif-item .ni-msg  { font-size: 12.5px; color: #444; line-height: 1.4; }
.notif-item .ni-time { font-size: 11px; color: #bbb; margin-top: 2px; }
.notif-empty { padding: 28px; text-align: center; color: #bbb; font-size: 13px; }

.profile-pill { display: flex; align-items: center; gap: 9px; background: var(--bg); border-radius: 30px; padding: 5px 14px 5px 6px; cursor: pointer; transition: 0.2s; border: 1px solid transparent; }
.profile-pill:hover { background: #ede6e3; border-color: rgba(149,18,44,0.15); }
.profile-pill img { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; border: 2px solid var(--red); }
.profile-pill span { font-size: 13px; font-weight: 600; color: var(--dark); }
.profile-pill .pp-role { font-size: 10px; color: #999; display: block; }

/* ══ CONTENT ════════════════════════════════════════════════════════════════ */
.content { padding: 28px 36px 50px; flex: 1; }

/* ══ STAT CARDS ═════════════════════════════════════════════════════════════ */
.stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; margin-bottom: 24px; }
.stat-card { background: var(--white); border-radius: var(--radius); padding: 22px; box-shadow: var(--shadow); display: flex; justify-content: space-between; align-items: flex-start; transition: transform 0.2s, box-shadow 0.2s; border-top: 3px solid transparent; }
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
.si-blue  { background: #eff6ff; color: #3b82f6; }
.si-teal  { background: #e0f7fa; color: #17a2b8; }

/* ══ SECTION TABS ═══════════════════════════════════════════════════════════ */
.section-tabs { display: flex; align-items: center; gap: 6px; margin-bottom: 24px; background: #fff; padding: 6px; border-radius: 12px; box-shadow: var(--shadow); width: fit-content; }
.section-tab { padding: 8px 20px; border-radius: 8px; border: none; font-family: 'Poppins'; font-size: 13px; font-weight: 500; color: #777; background: transparent; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; gap: 7px; text-decoration: none; }
.section-tab:hover { color: var(--dark); background: var(--bg); }
.section-tab.active { background: var(--red); color: #fff; font-weight: 600; box-shadow: 0 2px 10px rgba(149,18,44,0.25); }

/* ══ TAB CONTENT ════════════════════════════════════════════════════════════ */
.tab-content        { display: none; }
.tab-content.active { display: block; }

/* ══ CARD ═══════════════════════════════════════════════════════════════════ */
.card { background: var(--white); border-radius: var(--radius); padding: 24px 26px; box-shadow: var(--shadow); margin-bottom: 22px; }
.card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
.card-title { font-size: 15px; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 8px; }
.card-title i { color: var(--red); }

/* ══ FILTER PILLS ═══════════════════════════════════════════════════════════ */
.table-controls { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.filter-pill { padding: 7px 16px; border-radius: 25px; border: 1.5px solid #e0d8d5; background: #fff; color: #666; font-size: 12.5px; font-weight: 500; cursor: pointer; transition: all 0.2s; font-family: 'Poppins'; display: flex; align-items: center; gap: 6px; text-decoration: none; }
.filter-pill:hover  { border-color: var(--red); color: var(--red); }
.filter-pill.active { background: var(--red); color: #fff; border-color: var(--red); box-shadow: 0 2px 8px rgba(149,18,44,0.2); }

/* ══ ADVANCED SEARCH BAR ════════════════════════════════════════════════════ */
.adv-search-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; background: var(--bg); padding: 14px 18px; border-radius: 12px; margin-bottom: 18px; }
.adv-search-bar label { font-size: 12px; font-weight: 600; color: #666; white-space: nowrap; }
.adv-input { padding: 8px 14px; border: 1.5px solid #e0d8d5; border-radius: 8px; font-size: 13px; font-family: 'Poppins'; color: var(--dark); outline: none; transition: border-color 0.2s; background: #fff; }
.adv-input:focus { border-color: var(--red); }
.adv-search-input-wrap { display: flex; align-items: center; gap: 8px; border: 1.5px solid #e0d8d5; border-radius: 8px; padding: 7px 13px; background: #fff; transition: 0.2s; }
.adv-search-input-wrap:focus-within { border-color: var(--red); }
.adv-search-input-wrap i { color: #bbb; font-size: 13px; }
.adv-search-input-wrap input { border: none; outline: none; font-size: 13px; font-family: 'Poppins'; color: var(--dark); width: 190px; background: transparent; }
.btn-apply { padding: 8px 18px; border-radius: 8px; border: none; background: var(--red); color: #fff; font-size: 13px; font-weight: 600; cursor: pointer; font-family: 'Poppins'; transition: 0.2s; display: flex; align-items: center; gap: 6px; text-decoration: none; }
.btn-apply:hover { background: var(--red-dark); }
.btn-clear { padding: 8px 14px; border-radius: 8px; border: 1.5px solid #e0d8d5; background: #fff; color: #777; font-size: 12.5px; font-weight: 500; cursor: pointer; font-family: 'Poppins'; transition: 0.2s; text-decoration: none; display: flex; align-items: center; gap: 6px; }
.btn-clear:hover { border-color: #aaa; }
.btn-export { padding: 8px 16px; border-radius: 8px; border: 1.5px solid #22c55e; background: #edfaf1; color: #1a7a3c; font-size: 12.5px; font-weight: 600; cursor: pointer; font-family: 'Poppins'; transition: 0.2s; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; }
.btn-export:hover { background: #22c55e; color: #fff; }

/* ══ TABLE ══════════════════════════════════════════════════════════════════ */
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; min-width: 800px; }
thead tr { border-bottom: 2px solid #f0ebe8; }
th { padding: 11px 14px; text-align: left; font-size: 11px; font-weight: 700; letter-spacing: 0.6px; text-transform: uppercase; color: #aaa; }
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

.badge-status { display: inline-flex; align-items: center; gap: 5px; padding: 4px 11px; border-radius: 20px; font-size: 11.5px; font-weight: 600; }
.badge-status::before { content: ''; width: 6px; height: 6px; border-radius: 50%; display: inline-block; }
.status-Pending  { background: #fff8e6; color: #b07800; }
.status-Pending::before  { background: #f39c12; }
.status-Approved { background: #edfaf1; color: #1a7a3c; }
.status-Approved::before { background: #28a745; }
.status-Rejected { background: #fdf0f0; color: #9b1c1c; }
.status-Rejected::before { background: #dc3545; }
.status-Returned { background: #e0f0ff; color: #004085; }
.status-Returned::before { background: #17a2b8; }

.qty-badge { display: inline-flex; align-items: center; justify-content: center; background: var(--red); color: #fff; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 700; }

/* ══ ACTION BUTTONS ═════════════════════════════════════════════════════════ */
.btn-act { padding: 5px 11px; border-radius: 7px; border: none; font-size: 12px; font-weight: 600; cursor: pointer; font-family: 'Poppins'; transition: all 0.18s; display: inline-flex; align-items: center; gap: 5px; text-decoration: none; }
.btn-view   { background: #eff6ff; color: #3b82f6; }
.btn-view:hover   { background: #3b82f6; color: #fff; }

/* ══ MODAL ══════════════════════════════════════════════════════════════════ */
.modal-overlay { display: none; position: fixed; z-index: 1500; inset: 0; background: rgba(0,0,0,0.45); align-items: center; justify-content: center; }
.modal-overlay.open { display: flex; }
.modal-box { background: #fff; border-radius: 18px; padding: 32px; max-width: 540px; width: 92%; box-shadow: 0 16px 48px rgba(0,0,0,0.18); animation: modalIn 0.22s ease; }
@keyframes modalIn { from { transform: scale(0.94); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 14px; border-bottom: 1px solid #f0ebe8; }
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
.btn-modal-cancel { background: #f0ebe8; color: #555; }
.btn-modal-cancel:hover { background: #e0d8d5; }

/* ══ PAGINATION ═════════════════════════════════════════════════════════════ */
.pagination { display: flex; align-items: center; justify-content: space-between; margin-top: 20px; flex-wrap: wrap; gap: 12px; }
.pagination-info { font-size: 13px; color: #999; }
.pagination-btns { display: flex; gap: 6px; }
.page-btn { padding: 6px 13px; border-radius: 8px; border: 1.5px solid #e0d8d5; background: #fff; color: #666; font-size: 12.5px; font-weight: 500; cursor: pointer; font-family: 'Poppins'; transition: 0.2s; }
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
    .content { padding: 16px 12px 40px; }
    .top-header { padding: 0 14px; height: auto; min-height: 60px; flex-wrap: wrap; gap: 8px; padding-top: 8px; padding-bottom: 8px; }
    .header-left { gap: 8px; }
    .header-logo-mini { display: none; }
    .header-title-area h1 { font-size: 15px; }
    .header-title-area p { font-size: 11px; }
    .search-box { width: 150px; padding: 7px 12px; }
    .profile-pill span, .profile-pill .pp-role { display: none; }
    .profile-pill { padding: 5px 8px; }
    .stats-row { grid-template-columns: 1fr 1fr; gap: 10px; }
    .stat-card { padding: 14px; }
    .stat-card-body .sc-value { font-size: 24px; }
    .stat-icon { width: 38px; height: 38px; font-size: 16px; }
    .detail-grid { grid-template-columns: 1fr; }
    .card { padding: 14px 12px; }
    .card-header { flex-direction: column; align-items: flex-start; gap: 10px; }
    .table-controls { width: 100%; flex-wrap: wrap; gap: 6px; }
    .adv-search-bar { flex-direction: column; align-items: stretch; gap: 8px; padding: 12px; }
    .adv-search-input-wrap input { width: 100%; }
    .adv-search-input-wrap { width: 100%; }
    .adv-input { width: 100%; }
    .btn-apply, .btn-clear { width: 100%; justify-content: center; }
    .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table { min-width: 600px; }
    th, td { padding: 9px 10px; font-size: 12px; }
    .modal-box { padding: 20px 16px; }
    .pagination { flex-direction: column; align-items: flex-start; gap: 8px; }
    .btn-export { width: 100%; justify-content: center; }
    .filter-pill { font-size: 11.5px; padding: 6px 12px; }
    .section-tabs { width: 100%; }
    .section-tab { flex: 1; justify-content: center; font-size: 12px; padding: 8px 10px; }
    .notif-dropdown { width: 300px; right: -60px; }
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
            <div class="ab-name"><?= htmlspecialchars(strlen($superAdminName)>16 ? substr($superAdminName,0,16).'…' : $superAdminName) ?></div>
            <div class="ab-role">Super Admin</div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="super_admin_dashboard.php">
            <div class="nav-icon"><i class="fas fa-table-cells-large"></i></div> Dashboard
        </a>
        </a>
        <a href="super_analytics.php">
            <div class="nav-icon"><i class="fas fa-chart-bar"></i></div> Analytics
        </a>
        <a href="super_history.php" class="active">
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
                <h1>History Log</h1>
                <p>Welcome back, <strong><?= htmlspecialchars($superAdminName) ?></strong> — <?= date('l, F j, Y') ?></p>
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
                        <div class="notif-empty"><i class="fas fa-bell-slash" style="font-size:24px;display:block;margin-bottom:8px;color:#ddd;"></i>No notifications yet</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Profile pill -->
            <div class="profile-pill" onclick="window.location='super_settings.php'">
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($superAdminName) ?>&background=95122C&color=fff&rounded=true" alt="">
                <div>
                    <span><?= htmlspecialchars(strlen($superAdminName)>14 ? substr($superAdminName,0,14).'…' : $superAdminName) ?></span>
                    <span class="pp-role">Super Admin</span>
                </div>
            </div>
        </div>
    </div>

    <!-- CONTENT AREA -->
    <div class="content">

        <!-- SECTION TABS -->
        <div class="section-tabs">
            <button class="section-tab <?= $activeTab === 'rooms' ? 'active' : '' ?>" onclick="switchTab('rooms', this)">
                <i class="fas fa-door-open"></i> Room Reservations
            </button>
            <button class="section-tab <?= $activeTab === 'equipment' ? 'active' : '' ?>" onclick="switchTab('equipment', this)">
                <i class="fas fa-toolbox"></i> Equipment Borrows
            </button>
        </div>

        <!-- ════════════════ ROOM RESERVATIONS TAB ════════════════════════ -->
        <div id="rooms" class="tab-content <?= $activeTab === 'rooms' ? 'active' : '' ?>">

            <!-- STAT CARDS -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-card-body">
                        <div class="sc-label">Total Reservations</div>
                        <div class="sc-value"><?= $roomTotal ?></div>
                        <div class="sc-sub">All time</div>
                    </div>
                    <div class="stat-icon si-red"><i class="fas fa-calendar-check"></i></div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-body">
                        <div class="sc-label">Pending</div>
                        <div class="sc-value"><?= $roomPending ?></div>
                        <div class="sc-sub">Awaiting action</div>
                    </div>
                    <div class="stat-icon si-amber"><i class="fas fa-hourglass-half"></i></div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-body">
                        <div class="sc-label">Approved</div>
                        <div class="sc-value"><?= $roomApproved ?></div>
                        <div class="sc-sub">Confirmed bookings</div>
                    </div>
                    <div class="stat-icon si-green"><i class="fas fa-circle-check"></i></div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-body">
                        <div class="sc-label">Rejected</div>
                        <div class="sc-value"><?= $roomRejected ?></div>
                        <div class="sc-sub">Declined requests</div>
                    </div>
                    <div class="stat-icon si-rose"><i class="fas fa-circle-xmark"></i></div>
                </div>
            </div>

            <!-- ROOM HISTORY TABLE CARD -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fas fa-door-open"></i> Room Reservation Log</div>
                    <div class="table-controls">
                        <?php
                            $rExportParams = http_build_query(array_filter([
                                'export'    => 'rooms_csv',
                                'htab'      => 'rooms',
                                'rfilter'   => $rFilter !== 'All' ? $rFilter : '',
                                'rsearch'   => $rSearch,
                                'rdate_from'=> $rFrom,
                                'rdate_to'  => $rTo,
                            ]));
                        ?>
                        <a href="super_history.php?<?= $rExportParams ?>" class="btn-export">
                            <i class="fas fa-file-csv"></i> Export CSV
                        </a>
                        <a href="?htab=rooms&rfilter=All"      class="filter-pill <?= $rFilter==='All'      ? 'active' : '' ?>">All</a>
                        <a href="?htab=rooms&rfilter=Pending"  class="filter-pill <?= $rFilter==='Pending'  ? 'active' : '' ?>"><i class="fas fa-hourglass-half" style="font-size:10px;"></i> Pending</a>
                        <a href="?htab=rooms&rfilter=Approved" class="filter-pill <?= $rFilter==='Approved' ? 'active' : '' ?>"><i class="fas fa-check" style="font-size:10px;"></i> Approved</a>
                        <a href="?htab=rooms&rfilter=Rejected" class="filter-pill <?= $rFilter==='Rejected' ? 'active' : '' ?>"><i class="fas fa-xmark" style="font-size:10px;"></i> Rejected</a>
                    </div>
                </div>

                <!-- Advanced search bar -->
                <form method="GET" action="super_history.php">
                    <input type="hidden" name="htab"    value="rooms">
                    <input type="hidden" name="rfilter" value="<?= htmlspecialchars($rFilter) ?>">
                    <input type="hidden" name="rsort"   value="<?= htmlspecialchars($rSort) ?>">
                    <input type="hidden" name="rdir"    value="<?= htmlspecialchars($rDir) ?>">
                    <div class="adv-search-bar">
                        <label><i class="fas fa-search" style="margin-right:5px;color:var(--red);"></i> Search &amp; Filter</label>
                        <div class="adv-search-input-wrap">
                            <i class="fas fa-search"></i>
                            <input type="text" name="rsearch" value="<?= htmlspecialchars($rSearch) ?>" placeholder="Name, room, purpose…">
                        </div>
                        <label>From</label>
                        <input type="date" class="adv-input" name="rdate_from" value="<?= htmlspecialchars($rFrom) ?>">
                        <label>To</label>
                        <input type="date" class="adv-input" name="rdate_to"   value="<?= htmlspecialchars($rTo) ?>">
                        <button type="submit" class="btn-apply"><i class="fas fa-filter"></i> Apply</button>
                        <?php if ($rSearch || $rFrom || $rTo || $rFilter !== 'All'): ?>
                        <a href="super_history.php?htab=rooms" class="btn-clear"><i class="fas fa-xmark"></i> Clear</a>
                        <?php endif; ?>
                    </div>
                </form>

                <?php
                    function rSortLink($col, $label, $rSort, $rDir, $rFilter, $rSearch, $rFrom, $rTo) {
                        $newDir = ($rSort === $col && $rDir === 'ASC') ? 'DESC' : 'ASC';
                        $icon   = ($rSort === $col) ? ($rDir === 'ASC' ? '↑' : '↓') : '↕';
                        $active = ($rSort === $col) ? ' sorted' : '';
                        $p = http_build_query(array_filter(['htab'=>'rooms','rsort'=>$col,'rdir'=>$newDir,'rfilter'=>$rFilter!=='All'?$rFilter:'','rsearch'=>$rSearch,'rdate_from'=>$rFrom,'rdate_to'=>$rTo]));
                        return "<th class=\"$active\"><a href=\"super_history.php?$p\">$label <span class=\"sort-icon\">$icon</span></a></th>";
                    }
                ?>

                <div class="table-wrap">
                    <table id="roomHistoryTable">
                        <thead>
                            <tr>
                                <?= rSortLink('id',           '#',         $rSort, $rDir, $rFilter, $rSearch, $rFrom, $rTo) ?>
                                <?= rSortLink('user_name',    'User',      $rSort, $rDir, $rFilter, $rSearch, $rFrom, $rTo) ?>
                                <?= rSortLink('room_name',    'Room',      $rSort, $rDir, $rFilter, $rSearch, $rFrom, $rTo) ?>
                                <?= rSortLink('booking_date', 'Date',      $rSort, $rDir, $rFilter, $rSearch, $rFrom, $rTo) ?>
                                <th>Time</th>
                                <th>Purpose</th>
                                <?= rSortLink('status',       'Status',    $rSort, $rDir, $rFilter, $rSearch, $rFrom, $rTo) ?>
                                <?= rSortLink('created_at',   'Submitted', $rSort, $rDir, $rFilter, $rSearch, $rFrom, $rTo) ?>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                            $rRowCount = 0;
                            if ($roomRows && $roomRows->num_rows > 0):
                                while ($row = $roomRows->fetch_assoc()):
                                    $rRowCount++;
                                    $dateDisp = fmtDate($row['booking_date'], $row['end_date']);
                                    $timeDisp = fmtTime($row['start_time'], $row['end_time']);
                                    $purp     = $row['purpose'];
                                    $rDept    = '';
                                    if (preg_match('/Department:\s*([^|]+)/i', $purp, $dm)) {
                                        $rDept = trim($dm[1]);
                                    }
                                    $purp = preg_replace('/Department:\s*[^|]+(\||$)/i', '', $purp);
                                    $purp = preg_replace('/Location:\s*[^|]+(\||$)/i', '', $purp);
                                    $purp = preg_replace('/Room:\s*[^|]+(\||$)/i', '', $purp);
                                    $purp = preg_replace('/Purpose:\s*/i', '', $purp);
                                    $purp     = trim(str_replace('|', '', $purp));
                                    $purpFull = htmlspecialchars($purp);
                                    $purpDisp = htmlspecialchars(substr($purp, 0, 45));
                        ?>
                        <tr>
                            <td style="color:#bbb;font-size:12px;"><?= $row['id'] ?></td>
                            <td>
                                <strong><?= htmlspecialchars($row['user_name']) ?></strong>
                                <?php if ($rDept): ?>
                                    <div style="font-size:11.5px;color:var(--red);font-weight:600;margin-top:3px;"><i class="fas fa-building" style="font-size:10px;margin-right:3px;"></i><?= htmlspecialchars($rDept) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($row['room_name']) ?></td>
                            <td style="white-space:nowrap;"><?= $dateDisp ?></td>
                            <td style="white-space:nowrap;color:#777;"><?= $timeDisp ?></td>
                            <td style="color:#777;"><?= $purpDisp ? $purpDisp.(strlen($purp)>45?'…':'') : '<em style="color:#ccc;">—</em>' ?></td>
                            <td><span class="badge-status status-<?= $row['status'] ?>"><?= $row['status'] ?></span></td>
                            <td style="color:#999;font-size:12px;white-space:nowrap;"><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                            <td style="white-space:nowrap;">
                                <button type="button" class="btn-act btn-view"
                                    onclick="viewRoomDetail(
                                        <?= $row['id'] ?>,
                                        '<?= htmlspecialchars(addslashes($row['user_name'])) ?>',
                                        '<?= htmlspecialchars(addslashes($rDept)) ?>',
                                        '<?= htmlspecialchars(addslashes($row['room_name'])) ?>',
                                        '<?= addslashes($dateDisp) ?>',
                                        '<?= addslashes($timeDisp) ?>',
                                        '<?= htmlspecialchars(addslashes($purpFull)) ?>',
                                        '<?= $row['status'] ?>',
                                        '<?= date('M d, Y h:i A', strtotime($row['created_at'])) ?>'
                                    )">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="9">
                            <div class="no-results">
                                <i class="fas fa-inbox"></i>
                                <p>No room reservation records found<?= $rSearch ? " for &ldquo;".htmlspecialchars($rSearch)."&rdquo;" : '' ?>.</p>
                            </div>
                        </td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination">
                    <div class="pagination-info">
                        Showing <strong id="rVisibleCount"><?= $rRowCount ?></strong> of <strong><?= $roomTotal ?></strong> total records
                        <?php if ($rFilter !== 'All' || $rSearch || $rFrom || $rTo): ?>
                            <span style="color:var(--red);"> (filtered)</span>
                        <?php endif; ?>
                    </div>
                    <div class="pagination-btns" id="rPaginationBtns"></div>
                </div>
            </div>
        </div>

        <!-- ════════════════ EQUIPMENT BORROWS TAB ════════════════════════ -->
        <div id="equipment" class="tab-content <?= $activeTab === 'equipment' ? 'active' : '' ?>">

            <!-- STAT CARDS -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-card-body">
                        <div class="sc-label">Total Borrows</div>
                        <div class="sc-value"><?= $equipTotal ?></div>
                        <div class="sc-sub">All time</div>
                    </div>
                    <div class="stat-icon si-red"><i class="fas fa-toolbox"></i></div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-body">
                        <div class="sc-label">Pending</div>
                        <div class="sc-value"><?= $equipPending ?></div>
                        <div class="sc-sub">Awaiting action</div>
                    </div>
                    <div class="stat-icon si-amber"><i class="fas fa-hourglass-half"></i></div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-body">
                        <div class="sc-label">Approved</div>
                        <div class="sc-value"><?= $equipApproved ?></div>
                        <div class="sc-sub">Active borrows</div>
                    </div>
                    <div class="stat-icon si-green"><i class="fas fa-circle-check"></i></div>
                </div>
                <div class="stat-card">
                    <div class="stat-card-body">
                        <div class="sc-label">Returned</div>
                        <div class="sc-value"><?= $equipReturned ?></div>
                        <div class="sc-sub">Completed</div>
                    </div>
                    <div class="stat-icon si-teal"><i class="fas fa-rotate-left"></i></div>
                </div>
            </div>

            <!-- EQUIPMENT HISTORY TABLE CARD -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fas fa-toolbox"></i> Equipment Borrow Log</div>
                    <div class="table-controls">
                        <?php
                            $eExportParams = http_build_query(array_filter([
                                'export'    => 'equip_csv',
                                'htab'      => 'equipment',
                                'efilter'   => $eFilter !== 'All' ? $eFilter : '',
                                'esearch'   => $eSearch,
                                'edate_from'=> $eFrom,
                                'edate_to'  => $eTo,
                            ]));
                        ?>
                        <a href="super_history.php?<?= $eExportParams ?>" class="btn-export">
                            <i class="fas fa-file-csv"></i> Export CSV
                        </a>
                        <a href="?htab=equipment&efilter=All"      class="filter-pill <?= $eFilter==='All'      ? 'active' : '' ?>">All</a>
                        <a href="?htab=equipment&efilter=Pending"  class="filter-pill <?= $eFilter==='Pending'  ? 'active' : '' ?>"><i class="fas fa-hourglass-half" style="font-size:10px;"></i> Pending</a>
                        <a href="?htab=equipment&efilter=Approved" class="filter-pill <?= $eFilter==='Approved' ? 'active' : '' ?>"><i class="fas fa-check" style="font-size:10px;"></i> Approved</a>
                        <a href="?htab=equipment&efilter=Returned" class="filter-pill <?= $eFilter==='Returned' ? 'active' : '' ?>"><i class="fas fa-rotate-left" style="font-size:10px;"></i> Returned</a>
                        <a href="?htab=equipment&efilter=Rejected" class="filter-pill <?= $eFilter==='Rejected' ? 'active' : '' ?>"><i class="fas fa-xmark" style="font-size:10px;"></i> Rejected</a>
                    </div>
                </div>

                <!-- Advanced search bar -->
                <form method="GET" action="super_history.php">
                    <input type="hidden" name="htab"    value="equipment">
                    <input type="hidden" name="efilter" value="<?= htmlspecialchars($eFilter) ?>">
                    <input type="hidden" name="esort"   value="<?= htmlspecialchars($eSort) ?>">
                    <input type="hidden" name="edir"    value="<?= htmlspecialchars($eDir) ?>">
                    <div class="adv-search-bar">
                        <label><i class="fas fa-search" style="margin-right:5px;color:var(--red);"></i> Search &amp; Filter</label>
                        <div class="adv-search-input-wrap">
                            <i class="fas fa-search"></i>
                            <input type="text" name="esearch" value="<?= htmlspecialchars($eSearch) ?>" placeholder="Name, equipment, purpose…">
                        </div>
                        <label>From</label>
                        <input type="date" class="adv-input" name="edate_from" value="<?= htmlspecialchars($eFrom) ?>">
                        <label>To</label>
                        <input type="date" class="adv-input" name="edate_to"   value="<?= htmlspecialchars($eTo) ?>">
                        <button type="submit" class="btn-apply"><i class="fas fa-filter"></i> Apply</button>
                        <?php if ($eSearch || $eFrom || $eTo || $eFilter !== 'All'): ?>
                        <a href="super_history.php?htab=equipment" class="btn-clear"><i class="fas fa-xmark"></i> Clear</a>
                        <?php endif; ?>
                    </div>
                </form>

                <?php
                    function eSortLink($col, $label, $eSort, $eDir, $eFilter, $eSearch, $eFrom, $eTo) {
                        $newDir = ($eSort === $col && $eDir === 'ASC') ? 'DESC' : 'ASC';
                        $icon   = ($eSort === $col) ? ($eDir === 'ASC' ? '↑' : '↓') : '↕';
                        $active = ($eSort === $col) ? ' sorted' : '';
                        $p = http_build_query(array_filter(['htab'=>'equipment','esort'=>$col,'edir'=>$newDir,'efilter'=>$eFilter!=='All'?$eFilter:'','esearch'=>$eSearch,'edate_from'=>$eFrom,'edate_to'=>$eTo]));
                        return "<th class=\"$active\"><a href=\"super_history.php?$p\">$label <span class=\"sort-icon\">$icon</span></a></th>";
                    }
                ?>

                <div class="table-wrap">
                    <table id="equipHistoryTable">
                        <thead>
                            <tr>
                                <?= eSortLink('id',             '#',         $eSort, $eDir, $eFilter, $eSearch, $eFrom, $eTo) ?>
                                <?= eSortLink('user_name',      'User',      $eSort, $eDir, $eFilter, $eSearch, $eFrom, $eTo) ?>
                                <?= eSortLink('equipment_name', 'Equipment', $eSort, $eDir, $eFilter, $eSearch, $eFrom, $eTo) ?>
                                <th>Qty</th>
                                <?= eSortLink('borrow_date',    'Borrow Date',$eSort,$eDir, $eFilter, $eSearch, $eFrom, $eTo) ?>
                                <th>Return Date</th>
                                <th>Time</th>
                                <th>Purpose</th>
                                <?= eSortLink('status',         'Status',    $eSort, $eDir, $eFilter, $eSearch, $eFrom, $eTo) ?>
                                <?= eSortLink('created_at',     'Submitted', $eSort, $eDir, $eFilter, $eSearch, $eFrom, $eTo) ?>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                            $eRowCount = 0;
                            if ($equipRows && $equipRows->num_rows > 0):
                                while ($row = $equipRows->fetch_assoc()):
                                    $eRowCount++;
                                    $borrowDate  = date('M d, Y', strtotime($row['borrow_date']));
                                    $returnDate  = date('M d, Y', strtotime($row['return_date']));
                                    $eTimeDisp   = fmtTime($row['start_time'], $row['end_time']);
                                    $ePurpRaw    = $row['purpose'];
                                    $eDept       = '';
                                    if (preg_match('/Department:\s*([^|]+)/i', $ePurpRaw, $dm)) {
                                        $eDept = trim($dm[1]);
                                    }
                                    $ePurpRaw = preg_replace('/Department:\s*[^|]+(\||$)/i', '', $ePurpRaw);
                                    $ePurpRaw = preg_replace('/Purpose:\s*/i', '', $ePurpRaw);
                                    $ePurpRaw = trim(str_replace('|', '', $ePurpRaw));
                                    $ePurpFull   = htmlspecialchars($ePurpRaw);
                                    $ePurpDisp   = htmlspecialchars(substr($ePurpRaw, 0, 45));
                        ?>
                        <tr>
                            <td style="color:#bbb;font-size:12px;"><?= $row['id'] ?></td>
                            <td>
                                <strong><?= htmlspecialchars($row['user_name']) ?></strong>
                                <?php if ($eDept): ?>
                                    <div style="font-size:11.5px;color:var(--red);font-weight:600;margin-top:3px;"><i class="fas fa-building" style="font-size:10px;margin-right:3px;"></i><?= htmlspecialchars($eDept) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($row['equipment_name']) ?></td>
                            <td><span class="qty-badge"><?= intval($row['quantity']) ?></span></td>
                            <td style="white-space:nowrap;"><?= $borrowDate ?></td>
                            <td style="white-space:nowrap;color:#777;"><?= $returnDate ?></td>
                            <td style="white-space:nowrap;color:#777;"><?= $eTimeDisp ?></td>
                            <td style="color:#777;"><?= $ePurpDisp ? $ePurpDisp.(strlen($ePurpRaw)>45?'…':'') : '<em style="color:#ccc;">—</em>' ?></td>
                            <td><span class="badge-status status-<?= $row['status'] ?>"><?= $row['status'] ?></span></td>
                            <td style="color:#999;font-size:12px;white-space:nowrap;"><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                            <td style="white-space:nowrap;">
                                <button type="button" class="btn-act btn-view"
                                    onclick="viewEquipDetail(
                                        <?= $row['id'] ?>,
                                        '<?= htmlspecialchars(addslashes($row['user_name'])) ?>',
                                        '<?= htmlspecialchars(addslashes($eDept)) ?>',
                                        '<?= htmlspecialchars(addslashes($row['equipment_name'])) ?>',
                                        <?= intval($row['quantity']) ?>,
                                        '<?= addslashes($borrowDate) ?>',
                                        '<?= addslashes($returnDate) ?>',
                                        '<?= addslashes($eTimeDisp) ?>',
                                        '<?= htmlspecialchars(addslashes($ePurpFull)) ?>',
                                        '<?= $row['status'] ?>',
                                        '<?= date('M d, Y h:i A', strtotime($row['created_at'])) ?>'
                                    )">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="11">
                            <div class="no-results">
                                <i class="fas fa-inbox"></i>
                                <p>No equipment borrow records found<?= $eSearch ? " for &ldquo;".htmlspecialchars($eSearch)."&rdquo;" : '' ?>.</p>
                            </div>
                        </td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination">
                    <div class="pagination-info">
                        Showing <strong id="eVisibleCount"><?= $eRowCount ?></strong> of <strong><?= $equipTotal ?></strong> total records
                        <?php if ($eFilter !== 'All' || $eSearch || $eFrom || $eTo): ?>
                            <span style="color:var(--red);"> (filtered)</span>
                        <?php endif; ?>
                    </div>
                    <div class="pagination-btns" id="ePaginationBtns"></div>
                </div>
            </div>
        </div>

    </div><!-- /content -->
</div><!-- /main-wrap -->


<!-- ═══════════════════ ROOM DETAIL MODAL ═══════════════════════════════════ -->
<div id="roomViewModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h2><i class="fas fa-door-open" style="color:var(--red);margin-right:8px;"></i>Room Reservation Detail</h2>
            <button class="modal-close" onclick="closeRoomView()"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="detail-grid">
            <div class="detail-item"><span class="di-label"><i class="fas fa-hashtag"></i> Booking ID</span><span class="di-val" id="rdId">—</span></div>
            <div class="detail-item"><span class="di-label"><i class="fas fa-circle-dot"></i> Status</span><span class="di-val" id="rdStatus">—</span></div>
            <div class="detail-item"><span class="di-label"><i class="fas fa-user"></i> User</span><span class="di-val" id="rdUser">—</span></div>
            <div class="detail-item"><span class="di-label"><i class="fas fa-building"></i> Department</span><span class="di-val" id="rdDepartment">—</span></div>
            <div class="detail-item"><span class="di-label"><i class="fas fa-door-open"></i> Room</span><span class="di-val" id="rdRoom">—</span></div>
            <div class="detail-item"><span class="di-label"><i class="fas fa-calendar"></i> Date</span><span class="di-val" id="rdDate">—</span></div>
            <div class="detail-item"><span class="di-label"><i class="fas fa-clock"></i> Time</span><span class="di-val" id="rdTime">—</span></div>
            <div class="detail-item full"><span class="di-label"><i class="fas fa-pen-to-square"></i> Purpose</span><span class="di-val" id="rdPurpose" style="color:#555;">—</span></div>
            <div class="detail-item full"><span class="di-label"><i class="fas fa-clock-rotate-left"></i> Submitted On</span><span class="di-val" id="rdCreated" style="color:#999;font-size:12.5px;">—</span></div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-modal btn-modal-cancel" onclick="closeRoomView()">Close</button>
        </div>
    </div>
</div>

<!-- ═══════════════════ EQUIPMENT DETAIL MODAL ══════════════════════════════ -->
<div id="equipViewModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h2><i class="fas fa-toolbox" style="color:var(--red);margin-right:8px;"></i>Equipment Borrow Detail</h2>
            <button class="modal-close" onclick="closeEquipView()"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="detail-grid">
            <div class="detail-item"><span class="di-label"><i class="fas fa-hashtag"></i> Borrow ID</span><span class="di-val" id="edId">—</span></div>
            <div class="detail-item"><span class="di-label"><i class="fas fa-circle-dot"></i> Status</span><span class="di-val" id="edStatus">—</span></div>
            <div class="detail-item"><span class="di-label"><i class="fas fa-user"></i> User</span><span class="di-val" id="edUser">—</span></div>
            <div class="detail-item"><span class="di-label"><i class="fas fa-building"></i> Department</span><span class="di-val" id="edDepartment">—</span></div>
            <div class="detail-item"><span class="di-label"><i class="fas fa-toolbox"></i> Equipment</span><span class="di-val" id="edEquip">—</span></div>
            <div class="detail-item"><span class="di-label"><i class="fas fa-layer-group"></i> Quantity</span><span class="di-val" id="edQty">—</span></div>
            <div class="detail-item"><span class="di-label"><i class="fas fa-clock"></i> Time</span><span class="di-val" id="edTime">—</span></div>
            <div class="detail-item"><span class="di-label"><i class="fas fa-calendar"></i> Borrow Date</span><span class="di-val" id="edBorrow">—</span></div>
            <div class="detail-item full"><span class="di-label"><i class="fas fa-calendar-check"></i> Return Date</span><span class="di-val" id="edReturn">—</span></div>
            <div class="detail-item full"><span class="di-label"><i class="fas fa-pen-to-square"></i> Purpose</span><span class="di-val" id="edPurpose" style="color:#555;">—</span></div>
            <div class="detail-item full"><span class="di-label"><i class="fas fa-clock-rotate-left"></i> Submitted On</span><span class="di-val" id="edCreated" style="color:#999;font-size:12.5px;">—</span></div>
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-modal btn-modal-cancel" onclick="closeEquipView()">Close</button>
        </div>
    </div>
</div>

<script>
/* ── Tab switching ─────────────────────────────────────────────────────── */
function switchTab(tab, btn) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.section-tab').forEach(el => el.classList.remove('active'));
    document.getElementById(tab).classList.add('active');
    btn.classList.add('active');
    window.history.replaceState({}, '', '?htab=' + tab);
}

/* ── Live inline search ─────────────────────────────────────────────────── */
function liveSearch(query) {
    const q = query.toLowerCase();
    let rVis = 0, eVis = 0;
    document.querySelectorAll('#roomHistoryTable tbody tr').forEach(tr => {
        const show = tr.textContent.toLowerCase().includes(q);
        tr.style.display = show ? '' : 'none';
        if (show) rVis++;
    });
    document.querySelectorAll('#equipHistoryTable tbody tr').forEach(tr => {
        const show = tr.textContent.toLowerCase().includes(q);
        tr.style.display = show ? '' : 'none';
        if (show) eVis++;
    });
    const rv = document.getElementById('rVisibleCount');
    const ev = document.getElementById('eVisibleCount');
    if (rv) rv.textContent = rVis;
    if (ev) ev.textContent = eVis;
}

/* ── Notifications ─────────────────────────────────────────────────────── */
function toggleNotif(e) {
    e.stopPropagation();
    document.getElementById('notifDropdown').classList.toggle('active');
}
document.addEventListener('click', () => {
    document.getElementById('notifDropdown')?.classList.remove('active');
});

/* ── Room View Modal ────────────────────────────────────────────────────── */
function viewRoomDetail(id, user, department, room, date, time, purpose, status, created) {
    document.getElementById('rdId').textContent         = '#' + id;
    document.getElementById('rdUser').textContent       = user;
    document.getElementById('rdDepartment').textContent = department || '—';
    document.getElementById('rdRoom').textContent       = room;
    document.getElementById('rdDate').textContent       = date;
    document.getElementById('rdTime').textContent       = time;
    document.getElementById('rdPurpose').textContent    = purpose || '—';
    document.getElementById('rdCreated').textContent    = created;
    document.getElementById('rdStatus').innerHTML       = `<span class="badge-status status-${status}">${status}</span>`;
    document.getElementById('roomViewModal').classList.add('open');
}
function closeRoomView() { document.getElementById('roomViewModal').classList.remove('open'); }

/* ── Equipment View Modal ───────────────────────────────────────────────── */
function viewEquipDetail(id, user, department, equip, qty, borrowDate, returnDate, time, purpose, status, created) {
    document.getElementById('edId').textContent         = '#' + id;
    document.getElementById('edUser').textContent       = user;
    document.getElementById('edDepartment').textContent = department || '—';
    document.getElementById('edEquip').textContent      = equip;
    document.getElementById('edQty').textContent        = qty;
    document.getElementById('edBorrow').textContent     = borrowDate;
    document.getElementById('edReturn').textContent     = returnDate;
    document.getElementById('edTime').textContent       = time;
    document.getElementById('edPurpose').textContent    = purpose || '—';
    document.getElementById('edCreated').textContent    = created;
    document.getElementById('edStatus').innerHTML       = `<span class="badge-status status-${status}">${status}</span>`;
    document.getElementById('equipViewModal').classList.add('open');
}
function closeEquipView() { document.getElementById('equipViewModal').classList.remove('open'); }

window.addEventListener('click', e => {
    if (e.target.classList.contains('modal-overlay')) {
        closeRoomView(); closeEquipView();
    }
});

/* ── Client-side pagination ─────────────────────────────────────────────── */
function setupPagination(tableId, countId, btnContainerId) {
    const ROWS = 20;
    const tbody = document.querySelector('#' + tableId + ' tbody');
    if (!tbody) return;
    const rows  = Array.from(tbody.querySelectorAll('tr'));
    const total = rows.length;
    if (total <= ROWS) return;

    let cur = 1;
    const pages = Math.ceil(total / ROWS);

    function showPage(p) {
        cur = p;
        const start = (p-1)*ROWS, end = start+ROWS;
        rows.forEach((r,i) => r.style.display = (i>=start && i<end) ? '' : 'none');
        const vc = document.getElementById(countId);
        if (vc) vc.textContent = Math.min(end,total) - start;
        render();
    }
    function render() {
        const c = document.getElementById(btnContainerId);
        if (!c) return;
        c.innerHTML = '';
        const mk = (lbl, p, active) => {
            const b = document.createElement('button');
            b.className = 'page-btn' + (active ? ' active' : '');
            b.textContent = lbl;
            b.onclick = () => showPage(p);
            c.appendChild(b);
        };
        if (cur > 1) mk('‹ Prev', cur-1, false);
        for (let p=1; p<=pages; p++) {
            if (pages<=7 || p===1 || p===pages || Math.abs(p-cur)<=1) {
                mk(p, p, p===cur);
            } else if (Math.abs(p-cur)===2) {
                const d = document.createElement('span');
                d.style.cssText = 'padding:6px 8px;color:#bbb;font-size:13px;';
                d.textContent = '…';
                c.appendChild(d);
            }
        }
        if (cur < pages) mk('Next ›', cur+1, false);
    }
    showPage(1);
}

setupPagination('roomHistoryTable',  'rVisibleCount', 'rPaginationBtns');
setupPagination('equipHistoryTable', 'eVisibleCount', 'ePaginationBtns');
</script>
</body>
</html>