<?php
// pages/products/stock_adjust_variant.php
declare(strict_types=1);

$pageTitle = 'Adjust Stock - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';

// Load models
require_once __DIR__ . '/../../models/Product.php';
require_once __DIR__ . '/../../models/Variant.php';
require_once __DIR__ . '/../../models/Inventory.php';
require_once __DIR__ . '/../../models/Warehouse.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? 0;

// Check permission
if (!in_array($userRole, ['ADM', 'MGR', 'WHS'])) {
    SessionManager::flash('error', 'You do not have permission to adjust stock.');
    header('Location: ' . route_url('products'));
    exit;
}

// Get variant ID from URL
$variantId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$variantId) {
    SessionManager::flash('error', 'Variant ID is required.');
    header('Location: ' . route_url('products'));
    exit;
}

// Initialize models with company context
$variantModel = new Variant($companyId);
$productModel = new Product($companyId);
$inventoryModel = new Inventory($companyId);
$warehouseModel = new Warehouse($companyId);

// Get variant details
$variant = $variantModel->getWithDetails($variantId);

if (!$variant) {
    SessionManager::flash('error', 'Variant not found or not accessible for your company.');
    header('Location: ' . route_url('products'));
    exit;
}

// Get product details
$product = $productModel->getWithDetails($variant['product_id']);

// Get warehouses for this company
$warehouses = $warehouseModel->all(['id', 'warehouse_name', 'warehouse_code', 'is_main'], 'is_active = 1');

// Get current stock by warehouse
$currentStock = $variantModel->getStockByWarehouse($variantId);

