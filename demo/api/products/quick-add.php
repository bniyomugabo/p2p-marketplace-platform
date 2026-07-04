<?php
// api/products/quick-add.php - Updated with multi-company support
declare(strict_types=1);


require_once '../../config/autoload.php';
require_once '../../models/Product.php';
require_once '../../models/Variant.php';
require_once '../../models/Category.php';

header('Content-Type: application/json');

function debug_log($message, $data = null)
{
    $log = date('Y-m-d H:i:s') . ' - ' . $message;
    if ($data !== null) {
        $log .= ' - ' . json_encode($data);
    }
    error_log($log);
}

debug_log("Quick add API called");

// Check authentication
if (!isset($_SESSION['user_id'])) {
    debug_log("Unauthorized: No user_id in session");
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in first']);
    exit;
}

$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = (int) $_SESSION['user_id'];
$companyId = $_SESSION['company_id'] ?? null;

debug_log("Authenticated user", ['id' => $userId, 'role' => $userRole, 'company' => $companyId]);

// Check permission
if (!in_array($userRole, ['ADM', 'MGR', 'ACC'])) {
    debug_log("Permission denied for role: {$userRole}");
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to add products']);
    exit;
}

// Get POST data
$inputData = $_POST;

// If it's a JSON request, parse the body
if (empty($_POST) && $inputJSON = file_get_contents('php://input')) {
    debug_log("Received JSON input");
    $inputData = json_decode($inputJSON, true) ?? [];
}

debug_log("Input data", $inputData);

// Verify CSRF token
if (!isset($inputData['csrf_token'])) {
    debug_log("Missing CSRF token");
    echo json_encode(['success' => false, 'message' => 'Missing security token']);
    exit;
}

