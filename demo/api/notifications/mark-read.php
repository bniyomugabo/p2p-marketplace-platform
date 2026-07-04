<?php
// api/notifications/mark-read.php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Notification.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$notificationId = $input['id'] ?? 0;

if (!$notificationId) {
    echo json_encode(['success' => false, 'message' => 'Notification ID required']);
    exit;
}

$userId = $_SESSION['user_id'];
$notificationModel = new Notification();

try {
    $result = $notificationModel->markAsRead($notificationId, $userId);

    echo json_encode([
        'success' => $result,
        'message' => $result ? 'Marked as read' : 'Failed to mark as read'
    ]);

} catch (Exception $e) {
    error_log("Error marking notification as read: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error processing request'
    ]);
}