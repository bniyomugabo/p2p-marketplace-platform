<?php
// pages/inventory/stock-count.php
declare(strict_types=1);

$pageTitle = 'Stock Count - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Inventory.php';
require_once __DIR__ . '/../../models/Warehouse.php';
require_once __DIR__ . '/../../models/Location.php';
require_once __DIR__ . '/../../models/Variant.php';
require_once __DIR__ . '/../../models/Product.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR', 'WHS'])) {
    SessionManager::flash('error', 'You do not have permission to perform stock counts.');
    header('Location: ' . route_url('inventory/dashboard'));
    exit;
}

// Initialize models with company context
$inventoryModel = new Inventory($companyId);
$warehouseModel = new Warehouse($companyId);
$locationModel = new Location($companyId);
$variantModel = new Variant($companyId);
$productModel = new Product($companyId);

// Get parameters
$warehouseId = isset($_GET['warehouse_id']) ? (int) $_GET['warehouse_id'] : null;
$locationId = isset($_GET['location_id']) ? (int) $_GET['location_id'] : null;
$sessionId = $_GET['session_id'] ?? null;

// Generate or retrieve session ID
if (!$sessionId) {
    $sessionId = 'COUNT_' . date('Ymd_His') . '_' . uniqid();
}

// Get warehouses for this company
$warehouses = $warehouseModel->all(['id', 'warehouse_name'], 'is_active = 1');

// Get locations if warehouse selected
$locations = [];
if ($warehouseId) {
    $locations = $locationModel->getByWarehouse($warehouseId);
}

// Get stock items to count
$stockItems = [];
$totalItems = 0;
$countedItems = 0;

if ($warehouseId) {
    // Get stock list for this warehouse
    $stockItems = $inventoryModel->getStockList($warehouseId, $locationId);
    $totalItems = count($stockItems);

    // Track counted items in session
    $countedKey = 'stock_count_' . $sessionId;
    $countedData = isset($_SESSION[$countedKey]) ? $_SESSION[$countedKey] : [];
    $countedItems = count($countedData);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
        SessionManager::flash('error', 'Invalid security token.');
        header('Location: ?page=inventory/stock-count' . ($warehouseId ? '&warehouse_id=' . $warehouseId : ''));
        exit;
    }

    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_count') {
            // Save individual item count
            $variantId = (int) $_POST['variant_id'];
            $countedQty = (float) $_POST['counted_quantity'];
            $notes = trim($_POST['notes'] ?? '');
            $batchNumber = trim($_POST['batch_number'] ?? '');
            $expiryDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;

            // Store in session
            $countedKey = 'stock_count_' . $_POST['session_id'];
            if (!isset($_SESSION[$countedKey])) {
                $_SESSION[$countedKey] = [];
            }

            $_SESSION[$countedKey][$variantId] = [
                'variant_id' => $variantId,
                'counted_quantity' => $countedQty,
                'notes' => $notes,
                'batch_number' => $batchNumber,
                'expiry_date' => $expiryDate,
                'counted_at' => date('Y-m-d H:i:s'),
                'counted_by' => $userId
            ];

            SessionManager::flash('success', 'Item count saved.');

        } elseif ($action === 'complete_count') {
            // Complete the stock count and apply adjustments
            $sessionId = $_POST['session_id'];
            $warehouseId = (int) $_POST['warehouse_id'];
            $locationId = !empty($_POST['location_id']) ? (int) $_POST['location_id'] : null;

            $countedKey = 'stock_count_' . $sessionId;
            $countedData = $_SESSION[$countedKey] ?? [];

            if (empty($countedData)) {
                throw new Exception('No items have been counted.');
            }

            $db->beginTransaction();

            $adjustments = [];

            foreach ($stockItems as $item) {
                $variantId = $item['variant_id'] ?? $item['id'];
                $currentQty = $item['quantity'];
                $counted = isset($countedData[$variantId]);
                $countedQty = $counted ? $countedData[$variantId]['counted_quantity'] : 0;

                $difference = $countedQty - $currentQty;

                if (abs($difference) > 0.01) { // Only create adjustment if difference > 0.01
                    // Create inventory adjustment
                    $inventoryModel->updateStock(
                        $variantId,
                        $warehouseId,
                        $difference,
                        null,
                        $locationId,
                        $userId
                    );

                    $adjustments[] = [
                        'variant_id' => $variantId,
                        'product_name' => $item['product_name'],
                        'variant_name' => $item['variant_name'],
                        'old_qty' => $currentQty,
                        'new_qty' => $countedQty,
                        'difference' => $difference
                    ];
                }
            }

            // Clear the session data
            unset($_SESSION[$countedKey]);

            $db->commit();

            // Log the count completion
            error_log("Stock count completed by user {$userId}. " . count($adjustments) . " adjustments made.");

            SessionManager::flash('success', 'Stock count completed! ' . count($adjustments) . ' adjustments made.');
            header('Location: ' . route_url('inventory/stock'));
            exit;

        } elseif ($action === 'cancel_count') {
            // Cancel the count session
            $sessionId = $_POST['session_id'];
            $countedKey = 'stock_count_' . $sessionId;
            unset($_SESSION[$countedKey]);

            SessionManager::flash('info', 'Stock count cancelled.');
            header('Location: ' . route_url('inventory/stock'));
            exit;
        }

    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Stock count error: " . $e->getMessage());
        SessionManager::flash('error', 'Failed to process stock count: ' . $e->getMessage());
    }

    // Redirect back to the same page
    header('Location: ?page=inventory/stock-count&warehouse_id=' . $warehouseId . '&location_id=' . $locationId . '&session_id=' . $sessionId);
    exit;
}

