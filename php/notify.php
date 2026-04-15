<?php
/**
 * notify.php — push a notification to all active admins
 *
 * Usage:
 *   include 'php/notify.php';
 *   pushNotification($conn, 'payment', 'Payment Received', 'Juan paid ₱5,000', 'payment_history.php');
 */

function pushNotification($conn, string $type, string $title, string $message, string $link = ''): void {
    $admins = $conn->query("SELECT user_id FROM users WHERE role='admin' AND status='active'");
    $stmt   = $conn->prepare("
        INSERT INTO notifications (admin_id, type, title, message, link)
        VALUES (?, ?, ?, ?, ?)
    ");
    while ($admin = $admins->fetch_assoc()) {
        $stmt->bind_param('issss', $admin['user_id'], $type, $title, $message, $link);
        $stmt->execute();
    }
}
?>