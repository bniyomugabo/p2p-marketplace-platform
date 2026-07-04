<?php
// pages/quotations/view.php
declare(strict_types=1);

$pageTitle = 'View Quotation - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Quotation.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR', 'SEL'])) {
    SessionManager::flash('error', 'You do not have permission to view quotations.');
    header('Location: ' . route_url('quotations'));
    exit;
}

// Check if company context is set
if (!$companyId) {
    SessionManager::flash('error', 'Company context not found. Please log in again.');
    header('Location: ' . route_url('login'));
    exit;
}

// Get quotation ID from URL
$quotationId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$quotationId) {
    SessionManager::flash('error', 'Quotation ID is required.');
    header('Location: ' . route_url('quotations'));
    exit;
}

// Initialize model with company ID
$quotationModel = new Quotation($companyId);
$quotation = $quotationModel->getWithItems($quotationId);

if (!$quotation) {
    SessionManager::flash('error', 'Quotation not found or does not belong to your company.');
    header('Location: ' . route_url('quotations'));
    exit;
}

// Check if quotation is expired
$isExpired = strtotime($quotation['valid_until']) < time() && $quotation['status'] === 'sent';

// Get company info for display (optional)
$companyInfo = [
    'name' => $_SESSION['company_name'] ?? 'SATI ERP',
    'address' => $_SESSION['company_address'] ?? 'Kigali, Rwanda',
    'phone' => $_SESSION['company_phone'] ?? '+250 788 123 456',
    'email' => $_SESSION['company_email'] ?? 'info@sati.com'
];

