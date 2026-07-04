<?php
// pages/products/add_variant.php
declare(strict_types=1);

$pageTitle = 'Add Variant - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';

// Load models
require_once __DIR__ . '/../../models/Product.php';
require_once __DIR__ . '/../../models/Variant.php';
require_once __DIR__ . '/../../models/Warehouse.php';
require_once __DIR__ . '/../../models/Location.php';
require_once __DIR__ . '/../../models/Inventory.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR'])) {
    SessionManager::flash('error', 'You do not have permission to add variants.');
    header('Location: ' . route_url('products'));
    exit;
}

// Get product ID from URL
$productId = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;

if (!$productId) {
    SessionManager::flash('error', 'Product ID is required.');
    header('Location: ' . route_url('products'));
    exit;
}

// Initialize models
$productModel = new Product();
$variantModel = new Variant();
$warehouseModel = new Warehouse();
$locationModel = new Location();

// Get product details
$product = $productModel->getWithDetails($productId);

if (!$product) {
    SessionManager::flash('error', 'Product not found.');
    header('Location: ' . route_url('products'));
    exit;
}

// Get warehouses for stock allocation
$warehouses = $warehouseModel->all(['id', 'warehouse_code', 'warehouse_name', 'city'], 'is_active = 1');

// Get storage locations
$locations = $locationModel->getAllWithWarehouse();

// Generate CSRF token
$csrfToken = CSRF::generate();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
        SessionManager::flash('error', 'Invalid security token. Please try again.');
        header('Location: ?page=products/add-variant&product_id=' . $productId);
        exit;
    }

    try {
        // Basic validation
        if (empty($_POST['variant_name'])) {
            throw new Exception("Variant name is required.");
        }

        // Start transaction
        $db->beginTransaction();

        // Generate SKU if not provided
        $sku = !empty($_POST['sku']) ?
            trim($_POST['sku']) :
            generate_sku($productId, $product['product_code']);

        // Generate barcode if not provided
        $barcode = !empty($_POST['barcode']) ?
            trim($_POST['barcode']) :
            generate_barcode();

        // Check barcode uniqueness
        $existingVariant = $variantModel->findByBarcode($barcode);
        if ($existingVariant) {
            $barcode = generate_barcode();
        }

        // Handle variant image upload
        $variantImageUrl = null;
        if (isset($_FILES['variant_image']) && $_FILES['variant_image']['error'] === UPLOAD_ERR_OK) {
            $variantImageUrl = uploadVariantImage($_FILES['variant_image'], $productId, time());
        }

        // Prepare attributes
        $attributes = [];
        if (isset($_POST['attributes']) && is_array($_POST['attributes'])) {
            foreach ($_POST['attributes'] as $attr) {
                if (!empty($attr['name']) && !empty($attr['value'])) {
                    $attributes[] = [
                        'name' => trim($attr['name']),
                        'value' => trim($attr['value'])
                    ];
                }
            }
        }

        // Insert variant
        $variantData = [
            'product_id' => $productId,
            'sku' => $sku,
            'barcode' => $barcode,
            'variant_name' => trim($_POST['variant_name']),
            'purchase_price' => !empty($_POST['purchase_price']) ? (float) $_POST['purchase_price'] : 0,
            'selling_price' => !empty($_POST['selling_price']) ? (float) $_POST['selling_price'] : 0,
            'wholesale_price' => !empty($_POST['wholesale_price']) ? (float) $_POST['wholesale_price'] : null,
            'tax_rate' => !empty($_POST['tax_rate']) ? (float) $_POST['tax_rate'] : 18.00,
            'reorder_level' => !empty($_POST['reorder_level']) ? (int) $_POST['reorder_level'] : 0,
            'max_stock_level' => !empty($_POST['max_stock_level']) ? (int) $_POST['max_stock_level'] : 0,
            'is_active' => 1
        ];

        $variantId = $variantModel->create($variantData);

        // Insert variant attributes
        if (!empty($attributes)) {
            foreach ($attributes as $attrIndex => $attr) {
                $variantModel->addAttribute($variantId, $attr['name'], $attr['value'], $attrIndex + 1);
            }
        }

        // Add variant image if uploaded
        if ($variantImageUrl) {
            $variantModel->addImage($variantId, $variantImageUrl, true);
        }

        // Handle stock allocation if provided
        if (!empty($_POST['initial_stock']) && (float) $_POST['initial_stock'] > 0) {
            $inventoryModel = new Inventory();

            $warehouseId = !empty($_POST['warehouse_id']) ? (int) $_POST['warehouse_id'] : 1; // Default warehouse
            $locationId = !empty($_POST['location_id']) ? (int) $_POST['location_id'] : null;
            $quantity = (float) $_POST['initial_stock'];
            $unitCost = !empty($_POST['unit_cost']) ? (float) $_POST['unit_cost'] : null;

            $inventoryModel->updateStock(
                $variantId,
                $warehouseId,
                $quantity,
                $unitCost,
                $locationId,
                $userId
            );
        }

        $db->commit();

        SessionManager::flash('success', 'Variant added successfully!');
        header('Location: ' . route_url('products/view', ['id' => $productId]));
        exit;

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Variant creation error: " . $e->getMessage());
        SessionManager::flash('error', 'Failed to add variant: ' . $e->getMessage());
    }
}

