<?php
// pages/sales/view-invoice.php
declare(strict_types=1);

$pageTitle = 'View Invoice - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';

// Load models
require_once __DIR__ . '/../../models/Sale.php';
require_once __DIR__ . '/../../models/Customer.php';
require_once __DIR__ . '/../../models/Payment.php';
require_once __DIR__ . '/../../models/Variant.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Get invoice ID from URL
$invoiceId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$invoiceId) {
    SessionManager::flash('error', 'Invoice ID is required.');
    header('Location: ' . route_url('sales/invoices'));
    exit;
}

// Initialize models with company context
$saleModel = new Sale($companyId);
$paymentModel = new Payment($companyId);
$variantModel = new Variant($companyId);

// Get invoice details (company-specific)
$invoice = $saleModel->getInvoiceWithDetails($invoiceId);

if (!$invoice) {
    SessionManager::flash('error', 'Invoice not found or not accessible for your company.');
    header('Location: ' . route_url('sales/invoices'));
    exit;
}

// Calculate balance
$balance = $invoice['total_amount'] - $invoice['amount_paid'];

// Get currency
$currency = $_SESSION['company_currency'] ?? 'RWF';

// Generate CSRF token for payment form
$csrfToken = CSRF::generate();

// Get product images for each item
foreach ($invoice['items'] as &$item) {
    // Get primary image for variant
    $images = $variantModel->getImages($item['variant_id']);
    $item['image_url'] = !empty($images) ? $images[0]['image_url'] : null;
}

// Status configuration
$statusConfig = [
    'draft' => ['class' => 'secondary', 'icon' => 'fa-pencil-alt', 'label' => 'Draft'],
    'issued' => ['class' => 'primary', 'icon' => 'fa-paper-plane', 'label' => 'Issued'],
    'paid' => ['class' => 'success', 'icon' => 'fa-check-circle', 'label' => 'Paid'],
    'partial' => ['class' => 'warning', 'icon' => 'fa-chart-line', 'label' => 'Partial'],
    'overdue' => ['class' => 'danger', 'icon' => 'fa-exclamation-triangle', 'label' => 'Overdue'],
    'cancelled' => ['class' => 'dark', 'icon' => 'fa-ban', 'label' => 'Cancelled']
];

$methodClass = [
    'cash' => 'success',
    'bank_transfer' => 'info',
    'mobile' => 'warning',
    'card' => 'primary',
    'cheque' => 'secondary'
];
?>

<!-- Page Header -->
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <a href="<?php echo route_url('sales/invoices'); ?>" class="btn btn-outline-secondary btn-sm mb-2">
                    <i class="fas fa-arrow-left me-1"></i> Back to Invoices
                </a>
                <h2 class="h4 mb-1 text-gray-800">
                    <i class="fas fa-file-invoice me-2"></i>Invoice Details
                </h2>
                <p class="mb-0 text-muted">Viewing invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
            </div>
            <div class="btn-group mt-2 mt-sm-0">
                <button type="button" class="btn btn-primary" onclick="window.print()">
                    <i class="fas fa-print me-2"></i>Print
                </button>
                <?php if ($balance > 0 && $invoice['status'] !== 'cancelled' && $invoice['status'] !== 'paid'): ?>
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#paymentModal">
                            <i class="fas fa-credit-card me-2"></i>Record Payment
                        </button>
                <?php endif; ?>
                <?php if (in_array($userRole, ['ADM', 'MGR']) && $invoice['status'] !== 'cancelled' && $invoice['status'] !== 'paid'): ?>
                        <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#cancelModal">
                            <i class="fas fa-ban me-2"></i>Cancel Invoice
                        </button>
                <?php endif; ?>
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

