<?php
// pages/inventory/dashboard.php
declare(strict_types=1);

$pageTitle = 'Inventory Dashboard - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';

// Load models
require_once __DIR__ . '/../../models/Inventory.php';
require_once __DIR__ . '/../../models/Warehouse.php';
require_once __DIR__ . '/../../models/Product.php';
require_once __DIR__ . '/../../models/Category.php';

$db = Database::getInstance();
$userId = $_SESSION['user_id'] ?? 0;
$userRole = $_SESSION['user_role'] ?? 'VIW';
$companyId = $_SESSION['company_id'] ?? null;

// Initialize models with company context
$inventoryModel = new Inventory($companyId);
$warehouseModel = new Warehouse($companyId);
$productModel = new Product($companyId);
$categoryModel = new Category($companyId);

// Get inventory statistics (company-specific)
$stockSummary = $inventoryModel->getStockSummary();

// Get low stock items (company-specific)
$lowStock = $inventoryModel->getLowStock(50);

// Get out of stock items (company-specific)
$outOfStock = $inventoryModel->getOutOfStock();

// Get recent movements (company-specific)
$recentMovements = $inventoryModel->getMovements(null, null, 50);

// Get warehouse summary with inventory value (company-specific)
$warehouses = $warehouseModel->getAllWithInventoryValue();

// Get inventory turnover (company-specific)
$turnoverRate = $inventoryModel->getTurnoverRate();

