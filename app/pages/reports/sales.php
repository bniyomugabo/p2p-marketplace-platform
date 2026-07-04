<?php
// pages/reports/sales.php
declare(strict_types=1);

$pageTitle = 'Sales Report - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Sale.php';
require_once __DIR__ . '/../../models/Customer.php';
require_once __DIR__ . '/../../models/Product.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR', 'ACC', 'VIW'])) {
    SessionManager::flash('error', 'You do not have permission to view sales reports.');
    header('Location: ' . route_url('reports'));
    exit;
}

// Initialize models with company context
$saleModel = new Sale($companyId);
$customerModel = new Customer($companyId);

// Get filter parameters
$period = $_GET['period'] ?? 'month';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$customerId = isset($_GET['customer_id']) ? (int) $_GET['customer_id'] : null;
$status = $_GET['status'] ?? '';

// Set date range based on period
switch ($period) {
    case 'today':
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d');
        break;
    case 'week':
        $startDate = date('Y-m-d', strtotime('monday this week'));
        $endDate = date('Y-m-d', strtotime('sunday this week'));
        break;
    case 'month':
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-t');
        break;
    case 'quarter':
        $quarter = ceil(date('n') / 3);
        $startDate = date('Y-' . (($quarter - 1) * 3 + 1) . '-01');
        $endDate = date('Y-m-t', strtotime($startDate . ' +2 months'));
        break;
    case 'year':
        $startDate = date('Y-01-01');
        $endDate = date('Y-12-31');
        break;
    case 'custom':
        if (!$startDate || !$endDate) {
            $startDate = date('Y-m-01');
            $endDate = date('Y-m-t');
        }
        break;
    default:
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-t');
}

// Get sales summary (company-specific)
$summary = $saleModel->getSalesSummary($startDate, $endDate, $customerId, $status);

// Get daily breakdown (company-specific)
$dailySales = $saleModel->getDailySalesRange($startDate, $endDate, $customerId, $status);

// Get top products (company-specific)
$topProducts = $saleModel->getTopProductsByDateRange($startDate, $endDate, 20);

// Get sales by payment method (company-specific)
$paymentMethods = $saleModel->getSalesByPaymentMethod($startDate, $endDate);

// Get customers for filter (only this company's customers)
$customers = $customerModel->all(['id', 'full_name', 'customer_code'], 'is_active = 1');

// Get previous period for comparison
$daysDiff = (strtotime($endDate) - strtotime($startDate)) / (60 * 60 * 24);
$previousStart = date('Y-m-d', strtotime($startDate . ' -' . ($daysDiff + 1) . ' days'));
$previousEnd = date('Y-m-d', strtotime($startDate . ' -1 day'));
$previousSummary = $saleModel->getSalesSummary($previousStart, $previousEnd);

// Calculate growth
$growth = 0;
if ($previousSummary['total_sales'] > 0) {
    $growth = (($summary['total_sales'] - $previousSummary['total_sales']) / $previousSummary['total_sales']) * 100;
}

// Get currency
$currency = $_SESSION['company_currency'] ?? 'RWF';

// Set JS files
$jsFiles = ['reports/sales.js'];
?>

<!-- Pass data to JavaScript -->
<script>
    const currency = '<?php echo $currency; ?>';
    const dailyData = <?php echo json_encode($dailySales); ?>;
    const paymentData = <?php echo json_encode($paymentMethods); ?>;
</script>

