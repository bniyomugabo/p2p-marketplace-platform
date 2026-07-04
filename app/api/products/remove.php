<?php
// api/products/remove.php - Updated with multi-company support
declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';

header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

// Log API call
error_log("API remove.php called - " . date('Y-m-d H:i:s'));

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please login.']);
    exit;
}

$userRole = $_SESSION['user_role'] ?? 'VIW';
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to remove products.']);
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

// Get product ID to remove
$productId = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;

if (!$productId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Product ID is required.']);
    exit;
}

try {
    // Initialize session for products being added
    if (!isset($_SESSION['pending_products'])) {
        $_SESSION['pending_products'] = [];
    }

    error_log("Current pending products: " . print_r($_SESSION['pending_products'], true));
    error_log("Looking for product ID: " . $productId);

    $removed = false;
    $removedProduct = null;

    // Find and remove the product
    foreach ($_SESSION['pending_products'] as $key => $product) {
        if ((int) $product['id'] === (int) $productId) {
            $removedProduct = $product;
            unset($_SESSION['pending_products'][$key]);
            $removed = true;
            error_log("Product found and removed at index: " . $key);
            break;
        }
    }

    if (!$removed) {
        error_log("Product not found. Available IDs: " . implode(', ', array_column($_SESSION['pending_products'], 'id')));
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Product not found in session.',
            'debug' => [
                'requested_id' => $productId,
                'available_ids' => array_column($_SESSION['pending_products'], 'id')
            ]
        ]);
        exit;
    }

    // Re-index array
    $_SESSION['pending_products'] = array_values($_SESSION['pending_products']);

    // Generate new CSRF token for next request
    $newCsrfToken = CSRF::generateMultiUse();

    echo json_encode([
        'success' => true,
        'message' => 'Product removed from list successfully!',
        'product' => $removedProduct,
        'remaining_count' => count($_SESSION['pending_products']),
        'new_csrf_token' => $newCsrfToken
    ]);
    exit;

} catch (Exception $e) {
    error_log("Product removal error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to remove product: ' . $e->getMessage()]);
    exit;
}
?>