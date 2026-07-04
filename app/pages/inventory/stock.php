<?php
// pages/inventory/stock.php
declare(strict_types=1);

$pageTitle = 'Current Stock - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';

// Load models
require_once __DIR__ . '/../../models/Inventory.php';
require_once __DIR__ . '/../../models/Warehouse.php';
require_once __DIR__ . '/../../models/Product.php';
require_once __DIR__ . '/../../models/Variant.php';

$db = Database::getInstance();
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;
$userRole = $_SESSION['user_role'] ?? 'VIW';

// Initialize models with company context
$inventoryModel = new Inventory($companyId);
$warehouseModel = new Warehouse($companyId);
$productModel = new Product($companyId);
$variantModel = new Variant($companyId);

// Get filter parameters
$warehouseId = isset($_GET['warehouse']) ? (int)$_GET['warehouse'] : null;
$locationId = isset($_GET['location']) ? (int)$_GET['location'] : null;
$search = $_GET['search'] ?? '';
$filter = $_GET['filter'] ?? 'all'; // all, low, out

// Get warehouses for filter (only this company's warehouses)
$warehouses = $warehouseModel->all(['id', 'warehouse_name'], 'is_active = 1');

// Get stock list (company-specific)
$stockList = $inventoryModel->getStockList($warehouseId, $locationId);

// Filter by search
if (!empty($search)) {
    $stockList = array_filter($stockList, function($item) use ($search) {
        return stripos($item['product_name'], $search) !== false || 
               stripos($item['sku'], $search) !== false ||
               stripos($item['variant_name'], $search) !== false ||
               stripos($item['product_code'], $search) !== false;
    });
}

// Filter by stock status
if ($filter === 'low') {
    $stockList = array_filter($stockList, function($item) {
        return $item['available_quantity'] <= $item['reorder_level'] && $item['available_quantity'] > 0;
    });
} elseif ($filter === 'out') {
    $stockList = array_filter($stockList, function($item) {
        return $item['available_quantity'] <= 0;
    });
}

// Get locations for the selected warehouse
$locations = [];
if ($warehouseId) {
    $sql = "SELECT id, location_code, location_name FROM locations WHERE warehouse_id = :warehouse_id AND is_active = 1 AND company_id = :company_id";
    $stmt = $db->prepare($sql);
    $stmt->execute(['warehouse_id' => $warehouseId, 'company_id' => $companyId]);
    $locations = $stmt->fetchAll();
}

// Generate CSRF token
$csrfToken = CSRF::generate();
?>

