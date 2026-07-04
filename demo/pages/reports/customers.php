<?php
// pages/reports/customers.php
declare(strict_types=1);

$pageTitle = 'Customer Report - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Customer.php';
require_once __DIR__ . '/../../models/Sale.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR', 'ACC', 'VIW', 'SEL'])) {
    SessionManager::flash('error', 'You do not have permission to view customer reports.');
    header('Location: ' . route_url('reports'));
    exit;
}

// Initialize models with company context
$customerModel = new Customer($companyId);
$saleModel = new Sale($companyId);

// Get filter parameters
$period = $_GET['period'] ?? 'month';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$customerType = $_GET['customer_type'] ?? '';
$sortBy = $_GET['sort_by'] ?? 'total_purchases';

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
    case 'all':
        $startDate = '2000-01-01';
        $endDate = date('Y-m-d');
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

// Get customer statistics (company-specific)
$customerStats = $customerModel->getStats();

// Get customer list with purchase summary (company-specific)
$sql = "
    SELECT 
        c.*,
        COUNT(DISTINCT si.id) as total_invoices,
        COALESCE(SUM(si.total_amount), 0) as total_purchases,
        COALESCE(SUM(si.amount_paid), 0) as total_paid,
        COALESCE(SUM(CASE WHEN si.status IN ('issued', 'partial', 'overdue') THEN (si.total_amount - si.amount_paid) ELSE 0 END), 0) as outstanding,
        MAX(si.invoice_date) as last_purchase_date,
        AVG(CASE WHEN si.total_amount > 0 THEN si.total_amount ELSE NULL END) as avg_order_value
    FROM customers c
    LEFT JOIN sales_invoices si ON c.id = si.customer_id 
        AND si.invoice_date BETWEEN :start_date AND :end_date
        AND si.status != 'cancelled'
        AND si.company_id = $companyId
    WHERE c.company_id = $companyId
";

$params = [
    'start_date' => $startDate,
    'end_date' => $endDate
];

if ($customerType) {
    $sql .= " AND c.customer_type = :customer_type";
    $params['customer_type'] = $customerType;
}

$sql .= " GROUP BY c.id";

// Apply sorting
switch ($sortBy) {
    case 'total_purchases':
        $sql .= " ORDER BY total_purchases DESC";
        break;
    case 'invoices':
        $sql .= " ORDER BY total_invoices DESC";
        break;
    case 'outstanding':
        $sql .= " ORDER BY outstanding DESC";
        break;
    case 'last_purchase':
        $sql .= " ORDER BY last_purchase_date DESC NULLS LAST";
        break;
    case 'name':
        $sql .= " ORDER BY c.full_name ASC";
        break;
    default:
        $sql .= " ORDER BY total_purchases DESC";
}

$sql .= " LIMIT 100";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

// Calculate totals
$totalCustomers = count($customers);
$totalPurchases = array_sum(array_column($customers, 'total_purchases'));
$totalPaid = array_sum(array_column($customers, 'total_paid'));
$totalOutstanding = array_sum(array_column($customers, 'outstanding'));
$avgPurchasePerCustomer = $totalCustomers > 0 ? $totalPurchases / $totalCustomers : 0;

// Get top customers by value
usort($customers, function($a, $b) {
    return $b['total_purchases'] <=> $a['total_purchases'];
});
$topCustomers = array_slice($customers, 0, 10);

// Count customer types for chart
$individualCount = 0;
$companyCount = 0;
foreach ($customers as $cust) {
    if ($cust['customer_type'] === 'individual') {
        $individualCount++;
    } else {
        $companyCount++;
    }
}

// Get currency
$currency = $_SESSION['company_currency'] ?? 'RWF';

// Set JS files
$jsFiles = ['reports/customer.js'];
?>

<!-- Pass data to JavaScript -->
<script>
    const currency = '<?php echo $currency; ?>';
    const topCustomers = <?php echo json_encode($topCustomers); ?>;
    const individualCount = <?php echo $individualCount; ?>;
    const companyCount = <?php echo $companyCount; ?>;
