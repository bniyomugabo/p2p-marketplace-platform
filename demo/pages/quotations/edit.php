<?php
// pages/quotations/edit.php
declare(strict_types=1);

$pageTitle = 'Edit Quotation - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Quotation.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR', 'SEL'])) {
    SessionManager::flash('error', 'You do not have permission to edit quotations.');
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

// Only allow editing of draft quotations
if ($quotation['status'] !== 'draft') {
    SessionManager::flash('error', 'Only draft quotations can be edited.');
    header('Location: ' . route_url('quotations/view', ['id' => $quotationId]));
    exit;
}

// Generate CSRF token
$csrfToken = CSRF::generate();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
        SessionManager::flash('error', 'Invalid security token. Please try again.');
        header('Location: ?page=quotations/edit&id=' . $quotationId);
        exit;
    }

    try {
        // Basic validation
        if (empty($_POST['customer_name'])) {
            throw new Exception('Customer name is required.');
        }

        if (empty($_POST['items']) || !is_array($_POST['items'])) {
            throw new Exception('Please add at least one item to the quotation.');
        }

        // Validate email if provided
        if (!empty($_POST['customer_email']) && !filter_var($_POST['customer_email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please provide a valid email address.');
        }

        // Validate dates
        $quotationDate = $_POST['quotation_date'] ?? $quotation['quotation_date'];
        $validUntil = $_POST['valid_until'] ?? $quotation['valid_until'];

        if (strtotime($validUntil) < strtotime($quotationDate)) {
            throw new Exception('Valid until date must be after quotation date.');
        }

        // Start transaction
        $db->beginTransaction();

        // Update quotation data
        $updateData = [
            'customer_name' => trim($_POST['customer_name']),
            'customer_phone' => !empty($_POST['customer_phone']) ? trim($_POST['customer_phone']) : null,
            'customer_email' => !empty($_POST['customer_email']) ? trim($_POST['customer_email']) : null,
            'quotation_date' => $quotationDate,
            'valid_until' => $validUntil,
            'status' => $_POST['status'] ?? 'draft',
            'notes' => !empty($_POST['notes']) ? trim($_POST['notes']) : null,
            'terms_conditions' => !empty($_POST['terms_conditions']) ? trim($_POST['terms_conditions']) : null
        ];

        // Delete existing items (with company check)
        $deleteSql = "DELETE FROM quotation_items WHERE quotation_id = :quotation_id AND company_id = :company_id";
        $deleteStmt = $db->prepare($deleteSql);
        $deleteStmt->execute([
            'quotation_id' => $quotationId,
            'company_id' => $companyId
        ]);

        // Prepare and insert new items
        $items = [];
        $subtotal = 0;
        $totalDiscount = 0;
        $totalTax = 0;

        foreach ($_POST['items'] as $item) {
            if (empty($item['product_name']) || empty($item['quantity']) || $item['quantity'] <= 0) {
                continue;
            }

            $quantity = (float) $item['quantity'];
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $discountPercent = (float) ($item['discount_percent'] ?? 0);
            $taxRate = (float) ($item['tax_rate'] ?? 0);

            if ($unitPrice < 0) {
                throw new Exception("Unit price cannot be negative for item: " . htmlspecialchars($item['product_name']));
            }

            $lineSubtotal = $quantity * $unitPrice;
            $lineDiscount = $lineSubtotal * ($discountPercent / 100);
            $lineAfterDiscount = $lineSubtotal - $lineDiscount;
            $lineTax = $lineAfterDiscount * ($taxRate / 100);

            $subtotal += $lineSubtotal;
            $totalDiscount += $lineDiscount;
            $totalTax += $lineTax;

            $items[] = [
                'company_id' => $companyId,
                'quotation_id' => $quotationId,
                'product_name' => trim($item['product_name']),
                'description' => !empty($item['description']) ? trim($item['description']) : null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount_percent' => $discountPercent,
                'tax_rate' => $taxRate,
                'sort_order' => count($items)
            ];
        }

        if (empty($items)) {
            throw new Exception('No valid items to process.');
        }

        // Update quotation totals
        $updateData['subtotal'] = $subtotal;
        $updateData['discount_amount'] = $totalDiscount;
        $updateData['tax_amount'] = $totalTax;
        $updateData['total_amount'] = $subtotal - $totalDiscount + $totalTax;

        // Update quotation
        $quotationModel->update($quotationId, $updateData);

        // Insert new items
        $itemSql = "
            INSERT INTO quotation_items 
            (company_id, quotation_id, product_name, description, quantity, unit_price, discount_percent, tax_rate, sort_order)
            VALUES (:company_id, :quotation_id, :product_name, :description, :quantity, :unit_price, :discount_percent, :tax_rate, :sort_order)
        ";
        $itemStmt = $db->prepare($itemSql);

        foreach ($items as $item) {
            $itemStmt->execute($item);
        }

        // Log activity
        $activitySql = "
            INSERT INTO activity_log (company_id, user_id, action, entity_type, entity_id, old_data, new_data, created_at)
            VALUES (:company_id, :user_id, 'quotation_updated', 'quotation', :quotation_id, :old_data, :new_data, NOW())
        ";
        $activityStmt = $db->prepare($activitySql);
        $activityStmt->execute([
            'company_id' => $companyId,
            'user_id' => $userId,
            'quotation_id' => $quotationId,
            'old_data' => json_encode(['status' => $quotation['status']]),
            'new_data' => json_encode(['status' => $updateData['status'], 'items_count' => count($items)])
        ]);

        $db->commit();

        SessionManager::flash('success', 'Quotation updated successfully!');
        header('Location: ' . route_url('quotations/view', ['id' => $quotationId]));
        exit;

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Quotation update error for company {$companyId}: " . $e->getMessage());
        SessionManager::flash('error', 'Failed to update quotation: ' . $e->getMessage());
    }
}

