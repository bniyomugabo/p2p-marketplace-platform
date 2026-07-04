<?php
// pages/dashboard/main.php
declare(strict_types=1);

$pageTitle = 'Dashboard - SATI ERP Platform';
require_once __DIR__ . '/../../config/autoload.php';

// Load models
require_once __DIR__ . '/../../models/Product.php';
require_once __DIR__ . '/../../models/Sale.php';
require_once __DIR__ . '/../../models/Inventory.php';
require_once __DIR__ . '/../../models/Customer.php';
require_once __DIR__ . '/../../models/Company.php';

$db = Database::getInstance();
$session = SessionManager::getInstance();
$userId = $_SESSION['user_id'] ?? 0;
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userName = $_SESSION['full_name'] ?? 'User';
$companyId = $session->getCompanyId() ?? 1; // Get current company context

// Get user's accessible companies
$stmt = $db->prepare("
    SELECT c.* 
    FROM companies c
    JOIN company_users cu ON c.id = cu.company_id
    WHERE cu.user_id = ? AND cu.status = 'active'
    ORDER BY c.company_name
");
$stmt->execute([$userId]);
$userCompanies = $stmt->fetchAll();

// Get current company details
$stmt = $db->prepare("SELECT * FROM companies WHERE id = ?");
$stmt->execute([$companyId]);
$currentCompany = $stmt->fetch();

// Get greeting based on time
$hour = (int) date('H');
if ($hour < 12) {
    $greeting = 'Good Morning';
} elseif ($hour < 17) {
    $greeting = 'Good Afternoon';
} else {
    $greeting = 'Good Evening';
}

$today = date('l, F j, Y');

try {
    // Initialize models with company context - they automatically use session company_id
    $saleModel = new Sale();
    $inventoryModel = new Inventory();
    $productModel = new Product();
    $customerModel = new Customer();
    
    // ==================== COMPANY INFO ====================
    $companyName = $currentCompany['company_name'] ?? 'SATI ERP';
    $companyCurrency = $currentCompany['currency'] ?? 'RWF';
    
    // ==================== SALES DASHBOARD ====================
    
    // Today's sales summary (company-specific) - FIXED: removed trailing comma
    $todaySales = $saleModel->getDailySummary(date('Y-m-d'));
    
    // Weekly sales trend (company-specific)
    $weeklySales = $saleModel->getWeeklyTrend();
    
    // Monthly sales (company-specific) - FIXED: removed null parameters
    $monthlySales = $saleModel->getMonthlySummary();
    
    // Sales by status (company-specific)
    $statusSales = $saleModel->getSalesByStatus();
    
    // ==================== INVENTORY DASHBOARD ====================
    
    // Stock summary (company-specific)
    $stockSummary = $inventoryModel->getStockSummary();
    
    // Low stock alerts (company-specific)
    $lowStock = $inventoryModel->getLowStock(10);
    
    // Top selling products (company-specific)
    $topProducts = $saleModel->getTopProducts(5);

    // ==================== FINANCIAL DASHBOARD ====================
    
    // Cash position (company-specific)
    $cashPosition = [
        'bank_balance' => 0,
        'cash_balance' => 0,
        'receivables' => $saleModel->getTotalReceivables(),
        'payables' => getTotalPayables($db, $companyId)
    ];
    
    // Recent payments (company-specific) - FIXED: removed trailing comma
    $recentPayments = $saleModel->getRecentPayments(5);
    
    // ==================== CUSTOMER DASHBOARD ====================
    
    // Customer summary (company-specific) - FIXED: removed parameter, model uses session
    $customerSummary = $customerModel->getSummary();
    
    // Recent customers (company-specific) - FIXED: removed parameter, model uses session
    $recentCustomers = $customerModel->getRecent(5);
    
    // ==================== PURCHASING DASHBOARD ====================
    
    // Purchase orders summary (company-specific)
    $poSummary = getPurchaseOrderSummary($db, $companyId);
    
    // ==================== ACTIVITY & NOTIFICATIONS ====================
    
    // Recent activity (company-specific)
    $recentActivity = getRecentActivity($db, $userId, $companyId);
    
    // Upcoming tasks (company-specific)
    $upcomingTasks = getUpcomingTasks($db, $companyId);
    
    // ==================== PERFORMANCE METRICS ====================
    
    // Growth calculation (company-specific)
    $growth = $saleModel->getGrowthRate();
    $growthRate = $growth['rate'] ?? 0;
    
    // Inventory turnover (company-specific) - FIXED: removed extra parameter
    $turnoverRatio = $inventoryModel->getTurnoverRate(30);
    
    // Financial health (company-specific)
    $totalAssets = ($cashPosition['bank_balance'] + $cashPosition['cash_balance'] +
        ($stockSummary['stock_value'] ?? 0) + $cashPosition['receivables']);
    $totalLiabilities = $cashPosition['payables'];
    $netWorth = $totalAssets - $totalLiabilities;
    
    // ==================== COMPANY STATS ====================
    $companyStats = getCompanyStats($db, $companyId);
    
} catch (Exception $e) {
    error_log("Dashboard error for company {$companyId}: " . $e->getMessage());
    // Set default values
    $todaySales = ['invoice_count' => 0, 'total_sales' => 0, 'total_collected' => 0, 'outstanding' => 0];
    $weeklySales = [];
    $monthlySales = ['total_sales' => 0, 'invoice_count' => 0, 'avg_invoice' => 0];
    $statusSales = [];
    $stockSummary = ['total_stock' => 0, 'available_stock' => 0, 'stock_value' => 0, 'unique_products' => 0, 'low_stock' => 0, 'out_of_stock' => 0];
    $lowStock = [];
    $topProducts = [];
    $cashPosition = ['bank_balance' => 0, 'cash_balance' => 0, 'receivables' => 0, 'payables' => 0];
    $recentPayments = [];
    $customerSummary = ['total_customers' => 0, 'active_customers' => 0, 'new_customers' => 0];
    $recentCustomers = [];
    $poSummary = ['total_pos' => 0, 'pending' => 0, 'approved' => 0, 'total_value' => 0];
    $recentActivity = [];
    $upcomingTasks = [];
    $growthRate = 0;
    $turnoverRatio = 0;
    $totalAssets = 0;
    $totalLiabilities = 0;
    $netWorth = 0;
    $companyStats = ['user_count' => 1, 'active_users' => 1, 'customer_count' => 0, 'product_count' => 0];
}

// Helper function to get purchase order summary
function getPurchaseOrderSummary($db, $companyId)
{
    try {
        $sql = "
            SELECT 
                COUNT(*) as total_pos,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                COALESCE(SUM(total_amount), 0) as total_value
            FROM purchase_orders
            WHERE company_id = :company_id
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute(['company_id' => $companyId]);
        return $stmt->fetch();
    } catch (Exception $e) {
        return ['total_pos' => 0, 'pending' => 0, 'approved' => 0, 'total_value' => 0];
    }
}

// Helper function to get total payables
function getTotalPayables($db, $companyId)
{
    try {
        $sql = "
            SELECT COALESCE(SUM(total_amount), 0) as total
            FROM purchase_orders
            WHERE company_id = :company_id 
              AND status IN ('approved', 'partial')
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute(['company_id' => $companyId]);
        $result = $stmt->fetch();
        return $result['total'] ?? 0;
    } catch (Exception $e) {
        return 0;
    }
}

// Helper function to get company stats
function getCompanyStats($db, $companyId)
{
    try {
        $sql = "
            SELECT 
                (SELECT COUNT(*) FROM users WHERE company_id = :cid1 AND is_deleted = 0) as user_count,
                (SELECT COUNT(*) FROM users WHERE company_id = :cid2 AND is_active = 1 AND is_deleted = 0) as active_users,
                (SELECT COUNT(*) FROM customers WHERE company_id = :cid3) as customer_count,
                (SELECT COUNT(*) FROM products WHERE company_id = :cid4 AND is_active = 1) as product_count
        ";
        // Note: Removed FROM dual for MySQL compatibility
        $stmt = $db->prepare($sql);
        $stmt->execute([
            'cid1' => $companyId,
            'cid2' => $companyId,
            'cid3' => $companyId,
            'cid4' => $companyId
        ]);
        return $stmt->fetch();
    } catch (Exception $e) {
        error_log("Error in getCompanyStats: " . $e->getMessage());
        return ['user_count' => 1, 'active_users' => 1, 'customer_count' => 0, 'product_count' => 0];
    }
}

// Helper function to get recent activity (company-specific)
function getRecentActivity($db, $userId, $companyId)
{
    try {
        $sql = "
            (SELECT 
                'sale' as type, 
                CONCAT('New sale: ', si.invoice_number) as description, 
                CONCAT('Amount: ', si.total_amount) as details, 
                si.created_at, 
                u.full_name as user_name
            FROM sales_invoices si
            LEFT JOIN users u ON si.created_by = u.id
            WHERE si.company_id = :company_id
            ORDER BY si.created_at DESC
            LIMIT 5)
            
            UNION ALL
            
            (SELECT 
                'inventory' as type, 
                CONCAT('Stock movement: ', it.transaction_type) as description, 
                CONCAT('Quantity: ', it.quantity) as details, 
                it.created_at, 
                u.full_name as user_name
            FROM inventory_transactions it
            LEFT JOIN users u ON it.created_by = u.id
            WHERE it.company_id = :company_id2
            ORDER BY it.created_at DESC
            LIMIT 5)
            
            UNION ALL
            
            (SELECT 
                'customer' as type, 
                CONCAT('New customer: ', c.full_name) as description, 
                c.customer_code as details, 
                c.created_at, 
                NULL as user_name
            FROM customers c
            WHERE c.company_id = :company_id3
            ORDER BY c.created_at DESC
            LIMIT 5)
            
            ORDER BY created_at DESC
            LIMIT 10
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            'company_id' => $companyId,
            'company_id2' => $companyId,
            'company_id3' => $companyId
        ]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Recent activity error: " . $e->getMessage());
        return [];
    }
}