// Get counted data for display
$countedData = [];
if ($sessionId) {
    $countedKey = 'stock_count_' . $sessionId;
    $countedData = isset($_SESSION[$countedKey]) ? $_SESSION[$countedKey] : [];
}

// Generate CSRF token
$csrfToken = CSRF::generate();
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <a href="<?php echo route_url('inventory/stock'); ?>" class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Stock
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-clipboard-list me-2"></i>Stock Count
                    </h2>
                    <p class="mb-0 text-muted">Count physical inventory and reconcile with system</p>
                </div>
                <?php if ($warehouseId && $totalItems > 0): ?>
                    <div>
                        <div class="btn-group">
                            <button type="button" class="btn btn-success" data-bs-toggle="modal"
                                data-bs-target="#completeModal">
                                <i class="fas fa-check-circle me-2"></i>Complete Count
                            </button>
                            <button type="button" class="btn btn-danger" data-bs-toggle="modal"
                                data-bs-target="#cancelModal">
                                <i class="fas fa-times-circle me-2"></i>Cancel
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
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
    <?php elseif ($flash = SessionManager::flash('info')): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <i class="fas fa-info-circle me-2"></i><?php echo htmlspecialchars($flash); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Warehouse/Location Selection -->
    <?php if (!$warehouseId): ?>
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-warehouse me-2"></i>Select Location to Count
                </h6>
            </div>
            <div class="card-body">
                <form method="GET" action="" class="row g-3">
                    <input type="hidden" name="page" value="inventory/stock-count">

                    <div class="col-md-5">
                        <label for="warehouse_id" class="form-label">Warehouse <span class="text-danger">*</span></label>
                        <select class="form-control" id="warehouse_id" name="warehouse_id" required>
                            <option value="">Select Warehouse</option>
                            <?php foreach ($warehouses as $wh): ?>
                                <option value="<?php echo $wh['id']; ?>">
                                    <?php echo htmlspecialchars($wh['warehouse_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-5">
                        <label for="location_id" class="form-label">Location (Optional)</label>
                        <select class="form-control" id="location_id" name="location_id">
                            <option value="">All Locations</option>
                        </select>
                    </div>

                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-play me-2"></i>Start Count
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- Stock Count Progress -->
    <?php if ($warehouseId && $totalItems > 0): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-4">
                                <h5 class="mb-0">Progress</h5>
                                <p class="text-muted small mb-0"><?php echo $countedItems; ?> of <?php echo $totalItems; ?>
                                    items counted</p>
                            </div>
                            <div class="col-md-8">
                                <div class="progress" style="height: 20px;">
                                    <?php $progress = $totalItems > 0 ? round(($countedItems / $totalItems) * 100) : 0; ?>
                                    <div class="progress-bar bg-success" style="width: <?php echo $progress; ?>%"
                                        role="progressbar" aria-valuenow="<?php echo $progress; ?>" aria-valuemin="0"
                                        aria-valuemax="100">
                                        <?php echo $progress; ?>%
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Stock Count Table - Simplified -->
    <?php if ($warehouseId): ?>
        <div class="card shadow mb-4">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-boxes me-2"></i>Items to Count
                    <span class="badge bg-primary ms-2"><?php echo $totalItems; ?> items</span>
                </h6>
                <span class="text-muted small">
                    Session: <code><?php echo htmlspecialchars($sessionId); ?></code>
                </span>
            </div>
            <div class="card-body">
                <?php if (empty($stockItems)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-box-open fa-4x text-muted mb-3"></i>
                        <p class="mb-0">No items found in this location.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="stockCountTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th>SKU</th>
                                    <th class="text-end">System Qty</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stockItems as $item):
                                    $variantId = $item['variant_id'] ?? $item['id'];
                                    $counted = isset($countedData[$variantId]);

                                    $statusClass = $counted ? 'success' : 'secondary';
                                    $statusText = $counted ? 'Counted' : 'Pending';
                                    ?>
                                    <tr class="<?php echo $counted ? 'table-success' : ''; ?>">
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                            <?php if (!empty($item['variant_name']) && $item['variant_name'] !== 'Standard'): ?>
                                                <small class="text-muted"><?php echo htmlspecialchars($item['variant_name']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><code><?php echo htmlspecialchars($item['sku']); ?></code></td>
                                        <td class="text-end fw-bold"><?php echo number_format((float) $item['quantity'], 0); ?> units
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $statusClass; ?>">
                                                <i class="fas fa-<?php echo $counted ? 'check-circle' : 'clock'; ?> me-1"></i>
                                                <?php echo $statusText; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button type="button"
                                                class="btn btn-sm btn-<?php echo $counted ? 'warning' : 'primary'; ?>" onclick="openCountModal(<?php echo htmlspecialchars(json_encode([
                                                           'variant_id' => $variantId,
                                                           'product_name' => $item['product_name'],
                                                           'variant_name' => $item['variant_name'] ?? 'Standard',
                                                           'sku' => $item['sku'],
                                                           'system_qty' => $item['quantity'],
                                                           'current_count' => $counted ? $countedData[$variantId]['counted_quantity'] : $item['quantity'],
                                                           'notes' => $counted ? ($countedData[$variantId]['notes'] ?? '') : '',
                                                           'batch_number' => $counted ? ($countedData[$variantId]['batch_number'] ?? '') : '',
                                                           'expiry_date' => $counted ? ($countedData[$variantId]['expiry_date'] ?? '') : '',
                                                           'location' => $item['location_code'] ?? 'Default'
                                                       ])); ?>)">
                                                <i class="fas fa-<?php echo $counted ? 'edit' : 'clipboard-list'; ?> me-1"></i>
                                                <?php echo $counted ? 'Edit' : 'Count'; ?>
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
    <?php endif; ?>
