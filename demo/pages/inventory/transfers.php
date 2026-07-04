<?php
// pages/inventory/transfers.php
declare(strict_types=1);

$pageTitle = 'Stock Transfers - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';

// Load models
require_once __DIR__ . '/../../models/Inventory.php';
require_once __DIR__ . '/../../models/Warehouse.php';
require_once __DIR__ . '/../../models/Location.php';
require_once __DIR__ . '/../../models/Variant.php';
require_once __DIR__ . '/../../models/Product.php';

$db = Database::getInstance();
$userId = $_SESSION['user_id'] ?? 0;
$userRole = $_SESSION['user_role'] ?? 'VIW';
$companyId = $_SESSION['company_id'] ?? null;

// Check permission
if (!in_array($userRole, ['ADM', 'MGR', 'WHS'])) {
    SessionManager::flash('error', 'You do not have permission to manage stock transfers.');
    header('Location: ?page=inventory/dashboard');
    exit;
}

// Initialize models with company context
$inventoryModel = new Inventory($companyId);
$warehouseModel = new Warehouse($companyId);
$locationModel = new Location($companyId);
$variantModel = new Variant($companyId);
$productModel = new Product($companyId);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_transfer'])) {

    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
        SessionManager::flash('error', 'Invalid security token. Please try again.');
        header('Location: ?page=inventory/transfers');
        exit;
    }

    try {
        $db->beginTransaction();

        $transferNumber = 'TRF-' . date('Ymd') . '-' . rand(1000, 9999);
        $fromWarehouseId = (int) $_POST['from_warehouse'];
        $toWarehouseId = (int) $_POST['to_warehouse'];
        $fromLocationId = !empty($_POST['from_location']) ? (int) $_POST['from_location'] : null;
        $toLocationId = !empty($_POST['to_location']) ? (int) $_POST['to_location'] : null;
        $notes = trim($_POST['notes'] ?? '');

        // Validate warehouses belong to this company
        $fromWarehouse = $warehouseModel->find($fromWarehouseId);
        $toWarehouse = $warehouseModel->find($toWarehouseId);

        if (!$fromWarehouse || !$toWarehouse) {
            throw new Exception("Invalid warehouse selection. Both warehouses must belong to your company.");
        }

        if ($fromWarehouseId == $toWarehouseId && $fromLocationId == $toLocationId) {
            throw new Exception("Source and destination cannot be the same");
        }

        $transferredItems = 0;

        // Process transfer items
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                if (empty($item['variant_id']) || empty($item['quantity']) || $item['quantity'] <= 0) {
                    continue;
                }

                $variantId = (int) $item['variant_id'];
                $quantity = (float) $item['quantity'];

                // Verify variant belongs to this company
                $variant = $variantModel->find($variantId);
                if (!$variant) {
                    throw new Exception("Invalid variant selection: ID {$variantId}");
                }

                // Check available quantity
                $available = $inventoryModel->getAvailableQuantity($variantId, $fromWarehouseId, $fromLocationId);
                if ($available < $quantity) {
                    $productInfo = $variantModel->getWithDetails($variantId);
                    $productName = $productInfo['product_name'] . ' - ' . $productInfo['variant_name'];
                    throw new Exception("Insufficient stock for {$productName}. Available: {$available}, Requested: {$quantity}");
                }

                // Perform transfer
                $inventoryModel->transferStock(
                    $fromWarehouseId,
                    $toWarehouseId,
                    $variantId,
                    $quantity,
                    $fromLocationId,
                    $toLocationId,
                    $userId
                );

                // Insert into stock_transfers table
                $sql = "INSERT INTO stock_transfers 
                        (company_id, transfer_number, from_warehouse_id, to_warehouse_id, from_location_id, to_location_id,
                         variant_id, quantity, notes, created_by, created_at, status)
                        VALUES 
                        (:company_id, :transfer_number, :from_warehouse, :to_warehouse, :from_location, :to_location,
                         :variant_id, :quantity, :notes, :created_by, NOW(), 'completed')";

                $stmt = $db->prepare($sql);
                $stmt->execute([
                    'company_id' => $companyId,
                    'transfer_number' => $transferNumber,
                    'from_warehouse' => $fromWarehouseId,
                    'to_warehouse' => $toWarehouseId,
                    'from_location' => $fromLocationId,
                    'to_location' => $toLocationId,
                    'variant_id' => $variantId,
                    'quantity' => $quantity,
                    'notes' => $notes,
                    'created_by' => $userId
                ]);

                $transferredItems++;
            }
        }

        if ($transferredItems === 0) {
            throw new Exception("No valid items to transfer.");
        }

        $db->commit();
        SessionManager::flash('success', "Stock transfer completed successfully! {$transferredItems} item(s) transferred.");

    } catch (Exception $e) {
        $db->rollBack();
        SessionManager::flash('error', 'Transfer failed: ' . $e->getMessage());
    }

    header('Location: ?page=inventory/transfers');
    exit;
}

