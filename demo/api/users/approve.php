<?php
// api/users/approve.php
// ============================================
// APPROVE USER REGISTRATION API ENDPOINT
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

if ($csrfToken !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

// Validate input
$userId = $input['user_id'] ?? 0;

if (!$userId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'User ID is required']);
    exit;
}

try {
    $db = Database::getInstance();
    $userModel = new User();
    $notificationModel = new Notification();

    // Get user details before approval
    $stmt = $db->prepare("SELECT email, full_name, company_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        throw new Exception('User not found');
    }

    // Verify user belongs to admin's company (for multi-tenant security)
    if ($user['company_id'] != $_SESSION['company_id']) {
        if($_SESSION['company_id'] == 1){
            // Allow super admin to approve users from any company
        } else {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You do not have permission to approve this user']);
            exit;
        }
    }

    // Approve the user
    $userModel->approveUser($userId, $_SESSION['user_id']);

    // Create notification for the user
    $notificationModel->createNotification(
        $userId,
        'registration_approved',
        'Registration Approved',
        'Your registration has been approved. You can now log in to the system.',
        'auth/signin.php'
    );

    // Log the action
    error_log("User {$userId} approved by admin {$_SESSION['user_id']}");

    echo json_encode([
        'success' => true,
        'message' => 'User approved successfully'
    ]);

} catch (Exception $e) {
    error_log("User approval error: {$userId} " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}