<!-- Status Banner -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body py-3">
                <div class="d-flex align-items-center justify-content-between flex-wrap">
                    <div>
                        <span class="text-muted me-2">Status:</span>
                        <?php $config = $statusConfig[$invoice['status']] ?? ['class' => 'secondary', 'icon' => 'fa-question']; ?>
                        <span class="badge bg-<?php echo $config['class']; ?> fs-6 p-2">
                            <i class="fas <?php echo $config['icon']; ?> me-1"></i>
                            <?php echo strtoupper($invoice['status']); ?>
                        </span>
                    </div>
                    <div class="mt-2 mt-sm-0">
                        <span class="text-muted me-2">Balance Due:</span>
                        <span class="h5 mb-0 <?php echo $balance > 0 ? 'text-danger' : 'text-success'; ?>">
                            <?php echo format_currency($balance, $companyId); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Invoice Details - Left Column -->
    <div class="col-md-8">
        <!-- Invoice Information -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-info-circle me-2"></i>Invoice Information
                </h6>
            </div>
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-6">
                        <table class="table table-sm">
                            <tr><th style="width: 40%">Invoice Number:</th><td><strong class="text-primary"><?php echo htmlspecialchars($invoice['invoice_number']); ?></strong></td></tr>
                            <tr><th>Invoice Date:</th><td><?php echo date('d/m/Y', strtotime($invoice['invoice_date'])); ?></td></tr>
                            <tr><th>Due Date:</th><td><?php echo date('d/m/Y', strtotime($invoice['due_date'])); ?>
                                <?php if (strtotime($invoice['due_date']) < time() && $invoice['status'] !== 'paid' && $invoice['status'] !== 'cancelled'): ?>
                                        <span class="badge bg-danger ms-2">Overdue</span>
                                <?php endif; ?>
                            </td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm">
                            <tr><th style="width: 40%">Subtotal:</th><td class="text-end"><?php echo format_currency($invoice['subtotal'], $companyId); ?></td></tr>
                            <tr><th>Discount:</th><td class="text-end text-danger">-<?php echo format_currency($invoice['discount_amount'] ?? 0, $companyId); ?></td></tr>
                            <tr><th>Tax:</th><td class="text-end"><?php echo format_currency($invoice['tax_amount'], $companyId); ?></td></tr>
                            <tr class="fw-bold"><th>Total:</th><td class="text-end text-primary fs-5"><?php echo format_currency($invoice['total_amount'], $companyId); ?></td></tr>
                        </table>
                    </div>
                </div>
                <?php if (!empty($invoice['notes'])): ?>
                        <div class="row">
                            <div class="col-12">
                                <h6 class="fw-bold">Notes:</h6>
                                <div class="bg-light p-3 rounded"><?php echo nl2br(htmlspecialchars($invoice['notes'])); ?></div>
                            </div>
                        </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Items with Images -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-shopping-cart me-2"></i>Order Items
                </h6>
            </div>
            <div class="card-body">
                <?php if (empty($invoice['items'])): ?>
                        <div class="text-center py-4 text-muted">No items found</div>
                <?php else: ?>
                        <?php foreach ($invoice['items'] as $item):
                            $lineSubtotal = $item['quantity'] * $item['unit_price'];
                            $lineDiscount = $lineSubtotal * ($item['discount_percent'] / 100);
                            $lineAfterDiscount = $lineSubtotal - $lineDiscount;
                            $lineTax = $lineAfterDiscount * ($item['tax_rate'] / 100);
                            $lineTotal = $lineAfterDiscount + $lineTax;
                            ?>
                                <div class="row align-items-center mb-3 pb-3 border-bottom">
                                    <div class="col-auto">
                                        <?php if (!empty($item['image_url']) && file_exists($_SERVER['DOCUMENT_ROOT'] . '/sati_premium/assets/' . $item['image_url'])): ?>
                                                <img src="<?php echo BASE_URL . '/assets/' . $item['image_url']; ?>" 
                                                     alt="<?php echo htmlspecialchars($item['product_name']); ?>"
                                                     style="width: 60px; height: 60px; object-fit: cover; border-radius: 8px;">
                                        <?php else: ?>
                                                <div style="width: 60px; height: 60px; background: #f8f9fc; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                                    <i class="fas fa-box fa-2x text-muted"></i>
                                                </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-4">
                                        <h6 class="mb-1 fw-bold"><?php echo htmlspecialchars($item['product_name']); ?></h6>
                                        <?php if (!empty($item['variant_name']) && $item['variant_name'] !== 'Standard'): ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($item['variant_name']); ?></small><br>
                                        <?php endif; ?>
                                        <small class="text-muted">SKU: <?php echo htmlspecialchars($item['sku']); ?></small>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="row text-center">
                                            <div class="col-3">
                                                <small class="text-muted d-block">Qty</small>
                                                <strong><?php echo number_format((float) $item['quantity'], 0); ?></strong>
                                            </div>
                                            <div class="col-3">
                                                <small class="text-muted d-block">Unit Price</small>
                                                <strong><?php echo format_currency($item['unit_price'], $companyId); ?></strong>
                                            </div>
                                            <div class="col-3">
                                                <small class="text-muted d-block">Discount</small>
                                                <?php if ($item['discount_percent'] > 0): ?>
                                                        <strong class="text-danger"><?php echo $item['discount_percent']; ?>%</strong>
                                                        <small class="text-muted d-block">-<?php echo format_currency($lineDiscount, $companyId); ?></small>
                                                <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-3">
                                                <small class="text-muted d-block">Total</small>
                                                <strong class="text-primary"><?php echo format_currency($lineTotal, $companyId); ?></strong>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                        <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Sidebar - Right Column -->
    <div class="col-md-4">
        <!-- Customer Information -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-user me-2"></i>Customer Information
                </h6>
            </div>
            <div class="card-body">
                <h5 class="fw-bold"><?php echo htmlspecialchars($invoice['customer_name']); ?></h5>
                <?php if (!empty($invoice['customer_phone'])): ?>
                        <p class="mb-1"><i class="fas fa-phone me-2 text-muted"></i><a href="tel:<?php echo htmlspecialchars($invoice['customer_phone']); ?>"><?php echo htmlspecialchars($invoice['customer_phone']); ?></a></p>
                <?php endif; ?>
                <?php if (!empty($invoice['customer_email'])): ?>
                        <p class="mb-1"><i class="fas fa-envelope me-2 text-muted"></i><a href="mailto:<?php echo htmlspecialchars($invoice['customer_email']); ?>"><?php echo htmlspecialchars($invoice['customer_email']); ?></a></p>
                <?php endif; ?>
                <?php if (!empty($invoice['customer_address'])): ?>
                        <p class="mb-0"><i class="fas fa-map-marker-alt me-2 text-muted"></i><?php echo nl2br(htmlspecialchars($invoice['customer_address'])); ?></p>
                <?php endif; ?>
                <hr>
                <a href="?page=sales/view-customer&id=<?php echo $invoice['customer_id']; ?>" class="btn btn-outline-primary btn-sm w-100">
                    <i class="fas fa-user-circle me-2"></i>View Customer Profile
                </a>
            </div>
        </div>

        <!-- Payment History -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-credit-card me-2"></i>Payment History
                </h6>
                <?php if ($balance > 0 && $invoice['status'] !== 'cancelled' && $invoice['status'] !== 'paid'): ?>
                        <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#paymentModal">
                            <i class="fas fa-plus"></i>
                        </button>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($invoice['payments'])): ?>
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-credit-card fa-3x mb-3 opacity-50"></i>
                            <p class="mb-0">No payments recorded</p>
                        </div>
                <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($invoice['payments'] as $payment): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <small class="text-muted"><?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?></small><br>
                                                <code><?php echo htmlspecialchars($payment['payment_number']); ?></code>
                                            </div>
                                            <div class="text-end">
                                                <span class="fw-bold text-success"><?php echo format_currency($payment['amount'], $companyId); ?></span><br>
                                                <small class="badge bg-<?php echo $methodClass[$payment['payment_method']] ?? 'secondary'; ?>">
                                                    <?php echo ucfirst($payment['payment_method']); ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                            <?php endforeach; ?>
                        </div>
                <?php endif; ?>
            </div>
            <?php if (!empty($invoice['payments'])): ?>
                    <div class="card-footer bg-light">
                        <div class="d-flex justify-content-between">
                            <span class="fw-bold">Total Paid:</span>
                            <span class="fw-bold text-success"><?php echo format_currency($invoice['amount_paid'], $companyId); ?></span>
                        </div>
                    </div>
            <?php endif; ?>
        </div>

        <!-- Quick Actions -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-bolt me-2"></i>Quick Actions
                </h6>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-outline-primary" onclick="window.print()">
                        <i class="fas fa-print me-2"></i>Print Invoice
                    </button>
                    <a href="?page=sales/create&customer_id=<?php echo $invoice['customer_id']; ?>" class="btn btn-outline-success">
                        <i class="fas fa-plus-circle me-2"></i>New Sale for this Customer
                    </a>
                    <?php if ($balance > 0 && $invoice['status'] !== 'cancelled' && $invoice['status'] !== 'paid'): ?>
                            <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#paymentModal">
                                <i class="fas fa-credit-card me-2"></i>Record Payment
                            </button>
                    <?php endif; ?>
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
                <h5 class="modal-title"><i class="fas fa-credit-card me-2"></i>Record Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="?page=sales/record-payment">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="invoice_id" value="<?php echo $invoiceId; ?>">
                <input type="hidden" name="company_id" value="<?php echo $companyId; ?>">
                
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">Invoice</label><p class="form-control-static fw-bold"><?php echo htmlspecialchars($invoice['invoice_number']); ?></p></div>
                    <div class="mb-3"><label for="amount" class="form-label">Amount <span class="text-danger">*</span></label>
                        <div class="input-group"><span class="input-group-text"><?php echo $currency; ?></span>
                        <input type="number" class="form-control" id="amount" name="amount" min="0.01" max="<?php echo $balance; ?>" step="0.01" value="<?php echo $balance; ?>" required></div>
                        <small class="text-muted">Outstanding balance: <?php echo format_currency($balance, $companyId); ?></small>
                    </div>
                    <div class="mb-3"><label for="payment_method" class="form-label">Payment Method</label>
                        <select class="form-control" id="payment_method" name="payment_method">
                            <option value="cash">💰 Cash</option>
                            <option value="bank_transfer">🏦 Bank Transfer</option>
                            <option value="mobile">📱 Mobile Money</option>
                            <option value="card">💳 Card</option>
                            <option value="cheque">📝 Cheque</option>
                        </select>
                    </div>
                    <div class="mb-3"><label for="payment_date" class="form-label">Payment Date</label>
                        <input type="date" class="form-control" id="payment_date" name="payment_date" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="mb-3"><label for="reference_number" class="form-label">Reference Number</label>
                        <input type="text" class="form-control" id="reference_number" name="reference_number" placeholder="Transaction ID, Cheque #, etc.">
                    </div>
                    <div class="mb-3"><label for="payment_notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="payment_notes" name="notes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success">Record Payment</button></div>
            </form>
        </div>
    </div>