// Get default terms if empty
$defaultTerms = "1. Payment is due within 30 days of invoice date.\n2. Goods remain property of seller until full payment is received.\n3. Any disputes must be raised within 7 days of receipt.\n4. Prices are in RWF and include VAT where applicable.";
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <a href="<?php echo route_url('quotations/view', ['id' => $quotationId]); ?>"
                        class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Quotation
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-edit me-2"></i>Edit Quotation
                    </h2>
                    <p class="mb-0 text-muted">Editing quotation #
                        <?php echo htmlspecialchars($quotation['quotation_number']); ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if ($flash = SessionManager::flash('error')): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo htmlspecialchars($flash); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
    <?php endif; ?>

    <!-- Form -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-edit me-2"></i>Edit Quotation Details
            </h6>
        </div>
        <div class="card-body">
            <form method="POST" id="edit-quotation-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                <!-- Customer Information -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <label for="customer_name" class="form-label">Customer Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="customer_name" name="customer_name"
                            value="<?php echo htmlspecialchars($quotation['customer_name']); ?>" required>
                    </div>

                    <div class="col-md-4">
                        <label for="customer_phone" class="form-label">Phone Number</label>
                        <input type="tel" class="form-control" id="customer_phone" name="customer_phone"
                            value="<?php echo htmlspecialchars($quotation['customer_phone'] ?? ''); ?>">
                    </div>

                    <div class="col-md-4">
                        <label for="customer_email" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="customer_email" name="customer_email"
                            value="<?php echo htmlspecialchars($quotation['customer_email'] ?? ''); ?>">
                        <small class="text-muted">Required if you want to send the quotation via email</small>
                    </div>
                </div>

                <!-- Quotation Dates -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <label for="quotation_date" class="form-label">Quotation Date</label>
                        <input type="date" class="form-control" id="quotation_date" name="quotation_date"
                            value="<?php echo $quotation['quotation_date']; ?>">
                    </div>

                    <div class="col-md-3">
                        <label for="valid_until" class="form-label">Valid Until</label>
                        <input type="date" class="form-control" id="valid_until" name="valid_until"
                            value="<?php echo $quotation['valid_until']; ?>">
                        <small class="text-muted">Offer expires after this date</small>
                    </div>

                    <div class="col-md-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-control" id="status" name="status">
                            <option value="draft" <?php echo $quotation['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="sent" <?php echo $quotation['status'] === 'sent' ? 'selected' : ''; ?>>Sent</option>
                        </select>
                        <small class="text-muted text-warning">⚠️ Changing to Sent will send to customer</small>
                    </div>
                </div>

                <!-- Items Section -->
                <h5 class="mb-3 border-bottom pb-2">
                    <i class="fas fa-shopping-cart me-2"></i>Items
                </h5>

                <div class="row mb-3">
                    <div class="col-12">
                        <button type="button" class="btn btn-primary" id="add-item-btn">
                            <i class="fas fa-plus me-2"></i>Add Item
                        </button>
                        <small class="text-muted ms-2">Add products, services, or custom items</small>
                    </div>
                </div>

                <div id="items-container">
                    <!-- Existing items will be loaded here via JavaScript -->
                </div>

                <!-- Summary -->
                <div class="row mt-4">
                    <div class="col-md-6 offset-md-6">
                        <div class="card bg-light">
                            <div class="card-body">
                                <table class="table table-sm mb-0">
                                    <tr>
                                        <th>Subtotal:</th>
                                        <td class="text-end" id="subtotal">
                                            <?php echo format_currency($quotation['subtotal'] ?? 0); ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Discount:</th>
                                        <td class="text-end text-danger" id="discount">
                                            <?php echo format_currency($quotation['discount_amount'] ?? 0); ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Tax:</th>
                                        <td class="text-end" id="tax">
                                            <?php echo format_currency($quotation['tax_amount'] ?? 0); ?>
                                        </td>
                                    </tr>
                                    <tr class="fw-bold">
                                        <th>Total:</th>
                                        <td class="text-end text-primary fs-5" id="total">
                                            <?php echo format_currency($quotation['total_amount'] ?? 0); ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Terms and Notes -->
                <div class="row mb-4 mt-3">
                    <div class="col-md-6">
                        <label for="terms_conditions" class="form-label">Terms & Conditions</label>
                        <textarea class="form-control" id="terms_conditions" name="terms_conditions" rows="4"
                            placeholder="Payment terms, delivery terms, warranty, etc."><?php
                            echo htmlspecialchars($quotation['terms_conditions'] ?? $defaultTerms);
                            ?></textarea>
                        <small class="text-muted">These will appear on the printed quotation</small>
                    </div>

                    <div class="col-md-6">
                        <label for="notes" class="form-label">Internal Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="4"
                            placeholder="Additional notes (not visible to customer)..."><?php
                            echo htmlspecialchars($quotation['notes'] ?? '');
                            ?></textarea>
                        <small class="text-muted">For internal reference only</small>
                    </div>
                </div>

                <!-- Submit Buttons -->
                <div class="row">
                    <div class="col-12">
                        <hr>
                        <div class="d-flex justify-content-between">
                            <a href="<?php echo route_url('quotations/view', ['id' => $quotationId]); ?>"
                                class="btn btn-outline-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Update Quotation
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Item Template -->
<template id="item-template">
    <div class="item-row border rounded p-3 mb-3 bg-light">
        <div class="row">
            <div class="col-md-4 mb-2">
                <label class="form-label">Product Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control product-name" name="items[INDEX][product_name]" required>
            </div>

            <div class="col-md-2 mb-2">
                <label class="form-label">Quantity <span class="text-danger">*</span></label>
                <input type="number" class="form-control quantity" name="items[INDEX][quantity]" min="0.01" step="0.01"
                    value="1" required>
            </div>

            <div class="col-md-2 mb-2">
                <label class="form-label">Unit Price <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text">RWF</span>
                    <input type="number" class="form-control unit-price" name="items[INDEX][unit_price]" min="0"
                        step="0.01" value="0" required>
                </div>
            </div>

            <div class="col-md-2 mb-2">
                <label class="form-label">Discount %</label>
                <input type="number" class="form-control discount" name="items[INDEX][discount_percent]" min="0"
                    max="100" step="0.1" value="0">
            </div>

            <div class="col-md-2 mb-2">
                <label class="form-label">Tax %</label>
                <input type="number" class="form-control tax-rate" name="items[INDEX][tax_rate]" min="0" max="100"
                    step="0.1" value="18">
            </div>
        </div>

        <div class="row">
            <div class="col-md-8 mb-2">
                <label class="form-label">Description (Optional)</label>
                <textarea class="form-control description" name="items[INDEX][description]" rows="1"
                    placeholder="Additional details about this item..."></textarea>
            </div>

            <div class="col-md-4 text-end">
                <div class="mt-4">
                    <small class="text-muted line-total me-3">Line Total: RWF 0</small>
                    <button type="button" class="btn btn-sm btn-outline-danger remove-item">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
    .item-row {
        transition: background-color 0.2s;
    }
    .item-row:hover {
        background-color: #f8f9fa !important;
    }
    .line-total {
        font-weight: 500;
    }
    textarea {
        resize: vertical;
    }
</style>

<!-- Embedded JavaScript -->
<script>
(function() {
    'use strict';
    
    console.log('Edit quotation form initialized');
    
    let itemCounter = 0;
    const itemsContainer = document.getElementById('items-container');
    const itemTemplate = document.getElementById('item-template');
    const addItemBtn = document.getElementById('add-item-btn');
    const form = document.getElementById('edit-quotation-form');
    
    // Existing items from PHP
    const existingItems = <?php echo json_encode($quotation['items']); ?>;
    
    if (!itemsContainer) {
        console.error('Items container not found!');
        return;
    }
    
    if (!itemTemplate) {
        console.error('Item template not found!');
        return;
    }
    
    // Function to add a new empty item row
    function addEmptyItemRow() {
        console.log('Adding new empty item row, index:', itemCounter);
        
        const templateContent = itemTemplate.content.cloneNode(true);
        const itemDiv = document.createElement('div');
        itemDiv.className = 'item-row border rounded p-3 mb-3 bg-light';
        itemDiv.setAttribute('data-index', itemCounter);
        
        while (templateContent.firstChild) {
            itemDiv.appendChild(templateContent.firstChild);
        }
        
        // Replace INDEX placeholders
        itemDiv.querySelectorAll('[name*="INDEX"]').forEach(el => {
            const oldName = el.getAttribute('name');
            const newName = oldName.replace(/INDEX/g, itemCounter.toString());
            el.setAttribute('name', newName);
        });
        
        setupRowEventListeners(itemDiv);
        itemsContainer.appendChild(itemDiv);
        
        itemCounter++;
        calculateAllTotals();
        
        return itemDiv;
    }
    
    // Function to add an item row with existing data
    function addExistingItemRow(item, index) {
        console.log('Adding existing item row, index:', index);
        
        const templateContent = itemTemplate.content.cloneNode(true);
        const itemDiv = document.createElement('div');
        itemDiv.className = 'item-row border rounded p-3 mb-3 bg-light';
        itemDiv.setAttribute('data-index', index);
        
        while (templateContent.firstChild) {
            itemDiv.appendChild(templateContent.firstChild);
        }
        
        // Replace INDEX placeholders
        itemDiv.querySelectorAll('[name*="INDEX"]').forEach(el => {
            const oldName = el.getAttribute('name');
            const newName = oldName.replace(/INDEX/g, index.toString());
            el.setAttribute('name', newName);
        });
        
        // Populate with existing data
        const productName = itemDiv.querySelector('.product-name');
        const quantity = itemDiv.querySelector('.quantity');
        const unitPrice = itemDiv.querySelector('.unit-price');
        const discount = itemDiv.querySelector('.discount');
        const taxRate = itemDiv.querySelector('.tax-rate');
        const description = itemDiv.querySelector('.description');
        
        if (productName) productName.value = item.product_name || '';
        if (quantity) quantity.value = item.quantity || 1;
        if (unitPrice) unitPrice.value = item.unit_price || 0;
        if (discount) discount.value = item.discount_percent || 0;
        if (taxRate) taxRate.value = item.tax_rate || 18;
        if (description) description.value = item.description || '';
        
        setupRowEventListeners(itemDiv);
        itemsContainer.appendChild(itemDiv);
        
        calculateRowTotal(itemDiv);
        
        return itemDiv;
    }
    
    // Setup event listeners for a row
    function setupRowEventListeners(row) {
        const quantity = row.querySelector('.quantity');
        const price = row.querySelector('.unit-price');
        const discount = row.querySelector('.discount');
        const tax = row.querySelector('.tax-rate');
        const removeBtn = row.querySelector('.remove-item');
        
        if (quantity) quantity.addEventListener('input', () => calculateRowTotal(row));
        if (price) price.addEventListener('input', () => calculateRowTotal(row));
        if (discount) discount.addEventListener('input', () => calculateRowTotal(row));
        if (tax) tax.addEventListener('input', () => calculateRowTotal(row));
        
        if (removeBtn) {
            removeBtn.addEventListener('click', () => {
                const rows = document.querySelectorAll('.item-row');
                if (rows.length > 1) {
                    row.remove();
                    calculateAllTotals();
                } else {
                    alert('You need at least one item. Clear the fields instead.');
                }
            });
        }
        
        calculateRowTotal(row);
    }
    
    // Calculate single row total
    function calculateRowTotal(row) {
        const quantity = parseFloat(row.querySelector('.quantity')?.value) || 0;
        const price = parseFloat(row.querySelector('.unit-price')?.value) || 0;
        const discountPercent = parseFloat(row.querySelector('.discount')?.value) || 0;
        const taxRate = parseFloat(row.querySelector('.tax-rate')?.value) || 0;
        
        const subtotal = quantity * price;
        const discountAmount = subtotal * (discountPercent / 100);
        const afterDiscount = subtotal - discountAmount;
        const taxAmount = afterDiscount * (taxRate / 100);
        const total = afterDiscount + taxAmount;
        
        const lineTotalSpan = row.querySelector('.line-total');
        if (lineTotalSpan) {
            lineTotalSpan.innerHTML = `<strong>Line Total:</strong> RWF ${Math.round(total).toLocaleString()}`;
        }
        
        calculateAllTotals();
        return total;
    }
    
    // Calculate all totals
    function calculateAllTotals() {
        let subtotal = 0;
        let totalDiscount = 0;
        let totalTax = 0;
        
        document.querySelectorAll('.item-row').forEach(row => {
            const quantity = parseFloat(row.querySelector('.quantity')?.value) || 0;
            const price = parseFloat(row.querySelector('.unit-price')?.value) || 0;
            const discountPercent = parseFloat(row.querySelector('.discount')?.value) || 0;
            const taxRate = parseFloat(row.querySelector('.tax-rate')?.value) || 0;
            
            const lineSubtotal = quantity * price;
            const lineDiscount = lineSubtotal * (discountPercent / 100);
            const lineAfterDiscount = lineSubtotal - lineDiscount;
            const lineTax = lineAfterDiscount * (taxRate / 100);
            
            subtotal += lineSubtotal;
            totalDiscount += lineDiscount;
            totalTax += lineTax;
        });
        
        const total = subtotal - totalDiscount + totalTax;
        
        const subtotalEl = document.getElementById('subtotal');
        const discountEl = document.getElementById('discount');
        const taxEl = document.getElementById('tax');
        const totalEl = document.getElementById('total');
        
        if (subtotalEl) subtotalEl.textContent = `RWF ${Math.round(subtotal).toLocaleString()}`;
        if (discountEl) discountEl.textContent = `RWF ${Math.round(totalDiscount).toLocaleString()}`;
        if (taxEl) taxEl.textContent = `RWF ${Math.round(totalTax).toLocaleString()}`;
        if (totalEl) totalEl.textContent = `RWF ${Math.round(total).toLocaleString()}`;
    }
    
    // Load existing items
    if (existingItems && existingItems.length > 0) {
        existingItems.forEach((item, idx) => {
            addExistingItemRow(item, idx);
            itemCounter = idx + 1;
        });
    } else {
        // Add one empty row if no items
        addEmptyItemRow();
        itemCounter = 1;
    }
    
    // Add item button click handler
    if (addItemBtn) {
        addItemBtn.addEventListener('click', () => {
            addEmptyItemRow();
        });
    }
    
    // Date validation
    const quotationDate = document.getElementById('quotation_date');
    const validUntil = document.getElementById('valid_until');
    
    if (quotationDate && validUntil) {
        quotationDate.addEventListener('change', function() {
            validUntil.min = this.value;
            if (validUntil.value && validUntil.value < this.value) {
                validUntil.value = this.value;
            }
        });
    }
    
    // Form submission validation
    if (form) {
        form.addEventListener('submit', function(e) {
            // Validate customer name
            const customerName = document.getElementById('customer_name');
            if (!customerName || !customerName.value.trim()) {
                e.preventDefault();
                alert('Please enter customer name.');
                customerName?.focus();
                return false;
            }
            
            // Validate email if provided
            const customerEmail = document.getElementById('customer_email');
            if (customerEmail && customerEmail.value.trim()) {
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(customerEmail.value.trim())) {
                    e.preventDefault();
                    alert('Please enter a valid email address.');
                    customerEmail.focus();
                    return false;
                }
            }
            
            // Validate at least one valid item
            let hasValidItem = false;
            document.querySelectorAll('.item-row').forEach(row => {
                const productName = row.querySelector('.product-name')?.value.trim();
                const quantity = parseFloat(row.querySelector('.quantity')?.value) || 0;
                if (productName && quantity > 0) {
                    hasValidItem = true;
                }
            });
            
            if (!hasValidItem) {
                e.preventDefault();
                alert('Please add at least one valid item with product name and quantity > 0.');
                return false;
            }
            
            // Validate dates
            if (validUntil && validUntil.value && quotationDate && quotationDate.value) {
                if (validUntil.value < quotationDate.value) {
                    e.preventDefault();
                    alert('Valid until date must be after quotation date.');
                    validUntil.focus();
                    return false;
                }
            }
            
            // Log form data for debugging
            const formData = new FormData(form);
            console.log('=== Submitting Edited Quotation ===');
            for (let pair of formData.entries()) {
                if (pair[0].includes('items')) {
                    console.log(pair[0] + ':', pair[1]);
                }
            }
            
            // Show loading state
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) {
                const originalText = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Updating...';
            }
        });
    }
    
    console.log('Edit quotation form ready. Found ' + (existingItems?.length || 0) + ' existing items.');
})();
</script>