// Get warehouses for this company
$warehouses = $warehouseModel->all(['id', 'warehouse_name', 'warehouse_code'], 'is_active = 1');

// Get locations by warehouse (company-specific)
$locations = $locationModel->getAllWithWarehouse();

// Get recent transfers (company-specific)
$transfers = [];
try {
    $sql = "SELECT 
                t.*,
                w1.warehouse_name as from_warehouse_name,
                w2.warehouse_name as to_warehouse_name,
                l1.location_code as from_location_code,
                l2.location_code as to_location_code,
                v.sku,
                v.variant_name,
                p.product_name,
                u.full_name as created_by_name
            FROM stock_transfers t
            LEFT JOIN warehouses w1 ON t.from_warehouse_id = w1.id
            LEFT JOIN warehouses w2 ON t.to_warehouse_id = w2.id
            LEFT JOIN locations l1 ON t.from_location_id = l1.id
            LEFT JOIN locations l2 ON t.to_location_id = l2.id
            JOIN variants v ON t.variant_id = v.id
            JOIN products p ON v.product_id = p.id
            LEFT JOIN users u ON t.created_by = u.id
            WHERE t.company_id = :company_id
            ORDER BY t.created_at DESC
            LIMIT 50";

    $stmt = $db->prepare($sql);
    $stmt->execute(['company_id' => $companyId]);
    $transfers = $stmt->fetchAll();
} catch (Exception $e) {
    // Table might not exist yet - create it if needed
    error_log("Stock transfers table may not exist: " . $e->getMessage());
    $transfers = [];
}

// Get products with stock for selection (company-specific)
$products = $variantModel->getAllWithStock();

// Group locations by warehouse
$locationsByWarehouse = [];
foreach ($locations as $loc) {
    if (!isset($locationsByWarehouse[$loc['warehouse_id']])) {
        $locationsByWarehouse[$loc['warehouse_id']] = [];
    }
    $locationsByWarehouse[$loc['warehouse_id']][] = $loc;
}

// Generate CSRF token
$csrfToken = CSRF::generate();
?>

