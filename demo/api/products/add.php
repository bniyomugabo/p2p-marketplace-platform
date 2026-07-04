<?php
// api/products/add.php - Updated with multi-company support
// Handles both simple products (no variants) and products with variants
declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';

header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

// Log that the API was called
error_log("API add.php called - " . date('Y-m-d H:i:s'));

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Please login.']);
    exit;
}

$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to add products.']);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

// ============================================
// CSRF VALIDATION
// ============================================

$csrfToken = $_POST['csrf_token'] ?? '';
if (empty($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF token missing.']);
    exit;
}

// Use multi-use validation for AJAX forms
if (!CSRF::validateMultiUse($csrfToken)) {
    if (!CSRF::validate($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired security token.']);
        exit;
    }
}

// ============================================
// INITIALIZE MODELS
// ============================================

require_once __DIR__ . '/../../models/Category.php';
require_once __DIR__ . '/../../models/Warehouse.php';
require_once __DIR__ . '/../../models/Location.php';
require_once __DIR__ . '/../../models/Product.php';
require_once __DIR__ . '/../../models/Variant.php';
require_once __DIR__ . '/../../models/Inventory.php';

$categoryModel = new Category($companyId);
$warehouseModel = new Warehouse($companyId);
$locationModel = new Location($companyId);
$productModel = new Product($companyId);
$variantModel = new Variant($companyId);
$inventoryModel = new Inventory($companyId);

$db = Database::getInstance();

// ============================================
// HELPER FUNCTIONS
// ============================================

function generate_sku($productCode, $variantName, $variantNumber)
{
    // Clean variant name: uppercase, remove special chars, take first 3 chars
    $cleanVariant = $variantName ? strtoupper(preg_replace('/[^A-Z0-9]/', '', substr($variantName, 0, 3))) : 'STD';
    if (strlen($cleanVariant) < 2)
        $cleanVariant = 'STD';

    return $productCode . '-' . $cleanVariant . '-' . str_pad((string)$variantNumber, 2, '0', STR_PAD_LEFT);
}

function generate_barcode()
{
    $prefix = '200';
    $productCode = str_pad((string) mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT);
    $base = $prefix . $productCode;
    $base = substr($base, 0, 12);

    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $digit = (int) $base[$i];
        $multiplier = ($i % 2 === 0) ? 1 : 3;
        $sum += $digit * $multiplier;
    }

    $checkDigit = (10 - ($sum % 10)) % 10;
    return $base . $checkDigit;
}

function uploadProductImage($file, $productId, $companyId)
{
    $uploadDir = __DIR__ . '/../../assets/uploads/company_' . $companyId . '/products/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 5 * 1024 * 1024;

    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception('Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.');
    }

    if ($file['size'] > $maxSize) {
        throw new Exception('File is too large. Maximum size is 5MB.');
    }

    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'product_' . $productId . '_' . time() . '.' . $extension;
    $filepath = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return '/uploads/company_' . $companyId . '/products/' . $filename;
    }

    throw new Exception('Failed to upload image.');
}

function uploadVariantImage($tmpFile, $productId, $variantNumber, $companyId)
{
    $uploadDir = __DIR__ . '/../../assets/uploads/company_' . $companyId . '/products/variants/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $filename = 'variant_' . $productId . '_' . $variantNumber . '_' . time() . '.jpg';
    $filepath = $uploadDir . $filename;

    if (move_uploaded_file($tmpFile, $filepath)) {
        return 'uploads/company_' . $companyId . '/products/variants/' . $filename;
    }

    return null;
}

// ============================================
// MAIN PROCESSING
// ============================================

