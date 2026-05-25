  <?php
  /**
   * dashboard.php — AssetEase Overview Dashboard
   */
  session_start();
  require_once 'config.php';
  require_once 'notification_handler.php';
  require_once 'email_notification.php';

  if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }
  if (isset($_SESSION['role']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'super_admin')) {
      header("Location: admin_dashboard.php"); exit;
  }

  $userId   = $_SESSION['user_id'];
  $minDate  = date('Y-m-d');

  // Fresh user data
  $userStmt = $conn->prepare("SELECT uname, email FROM users WHERE id = ?");
  $userStmt->bind_param("i", $userId);
  $userStmt->execute();
  $userRow  = $userStmt->get_result()->fetch_assoc();
  $userName  = $userRow['uname']  ?? 'User';
  $userEmail = $userRow['email']  ?? '';

  // PRG flash messages
  $success = '';
  if (isset($_SESSION['success'])) { $success = $_SESSION['success']; unset($_SESSION['success']); }

  // Mark notifications read (AJAX)
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_notifications_read'])) {
      $s = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
      $s->bind_param("i", $userId); $s->execute(); exit;
  }

  // Fetch notifications
  $stmt = $conn->prepare("SELECT id, message, created_at, is_read FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
  $stmt->bind_param("i", $userId); $stmt->execute();
  $notificationsResult = $stmt->get_result();
  $notifications = []; $unread_count = 0;
  while ($notif = $notificationsResult->fetch_assoc()) {
      $notifications[] = $notif;
      if (($notif['is_read'] ?? 0) == 0) $unread_count++;
  }

  // ── Stats ──────────────────────────────────────────────────────────────────
  // Total room reservations this month
  $rTotalStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM room_reservations WHERE user_id = ? AND MONTH(booking_date)=MONTH(NOW()) AND YEAR(booking_date)=YEAR(NOW())");
  $rTotalStmt->bind_param("i", $userId); $rTotalStmt->execute();
  $totalRoomMonth = $rTotalStmt->get_result()->fetch_assoc()['cnt'] ?? 0;

  // Pending room reservations
  $rPendStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM room_reservations WHERE user_id = ? AND status='Pending'");
  $rPendStmt->bind_param("i", $userId); $rPendStmt->execute();
  $pendingRooms = $rPendStmt->get_result()->fetch_assoc()['cnt'] ?? 0;

  // Total equipment borrows this month
  $bTotalStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM borrows WHERE user_id = ? AND MONTH(borrow_date)=MONTH(NOW()) AND YEAR(borrow_date)=YEAR(NOW())");
  $bTotalStmt->bind_param("i", $userId); $bTotalStmt->execute();
  $totalBorrowMonth = $bTotalStmt->get_result()->fetch_assoc()['cnt'] ?? 0;

  // Pending borrows
  $bPendStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM borrows WHERE user_id = ? AND status='Pending'");
  $bPendStmt->bind_param("i", $userId); $bPendStmt->execute();
  $pendingBorrows = $bPendStmt->get_result()->fetch_assoc()['cnt'] ?? 0;

  // Approved this month (combined)
  $approvedStmt = $conn->prepare("
      SELECT (
          (SELECT COUNT(*) FROM room_reservations WHERE user_id=? AND status='Approved' AND MONTH(booking_date)=MONTH(NOW()))
          +
          (SELECT COUNT(*) FROM borrows WHERE user_id=? AND status='Approved' AND MONTH(borrow_date)=MONTH(NOW()))
      ) as cnt
  ");
  $approvedStmt->bind_param("ii", $userId, $userId); $approvedStmt->execute();
  $approvedMonth = $approvedStmt->get_result()->fetch_assoc()['cnt'] ?? 0;

  // ── Reservation type breakdown for donut chart ─────────────────────────────
  $roomCount    = $totalRoomMonth;
  $borrowCount  = $totalBorrowMonth;
  $totalCount   = $roomCount + $borrowCount ?: 1; // avoid div/0
  $roomPct      = round($roomCount   / $totalCount * 100);
  $borrowPct    = round($borrowCount / $totalCount * 100);

  // ── Recent history — Rooms (last 5) ───────────────────────────────────────
  $recentRoomStmt = $conn->prepare("
      SELECT id, room_name AS resource, booking_date AS rdate,
            start_time, end_time, status, created_at
      FROM room_reservations
      WHERE user_id = ?
      ORDER BY created_at DESC
      LIMIT 5
  ");
  $recentRoomStmt->bind_param("i", $userId); $recentRoomStmt->execute();
  $recentRooms = $recentRoomStmt->get_result()->fetch_all(MYSQLI_ASSOC);

  // ── Recent history — Equipment grouped by batch (last 5) ──────────────────
  $recentEquipStmt = $conn->prepare("
      SELECT MIN(id) AS id,
            GROUP_CONCAT(equipment_name ORDER BY equipment_name SEPARATOR '|||') AS resource,
            GROUP_CONCAT(quantity       ORDER BY equipment_name SEPARATOR '|||') AS quantities,
            borrow_date AS rdate,
            start_time, end_time,
            MAX(status) AS status,
            MIN(created_at) AS created_at
      FROM borrows
      WHERE user_id = ?
      GROUP BY borrow_date, start_time, end_time, DATE(created_at)
      ORDER BY created_at DESC
      LIMIT 5
  ");
  $recentEquipStmt->bind_param("i", $userId); $recentEquipStmt->execute();
  $recentEquips = $recentEquipStmt->get_result()->fetch_all(MYSQLI_ASSOC);
  ?>
  <!DOCTYPE html>
  <html lang="en">
  <head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard — AssetEase</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
    width:var(--sidebar-w);
    height:100vh;
    background:linear-gradient(180deg,var(--dark) 0%,var(--red) 100%);
    color:#fff;
    position:fixed;
    overflow-y:auto;
    display:flex;
    flex-direction:column;
    z-index:1001;
  }

  /* Logo */
  .sidebar-logo{
    display:flex;
    align-items:center;
    gap:12px;
    padding:28px 25px 22px;
    border-bottom:1px solid rgba(255,255,255,0.1);
  }

  .logo-image{
    width:42px;
    height:42px;
    object-fit:cover;
    border-radius:10px;
    background:#fff;
    padding:2px;
  }

  .sidebar-logo h2{
    color:#FFD700;
    font-size:20px;
    font-weight:700;
    letter-spacing:1.5px;
  }

  /* Labels */
  .sidebar-section-label{
    padding:18px 25px 6px;
    font-size:10px;
    font-weight:600;
    letter-spacing:2px;
    color:rgba(255,255,255,0.45);
    text-transform:uppercase;
  }

  /* Links */
  .sidebar a{
    display:flex;
    align-items:center;
    gap:14px;
    padding:13px 25px;
    color:rgba(255,255,255,0.8);
    text-decoration:none;
    transition:0.25s ease;
    font-size:0.9rem;
    font-weight:500;
    border-left:3px solid transparent;
    margin:1px 0;
  }

  .sidebar a i{
    width:32px;
    font-size:16px;
    opacity:0.85;
  }

  .sidebar a:hover{
    background:rgba(255,255,255,0.10);
    color:#fff;
    border-left-color:rgba(255,255,255,0.4);
  }

  .sidebar a.active{
    background:rgba(255,255,255,0.15);
    color:#fff;
    border-left-color:#FFD700;
    font-weight:600;
  }

  .sidebar a.active i{
    opacity:1;
  }

  /* Divider */
  .sidebar-divider{
    border:none;
    border-top:1px solid rgba(255,255,255,0.1);
    margin:10px 20px;
  }

  /* Promo Box */
  .sidebar-promo{
    margin:auto 15px 20px;
    padding:18px;
    background:linear-gradient(135deg,rgba(255,215,0,0.2),rgba(255,255,255,0.08));
    border:1px solid rgba(255,215,0,0.3);
    border-radius:12px;
  }

  .sidebar-promo p{
    font-size:12px;
    line-height:1.5;
    color:rgba(255,255,255,0.85);
  }

  .sidebar-promo strong{
    color:#FFD700;
    font-size:13px;
  }

  .sidebar-promo a{
    display:inline-block;
    margin-top:10px;
    padding:7px 16px;
    background:#FFD700;
    color:var(--dark);
    border-radius:8px;
    font-size:11px;
    font-weight:700;
    text-decoration:none;
    border:none;
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

  /* filter bar */
  .filter-bar{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;}
  .filter-bar-left{display:flex;gap:10px;}
  .filter-btn{
    display:flex;align-items:center;gap:8px;padding:9px 18px;
    background:#fff;border:1.5px solid #e8e0de;border-radius:25px;
    font-size:13px;font-weight:500;color:#555;cursor:pointer;transition:0.2s;
  }
  .filter-btn:hover{border-color:var(--red);color:var(--red);}
  .filter-btn i{font-size:13px;}
  .export-btn{
    display:flex;align-items:center;gap:8px;padding:10px 22px;
    background:var(--red);color:#fff;border:none;border-radius:25px;
    font-size:13px;font-weight:600;cursor:pointer;transition:0.2s;
  }
  .export-btn:hover{background:var(--red-dark);}

  /* ══ STAT CARDS (top row) ═══════════════════════════════════════════════════ */
  .stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:22px;}
  .stat-card{
    background:#fff;border-radius:var(--radius);padding:24px 26px;
    box-shadow:var(--shadow);display:flex;justify-content:space-between;align-items:flex-start;
  }
  .stat-card-body{}
  .stat-card-label{font-size:13px;color:#888;font-weight:500;margin-bottom:8px;}
  .stat-card-value{font-size:32px;font-weight:700;color:var(--dark);line-height:1;}
  .stat-card-sub{display:flex;align-items:center;gap:5px;margin-top:8px;font-size:12px;}
  .stat-card-sub.up{color:#22c55e;}
  .stat-card-sub.down{color:#ef4444;}
  .stat-card-sub.neutral{color:#888;}
  .stat-card-icon{
    width:52px;height:52px;border-radius:12px;
    display:flex;align-items:center;justify-content:center;font-size:22px;
  }
  .stat-card-icon.red{background:#fef2f2;color:#95122C;}
  .stat-card-icon.orange{background:#fff7ed;color:#f97316;}
  .stat-card-icon.green{background:#f0fdf4;color:#22c55e;}

  /* ══ BOTTOM ROW (chart + table) ═════════════════════════════════════════════ */
  .bottom-row{display:grid;grid-template-columns:340px 1fr;gap:20px;}

  /* chart card */
  .chart-card{background:#fff;border-radius:var(--radius);padding:24px;box-shadow:var(--shadow);}
  .card-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;}
  .card-header h3{font-size:16px;font-weight:700;color:var(--dark);}
  .card-header a{font-size:13px;color:var(--red);font-weight:600;text-decoration:none;}
  .card-header a:hover{text-decoration:underline;}

  .chart-legend{margin-bottom:18px;}
  .legend-item{display:flex;justify-content:space-between;align-items:center;padding:6px 0;}
  .legend-item-left{display:flex;align-items:center;gap:10px;font-size:13px;color:#555;}
  .legend-dot{width:11px;height:11px;border-radius:50%;}
  .legend-pct{font-size:13px;font-weight:600;color:var(--dark);}
  .donut-wrap{position:relative;display:flex;justify-content:center;}
  .donut-center{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);text-align:center;}
  .donut-center .dc-val{font-size:24px;font-weight:700;color:var(--dark);}
  .donut-center .dc-lbl{font-size:11px;color:#888;}

  /* history table card */
  .history-card{background:#fff;border-radius:var(--radius);padding:24px;box-shadow:var(--shadow);}
  .history-table{width:100%;border-collapse:collapse;}
  .history-table th{
    padding:10px 14px;font-size:12px;font-weight:600;
    color:#aaa;text-align:left;border-bottom:1px solid #f0f0f0;
    text-transform:uppercase;letter-spacing:0.5px;
  }
  .history-table td{padding:13px 14px;font-size:13px;color:#444;border-bottom:1px solid #f8f8f8;}
  .history-table tr:last-child td{border-bottom:none;}
  .history-table tr:hover td{background:#fafafa;}

  .res-num{
    display:inline-flex;align-items:center;padding:4px 10px;
    border:1.5px solid #e5e7eb;border-radius:6px;
    font-size:12px;font-weight:600;color:#555;
  }
  .badge-status{
    display:inline-flex;align-items:center;gap:5px;
    padding:4px 10px;border-radius:20px;font-size:12px;font-weight:600;
  }
  .badge-status.approved{background:#dcfce7;color:#16a34a;}
  .badge-status.pending{background:#fef9c3;color:#ca8a04;}
  .badge-status.rejected{background:#fee2e2;color:#dc2626;}
  .badge-status i{font-size:11px;}

  .type-badge{
    display:inline-flex;align-items:center;gap:5px;
    padding:3px 9px;border-radius:5px;font-size:11px;font-weight:600;
  }
  .type-badge.room{background:#fef2f2;color:#95122C;}
  .type-badge.equip{background:#eff6ff;color:#3b82f6;}

  .table-actions button{
    background:none;border:none;color:#ccc;font-size:16px;cursor:pointer;padding:4px 6px;
  }
  .table-actions button:hover{color:#888;}

  /* ══ QUICK ACTIONS ══════════════════════════════════════════════════════════ */
  .quick-row{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:22px;}
  .quick-card{
    background:#fff;border-radius:var(--radius);padding:22px 24px;
    box-shadow:var(--shadow);display:flex;align-items:center;gap:18px;
    text-decoration:none;transition:0.25s;border:2px solid transparent;
  }
  .quick-card:hover{border-color:var(--red);transform:translateY(-2px);box-shadow:var(--shadow-md);}
  .quick-icon{
    width:54px;height:54px;border-radius:12px;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;font-size:22px;
  }
  .quick-icon.red{background:#fef2f2;color:var(--red);}
  .quick-icon.blue{background:#eff6ff;color:#3b82f6;}
  .quick-body h4{font-size:14px;font-weight:700;color:var(--dark);margin-bottom:3px;}
  .quick-body p{font-size:12px;color:#888;}

  /* ══ CHAT BOT ═══════════════════════════════════════════════════════════════ */
  .chat-toggle{
    position:fixed;bottom:22px;right:22px;width:56px;height:56px;
    background:var(--red);color:#fff;border:none;border-radius:50%;
    cursor:pointer;font-size:22px;z-index:999;box-shadow:var(--shadow-md);transition:0.25s;
  }
  .chat-toggle:hover{transform:scale(1.08);background:var(--red-dark);}
  .chat-bot{
    position:fixed;bottom:90px;right:22px;width:370px;height:520px;
    background:#fff;border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,0.2);
    z-index:999;display:none;flex-direction:column;overflow:hidden;
  }
  .chat-bot.active{display:flex;}
  .chat-header{background:var(--red);color:#fff;padding:18px 20px;display:flex;justify-content:space-between;align-items:center;}
  .chat-header h3{font-size:15px;font-weight:700;}
  .chat-header button{background:none;border:none;color:#fff;cursor:pointer;font-size:18px;}
  .chat-messages{flex:1;overflow-y:auto;padding:18px;display:flex;flex-direction:column;gap:10px;background:#f9f9f9;}
  .message{padding:10px 14px;border-radius:10px;max-width:85%;word-wrap:break-word;font-size:13px;line-height:1.45;}
  .message.user{background:var(--red);color:#fff;align-self:flex-end;}
  .message.bot{background:#e8e8e8;color:#333;align-self:flex-start;}
  .chat-input-area{display:flex;gap:8px;padding:12px 14px;border-top:1px solid #eee;background:#fff;}
  .chat-input-area input{flex:1;padding:9px 14px;border:1.5px solid #ddd;border-radius:8px;font-size:13px;font-family:'Poppins';outline:none;}
  .chat-input-area input:focus{border-color:var(--red);}
  .chat-input-area button{background:var(--red);color:#fff;border:none;padding:9px 16px;border-radius:8px;cursor:pointer;font-weight:600;}

  /* empty state */
  .empty-state{text-align:center;padding:40px 20px;color:#bbb;}
  .empty-state i{font-size:40px;margin-bottom:12px;display:block;}
  .empty-state p{font-size:14px;}

  /* ══ MOBILE RESPONSIVE ══════════════════════════════════════════════════════ */
  .mobile-menu-btn{
    display:none;
    position:fixed;top:13px;left:14px;z-index:1200;
    background:var(--red);color:#fff;border:none;
    width:44px;height:44px;border-radius:10px;
    font-size:20px;cursor:pointer;
    align-items:center;justify-content:center;
    box-shadow:0 2px 8px rgba(0,0,0,0.3);
  }
  .sidebar-overlay{
    display:none;position:fixed;inset:0;
    background:rgba(0,0,0,0.5);z-index:1100;
  }
  .sidebar-overlay.open{display:block;}
  .bottom-nav{
    display:none;position:fixed;
    bottom:0;left:0;right:0;
    background:linear-gradient(90deg,#100C08 0%,#95122C 100%);
    z-index:1050;box-shadow:0 -2px 10px rgba(0,0,0,0.25);
  }
  .bottom-nav-inner{display:flex;width:100%;}
  .bottom-nav a{
    flex:1;display:flex;flex-direction:column;align-items:center;
    justify-content:center;color:rgba(255,255,255,0.65);
    text-decoration:none;padding:10px 0 8px;font-size:10px;gap:3px;
    transition:color 0.2s;font-family:'Poppins',sans-serif;
  }
  .bottom-nav a i{font-size:18px;}
  .bottom-nav a.active,.bottom-nav a:hover{color:#FFD700;}

  @media(max-width:768px){
    .sidebar{
      transform:translateX(-260px);
      transition:transform 0.3s ease;
      z-index:1150 !important;
    }
    .sidebar.open{transform:translateX(0);}
    .mobile-menu-btn{display:flex;}
    .main{margin-left:0 !important;width:100% !important;}
    .top-header{
      left:0 !important;right:0 !important;
      width:100% !important;
      padding:11px 14px 11px 68px !important;
    }
    .header-left h1{font-size:16px !important;}
    .header-left p{font-size:11px !important;}
    .search-box{display:none !important;}
    .profile-pill span{display:none !important;}
    .profile-pill{padding:4px !important;gap:0 !important;}
    .content{padding:80px 14px 90px !important;}
    .stats-row{grid-template-columns:1fr !important;}
    .bottom-row{grid-template-columns:1fr !important;}
    .quick-row{grid-template-columns:1fr !important;}
    .filter-bar{flex-direction:column;align-items:flex-start;gap:10px;}
    .filter-bar-left{flex-wrap:wrap;}
    .bottom-nav{display:block;}
    .chat-toggle{bottom:72px !important;right:14px !important;}
    .chat-bot{
      width:calc(100vw - 24px) !important;
      right:12px !important;left:12px !important;
      bottom:130px !important;
    }
    .notif-dropdown{width:min(300px,90vw) !important;}
    .stat-card-value{font-size:26px !important;}
    .history-table th:nth-child(3),
    .history-table td:nth-child(3){display:none;}
  }
  /* ══ HISTORY TABS ══════════════════════════════════════════════════════════ */
  .hist-tabs{display:flex;gap:0;border-bottom:2px solid #f0f0f0;margin-bottom:18px;}
  .hist-tab{
    padding:9px 20px;font-size:13px;font-weight:600;color:#aaa;
    cursor:pointer;border:none;background:none;
    border-bottom:2px solid transparent;margin-bottom:-2px;
    transition:0.2s;display:flex;align-items:center;gap:7px;
  }
  .hist-tab:hover{color:var(--dark);}
  .hist-tab.active{color:var(--red);border-bottom-color:var(--red);}
  .hist-tab .tab-count{
    background:#f0f0f0;color:#888;font-size:10px;font-weight:700;
    padding:2px 7px;border-radius:10px;
  }
  .hist-tab.active .tab-count{background:#fef2f2;color:var(--red);}
  .hist-panel{display:none;}
  .hist-panel.active{display:block;}

  /* equipment item list inside history table */
  .h-equip-list{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:4px;}
  .h-equip-item{display:flex;align-items:center;gap:6px;font-size:13px;color:#444;}
  .h-equip-dot{width:5px;height:5px;border-radius:50%;background:#3b82f6;flex-shrink:0;}
  .h-equip-qty{background:#eff6ff;color:#3b82f6;font-size:10px;font-weight:700;
    padding:1px 6px;border-radius:4px;margin-left:2px;}

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
    <a href="dashboard.php" class="active"><i class="fas fa-th-large"></i> Overview</a>
    <a href="reservation_form.php"><i class="fas fa-plus-circle"></i> Reserve and Request</a>
    <a href="view_reservations.php"><i class="fas fa-list-check"></i> My Reservations</a>

    <hr class="sidebar-divider">
    <div class="sidebar-section-label">Support</div>
    <a href="notification.php">
      <i class="fas fa-bell"></i> Notifications
      <?php if($unread_count>0): ?>
        <span style="background:#dc3545;color:#fff;font-size:10px;padding:1px 6px;border-radius:10px;margin-left:auto;"><?= $unread_count ?></span>
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

  <!-- ═══════════════════ TOP HEADER ═════════════════════════════════════════ -->
  <div class="main">
  <div class="top-header">
    <div class="header-left">
      <h1>Hello, <?= htmlspecialchars($userName) ?>!</h1>
      <p>Welcome back — here's your booking overview.</p>
    </div>
    <div class="header-right">
      <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" placeholder="Search reservations...">
      </div>
      <!-- Notification Bell -->
      <div class="notif-wrap">
        <button class="notif-btn" onclick="toggleNotifications(event)">
          <i class="fas fa-bell"></i>
          <?php if($unread_count > 0): ?>
            <span class="notif-badge" id="notifBadge"><?= $unread_count ?></span>
          <?php endif; ?>
        </button>
        <div class="notif-dropdown" id="notifDropdown">
          <div class="notif-header">Notifications</div>
          <?php if (!empty($notifications)): ?>
            <?php foreach($notifications as $n): ?>
              <div class="notif-item">
                <div class="ni-msg"><?= htmlspecialchars($n['message']) ?></div>
                <div class="ni-time"><?= date('M d, h:i A', strtotime($n['created_at'])) ?></div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="notif-empty"><i class="fas fa-bell-slash" style="font-size:24px;display:block;margin-bottom:8px;"></i>No notifications yet</div>
          <?php endif; ?>
        </div>
      </div>
      <!-- Profile -->
      <div class="profile-pill">
        <img src="https://ui-avatars.com/api/?name=<?= urlencode($userName) ?>&background=95122C&color=fff&rounded=true" alt="">
        <span><?= htmlspecialchars(strlen($userName) > 12 ? substr($userName,0,12).'…' : $userName) ?></span>
      </div>
    </div>
  </div>

  <!-- ═══════════════════ CONTENT ════════════════════════════════════════════ -->
  <div class="content">

    <!-- Success Flash Message -->
    <?php if($success): ?>
      <div style="display:flex;align-items:center;gap:10px;padding:14px 18px;background:#dcfce7;color:#15803d;border-radius:10px;margin-bottom:20px;font-size:13px;font-weight:500;border-left:4px solid #22c55e;">
        <i class="fas fa-check-circle"></i><?= htmlspecialchars($success) ?>
      </div>
    <?php endif; ?>

    <!-- Filter Bar -->
    <div class="filter-bar">
      <div class="filter-bar-left">
        <button class="filter-btn"><i class="fas fa-sliders-h"></i> Filters</button>
        <button class="filter-btn"><i class="far fa-calendar-alt"></i> This Month <i class="fas fa-chevron-down" style="font-size:10px;margin-left:4px;"></i></button>
      </div>
      <button class="export-btn" onclick="window.print()"><i class="fas fa-file-export"></i> Export PDF</button>
    </div>

    <!-- Quick Action Cards -->
    <div class="quick-row">
      <a href="reservation_form.php" class="quick-card">
        <div class="quick-icon red"><i class="fas fa-door-open"></i></div>
        <div class="quick-body">
          <h4>Reserve a Room</h4>
          <p>Book conference rooms, labs, and more</p>
        </div>
        <i class="fas fa-chevron-right" style="color:#ccc;margin-left:auto;"></i>
      </a>
      <a href="reservation_form.php" class="quick-card">
        <div class="quick-icon blue"><i class="fas fa-laptop"></i></div>
        <div class="quick-body">
          <h4>Request Equipment</h4>
          <p>Borrow projectors, cameras, devices & more</p>
        </div>
        <i class="fas fa-chevron-right" style="color:#ccc;margin-left:auto;"></i>
      </a>
    </div>

    <!-- Stat Cards -->
    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-card-body">
          <div class="stat-card-label">Room Reservations</div>
          <div class="stat-card-value"><?= $totalRoomMonth ?></div>
          <div class="stat-card-sub neutral"><i class="fas fa-circle-dot"></i><?= $pendingRooms ?> pending approval</div>
        </div>
        <div class="stat-card-icon red"><i class="fas fa-door-open"></i></div>
      </div>
      <div class="stat-card">
        <div class="stat-card-body">
          <div class="stat-card-label">Equipment Requests</div>
          <div class="stat-card-value"><?= $totalBorrowMonth ?></div>
          <div class="stat-card-sub neutral"><i class="fas fa-circle-dot"></i><?= $pendingBorrows ?> pending approval</div>
        </div>
        <div class="stat-card-icon orange"><i class="fas fa-laptop"></i></div>
      </div>
      <div class="stat-card">
        <div class="stat-card-body">
          <div class="stat-card-label">Approved This Month</div>
          <div class="stat-card-value"><?= $approvedMonth ?></div>
          <div class="stat-card-sub up"><i class="fas fa-arrow-trend-up"></i> All confirmed</div>
        </div>
        <div class="stat-card-icon green"><i class="fas fa-circle-check"></i></div>
      </div>
    </div>

    <!-- Bottom Row: Donut + History Table -->
    <div class="bottom-row">

      <!-- Donut Chart -->
      <div class="chart-card">
        <div class="card-header">
          <h3>Breakdown</h3>
          <a href="view_reservations.php">more &rsaquo;</a>
        </div>
        <div class="chart-legend">
          <div class="legend-item">
            <div class="legend-item-left"><span class="legend-dot" style="background:#95122C;"></span> Room Reservations</div>
            <span class="legend-pct"><?= $roomPct ?>%</span>
          </div>
          <div class="legend-item">
            <div class="legend-item-left"><span class="legend-dot" style="background:#3b82f6;"></span> Equipment Requests</div>
            <span class="legend-pct"><?= $borrowPct ?>%</span>
          </div>
        </div>
        <div class="donut-wrap">
          <canvas id="donutChart" width="200" height="200"></canvas>
          <div class="donut-center">
            <div class="dc-val"><?= $totalRoomMonth + $totalBorrowMonth ?></div>
            <div class="dc-lbl">Total<br>this month</div>
          </div>
        </div>
      </div>

      <!-- History Table -->
      <div class="history-card">
        <div class="card-header">
          <h3>Reservation History</h3>
          <a href="view_reservations.php">more &rsaquo;</a>
        </div>

        <!-- Tab switcher -->
        <div class="hist-tabs">
          <button class="hist-tab active" onclick="switchHistTab('rooms', this)">
            <i class="fas fa-door-open"></i> Rooms
            <span class="tab-count"><?= count($recentRooms) ?></span>
          </button>
          <button class="hist-tab" onclick="switchHistTab('equip', this)">
            <i class="fas fa-laptop"></i> Equipment
            <span class="tab-count"><?= count($recentEquips) ?></span>
          </button>
        </div>

        <!-- Rooms Panel -->
        <div class="hist-panel active" id="hist-panel-rooms">
          <?php if (!empty($recentRooms)): ?>
          <table class="history-table">
            <thead>
              <tr>
                <th>Ref #</th>
                <th>Room</th>
                <th>Date &amp; Time</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($recentRooms as $row):
                $sc = strtolower($row['status']);
                $ic = $sc === 'approved' ? 'fa-check' : ($sc === 'rejected' ? 'fa-xmark' : 'fa-hourglass-half');
              ?>
              <tr>
                <td><span class="res-num">#<?= str_pad($row['id'], 6, '0', STR_PAD_LEFT) ?></span></td>
                <td><?= htmlspecialchars($row['resource']) ?></td>
                <td><?= date('M d, Y', strtotime($row['rdate'])) ?><br>
                    <small style="color:#aaa;"><?= date('h:i A', strtotime($row['start_time'])) ?> – <?= date('h:i A', strtotime($row['end_time'])) ?></small></td>
                <td>
                  <span class="badge-status <?= $sc ?>">
                    <i class="fas <?= $ic ?>"></i> <?= ucfirst($row['status']) ?>
                  </span>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php else: ?>
            <div class="empty-state">
              <i class="fas fa-door-open"></i>
              <p>No room reservations yet.</p>
            </div>
          <?php endif; ?>
        </div>

        <!-- Equipment Panel -->
        <div class="hist-panel" id="hist-panel-equip">
          <?php if (!empty($recentEquips)): ?>
          <table class="history-table">
            <thead>
              <tr>
                <th>Ref #</th>
                <th>Equipment</th>
                <th>Date &amp; Time</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($recentEquips as $row):
                $sc    = strtolower($row['status']);
                $ic    = $sc === 'approved' ? 'fa-check' : ($sc === 'rejected' ? 'fa-xmark' : 'fa-hourglass-half');
                $names = explode('|||', $row['resource']);
                $qtys  = explode('|||', $row['quantities'] ?? '');
              ?>
              <tr>
                <td><span class="res-num">#<?= str_pad($row['id'], 6, '0', STR_PAD_LEFT) ?></span></td>
                <td>
                  <ul class="h-equip-list">
                  <?php foreach ($names as $ni => $en): ?>
                    <li class="h-equip-item">
                      <span class="h-equip-dot"></span>
                      <?= htmlspecialchars(trim($en)) ?>
                      <?php if (!empty($qtys[$ni]) && intval($qtys[$ni]) > 0): ?>
                        <span class="h-equip-qty">x<?= intval($qtys[$ni]) ?></span>
                      <?php endif; ?>
                    </li>
                  <?php endforeach; ?>
                  </ul>
                </td>
                <td><?= date('M d, Y', strtotime($row['rdate'])) ?><br>
                    <small style="color:#aaa;"><?= date('h:i A', strtotime($row['start_time'])) ?> – <?= date('h:i A', strtotime($row['end_time'])) ?></small></td>
                <td>
                  <span class="badge-status <?= $sc ?>">
                    <i class="fas <?= $ic ?>"></i> <?= ucfirst($row['status']) ?>
                  </span>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php else: ?>
            <div class="empty-state">
              <i class="fas fa-laptop"></i>
              <p>No equipment requests yet.</p>
            </div>
          <?php endif; ?>
        </div>

      </div>

    </div><!-- /bottom-row -->
  </div><!-- /content -->
  </div><!-- /main -->

  <!-- ═══════════════════ CHAT BOT ════════════════════════════════════════════ -->
  <button class="chat-toggle" onclick="toggleChat()"><i class="fas fa-comments"></i></button>
  <div class="chat-bot" id="chatBot">
    <div class="chat-header">
      <h3><i class="fas fa-robot" style="margin-right:8px;"></i>AssetEase Assistant</h3>
      <button onclick="toggleChat()"><i class="fas fa-times"></i></button>
    </div>
    <div class="chat-messages" id="chatMessages">
      <div class="message bot">Hello! I can help you with room reservations and equipment requests. What do you need?</div>
    </div>
    <div class="chat-input-area">
      <input type="text" id="chatInput" placeholder="Type your message..." onkeypress="handleChatEnter(event)">
      <button onclick="sendMessage()"><i class="fas fa-paper-plane"></i></button>
    </div>
  </div>

  <script>
  // ── Donut Chart ─────────────────────────────────────────────────────────────
  const ctx = document.getElementById('donutChart').getContext('2d');
  new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: ['Room Reservations', 'Equipment Requests'],
      datasets: [{
        data: [<?= $roomCount ?>, <?= $borrowCount ?>],
        backgroundColor: ['#95122C', '#3b82f6'],
        borderWidth: 0,
        hoverOffset: 6
      }]
    },
    options: {
      cutout: '72%',
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: ctx => ` ${ctx.label}: ${ctx.raw}`
          }
        }
      },
      animation: { animateRotate: true, duration: 800 }
    }
  });

  // ── Notifications ────────────────────────────────────────────────────────────
  function toggleNotifications(e) {
    e.stopPropagation();
    const d = document.getElementById('notifDropdown');
    d.classList.toggle('active');
    if (d.classList.contains('active')) {
      const badge = document.getElementById('notifBadge');
      if (badge) badge.style.display = 'none';
      fetch(location.href, {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'mark_all_notifications_read=1'
      }).catch(()=>{});
    }
  }
  document.addEventListener('click', () => {
    document.getElementById('notifDropdown').classList.remove('active');
  });

  // Chat Bot Logic
  function toggleChat() { 
      document.getElementById('chatBot').classList.toggle('active'); 
  }

  function handleChatEnter(e) { 
      if (e.key === 'Enter') sendMessage(); 
  }

  // Token is embedded directly by PHP at page render — no extra HTTP request needed.
  // This eliminates the JSON parse error from chatbot_api.php output corruption.
  const GITHUB_TOKEN = '<?php echo defined("GITHUB_TOKEN") ? addslashes(GITHUB_TOKEN) : ""; ?>';

  async function sendMessage() {
      const input = document.getElementById('chatInput');
      const message = input.value.trim();
      if (!message) return;

      const chatMessages = document.getElementById('chatMessages');

      // 1. Append User Message to UI
      chatMessages.insertAdjacentHTML('beforeend', `<div class="message user">${message}</div>`);
      input.value = '';
      chatMessages.scrollTop = chatMessages.scrollHeight;

      // 2. Add a visual loading typing indicator
      const loadingId = 'bot-loading-' + Date.now();
      chatMessages.insertAdjacentHTML('beforeend', `<div class="message bot" id="${loadingId}"><i>Thinking...</i></div>`);
      chatMessages.scrollTop = chatMessages.scrollHeight;

      if (!GITHUB_TOKEN) {
          document.getElementById(loadingId).remove();
          chatMessages.insertAdjacentHTML('beforeend', `<div class="message bot" style="color:#721c24;background:#f8d7da;">GitHub token not set. Add GITHUB_TOKEN to config.php</div>`);
          return;
      }

      try {
          // 3. Call GitHub Models API directly from the browser
          //    Browser has no outbound restrictions — bypasses InfinityFree block entirely
          const aiRes = await fetch('https://models.inference.ai.azure.com/chat/completions', {
              method: 'POST',
              headers: {
                  'Content-Type': 'application/json',
                  'Authorization': 'Bearer ' + GITHUB_TOKEN
              },
              body: JSON.stringify({
                  model: 'gpt-4o-mini',
                  messages: [
                      { role: 'system', content: 'You are AssetEase AI Assistant. You help users reserve rooms, request equipment, understand booking policies, understand the approval process, and explain reservation steps. Keep replies short, friendly, and professional.' },
                      { role: 'user', content: message }
                  ],
                  temperature: 0.7,
                  max_tokens: 150
              })
          });

          if (!aiRes.ok) {
              const e = await aiRes.json().catch(() => ({}));
              throw new Error(e.error?.message || 'AI error ' + aiRes.status);
          }

          const aiData = await aiRes.json();
          const aiReply = aiData.choices?.[0]?.message?.content || 'Sorry, I encountered an issue processing that statement.';

          document.getElementById(loadingId).remove();
          chatMessages.insertAdjacentHTML('beforeend', `<div class="message bot">${aiReply}</div>`);

      } catch (error) {
          document.getElementById(loadingId)?.remove();
          chatMessages.insertAdjacentHTML('beforeend', `<div class="message bot" style="color:#721c24;background:#f8d7da;">AI server connection failed. ${error.message}</div>`);
          console.error('Error:', error);
      }

      chatMessages.scrollTop = chatMessages.scrollHeight;
  }

  // ── SweetAlert flash ─────────────────────────────────────────────────────────
  <?php if ($success): ?>
  Swal.fire({
    title:'Success!',
    text:'<?= addslashes($success) ?>',
    icon:'success',
    confirmButtonColor:'#95122C',
    confirmButtonText:'Great',
    allowOutsideClick:false
  });
  <?php endif; ?>

  // ── Mobile sidebar toggle ─────────────────────────────────────────────────────
  function toggleMobileSidebar() {
    document.querySelector('.sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('open');
  }

  // ── History tab switcher ──────────────────────────────────────────────────────
  function switchHistTab(panel, btn) {
    document.querySelectorAll('.hist-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.hist-tab').forEach(b => b.classList.remove('active'));
    document.getElementById('hist-panel-' + panel).classList.add('active');
    btn.classList.add('active');
  }
  </script>

  <!-- Bottom navigation for mobile -->
  <nav class="bottom-nav">
    <div class="bottom-nav-inner">
      <a href="dashboard.php" class="active"><i class="fas fa-house"></i>Home</a>
      <a href="reservation_form.php"><i class="fas fa-plus-circle"></i>Reserve</a>
      <a href="view_reservations.php"><i class="fas fa-list-check"></i>Bookings</a>
      <a href="notification.php"><i class="fas fa-bell"></i>Alerts</a>
      <a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
    </div>
  </nav>

  </body>
  </html>