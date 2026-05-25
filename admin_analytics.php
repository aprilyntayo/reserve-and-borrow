<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once 'config.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'super_admin')) {
    header("Location: login.php");
    exit;
}

$adminName = $_SESSION['uname'];

// Get analytics data
$total_bookings = $conn->query("SELECT COUNT(*) as count FROM bookings")->fetch_assoc()['count'];
$pending_bookings = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE status='Pending'")->fetch_assoc()['count'];
$approved_bookings = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE status='Approved'")->fetch_assoc()['count'];
$rejected_bookings = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE status='Rejected'")->fetch_assoc()['count'];

// Room statistics
$total_rooms = $conn->query("SELECT COUNT(DISTINCT resource_name) as count FROM bookings WHERE resource_type='room'")->fetch_assoc()['count'];
$room_reserved = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE resource_type='room' AND status='Approved'")->fetch_assoc()['count'];

// Equipment statistics
$total_equipment = $conn->query("SELECT COUNT(*) as count FROM resources WHERE resource_type IN ('laptop', 'projector', 'camera', 'microphone')")->fetch_assoc()['count'];
$equipment_reserved = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE resource_type='equipment' AND status='Approved'")->fetch_assoc()['count'];

// User statistics
$total_users = $conn->query("SELECT COUNT(*) as count FROM users WHERE role='user'")->fetch_assoc()['count'];
$active_users = $conn->query("SELECT COUNT(DISTINCT user_id) as count FROM bookings")->fetch_assoc()['count'];

// Top users by bookings
$top_users = $conn->query("SELECT user_name, COUNT(*) as booking_count FROM bookings GROUP BY user_id ORDER BY booking_count DESC LIMIT 5");

// Bookings by status
$bookings_by_status = $conn->query("SELECT status, COUNT(*) as count FROM bookings GROUP BY status");
$status_data = [];
while ($row = $bookings_by_status->fetch_assoc()) {
    $status_data[$row['status']] = $row['count'];
}

// Bookings by resource type
$bookings_by_type = $conn->query("SELECT resource_type, COUNT(*) as count FROM bookings GROUP BY resource_type");
$type_data = [];
while ($row = $bookings_by_type->fetch_assoc()) {
    $type_data[$row['resource_type']] = $row['count'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - ASSETEASE</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Poppins, sans-serif;
            background: #F5EFED;
            display: flex;
        }

        .sidebar {
            width: 208px;
            background: #4A1428;
            color: white;
            padding: 30px 0;
            min-height: 100vh;
            position: fixed;
            left: 0;
            top: 0;
        }

        .sidebar-header {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 0 20px;
            margin-bottom: 40px;
            font-size: 16px;
            font-weight: 700;
            color: #D4AF37;
        }

        .sidebar-menu {
            display: flex;
            flex-direction: column;
            gap: 0;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px 20px;
            color: white;
            text-decoration: none;
            transition: 0.3s;
            border-left: 3px solid transparent;
        }

        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background: rgba(255, 255, 255, 0.1);
            border-left-color: #D4AF37;
        }

        .main-content {
            margin-left: 208px;
            width: calc(100% - 208px);
            padding: 30px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: white;
            padding: 20px 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .header h1 {
            font-size: 28px;
            color: #333;
        }

        .logout-btn {
            background: none;
            border: none;
            color: #A91D3A;
            text-decoration: none;
            cursor: pointer;
            font-weight: 600;
            font-family: Poppins, sans-serif;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid #A91D3A;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            text-align: center;
        }

        .stat-card i {
            font-size: 32px;
            color: #A91D3A;
            margin-bottom: 10px;
        }

        .stat-card h4 {
            color: #666;
            font-size: 12px;
            font-weight: 500;
            margin-bottom: 10px;
            text-transform: uppercase;
        }

        .stat-card .value {
            font-size: 32px;
            font-weight: 700;
            color: #333;
        }

        .card {
            background: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .card h2 {
            font-size: 18px;
            color: #333;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        .chart-container {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            position: relative;
            height: 300px;
        }

        .chart-title {
            font-size: 16px;
            color: #333;
            font-weight: 600;
            margin-bottom: 15px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        thead {
            border-bottom: 2px solid #A91D3A;
        }

        th {
            padding: 12px;
            text-align: left;
            color: #A91D3A;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #eee;
            font-size: 13px;
            color: #333;
        }

        tr:hover {
            background: #f9f9f9;
        }

        .progress-bar {
            width: 100%;
            height: 20px;
            background: #eee;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 5px;
        }

        .progress-fill {
            height: 100%;
            background: #A91D3A;
            border-radius: 10px;
        }

        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 100%;
                min-height: auto;
                padding: 15px;
            }
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 15px;
            }
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .header {
                flex-direction: column;
                gap: 15px;
            }
        }

    </style>
