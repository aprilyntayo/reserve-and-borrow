<?php
/**
 * Super Admin Dashboard — AssetEase
 * 3-step approval flow:
 *   Step 1 — MMIT Director (plain super_admin OR signatoryTitle='MMIT_Director'):
 *             Writes sig_mmit_director + name_mmit_director. Status stays 'Pending'.
 *   Step 2 — Program Head (signatoryTitle='Dept_Head'):
 *             Writes sig_dept_head + name_dept_head. Status stays 'Pending'.
 *   Step 3 — VP Admin (signatoryTitle='VP_Admin'):
 *             Writes sig_approved_by + name_approved_by. Status becomes 'Approved'.
 */
session_start();
require_once 'config.php';
require_once 'notification_handler.php';
require_once 'email_notification.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: login.php");
    exit;
}

$superAdminId = $_SESSION['user_id'];

$superAdminStmt = $conn->prepare("SELECT uname, signatory_title FROM users WHERE id = ?");
$superAdminStmt->bind_param("i", $superAdminId);
$superAdminStmt->execute();
$superAdminResult = $superAdminStmt->get_result();
$superAdminRow    = $superAdminResult->fetch_assoc();
$superAdminName   = $superAdminRow['uname']           ?? 'Super Admin';
$signatoryTitle   = $superAdminRow['signatory_title'] ?? null;

// ── Signatory label for display ──────────────────────────────────────────────
if ($signatoryTitle === 'Dept_Head') {
    $signatoryLabel = 'Program Head';
} elseif ($signatoryTitle === 'VP_Admin') {
    $signatoryLabel = 'VP for Admin & Finance';
} else {
    $signatoryLabel = match($signatoryTitle) {
        'MMIT_Director' => 'MMIT Director',
        'VP_Admin'      => 'VP for Admin & Finance',
        default         => 'MMIT Director',   // plain super admin acts as MMIT Director
    };
}

// ── Effective role (used everywhere for workflow logic) ──────────────────────
// Plain super admin (signatoryTitle === null) always acts as MMIT_Director (Step 1).
$effectiveRole = $signatoryTitle ?? 'MMIT_Director';

$success = '';
$error   = '';

// ── Tab visibility per role ──────────────────────────────────────────────────
$allowedTabs = match($signatoryTitle) {
    'MMIT_Director' => ['equipment', 'inventory'],
    'Dept_Head'     => ['equipment'],
    'VP_Admin'      => ['equipment'],
    default         => ['rooms', 'equipment', 'inventory'],   // plain super admin sees all
};
$requestedTab = $_GET['tab'] ?? 'equipment';
$currentTab   = in_array($requestedTab, $allowedTabs) ? $requestedTab : $allowedTabs[0];

// ============================================================
// EQUIPMENT MANAGEMENT (Inventory)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_equipment'])) {
    $equipName = trim($_POST['equip_name'] ?? '');
    $totalQty  = intval($_POST['total_qty'] ?? 0);
    if (empty($equipName) || $totalQty <= 0) {
        $error = "Equipment name and quantity are required.";
    } else {
        $checkStmt = $conn->prepare("SELECT COUNT(*) AS count FROM equipment WHERE equipment_name = ?");
        $checkStmt->bind_param("s", $equipName);
        $checkStmt->execute();
        $checkRow = $checkStmt->get_result()->fetch_assoc();
        if ($checkRow['count'] > 0) {
            $error = "Equipment '$equipName' already exists. Please update the existing item instead.";
        } else {
            $stmt = $conn->prepare("INSERT INTO equipment (equipment_name, total_quantity, status) VALUES (?, ?, 'Available')");
            $stmt->bind_param("si", $equipName, $totalQty);
            if ($stmt->execute()) {
                $success    = "Equipment '$equipName' added successfully with quantity $totalQty!";
                $currentTab = 'inventory';
            } else {
                $error = "Error adding equipment: " . $stmt->error;
            }
        }
    }
}