// Helper function to get upcoming tasks (company-specific)
function getUpcomingTasks($db, $companyId)
{
    try {
        $sql = "
            (SELECT 
                'Overdue Invoice' as task_type,
                si.invoice_number as reference,
                si.due_date,
                c.full_name as customer_name,
                DATEDIFF(CURDATE(), si.due_date) as days_overdue,
                NULL as days_until,
                si.total_amount - si.amount_paid as amount
            FROM sales_invoices si
            JOIN customers c ON si.customer_id = c.id
            WHERE si.company_id = :company_id1
              AND si.status IN ('issued', 'partial')
              AND si.due_date < CURDATE()
              AND si.total_amount > si.amount_paid
            ORDER BY si.due_date ASC
            LIMIT 5)
            
            UNION ALL
            
            (SELECT 
                'Pending Purchase Order' as task_type,
                po.po_number as reference,
                po.expected_date as due_date,
                s.supplier_name as customer_name,
                NULL as days_overdue,
                DATEDIFF(po.expected_date, CURDATE()) as days_until,
                po.total_amount as amount
            FROM purchase_orders po
            JOIN suppliers s ON po.supplier_id = s.id
            WHERE po.company_id = :company_id2
              AND po.status = 'approved'
              AND po.expected_date IS NOT NULL
            ORDER BY po.expected_date ASC
            LIMIT 5)
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            'company_id1' => $companyId,
            'company_id2' => $companyId
        ]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("Upcoming tasks error: " . $e->getMessage());
        return [];
    }
}
?>


