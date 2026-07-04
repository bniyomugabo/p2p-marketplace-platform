<?php
// pages/sales/dashboard.php
declare(strict_types=1);

$pageTitle = 'Sales Dashboard - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';

// Load models
require_once __DIR__ . '/../../models/Sale.php';
require_once __DIR__ . '/../../models/Customer.php';
require_once __DIR__ . '/../../models/Product.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Initialize models with company context
$saleModel = new Sale($companyId);
$customerModel = new Customer($companyId);
$productModel = new Product($companyId);

// Get date range for filters
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Get dashboard data (company-specific)
$todaySales = $saleModel->getDailySummary(date('Y-m-d'));
$weeklyTrend = $saleModel->getWeeklyTrend();
$salesByStatus = $saleModel->getSalesByStatus();
$topProducts = $saleModel->getTopProducts(10);
$recentPayments = $saleModel->getRecentPayments(10);
$totalReceivables = $saleModel->getTotalReceivables();
$growthRate = $saleModel->getGrowthRate();

// Get sales summary for date range
$salesSummary = $saleModel->getSalesSummary($dateFrom, $dateTo);

// Get customer summary (company-specific)
$customerSummary = $customerModel->getSummary();

// Format currency for the current company
$currency = $_SESSION['company_currency'] ?? 'RWF';
?>