<div class="stock-transfers">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <a href="?page=inventory/dashboard" class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-exchange-alt me-2"></i>Stock Transfers
                    </h2>
                    <p class="mb-0 text-muted">Transfer stock between warehouses and locations</p>
                </div>
                <div>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal"
                        data-bs-target="#newTransferModal">
                        <i class="fas fa-plus-circle me-2"></i>New Transfer
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

    <!-- Transfers List -->
    <div class="card shadow">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-history me-2"></i>Recent Transfers
                <span class="badge bg-primary ms-2"><?php echo count($transfers); ?> records</span>
            </h6>
            <button class="btn btn-sm btn-outline-primary" onclick="refreshPage()">
                <i class="fas fa-sync-alt me-1"></i> Refresh
            </button>
        </div>
        <div class="card-body">
            <?php if (empty($transfers)): ?>
                <div class="text-center py-5">
                    <div class="text-muted">
                        <i class="fas fa-exchange-alt fa-4x mb-3"></i>
                        <p class="h5 mb-2">No transfers found</p>
                        <p class="mb-0">Click "New Transfer" to move stock between warehouses</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover" id="transfersTable">
                        <thead class="table-light">
                            <tr>
                                <th>Transfer #</th>
                                <th>Date</th>
                                <th>Product</th>
                                <th>From</th>
                                <th>To</th>
                                <th class="text-end">Quantity</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transfers as $transfer): ?>
                                <tr>
                                    <td>
                                        <strong><code><?php echo htmlspecialchars($transfer['transfer_number']); ?></code></strong>
                                    </td>
                                    <td>
                                        <span title="<?php echo htmlspecialchars($transfer['created_at']); ?>">
                                            <i class="far fa-calendar-alt me-1"></i>
                                            <?php echo format_date($transfer['created_at'], 'd/m/Y H:i'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($transfer['product_name']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($transfer['sku']); ?></small>
                                        <?php if ($transfer['variant_name'] && $transfer['variant_name'] !== 'Standard'): ?>
                                            <br><small
                                                class="text-muted"><?php echo htmlspecialchars($transfer['variant_name']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <i class="fas fa-arrow-right text-success me-1"></i>
                                        <?php echo htmlspecialchars($transfer['from_warehouse_name']); ?>
                                        <?php if ($transfer['from_location_code']): ?>
                                            <br><small
                                                class="text-muted"><?php echo htmlspecialchars($transfer['from_location_code']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <i class="fas fa-arrow-left text-primary me-1"></i>
                                        <?php echo htmlspecialchars($transfer['to_warehouse_name']); ?>
                                        <?php if ($transfer['to_location_code']): ?>
                                            <br><small
                                                class="text-muted"><?php echo htmlspecialchars($transfer['to_location_code']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end fw-bold">
                                        <?php echo number_format((float) $transfer['quantity'], 0); ?> units
                                    </td>
                                    <td>
                                        <span class="badge bg-success">
                                            <i class="fas fa-check-circle me-1"></i>Completed
                                        </span>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-info"
                                            onclick="viewTransferDetails(<?php echo htmlspecialchars(json_encode($transfer)); ?>)"
                                            title="View Details">
                                            <i class="fas fa-info-circle"></i> Details
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- New Transfer Modal -->
<div class="modal fade" id="newTransferModal" tabindex="-1" aria-labelledby="newTransferModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="newTransferModalLabel">
                    <i class="fas fa-exchange-alt me-2"></i>Create Stock Transfer
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="" id="transferForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="create_transfer" value="1">
                <input type="hidden" name="company_id" value="<?php echo $companyId; ?>">

                <div class="modal-body">
                    <!-- Transfer Locations -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card bg-light">
                                <div class="card-header bg-warning text-dark">
                                    <i class="fas fa-arrow-right me-2"></i>Source
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label for="from_warehouse" class="form-label">Source Warehouse *</label>
                                        <select class="form-control" id="from_warehouse" name="from_warehouse" required>
                                            <option value="">Select Warehouse</option>
                                            <?php foreach ($warehouses as $wh): ?>
                                                <option value="<?php echo $wh['id']; ?>">
                                                    <?php echo htmlspecialchars($wh['warehouse_name']); ?>
                                                    <?php if ($wh['warehouse_code']): ?>
                                                        (<?php echo htmlspecialchars($wh['warehouse_code']); ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label for="from_location" class="form-label">Source Location (Optional)</label>
                                        <select class="form-control" id="from_location" name="from_location">
                                            <option value="">Default Location</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="card bg-light">
                                <div class="card-header bg-info text-white">
                                    <i class="fas fa-arrow-left me-2"></i>Destination
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <label for="to_warehouse" class="form-label">Destination Warehouse *</label>
                                        <select class="form-control" id="to_warehouse" name="to_warehouse" required>
                                            <option value="">Select Warehouse</option>
                                            <?php foreach ($warehouses as $wh): ?>
                                                <option value="<?php echo $wh['id']; ?>">
                                                    <?php echo htmlspecialchars($wh['warehouse_name']); ?>
                                                    <?php if ($wh['warehouse_code']): ?>
                                                        (<?php echo htmlspecialchars($wh['warehouse_code']); ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label for="to_location" class="form-label">Destination Location
                                            (Optional)</label>
                                        <select class="form-control" id="to_location" name="to_location">
                                            <option value="">Default Location</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Transfer Items -->
                    <h6 class="fw-bold mb-3">
                        <i class="fas fa-list me-2"></i>Items to Transfer
                    </h6>
                    <div id="transfer-items">
                        <div class="transfer-item border rounded p-3 mb-3 bg-white">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Product / Variant *</label>
                                    <select class="form-control product-select" name="items[0][variant_id]" required>
                                        <option value="">Search and select product...</option>
                                        <?php foreach ($products as $product): ?>
                                            <?php if (($product['available_stock'] ?? $product['total_stock'] ?? 0) > 0): ?>
                                                <option value="<?php echo $product['variant_id']; ?>"
                                                    data-stock="<?php echo $product['available_stock'] ?? $product['total_stock'] ?? 0; ?>"
                                                    data-sku="<?php echo htmlspecialchars($product['sku']); ?>">
                                                    <?php echo htmlspecialchars($product['product_name']); ?>
                                                    <?php if (!empty($product['variant_name']) && $product['variant_name'] !== 'Standard'): ?>
                                                        - <?php echo htmlspecialchars($product['variant_name']); ?>
                                                    <?php endif; ?>
                                                    (SKU: <?php echo htmlspecialchars($product['sku']); ?>)
                                                    [Stock:
                                                    <?php echo number_format((float) ($product['available_stock'] ?? $product['total_stock'] ?? 0), 0); ?>]
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Quantity *</label>
                                    <input type="number" class="form-control" name="items[0][quantity]" min="0.01"
                                        step="0.01" placeholder="Enter quantity" required>
                                </div>
                                <div class="col-md-2 mb-3 d-flex align-items-end">
                                    <button type="button" class="btn btn-outline-danger w-100 remove-item">
                                        <i class="fas fa-trash me-1"></i> Remove
                                    </button>
                                </div>
                            </div>
                            <small class="text-muted stock-indicator"></small>
                        </div>
                    </div>

                    <button type="button" class="btn btn-sm btn-outline-primary mb-3" onclick="addTransferItem()">
                        <i class="fas fa-plus me-1"></i>Add Another Item
                    </button>

                    <!-- Notes -->
                    <div class="mb-3">
                        <label for="notes" class="form-label">Transfer Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2"
                            placeholder="Reason for transfer, reference numbers, etc..."></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitTransfer">
                        <i class="fas fa-exchange-alt me-2"></i>Complete Transfer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Transfer Details Modal -->
<div class="modal fade" id="transferDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="fas fa-exchange-alt me-2"></i>Transfer Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="transferDetailsContent">
                <!-- Dynamic content -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
    const locationsByWarehouse = <?php echo json_encode($locationsByWarehouse); ?>;
    let itemIndex = 1;

    function updateFromLocations(warehouseId) {
        const select = document.getElementById('from_location');
        select.innerHTML = '<option value="">Default Location</option>';

        if (warehouseId && locationsByWarehouse[warehouseId]) {
            locationsByWarehouse[warehouseId].forEach(loc => {
                select.innerHTML += `<option value="${loc.id}">${escapeHtml(loc.location_code)} - ${escapeHtml(loc.location_name || '')}</option>`;
            });
        }
    }

    function updateToLocations(warehouseId) {
        const select = document.getElementById('to_location');
        select.innerHTML = '<option value="">Default Location</option>';

        if (warehouseId && locationsByWarehouse[warehouseId]) {
            locationsByWarehouse[warehouseId].forEach(loc => {
                select.innerHTML += `<option value="${loc.id}">${escapeHtml(loc.location_code)} - ${escapeHtml(loc.location_name || '')}</option>`;
            });
        }
    }

    function addTransferItem() {
        const container = document.getElementById('transfer-items');
        const template = document.querySelector('.transfer-item').cloneNode(true);

        // Clear input values
        template.querySelectorAll('select, input').forEach(input => {
            if (input.tagName === 'SELECT') {
                input.selectedIndex = 0;
            } else {
                input.value = '';
            }
        });

        // Clear stock indicator
        const indicator = template.querySelector('.stock-indicator');
        if (indicator) indicator.textContent = '';

        // Update indices
        template.querySelectorAll('[name]').forEach(input => {
            const name = input.getAttribute('name');
            if (name) {
                input.setAttribute('name', name.replace('[0]', `[${itemIndex}]`));
            }
        });

        container.appendChild(template);
        itemIndex++;
    }

    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('remove-item') || e.target.closest('.remove-item')) {
            const btn = e.target.classList.contains('remove-item') ? e.target : e.target.closest('.remove-item');
            const items = document.querySelectorAll('.transfer-item');
            if (items.length > 1) {
                btn.closest('.transfer-item').remove();
            } else {
                alert('At least one item is required for transfer');
            }
        }
    });

    // Show available stock when product is selected
    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('product-select')) {
            const option = e.target.options[e.target.selectedIndex];
            const stock = parseFloat(option.getAttribute('data-stock') || 0);
            const indicator = e.target.closest('.transfer-item').querySelector('.stock-indicator');
            if (indicator) {
                if (stock > 0) {
                    indicator.innerHTML = `<i class="fas fa-boxes me-1"></i>Available stock: ${stock.toFixed(0)} units`;
                    indicator.style.color = '#28a745';
                } else {
                    indicator.innerHTML = `<i class="fas fa-exclamation-triangle me-1"></i>Out of stock!`;
                    indicator.style.color = '#dc3545';
                }
            }
        }
    });

    // Warehouse selection listeners
    document.getElementById('from_warehouse')?.addEventListener('change', function () {
        updateFromLocations(this.value);
    });

    document.getElementById('to_warehouse')?.addEventListener('change', function () {
        updateToLocations(this.value);
    });

    function viewTransferDetails(transfer) {
        const modalContent = document.getElementById('transferDetailsContent');

        modalContent.innerHTML = `
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Transfer Information</h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr><th style="width: 40%">Transfer Number:</th><td><code>${escapeHtml(transfer.transfer_number)}</code></td></tr>
                            <tr><th>Date & Time:</th><td>${new Date(transfer.created_at).toLocaleString()}</td></tr>
                            <tr><th>Status:</th><td><span class="badge bg-success">Completed</span></td></tr>
                            <tr><th>Created By:</th><td>${escapeHtml(transfer.created_by_name || 'System')}</td></tr>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-box me-2"></i>Product Information</h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr><th style="width: 40%">Product:</th><td><strong>${escapeHtml(transfer.product_name)}</strong></td></tr>
                            <tr><th>Variant:</th><td>${escapeHtml(transfer.variant_name || 'Standard')}</td></tr>
                            <tr><th>SKU:</th><td><code>${escapeHtml(transfer.sku)}</code></td></tr>
                            <tr><th>Quantity:</th><td><strong>${formatNumber(transfer.quantity)} units</strong></td></tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-arrow-right text-success me-2"></i>Source</h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr><th style="width: 40%">Warehouse:</th><td>${escapeHtml(transfer.from_warehouse_name)}</td></tr>
                            <tr><th>Location:</th><td>${escapeHtml(transfer.from_location_code || 'Default')}</td></tr>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-arrow-left text-primary me-2"></i>Destination</h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr><th style="width: 40%">Warehouse:</th><td>${escapeHtml(transfer.to_warehouse_name)}</td></tr>
                            <tr><th>Location:</th><td>${escapeHtml(transfer.to_location_code || 'Default')}</td></tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        ${transfer.notes ? `
        <div class="card">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="fas fa-sticky-note me-2"></i>Notes</h6>
            </div>
            <div class="card-body">
                <p class="mb-0">${escapeHtml(transfer.notes)}</p>
            </div>
        </div>
        ` : ''}
    `;

        new bootstrap.Modal(document.getElementById('transferDetailsModal')).show();
    }

    function refreshPage() {
        window.location.reload();
    }

    function formatNumber(num) {
        return new Intl.NumberFormat().format(num);
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initialize DataTable if needed
    $(document).ready(function () {
        if ($.fn.DataTable && $('#transfersTable tbody tr').length > 10) {
            $('#transfersTable').DataTable({
                pageLength: 25,
                order: [[1, 'desc']],
                language: {
                    search: "Search transfers:",
                    lengthMenu: "Show _MENU_ transfers per page",
                    info: "Showing _START_ to _END_ of _TOTAL_ transfers",
                    emptyTable: "No transfers found"
                },
                columnDefs: [
                    { orderable: false, targets: [7] }
                ]
            });
        }
    });

    // Form validation
    document.getElementById('transferForm')?.addEventListener('submit', function (e) {
        const fromWarehouse = document.getElementById('from_warehouse').value;
        const toWarehouse = document.getElementById('to_warehouse').value;

        if (fromWarehouse === toWarehouse) {
            e.preventDefault();
            alert('Source and destination warehouses cannot be the same');
            return false;
        }

        const items = document.querySelectorAll('.transfer-item');
        let hasValidItem = false;

        items.forEach(item => {
            const variantSelect = item.querySelector('.product-select');
            const quantity = item.querySelector('[name*="quantity"]');
            if (variantSelect && variantSelect.value && quantity && quantity.value > 0) {
                hasValidItem = true;
            }
        });

        if (!hasValidItem) {
            e.preventDefault();
            alert('Please add at least one valid item to transfer');
            return false;
        }

        return true;
    });
</script>

<style>
    .stock-transfers .table td {
        vertical-align: middle;
    }

    .transfer-item {
        background-color: #f8f9fc;
        transition: all 0.2s ease;
    }

    .transfer-item:hover {
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    .transfer-item .stock-indicator {
        display: block;
        margin-top: 5px;
        font-size: 0.875rem;
    }

    .modal .table-sm th {
        background-color: #f8f9fc;
        width: 40%;
    }

    .badge {
        font-weight: 500;
        padding: 0.5em 0.85em;
    }

    .card-header {
        font-weight: 600;
    }
</style>