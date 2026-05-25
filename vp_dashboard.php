<?php
/**
 * VP for Administration & Finance Dashboard — AssetEase
 * Step 3 of 3 in the equipment borrow approval workflow.
 *
 * WORKFLOW:
 *   Step 1 (MMIT Director)  → name_mmit_director filled, sig_mmit_director filled, status = 'Pending'  → 1/3
 *   Step 2 (Program Head)   → name_dept_head filled,    sig_dept_head filled,    status = 'Pending'  → 2/3
 *   Step 3 (VP Admin — You) → name_approved_by filled,  sig_approved_by filled,  status = 'Approved' → 3/3
 *
 * VP PENDING queue: MMIT signed + Program Head signed, VP has NOT yet signed.
 */
session_start();
require_once 'config.php';
require_once 'email_notification.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$vpName   = $_SESSION['uname'] ?? 'VP Admin';
$userId   = $_SESSION['user_id'];

$success = '';
$error   = '';

// ============================================================
// FINAL APPROVAL — Step 3: VP signs → status = 'Approved'
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_equipment'])) {
    $bookingId   = intval($_POST['booking_id']);
    $sigVp       = trim($_POST['sig_approved_by']  ?? '');
    $nameVp      = trim($_POST['name_approved_by'] ?? '');

    try {
        if (empty($sigVp))  throw new Exception("Signature image was not received. Please try uploading again.");
        if (empty($nameVp)) throw new Exception("Please enter your full name before submitting.");
        if (strpos($sigVp, 'data:image') !== 0) throw new Exception("Invalid signature format. Please re-upload your signature image.");

        // Fetch request including prior sigs for email
        $bStmt = $conn->prepare(
            "SELECT b.id, b.user_name, b.equipment_name AS resource_name,
                    b.borrow_date AS booking_date, b.return_date AS end_date,
                    b.start_time, b.end_time, b.purpose, u.email,
                    b.name_mmit_director, b.sig_mmit_director,
                    b.name_dept_head,     b.sig_dept_head
             FROM borrows b
             LEFT JOIN users u ON b.user_id = u.id
             WHERE b.id = ?
               AND name_dept_head IS NOT NULL AND name_dept_head != ''
               AND (name_approved_by IS NULL OR name_approved_by = '')"
        );
        $bStmt->bind_param("i", $bookingId);
        $bStmt->execute();
        $booking = $bStmt->get_result()->fetch_assoc();
        if (!$booking) throw new Exception("Request not found or already approved.");

        // Write VP sig + flip status to Approved
        $upd = $conn->prepare(
            "UPDATE borrows
             SET sig_approved_by  = ?,
                 name_approved_by = ?,
                 status           = 'Approved'
             WHERE id = ?"
        );
        $upd->bind_param("ssi", $sigVp, $nameVp, $bookingId);
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
        $dbNameDept = htmlspecialchars($booking['name_dept_head']     ?? '');
        $dbSigDept  = $booking['sig_dept_head'] ?? '';

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

        $c1 = $sigCell('Noted By',    'MMIT Director',                  $dbNameMmit,                $dbSigMmit, true);
        $c2 = $sigCell('Verified By', 'Program Head',                   $dbNameDept,                $dbSigDept, true);
        $c3 = $sigCell('Approved By', 'VP for Administration & Finance', htmlspecialchars($nameVp), $sigVp,     true);

        $refNo    = 'AE-' . date('Y') . '-' . str_pad($bookingId, 5, '0', STR_PAD_LEFT);
        $issuedAt = date('F j, Y \a\t h:i A');

        $details = $detRow('Equipment',     $booking['resource_name'], '&#128188;')
                 . $detRow('Borrow Date',   $displayDate,              '&#128197;')
                 . $detRow('Time',          $displayTime,              '&#128336;')
                 . $detRow('Purpose/Event', $parsedEvent,              '&#127775;')
                 . $detRow('Department',    $parsedDept,               '&#127979;')
                 . $detRow('Contact',       $parsedContact,            '&#128100;');

        if (!empty($booking['email'])) {
            $emailSubject = "APPROVED: Equipment Borrow Slip {$refNo} | AssetEase";
            $emailBody = "
<html><body style='margin:0;padding:0;background:#f0f2f5;font-family:Arial,Helvetica,sans-serif;'>
<div style='max-width:640px;margin:30px auto 40px;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.12);'>
  <div style='background:#95122C;padding:28px 30px 20px;text-align:center;'>
    <div style='font-size:11px;color:rgba(255,255,255,.65);letter-spacing:2px;text-transform:uppercase;'>AssetEase &mdash; Facility &amp; Equipment Management</div>
    <div style='font-size:24px;font-weight:700;color:#fff;margin-top:6px;'>EQUIPMENT BORROW SLIP</div>
    <div style='display:inline-block;background:#28a745;color:#fff;font-size:13px;font-weight:700;padding:5px 20px;border-radius:20px;margin-top:10px;'>&#10003;&nbsp;FULLY APPROVED</div>
  </div>
  <div style='background:#f8f9fa;padding:9px 30px;border-bottom:1px solid #e0e0e0;font-size:12px;color:#555;'>
    <span><strong>Ref No.:</strong> {$refNo}</span>&nbsp;&nbsp;&nbsp;<span><strong>Issued:</strong> {$issuedAt}</span>
  </div>
  <div style='padding:26px 30px;'>
    <p style='font-size:14px;color:#333;margin:0 0 18px;'>Dear <strong>" . htmlspecialchars($booking['user_name']) . "</strong>,<br><br>
    Your equipment borrow request has been <strong style='color:#28a745;'>fully approved</strong> by all required signatories.
    Please present this email or a printed copy when claiming the equipment from the MMIT office.</p>
    <div style='background:#fafafa;border:1px solid #e8e8e8;border-left:4px solid #95122C;border-radius:8px;padding:18px 20px;margin-bottom:24px;'>
      <div style='font-size:11px;font-weight:700;color:#95122C;text-transform:uppercase;letter-spacing:1px;margin-bottom:12px;'>&#128203; Borrow Details</div>
      <table style='width:100%;border-collapse:collapse;'>{$details}</table>
    </div>
    <div style='font-size:11px;font-weight:700;color:#95122C;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;'>&#128394; Official Signatories</div>
    <table style='width:100%;border-collapse:collapse;margin-bottom:24px;'><tr>{$c1}{$c2}{$c3}</tr></table>
    <div style='background:#fff8e1;border:1px solid #ffe082;border-radius:8px;padding:12px 16px;font-size:12px;color:#5d4037;margin-bottom:20px;'>
      <strong>&#9888; Reminder:</strong> This slip is valid only for the dates and equipment listed above.
      Return the equipment in good condition. Damage or loss may result in replacement charges.
    </div>
    <p style='font-size:11px;color:#aaa;text-align:center;margin:0;'>Automated message from <strong>AssetEase</strong>. Do not reply.</p>
  </div>
  <div style='background:#95122C;padding:12px 30px;text-align:center;'>
    <span style='color:rgba(255,255,255,.55);font-size:11px;'>AssetEase &mdash; Facility &amp; Equipment Management System &mdash; " . date('Y') . "</span>
  </div>
</div></body></html>";
            sendEmail($booking['email'], $emailSubject, $emailBody);
        }

        $success = "Equipment request #{$bookingId} has been fully approved! The signed borrow slip has been emailed to the user.";
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// ============================================================
// DATA FETCH
// ============================================================
// Pending for VP: MMIT signed + PH signed + VP NOT yet signed
$pendingResult = $conn->query(
    "SELECT b.*, u.email AS user_email
     FROM borrows b LEFT JOIN users u ON b.user_id = u.id
     WHERE b.status = 'Pending'
       AND b.name_mmit_director IS NOT NULL AND b.name_mmit_director != ''
       AND b.name_dept_head     IS NOT NULL AND b.name_dept_head     != ''
       AND (b.name_approved_by IS NULL OR b.name_approved_by = '')
     ORDER BY b.id DESC"
);
$pending_requests = $pendingResult ? $pendingResult->fetch_all(MYSQLI_ASSOC) : [];

// Approved by VP
$approvedResult = $conn->query(
    "SELECT b.*, u.email AS user_email
     FROM borrows b LEFT JOIN users u ON b.user_id = u.id
     WHERE b.status = 'Approved' AND b.name_approved_by IS NOT NULL AND b.name_approved_by != ''
     ORDER BY b.id DESC"
);
$approved_requests = $approvedResult ? $approvedResult->fetch_all(MYSQLI_ASSOC) : [];

// All visible to VP (reached VP level = PH has signed)
$allVpResult = $conn->query(
    "SELECT b.*, u.email AS user_email
     FROM borrows b LEFT JOIN users u ON b.user_id = u.id
     WHERE b.name_dept_head IS NOT NULL AND b.name_dept_head != ''
     ORDER BY b.id DESC"
);
$vp_visible = $allVpResult ? $allVpResult->fetch_all(MYSQLI_ASSOC) : [];

$for_signing   = count($pending_requests);
$signed_by_vp  = count($approved_requests);
$total_visible = count($vp_visible);

// Notifications
$notifStmt = $conn->prepare("SELECT id, message, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$notifStmt->bind_param("i", $userId);
$notifStmt->execute();
$notifList  = [];
while ($n = $notifStmt->get_result()->fetch_assoc()) $notifList[] = $n;
$notifCount = count($notifList);

// Current filter from URL
$equipFilter = $_GET['equip_filter'] ?? 'Pending';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VP Administration Dashboard — AssetEase</title>
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

/* ── Sidebar ───────────────────────────────────────────────── */
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

/* ── Main wrap ─────────────────────────────────────────────── */
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

/* ── Content ───────────────────────────────────────────────── */
.content { padding:28px 36px 50px; flex:1; }
.alert { padding:13px 18px; border-radius:10px; margin-bottom:22px; border-left:4px solid; display:flex; align-items:center; gap:10px; font-size:13px; }
.alert-success { background:#edfaf1; color:#155724; border-color:#28a745; }
.alert-error   { background:#fdf0f0; color:#721c24; border-color:#dc3545; }

/* ── Stats ─────────────────────────────────────────────────── */
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
.si-amber { background:#fff7ed; color:#f97316; }
.si-red   { background:#fef2f2; color:var(--red); }
.si-green { background:#f0fdf4; color:#22c55e; }
.si-blue  { background:#eff6ff; color:#3b82f6; }

/* ── Workflow banner ───────────────────────────────────────── */
.workflow-banner { background:#f5f3ff; border:1px solid #c4b5fd; border-radius:var(--radius); padding:14px 20px; margin-bottom:22px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
.workflow-banner i { color:#7c3aed; font-size:16px; flex-shrink:0; }
.wf-steps { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.wf-step { font-size:12px; font-weight:500; color:#5b21b6; padding:4px 10px; border-radius:20px; }
.wf-step.done   { color:#6b7280; text-decoration:line-through; opacity:0.65; }
.wf-step.active { background:#7c3aed; color:#fff; font-weight:600; }
.wf-arrow { color:#c4b5fd; font-size:12px; }

/* ── Card & Table ──────────────────────────────────────────── */
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

/* ── Badges & buttons ──────────────────────────────────────── */
.badge-status { display:inline-flex; align-items:center; gap:5px; padding:4px 11px; border-radius:20px; font-size:11.5px; font-weight:600; }
.badge-status::before { content:''; width:6px; height:6px; border-radius:50%; display:inline-block; }
.status-Pending  { background:#fff8e6; color:#b07800; }
.status-Pending::before  { background:#f39c12; }
.status-Approved { background:#edfaf1; color:#1a7a3c; }
.status-Approved::before { background:#28a745; }
.status-Rejected { background:#fdf0f0; color:#9b1c1c; }
.status-Rejected::before { background:#dc3545; }
.signed-badge { display:inline-flex; align-items:center; gap:5px; font-size:12px; color:#1a7a3c; font-weight:600; }
.signed-badge i { font-size:10px; }
.btn-act { padding:5px 11px; border-radius:7px; border:none; font-size:12px; font-weight:600; cursor:pointer; font-family:'Poppins'; transition:all 0.18s; display:inline-flex; align-items:center; gap:5px; }
.btn-approve { background:#edfaf1; color:#1a7a3c; }
.btn-approve:hover { background:#28a745; color:#fff; }
.btn-view    { background:#f0f4ff; color:#4f46e5; border:1px solid #c7d2fe; }
.btn-view:hover    { background:#4f46e5; color:#fff; }
.btn-print   { background:#f0fff4; color:#1a7a3c; border:1px solid #86efac; }
.btn-print:hover   { background:#22c55e; color:#fff; }

/* ── Progress badge ────────────────────────────────────────── */
.sig-progress-badge { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:6px; font-size:11.5px; font-weight:700; cursor:pointer; transition:all 0.18s; border:1px solid; }
.sig-2of3 { background:#eff6ff; color:#1e40af; border-color:#93c5fd; }
.sig-3of3 { background:#f0fdf4; color:#166534; border-color:#86efac; }
.sig-progress-badge:hover { opacity:0.8; }

.no-results { text-align:center; padding:40px 20px; color:#ccc; }
.no-results i { font-size:32px; margin-bottom:10px; display:block; color:#ddd; }
.no-results p { font-size:14px; }

/* ── Modals ────────────────────────────────────────────────── */
.modal-overlay { display:none; position:fixed; z-index:1500; inset:0; background:rgba(0,0,0,0.45); align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:#fff; border-radius:18px; padding:32px; max-width:500px; width:92%; box-shadow:0 16px 48px rgba(0,0,0,0.18); animation:modalIn 0.22s ease; max-height:90vh; overflow-y:auto; }
.modal-box-wide { max-width:700px; }
@keyframes modalIn { from{transform:scale(0.94);opacity:0} to{transform:scale(1);opacity:1} }
.modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; padding-bottom:14px; border-bottom:1px solid #f0ebe8; }
.modal-header h2 { font-size:18px; color:var(--dark); font-weight:700; }
.modal-close { background:none; border:none; cursor:pointer; color:#bbb; font-size:20px; transition:color 0.2s; }
.modal-close:hover { color:#555; }
.modal-info { background:#fdf9f8; border-radius:10px; padding:14px; color:#555; font-size:13px; line-height:1.7; margin-bottom:16px; }
.modal-hint { background:#f0f7ff; border-radius:8px; padding:11px 14px; color:#444; font-size:12.5px; display:flex; gap:8px; align-items:flex-start; margin-bottom:16px; }
.modal-hint i { color:#0066cc; margin-top:2px; }
.modal-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:20px; }
.btn-modal { padding:10px 22px; border-radius:9px; border:none; font-size:13px; font-weight:600; font-family:'Poppins'; cursor:pointer; transition:0.2s; display:flex; align-items:center; gap:6px; }
.btn-modal-cancel  { background:#f0ebe8; color:#555; }
.btn-modal-cancel:hover { background:#e0d8d5; }
.btn-modal-confirm { background:var(--red); color:#fff; }
.btn-modal-confirm:hover { background:var(--red-dark); }
.warn-notice { background:#fffbeb; border:1px solid #fcd34d; border-radius:8px; padding:10px 14px; margin-bottom:16px; font-size:12px; color:#92400e; }

/* ── Prior signatures display block ───────────────────────── */
.prior-sigs-block { background:#f0fff4; border:1px solid #86efac; border-radius:10px; padding:14px 16px; margin-bottom:18px; }
.prior-sigs-title { font-size:11px; font-weight:700; color:#166534; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:12px; display:flex; align-items:center; gap:6px; }
.prior-sigs-grid  { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.prior-sig-cell { text-align:center; background:#fff; border:1px solid #d1fae5; border-radius:8px; padding:10px 8px; }
.prior-sig-step { font-size:9.5px; font-weight:700; color:var(--red); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px; }
.prior-sig-img  { max-width:100%; max-height:52px; object-fit:contain; border:1px solid #e5e7eb; border-radius:4px; background:#fff; padding:2px; display:block; margin:0 auto 6px; }
.prior-sig-placeholder { height:48px; border-bottom:1.5px dashed #a7f3d0; margin-bottom:6px; display:flex; align-items:center; justify-content:center; color:#a7f3d0; font-size:11px; }
.prior-sig-name { font-size:12px; font-weight:700; color:#1f2937; }
.prior-sig-role { font-size:10.5px; color:#9ca3af; margin-top:2px; }
.prior-sig-check { font-size:10.5px; color:#28a745; font-weight:700; margin-top:4px; }

/* ── VP upload area ────────────────────────────────────────── */
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

/* ── Sig view modal ────────────────────────────────────────── */
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

/* ── Print slip ────────────────────────────────────────────── */
.print-sig-grid  { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-top:16px; }
.print-sig-cell  { text-align:center; border:1px solid #e5e7eb; border-top:3px solid #28a745; padding:12px 8px; border-radius:6px; background:#f9fafb; }
.print-sig-cell img { max-width:140px; height:52px; object-fit:contain; display:block; margin:0 auto 6px; }
.print-sig-cell .p-name   { font-size:12px; font-weight:700; color:#1a1a2e; }
.print-sig-cell .p-role   { font-size:10.5px; color:#666; margin-top:2px; }
.print-sig-cell .p-lbl    { font-size:10px; color:#999; font-style:italic; }
.print-sig-cell .p-status { font-size:10.5px; font-weight:600; margin-bottom:4px; color:#28a745; }

@media print {
    body * { visibility:hidden !important; }
    #printSlipContent, #printSlipContent * { visibility:visible !important; }
    #printSlipContent { position:fixed; left:0; top:0; width:100%; padding:20px; background:#fff; }
    .no-print { display:none !important; }
}
@media(max-width:1100px) { .stats-row{grid-template-columns:repeat(2,1fr);} }
@media(max-width:768px) {
    :root{--sidebar-w:0px;}
    .sidebar{display:none;}
    .main-wrap{margin-left:0;}
    .content{padding:16px 12px 40px;}
    .top-header{padding:0 14px;height:auto;min-height:60px;flex-wrap:wrap;gap:8px;padding-top:8px;padding-bottom:8px;}
    .header-logo-mini{display:none;}
    .stats-row{grid-template-columns:1fr 1fr;gap:10px;}
    .sig-view-grid{grid-template-columns:1fr;}
    .prior-sigs-grid{grid-template-columns:1fr;}
    .print-sig-grid{grid-template-columns:1fr;}
}
</style>
</head>
<body>

<!-- ═══════════════════════════════════════════════════════════
     SIDEBAR
════════════════════════════════════════════════════════════ -->
<div class="sidebar">
    <a href="vp_dashboard.php" class="sidebar-logo">
        <div class="logo-img-wrap">
            <img src="image/logo.png" alt="AssetEase Logo">
            <div class="logo-glow"></div>
        </div>
        <div class="sidebar-logo-text">
            <h2>ASSETEASE</h2>
            <span>VP Signatory Portal</span>
        </div>
    </a>
    <div class="admin-badge">
        <img src="https://ui-avatars.com/api/?name=<?= urlencode($vpName) ?>&background=95122C&color=fff&rounded=true" alt="">
        <div class="admin-badge-info">
            <div class="ab-name"><?= htmlspecialchars(strlen($vpName) > 16 ? substr($vpName,0,16).'…' : $vpName) ?></div>
            <div class="ab-role">VP for Administration</div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="vp_dashboard.php" class="active">
            <div class="nav-icon"><i class="fas fa-file-signature"></i></div> Equipment Requests
        </a>
        <hr>
        <div class="nav-section-label">Account</div>
        <a href="logout.php"><div class="nav-icon"><i class="fas fa-right-from-bracket"></i></div> Logout</a>
    </nav>
    <div class="sidebar-promo">
        <p><strong>Your role:</strong> Final signatory (Step 3 of 3). Your approval fully releases the borrow request to the user.</p>
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
                <h1>VP Administration Panel</h1>
                <p>Welcome back, <strong><?= htmlspecialchars($vpName) ?></strong> — <?= date('l, F j, Y') ?></p>
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
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($vpName) ?>&background=95122C&color=fff&rounded=true" alt="">
                <div>
                    <span><?= htmlspecialchars(strlen($vpName) > 14 ? substr($vpName,0,14).'…' : $vpName) ?></span>
                    <span class="pp-role">VP for Administration</span>
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
            <div><strong>Logged in as: VP for Administration &amp; Finance</strong> &mdash;
            You can <strong>Finally Approve</strong> equipment requests already noted (Step 1) and verified (Step 2) — this is Step 3 of 3.
            Your signature flips the status to <strong>Approved</strong> and emails the full borrow slip to the user.</div>
        </div>

        <!-- Stat Cards -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="sc-label">For Your Signature</div>
                    <div class="sc-value"><?= $for_signing ?></div>
                    <div class="sc-sub">Steps 1 &amp; 2 done — needs you (2/3)</div>
                </div>
                <div class="stat-icon si-amber"><i class="fas fa-pen-nib"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="sc-label">Approved by You</div>
                    <div class="sc-value"><?= $signed_by_vp ?></div>
                    <div class="sc-sub">Fully approved — 3/3 done</div>
                </div>
                <div class="stat-icon si-red"><i class="fas fa-circle-check"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="sc-label">Borrow Slips Ready</div>
                    <div class="sc-value"><?= $signed_by_vp ?></div>
                    <div class="sc-sub">Print / email available</div>
                </div>
                <div class="stat-icon si-green"><i class="fas fa-file-circle-check"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="sc-label">Visible to You</div>
                    <div class="sc-value"><?= $total_visible ?></div>
                    <div class="sc-sub">Reached your level</div>
                </div>
                <div class="stat-icon si-blue"><i class="fas fa-layer-group"></i></div>
            </div>
        </div>

        <!-- Workflow Banner -->
        <div class="workflow-banner">
            <i class="fas fa-circle-info"></i>
            <div class="wf-steps">
                <span class="wf-step done">① Request Submitted</span><span class="wf-arrow">→</span>
                <span class="wf-step done">② MMIT Noted (1/3)</span><span class="wf-arrow">→</span>
                <span class="wf-step done">③ Program Head Verified (2/3)</span><span class="wf-arrow">→</span>
                <span class="wf-step active">④ VP Approves — You (3/3)</span><span class="wf-arrow">→</span>
                <span class="wf-step">⑤ Slip Released to User</span>
            </div>
        </div>

        <!-- Requests Table -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-file-signature"></i> Equipment Borrow Requests</div>
                <div class="table-controls">
                    <a href="?equip_filter=Pending"  class="filter-pill <?= $equipFilter==='Pending'?'active':'' ?>">
                        <i class="fas fa-pen-nib" style="font-size:10px;"></i> For Approval — 2/3 (<?= $for_signing ?>)
                    </a>
                    <a href="?equip_filter=Approved" class="filter-pill <?= $equipFilter==='Approved'?'active':'' ?>">
                        <i class="fas fa-check" style="font-size:10px;"></i> Approved — 3/3 (<?= $signed_by_vp ?>)
                    </a>
                    <a href="?equip_filter=All"      class="filter-pill <?= $equipFilter==='All'?'active':'' ?>">
                        All (<?= $total_visible ?>)
                    </a>
                </div>
            </div>
            <div class="table-wrap">
                <table id="requestsTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Equipment</th>
                            <th>Requested By</th>
                            <th>Borrow Date</th>
                            <th>MMIT Director</th>
                            <th>Program Head</th>
                            <th>Progress</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    // Choose which set to display based on filter
                    $displayRows = match($equipFilter) {
                        'Pending'  => $pending_requests,
                        'Approved' => $approved_requests,
                        default    => $vp_visible,
                    };
                    if (!empty($displayRows)):
                        foreach ($displayRows as $req):
                            $mmitDone  = !empty($req['name_mmit_director']);
                            $deptDone  = !empty($req['name_dept_head']);
                            $vpDone    = !empty($req['name_approved_by']);
                            $doneCount = ($mmitDone?1:0) + ($deptDone?1:0) + ($vpDone?1:0);
                            $sigClass  = $doneCount >= 3 ? 'sig-3of3' : 'sig-2of3';

                            $borrowDate = ($req['borrow_date'] ?? $req['booking_date'] ?? '');
                            $returnDate = ($req['return_date'] ?? $req['end_date']     ?? '');
                            $displayDate = ($borrowDate === $returnDate)
                                ? date('M d, Y', strtotime($borrowDate))
                                : date('M d, Y', strtotime($borrowDate)) . ' – ' . date('M d, Y', strtotime($returnDate));

                            $jsData = htmlspecialchars(json_encode([
                                'id'            => $req['id'],
                                'user_name'     => $req['user_name'],
                                'equipment_name'=> $req['equipment_name'] ?? '',
                                'borrow_date'   => $borrowDate,
                                'return_date'   => $returnDate,
                                'start_time'    => $req['start_time'] ?? '',
                                'end_time'      => $req['end_time']   ?? '',
                                'purpose'       => $req['purpose']    ?? '',
                                'sigMmit'       => $req['sig_mmit_director'] ?? '',
                                'nameMmit'      => $req['name_mmit_director'] ?? '',
                                'sigDept'       => $req['sig_dept_head']      ?? '',
                                'nameDept'      => $req['name_dept_head']     ?? '',
                                'sigVp'         => $req['sig_approved_by']    ?? '',
                                'nameVp'        => $req['name_approved_by']   ?? '',
                                'status'        => $req['status'],
                            ]), ENT_QUOTES);
                    ?>
                    <tr>
                        <td><span style="font-weight:700;color:var(--red);font-size:12px;">#<?= $req['id'] ?></span></td>
                        <td><strong><?= htmlspecialchars($req['equipment_name'] ?? '—') ?></strong></td>
                        <td><strong><?= htmlspecialchars($req['user_name']) ?></strong></td>
                        <td><?= $displayDate ?></td>
                        <td>
                            <?php if ($mmitDone): ?>
                                <span class="signed-badge"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($req['name_mmit_director']) ?></span>
                            <?php else: ?>
                                <span style="color:#bbb;font-size:12px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($deptDone): ?>
                                <span class="signed-badge"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($req['name_dept_head']) ?></span>
                            <?php else: ?>
                                <span style="color:#bbb;font-size:12px;">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button type="button"
                                class="sig-progress-badge <?= $sigClass ?>"
                                onclick='showSigViewModal(<?= $jsData ?>)'
                                title="View signature progress">
                                <i class="fas fa-signature"></i>
                                <?= $doneCount ?>/3
                                <?php if ($doneCount === 3): ?>
                                    <i class="fas fa-check-circle" style="color:#22c55e;"></i>
                                <?php endif; ?>
                            </button>
                        </td>
                        <td><span class="badge-status status-<?= $req['status'] ?>"><?= $req['status'] ?></span></td>
                        <td style="white-space:nowrap;">
                            <?php if ($req['status'] === 'Pending' && !$vpDone): ?>
                                <button type="button" class="btn-act btn-approve"
                                    onclick='showApproveModal(<?= $jsData ?>)'>
                                    <i class="fas fa-pen-nib"></i> Approve
                                </button>
                            <?php elseif ($req['status'] === 'Approved'): ?>
                                <button type="button" class="btn-act btn-print"
                                    onclick='showPrintModal(<?= $jsData ?>)'>
                                    <i class="fas fa-print"></i> Slip
                                </button>
                            <?php endif; ?>
                            <button type="button" class="btn-act btn-view"
                                onclick='showSigViewModal(<?= $jsData ?>)'>
                                <i class="fas fa-eye"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="9">
                        <div class="no-results"><i class="fas fa-file-signature"></i>
                        <p>No equipment requests found for this filter.</p></div>
                    </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div><!-- /content -->
</div><!-- /main-wrap -->

<!-- ═══════════════════════════════════════════════════════════
     APPROVE MODAL — upload-only, shows Step 1 & 2 sigs
════════════════════════════════════════════════════════════ -->
<div id="approveModal" class="modal-overlay">
    <div class="modal-box modal-box-wide">
        <div class="modal-header">
            <h2>
                <i class="fas fa-circle-check" style="color:#28a745;margin-right:8px;"></i>
                Final Approval — Step 3 of 3
            </h2>
            <button class="modal-close" onclick="closeApprove()"><i class="fas fa-xmark"></i></button>
        </div>

        <form method="POST" action="" id="approveForm" onsubmit="return validateApprove()">
            <input type="hidden" name="approve_equipment" value="1">
            <input type="hidden" id="approveBookingId"  name="booking_id"       value="">
            <input type="hidden" id="hiddenSigVp"        name="sig_approved_by"  value="">
            <input type="hidden" id="hiddenNameVp"       name="name_approved_by" value="">

            <div id="approveBkgInfo" class="modal-info"></div>

            <!-- Step progress bar -->
            <div style="display:flex;gap:0;margin-bottom:16px;border-radius:8px;overflow:hidden;font-size:12px;">
                <div style="flex:1;padding:8px 6px;text-align:center;background:#28a745;color:#fff;">&#10003; Step 1<br><strong>MMIT Director</strong></div>
                <div style="flex:1;padding:8px 6px;text-align:center;background:#28a745;color:#fff;">&#10003; Step 2<br><strong>Program Head</strong></div>
                <div style="flex:1;padding:8px 6px;text-align:center;background:var(--red);color:#fff;">&#9998; Step 3<br><strong>VP — You</strong></div>
            </div>

            <!-- Steps 1 & 2 signatures already collected -->
            <div class="prior-sigs-block">
                <div class="prior-sigs-title">
                    <i class="fas fa-check-circle"></i> Steps 1 &amp; 2 Complete — Signatures Already Collected
                </div>
                <div class="prior-sigs-grid">
                    <!-- Step 1: MMIT -->
                    <div class="prior-sig-cell">
                        <div class="prior-sig-step">Step 1 — Noted By</div>
                        <div id="mmitSigImgWrap"></div>
                        <div class="prior-sig-name" id="mmitSigName">—</div>
                        <div class="prior-sig-role">MMIT Director</div>
                        <div class="prior-sig-check"><i class="fas fa-check-circle"></i> Signed</div>
                    </div>
                    <!-- Step 2: Program Head -->
                    <div class="prior-sig-cell">
                        <div class="prior-sig-step">Step 2 — Verified By</div>
                        <div id="deptSigImgWrap"></div>
                        <div class="prior-sig-name" id="deptSigName">—</div>
                        <div class="prior-sig-role">Program Head</div>
                        <div class="prior-sig-check"><i class="fas fa-check-circle"></i> Signed</div>
                    </div>
                </div>
            </div>

            <!-- VP's own signature -->
            <div class="sig-section">
                <div class="sig-section-title"><i class="fas fa-pen-nib"></i> Your Signature — VP for Administration &amp; Finance (Step 3)</div>

                <input type="text" id="vpNameInput"
                    class="sig-name-input"
                    placeholder="Enter your full name and designation"
                    autocomplete="off"
                    oninput="syncVpName(this.value)">

                <div class="sig-upload-area" id="vpUploadArea" onclick="triggerVpUpload()">
                    <div class="sig-upload-icon"><i class="fas fa-file-signature"></i></div>
                    <div class="sig-upload-text" id="vpUploadText">Click to upload your signature image</div>
                    <div class="sig-upload-sub">PNG, JPG — signature on white or transparent background</div>
                </div>
                <input type="file" id="vpSigFile" accept="image/*" style="display:none;" onchange="handleVpUpload(this)">

                <div class="sig-preview-wrap" id="vpPreviewWrap">
                    <div class="sig-preview-label"><i class="fas fa-check-circle"></i> Signature ready</div>
                    <img id="vpPreviewImg" src="" alt="VP Signature preview">
                    <br>
                    <button type="button" class="sig-clear-btn" onclick="clearVpSig()">
                        <i class="fas fa-times"></i> Remove &amp; upload a different signature
                    </button>
                </div>
            </div>

            <div class="warn-notice">
                <strong>&#9888; By submitting,</strong> you give <strong>final approval</strong>. The status becomes <strong>Approved</strong> and a fully-signed borrow slip is emailed to the user.
            </div>
            <div class="modal-hint">
                <i class="fas fa-envelope"></i> The completed borrow slip with all 3 signatures will be sent to the user's email immediately.
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-modal btn-modal-cancel" onclick="closeApprove()">Cancel</button>
                <button type="submit" class="btn-modal btn-modal-confirm" id="vpSubmitBtn">
                    <i class="fas fa-check"></i> Confirm Final Approval
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ SIGNATURE VIEW MODAL ═════════════════════════════════ -->
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
        <!-- Shortcut Approve button if VP's turn -->
        <div id="sigViewApproveWrap" style="display:none;margin-top:16px;text-align:center;">
            <button type="button" id="sigViewApproveBtn"
                style="padding:10px 24px;background:var(--red);color:#fff;border:none;border-radius:9px;font-family:'Poppins';font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:8px;"
                onclick="sigViewGoApprove()">
                <i class="fas fa-pen-nib"></i> Sign &amp; Finally Approve This Request
            </button>
        </div>
        <div class="modal-actions" style="margin-top:16px;">
            <button type="button" class="btn-modal btn-modal-cancel" onclick="closeSigView()">Close</button>
        </div>
    </div>
</div>

<!-- ═══ PRINT / BORROW SLIP MODAL ════════════════════════════ -->
<div id="printModal" class="modal-overlay">
    <div class="modal-box modal-box-wide">
        <div class="modal-header">
            <h2><i class="fas fa-print" style="color:#22c55e;margin-right:8px;"></i>Equipment Borrow Slip</h2>
            <button class="modal-close" onclick="closePrintModal()"><i class="fas fa-xmark"></i></button>
        </div>
        <div id="printSlipContent">
            <div style="background:#fff;font-family:Arial,Helvetica,sans-serif;">
                <div style="background:#95122C;padding:22px 26px;text-align:center;border-radius:10px 10px 0 0;">
                    <div style="font-size:10px;color:rgba(255,255,255,.65);letter-spacing:2px;text-transform:uppercase;">AssetEase — Equipment Management</div>
                    <div style="font-size:20px;font-weight:700;color:#fff;margin-top:6px;">EQUIPMENT BORROW SLIP</div>
                    <div style="display:inline-block;background:#28a745;color:#fff;font-size:12px;font-weight:700;padding:4px 16px;border-radius:20px;margin-top:8px;">&#10003; FULLY APPROVED</div>
                    <div style="font-size:11px;color:rgba(255,255,255,.7);margin-top:6px;" id="slipRefNo"></div>
                </div>
                <div style="border:1px solid #e5e7eb;border-top:none;border-radius:0 0 10px 10px;padding:20px 26px;">
                    <table style="width:100%;border-collapse:collapse;margin-bottom:16px;font-size:13px;">
                        <tr><td style="padding:5px 0;color:#666;width:35%;">Borrower</td><td style="font-weight:600;" id="slipUser"></td></tr>
                        <tr><td style="padding:5px 0;color:#666;">Equipment</td><td style="font-weight:600;" id="slipEquip"></td></tr>
                        <tr><td style="padding:5px 0;color:#666;">Date</td><td style="font-weight:600;" id="slipDate"></td></tr>
                        <tr><td style="padding:5px 0;color:#666;">Time</td><td style="font-weight:600;" id="slipTime"></td></tr>
                        <tr><td style="padding:5px 0;color:#666;">Purpose</td><td style="font-weight:600;" id="slipEvent"></td></tr>
                    </table>
                    <div style="font-size:11px;font-weight:700;color:#95122C;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;">Official Signatories</div>
                    <div class="print-sig-grid" id="slipSigGrid"></div>
                    <div style="background:#fff8e1;border:1px solid #ffe082;border-radius:8px;padding:10px 14px;font-size:12px;color:#5d4037;margin-top:16px;">
                        <strong>&#9888; Reminder:</strong> Valid only for dates and equipment listed. Return in good condition.
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-actions no-print" style="margin-top:18px;">
            <button type="button" class="btn-modal btn-modal-cancel" onclick="closePrintModal()">Close</button>
            <button type="button" class="btn-modal btn-modal-confirm" onclick="window.print()">
                <i class="fas fa-print"></i> Print Slip
            </button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════════════════════ -->
<script>
let _currentRowData = null;

/* ── Notifications ─────────────────────────────────────────── */
function toggleNotif(e) {
    e.stopPropagation();
    document.getElementById('notifDropdown').classList.toggle('active');
}
document.addEventListener('click', () => {
    document.getElementById('notifDropdown')?.classList.remove('active');
});

/* ── Global search ─────────────────────────────────────────── */
function globalSearch(q) {
    q = q.toLowerCase();
    document.querySelectorAll('#requestsTable tbody tr').forEach(tr => {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

/* ── Image compression ─────────────────────────────────────── */
function compressImage(file, maxW, maxH, quality, callback) {
    const reader = new FileReader();
    reader.onload = e => {
        const img = new Image();
        img.onload = () => {
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

/* ── VP upload ─────────────────────────────────────────────── */
function triggerVpUpload() { document.getElementById('vpSigFile').click(); }
function handleVpUpload(input) {
    const file = input.files[0];
    if (!file) return;
    compressImage(file, 600, 200, 0.92, dataUrl => {
        document.getElementById('hiddenSigVp').value = dataUrl;
        document.getElementById('vpPreviewImg').src = dataUrl;
        document.getElementById('vpPreviewWrap').classList.add('visible');
        document.getElementById('vpUploadArea').classList.add('has-file');
        document.getElementById('vpUploadText').textContent = '✓ ' + file.name;
    });
}
function clearVpSig() {
    document.getElementById('hiddenSigVp').value = '';
    document.getElementById('vpPreviewImg').src = '';
    document.getElementById('vpPreviewWrap').classList.remove('visible');
    document.getElementById('vpUploadArea').classList.remove('has-file');
    document.getElementById('vpUploadText').textContent = 'Click to upload your signature image';
    document.getElementById('vpSigFile').value = '';
}
function syncVpName(val) {
    document.getElementById('hiddenNameVp').value = val;
}

/* ── Approve form validation ───────────────────────────────── */
function validateApprove() {
    const nameEl = document.getElementById('vpNameInput');
    syncVpName(nameEl ? nameEl.value.trim() : '');
    if (!nameEl || !nameEl.value.trim()) {
        alert('Please enter your full name before submitting.');
        if (nameEl) nameEl.focus();
        return false;
    }
    const sigEl = document.getElementById('hiddenSigVp');
    if (!sigEl || !sigEl.value || !sigEl.value.startsWith('data:image')) {
        alert('Please upload your signature image before submitting.');
        return false;
    }
    const btn = document.getElementById('vpSubmitBtn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting…';
    }
    return true;
}

/* ── Approve Modal ─────────────────────────────────────────── */
function showApproveModal(data) {
    _currentRowData = data;

    document.getElementById('approveBookingId').value = data.id;

    // Parse dates/times for display
    const borrowDate  = data.borrow_date;
    const returnDate  = data.return_date;
    const startTime   = data.start_time;
    const endTime     = data.end_time;
    const displayDate = borrowDate === returnDate ? formatDate(borrowDate) : formatDate(borrowDate) + ' to ' + formatDate(returnDate);
    const displayTime = startTime && endTime ? formatTime(startTime) + ' – ' + formatTime(endTime) : '';

    document.getElementById('approveBkgInfo').innerHTML =
        '<strong>Request #' + data.id + '</strong> &mdash; <em>' + escHtml(data.user_name) + '</em><br>' +
        'Equipment: <strong>' + escHtml(data.equipment_name) + '</strong><br>' +
        'Date: ' + displayDate + (displayTime ? ' &nbsp;|&nbsp; Time: ' + displayTime : '');

    // Show MMIT sig
    const mmitWrap = document.getElementById('mmitSigImgWrap');
    if (data.sigMmit) {
        mmitWrap.innerHTML = '<img class="prior-sig-img" src="' + data.sigMmit + '" alt="MMIT Sig">';
    } else {
        mmitWrap.innerHTML = '<div class="prior-sig-placeholder">No image</div>';
    }
    document.getElementById('mmitSigName').textContent = data.nameMmit || '—';

    // Show Program Head sig
    const deptWrap = document.getElementById('deptSigImgWrap');
    if (data.sigDept) {
        deptWrap.innerHTML = '<img class="prior-sig-img" src="' + data.sigDept + '" alt="PH Sig">';
    } else {
        deptWrap.innerHTML = '<div class="prior-sig-placeholder">No image</div>';
    }
    document.getElementById('deptSigName').textContent = data.nameDept || '—';

    // Reset VP fields
    document.getElementById('vpNameInput').value = '';
    document.getElementById('hiddenSigVp').value  = '';
    document.getElementById('hiddenNameVp').value = '';
    clearVpSig();

    const btn = document.getElementById('vpSubmitBtn');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check"></i> Confirm Final Approval'; }

    document.getElementById('approveModal').classList.add('open');
}
function closeApprove() { document.getElementById('approveModal').classList.remove('open'); }

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
        document.getElementById('svItem' + n).className = 'sig-view-item ' + (done ? 'done' : 'pending');
        document.getElementById('svImgWrap' + n).innerHTML = done
            ? '<img src="' + s.sig + '" alt="Signature">'
            : '<div class="sig-view-placeholder">Awaiting signature</div>';
        document.getElementById('svName' + n).textContent = s.name || (done ? '(name not recorded)' : '');
        document.getElementById('svBadge' + n).innerHTML  = done
            ? '<span class="sig-view-badge-done"><i class="fas fa-check-circle"></i> Signed</span>'
            : '<span class="sig-view-badge-pending"><i class="fas fa-hourglass-half"></i> Pending</span>';
    });

    const statusEl = document.getElementById('sigViewStatus');
    if (doneCount === 3) {
        statusEl.innerHTML = '<span style="color:#166534;font-weight:700;"><i class="fas fa-circle-check"></i> All 3 signatures complete — request fully approved.</span>';
    } else {
        statusEl.innerHTML = '<span style="color:#92400e;font-weight:600;"><i class="fas fa-hourglass-half"></i> '
            + doneCount + ' of 3 signatures collected.</span>';
    }

    // Show approve shortcut if it's VP's turn (2 done, VP not yet signed)
    const vpTurn = (doneCount === 2 && data.status === 'Pending' && !data.sigVp);
    document.getElementById('sigViewApproveWrap').style.display = vpTurn ? 'block' : 'none';

    document.getElementById('sigViewModal').classList.add('open');
}
function closeSigView() { document.getElementById('sigViewModal').classList.remove('open'); }
function sigViewGoApprove() {
    closeSigView();
    if (_currentRowData) showApproveModal(_currentRowData);
}

/* ── Print Slip Modal ──────────────────────────────────────── */
function showPrintModal(data) {
    const borrowDate = data.borrow_date || data.booking_date || '';
    const returnDate = data.return_date  || data.end_date    || '';
    const displayDate = borrowDate === returnDate ? formatDate(borrowDate) : formatDate(borrowDate) + ' – ' + formatDate(returnDate);
    const displayTime = (data.start_time && data.end_time) ? formatTime(data.start_time) + ' – ' + formatTime(data.end_time) : '';

    // Parse purpose
    let parsedEvent = '';
    const purpose   = data.purpose || '';
    const eMatch = purpose.match(/Event:\s*([^|]+)/i);
    if (eMatch) parsedEvent = eMatch[1].trim();
    if (!parsedEvent) {
        let raw = purpose.replace(/Contact:\s*[^|]+(\||$)/gi, '').replace(/Department:\s*[^|]+(\||$)/gi, '');
        parsedEvent = raw.replace(/Purpose:\s*/gi, '').replace(/\|/g, '').trim();
    }

    const refNo = 'AE-' + new Date().getFullYear() + '-' + String(data.id).padStart(5, '0');
    document.getElementById('slipRefNo').textContent  = 'Ref: ' + refNo;
    document.getElementById('slipUser').textContent   = data.user_name;
    document.getElementById('slipEquip').textContent  = data.equipment_name || '—';
    document.getElementById('slipDate').textContent   = displayDate;
    document.getElementById('slipTime').textContent   = displayTime || '—';
    document.getElementById('slipEvent').textContent  = parsedEvent || '—';

    const slots = [
        { label: 'Noted By',    role: 'MMIT Director',                  sig: data.sigMmit, name: data.nameMmit },
        { label: 'Verified By', role: 'Program Head',                   sig: data.sigDept, name: data.nameDept },
        { label: 'Approved By', role: 'VP for Administration & Finance', sig: data.sigVp,   name: data.nameVp  },
    ];
    let html = '';
    slots.forEach(s => {
        const done = !!(s.sig && s.sig.length > 0);
        html += `<div class="print-sig-cell" style="${done ? '' : 'border-top-color:#dee2e6;'}">
            <div class="p-status" style="color:${done ? '#28a745' : '#aaa'}">${done ? '&#10003; Signed' : '&#9744; Pending'}</div>
            ${done ? `<img src="${s.sig}" alt="Sig">` : `<div style="height:52px;border-bottom:1.5px dashed #ccc;margin-bottom:6px;"></div>`}
            <div class="p-name">${escHtml(s.name || '___________')}</div>
            <div class="p-role">${escHtml(s.role)}</div>
            <div class="p-lbl">${escHtml(s.label)}</div>
        </div>`;
    });
    document.getElementById('slipSigGrid').innerHTML = html;
    document.getElementById('printModal').classList.add('open');
}
function closePrintModal() { document.getElementById('printModal').classList.remove('open'); }

/* ── Close modals on backdrop click ───────────────────────── */
window.addEventListener('click', e => {
    if (e.target.classList.contains('modal-overlay')) {
        closeApprove(); closeSigView(); closePrintModal();
    }
});

/* ── Helpers ───────────────────────────────────────────────── */
function escHtml(str) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(str || ''));
    return d.innerHTML;
}
function formatDate(str) {
    if (!str) return '—';
    const d = new Date(str + 'T00:00:00');
    return d.toLocaleDateString('en-US', { month:'short', day:'2-digit', year:'numeric' });
}
function formatTime(str) {
    if (!str) return '';
    const [h, m] = str.split(':');
    const hr  = parseInt(h);
    const ampm = hr >= 12 ? 'PM' : 'AM';
    const hr12 = hr % 12 || 12;
    return hr12 + ':' + m + ' ' + ampm;
}
</script>
</body>
</html>