// Create stock lookup array
$stockLookup = [];
foreach ($currentStock as $stock) {
    $stockLookup[$stock['warehouse_id']] = $stock;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
        SessionManager::flash('error', 'Invalid security token. Please try again.');
        header('Location: ?page=products/stock-adjust-variant&id=' . $variantId);
        exit;
    }

    try {
        $adjustmentType = $_POST['adjustment_type'] ?? 'set';
        $warehouseId = (int) ($_POST['warehouse_id'] ?? 0);
        $quantity = (float) ($_POST['quantity'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        $reference = trim($_POST['reference'] ?? '');

        // Validate inputs
        if ($warehouseId <= 0) {
            throw new Exception('Please select a warehouse.');
        }

        if ($quantity <= 0) {
            throw new Exception('Quantity must be greater than zero.');
        }

        if (empty($reason)) {
            throw new Exception('Please provide a reason for the adjustment.');
        }

        // Get current quantity
        $currentQty = isset($stockLookup[$warehouseId]) ? (float) $stockLookup[$warehouseId]['quantity'] : 0;
        $newQuantity = 0;
        $changeAmount = 0;

        switch ($adjustmentType) {
            case 'add':
                $changeAmount = $quantity;
                $newQuantity = $currentQty + $quantity;
                break;
            case 'subtract':
                if ($currentQty < $quantity) {
                    throw new Exception('Insufficient stock. Current stock: ' . number_format((string)$currentQty, 0));
                }
                $changeAmount = -$quantity;
                $newQuantity = $currentQty - $quantity;
                break;
            case 'set':
                $changeAmount = $quantity - $currentQty;
                $newQuantity = $quantity;
                break;
            default:
                throw new Exception('Invalid adjustment type.');
        }

        // Begin transaction
        $db->beginTransaction();

        // Update or insert inventory
        if (isset($stockLookup[$warehouseId])) {
            // Update existing inventory record
            $stmt = $db->prepare("
                UPDATE inventory 
                SET quantity = :quantity, 
                    last_updated = NOW()
                WHERE variant_id = :variant_id 
                    AND warehouse_id = :warehouse_id
                    AND company_id = :company_id
            ");
            $stmt->execute([
                'quantity' => $newQuantity,
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'company_id' => $companyId
            ]);
        } else {
            // Create new inventory record
            $stmt = $db->prepare("
                INSERT INTO inventory (company_id, variant_id, warehouse_id, quantity, last_updated)
                VALUES (:company_id, :variant_id, :warehouse_id, :quantity, NOW())
            ");
            $stmt->execute([
                'company_id' => $companyId,
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'quantity' => $newQuantity
            ]);
        }

        // Create transaction log
        $transactionCode = 'ADJ-' . date('YmdHis') . '-' . rand(100, 999);
        $notes = $reason;
        if ($reference) {
            $notes .= " | Reference: " . $reference;
        }

        $stmt = $db->prepare("
            INSERT INTO inventory_transactions 
            (company_id, transaction_code, transaction_type, variant_id, warehouse_id, quantity, notes, created_by, created_at)
            VALUES (:company_id, :transaction_code, 'adjustment', :variant_id, :warehouse_id, :quantity, :notes, :created_by, NOW())
        ");
        $stmt->execute([
            'company_id' => $companyId,
            'transaction_code' => $transactionCode,
            'variant_id' => $variantId,
            'warehouse_id' => $warehouseId,
            'quantity' => $changeAmount,
            'notes' => $notes,
            'created_by' => $userId
        ]);

        // Log activity
        $activityDesc = "Stock adjustment for variant {$variant['sku']} in warehouse {$warehouseId}: ";
        $activityDesc .= $adjustmentType === 'add' ? "Added {$quantity}" : ($adjustmentType === 'subtract' ? "Removed {$quantity}" : "Set to {$quantity}");
        $activityDesc .= " (was {$currentQty})";

        $stmt = $db->prepare("
            INSERT INTO activity_log (company_id, user_id, action, entity_type, entity_id, new_data, created_at)
            VALUES (:company_id, :user_id, 'stock_adjustment', 'variant', :entity_id, :new_data, NOW())
        ");
        $stmt->execute([
            'company_id' => $companyId,
            'user_id' => $userId,
            'entity_id' => $variantId,
            'new_data' => json_encode([
                'adjustment_type' => $adjustmentType,
                'quantity' => $quantity,
                'new_quantity' => $newQuantity,
                'previous_quantity' => $currentQty,
                'warehouse_id' => $warehouseId,
                'reason' => $reason,
                'reference' => $reference
            ])
        ]);

        $db->commit();

        SessionManager::flash('success', "Stock adjusted successfully! New quantity: " . number_format((float)$newQuantity, 0) . " units.");
        header('Location: ?page=products/view-variant&id=' . $variantId);
        exit;

    } catch (Exception $e) {
        $db->rollBack();
        SessionManager::flash('error', 'Failed to adjust stock: ' . $e->getMessage());
        header('Location: ?page=products/stock-adjust-variant&id=' . $variantId);
        exit;
    }
}

// Generate CSRF token
$csrfToken = CSRF::generate();
?>

<div class="stock-adjust-page">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <a href="<?php echo route_url('products/view-variant', ['id' => $variantId]); ?>"
                        class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Variant
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-adjust me-2"></i>Adjust Stock
                    </h2>
                    <p class="mb-0 text-muted">
                        Adjust inventory levels for variant:
                        <strong>
                            <?php echo htmlspecialchars($variant['variant_name'] ?: 'Standard'); ?>
                        </strong>
                        (SKU:
                        <?php echo htmlspecialchars($variant['sku']); ?>)
                    </p>
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

    <div class="row">
        <!-- Main Form -->
        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-edit me-2"></i>Stock Adjustment Form
                    </h6>
                </div>
                <div class="card-body">
                    <form method="POST" action="" id="stockAdjustForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                        <!-- Product Information Summary -->
                        <div class="alert alert-info mb-4">
                            <div class="row">
                                <div class="col-md-6">
                                    <small class="text-muted">Product:</small>
                                    <div class="fw-bold">
                                        <?php echo htmlspecialchars($product['product_name']); ?>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <small class="text-muted">Variant:</small>
                                    <div class="fw-bold">
                                        <?php echo htmlspecialchars($variant['variant_name'] ?: 'Standard'); ?>
                                    </div>
                                </div>
                                <div class="col-md-6 mt-2">
                                    <small class="text-muted">SKU:</small>
                                    <div><code><?php echo htmlspecialchars($variant['sku']); ?></code></div>
                                </div>
                                <div class="col-md-6 mt-2">
                                    <small class="text-muted">Current Selling Price:</small>
                                    <div>
                                        <?php echo format_currency($variant['selling_price'] ?? 0, $companyId); ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Adjustment Type -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">Adjustment Type <span class="text-danger">*</span></label>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="adjustment_type"
                                            id="type_add" value="add" checked>
                                        <label class="form-check-label" for="type_add">
                                            <i class="fas fa-plus-circle text-success"></i>
                                            <strong>Add Stock</strong>
                                            <br><small class="text-muted">Increase inventory quantity</small>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="adjustment_type"
                                            id="type_subtract" value="subtract">
                                        <label class="form-check-label" for="type_subtract">
                                            <i class="fas fa-minus-circle text-danger"></i>
                                            <strong>Remove Stock</strong>
                                            <br><small class="text-muted">Decrease inventory quantity</small>
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="adjustment_type"
                                            id="type_set" value="set">
                                        <label class="form-check-label" for="type_set">
                                            <i class="fas fa-equals text-warning"></i>
                                            <strong>Set Exact Quantity</strong>
                                            <br><small class="text-muted">Override current stock level</small>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Warehouse Selection -->
                        <div class="mb-3">
                            <label for="warehouse_id" class="form-label">Warehouse <span
                                    class="text-danger">*</span></label>
                            <select class="form-control" id="warehouse_id" name="warehouse_id" required>
                                <option value="">Select Warehouse</option>
                                <?php foreach ($warehouses as $warehouse): ?>
                                    <option value="<?php echo $warehouse['id']; ?>">
                                        <?php echo htmlspecialchars($warehouse['warehouse_name']); ?>
                                        <?php if ($warehouse['warehouse_code']): ?>
                                            (
                                            <?php echo htmlspecialchars($warehouse['warehouse_code']); ?>)
                                        <?php endif; ?>
                                        <?php if ($warehouse['is_main']): ?>
                                            - Main Warehouse
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Current Stock Display (dynamic) -->
                        <div id="currentStockDisplay" class="mb-3" style="display: none;">
                            <label class="form-label">Current Stock Level</label>
                            <div class="alert alert-secondary">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>
                                        <i class="fas fa-boxes me-2"></i>
                                        Current quantity in selected warehouse:
                                    </span>
                                    <span class="fw-bold h5 mb-0" id="currentStockValue">0</span>
                                </div>
                            </div>
                        </div>

                        <!-- Quantity Input -->
                        <div class="mb-3">
                            <label for="quantity" class="form-label">Quantity <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="quantity" name="quantity" step="1"
                                    min="0.01" required>
                                <span class="input-group-text">units</span>
                            </div>
                            <small class="text-muted" id="quantityHelp"></small>
                        </div>

                        <!-- New Stock Preview -->
                        <div id="newStockPreview" class="mb-3" style="display: none;">
                            <label class="form-label">Preview</label>
                            <div class="alert" id="previewAlert">
                                <!-- Dynamic content -->
                            </div>
                        </div>

                        <!-- Reason -->
                        <div class="mb-3">
                            <label for="reason" class="form-label">Reason for Adjustment <span
                                    class="text-danger">*</span></label>
                            <select class="form-control" id="reason" name="reason" required>
                                <option value="">Select Reason</option>
                                <option value="Physical count correction">📊 Physical count correction</option>
                                <option value="Damaged goods">💔 Damaged goods</option>
                                <option value="Expired products">⏰ Expired products</option>
                                <option value="Quality control rejection">🔍 Quality control rejection</option>
                                <option value="Return to supplier">📦 Return to supplier</option>
                                <option value="Supplier credit">💳 Supplier credit</option>
                                <option value="Inventory write-off">✍️ Inventory write-off</option>
                                <option value="System error correction">🖥️ System error correction</option>
                                <option value="Stock transfer adjustment">🔄 Stock transfer adjustment</option>
                                <option value="Other">📝 Other</option>
                            </select>
                        </div>

                        <!-- Custom Reason (shown when "Other" is selected) -->
                        <div class="mb-3" id="customReasonDiv" style="display: none;">
                            <label for="custom_reason" class="form-label">Custom Reason <span
                                    class="text-danger">*</span></label>
                            <textarea class="form-control" id="custom_reason" name="custom_reason" rows="2"
                                placeholder="Please provide detailed reason for adjustment..."></textarea>
                        </div>

                        <!-- Reference Number -->
                        <div class="mb-3">
                            <label for="reference" class="form-label">Reference Number (Optional)</label>
                            <input type="text" class="form-control" id="reference" name="reference"
                                placeholder="e.g., Invoice #, Adjustment #, Count Sheet #">
                            <small class="text-muted">Any reference number associated with this adjustment</small>
                        </div>

                        <!-- Notes -->
                        <div class="mb-3">
                            <label for="notes" class="form-label">Additional Notes (Optional)</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3"
                                placeholder="Any additional information about this adjustment..."></textarea>
                        </div>

                        <hr>

                        <!-- Submit Buttons -->
                        <div class="d-flex justify-content-end gap-2">
                            <a href="<?php echo route_url('products/view-variant', ['id' => $variantId]); ?>"
                                class="btn btn-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <i class="fas fa-save me-2"></i>Apply Adjustment
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Sidebar - Current Stock Summary -->
        <div class="col-md-4">
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-chart-pie me-2"></i>Current Stock Summary
                    </h6>
                </div>
                <div class="card-body">
                    <?php if (empty($currentStock)): ?>
                        <div class="text-center text-muted py-3">
                            <i class="fas fa-box-open fa-3x mb-3"></i>
                            <p>No stock records found</p>
                            <small>Stock will be created when you add stock</small>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead class="table-light">
                                    <tr>
                                        <th>Warehouse</th>
                                        <th class="text-end">Quantity</th>
                                        <th class="text-end">Available</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $totalStock = 0;
                                    $totalAvailable = 0;
                                    foreach ($currentStock as $stock):
                                        $totalStock += $stock['quantity'];
                                        $totalAvailable += ($stock['available_quantity'] ?? $stock['quantity']);
                                        ?>
                                        <tr>
                                            <td>
                                                <?php echo htmlspecialchars($stock['warehouse_name']); ?>
                                                <?php if ($stock['location_code']): ?>
                                                    <br><small class="text-muted">
                                                        <?php echo htmlspecialchars($stock['location_code']); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end fw-bold">
                                                <?php echo number_format((float)$stock['quantity'], 0); ?>
                                            </td>
                                            <td class="text-end text-success">
                                                <?php echo number_format((float)$stock['available_quantity'] ?? $stock['quantity'], 0); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr class="fw-bold">
                                        <td>Total</td>
                                        <td class="text-end">
                                            <?php echo number_format((float)$totalStock, 0); ?>
                                        </td>
                                        <td class="text-end text-success">
                                            <?php echo number_format((float)$totalAvailable, 0); ?>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Help Card -->
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-question-circle me-2"></i>Adjustment Guidelines
                    </h6>
                </div>
                <div class="card-body">
                    <ul class="mb-0">
                        <li class="mb-2">
                            <strong>Add Stock:</strong>
                            <br>Use for receiving new inventory, returns, or correcting undercounts.
                        </li>
                        <li class="mb-2">
                            <strong>Remove Stock:</strong>
                            <br>Use for damaged goods, expired items, or correcting overcounts.
                        </li>
                        <li class="mb-2">
                            <strong>Set Exact Quantity:</strong>
                            <br>Use after physical count to match system to actual stock.
                        </li>
                        <li class="mb-2">
                            <strong>Always provide a reason:</strong>
                            <br>This helps with audit trails and inventory analysis.
                        </li>
                        <li>
                            <strong>Reference numbers:</strong>
                            <br>Link adjustments to purchase orders, count sheets, or approval forms.
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Stock data for preview calculations
    const stockData = <?php echo json_encode($stockLookup); ?>;

    // Update current stock display when warehouse changes
    document.getElementById('warehouse_id').addEventListener('change', function () {
        const warehouseId = this.value;
        const currentStockDiv = document.getElementById('currentStockDisplay');
        const currentStockSpan = document.getElementById('currentStockValue');
        const quantityInput = document.getElementById('quantity');
        const quantityHelp = document.getElementById('quantityHelp');

        if (warehouseId && stockData[warehouseId]) {
            const currentQty = parseFloat(stockData[warehouseId].quantity) || 0;
            currentStockSpan.textContent = numberFormat(currentQty, 0);
            currentStockDiv.style.display = 'block';

            // Update help text based on adjustment type
            const adjustmentType = document.querySelector('input[name="adjustment_type"]:checked').value;
            if (adjustmentType === 'subtract') {
                quantityHelp.innerHTML = `Maximum removable: ${numberFormat(currentQty, 0)} units`;
                quantityInput.max = currentQty;
            } else {
                quantityHelp.innerHTML = '';
                quantityInput.max = '';
            }
        } else if (warehouseId) {
            currentStockSpan.textContent = '0';
            currentStockDiv.style.display = 'block';
            quantityHelp.innerHTML = 'No existing stock - new record will be created';
        } else {
            currentStockDiv.style.display = 'none';
            quantityHelp.innerHTML = '';
        }

        updatePreview();
    });

    // Update preview when adjustment type changes
    document.querySelectorAll('input[name="adjustment_type"]').forEach(radio => {
        radio.addEventListener('change', function () {
            const warehouseId = document.getElementById('warehouse_id').value;
            if (warehouseId) {
                const currentQty = stockData[warehouseId] ? parseFloat(stockData[warehouseId].quantity) || 0 : 0;
                const quantityHelp = document.getElementById('quantityHelp');

                if (this.value === 'subtract') {
                    quantityHelp.innerHTML = `Maximum removable: ${numberFormat(currentQty, 0)} units`;
                    document.getElementById('quantity').max = currentQty;
                } else {
                    quantityHelp.innerHTML = '';
                    document.getElementById('quantity').max = '';
                }
            }
            updatePreview();
        });
    });

    // Update preview when quantity changes
    document.getElementById('quantity').addEventListener('input', updatePreview);

    function updatePreview() {
        const warehouseId = document.getElementById('warehouse_id').value;
        const quantity = parseFloat(document.getElementById('quantity').value) || 0;
        const adjustmentType = document.querySelector('input[name="adjustment_type"]:checked').value;
        const previewDiv = document.getElementById('newStockPreview');
        const previewAlert = document.getElementById('previewAlert');

        if (warehouseId && quantity > 0) {
            const currentQty = stockData[warehouseId] ? parseFloat(stockData[warehouseId].quantity) || 0 : 0;
            let newQty = currentQty;
            let action = '';
            let alertClass = 'alert-info';

            switch (adjustmentType) {
                case 'add':
                    newQty = currentQty + quantity;
                    action = 'adding';
                    alertClass = 'alert-success';
                    break;
                case 'subtract':
                    newQty = currentQty - quantity;
                    action = 'removing';
                    alertClass = newQty < 0 ? 'alert-danger' : 'alert-warning';
                    break;
                case 'set':
                    newQty = quantity;
                    action = 'setting to';
                    alertClass = 'alert-primary';
                    break;
            }

            if (adjustmentType === 'subtract' && newQty < 0) {
                previewAlert.innerHTML = `
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Warning:</strong> This would result in negative stock (${numberFormat(newQty, 0)} units).
                Please check your quantity.
            `;
                previewAlert.className = 'alert alert-danger';
                previewDiv.style.display = 'block';
                document.getElementById('submitBtn').disabled = true;
            } else {
                previewAlert.innerHTML = `
                <div class="d-flex justify-content-between align-items-center">
                    <span>
                        <i class="fas fa-calculator me-2"></i>
                        ${action.charAt(0).toUpperCase() + action.slice(1)} 
                        <strong>${numberFormat(quantity, 0)}</strong> units
                        ${adjustmentType !== 'set' ? '(from ' + numberFormat(currentQty, 0) + ')' : ''}
                    </span>
                    <span class="fw-bold h5 mb-0">
                        → ${numberFormat(newQty, 0)} units
                    </span>
                </div>
            `;
                previewAlert.className = `alert ${alertClass}`;
                previewDiv.style.display = 'block';
                document.getElementById('submitBtn').disabled = false;
            }
        } else {
            previewDiv.style.display = 'none';
            document.getElementById('submitBtn').disabled = false;
        }
    }

    // Show/hide custom reason field
    document.getElementById('reason').addEventListener('change', function () {
        const customReasonDiv = document.getElementById('customReasonDiv');
        if (this.value === 'Other') {
            customReasonDiv.style.display = 'block';
            document.getElementById('custom_reason').setAttribute('required', 'required');
        } else {
            customReasonDiv.style.display = 'none';
            document.getElementById('custom_reason').removeAttribute('required');
        }
    });

    // Update form submission to combine reasons
    document.getElementById('stockAdjustForm').addEventListener('submit', function (e) {
        const reasonSelect = document.getElementById('reason');
        let reason = reasonSelect.value;

        if (reason === 'Other') {
            const customReason = document.getElementById('custom_reason').value.trim();
            if (!customReason) {
                e.preventDefault();
                alert('Please enter a custom reason');
                return false;
            }
            reason = customReason;
        }

        // Set the combined reason
        const reasonInput = document.createElement('input');
        reasonInput.type = 'hidden';
        reasonInput.name = 'reason';
        reasonInput.value = reason;
        this.appendChild(reasonInput);

        // Disable the original select so it doesn't submit with empty value
        reasonSelect.disabled = true;

        return true;
    });

    function numberFormat(num, decimals) {
        return new Intl.NumberFormat('en-US', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        }).format(num);
    }

    // Initialize tooltips and other UI elements
    document.addEventListener('DOMContentLoaded', function () {
        // Set default max for subtract when warehouse is selected
        const warehouseSelect = document.getElementById('warehouse_id');
        if (warehouseSelect.value && stockData[warehouseSelect.value]) {
            const currentQty = parseFloat(stockData[warehouseSelect.value].quantity) || 0;
            const adjustmentType = document.querySelector('input[name="adjustment_type"]:checked').value;
            if (adjustmentType === 'subtract') {
                document.getElementById('quantity').max = currentQty;
                document.getElementById('quantityHelp').innerHTML = `Maximum removable: ${numberFormat(currentQty, 0)} units`;
            }
        }
    });
</script>

<style>
    .stock-adjust-page .form-check {
        padding: 12px;
        border: 1px solid #e3e6f0;
        border-radius: 8px;
        transition: all 0.2s;
    }

    .stock-adjust-page .form-check:hover {
        background-color: #f8f9fc;
        border-color: #bac8f3;
    }

    .stock-adjust-page .form-check-input:checked+.form-check-label {
        color: #4e73df;
    }

    .stock-adjust-page .alert-info {
        background-color: #f8f9fc;
        border-color: #e3e6f0;
    }

    .stock-adjust-page .btn-group-toggle {
        width: 100%;
    }
</style>