</script>

<!-- Page Header -->
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <a href="<?php echo route_url('reports'); ?>" class="btn btn-outline-secondary btn-sm mb-2">
                    <i class="fas fa-arrow-left me-1"></i> Back to Reports
                </a>
                <h2 class="h4 mb-1 text-gray-800">
                    <i class="fas fa-users me-2"></i>Customer Report
                </h2>
                <p class="mb-0 text-muted">Analyze customer behavior and value</p>
            </div>
            <div class="btn-group mt-2 mt-sm-0">
                <button type="button" class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="fas fa-download me-2"></i>Export
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="?page=exports/customers&format=pdf&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>" target="_blank"><i class="fas fa-file-pdf me-2"></i>PDF</a></li>
                    <li><a class="dropdown-item" href="?page=exports/customers&format=excel&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>"><i class="fas fa-file-excel me-2"></i>Excel</a></li>
                    <li><a class="dropdown-item" href="?page=exports/customers&format=csv&start_date=<?php echo $startDate; ?>&end_date=<?php echo $endDate; ?>"><i class="fas fa-file-csv me-2"></i>CSV</a></li>
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
            <input type="hidden" name="page" value="reports/customers">
            
            <div class="col-md-2">
                <label class="form-label">Period</label>
                <select class="form-control" name="period" id="period" onchange="toggleCustomDates()">
                    <option value="today" <?php echo $period === 'today' ? 'selected' : ''; ?>>Today</option>
                    <option value="week" <?php echo $period === 'week' ? 'selected' : ''; ?>>This Week</option>
                    <option value="month" <?php echo $period === 'month' ? 'selected' : ''; ?>>This Month</option>
                    <option value="quarter" <?php echo $period === 'quarter' ? 'selected' : ''; ?>>This Quarter</option>
                    <option value="year" <?php echo $period === 'year' ? 'selected' : ''; ?>>This Year</option>
                    <option value="all" <?php echo $period === 'all' ? 'selected' : ''; ?>>All Time</option>
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
                <label class="form-label">Customer Type</label>
                <select class="form-control" name="customer_type">
                    <option value="">All Types</option>
                    <option value="individual" <?php echo $customerType === 'individual' ? 'selected' : ''; ?>>👤 Individual</option>
                    <option value="company" <?php echo $customerType === 'company' ? 'selected' : ''; ?>>🏢 Company</option>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">Sort By</label>
                <select class="form-control" name="sort_by">
                    <option value="total_purchases" <?php echo $sortBy === 'total_purchases' ? 'selected' : ''; ?>>💰 Total Purchases</option>
                    <option value="invoices" <?php echo $sortBy === 'invoices' ? 'selected' : ''; ?>>📊 Number of Invoices</option>
                    <option value="outstanding" <?php echo $sortBy === 'outstanding' ? 'selected' : ''; ?>>⚠️ Outstanding Balance</option>
                    <option value="last_purchase" <?php echo $sortBy === 'last_purchase' ? 'selected' : ''; ?>>📅 Last Purchase Date</option>
                    <option value="name" <?php echo $sortBy === 'name' ? 'selected' : ''; ?>>📝 Customer Name</option>
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
<div class="row">
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-primary shadow-sm h-100">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Active Customers</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format((float)($customerStats['active_customers'] ?? 0)); ?></div>
                        <div class="small text-muted">Total: <?php echo number_format((float)($customerStats['total_customers'] ?? 0)); ?></div>
                    </div>
                    <div class="col-auto"><i class="fas fa-users fa-2x text-gray-300"></i></div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-success shadow-sm h-100">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Purchases</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo format_currency($totalPurchases, $companyId); ?></div>
                        <div class="small text-muted"><?php echo $totalCustomers; ?> customers</div>
                    </div>
                    <div class="col-auto"><i class="fas fa-shopping-cart fa-2x text-gray-300"></i></div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-info shadow-sm h-100">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Average per Customer</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo format_currency($avgPurchasePerCustomer, $companyId); ?></div>
                        <div class="small text-muted">Avg order value</div>
                    </div>
                    <div class="col-auto"><i class="fas fa-chart-line fa-2x text-gray-300"></i></div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-warning shadow-sm h-100">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Outstanding Balance</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo format_currency($totalOutstanding, $companyId); ?></div>
                        <div class="small text-danger">Collection rate: <?php echo $totalPurchases > 0 ? round(($totalPaid / $totalPurchases) * 100, 1) : 0; ?>%</div>
                    </div>
                    <div class="col-auto"><i class="fas fa-credit-card fa-2x text-gray-300"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Top Customers Chart -->
