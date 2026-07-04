// assets/js/sales/create.js
// POS Sale Creation JavaScript

let itemCounter = 0;
let searchTimeout = null;
let pendingSearchInput = null;

// Get config from window
const config = window.saleCreateConfig || {};
const companyCurrency = config.companyCurrency || 'RWF';
const apiBaseUrl = config.apiBaseUrl || '';
const defaultWarehouseId = config.defaultWarehouseId || '';
const locationsByWarehouse = config.locationsByWarehouse || {};

// Initialize when document is ready
$(document).ready(function() {
    console.log('POS Create Sale initialized');
    
    // Add first item row
    addNewItemRow();
    
    // Add item trigger
    $('#add-item-trigger').on('click', function() {
        addNewItemRow();
    });
    
    // Toggle notes
    $('#toggle-notes-btn').on('click', toggleNotes);
    
    // Save customer button
    $('#saveCustomerBtn').on('click', saveQuickCustomer);
    
    // Quick save product button
    $('#quickSaveProductBtn').on('click', saveQuickProduct);
    
    // Form submission handler
    $('#create-sale-form').on('submit', function(e) {
        e.preventDefault();
        prepareAndSubmitForm();
    });
    
    // Sync payment method with hidden form
    $('#payment_method').on('change', function() {
        $('#form_payment_method').val($(this).val());
    });
    $('#form_payment_method').val($('#payment_method').val());
    
    // Sync customer with hidden form
    $('#customer_id').on('change', function() {
        $('#form_customer_id').val($(this).val());
    });
    $('#form_customer_id').val($('#customer_id').val());
});

// Toggle invoice notes
function toggleNotes() {
    const wrapper = document.getElementById('invoice-notes-wrapper');
    const btnText = document.getElementById('notes-btn-text');
    if (wrapper.style.display === 'none') {
        wrapper.style.display = 'block';
        btnText.innerText = 'Hide Notes';
    } else {
        wrapper.style.display = 'none';
        btnText.innerText = 'Add Notes';
    }
}

// Add new item row
function addNewItemRow() {
    const template = document.getElementById('item-template');
    const clone = template.content.cloneNode(true);
    const itemRow = clone.querySelector('.item-row');
    
    // Replace INDEX placeholder
    const index = itemCounter++;
    replacePlaceholders(itemRow, index);
    
    // Add to container
    document.getElementById('items-list').appendChild(itemRow);
    
    // Attach event listeners
    attachItemEventListeners(itemRow);
    
    // Focus on search input
    const searchInput = itemRow.querySelector('.product-search-input');
    if (searchInput) searchInput.focus();
    
    // Update summaries
    updateAllSummaries();
}

// Replace INDEX placeholders
function replacePlaceholders(element, index) {
    if (element.attributes) {
        for (let attr of element.attributes) {
            if (attr.value && attr.value.includes('INDEX')) {
                attr.value = attr.value.replace(/INDEX/g, index);
            }
        }
    }
    
    if (element.tagName === 'INPUT' || element.tagName === 'SELECT' || element.tagName === 'TEXTAREA') {
        if (element.name && element.name.includes('INDEX')) {
            element.name = element.name.replace(/INDEX/g, index);
        }
    }
    
    if (element.children) {
        for (let child of element.children) {
            replacePlaceholders(child, index);
        }
    }
}