<!-- Main Dashboard Container -->
<div class="platform-dashboard">

    <!-- ==================== WELCOME SECTION WITH COMPANY SWITCHER ==================== -->
    <div class="welcome-section mb-4">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <div class="d-flex align-items-center">
                    <div class="welcome-avatar me-3">
                        <?php if (!empty($_SESSION['user_avatar'])): ?>
                            <img src="<?php echo asset_url('uploads/avatars/' . $_SESSION['user_avatar']); ?>" alt="Avatar"
                                class="rounded-circle" width="60" height="60">
                        <?php else: ?>
                            <div class="avatar-placeholder rounded-circle bg-primary bg-gradient d-flex align-items-center justify-content-center"
                                style="width: 60px; height: 60px;">
                                <span class="text-white fw-bold h4 mb-0">
                                    <?php echo strtoupper(substr($userName, 0, 1)); ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h2 class="h3 mb-1 text-gray-800">
                            <?php echo $greeting; ?>, <?php echo htmlspecialchars(explode(' ', $userName)[0]); ?>!
                        </h2>
                        <p class="mb-0 text-muted">
                            <i class="fas fa-building me-2"></i><?php echo htmlspecialchars($companyName); ?>
                            <span class="mx-2">|</span>
                            <i class="fas fa-calendar-alt me-2"></i><?php echo $today; ?>
                            <span class="mx-2">|</span>
                            <i class="fas fa-clock me-2"></i><span id="live-clock"></span>
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 mt-3 mt-lg-0">
                <div class="d-flex justify-content-lg-end align-items-center">
                    <!-- Company Switcher -->
                    <?php if (count($userCompanies) > 1): ?>
                    <div class="dropdown me-3">
                        <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <i class="fas fa-building me-2"></i>Switch Company
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <?php foreach ($userCompanies as $company): ?>
                            <li>
                                <a class="dropdown-item <?php echo $company['id'] == $companyId ? 'active' : ''; ?>" 
                                   href="#" onclick="switchCompany(<?php echo $company['id']; ?>)">
                                    <?php echo htmlspecialchars($company['company_name']); ?>
                                    <small class="text-muted d-block"><?php echo $company['company_code']; ?></small>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                    
                    <div class="quick-actions">
                        <a href="?page=sales/create&company_id=<?php echo $companyId; ?>" class="btn btn-primary me-2">
                            <i class="fas fa-plus-circle me-2"></i>New Invoice
                        </a>
                        <div class="dropdown">
                            <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-download me-2"></i>Export
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="?page=exports/sales&format=pdf&period=month&company_id=<?php echo $companyId; ?>">Sales Report</a></li>
                                <li><a class="dropdown-item" href="?page=exports/inventory&format=pdf&company_id=<?php echo $companyId; ?>">Inventory Report</a></li>
                                <li><a class="dropdown-item" href="?page=exports/financial&format=pdf&company_id=<?php echo $companyId; ?>">Financial Report</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== COMPANY STATS CARD ==================== -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card bg-light border-0 shadow-sm">
                <div class="card-body py-2">
                    <div class="row text-center">
                        <div class="col-md-3 col-6">
                            <small class="text-muted d-block">Active Users</small>
                            <span class="h5 mb-0 fw-bold text-primary">
                                <?php echo $companyStats['active_users'] ?? 0; ?> / <?php echo $companyStats['user_count'] ?? 1; ?>
                            </span>
                        </div>
                        <div class="col-md-3 col-6">
                            <small class="text-muted d-block">Total Customers</small>
                            <span class="h5 mb-0 fw-bold text-success">
                                <?php echo number_format($companyStats['customer_count'] ?? 0); ?>
                            </span>
                        </div>
                        <div class="col-md-3 col-6">
                            <small class="text-muted d-block">Active Products</small>
                            <span class="h5 mb-0 fw-bold text-info">
                                <?php echo number_format($companyStats['product_count'] ?? 0); ?>
                            </span>
                        </div>
                        <div class="col-md-3 col-6">
                            <small class="text-muted d-block">Currency</small>
                            <span class="h5 mb-0 fw-bold text-warning">
                                <?php echo $companyCurrency; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== KPI CARDS ==================== -->
    <div class="row mb-4">
        <!-- Sales KPI -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card dashboard-card border-left-primary shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                <i class="fas fa-chart-line me-1"></i>Today's Sales
                            </div>
                            <div class="h3 mb-0 font-weight-bold text-gray-800">
                                <?php echo format_currency($todaySales['total_sales'] ?? 0, $companyCurrency); ?>
                            </div>
                            <div class="mt-2 d-flex align-items-center">
                                <span class="badge bg-soft-success text-success me-2">
                                    <i class="fas fa-arrow-up me-1"></i><?php echo number_format((float)$growthRate, 1); ?>%
                                </span>
                                <span class="text-muted small">vs last month</span>
                            </div>
                            <div class="mt-2 small text-muted">
                                <span class="me-2"><i class="fas fa-file-invoice me-1"></i><?php echo $todaySales['invoice_count'] ?? 0; ?> invoices</span>
                                <span><i class="fas fa-hand-holding-usd me-1"></i><?php echo format_currency($todaySales['total_collected'] ?? 0, $companyCurrency); ?> collected</span>
                            </div>
                        </div>
                        <div class="col-auto">
                            <div class="icon-circle bg-primary-light">
                                <i class="fas fa-dollar-sign fa-2x text-primary"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Inventory KPI -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card dashboard-card border-left-success shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                <i class="fas fa-boxes me-1"></i>Inventory Value
                            </div>
                            <div class="h3 mb-0 font-weight-bold text-gray-800">
                                <?php echo format_currency($stockSummary['stock_value'] ?? 0, $companyCurrency); ?>
                            </div>
                            <div class="mt-2">
                                <div class="progress" style="height: 8px;">
                                    <?php
                                    $stockPercentage = ($stockSummary['total_stock'] ?? 0) > 0
                                        ? (($stockSummary['available_stock'] ?? 0) / ($stockSummary['total_stock'] ?? 1)) * 100
                                        : 0;
                                    ?>
                                    <div class="progress-bar bg-success"
                                        style="width: <?php echo min(100, $stockPercentage); ?>%" role="progressbar">
                                    </div>
                                </div>
                                <div class="mt-1 d-flex justify-content-between small text-muted">
                                    <span><i class="fas fa-cubes me-1"></i><?php echo number_format((float)($stockSummary['total_stock'] ?? 0), 0); ?> units</span>
                                    <span><?php echo $stockSummary['unique_products'] ?? 0; ?> products</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-auto">
                            <div class="icon-circle bg-success-light">
                                <i class="fas fa-cubes fa-2x text-success"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cash Position KPI -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card dashboard-card border-left-info shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                <i class="fas fa-wallet me-1"></i>Cash Position
                            </div>
                            <div class="h3 mb-0 font-weight-bold text-gray-800">
                                <?php echo format_currency(($cashPosition['bank_balance'] ?? 0) + ($cashPosition['cash_balance'] ?? 0), $companyCurrency); ?>
                            </div>
                            <div class="mt-2 small">
                                <div class="d-flex justify-content-between mb-1">
                                    <span class="text-muted">Receivables:</span>
                                    <span class="fw-bold"><?php echo format_currency($cashPosition['receivables'] ?? 0, $companyCurrency); ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted">Payables:</span>
                                    <span class="fw-bold"><?php echo format_currency($cashPosition['payables'] ?? 0, $companyCurrency); ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-auto">
                            <div class="icon-circle bg-info-light">
                                <i class="fas fa-coins fa-2x text-info"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Customers KPI -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card dashboard-card border-left-warning shadow h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                <i class="fas fa-users me-1"></i>Customers
                            </div>
                            <div class="h3 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float)($customerSummary['total_customers'] ?? 0)); ?>
                            </div>
                            <div class="mt-2 d-flex align-items-center">
                                <span class="badge bg-soft-success text-success me-2">
                                    <i class="fas fa-user-plus me-1"></i>+<?php echo $customerSummary['new_customers'] ?? 0; ?>
                                </span>
                                <span class="text-muted small">this month</span>
                            </div>
                            <div class="mt-1 small text-muted">
                                <i class="fas fa-check-circle me-1 text-success"></i><?php echo $customerSummary['active_customers'] ?? 0; ?> active
                            </div>
                        </div>
                        <div class="col-auto">
                            <div class="icon-circle bg-warning-light">
                                <i class="fas fa-user-friends fa-2x text-warning"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== MAIN CHARTS ROW ==================== -->
    <div class="row mb-4">
        <!-- Weekly Sales Chart -->
        <div class="col-xl-8 col-lg-7 mb-4">
            <div class="card shadow h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-chart-area me-2"></i>Weekly Sales Trend
                        <small class="text-muted ms-2">(<?php echo htmlspecialchars($companyName); ?>)</small>
                    </h6>
                    <div class="btn-group" role="group">
                        <button type="button" class="btn btn-sm btn-outline-primary active"
                            onclick="changeChartPeriod('week')">Week</button>
                        <button type="button" class="btn btn-sm btn-outline-primary"
                            onclick="changeChartPeriod('month')">Month</button>
                        <button type="button" class="btn btn-sm btn-outline-primary"
                            onclick="changeChartPeriod('year')">Year</button>
                    </div>
                </div>
                <div class="card-body" style="position: relative; height: 300px; overflow: hidden;">
                    <canvas id="weeklySalesChart" style="display: block; width: 100%; height: 100%;"></canvas>
                </div>
            </div>
        </div>

        <!-- Status Distribution Chart -->
        <div class="col-xl-4 col-lg-5 mb-4">
            <div class="card shadow h-100">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-chart-pie me-2"></i>Sales by Status
                    </h6>
                </div>
                <div class="card-body" style="position: relative; height: 250px; overflow: hidden;">
                    <canvas id="statusPieChart" style="display: block; width: 100%; height: 100%;"></canvas>
                </div>
                <div class="mt-2 text-center" id="statusLegend"></div>
                <hr class="my-2">
                <div class="row text-center pb-3">
                    <div class="col-6">
                        <div class="small text-muted">Total Invoices</div>
                        <div class="h5 mb-0 fw-bold">
                            <?php
                            $totalInvoices = 0;
                            foreach ($statusSales as $stat) {
                                $totalInvoices += $stat['count'] ?? 0;
                            }
                            echo $totalInvoices;
                            ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="small text-muted">Total Value</div>
                        <div class="h5 mb-0 fw-bold">
                            <?php
                            $totalValue = 0;
                            foreach ($statusSales as $stat) {
                                $totalValue += $stat['total'] ?? 0;
                            }
                            echo format_currency($totalValue, $companyCurrency);
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== SECOND ROW ==================== -->
    <div class="row mb-4">
        <!-- Low Stock Alerts -->
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card shadow h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>Low Stock Alerts
                        <?php if (count($lowStock) > 0): ?>
                            <span class="badge bg-danger ms-2"><?php echo count($lowStock); ?></span>
                        <?php endif; ?>
                    </h6>
                    <a href="?page=inventory/stock&company_id=<?php echo $companyId; ?>" class="btn btn-sm btn-link">View All</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($lowStock)): ?>
                        <div class="text-center py-4">
                            <div class="text-success mb-2">
                                <i class="fas fa-check-circle fa-3x"></i>
                            </div>
                            <p class="mb-0 text-muted">All stock levels are healthy!</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($lowStock as $item): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo htmlspecialchars($item['product_name'] ?? ''); ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($item['sku'] ?? $item['variant_code'] ?? ''); ?> -
                                            <?php echo htmlspecialchars($item['warehouse_name'] ?? ''); ?>
                                        </small>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-warning text-dark">
                                            <?php echo number_format((float)($item['available_quantity'] ?? $item['quantity'] ?? 0), 0); ?> left
                                        </span>
                                        <br>
                                        <small class="text-muted">Min: <?php echo $item['reorder_level'] ?? 0; ?></small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-white py-2">
                    <div class="small text-muted d-flex justify-content-between">
                        <span><i class="fas fa-thermometer-half me-1"></i>Stock Health</span>
                        <span class="fw-bold text-<?php echo ($stockSummary['low_stock'] ?? 0) > 0 ? 'warning' : 'success'; ?>">
                            <?php echo $stockSummary['low_stock'] ?? 0; ?> low /
                            <?php echo $stockSummary['out_of_stock'] ?? 0; ?> out
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Top Selling Products -->
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card shadow h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-success">
                        <i class="fas fa-crown me-2"></i>Top Selling Products
                    </h6>
                    <a href="?page=reports/sales&company_id=<?php echo $companyId; ?>" class="btn btn-sm btn-link">Full Report</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($topProducts)): ?>
                        <div class="text-center py-4">
                            <div class="text-muted mb-2">
                                <i class="fas fa-box-open fa-3x"></i>
                            </div>
                            <p class="mb-0 text-muted">No sales data this month</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Product</th>
                                        <th class="text-center">Qty</th>
                                        <th class="text-end">Revenue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($topProducts as $product): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($product['product_name'] ?? ''); ?></strong>
                                                <br>
                                                <small class="text-muted"><?php echo htmlspecialchars($product['sku'] ?? $product['variant_code'] ?? ''); ?></small>
                                            </td>
                                            <td class="text-center fw-bold">
                                                <?php echo number_format((float)($product['qty_sold'] ?? $product['total_sold'] ?? 0), 0); ?>
                                            </td>
                                            <td class="text-end">
                                                <?php echo format_currency($product['revenue'] ?? $product['total_revenue'] ?? 0, $companyCurrency); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-white py-2">
                    <div class="small text-muted">
                        <i class="fas fa-calendar me-1"></i>This month's performance
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Payments -->
        <div class="col-xl-4 col-md-12 mb-4">
            <div class="card shadow h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-info">
                        <i class="fas fa-credit-card me-2"></i>Recent Payments
                    </h6>
                    <a href="?page=sales/invoices&company_id=<?php echo $companyId; ?>" class="btn btn-sm btn-link">View All</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recentPayments)): ?>
                        <div class="text-center py-4">
                            <div class="text-muted mb-2">
                                <i class="fas fa-money-bill-wave fa-3x"></i>
                            </div>
                            <p class="mb-0 text-muted">No recent payments</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($recentPayments as $payment): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <strong><?php echo htmlspecialchars($payment['customer_name'] ?? $payment['full_name'] ?? ''); ?></strong>
                                            <br>
                                            <small class="text-muted">
                                                <?php echo $payment['payment_number'] ?? ''; ?> •
                                                <?php echo str_replace('_', ' ', $payment['payment_method'] ?? $payment['method'] ?? ''); ?>
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <span class="fw-bold text-success">
                                                <?php echo format_currency($payment['amount'] ?? 0, $companyCurrency); ?>
                                            </span>
                                            <br>
                                            <small class="text-muted">
                                                <?php echo isset($payment['payment_date']) ? format_date($payment['payment_date'], 'd/m/Y') : ''; ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== THIRD ROW ==================== -->
    <div class="row">
        <!-- Financial Health -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card shadow h-100">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-heartbeat me-2"></i>Financial Health
                    </h6>
                </div>
                <div class="card-body">
                    <div class="financial-metrics">
                        <div class="metric-item mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted">Total Assets</span>
                                <span class="fw-bold"><?php echo format_currency($totalAssets, $companyCurrency); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted">Liabilities</span>
                                <span class="fw-bold text-danger"><?php echo format_currency($totalLiabilities, $companyCurrency); ?></span>
                            </div>
                            <hr class="my-2">
                            <div class="d-flex justify-content-between">
                                <span class="fw-bold">Net Worth</span>
                                <span class="fw-bold text-<?php echo $netWorth >= 0 ? 'success' : 'danger'; ?>">
                                    <?php echo format_currency($netWorth, $companyCurrency); ?>
                                </span>
                            </div>
                        </div>

                        <div class="metric-item">
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Current Ratio</span>
                                <span class="fw-bold">
                                    <?php echo number_format((float)($totalLiabilities > 0 ? $totalAssets / $totalLiabilities : $totalAssets), 2); ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Inventory Turnover</span>
                                <span class="fw-bold"><?php echo number_format((float)$turnoverRatio, 1); ?>x</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Upcoming Tasks -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card shadow h-100">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-danger">
                        <i class="fas fa-clock me-2"></i>Upcoming Tasks
                        <?php if (count($upcomingTasks) > 0): ?>
                            <span class="badge bg-danger ms-2"><?php echo count($upcomingTasks); ?></span>
                        <?php endif; ?>
                    </h6>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($upcomingTasks)): ?>
                        <div class="text-center py-4">
                            <div class="text-success mb-2">
                                <i class="fas fa-check-circle fa-3x"></i>
                            </div>
                            <p class="mb-0 text-muted">All caught up!</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach (array_slice($upcomingTasks, 0, 4) as $task): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <?php
                                            $taskType = $task['task_type'] ?? '';
                                            $badgeClass = ($taskType == 'Overdue Invoice') ? 'danger' : 'warning';
                                            ?>
                                            <span class="badge bg-<?php echo $badgeClass; ?> mb-1">
                                                <?php echo $taskType; ?>
                                            </span>
                                            <br>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($task['reference'] ?? ''); ?> -
                                                <?php echo htmlspecialchars($task['customer_name'] ?? $task['supplier_name'] ?? ''); ?>
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <?php
                                            $daysBadgeClass = (!empty($task['days_overdue'])) ? 'danger' : 'info';
                                            $daysText = (!empty($task['days_overdue']))
                                                ? $task['days_overdue'] . ' days overdue'
                                                : ($task['days_until'] ?? 0) . ' days left';
                                            ?>
                                            <span class="badge bg-<?php echo $daysBadgeClass; ?>">
                                                <?php echo $daysText; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-white py-2">
                    <a href="?page=reports/dashboard&company_id=<?php echo $companyId; ?>" class="small text-decoration-none">
                        View all tasks <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
        </div>

        <!-- Recent Customers -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card shadow h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-info">
                        <i class="fas fa-user-plus me-2"></i>New Customers
                    </h6>
                    <a href="?page=sales/customers&company_id=<?php echo $companyId; ?>" class="btn btn-sm btn-link">View All</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recentCustomers)): ?>
                        <div class="text-center py-4">
                            <div class="text-muted mb-2">
                                <i class="fas fa-user-friends fa-3x"></i>
                            </div>
                            <p class="mb-0 text-muted">No new customers</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($recentCustomers as $customer): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?php echo htmlspecialchars($customer['full_name'] ?? $customer['customer_name'] ?? ''); ?></strong>
                                            <br>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($customer['customer_code'] ?? ''); ?>
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <small class="text-muted">
                                                <?php echo isset($customer['created_at']) ? format_date($customer['created_at'], 'd M') : ''; ?>
                                            </small>
                                            <br>
                                            <?php if (!empty($customer['phone'])): ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($customer['phone']); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Stats -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card shadow h-100">
                <div class="card-header bg-white py-3">
                    <h6 class="m-0 font-weight-bold text-secondary">
                        <i class="fas fa-tachometer-alt me-2"></i>Quick Stats
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="stat-item text-center p-2 rounded bg-light">
                                <i class="fas fa-file-invoice fa-2x text-primary mb-2"></i>
                                <h5 class="fw-bold mb-0"><?php echo $monthlySales['invoice_count'] ?? 0; ?></h5>
                                <small class="text-muted">Monthly Invoices</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-item text-center p-2 rounded bg-light">
                                <i class="fas fa-shopping-cart fa-2x text-success mb-2"></i>
                                <h5 class="fw-bold mb-0"><?php echo $poSummary['total_pos'] ?? 0; ?></h5>
                                <small class="text-muted">Purchase Orders</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-item text-center p-2 rounded bg-light">
                                <i class="fas fa-hourglass-half fa-2x text-warning mb-2"></i>
                                <h5 class="fw-bold mb-0"><?php echo $stockSummary['low_stock'] ?? 0; ?></h5>
                                <small class="text-muted">Low Stock</small>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-item text-center p-2 rounded bg-light">
                                <i class="fas fa-exclamation-circle fa-2x text-danger mb-2"></i>
                                <h5 class="fw-bold mb-0">
                                    <?php
                                    $overdueCount = 0;
                                    foreach ($upcomingTasks as $task) {
                                        if (($task['task_type'] ?? '') == 'Overdue Invoice') {
                                            $overdueCount++;
                                        }
                                    }
                                    echo $overdueCount;
                                    ?>
                                </h5>
                                <small class="text-muted">Overdue Items</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    
