<?php
// api/products/clear.php - Updated with multi-company support
declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';

header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

// Log API call
error_log("API clear.php called - " . date('Y-m-d H:i:s'));

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please login.']);
    exit;
}

$userRole = $_SESSION['user_role'] ?? 'VIW';

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to clear products.']);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// Verify CSRF token
$csrfToken = $_POST['csrf_token'] ?? '';
if (empty($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF token missing.']);
    exit;
}

// Validate CSRF token
if (!CSRF::validateMultiUse($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
}

try {
    // Get count before clearing
    $removedCount = isset($_SESSION['pending_products']) ? count($_SESSION['pending_products']) : 0;

    // Clear all pending products
    $_SESSION['pending_products'] = [];

    // Generate new CSRF token for next request
    $newCsrfToken = CSRF::generateMultiUse();

    echo json_encode([
        'success' => true,
        'message' => 'All products cleared from list successfully!',
        'removed_count' => $removedCount,
        'new_csrf_token' => $newCsrfToken
    ]);
    exit;

} catch (Exception $e) {
    error_log("Clear products error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to clear products: ' . $e->getMessage()]);
    exit;
}
?>