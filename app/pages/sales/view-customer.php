<?php
// pages/sales/view-customer.php
declare(strict_types=1);

$pageTitle = 'View Customer - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';

// Load models
require_once __DIR__ . '/../../models/Customer.php';
require_once __DIR__ . '/../../models/Sale.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Get customer ID from URL
$customerId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$customerId) {
    SessionManager::flash('error', 'Customer ID is required.');
    header('Location: ' . route_url('sales/customers'));
    exit;
}

// Initialize models with company context
$customerModel = new Customer($companyId);
$saleModel = new Sale($companyId);

// Get customer details with summary (company-specific)
$customer = $customerModel->getWithSummary($customerId);

if (!$customer) {
    SessionManager::flash('error', 'Customer not found or not accessible for your company.');
    header('Location: ' . route_url('sales/customers'));
    exit;
}

// Get customer's recent invoices (company-specific)
$sql = "
    SELECT 
        si.*,
        COUNT(ii.id) as item_count,
        u.full_name as created_by_name
    FROM sales_invoices si
    LEFT JOIN invoice_items ii ON si.id = ii.invoice_id
    LEFT JOIN users u ON si.created_by = u.id
    WHERE si.customer_id = :customer_id
        AND si.company_id = :company_id
    GROUP BY si.id
    ORDER BY si.invoice_date DESC, si.created_at DESC
    LIMIT 20
";

$stmt = $db->prepare($sql);
$stmt->execute([
    'customer_id' => $customerId,
    'company_id' => $companyId
]);
$recentInvoices = $stmt->fetchAll();

// Get payment history (company-specific)
$paymentSql = "
    SELECT 
        p.*,
        si.invoice_number,
        u.full_name as created_by_name
    FROM payments p
    JOIN sales_invoices si ON p.invoice_id = si.id
    LEFT JOIN users u ON p.created_by = u.id
    WHERE si.customer_id = :customer_id
        AND si.company_id = :company_id
    ORDER BY p.payment_date DESC, p.created_at DESC
    LIMIT 20
";

$paymentStmt = $db->prepare($paymentSql);
$paymentStmt->execute([
    'customer_id' => $customerId,
    'company_id' => $companyId
]);
$recentPayments = $paymentStmt->fetchAll();

// Get currency
$currency = $_SESSION['company_currency'] ?? 'RWF';

// Generate CSRF token
$csrfToken = CSRF::generate();
?>

