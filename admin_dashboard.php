<?php
/**
 * Admin Dashboard - AssetEase
 * Enhanced: updated header/logo, search & filter, monthly reservation calendar,
 * User Management sidebar entry, consistent UI based on dashboard.php style.
 */
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

$adminId = $_SESSION['user_id'];

// Fetch admin's current name from database
$adminStmt = $conn->prepare("SELECT uname FROM users WHERE id = ?");
$adminStmt->bind_param("i", $adminId);
$adminStmt->execute();
$adminResult = $adminStmt->get_result();
$adminRow    = $adminResult->fetch_assoc();
$adminName   = $adminRow['uname'] ?? 'Admin';
$msg         = '';
$msgType     = '';

// ==========================================
// HANDLE APPROVE ACTION WITH EMAIL
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_booking'])) {
    $bookingId = (int)$_POST['booking_id'];
    try {
        $bookingStmt = $conn->prepare("SELECT b.id, b.room_name, b.booking_date, b.end_date, b.start_time, b.end_time, b.user_id, b.user_name, u.email FROM room_reservations b LEFT JOIN users u ON b.user_id = u.id WHERE b.id = ?");
        $bookingStmt->bind_param("i", $bookingId);
        $bookingStmt->execute();
        $booking = $bookingStmt->get_result()->fetch_assoc();
        if (!$booking) throw new Exception("Booking not found.");

        $updateStmt = $conn->prepare("UPDATE room_reservations SET status = 'Approved' WHERE id = ?");
        $updateStmt->bind_param("i", $bookingId);
        $updateStmt->execute();

        $displayDate = ($booking['booking_date'] === $booking['end_date'])
            ? date('M d, Y', strtotime($booking['booking_date']))
            : date('M d, Y', strtotime($booking['booking_date'])) . ' to ' . date('M d, Y', strtotime($booking['end_date']));
        $displayTime = date('h:i A', strtotime($booking['start_time'])) . ' - ' . date('h:i A', strtotime($booking['end_time']));

        $notifMsg = "Your room reservation request for " . $booking['room_name'] . " on " . $displayDate . " has been APPROVED.";
        $notifStmt = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
        $notifStmt->bind_param("is", $booking['user_id'], $notifMsg);
        $notifStmt->execute();

        $adminNotifMsg = "You approved the room reservation request for " . $booking['room_name'] . " (User: " . $booking['user_name'] . ") on " . $displayDate . ".";
        $adminNotifStmt = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
        $adminNotifStmt->bind_param("is", $adminId, $adminNotifMsg);
        $adminNotifStmt->execute();

        if (!empty($booking['email'])) {
            $emailSubject = "Room Reservation Approved - AssetEase";
            $emailBody = "<html><body style='font-family:Arial,sans-serif;background:#f5f5f5;padding:20px;'>
                <div style='max-width:600px;margin:auto;'>
                <div style='background:#95122C;color:white;padding:25px;text-align:center;border-radius:10px 10px 0 0;'>
                <h2 style='margin:0;'>✓ Reservation Approved!</h2></div>
                <div style='padding:25px;background:white;border-radius:0 0 10px 10px;border:1px solid #eee;'>
                <p>Hello <strong>" . htmlspecialchars($booking['user_name']) . "</strong>,</p>
                <p>Your reservation for <strong>" . htmlspecialchars($booking['room_name']) . "</strong> on <strong>" . htmlspecialchars($displayDate) . "</strong> has been approved.</p>
                <p>Time: " . htmlspecialchars($displayTime) . "</p>
                <p>AssetEase Facility Management Team</p></div></div></body></html>";
            $emailSent = sendEmail($booking['email'], $emailSubject, $emailBody);
        }

        $msg = "Booking #$bookingId approved successfully!";
        $msgType = "success";
    } catch (Exception $e) {
        $msg = "Error approving booking: " . $e->getMessage();
        $msgType = "error";
    }
}

// ==========================================
// HANDLE REJECT ACTION WITH REASON
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_booking'])) {
    $bookingId    = (int)$_POST['booking_id'];
    $rejectReason = trim($_POST['reject_reason'] ?? 'No reason provided');
    try {
        $bookingStmt = $conn->prepare("SELECT b.id, b.room_name, b.booking_date, b.end_date, b.start_time, b.end_time, b.user_id, b.user_name, u.email FROM room_reservations b LEFT JOIN users u ON b.user_id = u.id WHERE b.id = ?");
        $bookingStmt->bind_param("i", $bookingId);
        $bookingStmt->execute();
        $booking = $bookingStmt->get_result()->fetch_assoc();
        if (!$booking) throw new Exception("Booking not found.");

        $stmt = $conn->prepare("UPDATE room_reservations SET status = 'Rejected' WHERE id = ?");
        $stmt->bind_param("i", $bookingId);
        $stmt->execute();

        $displayDate = ($booking['booking_date'] === $booking['end_date'])
            ? date('M d, Y', strtotime($booking['booking_date']))
            : date('M d, Y', strtotime($booking['booking_date'])) . ' to ' . date('M d, Y', strtotime($booking['end_date']));

        $notifMsg = "Your room reservation for " . $booking['room_name'] . " on " . $displayDate . " has been REJECTED. Reason: " . $rejectReason;
        $notifStmt = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
        $notifStmt->bind_param("is", $booking['user_id'], $notifMsg);
        $notifStmt->execute();

        if (!empty($booking['email'])) {
            $emailSubject = "Room Reservation Declined - AssetEase";
            $emailBody = "<html><body style='font-family:Arial,sans-serif;background:#f5f5f5;padding:20px;'>
                <div style='max-width:600px;margin:auto;'>
                <div style='background:#dc3545;color:white;padding:25px;text-align:center;border-radius:10px 10px 0 0;'>
                <h2 style='margin:0;'>⚠ Reservation Declined</h2></div>
                <div style='padding:25px;background:white;border-radius:0 0 10px 10px;border:1px solid #eee;'>
                <p>Hello <strong>" . htmlspecialchars($booking['user_name']) . "</strong>,</p>
                <p>Your reservation for <strong>" . htmlspecialchars($booking['room_name']) . "</strong> on <strong>" . htmlspecialchars($displayDate) . "</strong> has been declined.</p>
                <div style='background:#fff5f5;border-left:4px solid #dc3545;padding:15px;margin:20px 0;'>
                <strong>Reason:</strong> " . htmlspecialchars($rejectReason) . "</div>
                <p>AssetEase Facility Management Team</p></div></div></body></html>";
            $emailSent = sendEmail($booking['email'], $emailSubject, $emailBody);
        }

        $msg = "Booking #$bookingId rejected.";
        $msgType = "success";
    } catch (Exception $e) {
        $msg = "Error rejecting booking: " . $e->getMessage();
        $msgType = "error";
    }
}

