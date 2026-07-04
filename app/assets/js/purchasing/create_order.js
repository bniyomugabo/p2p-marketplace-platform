// assets/js/purchasing/create_order.js

// Global variables
let itemCount = 0;
let currentItemRow = null;
let attributeCount = 0;

document.addEventListener('DOMContentLoaded', function () {
    console.log('DOM loaded, productDataset available:', typeof productDataset !== 'undefined');
    const itemsContainer = document.getElementById('items-container');
    const itemTemplate = document.getElementById('item-template');
    const addItemBtn = document.getElementById('add-item-btn');
    const createPoForm = document.getElementById('create-po-form');

    if (!itemsContainer || !itemTemplate) {
        console.error('Required elements not found');
        return;
    }

    // Add first item by default
    addItem();

    // Add item button
    if (addItemBtn) {
        addItemBtn.addEventListener('click', addItem);
    }

    // Form validation
    if (createPoForm) {
        createPoForm.addEventListener('submit', validateForm);
    }

    // Initialize modal handlers
    initModalHandlers();
});

function addItem() {
    const itemsContainer = document.getElementById('items-container');
    const itemTemplate = document.getElementById('item-template');
    
    if (!itemsContainer || !itemTemplate) return;
    
    // Clone the template content
    const templateContent = itemTemplate.content.cloneNode(true);

    // Create a wrapper div to easily query elements
    const wrapper = document.createElement('div');
    wrapper.appendChild(templateContent);
    const rowElement = wrapper.firstElementChild;

    // Update all name attributes with the current index
    rowElement.querySelectorAll('[name*="INDEX"]').forEach(el => {
        if (el.name) {
            el.name = el.name.replace(/INDEX/g, itemCount);
        }
    });

    // Setup the row with event listeners
    setupItemRow(rowElement);

    // Append to container
    itemsContainer.appendChild(rowElement);
    itemCount++;
}

function setupItemRow(row) {
    const searchInput = row.querySelector('.product-search');
    const searchResults = row.querySelector('.search-results');
    const variantIdInput = row.querySelector('.variant-id');
    const unitPriceInput = row.querySelector('.unit-price');
    const taxRateInput = row.querySelector('.tax-rate');
    const quantityInput = row.querySelector('.quantity');
    const removeBtn = row.querySelector('.remove-item');
    
    // Add new product/variant buttons
    const addProductBtn = row.querySelector('.add-product');
    const addVariantBtn = row.querySelector('.add-variant');
    
    if (addProductBtn) {
        addProductBtn.addEventListener('click', function(e) {
            e.preventDefault();
            currentItemRow = row;
            $('#addProductModal').modal('show');
        });
    }
    
    if (addVariantBtn) {
        addVariantBtn.addEventListener('click', function(e) {
            e.preventDefault();
            currentItemRow = row;
            $('#addVariantModal').modal('show');
        });
    }

    if (!searchInput || !searchResults || !variantIdInput) {
        console.error('Required elements not found in row');
        return;
    }

    // Search functionality
    let searchTimeout;
    searchInput.addEventListener('input', function () {
        clearTimeout(searchTimeout);
        const term = this.value.toLowerCase().trim();

        if (term.length < 2) {
            searchResults.style.display = 'none';
            return;
        }

        searchTimeout = setTimeout(() => {
            const filtered = productDataset.filter(p =>
                (p.name && p.name.toLowerCase().includes(term)) ||
                (p.sku && p.sku.toLowerCase().includes(term))
            ).slice(0, 10);

            displaySearchResults(filtered, searchResults, row);
        }, 300);
    });

    // Hide search results when clicking outside
    document.addEventListener('click', function (e) {
        if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
            searchResults.style.display = 'none';
        }
    });

    // Calculate on input change
    if (quantityInput) {
        quantityInput.addEventListener('input', () => calculateLineTotal(row));
    }
    if (unitPriceInput) {
        unitPriceInput.addEventListener('input', () => calculateLineTotal(row));
    }
    if (taxRateInput) {
        taxRateInput.addEventListener('input', () => calculateLineTotal(row));
    }

    // Remove button
    if (removeBtn) {
        removeBtn.addEventListener('click', function () {
            if (document.querySelectorAll('.item-row').length > 1) {
                row.remove();
                calculateTotals();
            } else {
                alert('You need at least one item.');
            }
        });
    }

    // Initial calculation
    calculateLineTotal(row);
}

