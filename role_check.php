<?php
/**
 * Role Management Utility File
 * 
 * Include this in any file that needs role-based authorization
 * 
 * Usage:
 * require_once 'role_check.php';
 * 
 * // Check if user is admin
 * if (!isAdmin()) {
 *     redirect('login.php');
 * }
 * 
 * // Check if user is logged in
 * if (!isLoggedIn()) {
 *     redirect('login.php');
 * }
 * 
 * // Check if user is regular user
 * if (!isUser()) {
 *     redirect('admin_dashboard.php');
 * }
 */

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Check if user has admin role
 */
function isAdmin() {
    return isLoggedIn() && isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

/**
 * Check if user has user role (non-admin)
 */
function isUser() {
    return isLoggedIn() && isset($_SESSION['role']) && $_SESSION['role'] === 'user';
}

/**
 * Get current user ID from session
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current username from session
 */
function getCurrentUsername() {
    return $_SESSION['uname'] ?? null;
}

/**
 * Get current user role from session
 */
function getCurrentRole() {
    return $_SESSION['role'] ?? null;
}

/**
 * Require admin access - redirect if not admin
 */
function requireAdmin() {
    if (!isAdmin()) {
        header("Location: " . (isLoggedIn() ? 'dashboard.php' : 'login.php'));
        exit;
    }
}

/**
 * Require user login - redirect if not logged in
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit;
    }
}

/**
 * Require user access - redirect if not logged in as user
 */
function requireUser() {
    if (!isUser()) {
        header("Location: " . (isAdmin() ? 'admin_dashboard.php' : 'login.php'));
        exit;
    }
}

/**
 * Redirect to a page
 */
function redirect($page) {
    header("Location: " . $page);
    exit;
}

/**
 * Get user info from database
 */
function getUserInfo($userId = null) {
    global $conn;
    
    $userId = $userId ?? getCurrentUserId();
    
    if (!$userId) {
        return null;
    }
    
    $stmt = $conn->prepare("SELECT id, uname, fullname, email, role FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

/**
 * Check if user exists and get their role
 */
function getUserRole($username) {
    global $conn;
    
    $stmt = $conn->prepare("SELECT role FROM users WHERE uname = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row['role'];
    }
    
    return null;
}

/**
 * Change user role (admin only)
 */
function changeUserRole($userId, $newRole) {
    global $conn;
    
    if (!in_array($newRole, ['admin', 'user'])) {
        return false;
    }
    
    $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
    $stmt->bind_param("si", $newRole, $userId);
    
    return $stmt->execute();
}

/**
 * Log user action (for audit trail)
 */
function logAction($userId, $action, $details = '') {
    global $conn;
    
    $timestamp = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("
        INSERT INTO user_logs (user_id, action, details, timestamp) 
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param("isss", $userId, $action, $details, $timestamp);
    
    return $stmt->execute();
}

/**
 * Get user role name (formatted)
 */
function getRoleName($role) {
    $roleNames = [
        'admin' => 'Administrator',
        'user' => 'User'
    ];
    
    return $roleNames[$role] ?? $role;
}

/**
 * Get role color for UI (Bootstrap color classes)
 */
function getRoleColor($role) {
    $colors = [
        'admin' => 'danger',  // Red
        'user' => 'info'      // Blue
    ];
    
    return $colors[$role] ?? 'secondary';
}
?>