// Attach event listeners to an item row
function attachItemEventListeners(itemRow) {
    const searchInput = itemRow.querySelector('.product-search-input');
    const quantityInput = itemRow.querySelector('.quantity');
    const unitPriceInput = itemRow.querySelector('.unit-price');
    const taxRateInput = itemRow.querySelector('.tax-rate');
    const discountInput = itemRow.querySelector('.discount-percent');
    const warehouseSelect = itemRow.querySelector('.warehouse-select');
    const locationSelect = itemRow.querySelector('.location-select');
    const toggleBtn = itemRow.querySelector('.toggle-details-btn');
    const removeBtn = itemRow.querySelector('.remove-item');
    const quickAddLink = itemRow.querySelector('.quick-add-link');
    
    // Quick add link
    if (quickAddLink) {
        quickAddLink.addEventListener('click', function(e) {
            e.preventDefault();
            openQuickAdd(itemRow, searchInput);
        });
    }
    
    // Toggle details pane
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            const detailsPane = itemRow.querySelector('.details-pane');
            if (detailsPane.style.display === 'block') {
                detailsPane.style.display = 'none';
            } else {
                detailsPane.style.display = 'block';
            }
        });
    }
    
    // Remove item
    if (removeBtn) {
        removeBtn.addEventListener('click', function() {
            itemRow.remove();
            updateAllSummaries();
        });
    }
    
    // Search autocomplete
    if (searchInput) {
        const autocompleteResults = itemRow.querySelector('.autocomplete-results');
        const noResultPop = itemRow.querySelector('.no-result-pop');
        
        searchInput.addEventListener('input', function() {
            const term = this.value.trim();
            
            if (searchTimeout) clearTimeout(searchTimeout);
            
            if (term.length < 2) {
                if (autocompleteResults) autocompleteResults.style.display = 'none';
                if (noResultPop) noResultPop.style.display = 'none';
                return;
            }
            
            searchTimeout = setTimeout(() => {
                searchProducts(term, autocompleteResults, noResultPop, itemRow, searchInput);
            }, 300);
        });
        
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
            }
        });
    }
    
    // Quantity change
    if (quantityInput) {
        quantityInput.addEventListener('input', function() {
            updateItemLineTotal(itemRow);
            checkStockAvailability(itemRow);
            updateAllSummaries();
        });
    }
    
    // Unit price change
    if (unitPriceInput) {
        unitPriceInput.addEventListener('input', function() {
            updateItemLineTotal(itemRow);
            updateAllSummaries();
        });
    }
    
    // Tax rate change
    if (taxRateInput) {
        taxRateInput.addEventListener('input', function() {
            updateItemLineTotal(itemRow);
            updateAllSummaries();
        });
    }
    
    // Discount change
    if (discountInput) {
        discountInput.addEventListener('input', function() {
            updateItemLineTotal(itemRow);
            updateAllSummaries();
        });
    }
    
    // Warehouse change - update locations
    if (warehouseSelect) {
        warehouseSelect.addEventListener('change', function() {
            updateLocationOptions(locationSelect, this.value);
            checkStockAvailability(itemRow);
        });
    }
    
    // Initialize location options
    if (warehouseSelect && locationSelect && warehouseSelect.value) {
        updateLocationOptions(locationSelect, warehouseSelect.value);
    }
}

// Search products via AJAX
async function searchProducts(term, resultsContainer, noResultPop, itemRow, searchInput) {
    try {
        const url = `${apiBaseUrl}?page=sales/create&ajax=search_products&term=${encodeURIComponent(term)}`;
        console.log('Searching:', term);
        
        const response = await fetch(url);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const products = await response.json();
        console.log('Products found:', products.length);
        
        resultsContainer.innerHTML = '';
        
        if (!products || products.length === 0) {
            resultsContainer.style.display = 'none';
            if (noResultPop) {
                noResultPop.style.display = 'block';
                pendingSearchInput = { itemRow, searchInput };
            }
            return;
        }
        
        if (noResultPop) noResultPop.style.display = 'none';
        
        products.forEach(product => {
            const item = document.createElement('div');
            item.className = 'autocomplete-item';
            
            let displayHtml = `<strong>${escapeHtml(product.product_name)}</strong>`;
            if (product.variant_name && product.variant_name !== 'Standard') {
                displayHtml += ` - ${escapeHtml(product.variant_name)}`;
            }
            displayHtml += `<br><small class="text-muted">SKU: ${escapeHtml(product.sku)} | Stock: ${product.available_stock}</small>`;
            
            item.innerHTML = displayHtml;
            
            item.addEventListener('click', function() {
                selectProduct(itemRow, product, searchInput);
                resultsContainer.style.display = 'none';
            });
            
            resultsContainer.appendChild(item);
        });
        
        resultsContainer.style.display = 'block';
        
    } catch (error) {
        console.error('Search error:', error);
        if (noResultPop) {
            noResultPop.style.display = 'block';
            noResultPop.innerHTML = 'Search error. Please try again.';
        }
    }
}

