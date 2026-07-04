<?php
// pages/reports/inventory.php
declare(strict_types=1);

$pageTitle = 'Inventory Report - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Inventory.php';
require_once __DIR__ . '/../../models/Product.php';
require_once __DIR__ . '/../../models/Warehouse.php';
require_once __DIR__ . '/../../models/Category.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR', 'ACC', 'VIW', 'WHS'])) {
    SessionManager::flash('error', 'You do not have permission to view inventory reports.');
    header('Location: ' . route_url('reports'));
    exit;
}

// Initialize models with company context
$inventoryModel = new Inventory($companyId);
$productModel = new Product($companyId);
$warehouseModel = new Warehouse($companyId);
$categoryModel = new Category($companyId);

// Get filter parameters
$warehouseId = isset($_GET['warehouse_id']) ? (int) $_GET['warehouse_id'] : null;
$categoryId = isset($_GET['category_id']) ? (int) $_GET['category_id'] : null;
$stockStatus = $_GET['stock_status'] ?? 'all';

// Get warehouses for filter (only this company's warehouses)
$warehouses = $warehouseModel->all(['id', 'warehouse_name'], 'is_active = 1');

// Get aging items older than 90 days
$agingItems = $inventoryModel->getStockAging(90);

// Get aging items for specific warehouse
$agingItemsWarehouse = $inventoryModel->getStockAging(90, $warehouseId);

// Get summary statistics
$summary = $inventoryModel->getStockAgingSummary(90);

// Get breakdown by warehouse
$byWarehouse = $inventoryModel->getStockAgingByWarehouse(90);

// Get valuation by warehouse (company-specific)
$valuationByWarehouse = $inventoryModel->getValuationByWarehouse();

// Get valuation by category (company-specific - only activated categories)
$valuationByCategory = $inventoryModel->getValuationByCategory();

// Get low stock items (company-specific)
$lowStockItems = $inventoryModel->getLowStock(100);

// Get out of stock items (company-specific)
$outOfStockItems = $inventoryModel->getOutOfStock($warehouseId);

// Get stock aging (company-specific)
$stockAging = $inventoryModel->getStockAging(90);

// Get stock movements summary (company-specific)
$movementsSummary = $inventoryModel->getMovementsSummary(30);

// Get all stock with filters (company-specific)
$stockList = $inventoryModel->getStockList($warehouseId, null);

// Filter by category if specified
if ($categoryId) {
    $stockList = array_filter($stockList, function ($item) use ($categoryId) {
        return ($item['category_id'] ?? 0) == $categoryId;
    });
}

// Filter by stock status
if ($stockStatus === 'low') {
    $stockList = array_filter($stockList, function ($item) {
        return ($item['available_quantity'] ?? $item['quantity']) <= ($item['reorder_level'] ?? 0) && ($item['available_quantity'] ?? $item['quantity']) > 0;
    });
} elseif ($stockStatus === 'out') {
    $stockList = array_filter($stockList, function ($item) {
        return ($item['available_quantity'] ?? $item['quantity']) <= 0;
    });
} elseif ($stockStatus === 'normal') {
    $stockList = array_filter($stockList, function ($item) {
        return ($item['available_quantity'] ?? $item['quantity']) > ($item['reorder_level'] ?? 0);
    });
}

// Calculate totals
$totalQuantity = array_sum(array_column($stockList, 'quantity'));
$totalValue = array_sum(array_map(function ($item) {
    return $item['quantity'] * ($item['avg_landed_cost'] ?? $item['purchase_price'] ?? 0);
}, $stockList));
$totalItems = count($stockList);

// Prepare chart data - Use QUANTITY instead of VALUE
$catLabels = [];
$catQuantities = [];
$catColors = [];

foreach ($valuationByCategory as $index => $cat) {
    $catLabels[] = $cat['category_name'];
    $catQuantities[] = (float) $cat['total_quantity'];  // Changed from total_value to total_quantity
    // Generate consistent color based on index
    $hue = ($index * 137) % 360;
    $catColors[] = "hsl({$hue}, 70%, 60%)";
}