</div>

<!-- Company Switcher Script -->
<script>
function switchCompany(companyId) {
    fetch('api/company/switch.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': '<?php echo $_SESSION['csrf_token']; ?>'
        },
        body: JSON.stringify({ company_id: companyId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Failed to switch company: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to switch company');
    });
}
</script>

<!-- Chart.js Scripts -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Live clock
function updateClock() {
    const now = new Date();
    const timeString = now.toLocaleTimeString('en-US', { 
        hour: '2-digit', 
        minute: '2-digit', 
        second: '2-digit' 
    });
    const clockElement = document.getElementById('live-clock');
    if (clockElement) {
        clockElement.textContent = timeString;
    }
}
setInterval(updateClock, 1000);
updateClock();

document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        initializeCharts();
    }, 100);
});

function initializeCharts() {
    // Weekly Sales Chart Data
    <?php
    $days = [];
    $sales = [];
    $invoices = [];

    $last7Days = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $dayName = date('D', strtotime("-$i days"));
        $last7Days[$date] = [
            'day' => $dayName,
            'sales' => 0,
            'invoices' => 0
        ];
    }

    foreach ($weeklySales as $day) {
        if (isset($day['date']) && isset($last7Days[$day['date']])) {
            $last7Days[$day['date']]['sales'] = (float) ($day['total_sales'] ?? $day['sales'] ?? 0);
            $last7Days[$day['date']]['invoices'] = (int) ($day['invoice_count'] ?? $day['invoices'] ?? 0);
        }
    }

    foreach ($last7Days as $dayData) {
        $days[] = $dayData['day'];
        $sales[] = $dayData['sales'];
        $invoices[] = $dayData['invoices'];
    }
    ?>

    const weeklyCanvas = document.getElementById('weeklySalesChart');
    if (weeklyCanvas) {
        window.salesChart = new Chart(weeklyCanvas, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($days); ?>,
                datasets: [
                    {
                        label: 'Sales (<?php echo $companyCurrency; ?>)',
                        data: <?php echo json_encode($sales); ?>,
                        borderColor: '#4e73df',
                        backgroundColor: 'rgba(78, 115, 223, 0.05)',
                        borderWidth: 2,
                        pointRadius: 3,
                        pointBackgroundColor: '#4e73df',
                        pointBorderColor: '#ffffff',
                        pointHoverRadius: 5,
                        tension: 0.3,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Invoices',
                        data: <?php echo json_encode($invoices); ?>,
                        borderColor: '#1cc88a',
                        backgroundColor: 'rgba(28, 200, 138, 0.05)',
                        borderWidth: 2,
                        pointRadius: 3,
                        pointBackgroundColor: '#1cc88a',
                        pointBorderColor: '#ffffff',
                        pointHoverRadius: 5,
                        tension: 0.3,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        position: 'left',
                        ticks: {
                            callback: function(value) {
                                if (value >= 1000000) return (value / 1000000).toFixed(1) + 'M';
                                if (value >= 1000) return (value / 1000).toFixed(0) + 'K';
                                return value;
                            }
                        }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        ticks: { stepSize: 1 }
                    }
                }
            }
        });
    }

    // Status Pie Chart
    <?php
    $statusLabels = [];
    $statusValues = [];
    $statusColors = [];
    $totalStatusValue = 0;

    foreach ($statusSales as $status) {
        $statusLabels[] = $status['status'] ?? '';
        $value = (float) ($status['total'] ?? 0);
        $statusValues[] = $value;
        $totalStatusValue += $value;

        if ($status['status'] === 'paid')
            $color = '#1cc88a';
        elseif ($status['status'] === 'partial')
            $color = '#f6c23e';
        elseif ($status['status'] === 'overdue')
            $color = '#e74a3b';
        elseif ($status['status'] === 'issued')
            $color = '#4e73df';
        else
            $color = '#858796';
        $statusColors[] = $color;
    }
    ?>

    const statusCanvas = document.getElementById('statusPieChart');
    if (statusCanvas) {
        window.statusChart = new Chart(statusCanvas, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($statusLabels); ?>,
                datasets: [{
                    data: <?php echo json_encode($statusValues); ?>,
                    backgroundColor: <?php echo json_encode($statusColors); ?>,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const value = context.raw;
                                const percentage = <?php echo $totalStatusValue; ?> > 0
                                    ? ((value / <?php echo $totalStatusValue; ?>) * 100).toFixed(1)
                                    : 0;
                                return `${context.label}: <?php echo $companyCurrency; ?> ${value.toLocaleString()} (${percentage}%)`;
                            }
                        }
                    },
                    legend: { display: false }
                }
            }
        });
    }
}

