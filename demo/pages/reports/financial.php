<?php
// pages/reports/financial.php
declare(strict_types=1);

$pageTitle = 'Financial Report - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Sale.php';
require_once __DIR__ . '/../../models/PurchaseOrder.php';
require_once __DIR__ . '/../../models/Inventory.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR', 'ACC'])) {
    SessionManager::flash('error', 'You do not have permission to view financial reports.');
    header('Location: ' . route_url('reports'));
    exit;
}

// Initialize models with company context
$saleModel = new Sale($companyId);
$purchaseModel = new PurchaseOrder($companyId);
$inventoryModel = new Inventory($companyId);

// Get filter parameters
$period = $_GET['period'] ?? 'month';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$year = $_GET['year'] ?? date('Y');

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

// Get sales data (company-specific)
$salesSummary = $saleModel->getSalesSummary($startDate, $endDate);
$salesByStatus = $saleModel->getSalesByStatus();

// Get purchase data (company-specific)
$purchaseSummary = $purchaseModel->getSummary();
$purchasesByStatus = $purchaseModel->getPurchasesByStatus($startDate, $endDate);

// Get inventory value (company-specific)
$stockSummary = $inventoryModel->getStockSummary();
$inventoryValue = $stockSummary['stock_value'] ?? 0;
$totalStockUnits = $stockSummary['total_stock'] ?? 0;

// Calculate profit (simplified)
$revenue = $salesSummary['total_sales'] ?? 0;
$cogs = $purchaseSummary['total_value'] ?? 0; // This is simplified
$grossProfit = $revenue - $cogs;
$grossMargin = $revenue > 0 ? ($grossProfit / $revenue) * 100 : 0;

// Get receivables (company-specific)
$receivables = $saleModel->getTotalReceivables();

// Get payables (simplified)
$payables = $purchaseSummary['pending_value'] ?? 0;

// Get monthly trend for the year (company-specific)
$monthlySales = [];
$monthlyPurchases = [];
for ($m = 1; $m <= 12; $m++) {
    $monthStart = date('Y-m-d', strtotime("$year-$m-01"));
    $monthEnd = date('Y-m-t', strtotime($monthStart));

    $monthlySales[$m] = $saleModel->getSalesSummary($monthStart, $monthEnd)['total_sales'] ?? 0;
    $monthlyPurchases[$m] = $purchaseModel->getPurchasesSummary($monthStart, $monthEnd)['total_amount'] ?? 0;
}

// Get status breakdown for receivables
$statusBreakdown = $saleModel->getSalesByStatus();
$paidTotal = 0;
$partialTotal = 0;
$overdueTotal = 0;

foreach ($statusBreakdown as $status) {
    if ($status['status'] === 'paid') $paidTotal = $status['total'];
    if ($status['status'] === 'partial') $partialTotal = $status['total'];
    if ($status['status'] === 'overdue') $overdueTotal = $status['total'];
}

// Get purchase status for payables
$purchaseStatus = $purchaseModel->getSummary();

// Get currency
$currency = $_SESSION['company_currency'] ?? 'RWF';

// Set JS files
$jsFiles = ['reports/financial.js'];
?>

<!-- Pass data to JavaScript -->
<script>
    const currency = '<?php echo $currency; ?>';
    const monthlyData = {
        sales: <?php echo json_encode(array_values($monthlySales)); ?>,
        purchases: <?php echo json_encode(array_values($monthlyPurchases)); ?>,
        months: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
    };
    const receivablesData = {
        paid: <?php echo $paidTotal; ?>,
        partial: <?php echo $partialTotal; ?>,
        overdue: <?php echo $overdueTotal; ?>
    };
    const payablesData = {
        approved: <?php echo $purchaseStatus['approved_value'] ?? 0; ?>,
        partial: <?php echo $purchaseStatus['partial_value'] ?? 0; ?>,
        pending: <?php echo $purchaseStatus['pending_value'] ?? 0; ?>
    };
</script>

