<?php
/**
 * Program Head Dashboard — AssetEase
 * Step 2 of 3 in the equipment borrow approval workflow.
 *
 * WORKFLOW:
 *   Step 1 (MMIT Director)  → name_mmit_director filled, sig_mmit_director filled, status = 'Pending'  → shows 1/3
 *   Step 2 (Program Head)   → name_dept_head filled,    sig_dept_head filled,    status = 'Pending'  → shows 2/3
 *   Step 3 (VP Admin)       → name_approved_by filled,  sig_approved_by filled,  status = 'Approved' → shows 3/3
 *
 * Program Head PENDING queue: MMIT has signed (name_mmit_director filled),
 *   Program Head has NOT yet signed (name_dept_head empty).
 * Program Head VERIFIED queue: Program Head has signed, VP has not approved yet.
 */
session_start();
require_once 'config.php';
require_once 'email_notification.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId         = $_SESSION['user_id'];
$userName       = $_SESSION['uname']        ?? 'Program Head';
$programCode    = $_SESSION['program_code'] ?? '';
$signatoryLabel = ($programCode ? strtoupper($programCode) . ' ' : '') . 'Program Head';

$success = '';
$error   = '';

// ============================================================
// APPROVE — Step 2: Program Head verifies
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_equipment'])) {
    $bookingId = intval($_POST['booking_id']);
    $sigDept   = trim($_POST['sig_dept_head']   ?? '');
    $nameDept  = trim($_POST['name_dept_head']  ?? '');

    try {
        if (empty($sigDept))  throw new Exception("Signature image was not received. Please try uploading again.");
        if (empty($nameDept)) throw new Exception("Please enter your full name before submitting.");
        if (strpos($sigDept, 'data:image') !== 0) throw new Exception("Invalid signature format. Please re-upload your signature image.");

        $bStmt = $conn->prepare(
            "SELECT b.id, b.user_name, b.equipment_name AS resource_name,
                    b.borrow_date AS booking_date, b.return_date AS end_date,
                    b.start_time, b.end_time, b.purpose, u.email,
                    b.name_mmit_director, b.sig_mmit_director
             FROM borrows b
             LEFT JOIN users u ON b.user_id = u.id
             WHERE b.id = ?"
        );
        $bStmt->bind_param("i", $bookingId);
        $bStmt->execute();
        $booking = $bStmt->get_result()->fetch_assoc();
        if (!$booking) throw new Exception("Equipment request not found.");

        // Write sig_dept_head + name_dept_head; status stays 'Pending' so VP can pick it up
        $upd = $conn->prepare(
            "UPDATE borrows
             SET sig_dept_head  = ?,
                 name_dept_head = ?
             WHERE id = ?"
        );
        $upd->bind_param("ssi", $sigDept, $nameDept, $bookingId);
        $upd->execute();

        // ── Email helpers ──────────────────────────────────────────────────
        $displayDate = ($booking['booking_date'] === $booking['end_date'])
            ? date('M d, Y', strtotime($booking['booking_date']))
            : date('M d, Y', strtotime($booking['booking_date'])) . ' to ' . date('M d, Y', strtotime($booking['end_date']));
        $displayTime = date('h:i A', strtotime($booking['start_time'])) . ' – ' . date('h:i A', strtotime($booking['end_time']));

        $parsedContact = ''; $parsedDept = ''; $parsedEvent = '';
        if (preg_match('/Contact:\s*([^|]+)/i',    $booking['purpose'], $cm)) $parsedContact = trim($cm[1]);
        if (preg_match('/Department:\s*([^|]+)/i', $booking['purpose'], $dm)) $parsedDept    = trim($dm[1]);
        if (preg_match('/Event:\s*([^|]+)/i',      $booking['purpose'], $em)) $parsedEvent   = trim($em[1]);
        if (!$parsedEvent) {
            $raw = preg_replace('/Contact:\s*[^|]+(\||$)/i',    '', $booking['purpose']);
            $raw = preg_replace('/Department:\s*[^|]+(\||$)/i', '', $raw);
            $parsedEvent = trim(str_replace('|', '', preg_replace('/Purpose:\s*/i', '', $raw)));
        }

        $dbNameMmit = htmlspecialchars($booking['name_mmit_director'] ?? '');
        $dbSigMmit  = $booking['sig_mmit_director'] ?? '';

        $sigCell = function(string $label, string $role, string $name, string $sigData, bool $done) {
            $border = $done ? '#28a745' : '#dee2e6';
            $bg     = $done ? '#f0fff4' : '#f9f9f9';
            $check  = $done
                ? "<div style='font-size:11px;color:#28a745;margin-bottom:4px;'>&#10003; Signed</div>"
                : "<div style='font-size:11px;color:#aaa;margin-bottom:4px;'>Pending</div>";
            $sig = $sigData
                ? "<img src='{$sigData}' style='max-width:170px;height:52px;object-fit:contain;display:block;margin:0 auto 6px;'>"
                : "<div style='height:52px;border-bottom:1px dashed #ccc;margin-bottom:6px;'></div>";
            $n = $name ?: '<span style="color:#bbb;">___________</span>';
            return "<td style='width:33.3%;padding:12px 8px;text-align:center;vertical-align:bottom;"
                 . "border:1px solid {$border};border-top:3px solid {$border};background:{$bg};'>"
                 . "{$check}{$sig}"
                 . "<div style='font-size:12px;font-weight:700;color:#1a1a2e;'>{$n}</div>"
                 . "<div style='font-size:10.5px;color:#666;margin-top:2px;'>{$role}</div>"
                 . "<div style='font-size:10px;color:#999;font-style:italic;'>{$label}</div></td>";
        };

        $detRow = function(string $lbl, string $val, string $icon = '') {
            if (!$val) return '';
            return "<tr><td style='padding:6px 0;color:#666;font-size:13px;width:38%;'>{$icon} {$lbl}</td>"
                 . "<td style='padding:6px 0;font-weight:600;font-size:13px;color:#1a1a2e;'>"
                 . htmlspecialchars($val) . "</td></tr>";
        };

        $c1 = $sigCell('Noted By',    'MMIT Director',                  $dbNameMmit,                 $dbSigMmit, true);
        $c2 = $sigCell('Verified By', 'Program Head',                   htmlspecialchars($nameDept), $sigDept,   true);
        $c3 = $sigCell('Approved By', 'VP for Administration & Finance', '',                          '',         false);

        $refNo    = 'AE-' . date('Y') . '-' . str_pad($bookingId, 5, '0', STR_PAD_LEFT);
        $issuedAt = date('F j, Y \a\t h:i A');

        $details = $detRow('Equipment',     $booking['resource_name'], '&#128188;')
                 . $detRow('Borrow Date',   $displayDate,              '&#128197;')
                 . $detRow('Time',          $displayTime,              '&#128336;')
                 . $detRow('Purpose/Event', $parsedEvent,              '&#127775;')
                 . $detRow('Department',    $parsedDept,               '&#127979;')
                 . $detRow('Contact',       $parsedContact,            '&#128100;');

        if (!empty($booking['email'])) {
            $emailSubject = "[Step 2/3] Equipment Request Verified — {$refNo} | AssetEase";
            $emailBody = "
<html><body style='margin:0;padding:0;background:#f0f2f5;font-family:Arial,Helvetica,sans-serif;'>
<div style='max-width:600px;margin:30px auto 40px;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.12);'>
<div style='background:#1565c0;padding:22px 30px;text-align:center;'>
  <div style='font-size:11px;color:rgba(255,255,255,.75);letter-spacing:2px;text-transform:uppercase;'>AssetEase &mdash; Request Update</div>
  <div style='font-size:18px;font-weight:700;color:#fff;margin-top:6px;'>&#128338; Step 2 of 3 Complete</div>
  <div style='font-size:12px;color:rgba(255,255,255,.9);margin-top:4px;'>Verified by Program Head &mdash; awaiting VP for Administration</div>
</div>
<div style='background:#f8f9fa;padding:8px 30px;border-bottom:1px solid #e0e0e0;font-size:12px;color:#555;'>
  <span><strong>Ref:</strong> {$refNo}</span>&nbsp;&nbsp;<span><strong>Issued:</strong> {$issuedAt}</span>
</div>
<div style='padding:24px 30px;'>
  <p style='font-size:14px;color:#333;'>Dear <strong>" . htmlspecialchars($booking['user_name']) . "</strong>,</p>
  <p style='font-size:13px;color:#555;margin:10px 0 16px;'>Your request has been <strong>verified by the Program Head</strong> and is awaiting final approval from the VP for Administration &amp; Finance.</p>
  <div style='background:#fafafa;border:1px solid #e8e8e8;border-left:4px solid #1565c0;border-radius:8px;padding:14px 18px;margin-bottom:20px;'>
    <table style='width:100%;border-collapse:collapse;'>{$details}</table>
  </div>
  <div style='font-size:11px;font-weight:700;color:#1565c0;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;'>Signatories So Far</div>
  <table style='width:100%;border-collapse:collapse;margin-bottom:20px;'><tr>{$c1}{$c2}{$c3}</tr></table>
  <table style='width:100%;border-collapse:collapse;font-size:12px;border-radius:8px;overflow:hidden;margin-bottom:20px;'>
    <tr>
      <td style='background:#28a745;color:#fff;padding:10px 6px;text-align:center;'>&#10003; Step 1<br><strong>MMIT Director</strong><br><em style='font-weight:400;font-size:11px;'>{$dbNameMmit}</em></td>
      <td style='background:#28a745;color:#fff;padding:10px 6px;text-align:center;'>&#10003; Step 2<br><strong>Program Head</strong><br><em style='font-weight:400;font-size:11px;'>" . htmlspecialchars($nameDept) . "</em></td>
      <td style='background:#dee2e6;color:#777;padding:10px 6px;text-align:center;'>&#9744; Step 3<br><strong>VP for Administration</strong><br><em style='color:#aaa;font-size:11px;'>Pending</em></td>
    </tr>
  </table>
  <p style='font-size:11px;color:#aaa;text-align:center;margin:0;'>Automated message from AssetEase. Do not reply.</p>
</div>
<div style='background:#1565c0;padding:10px 30px;text-align:center;'>
  <span style='color:rgba(255,255,255,.65);font-size:11px;'>AssetEase &mdash; " . date('Y') . "</span>
</div>
</div></body></html>";
            sendEmail($booking['email'], $emailSubject, $emailBody);
        }
        $success = "Equipment request #{$bookingId} successfully verified — forwarded to VP for final approval!";
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// ============================================================
// REJECT
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_equipment'])) {
    $bookingId    = intval($_POST['booking_id']);
    $rejectReason = trim($_POST['reject_reason'] ?? 'No reason provided');
    try {
        $bStmt = $conn->prepare(
            "SELECT b.id, b.user_name, b.equipment_name AS resource_name,
                    b.borrow_date AS booking_date, b.return_date AS end_date, u.email
             FROM borrows b LEFT JOIN users u ON b.user_id = u.id WHERE b.id = ?"
        );
        $bStmt->bind_param("i", $bookingId);
        $bStmt->execute();
        $booking = $bStmt->get_result()->fetch_assoc();
        if (!$booking) throw new Exception("Equipment request not found.");

        $upd = $conn->prepare("UPDATE borrows SET status = 'Rejected' WHERE id = ?");
        $upd->bind_param("i", $bookingId);
        $upd->execute();

        $displayDate = ($booking['booking_date'] === $booking['end_date'])
            ? date('M d, Y', strtotime($booking['booking_date']))
            : date('M d, Y', strtotime($booking['booking_date'])) . ' to ' . date('M d, Y', strtotime($booking['end_date']));

        if (!empty($booking['email'])) {
            $emailBody = "<html><body style='font-family:Arial,sans-serif;background:#f5f5f5;padding:20px;'>
                <div style='max-width:600px;margin:auto;'>
                <div style='background:#dc3545;color:white;padding:25px;text-align:center;border-radius:10px 10px 0 0;'>
                <h2 style='margin:0;'>&#9888; Equipment Request Declined</h2></div>
                <div style='padding:25px;background:white;border-radius:0 0 10px 10px;border:1px solid #eee;'>
                <p>Hello <strong>" . htmlspecialchars($booking['user_name']) . "</strong>,</p>
                <p>Your request for <strong>" . htmlspecialchars($booking['resource_name']) . "</strong>
                   on <strong>" . htmlspecialchars($displayDate) . "</strong> has been declined by the Program Head.</p>
                <div style='background:#fff5f5;border-left:4px solid #dc3545;padding:15px;margin:20px 0;'>
                <strong>Reason:</strong> " . htmlspecialchars($rejectReason) . "</div>
                <p>AssetEase Facility Management Team</p></div></div></body></html>";
            sendEmail($booking['email'], "Equipment Request Declined — AssetEase", $emailBody);
        }
        $success = "Equipment request #{$bookingId} rejected.";
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// ============================================================
// DATA FETCH
// ============================================================
$equipFilter = $_GET['equip_filter'] ?? 'Pending';

// ── Pending queue (Step 2): MMIT has signed, Program Head has NOT yet
// ── Verified queue: Program Head has signed, VP has not yet approved
// ── No department filter on Pending — Program Head sees all MMIT-noted requests
$equipSql = "SELECT MIN(id) AS id, user_name,
                    equipment_name AS resource_name,
                    borrow_date AS booking_date,
                    return_date AS end_date,
                    start_time, end_time, purpose, status,
                    COUNT(*) AS quantity,
                    MAX(sig_mmit_director)  AS sig_mmit_director,
                    MAX(sig_dept_head)      AS sig_dept_head,
                    MAX(sig_approved_by)    AS sig_approved_by,
                    MAX(name_mmit_director) AS name_mmit_director,
                    MAX(name_dept_head)     AS name_dept_head,
                    MAX(name_approved_by)   AS name_approved_by
             FROM borrows WHERE 1";

if ($equipFilter === 'Pending') {
    // Step 2 queue: MMIT signed, Program Head has NOT signed yet
    $equipSql .= " AND status = 'Pending'
                   AND name_mmit_director IS NOT NULL AND name_mmit_director != ''
                   AND (name_dept_head IS NULL OR name_dept_head = '')";
} elseif ($equipFilter === 'Verified') {
    // Program Head has signed; VP not yet approved
    $equipSql .= " AND status = 'Pending'
                   AND name_dept_head IS NOT NULL AND name_dept_head != ''
                   AND (name_approved_by IS NULL OR name_approved_by = '')";
} elseif ($equipFilter === 'Approved') {
    $equipSql .= " AND status = 'Approved'";
} elseif ($equipFilter === 'Rejected') {
    $equipSql .= " AND status = 'Rejected'";
}
// 'All' → no extra WHERE, shows everything

$equipSql .= " GROUP BY user_name, equipment_name, borrow_date, return_date,
                         start_time, end_time, purpose, status
               ORDER BY id DESC";
$equipmentBorrows = $conn->query($equipSql);

// ── Counts for filter pills ───────────────────────────────────────────────────
$countPending  = (int)$conn->query(
    "SELECT COUNT(*) AS c FROM borrows
     WHERE status='Pending'
       AND name_mmit_director IS NOT NULL AND name_mmit_director!=''
       AND (name_dept_head IS NULL OR name_dept_head='')"
)->fetch_assoc()['c'];

$countVerified = (int)$conn->query(
    "SELECT COUNT(*) AS c FROM borrows
     WHERE status='Pending'
       AND name_dept_head IS NOT NULL AND name_dept_head!=''
       AND (name_approved_by IS NULL OR name_approved_by='')"
)->fetch_assoc()['c'];

$countApproved = (int)$conn->query("SELECT COUNT(*) AS c FROM borrows WHERE status='Approved'")->fetch_assoc()['c'];
$countRejected = (int)$conn->query("SELECT COUNT(*) AS c FROM borrows WHERE status='Rejected'")->fetch_assoc()['c'];
$countAll      = (int)$conn->query("SELECT COUNT(*) AS c FROM borrows")->fetch_assoc()['c'];

// ── Notifications ─────────────────────────────────────────────────────────────
$notifStmt = $conn->prepare("SELECT id, message, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$notifStmt->bind_param("i", $userId);
$notifStmt->execute();
$notifList  = [];
while ($n = $notifStmt->get_result()->fetch_assoc()) $notifList[] = $n;
$notifCount = count($notifList);

// ── Utility ───────────────────────────────────────────────────────────────────
function formatDateRange($s, $e) {
    return $s === $e ? date('M d, Y', strtotime($s)) : date('M d, Y', strtotime($s)) . ' to ' . date('M d, Y', strtotime($e));
}
function formatTimeRange($s, $e) {
    return date('h:i A', strtotime($s)) . ' - ' . date('h:i A', strtotime($e));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Program Head Dashboard — AssetEase</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
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

/* ── Sidebar ──────────────────────────────────────────────── */
.sidebar { width:var(--sidebar-w); height:100vh; background:linear-gradient(180deg,var(--dark) 0%,var(--red) 100%); color:#fff; position:fixed; left:0; top:0; display:flex; flex-direction:column; overflow:hidden; z-index:1001; }
.sidebar-logo { display:flex; align-items:center; gap:13px; padding:26px 22px 20px; border-bottom:1px solid rgba(255,255,255,0.1); flex-shrink:0; text-decoration:none; border-left:none!important; background:none!important; border-radius:0; margin:0; }
.logo-img-wrap { position:relative; width:44px; height:44px; flex-shrink:0; }
.logo-img-wrap img { width:44px; height:44px; object-fit:cover; border-radius:10px; background:#fff; padding:2px; border:2px solid rgba(255,215,0,0.5); }
.logo-glow { position:absolute; inset:-4px; border-radius:14px; background:radial-gradient(circle,rgba(255,215,0,0.25) 0%,transparent 70%); pointer-events:none; }
.sidebar-logo-text h2 { color:var(--gold); font-size:18px; font-weight:700; letter-spacing:2px; line-height:1; }
.sidebar-logo-text span { font-size:10px; color:rgba(255,255,255,0.5); letter-spacing:1px; text-transform:uppercase; }
.admin-badge { margin:14px 16px 6px; display:flex; align-items:center; gap:10px; padding:10px 14px; background:rgba(255,215,0,0.12); border:1px solid rgba(255,215,0,0.25); border-radius:10px; flex-shrink:0; }
.admin-badge img { width:34px; height:34px; border-radius:50%; border:2px solid var(--gold); object-fit:cover; }
.admin-badge-info .ab-name { font-size:12px; font-weight:700; color:#fff; line-height:1; }
.admin-badge-info .ab-role { font-size:10px; color:var(--gold); text-transform:uppercase; letter-spacing:0.8px; margin-top:3px; }
.sidebar-nav { flex:1; overflow-y:auto; padding:10px 0 8px; scrollbar-width:none; }
.sidebar-nav::-webkit-scrollbar { display:none; }
.nav-section-label { padding:14px 22px 5px; font-size:9.5px; font-weight:700; letter-spacing:2.5px; color:rgba(255,255,255,0.38); text-transform:uppercase; }
.sidebar a { display:flex; align-items:center; gap:12px; padding:11px 22px; color:rgba(255,255,255,0.78); text-decoration:none; font-size:0.855rem; font-weight:500; border-left:3px solid transparent; transition:all 0.22s; margin:1px 8px; border-radius:8px; }
.sidebar a .nav-icon { width:30px; height:30px; display:flex; align-items:center; justify-content:center; font-size:14px; border-radius:7px; background:rgba(255,255,255,0.06); flex-shrink:0; transition:all 0.22s; }
.sidebar a:hover { background:rgba(255,255,255,0.10); color:#fff; border-left-color:rgba(255,255,255,0.3); }
.sidebar a.active { background:rgba(255,255,255,0.15); color:#fff; border-left-color:var(--gold); font-weight:600; }
.sidebar a.active .nav-icon { background:rgba(255,215,0,0.18); color:var(--gold); }
.sidebar hr { border:none; border-top:1px solid rgba(255,255,255,0.09); margin:8px 18px; }
.sidebar-promo { margin:8px 14px 18px; padding:14px 15px; background:linear-gradient(135deg,rgba(255,215,0,0.18),rgba(255,255,255,0.06)); border:1px solid rgba(255,215,0,0.28); border-radius:12px; flex-shrink:0; }
.sidebar-promo p { font-size:11.5px; color:rgba(255,255,255,0.82); line-height:1.5; }
.sidebar-promo strong { color:var(--gold); }

/* ── Main wrap ────────────────────────────────────────────── */
.main-wrap { margin-left:var(--sidebar-w); flex:1; display:flex; flex-direction:column; min-height:100vh; }
.top-header { position:sticky; top:0; background:var(--white); display:flex; justify-content:space-between; align-items:center; padding:0 36px; height:68px; box-shadow:0 1px 0 rgba(0,0,0,0.07),0 2px 12px rgba(0,0,0,0.04); z-index:999; gap:16px; }
.header-left { display:flex; align-items:center; gap:14px; min-width:0; }
.header-logo-mini { display:flex; align-items:center; gap:9px; padding-right:14px; border-right:1px solid #eee; }
.header-logo-mini img { width:34px; height:34px; border-radius:8px; object-fit:cover; border:1.5px solid rgba(149,18,44,0.2); }
.header-logo-mini span { font-size:14px; font-weight:700; color:var(--red); letter-spacing:1.5px; }
.header-title-area h1 { font-size:19px; font-weight:700; color:var(--dark); line-height:1.1; }
.header-title-area p  { font-size:12px; color:#999; margin-top:1px; }
.header-right { display:flex; align-items:center; gap:11px; flex-shrink:0; }
.search-box { display:flex; align-items:center; gap:9px; background:var(--bg); border-radius:25px; padding:9px 16px; width:230px; border:1.5px solid transparent; transition:0.2s; }
.search-box:focus-within { border-color:var(--red); background:#fff; box-shadow:0 0 0 3px rgba(149,18,44,0.07); }
.search-box i { color:#bbb; font-size:13px; }
.search-box input { border:none; background:transparent; font-size:13px; font-family:'Poppins'; color:var(--dark); outline:none; width:100%; }
.search-box input::placeholder { color:#ccc; }
.notif-wrap { position:relative; }
.notif-btn { width:40px; height:40px; border-radius:50%; background:var(--bg); border:none; display:flex; align-items:center; justify-content:center; cursor:pointer; color:var(--dark); font-size:16px; transition:0.2s; }
.notif-btn:hover { background:#ede6e3; }
.notif-badge { position:absolute; top:-1px; right:-1px; background:#dc3545; color:#fff; font-size:9px; font-weight:700; width:17px; height:17px; border-radius:50%; display:flex; align-items:center; justify-content:center; border:2px solid #fff; }
.notif-dropdown { position:absolute; top:50px; right:0; width:360px; background:#fff; border-radius:var(--radius); box-shadow:var(--shadow-md); z-index:2000; display:none; max-height:390px; overflow-y:auto; border:1px solid rgba(0,0,0,0.07); }
.notif-dropdown.active { display:block; animation:fadeDown 0.18s ease; }
@keyframes fadeDown { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:translateY(0)} }
.notif-header { padding:14px 18px 10px; font-weight:700; font-size:13px; border-bottom:1px solid #f0f0f0; display:flex; justify-content:space-between; align-items:center; }
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

/* ── Content ──────────────────────────────────────────────── */
.content { padding:28px 36px 50px; flex:1; }
.alert { padding:13px 18px; border-radius:10px; margin-bottom:22px; border-left:4px solid; display:flex; align-items:center; gap:10px; font-size:13px; }
.alert-success { background:#edfaf1; color:#155724; border-color:#28a745; }
.alert-error   { background:#fdf0f0; color:#721c24; border-color:#dc3545; }

/* ── Stats ────────────────────────────────────────────────── */
.stats-row { display:grid; grid-template-columns:repeat(4,1fr); gap:18px; margin-bottom:24px; }
.stat-card { background:var(--white); border-radius:var(--radius); padding:22px; box-shadow:var(--shadow); display:flex; justify-content:space-between; align-items:flex-start; transition:transform 0.2s,box-shadow 0.2s; border-top:3px solid transparent; }
.stat-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-md); }
.stat-card:nth-child(1) { border-top-color:#f39c12; }
.stat-card:nth-child(2) { border-top-color:var(--red); }
.stat-card:nth-child(3) { border-top-color:#22c55e; }
.stat-card:nth-child(4) { border-top-color:#3b82f6; }
.stat-card-body .sc-label { font-size:12px; color:#999; font-weight:500; margin-bottom:7px; text-transform:uppercase; letter-spacing:0.4px; }
.stat-card-body .sc-value { font-size:32px; font-weight:700; color:var(--dark); line-height:1; }
.stat-card-body .sc-sub   { font-size:11.5px; color:#aaa; margin-top:5px; }
.stat-icon { width:48px; height:48px; border-radius:11px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
.si-red   { background:#fef2f2; color:var(--red); }
.si-amber { background:#fff7ed; color:#f97316; }
.si-green { background:#f0fdf4; color:#22c55e; }
.si-blue  { background:#eff6ff; color:#3b82f6; }

/* ── Workflow Banner ──────────────────────────────────────── */
.workflow-banner { background:#f5f3ff; border:1px solid #c4b5fd; border-radius:var(--radius); padding:14px 20px; margin-bottom:22px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
.workflow-banner i { color:#7c3aed; font-size:16px; flex-shrink:0; }
.wf-steps { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.wf-step { font-size:12px; font-weight:500; color:#5b21b6; padding:4px 10px; border-radius:20px; }
.wf-step.done   { color:#6b7280; text-decoration:line-through; opacity:0.65; }
.wf-step.active { background:#7c3aed; color:#fff; font-weight:600; }
.wf-arrow { color:#c4b5fd; font-size:12px; }

/* ── Card & Table ─────────────────────────────────────────── */
.card { background:var(--white); border-radius:var(--radius); padding:24px 26px; box-shadow:var(--shadow); margin-bottom:22px; }
.card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px; }
.card-title { font-size:15px; font-weight:700; color:var(--dark); display:flex; align-items:center; gap:8px; }
.card-title i { color:var(--red); }
.table-controls { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.filter-pill { padding:7px 16px; border-radius:25px; border:1.5px solid #e0d8d5; background:#fff; color:#666; font-size:12.5px; font-weight:500; cursor:pointer; transition:all 0.2s; font-family:'Poppins'; display:flex; align-items:center; gap:6px; text-decoration:none; }
.filter-pill:hover  { border-color:var(--red); color:var(--red); }
.filter-pill.active { background:var(--red); color:#fff; border-color:var(--red); box-shadow:0 2px 8px rgba(149,18,44,0.2); }
.table-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; min-width:820px; }
thead tr { border-bottom:2px solid #f0ebe8; }
th { padding:11px 14px; text-align:left; font-size:11px; font-weight:700; letter-spacing:0.6px; text-transform:uppercase; color:#aaa; }
tbody tr { border-bottom:1px solid #f7f3f1; transition:background 0.15s; }
tbody tr:last-child { border-bottom:none; }
tbody tr:hover { background:#fdf9f8; }
td { padding:13px 14px; font-size:13px; color:#444; vertical-align:middle; }
td strong { color:var(--dark); }

/* ── Badges & Buttons ─────────────────────────────────────── */
.badge-status { display:inline-flex; align-items:center; gap:5px; padding:4px 11px; border-radius:20px; font-size:11.5px; font-weight:600; }
.badge-status::before { content:''; width:6px; height:6px; border-radius:50%; display:inline-block; }
.status-Pending  { background:#fff8e6; color:#b07800; }
.status-Pending::before  { background:#f39c12; }
.status-Approved { background:#edfaf1; color:#1a7a3c; }
.status-Approved::before { background:#28a745; }
.status-Rejected { background:#fdf0f0; color:#9b1c1c; }
.status-Rejected::before { background:#dc3545; }
.qty-badge { display:inline-flex; align-items:center; justify-content:center; background:var(--red); color:#fff; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700; }
.btn-act { padding:5px 11px; border-radius:7px; border:none; font-size:12px; font-weight:600; cursor:pointer; font-family:'Poppins'; transition:all 0.18s; display:inline-flex; align-items:center; gap:5px; }
.btn-approve { background:#edfaf1; color:#1a7a3c; }
.btn-approve:hover { background:#28a745; color:#fff; }
.btn-reject  { background:#fdf0f0; color:#9b1c1c; }
.btn-reject:hover  { background:#dc3545; color:#fff; }
.btn-sigs    { background:#f0f4ff; color:#4f46e5; border:1px solid #c7d2fe; }
.btn-sigs:hover    { background:#4f46e5; color:#fff; }

/* ── Sig progress badge ───────────────────────────────────── */
.sig-progress-badge { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:6px; font-size:11.5px; font-weight:700; cursor:pointer; transition:all 0.18s; border:1px solid; }
.sig-0of3 { background:#f9fafb; color:#6b7280; border-color:#d1d5db; }
.sig-1of3 { background:#fff7ed; color:#9a3412; border-color:#fdba74; }
.sig-2of3 { background:#eff6ff; color:#1e40af; border-color:#93c5fd; }
.sig-3of3 { background:#f0fdf4; color:#166534; border-color:#86efac; }
.sig-progress-badge:hover { opacity:0.8; }
.sig-waiting-badge { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; background:#f8f9fa; color:#888; border:1px solid #dee2e6; border-radius:6px; font-size:11px; font-style:italic; }

.no-results { text-align:center; padding:40px 20px; color:#ccc; }
.no-results i { font-size:32px; margin-bottom:10px; display:block; color:#ddd; }
.no-results p { font-size:14px; }

/* ── Modals ───────────────────────────────────────────────── */
.modal-overlay { display:none; position:fixed; z-index:1500; inset:0; background:rgba(0,0,0,0.45); align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:#fff; border-radius:18px; padding:32px; max-width:500px; width:92%; box-shadow:0 16px 48px rgba(0,0,0,0.18); animation:modalIn 0.22s ease; max-height:90vh; overflow-y:auto; }
.modal-box-wide { max-width:680px; }
@keyframes modalIn { from{transform:scale(0.94);opacity:0} to{transform:scale(1);opacity:1} }
.modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-bottom:14px; border-bottom:1px solid #f0ebe8; }
.modal-header h2 { font-size:18px; color:var(--dark); font-weight:700; }
.modal-close { background:none; border:none; cursor:pointer; color:#bbb; font-size:20px; transition:color 0.2s; }
.modal-close:hover { color:#555; }
.modal-info { background:#fdf9f8; border-radius:10px; padding:14px; color:#555; font-size:13px; line-height:1.7; margin-bottom:16px; }
.modal-hint { background:#f0f7ff; border-radius:8px; padding:11px 14px; color:#444; font-size:12.5px; display:flex; gap:8px; align-items:flex-start; margin-bottom:16px; }
.modal-hint i { color:#0066cc; margin-top:2px; }
.modal-textarea { width:100%; padding:11px 14px; border:1.5px solid #e0d8d5; border-radius:10px; font-family:'Poppins'; font-size:13px; color:#333; resize:vertical; min-height:90px; outline:none; transition:border-color 0.2s; }
.modal-textarea:focus { border-color:var(--red); }
.modal-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:20px; }
.btn-modal { padding:10px 22px; border-radius:9px; border:none; font-size:13px; font-weight:600; font-family:'Poppins'; cursor:pointer; transition:0.2s; display:flex; align-items:center; gap:6px; }
.btn-modal-cancel  { background:#f0ebe8; color:#555; }
.btn-modal-cancel:hover { background:#e0d8d5; }
.btn-modal-confirm { background:var(--red); color:#fff; }
.btn-modal-confirm:hover { background:var(--red-dark); }

/* ── Signature upload area ────────────────────────────────── */
.sig-section { margin-bottom:18px; }
.sig-section-title { font-size:12px; font-weight:700; color:var(--red); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px; display:flex; align-items:center; gap:6px; }
.sig-name-input { width:100%; padding:9px 12px; border:1.5px solid #e0d8d5; border-radius:8px; font-family:'Poppins'; font-size:13px; color:var(--dark); outline:none; margin-bottom:12px; transition:border-color 0.2s; }
.sig-name-input:focus { border-color:var(--red); }
.sig-upload-area { border:2px dashed #d1d5db; border-radius:12px; padding:28px 20px; text-align:center; background:#fdfdfd; cursor:pointer; transition:all 0.2s; }
.sig-upload-area:hover { border-color:var(--red); background:#fff9f8; }
.sig-upload-area.has-file { border-color:#28a745; background:#f0fff4; }
.sig-upload-icon { font-size:32px; color:#d1d5db; margin-bottom:8px; transition:color 0.2s; }
.sig-upload-area:hover .sig-upload-icon { color:var(--red); }
.sig-upload-area.has-file .sig-upload-icon { color:#28a745; }
.sig-upload-text { font-size:13px; color:#666; font-weight:500; }
.sig-upload-sub  { font-size:11px; color:#aaa; margin-top:4px; }
.sig-preview-wrap { margin-top:12px; display:none; }
.sig-preview-wrap.visible { display:block; }
.sig-preview-wrap img { max-width:100%; max-height:90px; border:1px solid #e5e7eb; border-radius:8px; background:#fff; padding:4px; object-fit:contain; }
.sig-preview-label { font-size:11px; color:#28a745; font-weight:600; margin-bottom:6px; display:flex; align-items:center; gap:5px; }
.sig-clear-btn { font-size:11.5px; color:#dc3545; background:none; border:none; cursor:pointer; font-family:'Poppins'; padding:0; text-decoration:underline; margin-top:6px; display:inline-block; }
.warn-notice { background:#fffbeb; border:1px solid #fcd34d; border-radius:8px; padding:10px 14px; margin-bottom:16px; font-size:12px; color:#92400e; }

/* ── Step 1 sig display inside Verify modal ───────────────── */
.prev-sig-block { background:#f0fff4; border:1px solid #86efac; border-radius:10px; padding:14px 16px; margin-bottom:18px; }
.prev-sig-block-title { font-size:11px; font-weight:700; color:#166534; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px; display:flex; align-items:center; gap:6px; }
.prev-sig-inner { display:flex; align-items:center; gap:14px; }
.prev-sig-img { height:56px; max-width:180px; object-fit:contain; border:1px solid #d1fae5; border-radius:6px; background:#fff; padding:4px; }
.prev-sig-img-placeholder { height:56px; width:150px; border:1.5px dashed #a7f3d0; border-radius:6px; display:flex; align-items:center; justify-content:center; color:#6ee7b7; font-size:11px; }
.prev-sig-meta { font-size:12px; color:#374151; }
.prev-sig-meta strong { color:#166534; display:block; font-size:13px; }
.prev-sig-meta span  { color:#9ca3af; font-size:11px; }

/* ── Sig View Modal ───────────────────────────────────────── */
.sig-view-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-top:10px; }
.sig-view-item { text-align:center; border:1px solid #e5e7eb; border-radius:10px; padding:14px 10px; }
.sig-view-item.done { border-color:#86efac; background:#f0fff4; }
.sig-view-item.pending { background:#f9fafb; }
.sig-view-step { font-size:10px; font-weight:700; color:var(--red); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px; }
.sig-view-img-wrap { height:72px; display:flex; align-items:center; justify-content:center; margin-bottom:8px; }
.sig-view-img-wrap img { max-width:100%; max-height:70px; border:1px solid #e5e7eb; border-radius:6px; background:#fff; padding:3px; object-fit:contain; }
.sig-view-placeholder { width:100%; height:52px; border-bottom:1.5px dashed #d1d5db; display:flex; align-items:center; justify-content:center; color:#d1d5db; font-size:11px; font-style:italic; }
.sig-view-name { font-size:12px; font-weight:700; color:#1f2937; margin-top:4px; }
.sig-view-role { font-size:10.5px; color:#9ca3af; margin-top:2px; }
.sig-view-badge-done    { display:inline-flex; align-items:center; gap:4px; background:#dcfce7; color:#166534; border-radius:20px; padding:2px 10px; font-size:10.5px; font-weight:700; margin-top:6px; }
.sig-view-badge-pending { display:inline-flex; align-items:center; gap:4px; background:#f3f4f6; color:#6b7280; border-radius:20px; padding:2px 10px; font-size:10.5px; font-weight:600; margin-top:6px; }

@media(max-width:1100px){ .stats-row{grid-template-columns:repeat(2,1fr);} }
@media(max-width:768px){
    :root{--sidebar-w:0px;}
    .sidebar{display:none;}
    .main-wrap{margin-left:0;}
    .content{padding:16px 12px 40px;}
    .top-header{padding:0 14px;height:auto;min-height:60px;flex-wrap:wrap;gap:8px;padding-top:8px;padding-bottom:8px;}
    .header-logo-mini{display:none;}
    .stats-row{grid-template-columns:1fr 1fr;gap:10px;}
    .sig-view-grid{grid-template-columns:1fr;}
}
</style>
</head>
<body>

<!-- ═══════════════════════════════════════════════════════════
     SIDEBAR
════════════════════════════════════════════════════════════ -->
<div class="sidebar">
    <a href="programhead_dashboard.php" class="sidebar-logo">
        <div class="logo-img-wrap">
            <img src="image/logo.png" alt="AssetEase Logo">
            <div class="logo-glow"></div>
        </div>
        <div class="sidebar-logo-text">
            <h2>ASSETEASE</h2>
            <span>Program Head Portal</span>
        </div>
    </a>
    <div class="admin-badge">
        <img src="https://ui-avatars.com/api/?name=<?= urlencode($userName) ?>&background=95122C&color=fff&rounded=true" alt="">
        <div class="admin-badge-info">
            <div class="ab-name"><?= htmlspecialchars(strlen($userName) > 16 ? substr($userName, 0, 16) . '…' : $userName) ?></div>
            <div class="ab-role"><?= htmlspecialchars($signatoryLabel) ?></div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="programhead_dashboard.php" class="active">
            <div class="nav-icon"><i class="fas fa-table-cells-large"></i></div> Dashboard
        </a>
        <hr>
        <div class="nav-section-label">System</div>
        <a href="logout.php"><div class="nav-icon"><i class="fas fa-right-from-bracket"></i></div> Logout</a>
    </nav>
    <div class="sidebar-promo">
        <p><strong>Your role:</strong> Verify equipment requests after the MMIT Director's notation. Your signature forwards them to the VP for final approval.</p>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     MAIN WRAP
════════════════════════════════════════════════════════════ -->
<div class="main-wrap">
    <div class="top-header">
        <div class="header-left">
            <div class="header-logo-mini">
                <img src="image/logo.png" alt="AssetEase">
                <span>ASSETEASE</span>
            </div>
            <div class="header-title-area">
                <h1><?= htmlspecialchars($signatoryLabel) ?> Panel</h1>
                <p>Welcome back, <strong><?= htmlspecialchars($userName) ?></strong> — <?= date('l, F j, Y') ?></p>
            </div>
        </div>
        <div class="header-right">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Quick search…" oninput="globalSearch(this.value)">
            </div>
            <div class="notif-wrap">
                <button class="notif-btn" onclick="toggleNotif(event)">
                    <i class="fas fa-bell"></i>
                    <?php if ($notifCount > 0): ?><span class="notif-badge"><?= $notifCount ?></span><?php endif; ?>
                </button>
                <div class="notif-dropdown" id="notifDropdown">
                    <div class="notif-header">
                        <span><i class="fas fa-bell" style="color:var(--red);margin-right:6px;"></i>Notifications</span>
                        <span style="font-size:11px;color:#bbb;"><?= $notifCount ?> items</span>
                    </div>
                    <?php if (!empty($notifList)): foreach ($notifList as $notif): ?>
                    <div class="notif-item">
                        <i class="fas fa-bell ni-icon"></i>
                        <div>
                            <div class="ni-msg"><?= htmlspecialchars($notif['message']) ?></div>
                            <div class="ni-time"><?= date('M d, h:i A', strtotime($notif['created_at'])) ?></div>
                        </div>
                    </div>
                    <?php endforeach; else: ?>
                    <div class="notif-empty"><i class="fas fa-bell-slash" style="font-size:24px;display:block;margin-bottom:8px;color:#ddd;"></i>No notifications yet</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="profile-pill">
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($userName) ?>&background=95122C&color=fff&rounded=true" alt="">
                <div>
                    <span><?= htmlspecialchars(strlen($userName) > 14 ? substr($userName, 0, 14) . '…' : $userName) ?></span>
                    <span class="pp-role"><?= htmlspecialchars($signatoryLabel) ?></span>
                </div>
            </div>
        </div>
    </div><!-- /top-header -->

    <div class="content">

        <?php if (!empty($success)): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Role Banner -->
        <div style="background:#f0f7ff;border-left:4px solid #1976d2;border-radius:8px;padding:12px 16px;margin-bottom:18px;font-size:13px;color:#1a3a5c;display:flex;align-items:center;gap:10px;">
            <i class="fas fa-id-badge" style="font-size:18px;color:#1976d2;flex-shrink:0;"></i>
            <div><strong>Logged in as: <?= htmlspecialchars($signatoryLabel) ?></strong> &mdash;
            You can <strong>Verify</strong> equipment requests already noted by the MMIT Director (Step 2 of 3).
            After your signature, they move to the VP for Administration for final approval.</div>
        </div>

        <!-- Stat Cards -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="sc-label">Awaiting Your Signature</div>
                    <div class="sc-value"><?= $countPending ?></div>
                    <div class="sc-sub">MMIT noted — needs you (1/3 done)</div>
                </div>
                <div class="stat-icon si-amber"><i class="fas fa-pen-nib"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="sc-label">Verified by You</div>
                    <div class="sc-value"><?= $countVerified ?></div>
                    <div class="sc-sub">Awaiting VP approval (2/3 done)</div>
                </div>
                <div class="stat-icon si-red"><i class="fas fa-circle-check"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="sc-label">Fully Approved</div>
                    <div class="sc-value"><?= $countApproved ?></div>
                    <div class="sc-sub">VP signed &amp; released (3/3 done)</div>
                </div>
                <div class="stat-icon si-green"><i class="fas fa-boxes-stacked"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="sc-label">Total Requests</div>
                    <div class="sc-value"><?= $countAll ?></div>
                    <div class="sc-sub">All time</div>
                </div>
                <div class="stat-icon si-blue"><i class="fas fa-layer-group"></i></div>
            </div>
        </div>

        <!-- Workflow Banner -->
        <div class="workflow-banner">
            <i class="fas fa-circle-info"></i>
            <div class="wf-steps">
                <span class="wf-step done">① Submitted</span><span class="wf-arrow">→</span>
                <span class="wf-step done">② Noted by MMIT (1/3)</span><span class="wf-arrow">→</span>
                <span class="wf-step active">③ Program Head Verifies — You (2/3)</span><span class="wf-arrow">→</span>
                <span class="wf-step">④ VP Approves (3/3)</span><span class="wf-arrow">→</span>
                <span class="wf-step">⑤ Released to User</span>
            </div>
        </div>

        <!-- Equipment Table -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-toolbox"></i> Equipment Borrow Requests</div>
                <div class="table-controls">
                    <a href="?equip_filter=Pending"  class="filter-pill <?= $equipFilter==='Pending'?'active':'' ?>">
                        <i class="fas fa-hourglass-half" style="font-size:10px;"></i> For Signature — 1/3 (<?= $countPending ?>)
                    </a>
                    <a href="?equip_filter=Verified" class="filter-pill <?= $equipFilter==='Verified'?'active':'' ?>">
                        <i class="fas fa-check-double" style="font-size:10px;"></i> Verified — 2/3 (<?= $countVerified ?>)
                    </a>
                    <a href="?equip_filter=Approved" class="filter-pill <?= $equipFilter==='Approved'?'active':'' ?>">
                        <i class="fas fa-check" style="font-size:10px;"></i> Approved — 3/3 (<?= $countApproved ?>)
                    </a>
                    <a href="?equip_filter=Rejected" class="filter-pill <?= $equipFilter==='Rejected'?'active':'' ?>">
                        <i class="fas fa-xmark" style="font-size:10px;"></i> Rejected (<?= $countRejected ?>)
                    </a>
                    <a href="?equip_filter=All"      class="filter-pill <?= $equipFilter==='All'?'active':'' ?>">
                        All (<?= $countAll ?>)
                    </a>
                </div>
            </div>
            <div class="table-wrap">
                <table id="equipTable">
                    <thead>
                        <tr>
                            <th>User</th><th>Equipment</th><th>Qty</th>
                            <th>Date Range</th><th>Time</th><th>Contact</th><th>Event</th>
                            <th>Progress</th><th>Status</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($equipmentBorrows && $equipmentBorrows->num_rows > 0):
                        while ($b = $equipmentBorrows->fetch_assoc()):
                            $bContact = ''; $bDept = ''; $bEvent = '';
                            if (preg_match('/Contact:\s*([^|]+)/i',    $b['purpose'], $cm)) $bContact = trim($cm[1]);
                            if (preg_match('/Department:\s*([^|]+)/i', $b['purpose'], $dm)) $bDept    = trim($dm[1]);
                            if (preg_match('/Event:\s*([^|]+)/i',      $b['purpose'], $em)) $bEvent   = trim($em[1]);
                            if (!$bEvent) {
                                $raw = preg_replace('/Contact:\s*[^|]+(\||$)/i',    '', $b['purpose']);
                                $raw = preg_replace('/Department:\s*[^|]+(\||$)/i', '', $raw);
                                $bEvent = trim(str_replace('|', '', preg_replace('/Purpose:\s*/i', '', $raw)));
                            }

                            $mmitDone  = !empty($b['name_mmit_director']);
                            $deptDone  = !empty($b['name_dept_head']);
                            $vpDone    = !empty($b['name_approved_by']);
                            $doneCount = ($mmitDone ? 1 : 0) + ($deptDone ? 1 : 0) + ($vpDone ? 1 : 0);

                            // Program Head's turn: MMIT signed, PH has NOT signed yet
                            $myTurn = $mmitDone && !$deptDone;

                            $sigBadgeClass = match($doneCount) {
                                0 => 'sig-0of3', 1 => 'sig-1of3', 2 => 'sig-2of3', 3 => 'sig-3of3', default => 'sig-0of3',
                            };

                            $jsData = htmlspecialchars(json_encode([
                                'id'        => $b['id'],
                                'user'      => $b['user_name'],
                                'equipment' => $b['resource_name'],
                                'date'      => formatDateRange($b['booking_date'], $b['end_date']),
                                'time'      => formatTimeRange($b['start_time'], $b['end_time']),
                                'event'     => $bEvent,
                                'dept'      => $bDept,
                                'contact'   => $bContact,
                                'sigMmit'   => $b['sig_mmit_director'] ?? '',
                                'nameMmit'  => $b['name_mmit_director'] ?? '',
                                'sigDept'   => $b['sig_dept_head'] ?? '',
                                'nameDept'  => $b['name_dept_head'] ?? '',
                                'sigVp'     => $b['sig_approved_by'] ?? '',
                                'nameVp'    => $b['name_approved_by'] ?? '',
                                'status'    => $b['status'],
                                'myTurn'    => $myTurn,
                            ]), ENT_QUOTES);
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($b['user_name']) ?></strong>
                            <?php if ($bDept): ?>
                            <div style="font-size:11.5px;color:var(--red);font-weight:600;margin-top:3px;">
                                <i class="fas fa-building" style="font-size:10px;margin-right:3px;"></i><?= htmlspecialchars($bDept) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($b['resource_name']) ?></td>
                        <td><span class="qty-badge"><?= $b['quantity'] ?></span></td>
                        <td><?= formatDateRange($b['booking_date'], $b['end_date']) ?></td>
                        <td><?= formatTimeRange($b['start_time'], $b['end_time']) ?></td>
                        <td><?= htmlspecialchars($bContact ?: '—') ?></td>
                        <td><?= htmlspecialchars(substr($bEvent, 0, 40)) . (strlen($bEvent) > 40 ? '…' : '') ?></td>
                        <td>
                            <!-- Clickable progress badge — opens sig view modal -->
                            <button type="button"
                                class="sig-progress-badge <?= $sigBadgeClass ?>"
                                onclick='showSigViewModal(<?= $jsData ?>)'
                                title="View signature progress">
                                <i class="fas fa-signature"></i>
                                <?= $doneCount ?>/3
                                <?php if ($doneCount === 3): ?>
                                    <i class="fas fa-check-circle" style="color:#22c55e;"></i>
                                <?php endif; ?>
                            </button>
                        </td>
                        <td><span class="badge-status status-<?= $b['status'] ?>"><?= $b['status'] ?></span></td>
                        <td style="white-space:nowrap;">
                            <?php if ($b['status'] === 'Pending' && $myTurn): ?>
                                <button type="button" class="btn-act btn-approve"
                                    onclick='showApproveModal(<?= $jsData ?>)'>
                                    <i class="fas fa-check-double"></i> Verify
                                </button>
                                <button type="button" class="btn-act btn-reject"
                                    onclick="showRejectModal(<?= $b['id'] ?>,'<?= htmlspecialchars(addslashes($b['user_name'])) ?>','<?= htmlspecialchars(addslashes($b['resource_name'])) ?>')">
                                    <i class="fas fa-xmark"></i> Reject
                                </button>
                            <?php elseif ($b['status'] === 'Pending' && !$myTurn): ?>
                                <span class="sig-waiting-badge">
                                    <?php if (!$mmitDone): ?>
                                        <i class="fas fa-hourglass-half"></i> Wait MMIT
                                    <?php elseif ($deptDone && !$vpDone): ?>
                                        <i class="fas fa-check-circle" style="color:#22c55e;"></i> Awaiting VP
                                    <?php else: ?>
                                        <i class="fas fa-hourglass-half"></i> Pending
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="10">
                        <div class="no-results"><i class="fas fa-toolbox"></i><p>No equipment requests found for this filter.</p></div>
                    </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div><!-- /content -->
</div><!-- /main-wrap -->

<!-- ═══════════════════════════════════════════════════════════
     VERIFY (APPROVE) MODAL
     Shows Step 1 (MMIT) signature already collected,
     then lets Program Head upload their own signature.
════════════════════════════════════════════════════════════ -->
<div id="approveModal" class="modal-overlay">
    <div class="modal-box modal-box-wide">
        <div class="modal-header">
            <h2>
                <i class="fas fa-check-double" style="color:#28a745;margin-right:8px;"></i>
                Verify Request — Step 2 of 3
            </h2>
            <button class="modal-close" onclick="closeApprove()"><i class="fas fa-xmark"></i></button>
        </div>

        <form method="POST" action="" id="approveForm" onsubmit="return validateApprove()">
            <input type="hidden" name="approve_equipment" value="1">
            <input type="hidden" id="approveBookingId"  name="booking_id"    value="">
            <input type="hidden" id="hiddenSigDept"      name="sig_dept_head"  value="">
            <input type="hidden" id="hiddenNameDept"     name="name_dept_head" value="">

            <div id="approveBkgInfo" class="modal-info"></div>

            <!-- Step progress bar -->
            <div style="display:flex;gap:0;margin-bottom:16px;border-radius:8px;overflow:hidden;font-size:12px;">
                <div style="flex:1;padding:8px 6px;text-align:center;background:#28a745;color:#fff;">&#10003; Step 1<br><strong>MMIT Director</strong></div>
                <div style="flex:1;padding:8px 6px;text-align:center;background:var(--red);color:#fff;">&#9998; Step 2<br><strong>Program Head — You</strong></div>
                <div style="flex:1;padding:8px 6px;text-align:center;background:#dee2e6;color:#777;">&#9744; Step 3<br><strong>VP for Administration</strong></div>
            </div>

            <!-- ── Step 1 already-collected signature display ─────────── -->
            <div class="prev-sig-block">
                <div class="prev-sig-block-title">
                    <i class="fas fa-check-circle"></i> Step 1 Complete — Noted by MMIT Director
                </div>
                <div class="prev-sig-inner">
                    <div id="mmitSigImgWrap">
                        <!-- populated by JS -->
                    </div>
                    <div class="prev-sig-meta">
                        <strong id="mmitSigName">—</strong>
                        <span>MMIT Director</span>
                    </div>
                </div>
            </div>

            <!-- ── Program Head's own signature ───────────────────────── -->
            <div class="sig-section">
                <div class="sig-section-title"><i class="fas fa-user-pen"></i> Your Signature — Program Head (Step 2)</div>

                <input type="text" id="sigNameInput"
                    class="sig-name-input"
                    placeholder="Enter your full name and designation"
                    autocomplete="off"
                    oninput="syncName(this.value)">

                <div class="sig-upload-area" id="sigUploadArea" onclick="triggerSigUpload()">
                    <div class="sig-upload-icon"><i class="fas fa-file-signature"></i></div>
                    <div class="sig-upload-text" id="sigUploadText">Click to upload your signature image</div>
                    <div class="sig-upload-sub">PNG, JPG — signature on white or transparent background</div>
                </div>
                <input type="file" id="sigFileReal" accept="image/*" style="display:none;" onchange="handleSigUpload(this)">

                <div class="sig-preview-wrap" id="sigPreviewWrap">
                    <div class="sig-preview-label"><i class="fas fa-check-circle"></i> Signature ready</div>
                    <img id="sigPreviewImg" src="" alt="Signature preview">
                    <br>
                    <button type="button" class="sig-clear-btn" onclick="clearSig()">
                        <i class="fas fa-times"></i> Remove &amp; upload a different signature
                    </button>
                </div>
            </div>

            <div class="warn-notice">
                <strong>&#9888; By submitting,</strong> you confirm this request is legitimate and forward it to the VP for Administration for final approval.
            </div>
            <div class="modal-hint">
                <i class="fas fa-envelope"></i> A step-2 progress notification will be sent to the requesting user's email.
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-modal btn-modal-cancel" onclick="closeApprove()">Cancel</button>
                <button type="submit" class="btn-modal btn-modal-confirm" id="approveSubmitBtn">
                    <i class="fas fa-check-double"></i> Confirm &amp; Verify
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ REJECT MODAL ═════════════════════════════════════════ -->
<div id="rejectModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h2><i class="fas fa-circle-xmark" style="color:#dc3545;margin-right:8px;"></i>Reject Equipment Request</h2>
            <button class="modal-close" onclick="closeReject()"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="reject_equipment" value="1">
            <div id="rejectBkgInfo" class="modal-info"></div>
            <input type="hidden" id="rejectBookingId" name="booking_id">
            <label style="font-size:13px;font-weight:600;color:var(--dark);display:block;margin-bottom:8px;">
                Reason for Rejection <span style="color:#dc3545;">*</span>
            </label>
            <textarea name="reject_reason" required placeholder="Please provide a reason…" class="modal-textarea"></textarea>
            <div class="modal-hint" style="background:#fff5f5;">
                <i class="fas fa-envelope" style="color:#dc3545;"></i> A rejection notice will be emailed to the user.
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-modal btn-modal-cancel" onclick="closeReject()">Cancel</button>
                <button type="submit" class="btn-modal btn-modal-confirm" style="background:#dc3545;">
                    <i class="fas fa-xmark"></i> Send Rejection
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ SIGNATURE VIEW / PROGRESS MODAL ══════════════════════ -->
<div id="sigViewModal" class="modal-overlay">
    <div class="modal-box modal-box-wide">
        <div class="modal-header">
            <h2><i class="fas fa-signature" style="color:#4f46e5;margin-right:8px;"></i>Signature Progress</h2>
            <button class="modal-close" onclick="closeSigView()"><i class="fas fa-xmark"></i></button>
        </div>
        <div id="sigViewStatus" style="margin-bottom:16px;font-size:13px;color:#555;"></div>
        <div class="sig-view-grid">
            <div class="sig-view-item" id="svItem1">
                <div class="sig-view-step">Step 1 — Noted By</div>
                <div class="sig-view-img-wrap" id="svImgWrap1"></div>
                <div class="sig-view-name" id="svName1"></div>
                <div class="sig-view-role">MMIT Director</div>
                <div id="svBadge1"></div>
            </div>
            <div class="sig-view-item" id="svItem2">
                <div class="sig-view-step">Step 2 — Verified By</div>
                <div class="sig-view-img-wrap" id="svImgWrap2"></div>
                <div class="sig-view-name" id="svName2"></div>
                <div class="sig-view-role">Program Head</div>
                <div id="svBadge2"></div>
            </div>
            <div class="sig-view-item" id="svItem3">
                <div class="sig-view-step">Step 3 — Approved By</div>
                <div class="sig-view-img-wrap" id="svImgWrap3"></div>
                <div class="sig-view-name" id="svName3"></div>
                <div class="sig-view-role">VP for Administration &amp; Finance</div>
                <div id="svBadge3"></div>
            </div>
        </div>
        <!-- If it's the PH's turn, show a shortcut Verify button in the sig view modal too -->
        <div id="sigViewVerifyWrap" style="display:none;margin-top:16px;text-align:center;">
            <button type="button" id="sigViewVerifyBtn"
                style="padding:10px 24px;background:var(--red);color:#fff;border:none;border-radius:9px;font-family:'Poppins';font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:8px;"
                onclick="sigViewGoVerify()">
                <i class="fas fa-check-double"></i> Sign &amp; Verify This Request
            </button>
        </div>
        <div class="modal-actions" style="margin-top:16px;">
            <button type="button" class="btn-modal btn-modal-cancel" onclick="closeSigView()">Close</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════════════════════ -->
<script>
// Holds the current row's data for use when jumping from sig-view → verify
let _currentRowData = null;

/* ── Name sync → hidden field ──────────────────────────────── */
function syncName(val) {
    document.getElementById('hiddenNameDept').value = val;
}

/* ── Image compression ─────────────────────────────────────── */
function compressImage(file, maxW, maxH, quality, callback) {
    const reader = new FileReader();
    reader.onload = function(e) {
        const img = new Image();
        img.onload = function() {
            let w = img.width, h = img.height;
            if (w > maxW) { h = Math.round(h * maxW / w); w = maxW; }
            if (h > maxH) { w = Math.round(w * maxH / h); h = maxH; }
            const canvas = document.createElement('canvas');
            canvas.width = w; canvas.height = h;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, w, h);
            ctx.drawImage(img, 0, 0, w, h);
            callback(canvas.toDataURL('image/png', quality));
        };
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
}

/* ── Signature upload ──────────────────────────────────────── */
function triggerSigUpload() {
    document.getElementById('sigFileReal').click();
}
function handleSigUpload(input) {
    const file = input.files[0];
    if (!file) return;
    compressImage(file, 600, 200, 0.92, function(dataUrl) {
        document.getElementById('hiddenSigDept').value = dataUrl;
        document.getElementById('sigPreviewImg').src = dataUrl;
        document.getElementById('sigPreviewWrap').classList.add('visible');
        document.getElementById('sigUploadArea').classList.add('has-file');
        document.getElementById('sigUploadText').textContent = '✓ ' + file.name;
    });
}
function clearSig() {
    document.getElementById('hiddenSigDept').value = '';
    document.getElementById('sigPreviewImg').src = '';
    document.getElementById('sigPreviewWrap').classList.remove('visible');
    document.getElementById('sigUploadArea').classList.remove('has-file');
    document.getElementById('sigUploadText').textContent = 'Click to upload your signature image';
    document.getElementById('sigFileReal').value = '';
}

/* ── Approve form validation ───────────────────────────────── */
function validateApprove() {
    const nameEl = document.getElementById('sigNameInput');
    syncName(nameEl ? nameEl.value.trim() : '');
    if (!nameEl || !nameEl.value.trim()) {
        alert('Please enter your full name before submitting.');
        if (nameEl) nameEl.focus();
        return false;
    }
    const sigEl = document.getElementById('hiddenSigDept');
    if (!sigEl || !sigEl.value || !sigEl.value.startsWith('data:image')) {
        alert('Please upload your signature image before submitting.');
        return false;
    }
    const btn = document.getElementById('approveSubmitBtn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting…';
    }
    return true;
}

/* ── Global search ─────────────────────────────────────────── */
function globalSearch(q) {
    q = q.toLowerCase();
    document.querySelectorAll('#equipTable tbody tr').forEach(tr => {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

/* ── Notifications ─────────────────────────────────────────── */
function toggleNotif(e) {
    e.stopPropagation();
    document.getElementById('notifDropdown').classList.toggle('active');
}
document.addEventListener('click', () => {
    document.getElementById('notifDropdown')?.classList.remove('active');
});

/* ── Approve Modal — now receives full data object ─────────── */
function showApproveModal(data) {
    _currentRowData = data;

    document.getElementById('approveBookingId').value = data.id;
    document.getElementById('approveBkgInfo').innerHTML =
        '<strong>Request #' + data.id + '</strong> &mdash; <em>' + escHtml(data.user) + '</em><br>' +
        'Equipment: <strong>' + escHtml(data.equipment) + '</strong><br>' +
        'Date: ' + escHtml(data.date) + ' &nbsp;|&nbsp; Time: ' + escHtml(data.time);

    // Show MMIT sig already collected
    const mmitWrap = document.getElementById('mmitSigImgWrap');
    if (data.sigMmit && data.sigMmit.length > 0) {
        mmitWrap.innerHTML = '<img class="prev-sig-img" src="' + data.sigMmit + '" alt="MMIT Signature">';
    } else {
        mmitWrap.innerHTML = '<div class="prev-sig-img-placeholder"><i class="fas fa-question"></i></div>';
    }
    document.getElementById('mmitSigName').textContent = data.nameMmit || '(MMIT Director)';

    // Reset PH signature fields
    const nameInput = document.getElementById('sigNameInput');
    if (nameInput) nameInput.value = '';
    document.getElementById('hiddenSigDept').value  = '';
    document.getElementById('hiddenNameDept').value = '';
    clearSig();

    // Re-enable submit button
    const btn = document.getElementById('approveSubmitBtn');
    if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check-double"></i> Confirm &amp; Verify';
    }
    document.getElementById('approveModal').classList.add('open');
}
function closeApprove() { document.getElementById('approveModal').classList.remove('open'); }

/* ── Reject Modal ──────────────────────────────────────────── */
function showRejectModal(id, user, equip) {
    document.getElementById('rejectBookingId').value = id;
    document.getElementById('rejectBkgInfo').innerHTML =
        '<strong>Request #' + id + '</strong> &mdash; <em>' + escHtml(user) + '</em><br>Equipment: <strong>' + escHtml(equip) + '</strong>';
    document.getElementById('rejectModal').classList.add('open');
}
function closeReject() { document.getElementById('rejectModal').classList.remove('open'); }

/* ── Signature View Modal ──────────────────────────────────── */
function showSigViewModal(data) {
    _currentRowData = data;

    const slots = [
        { sig: data.sigMmit, name: data.nameMmit, role: 'MMIT Director' },
        { sig: data.sigDept, name: data.nameDept, role: 'Program Head' },
        { sig: data.sigVp,   name: data.nameVp,   role: 'VP for Administration' },
    ];
    let doneCount = 0;
    slots.forEach((s, i) => {
        const n    = i + 1;
        const done = !!(s.sig && s.sig.length > 0);
        if (done) doneCount++;
        const item = document.getElementById('svItem' + n);
        item.className = 'sig-view-item ' + (done ? 'done' : 'pending');
        const wrap = document.getElementById('svImgWrap' + n);
        wrap.innerHTML = done
            ? '<img src="' + s.sig + '" alt="Signature">'
            : '<div class="sig-view-placeholder">Awaiting signature</div>';
        document.getElementById('svName' + n).textContent  = s.name || (done ? '(name not recorded)' : '');
        document.getElementById('svBadge' + n).innerHTML   = done
            ? '<span class="sig-view-badge-done"><i class="fas fa-check-circle"></i> Signed</span>'
            : '<span class="sig-view-badge-pending"><i class="fas fa-hourglass-half"></i> Pending</span>';
    });

    const statusEl = document.getElementById('sigViewStatus');
    if (doneCount === 3) {
        statusEl.innerHTML = '<span style="color:#166534;font-weight:700;"><i class="fas fa-circle-check"></i> All 3 signatures complete — request fully approved.</span>';
    } else {
        statusEl.innerHTML = '<span style="color:#92400e;font-weight:600;"><i class="fas fa-hourglass-half"></i> '
            + doneCount + ' of 3 signatures collected. Awaiting remaining signatories.</span>';
    }

    // Show "Verify" shortcut only if it is PH's turn
    const verifyWrap = document.getElementById('sigViewVerifyWrap');
    verifyWrap.style.display = data.myTurn ? 'block' : 'none';

    document.getElementById('sigViewModal').classList.add('open');
}
function closeSigView() { document.getElementById('sigViewModal').classList.remove('open'); }

// Called from sig view modal's "Sign & Verify" button
function sigViewGoVerify() {
    closeSigView();
    if (_currentRowData) showApproveModal(_currentRowData);
}

/* ── Close modals on backdrop click ───────────────────────── */
window.addEventListener('click', e => {
    if (e.target.classList.contains('modal-overlay')) {
        closeApprove(); closeReject(); closeSigView();
    }
});

/* ── HTML escaping helper ──────────────────────────────────── */
function escHtml(str) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(str || ''));
    return d.innerHTML;
}
</script>
</body>
</html>