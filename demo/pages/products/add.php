<?php
// pages/products/add.php
declare(strict_types=1);

$pageTitle = 'Add Multiple Products - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';

// Get database connection
$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR'])) {
    SessionManager::flash('error', 'You do not have permission to add products.');
    header('Location: ?page=products/list');
    exit;
}

// Initialize models with company ID
require_once __DIR__ . '/../../models/Category.php';
require_once __DIR__ . '/../../models/Warehouse.php';
require_once __DIR__ . '/../../models/Location.php';
require_once __DIR__ . '/../../models/Product.php';

$categoryModel = new Category($companyId);
$warehouseModel = new Warehouse($companyId);
$locationModel = new Location($companyId);
$productModel = new Product($companyId);

// Get ONLY categories that are ACTIVATED for this company (with hierarchy)
$categories = $categoryModel->getAllWithHierarchy();

// Flatten categories for dropdown
function flattenCategoriesForSelect($categories, $level = 0, &$result = [])
{
    foreach ($categories as $category) {
        $category['level'] = $level;
        $result[] = $category;
        if (!empty($category['children'])) {
            flattenCategoriesForSelect($category['children'], $level + 1, $result);
        }
    }
    return $result;
}

$flatCategories = flattenCategoriesForSelect($categories);

// Get warehouses for this company
$warehouses = $warehouseModel->all(['id', 'warehouse_code', 'warehouse_name', 'city', 'is_main'], 'is_active = 1');

// Get storage locations with warehouse info
$locations = $locationModel->getAllWithWarehouse();

// Initialize session for products being added
if (!isset($_SESSION['pending_products'])) {
    $_SESSION['pending_products'] = [];
}

// Display any flash messages
$errorMessage = SessionManager::flash('error');
$successMessage = SessionManager::flash('success');

$pendingProducts = $_SESSION['pending_products'];
?>

<!-- Display flash messages -->
<?php if ($errorMessage): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($errorMessage); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($successMessage): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($successMessage); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-plus-circle me-2"></i>Add Multiple Products
                    </h2>
                    <p class="mb-0 text-muted">Add products one by one. They will appear in the table below.</p>
                </div>
                <div>
                    <?php if (!empty($pendingProducts)): ?>
                        <button type="button" class="btn btn-danger" id="clearAllBtn">
                            <i class="fas fa-trash-alt me-2"></i>Clear All
                        </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-primary ms-2" id="addProductBtn">
                        <i class="fas fa-plus-circle me-2"></i>Add New Product
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Products Table - Shows only products added in this session -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-list me-2"></i>Products Added
                <span class="badge bg-primary ms-2"><?php echo count($pendingProducts); ?> products</span>
            </h6>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="productsTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Product Code</th>
                            <th>Product Name</th>
                            <th>Brand</th>
                            <th>Category</th>
                            <th># Variants</th>
                            <th>Added At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pendingProducts)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-5">
                                    <i class="fas fa-box-open fa-3x mb-3 d-block"></i>
                                    <p>No products added yet. Click "Add New Product" to get started.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pendingProducts as $index => $product): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><code><?php echo htmlspecialchars($product['product_code']); ?></code></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($product['product_name']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($product['brand'] ?: '-'); ?></td>
                                    <td><?php echo htmlspecialchars($product['category_name']); ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-info"><?php echo htmlspecialchars($product['variant_count'] ?: '-'); ?></span>
                                    </td>
                                    <td><?php echo date('H:i:s', strtotime($product['created_at'])); ?></td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-info view-product"
                                            data-id="<?php echo $product['id']; ?>" title="View Product">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger remove-product"
                                            data-id="<?php echo $product['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($product['product_name']); ?>"
                                            title="Remove from list">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!empty($pendingProducts)): ?>
                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle me-2"></i>
                    These products have been successfully saved to the database.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Add Product Modal -->
