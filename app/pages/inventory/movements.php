<?php
// pages/inventory/movements.php
declare(strict_types=1);

$pageTitle = 'Stock Movements - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';

// Load models
require_once __DIR__ . '/../../models/Inventory.php';
require_once __DIR__ . '/../../models/Warehouse.php';
require_once __DIR__ . '/../../models/Product.php';
require_once __DIR__ . '/../../models/Variant.php';

$db = Database::getInstance();
$userId = $_SESSION['user_id'] ?? 0;
$userRole = $_SESSION['user_role'] ?? 'VIW';
$companyId = $_SESSION['company_id'] ?? null;

// Initialize models with company context
$inventoryModel = new Inventory($companyId);
$warehouseModel = new Warehouse($companyId);
$productModel = new Product($companyId);
$variantModel = new Variant($companyId);

// Get filter parameters
$variantId = isset($_GET['variant_id']) ? (int) $_GET['variant_id'] : null;
$warehouseId = isset($_GET['warehouse_id']) ? (int) $_GET['warehouse_id'] : null;
$typeFilter = $_GET['type'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Get variant details if filter is applied
$variantDetails = null;
if ($variantId) {
    $variantDetails = $variantModel->getWithDetails($variantId);
}

// Get warehouses for filter (only this company's warehouses)
$warehouses = $warehouseModel->all(['id', 'warehouse_name', 'warehouse_code'], 'is_active = 1');

// Get total count for pagination
$totalMovements = $inventoryModel->getMovementsCount($variantId, $warehouseId);
$totalPages = ceil($totalMovements / $limit);

// Get movements with pagination
$movements = $inventoryModel->getMovements($variantId, $warehouseId, $limit, $offset);

// Calculate summary statistics
$summary = [
    'total_in' => 0,
    'total_out' => 0,
    'total_adjustments' => 0,
    'total_purchases' => 0,
    'total_sales' => 0,
    'total_returns' => 0,
    'total_transfers' => 0,
    'total_value_in' => 0,
    'total_value_out' => 0
];

foreach ($movements as $movement) {
    if ($movement['quantity'] > 0) {
        $summary['total_in'] += $movement['quantity'];
        $summary['total_value_in'] += $movement['quantity'] * ($movement['unit_cost'] ?? 0);
    } else {
        $summary['total_out'] += abs($movement['quantity']);
        $summary['total_value_out'] += abs($movement['quantity']) * ($movement['unit_cost'] ?? 0);
    }

    switch ($movement['transaction_type']) {
        case 'purchase':
            $summary['total_purchases']++;
            break;
        case 'sale':
            $summary['total_sales']++;
            break;
        case 'return':
            $summary['total_returns']++;
            break;
        case 'adjustment':
            $summary['total_adjustments']++;
            break;
        case 'transfer':
            $summary['total_transfers']++;
            break;
    }
}
?>

<div class="stock-movements">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <a href="?page=inventory/stock" class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Stock
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-history me-2"></i>Stock Movements
                    </h2>
                    <p class="mb-0 text-muted">Track all inventory transactions and changes</p>
                    <?php if ($variantDetails): ?>
                            <p class="mb-0 text-muted mt-1">
                                <strong>Filtering by:</strong> 
                                <?php echo htmlspecialchars($variantDetails['product_name']); ?>
                                <?php if ($variantDetails['variant_name'] && $variantDetails['variant_name'] !== 'Standard'): ?>
                                        - <?php echo htmlspecialchars($variantDetails['variant_name']); ?>
                                <?php endif; ?>
                                (SKU: <?php echo htmlspecialchars($variantDetails['sku']); ?>)
                            </p>
                    <?php endif; ?>
                </div>
                <div>
                    <button type="button" class="btn btn-success me-2" onclick="exportToCSV()">
                        <i class="fas fa-file-excel me-2"></i>Export
                    </button>
                    <a href="?page=inventory/adjustments" class="btn btn-warning">
                        <i class="fas fa-adjust me-2"></i>Adjust Stock
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Total Movements
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo $totalMovements; ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-exchange-alt fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Total Inflow
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($summary['total_in'], 0); ?> units
                            </div>
                            <div class="small text-muted">
                                Value: <?php echo format_currency($summary['total_value_in'], $companyId); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-arrow-down fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-danger shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">
                                Total Outflow
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($summary['total_out'], 0); ?> units
                            </div>
                            <div class="small text-muted">
                                Value: <?php echo format_currency($summary['total_value_out'], $companyId); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-arrow-up fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                Net Movement
                            </div>
                            <div class="h5 mb-0 font-weight-bold <?php echo ($summary['total_in'] - $summary['total_out']) >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo number_format($summary['total_in'] - $summary['total_out'], 0); ?> units
                            </div>
                            <div class="small text-muted">
                                <?php echo $summary['total_purchases']; ?> purchases | <?php echo $summary['total_sales']; ?> sales
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-chart-line fa-2x text-gray-300"></i>
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
                <input type="hidden" name="page" value="inventory/movements">
                <?php if ($variantId): ?>
                        <input type="hidden" name="variant_id" value="<?php echo $variantId; ?>">
                <?php endif; ?>
                
                <div class="col-md-3">
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
                    <button type="submit" class="btn btn-primary w-100 me-2">
                        <i class="fas fa-search me-2"></i>Apply Filters
                    </button>
                    <a href="?page=inventory/movements<?php echo $variantId ? '&variant_id=' . $variantId : ''; ?>" 
                       class="btn btn-secondary w-100">
                        <i class="fas fa-undo me-2"></i>Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Movements Table -->
    <div class="card shadow">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-list me-2"></i>Movement History
                <span class="badge bg-primary ms-2"><?php echo $totalMovements; ?> records</span>
            </h6>
            <div>
                <button class="btn btn-sm btn-outline-primary" onclick="refreshTable()">
                    <i class="fas fa-sync-alt me-1"></i>Refresh
                </button>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="movementsTable">
                    <thead class="table-light">
                        <tr>
                            <th>Date & Time</th>
                            <th>Transaction Code</th>
                            <th>Type</th>
                            <th>Product</th>
                            <th>Variant</th>
                            <th>Warehouse</th>
                            <th class="text-end">Quantity</th>
                            <th class="text-end">Unit Cost</th>
                            <th class="text-end">Total Value</th>
                            <th>Reference</th>
                            <th>Created By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($movements)): ?>
                                <tr>
                                    <td colspan="12" class="text-center py-4">
                                        <div class="text-muted">
                                            <i class="fas fa-exchange-alt fa-3x mb-3"></i>
                                            <p class="mb-0">No movements found</p>
                                            <small>Try adjusting your filters or create a stock adjustment</small>
                                        </div>
                                    </td>
                                </tr>
                        <?php else: ?>
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
                                                <strong><?php echo htmlspecialchars($movement['product_name']); ?></strong>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($movement['variant_name']); ?>
                                                <?php if ($movement['variant_name'] === 'Standard'): ?>
                                                        <span class="badge bg-secondary ms-1">Default</span>
                                                <?php endif; ?>
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
                                                    <?php echo number_format(abs((float) $movement['quantity']), 0); ?>
                                                </strong>
                                            </td>
                                            <td class="text-end">
                                                <?php echo format_currency($movement['unit_cost'] ?? 0, $companyId); ?>
                                            </td>
                                            <td class="text-end">
                                                <strong>
                                                    <?php echo format_currency(abs((float) $movement['quantity']) * ($movement['unit_cost'] ?? 0), $companyId); ?>
                                                </strong>
                                            </td>
                                            <td>
                                                <?php if ($movement['reference_type'] && $movement['reference_id']): ?>
                                                        <a href="?page=<?php echo $movement['reference_type']; ?>/view&id=<?php echo $movement['reference_id']; ?>" 
                                                           class="text-decoration-none" title="View Reference">
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
                                                <small>
                                                    <i class="fas fa-user me-1"></i>
                                                    <?php echo htmlspecialchars($movement['created_by_name'] ?? 'System'); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-outline-info" 
                                                        onclick="showMovementDetails(<?php echo htmlspecialchars(json_encode($movement)); ?>)"
                                                        title="View Details">
                                                    <i class="fas fa-info-circle"></i>
                                                </button>
                                            </td>
                                        </tr>
                                <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                    <nav aria-label="Page navigation" class="mt-4">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=inventory/movements&variant_id=<?php echo $variantId; ?>&warehouse_id=<?php echo $warehouseId; ?>&type=<?php echo urlencode($typeFilter); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>&page=<?php echo $page - 1; ?>">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </a>
                            </li>
                        
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);

                            if ($startPage > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=inventory/movements&variant_id=<?php echo $variantId; ?>&warehouse_id=<?php echo $warehouseId; ?>&type=<?php echo urlencode($typeFilter); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>&page=1">1</a>
                                    </li>
                                    <?php if ($startPage > 2): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                            <?php endif; ?>
                        
                            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=inventory/movements&variant_id=<?php echo $variantId; ?>&warehouse_id=<?php echo $warehouseId; ?>&type=<?php echo urlencode($typeFilter); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>&page=<?php echo $i; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                            <?php endfor; ?>
                        
                            <?php if ($endPage < $totalPages): ?>
                                    <?php if ($endPage < $totalPages - 1): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=inventory/movements&variant_id=<?php echo $variantId; ?>&warehouse_id=<?php echo $warehouseId; ?>&type=<?php echo urlencode($typeFilter); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>&page=<?php echo $totalPages; ?>">
                                            <?php echo $totalPages; ?>
                                        </a>
                                    </li>
                            <?php endif; ?>
                        
                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=inventory/movements&variant_id=<?php echo $variantId; ?>&warehouse_id=<?php echo $warehouseId; ?>&type=<?php echo urlencode($typeFilter); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>&page=<?php echo $page + 1; ?>">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Movement Details Modal -->
<div class="modal fade" id="movementDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="fas fa-info-circle me-2"></i>Movement Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="movementDetailsContent">
                <!-- Dynamic content -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
function showMovementDetails(movement) {
    const modalContent = document.getElementById('movementDetailsContent');
    
    const typeConfig = {
        'purchase': { class: 'success', icon: 'fa-shopping-cart', text: 'Purchase' },
        'sale': { class: 'primary', icon: 'fa-tag', text: 'Sale' },
        'return': { class: 'warning', icon: 'fa-undo', text: 'Return' },
        'adjustment': { class: 'info', icon: 'fa-adjust', text: 'Adjustment' },
        'transfer': { class: 'secondary', icon: 'fa-exchange-alt', text: 'Transfer' }
    };
    const config = typeConfig[movement.transaction_type] || { class: 'secondary', icon: 'fa-question', text: movement.transaction_type };
    
    modalContent.innerHTML = `
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Transaction Information</h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr>
                                <th style="width: 40%">Transaction Code:</th>
                                <td><code>${escapeHtml(movement.transaction_code)}</code></td>
                            </tr>
                            <tr>
                                <th>Transaction Type:</th>
                                <td><span class="badge bg-${config.class}"><i class="fas ${config.icon} me-1"></i> ${config.text}</span></td>
                            </tr>
                            <tr>
                                <th>Date & Time:</th>
                                <td>${new Date(movement.created_at).toLocaleString()}</td>
                            </tr>
                            <tr>
                                <th>Created By:</th>
                                <td>${escapeHtml(movement.created_by_name || 'System')}</td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-box me-2"></i>Product Information</h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr>
                                <th style="width: 40%">Product:</th>
                                <td><strong>${escapeHtml(movement.product_name)}</strong></td>
                            </tr>
                            <tr>
                                <th>Variant:</th>
                                <td>${escapeHtml(movement.variant_name)}</td>
                            </tr>
                            <tr>
                                <th>SKU:</th>
                                <td><code>${escapeHtml(movement.sku)}</code></td>
                            </tr>
                            <tr>
                                <th>Warehouse:</th>
                                <td>${escapeHtml(movement.warehouse_name)} ${movement.warehouse_code ? '(' + movement.warehouse_code + ')' : ''}</td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-chart-line me-2"></i>Quantity & Cost</h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr>
                                <th style="width: 40%">Quantity:</th>
                                <td class="${movement.quantity > 0 ? 'text-success' : 'text-danger'} fw-bold">
                                    ${movement.quantity > 0 ? '+' : ''}${formatNumber(Math.abs(movement.quantity))} units
                                </td>
                            </tr>
                            <tr>
                                <th>Unit Cost:</th>
                                <td>${formatCurrency(movement.unit_cost)}</td>
                            </tr>
                            <tr>
                                <th>Total Value:</th>
                                <td class="fw-bold">${formatCurrency(Math.abs(movement.quantity) * (movement.unit_cost || 0))}</td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-link me-2"></i>Reference</h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr>
                                <th style="width: 40%">Reference Type:</th>
                                <td>${movement.reference_type ? movement.reference_type.charAt(0).toUpperCase() + movement.reference_type.slice(1) : '-'}</td>
                            </tr>
                            <tr>
                                <th>Reference ID:</th>
                                <td>${movement.reference_id ? '#' + movement.reference_id : '-'}</td>
                            </tr>
                            <tr>
                                <th>Location:</th>
                                <td>${movement.location_code ? movement.location_code : '-'}</td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        ${movement.notes ? `
        <div class="card">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="fas fa-sticky-note me-2"></i>Notes</h6>
            </div>
            <div class="card-body">
                <p class="mb-0">${escapeHtml(movement.notes)}</p>
            </div>
        </div>
        ` : ''}
    `;
    
    new bootstrap.Modal(document.getElementById('movementDetailsModal')).show();
}

function refreshTable() {
    window.location.reload();
}

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
        if (cells.length > 0 && cells[0].getAttribute('colspan') !== '12') {
            const rowData = [];
            cells.forEach(cell => {
                let text = cell.innerText.trim();
                text = text.replace(/,/g, ';');
                text = text.replace(/\n/g, ' ');
                rowData.push(text);
            });
            csv.push(rowData.join(','));
        }
    }
    
    // Download CSV
    const blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `stock_movements_${new Date().toISOString().slice(0, 19)}.csv`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function formatNumber(num) {
    return new Intl.NumberFormat().format(num);
}

