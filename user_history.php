<?php
/**
 * User History Page - Past and Approved Reservations
 */
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$userId = $_SESSION['user_id'];
$userName = $_SESSION['uname'] ?? $_SESSION['user_name'] ?? 'User';
$userEmail = $_SESSION['email'] ?? 'N/A';

// Get user from database if needed
if (empty($userName)) {
    $stmt = $conn->prepare("SELECT uname FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $userName = $user['uname'] ?? 'User';
}

// --- NOTIFICATION LOGIC ---
$stmt = $conn->prepare("SELECT COUNT(*) as unread FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$notifRow = $result->fetch_assoc();
$unread_notifications = $notifRow['unread'] ?? 0; 

// Fetch only Approved or Completed room bookings and approved/returned equipment borrows for this user, ordered by most recent date
$query = "SELECT id, room_name AS resource_name, 'room' AS resource_type, booking_date, end_date, start_time, end_time, status, purpose 
          FROM room_reservations 
          WHERE user_id = ? AND (status = 'Approved' OR status = 'Completed') 
          UNION ALL 
          SELECT id, equipment_name AS resource_name, 'equipment' AS resource_type, borrow_date AS booking_date, return_date AS end_date, start_time, end_time, status, purpose 
          FROM borrows 
          WHERE user_id = ? AND (status = 'Approved' OR status = 'Returned') 
          ORDER BY booking_date DESC, start_time DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $userId, $userId);
$stmt->execute();
$result = $stmt->get_result();

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

$historyData = [];
while ($row = $result->fetch_assoc()) {
    // Categorize by formatted date string
    $dateKey = date("F j, Y", strtotime($row['booking_date']));
    $historyData[$dateKey][] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservation History - AssetEase</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary-red: #95122C;
            --primary-dark: #100C08;
            --bg-color: #F5EFED;
            --white: #ffffff;
            --shadow: 0 4px 15px rgba(0, 0, 0, 0.1); }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background: var(--bg-color); display: flex; min-height: 100vh; }

        /* Sidebar Styles */
        .sidebar { 
            width: 260px; 
            height: 100vh; 
            background: linear-gradient(180deg, var(--primary-dark) 0%, var(--primary-red) 100%); 
            color: var(--white); 
            padding: 20px 0; 
            position: fixed;
            z-index: 1001;
        }
        .sidebar-header { 
            padding: 10px 25px 30px; 
            display: flex; 
            align-items: center; 
            gap: 12px; 
        }
        .sidebar-header h2 { 
            color: #FFD700; 
            font-weight: 700; 
            font-size: 24px; 
            letter-spacing: 1px; 
        }
        .sidebar a { 
            display: flex; 
            align-items: center; 
            padding: 18px 25px; 
            color: #ffffff; 
            text-decoration: none; 
            transition: all 0.3s ease;
            font-size: 1.1rem;
        }
        .sidebar a:hover, .sidebar a.active { 
            background: rgba(0, 0, 0, 0.3);
        }
        .sidebar a i { 
            width: 35px; 
            font-size: 20px; 
        }

        .content { 
            margin-left: 260px; 
            padding: 100px 40px 40px; 
            width: calc(100% - 260px); 
        }
        
        /* Sticky Top Header */
        .top-header {
            position: fixed;
            top: 0;
            right: 0;
            width: calc(100% - 260px);
            background: var(--bg-color);
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 15px;
            padding: 15px 40px;
            z-index: 1000;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        /* Profile Badge Pill */
        .profile-badge {
            display: flex;
            align-items: center;
            background: var(--white);
            border: 1.5px solid #ccc;
            border-radius: 50px;
            padding: 5px 5px 5px 20px;
            cursor: pointer;
            gap: 15px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
        }
        
        .profile-badge:hover {
            border-color: #aaa;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .profile-badge .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--primary-dark);
            font-weight: 700;
            font-size: 15px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .profile-badge img {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            object-fit: cover;
            border: 1px solid #eee;
        }

        /* Notification Bell */
        .notif-container {
            position: relative;
            cursor: pointer;
        }
        .notif-bell {
            width: 50px;
            height: 50px;
            background: white;
            border: 1.5px solid #ccc;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            color: var(--primary-dark);
            font-size: 20px;
            transition: all 0.3s ease;
        }
        .notif-bell:hover { 
            background: #f9f9f9;
            border-color: #aaa;
        }
        .notif-count {
            position: absolute;
            top: -2px;
            right: -2px;
            background: #dc3545;
            color: white;
            font-size: 11px;
            font-weight: 700;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--bg-color);
        }

        /* History Specific Styles */
        .date-section { margin-bottom: 30px; }
        .date-header { 
            background: var(--primary-dark); 
            color: #FFD700; 
            padding: 10px 20px; 
            border-radius: 8px; 
            display: inline-block; 
            margin-bottom: 15px;
            font-weight: 600;
            box-shadow: var(--shadow);
        }
        
        .history-card { 
            background: var(--white); 
            padding: 20px; 
            border-radius: 12px; 
            box-shadow: var(--shadow); 
            margin-bottom: 15px; 
            border-left: 5px solid #28a745;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .info-group h4 { color: var(--primary-dark); margin-bottom: 5px; text-transform: uppercase; }
        .info-group p { font-size: 0.9rem; color: #666; }
        .status-badge { 
            padding: 5px 15px; 
            border-radius: 20px; 
            font-size: 0.8rem; 
            font-weight: 700; 
            background: #d4edda; 
            color: #155724; 
        }
        .empty-state { text-align: center; padding: 50px; color: #888; }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-crown" style="color: #FFD700; font-size: 24px;"></i>
            <h2>ASSETEASE</h2>
        </div>
        <a href="dashboard.php"><i class="fas fa-home"></i><span> Dashboard</span></a>
        <a href="view_reservations.php"><i class="fas fa-list-check"></i><span> View Reservations</span></a>
        <a href="user_history.php" class="active"><i class="fas fa-history"></i><span> History</span></a>
        <a href="settings.php"><i class="fas fa-cog"></i><span> Settings</span></a>
        <a href="logout.php"><i class="fas fa-sign-out-alt"></i><span> Logout</span></a>
    </div>

    <div class="content">
        <div class="top-header">
            <div class="profile-badge">
                <div class="user-info">
                    <i class="fas fa-shield-halved"></i>
                    <span><?= htmlspecialchars(strlen($userName) > 10 ? substr($userName, 0, 10).'...' : $userName) ?></span> 
                </div>
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($userName) ?>&background=random&color=fff&rounded=true" alt="User Profile">
            </div>
            
            <div class="notif-container" onclick="toggleNotifications()">
                <div class="notif-bell">
                    <i class="fas fa-bell"></i>
                </div>
                <?php if($unread_notifications > 0): ?>
                    <div class="notif-count"><?= $unread_notifications ?></div>
                <?php endif; ?>
            </div>

            <!-- Notifications Dropdown -->
            <div id="notif-panel" style="display:none; position: absolute; top: 70px; right: 40px; width: 350px; background: white; border-radius: 12px; box-shadow: 0 8px 24px rgba(0,0,0,0.12); z-index: 1001; max-height: 400px; overflow-y: auto;">
                <div style="padding: 20px; border-bottom: 1px solid #eee;">
                    <h4 style="margin: 0; color: var(--primary-dark);">Notifications</h4>
                </div>
                <div id="notif-list" style="padding: 15px 0;">
                    <p style="padding: 15px 20px; color: #999; text-align: center;">No new notifications</p>
                </div>
            </div>
        </div>

        <h1>Your Reservation History</h1>
        <p style="margin-bottom: 30px; color: #666;">View all your successful past room and equipment bookings.</p>

        <?php if (empty($historyData)): ?>
            <div class="card empty-state">
                <i class="fas fa-folder-open" style="font-size: 48px; margin-bottom: 15px;"></i>
                <p>No successful history records found.</p>
            </div>
        <?php else: ?>
            <?php foreach ($historyData as $date => $bookings): ?>
                <div class="date-section">
                    <div class="date-header">
                        <i class="far fa-calendar-alt"></i> <?= $date ?>
                    </div>
                    
                    <?php foreach ($bookings as $booking): ?>
                        <div class="history-card">
                            <div class="info-group">
                                <h4>
                                    <i class="<?= $booking['resource_type'] === 'room' ? 'fas fa-door-open' : 'fas fa-tools' ?>"></i>
                                    <?= htmlspecialchars($booking['resource_name']) ?>
                                </h4>
                                <?php $purposeInfo = parsePurposeParts($booking['purpose']); ?>
                                <?php if ($purposeInfo['department']): ?>
                                    <p><strong>Department:</strong> <?= htmlspecialchars($purposeInfo['department']) ?></p>
                                <?php endif; ?>
                                <p><strong>Time:</strong> <?= date("g:i A", strtotime($booking['start_time'])) ?> - <?= date("g:i A", strtotime($booking['end_time'])) ?></p>
                                <p><strong>Purpose:</strong> <?= htmlspecialchars($purposeInfo['purpose']) ?></p>
                            </div>
                            <div class="status-badge">
                                <?= $booking['status'] ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        function toggleNotifications() {
            const panel = document.getElementById('notif-panel');
            panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
        }

        // Close notification panel when clicking outside
        document.addEventListener('click', function(event) {
            const panel = document.getElementById('notif-panel');
            const notifContainer = document.querySelector('.notif-container');
            
            if (!notifContainer.contains(event.target)) {
                panel.style.display = 'none';
            }
        });
    </script>

</body>