<div class="modal fade" id="productModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" style="max-width: 1400px;">
        <div class="modal-content" style="max-height: 90vh;">
            <div class="modal-header bg-primary text-white sticky-top">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle me-2"></i>
                    <span id="modalTitle">Add New Product</span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="max-height: calc(90vh - 120px); overflow-y: auto;">
                <form id="productForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" id="csrf_token"
                        value="<?php echo CSRF::generateMultiUse(); ?>">

                    <!-- Progress Steps -->
                    <div class="steps-container d-flex justify-content-between mb-4">
                        <div class="step active" data-step="1">
                            <div class="step-icon"><i class="fas fa-box"></i></div>
                            <div class="step-label">Product Details</div>
                        </div>
                        <div class="step" data-step="2">
                            <div class="step-icon"><i class="fas fa-layer-group"></i></div>
                            <div class="step-label">Variants</div>
                        </div>
                        <div class="step" data-step="3">
                            <div class="step-icon"><i class="fas fa-warehouse"></i></div>
                            <div class="step-label">Stock Allocation</div>
                        </div>
                        <div class="step" data-step="4">
                            <div class="step-icon"><i class="fas fa-check-circle"></i></div>
                            <div class="step-label">Review & Submit</div>
                        </div>
                    </div>

                    <!-- Step 1: Product Details -->
                    <div class="step-content" id="step-1">
                        <div class="row">
                            <div class="col-md-6">
                                <h5 class="mb-3"><i class="fas fa-info-circle me-2"></i>Basic Information</h5>

                                <div class="mb-3">
                                    <label for="product_code" class="form-label">
                                        Product Code <span class="text-danger">*</span>
                                    </label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="product_code" name="product_code"
                                            required value="<?php echo $productModel->generateCode(); ?>" readonly>
                                        <button type="button" class="btn btn-outline-secondary regenerate-code">
                                            <i class="fas fa-redo"></i>
                                        </button>
                                    </div>
                                    <div class="form-text">Auto-generated product code</div>
                                </div>

                                <div class="mb-3">
                                    <label for="product_name" class="form-label">
                                        Product Name <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" class="form-control" id="product_name" name="product_name"
                                        required placeholder="e.g., Samsung Galaxy S24">
                                </div>

                                <div class="mb-3">
                                    <label for="category_id" class="form-label">
                                        Category <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-control" id="category_id" name="category_id" required>
                                        <option value="">Select Category</option>
                                        <?php foreach ($flatCategories as $cat): ?>
                                            <option value="<?php echo $cat['id']; ?>">
                                                <?php echo str_repeat('-- ', $cat['level']); ?>
                                                <?php echo htmlspecialchars($cat['category_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text small">Only categories activated for your company are shown
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="product_image" class="form-label">Product Image</label>
                                    <input type="file" class="form-control" id="product_image" name="product_image"
                                        accept="image/*">
                                    <div class="form-text">Max 5MB. JPG, PNG, GIF, WebP allowed.</div>
                                    <div class="mt-2" id="image-preview"></div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <h5 class="mb-3"><i class="fas fa-cubes me-2"></i>Additional Information</h5>

                                <div class="mb-3">
                                    <label for="unit_of_measure" class="form-label">Unit of Measure</label>
                                    <select class="form-control" id="unit_of_measure" name="unit_of_measure">
                                        <option value="PCS" selected>Pieces (PCS)</option>
                                        <option value="KG">Kilograms (KG)</option>
                                        <option value="L">Liters (L)</option>
                                        <option value="M">Meters (M)</option>
                                        <option value="BOX">Box</option>
                                        <option value="PKG">Package</option>
                                        <option value="SET">Set</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="description" class="form-label">Description</label>
                                    <textarea class="form-control" id="description" name="description" rows="4"
                                        placeholder="Product description..."></textarea>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="brand" class="form-label">Brand</label>
                                        <input type="text" class="form-control" id="brand" name="brand"
                                            placeholder="e.g., Samsung">
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="reorder_level" class="form-label">Default Reorder Level</label>
                                        <input type="number" class="form-control" id="reorder_level"
                                            name="reorder_level" min="0" value="10">
                                        <div class="form-text small">Alert when stock reaches this level</div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="max_stock_level" class="form-label">Default Max Stock Level</label>
                                        <input type="number" class="form-control" id="max_stock_level"
                                            name="max_stock_level" min="0" value="0">
                                        <div class="form-text small">0 = unlimited</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 2: Variants -->
                    <div class="step-content" id="step-2" style="display: none;">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Add variants for different sizes, colors, or other attributes. Leave empty for single
                            product.
                        </div>

                        <div class="mb-3">
                            <button type="button" class="btn btn-primary" id="addVariantBtn">
                                <i class="fas fa-plus me-1"></i>Add Variant
                            </button>
                        </div>

                        <div id="variantsContainer">
                            <!-- Variants will be added here dynamically -->
                        </div>

                        <!-- Default variant for simple products -->
                        <div id="defaultVariant" class="border p-3 rounded mb-3">
                            <h6 class="mb-3">Default Pricing (for simple products)</h6>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Purchase Price</label>
                                    <div class="input-group">
                                        <span class="input-group-text currency-symbol">RWF</span>
                                        <input type="number" class="form-control" name="purchase_price" min="0"
                                            step="0.01" value="0">
                                    </div>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Selling Price</label>
                                    <div class="input-group">
                                        <span class="input-group-text currency-symbol">RWF</span>
                                        <input type="number" class="form-control" name="selling_price" min="0"
                                            step="0.01" value="0">
                                    </div>
                                </div>

                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Tax Rate (%)</label>
                                    <div class="input-group">
                                        <input type="number" class="form-control" name="tax_rate" min="0" max="100"
                                            step="0.01" value="18.00">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                            </div>
                            <div class="alert alert-secondary small mt-2 mb-0">
                                <i class="fas fa-info-circle me-1"></i>
                                For simple products (no variants), this pricing will be used.
                            </div>
                        </div>
                    </div>

                    <!-- Step 3: Stock Allocation -->
                    <div class="step-content" id="step-3" style="display: none;">
                        <div class="alert alert-warning">
                            <i class="fas fa-map-marker-alt me-2"></i>
                            Allocate stock to different warehouses and locations. You can add multiple allocations per
                            variant.
                        </div>

                        <div id="stockAllocationsContainer">
                            <!-- Stock allocations will be populated dynamically based on variants -->
                        </div>
                    </div>

                    <!-- Step 4: Review & Submit -->
                    <div class="step-content" id="step-4" style="display: none;">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card mb-3">
                                    <div class="card-header bg-light">
                                        <h6 class="mb-0"><i class="fas fa-box me-2"></i>Product Summary</h6>
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-sm">
                                            <tr>
                                                <th>Product Code:</th>
                                                <td id="review-product-code">-</td>
                                            </tr>
                                            <tr>
                                                <th>Product Name:</th>
                                                <td id="review-product-name">-</td>
                                            </tr>
                                            <tr>
                                                <th>Category:</th>
                                                <td id="review-category">-</td>
                                            </tr>
                                            <tr>
                                                <th>Brand:</th>
                                                <td id="review-brand">-</td>
                                            </tr>
                                            <tr>
                                                <th>Unit of Measure:</th>
                                                <td id="review-uom">-</td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card mb-3">
                                    <div class="card-header bg-light">
                                        <h6 class="mb-0"><i class="fas fa-layer-group me-2"></i>Variants Summary</h6>
                                    </div>
                                    <div class="card-body">
                                        <div id="reviewVariantsList">
                                            <p class="text-muted">No variants added</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-warehouse me-2"></i>Stock Allocation Summary</h6>
                            </div>
                            <div class="card-body">
                                <div id="reviewStockList">
                                    <p class="text-muted">No stock allocations</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Navigation Buttons -->
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="d-flex justify-content-between">
                                <button type="button" class="btn btn-secondary" id="prevBtn" disabled>
                                    <i class="fas fa-arrow-left me-2"></i>Previous
                                </button>
                                <div>
                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                        <i class="fas fa-times me-2"></i>Cancel
                                    </button>
                                    <button type="button" class="btn btn-primary" id="nextBtn">
                                        Next <i class="fas fa-arrow-right ms-2"></i>
                                    </button>
                                    <button type="submit" class="btn btn-success" id="submitBtn" style="display: none;">
                                        <i class="fas fa-save me-2"></i>Add Product
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- View Product Modal -->
<div class="modal fade" id="viewProductModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="fas fa-eye me-2"></i>Product Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewProductContent">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Templates -->
<?php include __DIR__ . '/templates/product_templates.php'; ?>

<!-- Pass PHP data to JavaScript -->
<script>
    const locations = <?php echo json_encode($locations); ?>;
    const warehouses = <?php echo json_encode($warehouses); ?>;
    const categories = <?php echo json_encode($flatCategories); ?>;
    const companyId = <?php echo json_encode($companyId); ?>;
    const apiBaseUrl = '<?php echo BASE_URL; ?>';
    const csrfToken = '<?php echo CSRF::generateMultiUse(); ?>';
    const companyCurrency = '<?php echo $_SESSION['company_currency'] ?? 'RWF'; ?>';
</script>

<style>
    .steps-container {
        position: relative;
        margin-bottom: 2rem;
    }

    .step {
        flex: 1;
        text-align: center;
        position: relative;
        z-index: 1;
    }

    .step:not(:last-child):before {
        content: '';
        position: absolute;
        top: 25px;
        left: 50%;
        width: 100%;
        height: 2px;
        background: #e0e0e0;
        z-index: -1;
    }

    .step.active:not(:last-child):before {
        background: linear-gradient(90deg, #4e73df 50%, #e0e0e0 50%);
    }

    .step.completed:not(:last-child):before {
        background: #4e73df;
    }

    .step-icon {
        width: 50px;
        height: 50px;
        background: #f8f9fc;
        border: 2px solid #e0e0e0;
        border-radius: 50%;
        margin: 0 auto 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s ease;
    }

    .step.active .step-icon {
        background: #4e73df;
        border-color: #4e73df;
        color: white;
    }

    .step.completed .step-icon {
        background: #1cc88a;
        border-color: #1cc88a;
        color: white;
    }

    .step-label {
        font-size: 0.85rem;
        font-weight: 500;
        color: #858796;
    }

    .step.active .step-label {
        color: #4e73df;
        font-weight: 600;
    }

    .step.completed .step-label {
        color: #1cc88a;
    }

    .toast {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
        min-width: 300px;
        animation: slideInRight 0.3s ease-out;
    }

    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }

        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    .table-hover tbody tr:hover {
        background-color: rgba(78, 115, 223, 0.05);
    }

    .is-invalid {
        border-color: #e74a3b;
    }

    .is-invalid:focus {
        border-color: #e74a3b;
        box-shadow: 0 0 0 0.2rem rgba(231, 74, 59, 0.25);
    }

    .modal-xl {
        max-width: 1200px !important;
    }

    .modal-content {
        max-height: 105vh;
        border-radius: 12px;
        overflow: hidden;
    }

    .modal-body {
        max-height: calc(90vh - 120px);
        overflow-y: auto;
        padding: 1.5rem;
    }

    .modal-header {
        position: sticky;
        top: 0;
        z-index: 10;
        background: linear-gradient(135deg, #4e73df 0%, #224abe 100%);
    }

    .modal-body::-webkit-scrollbar {
        width: 8px;
    }

    .modal-body::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }

    .modal-body::-webkit-scrollbar-thumb {
        background: #888;
        border-radius: 10px;
    }

    .modal-body::-webkit-scrollbar-thumb:hover {
        background: #555;
    }

    .step-content {
        animation: fadeIn 0.3s ease-in;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .form-control,
    .form-select {
        border-radius: 8px;
    }

    .card {
        border-radius: 10px;
        border: none;
        box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1);
    }

    .card-header {
        background-color: #f8f9fc;
        border-bottom: 1px solid #e3e6f0;
    }

    .btn {
        transition: all 0.2s ease;
    }

    .btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    }

    @media (max-width: 768px) {
        .modal-xl {
            max-width: 95% !important;
            margin: 0.5rem;
        }

        .modal-body {
            padding: 1rem;
        }

        .step-label {
            font-size: 0.7rem;
        }

        .step-icon {
            width: 40px;
            height: 40px;
            font-size: 1rem;
        }
    }
</style>

<?php $jsFiles = ['product/add_product.js']; ?>