function formatCurrency(amount) {
    const currency = '<?php echo $_SESSION['company_currency'] ?? 'RWF'; ?>';
    return currency + ' ' + new Intl.NumberFormat().format(amount);
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Initialize DataTable if available
$(document).ready(function() {
    if ($.fn.DataTable && $('#movementsTable tbody tr').length > 10) {
        $('#movementsTable').DataTable({
            pageLength: 25,
            order: [[0, 'desc']],
            language: {
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                emptyTable: "No movements found"
            },
            columnDefs: [
                { orderable: false, targets: [1, 9, 10, 11] }
            ],
            searching: false,
            paging: false
        });
    }
});
</script>

<style>
    .stock-movements .table td {
        vertical-align: middle;
    }
    
    .stock-movements .badge {
        font-weight: 500;
        padding: 0.5em 0.85em;
    }
    
    .stock-movements .border-left-primary {
        border-left: 4px solid #4e73df !important;
    }
    
    .stock-movements .border-left-success {
        border-left: 4px solid #1cc88a !important;
    }
    
    .stock-movements .border-left-danger {
        border-left: 4px solid #e74a3b !important;
    }
    
    .stock-movements .border-left-info {
        border-left: 4px solid #36b9cc !important;
    }
    
    .stock-movements .card {
        transition: transform 0.2s ease;
    }
    
    .stock-movements .card:hover {
        transform: translateY(-2px);
    }
    
    .stock-movements .pagination {
        margin-bottom: 0;
    }
    
    .table-responsive {
        overflow-x: auto;
    }
    
    @media (max-width: 768px) {
        .table td, .table th {
            white-space: nowrap;
        }
    }
</style>