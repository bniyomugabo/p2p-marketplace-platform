<?php
// pages/purchasing/view-order.php
declare(strict_types=1);

$pageTitle = 'View Purchase Order - Inventory Management System';
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
    header('Location: ' . route_url('purchasing/orders'));
    exit;
}

// Check if company context is set
if (!$companyId) {
    SessionManager::flash('error', 'Company context not found. Please log in again.');
    header('Location: ' . route_url('login'));
    exit;
}

// Get order ID from URL
$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$orderId) {
    SessionManager::flash('error', 'Order ID is required.');
    header('Location: ' . route_url('purchasing/orders'));
    exit;
}

// Initialize models with company ID
$purchaseOrderModel = new PurchaseOrder($companyId);
$supplierModel = new Supplier($companyId);

// Get order with items (company check is done inside the model)
$order = $purchaseOrderModel->getWithItems($orderId);

if (!$order) {
    SessionManager::flash('error', 'Purchase order not found or does not belong to your company.');
    header('Location: ' . route_url('purchasing/orders'));
    exit;
}

// Calculate progress
$totalOrdered = 0;
$totalReceived = 0;
foreach ($order['items'] as $item) {
    $totalOrdered += (float)$item['quantity'];
    $totalReceived += (float)$item['received_quantity'];
}
$progress = $totalOrdered > 0 ? min(100, round(($totalReceived / $totalOrdered) * 100)) : 0;

// Determine if order is fully received
$fullyReceived = $totalReceived >= $totalOrdered;

// Generate CSRF token for actions
$csrfToken = CSRF::generate();