// Generate CSRF token for status updates
$csrfToken = CSRF::generate();
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <a href="<?php echo route_url('quotations'); ?>" class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Quotations
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-file-contract me-2"></i>Quotation Details
                    </h2>
                    <p class="mb-0 text-muted">Viewing quotation #
                        <?php echo htmlspecialchars($quotation['quotation_number']); ?>
                    </p>
                </div>
                <div class="btn-group flex-wrap gap-2">
                    <a href="?page=quotations/print&id=<?php echo $quotationId; ?>" class="btn btn-outline-primary"
                        target="_blank">
                        <i class="fas fa-print me-2"></i>Print
                    </a>
                    <?php if ($quotation['status'] === 'draft'): ?>
                        <a href="?page=quotations/edit&id=<?php echo $quotationId; ?>" class="btn btn-warning">
                            <i class="fas fa-edit me-2"></i>Edit
                        </a>
                    <?php endif; ?>
                    <?php if ($quotation['status'] === 'sent' && !$isExpired && in_array($userRole, ['ADM', 'MGR', 'SEL'])): ?>
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#acceptModal">
                            <i class="fas fa-check-circle me-2"></i>Mark Accepted
                        </button>
                        <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">
                            <i class="fas fa-times-circle me-2"></i>Mark Rejected
                        </button>
                    <?php endif; ?>
                    <?php if ($quotation['status'] === 'accepted'): ?>
                        <a href="?page=sales/create&quotation_id=<?php echo $quotationId; ?>" class="btn btn-success">
                            <i class="fas fa-file-invoice me-2"></i>Convert to Invoice
                        </a>
                    <?php endif; ?>
                    <?php if (in_array($userRole, ['ADM', 'MGR']) && $quotation['status'] === 'draft'): ?>
                        <a href="?page=quotations/delete&id=<?php echo $quotationId; ?>" class="btn btn-outline-danger"
                            onclick="return confirm('Are you sure you want to delete this quotation? This action cannot be undone.')">
                            <i class="fas fa-trash me-2"></i>Delete
                        </a>
                    <?php endif; ?>
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

    <!-- Status Banner -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow">
                <div class="card-body py-3">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                        <div>
                            <span class="text-muted me-2">Status:</span>
                            <?php
                            $statusClass = [
                                'draft' => 'secondary',
                                'sent' => 'info',
                                'accepted' => 'success',
                                'rejected' => 'danger',
                                'expired' => 'warning'
                            ][$isExpired ? 'expired' : $quotation['status']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?php echo $statusClass; ?> fs-6 p-2">
                                <?php echo $isExpired ? 'EXPIRED' : strtoupper($quotation['status']); ?>
                            </span>
                        </div>
                        <div>
                            <span class="text-muted me-2">Valid Until:</span>
                            <span class="fw-bold <?php echo $isExpired ? 'text-danger' : ''; ?>">
                                <?php echo date('d/m/Y', strtotime($quotation['valid_until'])); ?>
                            </span>
                        </div>
                        <div>
                            <span class="text-muted me-2">Total Amount:</span>
                            <span class="fw-bold text-primary">
                                <?php echo format_currency($quotation['total_amount']); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Main Content -->
        <div class="col-lg-8">
            <!-- Company Info -->
            <div class="card shadow mb-4">
                <div class="card-body">
                    <div class="row">
                        <div class="col-6">
                            <h5 class="text-primary"><?php echo htmlspecialchars($companyInfo['name']); ?></h5>
                            <p class="mb-0"><?php echo htmlspecialchars($companyInfo['address']); ?></p>
                            <p class="mb-0"><?php echo htmlspecialchars($companyInfo['phone']); ?></p>
                            <p class="mb-0"><?php echo htmlspecialchars($companyInfo['email']); ?></p>
                        </div>
                        <div class="col-6 text-end">
                            <h3 class="text-muted">QUOTATION</h3>
                            <p class="mb-0"><strong>#<?php echo htmlspecialchars($quotation['quotation_number']); ?></strong></p>
                            <p class="mb-0">Date: <?php echo date('d/m/Y', strtotime($quotation['quotation_date'])); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Customer Info -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-user me-2"></i>Customer Information
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p class="mb-1"><strong><?php echo htmlspecialchars($quotation['customer_name']); ?></strong></p>
                            <?php if (!empty($quotation['customer_phone'])): ?>
                                <p class="mb-1"><i class="fas fa-phone me-2 text-muted"></i>
                                    <?php echo htmlspecialchars($quotation['customer_phone']); ?>
                                </p>
                            <?php endif; ?>
                            <?php if (!empty($quotation['customer_email'])): ?>
                                <p class="mb-1"><i class="fas fa-envelope me-2 text-muted"></i>
                                    <a href="mailto:<?php echo htmlspecialchars($quotation['customer_email']); ?>">
                                        <?php echo htmlspecialchars($quotation['customer_email']); ?>
                                    </a>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Items Table -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-shopping-cart me-2"></i>Items
                    </h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th>Description</th>
                                    <th class="text-end">Qty</th>
                                    <th class="text-end">Unit Price</th>
                                    <th class="text-end">Discount</th>
                                    <th class="text-end">Tax</th>
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($quotation['items'] as $item):
                                    $subtotal = (float)$item['quantity'] * (float)$item['unit_price'];
                                    $discountAmount = $subtotal * ((float)$item['discount_percent'] / 100);
                                    $afterDiscount = $subtotal - $discountAmount;
                                    $taxAmount = $afterDiscount * ((float)$item['tax_rate'] / 100);
                                    $lineTotal = $afterDiscount + $taxAmount;
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                        </td>
                                        <td>
                                            <?php echo nl2br(htmlspecialchars($item['description'] ?? '-')); ?>
                                        </td>
                                        <td class="text-end"><?php echo number_format((float)$item['quantity'], 2); ?></td>
                                        <td class="text-end"><?php echo format_currency($item['unit_price']); ?></td>
                                        <td class="text-end">
                                            <?php if ($item['discount_percent'] > 0): ?>
                                                <?php echo (float)$item['discount_percent']; ?>%
                                                <br><small class="text-danger">-<?php echo format_currency($discountAmount); ?></small>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($item['tax_rate'] > 0): ?>
                                                <?php echo (float)$item['tax_rate']; ?>%
                                                <br><small><?php echo format_currency($taxAmount); ?></small>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end fw-bold"><?php echo format_currency($lineTotal); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th colspan="6" class="text-end">Subtotal:</th>
                                    <th class="text-end"><?php echo format_currency($quotation['subtotal']); ?></th>
                                </tr>
                                <?php if ($quotation['discount_amount'] > 0): ?>
                                <tr>
                                    <th colspan="6" class="text-end">Discount:</th>
                                    <th class="text-end text-danger">-<?php echo format_currency($quotation['discount_amount']); ?></th>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <th colspan="6" class="text-end">Tax:</th>
                                    <th class="text-end"><?php echo format_currency($quotation['tax_amount']); ?></th>
                                </tr>
                                <tr class="fw-bold">
                                    <th colspan="6" class="text-end">TOTAL:</th>
                                    <th class="text-end text-primary fs-5"><?php echo format_currency($quotation['total_amount']); ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Terms and Notes -->
            <?php if (!empty($quotation['terms_conditions']) || !empty($quotation['notes'])): ?>
                <div class="row">
                    <?php if (!empty($quotation['terms_conditions'])): ?>
                        <div class="col-md-6 mb-4">
                            <div class="card shadow h-100">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">
                                        <i class="fas fa-file-signature me-2"></i>Terms & Conditions
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <?php echo nl2br(htmlspecialchars($quotation['terms_conditions'])); ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($quotation['notes'])): ?>
                        <div class="col-md-6 mb-4">
                            <div class="card shadow h-100">
                                <div class="card-header py-3">
                                    <h6 class="m-0 font-weight-bold text-primary">
                                        <i class="fas fa-sticky-note me-2"></i>Internal Notes
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <?php echo nl2br(htmlspecialchars($quotation['notes'])); ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Summary Card -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-calculator me-2"></i>Summary
                    </h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <td>Total Items:</td>
                            <td class="text-end"><?php echo count($quotation['items']); ?></td>
                        </tr>
                        <tr>
                            <td>Subtotal:</td>
                            <td class="text-end"><?php echo format_currency($quotation['subtotal']); ?></td>
                        </tr>
                        <?php if ($quotation['discount_amount'] > 0): ?>
                        <tr>
                            <td>Discount:</td>
                            <td class="text-end text-danger">-<?php echo format_currency($quotation['discount_amount']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td>Tax:</td>
                            <td class="text-end"><?php echo format_currency($quotation['tax_amount']); ?></td>
                        </tr>
                        <tr class="fw-bold">
                            <td>TOTAL:</td>
                            <td class="text-end text-primary fs-5"><?php echo format_currency($quotation['total_amount']); ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Timeline -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-history me-2"></i>Timeline
                    </h6>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0">
                        <li class="mb-3">
                            <div class="d-flex">
                                <div class="me-3">
                                    <i class="fas fa-plus-circle text-success"></i>
                                </div>
                                <div>
                                    <p class="mb-0"><strong>Quotation Created</strong></p>
                                    <small class="text-muted">
                                        <?php echo date('d/m/Y H:i', strtotime($quotation['created_at'])); ?>
                                        <?php if (!empty($quotation['created_by_name'])): ?>
                                            by <?php echo htmlspecialchars($quotation['created_by_name']); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </div>
                        </li>
                        <?php if ($quotation['status'] === 'sent' || $quotation['status'] === 'accepted'): ?>
                            <li class="mb-3">
                                <div class="d-flex">
                                    <div class="me-3">
                                        <i class="fas fa-paper-plane text-info"></i>
                                    </div>
                                    <div>
                                        <p class="mb-0"><strong>Quotation Sent</strong></p>
                                        <small class="text-muted">Sent to customer</small>
                                    </div>
                                </div>
                            </li>
                        <?php endif; ?>
                        <?php if ($quotation['status'] === 'accepted'): ?>
                            <li class="mb-3">
                                <div class="d-flex">
                                    <div class="me-3">
                                        <i class="fas fa-check-circle text-success"></i>
                                    </div>
                                    <div>
                                        <p class="mb-0"><strong>Quotation Accepted</strong></p>
                                        <small class="text-muted">Customer accepted the offer</small>
                                    </div>
                                </div>
                            </li>
                        <?php endif; ?>
                        <?php if ($quotation['status'] === 'rejected'): ?>
                            <li class="mb-3">
                                <div class="d-flex">
                                    <div class="me-3">
                                        <i class="fas fa-times-circle text-danger"></i>
                                    </div>
                                    <div>
                                        <p class="mb-0"><strong>Quotation Rejected</strong></p>
                                        <small class="text-muted">Customer declined the offer</small>
                                    </div>
                                </div>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-bolt me-2"></i>Quick Actions
                    </h6>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="?page=quotations/print&id=<?php echo $quotationId; ?>" class="btn btn-outline-primary"
                            target="_blank">
                            <i class="fas fa-print me-2"></i>Print Quotation
                        </a>
                        <?php if (!empty($quotation['customer_email'])): ?>
                            <a href="mailto:<?php echo htmlspecialchars($quotation['customer_email']); ?>"
                                class="btn btn-outline-info">
                                <i class="fas fa-envelope me-2"></i>Email to Customer
                            </a>
                        <?php endif; ?>
                        <?php if ($quotation['status'] === 'sent' && !$isExpired && in_array($userRole, ['ADM', 'MGR', 'SEL'])): ?>
                            <button type="button" class="btn btn-outline-success" data-bs-toggle="modal"
                                data-bs-target="#acceptModal">
                                <i class="fas fa-check-circle me-2"></i>Accept Quotation
                            </button>
                            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal"
                                data-bs-target="#rejectModal">
                                <i class="fas fa-times-circle me-2"></i>Reject Quotation
                            </button>
                        <?php endif; ?>
                        <?php if ($quotation['status'] === 'draft'): ?>
                            <a href="?page=quotations/edit&id=<?php echo $quotationId; ?>" class="btn btn-outline-warning">
                                <i class="fas fa-edit me-2"></i>Edit Quotation
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Accept Modal -->
<div class="modal fade" id="acceptModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="fas fa-check-circle me-2"></i>Accept Quotation
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="?page=quotations/update-status">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="id" value="<?php echo $quotationId; ?>">
                <input type="hidden" name="status" value="accepted">

                <div class="modal-body">
                    <p>Mark quotation <strong><?php echo htmlspecialchars($quotation['quotation_number']); ?></strong> as accepted?</p>
                    <p class="text-muted small">This will change the status to "Accepted" and you can later convert it to an invoice.</p>

                    <div class="mb-3">
                        <label for="accept_notes" class="form-label">Notes (Optional)</label>
                        <textarea class="form-control" id="accept_notes" name="notes" rows="2"
                            placeholder="Any notes about the acceptance..."></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check-circle me-2"></i>Accept Quotation
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="fas fa-times-circle me-2"></i>Reject Quotation
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="?page=quotations/update-status">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="id" value="<?php echo $quotationId; ?>">
                <input type="hidden" name="status" value="rejected">

                <div class="modal-body">
                    <p>Mark quotation <strong><?php echo htmlspecialchars($quotation['quotation_number']); ?></strong> as rejected?</p>
                    <p class="text-muted small">This will close the quotation as lost.</p>

                    <div class="mb-3">
                        <label for="reject_reason" class="form-label">Reason for Rejection <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="reject_reason" name="notes" rows="3" 
                            placeholder="Why is this quotation being rejected?" required></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-times-circle me-2"></i>Reject Quotation
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Embedded JavaScript for auto-hide alerts -->
<script>
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(alert) {
            if (alert) {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                if (bsAlert) bsAlert.close();
            }
        });
    }, 5000);
</script>

<style>
    @media print {
        .btn-group, .btn, .modal, .sidebar, .card-header .btn, footer, nav, .sidebar-card,
        #sidebar, .sidebar, .top-navbar, .btn-outline-secondary, .quick-actions {
            display: none !important;
        }
        body {
            background: white;
            padding: 0;
            margin: 0;
        }
        .container-fluid {
            width: 100%;
            padding: 0;
            margin: 0;
        }
        .card {
            border: none !important;
            box-shadow: none !important;
        }
        .card-header {
            background: none !important;
            border-bottom: 1px solid #ddd !important;
        }
        .table {
            border-collapse: collapse;
            width: 100%;
        }
        .table th, .table td {
            border: 1px solid #ddd;
            padding: 8px;
        }
        .badge {
            border: 1px solid #000;
            background: none !important;
            color: #000 !important;
        }
    }
</style>