<?php
// pages/purchasing/create-order.php
declare(strict_types=1);

$pageTitle = 'Create Purchase Order - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/PurchaseOrder.php';
require_once __DIR__ . '/../../models/Supplier.php';
require_once __DIR__ . '/../../models/Variant.php';
require_once __DIR__ . '/../../models/Product.php';
require_once __DIR__ . '/../../models/Category.php';
require_once __DIR__ . '/../../models/Warehouse.php';
require_once __DIR__ . '/../../models/Location.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR', 'ACC'])) {
    SessionManager::flash('error', 'You do not have permission to create purchase orders.');
    header('Location: ' . route_url('purchasing/orders'));
    exit;
}

// Check if company context is set
if (!$companyId) {
    SessionManager::flash('error', 'Company context not found. Please log in again.');
    header('Location: ' . route_url('login'));
    exit;
}

// Initialize models with company ID
$purchaseOrderModel = new PurchaseOrder($companyId);
$supplierModel = new Supplier($companyId);
$variantModel = new Variant($companyId);
$productModel = new Product($companyId);
$categoryModel = new Category($companyId);
$warehouseModel = new Warehouse($companyId);
$locationModel = new Location($companyId);

// Get suppliers for dropdown (company-specific)
$suppliers = $supplierModel->all(['id', 'supplier_code', 'supplier_name'], 'is_active = 1');

// Get warehouses for dropdown (company-specific)
$warehouses = $warehouseModel->all(['id', 'warehouse_name'], 'is_active = 1');

// Get locations for filtering (company-specific)
$locations = $locationModel->getAllWithWarehouse();

// GET ALL PRODUCTS AND VARIANTS FOR DATASET (company-specific)
$productDataset = [];
try {
    $sql = "
        SELECT 
            v.id,
            v.sku,
            v.barcode,
            v.variant_name,
            v.purchase_price,
            v.tax_rate,
            p.id as product_id,
            p.product_name,
            p.product_code
        FROM variants v
        JOIN products p ON v.product_id = p.id
        WHERE v.is_active = 1 
            AND p.is_active = 1
            AND v.company_id = :p_1_company_id
            AND p.company_id = :p_2_company_id
        ORDER BY p.product_name, v.variant_name
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute(['p_1_company_id' => $companyId, 'p_2_company_id' => $companyId]);
    $products = $stmt->fetchAll();

    foreach ($products as $product) {
        $displayName = $product['product_name'];
        if ($product['variant_name'] && $product['variant_name'] !== 'Standard') {
            $displayName .= ' - ' . $product['variant_name'];
        }

        $productDataset[] = [
            'id' => (int) $product['id'],
            'variant_id' => (int) $product['id'], // Add variant_id for easier access
            'sku' => $product['sku'],
            'name' => $displayName,
            'product_name' => $product['product_name'],
            'variant_name' => $product['variant_name'],
            'purchase_price' => (float) ($product['purchase_price'] ?? 0),
            'tax_rate' => (float) ($product['tax_rate'] ?? 0)
        ];
    }
} catch (Exception $e) {
    error_log("Error loading product dataset for company {$companyId}: " . $e->getMessage());
    $productDataset = [];
}

// Get categories for dropdown (company-specific)
$categories = $categoryModel->all(['id', 'category_name']);

