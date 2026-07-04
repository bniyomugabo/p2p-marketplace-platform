<?php
// api/users/get.php
// Get user details by ID (for AJAX calls)

declare(strict_types=1);

// Disable error display for API, log instead
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Set JSON content type
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../models/UserRole.php';


// Check if user is authenticated
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Authentication required.'
    ]);
    exit;
}

$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission (only Admins and Managers can view user details)
if (!in_array($userRole, ['ADM', 'MGR'])) {
    echo json_encode([
        'success' => false,
        'message' => 'You do not have permission to view user details.'
    ]);
    exit;
}

// Check if company context is set
if (!$companyId) {
    echo json_encode([
        'success' => false,
        'message' => 'Company context not found.'
    ]);
    exit;
}

// Get user ID from request
$requestId = isset($_GET['id']) ? (int) $_GET['id'] : (isset($_POST['id']) ? (int) $_POST['id'] : 0);

if (!$requestId) {
    echo json_encode([
        'success' => false,
        'message' => 'User ID is required.'
    ]);
    exit;
}

try {
    // Initialize models with company context
    $userModel = new User($companyId);
    $roleModel = new UserRole($companyId);

    // Get user details with role information
    $user = $userModel->getWithRole($requestId);

    if (!$user) {
        echo json_encode([
            'success' => false,
            'message' => 'User not found or does not belong to your company.'
        ]);
        exit;
    }

    // Get user's login history (last 5 logins)
    $loginHistory = $userModel->getLoginHistory($requestId, 5);

    // Get user's activity log (last 10 activities)
    $activitySql = "
        SELECT action, entity_type, entity_id, created_at 
        FROM activity_log 
        WHERE user_id = :user_id AND company_id = :company_id
        ORDER BY created_at DESC 
        LIMIT 10
    ";
    $activityStmt = $userModel->getConnection()->prepare($activitySql);
    $activityStmt->execute([
        'user_id' => $requestId,
        'company_id' => $companyId
    ]);
    $recentActivity = $activityStmt->fetchAll();

    // Get user's statistics
    $statsSql = "
        SELECT 
            (SELECT COUNT(*) FROM sales_invoices WHERE created_by = :user_id AND company_id = :company_id) as invoices_created,
            (SELECT COUNT(*) FROM purchase_orders WHERE created_by = :user_id AND company_id = :company_id) as purchase_orders_created,
            (SELECT COUNT(*) FROM quotations WHERE created_by = :user_id AND company_id = :company_id) as quotations_created,
            (SELECT COUNT(*) FROM inventory_transactions WHERE created_by = :user_id AND company_id = :company_id) as inventory_transactions
    ";
    $statsStmt = $userModel->getConnection()->prepare($statsSql);
    $statsStmt->execute([
        'user_id' => $requestId,
        'company_id' => $companyId
    ]);
    $userStats = $statsStmt->fetch();

    // Get role details
    $role = $roleModel->find($user['role_id']);

    // Prepare response data
    $responseData = [
        'id' => (int) $user['id'],
        'username' => $user['username'],
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'phone' => $user['phone'],
        'role_id' => (int) $user['role_id'],
        'role_name' => $role ? $role['role_name'] : 'Unknown',
        'role_code' => $role ? $role['role_code'] : 'Unknown',
        'is_active' => (bool) $user['is_active'],
        'is_deleted' => (bool) $user['is_deleted'],
        'last_login' => $user['last_login'],
        'login_count' => (int) $user['login_count'],
        'created_at' => $user['created_at'],
        'updated_at' => $user['updated_at'],
        'stats' => [
            'invoices_created' => (int) ($userStats['invoices_created'] ?? 0),
            'purchase_orders_created' => (int) ($userStats['purchase_orders_created'] ?? 0),
            'quotations_created' => (int) ($userStats['quotations_created'] ?? 0),
            'inventory_transactions' => (int) ($userStats['inventory_transactions'] ?? 0)
        ],
        'login_history' => [],
        'recent_activity' => []
    ];

    // Format login history
    foreach ($loginHistory as $login) {
        $responseData['login_history'][] = [
            'ip_address' => $login['ip_address'],
            'success' => (bool) $login['success'],
            'created_at' => $login['created_at']
        ];
    }

    // Format recent activity
    foreach ($recentActivity as $activity) {
        $responseData['recent_activity'][] = [
            'action' => $activity['action'],
            'entity_type' => $activity['entity_type'],
            'entity_id' => (int) $activity['entity_id'],
            'created_at' => $activity['created_at']
        ];
    }

    // Calculate account age
    $createdAt = new DateTime($user['created_at']);
    $now = new DateTime();
    $accountAge = $createdAt->diff($now);
    $responseData['account_age_days'] = (int) $accountAge->days;

    // Determine if user is online (last login within 5 minutes)
    $responseData['is_online'] = false;
    if ($user['last_login']) {
        $lastLogin = new DateTime($user['last_login']);
        $diff = $now->getTimestamp() - $lastLogin->getTimestamp();
        $responseData['is_online'] = $diff < 300; // 5 minutes
    }

    echo json_encode([
        'success' => true,
        'user' => $responseData,
        'message' => 'User details retrieved successfully.'
    ]);

} catch (Exception $e) {
    error_log("API get user error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to retrieve user details: ' . $e->getMessage()
    ]);
}
?>