<div class="view-customer">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <a href="<?php echo route_url('sales/customers'); ?>" class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Customers
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-user-circle me-2"></i>Customer Details
                    </h2>
                    <p class="mb-0 text-muted">Viewing customer information and transaction history</p>
                </div>
                <div>
                    <button type="button" class="btn btn-outline-warning me-2" data-bs-toggle="modal"
                        data-bs-target="#editCustomerModal">
                        <i class="fas fa-edit me-1"></i> Edit
                    </button>
                    <a href="?page=sales/create&customer_id=<?php echo $customerId; ?>" class="btn btn-success">
                        <i class="fas fa-plus-circle me-2"></i>New Sale
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

    <!-- Customer Info Cards -->
    <div class="row">
        <!-- Customer Details -->
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card shadow h-100">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-info-circle me-2"></i>Customer Information
                    </h6>
                    <span class="badge bg-<?php echo $customer['customer_type'] === 'company' ? 'primary' : 'info'; ?>">
                        <?php echo ucfirst($customer['customer_type']); ?>
                    </span>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <th style="width: 40%">Customer Code:</th>
                            <td><strong
                                    class="text-primary"><?php echo htmlspecialchars($customer['customer_code']); ?></strong>
                            </td>
                        </tr>
                        <tr>
                            <th>Full Name:</th>
                            <td class="fw-bold"><?php echo htmlspecialchars($customer['full_name']); ?></td>
                        </tr>
                        <tr>
                            <th>Status:</th>
                            <td>
                                <?php if ($customer['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                </div>
                </td>
                </tr>
                <tr>
                    <th>Customer Since:</th>
                    <td><?php echo date('d/m/Y', strtotime($customer['created_at'])); ?>
            </div>
            </td>
            </tr>
            <tr>
                <th>Last Activity:</th>
                <td>
                    <?php if ($customer['last_purchase_date']): ?>
                        <?php echo time_ago($customer['last_purchase_date']); ?>
                    <?php else: ?>
                        <span class="text-muted">No purchases yet</span>
                    <?php endif; ?>
        </div>
        </td>
        </tr>
        </table>
    </div>
</div>
</div>

<!-- Contact Information -->
<div class="col-xl-4 col-md-6 mb-4">
    <div class="card shadow h-100">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-address-card me-2"></i>Contact Information
            </h6>
        </div>
        <div class="card-body">
            <table class="table table-sm">
                <tr>
                    <th style="width: 40%"><i class="fas fa-phone me-2"></i>Phone:</th>
                    <td>
                        <?php if ($customer['phone']): ?>
                            <a href="tel:<?php echo htmlspecialchars($customer['phone']); ?>">
                                <?php echo htmlspecialchars($customer['phone']); ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted">Not provided</span>
                        <?php endif; ?>
        </div>
        </td>
        </tr>
        <tr>
            <th><i class="fas fa-envelope me-2"></i>Email:</th>
            <td>
                <?php if ($customer['email']): ?>
                    <a href="mailto:<?php echo htmlspecialchars($customer['email']); ?>">
                        <?php echo htmlspecialchars($customer['email']); ?>
                    </a>
                <?php else: ?>
                    <span class="text-muted">Not provided</span>
                <?php endif; ?>
    </div>
    </td>
    </tr>
    <tr>
        <th><i class="fas fa-map-marker-alt me-2"></i>Address:</th>
        <td>
            <?php if ($customer['address']): ?>
                <?php echo nl2br(htmlspecialchars($customer['address'])); ?>
            <?php else: ?>
                <span class="text-muted">Not provided</span>
            <?php endif; ?>
</div>
</td>
</tr>
<tr>
    <th><i class="fas fa-city me-2"></i>City:</th>
    <td>
        <?php if ($customer['city']): ?>
            <?php echo htmlspecialchars($customer['city']); ?>
        <?php else: ?>
            <span class="text-muted">Not provided</span>
        <?php endif; ?>
        </div>
    </td>
</tr>
<tr>
    <th><i class="fas fa-id-card me-2"></i>Tax ID:</th>
    <td>
        <?php if ($customer['tax_id']): ?>
            <?php echo htmlspecialchars($customer['tax_id']); ?>
        <?php else: ?>
            <span class="text-muted">Not provided</span>
        <?php endif; ?>
        </div>
    </td>
</tr>
</table>
</div>
</div>
</div>

<!-- Financial Summary -->
<div class="col-xl-4 col-md-12 mb-4">
    <div class="card shadow h-100">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-chart-pie me-2"></i>Financial Summary
            </h6>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-6">
                    <div class="text-muted small">Credit Limit</div>
                    <div class="h5 mb-0"><?php echo format_currency($customer['credit_limit'] ?? 0, $companyId); ?>
                    </div>
                </div>
                <div class="col-6">
                    <div class="text-muted small">Current Balance</div>
                    <div
                        class="h5 mb-0 <?php echo ($customer['current_balance'] ?? 0) > 0 ? 'text-danger' : 'text-success'; ?>">
                        <?php echo format_currency($customer['current_balance'] ?? 0, $companyId); ?>
                    </div>
                </div>
            </div>

            <div class="progress mb-3" style="height: 10px;">
                <?php
                $creditLimit = $customer['credit_limit'] ?? 1;
                $balance = $customer['current_balance'] ?? 0;
                $percentage = $creditLimit > 0 ? min(100, ($balance / $creditLimit) * 100) : 0;
                $progressClass = $percentage > 90 ? 'bg-danger' : ($percentage > 70 ? 'bg-warning' : 'bg-success');
                ?>
                <div class="progress-bar <?php echo $progressClass; ?>" role="progressbar"
                    style="width: <?php echo $percentage; ?>%;" aria-valuenow="<?php echo $percentage; ?>"
                    aria-valuemin="0" aria-valuemax="100">
                </div>
            </div>

            <hr>

            <div class="row mb-3">
                <div class="col-6">
                    <div class="text-muted small">Total Invoices</div>
                    <div class="h5 mb-0"><?php echo number_format($customer['total_invoices'] ?? 0); ?></div>
                </div>
                <div class="col-6">
                    <div class="text-muted small">Total Purchases</div>
                    <div class="h5 mb-0"><?php echo format_currency($customer['total_purchases'] ?? 0, $companyId); ?>
                    </div>
                </div>
            </div>

            <hr>

            <div class="row">
                <div class="col-12">
                    <div class="text-muted small">Outstanding Balance</div>
                    <div
                        class="h4 mb-0 <?php echo ($customer['outstanding_balance'] ?? 0) > 0 ? 'text-danger' : 'text-success'; ?>">
                        <?php echo format_currency($customer['outstanding_balance'] ?? 0, $companyId); ?>
                    </div>
                    <small class="text-muted">
                        <?php if (($customer['outstanding_balance'] ?? 0) > 0): ?>
                            <i class="fas fa-exclamation-circle me-1"></i>Payment required
                        <?php else: ?>
                            <i class="fas fa-check-circle me-1"></i>No outstanding balance
                        <?php endif; ?>
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<!-- Tabs for Invoices and Payments -->
<div class="row">
    <div class="col-12 mb-4">
        <ul class="nav nav-tabs" id="customerTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="invoices-tab" data-bs-toggle="tab" data-bs-target="#invoices"
                    type="button" role="tab" aria-controls="invoices" aria-selected="true">
                    <i class="fas fa-file-invoice me-2"></i>Recent Invoices
                    <span class="badge bg-primary ms-2"><?php echo count($recentInvoices); ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="payments-tab" data-bs-toggle="tab" data-bs-target="#payments" type="button"
                    role="tab" aria-controls="payments" aria-selected="false">
                    <i class="fas fa-credit-card me-2"></i>Recent Payments
                    <span class="badge bg-success ms-2"><?php echo count($recentPayments); ?></span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="notes-tab" data-bs-toggle="tab" data-bs-target="#notes" type="button"
                    role="tab" aria-controls="notes" aria-selected="false">
                    <i class="fas fa-sticky-note me-2"></i>Notes
                </button>
            </li>
        </ul>

        <div class="tab-content p-3 border border-top-0 rounded-bottom bg-white" id="customerTabsContent">
            <!-- Invoices Tab -->
            <div class="tab-pane fade show active" id="invoices" role="tabpanel" aria-labelledby="invoices-tab">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Invoice #</th>
                                <th>Date</th>
                                <th>Items</th>
                                <th class="text-end">Total</th>
                                <th class="text-end">Paid</th>
                                <th class="text-end">Balance</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentInvoices)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">
                                        <div class="text-muted">
                                            <i class="fas fa-file-invoice fa-3x mb-3"></i>
                                            <p class="mb-0">No invoices found for this customer</p>
                                            <a href="?page=sales/create&customer_id=<?php echo $customerId; ?>"
                                                class="btn btn-sm btn-primary mt-3">
                                                <i class="fas fa-plus-circle me-1"></i>Create First Invoice
                                            </a>
                                        </div>
                    </div>
                    </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentInvoices as $invoice): ?>
                        <tr>
                            <td>
                                <strong><code><?php echo htmlspecialchars($invoice['invoice_number']); ?></code></strong>
                    </div>
                    </td>
                    <td>
                        <?php echo date('d/m/Y', strtotime($invoice['invoice_date'])); ?>
                </div>
                </td>
                <td class="text-center"><?php echo $invoice['item_count']; ?></td>
                <td class="text-end"><?php echo format_currency($invoice['total_amount'], $companyId); ?></td>
                <td class="text-end text-success"><?php echo format_currency($invoice['amount_paid'], $companyId); ?></td>
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
                        <a href="?page=sales/view-invoice&id=<?php echo $invoice['id']; ?>" class="btn btn-outline-primary"
                            title="View Invoice">
                            <i class="fas fa-eye"></i>
                        </a>
                        <?php if ($invoice['status'] !== 'paid' && $invoice['status'] !== 'cancelled'): ?>
                            <button type="button" class="btn btn-outline-success"
                                onclick="recordPayment(<?php echo $invoice['id']; ?>, '<?php echo htmlspecialchars($invoice['invoice_number']); ?>', <?php echo $invoice['total_amount'] - $invoice['amount_paid']; ?>)"
                                title="Record Payment">
                                <i class="fas fa-credit-card"></i>
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