if (isset($_GET['update_id']) && isset($_GET['new_qty'])) {
    $updateId = intval($_GET['update_id']);
    $newQty   = intval($_GET['new_qty']);
    if ($newQty > 0) {
        $stmt = $conn->prepare("UPDATE equipment SET total_quantity = ? WHERE id = ?");
        $stmt->bind_param("ii", $newQty, $updateId);
        if ($stmt->execute()) {
            $success    = "Equipment quantity updated successfully!";
            $currentTab = 'inventory';
        } else {
            $error = "Error updating quantity: " . $stmt->error;
        }
    } else {
        $error = "Quantity must be greater than 0.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_equipment'])) {
    $equipmentIds = $_POST['equip_ids'] ?? [];
    if (!empty($equipmentIds)) {
        $deleteCount = 0;
        foreach ($equipmentIds as $id) {
            $id   = intval($id);
            $stmt = $conn->prepare("DELETE FROM equipment WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) $deleteCount++;
        }
        $success    = "$deleteCount equipment item(s) deleted successfully!";
        $currentTab = 'inventory';
    } else {
        $error = "Please select equipment to delete.";
    }
}

// ============================================================
// EQUIPMENT APPROVAL — 3-step flow
// Step 1 (MMIT_Director / plain super admin): status stays 'Pending'
// Step 2 (Dept_Head): status stays 'Pending'
// Step 3 (VP_Admin): status becomes 'Approved'
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_equipment'])) {
    $bookingId = intval($_POST['booking_id']);

    // Read ALL possible sig/name fields from POST
    $sigMmit  = trim($_POST['sig_mmit_director']  ?? '');
    $nameMmit = trim($_POST['name_mmit_director'] ?? '');
    $sigDept  = trim($_POST['sig_dept_head']       ?? '');
    $nameDept = trim($_POST['name_dept_head']      ?? '');
    $sigVp    = trim($_POST['sig_approved_by']     ?? '');
    $nameVp   = trim($_POST['name_approved_by']    ?? '');

    // Convenience: which sig/name applies to this role
    $mySig  = match($effectiveRole) {
        'Dept_Head' => $sigDept,
        'VP_Admin'  => $sigVp,
        default     => $sigMmit,
    };
    $myName = match($effectiveRole) {
        'Dept_Head' => $nameDept,
        'VP_Admin'  => $nameVp,
        default     => $nameMmit,
    };

    try {
        if (empty($mySig))  throw new Exception("Signature image was not received. Please try uploading again.");
        if (empty($myName)) throw new Exception("Please enter your full name before submitting.");
        if (strpos($mySig, 'data:image') !== 0) throw new Exception("Invalid signature format. Please re-upload your signature image.");

        $bookingStmt = $conn->prepare("
            SELECT b.id, b.user_name, b.equipment_name AS resource_name,
                   b.borrow_date AS booking_date, b.return_date AS end_date,
                   b.start_time, b.end_time, b.purpose, u.email,
                   b.name_mmit_director, b.name_dept_head
            FROM borrows b LEFT JOIN users u ON b.user_id = u.id WHERE b.id = ?");
        $bookingStmt->bind_param("i", $bookingId);
        $bookingStmt->execute();
        $booking = $bookingStmt->get_result()->fetch_assoc();
        if (!$booking) throw new Exception("Equipment request not found.");

        // Only VP_Admin (Step 3) flips status to 'Approved'.
        // Steps 1 and 2 keep status as 'Pending'.
        $newStatus = ($effectiveRole === 'VP_Admin') ? 'Approved' : 'Pending';

        // Build update based on effective role — only write the column for this role
        if ($effectiveRole === 'MMIT_Director') {
            $upd = $conn->prepare("
                UPDATE borrows SET
                    status            = ?,
                    sig_mmit_director  = ?,
                    name_mmit_director = ?
                WHERE id = ?");
            $upd->bind_param("sssi", $newStatus, $sigMmit, $nameMmit, $bookingId);
        } elseif ($effectiveRole === 'Dept_Head') {
            $upd = $conn->prepare("
                UPDATE borrows SET
                    status        = ?,
                    sig_dept_head  = ?,
                    name_dept_head = ?
                WHERE id = ?");
            $upd->bind_param("sssi", $newStatus, $sigDept, $nameDept, $bookingId);
        } elseif ($effectiveRole === 'VP_Admin') {
            $upd = $conn->prepare("
                UPDATE borrows SET
                    status           = ?,
                    sig_approved_by  = ?,
                    name_approved_by = ?
                WHERE id = ?");
            $upd->bind_param("sssi", $newStatus, $sigVp, $nameVp, $bookingId);
        }
        $upd->execute();

        // ── Email helpers ────────────────────────────────────────────────────
        $displayDate = ($booking['booking_date'] === $booking['end_date'])
            ? date('M d, Y', strtotime($booking['booking_date']))
            : date('M d, Y', strtotime($booking['booking_date'])) . ' to ' . date('M d, Y', strtotime($booking['end_date']));
        $displayTime = date('h:i A', strtotime($booking['start_time'])) . ' - ' . date('h:i A', strtotime($booking['end_time']));

        $parsedContact = ''; $parsedDept = ''; $parsedEvent = '';
        if (preg_match('/Contact:\s*([^|]+)/i',    $booking['purpose'], $cm)) $parsedContact = trim($cm[1]);
        if (preg_match('/Department:\s*([^|]+)/i', $booking['purpose'], $dm)) $parsedDept    = trim($dm[1]);
        if (preg_match('/Event:\s*([^|]+)/i',      $booking['purpose'], $em)) $parsedEvent   = trim($em[1]);
        if (!$parsedEvent) {
            $raw = preg_replace('/Contact:\s*[^|]+(\||$)/i',    '', $booking['purpose']);
            $raw = preg_replace('/Department:\s*[^|]+(\||$)/i', '', $raw);
            $parsedEvent = trim(str_replace('|', '', preg_replace('/Purpose:\s*/i', '', $raw)));
        }

        // Fetch fresh row for all sigs after the UPDATE
        $sigRow = $conn->query("SELECT sig_mmit_director, sig_dept_head, sig_approved_by,
                                       name_mmit_director, name_dept_head, name_approved_by
                                FROM borrows WHERE id = " . intval($bookingId))->fetch_assoc();
        $dbSigMmit  = $sigRow['sig_mmit_director'] ?? '';
        $dbSigDept  = $sigRow['sig_dept_head']      ?? '';
        $dbSigVp    = $sigRow['sig_approved_by']    ?? '';
        $dbNameMmit = htmlspecialchars($sigRow['name_mmit_director'] ?? '');
        $dbNameDept = htmlspecialchars($sigRow['name_dept_head']     ?? '');
        $dbNameVp   = htmlspecialchars($sigRow['name_approved_by']   ?? '');

        // Helper: build one signatory cell for HTML email
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

        $detRow = function(string $label, string $value, string $icon = '') {
            if (!$value) return '';
            return "<tr><td style='padding:6px 0;color:#666;font-size:13px;width:38%;'>{$icon} {$label}</td>"
                 . "<td style='padding:6px 0;font-weight:600;font-size:13px;color:#1a1a2e;'>"
                 . htmlspecialchars($value) . "</td></tr>";
        };

        $refNo    = 'AE-' . date('Y') . '-' . str_pad($bookingId, 5, '0', STR_PAD_LEFT);
        $issuedAt = date('F j, Y \a\t h:i A');

        if (!empty($booking['email'])) {

            // ── Fully Approved email (VP step) ────────────────────────────
            if ($newStatus === 'Approved') {
                $c1 = $sigCell('Noted By',    'MMIT Director',                  $dbNameMmit, $dbSigMmit, true);
                $c2 = $sigCell('Verified By', 'Program Head',                   $dbNameDept, $dbSigDept, true);
                $c3 = $sigCell('Approved By', 'VP for Administration & Finance', $dbNameVp,   $dbSigVp,  true);

                $details = $detRow('Equipment',     $booking['resource_name'], '&#128188;')
                         . $detRow('Borrow Date',   $displayDate,              '&#128197;')
                         . $detRow('Time',          $displayTime,              '&#128336;')
                         . $detRow('Purpose/Event', $parsedEvent,              '&#127775;')
                         . $detRow('Department',    $parsedDept,               '&#127979;')
                         . $detRow('Contact',       $parsedContact,            '&#128100;');

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

            // ── Step 1 email (MMIT Director noted) ───────────────────────
            } elseif ($effectiveRole === 'MMIT_Director') {
                $details = $detRow('Equipment',     $booking['resource_name'], '&#128188;')
                         . $detRow('Date',          $displayDate,              '&#128197;')
                         . $detRow('Time',          $displayTime,              '&#128336;')
                         . $detRow('Purpose/Event', $parsedEvent,              '&#127775;')
                         . $detRow('Department',    $parsedDept,               '&#127979;');

                $emailSubject = "[Step 1/3] Equipment Request Noted — {$refNo} | AssetEase";
                $emailBody = "
<html><body style='margin:0;padding:0;background:#f0f2f5;font-family:Arial,Helvetica,sans-serif;'>
<div style='max-width:600px;margin:30px auto 40px;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.12);'>
  <div style='background:#e67e00;padding:22px 30px;text-align:center;'>
    <div style='font-size:11px;color:rgba(255,255,255,.75);letter-spacing:2px;text-transform:uppercase;'>AssetEase &mdash; Request Update</div>
    <div style='font-size:18px;font-weight:700;color:#fff;margin-top:6px;'>&#128338; Step 1 of 3 Complete</div>
    <div style='font-size:12px;color:rgba(255,255,255,.9);margin-top:4px;'>Noted by MMIT Director &mdash; awaiting Program Head verification</div>
  </div>
  <div style='background:#f8f9fa;padding:8px 30px;border-bottom:1px solid #e0e0e0;font-size:12px;color:#555;'>
    <span><strong>Ref:</strong> {$refNo}</span>&nbsp;&nbsp;<span>{$issuedAt}</span>
  </div>
  <div style='padding:24px 30px;'>
    <p style='font-size:14px;color:#333;'>Dear <strong>" . htmlspecialchars($booking['user_name']) . "</strong>,</p>
    <p style='font-size:13px;color:#555;margin:10px 0 16px;'>Your request has been <strong>noted by the MMIT Director</strong> and forwarded to the Program Head for verification.</p>
    <div style='background:#fafafa;border:1px solid #e8e8e8;border-left:4px solid #e67e00;border-radius:8px;padding:14px 18px;margin-bottom:20px;'>
      <table style='width:100%;border-collapse:collapse;'>{$details}</table>
    </div>
    <table style='width:100%;border-collapse:collapse;font-size:12px;border-radius:8px;overflow:hidden;margin-bottom:20px;'>
      <tr>
        <td style='background:#28a745;color:#fff;padding:10px 6px;text-align:center;'>&#10003; Step 1<br><strong>MMIT Director</strong><br><em style='font-weight:400;font-size:11px;'>" . htmlspecialchars($myName) . "</em></td>
        <td style='background:#dee2e6;color:#777;padding:10px 6px;text-align:center;'>&#9744; Step 2<br><strong>Program Head</strong><br><em style='color:#aaa;font-size:11px;'>Pending</em></td>
        <td style='background:#dee2e6;color:#777;padding:10px 6px;text-align:center;'>&#9744; Step 3<br><strong>VP for Administration</strong><br><em style='color:#aaa;font-size:11px;'>Pending</em></td>
      </tr>
    </table>
    <p style='font-size:11px;color:#aaa;text-align:center;margin:0;'>Automated message from AssetEase. Do not reply.</p>
  </div>
  <div style='background:#e67e00;padding:10px 30px;text-align:center;'>
    <span style='color:rgba(255,255,255,.65);font-size:11px;'>AssetEase &mdash; " . date('Y') . "</span>
  </div>
</div></body></html>";
                sendEmail($booking['email'], $emailSubject, $emailBody);

            // ── Step 2 email (Dept Head verified) ────────────────────────
            } elseif ($effectiveRole === 'Dept_Head') {
                $details = $detRow('Equipment',     $booking['resource_name'], '&#128188;')
                         . $detRow('Date',          $displayDate,              '&#128197;')
                         . $detRow('Time',          $displayTime,              '&#128336;')
                         . $detRow('Purpose/Event', $parsedEvent,              '&#127775;');

                $emailSubject = "[Step 2/3] Equipment Request Verified — {$refNo} | AssetEase";
                $emailBody = "
<html><body style='margin:0;padding:0;background:#f0f2f5;font-family:Arial,Helvetica,sans-serif;'>
<div style='max-width:600px;margin:30px auto 40px;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.12);'>
  <div style='background:#1976d2;padding:22px 30px;text-align:center;'>
    <div style='font-size:11px;color:rgba(255,255,255,.75);letter-spacing:2px;text-transform:uppercase;'>AssetEase &mdash; Request Update</div>
    <div style='font-size:18px;font-weight:700;color:#fff;margin-top:6px;'>&#128338; Step 2 of 3 Complete</div>
    <div style='font-size:12px;color:rgba(255,255,255,.9);margin-top:4px;'>Verified by Program Head &mdash; awaiting VP final approval</div>
  </div>
  <div style='padding:24px 30px;'>
    <p style='font-size:14px;color:#333;'>Dear <strong>" . htmlspecialchars($booking['user_name']) . "</strong>,</p>
    <p style='font-size:13px;color:#555;margin:10px 0 16px;'>Your request has been <strong>verified by the Program Head</strong> and forwarded to the VP for Administration for final approval.</p>
    <div style='background:#fafafa;border:1px solid #e8e8e8;border-left:4px solid #1976d2;border-radius:8px;padding:14px 18px;margin-bottom:20px;'>
      <table style='width:100%;border-collapse:collapse;'>{$details}</table>
    </div>
    <table style='width:100%;border-collapse:collapse;font-size:12px;border-radius:8px;overflow:hidden;margin-bottom:20px;'>
      <tr>
        <td style='background:#28a745;color:#fff;padding:10px 6px;text-align:center;'>&#10003; Step 1<br><strong>MMIT Director</strong></td>
        <td style='background:#28a745;color:#fff;padding:10px 6px;text-align:center;'>&#10003; Step 2<br><strong>Program Head</strong><br><em style='font-weight:400;font-size:11px;'>" . htmlspecialchars($myName) . "</em></td>
        <td style='background:#dee2e6;color:#777;padding:10px 6px;text-align:center;'>&#9744; Step 3<br><strong>VP for Administration</strong><br><em style='color:#aaa;font-size:11px;'>Pending</em></td>
      </tr>
    </table>
    <p style='font-size:11px;color:#aaa;text-align:center;margin:0;'>Automated message from AssetEase. Do not reply.</p>
  </div>
  <div style='background:#1976d2;padding:10px 30px;text-align:center;'>
    <span style='color:rgba(255,255,255,.65);font-size:11px;'>AssetEase &mdash; " . date('Y') . "</span>
  </div>
</div></body></html>";
                sendEmail($booking['email'], $emailSubject, $emailBody);
            }
        }

        $stageLabel = match($effectiveRole) {
            'MMIT_Director' => 'noted by MMIT Director — forwarded to Program Head',
            'Dept_Head'     => 'verified by Program Head — forwarded to VP',
            'VP_Admin'      => 'fully approved',
            default         => 'processed',
        };
        $success    = "Equipment request #$bookingId successfully $stageLabel!";
        $currentTab = 'equipment';
    } catch (Exception $e) {
        $error      = "Error: " . $e->getMessage();
        $currentTab = 'equipment';
    }
}

// ============================================================
// REJECT
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_equipment'])) {
    $bookingId    = intval($_POST['booking_id']);
    $rejectReason = trim($_POST['reject_reason'] ?? 'No reason provided');
    try {
        $bookingStmt = $conn->prepare("
            SELECT b.id, b.user_name, b.equipment_name AS resource_name,
                   b.borrow_date AS booking_date, b.return_date AS end_date, u.email
            FROM borrows b LEFT JOIN users u ON b.user_id = u.id WHERE b.id = ?");
        $bookingStmt->bind_param("i", $bookingId);
        $bookingStmt->execute();
        $booking = $bookingStmt->get_result()->fetch_assoc();
        if (!$booking) throw new Exception("Equipment request not found.");

        $upd = $conn->prepare("UPDATE borrows SET status = 'Rejected' WHERE id = ?");
        $upd->bind_param("i", $bookingId);
        $upd->execute();

        $displayDate = ($booking['booking_date'] === $booking['end_date'])
            ? date('M d, Y', strtotime($booking['booking_date']))
            : date('M d, Y', strtotime($booking['booking_date'])) . ' to ' . date('M d, Y', strtotime($booking['end_date']));

        if (!empty($booking['email'])) {
            $emailSubject = "Equipment Request Declined — AssetEase";
            $emailBody    = "<html><body style='font-family:Arial,sans-serif;background:#f5f5f5;padding:20px;'>
                <div style='max-width:600px;margin:auto;'>
                <div style='background:#dc3545;color:white;padding:25px;text-align:center;border-radius:10px 10px 0 0;'>
                <h2 style='margin:0;'>&#9888; Equipment Request Declined</h2></div>
                <div style='padding:25px;background:white;border-radius:0 0 10px 10px;border:1px solid #eee;'>
                <p>Hello <strong>" . htmlspecialchars($booking['user_name']) . "</strong>,</p>
                <p>Your request for <strong>" . htmlspecialchars($booking['resource_name']) . "</strong> on <strong>" . htmlspecialchars($displayDate) . "</strong> has been declined.</p>
                <div style='background:#fff5f5;border-left:4px solid #dc3545;padding:15px;margin:20px 0;'>
                <strong>Reason:</strong> " . htmlspecialchars($rejectReason) . "</div>
                <p>AssetEase Facility Management Team</p></div></div></body></html>";
            sendEmail($booking['email'], $emailSubject, $emailBody);
        }
        $success    = "Equipment request #$bookingId rejected.";
        $currentTab = 'equipment';
    } catch (Exception $e) {
        $error = "Error rejecting request: " . $e->getMessage();
    }
}

// ============================================================
// RETURN
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['return_equipment'])) {
    $bookingId = intval($_POST['booking_id']);
    try {
        $bookingStmt = $conn->prepare("SELECT id, equipment_name AS resource_name FROM borrows WHERE id = ?");
        $bookingStmt->bind_param("i", $bookingId);
        $bookingStmt->execute();
        $booking = $bookingStmt->get_result()->fetch_assoc();
        if (!$booking) throw new Exception("Equipment request not found.");

        $upd = $conn->prepare("UPDATE borrows SET status = 'Returned' WHERE id = ?");
        $upd->bind_param("i", $bookingId);
        $upd->execute();

        $success    = htmlspecialchars($booking['resource_name']) . " marked as returned!";
        $currentTab = 'equipment';
    } catch (Exception $e) {
        $error = "Error processing return: " . $e->getMessage();
    }
}

// ============================================================
// DELETE REQUEST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_request'])) {
    $bookingId = intval($_POST['booking_id']);
    try {
        $del = $conn->prepare("DELETE FROM borrows WHERE id = ?");
        $del->bind_param("i", $bookingId);
        $del->execute();
        $success    = "Equipment request deleted successfully!";
        $currentTab = 'equipment';
    } catch (Exception $e) {
        $error = "Error deleting request: " . $e->getMessage();
    }
}

// ============================================================
// DATA FETCH — Equipment Inventory
// ============================================================
$allEquipment    = [];
$equipmentResult = $conn->query("SELECT id, equipment_name, total_quantity, status FROM equipment ORDER BY id DESC");
if ($equipmentResult) {
    while ($equip = $equipmentResult->fetch_assoc()) {
        $reservedStmt = $conn->prepare("SELECT COUNT(*) as reserved_count FROM borrows WHERE equipment_name = ? AND status IN ('Approved','Pending')");
        $reservedStmt->bind_param("s", $equip['equipment_name']);
        $reservedStmt->execute();
        $reserved  = $reservedStmt->get_result()->fetch_assoc()['reserved_count'] ?? 0;
        $available = max(0, $equip['total_quantity'] - $reserved);
        $allEquipment[] = [
            'id'        => $equip['id'],
            'name'      => $equip['equipment_name'],
            'total'     => $equip['total_quantity'],
            'reserved'  => $reserved,
            'available' => $available,
            'status'    => $equip['status'],
        ];
    }
}

// ── Room reservations ────────────────────────────────────────────────────────
$roomFilter = $_GET['room_filter'] ?? 'Pending';
$roomSql    = "SELECT id, user_name, room_name AS resource_name, booking_date, end_date, start_time, end_time, purpose, status FROM room_reservations WHERE 1";
if ($roomFilter === 'Pending')       $roomSql .= " AND status = 'Pending'";
elseif ($roomFilter === 'Approved')  $roomSql .= " AND status = 'Approved'";
elseif ($roomFilter === 'Rejected')  $roomSql .= " AND status = 'Rejected'";
$roomSql .= " ORDER BY id DESC";
$roomReservations = $conn->query($roomSql);

// ── Equipment borrows — filter & queue per role ──────────────────────────────
$equipFilter = $_GET['equip_filter'] ?? 'Pending';

$equipSql = "SELECT MIN(id) as id, user_name, equipment_name AS resource_name,
                    borrow_date AS booking_date, return_date AS end_date,
                    start_time, end_time, purpose, status, COUNT(*) as quantity,
                    MAX(sig_mmit_director)  as sig_mmit_director,
                    MAX(sig_dept_head)      as sig_dept_head,
                    MAX(sig_approved_by)    as sig_approved_by,
                    MAX(name_mmit_director) as name_mmit_director,
                    MAX(name_dept_head)     as name_dept_head,
                    MAX(name_approved_by)   as name_approved_by
             FROM borrows WHERE 1";

if ($equipFilter === 'Pending') {
    // Each role sees only their own pending queue
    if ($effectiveRole === 'MMIT_Director') {
        // Step 1 queue: no one has signed yet
        $equipSql .= " AND status = 'Pending'
                       AND (name_mmit_director IS NULL OR name_mmit_director = '')";
    } elseif ($effectiveRole === 'Dept_Head') {
        // Step 2 queue: MMIT signed, dept head has not
        $safeProg  = $conn->real_escape_string($userProgramCode ?? '');
        $equipSql .= " AND status = 'Pending'
                       AND name_mmit_director IS NOT NULL AND name_mmit_director != ''
                       AND (name_dept_head IS NULL OR name_dept_head = '')";
        if ($safeProg) $equipSql .= " AND purpose LIKE '%Department: $safeProg%'";
    } elseif ($effectiveRole === 'VP_Admin') {
        // Step 3 queue: MMIT + dept signed, VP has not
        $equipSql .= " AND status = 'Pending'
                       AND name_mmit_director IS NOT NULL AND name_mmit_director != ''
                       AND name_dept_head IS NOT NULL AND name_dept_head != ''
                       AND (name_approved_by IS NULL OR name_approved_by = '')";
    }
} elseif ($equipFilter === 'Approved') {
    $equipSql .= " AND status = 'Approved'";
} elseif ($equipFilter === 'Rejected') {
    $equipSql .= " AND status = 'Rejected'";
} elseif ($equipFilter === 'Returned') {
    $equipSql .= " AND status = 'Returned'";
}
// 'All' → no extra WHERE

$equipSql .= " GROUP BY user_name, equipment_name, borrow_date, return_date,
                         start_time, end_time, purpose, status
               ORDER BY id DESC";
$equipmentBorrows = $conn->query($equipSql);

// ── Statistics ───────────────────────────────────────────────────────────────
$totalRoomBookings = $conn->query("SELECT COUNT(*) as total FROM room_reservations")->fetch_assoc()['total'];
$pendingRooms      = $conn->query("SELECT COUNT(*) as total FROM room_reservations WHERE status = 'Pending'")->fetch_assoc()['total'];
$approvedRooms     = $conn->query("SELECT COUNT(*) as total FROM room_reservations WHERE status = 'Approved'")->fetch_assoc()['total'];
$totalEquipBorrows = $conn->query("SELECT COUNT(*) as total FROM borrows")->fetch_assoc()['total'];
$approvedEquips    = $conn->query("SELECT COUNT(*) as total FROM borrows WHERE status = 'Approved'")->fetch_assoc()['total'];

// Pending count varies by role — mirrors the queue filter above
if ($effectiveRole === 'MMIT_Director') {
    $pendingEquips = (int)$conn->query(
        "SELECT COUNT(*) as total FROM borrows
         WHERE status = 'Pending'
           AND (name_mmit_director IS NULL OR name_mmit_director = '')"
    )->fetch_assoc()['total'];
} elseif ($effectiveRole === 'Dept_Head') {
    $safeProg2     = $conn->real_escape_string($userProgramCode ?? '');
    $deptWhere     = $safeProg2 ? "AND purpose LIKE '%Department: $safeProg2%'" : '';
    $pendingEquips = (int)$conn->query(
        "SELECT COUNT(*) as total FROM borrows
         WHERE status = 'Pending'
           AND name_mmit_director IS NOT NULL AND name_mmit_director != ''
           AND (name_dept_head IS NULL OR name_dept_head = '')
           $deptWhere"
    )->fetch_assoc()['total'];
} elseif ($effectiveRole === 'VP_Admin') {
    $pendingEquips = (int)$conn->query(
        "SELECT COUNT(*) as total FROM borrows
         WHERE status = 'Pending'
           AND name_mmit_director IS NOT NULL AND name_mmit_director != ''
           AND name_dept_head IS NOT NULL AND name_dept_head != ''
           AND (name_approved_by IS NULL OR name_approved_by = '')"
    )->fetch_assoc()['total'];
} else {
    $pendingEquips = (int)$conn->query(
        "SELECT COUNT(*) as total FROM borrows WHERE status = 'Pending'"
    )->fetch_assoc()['total'];
}

// ── Notifications ────────────────────────────────────────────────────────────
$notifStmt = $conn->prepare("SELECT id, message, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
$notifStmt->bind_param("i", $superAdminId);
$notifStmt->execute();
$notifList  = [];
while ($n = $notifStmt->get_result()->fetch_assoc()) $notifList[] = $n;
$notifCount = count($notifList);

// ── Utility functions ─────────────────────────────────────────────────────────
function formatDateRange($s, $e) {
    return $s === $e
        ? date('M d, Y', strtotime($s))
        : date('M d, Y', strtotime($s)) . ' to ' . date('M d, Y', strtotime($e));
}
function formatTimeRange($s, $e) {
    return date('h:i A', strtotime($s)) . ' - ' . date('h:i A', strtotime($e));
}

// JS constant: effective role for the front-end modal logic
$jsEffectiveRole = json_encode($effectiveRole);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard — AssetEase</title>
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
.sidebar-promo a { display:inline-flex; align-items:center; gap:6px; margin-top:9px; padding:6px 14px; background:var(--gold)!important; color:var(--dark)!important; border-radius:7px!important; font-size:11px; font-weight:700; border:none!important; border-left:none!important; margin-left:0!important; }
.sidebar-promo a:hover { opacity:0.9; }

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
.stat-card:nth-child(1) { border-top-color:var(--red); }
.stat-card:nth-child(2) { border-top-color:#f39c12; }
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

/* ── Tabs ─────────────────────────────────────────────────── */
.section-tabs { display:flex; align-items:center; gap:6px; margin-bottom:24px; background:#fff; padding:6px; border-radius:12px; box-shadow:var(--shadow); width:fit-content; }
.section-tab { padding:8px 20px; border-radius:8px; border:none; font-family:'Poppins'; font-size:13px; font-weight:500; color:#777; background:transparent; cursor:pointer; transition:all 0.2s; display:flex; align-items:center; gap:7px; text-decoration:none; }
.section-tab:hover { color:var(--dark); background:var(--bg); }
.section-tab.active { background:var(--red); color:#fff; font-weight:600; box-shadow:0 2px 10px rgba(149,18,44,0.25); }

/* ── Cards & Tables ───────────────────────────────────────── */
.card { background:var(--white); border-radius:var(--radius); padding:24px 26px; box-shadow:var(--shadow); margin-bottom:22px; }
.card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px; }
.card-title { font-size:15px; font-weight:700; color:var(--dark); display:flex; align-items:center; gap:8px; }
.card-title i { color:var(--red); }
.table-controls { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.filter-pill { padding:7px 16px; border-radius:25px; border:1.5px solid #e0d8d5; background:#fff; color:#666; font-size:12.5px; font-weight:500; cursor:pointer; transition:all 0.2s; font-family:'Poppins'; display:flex; align-items:center; gap:6px; text-decoration:none; }
.filter-pill:hover  { border-color:var(--red); color:var(--red); }
.filter-pill.active { background:var(--red); color:#fff; border-color:var(--red); box-shadow:0 2px 8px rgba(149,18,44,0.2); }
.readonly-notice { background:#e7f3ff; border-left:4px solid #17a2b8; padding:12px 16px; border-radius:8px; margin-bottom:18px; color:#004085; font-size:13px; display:flex; align-items:center; gap:8px; }
.table-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; min-width:720px; }
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
.status-Returned { background:#e0f0ff; color:#004085; }
.status-Returned::before { background:#17a2b8; }
.btn-act { padding:5px 11px; border-radius:7px; border:none; font-size:12px; font-weight:600; cursor:pointer; font-family:'Poppins'; transition:all 0.18s; display:inline-flex; align-items:center; gap:5px; }
.btn-approve { background:#edfaf1; color:#1a7a3c; }
.btn-approve:hover { background:#28a745; color:#fff; }
.btn-reject  { background:#fdf0f0; color:#9b1c1c; }
.btn-reject:hover  { background:#dc3545; color:#fff; }
.btn-return  { background:#e0f0ff; color:#004085; }
.btn-return:hover  { background:#17a2b8; color:#fff; }
.btn-delete  { background:#f3f3f3; color:#555; }
.btn-delete:hover  { background:#343a40; color:#fff; }
.btn-inc     { background:#fff8e6; color:#b07800; }
.btn-inc:hover     { background:#ffc107; color:#fff; }
.btn-dec     { background:#fff3ea; color:#c45c00; }
.btn-dec:hover     { background:#fd7e14; color:#fff; }
.btn-sigs    { background:#f0f4ff; color:#4f46e5; border:1px solid #c7d2fe; }
.btn-sigs:hover    { background:#4f46e5; color:#fff; }
.btn-print   { background:#f0fff4; color:#1a7a3c; border:1px solid #86efac; }
.btn-print:hover   { background:#22c55e; color:#fff; }
.qty-badge { display:inline-flex; align-items:center; justify-content:center; background:var(--red); color:#fff; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:700; }

/* ── Signature progress badge ─────────────────────────────── */
.sig-progress-badge { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:6px; font-size:11.5px; font-weight:700; cursor:pointer; transition:all 0.18s; border:1px solid; }
.sig-0of3 { background:#f9fafb; color:#6b7280; border-color:#d1d5db; }
.sig-1of3 { background:#fff7ed; color:#9a3412; border-color:#fdba74; }
.sig-2of3 { background:#eff6ff; color:#1e40af; border-color:#93c5fd; }
.sig-3of3 { background:#f0fdf4; color:#166534; border-color:#86efac; }
.sig-progress-badge:hover { opacity:0.8; }
.sig-waiting-badge { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; background:#f8f9fa; color:#888; border:1px solid #dee2e6; border-radius:6px; font-size:11px; font-style:italic; }

/* ── Form elements ────────────────────────────────────────── */
.form-grid-3 { display:grid; grid-template-columns:1fr 1fr auto; gap:14px; align-items:end; }
.form-group  { display:flex; flex-direction:column; gap:6px; }
.form-group label { font-size:12.5px; font-weight:600; color:#555; }
.form-group input { padding:10px 14px; border:1.5px solid #e0d8d5; border-radius:9px; font-family:'Poppins'; font-size:13px; color:var(--dark); outline:none; transition:border-color 0.2s; background:#fff; }
.form-group input:focus { border-color:var(--red); }
.btn-primary { padding:11px 24px; border-radius:10px; border:none; background:var(--red); color:#fff; font-size:13.5px; font-weight:600; cursor:pointer; font-family:'Poppins'; transition:0.2s; display:flex; align-items:center; gap:8px; white-space:nowrap; }
.btn-primary:hover { background:var(--red-dark); }
.delete-row { display:flex; align-items:center; gap:12px; padding:14px 16px; background:var(--bg); border-radius:10px; margin-top:14px; }
.delete-row label { font-size:13px; font-weight:600; color:#555; display:flex; align-items:center; gap:8px; cursor:pointer; }
.selected-count { font-size:12.5px; color:var(--red); font-weight:600; }
.btn-danger { padding:9px 18px; border-radius:9px; border:none; background:#dc3545; color:#fff; font-size:13px; font-weight:600; cursor:pointer; font-family:'Poppins'; transition:0.2s; display:flex; align-items:center; gap:6px; margin-left:auto; }
.btn-danger:hover { background:#b02a37; }
.avail-chip { font-weight:700; }
.avail-ok   { color:#22c55e; }
.avail-low  { color:#f97316; }
.avail-none { color:#dc3545; }

/* ── Tab content ──────────────────────────────────────────── */
.tab-content        { display:none; }
.tab-content.active { display:block; }
.no-results { text-align:center; padding:40px 20px; color:#ccc; }
.no-results i { font-size:32px; margin-bottom:10px; display:block; color:#ddd; }
.no-results p { font-size:14px; }

/* ── Modals ───────────────────────────────────────────────── */
.modal-overlay { display:none; position:fixed; z-index:1500; inset:0; background:rgba(0,0,0,0.45); align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:#fff; border-radius:18px; padding:32px; max-width:500px; width:92%; box-shadow:0 16px 48px rgba(0,0,0,0.18); animation:modalIn 0.22s ease; max-height:90vh; overflow-y:auto; }
.modal-box-wide { max-width:660px; }
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
.btn-modal-return  { background:#17a2b8; color:#fff; }
.btn-modal-return:hover  { background:#117a8b; }

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
.warn-notice { background:#fffbeb; border:1px solid #fcd34d; border-radius:8px; padding:10px 14px; margin-bottom:16px; font-size:12px; color:#92400e; }

/* ── Print Slip Modal ─────────────────────────────────────── */
.print-slip-wrap { background:#fff; font-family:Arial,Helvetica,sans-serif; }
.print-slip-header { background:#95122C; padding:22px 26px; text-align:center; border-radius:10px 10px 0 0; }
.print-slip-header h2 { color:#fff; font-size:20px; margin:0; }
.print-slip-header .ref { color:rgba(255,255,255,0.7); font-size:12px; margin-top:4px; }
.print-slip-body { padding:20px 26px; border:1px solid #e5e7eb; border-top:none; border-radius:0 0 10px 10px; }
.print-slip-table { width:100%; border-collapse:collapse; margin-bottom:18px; }
.print-slip-table td { padding:7px 0; font-size:13px; }
.print-slip-table .lbl { color:#666; width:35%; }
.print-slip-table .val { font-weight:600; color:#1a1a2e; }
.print-sig-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-top:16px; }
.print-sig-cell { text-align:center; border:1px solid #e5e7eb; border-top:3px solid #28a745; padding:12px 8px; border-radius:6px; background:#f9fafb; }
.print-sig-cell.pending-cell { border-top-color:#dee2e6; background:#f9f9f9; }
.print-sig-cell img { max-width:140px; height:52px; object-fit:contain; display:block; margin:0 auto 6px; }
.print-sig-cell .p-name { font-size:12px; font-weight:700; color:#1a1a2e; }
.print-sig-cell .p-role { font-size:10.5px; color:#666; margin-top:2px; }
.print-sig-cell .p-lbl  { font-size:10px; color:#999; font-style:italic; }
.print-sig-cell .p-status { font-size:10.5px; font-weight:600; margin-bottom:4px; }
.p-status-done { color:#28a745; }
.p-status-pend { color:#aaa; }

@media print {
    body * { visibility:hidden !important; }
    #printSlipContent, #printSlipContent * { visibility:visible !important; }
    #printSlipContent { position:fixed; left:0; top:0; width:100%; padding:20px; background:#fff; }
    .no-print { display:none !important; }
}
@media(max-width:1100px){ .stats-row{grid-template-columns:repeat(2,1fr);} }
@media(max-width:768px){
    :root{--sidebar-w:0px;}
    .sidebar{display:none;}
    .main-wrap{margin-left:0;}
    .content{padding:16px 12px 40px;}
    .top-header{padding:0 14px;height:auto;min-height:60px;flex-wrap:wrap;gap:8px;padding-top:8px;padding-bottom:8px;}
    .header-logo-mini{display:none;}
    .stats-row{grid-template-columns:1fr 1fr;gap:10px;}
    .form-grid-3{grid-template-columns:1fr;}
    .section-tabs{width:100%;}
    .section-tab{flex:1;justify-content:center;font-size:12px;padding:8px 10px;}
    .sig-view-grid{grid-template-columns:1fr;}
    .print-sig-grid{grid-template-columns:1fr;}
}
</style>
</head>
<body>

<!-- ═══════════════════════════════════════════════════════════
     SIDEBAR
════════════════════════════════════════════════════════════ -->
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
        <img src="https://ui-avatars.com/api/?name=<?= urlencode($superAdminName) ?>&background=95122C&color=fff&rounded=true" alt="">
        <div class="admin-badge-info">
            <div class="ab-name"><?= htmlspecialchars(strlen($superAdminName) > 16 ? substr($superAdminName, 0, 16) . '…' : $superAdminName) ?></div>
            <div class="ab-role"><?= htmlspecialchars($signatoryLabel) ?></div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="super_admin_dashboard.php" class="active">
            <div class="nav-icon"><i class="fas fa-table-cells-large"></i></div> Dashboard
        </a>
        <?php if (!$signatoryTitle): ?>
        <a href="super_analytics.php"><div class="nav-icon"><i class="fas fa-chart-bar"></i></div> Analytics</a>
        <a href="super_history.php"><div class="nav-icon"><i class="fas fa-clock-rotate-left"></i></div> History</a>
        <?php endif; ?>
        <hr>
        <div class="nav-section-label">Management</div>
        <a href="create_user.php"><div class="nav-icon"><i class="fas fa-users"></i></div> User Management</a>
        <hr>
        <div class="nav-section-label">System</div>
        <?php if (!$signatoryTitle): ?>
        <a href="super_settings.php"><div class="nav-icon"><i class="fas fa-gear"></i></div> Settings</a>
        <?php endif; ?>
        <a href="logout.php"><div class="nav-icon"><i class="fas fa-right-from-bracket"></i></div> Logout</a>
    </nav>
    <div class="sidebar-promo">
        <strong>Need help?</strong>
        <p>Check the admin guide or contact support for assistance.</p>
        <a href="super_settings.php"><i class="fas fa-arrow-right"></i> Go to Settings</a>
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
                <h1><?php echo match($signatoryTitle) {
                    'MMIT_Director' => 'MMIT Director Panel',
                    'Dept_Head'     => htmlspecialchars($signatoryLabel) . ' Panel',
                    'VP_Admin'      => 'VP Administration Panel',
                    default         => 'Super Admin Dashboard',
                }; ?></h1>
                <p>Welcome back, <strong><?= htmlspecialchars($superAdminName) ?></strong> — <?= date('l, F j, Y') ?></p>
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
            <div class="profile-pill" onclick="window.location='super_settings.php'">
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($superAdminName) ?>&background=95122C&color=fff&rounded=true" alt="">
                <div>
                    <span><?= htmlspecialchars(strlen($superAdminName) > 14 ? substr($superAdminName, 0, 14) . '…' : $superAdminName) ?></span>
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

        <!-- Role info banner -->
        <div style="background:#f0f7ff;border-left:4px solid #1976d2;border-radius:8px;padding:12px 16px;margin-bottom:18px;font-size:13px;color:#1a3a5c;display:flex;align-items:center;gap:10px;">
            <i class="fas fa-id-badge" style="font-size:18px;color:#1976d2;flex-shrink:0;"></i>
            <div><strong>Logged in as: <?= htmlspecialchars($signatoryLabel) ?></strong> &mdash;
            <?php echo match($effectiveRole) {
                'MMIT_Director' => 'You can <strong>Note</strong> new equipment requests (Step 1 of 3). After your signature, requests move to the Program Head for verification.',
                'Dept_Head'     => 'You can <strong>Verify</strong> requests already noted by the MMIT Director (Step 2 of 3). After your signature, they move to the VP for final approval.',
                'VP_Admin'      => 'You can <strong>Finally Approve</strong> requests already noted and verified (Step 3 of 3). Your signature fully releases the request.',
                default         => '',
            }; ?></div>
        </div>

        <!-- Stat cards -->
        <div class="stats-row">
            <?php if (!$signatoryTitle): ?>
            <div class="stat-card">
                <div class="stat-card-body"><div class="sc-label">Total Room Bookings</div><div class="sc-value"><?= $totalRoomBookings ?></div><div class="sc-sub">All time</div></div>
                <div class="stat-icon si-red"><i class="fas fa-door-open"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-body"><div class="sc-label">Pending Rooms</div><div class="sc-value"><?= $pendingRooms ?></div><div class="sc-sub">View only</div></div>
                <div class="stat-icon si-amber"><i class="fas fa-hourglass-half"></i></div>
            </div>
            <?php endif; ?>
            <div class="stat-card">
                <div class="stat-card-body"><div class="sc-label">Total Equipment Requests</div><div class="sc-value"><?= $totalEquipBorrows ?></div><div class="sc-sub">All time</div></div>
                <div class="stat-icon si-green"><i class="fas fa-toolbox"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-body">
                    <div class="sc-label">
                        <?php echo match($effectiveRole) {
                            'MMIT_Director' => 'Awaiting Your Note',
                            'Dept_Head'     => 'Awaiting Your Verification',
                            'VP_Admin'      => 'Awaiting Your Approval',
                            default         => 'Awaiting Your Note',
                        }; ?>
                    </div>
                    <div class="sc-value"><?= $pendingEquips ?></div>
                    <div class="sc-sub">Requires action</div>
                </div>
                <div class="stat-icon si-blue"><i class="fas fa-boxes-stacked"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-body"><div class="sc-label">Approved Equipment</div><div class="sc-value"><?= $approvedEquips ?></div><div class="sc-sub">Fully approved</div></div>
                <div class="stat-icon si-red"><i class="fas fa-circle-check"></i></div>
            </div>
        </div>

        <!-- Section tabs -->
        <div class="section-tabs">
            <?php if (in_array('rooms', $allowedTabs)): ?>
            <button class="section-tab <?= $currentTab === 'rooms' ? 'active' : '' ?>" onclick="switchTab('rooms',this)">
                <i class="fas fa-door-open"></i> Room Reservations
            </button>
            <?php endif; ?>
            <button class="section-tab <?= $currentTab === 'equipment' ? 'active' : '' ?>" onclick="switchTab('equipment',this)">
                <i class="fas fa-toolbox"></i> Equipment Requests
            </button>
            <?php if (in_array('inventory', $allowedTabs)): ?>
            <button class="section-tab <?= $currentTab === 'inventory' ? 'active' : '' ?>" onclick="switchTab('inventory',this)">
                <i class="fas fa-boxes-stacked"></i> Inventory
            </button>
            <?php endif; ?>
        </div>

        <!-- ══════════════════════════════════════════════════
             ROOMS TAB
        ═══════════════════════════════════════════════════ -->
        <?php if (in_array('rooms', $allowedTabs)): ?>
        <div id="rooms" class="tab-content <?= $currentTab === 'rooms' ? 'active' : '' ?>">
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fas fa-door-open"></i> Room Reservations <span style="font-size:11px;font-weight:500;color:#aaa;margin-left:6px;">(View Only)</span></div>
                    <div class="table-controls">
                        <a href="?tab=rooms&room_filter=All"      class="filter-pill <?= $roomFilter==='All'?'active':'' ?>">All</a>
                        <a href="?tab=rooms&room_filter=Pending"  class="filter-pill <?= $roomFilter==='Pending'?'active':'' ?>"><i class="fas fa-hourglass-half" style="font-size:10px;"></i> Pending (<?= $pendingRooms ?>)</a>
                        <a href="?tab=rooms&room_filter=Approved" class="filter-pill <?= $roomFilter==='Approved'?'active':'' ?>"><i class="fas fa-check" style="font-size:10px;"></i> Approved (<?= $approvedRooms ?>)</a>
                        <a href="?tab=rooms&room_filter=Rejected" class="filter-pill <?= $roomFilter==='Rejected'?'active':'' ?>"><i class="fas fa-xmark" style="font-size:10px;"></i> Rejected</a>
                    </div>
                </div>
                <div class="readonly-notice"><i class="fas fa-info-circle"></i><span><strong>Read-Only Mode:</strong> Room reservations are managed by the Admin.</span></div>
                <div class="table-wrap">
                    <table id="roomsTable">
                        <thead><tr><th>User Name</th><th>Room</th><th>Date Range</th><th>Time</th><th>Purpose</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php if ($roomReservations && $roomReservations->num_rows > 0): while ($r = $roomReservations->fetch_assoc()):
                            $rPurp = preg_replace('/Department:\s*[^|]+(\||$)/i', '', $r['purpose']);
                            $rPurp = preg_replace('/Purpose:\s*/i', '', trim(str_replace('|', '', $rPurp)));
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($r['user_name']) ?></strong></td>
                            <td><?= htmlspecialchars($r['resource_name']) ?></td>
                            <td><?= formatDateRange($r['booking_date'], $r['end_date']) ?></td>
                            <td><?= formatTimeRange($r['start_time'], $r['end_time']) ?></td>
                            <td><?= htmlspecialchars(substr($rPurp, 0, 50)) . (strlen($rPurp) > 50 ? '…' : '') ?></td>
                            <td><span class="badge-status status-<?= $r['status'] ?>"><?= $r['status'] ?></span></td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="6"><div class="no-results"><i class="fas fa-calendar-xmark"></i><p>No room reservations found</p></div></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ══════════════════════════════════════════════════
             EQUIPMENT TAB
        ═══════════════════════════════════════════════════ -->
        <div id="equipment" class="tab-content <?= $currentTab === 'equipment' ? 'active' : '' ?>">
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fas fa-toolbox"></i> Equipment Borrow Requests</div>
                    <div class="table-controls">
                        <a href="?tab=equipment&equip_filter=All"      class="filter-pill <?= $equipFilter==='All'?'active':'' ?>">All</a>
                        <a href="?tab=equipment&equip_filter=Pending"  class="filter-pill <?= $equipFilter==='Pending'?'active':'' ?>">
                            <i class="fas fa-hourglass-half" style="font-size:10px;"></i>
                            <?php echo match($effectiveRole) {
                                'MMIT_Director' => 'For Your Note',
                                'Dept_Head'     => 'For Your Verification',
                                'VP_Admin'      => 'For Your Approval',
                                default         => 'For Your Note',
                            }; ?> (<?= $pendingEquips ?>)
                        </a>
                        <a href="?tab=equipment&equip_filter=Approved" class="filter-pill <?= $equipFilter==='Approved'?'active':'' ?>"><i class="fas fa-check" style="font-size:10px;"></i> Approved (<?= $approvedEquips ?>)</a>
                        <a href="?tab=equipment&equip_filter=Returned" class="filter-pill <?= $equipFilter==='Returned'?'active':'' ?>"><i class="fas fa-rotate-left" style="font-size:10px;"></i> Returned</a>
                        <a href="?tab=equipment&equip_filter=Rejected" class="filter-pill <?= $equipFilter==='Rejected'?'active':'' ?>"><i class="fas fa-xmark" style="font-size:10px;"></i> Rejected</a>
                    </div>
                </div>
                <div class="table-wrap">
                    <table id="equipTable">
                        <thead>
                            <tr>
                                <th>User</th><th>Equipment</th><th>Qty</th>
                                <th>Date Range</th><th>Time</th><th>Contact</th><th>Event</th>
                                <th>Signatures</th><th>Status</th><th>Actions</th>
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

                                // Signature completion
                                $mmitDone  = !empty($b['name_mmit_director']);
                                $deptDone  = !empty($b['name_dept_head']);
                                $vpDone    = !empty($b['name_approved_by']);
                                $doneCount = ($mmitDone ? 1 : 0) + ($deptDone ? 1 : 0) + ($vpDone ? 1 : 0);

                                // Is it this user's turn to act?
                                $myTurn = match($effectiveRole) {
                                    'MMIT_Director' => !$mmitDone,
                                    'Dept_Head'     => $mmitDone && !$deptDone,
                                    'VP_Admin'      => $mmitDone && $deptDone && !$vpDone,
                                    default         => !$mmitDone,
                                };

                                $sigBadgeClass = match($doneCount) {
                                    0 => 'sig-0of3',
                                    1 => 'sig-1of3',
                                    2 => 'sig-2of3',
                                    3 => 'sig-3of3',
                                    default => 'sig-0of3',
                                };

                                // Data for JS modals
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
                                <?php if ($b['status'] === 'Pending'): ?>
                                    <?php if ($myTurn): ?>
                                        <button type="button" class="btn-act btn-approve"
                                            onclick="showApproveModal(<?= $b['id'] ?>,'<?= htmlspecialchars(addslashes($b['user_name'])) ?>','<?= htmlspecialchars(addslashes($b['resource_name'])) ?>')">
                                            <i class="fas fa-check"></i>
                                            <?= match($effectiveRole) {
                                                'MMIT_Director' => 'Note',
                                                'Dept_Head'     => 'Verify',
                                                'VP_Admin'      => 'Approve',
                                                default         => 'Note',
                                            } ?>
                                        </button>
                                    <?php else: ?>
                                        <span class="sig-waiting-badge">
                                            <i class="fas fa-hourglass-half"></i>
                                            <?php
                                            if (!$mmitDone)       echo 'Wait MMIT';
                                            elseif (!$deptDone)   echo 'Wait Dept';
                                            else                  echo 'Wait VP';
                                            ?>
                                        </span>
                                    <?php endif; ?>
                                    <button type="button" class="btn-act btn-reject"
                                        onclick="showRejectModal(<?= $b['id'] ?>,'<?= htmlspecialchars(addslashes($b['user_name'])) ?>','<?= htmlspecialchars(addslashes($b['resource_name'])) ?>')">
                                        <i class="fas fa-xmark"></i> Reject
                                    </button>
                                <?php elseif ($b['status'] === 'Approved'): ?>
                                    <button type="button" class="btn-act btn-return"
                                        onclick="showReturnModal(<?= $b['id'] ?>,'<?= htmlspecialchars(addslashes($b['resource_name'])) ?>')">
                                        <i class="fas fa-rotate-left"></i> Return
                                    </button>
                                    <button type="button" class="btn-act btn-print"
                                        onclick='showPrintModal(<?= $jsData ?>)'>
                                        <i class="fas fa-print"></i> Slip
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="btn-act btn-delete"
                                    onclick="showDeleteModal(<?= $b['id'] ?>,'<?= htmlspecialchars(addslashes($b['resource_name'])) ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="10"><div class="no-results"><i class="fas fa-toolbox"></i><p>No equipment requests found</p></div></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════
             INVENTORY TAB
        ═══════════════════════════════════════════════════ -->
        <?php if (in_array('inventory', $allowedTabs)): ?>
        <div id="inventory" class="tab-content <?= $currentTab === 'inventory' ? 'active' : '' ?>">
            <div class="card">
                <div class="card-header"><div class="card-title"><i class="fas fa-plus-circle"></i> Add New Equipment</div></div>
                <form method="POST">
                    <div class="form-grid-3">
                        <div class="form-group">
                            <label>Equipment Name</label>
                            <input type="text" name="equip_name" required placeholder="Enter equipment name">
                        </div>
                        <div class="form-group">
                            <label>Total Quantity</label>
                            <input type="number" name="total_qty" min="1" required placeholder="Enter total quantity">
                        </div>
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="submit" name="add_equipment" class="btn-primary"><i class="fas fa-plus-circle"></i> Add Equipment</button>
                        </div>
                    </div>
                </form>
            </div>
            <div class="card">
                <div class="card-header"><div class="card-title"><i class="fas fa-boxes-stacked"></i> Equipment Inventory</div></div>
                <form method="POST">
                    <div class="table-wrap">
                        <table id="inventoryTable">
                            <thead><tr>
                                <th style="width:40px;"><input type="checkbox" id="selectAll" onchange="toggleAllCheckboxes()" style="width:15px;height:15px;cursor:pointer;accent-color:var(--red);"></th>
                                <th>Equipment Name</th><th>Total Qty</th><th>Reserved</th><th>Available</th><th>Status</th><th>Actions</th>
                            </tr></thead>
                            <tbody>
                            <?php if (!empty($allEquipment)): foreach ($allEquipment as $equip): ?>
                            <tr>
                                <td><input type="checkbox" name="equip_ids[]" value="<?= $equip['id'] ?>" class="equip-checkbox" style="width:15px;height:15px;cursor:pointer;accent-color:var(--red);" onchange="updateSelectedCount()"></td>
                                <td><strong><?= htmlspecialchars($equip['name']) ?></strong></td>
                                <td><?= $equip['total'] ?></td>
                                <td><?= $equip['reserved'] ?></td>
                                <td>
                                    <span class="avail-chip <?= $equip['available'] > 3 ? 'avail-ok' : ($equip['available'] > 0 ? 'avail-low' : 'avail-none') ?>">
                                        <?= $equip['available'] ?>
                                    </span>
                                </td>
                                <td><span class="badge-status status-Approved"><?= htmlspecialchars($equip['status']) ?></span></td>
                                <td>
                                    <a href="?update_id=<?= $equip['id'] ?>&new_qty=<?= $equip['total'] + 1 ?>&tab=inventory" class="btn-act btn-inc" title="Increase"><i class="fas fa-plus"></i></a>
                                    <?php if ($equip['total'] > 1): ?>
                                    <a href="?update_id=<?= $equip['id'] ?>&new_qty=<?= $equip['total'] - 1 ?>&tab=inventory" class="btn-act btn-dec" title="Decrease"><i class="fas fa-minus"></i></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; else: ?>
                            <tr><td colspan="7"><div class="no-results"><i class="fas fa-boxes-stacked"></i><p>No equipment found. Add your first item above.</p></div></td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="delete-row">
                        <label><input type="checkbox" id="selectAllBot" onchange="toggleAllCheckboxes()"> Select All</label>
                        <span class="selected-count" id="selectedCount">0 selected</span>
                        <button type="submit" name="delete_equipment" class="btn-danger"
                            onclick="return confirm('Delete selected? This cannot be undone.')">
                            <i class="fas fa-trash"></i> Delete Selected
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /.content -->
</div><!-- /.main-wrap -->

<!-- ═══════════════════════════════════════════════════════════
     APPROVE MODAL — upload-only signature
     NOTE: action="" posts to this same page; approve_equipment
     is a hidden field (not on the button) so PHP sees it reliably.
════════════════════════════════════════════════════════════ -->
<div id="approveModal" class="modal-overlay">
    <div class="modal-box modal-box-wide">
        <div class="modal-header">
            <h2>
                <i class="fas fa-circle-check" style="color:#28a745;margin-right:8px;"></i>
                <?php echo match($effectiveRole) {
                    'MMIT_Director' => 'Note Request — Step 1 of 3',
                    'Dept_Head'     => 'Verify Request — Step 2 of 3',
                    'VP_Admin'      => 'Final Approval — Step 3 of 3',
                    default         => 'Note Request — Step 1 of 3',
                }; ?>
            </h2>
            <button class="modal-close" onclick="closeApprove()"><i class="fas fa-xmark"></i></button>
        </div>

        <!-- IMPORTANT: approve_equipment is a hidden field, NOT on the submit button,
             so PHP isset($_POST['approve_equipment']) is always true on submit. -->
        <form method="POST" action="" id="approveForm" onsubmit="return validateApprove()">
            <input type="hidden" name="approve_equipment" value="1">

            <div id="approveBkgInfo" class="modal-info"></div>

            <!-- Booking ID -->
            <input type="hidden" id="approveBookingId" name="booking_id" value="">

            <!-- Signature data fields — only the active role's field gets populated via JS -->
            <input type="hidden" id="hiddenSigMmit" name="sig_mmit_director"  value="">
            <input type="hidden" id="hiddenSigDept" name="sig_dept_head"      value="">
            <input type="hidden" id="hiddenSigVp"   name="sig_approved_by"    value="">

            <!-- Name fields for all three roles (only the active role's field will have a value) -->
            <input type="hidden" id="hiddenNameMmit" name="name_mmit_director"  value="">
            <input type="hidden" id="hiddenNameDept" name="name_dept_head"      value="">
            <input type="hidden" id="hiddenNameVp"   name="name_approved_by"    value="">

            <!-- Step progress bar -->
            <?php
            $steps   = ['Step 1 — MMIT Director (Note)', 'Step 2 — Program Head (Verify)', 'Step 3 — VP Admin (Approve)'];
            $stepIdx = match($effectiveRole) { 'Dept_Head' => 1, 'VP_Admin' => 2, default => 0 };
            ?>
            <div style="display:flex;gap:0;margin-bottom:20px;border-radius:8px;overflow:hidden;font-size:12px;">
                <?php foreach ($steps as $i => $lbl): ?>
                <div style="flex:1;padding:8px 6px;text-align:center;
                    background:<?= $i < $stepIdx ? '#28a745' : ($i === $stepIdx ? '#95122C' : '#dee2e6') ?>;
                    color:<?= $i <= $stepIdx ? '#fff' : '#666' ?>;">
                    <?= $i < $stepIdx ? '&#10003; ' : ($i === $stepIdx ? '&#9998; ' : '') ?><?= $lbl ?>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="sig-section">
                <?php
                $roleLabel = match($effectiveRole) {
                    'MMIT_Director' => 'Noted By — MMIT Director',
                    'Dept_Head'     => 'Verified By — Program Head',
                    'VP_Admin'      => 'Approved By — VP for Administration & Finance',
                    default         => 'Noted By — MMIT Director',
                };
                ?>
                <div class="sig-section-title"><i class="fas fa-user-pen"></i> <?= $roleLabel ?></div>

                <!-- Visible name input — JS copies value into the correct hidden field on change -->
                <input type="text" id="sigNameInput"
                    class="sig-name-input"
                    placeholder="Enter your full name and designation"
                    autocomplete="off"
                    oninput="syncNameToHidden(this.value)">

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
                <strong>&#9888; By submitting,</strong> you confirm this request is legitimate and forward it to the next signatory.
            </div>
            <div class="modal-hint">
                <i class="fas fa-envelope"></i> A progress notification will be emailed to the requesting user.
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-modal btn-modal-cancel" onclick="closeApprove()">Cancel</button>
                <button type="submit" class="btn-modal btn-modal-confirm" id="approveSubmitBtn">
                    <i class="fas fa-check"></i>
                    <?= match($effectiveRole) {
                        'MMIT_Director' => 'Confirm &amp; Note',
                        'Dept_Head'     => 'Confirm &amp; Verify',
                        'VP_Admin'      => 'Confirm &amp; Approve',
                        default         => 'Confirm &amp; Note',
                    } ?>
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
        <form method="POST" action="" id="rejectForm">
            <input type="hidden" name="reject_equipment" value="1">
            <div id="rejectBkgInfo" class="modal-info"></div>
            <input type="hidden" id="rejectBookingId" name="booking_id">
            <label style="font-size:13px;font-weight:600;color:var(--dark);display:block;margin-bottom:8px;">
                Reason for Rejection <span style="color:#dc3545;">*</span>
            </label>
            <textarea id="rejectReason" name="reject_reason" required placeholder="Please provide a reason…" class="modal-textarea"></textarea>
            <div class="modal-hint" style="background:#fff5f5;">
                <i class="fas fa-envelope" style="color:#dc3545;"></i>
                A rejection notice will be sent to the user's email.
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

<!-- ═══ RETURN MODAL ═════════════════════════════════════════ -->
<div id="returnModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h2><i class="fas fa-rotate-left" style="color:#17a2b8;margin-right:8px;"></i>Mark as Returned</h2>
            <button class="modal-close" onclick="closeReturn()"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="return_equipment" value="1">
            <div id="returnBkgInfo" class="modal-info"></div>
            <input type="hidden" id="returnBookingId" name="booking_id">
            <div class="modal-hint" style="background:#e0f7fa;">
                <i class="fas fa-info-circle" style="color:#17a2b8;"></i>
                Marking as returned will update equipment availability.
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-modal btn-modal-cancel" onclick="closeReturn()">Cancel</button>
                <button type="submit" class="btn-modal btn-modal-return">
                    <i class="fas fa-rotate-left"></i> Confirm Return
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ DELETE MODAL ═════════════════════════════════════════ -->
<div id="deleteModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h2><i class="fas fa-trash" style="color:#dc3545;margin-right:8px;"></i>Delete Equipment Request</h2>
            <button class="modal-close" onclick="closeDelete()"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="delete_request" value="1">
            <div id="deleteBkgInfo" class="modal-info"></div>
            <input type="hidden" id="deleteBookingId" name="booking_id">
            <div class="modal-hint" style="background:#fff5f5;">
                <i class="fas fa-exclamation-triangle" style="color:#dc3545;"></i>
                <span><strong>Warning:</strong> This cannot be undone. The request will be permanently deleted.</span>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-modal btn-modal-cancel" onclick="closeDelete()">Cancel</button>
                <button type="submit" class="btn-modal btn-modal-confirm" style="background:#dc3545;">
                    <i class="fas fa-trash"></i> Delete Request
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
        <div class="modal-actions" style="margin-top:20px;">
            <button type="button" class="btn-modal btn-modal-cancel" onclick="closeSigView()">Close</button>
        </div>
    </div>
</div>

<!-- ═══ PRINT / BORROW SLIP MODAL ══════════════════════════ -->
<div id="printModal" class="modal-overlay">
    <div class="modal-box modal-box-wide">
        <div class="modal-header">
            <h2><i class="fas fa-print" style="color:#22c55e;margin-right:8px;"></i>Equipment Borrow Slip</h2>
            <button class="modal-close" onclick="closePrintModal()"><i class="fas fa-xmark"></i></button>
        </div>
        <div id="printSlipContent">
            <div class="print-slip-wrap">
                <div class="print-slip-header">
                    <div style="font-size:10px;color:rgba(255,255,255,.65);letter-spacing:2px;text-transform:uppercase;">AssetEase — Facility &amp; Equipment Management</div>
                    <h2>FELLOWSHIP BAPTIST COLLEGE</h2>
                    <div style="display:inline-block;background:#28a745;color:#fff;font-size:12px;font-weight:700;padding:4px 16px;border-radius:20px;margin-top:8px;">&#10003; FULLY APPROVED</div>
                    <div class="ref" id="slipRefNo"></div>
                </div>
                <div class="print-slip-body">
                    <table class="print-slip-table">
                        <tr><td class="lbl">Borrower</td><td class="val" id="slipUser"></td></tr>
                        <tr><td class="lbl">Equipment</td><td class="val" id="slipEquip"></td></tr>
                        <tr><td class="lbl">Date</td><td class="val" id="slipDate"></td></tr>
                        <tr><td class="lbl">Time</td><td class="val" id="slipTime"></td></tr>
                        <tr><td class="lbl">Purpose / Event</td><td class="val" id="slipEvent"></td></tr>
                        <tr><td class="lbl">Department</td><td class="val" id="slipDept"></td></tr>
                        <tr><td class="lbl">Contact</td><td class="val" id="slipContact"></td></tr>
                    </table>
                    <div style="font-size:11px;font-weight:700;color:#95122C;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;">&#128394; Official Signatories</div>
                    <div class="print-sig-grid" id="slipSigGrid"></div>
                    <div style="background:#fff8e1;border:1px solid #ffe082;border-radius:8px;padding:10px 14px;font-size:12px;color:#5d4037;margin-top:16px;">
                        <strong>&#9888; Reminder:</strong> This slip is valid only for the dates and equipment listed. Return in good condition.
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
/* ── Role constant (PHP → JS) ──────────────────────────────── */
const CURRENT_ROLE = <?= $jsEffectiveRole ?>;

/* ── Which hidden sig/name fields to populate for this role ── */
function getSigHiddenId()  {
    switch (CURRENT_ROLE) {
        case 'Dept_Head': return 'hiddenSigDept';
        case 'VP_Admin':  return 'hiddenSigVp';
        default:          return 'hiddenSigMmit';
    }
}
function getNameHiddenId() {
    switch (CURRENT_ROLE) {
        case 'Dept_Head': return 'hiddenNameDept';
        case 'VP_Admin':  return 'hiddenNameVp';
        default:          return 'hiddenNameMmit';
    }
}

/* ── Sync visible name input → correct hidden field ─────────── */
function syncNameToHidden(val) {
    const el = document.getElementById(getNameHiddenId());
    if (el) el.value = val;
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
        // Store in the correct hidden field for this role
        document.getElementById(getSigHiddenId()).value = dataUrl;
        // Show preview
        document.getElementById('sigPreviewImg').src       = dataUrl;
        document.getElementById('sigPreviewWrap').classList.add('visible');
        document.getElementById('sigUploadArea').classList.add('has-file');
        document.getElementById('sigUploadText').textContent = '✓ ' + file.name;
    });
}

function clearSig() {
    document.getElementById(getSigHiddenId()).value = '';
    document.getElementById('sigPreviewImg').src       = '';
    document.getElementById('sigPreviewWrap').classList.remove('visible');
    document.getElementById('sigUploadArea').classList.remove('has-file');
    document.getElementById('sigUploadText').textContent = 'Click to upload your signature image';
    document.getElementById('sigFileReal').value = '';
}

/* ── Approve form validation ───────────────────────────────── */
function validateApprove() {
    // Make sure name hidden field is synced before submit
    const nameEl = document.getElementById('sigNameInput');
    syncNameToHidden(nameEl ? nameEl.value.trim() : '');

    if (!nameEl || !nameEl.value.trim()) {
        alert('Please enter your full name before submitting.');
        if (nameEl) nameEl.focus();
        return false;
    }
    const sigEl = document.getElementById(getSigHiddenId());
    if (!sigEl || !sigEl.value || !sigEl.value.startsWith('data:image')) {
        alert('Please upload your signature image before submitting.');
        return false;
    }
    // Prevent double-submit
    const btn = document.getElementById('approveSubmitBtn');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting…';
    }
    return true;
}

/* ── Tab switching ─────────────────────────────────────────── */
function switchTab(tab, btn) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.section-tab').forEach(el => el.classList.remove('active'));
    document.getElementById(tab).classList.add('active');
    btn.classList.add('active');
    window.history.replaceState({}, '', '?tab=' + tab);
}

/* ── Global search ─────────────────────────────────────────── */
function globalSearch(q) {
    q = q.toLowerCase();
    ['roomsTable', 'equipTable', 'inventoryTable'].forEach(id => {
        const tbl = document.getElementById(id);
        if (tbl) tbl.querySelectorAll('tbody tr').forEach(tr => {
            tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
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

/* ── Inventory checkboxes ──────────────────────────────────── */
function toggleAllCheckboxes() {
    const master = document.getElementById('selectAll').checked
                || document.getElementById('selectAllBot').checked;
    document.querySelectorAll('.equip-checkbox').forEach(cb => cb.checked = master);
    updateSelectedCount();
}
function updateSelectedCount() {
    document.getElementById('selectedCount').textContent =
        document.querySelectorAll('.equip-checkbox:checked').length + ' selected';
}

/* ── Approve Modal ─────────────────────────────────────────── */
function showApproveModal(id, user, equip) {
    document.getElementById('approveBookingId').value = id;
    document.getElementById('approveBkgInfo').innerHTML =
        '<strong>Request #' + id + '</strong> &mdash; <em>' + user + '</em><br>Equipment: <strong>' + equip + '</strong>';

    // Clear visible name input
    const nameInput = document.getElementById('sigNameInput');
    if (nameInput) nameInput.value = '';

    // Clear ALL hidden sig + name fields
    ['hiddenSigMmit','hiddenSigDept','hiddenSigVp'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    ['hiddenNameMmit','hiddenNameDept','hiddenNameVp'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });

    // Reset upload UI
    document.getElementById('sigPreviewImg').src = '';
    document.getElementById('sigPreviewWrap').classList.remove('visible');
    document.getElementById('sigUploadArea').classList.remove('has-file');
    document.getElementById('sigUploadText').textContent = 'Click to upload your signature image';
    document.getElementById('sigFileReal').value = '';

    // Re-enable submit button
    const btn = document.getElementById('approveSubmitBtn');
    if (btn) {
        btn.disabled = false;
        const labels = {
            'MMIT_Director': 'Confirm &amp; Note',
            'Dept_Head':     'Confirm &amp; Verify',
            'VP_Admin':      'Confirm &amp; Approve',
        };
        btn.innerHTML = '<i class="fas fa-check"></i> ' + (labels[CURRENT_ROLE] || 'Confirm &amp; Note');
    }

    document.getElementById('approveModal').classList.add('open');
}
function closeApprove() { document.getElementById('approveModal').classList.remove('open'); }

/* ── Reject Modal ──────────────────────────────────────────── */
function showRejectModal(id, user, equip) {
    document.getElementById('rejectBookingId').value = id;
    document.getElementById('rejectBkgInfo').innerHTML =
        '<strong>Request #' + id + '</strong> &mdash; <em>' + user + '</em><br>Equipment: <strong>' + equip + '</strong>';
    document.getElementById('rejectReason').value = '';
    document.getElementById('rejectModal').classList.add('open');
}
function closeReject() { document.getElementById('rejectModal').classList.remove('open'); }

/* ── Return Modal ──────────────────────────────────────────── */
function showReturnModal(id, equip) {
    document.getElementById('returnBookingId').value = id;
    document.getElementById('returnBkgInfo').innerHTML =
        '<strong>Return Equipment</strong><br>' + equip + ' — Request #' + id;
    document.getElementById('returnModal').classList.add('open');
}
function closeReturn() { document.getElementById('returnModal').classList.remove('open'); }

/* ── Delete Modal ──────────────────────────────────────────── */
function showDeleteModal(id, equip) {
    document.getElementById('deleteBookingId').value = id;
    document.getElementById('deleteBkgInfo').innerHTML =
        '<strong>Delete Request #' + id + '</strong><br>Equipment: ' + equip;
    document.getElementById('deleteModal').classList.add('open');
}
function closeDelete() { document.getElementById('deleteModal').classList.remove('open'); }

/* ── Signature View / Progress Modal ──────────────────────── */
function showSigViewModal(data) {
    const slots = [
        { sig: data.sigMmit, name: data.nameMmit },
        { sig: data.sigDept, name: data.nameDept },
        { sig: data.sigVp,   name: data.nameVp   },
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
            + doneCount + ' of 3 signatures collected. Awaiting remaining signatories.</span>';
    }

    document.getElementById('sigViewModal').classList.add('open');
}
function closeSigView() { document.getElementById('sigViewModal').classList.remove('open'); }

/* ── Print / Borrow Slip Modal ─────────────────────────────── */
function showPrintModal(data) {
    const refNo = 'AE-' + new Date().getFullYear() + '-' + String(data.id).padStart(5, '0');

    document.getElementById('slipRefNo').textContent   = 'Ref No.: ' + refNo;
    document.getElementById('slipUser').textContent    = data.user;
    document.getElementById('slipEquip').textContent   = data.equipment;
    document.getElementById('slipDate').textContent    = data.date;
    document.getElementById('slipTime').textContent    = data.time;
    document.getElementById('slipEvent').textContent   = data.event   || '—';
    document.getElementById('slipDept').textContent    = data.dept    || '—';
    document.getElementById('slipContact').textContent = data.contact || '—';

    const sigSlots = [
        { label: 'Noted By',    role: 'MMIT Director',                   sig: data.sigMmit, name: data.nameMmit },
        { label: 'Verified By', role: 'Program Head',                    sig: data.sigDept, name: data.nameDept },
        { label: 'Approved By', role: 'VP for Administration & Finance',  sig: data.sigVp,   name: data.nameVp   },
    ];
    let sigHtml = '';
    sigSlots.forEach(s => {
        const done = !!(s.sig && s.sig.length > 0);
        sigHtml += `<div class="print-sig-cell ${done ? '' : 'pending-cell'}">
            <div class="p-status ${done ? 'p-status-done' : 'p-status-pend'}">${done ? '&#10003; Signed' : '&#9744; Pending'}</div>
            ${done
                ? `<img src="${s.sig}" alt="Signature">`
                : `<div style="height:52px;border-bottom:1.5px dashed #ccc;margin-bottom:6px;"></div>`
            }
            <div class="p-name">${s.name || '___________'}</div>
            <div class="p-role">${s.role}</div>
            <div class="p-lbl">${s.label}</div>
        </div>`;
    });
    document.getElementById('slipSigGrid').innerHTML = sigHtml;
    document.getElementById('printModal').classList.add('open');
}
function closePrintModal() { document.getElementById('printModal').classList.remove('open'); }

/* ── Close any modal on overlay click ─────────────────────── */
window.addEventListener('click', e => {
    if (e.target.classList.contains('modal-overlay')) {
        closeApprove(); closeReject(); closeReturn();
        closeDelete(); closeSigView(); closePrintModal();
    }
});
</script>
</body>
</html>