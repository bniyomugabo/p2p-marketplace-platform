<?php
// api/notifications/get.php
header('Content-Type: application/json');

// Enable error logging but not display
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/error.log');



// Log session info for debugging
//error_log("Notifications API - Session ID: " . session_id());
//error_log("Notifications API - Session data: " . json_encode($_SESSION));

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Notification.php';

// Check authentication with detailed logging
if (!isset($_SESSION['user_id'])) {
    //error_log("Notifications API - No user_id in session. Session: " . json_encode($_SESSION));
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Authentication required',
        'debug' => 'No user session found'
    ]);
    exit;
}

$userId = (int) $_SESSION['user_id'];
//error_log("Notifications API - Authenticated user: {$userId}");

$notificationModel = new Notification();

try {
    if (!method_exists($notificationModel, 'getRecent')) {
        $notifications = $notificationModel->getUnread($userId, 10);
    } else {
        $notifications = $notificationModel->getRecent($userId, 10);
    }

    $unreadCount = $notificationModel->getUnreadCount($userId);

    //error_log("Notifications API - Found " . count($notifications) . " notifications, {$unreadCount} unread");

    echo json_encode([
        'success' => true,
        'unread_count' => $unreadCount,
        'notifications' => $notifications
    ]);

} catch (Exception $e) {
    error_log("Notifications API Error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error loading notifications: ' . $e->getMessage()
    ]);
}