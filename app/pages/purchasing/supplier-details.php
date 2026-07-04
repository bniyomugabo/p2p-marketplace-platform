<?php
// pages/purchasing/supplier-details.php
declare(strict_types=1);

$pageTitle = 'Supplier Details - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Supplier.php';
require_once __DIR__ . '/../../models/PurchaseOrder.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR', 'ACC', 'WHS'])) {
    SessionManager::flash('error', 'You do not have permission to view supplier details.');
    header('Location: ' . route_url('purchasing'));
    exit;
}

// Check if company context is set
if (!$companyId) {
    SessionManager::flash('error', 'Company context not found. Please log in again.');
    header('Location: ' . route_url('login'));
    exit;
}

// Get supplier ID
$supplierId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$supplierId) {
    SessionManager::flash('error', 'Supplier ID is required.');
    header('Location: ' . route_url('purchasing/suppliers'));
    exit;
}

// Initialize models with company ID
$supplierModel = new Supplier($companyId);
$purchaseOrderModel = new PurchaseOrder($companyId);

// Get supplier details (with ownership verification)
$supplier = $supplierModel->getWithSummary($supplierId);

if (!$supplier) {
    SessionManager::flash('error', 'Supplier not found or does not belong to your company.');
    header('Location: ' . route_url('purchasing/suppliers'));
    exit;
}

// Get purchase orders for this supplier
$purchaseOrders = $purchaseOrderModel->getBySupplier($supplierId, 20);

// Get purchase statistics
$statsSql = "
    SELECT 
        COUNT(*) as total_orders,
        SUM(total_amount) as total_spent,
        AVG(total_amount) as avg_order_value,
        MIN(order_date) as first_order,
        MAX(order_date) as last_order,
        SUM(CASE WHEN status = 'received' THEN total_amount ELSE 0 END) as received_value,
        SUM(CASE WHEN status IN ('approved', 'partial') THEN total_amount ELSE 0 END) as pending_value
    FROM purchase_orders
    WHERE supplier_id = :supplier_id 
        AND company_id = :company_id
        AND status != 'cancelled'
";

$statsStmt = $db->prepare($statsSql);
$statsStmt->execute([
    'supplier_id' => $supplierId,
    'company_id' => $companyId
]);
$stats = $statsStmt->fetch();

// Get top products purchased from this supplier
$topProductsSql = "
    SELECT 
        p.product_name,
        v.variant_name,
        v.sku,
        SUM(pi.quantity) as total_quantity,
        SUM(pi.quantity * pi.unit_price) as total_value
    FROM purchase_items pi
    JOIN purchase_orders po ON pi.purchase_order_id = po.id
    JOIN variants v ON pi.variant_id = v.id
    JOIN products p ON v.product_id = p.id
    WHERE po.supplier_id = :supplier_id
        AND po.company_id = :company_id
        AND po.status != 'cancelled'
    GROUP BY v.id
    ORDER BY total_value DESC
    LIMIT 10
";

$topProductsStmt = $db->prepare($topProductsSql);
$topProductsStmt->execute([
    'supplier_id' => $supplierId,
    'company_id' => $companyId
]);
$topProducts = $topProductsStmt->fetchAll();

// Get monthly purchase trend
$trendSql = "
    SELECT 
        DATE_FORMAT(order_date, '%Y-%m') as month,
        COUNT(*) as order_count,
        SUM(total_amount) as total_value
    FROM purchase_orders
    WHERE supplier_id = :supplier_id
        AND company_id = :company_id
        AND status != 'cancelled'
        AND order_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(order_date, '%Y-%m')
    ORDER BY month ASC
";

$trendStmt = $db->prepare($trendSql);
$trendStmt->execute([
    'supplier_id' => $supplierId,
    'company_id' => $companyId
]);
$monthlyTrend = $trendStmt->fetchAll();