// Generate CSRF token
$csrfToken = CSRF::generate();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
        SessionManager::flash('error', 'Invalid security token.');
        header('Location: ?page=purchasing/create-order');
        exit;
    }

    try {
        if (empty($_POST['supplier_id'])) {
            throw new Exception('Please select a supplier.');
        }

        if (empty($_POST['items']) || !is_array($_POST['items'])) {
            throw new Exception('Please add at least one item.');
        }

        // Verify supplier belongs to company
        $supplier = $supplierModel->find((int)$_POST['supplier_id']);
        if (!$supplier) {
            throw new Exception('Selected supplier does not exist or does not belong to your company.');
        }

        // Prepare order data
        $orderData = [
            'supplier_id' => (int) $_POST['supplier_id'],
            'order_date' => $_POST['order_date'] ?? date('Y-m-d'),
            'expected_date' => !empty($_POST['expected_date']) ? $_POST['expected_date'] : null,
            'status' => $_POST['status'] ?? 'draft',
            'notes' => !empty($_POST['notes']) ? trim($_POST['notes']) : null,
            'created_by' => $userId
        ];

        // Prepare items
        $items = [];
        foreach ($_POST['items'] as $item) {
            if (empty($item['variant_id']) || empty($item['quantity']) || $item['quantity'] <= 0) {
                continue;
            }

            // Verify variant belongs to company
            $variant = $variantModel->find((int)$item['variant_id']);
            if (!$variant) {
                throw new Exception('Selected product variant does not exist or does not belong to your company.');
            }

            $items[] = [
                'variant_id' => (int) $item['variant_id'],
                'quantity' => (float) $item['quantity'],
                'unit_price' => (float) ($item['unit_price'] ?? 0),
                'tax_rate' => (float) ($item['tax_rate'] ?? 0)
            ];
        }

        if (empty($items)) {
            throw new Exception('No valid items.');
        }

        // Create order
        $orderId = $purchaseOrderModel->createOrder($orderData, $items);

        SessionManager::flash('success', 'Purchase order created successfully!');
        header('Location: ' . route_url('purchasing/view-order', ['id' => $orderId]));
        exit;

    } catch (Exception $e) {
        error_log("PO creation error for company {$companyId}: " . $e->getMessage());
        SessionManager::flash('error', 'Failed to create purchase order: ' . $e->getMessage());
    }
}
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <a href="<?php echo route_url('purchasing/orders'); ?>"
                        class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Orders
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-plus-circle me-2"></i>Create Purchase Order
                    </h2>
                    <p class="mb-0 text-muted">Create a new purchase order for your company</p>
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

    <?php if ($flash = SessionManager::flash('success')): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($flash); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Warning if no suppliers exist -->
    <?php if (empty($suppliers)): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            No suppliers found. Please <a href="<?php echo route_url('purchasing/suppliers'); ?>" class="alert-link">add a supplier</a> first.
        </div>
    <?php endif; ?>

    <!-- Warning if no products exist -->
    <?php if (empty($productDataset)): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            No products found. Please <a href="<?php echo route_url('products/create'); ?>" class="alert-link">add products</a> first.
        </div>
    <?php endif; ?>

    <!-- Form -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-edit me-2"></i>Purchase Order Details
            </h6>
        </div>
        <div class="card-body">
            <form method="POST" id="create-po-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                <!-- Supplier and Dates -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <label for="supplier_id" class="form-label">Supplier <span class="text-danger">*</span></label>
                        <select class="form-control" id="supplier_id" name="supplier_id" required 
                                <?php echo empty($suppliers) ? 'disabled' : ''; ?>>
                            <option value="">Select Supplier</option>
                            <?php foreach ($suppliers as $sup): ?>
                                <option value="<?php echo $sup['id']; ?>">
                                    <?php echo htmlspecialchars($sup['supplier_name']); ?> 
                                    (<?php echo htmlspecialchars($sup['supplier_code']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($suppliers)): ?>
                            <small class="text-muted">No suppliers available</small>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-3">
                        <label for="order_date" class="form-label">Order Date</label>
                        <input type="date" class="form-control" id="order_date" name="order_date"
                            value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="col-md-3">
                        <label for="expected_date" class="form-label">Expected Date</label>
                        <input type="date" class="form-control" id="expected_date" name="expected_date"
                            value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                    </div>

                    <div class="col-md-2">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-control" id="status" name="status">
                            <option value="draft">Draft</option>
                            <option value="pending">Submit for Approval</option>
                        </select>
                    </div>
                </div>

                <!-- Items Section -->
                <h5 class="mb-3 border-bottom pb-2">
                    <i class="fas fa-boxes me-2"></i>Items
                </h5>

                <div class="row mb-3">
                    <div class="col-12">
                        <button type="button" class="btn btn-primary" id="add-item-btn"
                                <?php echo empty($productDataset) ? 'disabled' : ''; ?>>
                            <i class="fas fa-plus me-2"></i>Add Item
                        </button>
                        <?php if (empty($productDataset)): ?>
                            <small class="text-muted ms-2">Add products first to create order items</small>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="items-container"></div>

                <!-- Summary -->
                <div class="row mt-4">
                    <div class="col-md-6 offset-md-6">
                        <div class="card bg-light">
                            <div class="card-body">
                                <table class="table table-sm mb-0">
                                    <tr>
                                        <th>Subtotal:</th>
                                        <td class="text-end" id="subtotal">RWF 0</td>
                                    </tr>
                                    <tr>
                                        <th>Tax (18%):</th>
                                        <td class="text-end" id="tax">RWF 0</td>
                                    </tr>
                                    <tr class="fw-bold">
                                        <th>Total:</th>
                                        <td class="text-end text-primary fs-5" id="total">RWF 0</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Notes -->
                <div class="row mb-4 mt-3">
                    <div class="col-12">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2" 
                            placeholder="Any additional notes or instructions..."></textarea>
                    </div>
                </div>

                <!-- Submit Buttons -->
                <div class="row">
                    <div class="col-12">
                        <hr>
                        <div class="d-flex justify-content-between">
                            <a href="<?php echo route_url('purchasing/orders'); ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-primary" 
                                    <?php echo (empty($suppliers) || empty($productDataset)) ? 'disabled' : ''; ?>>
                                <i class="fas fa-save me-2"></i>Create Purchase Order
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Product Modal -->
<div class="modal fade" id="addProductModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-box me-2"></i>Add New Product
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="addProductForm" method="POST" action="<?php echo route_url('products/create-ajax'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo CSRF::generate(); ?>">
                <input type="hidden" name="company_id" value="<?php echo $companyId; ?>">

                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="product_name" class="form-label">Product Name <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="product_name" name="product_name" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="product_code" class="form-label">Product Code</label>
                            <input type="text" class="form-control" id="product_code" name="product_code" readonly>
                            <small class="text-muted">Auto-generated</small>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="category_id" class="form-label">Category <span
                                    class="text-danger">*</span></label>
                            <select class="form-control" id="category_id" name="category_id" required>
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>">
                                        <?php echo htmlspecialchars($cat['category_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="brand" class="form-label">Brand</label>
                            <input type="text" class="form-control" id="brand" name="brand">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="product_description" class="form-label">Description</label>
                        <textarea class="form-control" id="product_description" name="description" rows="2"></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="unit_of_measure" class="form-label">Unit of Measure</label>
                            <select class="form-control" id="unit_of_measure" name="unit_of_measure">
                                <option value="PCS">Pieces (PCS)</option>
                                <option value="KG">Kilograms (KG)</option>
                                <option value="L">Liters (L)</option>
                                <option value="M">Meters (M)</option>
                                <option value="BOX">Box</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Variant Modal -->
<div class="modal fade" id="addVariantModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="fas fa-code-branch me-2"></i>Add New Variant
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="addVariantForm" method="POST" action="<?php echo route_url('products/add-variant-ajax'); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo CSRF::generate(); ?>">
                <input type="hidden" name="company_id" value="<?php echo $companyId; ?>">

                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="variant_product_id" class="form-label">Product <span
                                    class="text-danger">*</span></label>
                            <select class="form-control" id="variant_product_id" name="product_id" required>
                                <option value="">Select Product</option>
                                <?php
                                $products = $productModel->all(['id', 'product_name', 'product_code'], 'is_active = 1');
                                foreach ($products as $prod):
                                    ?>
                                    <option value="<?php echo $prod['id']; ?>">
                                        <?php echo htmlspecialchars($prod['product_name'] . ' (' . $prod['product_code'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="variant_name" class="form-label">Variant Name <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="variant_name" name="variant_name"
                                placeholder="e.g., Black, Large, 1GB" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="sku" class="form-label">SKU</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="sku" name="sku" readonly>
                                <button type="button" class="btn btn-outline-secondary" id="generateSkuBtn">
                                    <i class="fas fa-redo"></i>
                                </button>
                            </div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="barcode" class="form-label">Barcode</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="barcode" name="barcode" readonly>
                                <button type="button" class="btn btn-outline-secondary" id="generateBarcodeBtn">
                                    <i class="fas fa-redo"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="purchase_price" class="form-label">Purchase Price</label>
                            <div class="input-group">
                                <span class="input-group-text">RWF</span>
                                <input type="number" class="form-control" id="variant_purchase_price"
                                    name="purchase_price" min="0" step="0.01" value="0">
                            </div>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="selling_price" class="form-label">Selling Price</label>
                            <div class="input-group">
                                <span class="input-group-text">RWF</span>
                                <input type="number" class="form-control" id="variant_selling_price"
                                    name="selling_price" min="0" step="0.01" value="0">
                            </div>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="tax_rate" class="form-label">Tax Rate %</label>
                            <input type="number" class="form-control" id="variant_tax_rate" name="tax_rate" min="0"
                                max="100" step="0.1" value="18">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="reorder_level" class="form-label">Reorder Level</label>
                            <input type="number" class="form-control" id="reorder_level" name="reorder_level" min="0"
                                value="0">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="max_stock_level" class="form-label">Max Stock Level</label>
                            <input type="number" class="form-control" id="max_stock_level" name="max_stock_level"
                                min="0" value="0">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Attributes</label>
                        <div id="variant-attributes-container"></div>
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="add-attribute-btn">
                            <i class="fas fa-plus me-1"></i>Add Attribute
                        </button>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info">Save Variant</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Attribute Template -->
<template id="attribute-template">
    <div class="attribute-item row g-2 mb-2">
        <div class="col-md-5">
            <input type="text" class="form-control attribute-name" placeholder="Name (e.g., Color)">
        </div>
        <div class="col-md-5">
            <input type="text" class="form-control attribute-value" placeholder="Value (e.g., Black)">
        </div>
        <div class="col-md-2">
            <button type="button" class="btn btn-sm btn-outline-danger w-100 remove-attribute">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>
</template>

<!-- Item Template -->
<template id="item-template">
    <div class="item-row border rounded p-3 mb-3 bg-light">
        <div class="row">
            <div class="col-md-5 mb-2">
                <label class="form-label">Product <span class="text-danger">*</span></label>
                <div class="input-group">
                    <input type="text" class="form-control product-search" placeholder="Search product..."
                        autocomplete="off">
                    <button type="button" class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-plus"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item add-product" href="#"><i class="fas fa-box me-2"></i>New Product</a>
                        </li>
                        <li><a class="dropdown-item add-variant" href="#"><i class="fas fa-code-branch me-2"></i>New
                                Variant</a></li>
                    </ul>
                </div>
                <input type="hidden" class="variant-id" name="items[INDEX][variant_id]">
                <div class="search-results list-group mt-1" style="display: none; max-height: 200px; overflow-y: auto;">
                </div>
            </div>

            <div class="col-md-2 mb-2">
                <label class="form-label">Quantity <span class="text-danger">*</span></label>
                <input type="number" class="form-control quantity" name="items[INDEX][quantity]" min="0.01" step="0.01"
                    value="1" required>
            </div>

            <div class="col-md-2 mb-2">
                <label class="form-label">Unit Price <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text">RWF</span>
                    <input type="number" class="form-control unit-price" name="items[INDEX][unit_price]" min="0"
                        step="0.01" value="0" required>
                </div>
            </div>

            <div class="col-md-2 mb-2">
                <label class="form-label">Tax %</label>
                <input type="number" class="form-control tax-rate" name="items[INDEX][tax_rate]" min="0" max="100"
                    step="0.1" value="18">
            </div>

            <div class="col-md-1 mb-2 d-flex align-items-end">
                <button type="button" class="btn btn-sm btn-outline-danger remove-item">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
        <div class="row">
            <div class="col-md-12">
                <small class="text-muted line-total">Line Total: RWF 0</small>
            </div>
        </div>
    </div>
</template>

<style>
    .search-results {
        position: absolute;
        z-index: 1000;
        width: calc(100% - 30px);
        background: white;
        border: 1px solid #ddd;
        border-radius: 4px;
        box-shadow: 0 6px 12px rgba(0, 0, 0, 0.175);
        max-height: 200px;
        overflow-y: auto;
    }

    .list-group-item {
        cursor: pointer;
        padding: 8px 12px;
    }

    .list-group-item:hover {
        background-color: #f8f9fa;
    }

    .item-row {
        transition: background-color 0.2s;
    }

    .item-row:hover {
        background-color: #f8f9fa !important;
    }
</style>

<!-- Pass PHP data to JavaScript -->
<script>
    const productDataset = <?php echo json_encode($productDataset); ?>;
    const locations = <?php echo json_encode($locations); ?>;
    const baseUrl = '<?php echo BASE_URL; ?>';
    const companyId = '<?php echo $companyId; ?>';
</script>

<?php $jsFiles = ['purchasing/create_order.js']; ?>