function displaySearchResults(results, searchResults, row) {
    searchResults.innerHTML = '';

    if (results.length === 0) {
        searchResults.innerHTML = '<div class="list-group-item disabled">No products found</div>';
    } else {
        results.forEach(product => {
            const item = document.createElement('a');
            item.href = '#';
            item.className = 'list-group-item list-group-item-action';
            item.innerHTML = `
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong>${product.name || 'Unknown'}</strong>
                        <br>
                        <small>SKU: ${product.sku || 'N/A'}</small>
                    </div>
                    <div class="text-end">
                        <small>Purchase Price:</small>
                        <br>
                        <strong>RWF ${(product.purchase_price || 0).toLocaleString()}</strong>
                    </div>
                </div>
            `;

            item.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                selectProduct(product, row);
                searchResults.style.display = 'none';
            });

            searchResults.appendChild(item);
        });
    }

    searchResults.style.display = 'block';
}

function selectProduct(product, row) {
    const searchInput = row.querySelector('.product-search');
    const variantIdInput = row.querySelector('.variant-id');
    const unitPriceInput = row.querySelector('.unit-price');
    const taxRateInput = row.querySelector('.tax-rate');

    if (!variantIdInput) {
        console.error('Variant ID input not found');
        return;
    }

    variantIdInput.value = product.id || '';
    if (searchInput) searchInput.value = product.name || '';
    if (unitPriceInput) unitPriceInput.value = product.purchase_price || 0;
    if (taxRateInput) taxRateInput.value = product.tax_rate || 18;

    calculateLineTotal(row);
}

function calculateLineTotal(row) {
    const qtyInput = row.querySelector('.quantity');
    const priceInput = row.querySelector('.unit-price');
    const taxInput = row.querySelector('.tax-rate');
    const lineTotalSpan = row.querySelector('.line-total');

    if (!qtyInput || !priceInput || !taxInput || !lineTotalSpan) return;

    const qty = parseFloat(qtyInput.value) || 0;
    const price = parseFloat(priceInput.value) || 0;
    const tax = parseFloat(taxInput.value) || 0;

    const subtotal = qty * price;
    const taxAmount = subtotal * (tax / 100);
    const total = subtotal + taxAmount;

    lineTotalSpan.textContent = `Line Total: RWF ${Math.round(total).toLocaleString()}`;

    calculateTotals();
}

function calculateTotals() {
    let subtotal = 0, totalTax = 0;

    document.querySelectorAll('.item-row').forEach(row => {
        const qtyInput = row.querySelector('.quantity');
        const priceInput = row.querySelector('.unit-price');
        const taxInput = row.querySelector('.tax-rate');

        if (!qtyInput || !priceInput || !taxInput) return;

        const qty = parseFloat(qtyInput.value) || 0;
        const price = parseFloat(priceInput.value) || 0;
        const tax = parseFloat(taxInput.value) || 0;

        const lineSubtotal = qty * price;
        subtotal += lineSubtotal;
        totalTax += lineSubtotal * (tax / 100);
    });

    const total = subtotal + totalTax;

    const subtotalEl = document.getElementById('subtotal');
    const taxEl = document.getElementById('tax');
    const totalEl = document.getElementById('total');

    if (subtotalEl) subtotalEl.textContent = `RWF ${Math.round(subtotal).toLocaleString()}`;
    if (taxEl) taxEl.textContent = `RWF ${Math.round(totalTax).toLocaleString()}`;
    if (totalEl) totalEl.textContent = `RWF ${Math.round(total).toLocaleString()}`;
}

function validateForm(e) {
    const supplierId = document.getElementById('supplier_id').value;

    if (!supplierId) {
        e.preventDefault();
        alert('Please select a supplier.');
        return false;
    }

    let hasValidItem = false;
    document.querySelectorAll('.item-row').forEach(row => {
        const variantId = row.querySelector('.variant-id').value;
        const qty = parseFloat(row.querySelector('.quantity').value) || 0;

        if (variantId && qty > 0) {
            hasValidItem = true;
        }
    });

    if (!hasValidItem) {
        e.preventDefault();
        alert('Please add at least one valid item with quantity.');
        return false;
    }

    // Show loading state
    const submitBtn = e.submitter;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Creating...';
}

