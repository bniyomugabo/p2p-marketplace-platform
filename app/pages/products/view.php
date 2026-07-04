<?php
// pages/products/view.php
declare(strict_types=1);

$pageTitle = 'View Product - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';

// Load models
require_once __DIR__ . '/../../models/Product.php';
require_once __DIR__ . '/../../models/Variant.php';
require_once __DIR__ . '/../../models/Category.php';

$db = Database::getInstance();
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? 0;
$userRole = $_SESSION['user_role'] ?? 'VIW';

// Get product ID
$productId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$productId) {
    SessionManager::flash('error', 'Invalid product ID');
    header('Location: ?page=products/list');
    exit;
}

// Initialize models with company context
$productModel = new Product($companyId);
$variantModel = new Variant($companyId);
$categoryModel = new Category($companyId);

// Get product details (includes company validation)
$product = $productModel->getWithDetails($productId);

if (!$product) {
    SessionManager::flash('error', 'Product not found or not accessible for your company');
    header('Location: ?page=products/list');
    exit;
}

// Get variants (only those accessible to this company)
$variants = $variantModel->getByProductId($productId);

// Get stock summary
$stockSummary = [
    'total_stock' => 0,
    'total_committed' => 0,
    'total_available' => 0,
    'total_value' => 0,
    'warehouses' => []
];

$warehouseStock = [];

foreach ($variants as &$variant) {
    $stock = $variantModel->getStockByWarehouse($variant['id']);

    // Calculate variant totals
    $variant['total_stock'] = array_sum(array_column($stock, 'quantity'));
    $variant['total_committed'] = array_sum(array_column($stock, 'committed_quantity'));
    $variant['total_available'] = $variant['total_stock'] - $variant['total_committed'];

    foreach ($stock as $s) {
        $warehouseId = $s['warehouse_id'];
        if (!isset($warehouseStock[$warehouseId])) {
            $warehouseStock[$warehouseId] = [
                'warehouse_name' => $s['warehouse_name'],
                'warehouse_code' => $s['warehouse_code'],
                'quantity' => 0,
                'committed' => 0,
                'value' => 0,
                'locations' => []
            ];
        }

        $warehouseStock[$warehouseId]['quantity'] += (float) $s['quantity'];
        $warehouseStock[$warehouseId]['committed'] += (float) $s['committed_quantity'];

        // Calculate value using average landed cost if available, otherwise purchase price
        $costPerUnit = $s['avg_landed_cost'] ?: ($variant['purchase_price'] ?: 0);
        $warehouseStock[$warehouseId]['value'] += (float) $s['quantity'] * (float) $costPerUnit;

        if ($s['location_id']) {
            $warehouseStock[$warehouseId]['locations'][] = [
                'location' => $s['location_code'],
                'location_name' => $s['location_name'],
                'quantity' => (float) $s['quantity']
            ];
        }
    }

    $stockSummary['total_stock'] += $variant['total_stock'];
    $stockSummary['total_committed'] += $variant['total_committed'];
    $stockSummary['total_value'] += $variant['total_stock'] * ($variant['purchase_price'] ?: 0);
}

$stockSummary['total_available'] = $stockSummary['total_stock'] - $stockSummary['total_committed'];
$stockSummary['warehouses'] = $warehouseStock;
?>

