<?php
// pages/purchasing/dashboard.php
declare(strict_types=1);

$pageTitle = 'Purchasing Dashboard - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';

// Load models
require_once __DIR__ . '/../../models/PurchaseOrder.php';
require_once __DIR__ . '/../../models/Supplier.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR', 'ACC', 'WHS'])) {
    SessionManager::flash('error', 'You do not have permission to access purchasing.');
    header('Location: ' . route_url('dashboard'));
    exit;
}

// Check if company context is set
if (!$companyId) {
    SessionManager::flash('error', 'Company context not found. Please log in again.');
    header('Location: ' . route_url('login'));
    exit;
}

// Initialize models with company ID
$purchaseOrderModel = new PurchaseOrder($companyId);
$supplierModel = new Supplier($companyId);

// Get date range for filters
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Get dashboard data using models (already company-filtered)
$summary = $purchaseOrderModel->getSummary();
$recentOrders = $purchaseOrderModel->getRecent(10);
$pendingOrders = $purchaseOrderModel->getPendingOrders();
$supplierStats = $supplierModel->getStats();

// Get monthly trend data with company filter
$trendSql = "
    SELECT 
        DATE_FORMAT(order_date, '%Y-%m') as month,
        COUNT(*) as order_count,
        SUM(total_amount) as total_value
    FROM purchase_orders
    WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    AND status != 'cancelled'
    AND company_id = :company_id
    GROUP BY DATE_FORMAT(order_date, '%Y-%m')
    ORDER BY month ASC
";
$trendStmt = $db->prepare($trendSql);
$trendStmt->execute(['company_id' => $companyId]);
$monthlyTrend = $trendStmt->fetchAll();

