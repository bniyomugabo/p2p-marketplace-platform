<?php
// pages/products/templates/product_templates.php - Updated with multi-company support
?>

<!-- Variant Template -->
<template id="variantTemplate">
    <div class="variant-item border p-3 rounded mb-3" data-variant-index>
        <div class="d-flex justify-content-between align-items-center bg-light p-2 mb-3 rounded">
            <h6 class="mb-0">Variant <span class="variant-number">1</span></h6>
            <div>
                <button type="button" class="btn btn-sm btn-outline-danger remove-variant">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Variant Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control variant-name" name="variants[0][variant_name]" required
                    placeholder="e.g., Black, Large, 64GB">
                <div class="form-text">e.g., Color, Size, Capacity</div>
            </div>
            
            <div class="col-md-4 mb-3">
                <label class="form-label">SKU</label>
                <input type="text" class="form-control sku-input" name="variants[0][sku]" readonly>
                <div class="form-text small">Auto-generated Stock Keeping Unit</div>
            </div>
            
            <div class="col-md-4 mb-3">
                <label class="form-label">Barcode</label>
                <input type="text" class="form-control barcode-input" name="variants[0][barcode]" readonly>
                <div class="form-text small">Auto-generated EAN-13 barcode</div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-3 mb-3">
                <label class="form-label">Purchase Price</label>
                <div class="input-group">
                    <span class="input-group-text currency-symbol">RWF</span>
                    <input type="number" class="form-control purchase-price" name="variants[0][purchase_price]"
                        min="0" step="0.01" value="0">
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <label class="form-label">Selling Price</label>
                <div class="input-group">
                    <span class="input-group-text currency-symbol">RWF</span>
                    <input type="number" class="form-control selling-price" name="variants[0][selling_price]"
                        min="0" step="0.01" value="0">
                </div>
            </div>
            
            <div class="col-md-3 mb-3">
                <label class="form-label">Wholesale Price</label>
                <div class="input-group">
                    <span class="input-group-text currency-symbol">RWF</span>
                    <input type="number" class="form-control" name="variants[0][wholesale_price]" min="0"
                        step="0.01">
                </div>
                <div class="form-text small">Optional bulk purchase price</div>
            </div>
            
            <div class="col-md-3 mb-3">
                <label class="form-label">Tax Rate (%)</label>
                <div class="input-group">
                    <input type="number" class="form-control" name="variants[0][tax_rate]" min="0" max="100"
                        step="0.01" value="18.00">
                    <span class="input-group-text">%</span>
                </div>
            </div>
        </div>
        
        <div class="row mb-3">
            <div class="col-md-3">
                <label class="form-label">Reorder Level</label>
                <input type="number" class="form-control" name="variants[0][reorder_level]" min="0" value="0">
                <div class="form-text small">Alert when stock reaches this level</div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Max Stock Level</label>
                <input type="number" class="form-control" name="variants[0][max_stock_level]" min="0" value="0">
                <div class="form-text small">Maximum capacity</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Variant Image</label>
                <input type="file" class="form-control variant-image" name="variants[0][image]" accept="image/*">
                <div class="form-text small">Optional variant-specific image (max 5MB)</div>
                <div class="mt-2 variant-image-preview"></div>
            </div>
        </div>
        
        <!-- Variant Attributes -->
        <div class="variant-attributes mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <label class="form-label mb-0">Attributes (e.g., Color, Size)</label>
                <button type="button" class="btn btn-sm btn-outline-primary add-attribute">
                    <i class="fas fa-plus me-1"></i>Add Attribute
                </button>
            </div>
            <div class="attributes-container"></div>
            <div class="form-text small mt-1">Add attributes to describe this variant further</div>
        </div>
    </div>
</template>

