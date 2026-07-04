<?php
// pages/sales/returns.php
declare(strict_types=1);

$pageTitle = 'Sales Returns - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';

// Load models
require_once __DIR__ . '/../../models/Sale.php';
require_once __DIR__ . '/../../models/Customer.php';
require_once __DIR__ . '/../../models/Inventory.php';
require_once __DIR__ . '/../../models/Warehouse.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR', 'SEL', 'ACC'])) {
    SessionManager::flash('error', 'You do not have permission to access returns.');
    header('Location: ' . route_url('sales'));
    exit;
}

// Initialize models with company context
$saleModel = new Sale($companyId);
$customerModel = new Customer($companyId);
$inventoryModel = new Inventory($companyId);
$warehouseModel = new Warehouse($companyId);

// Get filter parameters
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$customerId = isset($_GET['customer_id']) ? (int) $_GET['customer_id'] : null;
$search = $_GET['search'] ?? '';

// Get returns from inventory transactions (company-specific)
$sql = "
    SELECT 
        it.*,
        v.sku,
        v.variant_name,
        p.product_name,
        p.id as product_id,
        c.id as customer_id,
        c.full_name as customer_name,
        c.customer_code,
        si.invoice_number,
        u.full_name as created_by_name,
        w.warehouse_name
    FROM inventory_transactions it
    JOIN variants v ON it.variant_id = v.id
    JOIN products p ON v.product_id = p.id
    LEFT JOIN sales_invoices si ON it.reference_id = si.id AND it.reference_type = 'sale' AND si.company_id = :p_1_company_id
    LEFT JOIN customers c ON si.customer_id = c.id AND c.company_id = :p_2_company_id
    LEFT JOIN users u ON it.created_by = u.id
    LEFT JOIN warehouses w ON it.warehouse_id = w.id AND w.company_id = :p_3_company_id
    WHERE it.transaction_type = 'return'
        AND it.company_id = :p_4_company_id
";

$params = ['p_1_company_id' => $companyId, 'p_2_company_id' => $companyId, 'p_3_company_id' => $companyId, 'p_4_company_id' => $companyId];

if ($dateFrom && $dateTo) {
    $sql .= " AND DATE(it.created_at) BETWEEN :date_from AND :date_to";
    $params['date_from'] = $dateFrom;
    $params['date_to'] = $dateTo;
}

if ($customerId) {
    $sql .= " AND c.id = :customer_id";
    $params['customer_id'] = $customerId;
}

if ($search) {
    $sql .= " AND (si.invoice_number LIKE :search OR p.product_name LIKE :search OR c.full_name LIKE :search OR it.transaction_code LIKE :search)";
    $params['search'] = "%{$search}%";
}

$sql .= " ORDER BY it.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$returns = $stmt->fetchAll();

// Get customers for filter (only this company's customers)
$customers = $customerModel->all(['id', 'full_name', 'customer_code'], 'is_active = 1');

// Get warehouses for filter
$warehouses = $warehouseModel->all(['id', 'warehouse_name'], 'is_active = 1');

// Get summary (company-specific)
$summarySql = "
    SELECT 
        COUNT(*) as total_returns,
        SUM(ABS(quantity)) as total_quantity,
        SUM(ABS(quantity) * COALESCE(unit_cost, 0)) as total_value
    FROM inventory_transactions
    WHERE transaction_type = 'return'
        AND company_id = :company_id
        AND DATE(created_at) BETWEEN :date_from AND :date_to
";

$summaryStmt = $db->prepare($summarySql);
$summaryStmt->execute([
    'company_id' => $companyId,
    'date_from' => $dateFrom,
    'date_to' => $dateTo
]);
$summary = $summaryStmt->fetch();

// Generate CSRF token
$csrfToken = CSRF::generate();

// Get currency
$currency = $_SESSION['company_currency'] ?? 'RWF';
?>