// Select a product for the item row
function selectProduct(itemRow, product, searchInput) {
    const variantIdInput = itemRow.querySelector('.variant-id');
    const unitPriceInput = itemRow.querySelector('.unit-price');
    const taxRateInput = itemRow.querySelector('.tax-rate');
    const selectedInfo = itemRow.querySelector('.selected-info');
    const searchInputField = searchInput || itemRow.querySelector('.product-search-input');
    
    if (variantIdInput) variantIdInput.value = product.variant_id;
    if (unitPriceInput) unitPriceInput.value = product.selling_price.toFixed(2);
    if (taxRateInput) taxRateInput.value = product.tax_rate;
    
    if (selectedInfo) {
        let displayText = product.product_name;
        if (product.variant_name && product.variant_name !== 'Standard') {
            displayText += ` - ${product.variant_name}`;
        }
        selectedInfo.innerHTML = `<i class="fas fa-check-circle text-success me-1"></i>${escapeHtml(displayText)} (${escapeHtml(product.sku)})`;
    }
    
    if (searchInputField) {
        let displayName = product.product_name;
        if (product.variant_name && product.variant_name !== 'Standard') {
            displayName += ` - ${product.variant_name}`;
        }
        searchInputField.value = displayName;
    }
    
    updateItemLineTotal(itemRow);
    checkStockAvailability(itemRow);
    updateAllSummaries();
}

// Update line total for an item
function updateItemLineTotal(itemRow) {
    const quantity = parseFloat(itemRow.querySelector('.quantity')?.value) || 0;
    const unitPrice = parseFloat(itemRow.querySelector('.unit-price')?.value) || 0;
    const discountPercent = parseFloat(itemRow.querySelector('.discount-percent')?.value) || 0;
    const taxRate = parseFloat(itemRow.querySelector('.tax-rate')?.value) || 0;
    
    const subtotal = quantity * unitPrice;
    const discount = subtotal * (discountPercent / 100);
    const afterDiscount = subtotal - discount;
    const tax = afterDiscount * (taxRate / 100);
    const total = afterDiscount + tax;
    
    const lineTotalSpan = itemRow.querySelector('.line-total');
    if (lineTotalSpan) {
        lineTotalSpan.textContent = `${companyCurrency} ${total.toFixed(2)}`;
    }
}

// Check stock availability
async function checkStockAvailability(itemRow) {
    const variantId = itemRow.querySelector('.variant-id')?.value;
    const quantity = parseFloat(itemRow.querySelector('.quantity')?.value) || 0;
    const warehouseId = itemRow.querySelector('.warehouse-select')?.value;
    const stockWarning = itemRow.querySelector('.stock-warning');
    
    if (!variantId || quantity <= 0) return;
    
    try {
        const url = `${apiBaseUrl}?page=sales/create&ajax=check_stock&variant_id=${variantId}&warehouse_id=${warehouseId || defaultWarehouseId}`;
        const response = await fetch(url);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (stockWarning) {
            if (data.available_stock < quantity) {
                stockWarning.style.display = 'block';
                stockWarning.innerHTML = `<i class="fas fa-exclamation-triangle me-1"></i>Low stock! Available: ${data.available_stock}`;
            } else if (data.available_stock < 5) {
                stockWarning.style.display = 'block';
                stockWarning.innerHTML = `<i class="fas fa-info-circle me-1"></i>Only ${data.available_stock} left`;
            } else {
                stockWarning.style.display = 'none';
            }
        }
    } catch (error) {
        console.error('Stock check error:', error);
    }
}

