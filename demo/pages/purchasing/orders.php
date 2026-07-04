<?php
// pages/purchasing/orders.php
declare(strict_types=1);

$pageTitle = 'Purchase Orders - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/PurchaseOrder.php';
require_once __DIR__ . '/../../models/Supplier.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR', 'ACC', 'WHS'])) {
    SessionManager::flash('error', 'You do not have permission to view purchase orders.');
    header('Location: ' . route_url('purchasing'));
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

// Get filter parameters
$status = $_GET['status'] ?? '';
$supplierId = isset($_GET['supplier_id']) ? (int) $_GET['supplier_id'] : null;
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';

// Build query with company filter
$sql = "
    SELECT 
        po.*,
        s.supplier_name,
        s.supplier_code,
        (SELECT COUNT(*) FROM purchase_items WHERE purchase_order_id = po.id AND company_id = :company_id_1) as item_count,
        (SELECT COALESCE(SUM(received_quantity), 0) FROM purchase_items WHERE purchase_order_id = po.id AND company_id = :company_id_2) as total_received,
        (SELECT COALESCE(SUM(quantity), 0) FROM purchase_items WHERE purchase_order_id = po.id AND company_id = :company_id_3) as total_ordered
    FROM purchase_orders po
    JOIN suppliers s ON po.supplier_id = s.id
    WHERE po.company_id = :company_id_main
";
$params = [
    'company_id_main' => $companyId,
    'company_id_1' => $companyId,
    'company_id_2' => $companyId,
    'company_id_3' => $companyId
];

if ($status) {
    $sql .= " AND po.status = :status";
    $params['status'] = $status;
}

if ($supplierId) {
    $sql .= " AND po.supplier_id = :supplier_id";
    $params['supplier_id'] = $supplierId;
}

if ($dateFrom && $dateTo) {
    $sql .= " AND DATE(po.order_date) BETWEEN :date_from AND :date_to";
    $params['date_from'] = $dateFrom;
    $params['date_to'] = $dateTo;
}

if ($search) {
    $sql .= " AND (po.po_number LIKE :search OR s.supplier_name LIKE :search OR s.supplier_code LIKE :search)";
    $params['search'] = "%{$search}%";
}

$sql .= " ORDER BY po.order_date DESC, po.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Get suppliers for filter (company-specific)
$suppliers = $supplierModel->all(['id', 'supplier_name', 'supplier_code'], 'is_active = 1');

// Get summary using model (already company-filtered)
$summary = $purchaseOrderModel->getSummary();

// Get status counts for summary cards
$statusCounts = [
    'total' => $summary['total_orders'] ?? 0,
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
                    <a href="<?php echo route_url('purchasing'); ?>" class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Purchasing
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-shopping-cart me-2"></i>Purchase Orders
                    </h2>
                    <p class="mb-0 text-muted">Manage and track purchase orders for your company</p>
                </div>
                <div>
                    <a href="?page=purchasing/create-order" class="btn btn-primary">
                        <i class="fas fa-plus-circle me-2"></i>New Purchase Order
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
    <div class="row mb-4">
        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Total
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) $statusCounts['total']); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-file-invoice fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-secondary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">
                                Draft
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) $statusCounts['draft']); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-pen fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                Pending
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) $statusCounts['pending']); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-clock fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Approved
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) $statusCounts['approved']); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-check-circle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Partial
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) $statusCounts['partial']); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-truck-loading fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Received
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) $statusCounts['received']); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-check-double fa-2x text-gray-300"></i>
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
                <i class="fas fa-filter me-2"></i>Filter Orders
            </h6>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <input type="hidden" name="page" value="purchasing/orders">

                <div class="col-md-3">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" class="form-control" id="search" name="search"
                        value="<?php echo htmlspecialchars($search); ?>" placeholder="PO #, Supplier...">
                </div>

                <div class="col-md-2">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-control" id="status" name="status">
                        <option value="">All Status</option>
                        <option value="draft" <?php echo $status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="partial" <?php echo $status === 'partial' ? 'selected' : ''; ?>>Partial</option>
                        <option value="received" <?php echo $status === 'received' ? 'selected' : ''; ?>>Received</option>
                        <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled
                        </option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label for="supplier_id" class="form-label">Supplier</label>
                    <select class="form-control" id="supplier_id" name="supplier_id">
                        <option value="">All Suppliers</option>
                        <?php foreach ($suppliers as $sup): ?>
                            <option value="<?php echo $sup['id']; ?>" <?php echo $supplierId == $sup['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($sup['supplier_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label for="date_from" class="form-label">Date From</label>
                    <input type="date" class="form-control" id="date_from" name="date_from"
                        value="<?php echo $dateFrom; ?>">
                </div>

                <div class="col-md-2">
                    <label for="date_to" class="form-label">Date To</label>
                    <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo $dateTo; ?>">
                </div>

                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Orders Table -->
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-list me-2"></i>Purchase Orders
            </h6>
            <span class="text-muted small">Total: <?php echo count($orders); ?> orders</span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="ordersTable">
                    <thead class="table-light">
                        <tr>
                            <th>PO #</th>
                            <th>Date</th>
                            <th>Supplier</th>
                            <th>Expected</th>
                            <th>Items</th>
                            <th class="text-end">Total</th>
                            <th>Progress</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($orders)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-4">
                                    <div class="text-muted">
                                        <i class="fas fa-shopping-cart fa-3x mb-3"></i>
                                        <p class="mb-0">No purchase orders found</p>
                                        <p class="small mt-2">
                                            <a href="?page=purchasing/create-order" class="btn btn-sm btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i>Create Your First Order
                                            </a>
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($orders as $order):
                                $totalOrdered = (float) ($order['total_ordered'] ?? 0);
                                $totalReceived = (float) ($order['total_received'] ?? 0);
                                $progress = $totalOrdered > 0
                                    ? min(100, round(($totalReceived / $totalOrdered) * 100))
                                    : 0;
                                $isOverdue = $order['expected_date'] &&
                                    strtotime($order['expected_date']) < time() &&
                                    in_array($order['status'], ['approved', 'partial']);
                                ?>
                                <tr class="<?php echo $isOverdue ? 'table-warning' : ''; ?>">
                                    <td>
                                        <strong>
                                            <?php echo htmlspecialchars($order['po_number']); ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <?php echo date('d/m/Y', strtotime($order['order_date'])); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($order['supplier_name']); ?>
                                        <br><small class="text-muted">
                                            <?php echo htmlspecialchars($order['supplier_code']); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php if ($order['expected_date']): ?>
                                            <?php echo date('d/m/Y', strtotime($order['expected_date'])); ?>
                                            <?php if ($isOverdue): ?>
                                                <span class="badge bg-danger ms-1" title="Overdue">
                                                    <i class="fas fa-exclamation-triangle"></i>
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php echo (int) $order['item_count']; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php echo format_currency($order['total_amount']); ?>
                                    </td>
                                    <td style="min-width: 100px;">
                                        <div class="progress" style="height: 8px;">
                                            <div class="progress-bar bg-<?php echo $progress >= 100 ? 'success' : 'info'; ?>"
                                                style="width: <?php echo $progress; ?>%"></div>
                                        </div>
                                        <small class="text-muted">
                                            <?php echo $progress; ?>% received
                                        </small>
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
                                            <?php if ($order['status'] === 'draft' && in_array($userRole, ['ADM', 'MGR', 'ACC'])): ?>
                                                <a href="?page=purchasing/edit-order&id=<?php echo $order['id']; ?>"
                                                    class="btn btn-outline-warning" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if (in_array($order['status'], ['approved', 'partial']) && in_array($userRole, ['ADM', 'MGR', 'WHS'])): ?>
                                                <a href="?page=purchasing/receiving&po_id=<?php echo $order['id']; ?>"
                                                    class="btn btn-outline-success" title="Receive Items">
                                                    <i class="fas fa-truck-loading"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($order['status'] === 'pending' && in_array($userRole, ['ADM', 'MGR'])): ?>
                                                <a href="?page=purchasing/approve-order&id=<?php echo $order['id']; ?>"
                                                    class="btn btn-outline-success" title="Approve"
                                                    onclick="return confirm('Approve this purchase order?')">
                                                    <i class="fas fa-check"></i>
                                                </a>
                                                <a href="?page=purchasing/reject-order&id=<?php echo $order['id']; ?>"
                                                    class="btn btn-outline-danger" title="Reject"
                                                    onclick="return confirm('Reject this purchase order?')">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($order['status'] === 'draft' && in_array($userRole, ['ADM', 'MGR'])): ?>
                                                <button type="button" class="btn btn-outline-danger" title="Delete"
                                                    onclick="if(confirm('Delete this purchase order? This action cannot be undone.')) window.location.href='?page=purchasing/delete-order&id=<?php echo $order['id']; ?>'">
                                                    <i class="fas fa-trash"></i>
                                                </button>
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
</div>

<script>
    const table_id = '#ordersTable';
    const target_cols = 7;
</script>

<?php
$jsFiles = ['purchasing/orders.js'];
?>