<div class="sales-returns">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <a href="<?php echo route_url('sales'); ?>" class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-undo-alt me-2"></i>Sales Returns
                    </h2>
                    <p class="mb-0 text-muted">Manage product returns from customers</p>
                </div>
                <div>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newReturnModal">
                        <i class="fas fa-plus-circle me-2"></i>New Return
                    </button>
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

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-xl-4 col-md-4 mb-3">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Total Returns
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($summary['total_returns'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-exchange-alt fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-4 mb-3">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Quantity Returned
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format($summary['total_quantity'] ?? 0, 0); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-cubes fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-4 mb-3">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Total Value
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo format_currency($summary['total_value'] ?? 0, $companyId); ?>
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

    <!-- Filters -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-filter me-2"></i>Filter Returns
            </h6>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <input type="hidden" name="page" value="sales/returns">

                <div class="col-md-3">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" class="form-control" id="search" name="search"
                        value="<?php echo htmlspecialchars($search); ?>" placeholder="Invoice, Product, Customer, Return #...">
                </div>

                <div class="col-md-3">
                    <label for="customer_id" class="form-label">Customer</label>
                    <select class="form-control" id="customer_id" name="customer_id">
                        <option value="">All Customers</option>
                        <?php foreach ($customers as $cust): ?>
                                <option value="<?php echo $cust['id']; ?>" <?php echo $customerId == $cust['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cust['full_name']); ?>
                                </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label for="date_from" class="form-label">Date From</label>
                    <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo $dateFrom; ?>">
                </div>

                <div class="col-md-2">
                    <label for="date_to" class="form-label">Date To</label>
                    <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo $dateTo; ?>">
                </div>

                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100 me-2">
                        <i class="fas fa-search me-2"></i>Filter
                    </button>
                    <a href="?page=sales/returns" class="btn btn-secondary w-100">
                        <i class="fas fa-undo me-2"></i>Reset
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Returns Table -->
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-list me-2"></i>Return Transactions
                <span class="badge bg-primary ms-2"><?php echo count($returns); ?> returns</span>
            </h6>
            <button class="btn btn-sm btn-outline-primary" onclick="refreshPage()">
                <i class="fas fa-sync-alt me-1"></i> Refresh
            </button>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="returnsTable" width="100%" cellspacing="0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Return #</th>
                            <th>Invoice #</th>
                            <th>Customer</th>
                            <th>Product</th>
                            <th>Warehouse</th>
                            <th class="text-end">Quantity</th>
                            <th class="text-end">Total</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($returns)): ?>
                                <tr>
                                    <td colspan="9" class="text-center py-5">
                                        <div class="text-muted">
                                            <i class="fas fa-undo-alt fa-4x mb-3"></i>
                                            <p class="h5 mb-2">No returns found</p>
                                            <p class="mb-0">Try adjusting your filters or create a new return</p>
                                        </div>
                                    </td>
                                </tr>
                        <?php else: ?>
                                <?php foreach ($returns as $return): ?>
                                        <tr>
                                            <td>
                                                <span title="<?php echo htmlspecialchars($return['created_at']); ?>">
                                                    <i class="far fa-calendar-alt me-1"></i>
                                                    <?php echo date('d/m/Y H:i', strtotime($return['created_at'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <code><?php echo htmlspecialchars($return['transaction_code']); ?></code>
                                            </td>
                                            <td>
                                                <?php if ($return['invoice_number']): ?>
                                                        <a href="?page=sales/view-invoice&id=<?php echo $return['reference_id']; ?>" class="text-decoration-none">
                                                            <code><?php echo htmlspecialchars($return['invoice_number']); ?></code>
                                                        </a>
                                                <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($return['customer_name']): ?>
                                                        <div class="fw-bold"><?php echo htmlspecialchars($return['customer_name']); ?></div>
                                                        <small class="text-muted"><?php echo htmlspecialchars($return['customer_code']); ?></small>
                                                <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="fw-bold"><?php echo htmlspecialchars($return['product_name']); ?></div>
                                                <?php if ($return['variant_name'] && $return['variant_name'] !== 'Standard'): ?>
                                                        <small class="text-muted"><?php echo htmlspecialchars($return['variant_name']); ?></small>
                                                <?php endif; ?>
                                                <br><small class="text-muted">SKU: <?php echo htmlspecialchars($return['sku']); ?></small>
                                            </td>
                                            <td>
                                                <?php if ($return['warehouse_name']): ?>
                                                        <i class="fas fa-building me-1"></i>
                                                        <?php echo htmlspecialchars($return['warehouse_name']); ?>
                                                <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end text-success fw-bold">
                                                + <?php echo number_format(abs($return['quantity']), 0); ?>
                                            </td>
                                            <td class="text-end">
                                                <?php
                                                $totalValue = abs($return['quantity']) * ($return['unit_cost'] ?? 0);
                                                echo format_currency($totalValue, $companyId);
                                                ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-info"
                                                        onclick="viewReturn(<?php echo htmlspecialchars(json_encode($return)); ?>)"
                                                        title="View Details">
                                                        <i class="fas fa-info-circle"></i> Details
                                                    </button>
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

<!-- New Return Modal -->
<div class="modal fade" id="newReturnModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle me-2"></i>New Return
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="?page=sales/process-return" id="returnForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="company_id" value="<?php echo $companyId; ?>">

                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="return_invoice" class="form-label">Invoice Number</label>
                            <input type="text" class="form-control" id="return_invoice" name="invoice_number"
                                placeholder="Enter invoice number to load items">
                            <input type="hidden" id="return_invoice_id" name="invoice_id">
                            <div class="form-text">Leave empty for manual return entry</div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="return_date" class="form-label">Return Date</label>
                            <input type="date" class="form-control" id="return_date" name="return_date"
                                value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>

                    <div id="invoiceItems" style="display: none;">
                        <h6 class="mb-3">Items from Invoice</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>Product</th>
                                        <th class="text-end">Qty Sold</th>
                                        <th class="text-end">Price</th>
                                        <th class="text-end">Return Qty</th>
                                        <th>Reason</th>
                                    </tr>
                                </thead>
                                <tbody id="invoiceItemsBody">
                                    <!-- Items will be loaded here -->
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div id="manualReturn" class="mt-3">
                        <h6 class="mb-3">Manual Return Entry</h6>
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label for="return_product" class="form-label">Product</label>
                                <input type="text" class="form-control" id="return_product"
                                    placeholder="Search product by name, SKU, or barcode...">
                                <input type="hidden" id="return_variant_id" name="variant_id">
                                <div class="search-results list-group mt-1" style="display: none;"></div>
                            </div>

                            <div class="col-md-4 mb-3">
                                <label for="return_quantity" class="form-label">Quantity</label>
                                <input type="number" class="form-control" id="return_quantity" name="quantity"
                                    min="0.01" step="0.01">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="return_warehouse" class="form-label">Warehouse</label>
                                <select class="form-control" id="return_warehouse" name="warehouse_id" required>
                                    <option value="">Select Warehouse</option>
                                    <?php foreach ($warehouses as $wh): ?>
                                            <option value="<?php echo $wh['id']; ?>">
                                                <?php echo htmlspecialchars($wh['warehouse_name']); ?>
                                            </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="return_location" class="form-label">Location</label>
                                <select class="form-control" id="return_location" name="location_id">
                                    <option value="">Default Location</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="return_reason" class="form-label">Return Reason</label>
                            <select class="form-control" id="return_reason" name="reason">
                                <option value="">Select Reason</option>
                                <option value="Damaged product">💔 Damaged product</option>
                                <option value="Wrong item sent">📦 Wrong item sent</option>
                                <option value="Customer changed mind">🤔 Customer changed mind</option>
                                <option value="Quality issue">⚠️ Quality issue</option>
                                <option value="Expired product">⏰ Expired product</option>
                                <option value="Other">📝 Other</option>
                            </select>
                            <textarea class="form-control mt-2" id="return_notes" name="notes" rows="2" 
                                placeholder="Additional notes about the return..." style="display: none;"></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Process Return
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Return Modal -->
<div class="modal fade" id="viewReturnModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="fas fa-info-circle me-2"></i>Return Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="returnDetails">
                <!-- Content will be populated by JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
const locations = <?php echo json_encode([]); ?>;
const productDataset = <?php echo json_encode([]); ?>;

function refreshPage() {
    window.location.reload();
}

function viewReturn(returnData) {
    const detailsDiv = document.getElementById('returnDetails');
    const currency = '<?php echo $currency; ?>';
    
    detailsDiv.innerHTML = `
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Transaction Information</h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr><th style="width: 40%">Return #:</th><td><code>${escapeHtml(returnData.transaction_code)}</code></td></tr>
                            <tr><th>Date:</th><td>${new Date(returnData.created_at).toLocaleString()}</td></tr>
                            <tr><th>Processed By:</th><td>${escapeHtml(returnData.created_by_name || 'System')}</td></tr>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-file-invoice me-2"></i>Invoice Information</h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr><th style="width: 40%">Invoice #:</th><td>${returnData.invoice_number || '-'}</td></tr>
                            <tr><th>Customer:</th><td>${escapeHtml(returnData.customer_name || '-')}</td></tr>
                            <tr><th>Customer Code:</th><td>${escapeHtml(returnData.customer_code || '-')}</td></tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-box me-2"></i>Product Information</h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr><th style="width: 40%">Product:</th><td><strong>${escapeHtml(returnData.product_name)}</strong></td></tr>
                            <tr><th>Variant:</th><td>${escapeHtml(returnData.variant_name && returnData.variant_name !== 'Standard' ? returnData.variant_name : '-')}</td></tr>
                            <tr><th>SKU:</th><td><code>${escapeHtml(returnData.sku)}</code></td></tr>
                            <tr><th>Warehouse:</th><td>${escapeHtml(returnData.warehouse_name || '-')}</td></tr>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-chart-line me-2"></i>Return Details</h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr><th style="width: 40%">Quantity:</th><td class="text-success fw-bold">+ ${Math.abs(returnData.quantity)} units</td></tr>
                            <tr><th>Unit Cost:</th><td>${returnData.unit_cost ? formatCurrency(returnData.unit_cost) : '-'}</td></tr>
                            <tr><th>Total Value:</th><td class="fw-bold">${returnData.unit_cost ? formatCurrency(Math.abs(returnData.quantity * returnData.unit_cost)) : '-'}</td></tr>
                            <tr><th>Reason:</th><td>${escapeHtml(returnData.notes || '-')}</td></tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    new bootstrap.Modal(document.getElementById('viewReturnModal')).show();
}

function formatCurrency(amount) {
    const currency = '<?php echo $currency; ?>';
    return currency + ' ' + new Intl.NumberFormat().format(amount);
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Show/hide custom reason textarea
document.getElementById('return_reason')?.addEventListener('change', function() {
    const notesTextarea = document.getElementById('return_notes');
    if (this.value === 'Other') {
        notesTextarea.style.display = 'block';
        notesTextarea.required = true;
    } else {
        notesTextarea.style.display = 'none';
        notesTextarea.required = false;
    }
});

// Initialize DataTable
$(document).ready(function() {
    if ($.fn.DataTable && $('#returnsTable tbody tr').length > 10) {
        $('#returnsTable').DataTable({
            pageLength: 25,
            order: [[0, 'desc']],
            language: {
                search: "Search returns:",
                lengthMenu: "Show _MENU_ returns per page",
                info: "Showing _START_ to _END_ of _TOTAL_ returns",
                emptyTable: "No returns found"
            },
            columnDefs: [
                { orderable: false, targets: [8] }
            ],
            searching: false,
            paging: false
        });
    }
});
</script>

<style>
    .sales-returns .border-left-primary {
        border-left: 4px solid #4e73df !important;
    }
    .sales-returns .border-left-warning {
        border-left: 4px solid #f6c23e !important;
    }
    .sales-returns .border-left-success {
        border-left: 4px solid #1cc88a !important;
    }
    .sales-returns .table td {
        vertical-align: middle;
    }
    .sales-returns .btn-group {
        gap: 4px;
    }
    .sales-returns .badge {
        font-weight: 500;
        padding: 0.5em 0.85em;
    }
    .search-results {
        position: absolute;
        z-index: 1000;
        background: white;
        border: 1px solid #ddd;
        border-radius: 4px;
        box-shadow: 0 6px 12px rgba(0, 0, 0, 0.175);
        max-height: 200px;
        overflow-y: auto;
    }
    .list-group-item {
        cursor: pointer;
        padding: 8px 12px;
        border-bottom: 1px solid #eee;
    }
    .list-group-item:hover {
        background-color: #f8f9fc;
    }
</style>