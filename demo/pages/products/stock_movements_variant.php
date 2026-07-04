<?php
// pages/products/stock_movements_variant.php
declare(strict_types=1);

$pageTitle = 'Stock Movements - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';

// Load models
require_once __DIR__ . '/../../models/Product.php';
require_once __DIR__ . '/../../models/Variant.php';
require_once __DIR__ . '/../../models/Inventory.php';
require_once __DIR__ . '/../../models/Warehouse.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? 0;

// Get variant ID from URL
$variantId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$variantId) {
    SessionManager::flash('error', 'Variant ID is required.');
    header('Location: ' . route_url('products'));
    exit;
}

// Initialize models with company context
$variantModel = new Variant($companyId);
$productModel = new Product($companyId);
$inventoryModel = new Inventory($companyId);
$warehouseModel = new Warehouse($companyId);

// Get variant details
$variant = $variantModel->getWithDetails($variantId);

if (!$variant) {
    SessionManager::flash('error', 'Variant not found or not accessible for your company.');
    header('Location: ' . route_url('products'));
    exit;
}

// Get product details
$product = $productModel->getWithDetails($variant['product_id']);

// Get warehouses for filter
$warehouses = $warehouseModel->all(['id', 'warehouse_name', 'warehouse_code'], 'is_active = 1');