<!-- Payments Tab -->
<div class="tab-pane fade" id="payments" role="tabpanel" aria-labelledby="payments-tab">
    <div class="table-responsive">
        <table class="table table-hover">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Payment #</th>
                    <th>Invoice</th>
                    <th>Method</th>
                    <th class="text-end">Amount</th>
                    <th>Reference</th>
                    <th>Processed By</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentPayments)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4">
                            <div class="text-muted">
                                <i class="fas fa-credit-card fa-3x mb-3"></i>
                                <p class="mb-0">No payment records found</p>
                            </div>
        </div>
        </td>
        </tr>
    <?php else: ?>
        <?php foreach ($recentPayments as $payment): ?>
            <tr>
                <td><?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?></td>
                <td><code><?php echo htmlspecialchars($payment['payment_number']); ?></code></td>
                <td>
                    <a href="?page=sales/view-invoice&id=<?php echo $payment['invoice_id']; ?>">
                        <?php echo htmlspecialchars($payment['invoice_number']); ?>
                    </a>
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
                    $methodLabels = [
                        'cash' => 'Cash',
                        'bank_transfer' => 'Bank Transfer',
                        'mobile' => 'Mobile Money',
                        'card' => 'Card',
                        'cheque' => 'Cheque'
                    ];
                    $methodClass = [
                        'cash' => 'success',
                        'bank_transfer' => 'info',
                        'mobile' => 'warning',
                        'card' => 'primary',
                        'cheque' => 'secondary'
                    ];
                    ?>
                    <span class="badge bg-<?php echo $methodClass[$payment['payment_method']] ?? 'secondary'; ?>">
                        <i class="fas <?php echo $methodIcons[$payment['payment_method']] ?? 'fa-credit-card'; ?> me-1"></i>
                        <?php echo $methodLabels[$payment['payment_method']] ?? ucfirst($payment['payment_method']); ?>
                    </span>
                </td>
                <td class="text-end text-success fw-bold"><?php echo format_currency($payment['amount'], $companyId); ?></td>
                <td>
                    <?php if ($payment['reference_number']): ?>
                        <small><?php echo htmlspecialchars($payment['reference_number']); ?></small>
                    <?php else: ?>
                        <span class="text-muted">-</span>
                    <?php endif; ?>
                </td>
                <td><small><?php echo htmlspecialchars($payment['created_by_name'] ?? 'System'); ?></small></td>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
    </table>
