<?php
// pages/sales/invoices.php
declare(strict_types=1);

$pageTitle = 'Sales Invoices - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';

// Load models
require_once __DIR__ . '/../../models/Sale.php';
require_once __DIR__ . '/../../models/Customer.php';
require_once __DIR__ . '/../../models/Payment.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Initialize models with company context
$saleModel = new Sale($companyId);
$customerModel = new Customer($companyId);
$paymentModel = new Payment($companyId);

// Get filter parameters
$status = $_GET['status'] ?? '';
$customerId = isset($_GET['customer_id']) ? (int) $_GET['customer_id'] : null;
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$search = $_GET['search'] ?? '';

// Build query with company filter
$sql = "
    SELECT 
        si.*,
        c.full_name as customer_name,
        c.phone as customer_phone,
        c.email as customer_email,
        c.customer_code,
        u.full_name as created_by_name,
        (SELECT COUNT(*) FROM invoice_items WHERE invoice_id = si.id) as item_count
    FROM sales_invoices si
    JOIN customers c ON si.customer_id = c.id
    LEFT JOIN users u ON si.created_by = u.id
    WHERE si.company_id = :company_id
";
$params = ['company_id' => $companyId];

if ($status) {
    $sql .= " AND si.status = :status";
    $params['status'] = $status;
}

if ($customerId) {
    $sql .= " AND si.customer_id = :customer_id";
    $params['customer_id'] = $customerId;
}

if ($dateFrom && $dateTo) {
    $sql .= " AND DATE(si.invoice_date) BETWEEN :date_from AND :date_to";
    $params['date_from'] = $dateFrom;
    $params['date_to'] = $dateTo;
}

if ($search) {
    $sql .= " AND (si.invoice_number LIKE :search OR c.full_name LIKE :search OR c.customer_code LIKE :search)";
    $params['search'] = "%{$search}%";
}

$sql .= " ORDER BY si.invoice_date DESC, si.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// Get customers for filter (only this company's customers)
$customers = $customerModel->all(['id', 'full_name', 'customer_code'], 'is_active = 1');

// Get summary statistics (company-specific)
$summarySql = "
    SELECT 
        COUNT(*) as total_invoices,
        SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count,
        SUM(CASE WHEN status = 'issued' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_count,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
        SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) as partial_count,
        SUM(total_amount) as total_amount,
        SUM(amount_paid) as total_paid,
        SUM(total_amount - amount_paid) as total_outstanding
    FROM sales_invoices
    WHERE DATE(invoice_date) BETWEEN :date_from AND :date_to
        AND company_id = :company_id
";

$summaryStmt = $db->prepare($summarySql);
$summaryStmt->execute([
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'company_id' => $companyId
]);
$summary = $summaryStmt->fetch();

// Generate CSRF token for modals
$csrfToken = CSRF::generate();
?>