if (!CSRF::validate($inputData['csrf_token'])) {
    debug_log("Invalid CSRF token: " . $inputData['csrf_token']);
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

$db = Database::getInstance();
$action = $inputData['action'] ?? '';

try {
    if ($action === 'add_product') {
        debug_log("Processing add_product action");

        // Validate required fields
        if (empty($inputData['product_name'])) {
            throw new Exception('Product name is required');
        }

        if (empty($inputData['category_id'])) {
            throw new Exception('Category is required');
        }

        $productModel = new Product($companyId);
        $categoryModel = new Category($companyId);

        // Verify category is activated for this company
        $categoryId = (int) $inputData['category_id'];
        if (!$categoryModel->isActivatedForCompany($categoryId, $companyId)) {
            throw new Exception('Selected category is not activated for your company');
        }

        // Generate product code
        $productCode = $productModel->generateCode();
        debug_log("Generated product code: " . $productCode);

        // Create product
        $productData = [
            'company_id' => $companyId,
            'product_code' => $productCode,
            'product_name' => trim($inputData['product_name']),
            'description' => $inputData['description'] ?? null,
            'category_id' => $categoryId,
            'brand' => $inputData['brand'] ?? null,
            'has_variants' => 0,
            'unit_of_measure' => $inputData['unit_of_measure'] ?? 'PCS',
            'is_active' => 1,
            'created_by' => $userId
        ];

        debug_log("Creating product with data", $productData);

        $productId = $productModel->create($productData);
        debug_log("Product created with ID: " . $productId);

        if (!$productId) {
            throw new Exception('Failed to create product - database insert returned no ID');
        }

        // Create default variant
        $variantModel = new Variant($companyId);

        // Check if SKU already exists
        $sku = 'SKU-' . $productCode . '-001';
        $existingVariant = $variantModel->findBySku($sku);
        if ($existingVariant) {
            $sku = 'SKU-' . $productCode . '-' . str_pad((string) rand(1, 999), 3, '0', STR_PAD_LEFT);
        }

        $variantData = [
            'company_id' => $companyId,
            'product_id' => $productId,
            'sku' => $sku,
            'barcode' => generate_barcode(),
            'variant_name' => 'Standard',
            'purchase_price' => 0,
            'selling_price' => 0,
            'tax_rate' => 18.00,
            'reorder_level' => 0,
            'max_stock_level' => 0,
            'is_active' => 1
        ];

        debug_log("Creating variant with data", $variantData);

        $variantId = $variantModel->create($variantData);
        debug_log("Variant created with ID: " . $variantId);

        if (!$variantId) {
            throw new Exception('Product created but failed to create default variant');
        }

        $response = [
            'success' => true,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'product_name' => $inputData['product_name'],
            'product_code' => $productCode,
            'message' => 'Product added successfully'
        ];

        debug_log("Success response", $response);
        echo json_encode($response);

    } elseif ($action === 'add_variant') {
        debug_log("Processing add_variant action");

        // Validate required fields
        if (empty($inputData['product_id'])) {
            throw new Exception('Product is required');
        }

        if (empty($inputData['variant_name'])) {
            throw new Exception('Variant name is required');
        }

        $variantModel = new Variant($companyId);
        $productModel = new Product($companyId);

        // Get product details
        $product = $productModel->find((int) $inputData['product_id']);
        if (!$product) {
            throw new Exception('Product not found');
        }

        debug_log("Found product", $product);

        // Generate SKU if not provided
        $sku = !empty($inputData['sku']) ? $inputData['sku'] :
            'SKU-' . $product['product_code'] . '-' . str_pad((string) rand(1, 999), 3, '0', STR_PAD_LEFT);

        // Generate barcode if not provided
        $barcode = !empty($inputData['barcode']) ? $inputData['barcode'] : generate_barcode();

        // Check SKU uniqueness
        $existing = $variantModel->findBySku($sku);
        if ($existing) {
            $sku = 'SKU-' . $product['product_code'] . '-' . str_pad((string) rand(1, 999), 3, '0', STR_PAD_LEFT);
            debug_log("SKU existed, generated new: " . $sku);
        }

        // Create variant
        $variantData = [
            'company_id' => $companyId,
            'product_id' => (int) $inputData['product_id'],
            'sku' => $sku,
            'barcode' => $barcode,
            'variant_name' => trim($inputData['variant_name']),
            'purchase_price' => !empty($inputData['purchase_price']) ? (float) $inputData['purchase_price'] : 0,
            'selling_price' => !empty($inputData['selling_price']) ? (float) $inputData['selling_price'] : 0,
            'tax_rate' => !empty($inputData['tax_rate']) ? (float) $inputData['tax_rate'] : 18.00,
            'reorder_level' => !empty($inputData['reorder_level']) ? (int) $inputData['reorder_level'] : 0,
            'max_stock_level' => !empty($inputData['max_stock_level']) ? (int) $inputData['max_stock_level'] : 0,
            'is_active' => 1
        ];

        debug_log("Creating variant with data", $variantData);

        $variantId = $variantModel->create($variantData);
        debug_log("Variant created with ID: " . $variantId);

        if (!$variantId) {
            throw new Exception('Failed to create variant');
        }

        // Add attributes if provided
        if (!empty($inputData['attributes'])) {
            $attributes = is_string($inputData['attributes']) ?
                json_decode($inputData['attributes'], true) :
                $inputData['attributes'];

            debug_log("Processing attributes", $attributes);

            if (is_array($attributes)) {
                foreach ($attributes as $index => $attr) {
                    if (!empty($attr['name']) && !empty($attr['value'])) {
                        $variantModel->addAttribute(
                            $variantId,
                            trim($attr['name']),
                            trim($attr['value']),
                            $index + 1
                        );
                        debug_log("Added attribute: {$attr['name']} = {$attr['value']}");
                    }
                }
            }
        }

        // Update product to indicate it has variants
        if ($product['has_variants'] == 0) {
            $productModel->update($product['id'], ['has_variants' => 1]);
            debug_log("Updated product has_variants flag");
        }

        $response = [
            'success' => true,
            'variant_id' => $variantId,
            'product_name' => $product['product_name'],
            'variant_name' => $inputData['variant_name'],
            'sku' => $sku,
            'purchase_price' => $variantData['purchase_price'],
            'tax_rate' => $variantData['tax_rate'],
            'message' => 'Variant added successfully'
        ];

        debug_log("Success response", $response);
        echo json_encode($response);

    } else {
        throw new Exception('Invalid action: ' . $action);
    }

} catch (Exception $e) {
    debug_log("ERROR: " . $e->getMessage());
    debug_log("Stack trace: " . $e->getTraceAsString());

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
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
?>