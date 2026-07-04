<?php
// pages/inventory/adjustments.php
declare(strict_types=1);

$pageTitle = 'Stock Adjustments - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';

// Load models
require_once __DIR__ . '/../../models/Inventory.php';
require_once __DIR__ . '/../../models/Variant.php';
require_once __DIR__ . '/../../models/Product.php';
require_once __DIR__ . '/../../models/Warehouse.php';
require_once __DIR__ . '/../../models/Location.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR', 'WHS'])) {
    SessionManager::flash('error', 'You do not have permission to access inventory adjustments.');
    header('Location: ' . route_url('dashboard'));
    exit;
}

// Initialize models with company context
$inventoryModel = new Inventory($companyId);
$variantModel = new Variant($companyId);
$productModel = new Product($companyId);
$warehouseModel = new Warehouse($companyId);
$locationModel = new Location($companyId);

// Get warehouses for filter (only this company's warehouses)
$warehouses = $warehouseModel->all(['id', 'warehouse_code', 'warehouse_name'], 'is_active = 1');

// Get filter parameters
$warehouseId = isset($_GET['warehouse_id']) ? (int) $_GET['warehouse_id'] : null;
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$type = $_GET['type'] ?? '';
$search = $_GET['search'] ?? '';

// Get recent adjustments
$adjustments = $inventoryModel->getMovementsByDateRange($dateFrom, $dateTo, $warehouseId);

// Filter by type if specified
if (!empty($type) && $type !== 'all') {
    $adjustments = array_filter($adjustments, function ($adj) use ($type) {
        return $adj['transaction_type'] === $type;
    });
}

// Filter by search term
if (!empty($search)) {
    $searchLower = strtolower($search);
    $adjustments = array_filter($adjustments, function ($adj) use ($searchLower) {
        return strpos(strtolower($adj['product_name'] ?? ''), $searchLower) !== false ||
            strpos(strtolower($adj['sku'] ?? ''), $searchLower) !== false ||
            strpos(strtolower($adj['transaction_code'] ?? ''), $searchLower) !== false;
    });
}

// Generate CSRF token for adjustment form
$csrfToken = CSRF::generate();

// Get adjustment summary
$adjustmentSummary = $inventoryModel->getMovementsSummary(30);

// Get low stock items for quick reference
$lowStockItems = $inventoryModel->getLowStock(10);

// Get locations for JavaScript
$locations = $locationModel->getAllWithWarehouse();

// Get currency
$currency = $_SESSION['company_currency'] ?? 'RWF';

// Set JS files
$jsFiles = ['inventory/adjustment.js'];
?>

<!-- Pass data to JavaScript -->
<script>
    const companyCurrency = '<?php echo $currency; ?>';
    const locations = <?php echo json_encode($locations); ?>;
    const warehouses = <?php echo json_encode($warehouses); ?>;
    const csrfToken = '<?php echo $csrfToken; ?>';
    const companyId = <?php echo json_encode($companyId); ?>;
