<?php
/**
 * Index Page - Landing Page
 * 
 * This is the main entry point for the Booking System.
 * It displays the landing page with navigation to login/register.
 */
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking System | Resource Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* =============================================
           CSS Variables - Color Theme
           Using maroon/dark red gradient (#95122C to #100C08)
        ============================================= */
        :root {
            --primary-red: #95122C;
            --primary-dark: #100C08;
            --bg-light: #F5EFED;
            --white: #ffffff;
            --text-dark: #2C2C2C;
            --text-light: #666666;
            --shadow: 0 8px 24px rgba(149, 18, 44, 0.15);
            --shadow-lg: 0 12px 40px rgba(149, 18, 44, 0.2);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: var(--white);
            color: var(--text-dark);
            line-height: 1.6;
            overflow-x: hidden;
        }

        /* NAVBAR */
        header {
            background: var(--white);
            padding: 20px 60px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .logo {
            font-size: 26px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary-red) 0%, var(--primary-dark) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        nav ul {
            list-style: none;
            display: flex;
            gap: 40px;
        }

        nav ul li a {
            text-decoration: none;
            color: var(--text-dark);
            font-weight: 600;
            transition: 0.3s ease;
            position: relative;
        }

        nav ul li a:hover {
            color: var(--primary-red);
        }

        nav ul li a::after {
            content: '';
            position: absolute;
            width: 0;
            height: 2px;
            bottom: -5px;
            left: 0;
            background: linear-gradient(135deg, var(--primary-red) 0%, var(--primary-dark) 100%);
            transition: width 0.3s ease;
        }

        nav ul li a:hover::after {
            width: 100%;
        }

        .nav-btn {
            background: linear-gradient(135deg, var(--primary-red) 0%, var(--primary-dark) 100%);
            color: var(--white);
            padding: 12px 30px;
            border-radius: 30px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 700;
            transition: 0.3s ease;
            box-shadow: 0 4px 15px rgba(149, 18, 44, 0.3);
        }

        .nav-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(149, 18, 44, 0.4);
        }

        /* HERO SECTION */
        .hero {
            padding: 120px 60px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 60px;
            min-height: 700px;
            background: linear-gradient(135deg, var(--bg-light) 0%, var(--white) 100%);
        }

        .hero-text {
            max-width: 580px;
            animation: fadeInLeft 0.8s ease;
        }

        .hero-text h1 {
            font-size: 56px;
            font-weight: 800;
            line-height: 1.2;
            color: var(--text-dark);
            margin-bottom: 20px;
        }

        .hero-text h1 span {
            background: linear-gradient(135deg, var(--primary-red) 0%, var(--primary-dark) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-text p {
            font-size: 18px;
            color: var(--text-light);
            line-height: 1.8;
            margin-bottom: 30px;
        }

        .hero-buttons {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .hero-buttons a {
            padding: 16px 40px;
            border-radius: 30px;
            text-decoration: none;
            font-weight: 700;
            transition: 0.3s ease;
            display: inline-block;
            font-size: 15px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-red) 0%, var(--primary-dark) 100%);
            color: var(--white);
            box-shadow: 0 8px 20px rgba(149, 18, 44, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 28px rgba(149, 18, 44, 0.4);
        }

        .btn-secondary {
            border: 2px solid var(--primary-red);
            color: var(--primary-red);
            background: transparent;
        }

        .btn-secondary:hover {
            background: rgba(149, 18, 44, 0.1);
            transform: translateY(-3px);
        }

        .hero-image {
            animation: fadeInRight 0.8s ease;
        }

        .hero-image-placeholder {
            width: 450px;
            height: 450px;
            background: linear-gradient(135deg, var(--primary-red) 0%, var(--primary-dark) 100%);
            border-radius: 20px;
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 80px;
            color: var(--white);
        }

        @keyframes fadeInLeft {
            from { opacity: 0; transform: translateX(-30px); }
            to { opacity: 1; transform: translateX(0); }
        }

        @keyframes fadeInRight {
            from { opacity: 0; transform: translateX(30px); }
            to { opacity: 1; transform: translateX(0); }
        }

        /* FEATURES SECTION */
        .features {
            padding: 120px 60px;
            text-align: center;
            background: var(--white);
        }

        .features h2 {
            font-size: 48px;
            font-weight: 800;
            margin-bottom: 20px;
            color: var(--text-dark);
        }

        .features-subtitle {
            font-size: 18px;
            color: var(--text-light);
            max-width: 600px;
            margin: 0 auto 60px;
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 40px;
        }

        .feature-card {
            background: var(--white);
            padding: 45px 35px;
            border-radius: 20px;
            transition: 0.3s ease;
            border: 2px solid transparent;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        .feature-card:hover {
            transform: translateY(-15px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-red);
        }

        .feature-icon {
            width: 70px;
            height: 70px;
            background: rgba(149, 18, 44, 0.1);
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin: 0 auto 20px;
            color: var(--primary-red);
            transition: 0.3s ease;
        }

        .feature-card:hover .feature-icon {
            background: linear-gradient(135deg, var(--primary-red) 0%, var(--primary-dark) 100%);
            color: var(--white);
            transform: scale(1.1);
        }

        .feature-card h3 {
            margin-bottom: 15px;
            color: var(--primary-red);
            font-size: 22px;
            font-weight: 700;
        }

        .feature-card p {
            font-size: 15px;
            color: var(--text-light);
            line-height: 1.8;
        }

        /* STATS SECTION */
        .stats {
            background: linear-gradient(135deg, var(--primary-red) 0%, var(--primary-dark) 100%);
            padding: 80px 60px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 60px;
            color: var(--white);
            text-align: center;
        }

        .stat-box h2 {
            font-size: 48px;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .stat-box p {
            font-size: 16px;
            font-weight: 600;
            opacity: 0.95;
        }

        /* FOOTER */
        footer {
            background: linear-gradient(135deg, #2C2C2C 0%, #1A1A1A 100%);
            color: var(--white);
            text-align: center;
            padding: 50px 30px;
            font-size: 14px;
            border-top: 2px solid var(--primary-red);
        }

        footer a {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            font-weight: 600;
        }

        footer a:hover {
            text-decoration: underline;
        }

        /* RESPONSIVE */
        @media (max-width: 1024px) {
            .hero {
                flex-direction: column;
                text-align: center;
                padding: 80px 30px;
            }
            .hero-text h1 { font-size: 42px; }
            .hero-image-placeholder { width: 350px; height: 350px; }
            header { padding: 18px 30px; }
            nav ul { gap: 20px; }
        }

        @media (max-width: 768px) {
            header {
                flex-direction: column;
                padding: 15px;
                gap: 15px;
            }
            nav ul {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            .hero-text h1 { font-size: 32px; }
            .hero-buttons { flex-direction: column; }
            .hero-buttons a { width: 100%; text-align: center; }
            .features, .stats { padding: 60px 20px; }
        }
    </style>
</head>
<body>

    <!-- HEADER / NAVBAR -->
    <header>
        <div class="logo"><i class="fas fa-calendar-check"></i> BookingPro</div>
        <nav>
            <ul>
                <li><a href="#home">Home</a></li>
                <li><a href="#features">Features</a></li>
                <li><a href="#about">About</a></li>
            </ul>
        </nav>
        <a href="login.php" class="nav-btn">Login</a>
    </header>

    <!-- HERO SECTION -->
    <section class="hero" id="home">
        <div class="hero-text">
            <h1>Manage Your <span>Resources</span> Efficiently</h1>
            <p>
                Streamline room reservations, equipment borrowing, and supply requests. 
                All in one integrated platform designed for your organization.
            </p>
            <div class="hero-buttons">
                <a href="register.php" class="btn-primary">Get Started</a>
                <a href="login.php" class="btn-secondary">Sign In</a>
            </div>
        </div>

        <div class="hero-image">
            <div class="hero-image-placeholder">
                <i class="fas fa-building"></i>
            </div>
        </div>
    </section>

    <!-- STATS SECTION -->
    <section class="stats">
        <div class="stat-box">
            <h2>500+</h2>
            <p>Active Users</p>
        </div>
        <div class="stat-box">
            <h2>1,200+</h2>
            <p>Bookings Made</p>
        </div>
        <div class="stat-box">
            <h2>98%</h2>
            <p>Satisfaction Rate</p>
        </div>
    </section>

    <!-- FEATURES SECTION -->
    <section class="features" id="features">
        <h2>Powerful Features</h2>
        <p class="features-subtitle">Everything you need to manage resources and bookings efficiently</p>

        <div class="feature-grid">
            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-door-open"></i></div>
                <h3>Room Reservations</h3>
                <p>Book conference rooms, classrooms, and event spaces with real-time availability checking.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-laptop"></i></div>
                <h3>Equipment Borrowing</h3>
                <p>Request projectors, laptops, and other equipment with automatic tracking and return reminders.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-box-open"></i></div>
                <h3>Supply Requests</h3>
                <p>Order office supplies and consumables with department-based billing and inventory management.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-chart-line"></i></div>
                <h3>Analytics Dashboard</h3>
                <p>Track usage patterns, monitor approvals, and generate reports for better resource planning.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-bell"></i></div>
                <h3>Smart Notifications</h3>
                <p>Receive alerts for booking confirmations, approvals, and upcoming reservations.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon"><i class="fas fa-shield-alt"></i></div>
                <h3>Secure Access</h3>
                <p>Role-based access control ensures only authorized users can make and approve requests.</p>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer id="about">
        <p>&copy; <?php echo date('Y'); ?> BookingPro System. All Rights Reserved.</p>
        <p>Need help? <a href="mailto:support@bookingpro.com">Contact Support</a></p>
    </footer>

</body>
</html>
