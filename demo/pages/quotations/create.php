<?php
// pages/quotations/create.php
declare(strict_types=1);

$pageTitle = 'Create Quotation - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Quotation.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR', 'SEL'])) {
    SessionManager::flash('error', 'You do not have permission to create quotations.');
    header('Location: ' . route_url('quotations'));
    exit;
}

// Check if company context is set
if (!$companyId) {
    SessionManager::flash('error', 'Company context not found. Please log in again.');
    header('Location: ' . route_url('login'));
    exit;
}

// Initialize model with company ID
$quotationModel = new Quotation($companyId);

// Generate CSRF token
$csrfToken = CSRF::generate();

// Get saved form data from session if any (for validation errors)
$savedData = $_SESSION['quotation_form_data'] ?? null;
if ($savedData) {
    unset($_SESSION['quotation_form_data']);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
        SessionManager::flash('error', 'Invalid security token. Please try again.');
        header('Location: ?page=quotations/create');
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
        $quotationDate = $_POST['quotation_date'] ?? date('Y-m-d');
        $validUntil = $_POST['valid_until'] ?? date('Y-m-d', strtotime('+30 days'));

        if (strtotime($validUntil) < strtotime($quotationDate)) {
            throw new Exception('Valid until date must be after quotation date.');
        }

        // Get status from submit button
        $status = $_POST['status'] ?? 'draft';

        // Prepare quotation data
        $quotationData = [
            'customer_name' => trim($_POST['customer_name']),
            'customer_phone' => !empty($_POST['customer_phone']) ? trim($_POST['customer_phone']) : null,
            'customer_email' => !empty($_POST['customer_email']) ? trim($_POST['customer_email']) : null,
            'quotation_date' => $quotationDate,
            'valid_until' => $validUntil,
            'status' => $status,
            'notes' => !empty($_POST['notes']) ? trim($_POST['notes']) : null,
            'terms_conditions' => !empty($_POST['terms_conditions']) ? trim($_POST['terms_conditions']) : null,
            'created_by' => $userId
        ];

        // Prepare items array
        $items = [];
        foreach ($_POST['items'] as $index => $item) {
            if (empty($item['product_name']) || empty($item['quantity']) || $item['quantity'] <= 0) {
                continue;
            }

            $quantity = (float) $item['quantity'];
            $unitPrice = (float) ($item['unit_price'] ?? 0);

            if ($unitPrice < 0) {
                throw new Exception("Unit price cannot be negative for item: " . htmlspecialchars($item['product_name']));
            }

            $items[] = [
                'product_name' => trim($item['product_name']),
                'description' => !empty($item['description']) ? trim($item['description']) : null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount_percent' => (float) ($item['discount_percent'] ?? 0),
                'tax_rate' => (float) ($item['tax_rate'] ?? 0)
            ];
        }

        if (empty($items)) {
            throw new Exception('No valid items to process.');
        }

        // Debug log
        error_log("=== Items received ===");
        error_log("Number of items: " . count($items));
        foreach ($items as $idx => $item) {
            error_log("Item {$idx}: " . print_r($item, true));
        }

        // Create quotation
        $quotationId = $quotationModel->createQuotation($quotationData, $items);

        // Log activity
        $activitySql = "
            INSERT INTO activity_log (company_id, user_id, action, entity_type, entity_id, new_data, created_at)
            VALUES (:company_id, :user_id, 'quotation_created', 'quotation', :quotation_id, :new_data, NOW())
        ";
        $activityStmt = $db->prepare($activitySql);
        $activityStmt->execute([
            'company_id' => $companyId,
            'user_id' => $userId,
            'quotation_id' => $quotationId,
            'new_data' => json_encode(['quotation_number' => $quotationId, 'customer_name' => $quotationData['customer_name']])
        ]);

        $message = $status === 'sent'
            ? 'Quotation created and sent successfully!'
            : 'Quotation saved as draft successfully!';

        SessionManager::flash('success', $message);

        header('Location: ' . route_url('quotations/view', ['id' => $quotationId]));
        exit;

    } catch (Exception $e) {
        error_log("Quotation creation error for company {$companyId}: " . $e->getMessage());
        SessionManager::flash('error', 'Failed to create quotation: ' . $e->getMessage());

        // Save form data to session for repopulation
        $_SESSION['quotation_form_data'] = $_POST;

        header('Location: ?page=quotations/create');
        exit;
    }
}

