<?php
        /**
         * View Reservations Page — AssetEase
         */
        session_start();
        require_once 'config.php';

        if (!isset($_SESSION['user_id'])) {
            header("Location: login.php");
            exit;
        }

        $userId   = $_SESSION['user_id'];
        $userEmail = $_SESSION['email'] ?? 'N/A';
        $success  = '';
        $error    = '';

        // Always fetch current user info fresh from database
        $stmt = $conn->prepare("SELECT uname FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $user   = $result->fetch_assoc();
        $userName = $user['uname'] ?? ($_SESSION['uname'] ?? $_SESSION['user_name'] ?? 'User');

        // Fetch unread notifications count
        $stmt = $conn->prepare("SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $notifRow         = $stmt->get_result()->fetch_assoc();
        $unread_count     = $notifRow['unread'] ?? 0;

        // Recent notifications for dropdown
        $recentStmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
        $recentStmt->bind_param("i", $userId);
        $recentStmt->execute();
        $notifications = $recentStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Handle Delete/Cancel
        if (isset($_GET['delete_id'])) {
            $deleteId = intval($_GET['delete_id']);
            $stmt = $conn->prepare("DELETE FROM room_reservations WHERE id = ? AND user_id = ? AND status = 'Pending'");
            $stmt->bind_param("ii", $deleteId, $userId);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                $success = "Reservation cancelled successfully.";
            } else {
                $stmt = $conn->prepare("DELETE FROM borrows WHERE id = ? AND user_id = ? AND status = 'Pending'");
                $stmt->bind_param("ii", $deleteId, $userId);
                $stmt->execute();
                if ($stmt->affected_rows > 0) {
                    $success = "Equipment request cancelled successfully.";
                } else {
                    $error = "Unable to cancel. Only pending reservations can be cancelled.";
                }
            }
        }

        // Handle Edit/Update
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_type'])) {
            $editId      = intval($_POST['edit_id']);
            $startDate   = $_POST['start_date']  ?? '';
            $endDate     = $_POST['end_date']    ?? $startDate;
            $startTime   = $_POST['start_time']  ?? '';
            $endTime     = $_POST['end_time']    ?? '';
            $purpose     = trim($_POST['purpose'] ?? '');

            if ($editId && $startDate && $startTime && $endTime && $purpose) {
                if ($_POST['edit_type'] === 'room') {
                    $stmt = $conn->prepare("UPDATE room_reservations SET booking_date=?, end_date=?, start_time=?, end_time=?, purpose=? WHERE id=? AND user_id=? AND status='Pending'");
                    $stmt->bind_param("sssssii", $startDate, $endDate, $startTime, $endTime, $purpose, $editId, $userId);
                } else {
                    $stmt = $conn->prepare("UPDATE borrows SET borrow_date=?, return_date=?, start_time=?, end_time=?, purpose=? WHERE id=? AND user_id=? AND status='Pending'");
                    $stmt->bind_param("sssssii", $startDate, $endDate, $startTime, $endTime, $purpose, $editId, $userId);
                }
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $success = "Reservation updated successfully.";
                } else {
                    $error = "Unable to update. Only pending reservations can be edited.";
                }
            } else {
                $error = "Please fill in all required fields.";
            }
        }

        // Helper functions
        function formatDateRange($startDate, $endDate) {
            $start = date('M d, Y', strtotime($startDate));
            if ($startDate === $endDate) return $start;
            $end = date('M d, Y', strtotime($endDate));
            return "$start to $end";
        }

        function formatTimeRange($startTime, $endTime) {
            $start = date('h:i A', strtotime($startTime));
            $end = date('h:i A', strtotime($endTime));
            return "$start - $end";
        }

        function parsePurposeParts($purpose) {
            $parts = [
                'department' => '',
                'room'       => '',
                'purpose'    => trim($purpose)
            ];

            if (preg_match('/Department:\s*([^|]+)/i', $purpose, $matches)) {
                $parts['department'] = trim($matches[1]);
            }
            if (preg_match('/Room:\s*([^|]+)/i', $purpose, $matches)) {
                $parts['room'] = trim($matches[1]);
            }
            if (preg_match('/Purpose:\s*(.+)$/i', $purpose, $matches)) {
                $parts['purpose'] = trim($matches[1]);
            }

            if ($parts['purpose'] === '') {
                $clean = preg_replace('/(?:Department|Room|Location|Purpose):\s*[^|]+(\||$)/i', '', $purpose);
                $parts['purpose'] = trim(trim($clean, "| "));
            }

            return $parts;
        }

        // Fetch equipment availability
        $equipmentAvailability = [];
        $equipResult = $conn->query("SELECT id, equipment_name, total_quantity FROM equipment WHERE status = 'Available' ORDER BY equipment_name");
        if ($equipResult) {
            while ($equip = $equipResult->fetch_assoc()) {
                $equipName = $equip['equipment_name'];
                $totalQty  = $equip['total_quantity'];
                
                $reservedStmt = $conn->prepare("SELECT COUNT(*) as reserved_count FROM borrows WHERE equipment_name LIKE ? AND (status = 'Approved' OR status = 'Pending')");
                $likeSearch   = "%$equipName%";
                $reservedStmt->bind_param("s", $likeSearch);
                $reservedStmt->execute();
                $reservedRow = $reservedStmt->get_result()->fetch_assoc();
                $reservedQty = $reservedRow['reserved_count'] ?? 0;
                $availableQty = max(0, $totalQty - $reservedQty);
                
                $equipmentAvailability[$equipName] = [
                    'total'    => $totalQty,
                    'reserved' => $reservedQty,
                    'available' => $availableQty
                ];
            }
        }

        // Fetch reservations
        $pending_room_stmt = $conn->prepare("SELECT id, user_name, room_name AS resource_name, booking_date, end_date, start_time, end_time, purpose, status FROM room_reservations WHERE user_id = ? AND status = 'Pending' ORDER BY booking_date DESC");
        $pending_room_stmt->bind_param("i", $userId);
        $pending_room_stmt->execute();
        $pending_room_result = $pending_room_stmt->get_result();

        $approved_room_stmt = $conn->prepare("SELECT id, user_name, room_name AS resource_name, booking_date, end_date, start_time, end_time, purpose, status FROM room_reservations WHERE user_id = ? AND status = 'Approved' ORDER BY booking_date DESC");
        $approved_room_stmt->bind_param("i", $userId);
        $approved_room_stmt->execute();
        $approved_room_result = $approved_room_stmt->get_result();

        // Equipment borrows grouped by batch (same date/time/day submitted)
        // Each row = one submission with all its items listed together
        $pending_equip_stmt = $conn->prepare("
            SELECT
                MIN(id)                                                          AS id,
                GROUP_CONCAT(equipment_name ORDER BY equipment_name SEPARATOR '|||') AS resource_name,
                GROUP_CONCAT(quantity       ORDER BY equipment_name SEPARATOR '|||') AS quantities,
                borrow_date  AS booking_date,
                return_date  AS end_date,
                start_time, end_time,
                MAX(purpose) AS purpose,
                MAX(status)  AS status
            FROM borrows
            WHERE user_id = ? AND status = 'Pending'
            GROUP BY borrow_date, return_date, start_time, end_time, DATE(created_at)
            ORDER BY borrow_date DESC
        ");
        $pending_equip_stmt->bind_param("i", $userId);
        $pending_equip_stmt->execute();
        $pending_equip_result = $pending_equip_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $approved_equip_stmt = $conn->prepare("
            SELECT
                MIN(id)                                                          AS id,
                GROUP_CONCAT(equipment_name ORDER BY equipment_name SEPARATOR '|||') AS resource_name,
                GROUP_CONCAT(quantity       ORDER BY equipment_name SEPARATOR '|||') AS quantities,
                borrow_date  AS booking_date,
                return_date  AS end_date,
                start_time, end_time,
                MAX(purpose) AS purpose,
                MAX(status)  AS status
            FROM borrows
            WHERE user_id = ? AND status = 'Approved'
            GROUP BY borrow_date, return_date, start_time, end_time, DATE(created_at)
            ORDER BY borrow_date DESC
        ");
        $approved_equip_stmt->bind_param("i", $userId);
        $approved_equip_stmt->execute();
        $approved_equip_result = $approved_equip_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>My Reservations — AssetEase</title>
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
        /* ══ RESET & BASE ══════════════════════════════════════════════════════════ */
        :root {
        --red:        #95122C;
        --red-dark:   #7a0c23;
        --dark:       #100C08;
        --bg:         #F5EFED;
        --white:      #ffffff;
        --shadow:     0 4px 20px rgba(0,0,0,0.08);
        --shadow-md:  0 8px 30px rgba(0,0,0,0.12);
        --radius:     14px;
        --sidebar-w:  260px;
        }
        *{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif;}
        body{background:var(--bg);display:flex;min-height:100vh;}

        /* ══ SIDEBAR ═══════════════════════════════════════════════════════════════ */
        .sidebar{
        width:var(--sidebar-w);height:100vh;
        background:linear-gradient(180deg,var(--dark) 0%,var(--red) 100%);
        color:#fff;padding:0;position:fixed;z-index:1001;overflow-y:auto;
        display:flex;flex-direction:column;
        }
        .sidebar-logo{
        padding:28px 25px 22px;display:flex;align-items:center;gap:12px;
        border-bottom:1px solid rgba(255,255,255,0.1);
        }
        .logo-image{
        width:42px;height:42px;object-fit:cover;border-radius:10px;
        background:#fff;padding:2px;
        }
        .sidebar-logo h2{color:#FFD700;font-weight:700;font-size:20px;letter-spacing:1.5px;}
        .sidebar-section-label{
        padding:18px 25px 6px;font-size:10px;font-weight:600;
        letter-spacing:2px;color:rgba(255,255,255,0.45);text-transform:uppercase;
        }
        .sidebar a{
        display:flex;align-items:center;padding:13px 25px;
        color:rgba(255,255,255,0.8);text-decoration:none;
        transition:all 0.25s ease;font-size:0.9rem;font-weight:500;
        border-left:3px solid transparent;margin:1px 0;
        }
        .sidebar a i{width:32px;font-size:16px;opacity:0.85;}
        .sidebar a:hover{background:rgba(255,255,255,0.1);color:#fff;border-left-color:rgba(255,255,255,0.4);}
        .sidebar a.active{background:rgba(255,255,255,0.18);color:#fff;border-left-color:#FFD700;font-weight:600;}
        .sidebar a.active i{opacity:1;}
        .sidebar-divider{border:none;border-top:1px solid rgba(255,255,255,0.1);margin:10px 20px;}
        .sidebar-promo{
        margin:auto 15px 20px;padding:18px;
        background:linear-gradient(135deg,rgba(255,215,0,0.2),rgba(255,255,255,0.08));
        border:1px solid rgba(255,215,0,0.3);border-radius:12px;
        }
        .sidebar-promo p{font-size:12px;color:rgba(255,255,255,0.85);line-height:1.5;}
        .sidebar-promo strong{color:#FFD700;font-size:13px;}
        .sidebar-promo a{
        display:inline-block;margin-top:10px;padding:7px 16px;
        background:#FFD700;color:var(--dark);border-radius:8px;
        font-size:11px;font-weight:700;text-decoration:none;border:none;
        }

        /* ══ MAIN LAYOUT ════════════════════════════════════════════════════════════ */
        .main{margin-left:var(--sidebar-w);width:calc(100% - var(--sidebar-w));display:flex;flex-direction:column;min-height:100vh;}

        /* ══ TOP HEADER ════════════════════════════════════════════════════════════ */
        .top-header{
        position:fixed;top:0;right:0;
        width:calc(100% - var(--sidebar-w));
        background:var(--white);
        display:flex;justify-content:space-between;align-items:center;
        padding:14px 36px;z-index:1000;
        box-shadow:0 1px 0 rgba(0,0,0,0.07);
        }
        .header-left h1{font-size:22px;font-weight:700;color:var(--dark);}
        .header-left p{font-size:13px;color:#888;margin-top:1px;}
        .header-right{display:flex;align-items:center;gap:12px;}
        .search-box{
        display:flex;align-items:center;gap:10px;
        background:#F5EFED;border-radius:25px;padding:9px 18px;
        width:240px;border:1.5px solid transparent;transition:0.2s;
        }
        .search-box:focus-within{border-color:var(--red);background:#fff;}
        .search-box i{color:#aaa;font-size:14px;}
        .search-box input{border:none;background:transparent;font-size:13px;font-family:'Poppins';color:var(--dark);outline:none;width:100%;}
        .search-box input::placeholder{color:#bbb;}
        .notif-wrap{position:relative;}
        .notif-btn{
        width:42px;height:42px;border-radius:50%;background:#F5EFED;border:none;
        display:flex;align-items:center;justify-content:center;cursor:pointer;
        color:var(--dark);font-size:17px;transition:0.2s;
        }
        .notif-btn:hover{background:#ede6e3;}
        .notif-badge{
        position:absolute;top:-2px;right:-2px;background:#dc3545;color:#fff;
        font-size:10px;font-weight:700;width:18px;height:18px;
        border-radius:50%;display:flex;align-items:center;justify-content:center;
        border:2px solid #fff;
        }
        .notif-dropdown{
        position:absolute;top:52px;right:0;width:340px;background:#fff;
        border-radius:var(--radius);box-shadow:var(--shadow-md);z-index:2000;
        display:none;max-height:380px;overflow-y:auto;
        }
        .notif-dropdown.active{display:block;}
        .notif-header{padding:14px 18px 10px;font-weight:700;font-size:13px;color:var(--dark);border-bottom:1px solid #f0f0f0;}
        .notif-item{padding:12px 18px;border-bottom:1px solid #f8f8f8;cursor:pointer;}
        .notif-item:hover{background:#fafafa;}
        .notif-item .ni-msg{font-size:13px;color:#444;line-height:1.4;}
        .notif-item .ni-time{font-size:11px;color:#bbb;margin-top:3px;}
        .notif-empty{padding:24px;text-align:center;color:#bbb;font-size:13px;}
        .profile-pill{
        display:flex;align-items:center;gap:10px;
        background:#F5EFED;border-radius:30px;padding:6px 14px 6px 8px;
        cursor:pointer;transition:0.2s;
        }
        .profile-pill:hover{background:#ede6e3;}
        .profile-pill img{width:34px;height:34px;border-radius:50%;object-fit:cover;border:2px solid var(--red);}
        .profile-pill span{font-size:13px;font-weight:600;color:var(--dark);}

        /* ══ CONTENT ════════════════════════════════════════════════════════════════ */
        .content{padding:90px 36px 40px;flex:1;}

        /* Alerts */
        .alert{
        display:flex;align-items:center;gap:10px;padding:14px 18px;
        border-radius:10px;margin-bottom:20px;font-size:13px;font-weight:500;
        }
        .alert-success{background:#dcfce7;color:#15803d;border-left:4px solid #22c55e;}
        .alert-error{background:#fee2e2;color:#b91c1c;border-left:4px solid #ef4444;}

        /* ══ PAGE HEADER ════════════════════════════════════════════════════════════ */
        .page-header{margin-bottom:28px;}
        .page-header h2{font-size:20px;font-weight:700;color:var(--dark);margin-bottom:4px;display:flex;align-items:center;gap:10px;}
        .page-header p{font-size:13px;color:#888;}

        /* ══ SECTION HEADER ═════════════════════════════════════════════════════════ */
        .section-header{
        display:flex;align-items:center;gap:10px;
        margin:32px 0 16px;padding-bottom:12px;
        border-bottom:2px solid var(--red);
        }
        .section-header h3{font-size:16px;font-weight:700;color:var(--dark);}
        .section-header i{color:var(--red);font-size:18px;}

        /* ══ TABLE CARD ══════════════════════════════════════════════════════════════ */
        .table-card{
        background:#fff;border-radius:var(--radius);
        box-shadow:var(--shadow);overflow:hidden;margin-bottom:22px;
        }
        .table-wrap{overflow-x:auto;}
        table{width:100%;border-collapse:collapse;}
        th{
        padding:14px 16px;font-size:12px;font-weight:600;
        color:#fff;background:var(--red);text-transform:uppercase;
        letter-spacing:0.5px;text-align:left;
        }
        td{
        padding:14px 16px;font-size:13px;color:#444;
        border-bottom:1px solid #f0f0f0;
        }
        tr:last-child td{border-bottom:none;}
        tr:hover{background:#faf8f7;}

        /* ══ STATUS & BADGES ════════════════════════════════════════════════════════ */
        .badge{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:600;}
        .badge-pending{background:#fef9c3;color:#ca8a04;}
        .badge-approved{background:#dcfce7;color:#16a34a;}
        .badge-available{background:#dcfce7;color:#16a34a;}
        .badge-unavailable{background:#fee2e2;color:#dc2626;}

        /* ══ ACTION BUTTONS ══════════════════════════════════════════════════════════ */
        .btn-delete{
        display:inline-flex;align-items:center;gap:6px;
        padding:7px 14px;background:#fef2f2;color:var(--red);border:1.5px solid var(--red);
        border-radius:20px;font-size:12px;font-weight:600;text-decoration:none;
        cursor:pointer;transition:0.2s;font-family:'Poppins';
        }
        .btn-delete:hover{background:var(--red);color:#fff;}
        .btn-edit{
        display:inline-flex;align-items:center;gap:6px;
        padding:7px 14px;background:#eff6ff;color:#3b82f6;border:1.5px solid #3b82f6;
        border-radius:20px;font-size:12px;font-weight:600;text-decoration:none;
        cursor:pointer;transition:0.2s;font-family:'Poppins';margin-right:6px;
        }
        .btn-edit:hover{background:#3b82f6;color:#fff;}
        .action-btns{display:flex;gap:6px;flex-wrap:wrap;}

        /* ══ EDIT MODAL ══════════════════════════════════════════════════════════════ */
        .modal-overlay{
        display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);
        z-index:3000;animation:fadeIn 0.2s;
        }
        .modal-overlay.open{display:flex;align-items:center;justify-content:center;padding:16px;}
        @keyframes fadeIn{from{opacity:0}to{opacity:1}}
        .modal-box{
        background:#fff;border-radius:16px;width:100%;max-width:520px;
        box-shadow:0 20px 60px rgba(0,0,0,0.25);animation:slideUp 0.25s;
        overflow:hidden;
        }
        @keyframes slideUp{from{transform:translateY(30px);opacity:0}to{transform:translateY(0);opacity:1}}
        .modal-head{
        background:var(--red);color:#fff;padding:20px 24px;
        display:flex;justify-content:space-between;align-items:center;
        }
        .modal-head h3{font-size:16px;font-weight:700;margin:0;}
        .modal-head button{background:none;border:none;color:#fff;font-size:20px;cursor:pointer;line-height:1;}
        .modal-head button:hover{opacity:0.8;}
        .modal-body{padding:24px;}
        .modal-note{
        display:flex;align-items:center;gap:8px;padding:10px 14px;
        background:#fef9c3;color:#92400e;border-radius:8px;
        font-size:12px;font-weight:500;margin-bottom:20px;border-left:3px solid #ca8a04;
        }
        .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
        .form-group{display:flex;flex-direction:column;gap:6px;}
        .form-group.full{grid-column:1/-1;}
        .form-group label{font-size:12px;font-weight:600;color:#555;text-transform:uppercase;letter-spacing:0.5px;}
        .form-group input,.form-group textarea{
        padding:10px 14px;border:1.5px solid #e5e7eb;border-radius:8px;
        font-size:13px;font-family:'Poppins';color:var(--dark);outline:none;transition:0.2s;
        }
        .form-group input:focus,.form-group textarea:focus{border-color:var(--red);box-shadow:0 0 0 3px rgba(149,18,44,0.08);}
        .form-group textarea{resize:vertical;min-height:80px;}
        .form-group .resource-label{
        padding:10px 14px;background:#f5f5f5;border:1.5px solid #e5e7eb;
        border-radius:8px;font-size:13px;color:#666;font-weight:600;
        }
        .modal-footer{
        padding:16px 24px;border-top:1px solid #f0f0f0;
        display:flex;justify-content:flex-end;gap:10px;
        }
        .btn-modal-cancel{
        padding:10px 22px;background:#f5f5f5;color:#555;border:1.5px solid #ddd;
        border-radius:20px;font-size:13px;font-weight:600;cursor:pointer;transition:0.2s;
        }
        .btn-modal-cancel:hover{background:#eee;}
        .btn-modal-save{
        padding:10px 26px;background:var(--red);color:#fff;border:none;
        border-radius:20px;font-size:13px;font-weight:600;cursor:pointer;transition:0.2s;
        }
        .btn-modal-save:hover{background:var(--red-dark);}

        /* ══ EMPTY STATE ═════════════════════════════════════════════════════════════ */
        .empty-state{
        text-align:center;padding:60px 20px;color:#bbb;
        background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);
        }
        .empty-state i{font-size:48px;margin-bottom:12px;display:block;color:#e0d8d4;}
        .empty-state p{font-size:14px;font-weight:600;color:#aaa;margin-bottom:4px;}
        .empty-state small{font-size:12px;}

        /* ══ MOBILE RESPONSIVE ══════════════════════════════════════════════════ */
        .mobile-menu-btn{display:none;position:fixed;top:13px;left:14px;z-index:1200;background:var(--red);color:#fff;border:none;width:44px;height:44px;border-radius:10px;font-size:20px;cursor:pointer;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,0.3);}
        .sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1100;}
        .sidebar-overlay.open{display:block;}
        .bottom-nav{display:none;position:fixed;bottom:0;left:0;right:0;background:linear-gradient(90deg,#100C08 0%,#95122C 100%);z-index:1050;box-shadow:0 -2px 10px rgba(0,0,0,0.25);}
        .bottom-nav-inner{display:flex;width:100%;}
        .bottom-nav a{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;color:rgba(255,255,255,0.65);text-decoration:none;padding:10px 0 8px;font-size:10px;gap:3px;transition:color 0.2s;font-family:'Poppins',sans-serif;}
        .bottom-nav a i{font-size:18px;}
        .bottom-nav a.active,.bottom-nav a:hover{color:#FFD700;}
        @media(max-width:768px){
        .sidebar{transform:translateX(-260px);transition:transform 0.3s ease;z-index:1150!important;}
        .sidebar.open{transform:translateX(0);}
        .mobile-menu-btn{display:flex;}
        .main{margin-left:0!important;width:100%!important;}
        .top-header{left:0!important;right:0!important;width:100%!important;padding:11px 14px 11px 68px!important;}
        .header-left h1{font-size:16px!important;}
        .header-left p{font-size:11px!important;}
        .search-box{display:none!important;}
        .profile-pill span{display:none!important;}
        .profile-pill{padding:4px!important;gap:0!important;}
        .content{padding:80px 14px 90px!important;}
        .bottom-nav{display:block;}
        .notif-dropdown{width:min(300px,90vw)!important;}
        .table-wrap{overflow-x:auto;}
        table{min-width:520px;}
        .section-header{flex-direction:column;align-items:flex-start;gap:6px;}
        .action-btns{flex-direction:column;}
        .form-grid{grid-template-columns:1fr!important;}
        .modal-box{margin:8px;}
        }

        /* ══ EQUIPMENT ITEM LIST ══════════════════════════════════════════════════════ */
        .equip-item-list{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:5px;}
        .equip-item{display:flex;align-items:center;gap:8px;font-size:13px;color:#444;}
        .equip-item-dot{width:6px;height:6px;border-radius:50%;background:#3b82f6;flex-shrink:0;}
        .equip-qty{display:inline-flex;align-items:center;justify-content:center;
            background:#eff6ff;color:#3b82f6;border-radius:5px;
            font-size:11px;font-weight:700;padding:1px 7px;margin-left:4px;white-space:nowrap;}
                </style>
        </head>
        <body>

        <!-- ═══════════════════ SIDEBAR ═══════════════════════════════════════════ -->
        <button class="mobile-menu-btn" id="mobileMenuBtn" onclick="toggleMobileSidebar()"><i class="fas fa-bars"></i></button>
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleMobileSidebar()"></div>
        <div class="sidebar">
        <div class="sidebar-logo">
            <img src="image/logo.png" alt="AssetEase Logo" class="logo-image">
            <h2>ASSETEASE</h2>
        </div>

        <div class="sidebar-section-label">Main</div>
        <a href="dashboard.php"><i class="fas fa-th-large"></i> Overview</a>
        <a href="reservation_form.php"><i class="fas fa-plus-circle"></i> Reserve and Request</a>
        <a href="view_reservations.php" class="active"><i class="fas fa-list-check"></i> My Reservations</a>

        <hr class="sidebar-divider">
        <div class="sidebar-section-label">Support</div>
        <a href="notification.php">
            <i class="fas fa-bell"></i> Notifications
            <?php if($unread_count > 0): ?>
            <span style="background:#dc3545;color:#fff;font-size:10px;padding:1px 7px;border-radius:10px;margin-left:auto;"><?= $unread_count ?></span>
            <?php endif; ?>
        </a>
        <a href="settings.php"><i class="fas fa-cog"></i> Settings</a>
        <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>

        <div class="sidebar-promo">
            <strong>Need help?</strong>
            <p>Contact admin or check our help center for assistance.</p>
            <a href="settings.php">Go to Settings</a>
        </div>
        </div>

        <!-- ═══════════════════ MAIN ═══════════════════════════════════════════════ -->
        <div class="main">

        <!-- TOP HEADER -->
        <div class="top-header">
            <div class="header-left">
            <h1>My Reservations</h1>
            <p>Track and manage your room and equipment bookings.</p>
            </div>
            <div class="header-right">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search reservations...">
            </div>
            <div class="notif-wrap">
                <button class="notif-btn" onclick="toggleNotifDropdown(event)">
                <i class="fas fa-bell"></i>
                <?php if($unread_count > 0): ?>
                    <span class="notif-badge"><?= $unread_count ?></span>
                <?php endif; ?>
                </button>
                <div class="notif-dropdown" id="notifDropdown">
                <div class="notif-header">Notifications</div>
                <?php if(!empty($notifications)): ?>
                    <?php foreach($notifications as $n): ?>
                    <div class="notif-item">
                        <div class="ni-msg"><?= htmlspecialchars($n['message']) ?></div>
                        <div class="ni-time"><?= date('M d, h:i A', strtotime($n['created_at'])) ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="notif-empty"><i class="fas fa-bell-slash"></i> No notifications yet</div>
                <?php endif; ?>
                </div>
            </div>
            <div class="profile-pill">
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($userName) ?>&background=95122C&color=fff&rounded=true" alt="">
                <span><?= htmlspecialchars(strlen($userName) > 12 ? substr($userName,0,12).'…' : $userName) ?></span>
            </div>
            </div>
        </div>

        <!-- CONTENT -->
        <div class="content">

            <?php if($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i><?= $success ?></div>
            <?php endif; ?>
            <?php if($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><?= $error ?></div>
            <?php endif; ?>

            <div class="page-header">
            <h2><i class="fas fa-list-check" style="color:var(--red);"></i>My Reservations</h2>
            <p>View and manage your active and completed reservations below.</p>
            </div>

            <!-- ═══════════════════ ROOM RESERVATIONS ════════════════════════════════ -->
            <div class="section-header">
            <i class="fas fa-door-open"></i>
            <h3>Room Reservations</h3>
            </div>

            <!-- Pending Rooms -->
            <h4 style="font-size:14px;font-weight:600;color:#333;margin:18px 0 12px;"><i class="fas fa-hourglass-half" style="color:#ca8a04;margin-right:6px;"></i>Pending Requests</h4>
            <?php if ($pending_room_result->num_rows > 0): ?>
            <div class="table-card">
                <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Room</th>
                        <th>Date Range</th>
                        <th>Time</th>
                        <th>Purpose</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php while ($row = $pending_room_result->fetch_assoc()):
                        $purposeInfo = parsePurposeParts($row['purpose']);
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($row['resource_name']) ?></strong>
                            <?php if ($purposeInfo['department']): ?>
                                <div style="font-size:12px;color:#666;line-height:1.3;margin-top:4px;">Dept: <?= htmlspecialchars($purposeInfo['department']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= formatDateRange($row['booking_date'], $row['end_date']) ?></td>
                        <td><?= formatTimeRange($row['start_time'], $row['end_time']) ?></td>
                        <td><small><?= htmlspecialchars($purposeInfo['purpose']) ?></small></td>
                        <td><span class="badge badge-pending"><i class="fas fa-clock"></i> Pending</span></td>
                        <td>
                        <div class="action-btns">
                            <button class="btn-edit" onclick="openEditModal(
                                <?= $row['id'] ?>, 'room',
                                '<?= htmlspecialchars($row['resource_name'], ENT_QUOTES) ?>',
                                '<?= $row['booking_date'] ?>','<?= $row['end_date'] ?>',
                                '<?= $row['start_time'] ?>','<?= $row['end_time'] ?>',
                                '<?= htmlspecialchars($purposeInfo['purpose'], ENT_QUOTES) ?>'
                            )"><i class="fas fa-pen"></i> Edit</button>
                            <a href="?delete_id=<?= $row['id'] ?>" class="btn-delete" onclick="return confirm('Cancel this reservation?')">
                                <i class="fas fa-trash"></i> Cancel
                            </a>
                        </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <p>No pending room reservations</p>
                <small>You don't have any rooms waiting for approval</small>
            </div>
            <?php endif; ?>

            <!-- Approved Rooms -->
            <h4 style="font-size:14px;font-weight:600;color:#333;margin:24px 0 12px;"><i class="fas fa-check-circle" style="color:#16a34a;margin-right:6px;"></i>Approved Reservations</h4>
            <?php if ($approved_room_result->num_rows > 0): ?>
            <div class="table-card">
                <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Room</th>
                        <th>Date Range</th>
                        <th>Time</th>
                        <th>Purpose</th>
                        <th>Status</th>
                        <th>Confirmation</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php while ($row = $approved_room_result->fetch_assoc()):
                        $purposeInfo = parsePurposeParts($row['purpose']);
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($row['resource_name']) ?></strong>
                            <?php if ($purposeInfo['department']): ?>
                                <div style="font-size:12px;color:#666;line-height:1.3;margin-top:4px;">Dept: <?= htmlspecialchars($purposeInfo['department']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= formatDateRange($row['booking_date'], $row['end_date']) ?></td>
                        <td><?= formatTimeRange($row['start_time'], $row['end_time']) ?></td>
                        <td><small><?= htmlspecialchars($purposeInfo['purpose']) ?></small></td>
                        <td><span class="badge badge-approved"><i class="fas fa-check"></i> Approved</span></td>
                        <td><i class="fas fa-check-double" style="color:#16a34a;"></i> <small style="color:#16a34a;font-weight:600;">Confirmed</small></td>
                    </tr>
                    <?php endwhile; ?>

                    </tbody>
                </table>
                </div>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <p>No approved room reservations</p>
                <small>Your approved reservations will appear here</small>
            </div>
            <?php endif; ?>

            <!-- ═══════════════════ EQUIPMENT REQUESTS ════════════════════════════════ -->
            <div class="section-header" style="margin-top:40px;">
            <i class="fas fa-laptop"></i>
            <h3>Equipment Requests</h3>
            </div>

            <!-- Pending Equipment -->
            <h4 style="font-size:14px;font-weight:600;color:#333;margin:18px 0 12px;"><i class="fas fa-hourglass-half" style="color:#ca8a04;margin-right:6px;"></i>Pending Requests</h4>
            <?php if (!empty($pending_equip_result)): ?>
            <div class="table-card">
                <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Equipment</th>
                        <th>Date Range</th>
                        <th>Time</th>
                        <th>Availability</th>
                        <th>Purpose</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pending_equip_result as $row):
                        $names      = explode('|||', $row['resource_name']);
                        $qtys       = explode('|||', $row['quantities'] ?? '');
                        $purposeInfo = parsePurposeParts($row['purpose']);
                        // Build display label for modal (first item name)
                        $modalLabel = htmlspecialchars($names[0], ENT_QUOTES) . (count($names) > 1 ? ' + ' . (count($names)-1) . ' more' : '');
                    ?>
                    <tr>
                        <td>
                            <ul class="equip-item-list">
                            <?php foreach ($names as $idx => $ename): ?>
                                <li class="equip-item">
                                    <span class="equip-item-dot"></span>
                                    <?= htmlspecialchars(trim($ename)) ?>
                                    <?php if (!empty($qtys[$idx]) && intval($qtys[$idx]) > 0): ?>
                                        <span class="equip-qty">x<?= intval($qtys[$idx]) ?></span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                            </ul>
                            <?php if ($purposeInfo['department']): ?>
                                <div style="font-size:12px;color:#666;margin-top:6px;">Dept: <?= htmlspecialchars($purposeInfo['department']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= formatDateRange($row['booking_date'], $row['end_date']) ?></td>
                        <td><?= formatTimeRange($row['start_time'], $row['end_time']) ?></td>
                        <td>
                        <?php
                            // Show availability for each item in the batch
                            foreach ($names as $ename) {
                                preg_match('/^(.*?)\s*\(/', trim($ename), $m);
                                $key = !empty($m[1]) ? $m[1] : trim($ename);
                                $avail = $equipmentAvailability[$key] ?? null;
                                if ($avail):
                        ?>
                            <div style="margin-bottom:3px;">
                                <span class="badge <?= ($avail['available'] > 0) ? 'badge-available' : 'badge-unavailable' ?>">
                                    <?= htmlspecialchars($key) ?>: <?= $avail['available'] ?>/<?= $avail['total'] ?>
                                </span>
                            </div>
                        <?php   else: ?>
                            <div style="margin-bottom:3px;"><span class="badge badge-unavailable"><?= htmlspecialchars(trim($ename)) ?>: N/A</span></div>
                        <?php   endif;
                            }
                        ?>
                        </td>
                        <td><small><?= htmlspecialchars($purposeInfo['purpose']) ?></small></td>
                        <td><span class="badge badge-pending"><i class="fas fa-clock"></i> Pending</span></td>
                        <td>
                        <div class="action-btns">
                            <button class="btn-edit" onclick="openEditModal(
                                <?= $row['id'] ?>, 'equip',
                                '<?= $modalLabel ?>',
                                '<?= $row['booking_date'] ?>','<?= $row['end_date'] ?>',
                                '<?= $row['start_time'] ?>','<?= $row['end_time'] ?>',
                                '<?= htmlspecialchars($purposeInfo['purpose'], ENT_QUOTES) ?>'
                            )"><i class="fas fa-pen"></i> Edit</button>
                            <a href="?delete_id=<?= $row['id'] ?>" class="btn-delete" onclick="return confirm('Cancel this entire batch request?')">
                                <i class="fas fa-trash"></i> Cancel
                            </a>
                        </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <p>No pending equipment requests</p>
                <small>You don't have any equipment waiting for approval</small>
            </div>
            <?php endif; ?>

            <!-- Approved Equipment -->
            <h4 style="font-size:14px;font-weight:600;color:#333;margin:24px 0 12px;"><i class="fas fa-check-circle" style="color:#16a34a;margin-right:6px;"></i>Approved Requests</h4>
            <?php if (!empty($approved_equip_result)): ?>
            <div class="table-card">
                <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Equipment</th>
                        <th>Date Range</th>
                        <th>Time</th>
                        <th>Availability</th>
                        <th>Purpose</th>
                        <th>Status</th>
                        <th>Status Detail</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($approved_equip_result as $row):
                        $names      = explode('|||', $row['resource_name']);
                        $qtys       = explode('|||', $row['quantities'] ?? '');
                        $purposeInfo = parsePurposeParts($row['purpose']);
                    ?>
                    <tr>
                        <td>
                            <ul class="equip-item-list">
                            <?php foreach ($names as $idx => $ename): ?>
                                <li class="equip-item">
                                    <span class="equip-item-dot" style="background:#22c55e;"></span>
                                    <?= htmlspecialchars(trim($ename)) ?>
                                    <?php if (!empty($qtys[$idx]) && intval($qtys[$idx]) > 0): ?>
                                        <span class="equip-qty" style="background:#f0fdf4;color:#16a34a;"><?= intval($qtys[$idx]) ?>x</span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                            </ul>
                            <?php if ($purposeInfo['department']): ?>
                                <div style="font-size:12px;color:#666;margin-top:6px;">Dept: <?= htmlspecialchars($purposeInfo['department']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= formatDateRange($row['booking_date'], $row['end_date']) ?></td>
                        <td><?= formatTimeRange($row['start_time'], $row['end_time']) ?></td>
                        <td>
                        <?php
                            foreach ($names as $ename) {
                                preg_match('/^(.*?)\s*\(/', trim($ename), $m);
                                $key = !empty($m[1]) ? $m[1] : trim($ename);
                                $avail = $equipmentAvailability[$key] ?? null;
                                if ($avail):
                        ?>
                            <div style="margin-bottom:3px;">
                                <span class="badge <?= ($avail['available'] > 0) ? 'badge-available' : 'badge-unavailable' ?>">
                                    <?= htmlspecialchars($key) ?>: <?= $avail['available'] ?>/<?= $avail['total'] ?>
                                </span>
                            </div>
                        <?php   else: ?>
                            <div style="margin-bottom:3px;"><span class="badge badge-unavailable"><?= htmlspecialchars(trim($ename)) ?>: N/A</span></div>
                        <?php   endif;
                            }
                        ?>
                        </td>
                        <td><small><?= htmlspecialchars($purposeInfo['purpose']) ?></small></td>
                        <td><span class="badge badge-approved"><i class="fas fa-check"></i> Approved</span></td>
                        <td><i class="fas fa-box" style="color:#16a34a;"></i> <small style="color:#16a34a;font-weight:600;">Ready for Pickup</small></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <p>No approved equipment requests</p>
                <small>Your approved requests will appear here</small>
            </div>
            <?php endif; ?>

        </div><!-- /content -->
        </div><!-- /main -->

        <script>
        function toggleNotifDropdown(e) {
        e.stopPropagation();
        document.getElementById('notifDropdown').classList.toggle('active');
        }
        document.addEventListener('click', function() {
        document.getElementById('notifDropdown').classList.remove('active');
        });
        function toggleMobileSidebar() {
        document.querySelector('.sidebar').classList.toggle('open');
        document.getElementById('sidebarOverlay').classList.toggle('open');
        }

        // ── Edit Modal ──────────────────────────────────────────────────────────────
        function openEditModal(id, type, resource, startDate, endDate, startTime, endTime, purpose) {
            document.getElementById('edit_id').value       = id;
            document.getElementById('edit_type').value     = type;
            document.getElementById('edit_resource').textContent = resource;
            document.getElementById('edit_start_date').value    = startDate;
            document.getElementById('edit_end_date').value      = endDate;
            document.getElementById('edit_start_time').value    = startTime;
            document.getElementById('edit_end_time').value      = endTime;
            document.getElementById('edit_purpose').value       = purpose;

            // Update modal title label
            document.getElementById('modal_type_label').textContent =
                type === 'room' ? 'Room Reservation' : 'Equipment Request';

            document.getElementById('editModal').classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('open');
            document.body.style.overflow = '';
        }

        // Close on backdrop click
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) closeEditModal();
        });
        </script>

        <!-- ═══════════════════ EDIT MODAL ═════════════════════════════════════════ -->
        <div class="modal-overlay" id="editModal">
        <div class="modal-box">
            <div class="modal-head">
            <h3><i class="fas fa-pen" style="margin-right:8px;"></i>Edit <span id="modal_type_label">Reservation</span></h3>
            <button onclick="closeEditModal()" title="Close">&times;</button>
            </div>
            <form method="POST" action="">
            <div class="modal-body">
                <div class="modal-note">
                <i class="fas fa-info-circle"></i>
                Only <strong>Pending</strong> reservations can be edited. Date, time, and purpose can be updated.
                </div>
                <input type="hidden" name="edit_id"   id="edit_id">
                <input type="hidden" name="edit_type" id="edit_type">
                <div class="form-grid">
                <div class="form-group full">
                    <label>Resource</label>
                    <div class="resource-label" id="edit_resource"></div>
                </div>
                <div class="form-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" id="edit_start_date" required min="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" id="edit_end_date" min="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label>Start Time</label>
                    <input type="time" name="start_time" id="edit_start_time" required>
                </div>
                <div class="form-group">
                    <label>End Time</label>
                    <input type="time" name="end_time" id="edit_end_time" required>
                </div>
                <div class="form-group full">
                    <label>Purpose</label>
                    <textarea name="purpose" id="edit_purpose" required placeholder="Describe the purpose of your reservation..."></textarea>
                </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-modal-cancel" onclick="closeEditModal()">Cancel</button>
                <button type="submit" class="btn-modal-save"><i class="fas fa-save" style="margin-right:6px;"></i>Save Changes</button>
            </div>
            </form>
        </div>
        </div>

        <!-- Bottom navigation for mobile -->
        <nav class="bottom-nav">
        <div class="bottom-nav-inner">
            <a href="dashboard.php"><i class="fas fa-house"></i>Home</a>
            <a href="reservation_form.php"><i class="fas fa-plus-circle"></i>Reserve</a>
            <a href="view_reservations.php" class="active"><i class="fas fa-list-check"></i>Bookings</a>
            <a href="notification.php"><i class="fas fa-bell"></i>Alerts</a>
            <a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
        </div>
        </nav>

        </body>
        </html>