// Get status counts for chart (using model data or direct query)
$statusCounts = [
    'draft' => $summary['draft_count'] ?? 0,
    'pending' => $summary['pending_count'] ?? 0,
    'approved' => $summary['approved_count'] ?? 0,
    'partial' => $summary['partial_count'] ?? 0,
    'received' => $summary['received_count'] ?? 0,
    'cancelled' => $summary['cancelled_count'] ?? 0
];
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-truck-loading me-2"></i>Purchasing Dashboard
                    </h2>
                    <p class="mb-0 text-muted">Manage purchase orders and suppliers</p>
                </div>
                <div>
                    <a href="?page=purchasing/create-order" class="btn btn-primary">
                        <i class="fas fa-plus-circle me-2"></i>New Purchase Order
                    </a>
                    <a href="?page=purchasing/suppliers" class="btn btn-success">
                        <i class="fas fa-user-plus me-2"></i>Suppliers
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if ($flash = SessionManager::flash('success')): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($flash); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($flash = SessionManager::flash('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($flash); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="row">
        <!-- Total Orders -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Total Orders
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) ($summary['total_orders'] ?? 0)); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-shopping-cart fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Orders -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Pending Orders
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo ($summary['approved_count'] ?? 0) + ($summary['partial_count'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-clock fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Suppliers -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Active Suppliers
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) ($supplierStats['active_suppliers'] ?? 0)); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-users fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Value -->
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                Pending Value
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo format_currency($summary['pending_value'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-dollar-sign fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row">
        <!-- Monthly Trend Chart -->
        <div class="col-xl-8 col-lg-7 mb-4">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-chart-line me-2"></i>Monthly Purchase Trend
                    </h6>
                </div>
                <div class="card-body">
                    <canvas id="purchaseTrendChart" height="300"></canvas>
                </div>
            </div>
        </div>

        <!-- Status Distribution -->
        <div class="col-xl-4 col-lg-5 mb-4">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-chart-pie me-2"></i>Orders by Status
                    </h6>
                </div>
                <div class="card-body">
                    <canvas id="orderStatusChart" height="300"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Two Column Tables -->
    <div class="row">
        <!-- Recent Orders -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-history me-2"></i>Recent Purchase Orders
                    </h6>
                    <a href="?page=purchasing/orders" class="btn btn-sm btn-primary">View All</a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>PO #</th>
                                    <th>Supplier</th>
                                    <th>Date</th>
                                    <th class="text-end">Total</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentOrders)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-3">No recent orders</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recentOrders as $order): ?>
                                        <tr>
                                            <td>
                                                <a href="?page=purchasing/orders&action=view&id=<?php echo $order['id']; ?>">
                                                    <?php echo htmlspecialchars($order['po_number']); ?>
                                                </a>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($order['supplier_name']); ?>
                                            </td>
                                            <td>
                                                <?php echo date('d/m/Y', strtotime($order['order_date'])); ?>
                                            </td>
                                            <td class="text-end">
                                                <?php echo format_currency($order['total_amount']); ?>
                                            </td>
                                            <td>
                                                <?php
                                                $statusClass = [
                                                    'draft' => 'secondary',
                                                    'pending' => 'info',
                                                    'approved' => 'primary',
                                                    'received' => 'success',
                                                    'partial' => 'warning',
                                                    'cancelled' => 'danger'
                                                ][$order['status']] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?php echo $statusClass; ?>">
                                                    <?php echo ucfirst($order['status']); ?>
                                                </span>
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

        <!-- Pending Receipts -->
        <div class="col-lg-6 mb-4">
            <div class="card shadow">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-clock me-2"></i>Pending Receipts
                    </h6>
                    <a href="?page=purchasing/receiving" class="btn btn-sm btn-primary">Process Receipts</a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>PO #</th>
                                    <th>Supplier</th>
                                    <th>Expected</th>
                                    <th class="text-end">Ordered</th>
                                    <th class="text-end">Received</th>
                                    <th>Progress</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($pendingOrders)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-3">No pending receipts</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($pendingOrders as $order):
                                        $ordered = (float) $order['total_ordered'];
                                        $received = (float) $order['total_received'];
                                        $progress = $ordered > 0
                                            ? min(100, round(($received / $ordered) * 100))
                                            : 0;
                                        ?>
                                        <tr>
                                            <td>
                                                <a href="?page=purchasing/receiving&po_id=<?php echo $order['id']; ?>">
                                                    <?php echo htmlspecialchars($order['po_number']); ?>
                                                </a>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($order['supplier_name']); ?>
                                            </td>
                                            <td>
                                                <?php echo date('d/m/Y', strtotime($order['expected_date'])); ?>
                                            </td>
                                            <td class="text-end">
                                                <?php echo number_format($ordered, 0); ?>
                                            </td>
                                            <td class="text-end">
                                                <?php echo number_format($received, 0); ?>
                                            </td>
                                            <td style="min-width: 100px;">
                                                <div class="progress" style="height: 8px;">
                                                    <div class="progress-bar bg-<?php echo $progress >= 100 ? 'success' : 'warning'; ?>"
                                                        style="width: <?php echo $progress; ?>%"></div>
                                                </div>
                                                <small class="text-muted">
                                                    <?php echo $progress; ?>%
                                                </small>
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

    <!-- Quick Stats Row -->
    <div class="row">
        <div class="col-12">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-chart-bar me-2"></i>Purchase Statistics
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="text-center">
                                <div class="h4 mb-0">
                                    <?php echo number_format((float) ($summary['draft_count'] ?? 0)); ?>
                                </div>
                                <div class="small text-muted">Draft Orders</div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="text-center">
                                <div class="h4 mb-0">
                                    <?php echo number_format((float) ($summary['pending_count'] ?? 0)); ?>
                                </div>
                                <div class="small text-muted">Pending Approval</div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="text-center">
                                <div class="h4 mb-0">
                                    <?php echo number_format((float) ($summary['approved_count'] ?? 0)); ?>
                                </div>
                                <div class="small text-muted">Approved</div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="text-center">
                                <div class="h4 mb-0">
                                    <?php echo number_format((float) ($summary['received_count'] ?? 0)); ?>
                                </div>
                                <div class="small text-muted">Received</div>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <div class="text-muted small">Total Purchase Value (All time):</div>
                            <div class="h5">
                                <?php echo format_currency($summary['total_value'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Average Order Value:</div>
                            <div class="h5">
                                <?php
                                $totalOrders = (float) ($summary['total_orders'] ?? 0);
                                $totalValue = (float) ($summary['total_value'] ?? 0);
                                $avgOrder = $totalOrders > 0 ? $totalValue / $totalOrders : 0;
                                echo format_currency($avgOrder);
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Purchase Trend Chart
        const trendCtx = document.getElementById('purchaseTrendChart').getContext('2d');
        const trendData = <?php echo json_encode($monthlyTrend); ?>;

        const months = trendData.map(d => {
            const [year, month] = d.month.split('-');
            return month + '/' + year;
        });
        const values = trendData.map(d => parseFloat(d.total_value) || 0);

        if (trendCtx) {
            new Chart(trendCtx, {
                type: 'line',
                data: {
                    labels: months,
                    datasets: [{
                        label: 'Purchase Value (RWF)',
                        data: values,
                        borderColor: '#4e73df',
                        backgroundColor: 'rgba(78, 115, 223, 0.05)',
                        tension: 0.3,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => 'RWF ' + ctx.raw.toLocaleString()
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: (value) => 'RWF ' + value.toLocaleString()
                            }
                        }
                    }
                }
            });
        }

        // Order Status Chart
        const statusCtx = document.getElementById('orderStatusChart').getContext('2d');

        const statusData = [
            <?php echo $statusCounts['draft']; ?>,
            <?php echo $statusCounts['pending']; ?>,
            <?php echo $statusCounts['approved']; ?>,
            <?php echo $statusCounts['partial']; ?>,
            <?php echo $statusCounts['received']; ?>,
            <?php echo $statusCounts['cancelled']; ?>
        ];

        if (statusCtx && statusData.some(v => v > 0)) {
            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Draft', 'Pending', 'Approved', 'Partial', 'Received', 'Cancelled'],
                    datasets: [{
                        data: statusData,
                        backgroundColor: [
                            '#858796', // draft
                            '#36b9cc', // pending
                            '#4e73df', // approved
                            '#f6c23e', // partial
                            '#1cc88a', // received
                            '#e74a3b'  // cancelled
                        ],
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom' },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => `${ctx.label}: ${ctx.raw} orders`
                            }
                        }
                    }
                }
            });
        } else if (statusCtx) {
            // Show message when no data
            statusCtx.canvas.parentElement.innerHTML = '<div class="text-center py-5 text-muted">No order data available</div>';
        }
    });
</script>

<style>
    .border-left-primary {
        border-left: 4px solid #4e73df !important;
    }

    .border-left-success {
        border-left: 4px solid #1cc88a !important;
    }

    .border-left-info {
        border-left: 4px solid #36b9cc !important;
    }

    .border-left-warning {
        border-left: 4px solid #f6c23e !important;
    }

    .table td {
        vertical-align: middle;
    }

    .progress {
        background-color: #eaecf4;
    }

    .card-header .btn-sm {
        font-size: 0.75rem;
    }
</style>