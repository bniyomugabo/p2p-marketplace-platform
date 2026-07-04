<?php
// pages/reports/dashboard.php
declare(strict_types=1);

$pageTitle = 'Reports Dashboard - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';

// Load models
require_once __DIR__ . '/../../models/Sale.php';
require_once __DIR__ . '/../../models/Inventory.php';
require_once __DIR__ . '/../../models/Customer.php';
require_once __DIR__ . '/../../models/Product.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;
$companyCurrency = $_SESSION['company_currency'] ?? 'RWF';

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR', 'ACC', 'VIW'])) {
    SessionManager::flash('error', 'You do not have permission to view reports.');
    header('Location: ' . route_url('dashboard'));
    exit;
}

// Check if company context is set
if (!$companyId) {
    SessionManager::flash('error', 'Company context not found. Please log in again.');
    header('Location: ' . route_url('login'));
    exit;
}

// Get date range
$dateTo = date('Y-m-d');
$dateFrom = date('Y-m-d', strtotime('-30 days'));
$year = date('Y');
$month = date('m');

// Initialize models with company ID
$saleModel = new Sale($companyId);
$inventoryModel = new Inventory($companyId);
$customerModel = new Customer($companyId);
$productModel = new Product($companyId);

// Get summary data
$todaySales = $saleModel->getDailySummary($dateTo);
$monthlySales = $saleModel->getMonthlySummary($year, $month);
$salesByStatus = $saleModel->getSalesByStatus();
$topProducts = $saleModel->getTopProducts(10);
$inventorySummary = $inventoryModel->getStockSummary();
$customerSummary = $customerModel->getSummary();
$growthRate = $saleModel->getGrowthRate();

// Get inventory valuation
$valuationByCategory = $inventoryModel->getValuationByCategory();
$valuationByWarehouse = $inventoryModel->getValuationByWarehouse();

// Get low stock items
$lowStockItems = $inventoryModel->getLowStock(5);

// Get total receivables
$totalReceivables = $saleModel->getTotalReceivables();

