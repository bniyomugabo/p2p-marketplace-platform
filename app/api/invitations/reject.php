<?php
// api/users/reject.php
// ============================================
// REJECT USER REGISTRATION API ENDPOINT
// ============================================

declare(strict_types=1);


// Set JSON content type
header('Content-Type: application/json');

// Check authentication and authorization
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'ADM') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit;
}

// Load required files
require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../models/Notification.php';

// Get request data
$input = json_decode(file_get_contents('php://input'), true);

// Validate CSRF token
$headers = getallheaders();
$csrfToken = $headers['X-CSRF-Token'] ?? '';

if (!CSRF::validate($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

// Validate input
$userId = $input['user_id'] ?? 0;
$reason = $input['reason'] ?? 'No reason provided';

if (!$userId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'User ID is required']);
    exit;
}

try {
    $db = Database::getInstance();
    $userModel = new User();
    $notificationModel = new Notification();

    // Get user details before rejection
    $stmt = $db->prepare("SELECT email, full_name, company_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        throw new Exception('User not found');
    }

    // Verify user belongs to admin's company (for multi-tenant security)
    if ($user['company_id'] != $_SESSION['company_id']) {
        throw new Exception('You do not have permission to reject this user');
    }

    // Reject the user
    $userModel->rejectUser($userId, $reason);

    // Create notification for the user
    $notificationModel->createNotification(
        $userId,
        'registration_rejected',
        'Registration Rejected',
        'Your registration request has been rejected. Reason: ' . $reason,
        null
    );

    // Log the action
    error_log("User {$userId} rejected by admin {$_SESSION['user_id']}. Reason: {$reason}");

    echo json_encode([
        'success' => true,
        'message' => 'User rejected successfully'
    ]);

} catch (Exception $e) {
    error_log("User rejection error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}