<div class="current-stock">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-boxes me-2"></i>Current Stock
                    </h2>
                    <p class="mb-0 text-muted">View and manage current inventory levels</p>
                </div>
                <div>
                    <a href="?page=exports/stock" class="btn btn-success me-2">
                        <i class="fas fa-file-excel me-2"></i>Export
                    </a>
                    <a href="?page=inventory/adjustments" class="btn btn-warning">
                        <i class="fas fa-adjust me-2"></i>Adjust Stock
                    </a>
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

    <!-- Filters -->
    <div class="card shadow mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <input type="hidden" name="page" value="inventory/stock">
                
                <div class="col-md-3">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" class="form-control" id="search" name="search" 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Product name, code or SKU...">
                </div>
                
                <div class="col-md-2">
                    <label for="warehouse" class="form-label">Warehouse</label>
                    <select class="form-control" id="warehouse" name="warehouse" onchange="this.form.submit()">
                        <option value="">All Warehouses</option>
                        <?php foreach ($warehouses as $wh): ?>
                            <option value="<?php echo $wh['id']; ?>" <?php echo $warehouseId == $wh['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($wh['warehouse_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label for="location" class="form-label">Location</label>
                    <select class="form-control" id="location" name="location" onchange="this.form.submit()">
                        <option value="">All Locations</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?php echo $loc['id']; ?>" <?php echo $locationId == $loc['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($loc['location_code']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label for="filter" class="form-label">Filter</label>
                    <select class="form-control" id="filter" name="filter" onchange="this.form.submit()">
                        <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Stock</option>
                        <option value="low" <?php echo $filter === 'low' ? 'selected' : ''; ?>>Low Stock Only</option>
                        <option value="out" <?php echo $filter === 'out' ? 'selected' : ''; ?>>Out of Stock</option>
                    </select>
                </div>
                
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100 me-2">
                        <i class="fas fa-filter me-2"></i>Apply Filters
                    </button>
                    <a href="?page=inventory/stock" class="btn btn-secondary w-100">
                        <i class="fas fa-undo me-2"></i>Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Stock Table -->
    <div class="card shadow">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-list me-2"></i>Stock List
                <span class="badge bg-primary ms-2"><?php echo count($stockList); ?> items</span>
            </h6>
            <div>
                <button class="btn btn-sm btn-outline-primary" onclick="refreshTable()">
                    <i class="fas fa-sync-alt me-1"></i>Refresh
                </button>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="stockTable">
                    <thead class="table-light">
                        <tr>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Warehouse</th>
                            <th class="text-end">Available</th>
                            <th class="text-end">Unit Cost</th>
                            <th class="text-end">Total Value</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stockList)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <div class="text-muted">
                                        <i class="fas fa-box-open fa-3x mb-3"></i>
                                        <p class="mb-0">No stock found</p>
                                        <small>Try adjusting your filters or add some stock</small>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($stockList as $item): ?>
                                <?php
                                $statusClass = 'success';
                                $statusText = 'In Stock';
                                if ($item['available_quantity'] <= 0) {
                                    $statusClass = 'danger';
                                    $statusText = 'Out of Stock';
                                } elseif ($item['available_quantity'] <= $item['reorder_level']) {
                                    $statusClass = 'warning';
                                    $statusText = 'Low Stock';
                                }
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($item['variant_name']); ?></small>
                                    </td>
                                    <td><code><?php echo htmlspecialchars($item['sku']); ?></code></td>
                                    <td>
                                        <?php echo htmlspecialchars($item['warehouse_name']); ?>
                                        <?php if ($item['location_code']): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($item['location_code']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-bold <?php echo $statusClass === 'warning' ? 'text-warning' : ($statusClass === 'danger' ? 'text-danger' : 'text-success'); ?>">
                                        <?php echo number_format((float)$item['available_quantity'], 0); ?>
                                        <?php if ($item['committed_quantity'] > 0): ?>
                                            <br><small class="text-muted">(<?php echo number_format((float)$item['committed_quantity'], 0); ?> committed)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?php echo format_currency($item['avg_landed_cost'] ?? $item['purchase_price'] ?? 0, $companyId); ?></td>
                                    <td class="text-end"><?php echo format_currency(($item['quantity'] * ($item['avg_landed_cost'] ?? $item['purchase_price'] ?? 0)), $companyId); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                        <?php if ($item['reorder_level'] > 0 && $item['available_quantity'] <= $item['reorder_level'] && $item['available_quantity'] > 0): ?>
                                            <br><small class="text-muted">Reorder at: <?php echo number_format((float)$item['reorder_level'], 0); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-info" 
                                                onclick="showDetails(<?php echo htmlspecialchars(json_encode($item)); ?>)"
                                                title="View Details">
                                            <i class="fas fa-info-circle me-1"></i>Details
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Stock Details Modal -->
<div class="modal fade" id="stockDetailsModal" tabindex="-1"">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-boxes me-2"></i>Stock Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="stockDetailsContent">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a href="#" id="adjustStockBtn" class="btn btn-warning">
                    <i class="fas fa-adjust me-2"></i>Adjust Stock
                </a>
                <a href="#" id="viewMovementsBtn" class="btn btn-info">
                    <i class="fas fa-history me-2"></i>View Movements
                </a>
            </div>
        </div>
    </div>
</div>


<style>
    .current-stock .table td {
        vertical-align: middle;
    }
    
    .current-stock .badge {
        font-weight: 500;
        padding: 0.5em 0.85em;
    }
    
    .current-stock .progress {
        border-radius: 10px;
        background-color: #e9ecef;
    }
    
    .modal .table-sm th {
        background-color: #f8f9fc;
    }
    
    .btn-group {
        gap: 4px;
    }
    
    .table-responsive {
        overflow-x: auto;
    }
    
    /* Responsive adjustments */
    @media (max-width: 768px) {
        .table td, .table th {
            white-space: nowrap;
        }
    }
</style>

<?php $jsFiles = ['inventory/stock.js']; ?>