<div class="sales-report">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <a href="<?php echo route_url('reports'); ?>" class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Reports
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-chart-line me-2"></i>Sales Report
                    </h2>
                    <p class="mb-0 text-muted">Analyze your sales performance</p>
                </div>
                <div class="btn-group mt-2 mt-sm-0">
                    <button type="button" class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-download me-2"></i>Export
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="?page=exports/sales&format=pdf&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>" target="_blank"><i class="fas fa-file-pdf me-2"></i>PDF</a></li>
                        <li><a class="dropdown-item" href="?page=exports/sales&format=excel&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>"><i class="fas fa-file-excel me-2"></i>Excel</a></li>
                        <li><a class="dropdown-item" href="?page=exports/sales&format=csv&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>"><i class="fas fa-file-csv me-2"></i>CSV</a></li>
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
                <input type="hidden" name="page" value="reports/sales">
                
                <div class="col-md-2">
                    <label class="form-label">Period</label>
                    <select class="form-control" name="period" id="period" onchange="toggleCustomDates()">
                        <option value="today" <?php echo $period === 'today' ? 'selected' : ''; ?>>Today</option>
                        <option value="week" <?php echo $period === 'week' ? 'selected' : ''; ?>>This Week</option>
                        <option value="month" <?php echo $period === 'month' ? 'selected' : ''; ?>>This Month</option>
                        <option value="quarter" <?php echo $period === 'quarter' ? 'selected' : ''; ?>>This Quarter</option>
                        <option value="year" <?php echo $period === 'year' ? 'selected' : ''; ?>>This Year</option>
                        <option value="custom" <?php echo $period === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                    </select>
                </div>
                
                <div class="col-md-2 custom-date" style="<?php echo $period !== 'custom' ? 'display: none;' : ''; ?>">
                    <label class="form-label">Start Date</label>
                    <input type="date" class="form-control" name="start_date" value="<?php echo $startDate; ?>">
                </div>
                
                <div class="col-md-2 custom-date" style="<?php echo $period !== 'custom' ? 'display: none;' : ''; ?>">
                    <label class="form-label">End Date</label>
                    <input type="date" class="form-control" name="end_date" value="<?php echo $endDate; ?>">
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Customer</label>
                    <select class="form-control" name="customer_id">
                        <option value="">All Customers</option>
                        <?php foreach ($customers as $cust): ?>
                            <option value="<?php echo $cust['id']; ?>" <?php echo $customerId == $cust['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cust['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select class="form-control" name="status">
                        <option value="">All Status</option>
                        <option value="paid" <?php echo $status === 'paid' ? 'selected' : ''; ?>>✅ Paid</option>
                        <option value="partial" <?php echo $status === 'partial' ? 'selected' : ''; ?>>🔄 Partial</option>
                        <option value="issued" <?php echo $status === 'issued' ? 'selected' : ''; ?>>📄 Issued</option>
                        <option value="overdue" <?php echo $status === 'overdue' ? 'selected' : ''; ?>>⚠️ Overdue</option>
                        <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>❌ Cancelled</option>
                    </select>
                </div>
                
                <div class="col-md-2 d-flex align-items-end">
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
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Sales</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo format_currency($summary['total_sales'] ?? 0, $companyId); ?></div>
                            <div class="small <?php echo $growth >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <i class="fas <?php echo $growth >= 0 ? 'fa-arrow-up' : 'fa-arrow-down'; ?> me-1"></i>
                                <?php echo number_format((float)(float)abs($growth), 1); ?>% vs previous period
                            </div>
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
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Invoices</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format((float)(float)($summary['total_invoices'] ?? 0)); ?></div>
                            <div class="small text-muted"><?php echo number_format((float)(float)($summary['unique_customers'] ?? 0)); ?> unique customers</div>
                        </div>
                        <div class="col-auto"><i class="fas fa-file-invoice fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-info shadow-sm h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Average Order Value</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo format_currency($summary['avg_order_value'] ?? 0, $companyId); ?></div>
                            <div class="small text-muted"><?php echo number_format((float)$summary['avg_items_per_order'] ?? 0, 1); ?> items per order</div>
                        </div>
                        <div class="col-auto"><i class="fas fa-chart-bar fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-warning shadow-sm h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Collected</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo format_currency($summary['total_collected'] ?? 0, $companyId); ?></div>
                            <div class="small text-danger">Outstanding: <?php echo format_currency($summary['outstanding'] ?? 0, $companyId); ?></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-credit-card fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row mb-4">
        <div class="col-xl-8 col-lg-7 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-chart-line me-2"></i>Daily Sales Trend
                    </h6>
                </div>
                <div class="card-body">
                    <canvas id="dailySalesChart" height="300"></canvas>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-lg-5 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-chart-pie me-2"></i>Payment Methods
                    </h6>
                </div>
                <div class="card-body">
                    <canvas id="paymentMethodChart" height="300"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Top Products Table -->
    <div class="row">
        <div class="col-12 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-crown me-2"></i>Top Selling Products
                        <span class="badge bg-primary ms-2">Top 20</span>
                    </h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="topProductsTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th class="text-end">Quantity Sold</th>
                                    <th class="text-end">Revenue</th>
                                    <th class="text-end">% of Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $totalRevenue = array_sum(array_column($topProducts, 'total_revenue'));
                                if (empty($topProducts)):
                                ?>
                                    <tr><td colspan="5" class="text-center py-4 text-muted">No sales data available</td></tr>
                                <?php else: ?>
                                    <?php foreach ($topProducts as $product):
                                        $percentage = $totalRevenue > 0 ? round(($product['total_revenue'] / $totalRevenue) * 100, 1) : 0;
                                    ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($product['product_name']); ?></strong>
                                                <?php if (!empty($product['variant_name']) && $product['variant_name'] !== 'Standard'): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($product['variant_name']); ?></small>
                                                <?php endif; ?>
                                             </div>
                                          </div>
                                        </div>
                                        <td class="text-center"><code><?php echo htmlspecialchars($product['sku']); ?></code></td>
                                        <td class="text-end fw-bold"><?php echo number_format((float)(float)$product['total_sold'], 0); ?> </div>
                                      </div>
                                      <td class="text-end text-success"><?php echo format_currency($product['total_revenue'], $companyId); ?> </div>
                                      </div>
                                      <td class="text-end">
                                        <div class="d-flex align-items-center justify-content-end">
                                            <span class="me-2"><?php echo $percentage; ?>%</span>
                                            <div class="progress" style="width: 80px; height: 6px;">
                                                <div class="progress-bar bg-success" style="width: <?php echo $percentage; ?>%"></div>
                                            </div>
                                        </div>
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

    <!-- Daily Breakdown Table -->
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-calendar-alt me-2"></i>Daily Breakdown
                        <span class="badge bg-primary ms-2"><?php echo count($dailySales); ?> days</span>
                    </h6>
                    <button class="btn btn-sm btn-outline-primary" onclick="window.location.reload()">
                        <i class="fas fa-sync-alt me-1"></i> Refresh
                    </button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="dailyTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th class="text-end">Invoices</th>
                                    <th class="text-end">Items Sold</th>
                                    <th class="text-end">Sales</th>
                                    <th class="text-end">Payments</th>
                                    <th class="text-end">Outstanding</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($dailySales)): ?>
                                    <tr><td colspan="6" class="text-center py-4 text-muted">No sales data available for this period</td></tr>
                                <?php else: ?>
                                    <?php foreach ($dailySales as $day): ?>
                                        <tr>
                                            <td><?php echo date('d/m/Y', strtotime($day['date'])); ?></td>
                                            <td class="text-end"><?php echo number_format((float)(float)$day['invoice_count'], 0); ?></td>
                                            <td class="text-end"><?php echo number_format((float)(float)($day['items_sold'] ?? 0), 0); ?></td>
                                            <td class="text-end fw-bold"><?php echo format_currency($day['sales'], $companyId); ?></td>
                                            <td class="text-end text-success"><?php echo format_currency($day['payments'], $companyId); ?></td>
                                            <td class="text-end text-danger"><?php echo format_currency($day['sales'] - $day['payments'], $companyId); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th class="fw-bold">Total</th>
                                    <th class="text-end fw-bold"><?php echo number_format((float)(float)array_sum(array_column($dailySales, 'invoice_count')), 0); ?></th>
                                    <th class="text-end fw-bold"><?php echo number_format((float)(float)array_sum(array_column($dailySales, 'items_sold')), 0); ?></th>
                                    <th class="text-end fw-bold"><?php echo format_currency(array_sum(array_column($dailySales, 'sales')), $companyId); ?></th>
                                    <th class="text-end fw-bold"><?php echo format_currency(array_sum(array_column($dailySales, 'payments')), $companyId); ?></th>
                                    <th class="text-end fw-bold"><?php echo format_currency(array_sum(array_column($dailySales, 'sales')) - array_sum(array_column($dailySales, 'payments')), $companyId); ?></th>
                                </tr>
                            </tfoot>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<style>
    .sales-report .border-left-primary { border-left: 4px solid #4e73df !important; }
    .sales-report .border-left-success { border-left: 4px solid #1cc88a !important; }
    .sales-report .border-left-info { border-left: 4px solid #36b9cc !important; }
    .sales-report .border-left-warning { border-left: 4px solid #f6c23e !important; }
    .sales-report .table td { vertical-align: middle; }
    .sales-report .progress { background-color: #eaecf4; border-radius: 10px; }
    .sales-report .badge { font-weight: 500; padding: 0.5em 0.85em; }
    .sales-report .card-header { background-color: #f8f9fc; }
</style>