<div class="product-view">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <a href="?page=products" class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Products
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-box me-2"></i>
                        <?php echo htmlspecialchars($product['product_name']); ?>
                    </h2>
                    <p class="mb-0 text-muted">
                        Product Code: <strong><?php echo htmlspecialchars($product['product_code']); ?></strong>
                        <?php if ($product['category_parent_id']): ?>
                            | Category Path:
                            <?php
                            $path = $categoryModel->getPath($product['category_id']);
                            $pathNames = array_column($path, 'category_name');
                            echo htmlspecialchars(implode(' > ', $pathNames));
                            ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div>
                    <?php if (in_array($userRole, ['ADM', 'MGR'])): ?>
                        <a href="?page=products/edit&id=<?php echo $productId; ?>" class="btn btn-warning me-2">
                            <i class="fas fa-edit me-2"></i>Edit Product
                        </a>
                    <?php endif; ?>
                    <a href="?page=inventory/stock&product=<?php echo $productId; ?>" class="btn btn-info">
                        <i class="fas fa-warehouse me-2"></i>View Stock
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Product Details -->
    <div class="row">
        <div class="col-md-8">
            <!-- Basic Information -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-info-circle me-2"></i>Basic Information
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <th style="width: 40%">Product Code:</th>
                                    <td>
                                        <strong><?php echo htmlspecialchars($product['product_code']); ?></strong>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Product Name:</th>
                                    <td>
                                        <?php echo htmlspecialchars($product['product_name']); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Category:</th>
                                    <td>
                                        <?php if ($product['category_name']): ?>
                                            <span class="badge bg-primary">
                                                <?php echo htmlspecialchars($product['category_name']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Uncategorized</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Brand:</th>
                                    <td>
                                        <?php echo htmlspecialchars($product['brand'] ?? '-'); ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <th style="width: 40%">Unit of Measure:</th>
                                    <td>
                                        <?php echo htmlspecialchars($product['unit_of_measure'] ?? 'PCS'); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Has Variants:</th>
                                    <td>
                                        <?php if ($product['has_variants']): ?>
                                            <span class="badge bg-info">Yes (<?php echo count($variants); ?>
                                                variants)</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">No</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Status:</th>
                                    <td>
                                        <?php if ($product['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Created:</th>
                                    <td>
                                        <?php echo format_date($product['created_at'], 'd/m/Y H:i'); ?>
                                        <?php if ($product['created_by']): ?>
                                            <small class="text-muted d-block">
                                                by <?php echo htmlspecialchars($product['created_by_name'] ?? 'User'); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <?php if (!empty($product['description'])): ?>
                        <div class="mt-3">
                            <h6 class="fw-bold">Description:</h6>
                            <div class="p-3 bg-light rounded">
                                <?php echo nl2br(htmlspecialchars($product['description'])); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Variants -->
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-layer-group me-2"></i>Product Variants
                    </h6>
                    <?php if (in_array($userRole, ['ADM', 'MGR']) && $product['has_variants']): ?>
                        <button type="button" class="btn btn-sm btn-primary" onclick="addVariant()">
                            <i class="fas fa-plus me-1"></i>Add Variant
                        </button>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!$product['has_variants']): ?>
                        <div class="alert alert-info mb-0">
                            <i class="fas fa-info-circle me-2"></i>
                            This is a simple product (no variants).
                            <a href="?page=products/edit&id=<?php echo $productId; ?>">Edit product</a> to enable variants.
                        </div>
                    <?php elseif (empty($variants)): ?>
                        <div class="text-center py-4">
                            <div class="text-muted">
                                <i class="fas fa-layer-group fa-3x mb-3"></i>
                                <p class="mb-0">No variants found for this product</p>
                                <?php if (in_array($userRole, ['ADM', 'MGR'])): ?>
                                    <button class="btn btn-sm btn-primary mt-3" onclick="addVariant()">
                                        <i class="fas fa-plus me-1"></i>Add First Variant
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover" id="variantsTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>SKU / Barcode</th>
                                        <th>Variant Name</th>
                                        <th>Attributes</th>
                                        <th>Purchase Price</th>
                                        <th>Selling Price</th>
                                        <th>Stock</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($variants as $variant): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($variant['sku']); ?></strong>
                                                <?php if (!empty($variant['barcode'])): ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        <i class="fas fa-barcode"></i>
                                                        <?php echo htmlspecialchars($variant['barcode']); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($variant['variant_name'] ?: 'Standard'); ?>
                                            </td>
                                            <td>
                                                <?php
                                                if (!empty($variant['attributes_list'])) {
                                                    $attrs = explode(', ', $variant['attributes_list']);
                                                    foreach ($attrs as $attr) {
                                                        echo '<span class="badge bg-light text-dark me-1 mb-1">' . htmlspecialchars($attr) . '</span>';
                                                    }
                                                } else {
                                                    echo '<span class="text-muted">-</span>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php echo format_currency($variant['purchase_price'] ?? 0, $companyId); ?>
                                            </td>
                                            <td>
                                                <strong class="text-success">
                                                    <?php echo format_currency($variant['selling_price'] ?? 0, $companyId); ?>
                                                </strong>
                                                <?php if ($variant['wholesale_price'] > 0): ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        Wholesale:
                                                        <?php echo format_currency($variant['wholesale_price'], $companyId); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                $stockClass = 'success';
                                                $stockStatus = 'In Stock';

                                                if ($variant['total_available'] <= 0) {
                                                    $stockClass = 'danger';
                                                    $stockStatus = 'Out of Stock';
                                                } elseif ($variant['total_available'] <= $variant['reorder_level']) {
                                                    $stockClass = 'warning';
                                                    $stockStatus = 'Low Stock';
                                                }
                                                ?>
                                                <span class="badge bg-<?php echo $stockClass; ?> d-block mb-1">
                                                    <?php echo number_format($variant['total_available'], 0); ?> units
                                                </span>
                                                <?php if ($variant['total_committed'] > 0): ?>
                                                    <small class="text-muted">
                                                        (<?php echo number_format($variant['total_committed'], 0); ?> committed)
                                                    </small>
                                                <?php endif; ?>
                                                <?php if ($variant['reorder_level'] > 0): ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        Reorder at: <?php echo number_format($variant['reorder_level'], 0); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($variant['is_active']): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="?page=products/view-variant&id=<?php echo $variant['id']; ?>"
                                                        class="btn btn-outline-primary" title="View Variant">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if (in_array($userRole, ['ADM', 'MGR'])): ?>
                                                        <a href="?page=products/edit-variant&id=<?php echo $variant['id']; ?>"
                                                            class="btn btn-outline-warning" title="Edit Variant">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <a href="?page=products/stock-adjust-variant&id=<?php echo $variant['id']; ?>"
                                                            class="btn btn-outline-info" title="Adjust Stock">
                                                            <i class="fas fa-warehouse"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <!-- Stock Summary -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-chart-pie me-2"></i>Stock Summary
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row text-center mb-3">
                        <div class="col-6">
                            <h4 class="mb-0 text-info">
                                <?php echo number_format($stockSummary['total_stock'], 0); ?>
                            </h4>
                            <small class="text-muted">Total Units</small>
                        </div>
                        <div class="col-6">
                            <h4 class="mb-0 text-warning">
                                <?php echo number_format($stockSummary['total_committed'], 0); ?>
                            </h4>
                            <small class="text-muted">Committed</small>
                        </div>
                    </div>

                    <div class="text-center mb-3">
                        <div class="display-6 mb-0 text-success">
                            <?php echo number_format($stockSummary['total_available'], 0); ?>
                        </div>
                        <small class="text-muted">Available for Sale</small>
                    </div>

                    <div class="text-center mb-3">
                        <h5 class="mb-0">
                            <?php echo format_currency($stockSummary['total_value'], $companyId); ?>
                        </h5>
                        <small class="text-muted">Total Stock Value (at cost)</small>
                    </div>

                    <?php if (!empty($stockSummary['warehouses'])): ?>
                        <hr>
                        <h6 class="fw-bold mb-3">Stock by Warehouse</h6>
                        <?php foreach ($stockSummary['warehouses'] as $warehouse): ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between">
                                    <span>
                                        <i class="fas fa-building me-1"></i>
                                        <?php echo htmlspecialchars($warehouse['warehouse_name']); ?>
                                    </span>
                                    <span class="fw-bold">
                                        <?php echo number_format($warehouse['quantity'], 0); ?> units
                                    </span>
                                </div>
                                <?php if ($warehouse['committed'] > 0): ?>
                                    <div class="d-flex justify-content-between small text-muted">
                                        <span>Committed:</span>
                                        <span><?php echo number_format($warehouse['committed'], 0); ?> units</span>
                                    </div>
                                <?php endif; ?>
                                <div class="progress mt-1" style="height: 5px;">
                                    <?php
                                    $percentage = $stockSummary['total_stock'] > 0
                                        ? ($warehouse['quantity'] / $stockSummary['total_stock']) * 100
                                        : 0;
                                    ?>
                                    <div class="progress-bar bg-info" style="width: <?php echo $percentage; ?>%"></div>
                                </div>
                                <?php if (!empty($warehouse['locations'])): ?>
                                    <div class="mt-2 small">
                                        <span class="text-muted">Locations:</span>
                                        <?php foreach ($warehouse['locations'] as $loc): ?>
                                            <div class="ms-2">
                                                <i class="fas fa-map-marker-alt me-1"></i>
                                                <?php echo htmlspecialchars($loc['location']); ?>:
                                                <?php echo number_format($loc['quantity'], 0); ?> units
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="alert alert-warning mb-0">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            No stock records found for this product.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-bolt me-2"></i>Quick Actions
                    </h6>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="?page=inventory/adjustments&product=<?php echo $productId; ?>"
                            class="btn btn-outline-warning">
                            <i class="fas fa-adjust me-2"></i>Adjust Stock
                        </a>
                        <a href="?page=inventory/transfers&product=<?php echo $productId; ?>"
                            class="btn btn-outline-info">
                            <i class="fas fa-exchange-alt me-2"></i>Transfer Stock
                        </a>
                        <a href="?page=reports/product&id=<?php echo $productId; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-chart-line me-2"></i>View Reports
                        </a>
                        <?php if (!empty($variants)): ?>
                            <a href="?page=products/print-barcode&id=<?php echo $productId; ?>"
                                class="btn btn-outline-dark">
                                <i class="fas fa-barcode me-2"></i>Print Barcodes
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function addVariant() {
        window.location.href = '?page=products/add-variant&product_id=<?php echo $productId; ?>';
    }

    // Initialize DataTable for variants if there are many
    $(document).ready(function () {
        if ($('#variantsTable tbody tr').length > 10) {
            $('#variantsTable').DataTable({
                pageLength: 10,
                ordering: true,
                searching: true,
                info: true,
                lengthChange: true,
                order: [[1, 'asc']],
                columnDefs: [
                    { orderable: false, targets: [2, 7] }
                ]
            });
        }
    });
</script>

<style>
    .product-view .table td {
        vertical-align: middle;
    }

    .product-view .btn-group {
        gap: 4px;
    }

    .product-view .progress {
        border-radius: 10px;
        background-color: #e9ecef;
    }

    .product-view .badge.bg-light {
        background-color: #f8f9fa !important;
        border: 1px solid #dee2e6;
    }

    .product-view .display-6 {
        font-size: 2rem;
        font-weight: 600;
    }
</style>