// Generate CSRF token for actions
$csrfToken = CSRF::generate();
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <a href="<?php echo route_url('purchasing/suppliers'); ?>"
                        class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Suppliers
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-truck me-2"></i>Supplier Details
                    </h2>
                    <p class="mb-0 text-muted">View and manage supplier information</p>
                </div>
                <div>
                    <a href="?page=purchasing/create-order&supplier_id=<?php echo $supplierId; ?>"
                        class="btn btn-primary">
                        <i class="fas fa-plus-circle me-2"></i>Create Purchase Order
                    </a>
                    <button type="button" class="btn btn-warning" onclick="editSupplier()">
                        <i class="fas fa-edit me-2"></i>Edit Supplier
                    </button>
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

    <!-- Supplier Info Card -->
    <div class="row">
        <div class="col-xl-4 col-lg-5 mb-4">
            <div class="card shadow h-100">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-info-circle me-2"></i>Supplier Information
                    </h6>
                </div>
                <div class="card-body">
                    <div class="text-center mb-3">
                        <div class="bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center"
                            style="width: 80px; height: 80px; font-size: 32px;">
                            <i class="fas fa-building"></i>
                        </div>
                        <h4 class="mt-3 mb-1">
                            <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                        </h4>
                        <p class="text-muted small">Code:
                            <?php echo htmlspecialchars($supplier['supplier_code']); ?>
                        </p>
                        <span class="badge bg-<?php echo $supplier['is_active'] ? 'success' : 'secondary'; ?>">
                            <?php echo $supplier['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </div>

                    <hr>

                    <div class="mb-3">
                        <small class="text-muted d-block">Contact Person</small>
                        <strong>
                            <?php echo htmlspecialchars($supplier['contact_person'] ?? 'N/A'); ?>
                        </strong>
                    </div>

                    <div class="mb-3">
                        <small class="text-muted d-block">Phone</small>
                        <?php if ($supplier['phone']): ?>
                            <a href="tel:<?php echo htmlspecialchars($supplier['phone']); ?>">
                                <i class="fas fa-phone me-1"></i>
                                <?php echo htmlspecialchars($supplier['phone']); ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">N/A</span>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <small class="text-muted d-block">Email</small>
                        <?php if ($supplier['email']): ?>
                            <a href="mailto:<?php echo htmlspecialchars($supplier['email']); ?>">
                                <i class="fas fa-envelope me-1"></i>
                                <?php echo htmlspecialchars($supplier['email']); ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">N/A</span>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <small class="text-muted d-block">Tax ID / VAT</small>
                        <strong>
                            <?php echo htmlspecialchars($supplier['tax_id'] ?? 'N/A'); ?>
                        </strong>
                    </div>

                    <div class="mb-3">
                        <small class="text-muted d-block">Payment Terms</small>
                        <strong>
                            <?php echo htmlspecialchars($supplier['payment_terms'] ?? 'N/A'); ?>
                        </strong>
                    </div>

                    <div class="mb-3">
                        <small class="text-muted d-block">Address</small>
                        <p class="mb-0">
                            <?php echo nl2br(htmlspecialchars($supplier['address'] ?? 'N/A')); ?>
                        </p>
                    </div>

                    <hr>

                    <div class="text-center">
                        <small class="text-muted d-block">Created At</small>
                        <span>
                            <?php echo date('d M Y, H:i', strtotime($supplier['created_at'])); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-8 col-lg-7 mb-4">
            <!-- Summary Cards -->
            <div class="row">
                <div class="col-md-4 mb-4">
                    <div class="card border-left-primary shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                        Total Orders
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php echo number_format((float)$supplier['total_orders'] ?? 0); ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-shopping-cart fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4 mb-4">
                    <div class="card border-left-success shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                        Total Spent
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php echo format_currency($stats['total_spent'] ?? 0); ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-dollar-sign fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4 mb-4">
                    <div class="card border-left-info shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                        Avg Order Value
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php echo format_currency($stats['avg_order_value'] ?? 0); ?>
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

            <!-- Balance Card -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-wallet me-2"></i>Financial Summary
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 text-center">
                            <div class="text-muted small">Current Balance</div>
                            <div
                                class="h4 <?php echo ($supplier['balance'] ?? 0) > 0 ? 'text-danger' : 'text-success'; ?>">
                                <?php echo format_currency($supplier['balance'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="col-md-4 text-center">
                            <div class="text-muted small">Received Value</div>
                            <div class="h4 text-success">
                                <?php echo format_currency($stats['received_value'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="col-md-4 text-center">
                            <div class="text-muted small">Pending Value</div>
                            <div class="h4 text-warning">
                                <?php echo format_currency($stats['pending_value'] ?? 0); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Monthly Trend Chart -->
            <?php if (!empty($monthlyTrend)): ?>
                <div class="card shadow mb-4">
                    <div class="card-header py-3">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <i class="fas fa-chart-line me-2"></i>Purchase Trend (Last 12 Months)
                        </h6>
                    </div>
                    <div class="card-body">
                        <canvas id="purchaseTrendChart" height="250"></canvas>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Purchase Orders -->
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-history me-2"></i>Recent Purchase Orders
            </h6>
            <a href="?page=purchasing/orders&supplier_id=<?php echo $supplierId; ?>" class="btn btn-sm btn-primary">
                View All Orders
            </a>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>PO #</th>
                            <th>Date</th>
                            <th>Expected Date</th>
                            <th class="text-end">Total</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($purchaseOrders)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4">
                                    <div class="text-muted">
                                        <i class="fas fa-shopping-cart fa-3x mb-3"></i>
                                        <p class="mb-0">No purchase orders found for this supplier</p>
                                        <a href="?page=purchasing/create-order&supplier_id=<?php echo $supplierId; ?>"
                                            class="btn btn-sm btn-primary mt-2">
                                            <i class="fas fa-plus me-1"></i>Create First Order
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($purchaseOrders as $order): ?>
                                <tr>
                                    <td>
                                        <strong>
                                            <?php echo htmlspecialchars($order['po_number']); ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <?php echo date('d/m/Y', strtotime($order['order_date'])); ?>
                                    </td>
                                    <td>
                                        <?php echo $order['expected_date'] ? date('d/m/Y', strtotime($order['expected_date'])) : '-'; ?>
                                        <?php if ($order['expected_date'] && strtotime($order['expected_date']) < time() && in_array($order['status'], ['approved', 'partial'])): ?>
                                            <span class="badge bg-danger ms-1" title="Overdue">!</span>
                                        <?php endif; ?>
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
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="?page=purchasing/view-order&id=<?php echo $order['id']; ?>"
                                                class="btn btn-outline-primary" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($order['status'] === 'approved' || $order['status'] === 'partial'): ?>
                                                <a href="?page=purchasing/receiving&po_id=<?php echo $order['id']; ?>"
                                                    class="btn btn-outline-success" title="Receive">
                                                    <i class="fas fa-truck-loading"></i>
                                                </a>
                                            <?php endif; ?>
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

    <!-- Top Products from this Supplier -->
    <?php if (!empty($topProducts)): ?>
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-boxes me-2"></i>Top Products Purchased
                </h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>SKU</th>
                                <th class="text-end">Quantity</th>
                                <th class="text-end">Total Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topProducts as $product): ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($product['product_name']); ?>
                                        <?php if ($product['variant_name'] && $product['variant_name'] !== 'Standard'): ?>
                                            <small class="text-muted"> -
                                                <?php echo htmlspecialchars($product['variant_name']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($product['sku']); ?>
                                    </td>
                                    <td class="text-end">
                                        <?php echo number_format((float)$product['total_quantity'], 0); ?>
                                    </td>
                                    <td class="text-end">
                                        <?php echo format_currency($product['total_value']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Edit Supplier Modal -->
<div class="modal fade" id="editSupplierModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="fas fa-edit me-2"></i>Edit Supplier
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="?page=purchasing/update-supplier">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="supplier_id" value="<?php echo $supplierId; ?>">

                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_supplier_name" class="form-label">Supplier Name <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_supplier_name" name="supplier_name"
                                value="<?php echo htmlspecialchars($supplier['supplier_name']); ?>" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="edit_supplier_code" class="form-label">Supplier Code</label>
                            <input type="text" class="form-control" id="edit_supplier_code" name="supplier_code"
                                value="<?php echo htmlspecialchars($supplier['supplier_code']); ?>" readonly>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_contact_person" class="form-label">Contact Person</label>
                            <input type="text" class="form-control" id="edit_contact_person" name="contact_person"
                                value="<?php echo htmlspecialchars($supplier['contact_person'] ?? ''); ?>">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="edit_phone" class="form-label">Phone</label>
                            <input type="tel" class="form-control" id="edit_phone" name="phone"
                                value="<?php echo htmlspecialchars($supplier['phone'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="edit_email" name="email"
                                value="<?php echo htmlspecialchars($supplier['email'] ?? ''); ?>">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="edit_tax_id" class="form-label">Tax ID / VAT Number</label>
                            <input type="text" class="form-control" id="edit_tax_id" name="tax_id"
                                value="<?php echo htmlspecialchars($supplier['tax_id'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="edit_address" class="form-label">Address</label>
                        <textarea class="form-control" id="edit_address" name="address"
                            rows="2"><?php echo htmlspecialchars($supplier['address'] ?? ''); ?></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_payment_terms" class="form-label">Payment Terms</label>
                            <select class="form-control" id="edit_payment_terms" name="payment_terms">
                                <option value="">Select Terms</option>
                                <option value="Immediate" <?php echo ($supplier['payment_terms'] ?? '') === 'Immediate' ? 'selected' : ''; ?>>Immediate</option>
                                <option value="Net 15" <?php echo ($supplier['payment_terms'] ?? '') === 'Net 15' ? 'selected' : ''; ?>>Net 15</option>
                                <option value="Net 30" <?php echo ($supplier['payment_terms'] ?? '') === 'Net 30' ? 'selected' : ''; ?>>Net 30</option>
                                <option value="Net 45" <?php echo ($supplier['payment_terms'] ?? '') === 'Net 45' ? 'selected' : ''; ?>>Net 45</option>
                                <option value="Net 60" <?php echo ($supplier['payment_terms'] ?? '') === 'Net 60' ? 'selected' : ''; ?>>Net 60</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save me-2"></i>Update Supplier
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    function editSupplier() {
        const modal = new bootstrap.Modal(document.getElementById('editSupplierModal'));
        modal.show();
    }

    // Purchase Trend Chart
    <?php if (!empty($monthlyTrend)): ?>
        const trendCtx = document.getElementById('purchaseTrendChart').getContext('2d');
        const trendData = <?php echo json_encode($monthlyTrend); ?>;

        const months = trendData.map(d => {
            const [year, month] = d.month.split('-');
            const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            return monthNames[parseInt(month) - 1] + ' ' + year;
        });
        const values = trendData.map(d => parseFloat(d.total_value) || 0);

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
    <?php endif; ?>
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

    .table td {
        vertical-align: middle;
    }

    .btn-group {
        gap: 2px;
    }
</style>