<!-- Attribute Template -->
<template id="attributeTemplate">
    <div class="attribute-item row g-2 mb-2">
        <div class="col-md-5">
            <input type="text" class="form-control attribute-name" 
                   placeholder="Attribute name (e.g., Color, Size, Material)" required>
        </div>
        <div class="col-md-5">
            <input type="text" class="form-control attribute-value" 
                   placeholder="Attribute value (e.g., Black, Large, Cotton)" required>
        </div>
        <div class="col-md-2">
            <button type="button" class="btn btn-sm btn-outline-danger w-100 remove-attribute">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>
</template>

<!-- Stock Allocation Template - Updated with multi-warehouse support -->
<template id="stockAllocationTemplate">
    <div class="stock-allocation-item border p-3 rounded mb-3" data-variant-id="">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="mb-0">
                <i class="fas fa-warehouse me-2"></i>
                Stock Allocation for <span class="allocation-variant-name">Variant</span>
            </h6>
            <div>
                <button type="button" class="btn btn-sm btn-outline-success add-another-allocation me-2"
                    title="Add another allocation for this variant">
                    <i class="fas fa-plus"></i> Add More
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger remove-allocation"
                    title="Remove this allocation">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
        
        <input type="hidden" class="allocation-variant-id" name="stock_allocations[0][variant_id]" value="">
        
        <div class="row">
            <div class="col-md-4 mb-3">
                <label class="form-label">Warehouse <span class="text-danger">*</span></label>
                <select class="form-control allocation-warehouse" name="stock_allocations[0][warehouse_id]" required>
                    <option value="">Select Warehouse</option>
                    <?php foreach ($warehouses as $wh): ?>
                        <option value="<?php echo $wh['id']; ?>" data-warehouse-code="<?php echo htmlspecialchars($wh['warehouse_code']); ?>">
                            <?php echo htmlspecialchars($wh['warehouse_name']); ?>
                            <?php if ($wh['city']): ?>
                                (<?php echo htmlspecialchars($wh['city']); ?>)
                            <?php endif; ?>
                            <?php if ($wh['is_main']): ?>
                                - Main
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text small">Select the warehouse for this stock</div>
            </div>
            
            <div class="col-md-4 mb-3">
                <label class="form-label">Storage Location (Optional)</label>
                <select class="form-control allocation-location" name="stock_allocations[0][location_id]">
                    <option value="">Default Location</option>
                </select>
                <div class="form-text small">Specific rack, shelf, or bin within warehouse</div>
            </div>
            
            <div class="col-md-4 mb-3">
                <label class="form-label">Quantity <span class="text-danger">*</span></label>
                <input type="number" class="form-control allocation-quantity" name="stock_allocations[0][quantity]"
                    min="0" step="1" value="0" required>
                <div class="form-text small">Initial stock quantity (units)</div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Unit Cost (Purchase Price)</label>
                <div class="input-group">
                    <span class="input-group-text currency-symbol">RWF</span>
                    <input type="number" class="form-control allocation-unit-cost"
                        name="stock_allocations[0][unit_cost]" min="0" step="0.01" value="0">
                </div>
                <div class="form-text small">
                    Purchase cost per unit for this batch. If left empty, the variant's purchase price will be used.
                </div>
            </div>
            
            <div class="col-md-6 mb-3">
                <label class="form-label">Batch/Lot Number (Optional)</label>
                <input type="text" class="form-control allocation-batch" name="stock_allocations[0][batch_number]"
                    placeholder="e.g., BATCH-2024-001">
                <div class="form-text small">Track specific batches for quality control</div>
            </div>
        </div>
        
        <div class="mb-3">
            <label class="form-label">Notes</label>
            <textarea class="form-control allocation-notes" name="stock_allocations[0][notes]" rows="2"
                placeholder="Optional notes about this stock allocation (e.g., supplier info, quality notes, expiry date)"></textarea>
        </div>
        
        <div class="alert alert-info small mb-0">
            <i class="fas fa-info-circle me-2"></i>
            <strong>Note:</strong> Stock will be added to inventory upon product creation. 
            You can adjust stock later from the inventory management page.
        </div>
    </div>
</template>