// Filter parameters
$warehouseFilter = isset($_GET['warehouse']) ? (int)$_GET['warehouse'] : null;
$typeFilter = $_GET['type'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Build query for movements
$movementsSql = "
    SELECT 
        it.*,
        w.warehouse_name,
        w.warehouse_code,
        u.full_name as created_by_name
    FROM inventory_transactions it
    LEFT JOIN warehouses w ON it.warehouse_id = w.id
    LEFT JOIN users u ON it.created_by = u.id
    WHERE it.variant_id = :variant_id
        AND it.company_id = :company_id
";

$countSql = "
    SELECT COUNT(*) as total
    FROM inventory_transactions it
    WHERE it.variant_id = :variant_id
        AND it.company_id = :company_id
";

$params = [
    'variant_id' => $variantId,
    'company_id' => $companyId
];
$countParams = [
    'variant_id' => $variantId,
    'company_id' => $companyId
];

// Apply warehouse filter
if ($warehouseFilter) {
    $movementsSql .= " AND it.warehouse_id = :warehouse_id";
    $countSql .= " AND it.warehouse_id = :warehouse_id";
    $params['warehouse_id'] = $warehouseFilter;
    $countParams['warehouse_id'] = $warehouseFilter;
}

// Apply type filter
if ($typeFilter) {
    $movementsSql .= " AND it.transaction_type = :type";
    $countSql .= " AND it.transaction_type = :type";
    $params['type'] = $typeFilter;
    $countParams['type'] = $typeFilter;
}

// Apply date filters
if ($dateFrom) {
    $movementsSql .= " AND DATE(it.created_at) >= :date_from";
    $countSql .= " AND DATE(it.created_at) >= :date_from";
    $params['date_from'] = $dateFrom;
    $countParams['date_from'] = $dateFrom;
}

if ($dateTo) {
    $movementsSql .= " AND DATE(it.created_at) <= :date_to";
    $countSql .= " AND DATE(it.created_at) <= :date_to";
    $params['date_to'] = $dateTo;
    $countParams['date_to'] = $dateTo;
}

// Get total count
$countStmt = $db->prepare($countSql);
$countStmt->execute($countParams);
$totalMovements = $countStmt->fetch()['total'];
$totalPages = ceil($totalMovements / $limit);


// Get movements with pagination
$movementsSql .= " ORDER BY it.created_at DESC LIMIT :limit OFFSET :offset";
$params['limit'] = $limit;
$params['offset'] = $offset;

$movementsStmt = $db->prepare($movementsSql);
foreach ($params as $key => $value) {
    if ($key === 'limit' || $key === 'offset') {
        $movementsStmt->bindValue(":$key", $value, PDO::PARAM_INT);
    } else {
        $movementsStmt->bindValue(":$key", $value);
    }
}
$movementsStmt->execute();
$movements = $movementsStmt->fetchAll();

echo count($movements);

// Get stock summary
$currentStock = $variantModel->getStockByWarehouse($variantId);
$totalStock = array_sum(array_column($currentStock, 'quantity'));
$totalCommitted = array_sum(array_column($currentStock, 'committed_quantity'));
$totalAvailable = $totalStock - $totalCommitted;

// Calculate statistics
$stats = [
    'total_in' => 0,
    'total_out' => 0,
    'total_adjustments' => 0,
    'avg_quantity' => 0
];

foreach ($movements as $movement) {
    if ($movement['quantity'] > 0) {
        $stats['total_in'] += $movement['quantity'];
    } else {
        $stats['total_out'] += abs($movement['quantity']);
    }
    if ($movement['transaction_type'] === 'adjustment') {
        $stats['total_adjustments']++;
    }
}
$stats['avg_quantity'] = $totalMovements > 0 ? abs($stats['total_in'] - $stats['total_out']) / $totalMovements : 0;

// Generate CSRF token for any actions
$csrfToken = CSRF::generate();
?>

<div class="stock-movements-page">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <a href="<?php echo route_url('products/view-variant', ['id' => $variantId]); ?>" 
                       class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Variant
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-history me-2"></i>Stock Movement History
                    </h2>
                    <p class="mb-0 text-muted">
                        Tracking inventory changes for variant: 
                        <strong><?php echo htmlspecialchars($variant['variant_name'] ?: 'Standard'); ?></strong>
                        (SKU: <?php echo htmlspecialchars($variant['sku']); ?>)
                    </p>
                </div>
                <div>
                    <a href="?page=products/stock-adjust-variant&id=<?php echo $variantId; ?>" 
                       class="btn btn-primary">
                        <i class="fas fa-adjust me-2"></i>New Adjustment
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

    <!-- Stock Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Current Stock</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($totalStock, 0); ?> units
                            </div>
                            <small class="text-muted">Total in all warehouses</small>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-boxes fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Available Stock</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($totalAvailable, 0); ?> units
                            </div>
                            <small class="text-muted">Available for sale</small>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-check-circle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                Total Inflow</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['total_in'], 0); ?> units
                            </div>
                            <small class="text-muted">Purchases & additions</small>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-arrow-down fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Total Outflow</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($stats['total_out'], 0); ?> units
                            </div>
                            <small class="text-muted">Sales & removals</small>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-arrow-up fa-2x text-gray-300"></i>
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
                <i class="fas fa-filter me-2"></i>Filter Movements
            </h6>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <input type="hidden" name="page" value="products/stock-movements-variant">
                <input type="hidden" name="id" value="<?php echo $variantId; ?>">
                
                <div class="col-md-3">
                    <label for="warehouse" class="form-label">Warehouse</label>
                    <select class="form-control" id="warehouse" name="warehouse">
                        <option value="">All Warehouses</option>
                        <?php foreach ($warehouses as $warehouse): ?>
                            <option value="<?php echo $warehouse['id']; ?>" 
                                    <?php echo $warehouseFilter == $warehouse['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($warehouse['warehouse_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label for="type" class="form-label">Transaction Type</label>
                    <select class="form-control" id="type" name="type">
                        <option value="">All Types</option>
                        <option value="purchase" <?php echo $typeFilter === 'purchase' ? 'selected' : ''; ?>>Purchase</option>
                        <option value="sale" <?php echo $typeFilter === 'sale' ? 'selected' : ''; ?>>Sale</option>
                        <option value="return" <?php echo $typeFilter === 'return' ? 'selected' : ''; ?>>Return</option>
                        <option value="adjustment" <?php echo $typeFilter === 'adjustment' ? 'selected' : ''; ?>>Adjustment</option>
                        <option value="transfer" <?php echo $typeFilter === 'transfer' ? 'selected' : ''; ?>>Transfer</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label for="date_from" class="form-label">Date From</label>
                    <input type="date" class="form-control" id="date_from" name="date_from" 
                           value="<?php echo htmlspecialchars($dateFrom); ?>">
                </div>
                
                <div class="col-md-2">
                    <label for="date_to" class="form-label">Date To</label>
                    <input type="date" class="form-control" id="date_to" name="date_to" 
                           value="<?php echo htmlspecialchars($dateTo); ?>">
                </div>
                
                <div class="col-md-3 d-flex align-items-end">
                    <div class="btn-group w-100">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-2"></i>Apply Filters
                        </button>
                        <a href="?page=products/stock-movements-variant&id=<?php echo $variantId; ?>" 
                           class="btn btn-secondary">
                            <i class="fas fa-undo me-2"></i>Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Movements Table -->
    <div class="card shadow">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-list me-2"></i>Movement History
                <span class="badge bg-secondary ms-2"><?php echo number_format($totalMovements); ?> records</span>
            </h6>
            <div>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="exportToCSV()">
                    <i class="fas fa-download me-1"></i>Export CSV
                </button>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($movements)): ?>
                <div class="text-center py-5">
                    <div class="text-muted">
                        <i class="fas fa-exchange-alt fa-4x mb-3"></i>
                        <p class="h5 mb-2">No stock movements found</p>
                        <p class="mb-0">Try adjusting your filters or create a new stock adjustment.</p>
                        <a href="?page=products/stock-adjust-variant&id=<?php echo $variantId; ?>" 
                           class="btn btn-primary mt-3">
                            <i class="fas fa-plus me-2"></i>Create First Adjustment
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover" id="movementsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Date & Time</th>
                                <th>Transaction Code</th>
                                <th>Type</th>
                                <th>Warehouse</th>
                                <th class="text-end">Quantity</th>
                                <th>Reference</th>
                                <th>Notes</th>
                                <th>Created By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($movements as $movement): ?>
                                <tr>
                                    <td>
                                        <span title="<?php echo htmlspecialchars($movement['created_at']); ?>">
                                            <i class="far fa-calendar-alt me-1"></i>
                                            <?php echo date('d/m/Y H:i', strtotime($movement['created_at'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <code class="small"><?php echo htmlspecialchars($movement['transaction_code']); ?></code>
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
                                        $config = $badgeConfig[$movement['transaction_type']] ?? ['class' => 'secondary', 'icon' => 'fa-question', 'text' => ucfirst($movement['transaction_type'])];
                                        ?>
                                        <span class="badge bg-<?php echo $config['class']; ?>">
                                            <i class="fas <?php echo $config['icon']; ?> me-1"></i>
                                            <?php echo $config['text']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($movement['warehouse_name']): ?>
                                            <i class="fas fa-building me-1"></i>
                                            <?php echo htmlspecialchars($movement['warehouse_name']); ?>
                                            <?php if ($movement['warehouse_code']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($movement['warehouse_code']); ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end <?php echo $movement['quantity'] > 0 ? 'text-success' : 'text-danger'; ?>">
                                        <strong>
                                            <?php echo $movement['quantity'] > 0 ? '+' : ''; ?>
                                            <?php echo number_format((float)$movement['quantity'], 0); ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <?php if ($movement['reference_type'] && $movement['reference_id']): ?>
                                            <a href="?page=<?php echo $movement['reference_type']; ?>/view&id=<?php echo $movement['reference_id']; ?>" 
                                               class="text-decoration-none">
                                                <small>
                                                    <?php echo ucfirst($movement['reference_type']); ?>
                                                    #<?php echo $movement['reference_id']; ?>
                                                </small>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($movement['notes'])): ?>
                                            <small class="text-muted" title="<?php echo htmlspecialchars($movement['notes']); ?>">
                                                <i class="fas fa-sticky-note me-1"></i>
                                                <?php echo htmlspecialchars(substr($movement['notes'], 0, 50)); ?>
                                                <?php echo strlen($movement['notes']) > 50 ? '...' : ''; ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small>
                                            <i class="fas fa-user me-1"></i>
                                            <?php echo htmlspecialchars($movement['created_by_name'] ?? 'System'); ?>
                                        </small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <nav aria-label="Page navigation" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=products/stock-movements-variant&id=<?php echo $variantId; ?>&warehouse=<?php echo $warehouseFilter; ?>&type=<?php echo urlencode($typeFilter); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>&page=<?php echo $page - 1; ?>">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </a>
                            </li>
                            
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            
                            if ($startPage > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=products/stock-movements-variant&id=<?php echo $variantId; ?>&warehouse=<?php echo $warehouseFilter; ?>&type=<?php echo urlencode($typeFilter); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>&page=1">1</a>
                                </li>
                                <?php if ($startPage > 2): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=products/stock-movements-variant&id=<?php echo $variantId; ?>&warehouse=<?php echo $warehouseFilter; ?>&type=<?php echo urlencode($typeFilter); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>&page=<?php echo $i; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($endPage < $totalPages): ?>
                                <?php if ($endPage < $totalPages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=products/stock-movements-variant&id=<?php echo $variantId; ?>&warehouse=<?php echo $warehouseFilter; ?>&type=<?php echo urlencode($typeFilter); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>&page=<?php echo $totalPages; ?>">
                                        <?php echo $totalPages; ?>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=products/stock-movements-variant&id=<?php echo $variantId; ?>&warehouse=<?php echo $warehouseFilter; ?>&type=<?php echo urlencode($typeFilter); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>&page=<?php echo $page + 1; ?>">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Warehouse Stock Breakdown -->
    <div class="row mt-4">
        <div class="col-md-6">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-building me-2"></i>Stock by Warehouse
                    </h6>
                </div>
                <div class="card-body">
                    <?php if (empty($currentStock)): ?>
                        <div class="text-center text-muted py-3">
                            <i class="fas fa-box-open fa-3x mb-3"></i>
                            <p>No stock in any warehouse</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Warehouse</th>
                                        <th class="text-end">Quantity</th>
                                        <th class="text-end">Committed</th>
                                        <th class="text-end">Available</th>
                                        <th>Location</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($currentStock as $stock): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($stock['warehouse_name']); ?></strong>
                                                <?php if ($stock['warehouse_code']): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($stock['warehouse_code']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end"><?php echo number_format($stock['quantity'], 0); ?></td>
                                            <td class="text-end"><?php echo number_format($stock['committed_quantity'] ?? 0, 0); ?></td>
                                            <td class="text-end text-success">
                                                <?php echo number_format($stock['available_quantity'] ?? $stock['quantity'], 0); ?>
                                            </td>
                                            <td>
                                                <?php if ($stock['location_code']): ?>
                                                    <small>
                                                        <?php echo htmlspecialchars($stock['location_code']); ?>
                                                        <?php if ($stock['location_name']): ?>
                                                            - <?php echo htmlspecialchars($stock['location_name']); ?>
                                                        <?php endif; ?>
                                                    </small>
                                                <?php else: ?>
                                                    <small class="text-muted">Default</small>
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

        <div class="col-md-6">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-chart-line me-2"></i>Movement Statistics
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6 mb-3">
                            <div class="border rounded p-3">
                                <div class="text-muted small">Total Transactions</div>
                                <div class="h4 mb-0"><?php echo number_format($totalMovements); ?></div>
                            </div>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="border rounded p-3">
                                <div class="text-muted small">Adjustments</div>
                                <div class="h4 mb-0"><?php echo number_format($stats['total_adjustments']); ?></div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded p-3">
                                <div class="text-muted small">Net Movement</div>
                                <div class="h4 mb-0 <?php echo ($stats['total_in'] - $stats['total_out']) >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo number_format($stats['total_in'] - $stats['total_out'], 0); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded p-3">
                                <div class="text-muted small">Turnover Rate</div>
                                <div class="h4 mb-0">
                                    <?php 
                                    $avgStock = ($totalStock + $stats['total_in']) / 2;
                                    $turnover = $avgStock > 0 ? $stats['total_out'] / $avgStock : 0;
                                    echo number_format($turnover, 2);
                                    ?>
                                </div>
                                <small class="text-muted">times</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Initialize DataTable with custom options
$(document).ready(function() {
    if ($('#movementsTable tbody tr').length > 0) {
        $('#movementsTable').DataTable({
            pageLength: 25,
            ordering: true,
            searching: false,
            info: false,
            lengthChange: false,
            paging: false, // We use custom pagination
            order: [[0, 'desc']],
            columnDefs: [
                { orderable: false, targets: [1, 5, 6, 7] }
            ]
        });
    }
});

// Export to CSV function
function exportToCSV() {
    const table = document.getElementById('movementsTable');
    const rows = table.querySelectorAll('tr');
    const csv = [];
    
    // Get headers
    const headers = [];
    const headerCells = rows[0].querySelectorAll('th');
    headerCells.forEach(cell => {
        headers.push(cell.innerText);
    });
    csv.push(headers.join(','));
    
    // Get data rows
    for (let i = 1; i < rows.length; i++) {
        const row = rows[i];
        const cells = row.querySelectorAll('td');
        const rowData = [];
        
        cells.forEach(cell => {
            let text = cell.innerText.trim();
            // Remove HTML tags and clean
            text = text.replace(/,/g, ';'); // Replace commas to avoid CSV issues
            text = text.replace(/\n/g, ' '); // Replace newlines
            rowData.push(text);
        });
        
        csv.push(rowData.join(','));
    }
    
    // Download CSV
    const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'stock_movements_variant_<?php echo $variantId; ?>_<?php echo date('Y-m-d'); ?>.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// Auto-refresh data every 30 seconds (optional)
let autoRefresh = false;
function toggleAutoRefresh() {
    autoRefresh = !autoRefresh;
    if (autoRefresh) {
        setInterval(function() {
            location.reload();
        }, 30000);
    }
}
</script>

<style>
    .stock-movements-page .border-left-primary {
        border-left: 4px solid #4e73df;
    }
    
    .stock-movements-page .border-left-success {
        border-left: 4px solid #1cc88a;
    }
    
    .stock-movements-page .border-left-info {
        border-left: 4px solid #36b9cc;
    }
    
    .stock-movements-page .border-left-warning {
        border-left: 4px solid #f6c23e;
    }
    
    .stock-movements-page .table td {
        vertical-align: middle;
    }
    
    .stock-movements-page .badge {
        font-weight: 500;
        padding: 0.5em 0.85em;
    }
    
    .stock-movements-page .pagination {
        margin-bottom: 0;
    }
    
    .stock-movements-page .border {
        transition: all 0.2s;
    }
    
    .stock-movements-page .border:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }
</style>