</div>

<!-- Cancel Invoice Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-ban me-2"></i>Cancel Invoice</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="?page=sales/cancel-invoice">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="invoice_id" value="<?php echo $invoiceId; ?>">
                <input type="hidden" name="company_id" value="<?php echo $companyId; ?>">
                
                <div class="modal-body">
                    <div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i><strong>Warning:</strong> This action cannot be undone and will reverse all inventory movements.</div>
                    <p>Are you sure you want to cancel invoice <strong><?php echo htmlspecialchars($invoice['invoice_number']); ?></strong>?</p>
                    <div class="mb-3"><label for="cancel_reason" class="form-label">Reason for Cancellation <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="cancel_reason" name="reason" rows="3" required placeholder="Please provide a reason for cancelling this invoice"></textarea>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">No, Keep Invoice</button><button type="submit" class="btn btn-danger">Yes, Cancel Invoice</button></div>
            </form>
        </div>
    </div>
</div>

<!-- Print Styles -->
<style media="print">
    @page { size: A4; margin: 1cm; }
    body { background: white; font-size: 12pt; }
    .btn-group, .btn, .modal, .no-print, .card-header .btn, footer, nav, .alert, .btn-outline-secondary, .btn-primary, .btn-success, .btn-danger { display: none !important; }
    .container-fluid { width: 100%; padding: 0; margin: 0; }
    .card { border: none !important; box-shadow: none !important; }
    .card-header { background: none !important; border-bottom: 1px solid #ddd !important; }
    .table th, .table td { border: 1px solid #ddd; padding: 8px; }
    .badge { border: 1px solid #000; background: none !important; color: #000 !important; }
    .text-primary, .text-success, .text-danger, .text-warning { color: #000 !important; }
    .row { margin: 0; }
    .col-md-8, .col-md-4 { width: 100%; float: none; }
</style>

<script>
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            if (bsAlert) bsAlert.close();
        });
    }, 5000);
    
    // Validate payment amount
    document.getElementById('paymentForm')?.addEventListener('submit', function(e) {
        const amount = parseFloat(document.getElementById('amount').value);
        const maxAmount = parseFloat('<?php echo $balance; ?>');
        if (isNaN(amount) || amount <= 0) { e.preventDefault(); alert('Please enter a valid payment amount.'); }
        else if (amount > maxAmount) { e.preventDefault(); alert('Payment amount cannot exceed the outstanding balance of <?php echo format_currency($balance, $companyId); ?>'); }
    });
    
    // Validate cancel reason
    document.getElementById('cancelForm')?.addEventListener('submit', function(e) {
        const reason = document.getElementById('cancel_reason').value.trim();
        if (!reason) { e.preventDefault(); alert('Please provide a reason for cancelling this invoice.'); }
    });
</script>