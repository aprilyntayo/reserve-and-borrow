<?php
/**
 * Notification Handler - Manages all notifications
 */

require_once 'config.php';

function createNotification($userId, $type, $title, $message, $bookingId = null) {
    global $conn;
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, booking_id, type, title, message, is_read, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW())");
    $stmt->bind_param("iisss", $userId, $bookingId, $type, $title, $message);
    return $stmt->execute();
}

function markAsRead($notificationId) {
    global $conn;
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
    $stmt->bind_param("i", $notificationId);
    return $stmt->execute();
}

function getUnreadCount($userId) {
    global $conn;
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc()['count'] ?? 0;
}

function getNotifications($userId, $limit = 10) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
    $stmt->bind_param("ii", $userId, $limit);
    $stmt->execute();
    return $stmt->get_result();
}
?>