<!-- Location Options Template (for dynamic location loading) -->
<template id="locationOptionsTemplate">
    <option value="">Default Location</option>
    <?php if (!empty($locations)): ?>
        <?php foreach ($locations as $loc): ?>
            <option value="<?php echo $loc['id']; ?>" data-warehouse-id="<?php echo $loc['warehouse_id']; ?>">
                <?php echo htmlspecialchars($loc['location_code']); ?>
                <?php if ($loc['location_name']): ?>
                    - <?php echo htmlspecialchars($loc['location_name']); ?>
                <?php endif; ?>
            </option>
        <?php endforeach; ?>
    <?php endif; ?>
</template>

<script>
// Helper function to update currency symbols based on company settings
function updateCurrencySymbols() {
    const currencySymbols = document.querySelectorAll('.currency-symbol');
    const companyCurrency = '<?php echo $_SESSION['company_currency'] ?? 'RWF'; ?>';
    
    currencySymbols.forEach(symbol => {
        symbol.textContent = companyCurrency;
    });
}

// Helper function to generate SKU
function generateSKU(productCode, variantName, variantIndex) {
    if (!productCode) return '';
    
    // Clean variant name: uppercase, remove special chars, take first 3 chars
    let cleanVariant = variantName ? variantName.toUpperCase().replace(/[^A-Z0-9]/g, '').substring(0, 3) : 'VAR';
    if (cleanVariant.length < 2) cleanVariant = 'VAR';
    
    return productCode + '-' + cleanVariant + '-' + String(variantIndex + 1).padStart(2, '0');
}

// Helper function to generate EAN-13 barcode (simplified)
function generateBarcode() {
    const prefix = '200';
    const random = String(Math.floor(Math.random() * 999999999)).padStart(9, '0');
    const base = prefix + random;
    const truncatedBase = base.substring(0, 12);
    
    let sum = 0;
    for (let i = 0; i < truncatedBase.length; i++) {
        const digit = parseInt(truncatedBase[i]);
        sum += (i % 2 === 0) ? digit : digit * 3;
    }
    const checkDigit = (10 - (sum % 10)) % 10;
    
    return truncatedBase + checkDigit;
}

// Helper function to load locations for a warehouse
async function loadLocationsForWarehouse(warehouseId, locationSelect) {
    if (!warehouseId) {
        locationSelect.innerHTML = '<option value="">Default Location</option>';
        return;
    }
    
    try {
        const response = await fetch(`${apiBaseUrl}/api/locations.php?warehouse_id=${warehouseId}&company_id=${companyId}`, {
            headers: {
                'X-CSRF-Token': csrfToken
            }
        });
        
        if (response.ok) {
            const locations = await response.json();
            locationSelect.innerHTML = '<option value="">Default Location</option>';
            locations.forEach(location => {
                const option = document.createElement('option');
                option.value = location.id;
                option.textContent = location.location_code + (location.location_name ? ` - ${location.location_name}` : '');
                locationSelect.appendChild(option);
            });
        }
    } catch (error) {
        console.error('Error loading locations:', error);
    }
}

// Export functions for use in main JS
window.productTemplates = {
    updateCurrencySymbols,
    generateSKU,
    generateBarcode,
    loadLocationsForWarehouse
};
</script>

<style>
    .variant-item, .stock-allocation-item {
        transition: all 0.2s ease;
        background: #fff;
    }
    
    .variant-item:hover, .stock-allocation-item:hover {
        box-shadow: 0 0.25rem 0.5rem rgba(0, 0, 0, 0.1);
    }
    
    .attribute-item {
        animation: fadeIn 0.2s ease;
    }
    
    .variant-image-preview img {
        max-width: 100px;
        max-height: 100px;
        object-fit: cover;
        border-radius: 4px;
    }
    
    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .is-invalid {
        border-color: #e74a3b;
    }
    
    .is-invalid:focus {
        border-color: #e74a3b;
        box-shadow: 0 0 0 0.2rem rgba(231, 74, 59, 0.25);
    }
    
    .invalid-feedback {
        display: block;
        font-size: 0.875rem;
        color: #e74a3b;
    }
</style>