<div class="sales-dashboard">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-chart-line me-2"></i>Sales Dashboard
                    </h2>
                    <p class="mb-0 text-muted">Overview of your sales performance and metrics</p>
                </div>
                <div>
                    <a href="?page=sales/create" class="btn btn-primary me-2">
                        <i class="fas fa-plus-circle me-2"></i>New Sale
                    </a>
                    <a href="?page=sales/invoices" class="btn btn-info">
                        <i class="fas fa-file-invoice me-2"></i>View Invoices
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

    <!-- Key Metrics Cards -->
    <div class="row mb-4">
        <!-- Period Sales -->
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Sales (<?php echo date('d/m', strtotime($dateFrom)); ?> -
                                <?php echo date('d/m', strtotime($dateTo)); ?>)
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo format_currency($salesSummary['total_sales'] ?? 0, $companyId); ?>
                            </div>
                            <div class="small text-muted">
                                <?php echo number_format((float)$salesSummary['total_invoices'] ?? 0); ?> invoices
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-chart-line fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Today's Sales -->
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                Today's Sales
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo format_currency($todaySales['total_sales'] ?? 0, $companyId); ?>
                            </div>
                            <div class="small text-muted">
                                <?php echo $todaySales['invoice_count'] ?? 0; ?> invoices
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-calendar-day fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Receivables -->
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Outstanding Receivables
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo format_currency($totalReceivables, $companyId); ?>
                            </div>
                            <div class="small text-muted">
                                Awaiting payment
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-clock fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Customers -->
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Total Customers
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float)(float) ($customerSummary['total_customers'] ?? 0)); ?>
                            </div>
                            <div class="small text-muted">
                                <?php echo $customerSummary['new_customers'] ?? 0; ?> new this month
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-users fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Additional Metrics Row -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Collected Amount
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo format_currency($salesSummary['total_collected'] ?? 0, $companyId); ?>
                            </div>
                            <div class="small text-muted">
                                During selected period
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-hand-holding-usd fa-2x text-gray-300"></i>
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
                                Items Sold
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float)$salesSummary['total_items_sold'] ?? 0); ?>
                            </div>
                            <div class="small text-muted">
                                <?php echo number_format((float)$salesSummary['avg_items_per_order'] ?? 0, 1); ?> avg per order
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-boxes fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Average Order Value
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo format_currency($salesSummary['avg_order_value'] ?? 0, $companyId); ?>
                            </div>
                            <div class="small text-muted">
                                Per transaction
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-receipt fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Growth Rate (MoM)
                            </div>
                            <div
                                class="h5 mb-0 font-weight-bold text-gray-800 <?php echo $growthRate['rate'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo number_format((float)(float) $growthRate['rate'], 1); ?>%
                            </div>
                            <div class="small text-muted">
                                vs last month (<?php echo format_currency($growthRate['previous'], $companyId); ?>)
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

    <!-- Date Filter -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow">
                <div class="card-body">
                    <form method="GET" action="" class="row g-3">
                        <input type="hidden" name="page" value="sales">

                        <div class="col-md-4">
                            <label for="date_from" class="form-label">Date From</label>
                            <input type="date" class="form-control" id="date_from" name="date_from"
                                value="<?php echo $dateFrom; ?>">
                        </div>

                        <div class="col-md-4">
                            <label for="date_to" class="form-label">Date To</label>
                            <input type="date" class="form-control" id="date_to" name="date_to"
                                value="<?php echo $dateTo; ?>">
                        </div>

                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="fas fa-filter me-2"></i>Apply
                            </button>
                            <a href="?page=sales" class="btn btn-secondary">
                                <i class="fas fa-undo me-2"></i>Reset
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row mb-4">
        <!-- Sales Trend Chart -->
        <div class="col-xl-8 col-lg-7 mb-4">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-chart-line me-2"></i>Sales Trend (Last 7 Days)
                    </h6>
                </div>
                <div class="card-body">
                    <?php if (empty($weeklyTrend)): ?>
                        <div class="text-center py-5">
                            <div class="text-muted">
                                <i class="fas fa-chart-line fa-4x mb-3"></i>
                                <p class="mb-0">No sales data available for the last 7 days</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <canvas id="salesTrendChart" height="300"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Sales by Status -->
        <div class="col-xl-4 col-lg-5 mb-4">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-chart-pie me-2"></i>Sales by Status
                    </h6>
                </div>
                <div class="card-body">
                    <?php if (empty($salesByStatus)): ?>
                        <div class="text-center py-5">
                            <div class="text-muted">
                                <i class="fas fa-chart-pie fa-4x mb-3"></i>
                                <p class="mb-0">No invoice data available</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <canvas id="salesStatusChart" height="300"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Tables Row -->
    <div class="row">
        <!-- Top Products -->
        <div class="col-xl-6 mb-4">
            <div class="card shadow h-100">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-crown me-2"></i>Top Selling Products
                        <span class="badge bg-primary ms-2">This Month</span>
                    </h6>
                    <a href="?page=reports/products" class="btn btn-sm btn-primary">View All</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-hover mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Product</th>
                                    <th class="text-end">Sold</th>
                                    <th class="text-end">Revenue</th>
                                    <th class="text-end">%</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($topProducts)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">
                                            <i class="fas fa-box-open fa-2x mb-2 d-block"></i>
                                            No sales data available
                                        </td>
                                    </tr>
                                <?php else:
                                    $totalRevenue = array_sum(array_column($topProducts, 'total_revenue'));
                                    ?>
                                    <?php foreach ($topProducts as $index => $product): ?>
                                        <tr>
                                            <td>
                                                <?php if ($index < 3): ?>
                                                    <i class="fas fa-medal text-warning me-1"></i>
                                                <?php endif; ?>
                                                <strong><?php echo htmlspecialchars($product['product_name']); ?></strong>
                                                <?php if (!empty($product['variant_name']) && $product['variant_name'] !== 'Standard'): ?>
                                                    <br><small class="text-muted">
                                                        <?php echo htmlspecialchars($product['variant_name']); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end fw-bold">
                                                <?php echo number_format((float)(float) $product['total_sold'], 0); ?>
                                            </td>
                                            <td class="text-end text-success">
                                                <?php echo format_currency($product['total_revenue'], $companyId); ?>
                                            </td>
                                            <td class="text-end">
                                                <div class="d-flex align-items-center">
                                                    <div class="progress flex-grow-1 me-2" style="height: 4px;">
                                                        <div class="progress-bar bg-success"
                                                            style="width: <?php echo $totalRevenue > 0 ? ($product['total_revenue'] / $totalRevenue) * 100 : 0; ?>%">
                                                        </div>
                                                    </div>
                                                    <small><?php echo $totalRevenue > 0 ? number_format((float)($product['total_revenue'] / $totalRevenue) * 100, 1) : 0; ?>%</small>
                                                </div>
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

        <!-- Recent Payments -->
        <div class="col-xl-6 mb-4">
            <div class="card shadow h-100">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-credit-card me-2"></i>Recent Payments
                        <span class="badge bg-primary ms-2">Last 10</span>
                    </h6>
                    <a href="?page=sales/payments" class="btn btn-sm btn-primary">View All</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                        <table class="table table-hover mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Date</th>
                                    <th>Invoice #</th>
                                    <th>Customer</th>
                                    <th>Method</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentPayments)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">
                                            <i class="fas fa-credit-card fa-2x mb-2 d-block"></i>
                                            No recent payments
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recentPayments as $payment): ?>
                                        <tr>
                                            <td>
                                                <span title="<?php echo htmlspecialchars($payment['payment_date']); ?>">
                                                    <i class="far fa-calendar-alt me-1"></i>
                                                    <?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="?page=sales/view-invoice&id=<?php echo $payment['invoice_id']; ?>"
                                                    class="text-decoration-none">
                                                    <code><?php echo htmlspecialchars($payment['invoice_number']); ?></code>
                                                </a>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($payment['customer_name']); ?></strong>
                                            </td>
                                            <td>
                                                <?php
                                                $methodIcons = [
                                                    'cash' => 'fa-money-bill-wave',
                                                    'bank_transfer' => 'fa-university',
                                                    'mobile' => 'fa-mobile-alt',
                                                    'card' => 'fa-credit-card',
                                                    'cheque' => 'fa-money-check'
                                                ];
                                                $icon = $methodIcons[$payment['payment_method']] ?? 'fa-credit-card';
                                                $methodLabels = [
                                                    'cash' => 'Cash',
                                                    'bank_transfer' => 'Bank Transfer',
                                                    'mobile' => 'Mobile Money',
                                                    'card' => 'Card',
                                                    'cheque' => 'Cheque'
                                                ];
                                                ?>
                                                <i class="fas <?php echo $icon; ?> me-1"></i>
                                                <?php echo $methodLabels[$payment['payment_method']] ?? ucfirst($payment['payment_method']); ?>
                                            </td>
                                            <td class="text-end text-success fw-bold">
                                                <?php echo format_currency($payment['amount'], $companyId); ?>
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
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Sales Trend Chart
        const weeklyData = <?php echo json_encode($weeklyTrend); ?>;

        <?php if (!empty($weeklyTrend)): ?>
            const trendCtx = document.getElementById('salesTrendChart').getContext('2d');

            // Get day names in order (last 7 days)
            const dayOrder = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            const sortedData = [...weeklyData].sort((a, b) => {
                return dayOrder.indexOf(a.day_name) - dayOrder.indexOf(b.day_name);
            });

            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: sortedData.map(d => d.day_name.substring(0, 3)),
                    datasets: [{
                        label: 'Sales Amount (<?php echo $currency; ?>)',
                        data: sortedData.map(d => d.total_sales),
                        borderColor: '#4e73df',
                        backgroundColor: 'rgba(78, 115, 223, 0.05)',
                        borderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#4e73df',
                        tension: 0.3,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    return '<?php echo $currency; ?> ' + context.raw.toLocaleString();
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function (value) {
                                    return '<?php echo $currency; ?> ' + value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
        <?php endif; ?>

        // Sales Status Chart
        const statusData = <?php echo json_encode($salesByStatus); ?>;

        <?php if (!empty($salesByStatus)): ?>
            const statusCtx = document.getElementById('salesStatusChart').getContext('2d');

            const statusLabels = {
                'paid': 'Paid',
                'partial': 'Partial',
                'overdue': 'Overdue',
                'issued': 'Issued',
                'draft': 'Draft',
                'cancelled': 'Cancelled'
            };

            const statusColors = {
                'paid': '#1cc88a',
                'partial': '#f6c23e',
                'overdue': '#e74a3b',
                'issued': '#36b9cc',
                'draft': '#858796',
                'cancelled': '#e74a3b'
            };

            const labels = statusData.map(d => statusLabels[d.status] || d.status);
            const values = statusData.map(d => d.total);
            const backgroundColors = statusData.map(d => statusColors[d.status] || '#858796');

            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: values,
                        backgroundColor: backgroundColors,
                        borderWidth: 0,
                        hoverOffset: 10
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '60%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                padding: 15,
                                font: { size: 11 }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function (context) {
                                    const label = context.label || '';
                                    const value = context.raw;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((value / total) * 100).toFixed(1);
                                    return `${label}: <?php echo $currency; ?> ${value.toLocaleString()} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        <?php endif; ?>
    });
</script>

<style>
    .sales-dashboard .border-left-primary {
        border-left: 4px solid #4e73df !important;
    }

    .sales-dashboard .border-left-success {
        border-left: 4px solid #1cc88a !important;
    }

    .sales-dashboard .border-left-info {
        border-left: 4px solid #36b9cc !important;
    }

    .sales-dashboard .border-left-warning {
        border-left: 4px solid #f6c23e !important;
    }

    .sales-dashboard .table td {
        vertical-align: middle;
    }

    .sales-dashboard .card {
        transition: transform 0.2s ease;
    }

    .sales-dashboard .card:hover {
        transform: translateY(-2px);
    }

    .sales-dashboard .sticky-top {
        position: sticky;
        top: 0;
        z-index: 10;
        background-color: #f8f9fc;
    }

    .sales-dashboard .progress {
        background-color: #e9ecef;
        border-radius: 10px;
    }

    .sales-dashboard .fa-medal {
        font-size: 0.9rem;
    }

    .table-responsive {
        scrollbar-width: thin;
    }

    .table-responsive::-webkit-scrollbar {
        width: 6px;
        height: 6px;
    }

    .table-responsive::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }

    .table-responsive::-webkit-scrollbar-thumb {
        background: #cbd5e0;
        border-radius: 10px;
    }
</style>