<div class="financial-report">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <a href="<?php echo route_url('reports'); ?>" class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Reports
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-coins me-2"></i>Financial Report
                    </h2>
                    <p class="mb-0 text-muted">Analyze your financial performance</p>
                </div>
                <div class="btn-group mt-2 mt-sm-0">
                    <button type="button" class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="fas fa-download me-2"></i>Export
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="?page=exports/financial&format=pdf&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>" target="_blank"><i class="fas fa-file-pdf me-2"></i>PDF</a></li>
                        <li><a class="dropdown-item" href="?page=exports/financial&format=excel&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>"><i class="fas fa-file-excel me-2"></i>Excel</a></li>
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
                <input type="hidden" name="page" value="reports/financial">
                
                <div class="col-md-3">
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
                
                <div class="col-md-3 custom-date" style="<?php echo $period !== 'custom' ? 'display: none;' : ''; ?>">
                    <label class="form-label">Start Date</label>
                    <input type="date" class="form-control" name="start_date" value="<?php echo $startDate; ?>">
                </div>
                
                <div class="col-md-3 custom-date" style="<?php echo $period !== 'custom' ? 'display: none;' : ''; ?>">
                    <label class="form-label">End Date</label>
                    <input type="date" class="form-control" name="end_date" value="<?php echo $endDate; ?>">
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Year</label>
                    <select class="form-control" name="year">
                        <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="col-12 d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search me-2"></i>Generate Report
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Key Financial Metrics -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-primary shadow-sm h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Revenue (Sales)</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo format_currency($salesSummary['total_sales'] ?? 0, $companyId); ?></div>
                            <div class="small text-muted"><?php echo $salesSummary['total_invoices'] ?? 0; ?> invoices</div>
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
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Gross Profit</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo format_currency($grossProfit, $companyId); ?></div>
                            <div class="small <?php echo $grossMargin >= 0 ? 'text-success' : 'text-danger'; ?>">Margin: <?php echo number_format((float)$grossMargin, 1); ?>%</div>
                        </div>
                        <div class="col-auto"><i class="fas fa-chart-line fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-info shadow-sm h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Inventory Value</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo format_currency($inventoryValue, $companyId); ?></div>
                            <div class="small text-muted"><?php echo number_format((float)$totalStockUnits, 0); ?> total units</div>
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
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Net Position</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo format_currency($grossProfit + $inventoryValue, $companyId); ?></div>
                            <div class="small text-muted">Assets - Liabilities (simplified)</div>
                        </div>
                        <div class="col-auto"><i class="fas fa-balance-scale fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Receivables & Payables -->
    <div class="row mb-4">
        <div class="col-xl-6 mb-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-credit-card me-2"></i>Accounts Receivable
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <h3 class="text-danger mb-0"><?php echo format_currency($receivables, $companyId); ?></h3>
                            <p class="text-muted">Outstanding customer payments</p>
                            
                            <div class="mt-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Paid</span>
                                    <span class="text-success"><?php echo format_currency($paidTotal, $companyId); ?></span>
                                </div>
                                <div class="progress mb-2" style="height: 8px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo $salesSummary['total_sales'] > 0 ? ($paidTotal / $salesSummary['total_sales']) * 100 : 0; ?>%"></div>
                                </div>
                                
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Partial</span>
                                    <span class="text-warning"><?php echo format_currency($partialTotal, $companyId); ?></span>
                                </div>
                                <div class="progress mb-2" style="height: 8px;">
                                    <div class="progress-bar bg-warning" style="width: <?php echo $salesSummary['total_sales'] > 0 ? ($partialTotal / $salesSummary['total_sales']) * 100 : 0; ?>%"></div>
                                </div>
                                
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Overdue</span>
                                    <span class="text-danger"><?php echo format_currency($overdueTotal, $companyId); ?></span>
                                </div>
                                <div class="progress mb-2" style="height: 8px;">
                                    <div class="progress-bar bg-danger" style="width: <?php echo $salesSummary['total_sales'] > 0 ? ($overdueTotal / $salesSummary['total_sales']) * 100 : 0; ?>%"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <canvas id="receivablesChart" height="200"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-6 mb-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-credit-card me-2"></i>Accounts Payable
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <h3 class="text-warning mb-0"><?php echo format_currency($payables, $companyId); ?></h3>
                            <p class="text-muted">Outstanding supplier payments</p>
                            
                            <div class="mt-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Approved Orders</span>
                                    <span class="text-primary"><?php echo format_currency($purchaseStatus['approved_value'] ?? 0, $companyId); ?></span>
                                </div>
                                <div class="progress mb-2" style="height: 8px;">
                                    <div class="progress-bar bg-primary" style="width: <?php echo $purchaseStatus['total_value'] > 0 ? (($purchaseStatus['approved_value'] ?? 0) / $purchaseStatus['total_value']) * 100 : 0; ?>%"></div>
                                </div>
                                
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Partial Received</span>
                                    <span class="text-warning"><?php echo format_currency($purchaseStatus['partial_value'] ?? 0, $companyId); ?></span>
                                </div>
                                <div class="progress mb-2" style="height: 8px;">
                                    <div class="progress-bar bg-warning" style="width: <?php echo $purchaseStatus['total_value'] > 0 ? (($purchaseStatus['partial_value'] ?? 0) / $purchaseStatus['total_value']) * 100 : 0; ?>%"></div>
                                </div>
                                
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Pending Approval</span>
                                    <span class="text-info"><?php echo format_currency($purchaseStatus['pending_value'] ?? 0, $companyId); ?></span>
                                </div>
                                <div class="progress mb-2" style="height: 8px;">
                                    <div class="progress-bar bg-info" style="width: <?php echo $purchaseStatus['total_value'] > 0 ? (($purchaseStatus['pending_value'] ?? 0) / $purchaseStatus['total_value']) * 100 : 0; ?>%"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <canvas id="payablesChart" height="200"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Monthly Performance Chart -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-chart-bar me-2"></i>Monthly Performance - <?php echo $year; ?>
                    </h6>
                </div>
                <div class="card-body">
                    <canvas id="monthlyChart" height="300"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Profit & Loss Statement -->
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-file-invoice me-2"></i>Profit & Loss Statement
                    </h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th colspan="2">INCOME</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Sales Revenue</div>
                                  </div>
                                  <td class="text-end"><?php echo format_currency($salesSummary['total_sales'] ?? 0, $companyId); ?> </div>
                                </div>
                              </div>
                              <tr>
                                <td class="ps-4">- Discounts</div>
                                <td class="text-end text-danger"><?php echo format_currency($salesSummary['total_discount'] ?? 0, $companyId); ?> </div>
                              </div>
                            </div>
                            <tr>
                                <td class="ps-4">- Returns</div>
                                <td class="text-end text-danger"><?php echo format_currency($salesSummary['total_returns'] ?? 0, $companyId); ?> </div>
                              </div>
                            </div>
                            <tr class="fw-bold">
                                <td>Net Sales</div>
                                <td class="text-end"><?php echo format_currency(($salesSummary['total_sales'] ?? 0) - ($salesSummary['total_discount'] ?? 0) - ($salesSummary['total_returns'] ?? 0), $companyId); ?> </div>
                              </div>
                            </div>
                            
                            <tr>
                                <th colspan="2">COST OF GOODS SOLD</th>
                             </div>
                          </div>
                          <tr>
                            <td class="ps-4">Beginning Inventory</div>
                            <td class="text-end"><?php echo format_currency($inventoryValue, $companyId); ?> </div>
                          </div>
                        </div>
                        <tr>
                            <td class="ps-4">+ Purchases</div>
                            <td class="text-end"><?php echo format_currency($purchaseSummary['total_value'] ?? 0, $companyId); ?> </div>
                          </div>
                        </div>
                        <tr>
                            <td class="ps-4">- Ending Inventory</div>
                            <td class="text-end text-danger"><?php echo format_currency($inventoryValue, $companyId); ?> </div>
                          </div>
                        </div>
                        <tr class="fw-bold">
                            <td>Cost of Goods Sold</div>
                            <td class="text-end"><?php echo format_currency($cogs, $companyId); ?> </div>
                          </div>
                        </div>
                        
                        <tr class="fw-bold text-primary">
                            <td>GROSS PROFIT</div>
                            <td class="text-end"><?php echo format_currency($grossProfit, $companyId); ?> </div>
                          </div>
                        </div>
                        
                        <tr>
                            <th colspan="2">EXPENSES</th>
                         </div>
                      </div>
                      <tr>
                            <td class="ps-4">Operating Expenses</div>
                            <td class="text-end"><?php echo format_currency(0, $companyId); ?> </div>
                          </div>
                        </div>
                        <tr>
                            <td class="ps-4">Other Expenses</div>
                            <td class="text-end"><?php echo format_currency(0, $companyId); ?> </div>
                          </div>
                        </div>
                        <tr class="fw-bold">
                            <td>Total Expenses</div>
                            <td class="text-end"><?php echo format_currency(0, $companyId); ?> </div>
                          </div>
                        </div>
                        
                        <tr class="fw-bold text-success">
                            <td>NET PROFIT</div>
                            <td class="text-end"><?php echo format_currency($grossProfit, $companyId); ?> </div>
                          </div>
                        </div>
                        
                        <tr>
                            <td colspan="2" class="text-muted small">
                                * Note: This is a simplified P&L statement. Expenses are not included in this calculation.
                             </div>
                          </div>
                        </div>
                    </tbody>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    .financial-report .border-left-primary { border-left: 4px solid #4e73df !important; }
    .financial-report .border-left-success { border-left: 4px solid #1cc88a !important; }
    .financial-report .border-left-info { border-left: 4px solid #36b9cc !important; }
    .financial-report .border-left-warning { border-left: 4px solid #f6c23e !important; }
    .financial-report .table td { vertical-align: middle; }
    .financial-report .table th { background-color: #f8f9fc; }
    .financial-report .progress { border-radius: 10px; background-color: #e9ecef; }
    .financial-report .card-header { background-color: #f8f9fc; }
</style>