function changeChartPeriod(period) {
    window.location.href = '?page=dashboard&period=' + period + '&company_id=<?php echo $companyId; ?>';
}

function refreshActivity() {
    location.reload();
}
</script>

<!-- Dashboard Styles -->
<style>
.platform-dashboard .dashboard-card {
    border: none;
    border-radius: 0.75rem;
    transition: transform 0.2s;
}
.platform-dashboard .dashboard-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.1) !important;
}
.platform-dashboard .border-left-primary { border-left: 0.25rem solid #4e73df !important; }
.platform-dashboard .border-left-success { border-left: 0.25rem solid #1cc88a !important; }
.platform-dashboard .border-left-info { border-left: 0.25rem solid #36b9cc !important; }
.platform-dashboard .border-left-warning { border-left: 0.25rem solid #f6c23e !important; }
.platform-dashboard .border-left-danger { border-left: 0.25rem solid #e74a3b !important; }
.platform-dashboard .icon-circle { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
.platform-dashboard .bg-primary-light { background-color: rgba(78, 115, 223, 0.1); }
.platform-dashboard .bg-success-light { background-color: rgba(28, 200, 138, 0.1); }
.platform-dashboard .bg-info-light { background-color: rgba(54, 185, 204, 0.1); }
.platform-dashboard .bg-warning-light { background-color: rgba(246, 194, 62, 0.1); }
.platform-dashboard .bg-danger-light { background-color: rgba(231, 74, 59, 0.1); }
.platform-dashboard .bg-soft-success { background-color: rgba(40, 167, 69, 0.1); color: #28a745; }
.platform-dashboard .avatar-placeholder { box-shadow: 0 0 0 3px rgba(78, 115, 223, 0.2); }
.platform-dashboard .list-group-item { border-left: none; border-right: none; padding: 0.75rem 1rem; }
.platform-dashboard .list-group-item:first-child { border-top: none; }
.platform-dashboard .list-group-item:last-child { border-bottom: none; }
.platform-dashboard .progress { border-radius: 1rem; background-color: #eaecf4; }
.platform-dashboard .stat-item { transition: all 0.2s; }
.platform-dashboard .stat-item:hover { background-color: #e3e6f0 !important; transform: scale(1.02); }
@media (max-width: 768px) {
    .platform-dashboard .welcome-section .d-flex { flex-direction: column; text-align: center; }
    .platform-dashboard .quick-actions { justify-content: center !important; margin-top: 1rem; }
}
</style>