// Get product stats
$productStats = $productModel->getStats();
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-chart-bar me-2"></i>Reports Dashboard
                    </h2>
                    <p class="mb-0 text-muted">Overview of your business performance</p>
                </div>
                <div class="btn-group">
                    <button type="button" class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-download me-2"></i>Export Report
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><h6 class="dropdown-header">Sales Reports</h6></li>
                        <li><a class="dropdown-item" href="?page=exports/sales&format=pdf" target="_blank">Sales Report (PDF)</a></li>
                        <li><a class="dropdown-item" href="?page=exports/sales&format=excel">Sales Report (Excel)</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">Inventory Reports</h6></li>
                        <li><a class="dropdown-item" href="?page=exports/inventory&format=pdf" target="_blank">Stock Report (PDF)</a></li>
                        <li><a class="dropdown-item" href="?page=exports/inventory&format=excel">Stock Report (Excel)</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Report Links -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow">
                <div class="card-body py-3">
                    <div class="d-flex flex-wrap gap-2">
                        <a href="?page=reports/sales" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-chart-line me-2"></i>Sales Report
                        </a>
                        <a href="?page=reports/inventory" class="btn btn-outline-success btn-sm">
                            <i class="fas fa-boxes me-2"></i>Inventory Report
                        </a>
                        <a href="?page=reports/financial" class="btn btn-outline-info btn-sm">
                            <i class="fas fa-coins me-2"></i>Financial Report
                        </a>
                        <a href="?page=reports/customers" class="btn btn-outline-warning btn-sm">
                            <i class="fas fa-users me-2"></i>Customer Report
                        </a>
                        <a href="?page=reports/products" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-box me-2"></i>Product Report
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Key Metrics Cards -->
    <div class="row">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Today's Sales</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo format_currency($todaySales['total_sales'] ?? 0); ?></div>
                            <div class="small text-muted"><?php echo number_format($todaySales['invoice_count'] ?? 0); ?> invoices</div>
                        </div>
                        <div class="col-auto"><i class="fas fa-calendar-day fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Monthly Sales</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo format_currency($monthlySales['total_sales'] ?? 0); ?></div>
                            <div class="small text-muted">Avg: <?php echo format_currency($monthlySales['avg_invoice'] ?? 0); ?></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-calendar-alt fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Inventory Value</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo format_currency($inventorySummary['stock_value'] ?? 0); ?></div>
                            <div class="small text-muted"><?php echo number_format((float) ($inventorySummary['total_stock'] ?? 0)); ?> units</div>
                        </div>
                        <div class="col-auto"><i class="fas fa-boxes fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Total Customers</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format((float) ($customerSummary['total_customers'] ?? 0)); ?></div>
                            <div class="small text-muted"><?php echo number_format($customerSummary['new_customers'] ?? 0); ?> new this month</div>
                        </div>
                        <div class="col-auto"><i class="fas fa-users fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Dynamic Graph Section -->
    <div class="row">
        <div class="col-12 mb-4">
            <!-- Sales Graph -->
            <?php 
            $graphTitle = 'Sales Performance';
            $canvasId = 'salesGraph';
            $graphType = 'sales';
            include __DIR__ . '/../../templates/graph-template.php'; 
            ?>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-4">
            <!-- Invoices Graph -->
            <?php 
            $graphTitle = 'Invoice Status Overview';
            $canvasId = 'invoicesGraph';
            $graphType = 'invoices';
            include __DIR__ . '/../../templates/graph-template.php'; 
            ?>
        </div>
        <div class="col-md-6 mb-4">
            <!-- Quotations Graph -->
            <?php 
            $graphTitle = 'Quotations Performance';
            $canvasId = 'quotationsGraph';
            $graphType = 'quotations';
            include __DIR__ . '/../../templates/graph-template.php'; 
            ?>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6 mb-4">
            <!-- Purchases Graph -->
            <?php 
            $graphTitle = 'Purchase Orders';
            $canvasId = 'purchasesGraph';
            $graphType = 'purchases';
            include __DIR__ . '/../../templates/graph-template.php'; 
            ?>
        </div>
        <div class="col-md-6 mb-4">
            <!-- Stock Status Graph -->
            <?php 
            $graphTitle = 'Stock Status by Category';
            $canvasId = 'stockGraph';
            $graphType = 'stock';
            include __DIR__ . '/../../templates/graph-template.php'; 
            ?>
        </div>
    </div>

    <!-- Two Column Tables -->
    <div class="row">
        <!-- Top Products -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow h-100">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-crown me-2"></i>Top Selling Products
                    </h6>
                    <a href="?page=reports/products" class="btn btn-sm btn-primary">View Details</a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr><th>Product</th><th class="text-end">Quantity</th><th class="text-end">Revenue</th></tr>
                            </thead>
                            <tbody>
                                <?php if (empty($topProducts)): ?>
                                    <tr><td colspan="3" class="text-center py-3">No data available</td></tr>
                                <?php else: ?>
                                    <?php foreach ($topProducts as $product): ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($product['product_name']); ?>
                                            <?php if (!empty($product['variant_name']) && $product['variant_name'] !== 'Standard'): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($product['variant_name']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end"><?php echo number_format((float) $product['total_sold'], 0); ?></td>
                                        <td class="text-end"><?php echo format_currency($product['total_revenue']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Low Stock Alert -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow h-100">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-exclamation-triangle me-2"></i>Low Stock Alert
                    </h6>
                    <a href="?page=inventory/stock" class="btn btn-sm btn-primary">View All</a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr><th>Product</th><th>Warehouse</th><th class="text-end">Stock</th><th class="text-end">Reorder</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <?php if (empty($lowStockItems)): ?>
                                    <tr><td colspan="5" class="text-center py-3"><i class="fas fa-check-circle text-success me-2"></i>All stock levels are healthy</td></tr>
                                <?php else: ?>
                                    <?php foreach ($lowStockItems as $item): ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($item['product_name']); ?>
                                            <?php if (!empty($item['variant_name'])): ?><br><small><?php echo htmlspecialchars($item['variant_name']); ?></small><?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($item['warehouse_name']); ?></td>
                                        <td class="text-end <?php echo ($item['quantity'] ?? 0) <= 0 ? 'text-danger' : 'text-warning'; ?>"><?php echo number_format((float) ($item['quantity'] ?? 0), 0); ?></td>
                                        <td class="text-end"><?php echo number_format((float) ($item['reorder_level'] ?? 0), 0); ?></td>
                                        <td><?php echo ($item['quantity'] ?? 0) <= 0 ? '<span class="badge bg-danger">Out of Stock</span>' : '<span class="badge bg-warning">Low Stock</span>'; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Inventory by Category -->
    <div class="row">
        <div class="col-12 mb-4">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-tags me-2"></i>Inventory by Category
                    </h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr><th>Category</th><th class="text-end">Products</th><th class="text-end">Quantity</th><th class="text-end">Value</th><th class="text-end">% of Total</th></tr>
                            </thead>
                            <tbody>
                                <?php
                                $totalCatValue = array_sum(array_column($valuationByCategory, 'total_value'));
                                foreach ($valuationByCategory as $cat):
                                    $percentage = $totalCatValue > 0 ? round(($cat['total_value'] / $totalCatValue) * 100, 1) : 0;
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($cat['category_name']); ?></td>
                                    <td class="text-end"><?php echo number_format((float) ($cat['variant_count'] ?? 0), 0); ?></td>
                                    <td class="text-end"><?php echo number_format((float) ($cat['total_quantity'] ?? 0), 0); ?></td>
                                    <td class="text-end"><?php echo format_currency($cat['total_value'] ?? 0); ?></td>
                                    <td class="text-end">
                                        <div class="d-flex align-items-center justify-content-end">
                                            <span class="me-2"><?php echo $percentage; ?>%</span>
                                            <div class="progress" style="width: 80px; height: 6px;"><div class="progress-bar bg-info" style="width: <?php echo $percentage; ?>%"></div></div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($valuationByCategory)): ?>
                                    <tr><td colspan="5" class="text-center py-3">No category data available</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Stats Row -->
    <div class="row">
        <div class="col-12 mb-4">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-chart-bar me-2"></i>Quick Statistics
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-2 col-sm-4 mb-3 text-center">
                            <div class="h4 mb-0"><?php echo number_format((float) ($inventorySummary['low_stock'] ?? 0)); ?></div>
                            <div class="small text-muted">Low Stock Items</div>
                        </div>
                        <div class="col-md-2 col-sm-4 mb-3 text-center">
                            <div class="h4 mb-0"><?php echo number_format((float) ($inventorySummary['out_of_stock'] ?? 0)); ?></div>
                            <div class="small text-muted">Out of Stock</div>
                        </div>
                        <div class="col-md-2 col-sm-4 mb-3 text-center">
                            <div class="h4 mb-0"><?php echo number_format((float) ($growthRate['rate'] ?? 0), 1); ?>%</div>
                            <div class="small text-muted">Growth Rate (MoM)</div>
                        </div>
                        <div class="col-md-2 col-sm-4 mb-3 text-center">
                            <div class="h4 mb-0"><?php echo format_currency($totalReceivables); ?></div>
                            <div class="small text-muted">Outstanding Receivables</div>
                        </div>
                        <div class="col-md-2 col-sm-4 mb-3 text-center">
                            <div class="h4 mb-0"><?php echo number_format((float) ($productStats['total_products'] ?? 0)); ?></div>
                            <div class="small text-muted">Total Products</div>
                        </div>
                        <div class="col-md-2 col-sm-4 mb-3 text-center">
                            <div class="h4 mb-0"><?php echo number_format((float) ($customerSummary['total_customers'] ?? 0)); ?></div>
                            <div class="small text-muted">Active Customers</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    companyCurrency = '<?php echo $companyCurrency; ?>';
</script>

<?php
// pages/reports/dashboard.php - Add this at the top with other declarations
$jsFiles = ['graph.js','reports/dashboard.js'];
?>

<style>
.border-left-primary { border-left: 4px solid #4e73df !important; }
.border-left-success { border-left: 4px solid #1cc88a !important; }
.border-left-info { border-left: 4px solid #36b9cc !important; }
.border-left-warning { border-left: 4px solid #f6c23e !important; }
.table td { vertical-align: middle; }
.progress { background-color: #eaecf4; }
@media (max-width: 768px) {
    .table-responsive { font-size: 0.85rem; }
    .h4, .h5 { font-size: 1rem; }
}
</style>