<?php
// pages/products/view_variant.php
declare(strict_types=1);

$pageTitle = 'View Variant - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';

// Load models
require_once __DIR__ . '/../../models/Product.php';
require_once __DIR__ . '/../../models/Variant.php';
require_once __DIR__ . '/../../models/Inventory.php';
require_once __DIR__ . '/../../models/Category.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? 0;

// Get variant ID from URL
$variantId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$variantId) {
    SessionManager::flash('error', 'Variant ID is required.');
    header('Location: ' . route_url('products/list'));
    exit;
}

// Initialize models with company context
$variantModel = new Variant($companyId);
$productModel = new Product($companyId);
$inventoryModel = new Inventory($companyId);
$categoryModel = new Category($companyId);

// Get variant details (includes company validation)
$variant = $variantModel->getWithDetails($variantId);

if (!$variant) {
    SessionManager::flash('error', 'Variant not found or not accessible for your company.');
    header('Location: ' . route_url('products/list'));
    exit;
}

// Get product details (includes company validation)
$product = $productModel->getWithDetails($variant['product_id']);

if (!$product) {
    SessionManager::flash('error', 'Product not found or not accessible.');
    header('Location: ' . route_url('products/list'));
    exit;
}

// Get category path for breadcrumb
$categoryPath = $categoryModel->getPath($product['category_id']);
?>