<div class="sales-invoices">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <a href="<?php echo route_url('sales'); ?>" class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-file-invoice me-2"></i>Sales Invoices
                    </h2>
                    <p class="mb-0 text-muted">Manage and view all sales invoices</p>
                </div>
                <div>
                    <a href="?page=sales/create" class="btn btn-primary">
                        <i class="fas fa-plus-circle me-2"></i>New Invoice
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

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-xl-2 col-md-4 mb-3">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Total Invoices
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) ($summary['total_invoices'] ?? 0)); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-file-invoice fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-3">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Paid
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) ($summary['paid_count'] ?? 0)); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-check-circle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-3">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Pending
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) ($summary['pending_count'] ?? 0)); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-clock fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-3">
            <div class="card border-left-danger shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">
                                Overdue
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) ($summary['overdue_count'] ?? 0)); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-exclamation-triangle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-3">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                Partial
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) ($summary['partial_count'] ?? 0)); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-chart-line fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-3">
            <div class="card border-left-dark shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-dark text-uppercase mb-1">
                                Outstanding Amount
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo format_currency($summary['total_outstanding'] ?? 0, $companyId); ?>
                            </div>
                            <div class="small text-muted">
                                Total: <?php echo format_currency($summary['total_amount'] ?? 0, $companyId); ?>
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
                <i class="fas fa-filter me-2"></i>Filter Invoices
            </h6>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <input type="hidden" name="page" value="sales/invoices">

                <div class="col-md-3">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" class="form-control" id="search" name="search"
                        value="<?php echo htmlspecialchars($search); ?>" placeholder="Invoice #, Customer...">
                </div>

                <div class="col-md-2">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-control" id="status" name="status">
                        <option value="">All Status</option>
                        <option value="draft" <?php echo $status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="issued" <?php echo $status === 'issued' ? 'selected' : ''; ?>>Issued</option>
                        <option value="paid" <?php echo $status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                        <option value="partial" <?php echo $status === 'partial' ? 'selected' : ''; ?>>Partial</option>
                        <option value="overdue" <?php echo $status === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                        <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled
                        </option>
                    </select>
                </div>

                <div class="col-md-2">
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

    <!-- Invoices Table -->
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-list me-2"></i>Invoices
                <span class="badge bg-primary ms-2"><?php echo count($invoices); ?> invoices</span>
            </h6>
            <button class="btn btn-sm btn-outline-primary" onclick="refreshPage()">
                <i class="fas fa-sync-alt me-1"></i> Refresh
            </button>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="invoicesTable" width="100%" cellspacing="0">
                    <thead class="table-light">
                        <tr>
                            <th>Invoice #</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th class="text-center">Items</th>
                            <th class="text-end">Total</th>
                            <th class="text-end">Paid</th>
                            <th class="text-end">Balance</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($invoices)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5">
                                    <div class="text-muted">
                                        <i class="fas fa-file-invoice fa-4x mb-3"></i>
                                        <p class="h5 mb-2">No invoices found</p>
                                        <p class="mb-0">Try adjusting your filters or create a new invoice</p>
                                        <a href="?page=sales/create" class="btn btn-primary mt-3">
                                            <i class="fas fa-plus-circle me-2"></i>Create Invoice
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($invoices as $invoice): ?>
                                <tr>
                                    <td>
                                        <strong><code><?php echo htmlspecialchars($invoice['invoice_number']); ?></code></strong>
                                    </td>
                                    <td>
                                        <span title="<?php echo htmlspecialchars($invoice['invoice_date']); ?>">
                                            <i class="far fa-calendar-alt me-1"></i>
                                            <?php echo date('d/m/Y', strtotime($invoice['invoice_date'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($invoice['customer_name']); ?></div>
                                        <small
                                            class="text-muted"><?php echo htmlspecialchars($invoice['customer_code']); ?></small>
                                        <?php if ($invoice['customer_phone']): ?>
                                            <br><small class="text-muted">📞
                                                <?php echo htmlspecialchars($invoice['customer_phone']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-secondary"><?php echo $invoice['item_count']; ?> items</span>
                                    </td>
                                    <td class="text-end fw-bold">
                                        <?php echo format_currency($invoice['total_amount'], $companyId); ?>
                                    </td>
                                    <td class="text-end text-success">
                                        <?php echo format_currency($invoice['amount_paid'], $companyId); ?>
                                    </td>
                                    <td
                                        class="text-end <?php echo ($invoice['total_amount'] - $invoice['amount_paid']) > 0 ? 'text-danger' : 'text-muted'; ?>">
                                        <?php echo format_currency($invoice['total_amount'] - $invoice['amount_paid'], $companyId); ?>
                                    </td>
                                    <td>
                                        <?php
                                        $statusConfig = [
                                            'draft' => ['class' => 'secondary', 'icon' => 'fa-pencil-alt'],
                                            'issued' => ['class' => 'primary', 'icon' => 'fa-paper-plane'],
                                            'paid' => ['class' => 'success', 'icon' => 'fa-check-circle'],
                                            'partial' => ['class' => 'warning', 'icon' => 'fa-chart-line'],
                                            'overdue' => ['class' => 'danger', 'icon' => 'fa-exclamation-triangle'],
                                            'cancelled' => ['class' => 'dark', 'icon' => 'fa-ban']
                                        ];
                                        $config = $statusConfig[$invoice['status']] ?? ['class' => 'secondary', 'icon' => 'fa-question'];
                                        ?>
                                        <span class="badge bg-<?php echo $config['class']; ?>">
                                            <i class="fas <?php echo $config['icon']; ?> me-1"></i>
                                            <?php echo ucfirst($invoice['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="?page=sales/view-invoice&id=<?php echo $invoice['id']; ?>"
                                                class="btn btn-outline-primary" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="?page=sales/print-invoice&id=<?php echo $invoice['id']; ?>"
                                                class="btn btn-outline-info" title="Print Invoice" target="_blank">
                                                <i class="fas fa-print"></i>
                                            </a>
                                            <?php if ($invoice['status'] !== 'paid' && $invoice['status'] !== 'cancelled'): ?>
                                                <button type="button" class="btn btn-outline-success"
                                                    onclick="recordPayment(<?php echo $invoice['id']; ?>, '<?php echo htmlspecialchars($invoice['invoice_number']); ?>', <?php echo $invoice['total_amount'] - $invoice['amount_paid']; ?>)"
                                                    title="Record Payment">
                                                    <i class="fas fa-credit-card"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if (in_array($userRole, ['ADM']) && $invoice['status'] !== 'cancelled'): ?>
                                                <button type="button" class="btn btn-outline-danger"
                                                    onclick="cancelInvoice(<?php echo $invoice['id']; ?>, '<?php echo htmlspecialchars($invoice['invoice_number']); ?>')"
                                                    title="Cancel Invoice">
                                                    <i class="fas fa-ban"></i>
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

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="fas fa-credit-card me-2"></i>Record Payment
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="?page=sales/record-payment" id="paymentForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="invoice_id" id="payment_invoice_id">
                <input type="hidden" name="company_id" value="<?php echo $companyId; ?>">

                <div class="modal-body">
                    <div class="alert alert-info">
                        <strong>Invoice:</strong> <span id="payment_invoice_number"></span><br>
                        <strong>Outstanding Balance:</strong> <span id="outstanding_balance"
                            class="text-danger fw-bold"></span>
                    </div>

                    <div class="mb-3">
                        <label for="payment_amount" class="form-label">Payment Amount <span
                                class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text currency-symbol">RWF</span>
                            <input type="number" class="form-control" id="payment_amount" name="amount" min="0.01"
                                step="0.01" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="payment_method" class="form-label">Payment Method</label>
                        <select class="form-control" id="payment_method" name="payment_method">
                            <option value="cash">💰 Cash</option>
                            <option value="bank_transfer">🏦 Bank Transfer</option>
                            <option value="mobile">📱 Mobile Money</option>
                            <option value="card">💳 Card</option>
                            <option value="cheque">📝 Cheque</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="payment_date" class="form-label">Payment Date</label>
                        <input type="date" class="form-control" id="payment_date" name="payment_date"
                            value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="mb-3">
                        <label for="payment_reference" class="form-label">Reference Number</label>
                        <input type="text" class="form-control" id="payment_reference" name="reference_number"
                            placeholder="Transaction ID, Cheque #, etc.">
                    </div>

                    <div class="mb-3">
                        <label for="payment_notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="payment_notes" name="notes" rows="2"
                            placeholder="Additional notes about this payment"></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-save me-2"></i>Record Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Cancel Invoice Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="fas fa-ban me-2"></i>Cancel Invoice
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="?page=sales/cancel-invoice">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="invoice_id" id="cancel_invoice_id">
                <input type="hidden" name="company_id" value="<?php echo $companyId; ?>">

                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Warning:</strong> This action cannot be undone and will reverse all inventory movements.
                    </div>

                    <p>Are you sure you want to cancel invoice <strong id="cancel_invoice_number"></strong>?</p>

                    <div class="mb-3">
                        <label for="cancel_reason" class="form-label">Reason for Cancellation <span
                                class="text-danger">*</span></label>
                        <textarea class="form-control" id="cancel_reason" name="reason" rows="3" required
                            placeholder="Please provide a reason for cancelling this invoice"></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">No, Keep Invoice</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-ban me-2"></i>Yes, Cancel Invoice
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function refreshPage() {
        window.location.reload();
    }

    function recordPayment(invoiceId, invoiceNumber, outstandingBalance) {
        document.getElementById('payment_invoice_id').value = invoiceId;
        document.getElementById('payment_invoice_number').textContent = invoiceNumber;
        document.getElementById('outstanding_balance').textContent = formatCurrency(outstandingBalance);
        document.getElementById('payment_amount').value = outstandingBalance;
        document.getElementById('payment_amount').max = outstandingBalance;

        new bootstrap.Modal(document.getElementById('paymentModal')).show();
    }

    function cancelInvoice(invoiceId, invoiceNumber) {
        document.getElementById('cancel_invoice_id').value = invoiceId;
        document.getElementById('cancel_invoice_number').textContent = invoiceNumber;
        new bootstrap.Modal(document.getElementById('cancelModal')).show();
    }

    function formatCurrency(amount) {
        const currency = '<?php echo $_SESSION['company_currency'] ?? 'RWF'; ?>';
        return currency + ' ' + new Intl.NumberFormat().format(amount);
    }

    // Initialize DataTable if needed
    $(document).ready(function () {
        if ($.fn.DataTable && $('#invoicesTable tbody tr').length > 10) {
            $('#invoicesTable').DataTable({
                pageLength: 25,
                order: [[1, 'desc']],
                language: {
                    search: "Search invoices:",
                    lengthMenu: "Show _MENU_ invoices per page",
                    info: "Showing _START_ to _END_ of _TOTAL_ invoices",
                    emptyTable: "No invoices found"
                },
                columnDefs: [
                    { orderable: false, targets: [8] }
                ],
                searching: false,
                paging: false
            });
        }
    });

    // Payment amount validation
    document.getElementById('payment_amount')?.addEventListener('input', function () {
        const max = parseFloat(this.max);
        const value = parseFloat(this.value);
        if (value > max) {
            this.value = max;
        }
    });

    // Form validation for payment
    document.getElementById('paymentForm')?.addEventListener('submit', function (e) {
        const amount = parseFloat(document.getElementById('payment_amount').value);
        if (isNaN(amount) || amount <= 0) {
            e.preventDefault();
            alert('Please enter a valid payment amount');
            return false;
        }
        return true;
    });
</script>

<?php $jsFiles = ['sales/invoices.js']; ?>

<style>
    .sales-invoices .border-left-primary {
        border-left: 4px solid #4e73df !important;
    }

    .sales-invoices .border-left-success {
        border-left: 4px solid #1cc88a !important;
    }

    .sales-invoices .border-left-warning {
        border-left: 4px solid #f6c23e !important;
    }

    .sales-invoices .border-left-danger {
        border-left: 4px solid #e74a3b !important;
    }

    .sales-invoices .border-left-info {
        border-left: 4px solid #36b9cc !important;
    }

    .sales-invoices .border-left-dark {
        border-left: 4px solid #5a5c69 !important;
    }

    .sales-invoices .table td {
        vertical-align: middle;
    }

    .sales-invoices .btn-group {
        gap: 4px;
    }

    .sales-invoices .badge {
        font-weight: 500;
        padding: 0.5em 0.85em;
    }

    .modal .currency-symbol {
        min-width: 50px;
    }
</style>