// Image upload function
function uploadVariantImage($file, $productId, $timestamp)
{
    $uploadDir = __DIR__ . '/../../assets/uploads/products/variants/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception('Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.');
    }

    if ($file['size'] > $maxSize) {
        throw new Exception('File is too large. Maximum size is 5MB.');
    }

    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'variant_' . $productId . '_' . $timestamp . '.' . $extension;
    $filepath = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return 'uploads/products/variants/' . $filename;
    }

    throw new Exception('Failed to upload image.');
}
?>

<div class="container-fluid">
    <!-- Back button and title -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <a href="<?php echo route_url('products/view', ['id' => $productId]); ?>"
                        class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Product
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-plus-circle me-2"></i>Add New Variant
                    </h2>
                    <p class="mb-0 text-muted">
                        Adding variant for: <strong>
                            <?php echo htmlspecialchars($product['product_name']); ?>
                        </strong>
                        (
                        <?php echo htmlspecialchars($product['product_code']); ?>)
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if ($flash = SessionManager::flash('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($flash); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Form -->
    <div class="card shadow">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-edit me-2"></i>Variant Details
            </h6>
        </div>
        <div class="card-body">
            <form method="POST" id="add-variant-form" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                <div class="row">
                    <div class="col-md-6">
                        <h5 class="mb-3"><i class="fas fa-info-circle me-2"></i>Basic Information</h5>

                        <div class="mb-3">
                            <label for="variant_name" class="form-label">
                                Variant Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="variant_name" name="variant_name" required
                                placeholder="e.g., Black, Large, 1GB">
                        </div>

                        <div class="mb-3">
                            <label for="sku" class="form-label">SKU</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="sku" name="sku"
                                    placeholder="Leave empty to auto-generate">
                                <button type="button" class="btn btn-outline-secondary" id="generate-sku">
                                    <i class="fas fa-redo"></i>
                                </button>
                            </div>
                            <div class="form-text">Stock Keeping Unit - unique identifier</div>
                        </div>

                        <div class="mb-3">
                            <label for="barcode" class="form-label">Barcode</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="barcode" name="barcode"
                                    placeholder="Leave empty to auto-generate">
                                <button type="button" class="btn btn-outline-secondary" id="generate-barcode">
                                    <i class="fas fa-redo"></i>
                                </button>
                            </div>
                            <div class="form-text">EAN-13 format, auto-generated if empty</div>
                        </div>

                        <div class="mb-3">
                            <label for="variant_image" class="form-label">Variant Image</label>
                            <input type="file" class="form-control" id="variant_image" name="variant_image"
                                accept="image/*">
                            <div class="form-text">Max 5MB. JPG, PNG, GIF, WebP allowed.</div>
                            <div class="mt-2" id="image-preview"></div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <h5 class="mb-3"><i class="fas fa-tag me-2"></i>Pricing & Inventory</h5>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="purchase_price" class="form-label">Purchase Price</label>
                                <div class="input-group">
                                    <span class="input-group-text">RWF</span>
                                    <input type="number" class="form-control" id="purchase_price" name="purchase_price"
                                        min="0" step="0.01" value="0">
                                </div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="selling_price" class="form-label">Selling Price</label>
                                <div class="input-group">
                                    <span class="input-group-text">RWF</span>
                                    <input type="number" class="form-control" id="selling_price" name="selling_price"
                                        min="0" step="0.01" value="0">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="wholesale_price" class="form-label">Wholesale Price</label>
                                <div class="input-group">
                                    <span class="input-group-text">RWF</span>
                                    <input type="number" class="form-control" id="wholesale_price"
                                        name="wholesale_price" min="0" step="0.01">
                                </div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="tax_rate" class="form-label">Tax Rate (%)</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="tax_rate" name="tax_rate" min="0"
                                        max="100" step="0.01" value="18.00">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="reorder_level" class="form-label">Reorder Level</label>
                                <input type="number" class="form-control" id="reorder_level" name="reorder_level"
                                    min="0" value="0">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="max_stock_level" class="form-label">Max Stock Level</label>
                                <input type="number" class="form-control" id="max_stock_level" name="max_stock_level"
                                    min="0" value="0">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Variant Attributes -->
                <div class="row mt-4">
                    <div class="col-12">
                        <h5 class="mb-3 border-bottom pb-2">
                            <i class="fas fa-list me-2"></i>Variant Attributes
                        </h5>

                        <div id="attributes-container">
                            <!-- Attributes will be added here dynamically -->
                        </div>

                        <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="add-attribute-btn">
                            <i class="fas fa-plus me-1"></i>Add Attribute
                        </button>
                    </div>
                </div>

                <!-- Initial Stock -->
                <div class="row mt-4">
                    <div class="col-12">
                        <h5 class="mb-3 border-bottom pb-2">
                            <i class="fas fa-warehouse me-2"></i>Initial Stock (Optional)
                        </h5>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="initial_stock" class="form-label">Initial Quantity</label>
                                <input type="number" class="form-control" id="initial_stock" name="initial_stock"
                                    min="0" step="1" value="0">
                            </div>

                            <div class="col-md-4 mb-3">
                                <label for="warehouse_id" class="form-label">Warehouse</label>
                                <select class="form-control" id="warehouse_id" name="warehouse_id">
                                    <option value="">Select Warehouse</option>
                                    <?php foreach ($warehouses as $wh): ?>
                                        <option value="<?php echo $wh['id']; ?>">
                                            <?php echo htmlspecialchars($wh['warehouse_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4 mb-3">
                                <label for="location_id" class="form-label">Storage Location</label>
                                <select class="form-control" id="location_id" name="location_id">
                                    <option value="">Select Location (Optional)</option>
                                </select>
                            </div>

                            <div class="col-md-4 mb-3">
                                <label for="unit_cost" class="form-label">Unit Cost</label>
                                <div class="input-group">
                                    <span class="input-group-text">RWF</span>
                                    <input type="number" class="form-control" id="unit_cost" name="unit_cost" min="0"
                                        step="0.01" value="0">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Submit Buttons -->
                <div class="row mt-4">
                    <div class="col-12">
                        <hr>
                        <div class="d-flex justify-content-between">
                            <a href="<?php echo route_url('products/view', ['id' => $productId]); ?>"
                                class="btn btn-outline-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-save me-2"></i>Save Variant
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Attribute Template -->
<template id="attribute-template">
    <div class="attribute-item row g-2 mb-2">
        <div class="col-md-5">
            <input type="text" class="form-control attribute-name" name="attributes[0][name]"
                placeholder="Attribute name (e.g., Color)">
        </div>
        <div class="col-md-5">
            <input type="text" class="form-control attribute-value" name="attributes[0][value]"
                placeholder="Attribute value (e.g., Black)">
        </div>
        <div class="col-md-2">
            <button type="button" class="btn btn-sm btn-outline-danger w-100 remove-attribute">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>
</template>

<!-- Pass PHP data to JavaScript -->
<script>
    const locations = <?php echo json_encode($locations); ?>;
    const productCode = JSON.stringify(<?php echo $product['product_code']; ?>);
    console.log(productCode);
</script>


<?php $jsFiles = ['product/add_variant.js']; ?>