try {
    // Basic validation
    $required = ['product_name', 'category_id'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Please fill in all required fields.");
        }
    }

    // Verify category is activated for this company
    $categoryId = (int) $_POST['category_id'];
    $categoryActive = $categoryModel->isActivatedForCompany($categoryId, $companyId);
    if (!$categoryActive) {
        throw new Exception("Selected category is not activated for your company.");
    }

    // Start transaction
    $db->beginTransaction();

    // Generate product code if not provided
    $productCode = !empty($_POST['product_code']) ? trim($_POST['product_code']) : $productModel->generateCode();

    // Check if product code already exists
    $existing = $productModel->findByCode($productCode);
    if ($existing) {
        $productCode = $productModel->generateCode();
    }

    // Determine if product has variants
    $hasVariants = isset($_POST['has_variants']) && $_POST['has_variants'] == '1';
    
    // Also check if variants array exists and has content
    if (!$hasVariants && isset($_POST['variants']) && is_array($_POST['variants']) && count($_POST['variants']) > 0) {
        $hasVariants = true;
    }

    // Insert product
    $productData = [
        'company_id' => $companyId,
        'product_code' => $productCode,
        'product_name' => trim($_POST['product_name']),
        'description' => trim($_POST['description'] ?? ''),
        'category_id' => $categoryId,
        'brand' => trim($_POST['brand'] ?? ''),
        'has_variants' => $hasVariants ? 1 : 0,
        'unit_of_measure' => trim($_POST['unit_of_measure'] ?? 'PCS'),
        'is_active' => 1,
        'created_by' => $userId
    ];

    $productId = $productModel->create($productData);

    // Handle image upload for main product
    $mainImageUrl = null;
    if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
        $mainImageUrl = uploadProductImage($_FILES['product_image'], $productId, $companyId);
    }

    $createdVariants = [];
    $variantCount = 0;

    // ============================================
    // HANDLE VARIANTS
    // ============================================
    
    if ($hasVariants && isset($_POST['variants']) && is_array($_POST['variants']) && count($_POST['variants']) > 0) {
        // Product WITH variants - create each variant
        $variantNumber = 1;
        foreach ($_POST['variants'] as $index => $variantData) {
            if (empty($variantData['variant_name'])) {
                continue;
            }

            $variantName = trim($variantData['variant_name']);

            // Generate SKU
            $sku = !empty($variantData['sku']) ?
                trim($variantData['sku']) :
                generate_sku($productCode, $variantName, $variantNumber);

            // Generate barcode
            $barcode = !empty($variantData['barcode']) ?
                trim($variantData['barcode']) :
                generate_barcode();

            // Check barcode uniqueness
            $existingVariant = $variantModel->findByBarcode($barcode);
            if ($existingVariant) {
                $barcode = generate_barcode();
            }

            // Handle variant image upload
            $variantImageUrl = null;
            if (
                isset($_FILES['variants']['tmp_name'][$index]['image']) &&
                $_FILES['variants']['error'][$index]['image'] === UPLOAD_ERR_OK
            ) {
                $variantImageUrl = uploadVariantImage(
                    $_FILES['variants']['tmp_name'][$index]['image'],
                    $productId,
                    $variantNumber,
                    $companyId
                );
            }

            // Insert variant
            $variantInsertData = [
                'company_id' => $companyId,
                'product_id' => $productId,
                'sku' => $sku,
                'barcode' => $barcode,
                'variant_name' => $variantName,
                'purchase_price' => !empty($variantData['purchase_price']) ? (float) $variantData['purchase_price'] : 0,
                'selling_price' => !empty($variantData['selling_price']) ? (float) $variantData['selling_price'] : 0,
                'wholesale_price' => !empty($variantData['wholesale_price']) ? (float) $variantData['wholesale_price'] : null,
                'tax_rate' => !empty($variantData['tax_rate']) ? (float) $variantData['tax_rate'] : 18.00,
                'reorder_level' => !empty($variantData['reorder_level']) ? (int) $variantData['reorder_level'] : 0,
                'max_stock_level' => !empty($variantData['max_stock_level']) ? (int) $variantData['max_stock_level'] : 0,
                'is_active' => 1
            ];

            $variantId = $variantModel->create($variantInsertData);
            $createdVariants[$index] = $variantId;
            $variantCount++;

            // Add variant image if uploaded
            if ($variantImageUrl) {
                $variantModel->addImage($variantId, $variantImageUrl, true);
            }

            // Handle attributes
            if (isset($variantData['attributes']) && is_array($variantData['attributes'])) {
                $attrIndex = 1;
                foreach ($variantData['attributes'] as $attr) {
                    if (!empty($attr['name']) && !empty($attr['value'])) {
                        $variantModel->addAttribute(
                            $variantId,
                            trim($attr['name']),
                            trim($attr['value']),
                            $attrIndex++
                        );
                    }
                }
            }

            $variantNumber++;
        }
    } else {
        // ============================================
        // SIMPLE PRODUCT (NO VARIANTS)
        // Create a default "Standard" variant
        // ============================================
        
        $sku = generate_sku($productCode, 'STD', 1);
        $barcode = generate_barcode();

        // Check barcode uniqueness
        $existingVariant = $variantModel->findByBarcode($barcode);
        if ($existingVariant) {
            $barcode = generate_barcode();
        }

        // Get simple product prices from form
        $purchasePrice = !empty($_POST['purchase_price']) ? (float) $_POST['purchase_price'] : 0;
        $sellingPrice = !empty($_POST['selling_price']) ? (float) $_POST['selling_price'] : 0;
        $taxRate = !empty($_POST['tax_rate']) ? (float) $_POST['tax_rate'] : 18.00;
        $reorderLevel = !empty($_POST['reorder_level']) ? (int) $_POST['reorder_level'] : 10;

        $variantData = [
            'company_id' => $companyId,
            'product_id' => $productId,
            'sku' => $sku,
            'barcode' => $barcode,
            'variant_name' => 'Standard',
            'purchase_price' => $purchasePrice,
            'selling_price' => $sellingPrice,
            'wholesale_price' => !empty($_POST['wholesale_price']) ? (float) $_POST['wholesale_price'] : null,
            'tax_rate' => $taxRate,
            'reorder_level' => $reorderLevel,
            'max_stock_level' => !empty($_POST['max_stock_level']) ? (int) $_POST['max_stock_level'] : 0,
            'is_active' => 1
        ];

        $variantId = $variantModel->create($variantData);
        $createdVariants['default'] = $variantId;
        $variantCount = 1;

        // Add product image as variant image (if uploaded)
        if ($mainImageUrl) {
            $variantModel->addImage($variantId, $mainImageUrl, true);
        }
    }

    // ============================================
    // HANDLE STOCK ALLOCATIONS
    // ============================================
    
    if (isset($_POST['stock_allocations']) && is_array($_POST['stock_allocations'])) {
        foreach ($_POST['stock_allocations'] as $allocationKey => $allocation) {
            // Skip if no warehouse or quantity
            if (empty($allocation['warehouse_id']) || empty($allocation['quantity']) || $allocation['quantity'] <= 0) {
                continue;
            }

            // Determine which variant this allocation belongs to
            $variantIndex = $allocation['variant_id'] ?? 'default';
            $variantId = null;

            if ($variantIndex === 'default') {
                $variantId = $createdVariants['default'] ?? null;
            } else {
                $variantId = $createdVariants[(int) $variantIndex] ?? null;
            }

            if (!$variantId) {
                continue;
            }

            $locationId = !empty($allocation['location_id']) ? (int) $allocation['location_id'] : null;
            $quantity = (float) $allocation['quantity'];
            $unitCost = !empty($allocation['unit_cost']) ? (float) $allocation['unit_cost'] : null;

            // If no unit cost provided, use the variant's purchase price
            if (!$unitCost) {
                $variant = $variantModel->find($variantId);
                if ($variant) {
                    $unitCost = (float) $variant['purchase_price'];
                }
            }

            // Update inventory
            $inventoryModel->updateStock(
                $variantId,
                (int) $allocation['warehouse_id'],
                $quantity,
                $unitCost,
                $locationId,
                $userId
            );
        }
    }

    // Commit transaction
    $db->commit();

    // Get category name
    $category = $categoryModel->find($categoryId);
    $categoryName = $category ? $category['category_name'] : '-';

    // Prepare response
    $productResponse = [
        'id' => $productId,
        'product_code' => $productCode,
        'product_name' => trim($_POST['product_name']),
        'brand' => trim($_POST['brand'] ?? ''),
        'category_name' => $categoryName,
        'variant_count' => $variantCount,
        'has_variants' => $hasVariants,
        'created_at' => date('Y-m-d H:i:s')
    ];

    // Generate a new CSRF token for the next request
    $newCsrfToken = CSRF::generateMultiUse();

    echo json_encode([
        'success' => true,
        'message' => 'Product added successfully!',
        'product' => $productResponse,
        'product_id' => $productId,
        'variant_count' => $variantCount,
        'new_csrf_token' => $newCsrfToken
    ]);
    exit;

} catch (Exception $e) {
    // Rollback if transaction is active
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log("Product creation error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to add product: ' . $e->getMessage()]);
    exit;
}
?>