// ==========================================
// HANDLE USER MANAGEMENT ACTIONS
// ==========================================

if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM room_reservations WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: admin_dashboard.php?msg=deleted");
    exit();
}

// ==========================================
// STATS
// ==========================================
$totalBookings  = $conn->query("SELECT COUNT(*) as total FROM room_reservations")->fetch_assoc()['total'];
$pendingCount   = $conn->query("SELECT COUNT(*) as total FROM room_reservations WHERE status = 'Pending'")->fetch_assoc()['total'];
$approvedCount  = $conn->query("SELECT COUNT(*) as total FROM room_reservations WHERE status = 'Approved'")->fetch_assoc()['total'];
$rejectedCount  = $conn->query("SELECT COUNT(*) as total FROM room_reservations WHERE status = 'Rejected'")->fetch_assoc()['total'];

// Total users
$totalUsers = $conn->query("SELECT COUNT(*) as total FROM users")->fetch_assoc()['total'];

// ==========================================
// FILTERING + SEARCH
// ==========================================
$currentFilter   = $_GET['filter']   ?? 'All';
$currentSection  = $_GET['section']  ?? 'dashboard';
$searchQuery     = trim($_GET['search'] ?? '');
$dateFrom        = $_GET['date_from'] ?? '';
$dateTo          = $_GET['date_to']   ?? '';

$sql = "SELECT id, user_name, room_name, booking_date, end_date, start_time, end_time, purpose, status FROM room_reservations WHERE 1=1";
$params = [];
$types  = '';

if ($currentFilter === 'Pending')  { $sql .= " AND status = 'Pending'"; }
elseif ($currentFilter === 'Approved') { $sql .= " AND status = 'Approved'"; }
elseif ($currentFilter === 'Rejected') { $sql .= " AND status = 'Rejected'"; }

if ($searchQuery) {
    $sql    .= " AND (user_name LIKE ? OR room_name LIKE ? OR purpose LIKE ?)";
    $like    = '%' . $searchQuery . '%';
    $params  = array_merge($params, [$like, $like, $like]);
    $types  .= 'sss';
}
if ($dateFrom) { $sql .= " AND booking_date >= ?"; $params[] = $dateFrom; $types .= 's'; }
if ($dateTo)   { $sql .= " AND booking_date <= ?"; $params[] = $dateTo;   $types .= 's'; }

$sql .= " ORDER BY created_at DESC";

if ($params) {
    $roomStmt = $conn->prepare($sql);
    $roomStmt->bind_param($types, ...$params);
    $roomStmt->execute();
    $roomReservations = $roomStmt->get_result();
} else {
    $roomReservations = $conn->query($sql);
}

// ==========================================
// MONTHLY CALENDAR DATA
// ==========================================
$calYear  = (int)($_GET['cal_year']  ?? date('Y'));
$calMonth = (int)($_GET['cal_month'] ?? date('n'));
if ($calMonth < 1)  { $calMonth = 12; $calYear--; }
if ($calMonth > 12) { $calMonth =  1; $calYear++; }

$calStart = sprintf('%04d-%02d-01', $calYear, $calMonth);
$calEnd   = date('Y-m-t', strtotime($calStart));
$calBookings = $conn->query("SELECT booking_date, status, room_name, user_name FROM room_reservations WHERE booking_date BETWEEN '$calStart' AND '$calEnd' ORDER BY booking_date");
$calData = [];
while ($cb = $calBookings->fetch_assoc()) {
    $d = (int)date('j', strtotime($cb['booking_date']));
    $calData[$d][] = $cb;
}

// ==========================================
// NOTIFICATIONS
// ==========================================
$adminNotifQuery = $conn->prepare("SELECT n.id, n.message, n.created_at FROM notifications n WHERE n.user_id = ? ORDER BY n.created_at DESC LIMIT 10");
$adminNotifQuery->bind_param("i", $adminId);
$adminNotifQuery->execute();
$adminNotifs = $adminNotifQuery->get_result();

$adminNotifList = [];
while ($notif = $adminNotifs->fetch_assoc()) { $adminNotifList[] = $notif; }
$adminNotifCount = count($adminNotifList);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — AssetEase</title>
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

/* Logo area */
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

/* Admin badge */
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
.admin-badge-info .ab-name {
    font-size: 12px;
    font-weight: 700;
    color: #fff;
    line-height: 1;
}
.admin-badge-info .ab-role {
    font-size: 10px;
    color: var(--gold);
    text-transform: uppercase;
    letter-spacing: 0.8px;
    margin-top: 3px;
}

/* Nav */
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
.sidebar a:hover {
    background: rgba(255,255,255,0.10);
    color: #fff;
    border-left-color: rgba(255,255,255,0.3);
}
.sidebar a.active {
    background: rgba(255,255,255,0.15);
    color: #fff;
    border-left-color: var(--gold);
    font-weight: 600;
}
.sidebar a.active .nav-icon {
    background: rgba(255,215,0,0.18);
    color: var(--gold);
}
.sidebar a:hover .nav-icon { background: rgba(255,255,255,0.12); }

.sidebar a .nav-badge {
    margin-left: auto;
    background: var(--red);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 10px;
    min-width: 20px;
    text-align: center;
}

.sidebar hr {
    border: none;
    border-top: 1px solid rgba(255,255,255,0.09);
    margin: 8px 18px;
}