// Prepare warehouse chart data - Use QUANTITY instead of VALUE
$warehouseLabels = [];
$warehouseQuantities = [];
$warehouseColors = [];

foreach ($valuationByWarehouse as $index => $wh) {
    $warehouseLabels[] = $wh['warehouse_name'];
    $warehouseQuantities[] = (float) ($wh['total_quantity'] ?? 0);  // Changed from total_value to total_quantity
    $hue = ($index * 137) % 360;
    $warehouseColors[] = "hsl({$hue}, 70%, 60%)";
}

// Calculate total quantity for display
$totalCategoryQuantity = array_sum($catQuantities);
$totalWarehouseQuantity = array_sum($warehouseQuantities);

// Get currency
$currency = $_SESSION['company_currency'] ?? 'RWF';

// Set JS files
$jsFiles = ['reports/inventory.js'];
?>

<!-- Pass data to JavaScript -->
<script>
    const currency = '<?php echo $currency; ?>';
    const categoryData = {
        labels: <?php echo json_encode($catLabels); ?>,
        quantities: <?php echo json_encode($catQuantities); ?>,
        colors: <?php echo json_encode($catColors); ?>,
        total: <?php echo $totalCategoryQuantity; ?>
    };
    const warehouseData = {
        labels: <?php echo json_encode($warehouseLabels); ?>,
        quantities: <?php echo json_encode($warehouseQuantities); ?>,
        colors: <?php echo json_encode($warehouseColors); ?>,
        total: <?php echo $totalWarehouseQuantity; ?>
    };
</script>