// Get default terms & conditions
$defaultTerms = "1. Payment is due within 30 days of invoice date.\n2. Goods remain property of seller until full payment is received.\n3. Any disputes must be raised within 7 days of receipt.\n4. Prices are in RWF and include VAT where applicable.";
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <a href="<?php echo route_url('quotations'); ?>" class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Quotations
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-plus-circle me-2"></i>Create New Quotation
                    </h2>
                    <p class="mb-0 text-muted">Create a proforma invoice for your customer</p>
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
                <i class="fas fa-edit me-2"></i>Quotation Details
            </h6>
        </div>
        <div class="card-body">
            <form method="POST" id="create-quotation-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                <!-- Customer Information -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <label for="customer_name" class="form-label">Customer Name <span
                                class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="customer_name" name="customer_name"
                            value="<?php echo htmlspecialchars($savedData['customer_name'] ?? ''); ?>" required>
                    </div>

                    <div class="col-md-4">
                        <label for="customer_phone" class="form-label">Phone Number</label>
                        <input type="tel" class="form-control" id="customer_phone" name="customer_phone"
                            value="<?php echo htmlspecialchars($savedData['customer_phone'] ?? ''); ?>">
                    </div>

                    <div class="col-md-4">
                        <label for="customer_email" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="customer_email" name="customer_email"
                            value="<?php echo htmlspecialchars($savedData['customer_email'] ?? ''); ?>">
                        <small class="text-muted">Required if you want to send the quotation via email</small>
                    </div>
                </div>

                <!-- Quotation Dates -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <label for="quotation_date" class="form-label">Quotation Date</label>
                        <input type="date" class="form-control" id="quotation_date" name="quotation_date"
                            value="<?php echo $savedData['quotation_date'] ?? date('Y-m-d'); ?>">
                    </div>

                    <div class="col-md-3">
                        <label for="valid_until" class="form-label">Valid Until</label>
                        <input type="date" class="form-control" id="valid_until" name="valid_until"
                            value="<?php echo $savedData['valid_until'] ?? date('Y-m-d', strtotime('+30 days')); ?>">
                        <small class="text-muted">Offer expires after this date</small>
                    </div>

                    <div class="col-md-3">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-control" id="status" name="status">
                            <option value="draft" <?php echo ($savedData['status'] ?? '') === 'draft' ? 'selected' : ''; ?>>Draft</option>
                            <option value="sent" <?php echo ($savedData['status'] ?? '') === 'sent' ? 'selected' : ''; ?>>
                                Sent</option>
                        </select>
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
                    <!-- Initial item will be added by JavaScript -->
                </div>

                <!-- Summary -->
                <div class="row mt-4">
                    <div class="col-md-6 offset-md-6">
                        <div class="card bg-light">
                            <div class="card-body">
                                <table class="table table-sm mb-0">
                                    <tr>
                                        <th>Subtotal:</th>
                                        <td class="text-end" id="subtotal">RWF 0</td>
                                    </tr>
                                    <tr>
                                        <th>Discount:</th>
                                        <td class="text-end text-danger" id="discount">RWF 0</td>
                                    </tr>
                                    <tr>
                                        <th>Tax:</th>
                                        <td class="text-end" id="tax">RWF 0</td>
                                    </tr>
                                    <tr class="fw-bold">
                                        <th>Total:</th>
                                        <td class="text-end text-primary fs-5" id="total">RWF 0</td>
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
                            echo htmlspecialchars($savedData['terms_conditions'] ?? $defaultTerms);
                            ?></textarea>
                        <small class="text-muted">These will appear on the printed quotation</small>
                    </div>

                    <div class="col-md-6">
                        <label for="notes" class="form-label">Internal Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="4"
                            placeholder="Additional notes (not visible to customer)..."><?php
                            echo htmlspecialchars($savedData['notes'] ?? '');
                            ?></textarea>
                        <small class="text-muted">For internal reference only</small>
                    </div>
                </div>

                <!-- Submit Buttons -->
                <div class="row">
                    <div class="col-12">
                        <hr>
                        <div class="d-flex justify-content-between">
                            <a href="<?php echo route_url('quotations'); ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                            <div>
                                <button type="submit" name="status" value="draft" class="btn btn-secondary me-2">
                                    <i class="fas fa-save me-2"></i>Save as Draft
                                </button>
                                <button type="submit" name="status" value="sent" class="btn btn-primary">
                                    <i class="fas fa-paper-plane me-2"></i>Save & Send
                                </button>
                            </div>
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
                <input type="text" class="form-control product-name" name="items[INDEX][product_name]"
                    placeholder="Product or service name" required>
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
    (function () {
        'use strict';

        console.log('Quotation form initialized');

        let itemCounter = 0;
        const itemsContainer = document.getElementById('items-container');
        const itemTemplate = document.getElementById('item-template');
        const addItemBtn = document.getElementById('add-item-btn');
        const form = document.getElementById('create-quotation-form');

        if (!itemsContainer) {
            console.error('Items container not found!');
            return;
        }

        if (!itemTemplate) {
            console.error('Item template not found!');
            return;
        }

        // Function to add a new item row
        function addItemRow() {
            console.log('Adding new item row, index:', itemCounter);

            // Clone the template content
            const templateContent = itemTemplate.content.cloneNode(true);

            // Create a wrapper div
            const itemDiv = document.createElement('div');
            itemDiv.className = 'item-row border rounded p-3 mb-3 bg-light';
            itemDiv.setAttribute('data-index', itemCounter);

            // Move template content into the div
            while (templateContent.firstChild) {
                itemDiv.appendChild(templateContent.firstChild);
            }

            // Replace all INDEX placeholders with the current index
            itemDiv.querySelectorAll('[name*="INDEX"]').forEach(el => {
                const oldName = el.getAttribute('name');
                const newName = oldName.replace(/INDEX/g, itemCounter.toString());
                el.setAttribute('name', newName);
            });

            // Add event listeners to this row
            setupRowEventListeners(itemDiv);

            // Add to container
            itemsContainer.appendChild(itemDiv);

            // Focus on product name
            const productInput = itemDiv.querySelector('.product-name');
            if (productInput) {
                productInput.focus();
            }

            itemCounter++;

            // Recalculate totals
            calculateAllTotals();
        }

        // Setup event listeners for a row
        function setupRowEventListeners(row) {
            const quantity = row.querySelector('.quantity');
            const price = row.querySelector('.unit-price');
            const discount = row.querySelector('.discount');
            const tax = row.querySelector('.tax-rate');
            const removeBtn = row.querySelector('.remove-item');

            // Input event listeners for calculations
            if (quantity) quantity.addEventListener('input', () => calculateRowTotal(row));
            if (price) price.addEventListener('input', () => calculateRowTotal(row));
            if (discount) discount.addEventListener('input', () => calculateRowTotal(row));
            if (tax) tax.addEventListener('input', () => calculateRowTotal(row));

            // Remove button
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

            // Calculate initial total
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

        // Add first item on page load
        addItemRow();

        // Add item button click handler
        if (addItemBtn) {
            addItemBtn.addEventListener('click', addItemRow);
        }

        // Date validation
        const quotationDate = document.getElementById('quotation_date');
        const validUntil = document.getElementById('valid_until');

        if (quotationDate && validUntil) {
            quotationDate.addEventListener('change', function () {
                validUntil.min = this.value;
                if (validUntil.value && validUntil.value < this.value) {
                    validUntil.value = this.value;
                }
            });
        }

        // Form submission validation
        if (form) {
            form.addEventListener('submit', function (e) {
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

                // Log form data for debugging
                const formData = new FormData(form);
                console.log('=== Submitting Quotation ===');
                for (let pair of formData.entries()) {
                    if (pair[0].includes('items')) {
                        console.log(pair[0] + ':', pair[1]);
                    }
                }

                // Show loading state on submit button
                const submitBtn = e.submitter;
                if (submitBtn) {
                    const originalText = submitBtn.innerHTML;
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';
                }
            });
        }

        console.log('Quotation form ready. Items can be added dynamically.');
    })();
</script>