<div class="row">
    <div class="col-xl-6 mb-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-crown me-2"></i>Top 10 Customers by Value
                </h6>
            </div>
            <div class="card-body">
                <canvas id="topCustomersChart" height="300"></canvas>
            </div>
        </div>
    </div>

    <div class="col-xl-6 mb-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-chart-pie me-2"></i>Customer Type Distribution
                </h6>
            </div>
            <div class="card-body">
                <canvas id="customerTypeChart" height="300"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Customer Table -->
<div class="row">
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-list me-2"></i>Customer List
                    <span class="badge bg-primary ms-2"><?php echo count($customers); ?> customers</span>
                </h6>
                <button class="btn btn-sm btn-outline-primary" onclick="window.location.reload()">
                    <i class="fas fa-sync-alt me-1"></i> Refresh
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="customerTable">
                        <thead class="table-light">
                            <tr>
                                <th>Customer</th>
                                <th>Type</th>
                                <th>Contact</th>
                                <th class="text-end">Invoices</th>
                                <th class="text-end">Total Purchases</th>
                                <th class="text-end">Amount Paid</th>
                                <th class="text-end">Outstanding</th>
                                <th class="text-end">Avg Order</th>
                                <th>Last Purchase</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($customers)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-users fa-4x mb-3"></i>
                                            <p class="h5 mb-2">No customer data found</p>
                                            <p class="mb-0">Try adjusting your filter criteria</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($customers as $cust): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($cust['full_name']); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($cust['customer_code']); ?></small>
                                         </div>
                                      </div>
                                    </div>
                                    <td class="text-center"><span class="badge bg-<?php echo $cust['customer_type'] === 'company' ? 'primary' : 'info'; ?>"><?php echo ucfirst($cust['customer_type']); ?></span></td>
                                    <td>
                                        <?php if ($cust['phone']): ?>
                                            <div><i class="fas fa-phone me-1"></i> <?php echo htmlspecialchars($cust['phone']); ?></div>
                                        <?php endif; ?>
                                        <?php if ($cust['email']): ?>
                                            <div><i class="fas fa-envelope me-1"></i> <?php echo htmlspecialchars($cust['email']); ?></div>
                                        <?php endif; ?>
                                        <?php if (empty($cust['phone']) && empty($cust['email'])): ?>
                                            <span class="text-muted">No contact info</span>
                                        <?php endif; ?>
                                     </div>
                                     </td>
                                    <td class="text-end"><?php echo number_format((float)$cust['total_invoices'], 0); ?> </div>
                                     </td>
                                    <td class="text-end fw-bold"><?php echo format_currency($cust['total_purchases'], $companyId); ?> </div>
                                     </td>
                                    <td class="text-end text-success"><?php echo format_currency($cust['total_paid'], $companyId); ?> </div>
                                     </td>
                                    <td class="text-end <?php echo $cust['outstanding'] > 0 ? 'text-danger' : 'text-muted'; ?>">
                                        <?php echo format_currency($cust['outstanding'], $companyId); ?>
                                     </div>
                                     </td>
                                    <td class="text-end"><?php echo format_currency($cust['avg_order_value'], $companyId); ?> </div>
                                     </td>
                                    <td>
                                        <?php if ($cust['last_purchase_date']): ?>
                                            <?php echo date('d/m/Y', strtotime($cust['last_purchase_date'])); ?>
                                            <br><small class="text-muted"><?php echo time_ago($cust['last_purchase_date']); ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">Never</span>
                                        <?php endif; ?>
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