</div>
</div>

<!-- Notes Tab -->
<div class="tab-pane fade" id="notes" role="tabpanel" aria-labelledby="notes-tab">
    <div class="p-3">
        <?php if ($customer['notes']): ?>
            <div class="bg-light p-3 rounded">
                <?php echo nl2br(htmlspecialchars($customer['notes'])); ?>
            </div>
        <?php else: ?>
            <div class="text-center text-muted py-4">
                <i class="fas fa-sticky-note fa-3x mb-3"></i>
                <p class="mb-0">No notes available for this customer</p>
                <a href="#" data-bs-toggle="modal" data-bs-target="#editCustomerModal" class="text-primary">
                    Edit customer to add notes
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>
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
                    <div class="mb-3">
                        <label class="form-label">Invoice</label>
                        <p class="form-control-static fw-bold" id="payment_invoice_number"></p>
                    </div>

                    <div class="mb-3">
                        <label for="payment_amount" class="form-label">Amount <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text currency-symbol"><?php echo $currency; ?></span>
                            <input type="number" class="form-control" id="payment_amount" name="amount" min="0.01"
                                step="0.01" required>
                        </div>
                        <small class="text-muted" id="balance_hint"></small>
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
                        <label for="payment_reference" class="form-label">Reference</label>
                        <input type="text" class="form-control" id="payment_reference" name="reference_number"
                            placeholder="Transaction ID, Cheque #, etc.">
                    </div>

                    <div class="mb-3">
                        <label for="payment_notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="payment_notes" name="notes" rows="2"></textarea>
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