function initModalHandlers() {
    // Handle Add Product Form Submission
    const addProductForm = document.getElementById('addProductForm');
    if (addProductForm) {
        addProductForm.addEventListener('submit', handleAddProduct);
    }

    // Handle Add Variant Form Submission
    const addVariantForm = document.getElementById('addVariantForm');
    if (addVariantForm) {
        addVariantForm.addEventListener('submit', handleAddVariant);
    }

    // Generate SKU for variant
    const generateSkuBtn = document.getElementById('generateSkuBtn');
    if (generateSkuBtn) {
        generateSkuBtn.addEventListener('click', generateSku);
    }

    // Generate Barcode
    const generateBarcodeBtn = document.getElementById('generateBarcodeBtn');
    if (generateBarcodeBtn) {
        generateBarcodeBtn.addEventListener('click', generateBarcode);
    }

    // Attribute management for variant modal
    const addAttributeBtn = document.getElementById('add-attribute-btn');
    if (addAttributeBtn) {
        addAttributeBtn.addEventListener('click', addAttribute);
    }

    // Reset modals when hidden
    $('#addProductModal, #addVariantModal').on('hidden.bs.modal', function () {
        resetModal(this);
    });
}
function handleAddProduct(e) {
    e.preventDefault();
    
    console.log('Submitting add product form');
    
    const formData = new FormData(this);
    formData.append('action', 'add_product');

    // Use the route URL instead of direct file path
    const apiUrl = './api/products/create.php';

    console.log('Fetching URL:', apiUrl);

    fetch(apiUrl, {
        method: 'POST',
        body: formData,
        credentials: 'include'
    })
    .then(response => {
        console.log('Response status:', response.status);
        
        const contentType = response.headers.get('content-type');
        if (contentType && contentType.includes('application/json')) {
            return response.json();
        } else {
            return response.text().then(text => {
                console.error('Non-JSON response:', text.substring(0, 500));
                throw new Error('Server returned non-JSON response. Check error log.');
            });
        }
    })
    .then(data => {
        console.log('Response data:', data);
        
        if (data.success) {
            // Add new product to dataset
            const newProduct = {
                id: data.product_id,
                name: data.product_name,
                sku: 'PROD-' + data.product_id,
                purchase_price: 0,
                tax_rate: 18
            };
            productDataset.push(newProduct);
            console.log('Added to dataset:', newProduct);

            if (currentItemRow) {
                const searchInput = currentItemRow.querySelector('.product-search');
                const variantIdInput = currentItemRow.querySelector('.variant-id');
                const unitPriceInput = currentItemRow.querySelector('.unit-price');

                searchInput.value = data.product_name;
                variantIdInput.value = data.product_id;
                unitPriceInput.value = 0;

                calculateLineTotal(currentItemRow);
            }

            $('#addProductModal').modal('hide');
            this.reset();
            alert('Product added successfully!');
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        alert('Failed to add product: ' + error.message);
    });
}

function handleAddVariant(e) {
    e.preventDefault();

    console.log('Submitting add variant form');

    const attributes = [];
    document.querySelectorAll('#variant-attributes-container .attribute-item').forEach(item => {
        const name = item.querySelector('.attribute-name').value;
        const value = item.querySelector('.attribute-value').value;
        if (name && value) {
            attributes.push({ name, value });
        }
    });

    const formData = new FormData(this);
    formData.append('action', 'add_variant');
    formData.append('attributes', JSON.stringify(attributes));

    // Use the route URL instead of direct file path
    const apiUrl = './api/products/create.php';

    console.log('Fetching URL:', apiUrl);

    fetch(apiUrl, {
        method: 'POST',
        body: formData,
        credentials: 'include'
    })
    .then(response => {
        console.log('Response status:', response.status);
        
        const contentType = response.headers.get('content-type');
        if (contentType && contentType.includes('application/json')) {
            return response.json();
        } else {
            return response.text().then(text => {
                console.error('Non-JSON response:', text.substring(0, 500));
                throw new Error('Server returned non-JSON response. Check error log.');
            });
        }
    })
    .then(data => {
        console.log('Response data:', data);
        
        if (data.success) {
            const newVariant = {
                id: data.variant_id,
                name: data.product_name + ' - ' + data.variant_name,
                sku: data.sku,
                purchase_price: data.purchase_price || 0,
                tax_rate: data.tax_rate || 18
            };
            productDataset.push(newVariant);

            if (currentItemRow) {
                const searchInput = currentItemRow.querySelector('.product-search');
                const variantIdInput = currentItemRow.querySelector('.variant-id');
                const unitPriceInput = currentItemRow.querySelector('.unit-price');
                const taxRateInput = currentItemRow.querySelector('.tax-rate');

                searchInput.value = newVariant.name;
                variantIdInput.value = data.variant_id;
                unitPriceInput.value = data.purchase_price || 0;
                if (taxRateInput) taxRateInput.value = data.tax_rate || 18;

                calculateLineTotal(currentItemRow);
            }

            $('#addVariantModal').modal('hide');
            this.reset();
            
            const container = document.getElementById('variant-attributes-container');
            if (container) container.innerHTML = '';

            alert('Variant added successfully!');
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Fetch error:', error);
        alert('Failed to add variant: ' + error.message);
    });
}




// Helper function for notifications
function showNotification(message, type = 'info') {
    // You can use alert for now, but consider a better notification system
    alert(message);
    
    // Optional: You could also create a toast notification
    console.log(`[${type}] ${message}`);
}


function generateSku() {
    const productId = document.getElementById('variant_product_id').value;
    const variantName = document.getElementById('variant_name').value;
    
    if (productId && variantName) {
        // Get selected product text to extract product code
        const select = document.getElementById('variant_product_id');
        const selectedOption = select.options[select.selectedIndex];
        const productCode = selectedOption.text.match(/\(([^)]+)\)/)?.[1] || 'PROD';
        
        const timestamp = Date.now().toString().slice(-4);
        const sku = 'SKU-' + productCode + '-' + variantName.substring(0, 3).toUpperCase() + '-' + timestamp;
        document.getElementById('sku').value = sku;
    } else {
        alert('Please select product and enter variant name first');
    }
}

function generateBarcode() {
    // Generate a simple EAN-13 like barcode
    const prefix = '2' + new Date().getFullYear().toString().slice(-2);
    const random = Math.random().toString().slice(2, 12);
    const barcode = prefix + random;
    
    // Simple check digit calculation
    let sum = 0;
    for (let i = 0; i < 12; i++) {
        const digit = parseInt(barcode[i]);
        sum += (i % 2 === 0) ? digit : digit * 3;
    }
    const checkDigit = (10 - (sum % 10)) % 10;
    
    document.getElementById('barcode').value = barcode + checkDigit;
}

function addAttribute() {
    const template = document.getElementById('attribute-template');
    if (!template) return;
    
    const templateContent = template.content.cloneNode(true);
    const container = document.getElementById('variant-attributes-container');

    const removeBtn = templateContent.querySelector('.remove-attribute');
    if (removeBtn) {
        removeBtn.addEventListener('click', function () {
            this.closest('.attribute-item').remove();
        });
    }

    container.appendChild(templateContent);
    attributeCount++;
}

function resetModal(modal) {
    if (!modal) return;
    
    const form = modal.querySelector('form');
    if (form) form.reset();
    
    if (modal.id === 'addVariantModal') {
        const container = document.getElementById('variant-attributes-container');
        if (container) container.innerHTML = '';
        
        // Reset SKU and barcode fields
        const skuInput = document.getElementById('sku');
        const barcodeInput = document.getElementById('barcode');
        if (skuInput) skuInput.value = '';
        if (barcodeInput) barcodeInput.value = '';
    }
}

// Make functions globally available for onclick handlers
window.openAddProductModal = function(button) {
    currentItemRow = button.closest('.item-row');
    $('#addProductModal').modal('show');
};

window.openAddVariantModal = function(button) {
    currentItemRow = button.closest('.item-row');
    $('#addVariantModal').modal('show');
};