<div class="container-fluid">
    <!-- Back button and title -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <a href="<?php echo route_url('products/view', ['id' => $variant['product_id']]); ?>" 
                       class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Product
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-cube me-2"></i>Variant Details
                    </h2>
                    <p class="mb-0 text-muted">
                        Product: <strong><?php echo htmlspecialchars($product['product_name'] ?? ''); ?></strong>
                        <?php if (!empty($categoryPath)): ?>
                                <br><small class="text-muted">
                                    Category: 
                                    <?php
                                    $pathNames = array_column($categoryPath, 'category_name');
                                    echo htmlspecialchars(implode(' > ', $pathNames));
                                    ?>
                                </small>
                        <?php endif; ?>
                    </p>
                </div>
                <div>
                    <?php if (in_array($userRole, ['ADM', 'MGR'])): ?>
                            <a href="?page=products/edit-variant&id=<?php echo $variantId; ?>" 
                               class="btn btn-warning">
                                <i class="fas fa-edit me-2"></i>Edit Variant
                            </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if ($flash = SessionManager::flash('success')): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($flash); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
    <?php elseif ($flash = SessionManager::flash('error')): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($flash); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
    <?php endif; ?>

    <div class="row">
        <!-- Main Info Card -->
        <div class="col-md-8">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-info-circle me-2"></i>Variant Information
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <th style="width: 40%">Variant Name:</th>
                                    <td><strong><?php echo htmlspecialchars($variant['variant_name'] ?: 'Standard'); ?></strong></td>
                                </tr>
                                <tr>
                                    <th>SKU:</th>
                                    <td><code><?php echo htmlspecialchars($variant['sku']); ?></code>
                                        <button class="btn btn-sm btn-outline-secondary ms-2" 
                                                onclick="copyToClipboard('<?php echo htmlspecialchars($variant['sku']); ?>')"
                                                title="Copy SKU">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Barcode:</th>
                                    <td>
                                        <?php if (!empty($variant['barcode'])): ?>
                                                <code><?php echo htmlspecialchars($variant['barcode']); ?></code>
                                                <button class="btn btn-sm btn-outline-secondary ms-2" 
                                                        onclick="copyToClipboard('<?php echo htmlspecialchars($variant['barcode']); ?>')"
                                                        title="Copy Barcode">
                                                    <i class="fas fa-copy"></i>
                                                </button>
                                        <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Status:</th>
                                    <td>
                                        <?php if ($variant['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Reorder Level:</th>
                                    <td>
                                        <?php echo number_format((float) ($variant['reorder_level'] ?? 0), 0); ?> units
                                        <?php if (($variant['total_available'] ?? 0) <= ($variant['reorder_level'] ?? 0) && ($variant['reorder_level'] ?? 0) > 0): ?>
                                                <span class="badge bg-warning ms-2">Low Stock Warning</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Max Stock Level:</th>
                                    <td><?php echo number_format((float) ($variant['max_stock_level'] ?? 0), 0); ?> units</td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <th style="width: 40%">Purchase Price:</th>
                                    <td>
                                        <?php echo format_currency($variant['purchase_price'] ?? 0, $companyId); ?>
                                        <?php if ($variant['avg_landed_cost'] ?? 0): ?>
                                                <br><small class="text-muted">
                                                    Landed: <?php echo format_currency($variant['avg_landed_cost'], $companyId); ?>
                                                </small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Selling Price:</th>
                                    <td class="fw-bold text-success">
                                        <?php echo format_currency($variant['selling_price'] ?? 0, $companyId); ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Wholesale Price:</th>
                                    <td>
                                        <?php if ($variant['wholesale_price'] > 0): ?>
                                                <?php echo format_currency($variant['wholesale_price'] ?? 0, $companyId); ?>
                                        <?php else: ?>
                                                <span class="text-muted">Not set</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Tax Rate:</th>
                                    <td><?php echo number_format((float) ($variant['tax_rate'] ?? 0), 2); ?>%</td>
                                </tr>
                                <tr>
                                    <th>Profit Margin:</th>
                                    <td>
                                        <?php
                                        $cost = $variant['purchase_price'] ?? 0;
                                        $price = $variant['selling_price'] ?? 0;
                                        if ($cost > 0 && $price > 0):
                                            $margin = (($price - $cost) / $price) * 100;
                                            $marginClass = $margin >= 30 ? 'text-success' : ($margin >= 15 ? 'text-warning' : 'text-danger');
                                            ?>
                                                <span class="<?php echo $marginClass; ?> fw-bold">
                                                    <?php echo number_format($margin, 1); ?>%
                                                </span>
                                                <br><small class="text-muted">
                                                    Absolute: <?php echo format_currency($price - $cost, $companyId); ?>
                                                </small>
                                        <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                             </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stock Information -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-warehouse me-2"></i>Stock by Warehouse
                    </h6>
                </div>
                <div class="card-body">
                    <?php
                    $stockData = $variantModel->getStockByWarehouse($variantId);
                    $totalStock = array_sum(array_column($stockData, 'quantity'));
                    $totalCommitted = array_sum(array_column($stockData, 'committed_quantity'));
                    $totalAvailable = $totalStock - $totalCommitted;
                    ?>
                    
                    <!-- Stock Summary Cards -->
                    <div class="row mb-4">
                        <div class="col-md-4">
                            <div class="card bg-light">
                                <div class="card-body text-center py-2">
                                    <h6 class="text-muted mb-0">Total Stock</h6>
                                    <h4 class="mb-0 text-info"><?php echo number_format($totalStock, 0); ?></h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card bg-light">
                                <div class="card-body text-center py-2">
                                    <h6 class="text-muted mb-0">Committed</h6>
                                    <h4 class="mb-0 text-warning"><?php echo number_format($totalCommitted, 0); ?></h4>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card bg-light">
                                <div class="card-body text-center py-2">
                                    <h6 class="text-muted mb-0">Available</h6>
                                    <h4 class="mb-0 <?php echo $totalAvailable > 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo number_format($totalAvailable, 0); ?>
                                    </h4>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (empty($stockData)): ?>
                            <div class="text-center text-muted py-3">
                                <i class="fas fa-box-open fa-3x mb-3"></i>
                                <p>No stock information available for this variant</p>
                            </div>
                    <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Warehouse</th>
                                            <th>Location</th>
                                            <th class="text-end">Quantity</th>
                                            <th class="text-end">Committed</th>
                                            <th class="text-end">Available</th>
                                            <th class="text-end">Value</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($stockData as $stock):
                                            $stockValue = ($stock['avg_landed_cost'] ?: ($variant['purchase_price'] ?? 0)) * $stock['quantity'];
                                            ?>
                                                <tr>
                                                    <td>
                                                        <i class="fas fa-building me-1"></i>
                                                        <?php echo htmlspecialchars($stock['warehouse_name']); ?>
                                                        <?php if ($stock['warehouse_code']): ?>
                                                                <br><small class="text-muted"><?php echo htmlspecialchars($stock['warehouse_code']); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($stock['location_code']): ?>
                                                                <i class="fas fa-map-marker-alt me-1"></i>
                                                                <?php echo htmlspecialchars($stock['location_code']); ?>
                                                                <?php if ($stock['location_name']): ?>
                                                                        <br><small><?php echo htmlspecialchars($stock['location_name']); ?></small>
                                                                <?php endif; ?>
                                                        <?php else: ?>
                                                                <span class="text-muted">Default Location</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end"><?php echo number_format((float) $stock['quantity'], 0); ?></td>
                                                    <td class="text-end"><?php echo number_format((float) ($stock['committed_quantity'] ?? 0), 0); ?></td>
                                                    <td class="text-end fw-bold <?php echo ($stock['available_quantity'] ?? 0) <= 0 ? 'text-danger' : 'text-success'; ?>">
                                                        <?php echo number_format((float) ($stock['available_quantity'] ?? 0), 0); ?>
                                                    </td>
                                                    <td class="text-end text-muted small">
                                                        <?php echo format_currency($stockValue, $companyId); ?>
                                                    </td>
                                                </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr class="fw-bold">
                                            <td colspan="2">Total</td>
                                            <td class="text-end"><?php echo number_format($totalStock, 0); ?></td>
                                            <td class="text-end"><?php echo number_format($totalCommitted, 0); ?></td>
                                            <td class="text-end"><?php echo number_format($totalAvailable, 0); ?></td>
                                            <td class="text-end">RWF</td>
                                        </tr>
                                    </tfoot>
                                 </table>
                            </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Stock Movements -->
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-history me-2"></i>Recent Stock Movements
                    </h6>
                    <a href="?page=products/stock-movements-variant&id=<?php echo $variantId; ?>" class="btn btn-sm btn-outline-primary">
                        View All <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
                <div class="card-body">
                    <?php
                    // Get recent movements for this variant using Inventory model with company context
                    $movements = $inventoryModel->getMovements($variantId, null, 10, 0);
                    ?>
                    
                    <?php if (empty($movements)): ?>
                            <div class="text-center text-muted py-3">
                                <i class="fas fa-exchange-alt fa-3x mb-3"></i>
                                <p>No stock movements recorded</p>
                            </div>
                    <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date & Time</th>
                                            <th>Type</th>
                                            <th>Warehouse</th>
                                            <th class="text-end">Quantity</th>
                                            <th>Reference</th>
                                            <th>Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($movements as $movement): ?>
                                                <tr>
                                                    <td>
                                                        <span title="<?php echo htmlspecialchars($movement['created_at']); ?>">
                                                            <?php echo date('d/m/Y H:i', strtotime($movement['created_at'])); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $badgeClass = [
                                                            'purchase' => 'success',
                                                            'sale' => 'primary',
                                                            'return' => 'warning',
                                                            'adjustment' => 'info',
                                                            'transfer' => 'secondary'
                                                        ][$movement['transaction_type']] ?? 'secondary';
                                                        ?>
                                                        <span class="badge bg-<?php echo $badgeClass; ?>">
                                                            <?php echo ucfirst($movement['transaction_type']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($movement['warehouse_name'] ?? '-'); ?></td>
                                                    <td class="text-end <?php echo $movement['quantity'] > 0 ? 'text-success' : 'text-danger'; ?>">
                                                        <?php echo $movement['quantity'] > 0 ? '+' : ''; ?>
                                                        <?php echo number_format((float) $movement['quantity'], 0); ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($movement['reference_type'] && $movement['reference_id']): ?>
                                                                <small>
                                                                    <?php echo htmlspecialchars($movement['reference_type']); ?>
                                                                    #<?php echo $movement['reference_id']; ?>
                                                                </small>
                                                        <?php else: ?>
                                                                <small class="text-muted">-</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($movement['notes'])): ?>
                                                                <small class="text-muted" title="<?php echo htmlspecialchars($movement['notes']); ?>">
                                                                    <?php echo htmlspecialchars(substr($movement['notes'], 0, 30)); ?>
                                                                    <?php echo strlen($movement['notes']) > 30 ? '...' : ''; ?>
                                                                </small>
                                                        <?php else: ?>
                                                                <small class="text-muted">-</small>
                                                        <?php endif; ?>
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

        <!-- Sidebar -->
        <div class="col-md-4">
            <!-- Images -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-images me-2"></i>Images
                    </h6>
                </div>
                <div class="card-body text-center">
                    <?php if (!empty($variant['images'])): ?>
                            <div id="variantImagesCarousel" class="carousel slide" data-bs-ride="carousel">
                                <div class="carousel-inner">
                                    <?php foreach ($variant['images'] as $index => $image): ?>
                                            <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                                                <img src="<?php echo asset_url($image['image_url']); ?>" 
                                                     class="d-block w-100 img-fluid rounded" 
                                                     alt="<?php echo htmlspecialchars($image['caption'] ?? 'Variant image'); ?>"
                                                     style="max-height: 250px; object-fit: contain;">
                                                <?php if ($image['is_primary']): ?>
                                                        <div class="carousel-caption d-none d-md-block">
                                                            <span class="badge bg-primary">Primary Image</span>
                                                        </div>
                                                <?php endif; ?>
                                                <?php if ($image['caption']): ?>
                                                        <div class="mt-2 small text-muted">
                                                            <?php echo htmlspecialchars($image['caption']); ?>
                                                        </div>
                                                <?php endif; ?>
                                            </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (count($variant['images']) > 1): ?>
                                        <button class="carousel-control-prev" type="button" data-bs-target="#variantImagesCarousel" data-bs-slide="prev">
                                            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                            <span class="visually-hidden">Previous</span>
                                        </button>
                                        <button class="carousel-control-next" type="button" data-bs-target="#variantImagesCarousel" data-bs-slide="next">
                                            <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                            <span class="visually-hidden">Next</span>
                                        </button>
                                <?php endif; ?>
                            </div>
                            <div class="mt-2">
                                <small class="text-muted"><?php echo count($variant['images']); ?> image(s) total</small>
                            </div>
                    <?php else: ?>
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-image fa-4x mb-3"></i>
                                <p>No images available</p>
                                <?php if (in_array($userRole, ['ADM', 'MGR'])): ?>
                                        <a href="?page=products/edit-variant&id=<?php echo $variantId; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-upload me-1"></i>Add Images
                                        </a>
                                <?php endif; ?>
                            </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Attributes -->
            <?php if (!empty($variant['attributes'])): ?>
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-tags me-2"></i>Attributes
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <tbody>
                                    <?php foreach ($variant['attributes'] as $attr): ?>
                                            <tr>
                                                <th style="width: 40%"><?php echo htmlspecialchars($attr['attribute_name']); ?>:</th>
                                                <td>
                                                    <span class="badge bg-light text-dark">
                                                        <?php echo htmlspecialchars($attr['attribute_value']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Quick Actions -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-bolt me-2"></i>Quick Actions
                    </h6>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <?php if (in_array($userRole, ['ADM', 'MGR'])): ?>
                                <a href="?page=products/edit-variant&id=<?php echo $variantId; ?>" 
                                   class="btn btn-warning">
                                    <i class="fas fa-edit me-2"></i>Edit Variant
                                </a>
                                <a href="?page=products/stock-adjust-variant&id=<?php echo $variantId; ?>" 
                                   class="btn btn-info">
                                    <i class="fas fa-adjust me-2"></i>Adjust Stock
                                </a>
                                <button type="button" class="btn btn-danger" 
                                        onclick="deleteVariant(<?php echo $variantId; ?>, '<?php echo htmlspecialchars(addslashes($variant['variant_name'] ?: 'Standard')); ?>')">
                                    <i class="fas fa-trash me-2"></i>Delete Variant
                                </button>
                        <?php endif; ?>
                        <?php if (!empty($variant['barcode'])): ?>
                                <a href="?page=products/print-barcode&id=<?php echo $variantId; ?>" 
                                   class="btn btn-secondary" target="_blank">
                                    <i class="fas fa-print me-2"></i>Print Barcode
                                </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteVariantModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="fas fa-exclamation-triangle me-2"></i>Delete Variant
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete variant <strong id="deleteVariantName"></strong>?</p>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <strong>Warning:</strong> This action will:
                    <ul class="mb-0 mt-2">
                        <li>Soft-delete this variant</li>
                        <li>Make it unavailable for new sales/purchases</li>
                        <li>Preserve existing stock and transaction history</li>
                    </ul>
                </div>
                <p class="text-muted small mb-0">
                    <i class="fas fa-info-circle me-1"></i>
                    The variant can be restored later if needed.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="deleteVariantConfirmBtn" class="btn btn-danger">
                    <i class="fas fa-trash me-1"></i>Delete Variant
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function deleteVariant(id, name) {
    document.getElementById('deleteVariantName').textContent = name;
    document.getElementById('deleteVariantConfirmBtn').href = '?page=products/delete-variant&id=' + id;
    new bootstrap.Modal(document.getElementById('deleteVariantModal')).show();
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(function() {
        // Optional: Show a temporary tooltip or notification
        alert('Copied to clipboard: ' + text);
    }, function(err) {
        console.error('Could not copy text: ', err);
    });
}
</script>

<style>
    .product-view .table td {
        vertical-align: middle;
    }
    
    .carousel-control-prev-icon,
    .carousel-control-next-icon {
        background-color: rgba(0,0,0,0.5);
        border-radius: 50%;
        padding: 20px;
    }
    
    .badge.bg-light {
        background-color: #f8f9fa !important;
        border: 1px solid #dee2e6;
    }
    
    .card.bg-light {
        background-color: #f8f9fa !important;
    }
</style>