/* Sidebar footer promo */
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
    background: var(--gold);
    color: var(--dark);
    border-radius: 7px;
    font-size: 11px;
    font-weight: 700;
    text-decoration: none;
    border: none !important;
    border-left: none !important;
    background-color: var(--gold) !important;
    margin-left: 0 !important;
    border-radius: 7px !important;
}
.sidebar-promo a:hover { opacity: 0.9; transform: none; background: var(--gold) !important; }

/* ══ MAIN LAYOUT ════════════════════════════════════════════════════════════ */
.main-wrap {
    margin-left: var(--sidebar-w);
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 100vh;
}

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

/* Header left: breadcrumb + title */
.header-left {
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 0;
}
.header-logo-mini {
    display: flex;
    align-items: center;
    gap: 9px;
    padding-right: 14px;
    border-right: 1px solid #eee;
}
.header-logo-mini img {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    object-fit: cover;
    border: 1.5px solid rgba(149,18,44,0.2);
}
.header-logo-mini span {
    font-size: 14px;
    font-weight: 700;
    color: var(--red);
    letter-spacing: 1.5px;
}
.header-title-area h1 { font-size: 19px; font-weight: 700; color: var(--dark); line-height: 1.1; }
.header-title-area p  { font-size: 12px; color: #999; margin-top: 1px; }

/* Header right */
.header-right { display: flex; align-items: center; gap: 11px; flex-shrink: 0; }

.search-box {
    display: flex;
    align-items: center;
    gap: 9px;
    background: var(--bg);
    border-radius: 25px;
    padding: 9px 16px;
    width: 230px;
    border: 1.5px solid transparent;
    transition: 0.2s;
}
.search-box:focus-within { border-color: var(--red); background: #fff; box-shadow: 0 0 0 3px rgba(149,18,44,0.07); }
.search-box i { color: #bbb; font-size: 13px; }
.search-box input {
    border: none; background: transparent; font-size: 13px;
    font-family: 'Poppins'; color: var(--dark); outline: none; width: 100%;
}
.search-box input::placeholder { color: #ccc; }

/* Notification bell */
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
.notif-header {
    padding: 14px 18px 10px; font-weight: 700; font-size: 13px;
    color: var(--dark); border-bottom: 1px solid #f0f0f0;
    display: flex; justify-content: space-between; align-items: center;
}
.notif-item { padding: 12px 18px; border-bottom: 1px solid #f8f8f8; cursor: pointer; display: flex; gap: 10px; }
.notif-item:hover { background: #fafafa; }
.notif-item .ni-icon { color: var(--red); font-size: 13px; margin-top: 2px; flex-shrink: 0; }
.notif-item .ni-msg { font-size: 12.5px; color: #444; line-height: 1.4; }
.notif-item .ni-time { font-size: 11px; color: #bbb; margin-top: 2px; }
.notif-empty { padding: 28px; text-align: center; color: #bbb; font-size: 13px; }

/* Profile pill */
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

/* ══ SECTION TABS ═══════════════════════════════════════════════════════════ */
.section-tabs {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 24px;
    background: #fff;
    padding: 6px;
    border-radius: 12px;
    box-shadow: var(--shadow);
    width: fit-content;
}
.section-tab {
    padding: 8px 20px;
    border-radius: 8px;
    border: none;
    font-family: 'Poppins';
    font-size: 13px;
    font-weight: 500;
    color: #777;
    background: transparent;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 7px;
    text-decoration: none;
}
.section-tab:hover { color: var(--dark); background: var(--bg); }
.section-tab.active { background: var(--red); color: #fff; font-weight: 600; box-shadow: 0 2px 10px rgba(149,18,44,0.25); }

/* ══ STAT CARDS ═════════════════════════════════════════════════════════════ */
.stats-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 18px;
    margin-bottom: 24px;
}
.stat-card {
    background: var(--white);
    border-radius: var(--radius);
    padding: 22px 22px;
    box-shadow: var(--shadow);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    transition: transform 0.2s, box-shadow 0.2s;
    border-top: 3px solid transparent;
}
.stat-card:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
.stat-card:nth-child(1) { border-top-color: var(--red); }
.stat-card:nth-child(2) { border-top-color: #f39c12; }
.stat-card:nth-child(3) { border-top-color: #22c55e; }
.stat-card:nth-child(4) { border-top-color: #3b82f6; }
.stat-card-body .sc-label { font-size: 12px; color: #999; font-weight: 500; margin-bottom: 7px; text-transform: uppercase; letter-spacing: 0.4px; }
.stat-card-body .sc-value { font-size: 32px; font-weight: 700; color: var(--dark); line-height: 1; }
.stat-card-body .sc-sub { font-size: 11.5px; color: #aaa; margin-top: 5px; }
.stat-icon {
    width: 48px; height: 48px; border-radius: 11px;
    display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0;
}
.si-red   { background: #fef2f2; color: var(--red); }
.si-amber { background: #fff7ed; color: #f97316; }
.si-green { background: #f0fdf4; color: #22c55e; }
.si-blue  { background: #eff6ff; color: #3b82f6; }

/* ══ CARD ═══════════════════════════════════════════════════════════════════ */
.card {
    background: var(--white);
    border-radius: var(--radius);
    padding: 24px 26px;
    box-shadow: var(--shadow);
    margin-bottom: 22px;
}
.card-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 20px; flex-wrap: wrap; gap: 12px;
}
.card-title {
    font-size: 15px; font-weight: 700; color: var(--dark);
    display: flex; align-items: center; gap: 8px;
}
.card-title i { color: var(--red); }

/* ══ FILTER & SEARCH BAR ════════════════════════════════════════════════════ */
.table-controls {
    display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
}
.filter-pill {
    padding: 7px 16px;
    border-radius: 25px;
    border: 1.5px solid #e0d8d5;
    background: #fff;
    color: #666; font-size: 12.5px; font-weight: 500;
    cursor: pointer; transition: all 0.2s;
    font-family: 'Poppins';
    display: flex; align-items: center; gap: 6px;
}
.filter-pill:hover  { border-color: var(--red); color: var(--red); }
.filter-pill.active { background: var(--red); color: #fff; border-color: var(--red); box-shadow: 0 2px 8px rgba(149,18,44,0.2); }

.adv-search-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    background: var(--bg);
    padding: 14px 18px;
    border-radius: 12px;
    margin-bottom: 18px;
}
.adv-search-bar label { font-size: 12px; font-weight: 600; color: #666; white-space: nowrap; }
.adv-input {
    padding: 8px 14px;
    border: 1.5px solid #e0d8d5;
    border-radius: 8px;
    font-size: 13px;
    font-family: 'Poppins';
    color: var(--dark);
    outline: none;
    transition: border-color 0.2s;
    background: #fff;
}
.adv-input:focus { border-color: var(--red); }
.adv-search-input-wrap {
    display: flex; align-items: center; gap: 8px;
    border: 1.5px solid #e0d8d5; border-radius: 8px;
    padding: 7px 13px; background: #fff; transition: 0.2s;
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
    display: flex; align-items: center; gap: 6px;
}
.btn-apply:hover { background: var(--red-dark); }
.btn-clear {
    padding: 8px 14px; border-radius: 8px; border: 1.5px solid #e0d8d5;
    background: #fff; color: #777; font-size: 12.5px; font-weight: 500;
    cursor: pointer; font-family: 'Poppins'; transition: 0.2s;
}
.btn-clear:hover { border-color: #aaa; }

/* ══ TABLE ══════════════════════════════════════════════════════════════════ */
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; min-width: 720px; }
thead tr { border-bottom: 2px solid #f0ebe8; }
th {
    padding: 11px 14px; text-align: left;
    font-size: 11px; font-weight: 700; letter-spacing: 0.6px;
    text-transform: uppercase; color: #aaa;
}
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

.badge-role {
    display: inline-flex; align-items: center; padding: 3px 10px;
    border-radius: 20px; font-size: 11px; font-weight: 600;
}
.role-user       { background: #eff6ff; color: #3b82f6; }
.role-admin      { background: #fef2f2; color: var(--red); }
.role-super_admin{ background: rgba(255,215,0,0.15); color: #a07800; }

/* ══ ACTION BUTTONS ═════════════════════════════════════════════════════════ */
.btn-act {
    padding: 5px 11px; border-radius: 7px; border: none;
    font-size: 12px; font-weight: 600; cursor: pointer;
    font-family: 'Poppins'; transition: all 0.18s;
    display: inline-flex; align-items: center; gap: 5px;
}
.btn-approve { background: #edfaf1; color: #1a7a3c; }
.btn-approve:hover { background: #28a745; color: #fff; }
.btn-reject  { background: #fdf0f0; color: #9b1c1c; }
.btn-reject:hover  { background: #dc3545; color: #fff; }
.btn-delete  { background: #f3f3f3; color: #555; }
.btn-delete:hover  { background: #343a40; color: #fff; }
.btn-edit    { background: #eff6ff; color: #3b82f6; }
.btn-edit:hover { background: #3b82f6; color: #fff; }

/* ══ MODAL ══════════════════════════════════════════════════════════════════ */
.modal-overlay {
    display: none; position: fixed; z-index: 1500;
    inset: 0; background: rgba(0,0,0,0.45);
    align-items: center; justify-content: center;
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: #fff; border-radius: 18px;
    padding: 32px; max-width: 500px; width: 92%;
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
.modal-info {
    background: #fdf9f8; border-radius: 10px; padding: 14px;
    color: #555; font-size: 13px; line-height: 1.7; margin-bottom: 16px;
}
.modal-hint {
    background: #f0f7ff; border-radius: 8px; padding: 11px 14px;
    color: #444; font-size: 12.5px; display: flex; gap: 8px; align-items: flex-start; margin-bottom: 16px;
}
.modal-hint i { color: #0066cc; margin-top: 2px; }
.modal-label { font-size: 13px; font-weight: 600; color: var(--dark); margin-bottom: 8px; display: block; }
.modal-textarea {
    width: 100%; padding: 11px 14px;
    border: 1.5px solid #e0d8d5; border-radius: 10px;
    font-family: 'Poppins'; font-size: 13px; color: #333;
    resize: vertical; min-height: 90px; outline: none; transition: border-color 0.2s;
}
.modal-textarea:focus { border-color: var(--red); }
.modal-input {
    width: 100%; padding: 10px 14px;
    border: 1.5px solid #e0d8d5; border-radius: 9px;
    font-family: 'Poppins'; font-size: 13px; color: #333; outline: none;
    transition: border-color 0.2s; margin-bottom: 12px;
}
.modal-input:focus { border-color: var(--red); }
.modal-select {
    width: 100%; padding: 10px 14px;
    border: 1.5px solid #e0d8d5; border-radius: 9px;
    font-family: 'Poppins'; font-size: 13px; color: #333; outline: none;
    background: #fff; cursor: pointer; transition: border-color 0.2s; margin-bottom: 12px;
}
.modal-select:focus { border-color: var(--red); }
.modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
.btn-modal {
    padding: 10px 22px; border-radius: 9px; border: none;
    font-size: 13px; font-weight: 600; font-family: 'Poppins'; cursor: pointer; transition: 0.2s;
    display: flex; align-items: center; gap: 6px;
}
.btn-modal-cancel  { background: #f0ebe8; color: #555; }
.btn-modal-cancel:hover { background: #e0d8d5; }
.btn-modal-confirm { background: var(--red); color: #fff; }
.btn-modal-confirm:hover { background: var(--red-dark); }

/* ══ CALENDAR ═══════════════════════════════════════════════════════════════ */
.cal-wrap { }
.cal-nav {
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 18px; flex-wrap: wrap;
}
.cal-nav h3 { font-size: 18px; font-weight: 700; color: var(--dark); flex: 1; }
.cal-nav-btn {
    width: 36px; height: 36px; border-radius: 9px; border: 1.5px solid #e0d8d5;
    background: #fff; color: var(--dark); font-size: 14px; cursor: pointer;
    display: flex; align-items: center; justify-content: center; transition: 0.2s;
}
.cal-nav-btn:hover { border-color: var(--red); color: var(--red); }
.cal-legend {
    display: flex; gap: 16px; margin-bottom: 12px; flex-wrap: wrap;
}
.cal-legend-item { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #666; }
.cal-legend-dot  { width: 10px; height: 10px; border-radius: 50%; }

.calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 4px;
}
.cal-day-header {
    text-align: center;
    font-size: 11px;
    font-weight: 700;
    color: #aaa;
    text-transform: uppercase;
    padding: 6px 0;
    letter-spacing: 0.5px;
}
.cal-day {
    min-height: 80px;
    background: #fafafa;
    border-radius: 8px;
    padding: 7px 8px;
    cursor: default;
    transition: background 0.15s;
    border: 1.5px solid transparent;
    position: relative;
}
.cal-day.today { border-color: var(--red); background: #fff8f9; }
.cal-day.other-month { opacity: 0.35; }
.cal-day:hover { background: #f5f0ef; }
.cal-day-num {
    font-size: 12px; font-weight: 700; color: #444; line-height: 1;
    margin-bottom: 5px;
}
.cal-day.today .cal-day-num {
    background: var(--red); color: #fff;
    width: 22px; height: 22px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center; font-size: 11px;
}
.cal-event {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 2px 5px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 600;
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.cal-event-dot { width: 5px; height: 5px; border-radius: 50%; flex-shrink: 0; }
.cal-event.ev-approved { background: #edfaf1; color: #1a7a3c; }
.cal-event.ev-approved .cal-event-dot { background: #28a745; }
.cal-event.ev-pending  { background: #fff8e6; color: #b07800; }
.cal-event.ev-pending .cal-event-dot  { background: #f39c12; }
.cal-event.ev-rejected { background: #fdf0f0; color: #9b1c1c; }
.cal-event.ev-rejected .cal-event-dot { background: #dc3545; }
.cal-more { font-size: 10px; color: #999; font-weight: 600; margin-top: 2px; }

/* ══ USER MGMT FORM ═════════════════════════════════════════════════════════ */
.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px 18px;
    margin-bottom: 18px;
}
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-group label { font-size: 12.5px; font-weight: 600; color: #555; }
.form-group input, .form-group select {
    padding: 10px 14px;
    border: 1.5px solid #e0d8d5;
    border-radius: 9px;
    font-family: 'Poppins'; font-size: 13px;
    color: var(--dark); outline: none;
    transition: border-color 0.2s; background: #fff;
}
.form-group input:focus, .form-group select:focus { border-color: var(--red); }
.form-full { grid-column: 1 / -1; }
.btn-primary {
    padding: 11px 26px; border-radius: 10px; border: none;
    background: var(--red); color: #fff; font-size: 13.5px; font-weight: 600;
    cursor: pointer; font-family: 'Poppins'; transition: 0.2s;
    display: flex; align-items: center; gap: 8px;
}
.btn-primary:hover { background: var(--red-dark); }

/* ══ CHAT BOT ════════════════════════════════════════════════════════════════ */
.chat-toggle {
    position: fixed; bottom: 24px; right: 24px;
    width: 54px; height: 54px;
    background: var(--red); color: white;
    border: none; border-radius: 50%; cursor: pointer;
    font-size: 20px; z-index: 1200;
    box-shadow: 0 4px 18px rgba(149,18,44,0.4);
    transition: transform 0.2s, box-shadow 0.2s;
    display: flex; align-items: center; justify-content: center;
}
.chat-toggle:hover { transform: scale(1.09); box-shadow: 0 6px 24px rgba(149,18,44,0.5); }
.chat-bot {
    position: fixed; bottom: 90px; right: 24px;
    width: 370px; height: 510px;
    background: white; border-radius: 18px;
    box-shadow: 0 12px 42px rgba(0,0,0,0.18);
    z-index: 1200; display: none; flex-direction: column; overflow: hidden;
}
.chat-bot.active { display: flex; }
.chat-header {
    background: var(--red); color: white; padding: 16px 20px;
    display: flex; justify-content: space-between; align-items: center;
}
.chat-header h3 { font-size: 14.5px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
.chat-header button { background: none; border: none; color: rgba(255,255,255,0.8); cursor: pointer; font-size: 17px; }
.chat-header button:hover { color: #fff; }
.chat-messages {
    flex: 1; overflow-y: auto; padding: 16px;
    display: flex; flex-direction: column; gap: 10px; background: #faf8f7;
}
.message {
    padding: 10px 13px; border-radius: 12px;
    max-width: 82%; font-size: 13px; line-height: 1.5;
}
.message.user { background: var(--red); color: white; align-self: flex-end; border-bottom-right-radius: 4px; }
.message.bot  { background: #fff; color: #333; align-self: flex-start; border-bottom-left-radius: 4px; box-shadow: 0 1px 4px rgba(0,0,0,0.07); }
.chat-input-area {
    display: flex; gap: 8px; padding: 13px;
    border-top: 1px solid #f0ebe8; background: white;
}
.chat-input-area input {
    flex: 1; padding: 9px 14px; border: 1.5px solid #e0d8d5;
    border-radius: 10px; font-size: 13px; font-family: 'Poppins'; outline: none;
    transition: border-color 0.2s;
}
.chat-input-area input:focus { border-color: var(--red); }
.chat-input-area button {
    background: var(--red); color: white; border: none;
    padding: 9px 15px; border-radius: 10px;
    cursor: pointer; font-size: 14px; transition: 0.2s;
}
.chat-input-area button:hover { background: var(--red-dark); }

/* No results */
.no-results { text-align: center; padding: 40px 20px; color: #ccc; }
.no-results i { font-size: 32px; margin-bottom: 10px; display: block; color: #ddd; }
.no-results p { font-size: 14px; }

/* ══ ANALYTICS QUICK STATS ROW ══════════════════════════════════════════════ */
.analytics-row {
    display: grid;
    grid-template-columns: 1fr 2fr;
    gap: 20px;
    margin-bottom: 22px;
}
.mini-stat-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}
.mini-stat {
    background: #fff;
    border-radius: var(--radius);
    padding: 18px;
    box-shadow: var(--shadow);
    text-align: center;
}
.mini-stat .ms-val { font-size: 28px; font-weight: 700; color: var(--dark); line-height: 1; }
.mini-stat .ms-lbl { font-size: 11px; color: #aaa; text-transform: uppercase; letter-spacing: 0.4px; margin-top: 5px; }

/* Responsive */
@media (max-width: 1100px) {
    .stats-row { grid-template-columns: repeat(2, 1fr); }
    .analytics-row { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
    :root { --sidebar-w: 0px; }
    .sidebar { display: none; }
    .main-wrap { margin-left: 0; }
    .content { padding: 20px 16px 40px; }
    .top-header { padding: 0 18px; }
    .stats-row { grid-template-columns: 1fr 1fr; }
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
        <a href="admin_dashboard.php" class="<?= ($currentSection==='dashboard'||!in_array($currentSection,['analytics','users'])) ? 'active' : '' ?>">
            <div class="nav-icon"><i class="fas fa-table-cells-large"></i></div> Dashboard
        </a>
        <a href="admin_history.php">
            <div class="nav-icon"><i class="fas fa-clock-rotate-left"></i></div> History
        </a>
        <a href="admin_dashboard.php?section=analytics" class="<?= $currentSection==='analytics' ? 'active' : '' ?>">
            <div class="nav-icon"><i class="fas fa-chart-line"></i></div> Analytics
            <?php if($pendingCount>0): ?><span class="nav-badge"><?= $pendingCount ?></span><?php endif; ?>
        </a>

        <hr>
        <div class="nav-section-label">Management</div>
        <a href="create_admin.php">
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
                <h1><?= $currentSection==='analytics' ? 'Analytics & Calendar' : 'Admin Dashboard' ?></h1>
                <p>Welcome back, <strong><?= htmlspecialchars($adminName) ?></strong> — <?= date('l, F j, Y') ?></p>
            </div>
        </div>
        <div class="header-right">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="globalSearch" placeholder="Quick search…" oninput="globalSearchTable(this.value)">
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

        <?php if (!empty($msg)): ?>
        <div class="alert alert-<?= htmlspecialchars($msgType) ?>">
            <i class="fas fa-<?= $msgType==='success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
            <?= htmlspecialchars($msg) ?>
        </div>
        <?php endif; ?>

        <?php if(isset($_GET['msg']) && $_GET['msg']==='deleted'): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> Booking deleted successfully.</div>
        <?php endif; ?>

        <!-- SECTION TABS -->
        <div class="section-tabs">
            <a href="admin_dashboard.php?section=dashboard" class="section-tab <?= $currentSection==='dashboard'||!in_array($currentSection,['analytics']) ? 'active' : '' ?>">
                <i class="fas fa-table-cells-large"></i> Dashboard
            </a>
            <a href="admin_dashboard.php?section=analytics" class="section-tab <?= $currentSection==='analytics' ? 'active' : '' ?>">
                <i class="fas fa-chart-bar"></i> Analytics & Calendar
            </a>
        </div>

        <!-- ════════════════ DASHBOARD SECTION ════════════════════════════ -->
        <?php if ($currentSection !== 'analytics'): ?>

        <!-- STAT CARDS -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="sc-label">Total Bookings</div>
                    <div class="sc-value"><?= $totalBookings ?></div>
                    <div class="sc-sub">All time</div>
                </div>
                <div class="stat-icon si-red"><i class="fas fa-calendar-check"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="sc-label">Pending Requests</div>
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
                    <div class="sc-label">Total Users</div>
                    <div class="sc-value"><?= $totalUsers ?></div>
                    <div class="sc-sub">Registered accounts</div>
                </div>
                <div class="stat-icon si-blue"><i class="fas fa-users"></i></div>
            </div>
        </div>

        <!-- RESERVATIONS TABLE CARD -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-list-check"></i> Room Reservations</div>
                <div class="table-controls">
                    <button class="filter-pill <?= $currentFilter==='All' ? 'active' : '' ?>" onclick="filterBookings('All')">All</button>
                    <button class="filter-pill <?= $currentFilter==='Pending' ? 'active' : '' ?>" onclick="filterBookings('Pending')"><i class="fas fa-hourglass-half" style="font-size:10px;"></i> Pending</button>
                    <button class="filter-pill <?= $currentFilter==='Approved' ? 'active' : '' ?>" onclick="filterBookings('Approved')"><i class="fas fa-check" style="font-size:10px;"></i> Approved</button>
                    <button class="filter-pill <?= $currentFilter==='Rejected' ? 'active' : '' ?>" onclick="filterBookings('Rejected')"><i class="fas fa-xmark" style="font-size:10px;"></i> Rejected</button>
                </div>
            </div>

            <div class="table-wrap">
                <table id="reservationsTable">
                    <thead>
                        <tr>
                            <th>User Name</th>
                            <th>Room</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Purpose</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($roomReservations && $roomReservations->num_rows > 0): ?>
                        <?php while($row = $roomReservations->fetch_assoc()):
                            $startDate   = date('M d, Y', strtotime($row['booking_date']));
                            $endDate     = date('M d, Y', strtotime($row['end_date']));
                            $dateDisplay = ($row['booking_date'] === $row['end_date']) ? $startDate : "$startDate – $endDate";
                            $timeDisplay = date('h:i A', strtotime($row['start_time'])) . ' – ' . date('h:i A', strtotime($row['end_time']));
                            $purposeTxt  = $row['purpose'];
                            $department  = '';
                            if (preg_match('/Department:\s*([^|]+)/i', $purposeTxt, $matches)) {
                                $department = trim($matches[1]);
                            }
                            $purposeTxt  = preg_replace('/Department:\s*[^|]+(\||$)/i', '', $purposeTxt);
                            $purposeTxt  = preg_replace('/Room:\s*[^|]+(\||$)/i', '', $purposeTxt);
                            $purposeTxt  = preg_replace('/Location:\s*[^|]+(\||$)/i', '', $purposeTxt);
                            $purposeTxt  = preg_replace('/Purpose:\s*/i', '', $purposeTxt);
                            $purposeTxt  = trim(str_replace('|', '', $purposeTxt));
                            $purposeDisp = htmlspecialchars(substr(trim($purposeTxt), 0, 50));
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($row['user_name']) ?></strong>
                                <?php if (!empty($department)): ?>
                                    <div style="font-size:12px;color:#777;margin-top:4px;">Dept: <?= htmlspecialchars($department) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($row['room_name']) ?></td>
                            <td><?= $dateDisplay ?></td>
                            <td style="white-space:nowrap;"><?= $timeDisplay ?></td>
                            <td style="color:#777;"><?= $purposeDisp ?: '<em style="color:#ccc;">—</em>' ?></td>
                            <td><span class="badge-status status-<?= $row['status'] ?>"><?= $row['status'] ?></span></td>
                            <td style="white-space:nowrap;">
                                <?php if ($row['status'] === 'Pending'): ?>
                                    <button type="button" class="btn-act btn-approve" onclick="showApproveModal(<?= $row['id'] ?>,'<?= htmlspecialchars(addslashes($row['user_name'])) ?>','<?= htmlspecialchars(addslashes($row['room_name'])) ?>')">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                    <button type="button" class="btn-act btn-reject" onclick="showRejectModal(<?= $row['id'] ?>,'<?= htmlspecialchars(addslashes($row['user_name'])) ?>','<?= htmlspecialchars(addslashes($row['room_name'])) ?>')">
                                        <i class="fas fa-xmark"></i> Reject
                                    </button>
                                <?php else: ?>
                                    <span style="color:#ccc;font-size:12px;">—</span>
                                <?php endif; ?>
                                <a href="admin_dashboard.php?delete=<?= $row['id'] ?>" class="btn-act btn-delete" onclick="return confirm('Delete this booking permanently?')">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7">
                            <div class="no-results">
                                <i class="fas fa-inbox"></i>
                                <p>No bookings found<?= $searchQuery ? " for &ldquo;" . htmlspecialchars($searchQuery) . "&rdquo;" : '' ?>.</p>
                            </div>
                        </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php endif; /* end dashboard section */ ?>


        <!-- ════════════════ ANALYTICS SECTION ════════════════════════════ -->
        <?php if ($currentSection === 'analytics'): ?>

        <div class="analytics-row">
            <div class="mini-stat-grid">
                <div class="mini-stat">
                    <div class="ms-val" style="color:var(--red);"><?= $totalBookings ?></div>
                    <div class="ms-lbl">Total Bookings</div>
                </div>
                <div class="mini-stat">
                    <div class="ms-val" style="color:#f39c12;"><?= $pendingCount ?></div>
                    <div class="ms-lbl">Pending</div>
                </div>
                <div class="mini-stat">
                    <div class="ms-val" style="color:#22c55e;"><?= $approvedCount ?></div>
                    <div class="ms-lbl">Approved</div>
                </div>
                <div class="mini-stat">
                    <div class="ms-val" style="color:#dc3545;"><?= $rejectedCount ?></div>
                    <div class="ms-lbl">Rejected</div>
                </div>
            </div>

            <div class="card" style="margin-bottom:0;">
                <div class="card-header">
                    <div class="card-title"><i class="fas fa-chart-bar"></i> Booking Status Breakdown</div>
                </div>
                <canvas id="statusChart" height="110"></canvas>
            </div>
        </div>

        <!-- MONTHLY RESERVATION CALENDAR -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-calendar-days"></i> Monthly Reservation Calendar</div>
            </div>

            <?php
                $prevMonth = $calMonth - 1; $prevYear = $calYear;
                if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
                $nextMonth = $calMonth + 1; $nextYear = $calYear;
                if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }
            ?>
            <div class="cal-wrap">
                <div class="cal-nav">
                    <a href="?section=analytics&cal_year=<?= $prevYear ?>&cal_month=<?= $prevMonth ?>" class="cal-nav-btn"><i class="fas fa-chevron-left"></i></a>
                    <h3><?= date('F Y', strtotime("$calYear-$calMonth-01")) ?></h3>
                    <a href="?section=analytics&cal_year=<?= $nextYear ?>&cal_month=<?= $nextMonth ?>" class="cal-nav-btn"><i class="fas fa-chevron-right"></i></a>
                    <a href="?section=analytics&cal_year=<?= date('Y') ?>&cal_month=<?= date('n') ?>" class="btn-apply" style="margin-left:8px;padding:7px 16px;font-size:12px;">Today</a>
                </div>

                <div class="cal-legend">
                    <div class="cal-legend-item"><div class="cal-legend-dot" style="background:#28a745;"></div> Approved</div>
                    <div class="cal-legend-item"><div class="cal-legend-dot" style="background:#f39c12;"></div> Pending</div>
                    <div class="cal-legend-item"><div class="cal-legend-dot" style="background:#dc3545;"></div> Rejected</div>
                </div>

                <div class="calendar-grid">
                    <?php
                    $dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
                    foreach ($dayNames as $dn) {
                        echo '<div class="cal-day-header">' . $dn . '</div>';
                    }

                    $firstDow  = (int)date('w', strtotime("$calYear-$calMonth-01"));
                    $daysInMon = (int)date('t', strtotime("$calYear-$calMonth-01"));
                    $todayDay  = (date('Y') == $calYear && date('n') == $calMonth) ? (int)date('j') : -1;

                    // Prev month padding
                    $prevMonthDays = ($calMonth === 1)
                        ? (int)date('t', strtotime(($calYear-1) . "-12-01"))
                        : (int)date('t', strtotime("$calYear-" . ($calMonth-1) . "-01"));
                    for ($i = $firstDow - 1; $i >= 0; $i--) {
                        $d = $prevMonthDays - $i;
                        echo "<div class='cal-day other-month'><div class='cal-day-num'>$d</div></div>";
                    }

                    // Current month
                    for ($d = 1; $d <= $daysInMon; $d++) {
                        $todayCls = ($d === $todayDay) ? ' today' : '';
                        echo "<div class='cal-day$todayCls'>";
                        echo "<div class='cal-day-num'>$d</div>";
                        if (isset($calData[$d])) {
                            $shown = 0;
                            foreach ($calData[$d] as $ev) {
                                if ($shown >= 2) break;
                                $cls  = 'ev-' . strtolower($ev['status']);
                                $name = htmlspecialchars(strlen($ev['room_name']) > 12 ? substr($ev['room_name'], 0, 12).'…' : $ev['room_name']);
                                echo "<div class='cal-event $cls'><div class='cal-event-dot'></div>$name</div>";
                                $shown++;
                            }
                            $remaining = count($calData[$d]) - $shown;
                            if ($remaining > 0) echo "<div class='cal-more'>+$remaining more</div>";
                        }
                        echo "</div>";
                    }

                    // Trailing days
                    $total = $firstDow + $daysInMon;
                    $trail = (7 - ($total % 7)) % 7;
                    for ($d = 1; $d <= $trail; $d++) {
                        echo "<div class='cal-day other-month'><div class='cal-day-num'>$d</div></div>";
                    }
                    ?>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
        <script>
        (function(){
            const ctx = document.getElementById('statusChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Total','Pending','Approved','Rejected'],
                    datasets: [{
                        data: [<?= $totalBookings ?>, <?= $pendingCount ?>, <?= $approvedCount ?>, <?= $rejectedCount ?>],
                        backgroundColor: ['rgba(149,18,44,0.15)','rgba(243,156,18,0.18)','rgba(34,197,94,0.18)','rgba(220,53,69,0.18)'],
                        borderColor:     ['#95122C','#f39c12','#22c55e','#dc3545'],
                        borderWidth: 2,
                        borderRadius: 8,
                    }]
                },
                options: {
                    responsive: true,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, ticks: { precision: 0, font: { family: 'Poppins', size: 12 } }, grid: { color: '#f0ebe8' } },
                        x: { ticks: { font: { family: 'Poppins', size: 12 } }, grid: { display: false } }
                    }
                }
            });
        })();
        </script>

        <?php endif; /* end analytics section */ ?>

    </div><!-- /content -->
</div><!-- /main-wrap -->


<!-- ═══════════════════ APPROVE MODAL ═══════════════════════════════════════ -->
<div id="approveModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h2><i class="fas fa-circle-check" style="color:#28a745;margin-right:8px;"></i>Approve Booking</h2>
            <button class="modal-close" onclick="closeApprove()"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" id="approveForm">
            <div id="approveBkgInfo" class="modal-info"></div>
            <input type="hidden" id="approveBookingId" name="booking_id">
            <div class="modal-hint">
                <i class="fas fa-envelope"></i>
                An approval notification will be sent to the user's registered email address.
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-modal btn-modal-cancel" onclick="closeApprove()">Cancel</button>
                <button type="submit" name="approve_booking" value="1" class="btn-modal btn-modal-confirm"><i class="fas fa-check"></i> Send Approval Email</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════ REJECT MODAL ════════════════════════════════════════ -->
<div id="rejectModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h2><i class="fas fa-circle-xmark" style="color:#dc3545;margin-right:8px;"></i>Reject Booking</h2>
            <button class="modal-close" onclick="closeReject()"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" id="rejectForm">
            <div id="rejectBkgInfo" class="modal-info"></div>
            <input type="hidden" id="rejectBookingId" name="booking_id">
            <label class="modal-label">Reason for Rejection <span style="color:#dc3545;">*</span></label>
            <textarea id="rejectReason" name="reject_reason" required placeholder="Please provide a reason…" class="modal-textarea"></textarea>
            <div class="modal-hint" style="background:#fff5f5;">
                <i class="fas fa-envelope" style="color:#dc3545;"></i>
                A rejection notice with your reason will be sent to the user's email.
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-modal btn-modal-cancel" onclick="closeReject()">Cancel</button>
                <button type="submit" name="reject_booking" value="1" class="btn-modal btn-modal-confirm" style="background:#dc3545;"><i class="fas fa-xmark"></i> Send Rejection Email</button>
            </div>
        </form>
    </div>
</div>

<script>
/* ── Filtering ─────────────────────────────────────────────────────────── */
function filterBookings(filter) {
    const url = new URL(window.location.href);
    url.searchParams.set('filter', filter);
    url.searchParams.set('section', 'dashboard');
    window.location.href = url.toString();
}

/* ── Inline table search ────────────────────────────────────────────────── */
function filterTable(query) {
    const q = query.toLowerCase();
    document.querySelectorAll('#reservationsTable tbody tr').forEach(tr => {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}
function globalSearchTable(query) {
    const tSearch = document.getElementById('tableSearch');
    if (tSearch) { tSearch.value = query; filterTable(query); }
    filterUsers(query);
}

/* ── User search ────────────────────────────────────────────────────────── */
function filterUsers(query) {
    const q = query.toLowerCase();
    document.querySelectorAll('#usersTable tbody tr').forEach(tr => {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

/* ── Notifications ──────────────────────────────────────────────────────── */
function toggleNotif(e) {
    e.stopPropagation();
    document.getElementById('notifDropdown').classList.toggle('active');
}
document.addEventListener('click', () => {
    document.getElementById('notifDropdown')?.classList.remove('active');
});


/* ── Modals ──────────────────────────────────────────────────────────────── */
function showApproveModal(id, user, room) {
    document.getElementById('approveBookingId').value = id;
    document.getElementById('approveBkgInfo').innerHTML =
        '<strong>Booking #'+id+'</strong><br>User: '+user+'<br>Room: '+room;
    document.getElementById('approveModal').classList.add('open');
}
function closeApprove() { document.getElementById('approveModal').classList.remove('open'); }

function showRejectModal(id, user, room) {
    document.getElementById('rejectBookingId').value = id;
    document.getElementById('rejectBkgInfo').innerHTML =
        '<strong>Booking #'+id+'</strong><br>User: '+user+'<br>Room: '+room;
    document.getElementById('rejectModal').classList.add('open');
}
function closeReject() {
    document.getElementById('rejectModal').classList.remove('open');
    document.getElementById('rejectForm').reset();
}

window.addEventListener('click', e => {
    if (e.target.classList.contains('modal-overlay')) {
        closeApprove(); closeReject();
    }
});
</script>
</body>
</html>