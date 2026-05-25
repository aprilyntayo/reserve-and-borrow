<?php
    /**
     * User Dashboard - Room & Equipment Booking with Dynamic Equipment Section
     * Features: Multiple Equipment Items, Notifications, Chat Bot, Email Notifications, Database Validation, SweetAlert
     */
    session_start();
    require_once 'config.php';
    require_once 'notification_handler.php';
    require_once 'email_notification.php';

    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }

    if (isset($_SESSION['role']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'super_admin')) {
        header("Location: admin_dashboard.php");
        exit;
    }

    $userId = $_SESSION['user_id'];
    $minDate = date('Y-m-d'); // Defined to prevent undefined variable notice in HTML inputs

    // CRITICAL FIX: Fetch user's current name and email directly from database to prevent stale session data
    $userStmt = $conn->prepare("SELECT uname, email FROM users WHERE id = ?");
    $userStmt->bind_param("i", $userId);
    $userStmt->execute();
    $userResult = $userStmt->get_result();
    $userRow = $userResult->fetch_assoc();

    $userName = $userRow['uname'] ?? 'User';
    $userEmail = $userRow['email'] ?? '';

    // Implement PRG Pattern: Retrieve and clear success message from session if it exists
    $success = '';
    if (isset($_SESSION['success'])) {
        $success = $_SESSION['success'];
        unset($_SESSION['success']);
    }
    $error = '';

    // Mark all notifications as read when dropdown is opened
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_notifications_read'])) {
        $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        exit; // Prevent further execution on AJAX notification mark
    }

    // Fetch unread notifications
    $stmt = $conn->prepare("SELECT id, message, created_at, is_read FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $notificationsResult = $stmt->get_result();
    $notifications = [];
    $unread_count = 0;

    while ($notif = $notificationsResult->fetch_assoc()) {
        $notifications[] = $notif;
        if (!isset($notif['is_read']) || $notif['is_read'] == 0) {
            $unread_count++;
        }
    }

    // ==========================================
    // HANDLE ROOM RESERVATION SUBMISSION
    // ==========================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_room_reservation'])) {
        $roomDept = trim($_POST['room_department'] ?? '');
        $roomDeptOther = trim($_POST['room_department_other'] ?? '');
        $finalDept = ($roomDept === 'Others') ? $roomDeptOther : $roomDept;
        
        $roomType = trim($_POST['room_type'] ?? '');
        $roomTypeOther = trim($_POST['room_reservation_other'] ?? '');
        $finalRoomType = ($roomType === 'Others') ? $roomTypeOther : $roomType;
        $bookingType = $_POST['room_booking_type'] ?? 'single';
        $startDate = $_POST['room_start_date'] ?? '';
        $endDate = ($bookingType === 'single') ? $startDate : ($_POST['room_end_date'] ?? '');
        $startTime = $_POST['room_start_time'] ?? '';
        $endTime = $_POST['room_end_time'] ?? '';
        $roomPurpose = trim($_POST['room_purpose'] ?? '');

        $displayDate = ($startDate === $endDate) ? 
                    date('M d, Y', strtotime($startDate)) : 
                    date('M d, Y', strtotime($startDate)) . ' to ' . date('M d, Y', strtotime($endDate));
        $displayTime = date('h:i A', strtotime($startTime)) . ' - ' . date('h:i A', strtotime($endTime));
        
        $formattedPurpose = $roomPurpose;

        if (empty($roomType) || empty($finalRoomType) || empty($startDate) || empty($startTime) || empty($endTime) || ($bookingType === 'multiple' && empty($endDate))) {
            $error = "Please fill in all required room reservation fields.";
        } elseif ($roomType === 'Others' && empty($roomTypeOther)) {
            $error = "Please specify the room name when selecting Others.";
        } elseif ($bookingType === 'multiple' && empty($endDate)) {
            $error = "Please select an end date for a multi-day booking.";
        } else {
            // Validation check for room booking overlap
            $checkAvailStmt = $conn->prepare("SELECT COUNT(*) as conflict_count FROM room_reservations WHERE room_name = ? AND (status = 'Approved' OR status = 'Pending') AND booking_date <= ? AND end_date >= ? AND start_time < ? AND end_time > ?");
            $checkAvailStmt->bind_param("sssss", $finalRoomType, $endDate, $startDate, $endTime, $startTime);
            $checkAvailStmt->execute();
            $availResult = $checkAvailStmt->get_result();
            $availRow = $availResult->fetch_assoc();
            
            if ($availRow['conflict_count'] > 0) {
                $error = "This room is not available for the selected date and time. Please choose a different date or room.";
            } else {
                try {
                    $conn->begin_transaction();
                    
                    $stmt = $conn->prepare("INSERT INTO room_reservations (user_id, user_name, room_name, booking_date, end_date, start_time, end_time, purpose, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
                    $stmt->bind_param("isssssss", $userId, $userName, $finalRoomType, $startDate, $endDate, $startTime, $endTime, $formattedPurpose);
                    $stmt->execute();

                    // Create app notification
                    $notifMsg = "Your room reservation for $finalRoomType on $displayDate has been submitted and is awaiting approval.";
                    $stmtNotif = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
                    $stmtNotif->bind_param("is", $userId, $notifMsg);
                    $stmtNotif->execute();
                    
                    // Send email notification for Room
                    $emailSubject = "Room Reservation Submitted - AssetEase";
                    $emailBody = <<<HTML
                    <html>
                    <body style='font-family:Arial; background: #f5f5f5;'>
                        <div style='max-width:600px;margin:auto;'>
                            <div style='background:#95122C;color:white;padding:25px;text-align:center;border-radius:10px 10px 0 0;'>
                                <h2 style='margin:0;'>Room Reservation Submitted</h2>
                            </div>
                            <div style='padding:25px;background:white;border-radius:0 0 10px 10px;border:1px solid #eee;'>
                                <p style='color:#333;'>Hello <strong>$userName</strong>,</p>
                                <p style='color:#666;'>Your room reservation has been submitted successfully. Here are the details:</p>
                                <table style='width:100%;border-collapse:collapse;margin:20px 0;' border='0' cellpadding='12'>
                                    <tr style='background:#f9f9f9;'>
                                        <td style='border-bottom:1px solid #eee;font-weight:bold;color:#95122C;'>Room Type</td>
                                        <td style='border-bottom:1px solid #eee;'>$finalRoomType</td>
                                    </tr>
                                    <tr style='background:#f9f9f9;'>
                                        <td style='border-bottom:1px solid #eee;font-weight:bold;color:#95122C;'>Department</td>
                                        <td style='border-bottom:1px solid #eee;'>$finalDept</td>
                                    </tr>
                                    <tr>
                                        <td style='border-bottom:1px solid #eee;font-weight:bold;color:#95122C;'>Date Range</td>
                                        <td style='border-bottom:1px solid #eee;'>$displayDate</td>
                                    </tr>
                                    <tr style='background:#f9f9f9;'>
                                        <td style='border-bottom:1px solid #eee;font-weight:bold;color:#95122C;'>Time</td>
                                        <td style='border-bottom:1px solid #eee;'>$displayTime</td>
                                    </tr>
                                    <tr>
                                        <td style='font-weight:bold;color:#95122C;'>Purpose</td>
                                        <td>$roomPurpose</td>
                                    </tr>
                                </table>
                                <p style='color:#666;margin-top:20px;'>Your reservation is awaiting admin approval. You will be notified once it's approved or rejected.</p>
                                <p style='color:#666;margin-top:20px;'>Thank you for using AssetEase,<br><strong>AssetEase Booking System</strong></p>
                            </div>
                        </div>
                    
        <!-- Bottom navigation for mobile -->
        <nav class="bottom-nav">
        <div class="bottom-nav-inner">
            <a href="dashboard.php"><i class="fas fa-house"></i>Home</a>
            <a href="reservation_form.php" class="active"><i class="fas fa-plus-circle"></i>Reserve</a>
            <a href="view_reservations.php"><i class="fas fa-list-check"></i>Bookings</a>
            <a href="user_history.php"><i class="fas fa-history"></i>History</a>
            <a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
        </div>
        </nav>

    </body>
                    </html>
HTML;
                    
                    sendEmail($userEmail, $emailSubject, $emailBody);
                    $conn->commit();
                    
                    // Set session success message and redirect
                    $_SESSION['success'] = "Room reservation submitted successfully! You'll be notified once it's approved.";
                    header("Location: dashboard.php");
                    exit;
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "Error submitting reservation: " . $e->getMessage();
                }
            }
        }
    }

    // ==========================================
    // HANDLE EQUIPMENT REQUEST SUBMISSION
    // ==========================================
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_equipment_request'])) {
        $equipDept = trim($_POST['equip_department'] ?? '');
        $equipDeptOther = trim($_POST['equip_department_other'] ?? '');
        $finalDept = ($equipDept === 'others') ? $equipDeptOther : strtoupper($equipDept);
        
        $bookingType = $_POST['equip_booking_type'] ?? 'single';
        $startDate = $_POST['equip_start_date'] ?? '';
        $endDate = ($bookingType === 'single') ? $startDate : ($_POST['equip_end_date'] ?? '');
        $startTime = $_POST['equip_start_time'] ?? '';
        $endTime = $_POST['equip_end_time'] ?? '';
        $equipPurpose = trim($_POST['equip_purpose'] ?? '');
        $equipContact = trim($_POST['equip_contact_number'] ?? '');
        // Embed contact number into purpose for storage (no schema change needed)
        $formattedPurposeBase = ($equipContact ? "Contact: $equipContact | " : '') . "Department: $finalDept | Event: $equipPurpose";

        $equipNames = $_POST['equipment_name'] ?? [];
        $equipQuantities = $_POST['equipment_quantity'] ?? [];

        $displayDate = ($startDate === $endDate) ? 
                    date('M d, Y', strtotime($startDate)) : 
                    date('M d, Y', strtotime($startDate)) . ' to ' . date('M d, Y', strtotime($endDate));
        $displayTime = date('h:i A', strtotime($startTime)) . ' - ' . date('h:i A', strtotime($endTime));

        if (empty($startDate) || empty($startTime) || empty($endTime)) {
            $error = "Please fill in all required equipment fields.";
        } else {
            try {
                $conn->begin_transaction();
                $bookedItemsList = [];
                
                foreach ($equipNames as $index => $eqName) {
                    $eqQty = (int)$equipQuantities[$index];
                    if (empty($eqName) || $eqQty <= 0) continue;

                    // 1. Get overlapping reservations
                    $checkAvailStmt = $conn->prepare("SELECT COUNT(*) as reserved_count FROM borrows WHERE equipment_name = ? AND (status = 'Approved' OR status = 'Pending') AND borrow_date <= ? AND return_date >= ? AND start_time < ? AND end_time > ?");
                    $checkAvailStmt->bind_param("sssss", $eqName, $endDate, $startDate, $endTime, $startTime);
                    $checkAvailStmt->execute();
                    $availResult = $checkAvailStmt->get_result();
                    $reservedQty = $availResult->fetch_assoc()['reserved_count'] ?? 0;

                    // 2. Get total inventory
                    $totStmt = $conn->prepare("SELECT total_quantity FROM equipment WHERE equipment_name = ?");
                    $totStmt->bind_param("s", $eqName);
                    $totStmt->execute();
                    $totResult = $totStmt->get_result();
                    $totalQty = $totResult->fetch_assoc()['total_quantity'] ?? 0;

                    // 3. Verify real-time availability constraint
                    if (($totalQty - $reservedQty) < $eqQty) {
                        throw new Exception("Not enough '$eqName' available for the selected date and time.");
                    }

                    // 4. Insert records
                    for ($j = 0; $j < $eqQty; $j++) {
                        $formattedPurpose = $formattedPurposeBase;
                        $stmt = $conn->prepare("INSERT INTO borrows (user_id, user_name, equipment_name, borrow_date, return_date, start_time, end_time, purpose, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
                        $stmt->bind_param("isssssss", $userId, $userName, $eqName, $startDate, $endDate, $startTime, $endTime, $formattedPurpose);
                        $stmt->execute();
                    }
                    
                    $bookedItemsList[] = "<b>$eqQty x</b> $eqName";
                }

                if (empty($bookedItemsList)) {
                    throw new Exception("Please select at least one valid item.");
                }

                $itemsString = implode(", ", $bookedItemsList);
                $cleanItemsString = strip_tags($itemsString);

                // Create notification
                $notifMsg = "Your equipment request for ($cleanItemsString) on $displayDate has been submitted and is awaiting approval.";
                $stmtNotif = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
                $stmtNotif->bind_param("is", $userId, $notifMsg);
                $stmtNotif->execute();

                // Send email notification specifically for Equipment
                $emailSubject = "Equipment Request Submitted - AssetEase";
                $emailBody = <<<HTML
                <html>
                <body style='font-family:Arial; background: #f5f5f5;'>
                    <div style='max-width:600px;margin:auto;'>
                        <div style='background:#95122C;color:white;padding:25px;text-align:center;border-radius:10px 10px 0 0;'>
                            <h2 style='margin:0;'>Equipment Request Submitted</h2>
                        </div>
                        <div style='padding:25px;background:white;border-radius:0 0 10px 10px;border:1px solid #eee;'>
                            <p style='color:#333;'>Hello <strong>$userName</strong>,</p>
                            <p style='color:#666;'>Your equipment request has been submitted successfully. Here are the details:</p>
                            <table style='width:100%;border-collapse:collapse;margin:20px 0;' border='0' cellpadding='12'>
                                <tr style='background:#f9f9f9;'>
                                    <td style='border-bottom:1px solid #eee;font-weight:bold;color:#95122C;'>Items Requested</td>
                                    <td style='border-bottom:1px solid #eee;'>$itemsString</td>
                                </tr>
                                <tr>
                                    <td style='border-bottom:1px solid #eee;font-weight:bold;color:#95122C;'>Department</td>
                                    <td style='border-bottom:1px solid #eee;'>$finalDept</td>
                                </tr>
                                <tr style='background:#f9f9f9;'>
                                    <td style='border-bottom:1px solid #eee;font-weight:bold;color:#95122C;'>Contact Number</td>
                                    <td style='border-bottom:1px solid #eee;'>$equipContact</td>
                                </tr>
                                <tr>
                                    <td style='border-bottom:1px solid #eee;font-weight:bold;color:#95122C;'>Event Date</td>
                                    <td style='border-bottom:1px solid #eee;'>$displayDate</td>
                                </tr>
                                <tr style='background:#f9f9f9;'>
                                    <td style='border-bottom:1px solid #eee;font-weight:bold;color:#95122C;'>Time</td>
                                    <td style='border-bottom:1px solid #eee;'>$displayTime</td>
                                </tr>
                                <tr>
                                    <td style='font-weight:bold;color:#95122C;'>Event Name</td>
                                    <td>$equipPurpose</td>
                                </tr>
                            </table>
                            <p style='color:#666;margin-top:20px;'>Your reservation is awaiting admin approval. You will be notified once it's approved or rejected.</p>
                            <p style='color:#666;margin-top:20px;'>Thank you for using AssetEase,<br><strong>AssetEase Booking System</strong></p>
                        </div>
                    </div>
                
        <!-- Bottom navigation for mobile -->
        <nav class="bottom-nav">
        <div class="bottom-nav-inner">
            <a href="dashboard.php"><i class="fas fa-house"></i>Home</a>
            <a href="reservation_form.php" class="active"><i class="fas fa-plus-circle"></i>Reserve</a>
            <a href="view_reservations.php"><i class="fas fa-list-check"></i>Bookings</a>
            <a href="user_history.php"><i class="fas fa-history"></i>History</a>
            <a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
        </div>
        </nav>

    </body>
</html>
HTML;
                
                sendEmail($userEmail, $emailSubject, $emailBody);
                $conn->commit();

                // Set session success message and redirect
                $_SESSION['success'] = "Equipment request submitted successfully! You'll be notified once it's approved.";
                header("Location: dashboard.php");
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }


    // Fetch upcoming room reservations for calendar display (approved + pending)
    $roomCalendarData = [];
    $calStmt = $conn->query("SELECT room_name, booking_date, end_date, start_time, end_time, status, user_name FROM room_reservations WHERE (status = 'Approved' OR status = 'Pending') AND end_date >= CURDATE() ORDER BY booking_date ASC");
    if ($calStmt) {
        while ($cr = $calStmt->fetch_assoc()) {
            $roomCalendarData[] = $cr;
        }
    }

    // Fetch available equipment for the forms
    $availableEquipment = [];
    $equipResult = $conn->query("SELECT equipment_name, total_quantity FROM equipment WHERE status = 'Available'");
    if ($equipResult && $equipResult->num_rows > 0) {
        while ($equip = $equipResult->fetch_assoc()) {
            $eName = $equip['equipment_name'];
                
            $resStmt = $conn->prepare("SELECT SUM(quantity) as reserved_qty FROM borrows WHERE equipment_name = ? AND (status = 'Approved' OR status = 'Pending')");
            $resStmt->bind_param("s", $eName);
            $resStmt->execute();
            $resCount = $resStmt->get_result()->fetch_assoc()['reserved_qty'] ?? 0;
            
            $availableEquipment[] = [
                'name' => $eName,
                'available' => max(0, $equip['total_quantity'] - $resCount)
            ];
        }
    }
    ?>

    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Unified Reservation Dashboard - AssetEase</title>
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <style>
            :root {
                --primary-red: #95122C;
                --primary-dark: #100C08;
                --sidebar-bg: #1a0a0f;
                --sidebar-hover: rgba(255,255,255,0.08);
                --sidebar-active-bar: #FFD700;
                --bg-color: #F5EFED;
                --white: #ffffff;
                --shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
                --sidebar-width: 260px;
            }

            * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
            body { background: var(--bg-color); display: flex; min-height: 100vh; }

            /* ===================== SIDEBAR ===================== */
            .sidebar {
                width: var(--sidebar-width);
                height: 100vh;
                background: linear-gradient(180deg, #100C08 0%, #95122C 100%);
                color: var(--white);
                position: fixed;
                z-index: 1001;
                display: flex;
                flex-direction: column;
                overflow: hidden;
            }

            .sidebar-header {
                padding: 28px 25px 22px;
                display: flex;
                align-items: center;
                gap: 12px;
                border-bottom: 1px solid rgba(255,255,255,0.1);
                flex-shrink: 0;
            }
            .sidebar-header .logo-image {
                width: 42px;
                height: 42px;
                object-fit: cover;
                border-radius: 10px;
                background: #fff;
                padding: 2px;
                flex-shrink: 0;
            }
            .sidebar-header h2 {
                color: #FFD700;
                font-weight: 700;
                font-size: 20px;
                letter-spacing: 1.5px;
            }

            /* Nav sections */
            .sidebar-nav {
                flex: 1;
                overflow-y: auto;
                padding: 20px 0 10px;
                scrollbar-width: none;
            }
            .sidebar-nav::-webkit-scrollbar { display: none; }

            .nav-section-label {
                font-size: 10px;
                font-weight: 600;
                letter-spacing: 2px;
                color: rgba(255,255,255,0.45);
                padding: 18px 25px 6px;
                text-transform: uppercase;
            }

            .sidebar a {
                display: flex;
                align-items: center;
                gap: 14px;
                padding: 13px 25px;
                color: rgba(255,255,255,0.8);
                text-decoration: none;
                font-size: 0.9rem;
                font-weight: 500;
                border-left: 3px solid transparent;
                transition: all 0.25s ease;
                margin: 1px 0;
            }
            .sidebar a:hover {
                background: rgba(255,255,255,0.10);
                color: #ffffff;
                border-left-color: rgba(255,255,255,0.4);
            }
            .sidebar a.active {
                background: rgba(255,255,255,0.15);
                color: #fff;
                border-left-color: #FFD700;
                font-weight: 600;
            }
            .sidebar a .nav-icon {
                width: 32px;
                font-size: 16px;
                opacity: 0.85;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
                background: none;
                border-radius: 0;
                height: auto;
            }
            .sidebar a.active .nav-icon { opacity: 1; }
            .sidebar a:hover .nav-icon { opacity: 1; }

            .nav-divider {
                border: none;
                border-top: 1px solid rgba(255,255,255,0.1);
                margin: 10px 20px;
            }

            /* Dark mode toggle */
            .dark-mode-toggle {
                display: flex;
                align-items: center;
                gap: 14px;
                padding: 13px 25px;
                color: rgba(255,255,255,0.8);
                font-size: 0.9rem;
                font-weight: 500;
                border-left: 3px solid transparent;
                cursor: pointer;
                transition: all 0.25s ease;
                margin: 1px 0;
            }
            .dark-mode-toggle:hover {
                background: rgba(255,255,255,0.10);
                color: #ffffff;
                border-left-color: rgba(255,255,255,0.4);
            }
            .dark-mode-toggle .nav-icon {
                width: 32px;
                font-size: 16px;
                opacity: 0.85;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
                background: none;
                border-radius: 0;
            }

            /* Help card at bottom */
            .sidebar-footer {
                padding: 15px;
                flex-shrink: 0;
            }
            .help-card {
                background: linear-gradient(135deg, rgba(255,215,0,0.15), rgba(255,140,0,0.1));
                border: 1px solid rgba(255,215,0,0.25);
                border-radius: 14px;
                padding: 16px;
            }
            .help-card p.help-title {
                font-weight: 700;
                font-size: 13px;
                color: #FFD700;
                margin-bottom: 4px;
            }
            .help-card p.help-desc {
                font-size: 11px;
                color: rgba(255,255,255,0.6);
                line-height: 1.5;
                margin-bottom: 12px;
            }
            .help-card a {
                display: block;
                background: #FFD700;
                color: #1a0a0f !important;
                text-align: center;
                padding: 9px 14px;
                border-radius: 9px;
                font-size: 12px;
                font-weight: 700;
                text-decoration: none;
                border-left: none !important;
                transition: opacity 0.2s;
            }
            .help-card a:hover {
                opacity: 0.88;
                background: #FFD700 !important;
                border-left-color: transparent !important;
            }

            /* ===================== LAYOUT ===================== */
            .content { margin-left: var(--sidebar-width); padding: 90px 36px 40px; width: calc(100% - var(--sidebar-width)); }

            /* ===================== TOP HEADER ===================== */
            .top-header {
                position: fixed; top: 0; right: 0;
                width: calc(100% - var(--sidebar-width));
                background: var(--white);
                display: flex; justify-content: space-between; align-items: center;
                padding: 14px 36px; z-index: 1000;
                box-shadow: 0 1px 0 rgba(0,0,0,0.07);
            }
            .header-left h1 { font-size: 22px; font-weight: 700; color: var(--primary-dark); }
            .header-left p { font-size: 13px; color: #888; margin-top: 1px; }
            .header-right { display: flex; align-items: center; gap: 12px; }

            .search-box {
                display: flex; align-items: center; gap: 10px;
                background: #F5EFED; border-radius: 25px; padding: 9px 18px;
                width: 240px; border: 1.5px solid transparent; transition: 0.2s;
            }
            .search-box:focus-within { border-color: var(--primary-red); background: #fff; }
            .search-box i { color: #aaa; font-size: 14px; }
            .search-box input { border: none; background: transparent; font-size: 13px; font-family: 'Poppins'; color: var(--primary-dark); outline: none; width: 100%; }
            .search-box input::placeholder { color: #bbb; }

            .notif-wrap { position: relative; }
            .notif-btn {
                width: 42px; height: 42px; border-radius: 50%; background: #F5EFED; border: none;
                display: flex; align-items: center; justify-content: center; cursor: pointer;
                color: var(--primary-dark); font-size: 17px; transition: 0.2s;
            }
            .notif-btn:hover { background: #ede6e3; }
            .notif-badge {
                position: absolute; top: -2px; right: -2px; background: #dc3545; color: #fff;
                font-size: 10px; font-weight: 700; width: 18px; height: 18px;
                border-radius: 50%; display: flex; align-items: center; justify-content: center;
                border: 2px solid #fff;
            }
            .notif-dropdown {
                position: absolute; top: 52px; right: 0; width: 340px; background: #fff;
                border-radius: 14px; box-shadow: 0 8px 30px rgba(0,0,0,0.12); z-index: 2000;
                display: none; max-height: 380px; overflow-y: auto;
            }
            .notif-dropdown.active { display: block; }
            .notif-header-label { padding: 14px 18px 10px; font-weight: 700; font-size: 13px; color: var(--primary-dark); border-bottom: 1px solid #f0f0f0; }
            .notif-item { padding: 12px 18px; border-bottom: 1px solid #f8f8f8; cursor: pointer; }
            .notif-item:hover { background: #fafafa; }
            .notif-item .ni-msg { font-size: 13px; color: #444; line-height: 1.4; }
            .notif-item .ni-time { font-size: 11px; color: #bbb; margin-top: 3px; }
            .notif-empty { padding: 24px; text-align: center; color: #bbb; font-size: 13px; }

            .profile-pill {
                display: flex; align-items: center; gap: 10px;
                background: #F5EFED; border-radius: 30px; padding: 6px 14px 6px 8px;
                cursor: pointer; transition: 0.2s;
            }
            .profile-pill:hover { background: #ede6e3; }
            .profile-pill img { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; border: 2px solid var(--primary-red); }
            .profile-pill span { font-size: 13px; font-weight: 600; color: var(--primary-dark); }

            /* ===================== CARDS & FORMS ===================== */
            .card { background: var(--white); padding: 30px; border-radius: 15px; box-shadow: var(--shadow); margin-bottom: 25px; }
            h3 { color: var(--primary-red); margin-bottom: 20px; }
            .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 20px; }
            .form-group { display: flex; flex-direction: column; }
            .form-label { font-weight: 600; margin-bottom: 8px; color: var(--primary-dark); font-size: 14px; }
            .form-input, .form-select, .form-textarea { width: 100%; padding: 12px; border: 2px solid #EEE; border-radius: 8px; font-family: 'Poppins'; font-size: 14px; }
            .form-input:focus, .form-select:focus, .form-textarea:focus { outline: none; border-color: var(--primary-red); }
            .toggle-group { display: flex; gap: 10px; margin-bottom: 20px; }
            .toggle-btn { flex: 1; padding: 12px; border: 2px solid #EEE; background: white; border-radius: 8px; cursor: pointer; font-weight: 600; transition: all 0.3s ease; color: #666; }
            .toggle-btn.active { background: var(--primary-red); color: white; border-color: var(--primary-red); }
            .btn-primary, .btn-success { color: #fff; padding: 14px 28px; border: none; border-radius: 10px; cursor: pointer; font-weight: 700; font-size: 16px; box-shadow: var(--shadow); transition: 0.3s; width: 100%; }
            .btn-primary { background: linear-gradient(135deg, var(--primary-red) 0%, #7a0c23 100%); }
            .btn-success { background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%); }
            .btn-primary:hover:not(:disabled), .btn-success:hover:not(:disabled) { opacity: 0.9; }
            .btn-primary:disabled, .btn-success:disabled { opacity: 0.6; cursor: not-allowed; }
            .equipment-item { background: #f9f9f9; padding: 20px; border-radius: 10px; margin-bottom: 20px; border: 2px solid #EEE; position: relative; }
            .availability-status { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; margin-top: 8px;}
            .available { background: #d4edda; color: #155724; }
            .unavailable { background: #f8d7da; color: #721c24; border: 2px solid #721c24; }
            .equipment-row { display: grid; grid-template-columns: 2fr 1fr auto; gap: 15px; align-items: flex-end; }
            .equipment-item-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
            .equipment-item-number { font-weight: 700; color: var(--primary-red); }
            .btn-remove-item { background: #dc3545; color: white; border: none; padding: 8px 15px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; transition: 0.3s; }
            .btn-remove-item:hover { background: #c82333; }
            .btn-add-equipment { background: var(--primary-red); color: white; border: none; padding: 12px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; margin-bottom: 20px; transition: 0.3s; }
            .btn-add-equipment:hover { opacity: 0.9; }

            /* ===================== CHATBOT ===================== */
            .chat-toggle { position: fixed; bottom: 20px; right: 20px; width: 60px; height: 60px; background: var(--primary-red); color: white; border: none; border-radius: 50%; cursor: pointer; font-size: 24px; z-index: 999; box-shadow: var(--shadow); transition: 0.3s; }
            .chat-toggle:hover { transform: scale(1.1); }
            .chat-bot { position: fixed; bottom: 90px; right: 20px; width: 380px; height: 550px; background: white; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); z-index: 999; display: none; flex-direction: column; overflow: hidden; }
            .chat-bot.active { display: flex; }
            .chat-header { background: var(--primary-red); color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center; border-radius: 15px 15px 0 0; }
            .chat-header h3 { font-size: 16px; font-weight: 700; }
            .chat-header button { background: none; border: none; color: white; cursor: pointer; font-size: 20px; }
            .chat-messages { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 12px; background: #f9f9f9; }
            .message { padding: 10px 15px; border-radius: 10px; max-width: 85%; word-wrap: break-word; font-size: 13px; line-height: 1.4; }
            .message.user { background: var(--primary-red); color: white; align-self: flex-end; }
            .message.bot { background: #e8e8e8; color: #333; align-self: flex-start; }
            .chat-input-area { display: flex; gap: 10px; padding: 15px; border-top: 1px solid #eee; background: white; }
            .chat-input-area input { flex: 1; padding: 10px 15px; border: 1px solid #ddd; border-radius: 8px; font-size: 13px; }
            .chat-input-area button { background: var(--primary-red); color: white; border: none; padding: 10px 18px; border-radius: 8px; cursor: pointer; font-weight: 600; }

            .hidden { display: none; }

            /* ===================== MOBILE RESPONSIVE ===================== */
            .mobile-menu-btn {
                display: none;
                position: fixed;
                top: 13px;
                left: 14px;
                z-index: 1200;
                background: var(--primary-red);
                color: #fff;
                border: none;
                width: 44px;
                height: 44px;
                border-radius: 10px;
                font-size: 20px;
                cursor: pointer;
                align-items: center;
                justify-content: center;
                box-shadow: 0 2px 8px rgba(0,0,0,0.3);
            }
            .sidebar-overlay {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,0.5);
                z-index: 1100;
            }
            .sidebar-overlay.open { display: block; }
            .bottom-nav {
                display: none;
                position: fixed;
                bottom: 0; left: 0; right: 0;
                background: linear-gradient(90deg, #100C08 0%, #95122C 100%);
                z-index: 1050;
                box-shadow: 0 -2px 10px rgba(0,0,0,0.25);
            }
            .bottom-nav-inner { display: flex; width: 100%; }
            .bottom-nav a {
                flex: 1; display: flex; flex-direction: column; align-items: center;
                justify-content: center; color: rgba(255,255,255,0.65);
                text-decoration: none; padding: 10px 0 8px; font-size: 10px; gap: 3px;
                transition: color 0.2s; font-family: 'Poppins', sans-serif;
            }
            .bottom-nav a i { font-size: 18px; }
            .bottom-nav a.active, .bottom-nav a:hover { color: #FFD700; }

            /* ===================== ROOM CALENDAR ===================== */
            .room-type-wrapper {
                position: relative;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .room-type-wrapper .form-select {
                flex: 1;
            }
            .room-cal-btn {
                flex-shrink: 0;
                width: 44px;
                height: 44px;
                background: var(--primary-red);
                color: #fff;
                border: none;
                border-radius: 8px;
                font-size: 16px;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: 0.2s;
                box-shadow: 0 2px 6px rgba(149,18,44,0.3);
            }
            .room-cal-btn:hover { background: #7a0c23; }
            .room-cal-popup {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 320px;
                background: #fff;
                border-radius: 14px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.18);
                z-index: 3000;
                padding: 16px;
                border: 1px solid #eee;
            }
            .room-cal-popup.open { display: block; }
            .room-cal-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 12px;
            }
            .room-cal-header span {
                font-weight: 700;
                font-size: 14px;
                color: var(--primary-dark);
            }
            .room-cal-nav {
                background: none;
                border: 1px solid #ddd;
                border-radius: 6px;
                width: 28px; height: 28px;
                cursor: pointer;
                color: #555;
                display: flex; align-items: center; justify-content: center;
                font-size: 12px;
                transition: 0.2s;
            }
            .room-cal-nav:hover { background: var(--primary-red); color: #fff; border-color: var(--primary-red); }
            .room-cal-legend {
                display: flex;
                align-items: center;
                gap: 12px;
                font-size: 11px;
                color: #666;
                margin-bottom: 10px;
            }
            .room-cal-summary {
                font-size: 12px;
                color: #444;
                margin-bottom: 10px;
                line-height: 1.4;
            }
            .rcl-dot {
                display: inline-block;
                width: 10px; height: 10px;
                border-radius: 50%;
                margin-right: 3px;
            }
            .rcl-approved { background: #16a34a; }
            .rcl-pending  { background: #ca8a04; }
            .rcl-free     { background: #e0e0e0; }
            .room-cal-days-head {
                display: grid;
                grid-template-columns: repeat(7, 1fr);
                text-align: center;
                font-size: 11px;
                font-weight: 600;
                color: #999;
                margin-bottom: 4px;
            }
            .room-cal-grid {
                display: grid;
                grid-template-columns: repeat(7, 1fr);
                gap: 3px;
            }
            .rc-day {
                aspect-ratio: 1;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 6px;
                font-size: 12px;
                font-weight: 500;
                color: #333;
                cursor: default;
                position: relative;
                transition: 0.15s;
            }
            .rc-day.empty { background: transparent; cursor: default; }
            .rc-day.past  { color: #ccc; }
            .rc-day.today {
                background: #fef2f2;
                color: var(--primary-red);
                font-weight: 700;
                border: 1.5px solid var(--primary-red);
            }
            .rc-day.has-approved {
                background: #dcfce7;
                color: #15803d;
                cursor: pointer;
                font-weight: 600;
            }
            .rc-day.has-pending {
                background: #fef9c3;
                color: #92400e;
                cursor: pointer;
                font-weight: 600;
            }
            .rc-day.has-both {
                background: linear-gradient(135deg, #dcfce7 50%, #fef9c3 50%);
                cursor: pointer;
                font-weight: 600;
            }
            .rc-day:hover:not(.empty):not(.past) {
                transform: scale(1.12);
                box-shadow: 0 2px 8px rgba(0,0,0,0.12);
                z-index: 1;
            }
            .room-cal-detail {
                margin-top: 12px;
                min-height: 40px;
                border-top: 1px solid #f0f0f0;
                padding-top: 10px;
            }
            .rcd-item {
                display: flex;
                align-items: flex-start;
                gap: 8px;
                font-size: 12px;
                color: #444;
                padding: 6px 8px;
                border-radius: 7px;
                margin-bottom: 5px;
                background: #fafafa;
                border-left: 3px solid #ccc;
            }
            .rcd-item.approved { border-left-color: #16a34a; background: #f0fdf4; }
            .rcd-item.pending  { border-left-color: #ca8a04; background: #fefce8; }
            .rcd-item i { margin-top: 2px; flex-shrink: 0; }
            .rcd-item .rcd-info { flex: 1; }
            .rcd-item .rcd-room { font-weight: 700; color: var(--primary-dark); }
            .rcd-item .rcd-time { color: #888; font-size: 11px; }
            .rcd-empty { font-size: 12px; color: #aaa; text-align: center; padding: 8px 0; }

            @media (max-width: 768px) {
                .sidebar {
                    transform: translateX(-260px);
                    transition: transform 0.3s ease;
                    z-index: 1150 !important;
                }
                .sidebar.open { transform: translateX(0); }
                .mobile-menu-btn { display: flex; }
                .content {
                    margin-left: 0 !important;
                    width: 100% !important;
                    padding-bottom: 80px !important;
                }
                .top-header {
                    left: 0 !important;
                    right: 0 !important;
                    width: 100% !important;
                    padding: 11px 14px 11px 68px !important;
                }
                .header-left h1 { font-size: 16px !important; }
                .header-left p { font-size: 11px !important; }
                .search-box { display: none !important; }
                .profile-pill span { display: none !important; }
                .profile-pill { padding: 4px !important; gap: 0 !important; }
                .bottom-nav { display: block; }
                .form-row { grid-template-columns: 1fr !important; }
                .equipment-row { grid-template-columns: 1fr !important; }
                .card { padding: 16px !important; }
                .chat-toggle { bottom: 72px !important; right: 14px !important; }
                .chat-bot {
                    width: calc(100vw - 24px) !important;
                    right: 12px !important;
                    left: 12px !important;
                    bottom: 130px !important;
                }
                .notif-dropdown { width: min(300px, 90vw) !important; }
                .room-cal-popup {
                    width: min(310px, 92vw);
                }
            }

        </style>
    </head>
    <body>

        <!-- Mobile menu button -->
        <button class="mobile-menu-btn" id="mobileMenuBtn" onclick="toggleMobileSidebar()"><i class="fas fa-bars"></i></button>
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleMobileSidebar()"></div>

        <!-- ===================== SIDEBAR ===================== -->
        <div class="sidebar">
            <div class="sidebar-header">
                <img src="image/logo.png" alt="AssetEase Logo" class="logo-image">
                <h2>ASSETEASE</h2>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-section-label">Main</div>

                <a href="dashboard.php" class="active">
                    <div class="nav-icon"><i class="fas fa-table-cells-large"></i></div>
                    Overview
                </a>
                    <a href="reservation_form.php"><i class="fas fa-plus-circle"></i> Reserve and Request</a>
                <a href="view_reservations.php">
                    <div class="nav-icon"><i class="fas fa-list-check"></i></div>
                    My Reservations
                </a>

                <div class="nav-divider"></div>
                <div class="nav-section-label">Support</div>

                <a href="notification.php">
                    <div class="nav-icon"><i class="fas fa-bell"></i></div>
                    Notifications
                </a>
                <a href="settings.php">
                    <div class="nav-icon"><i class="fas fa-gear"></i></div>
                    Settings
                </a>

                <div class="dark-mode-toggle" onclick="toggleDarkMode()">
                    <div class="nav-icon"><i class="fas fa-moon" id="darkModeIcon"></i></div>
                    <span id="darkModeLabel">Dark Mode</span>
                </div>

                <a href="logout.php">
                    <div class="nav-icon"><i class="fas fa-right-from-bracket"></i></div>
                    Logout
                </a>
            </nav>

            <div class="sidebar-footer">
                <div class="help-card">
                    <p class="help-title">Need help?</p>
                    <p class="help-desc">Contact admin or check our help center for assistance.</p>
                    <a href="settings.php">Go to Settings</a>
                </div>
            </div>
        </div>

        <!-- ===================== TOP HEADER ===================== -->
        <div class="top-header">
            <div class="header-left">
                <h1>Reserve &amp; Request</h1>
                <p>Book a room or borrow equipment for your activities.</p>
            </div>
            <div class="header-right">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" placeholder="Search rooms or equipment...">
                </div>
                <!-- Notification Bell -->
                <div class="notif-wrap">
                    <button class="notif-btn" onclick="toggleNotifications(event)">
                        <i class="fas fa-bell"></i>
                        <?php if($unread_count > 0): ?>
                            <span class="notif-badge" id="notificationBadge"><?= $unread_count ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="notif-dropdown" id="notifDropdown">
                        <div class="notif-header-label">Notifications</div>
                        <?php if (!empty($notifications)): ?>
                            <?php foreach($notifications as $notif): ?>
                                <div class="notif-item">
                                    <div class="ni-msg"><?= htmlspecialchars($notif['message']) ?></div>
                                    <div class="ni-time"><?= date('M d, h:i A', strtotime($notif['created_at'])) ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="notif-empty"><i class="fas fa-bell-slash" style="font-size:24px;display:block;margin-bottom:8px;"></i>No notifications yet</div>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- Profile Pill -->
                <div class="profile-pill">
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($userName) ?>&background=95122C&color=fff&rounded=true" alt="">
                    <span><?= htmlspecialchars(strlen($userName) > 12 ? substr($userName,0,12).'…' : $userName) ?></span>
                </div>
            </div>
        </div>

        <!-- ===================== MAIN CONTENT ===================== -->
        <div class="content">
            <h1 style="margin-bottom: 10px; color: var(--primary-dark);">Make a Reservation</h1>
            <p style="color: #666; margin-bottom: 30px;">Book a room or equipment for your activities seamlessly.</p>

            <div class="card">
                <div class="toggle-group">
                    <button type="button" class="toggle-btn active" id="btn-room" onclick="switchTab('room')">
                        <i class="fas fa-door-open"></i> Room Reservation
                    </button>
                    <button type="button" class="toggle-btn" id="btn-equipment" onclick="switchTab('equipment')">
                        <i class="fas fa-laptop"></i> Equipment Request
                    </button>
                </div>

                <div id="form-room">
                    <form action="" method="POST">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Department</label>
                                <select name="room_department" id="room_department" class="form-select" onchange="toggleRoomOtherDept()" required>
                                    <option value="">Select Department</option>
                                    <option value="CECS">CECS</option>
                                    <option value="CHBA">CHBA</option>
                                    <option value="CTEAS">CTEAS</option>
                                    <option value="CCJE">CCJE</option>
                                    <option value="AHC">AHC</option>
                                    <option value="Others">Others (Please Specify)</option>
                                </select>
                            </div>
                            <div class="form-group hidden" id="room_dept_other_group">
                                <label class="form-label">Specify Department</label>
                                <input type="text" name="room_department_other" class="form-input" placeholder="Enter department name">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Room Type</label>
                                <div class="room-type-wrapper">
                                    <select name="room_type" id="room_type" class="form-select" onchange="toggleRoomTypeOther(); updateRoomCalendar();" required>
                                        <option value="">Select Room</option>
                                        <option value="CL1">CL1</option>
                                        <option value="CL2">CL2</option>
                                        <option value="CL3">CL3</option>
                                        <option value="AVR">AVR</option>
                                        <option value="SB202">SB202</option>
                                        <option value="Others">Others (Please Specify)</option>
                                    </select>
                                    <button type="button" class="room-cal-btn" id="roomCalBtn" onclick="toggleRoomCalendar(event)" title="View room availability calendar">
                                        <i class="fas fa-calendar-alt"></i>
                                    </button>
                                </div>
                                <!-- Room Availability Calendar Popup -->
                                <div class="room-cal-popup" id="roomCalPopup">
                                    <div class="room-cal-header">
                                        <button type="button" class="room-cal-nav" onclick="changeCalMonth(-1)"><i class="fas fa-chevron-left"></i></button>
                                        <span id="roomCalMonthLabel"></span>
                                        <button type="button" class="room-cal-nav" onclick="changeCalMonth(1)"><i class="fas fa-chevron-right"></i></button>
                                    </div>
                                    <div class="room-cal-legend">
                                        <span class="rcl-dot rcl-approved"></span>Approved
                                        <span class="rcl-dot rcl-pending"></span>Pending
                                        <span class="rcl-dot rcl-free"></span>Available
                                    </div>
                                    <div class="room-cal-summary" id="roomCalSummary"></div>
                                    <div class="room-cal-days-head">
                                        <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
                                    </div>
                                    <div class="room-cal-grid" id="roomCalGrid"></div>
                                    <div class="room-cal-detail" id="roomCalDetail"></div>
                                </div>
                            </div>
                            <div class="form-group hidden" id="room_reserve_other_group">
                                <label class="form-label">Specify Room</label>
                                <input type="text" name="room_reservation_other" class="form-input" placeholder="Enter room name" />
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Booking Type</label>
                                <select name="room_booking_type" id="room_booking_type" class="form-select" onchange="toggleEndDate('room')" required>
                                    <option value="single">Single Day</option>
                                    <option value="multiple">Multiple Days</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="room_start_date" class="form-input" min="<?= $minDate ?>" required>
                            </div>
                            <div class="form-group hidden" id="room_end_date_group">
                                <label class="form-label">End Date</label>
                                <input type="date" name="room_end_date" class="form-input" min="<?= $minDate ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Start Time</label>
                                <input type="time" name="room_start_time" class="form-input" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">End Time</label>
                                <input type="time" name="room_end_time" class="form-input" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Purpose</label>
                            <textarea name="room_purpose" class="form-textarea" rows="3" placeholder="Briefly describe the purpose of the reservation..." required></textarea>
                        </div>

                        <button type="submit" name="submit_room_reservation" class="btn-primary" style="margin-top: 20px;">
                            Submit Room Reservation
                        </button>
                    </form>
                </div>

                <div id="form-equipment" class="hidden">
                    <form action="" method="POST" id="equipmentForm">
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Name</label>
                                <input type="text" class="form-input" value="<?= htmlspecialchars($userName) ?>" readonly style="background:#f5f5f5;color:#888;cursor:not-allowed;">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Contact Number <span style="color:var(--primary-red);">*</span></label>
                                <input type="tel" name="equip_contact_number" class="form-input" placeholder="e.g. 09xxxxxxxxx" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Department</label>
                                <select name="equip_department" id="equip_department" class="form-select" onchange="toggleOtherDept()" required>
                                    <option value="">Select Department</option>
                                    <option value="CECS">CECS</option>
                                    <option value="CHBA">CHBA</option>
                                    <option value="AHC">AHC</option>
                                    <option value="CTEAS">CTEAS</option>
                                    <option value="CCJE">CCJE</option>
                                    <option value="others">Others</option>
                                </select>
                            </div>
                            <div class="form-group hidden" id="equip_dept_other_group">
                                <label class="form-label">Specify Department</label>
                                <input type="text" name="equip_department_other" class="form-input" placeholder="Enter department name">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Booking Type</label>
                                <select name="equip_booking_type" id="equip_booking_type" class="form-select" onchange="toggleEndDate('equip')" required>
                                    <option value="single">Single Day</option>
                                    <option value="multiple">Multiple Days</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="equip_start_date" class="form-input" min="<?= $minDate ?>" required>
                            </div>
                            <div class="form-group hidden" id="equip_end_date_group">
                                <label class="form-label">End Date</label>
                                <input type="date" name="equip_end_date" class="form-input" min="<?= $minDate ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Start Time</label>
                                <input type="time" name="equip_start_time" class="form-input" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">End Time</label>
                                <input type="time" name="equip_end_time" class="form-input" required>
                            </div>
                        </div>

                        <div style="margin-top: 30px; border-top: 1px solid #eee; padding-top: 20px;">
                            <h3 style="color: var(--primary-dark); font-size: 18px; margin-bottom: 15px;">Requested Items</h3>
                            <div id="equipment-container"></div>
                            <button type="button" class="btn-add-equipment" onclick="addEquipmentRow()">
                                <i class="fas fa-plus"></i> Add Another Item
                            </button>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Event Name <span style="color:var(--primary-red);">*</span></label>
                        </div>

                        <button type="submit" name="submit_equipment_request" class="btn-primary" style="margin-top: 20px;">
                            Submit Equipment Request
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- ===================== CHATBOT ===================== -->
        <button class="chat-toggle" onclick="toggleChat()"><i class="fas fa-comments"></i></button>
        <div class="chat-bot" id="chatBot">
            <div class="chat-header">
                <h3>AssetEase Assistant</h3>
                <button onclick="toggleChat()"><i class="fas fa-times"></i></button>
            </div>
            <div class="chat-messages" id="chatMessages">
                <div class="message bot">Hello! How can I help you with your reservations today?</div>
            </div>
            <div class="chat-input-area">
                <input type="text" id="chatInput" placeholder="Type your message..." onkeypress="handleChatEnter(event)">
                <button onclick="sendMessage()"><i class="fas fa-paper-plane"></i></button>
            </div>
        </div>

        <script>
            const availableEquipment = <?= json_encode($availableEquipment) ?>;

            // Room reservation data for calendar
            const roomReservations = <?= json_encode($roomCalendarData) ?>;

            let equipmentCount = 0;
            const roomCalState = {
                currentMonth: new Date(),
                selectedDate: null,
                selectedRoom: ''
            };

            const monthNames = [
                'January', 'February', 'March', 'April', 'May', 'June',
                'July', 'August', 'September', 'October', 'November', 'December'
            ];

            const allRooms = ['CL1', 'CL2', 'CL3', 'AVR', 'SB202'];

            function getSelectedRoom() {
                const select = document.getElementById('room_type');
                if (!select) return '';
                const value = select.value;
                if (value === 'Others') {
                    const otherInput = document.querySelector('input[name="room_reservation_other"]');
                    return otherInput && otherInput.value.trim() ? otherInput.value.trim() : value;
                }
                return value;
            }

            function toggleRoomCalendar(event) {
                if (event) event.stopPropagation();
                const popup = document.getElementById('roomCalPopup');
                const btn = document.getElementById('roomCalBtn');
                if (!popup || !btn) return;
                popup.classList.toggle('open');
                if (popup.classList.contains('open')) {
                    // Position popup near the calendar button
                    const rect = btn.getBoundingClientRect();
                    const popupWidth = 320;
                    const margin = 8;
                    let top = rect.bottom + margin;
                    let left = rect.left;
                    // Keep within viewport horizontally
                    if (left + popupWidth > window.innerWidth - 10) {
                        left = window.innerWidth - popupWidth - 10;
                    }
                    if (left < 8) left = 8;
                    // Keep within viewport vertically
                    const popupHeight = 440;
                    if (top + popupHeight > window.innerHeight - 10) {
                        top = rect.top - popupHeight - margin;
                        if (top < 8) top = 8;
                    }
                    popup.style.top = top + 'px';
                    popup.style.left = left + 'px';
                    roomCalState.currentMonth = new Date();
                    roomCalState.selectedRoom = getSelectedRoom();
                    roomCalState.selectedDate = null;
                    updateRoomCalendar();
                }
            }

            function changeCalMonth(delta) {
                roomCalState.currentMonth.setMonth(roomCalState.currentMonth.getMonth() + delta);
                updateRoomCalendar();
            }

            function updateRoomCalendar() {
                roomCalState.selectedRoom = getSelectedRoom();
                renderRoomCalendar(new Date(roomCalState.currentMonth));
            }

            function formatDateISO(date) {
                const y = date.getFullYear();
                const m = String(date.getMonth() + 1).padStart(2, '0');
                const d = String(date.getDate()).padStart(2, '0');
                return `${y}-${m}-${d}`;
            }

            function normalizeDateISO(value) {
                if (!value) return '';
                const date = new Date(value);
                if (isNaN(date.getTime())) return '';
                return formatDateISO(date);
            }

            function getRoomBookingsForDate(dateISO, selectedRoom) {
                return roomReservations.filter(booking => {
                    if (!booking || !booking.booking_date || !booking.end_date) return false;
                    if (selectedRoom && selectedRoom !== '' && selectedRoom !== 'Others' && booking.room_name !== selectedRoom) {
                        return false;
                    }
                    const bookingStart = normalizeDateISO(booking.booking_date);
                    const bookingEnd = normalizeDateISO(booking.end_date);
                    return bookingStart && bookingEnd && dateISO >= bookingStart && dateISO <= bookingEnd;
                });
            }

            function getAvailableRoomsForDate(dateISO) {
                return allRooms.filter(room => {
                    return !roomReservations.some(booking => {
                        if (!booking || !booking.booking_date || !booking.end_date) return false;
                        if (booking.room_name !== room) return false;
                        const bookingStart = normalizeDateISO(booking.booking_date);
                        const bookingEnd = normalizeDateISO(booking.end_date);
                        return bookingStart && bookingEnd && dateISO >= bookingStart && dateISO <= bookingEnd;
                    });
                });
            }

            function getRoomStatusForDate(dateISO, selectedRoom) {
                const bookings = getRoomBookingsForDate(dateISO, selectedRoom);
                const approved = bookings.some(booking => booking.status === 'Approved');
                const pending = bookings.some(booking => booking.status === 'Pending');
                if (approved && pending) return 'both';
                if (approved) return 'approved';
                if (pending) return 'pending';
                return 'free';
            }

            function renderRoomCalDetail(dateISO, selectedRoom) {
                const detail = document.getElementById('roomCalDetail');
                const bookings = getRoomBookingsForDate(dateISO, selectedRoom);
                const availableRooms = selectedRoom && selectedRoom !== 'Others' ?
                    (getAvailableRoomsForDate(dateISO).includes(selectedRoom) ? [selectedRoom] : []) :
                    getAvailableRoomsForDate(dateISO);
                const formattedDate = new Date(dateISO).toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
                const roomLabel = selectedRoom && selectedRoom !== 'Others' ? selectedRoom : 'All rooms';
                if (!detail) return;

                let html = `<div class="rcd-empty" style="padding-bottom:8px; font-weight:700;">Availability for <strong>${roomLabel}</strong> on <strong>${formattedDate}</strong>:</div>`;
                if (availableRooms.length === 0) {
                    html += `<div class="rcd-empty">No rooms are fully available for the selected day. Please choose another date or check a different room.</div>`;
                } else {
                    html += `<div class="rcd-empty" style="padding-bottom:10px;">Available room${availableRooms.length > 1 ? 's' : ''}: <strong>${availableRooms.join(', ')}</strong></div>`;
                }

                if (bookings.length > 0) {
                    html += `<div class="rcd-empty" style="padding:8px 0;font-weight:700;">Existing reservations on this date:</div>`;
                    bookings.forEach(booking => {
                        const statusClass = booking.status === 'Approved' ? 'approved' : 'pending';
                        html += `
                            <div class="rcd-item ${statusClass}">
                                <i class="fas ${booking.status === 'Approved' ? 'fa-check-circle' : 'fa-clock'}"></i>
                                <div class="rcd-info">
                                    <div class="rcd-room">${booking.room_name} — <span style="font-weight:400;color:${booking.status === 'Approved' ? '#15803d' : '#92400e'};">${booking.status}</span></div>
                                    <div class="rcd-time"><i class="fas fa-user" style="margin-right:4px;"></i>Reserved by: <strong>${booking.user_name}</strong></div>
                                    <div class="rcd-time"><i class="fas fa-clock" style="margin-right:4px;"></i>${booking.start_time} &ndash; ${booking.end_time}</div>
                                </div>
                            </div>
                        `;
                    });
                }
                detail.innerHTML = html;
            }

            function renderRoomCalendar(baseDate) {
                const monthLabel = document.getElementById('roomCalMonthLabel');
                const grid = document.getElementById('roomCalGrid');
                const summary = document.getElementById('roomCalSummary');
                if (!monthLabel || !grid || !summary) return;
                const year = baseDate.getFullYear();
                const month = baseDate.getMonth();
                const todayISO = formatDateISO(new Date());
                monthLabel.textContent = `${monthNames[month]} ${year}`;
                grid.innerHTML = '';

                const firstDay = new Date(year, month, 1);
                const startDay = firstDay.getDay();
                const lastDay = new Date(year, month + 1, 0);
                const selectedRoomText = roomCalState.selectedRoom && roomCalState.selectedRoom !== 'Others' ? roomCalState.selectedRoom : 'All rooms';

                summary.innerHTML = `
                    <div style="font-size:13px;font-weight:700;color:#333;margin-bottom:8px;">Viewing availability for <span style="color:#95122C;">${selectedRoomText}</span></div>
                    <div style="font-size:12px;color:#555;">Click any day to view bookings and availability details.</div>
                `;

                for (let i = 0; i < startDay; i++) {
                    const empty = document.createElement('div');
                    empty.className = 'rc-day empty';
                    grid.appendChild(empty);
                }

                for (let day = 1; day <= lastDay.getDate(); day++) {
                    const date = new Date(year, month, day);
                    const iso = formatDateISO(date);
                    const status = getRoomStatusForDate(iso, roomCalState.selectedRoom);
                    const cell = document.createElement('div');
                    cell.className = 'rc-day';
                    if (iso < todayISO) cell.classList.add('past');
                    if (status === 'approved') cell.classList.add('has-approved');
                    if (status === 'pending') cell.classList.add('has-pending');
                    if (status === 'both') cell.classList.add('has-both');
                    if (date.toDateString() === new Date().toDateString()) cell.classList.add('today');
                    cell.innerHTML = `<span>${day}</span>`;
                    cell.addEventListener('click', () => {
                        roomCalState.selectedDate = iso;
                        renderRoomCalDetail(iso, roomCalState.selectedRoom);
                    });
                    grid.appendChild(cell);
                }

                const startDetailDate = roomCalState.selectedDate || formatDateISO(new Date(year, month, 1));
                roomCalState.selectedDate = startDetailDate;
                renderRoomCalDetail(startDetailDate, roomCalState.selectedRoom);
            }

            function closeAllModals(event) {
                const popup = document.getElementById('roomCalPopup');
                const target = event.target;
                if (!popup || !popup.classList.contains('open')) return;
                if (!popup.contains(target) && target.id !== 'roomCalBtn') {
                    popup.classList.remove('open');
                }
            }

            document.addEventListener('click', closeAllModals);

            <?php if ($success): ?>
                Swal.fire({
                    title: 'Success!',
                    text: '<?= addslashes($success) ?>',
                    icon: 'success',
                    confirmButtonColor: '#28a745',
                    confirmButtonText: 'Great',
                    allowOutsideClick: false,
                    allowEscapeKey: false
                });
            <?php endif; ?>

            <?php if ($error): ?>
                Swal.fire({
                    title: 'Request Failed',
                    text: '<?= addslashes($error) ?>',
                    icon: 'error',
                    confirmButtonColor: '#95122C',
                    confirmButtonText: 'Try Again'
                });
            <?php endif; ?>

            function toggleNotifications(event) {
                event.stopPropagation();
                const dropdown = document.getElementById('notifDropdown');
                dropdown.classList.toggle('active');
                if (dropdown.classList.contains('active')) {
                    const badge = document.getElementById('notificationBadge');
                    if (badge) badge.style.display = 'none';
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'mark_all_notifications_read=1'
                    }).catch(() => {});
                }
            }

            document.addEventListener('click', () => {
                const drop = document.getElementById('notifDropdown');
                if (drop) drop.classList.remove('active');
            });

            function switchTab(tab) {
                document.getElementById('btn-room').classList.remove('active');
                document.getElementById('btn-equipment').classList.remove('active');
                document.getElementById('form-room').classList.add('hidden');
                document.getElementById('form-equipment').classList.add('hidden');
                document.getElementById('btn-' + tab).classList.add('active');
                document.getElementById('form-' + tab).classList.remove('hidden');
            }

            function toggleEndDate(type) {
                const select = document.getElementById(type + '_booking_type');
                const dateGroup = document.getElementById(type + '_end_date_group');
                if (select.value === 'multiple') {
                    dateGroup.classList.remove('hidden');
                    document.querySelector(`input[name="${type}_end_date"]`).required = true;
                } else {
                    dateGroup.classList.add('hidden');
                    document.querySelector(`input[name="${type}_end_date"]`).required = false;
                }
            }

            function toggleRoomOtherDept() {
                const select = document.getElementById('room_department');
                const otherGroup = document.getElementById('room_dept_other_group');
                const otherInput = document.querySelector('input[name="room_department_other"]');
                if (select.value === 'Others') { otherGroup.classList.remove('hidden'); otherInput.required = true; }
                else { otherGroup.classList.add('hidden'); otherInput.required = false; }
            }

            function toggleRoomTypeOther() {
                const select = document.getElementById('room_type');
                const otherGroup = document.getElementById('room_reserve_other_group');
                const otherInput = document.querySelector('input[name="room_reservation_other"]');
                if (select.value === 'Others') { otherGroup.classList.remove('hidden'); otherInput.required = true; }
                else { otherGroup.classList.add('hidden'); otherInput.required = false; }
            }

            function toggleOtherDept() {
                const select = document.getElementById('equip_department');
                const otherGroup = document.getElementById('equip_dept_other_group');
                const otherInput = document.querySelector('input[name="equip_department_other"]');
                if (select.value === 'others') { otherGroup.classList.remove('hidden'); otherInput.required = true; }
                else { otherGroup.classList.add('hidden'); otherInput.required = false; }
            }

            function addEquipmentRow() {
                equipmentCount++;
                const container = document.getElementById('equipment-container');
                let optionsHTML = '<option value="">Select Equipment</option>';
                availableEquipment.forEach(item => {
                    optionsHTML += `<option value="${item.name}">${item.name} (Max Available: ${item.available})</option>`;
                });
                const html = `
                    <div class="equipment-item" id="equip-row-${equipmentCount}">
                        <div class="equipment-item-header">
                            <span class="equipment-item-number">Item #${equipmentCount}</span>
                            ${equipmentCount > 1 ? `<button type="button" class="btn-remove-item" onclick="removeEquipmentRow(${equipmentCount})"><i class="fas fa-trash"></i> Remove</button>` : ''}
                        </div>
                        <div class="equipment-row">
                            <div class="form-group">
                                <label class="form-label">Equipment Name</label>
                                <select name="equipment_name[]" class="form-select" onchange="updateAvailability(this, ${equipmentCount})">
                                    ${optionsHTML}
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Quantity</label>
                                <input type="number" name="equipment_quantity[]" class="form-input" min="1" value="1">
                            </div>
                            <div class="availability-status" id="status-${equipmentCount}"></div>
                        </div>
                    </div>`;
                container.insertAdjacentHTML('beforeend', html);
            }

            function removeEquipmentRow(id) {
                const row = document.getElementById(`equip-row-${id}`);
                if (row) row.remove();
            }

            function updateAvailability(selectElement, id) {
                const statusDiv = document.getElementById(`status-${id}`);
                const selectedValue = selectElement.value;
                if (!selectedValue) { statusDiv.innerHTML = ''; return; }
                const item = availableEquipment.find(eq => eq.name === selectedValue);
                if (item) {
                    if (item.available > 0) {
                        statusDiv.innerHTML = `<span class="availability-status available"><i class="fas fa-check"></i> In Stock</span>`;
                        const qtyInput = selectElement.closest('.equipment-row').querySelector('input[type="number"]');
                        qtyInput.max = item.available;
                    } else {
                        statusDiv.innerHTML = `<span class="availability-status unavailable"><i class="fas fa-times"></i> Unavailable</span>`;
                    }
                }
            }

            window.addEventListener('DOMContentLoaded', () => {
                if (availableEquipment.length > 0) {
                    addEquipmentRow();
                } else {
                    document.getElementById('equipment-container').innerHTML = '<div style="color: #721c24; background: #f8d7da; padding: 15px; border-radius: 8px;">No equipment is currently available.</div>';
                    document.querySelector('.btn-add-equipment').style.display = 'none';
                }
            });

            // Dark Mode Toggle
            function toggleDarkMode() {
                document.body.classList.toggle('dark-mode');
                const icon = document.getElementById('darkModeIcon');
                const label = document.getElementById('darkModeLabel');
                if (document.body.classList.contains('dark-mode')) {
                    icon.className = 'fas fa-sun';
                    label.textContent = 'Light Mode';
                } else {
                    icon.className = 'fas fa-moon';
                    label.textContent = 'Dark Mode';
                }
            }

            // Chat Bot
            function toggleChat() { document.getElementById('chatBot').classList.toggle('active'); }
            function handleChatEnter(e) { if (e.key === 'Enter') sendMessage(); }

            function sendMessage() {
                const input = document.getElementById('chatInput');
                const message = input.value.trim();
                if (!message) return;
                const chatMessages = document.getElementById('chatMessages');
                chatMessages.insertAdjacentHTML('beforeend', `<div class="message user">${message}</div>`);
                input.value = '';
                chatMessages.scrollTop = chatMessages.scrollHeight;
                const loadingId = 'bot-loading-' + Date.now();
                chatMessages.insertAdjacentHTML('beforeend', `<div class="message bot" id="${loadingId}"><i>Thinking...</i></div>`);
                chatMessages.scrollTop = chatMessages.scrollHeight;
                fetch('chatbot_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ message: message })
                })
                .then(response => { if (!response.ok) throw new Error('Network response failure'); return response.json(); })
                .then(data => {
                    document.getElementById(loadingId).remove();
                    const aiReply = data.reply || "Sorry, I encountered an issue processing that statement.";
                    chatMessages.insertAdjacentHTML('beforeend', `<div class="message bot">${aiReply}</div>`);
                    chatMessages.scrollTop = chatMessages.scrollHeight;
                })
                .catch(error => {
                    document.getElementById(loadingId).remove();
                    chatMessages.insertAdjacentHTML('beforeend', `<div class="message bot" style="color: #721c24; background: #f8d7da;">AI server connection failed. Please check endpoint configuration.</div>`);
                    chatMessages.scrollTop = chatMessages.scrollHeight;
                    console.error('Error:', error);
                });
            }
        </script>

        <!-- Bottom navigation for mobile -->
        <nav class="bottom-nav">
        <div class="bottom-nav-inner">
            <a href="dashboard.php"><i class="fas fa-house"></i>Home</a>
            <a href="reservation_form.php" class="active"><i class="fas fa-plus-circle"></i>Reserve</a>
            <a href="view_reservations.php"><i class="fas fa-list-check"></i>Bookings</a>
            <a href="user_history.php"><i class="fas fa-history"></i>History</a>
            <a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
        </div>
        </nav>

    </body>
    </html>