// FIXED: Get top products by value - Use proper parameter binding
$topValueProducts = $db->prepare("
    SELECT 
        p.product_name,
        v.sku,
        v.variant_name,
        SUM(i.quantity * COALESCE(i.avg_landed_cost, v.purchase_price, 0)) as total_value,
        SUM(i.quantity) as total_quantity,
        AVG(COALESCE(i.avg_landed_cost, v.purchase_price, 0)) as avg_unit_cost
    FROM inventory i
    JOIN variants v ON i.variant_id = v.id
    JOIN products p ON v.product_id = p.id
    WHERE i.quantity > 0
        AND i.company_id = :p_1_company_id
        AND p.company_id = :p_2_company_id
    GROUP BY v.id
    ORDER BY total_value DESC
    LIMIT 50
");
$topValueProducts->execute(['p_1_company_id' => $companyId, 'p_2_company_id' => $companyId]);
$topValueProducts = $topValueProducts->fetchAll();

// FIXED: Get inventory quantity by category - Use proper parameter binding
$categoryQuantity = $db->prepare("
    SELECT 
        c.id,
        c.category_name,
        COALESCE(SUM(i.quantity), 0) as total_quantity,
        COUNT(DISTINCT v.id) as variant_count
    FROM categories c
    INNER JOIN category_company cc ON c.id = cc.category_id AND cc.company_id = :p_1_company_id
    LEFT JOIN products p ON c.id = p.category_id 
        AND p.company_id = :p_2_company_id
        AND p.is_active = 1
    LEFT JOIN variants v ON p.id = v.product_id AND v.is_active = 1
    LEFT JOIN inventory i ON v.id = i.variant_id AND i.company_id = :p_3_company_id
    WHERE cc.is_active = 1
    GROUP BY c.id
    HAVING total_quantity > 0
    ORDER BY total_quantity DESC
");
$categoryQuantity->execute(['p_1_company_id' => $companyId, 'p_2_company_id' => $companyId, 'p_3_company_id' => $companyId]);
$categoryQuantity = $categoryQuantity->fetchAll();

// Calculate total stock value for percentage calculations
$totalStockValue = $stockSummary['stock_value'] ?? 0;

// Calculate total quantity for percentage calculations
$totalQuantityAll = array_sum(array_column($categoryQuantity, 'total_quantity'));

// Prepare chart data
$catLabels = [];
$catQuantities = [];
$catColors = [];

foreach ($categoryQuantity as $index => $cat) {
    $catLabels[] = $cat['category_name'];
    $catQuantities[] = (float) $cat['total_quantity'];
    // Generate consistent color based on index
    $hue = ($index * 137) % 360;
    $catColors[] = "hsl({$hue}, 70%, 60%)";
}

// Get currency
$currency = $_SESSION['company_currency'] ?? 'RWF';

// Set JS files
$jsFiles = ['inventory/dashboard.js'];
?>

<!-- Pass data to JavaScript -->
<script>
    const currency = '<?php echo $currency; ?>';
    const categoryData = {
        labels: <?php echo json_encode($catLabels); ?>,
        quantities: <?php echo json_encode($catQuantities); ?>,
        colors: <?php echo json_encode($catColors); ?>
    };
    console.log('Category Data:', categoryData);
</script>

<div class="inventory-dashboard">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-warehouse me-2"></i>Inventory Dashboard
                    </h2>
                    <p class="mb-0 text-muted">Monitor stock levels, movements, and inventory health</p>
                </div>
                <div>
                    <a href="?page=inventory/stock" class="btn btn-primary me-2">
                        <i class="fas fa-boxes me-2"></i>View Stock
                    </a>
                    <a href="?page=inventory/adjustments" class="btn btn-warning">
                        <i class="fas fa-adjust me-2"></i>Adjust Stock
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Total Stock Value
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo format_currency($stockSummary['stock_value'] ?? 0, $companyId); ?>
                            </div>
                            <div class="mt-2 small text-muted">
                                <i
                                    class="fas fa-cubes me-1"></i><?php echo number_format((float) ($stockSummary['total_stock'] ?? 0), 0); ?>
                                total units
                            </div>
                            <div class="small text-muted">
                                <i
                                    class="fas fa-check-circle me-1"></i><?php echo number_format((float) ($stockSummary['available_stock'] ?? 0), 0); ?>
                                available
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
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Unique Products
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) ($stockSummary['unique_products'] ?? 0), 0); ?>
                            </div>
                            <div class="mt-2 small text-muted">
                                <i class="fas fa-boxes me-1"></i>products in stock
                            </div>
                            <div class="small text-muted">
                                <i
                                    class="fas fa-warehouse me-1"></i><?php echo number_format((float) ($stockSummary['warehouses_used'] ?? 0), 0); ?>
                                warehouses used
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-box fa-2x text-gray-300"></i>
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
                                Inventory Turnover
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) $turnoverRate, 2); ?>x
                            </div>
                            <div class="mt-2 small text-muted">
                                <i class="fas fa-chart-line me-1"></i>last 30 days
                            </div>
                            <?php if ($turnoverRate > 0): ?>
                                <div class="small text-muted">
                                    <i class="fas fa-calendar me-1"></i>~<?php echo round(30 / $turnoverRate); ?> days
                                    turnover
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-sync-alt fa-2x text-gray-300"></i>
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
                                Stock Alerts
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo ($stockSummary['low_stock'] ?? 0) + ($stockSummary['out_of_stock'] ?? 0); ?>
                            </div>
                            <div class="mt-2 small">
                                <span class="text-warning me-2"><?php echo $stockSummary['low_stock'] ?? 0; ?>
                                    low</span>
                                <span class="text-danger"><?php echo $stockSummary['out_of_stock'] ?? 0; ?> out</span>
                            </div>
                            <?php if (($stockSummary['low_stock'] ?? 0) > 0): ?>
                                <div class="small text-muted mt-1">
                                    <a href="?page=inventory/stock&filter=low" class="text-warning">View low stock</a>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-exclamation-triangle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row mb-4">
        <div class="col-lg-8">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-chart-pie me-2"></i>Stock Quantity by Category
                    </h6>
                </div>
                <div class="card-body chart-container">
                    <?php if (empty($categoryQuantity)): ?>
                        <div class="text-center py-5">
                            <div class="text-muted">
                                <i class="fas fa-chart-pie fa-4x mb-3"></i>
                                <p class="mb-0">No category data available</p>
                                <small>Add products to categories to see stock distribution</small>
                            </div>
                        </div>
                    <?php else: ?>
                        <canvas id="categoryChart" height="300"></canvas>
                        <div class="text-center mt-3">
                            <small class="text-muted">Total Stock: <?php echo number_format($totalQuantityAll); ?>
                                units</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-warehouse me-2"></i>Warehouse Summary
                    </h6>
                </div>
                <div class="card-body">
                    <?php if (empty($warehouses)): ?>
                        <div class="text-center py-4">
                            <div class="text-muted">
                                <i class="fas fa-warehouse fa-3x mb-3"></i>
                                <p class="mb-0">No warehouses configured</p>
                                <small>Add warehouses to track stock locations</small>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($warehouses as $wh): ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between">
                                    <span>
                                        <i class="fas fa-building me-1"></i>
                                        <?php echo htmlspecialchars($wh['warehouse_name']); ?>
                                        <?php if ($wh['is_main'] ?? false): ?>
                                            <span class="badge bg-primary ms-1">Main</span>
                                        <?php endif; ?>
                                    </span>
                                    <span
                                        class="fw-bold"><?php echo format_currency($wh['total_value'] ?? 0, $companyId); ?></span>
                                </div>
                                <div class="progress mt-1" style="height: 5px;">
                                    <?php
                                    $percentage = $totalStockValue > 0
                                        ? (($wh['total_value'] ?? 0) / $totalStockValue) * 100
                                        : 0;
                                    ?>
                                    <div class="progress-bar bg-info" style="width: <?php echo $percentage; ?>%"></div>
                                </div>
                                <div class="small text-muted mt-1">
                                    <?php echo number_format((float) ($wh['product_count'] ?? 0), 0); ?> products |
                                    <?php echo number_format((float) ($wh['total_quantity'] ?? 0), 0); ?> units
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Alerts and Movements with Fixed Heights -->
    <div class="row">
        <!-- Low Stock Alerts -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow h-100">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>Low Stock Alerts
                        <span class="badge bg-warning ms-2"><?php echo count($lowStock); ?></span>
                    </h6>
                    <a href="?page=inventory/stock&filter=low" class="btn btn-sm btn-link">View All</a>
                </div>
                <div class="card-body p-0 scrollable-list" style="max-height: 400px; overflow-y: auto;">
                    <?php if (empty($lowStock)): ?>
                        <div class="text-center py-4">
                            <div class="text-success mb-2">
                                <i class="fas fa-check-circle fa-3x"></i>
                            </div>
                            <p class="mb-0 text-muted">No low stock items</p>
                            <small class="text-muted">All inventory levels are healthy</small>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($lowStock as $item): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <div class="d-flex align-items-center mb-1">
                                                <strong><?php echo htmlspecialchars($item['product_name'] ?? ''); ?></strong>
                                                <?php if (!empty($item['variant_name']) && $item['variant_name'] !== 'Standard'): ?>
                                                    <span
                                                        class="badge bg-secondary ms-2"><?php echo htmlspecialchars($item['variant_name']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted">
                                                SKU: <?php echo htmlspecialchars($item['sku'] ?? ''); ?> |
                                                <?php echo htmlspecialchars($item['warehouse_name'] ?? ''); ?>
                                            </small>
                                        </div>
                                        <div class="text-end ms-3">
                                            <span class="badge bg-warning text-dark">
                                                <?php echo number_format((float) ($item['available_quantity'] ?? $item['quantity'] ?? 0), 0); ?>
                                                left
                                            </span>
                                            <br>
                                            <small class="text-muted">Reorder:
                                                <?php echo $item['reorder_level'] ?? 0; ?></small>
                                        </div>
                                    </div>
                                    <?php if (($item['available_quantity'] ?? 0) <= ($item['reorder_level'] ?? 0) / 2): ?>
                                        <div class="mt-2">
                                            <div class="progress" style="height: 3px;">
                                                <div class="progress-bar bg-danger"
                                                    style="width: <?php echo (($item['available_quantity'] ?? 0) / ($item['reorder_level'] ?? 1)) * 100; ?>%">
                                                </div>
                                            </div>
                                            <small class="text-danger">Critical low stock!</small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if (count($lowStock) > 8): ?>
                    <div class="card-footer bg-light text-center py-2">
                        <small class="text-muted">Showing <?php echo count($lowStock); ?> low stock items</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Movements -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow h-100">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-info">
                        <i class="fas fa-exchange-alt me-2"></i>Recent Movements
                        <span class="badge bg-info ms-2"><?php echo count($recentMovements); ?></span>
                    </h6>
                    <a href="?page=inventory/movements" class="btn btn-sm btn-link">View All</a>
                </div>
                <div class="card-body p-0 scrollable-list" style="max-height: 400px; overflow-y: auto;">
                    <?php if (empty($recentMovements)): ?>
                        <div class="text-center py-4">
                            <div class="text-muted mb-2">
                                <i class="fas fa-exchange-alt fa-3x"></i>
                            </div>
                            <p class="mb-0 text-muted">No recent movements</p>
                            <small>Stock adjustments will appear here</small>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($recentMovements as $movement): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <span class="badge bg-<?php
                                            echo $movement['transaction_type'] == 'purchase' ? 'success' :
                                                ($movement['transaction_type'] == 'sale' ? 'primary' :
                                                    ($movement['transaction_type'] == 'adjustment' ? 'warning' : 'info'));
                                            ?> mb-1">
                                                <?php echo ucfirst($movement['transaction_type']); ?>
                                            </span>
                                            <br>
                                            <strong><?php echo htmlspecialchars($movement['product_name'] ?? ''); ?></strong>
                                            <?php if (!empty($movement['variant_name']) && $movement['variant_name'] !== 'Standard'): ?>
                                                <br><small
                                                    class="text-muted"><?php echo htmlspecialchars($movement['variant_name']); ?></small>
                                            <?php endif; ?>
                                            <br>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($movement['sku'] ?? ''); ?> -
                                                <?php echo htmlspecialchars($movement['warehouse_name'] ?? ''); ?>
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <span
                                                class="fw-bold <?php echo $movement['quantity'] > 0 ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo $movement['quantity'] > 0 ? '+' : ''; ?>
                                                <?php echo number_format((float) $movement['quantity'], 0); ?>
                                            </span>
                                            <br>
                                            <small class="text-muted"
                                                title="<?php echo htmlspecialchars($movement['created_at']); ?>">
                                                <?php echo time_ago($movement['created_at']); ?>
                                            </small>
                                            <?php if (!empty($movement['created_by_name'])): ?>
                                                <br><small class="text-muted">by
                                                    <?php echo htmlspecialchars($movement['created_by_name']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if (count($recentMovements) > 8): ?>
                    <div class="card-footer bg-light text-center py-2">
                        <small class="text-muted">Showing last <?php echo count($recentMovements); ?> movements</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Top Value Products with Fixed Height -->
    <div class="row">
        <div class="col-12">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-crown me-2"></i>Top Products by Inventory Value
                        <span class="badge bg-primary ms-2">Top <?php echo count($topValueProducts); ?></span>
                    </h6>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($topValueProducts)): ?>
                        <div class="text-center py-5">
                            <div class="text-muted">
                                <i class="fas fa-chart-line fa-4x mb-3"></i>
                                <p class="mb-0">No inventory data available</p>
                                <small>Add products and stock to see top value items</small>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive scrollable-table" style="max-height: 500px; overflow-y: auto;">
                            <table class="table table-hover mb-0">
                                <thead class="table-light sticky-top">
                                    <tr>
                                        <th style="position: sticky; top: 0; background: #f8f9fc;">Product</th>
                                        <th style="position: sticky; top: 0; background: #f8f9fc;">SKU</th>
                                        <th style="position: sticky; top: 0; background: #f8f9fc;">Variant</th>
                                        <th class="text-end" style="position: sticky; top: 0; background: #f8f9fc;">Quantity
                                        </th>
                                        <th class="text-end" style="position: sticky; top: 0; background: #f8f9fc;">Avg Unit
                                            Cost</th>
                                        <th class="text-end" style="position: sticky; top: 0; background: #f8f9fc;">Total
                                            Value</th>
                                        <th style="position: sticky; top: 0; background: #f8f9fc;">% of Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $rank = 1;
                                    foreach ($topValueProducts as $product):
                                        ?>
                                        <tr>
                                            <td>
                                                <?php if ($rank <= 3): ?>
                                                    <i class="fas fa-medal text-warning me-1"></i>
                                                <?php endif; ?>
                                                <strong><?php echo htmlspecialchars($product['product_name']); ?></strong>
                            </div>
                        </div>
                    </div>
                    <td class="text-center"><code><?php echo htmlspecialchars($product['sku']); ?></code></td>
                    <td>
                        <?php echo htmlspecialchars($product['variant_name']); ?>
                        <?php if ($product['variant_name'] === 'Standard'): ?>
                            <span class="badge bg-secondary ms-1">Default</span>
                        <?php endif; ?>
                </div>
            </div>
            <td class="text-end"><?php echo number_format((float) $product['total_quantity'], 0); ?>
        </div>
        </div>
        <td class="text-end"><?php echo format_currency($product['avg_unit_cost'] ?? 0, $companyId); ?> </div>
            </div>
        <td class="text-end fw-bold text-success"><?php echo format_currency($product['total_value'], $companyId); ?> </div>
            </div>
        <td class="text-center">
            <div class="d-flex align-items-center">
                <div class="progress flex-grow-1" style="height: 5px;">
                    <div class="progress-bar bg-success"
                        style="width: <?php echo $totalStockValue > 0 ? ($product['total_value'] / $totalStockValue) * 100 : 0; ?>%">
                    </div>
                </div>
                <span class="ms-2 small">
                    <?php echo $totalStockValue > 0 ? number_format(($product['total_value'] / $totalStockValue) * 100, 1) : 0; ?>%
                </span>
            </div>
            </div>
            </div>
            <?php
            $rank++;
                                    endforeach;
                                    ?>
        </tbody>
        </div>
        </div>
    <?php endif; ?>
    </div>
    <?php if (count($topValueProducts) > 10): ?>
        <div class="card-footer bg-light text-center py-2">
            <small class="text-muted">Showing top <?php echo count($topValueProducts); ?> products by value</small>
        </div>
    <?php endif; ?>
    </div>
    </div>
    </div>

    <style>
        .chart-container {
            position: relative;
            height: 300px;
            /* Or whatever height you want */
            width: 100%;
        }

        .inventory-dashboard .border-left-primary {
            border-left: 4px solid #4e73df !important;
        }

        .inventory-dashboard .border-left-success {
            border-left: 4px solid #1cc88a !important;
        }

        .inventory-dashboard .border-left-info {
            border-left: 4px solid #36b9cc !important;
        }

        .inventory-dashboard .border-left-warning {
            border-left: 4px solid #f6c23e !important;
        }

        .inventory-dashboard .card {
            border: none;
            border-radius: 0.75rem;
            transition: transform 0.2s ease;
        }

        .inventory-dashboard .card:hover {
            transform: translateY(-2px);
        }

        .inventory-dashboard .progress {
            border-radius: 10px;
            background-color: #e9ecef;
        }

        .inventory-dashboard .list-group-item {
            border-left: none;
            border-right: none;
            padding: 0.75rem 1rem;
            transition: background-color 0.2s ease;
        }

        .inventory-dashboard .list-group-item:hover {
            background-color: #f8f9fc;
        }

        .inventory-dashboard .list-group-item:first-child {
            border-top: none;
        }

        .inventory-dashboard .list-group-item:last-child {
            border-bottom: none;
        }

        .inventory-dashboard .badge {
            font-weight: 500;
            padding: 0.35em 0.65em;
        }

        /* Scrollable list styles */
        .inventory-dashboard .scrollable-list {
            scrollbar-width: thin;
            scrollbar-color: #cbd5e0 #f1f1f1;
        }

        .inventory-dashboard .scrollable-list::-webkit-scrollbar {
            width: 6px;
        }

        .inventory-dashboard .scrollable-list::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        .inventory-dashboard .scrollable-list::-webkit-scrollbar-thumb {
            background: #cbd5e0;
            border-radius: 10px;
        }

        .inventory-dashboard .scrollable-list::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Scrollable table styles */
        .inventory-dashboard .scrollable-table {
            scrollbar-width: thin;
            scrollbar-color: #cbd5e0 #f1f1f1;
        }

        .inventory-dashboard .scrollable-table::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        .inventory-dashboard .scrollable-table::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        .inventory-dashboard .scrollable-table::-webkit-scrollbar-thumb {
            background: #cbd5e0;
            border-radius: 10px;
        }

        .inventory-dashboard .scrollable-table::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        /* Sticky header for table */
        .inventory-dashboard .sticky-top {
            position: sticky;
            top: 0;
            z-index: 10;
        }

        /* Medal colors for top 3 */
        .fa-medal {
            font-size: 0.9rem;
        }
    </style>