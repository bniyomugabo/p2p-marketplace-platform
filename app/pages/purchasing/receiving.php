<?php
// pages/purchasing/receiving.php
declare(strict_types=1);

$pageTitle = 'Receive Items - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/PurchaseOrder.php';
require_once __DIR__ . '/../../models/Warehouse.php';
require_once __DIR__ . '/../../models/Location.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR', 'WHS'])) {
    SessionManager::flash('error', 'You do not have permission to receive items.');
    header('Location: ' . route_url('purchasing/orders'));
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
$warehouseModel = new Warehouse($companyId);
$locationModel = new Location($companyId);

// Get PO ID from URL
$poId = isset($_GET['po_id']) ? (int)$_GET['po_id'] : 0;

if (!$poId) {
    // Show list of pending orders (company-specific)
    $pendingOrders = $purchaseOrderModel->getPendingOrders();
    $showList = true;
} else {
    // Get order with company verification
    $order = $purchaseOrderModel->getWithItems($poId);
    
    if (!$order) {
        SessionManager::flash('error', 'Purchase order not found or does not belong to your company.');
        header('Location: ' . route_url('purchasing/receiving'));
        exit;
    }
    
    // Only allow receiving for approved or partial orders
    if (!in_array($order['status'], ['approved', 'partial'])) {
        SessionManager::flash('error', 'This order is not ready for receiving.');
        header('Location: ' . route_url('purchasing/view-order', ['id' => $poId]));
        exit;
    }
    
    $showList = false;
}

// Get warehouses for dropdown (company-specific)
$warehouses = $warehouseModel->all(['id', 'warehouse_name'], 'is_active = 1');

// Get locations (company-specific)
$locations = $locationModel->getAllWithWarehouse();

// Check if warehouses exist
$hasWarehouses = !empty($warehouses);

// Generate CSRF token
$csrfToken = CSRF::generate();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['receive_items'])) {
    
    if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
        SessionManager::flash('error', 'Invalid security token.');
        header('Location: ?page=purchasing/receiving&po_id=' . $poId);
        exit;
    }

    try {
        $receivedItems = [];
        
        foreach ($_POST['items'] as $itemId => $data) {
            if (isset($data['receive']) && $data['receive'] == 1 && !empty($data['received_qty']) && $data['received_qty'] > 0) {
                // Validate warehouse selection
                if (empty($data['warehouse_id'])) {
                    throw new Exception('Please select a warehouse for all received items.');
                }
                
                // Verify warehouse belongs to company
                $warehouse = $warehouseModel->find((int)$data['warehouse_id']);
                if (!$warehouse) {
                    throw new Exception('Selected warehouse does not exist or does not belong to your company.');
                }
                
                $receivedItems[] = [
                    'item_id' => (int)$itemId,
                    'received_quantity' => (float) $data['received_qty'],
                    'warehouse_id' => (int) $data['warehouse_id'],
                    'location_id' => !empty($data['location_id']) ? (int) $data['location_id'] : null
                ];
            }
        }
        
        if (empty($receivedItems)) {
            throw new Exception('No items selected for receiving.');
        }
        
        // Process receiving
        $purchaseOrderModel->receiveItems($poId, $receivedItems);
        
        SessionManager::flash('success', 'Items received successfully! Inventory has been updated.');
        header('Location: ' . route_url('purchasing/view-order', ['id' => $poId]));
        exit;
        
    } catch (Exception $e) {
        error_log("Receive error for company {$companyId}, order {$poId}: " . $e->getMessage());
        SessionManager::flash('error', 'Failed to receive items: ' . $e->getMessage());
    }
}
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <a href="<?php echo $showList ? route_url('purchasing/orders') : route_url('purchasing/view-order', ['id' => $poId]); ?>" 
                       class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> 
                        <?php echo $showList ? 'Back to Orders' : 'Back to Order'; ?>
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-truck-loading me-2"></i>
                        <?php echo $showList ? 'Pending Receipts' : 'Receive Items'; ?>
                    </h2>
                    <p class="mb-0 text-muted">
                        <?php echo $showList ? 'Select a purchase order to receive items' : 'Enter received quantities and select warehouse locations'; ?>
                    </p>
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

    <?php if (!$hasWarehouses && !$showList): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            No warehouses found. Please <a href="<?php echo route_url('warehouses/create'); ?>" class="alert-link">add a warehouse</a> before receiving items.
        </div>
    <?php endif; ?>

    <?php if ($showList): ?>
        <!-- Pending Orders List -->
        <div class="card shadow">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-clock me-2"></i>Orders Awaiting Receipt
                </h6>
            </div>
            <div class="card-body">
                <?php if (empty($pendingOrders)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                        <h5 class="text-muted">No Pending Receipts</h5>
                        <p class="mb-0">All purchase orders are complete!</p>
                        <a href="<?php echo route_url('purchasing/orders'); ?>" class="btn btn-primary mt-3">
                            <i class="fas fa-shopping-cart me-2"></i>View All Orders
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-bordered" id="pendingTable">
                            <thead class="table-light">
                                <tr>
                                    <th>PO #</th>
                                    <th>Supplier</th>
                                    <th>Order Date</th>
                                    <th>Expected Date</th>
                                    <th class="text-end">Ordered</th>
                                    <th class="text-end">Received</th>
                                    <th>Progress</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingOrders as $po): 
                                    $totalOrdered = (float)($po['total_ordered'] ?? 0);
                                    $totalReceived = (float)($po['total_received'] ?? 0);
                                    $progress = $totalOrdered > 0 
                                        ? min(100, round(($totalReceived / $totalOrdered) * 100)) 
                                        : 0;
                                    $isOverdue = $po['expected_date'] && strtotime($po['expected_date']) < time();
                                ?>
                                    <tr class="<?php echo $isOverdue ? 'table-warning' : ''; ?>">
                                        <td>
                                            <strong><?php echo htmlspecialchars($po['po_number']); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($po['supplier_name']); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($po['order_date'])); ?></td>
                                        <td>
                                            <?php echo $po['expected_date'] ? date('d/m/Y', strtotime($po['expected_date'])) : '-'; ?>
                                            <?php if ($isOverdue): ?>
                                                <span class="badge bg-danger ms-1" title="Overdue">!</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end"><?php echo number_format((float)$totalOrdered, 0); ?></td>
                                        <td class="text-end text-success"><?php echo number_format((float)$totalReceived, 0); ?></td>
                                        <td style="min-width: 120px;">
                                            <div class="progress" style="height: 8px;">
                                                <div class="progress-bar bg-<?php echo $progress >= 100 ? 'success' : 'info'; ?>" 
                                                     style="width: <?php echo $progress; ?>%"></div>
                                            </div>
                                            <small><?php echo $progress; ?>% complete</small>
                                        </td>
                                        <td>
                                            <a href="?page=purchasing/receiving&po_id=<?php echo $po['id']; ?>" 
                                               class="btn btn-sm btn-primary">
                                                <i class="fas fa-truck-loading me-1"></i>Receive
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <!-- Receive Items Form -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-receipt me-2"></i>
                    PO: <?php echo htmlspecialchars($order['po_number']); ?> | 
                    Supplier: <?php echo htmlspecialchars($order['supplier_name']); ?>
                </h6>
            </div>
            <div class="card-body">
                <?php if (empty($warehouses)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        No warehouses configured. Please contact your administrator to add a warehouse before receiving items.
                    </div>
                <?php endif; ?>
                
                <form method="POST" id="receive-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="receive_items" value="1">
                    
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th style="width: 40px;">Receive</th>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th class="text-end">Ordered</th>
                                    <th class="text-end">Received</th>
                                    <th class="text-end">Pending</th>
                                    <th class="text-end">Receive Qty</th>
                                    <th>Warehouse <span class="text-danger">*</span></th>
                                    <th>Location (Optional)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($order['items'] as $item): 
                                    $pending = (float)$item['quantity'] - (float)$item['received_quantity'];
                                ?>
                                    <tr>
                                        <td class="text-center">
                                            <input type="checkbox" class="form-check-input receive-check" 
                                                   name="items[<?php echo $item['id']; ?>][receive]" value="1"
                                                   <?php echo $pending > 0 ? '' : 'disabled'; ?>
                                                   data-item-id="<?php echo $item['id']; ?>">
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                            <?php if (!empty($item['variant_name']) && $item['variant_name'] !== 'Standard'): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($item['variant_name']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><code><?php echo htmlspecialchars($item['sku']); ?></code></td>
                                        <td class="text-end"><?php echo number_format((float)$item['quantity'], 2); ?></td>
                                        <td class="text-end text-success"><?php echo number_format((float)$item['received_quantity'], 2); ?></td>
                                        <td class="text-end text-warning pending-qty" data-pending="<?php echo $pending; ?>">
                                            <?php echo number_format((float)$pending, 2); ?>
                                        </td>
                                        <td style="min-width: 120px;">
                                            <input type="number" class="form-control form-control-sm receive-qty" 
                                                   name="items[<?php echo $item['id']; ?>][received_qty]" 
                                                   min="0" max="<?php echo $pending; ?>" step="0.01" value="0" 
                                                   data-pending="<?php echo $pending; ?>" disabled>
                                        </td>
                                        <td style="min-width: 150px;">
                                            <select class="form-control form-control-sm warehouse-select" 
                                                    name="items[<?php echo $item['id']; ?>][warehouse_id]" disabled>
                                                <option value="">Select Warehouse</option>
                                                <?php foreach ($warehouses as $wh): ?>
                                                    <option value="<?php echo $wh['id']; ?>">
                                                        <?php echo htmlspecialchars($wh['warehouse_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td style="min-width: 150px;">
                                            <select class="form-control form-control-sm location-select" 
                                                    name="items[<?php echo $item['id']; ?>][location_id]" disabled>
                                                <option value="">No Location</option>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Note:</strong> Received items will be added to inventory automatically. 
                                The purchase order status will update to "Received" when all items are received, 
                                or "Partial" if only some items are received.
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12">
                            <button type="submit" class="btn btn-success" id="submit-receive" disabled>
                                <i class="fas fa-check-circle me-2"></i>Receive Selected Items
                            </button>
                            <a href="?page=purchasing/view-order&id=<?php echo $poId; ?>" class="btn btn-secondary ms-2">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Summary Card -->
        <div class="card shadow">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-chart-bar me-2"></i>Order Summary
                </h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 text-center">
                        <div class="text-muted small">Total Items</div>
                        <div class="h5"><?php echo count($order['items']); ?></div>
                    </div>
                    <div class="col-md-3 text-center">
                        <div class="text-muted small">Total Ordered</div>
                        <div class="h5"><?php echo number_format((float)array_sum(array_column($order['items'], 'quantity')), 0); ?></div>
                    </div>
                    <div class="col-md-3 text-center">
                        <div class="text-muted small">Total Received</div>
                        <div class="h5 text-success"><?php echo number_format((float)array_sum(array_column($order['items'], 'received_quantity')), 0); ?></div>
                    </div>
                    <div class="col-md-3 text-center">
                        <div class="text-muted small">Total Pending</div>
                        <div class="h5 text-warning">
                            <?php 
                            $totalPending = array_sum(array_map(function($item) {
                                return (float)$item['quantity'] - (float)$item['received_quantity'];
                            }, $order['items']));
                            echo number_format((float)$totalPending, 0);
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    <?php if (!$showList): ?>
    const receiveChecks = document.querySelectorAll('.receive-check');
    const submitBtn = document.getElementById('submit-receive');
    const locations = <?php echo json_encode($locations); ?>;
    
    function updateRowState(checkbox) {
        const row = checkbox.closest('tr');
        const receiveQty = row.querySelector('.receive-qty');
        const warehouseSelect = row.querySelector('.warehouse-select');
        const locationSelect = row.querySelector('.location-select');
        const pendingQty = parseFloat(row.querySelector('.pending-qty').getAttribute('data-pending') || 0);
        
        if (checkbox.checked) {
            receiveQty.disabled = false;
            receiveQty.max = pendingQty;
            receiveQty.value = pendingQty; // Default to full pending
            warehouseSelect.disabled = false;
            locationSelect.disabled = false;
            
            // Trigger warehouse change to load locations
            if (warehouseSelect.value) {
                updateLocations(warehouseSelect, locationSelect);
            }
        } else {
            receiveQty.disabled = true;
            receiveQty.value = 0;
            warehouseSelect.disabled = true;
            locationSelect.disabled = true;
            locationSelect.innerHTML = '<option value="">No Location</option>';
        }
        
        // Update submit button state
        const anyChecked = Array.from(receiveChecks).some(cb => cb.checked);
        submitBtn.disabled = !anyChecked;
    }
    
    function updateLocations(warehouseSelect, locationSelect) {
        const warehouseId = warehouseSelect.value;
        
        locationSelect.innerHTML = '<option value="">No Location</option>';
        
        if (warehouseId && locations && locations.length) {
            const filtered = locations.filter(l => l.warehouse_id == warehouseId);
            filtered.forEach(loc => {
                const option = document.createElement('option');
                option.value = loc.id;
                option.textContent = loc.location_name || loc.location_code;
                locationSelect.appendChild(option);
            });
        }
    }
    
    receiveChecks.forEach(cb => {
        cb.addEventListener('change', function() { updateRowState(this); });
    });
    
    // Warehouse location filtering
    document.querySelectorAll('.warehouse-select').forEach(select => {
        select.addEventListener('change', function() {
            const row = this.closest('tr');
            const locationSelect = row.querySelector('.location-select');
            updateLocations(this, locationSelect);
        });
    });
    
    // Validate before submit
    document.getElementById('receive-form').addEventListener('submit', function(e) {
        const checkedItems = Array.from(receiveChecks).filter(cb => cb.checked);
        
        for (let cb of checkedItems) {
            const row = cb.closest('tr');
            const receiveQty = row.querySelector('.receive-qty');
            const warehouseSelect = row.querySelector('.warehouse-select');
            
            if (parseFloat(receiveQty.value) <= 0) {
                e.preventDefault();
                alert('Please enter a valid quantity for all selected items.');
                receiveQty.focus();
                return false;
            }
            
            if (!warehouseSelect.value) {
                e.preventDefault();
                alert('Please select a warehouse for all received items.');
                warehouseSelect.focus();
                return false;
            }
        }
        return true;
    });
    <?php endif; ?>
});
</script>

<style>
    .receive-qty:not(:disabled) { background-color: #fff3cd; }
    .warehouse-select:not(:disabled), .location-select:not(:disabled) { background-color: #fff3cd; }
    .progress { background-color: #eaecf4; }
    .table td { vertical-align: middle; }
    .btn-group-sm .btn { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
    
    @media (max-width: 768px) {
        .table-responsive {
            font-size: 0.85rem;
        }
        .btn-sm {
            padding: 0.2rem 0.4rem;
        }
    }
</style>

<?php $jsFiles = ['purchasing/receiving.js']; ?>