<div class="inventory-report">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <a href="<?php echo route_url('reports'); ?>" class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Reports
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-boxes me-2"></i>Inventory Report
                    </h2>
                    <p class="mb-0 text-muted">Analyze your stock levels and valuation</p>
                </div>
                <div class="btn-group mt-2 mt-sm-0">
                    <button type="button" class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-download me-2"></i>Export
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="?page=exports/inventory&format=pdf" target="_blank"><i
                                    class="fas fa-file-pdf me-2"></i>PDF</a></li>
                        <li><a class="dropdown-item" href="?page=exports/inventory&format=excel"><i
                                    class="fas fa-file-excel me-2"></i>Excel</a></li>
                        <li><a class="dropdown-item" href="?page=exports/inventory&format=csv"><i
                                    class="fas fa-file-csv me-2"></i>CSV</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Form -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-filter me-2"></i>Filter Report
            </h6>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <input type="hidden" name="page" value="reports/inventory">

                <div class="col-md-3">
                    <label class="form-label">Warehouse</label>
                    <select class="form-control" name="warehouse_id">
                        <option value="">All Warehouses</option>
                        <?php foreach ($warehouses as $wh): ?>
                            <option value="<?php echo $wh['id']; ?>" <?php echo $warehouseId == $wh['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($wh['warehouse_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Category</label>
                    <select class="form-control" name="category_id">
                        <option value="">All Categories</option>
                        <?php foreach ($activatedCategories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo $categoryId == $cat['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['category_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Stock Status</label>
                    <select class="form-control" name="stock_status">
                        <option value="all" <?php echo $stockStatus === 'all' ? 'selected' : ''; ?>>All Items</option>
                        <option value="normal" <?php echo $stockStatus === 'normal' ? 'selected' : ''; ?>>✅ Normal Stock
                        </option>
                        <option value="low" <?php echo $stockStatus === 'low' ? 'selected' : ''; ?>>⚠️ Low Stock</option>
                        <option value="out" <?php echo $stockStatus === 'out' ? 'selected' : ''; ?>>❌ Out of Stock
                        </option>
                    </select>
                </div>

                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search me-2"></i>Generate Report
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-primary shadow-sm h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Value</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo format_currency($summary['stock_value'] ?? 0, $companyId); ?></div>
                            <div class="small text-muted">
                                <?php echo number_format((float) ($summary['total_stock'] ?? 0), 0); ?> total units</div>
                        </div>
                        <div class="col-auto"><i class="fas fa-dollar-sign fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-success shadow-sm h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Unique Products</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) ($summary['unique_products'] ?? 0)); ?></div>
                            <div class="small text-muted">Across
                                <?php echo number_format((float) ($summary['warehouses_used'] ?? 0)); ?> warehouses</div>
                        </div>
                        <div class="col-auto"><i class="fas fa-boxes fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-warning shadow-sm h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Low Stock Items</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) ($summary['low_stock'] ?? 0)); ?></div>
                            <div class="small text-warning">Need reordering</div>
                        </div>
                        <div class="col-auto"><i class="fas fa-exclamation-triangle fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-danger shadow-sm h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Out of Stock</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) ($summary['out_of_stock'] ?? 0)); ?></div>
                            <div class="small text-danger">Critical items</div>
                        </div>
                        <div class="col-auto"><i class="fas fa-times-circle fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row mb-4">
        <div class="col-xl-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-chart-pie me-2"></i>Stock Quantity by Warehouse
                    </h6>
                </div>
                <div class="card-body chart-container">
                    <canvas id="warehouseChart" height="300"></canvas>
                    <div class="text-center mt-3">
                        <small class="text-muted">Total Stock: <?php echo number_format($totalWarehouseQuantity); ?> units
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-chart-pie me-2"></i>Stock Quantity by Category
                    </h6>
                </div>
                <div class="card-body chart-container">
                    <canvas id="categoryChart" height="300"></canvas>
                    <div class="text-center mt-3">
                        <small class="text-muted">Total Stock:
                            <?php echo number_format($totalCategoryQuantity); ?> units
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Stock Aging Table -->
    <div class="row">
        <div class="col-12 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-clock me-2"></i>Stock Aging Report (90+ days)
                        <span class="badge bg-primary ms-2"><?php echo count($stockAging); ?> items</span>
                    </h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="agingTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th>Warehouse</th>
                                    <th class="text-end">Quantity</th>
                                    <th class="text-end">Value</th>
                                    <th class="text-end">Days Inactive</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($stockAging)): ?>
                                    <!-- Empty state - each cell must match column count -->
                                    <tr class="text-center">
                                        <td colspan="7" class="text-muted py-4">No aging stock data available</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($stockAging as $item): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                                <?php if (!empty($item['variant_name']) && $item['variant_name'] !== 'Standard'): ?>
                                                    <br><small
                                                        class="text-muted"><?php echo htmlspecialchars($item['variant_name']); ?></small>
                                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <td class="text-center"><code><?php echo htmlspecialchars($item['sku']); ?></code></td>
                    <td><?php echo htmlspecialchars($item['warehouse_name']); ?>
                </div>
            </div>
            <td class="text-end"><?php echo number_format((float) $item['quantity'], 0); ?>
        </div>
        </div>
        <td class="text-end"><?php echo format_currency($item['stock_value'] ?? 0, $companyId); ?> </div>
            </div>
        <td class="text-end"><?php echo number_format((float) ($item['days_since_last_movement'] ?? 0), 0); ?> </div>
            </div>
        <td class="text-center">
            <?php
            $statusConfig = [
                'Fast Moving' => ['class' => 'success', 'icon' => 'fa-rocket'],
                'Normal' => ['class' => 'info', 'icon' => 'fa-chart-line'],
                'Slow Moving' => ['class' => 'warning', 'icon' => 'fa-turtle'],
                'Dead Stock' => ['class' => 'danger', 'icon' => 'fa-skull']
            ];
            $config = $statusConfig[$item['stock_status']] ?? ['class' => 'secondary', 'icon' => 'fa-question'];
            ?>
            <span class="badge bg-<?php echo $config['class']; ?>">
                <i class="fas <?php echo $config['icon']; ?> me-1"></i>
                <?php echo $item['stock_status']; ?>
            </span>
            </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
    </div>
    </div>
    </div>
    </div>
    </div>
    </div>
    <!-- Stock List Table -->
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-list me-2"></i>Current Stock Levels
                        <span class="badge bg-primary ms-2"><?php echo $totalItems; ?> items</span>
                    </h6>
                    <button class="btn btn-sm btn-outline-primary" onclick="window.location.reload()">
                        <i class="fas fa-sync-alt me-1"></i> Refresh
                    </button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="stockTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th>Category</th>
                                    <th>Warehouse</th>
                                    <th>Location</th>
                                    <th class="text-end">Quantity</th>
                                    <th class="text-end">Available</th>
                                    <th class="text-end">Avg Cost</th>
                                    <th class="text-end">Stock Value</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($stockList)): ?>
                                    <tr>
                                        <td colspan="10" class="text-center py-4 text-muted">No stock data available</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($stockList as $item):
                                        $stockValue = $item['quantity'] * ($item['avg_landed_cost'] ?? $item['purchase_price'] ?? 0);
                                        $availableQty = $item['available_quantity'] ?? $item['quantity'];
                                        $statusClass = 'success';
                                        $statusText = 'Normal';
                                        $statusIcon = 'fa-check-circle';

                                        if ($availableQty <= 0) {
                                            $statusClass = 'danger';
                                            $statusText = 'Out of Stock';
                                            $statusIcon = 'fa-times-circle';
                                        } elseif ($availableQty <= ($item['reorder_level'] ?? 0)) {
                                            $statusClass = 'warning';
                                            $statusText = 'Low Stock';
                                            $statusIcon = 'fa-exclamation-triangle';
                                        }
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                                <?php if (!empty($item['variant_name']) && $item['variant_name'] !== 'Standard'): ?>
                                                    <br><small
                                                        class="text-muted"><?php echo htmlspecialchars($item['variant_name']); ?></small>
                                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
        <td class="text-center"><code><?php echo htmlspecialchars($item['sku']); ?></code></td>
        <td><?php echo htmlspecialchars($item['category_name'] ?? 'N/A'); ?> </div>
            </div>
        <td><?php echo htmlspecialchars($item['warehouse_name']); ?> </div>
            </div>
        <td>
            <?php if ($item['location_code']): ?>
                <i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($item['location_code']); ?>
            <?php else: ?>
                <span class="text-muted">-</span>
            <?php endif; ?>
            </div>
            </div>
        <td class="text-end <?php echo $availableQty <= 0 ? 'text-danger' : ''; ?>">
            <?php echo number_format((float) $item['quantity'], 0); ?>
            </div>
            </div>
        <td class="text-end"><?php echo number_format((float) $availableQty, 0); ?> </div>
            </div>
        <td class="text-end">
            <?php echo format_currency($item['avg_landed_cost'] ?? $item['purchase_price'] ?? 0, $companyId); ?> </div>
            </div>
        <td class="text-end"><?php echo format_currency($stockValue, $companyId); ?> </div>
            </div>
        <td class="text-center">
            <span class="badge bg-<?php echo $statusClass; ?>">
                <i class="fas <?php echo $statusIcon; ?> me-1"></i>
                <?php echo $statusText; ?>
            </span>
            </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
    <tfoot class="table-light">
        <tr>
            <th colspan="5" class="text-end fw-bold">Totals:</th>
            <th class="text-end fw-bold"><?php echo number_format((float) $totalQuantity, 0); ?></th>
            <th class="text-end fw-bold">-</th>
            <th class="text-end fw-bold">-</th>
            <th class="text-end fw-bold"><?php echo format_currency($totalValue, $companyId); ?></th>
            <th></th>
        </tr>
    </tfoot>
    </div>
    </div>
    </div>
    </div>
    </div>
    </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        .chart-container {
            position: relative;
            height: 300px; /* Or whatever height you want */
            width: 100%;
        }
        .inventory-report .border-left-primary {
            border-left: 4px solid #4e73df !important;
        }

        .inventory-report .border-left-success {
            border-left: 4px solid #1cc88a !important;
        }

        .inventory-report .border-left-info {
            border-left: 4px solid #36b9cc !important;
        }

        .inventory-report .border-left-warning {
            border-left: 4px solid #f6c23e !important;
        }

        .inventory-report .border-left-danger {
            border-left: 4px solid #e74a3b !important;
        }

        .inventory-report .table td {
            vertical-align: middle;
        }

        .inventory-report .badge {
            font-weight: 500;
            padding: 0.5em 0.85em;
        }

        .inventory-report .progress {
            border-radius: 10px;
            background-color: #e9ecef;
        }
    </style>