<!-- Edit Customer Modal -->
<div class="modal fade" id="editCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title">
                    <i class="fas fa-edit me-2"></i>Edit Customer
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="?page=sales/update-customer" id="editCustomerForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="id" value="<?php echo $customerId; ?>">
                <input type="hidden" name="company_id" value="<?php echo $companyId; ?>">

                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_full_name" class="form-label">Full Name / Company Name <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_full_name" name="full_name"
                                value="<?php echo htmlspecialchars($customer['full_name']); ?>" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="edit_customer_type" class="form-label">Customer Type</label>
                            <select class="form-control" id="edit_customer_type" name="customer_type">
                                <option value="individual" <?php echo $customer['customer_type'] === 'individual' ? 'selected' : ''; ?>>👤 Individual</option>
                                <option value="company" <?php echo $customer['customer_type'] === 'company' ? 'selected' : ''; ?>>🏢 Company</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_phone" class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="edit_phone" name="phone"
                                value="<?php echo htmlspecialchars($customer['phone'] ?? ''); ?>">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="edit_email" class="form-label">Email Address</label>
                            <input type="email" class="form-control" id="edit_email" name="email"
                                value="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="edit_address" class="form-label">Address</label>
                        <textarea class="form-control" id="edit_address" name="address"
                            rows="2"><?php echo htmlspecialchars($customer['address'] ?? ''); ?></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_city" class="form-label">City</label>
                            <input type="text" class="form-control" id="edit_city" name="city"
                                value="<?php echo htmlspecialchars($customer['city'] ?? ''); ?>">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="edit_tax_id" class="form-label">Tax ID / VAT Number</label>
                            <input type="text" class="form-control" id="edit_tax_id" name="tax_id"
                                value="<?php echo htmlspecialchars($customer['tax_id'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_credit_limit" class="form-label">Credit Limit</label>
                            <div class="input-group">
                                <span class="input-group-text currency-symbol"><?php echo $currency; ?></span>
                                <input type="number" class="form-control" id="edit_credit_limit" name="credit_limit"
                                    min="0" step="0.01" value="<?php echo $customer['credit_limit'] ?? 0; ?>">
                            </div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="edit_is_active" class="form-label">Status</label>
                            <select class="form-control" id="edit_is_active" name="is_active">
                                <option value="1" <?php echo $customer['is_active'] ? 'selected' : ''; ?>>✅ Active
                                </option>
                                <option value="0" <?php echo !$customer['is_active'] ? 'selected' : ''; ?>>❌ Inactive
                                </option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="edit_notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="edit_notes" name="notes"
                            rows="2"><?php echo htmlspecialchars($customer['notes'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save me-2"></i>Update Customer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function recordPayment(invoiceId, invoiceNumber, balance) {
        document.getElementById('payment_invoice_id').value = invoiceId;
        document.getElementById('payment_invoice_number').textContent = invoiceNumber;
        document.getElementById('payment_amount').max = balance;
        document.getElementById('payment_amount').value = balance;
        document.getElementById('balance_hint').textContent = 'Outstanding balance: <?php echo $currency; ?> ' + balance.toLocaleString();

        new bootstrap.Modal(document.getElementById('paymentModal')).show();
    }

    // Auto-hide alerts after 5 seconds
    setTimeout(function () {
        document.querySelectorAll('.alert').forEach(function (alert) {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            if (bsAlert) bsAlert.close();
        });
    }, 5000);
</script>

<style>
    .view-customer .border-left-primary {
        border-left: 4px solid #4e73df !important;
    }

    .view-customer .border-left-success {
        border-left: 4px solid #1cc88a !important;
    }

    .view-customer .border-left-info {
        border-left: 4px solid #36b9cc !important;
    }

    .view-customer .table td {
        vertical-align: middle;
    }

    .view-customer .progress {
        background-color: #e9ecef;
        border-radius: 0.25rem;
    }

    .view-customer .nav-tabs .nav-link {
        color: #495057;
    }

    .view-customer .nav-tabs .nav-link.active {
        font-weight: 600;
        color: #4e73df;
        border-bottom: 2px solid #4e73df;
    }

    .view-customer .tab-content {
        background-color: #fff;
        border: 1px solid #dee2e6;
        border-top: none;
    }

    .view-customer .btn-group {
        gap: 2px;
    }

    .view-customer .currency-symbol {
        min-width: 50px;
    }
</style>