</head>
<body>

    <div class="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-crown"></i> ASSETEASE
        </div>
        <div class="sidebar-menu">
            <a href="admin_dashboard.php"><i class="fas fa-home"></i> Dashboard</a>
            <a href="admin_dashboard.php#history"><i class="fas fa-history"></i> History</a>
            <a href="analytics.php" class="active"><i class="fas fa-chart-line"></i> Analytics</a>
            <a href="admin_settings.php"><i class="fas fa-cog"></i> Settings</a>
            <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <div class="main-content">

        <div class="header">
            <h1><i class="fas fa-chart-bar"></i> Reports & Analytics</h1>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <i class="fas fa-calendar-check"></i>
                <h4>Total Bookings</h4>
                <div class="value"><?= $total_bookings ?></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-hourglass-half"></i>
                <h4>Pending Requests</h4>
                <div class="value"><?= $pending_bookings ?></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-check-circle"></i>
                <h4>Approved</h4>
                <div class="value"><?= $approved_bookings ?></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-times-circle"></i>
                <h4>Rejected</h4>
                <div class="value"><?= $rejected_bookings ?></div>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <i class="fas fa-door-open"></i>
                <h4>Total Rooms</h4>
                <div class="value"><?= $total_rooms ?></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-laptop"></i>
                <h4>Total Equipment</h4>
                <div class="value"><?= $total_equipment ?></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-users"></i>
                <h4>Total Users</h4>
                <div class="value"><?= $total_users ?></div>
            </div>
            <div class="stat-card">
                <i class="fas fa-user-check"></i>
                <h4>Active Users</h4>
                <div class="value"><?= $active_users ?></div>
            </div>
        </div>

        <div class="charts-grid">
            <div class="chart-container">
                <div class="chart-title">Bookings by Status</div>
                <canvas id="statusChart"></canvas>
            </div>
            <div class="chart-container">
                <div class="chart-title">Bookings by Type</div>
                <canvas id="typeChart"></canvas>
            </div>
        </div>

        <div class="card">
            <h2><i class="fas fa-star"></i> Top 5 Users by Bookings</h2>
            <table>
                <thead>
                    <tr>
                        <th>User Name</th>
                        <th>Total Bookings</th>
                        <th>Progress</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                        $max_count = 0;
                        $temp_result = $conn->query("SELECT MAX(COUNT(*)) as max_count FROM bookings GROUP BY user_id");
                        $temp_row = $temp_result->fetch_assoc();
                        $max_count = $temp_row['max_count'] ?? 1;
                        
                        $top_users = $conn->query("SELECT user_name, COUNT(*) as booking_count FROM bookings GROUP BY user_id ORDER BY booking_count DESC LIMIT 5");
                        
                        if ($top_users->num_rows > 0):
                            while ($user = $top_users->fetch_assoc()):
                                $percentage = ($user['booking_count'] / $max_count) * 100;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($user['user_name']) ?></td>
                        <td><?= $user['booking_count'] ?></td>
                        <td>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?= $percentage ?>%"></div>
                            </div>
                        </td>
                    </tr>
                    <?php 
                            endwhile;
                        else:
                    ?>
                    <tr>
                        <td colspan="3" style="text-align: center; color: #999;">No user data available</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

    <script>
        // Status Chart
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Pending', 'Approved', 'Rejected'],
                datasets: [{
                    data: [<?= $status_data['Pending'] ?? 0 ?>, <?= $status_data['Approved'] ?? 0 ?>, <?= $status_data['Rejected'] ?? 0 ?>],
                    backgroundColor: ['#FFC107', '#4CAF50', '#F44336'],
                    borderColor: ['#FFF', '#FFF', '#FFF'],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Type Chart
        const typeCtx = document.getElementById('typeChart').getContext('2d');
        new Chart(typeCtx, {
            type: 'bar',
            data: {
                labels: ['Room', 'Equipment'],
                datasets: [{
                    label: 'Bookings',
                    data: [<?= $type_data['room'] ?? 0 ?>, <?= $type_data['equipment'] ?? 0 ?>],
                    backgroundColor: '#A91D3A',
                    borderColor: '#8B1630',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    </script>

</body>
</html>