// Get status badge class helper
function getStatusBadgeClass($status) {
    $classes = [
        'draft' => 'secondary',
        'pending' => 'info',
        'approved' => 'primary',
        'received' => 'success',
        'partial' => 'warning',
        'cancelled' => 'danger'
    ];
    return $classes[$status] ?? 'secondary';
}
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <a href="<?php echo route_url('purchasing/orders'); ?>" class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Orders
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-file-invoice me-2"></i>Purchase Order Details
                    </h2>
                    <p class="mb-0 text-muted">Viewing PO #<?php echo htmlspecialchars($order['po_number']); ?></p>
                </div>
                <div class="btn-group flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                        <i class="fas fa-print me-2"></i>Print
                    </button>
                    
                    <?php if ($order['status'] === 'draft'): ?>
                        <a href="?page=purchasing/edit-order&id=<?php echo $orderId; ?>" class="btn btn-warning">
                            <i class="fas fa-edit me-2"></i>Edit
                        </a>
                        <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#submitModal">
                            <i class="fas fa-paper-plane me-2"></i>Submit for Approval
                        </button>
                    <?php endif; ?>
                    
                    <?php if ($order['status'] === 'pending' && in_array($userRole, ['ADM', 'MGR'])): ?>
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#approveModal">
                            <i class="fas fa-check-circle me-2"></i>Approve
                        </button>
                        <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal">
                            <i class="fas fa-times-circle me-2"></i>Reject
                        </button>
                    <?php endif; ?>
                    
                    <?php if (in_array($order['status'], ['approved', 'partial']) && !$fullyReceived && in_array($userRole, ['ADM', 'MGR', 'WHS'])): ?>
                        <a href="?page=purchasing/receiving&po_id=<?php echo $orderId; ?>" class="btn btn-success">
                            <i class="fas fa-truck-loading me-2"></i>Receive Items
                        </a>
                    <?php endif; ?>
                    
                    <?php if (in_array($userRole, ['ADM', 'MGR']) && $order['status'] === 'draft'): ?>
                        <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteModal">
                            <i class="fas fa-trash me-2"></i>Delete
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
            <div class="card shadow">
                <div class="card-body py-3">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                        <div class="d-flex align-items-center gap-3">
                            <span class="text-muted">Status:</span>
                            <span class="badge bg-<?php echo getStatusBadgeClass($order['status']); ?> fs-6 p-2">
                                <?php echo strtoupper($order['status']); ?>
                            </span>
                            
                            <?php if ($order['expected_date'] && strtotime($order['expected_date']) < time() && in_array($order['status'], ['approved', 'partial'])): ?>
                                <span class="badge bg-danger fs-6 p-2">
                                    <i class="fas fa-exclamation-triangle me-1"></i>OVERDUE
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="d-flex align-items-center gap-3">
                            <span class="text-muted">Progress:</span>
                            <div style="width: 200px;">
                                <div class="progress" style="height: 10px;">
                                    <div class="progress-bar bg-<?php echo $fullyReceived ? 'success' : 'info'; ?>" 
                                         style="width: <?php echo $progress; ?>%"></div>
                                </div>
                            </div>
                            <span class="fw-bold"><?php echo $progress; ?>%</span>
                            <span class="text-muted">(<?php echo number_format((float)$totalReceived, 0); ?>/<?php echo number_format((float)$totalOrdered, 0); ?> units)</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Main Content -->
        <div class="col-lg-8">
            <!-- Order Information -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-info-circle me-2"></i>Order Information
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <th style="width: 40%">PO Number:</th>
                                    <td><strong class="text-primary"><?php echo htmlspecialchars($order['po_number']); ?></strong></td>
                                </tr>
                                <tr>
                                    <th>Order Date:</th>
                                    <td><?php echo date('d/m/Y', strtotime($order['order_date'])); ?></td>
                                </tr>
                                <tr>
                                    <th>Expected Date:</th>
                                    <td>
                                        <?php echo $order['expected_date'] ? date('d/m/Y', strtotime($order['expected_date'])) : 'Not set'; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Created By:</th>
                                    <td><?php echo htmlspecialchars($order['created_by_name'] ?? 'System'); ?></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <th style="width: 40%">Created At:</th>
                                    <td><?php echo date('d/m/Y H:i', strtotime($order['created_at'])); ?></td>
                                </tr>
                                <?php if ($order['updated_at']): ?>
                                <tr>
                                    <th>Last Updated:</th>
                                    <td><?php echo date('d/m/Y H:i', strtotime($order['updated_at'])); ?></td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <th>Items:</th>
                                    <td><?php echo count($order['items']); ?> items</td>
                                </tr>
                                <tr>
                                    <th>Total Value:</th>
                                    <td class="fw-bold text-primary"><?php echo format_currency($order['total_amount']); ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <?php if (!empty($order['notes'])): ?>
                    <div class="mt-3 p-3 bg-light rounded">
                        <strong>Notes:</strong>
                        <p class="mb-0 mt-2"><?php echo nl2br(htmlspecialchars($order['notes'])); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Items Table -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-boxes me-2"></i>Items
                    </h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th class="text-end">Ordered</th>
                                    <th class="text-end">Received</th>
                                    <th class="text-end">Pending</th>
                                    <th class="text-end">Unit Price</th>
                                    <th class="text-end">Tax %</th>
                                    <th class="text-end">Line Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $subtotal = 0;
                                $totalTax = 0;
                                foreach ($order['items'] as $item): 
                                    $pending = (float)$item['quantity'] - (float)$item['received_quantity'];
                                    $lineSubtotal = (float)$item['quantity'] * (float)$item['unit_price'];
                                    $lineTax = $lineSubtotal * ((float)$item['tax_rate'] / 100);
                                    $lineTotal = $lineSubtotal + $lineTax;
                                    $subtotal += $lineSubtotal;
                                    $totalTax += $lineTax;
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                            <?php if (!empty($item['variant_name']) && $item['variant_name'] !== 'Standard'): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($item['variant_name']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><code><?php echo htmlspecialchars($item['sku']); ?></code></td>
                                        <td class="text-end"><?php echo number_format((float)$item['quantity'], 2); ?></td>
                                        <td class="text-end text-success"><?php echo number_format((float)$item['received_quantity'], 2); ?></td>
                                        <td class="text-end <?php echo $pending > 0 ? 'text-warning fw-bold' : 'text-muted'; ?>">
                                            <?php echo number_format((float)$pending, 2); ?>
                                        </td>
                                        <td class="text-end"><?php echo format_currency($item['unit_price']); ?></td>
                                        <td class="text-end"><?php echo (float)$item['tax_rate']; ?>%</td>
                                        <td class="text-end"><?php echo format_currency($lineTotal); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th colspan="7" class="text-end">Subtotal:</th>
                                    <th class="text-end"><?php echo format_currency($subtotal); ?></th>
                                </tr>
                                <tr>
                                    <th colspan="7" class="text-end">Tax:</th>
                                    <th class="text-end"><?php echo format_currency($totalTax); ?></th>
                                </tr>
                                <tr>
                                    <th colspan="7" class="text-end">Total:</th>
                                    <th class="text-end text-primary fs-6"><?php echo format_currency($subtotal + $totalTax); ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Additional Costs (if any) -->
            <?php if (!empty($order['costs'])): ?>
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-receipt me-2"></i>Additional Costs
                    </h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Cost Type</th>
                                    <th>Description</th>
                                    <th class="text-end">Amount</th>
                                    <th>Allocation Method</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($order['costs'] as $cost): ?>
                                <tr>
                                    <td><?php echo ucfirst(str_replace('_', ' ', $cost['cost_type'])); ?></td>
                                    <td><?php echo htmlspecialchars($cost['description']); ?></td>
                                    <td class="text-end"><?php echo format_currency($cost['amount']); ?></td>
                                    <td><?php echo ucfirst($cost['allocation_method']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Supplier Information -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-truck me-2"></i>Supplier Information
                    </h6>
                </div>
                <div class="card-body">
                    <h5 class="fw-bold"><?php echo htmlspecialchars($order['supplier_name']); ?></h5>
                    <p class="mb-2"><small class="text-muted">Code:</small> <?php echo htmlspecialchars($order['supplier_code']); ?></p>
                    
                    <?php if (!empty($order['contact_person'])): ?>
                        <p class="mb-2">
                            <i class="fas fa-user me-2 text-muted"></i>
                            <?php echo htmlspecialchars($order['contact_person']); ?>
                        </p>
                    <?php endif; ?>
                    
                    <?php if (!empty($order['supplier_phone'])): ?>
                        <p class="mb-2">
                            <i class="fas fa-phone me-2 text-muted"></i>
                            <a href="tel:<?php echo htmlspecialchars($order['supplier_phone']); ?>">
                                <?php echo htmlspecialchars($order['supplier_phone']); ?>
                            </a>
                        </p>
                    <?php endif; ?>
                    
                    <?php if (!empty($order['supplier_email'])): ?>
                        <p class="mb-2">
                            <i class="fas fa-envelope me-2 text-muted"></i>
                            <a href="mailto:<?php echo htmlspecialchars($order['supplier_email']); ?>">
                                <?php echo htmlspecialchars($order['supplier_email']); ?>
                            </a>
                        </p>
                    <?php endif; ?>
                    
                    <?php if (!empty($order['supplier_address'])): ?>
                        <p class="mb-0">
                            <i class="fas fa-map-marker-alt me-2 text-muted"></i>
                            <?php echo nl2br(htmlspecialchars($order['supplier_address'])); ?>
                        </p>
                    <?php endif; ?>
                    
                    <hr>
                    
                    <div class="d-grid">
                        <a href="?page=purchasing/orders&supplier_id=<?php echo $order['supplier_id']; ?>" 
                           class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-history me-2"></i>View All Orders from this Supplier
                        </a>
                    </div>
                </div>
            </div>

            <!-- Order Summary -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-calculator me-2"></i>Order Summary
                    </h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <td>Total Items:</td>
                            <td class="text-end"><?php echo count($order['items']); ?></td>
                        </tr>
                        <tr>
                            <td>Total Quantity:</td>
                            <td class="text-end"><?php echo number_format((float)$totalOrdered, 0); ?></td>
                        </tr>
                        <tr>
                            <td>Received:</td>
                            <td class="text-end text-success"><?php echo number_format((float)$totalReceived, 0); ?></td>
                        </tr>
                        <tr>
                            <td>Pending:</td>
                            <td class="text-end text-warning"><?php echo number_format((float)$totalOrdered - $totalReceived, 0); ?></td>
                        </tr>
                        <tr>
                            <td>Completion:</td>
                            <td class="text-end">
                                <div class="progress" style="height: 5px;">
                                    <div class="progress-bar bg-<?php echo $fullyReceived ? 'success' : 'info'; ?>" 
                                         style="width: <?php echo $progress; ?>%"></div>
                                </div>
                                <small class="text-muted"><?php echo $progress; ?>%</small>
                            </td>
                        </tr>
                    </table>
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
                        <button type="button" class="btn btn-outline-primary" onclick="window.print()">
                            <i class="fas fa-print me-2"></i>Print Order
                        </button>
                        
                        <?php if (in_array($order['status'], ['approved', 'partial']) && !$fullyReceived && in_array($userRole, ['ADM', 'MGR', 'WHS'])): ?>
                            <a href="?page=purchasing/receiving&po_id=<?php echo $orderId; ?>" 
                               class="btn btn-outline-success">
                                <i class="fas fa-truck-loading me-2"></i>Receive Items
                            </a>
                        <?php endif; ?>
                        
                        <?php if ($order['status'] === 'draft'): ?>
                            <button type="button" class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#submitModal">
                                <i class="fas fa-paper-plane me-2"></i>Submit for Approval
                            </button>
                        <?php endif; ?>
                        
                        <?php if ($order['status'] === 'approved'): ?>
                            <a href="?page=purchasing/create-order&copy=<?php echo $orderId; ?>" 
                               class="btn btn-outline-secondary">
                                <i class="fas fa-copy me-2"></i>Create Copy
                            </a>
                        <?php endif; ?>
                    </div>
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
                                    <p class="mb-0"><strong>Order Created</strong></p>
                                    <small class="text-muted">
                                        <?php echo date('d/m/Y H:i', strtotime($order['created_at'])); ?>
                                        <?php if (!empty($order['created_by_name'])): ?>
                                            by <?php echo htmlspecialchars($order['created_by_name']); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </div>
                        </li>
                        
                        <?php if ($order['status'] === 'pending' || $order['status'] === 'approved' || $order['status'] === 'received'): ?>
                        <li class="mb-3">
                            <div class="d-flex">
                                <div class="me-3">
                                    <i class="fas fa-paper-plane text-info"></i>
                                </div>
                                <div>
                                    <p class="mb-0"><strong>Submitted for Approval</strong></p>
                                    <small class="text-muted">Pending review</small>
                                </div>
                            </div>
                        </li>
                        <?php endif; ?>
                        
                        <?php if ($order['status'] === 'approved' || $order['status'] === 'received'): ?>
                        <li class="mb-3">
                            <div class="d-flex">
                                <div class="me-3">
                                    <i class="fas fa-check-circle text-primary"></i>
                                </div>
                                <div>
                                    <p class="mb-0"><strong>Order Approved</strong></p>
                                    <small class="text-muted">Ready for receiving</small>
                                </div>
                            </div>
                        </li>
                        <?php endif; ?>
                        
                        <?php if ($order['status'] === 'received'): ?>
                        <li class="mb-3">
                            <div class="d-flex">
                                <div class="me-3">
                                    <i class="fas fa-check-double text-success"></i>
                                </div>
                                <div>
                                    <p class="mb-0"><strong>Order Completed</strong></p>
                                    <small class="text-muted">All items received</small>
                                </div>
                            </div>
                        </li>
                        <?php endif; ?>
                        
                        <?php if ($order['status'] === 'cancelled'): ?>
                        <li class="mb-3">
                            <div class="d-flex">
                                <div class="me-3">
                                    <i class="fas fa-times-circle text-danger"></i>
                                </div>
                                <div>
                                    <p class="mb-0"><strong>Order Cancelled</strong></p>
                                    <small class="text-muted"><?php echo htmlspecialchars($order['notes'] ?? 'No reason provided'); ?></small>
                                </div>
                            </div>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Submit Modal -->
<div class="modal fade" id="submitModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="fas fa-paper-plane me-2"></i>Submit for Approval
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="?page=purchasing/update-order-status">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="id" value="<?php echo $orderId; ?>">
                <input type="hidden" name="status" value="pending">
                
                <div class="modal-body">
                    <p>Submit PO <strong><?php echo htmlspecialchars($order['po_number']); ?></strong> for approval?</p>
                    <div class="mb-3">
                        <label for="submit_notes" class="form-label">Notes (Optional)</label>
                        <textarea class="form-control" id="submit_notes" name="notes" rows="2" 
                                  placeholder="Any additional information for the approver..."></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info">Submit</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Approve Modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="fas fa-check-circle me-2"></i>Approve Order
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="?page=purchasing/update-order-status">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="id" value="<?php echo $orderId; ?>">
                <input type="hidden" name="status" value="approved">
                
                <div class="modal-body">
                    <p>Approve PO <strong><?php echo htmlspecialchars($order['po_number']); ?></strong>?</p>
                    <div class="mb-3">
                        <label for="approve_notes" class="form-label">Notes (Optional)</label>
                        <textarea class="form-control" id="approve_notes" name="notes" rows="2"></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Approve</button>
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
                    <i class="fas fa-times-circle me-2"></i>Reject Order
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="?page=purchasing/update-order-status">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="id" value="<?php echo $orderId; ?>">
                <input type="hidden" name="status" value="cancelled">
                
                <div class="modal-body">
                    <p>Reject PO <strong><?php echo htmlspecialchars($order['po_number']); ?></strong>?</p>
                    <div class="mb-3">
                        <label for="reject_reason" class="form-label">Reason for Rejection <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="reject_reason" name="notes" rows="3" required></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="fas fa-trash me-2"></i>Delete Order
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="?page=purchasing/delete-order">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="id" value="<?php echo $orderId; ?>">
                
                <div class="modal-body">
                    <p>Are you sure you want to delete PO <strong><?php echo htmlspecialchars($order['po_number']); ?></strong>?</p>
                    <p class="text-danger"><small>This action cannot be undone.</small></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-2"></i>Delete Order
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Print Styles -->
<style media="print">
    @page { size: A4; margin: 1cm; }
    body { background: white; font-size: 12pt; }
    .btn-group, .btn, .modal, .sidebar, .card-header .btn, footer, nav, .sidebar-card {
        display: none !important;
    }
    .container-fluid { width: 100%; padding: 0; margin: 0; }
    .card { border: none !important; box-shadow: none !important; }
    .card-header { background: none !important; border-bottom: 1px solid #ddd !important; }
    .table { border-collapse: collapse; width: 100%; }
    .table th, .table td { border: 1px solid #ddd; padding: 8px; }
    .badge { border: 1px solid #000; background: none !important; color: #000 !important; }
    .text-primary, .text-success, .text-danger, .text-warning { color: #000 !important; }
    .progress { display: none; }
</style>

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