// Update location dropdown based on warehouse
function updateLocationOptions(locationSelect, warehouseId) {
    if (!locationSelect) return;
    
    const locations = locationsByWarehouse;
    const warehouseLocations = locations[warehouseId] || [];
    
    locationSelect.innerHTML = '<option value="">Default Location</option>';
    warehouseLocations.forEach(loc => {
        const option = document.createElement('option');
        option.value = loc.id;
        let text = loc.location_code;
        if (loc.location_name) {
            text += ` - ${loc.location_name}`;
        }
        option.textContent = text;
        locationSelect.appendChild(option);
    });
}

// Update all summary totals
function updateAllSummaries() {
    let subtotal = 0;
    let totalDiscount = 0;
    let totalTax = 0;
    
    document.querySelectorAll('.item-row').forEach(itemRow => {
        const quantity = parseFloat(itemRow.querySelector('.quantity')?.value) || 0;
        const unitPrice = parseFloat(itemRow.querySelector('.unit-price')?.value) || 0;
        const discountPercent = parseFloat(itemRow.querySelector('.discount-percent')?.value) || 0;
        const taxRate = parseFloat(itemRow.querySelector('.tax-rate')?.value) || 0;
        
        const lineSubtotal = quantity * unitPrice;
        const lineDiscount = lineSubtotal * (discountPercent / 100);
        const afterDiscount = lineSubtotal - lineDiscount;
        const lineTax = afterDiscount * (taxRate / 100);
        
        subtotal += lineSubtotal;
        totalDiscount += lineDiscount;
        totalTax += lineTax;
    });
    
    const total = subtotal - totalDiscount + totalTax;
    
    const subtotalEl = document.getElementById('summary-subtotal');
    const discountEl = document.getElementById('summary-discount');
    const taxEl = document.getElementById('summary-tax');
    const totalEl = document.getElementById('summary-total');
    
    if (subtotalEl) subtotalEl.textContent = `${companyCurrency} ${subtotal.toFixed(2)}`;
    if (discountEl) discountEl.textContent = `${companyCurrency} ${totalDiscount.toFixed(2)}`;
    if (taxEl) taxEl.textContent = `${companyCurrency} ${totalTax.toFixed(2)}`;
    if (totalEl) totalEl.textContent = `${companyCurrency} ${total.toFixed(2)}`;
}

// Open quick add product modal
function openQuickAdd(itemRow, searchInput) {
    pendingSearchInput = { itemRow, searchInput };
    const modal = new bootstrap.Modal(document.getElementById('quickAddProductModal'));
    modal.show();
}