</div>

<!-- Count Modal - Detailed View -->
<div class="modal fade" id="countModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-clipboard-list me-2"></i>Count Item
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="" id="countForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="save_count">
                <input type="hidden" name="session_id" value="<?php echo htmlspecialchars($sessionId); ?>">
                <input type="hidden" name="variant_id" id="count_variant_id">
                <input type="hidden" name="warehouse_id" value="<?php echo $warehouseId; ?>">
                <input type="hidden" name="location_id" value="<?php echo $locationId; ?>">

                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0"><i class="fas fa-box me-2"></i>Product Information</h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm">
                                        <tr>
                                            <th style="width: 40%">Product:</th>
                                            <td><strong id="count_product_name"></strong></td>
                                        </tr>
                                        <tr>
                                            <th>SKU:</th>
                                            <td><code id="count_sku"></code></td>
                                        </tr>
                                        <tr>
                                            <th>Location:</th>
                                            <td id="count_location"></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="card mb-3">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0"><i class="fas fa-chart-line me-2"></i>System Information</h6>
                                </div>
                                <div class="card-body">
                                    <table class="table table-sm">
                                        <tr>
                                            <th style="width: 40%">System Quantity:</th>
                                            <td class="fw-bold" id="count_system_qty"></td>
                                        </tr>
                                        <tr>
                                            <th>Last Updated:</th>
                                            <td id="count_last_updated">-</td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card mb-3">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-pencil-alt me-2"></i>Count Information</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="counted_quantity" class="form-label">Counted Quantity <span
                                            class="text-danger">*</span></label>
                                    <input type="number" class="form-control form-control-lg" id="counted_quantity"
                                        name="counted_quantity" min="0" step="1" required>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="batch_number" class="form-label">Batch/Lot Number</label>
                                    <input type="text" class="form-control" id="batch_number" name="batch_number"
                                        placeholder="e.g., BATCH-2024-001">
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="expiry_date" class="form-label">Expiry Date</label>
                                    <input type="date" class="form-control" id="expiry_date" name="expiry_date">
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="count_notes" class="form-label">Notes</label>
                                    <input type="text" class="form-control" id="count_notes" name="notes"
                                        placeholder="Any observations about this item...">
                                </div>
                            </div>

                            <div class="alert alert-info" id="variance_alert" style="display: none;">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-calculator fa-2x me-3"></i>
                                    <div>
                                        <strong>Variance:</strong>
                                        <span id="variance_value"></span>
                                        <br>
                                        <small class="text-muted">This difference will be adjusted in inventory upon
                                            completion</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Save Count
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Complete Count Modal -->
<div class="modal fade" id="completeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="fas fa-check-circle me-2"></i>Complete Stock Count
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="complete_count">
                <input type="hidden" name="session_id" value="<?php echo htmlspecialchars($sessionId); ?>">
                <input type="hidden" name="warehouse_id" value="<?php echo $warehouseId; ?>">
                <input type="hidden" name="location_id" value="<?php echo $locationId; ?>">

                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-check-circle fa-4x text-success"></i>
                    </div>
                    <p>You have counted <strong><?php echo $countedItems; ?></strong> of
                        <strong><?php echo $totalItems; ?></strong> items.</p>

                    <?php if ($countedItems < $totalItems): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Warning:</strong> You still have <?php echo $totalItems - $countedItems; ?> items
                            pending.
                            Completing now will assume uncounted items have zero quantity.
                        </div>
                    <?php endif; ?>

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>What happens next:</strong> Inventory adjustments will be created for any variances
                        between system and counted quantities.
                    </div>

                    <p class="mb-0">Are you sure you want to complete this stock count?</p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check-circle me-2"></i>Complete Count
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Cancel Count Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="fas fa-times-circle me-2"></i>Cancel Stock Count
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="cancel_count">
                <input type="hidden" name="session_id" value="<?php echo htmlspecialchars($sessionId); ?>">

                <div class="modal-body">
                    <div class="text-center mb-3">
                        <i class="fas fa-exclamation-triangle fa-4x text-warning"></i>
                    </div>
                    <p>Are you sure you want to cancel this stock count session?</p>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        All counted data will be lost and no adjustments will be made.
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Continue Counting</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-times-circle me-2"></i>Cancel Count
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Location filtering on warehouse selection
    document.getElementById('warehouse_id')?.addEventListener('change', function () {
        const warehouseId = this.value;
        const locationSelect = document.getElementById('location_id');
        const locations = <?php echo json_encode($locationModel->getAllWithWarehouse()); ?>;

        locationSelect.innerHTML = '<option value="">All Locations</option>';

        if (warehouseId && locations.length > 0) {
            const filtered = locations.filter(loc => loc.warehouse_id == warehouseId);
            filtered.forEach(loc => {
                const option = document.createElement('option');
                option.value = loc.id;
                option.textContent = `${loc.location_name} (${loc.location_code})`;
                locationSelect.appendChild(option);
            });
        }
    });

    // Count modal functions
    let currentSystemQty = 0;

    function openCountModal(item) {
        document.getElementById('count_variant_id').value = item.variant_id;
        document.getElementById('count_product_name').textContent =
            item.product_name + (item.variant_name !== 'Standard' ? ' - ' + item.variant_name : '');
        document.getElementById('count_sku').textContent = item.sku;
        document.getElementById('count_location').textContent = item.location || 'Default';
        document.getElementById('count_system_qty').textContent = formatNumber(item.system_qty) + ' units';
        document.getElementById('counted_quantity').value = item.current_count || item.system_qty;
        document.getElementById('batch_number').value = item.batch_number || '';
        document.getElementById('expiry_date').value = item.expiry_date || '';
        document.getElementById('count_notes').value = item.notes || '';

        currentSystemQty = item.system_qty;

        // Update variance alert
        updateVariance();

        new bootstrap.Modal(document.getElementById('countModal')).show();
    }

    document.getElementById('counted_quantity')?.addEventListener('input', updateVariance);

    function updateVariance() {
        const countedQty = parseFloat(document.getElementById('counted_quantity').value) || 0;
        const variance = countedQty - currentSystemQty;
        const alertDiv = document.getElementById('variance_alert');
        const varianceSpan = document.getElementById('variance_value');

        if (Math.abs(variance) > 0.01) {
            alertDiv.style.display = 'block';
            const varianceText = (variance > 0 ? '+' : '') + formatNumber(Math.abs(variance));
            const varianceClass = variance > 0 ? 'text-success' : 'text-danger';
            varianceSpan.innerHTML = `<span class="${varianceClass} fw-bold">${varianceText} units</span>`;
        } else {
            alertDiv.style.display = 'none';
        }
    }

    function formatNumber(num) {
        return new Intl.NumberFormat().format(num);
    }

    function formatCurrency(amount) {
        const currency = '<?php echo $_SESSION['company_currency'] ?? 'RWF'; ?>';
        return currency + ' ' + new Intl.NumberFormat().format(amount);
    }

    // Form validation
    document.getElementById('countForm')?.addEventListener('submit', function (e) {
        const qty = parseFloat(document.getElementById('counted_quantity').value);
        if (isNaN(qty) || qty < 0) {
            e.preventDefault();
            alert('Please enter a valid quantity.');
        }
    });

    // Initialize DataTable
    $(document).ready(function () {
        if ($('#stockCountTable tbody tr').length > 0) {
            $('#stockCountTable').DataTable({
                pageLength: 25,
                order: [[0, 'asc']],
                language: {
                    search: "Search items:",
                    lengthMenu: "Show _MENU_ items per page",
                    info: "Showing _START_ to _END_ of _TOTAL_ items",
                    emptyTable: "No items to count"
                },
                columnDefs: [
                    { orderable: false, targets: [4] }
                ]
            });
        }
    });
</script>

<style>
    .table td {
        vertical-align: middle;
    }

    .table-success {
        background-color: #d4edda !important;
    }

    .progress {
        background-color: #e9ecef;
        border-radius: 10px;
    }

    .progress-bar {
        border-radius: 10px;
    }

    .modal .table-sm th {
        background-color: #f8f9fc;
        width: 40%;
    }

    .badge {
        font-weight: 500;
        padding: 0.5em 0.85em;
    }

    .form-control-lg {
        font-size: 1.25rem;
    }
</style>