</script>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <a href="<?php echo route_url('inventory/dashboard'); ?>" class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-sliders-h me-2"></i>Stock Adjustments
                    </h2>
                    <p class="mb-0 text-muted">Manage inventory adjustments, corrections, and stock counts</p>
                </div>
                <div>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newAdjustmentModal">
                        <i class="fas fa-plus-circle me-2"></i>New Adjustment
                    </button>
                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#stockCountModal">
                        <i class="fas fa-clipboard-list me-2"></i>Stock Count
                    </button>
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

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Total Adjustments (30 days)
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php
                                $totalAdjustments = array_sum(array_column($adjustmentSummary, 'transaction_count'));
                                echo number_format((float) $totalAdjustments);
                                ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-calendar fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Total Quantity Adjusted
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php
                                $totalQuantity = array_sum(array_column($adjustmentSummary, 'total_quantity'));
                                echo number_format((float) $totalQuantity, 0);
                                ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-cubes fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                Total Adjustment Value
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php
                                $totalValue = array_sum(array_column($adjustmentSummary, 'total_value'));
                                echo format_currency($totalValue, $companyId);
                                ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-dollar-sign fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Low Stock Items
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo count($lowStockItems); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-exclamation-triangle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-filter me-2"></i>Filter Adjustments
            </h6>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <input type="hidden" name="page" value="inventory/adjustments">

                <div class="col-md-3">
                    <label for="date_from" class="form-label">Date From</label>
                    <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo $dateFrom; ?>">
                </div>

                <div class="col-md-3">
                    <label for="date_to" class="form-label">Date To</label>
                    <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo $dateTo; ?>">
                </div>

                <div class="col-md-2">
                    <label for="warehouse_id" class="form-label">Warehouse</label>
                    <select class="form-control" id="warehouse_id" name="warehouse_id">
                        <option value="">All Warehouses</option>
                        <?php foreach ($warehouses as $wh): ?>
                            <option value="<?php echo $wh['id']; ?>" <?php echo $warehouseId == $wh['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($wh['warehouse_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label for="type" class="form-label">Adjustment Type</label>
                    <select class="form-control" id="type" name="type">
                        <option value="all" <?php echo $type === 'all' || $type === '' ? 'selected' : ''; ?>>All Types</option>
                        <option value="purchase" <?php echo $type === 'purchase' ? 'selected' : ''; ?>>Purchase</option>
                        <option value="sale" <?php echo $type === 'sale' ? 'selected' : ''; ?>>Sale</option>
                        <option value="return" <?php echo $type === 'return' ? 'selected' : ''; ?>>Return</option>
                        <option value="adjustment" <?php echo $type === 'adjustment' ? 'selected' : ''; ?>>Adjustment</option>
                        <option value="transfer" <?php echo $type === 'transfer' ? 'selected' : ''; ?>>Transfer</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" class="form-control" id="search" name="search"
                        value="<?php echo htmlspecialchars($search); ?>" placeholder="Product, SKU, or code...">
                </div>

                <div class="col-12 d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="fas fa-search me-2"></i>Apply Filters
                    </button>
                    <a href="?page=inventory/adjustments" class="btn btn-secondary">
                        <i class="fas fa-times me-2"></i>Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Adjustments Table - Simplified -->
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-list me-2"></i>Adjustment History
                <span class="badge bg-primary ms-2"><?php echo count($adjustments); ?> records</span>
            </h6>
            <button class="btn btn-sm btn-outline-primary" onclick="window.location.reload()">
                <i class="fas fa-sync-alt me-1"></i>Refresh
            </button>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="adjustmentsTable" width="100%" cellspacing="0">
                    <thead class="table-light">
                        <tr>
                            <th>Date & Time</th>
                            <th>Transaction Code</th>
                            <th>Type</th>
                            <th>Product</th>
                            <th>Warehouse</th>
                            <th class="text-end">Quantity</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($adjustments)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <div class="text-muted">
                                        <i class="fas fa-exchange-alt fa-3x mb-3"></i>
                                        <p class="mb-0">No adjustments found</p>
                                        <small>Try adjusting your filters or create a new adjustment</small>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($adjustments as $adj): ?>
                                <?php
                                $statusClass = $adj['quantity'] > 0 ? 'success' : 'danger';
                                $statusText = $adj['quantity'] > 0 ? 'Added' : 'Removed';
                                ?>
                                <tr>
                                    <td>
                                        <span title="<?php echo htmlspecialchars($adj['created_at']); ?>">
                                            <i class="far fa-calendar-alt me-1"></i>
                                            <?php echo date('d/m/Y H:i', strtotime($adj['created_at'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <code class="small"><?php echo htmlspecialchars($adj['transaction_code']); ?></code>
                                    </td>
                                    <td>
                                        <?php
                                        $badgeConfig = [
                                            'purchase' => ['class' => 'success', 'icon' => 'fa-shopping-cart', 'text' => 'Purchase'],
                                            'sale' => ['class' => 'primary', 'icon' => 'fa-tag', 'text' => 'Sale'],
                                            'return' => ['class' => 'warning', 'icon' => 'fa-undo', 'text' => 'Return'],
                                            'adjustment' => ['class' => 'info', 'icon' => 'fa-adjust', 'text' => 'Adjustment'],
                                            'transfer' => ['class' => 'secondary', 'icon' => 'fa-exchange-alt', 'text' => 'Transfer']
                                        ];
                                        $config = $badgeConfig[$adj['transaction_type']] ?? ['class' => 'secondary', 'icon' => 'fa-question', 'text' => ucfirst($adj['transaction_type'])];
                                        ?>
                                        <span class="badge bg-<?php echo $config['class']; ?>">
                                            <i class="fas <?php echo $config['icon']; ?> me-1"></i>
                                            <?php echo $config['text']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($adj['product_name']); ?></div>
                                        <?php if (!empty($adj['variant_name']) && $adj['variant_name'] !== 'Standard'): ?>
                                            <small class="text-muted"><?php echo htmlspecialchars($adj['variant_name']); ?></small>
                                        <?php endif; ?>
                                        <br>
                                        <small class="text-muted">SKU: <?php echo htmlspecialchars($adj['sku']); ?></small>
                                    </td>
                                    <td>
                                        <i class="fas fa-building me-1"></i>
                                        <?php echo htmlspecialchars($adj['warehouse_name']); ?>
                                        <?php if ($adj['warehouse_code']): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($adj['warehouse_code']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-bold <?php echo $adj['quantity'] > 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo $adj['quantity'] > 0 ? '+' : ''; ?>
                                        <?php echo number_format(abs((float) $adj['quantity']), 0); ?> units
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-info" 
                                                onclick='viewAdjustment(<?php echo htmlspecialchars(json_encode($adj)); ?>)'
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

<!-- New Adjustment Modal -->
<div class="modal fade" id="newAdjustmentModal" tabindex="-1" aria-labelledby="newAdjustmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="newAdjustmentModalLabel">
                    <i class="fas fa-plus-circle me-2"></i>New Stock Adjustment
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="?page=inventory/process-adjustment" id="adjustmentForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="company_id" value="<?php echo $companyId; ?>">

                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="adjustment_type" class="form-label">Adjustment Type <span class="text-danger">*</span></label>
                            <select class="form-control" id="adjustment_type" name="type" required>
                                <option value="">Select Type</option>
                                <option value="purchase">Purchase (Add Stock)</option>
                                <option value="sale">Sale (Remove Stock)</option>
                                <option value="return">Return (Add Stock)</option>
                                <option value="adjustment">Manual Adjustment</option>
                                <option value="transfer">Transfer</option>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="adjustment_date" class="form-label">Adjustment Date <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control" id="adjustment_date" name="date"
                                value="<?php echo date('Y-m-d\TH:i'); ?>" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="modal_warehouse_id" class="form-label">Warehouse <span class="text-danger">*</span></label>
                            <select class="form-control" id="modal_warehouse_id" name="warehouse_id" required>
                                <option value="">Select Warehouse</option>
                                <?php foreach ($warehouses as $wh): ?>
                                    <option value="<?php echo $wh['id']; ?>">
                                        <?php echo htmlspecialchars($wh['warehouse_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="modal_location_id" class="form-label">Location</label>
                            <select class="form-control" id="modal_location_id" name="location_id">
                                <option value="">Select Location (Optional)</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label for="product_search" class="form-label">Search Product/Variant <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="product_search" placeholder="Type to search..." autocomplete="off">
                            <input type="hidden" id="variant_id" name="variant_id" required>
                            <div id="searchResults" class="list-group mt-1" style="max-height: 200px; overflow-y: auto; display: none;"></div>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="quantity" class="form-label">Quantity <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="quantity" name="quantity" min="0.01" step="0.01" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="unit_cost" class="form-label">Unit Cost</label>
                            <div class="input-group">
                                <span class="input-group-text currency-symbol"><?php echo $currency; ?></span>
                                <input type="number" class="form-control" id="unit_cost" name="unit_cost" min="0" step="0.01" value="0">
                            </div>
                            <small class="text-muted">Required for purchase adjustments</small>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="reference" class="form-label">Reference</label>
                            <input type="text" class="form-control" id="reference" name="reference" placeholder="e.g., PO-001, Count Sheet">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="reason" class="form-label">Reason/Notes</label>
                        <textarea class="form-control" id="reason" name="reason" rows="3" placeholder="Explain the reason for this adjustment"></textarea>
                    </div>

                    <!-- Selected Product Display -->
                    <div id="selectedProductDisplay" class="alert alert-info" style="display: none;">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-check-circle fa-2x me-3"></i>
                            <div>
                                <strong id="selectedProductName"></strong>
                                <br>
                                <small id="selectedProductDetails"></small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitAdjustment">
                        <i class="fas fa-save me-2"></i>Process Adjustment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Stock Count Modal -->
<div class="modal fade" id="stockCountModal" tabindex="-1" aria-labelledby="stockCountModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="stockCountModalLabel">
                    <i class="fas fa-clipboard-list me-2"></i>Stock Count
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">Select a warehouse to start a stock count session.</p>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="count_warehouse" class="form-label">Warehouse <span class="text-danger">*</span></label>
                        <select class="form-control" id="count_warehouse" name="count_warehouse">
                            <option value="">Select Warehouse</option>
                            <?php foreach ($warehouses as $wh): ?>
                                <option value="<?php echo $wh['id']; ?>">
                                    <?php echo htmlspecialchars($wh['warehouse_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6 mb-3">
                        <label for="count_location" class="form-label">Location</label>
                        <select class="form-control" id="count_location" name="count_location">
                            <option value="">All Locations</option>
                        </select>
                    </div>
                </div>

                <div class="alert alert-warning">
                    <i class="fas fa-info-circle me-2"></i>
                    You will be redirected to the stock count page where you can enter physical counts.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="startCountBtn" class="btn btn-success">
                    <i class="fas fa-play me-2"></i>Start Count
                </a>
            </div>
        </div>
    </div>
</div>

<!-- View Adjustment Modal -->
<div class="modal fade" id="viewAdjustmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="fas fa-info-circle me-2"></i>Adjustment Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="adjustmentDetails">
                <!-- Content will be populated by JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a href="#" id="viewRelatedProductBtn" class="btn btn-primary" target="_blank">
                    <i class="fas fa-box me-2"></i>View Product
                </a>
            </div>
        </div>
    </div>
</div>

<style>
    .border-left-primary { border-left: 4px solid #4e73df !important; }
    .border-left-success { border-left: 4px solid #1cc88a !important; }
    .border-left-info { border-left: 4px solid #36b9cc !important; }
    .border-left-warning { border-left: 4px solid #f6c23e !important; }
    
    .list-group {
        position: absolute;
        z-index: 1000;
        width: calc(100% - 30px);
        background: white;
        border: 1px solid #ddd;
        border-radius: 4px;
        box-shadow: 0 6px 12px rgba(0, 0, 0, 0.175);
    }
    
    .list-group-item {
        cursor: pointer;
        padding: 8px 12px;
        border: none;
        border-bottom: 1px solid #eee;
    }
    
    .list-group-item:last-child {
        border-bottom: none;
    }
    
    .list-group-item:hover {
        background-color: #f8f9fa;
    }
    
    .table td {
        vertical-align: middle;
    }
    
    .badge {
        font-weight: 500;
        padding: 0.5em 0.85em;
    }
    
    .btn-group {
        gap: 2px;
    }
    
    .modal .table-sm th {
        background-color: #f8f9fc;
    }
    
    .currency-symbol {
        min-width: 50px;
    }
</style>