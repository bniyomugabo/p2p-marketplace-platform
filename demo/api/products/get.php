<?php
// api/products/get.php - Updated with multi-company support
declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please login.']);
    exit;
}

$companyId = $_SESSION['company_id'] ?? null;
$productId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$productId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Product ID is required']);
    exit;
}

// Initialize models with company context
require_once __DIR__ . '/../../models/Product.php';
require_once __DIR__ . '/../../models/Variant.php';
require_once __DIR__ . '/../../models/Category.php';

$productModel = new Product($companyId);
$variantModel = new Variant($companyId);
$categoryModel = new Category($companyId);

// Get product details
$product = $productModel->getWithDetails($productId);

if (!$product) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Product not found']);
    exit;
}

// Get category path
$categoryPath = $categoryModel->getPath($product['category_id']);
$product['category_path'] = array_column($categoryPath, 'category_name');

echo json_encode(['success' => true, 'data' => $product]);
?>