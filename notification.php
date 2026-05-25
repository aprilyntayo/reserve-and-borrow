<?php
            /**
             * Notifications Page - View all notifications
             */
            session_start();
            require_once 'config.php';
            require_once 'notification_handler.php';

            if (!isset($_SESSION['user_id'])) {
                header("Location: login.php");
                exit;
            }

            $userId   = $_SESSION['user_id'];

            // Always fetch current user info fresh from database
            $userStmt = $conn->prepare("SELECT uname, email FROM users WHERE id = ?");
            $userStmt->bind_param("i", $userId);
            $userStmt->execute();
            $userResult = $userStmt->get_result();
            $userRow    = $userResult->fetch_assoc();
            $userName   = $userRow['uname'] ?? ($_SESSION['uname'] ?? $_SESSION['user_name'] ?? 'User');

            // Get unread notifications count
            $notifStmt = $conn->prepare("SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0");
            $notifStmt->bind_param("i", $userId);
            $notifStmt->execute();
            $notifResult = $notifStmt->get_result();
            $notifRow    = $notifResult->fetch_assoc();
            $unread_count = $notifRow['unread'] ?? 0;

            // Mark as read
            if (isset($_GET['mark_read'])) {
                $notifId = intval($_GET['mark_read']);
                markAsRead($notifId);
                header("Location: notification.php");
                exit;
            }

            // Mark all as read
            if (isset($_GET['mark_all_read'])) {
                $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = $userId");
                header("Location: notification.php");
                exit;
            }

            // Get recent notifications for header dropdown (5)
            $recentStmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
            $recentStmt->bind_param("i", $userId);
            $recentStmt->execute();
            $notifications = $recentStmt->get_result()->fetch_all(MYSQLI_ASSOC);

            // Get all notifications
            $allNotifications = getNotifications($userId, 50);
            ?>
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Notifications — AssetEase</title>
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

            /* ══ PAGE HEADER ROW ═══════════════════════════════════════════════════════ */
            .page-header-row{
            display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;
            }
            .page-header-row h2{font-size:20px;font-weight:700;color:var(--dark);}
            .mark-all-btn{
            display:flex;align-items:center;gap:8px;padding:9px 18px;
            background:#fff;border:1.5px solid #e8e0de;border-radius:25px;
            font-size:13px;font-weight:500;color:#555;cursor:pointer;
            text-decoration:none;transition:0.2s;
            }
            .mark-all-btn:hover{border-color:var(--red);color:var(--red);}

            /* ══ NOTIFICATION CARDS ════════════════════════════════════════════════════ */
            .notif-card{
            background:#fff;border-radius:var(--radius);
            box-shadow:var(--shadow);margin-bottom:12px;
            border-left:4px solid #e8e0de;
            display:flex;align-items:flex-start;gap:16px;padding:18px 22px;
            transition:0.2s;
            }
            .notif-card:hover{box-shadow:var(--shadow-md);transform:translateY(-1px);}
            .notif-card.unread{border-left-color:var(--red);background:#fffbfb;}
            .notif-icon{
            width:44px;height:44px;border-radius:11px;flex-shrink:0;
            display:flex;align-items:center;justify-content:center;font-size:18px;
            }
            .notif-icon.red{background:#fef2f2;color:var(--red);}
            .notif-icon.green{background:#f0fdf4;color:#22c55e;}
            .notif-icon.yellow{background:#fefce8;color:#ca8a04;}
            .notif-body{flex:1;}
            .notif-body .nb-msg{font-size:13px;color:#333;line-height:1.5;font-weight:500;}
            .notif-body .nb-time{font-size:11px;color:#bbb;margin-top:5px;display:flex;align-items:center;gap:5px;}
            .unread-dot{
            width:9px;height:9px;background:var(--red);border-radius:50%;
            flex-shrink:0;margin-top:6px;
            }
            .notif-actions a{
            font-size:12px;color:var(--red);font-weight:600;text-decoration:none;
            padding:5px 12px;border:1.5px solid var(--red);border-radius:20px;
            display:inline-flex;align-items:center;gap:5px;transition:0.2s;white-space:nowrap;
            }
            .notif-actions a:hover{background:var(--red);color:#fff;}

            .empty-state{
            text-align:center;padding:80px 20px;color:#bbb;
            background:#fff;border-radius:var(--radius);box-shadow:var(--shadow);
            }
            .empty-state i{font-size:54px;margin-bottom:16px;display:block;color:#e0d8d4;}
            .empty-state p{font-size:15px;font-weight:600;color:#aaa;margin-bottom:6px;}
            .empty-state small{font-size:13px;}

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
            .page-header-row{flex-direction:column;align-items:flex-start;gap:10px;}
            .notif-card{flex-wrap:wrap;}
            .notif-actions{width:100%;margin-top:6px;}
            }
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
        <a href="view_reservations.php"><i class="fas fa-list-check"></i> My Reservations</a>

        <hr class="sidebar-divider">
        <div class="sidebar-section-label">Support</div>
        <a href="notification.php" class="active">
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

            <!-- CONTENT -->
            <div class="content">

                <div class="page-header-row">
                <h2><i class="fas fa-bell" style="color:var(--red);margin-right:8px;"></i>All Notifications</h2>
                <?php if($unread_count > 0): ?>
                    <a href="?mark_all_read=1" class="mark-all-btn">
                    <i class="fas fa-check-double"></i> Mark all as read
                    </a>
                <?php endif; ?>
                </div>

                <div id="notifList">
                <?php if ($allNotifications->num_rows > 0): ?>
                    <?php while ($notif = $allNotifications->fetch_assoc()):
                    $isUnread = !$notif['is_read'];
                    // Pick icon colour by keyword
                    $msg = strtolower($notif['message']);
                    $iconClass = 'red';
                    $iconName  = 'fa-bell';
                    if (str_contains($msg,'approved')) { $iconClass='green'; $iconName='fa-check-circle'; }
                    elseif (str_contains($msg,'rejected')||str_contains($msg,'cancelled')) { $iconClass='red'; $iconName='fa-times-circle'; }
                    elseif (str_contains($msg,'reminder')||str_contains($msg,'return')) { $iconClass='yellow'; $iconName='fa-clock'; }
                    elseif (str_contains($msg,'pending')) { $iconClass='yellow'; $iconName='fa-hourglass-half'; }
                    ?>
                    <div class="notif-card <?= $isUnread ? 'unread' : '' ?>" data-msg="<?= htmlspecialchars(strtolower($notif['message'])) ?>">
                    <div class="notif-icon <?= $iconClass ?>">
                        <i class="fas <?= $iconName ?>"></i>
                    </div>
                    <div class="notif-body">
                        <div class="nb-msg"><?= htmlspecialchars($notif['message']) ?></div>
                        <div class="nb-time">
                        <i class="fas fa-clock"></i>
                        <?= date('M d, Y h:i A', strtotime($notif['created_at'])) ?>
                        </div>
                    </div>
                    <?php if ($isUnread): ?>
                        <div class="unread-dot" title="Unread"></div>
                    <?php endif; ?>
                    <div class="notif-actions">
                        <?php if ($isUnread): ?>
                        <a href="?mark_read=<?= $notif['id'] ?>"><i class="fas fa-check"></i> Mark read</a>
                        <?php else: ?>
                        <a href="#" style="border-color:#ccc;color:#ccc;pointer-events:none;"><i class="fas fa-check-double"></i> Read</a>
                        <?php endif; ?>
                    </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No notifications yet</p>
                    <small>Your notifications will appear here once activity occurs.</small>
                    </div>
                <?php endif; ?>
                </div>

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

            function filterNotifications() {
            const q = document.getElementById('notifSearch').value.toLowerCase();
            document.querySelectorAll('#notifList .notif-card').forEach(card => {
                card.style.display = card.dataset.msg.includes(q) ? '' : 'none';
            });
            }

            function toggleMobileSidebar() {
            document.querySelector('.sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('open');
            }
            </script>

            <!-- Bottom navigation for mobile -->
            <nav class="bottom-nav">
            <div class="bottom-nav-inner">
                <a href="dashboard.php"><i class="fas fa-house"></i>Home</a>
                <a href="reservation_form.php"><i class="fas fa-plus-circle"></i>Reserve</a>
                <a href="view_reservations.php"><i class="fas fa-list-check"></i>Bookings</a>
                <a href="notification.php" class="active"><i class="fas fa-bell"></i>Alerts</a>
                <a href="settings.php"><i class="fas fa-cog"></i>Settings</a>
            </div>
            </nav>

            </body>
            </html>