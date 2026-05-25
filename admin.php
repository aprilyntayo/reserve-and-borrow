<?php
/**
 * Admin / Super Admin Access Control
 * Clean + secure version
 */

session_start();
require_once 'config.php';

/* ======================
1. LOGIN CHECK
====================== */
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

/* ======================
2. ROLE CHECK (ADMIN + SUPER ADMIN)
====================== */
if (!isset($_SESSION['role'])) {
    header("Location: dashboard.php");
    exit;
}

$role = $_SESSION['role'];

/* ======================
3. ROUTING LOGIC
====================== */

if ($role === 'super_admin') {

    // Super admin goes to super admin dashboard
    header("Location: super_admin_dashboard.php");
    exit;

} elseif ($role === 'admin') {

    // Admin goes to admin dashboard
    header("Location: admin_dashboard.php");
    exit;

} else {

    // Any other role goes to user dashboard
    header("Location: dashboard.php");
    exit;
}
?>