// Save quick product
async function saveQuickProduct() {
    const productName = document.getElementById('new-prod-name')?.value.trim();
    const sellingPrice = parseFloat(document.getElementById('new-prod-price')?.value);
    const initialStock = parseFloat(document.getElementById('new-prod-stock')?.value) || 0;
    
    if (!productName) {
        showNotification('Please enter product name', 'error');
        return;
    }
    
    if (isNaN(sellingPrice) || sellingPrice <= 0) {
        showNotification('Please enter a valid selling price', 'error');
        return;
    }
    
    const formData = new FormData();
    formData.append('product_name', productName);
    formData.append('selling_price', sellingPrice);
    formData.append('initial_stock', initialStock);
    
    try {
        const response = await fetch(`${apiBaseUrl}?page=sales/create&ajax=quick_product`, {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        
        if (result.success) {
            bootstrap.Modal.getInstance(document.getElementById('quickAddProductModal'))?.hide();
            
            document.getElementById('new-prod-name').value = '';
            document.getElementById('new-prod-price').value = '';
            document.getElementById('new-prod-stock').value = '0';
            
            if (pendingSearchInput && pendingSearchInput.itemRow) {
                const product = {
                    variant_id: result.variant_id,
                    product_name: result.product_name,
                    variant_name: result.variant_name,
                    selling_price: result.selling_price,
                    tax_rate: result.tax_rate,
                    sku: 'NEW-' + Date.now(),
                    available_stock: result.available_stock
                };
                selectProduct(pendingSearchInput.itemRow, product, pendingSearchInput.searchInput);
                pendingSearchInput = null;
            } else {
                addNewItemRow();
                const newItemRow = document.querySelector('.item-row:last-child');
                const product = {
                    variant_id: result.variant_id,
                    product_name: result.product_name,
                    variant_name: result.variant_name,
                    selling_price: result.selling_price,
                    tax_rate: result.tax_rate,
                    sku: 'NEW-' + Date.now(),
                    available_stock: result.available_stock
                };
                selectProduct(newItemRow, product, null);
            }
            
            showNotification('Product created successfully!', 'success');
        } else {
            showNotification('Error: ' + (result.error || 'Unknown error'), 'error');
        }
    } catch (error) {
        console.error('Quick product error:', error);
        showNotification('Error creating product. Please try again.', 'error');
    }
}

// Save quick customer
async function saveQuickCustomer() {
    const fullName = document.getElementById('cust_full_name')?.value.trim();
    
    if (!fullName) {
        showNotification('Please enter customer name', 'error');
        return;
    }
    
    const formData = new FormData(document.getElementById('quickCustomerForm'));
    formData.append('ajax', 'quick_customer');
    
    try {
        const response = await fetch(`${apiBaseUrl}?page=sales/create&ajax=quick_customer`, {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        
        if (result.success) {
            const customerSelect = document.getElementById('customer_id');
            const option = document.createElement('option');
            option.value = result.customer_id;
            option.textContent = result.full_name + (result.customer_code ? ` (${result.customer_code})` : '');
            customerSelect.appendChild(option);
            customerSelect.value = result.customer_id;
            $('#form_customer_id').val(result.customer_id);
            
            bootstrap.Modal.getInstance(document.getElementById('addCustomerModal'))?.hide();
            
            document.getElementById('cust_full_name').value = '';
            document.getElementById('cust_phone').value = '';
            document.getElementById('cust_email').value = '';
            document.getElementById('cust_address').value = '';
            document.getElementById('cust_city').value = '';
            document.getElementById('cust_tax_id').value = '';
            
            showNotification('Customer created successfully!', 'success');
        } else {
            showNotification('Error: ' + (result.error || 'Unknown error'), 'error');
        }
    } catch (error) {
        console.error('Quick customer error:', error);
        showNotification('Error creating customer. Please try again.', 'error');
    }
}

// Prepare and submit form
function prepareAndSubmitForm() {
    // Sync customer
    $('#form_customer_id').val($('#customer_id').val());
    
    // Sync payment
    $('#form_payment_method').val($('#payment_method').val());
    
    // Sync notes
    $('#form_notes').val($('#notes').val());
    
    // Build items array
    const items = [];
    document.querySelectorAll('.item-row').forEach((itemRow) => {
        const variantId = itemRow.querySelector('.variant-id')?.value;
        const quantity = itemRow.querySelector('.quantity')?.value;
        const unitPrice = itemRow.querySelector('.unit-price')?.value;
        const taxRate = itemRow.querySelector('.tax-rate')?.value;
        const discountPercent = itemRow.querySelector('.discount-percent')?.value;
        const warehouseId = itemRow.querySelector('.warehouse-select')?.value;
        const locationId = itemRow.querySelector('.location-select')?.value;
        const description = itemRow.querySelector('.description')?.value;
        
        if (variantId && quantity && quantity > 0 && unitPrice && unitPrice > 0) {
            items.push({
                variant_id: variantId,
                quantity: quantity,
                unit_price: unitPrice,
                tax_rate: taxRate || 18,
                discount_percent: discountPercent || 0,
                warehouse_id: warehouseId || defaultWarehouseId,
                location_id: locationId || null,
                description: description || ''
            });
        }
    });
    
    if (items.length === 0) {
        showNotification('Please add at least one item to the sale.', 'error');
        return;
    }
    
    // Convert to JSON string before setting the value
    $('#form_items').val(JSON.stringify(items));
    
    // Submit the form
    document.getElementById('create-sale-form').submit();
}

// Escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Show notification
function showNotification(message, type) {
    if (typeof window.showNotification === 'function') {
        window.showNotification(message, type);
    } else {
        console.log(message);
        alert(message);
    }
}