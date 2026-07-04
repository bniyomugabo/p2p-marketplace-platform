// assets/js/product/add_product.js
$(document).ready(function() {
    // ============================================
    // CSRF Token Management
    // ============================================
    
    let currentCsrfToken = $('input[name="csrf_token"]').val();

    function refreshCsrfToken(newToken) {
        if (newToken) {
            currentCsrfToken = newToken;
            $('input[name="csrf_token"]').val(newToken);
        }
    }

    // ============================================
    // API Configuration
    // ============================================
    
    const API = {
        addProduct: apiBaseUrl + 'api/products/add.php',
        getProduct: apiBaseUrl + 'api/products/get.php',
        removeProduct: apiBaseUrl + 'api/products/remove.php',
        clearProducts: apiBaseUrl + 'api/products/clear.php'
    };
    
    // ============================================
    // MODAL MANAGEMENT
    // ============================================
    
    let currentStep = 1;
    let variantCount = 0;
    let editingProductId = null;
    
    // Open add product modal
    $('#addProductBtn').click(function() {
        resetProductForm();
        $('#modalTitle').text('Add New Product');
        $('#product_id').val('');
        editingProductId = null;
        $('#productModal').modal('show');
    });
    
    // Open view product modal
    $(document).on('click', '.view-product', function() {
        const productId = $(this).data('id');
        viewProduct(productId);
    });
    
    // Regenerate product code
    $(document).on('click', '.regenerate-code', function() {
        regenerateCode();
    });
    
    // ============================================
    // Remove Product from Session via API
    // ============================================

    $(document).on('click', '.remove-product', function() {
        const $btn = $(this);
        const productId = $btn.data('id');
        const productName = $btn.data('name');
        const $row = $btn.closest('tr');
        
        console.log('Attempting to remove product:', { productId, productName });
        
        if (confirm(`Remove "${productName}" from the list? This will not delete it from the database.`)) {
            // Show loading state
            const originalHtml = $btn.html();
            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
            
            $.ajax({
                url: API.removeProduct,
                type: 'POST',
                data: {
                    product_id: productId,
                    csrf_token: currentCsrfToken
                },
                dataType: 'json',
                success: function(response) {
                    console.log('Remove response:', response);
                    
                    if (response.success) {
                        // Refresh CSRF token
                        if (response.new_csrf_token) {
                            refreshCsrfToken(response.new_csrf_token);
                        }
                        
                        // Remove the row from table with animation
                        $row.fadeOut(300, function() {
                            $(this).remove();
                            updateRowNumbers();
                            
                            // Update badge count
                            const remainingRows = $('#productsTable tbody tr:visible').length;
                            const $badge = $('.card-header .badge');
                            $badge.text(remainingRows + ' products');
                            
                            // Update the counter in the badge
                            if (remainingRows === 0) {
                                showEmptyState();
                                $('#clearAllBtn').fadeOut();
                            }
                            
                            // Refresh DataTable after removal
                            refreshDataTable();
                            
                            // Show success message
                            showNotification(response.message, 'success');
                        });
                    } else {
                        console.error('Remove failed:', response);
                        showNotification(response.message, 'danger');
                        
                        // Show debug info if available
                        if (response.debug) {
                            console.error('Debug info:', response.debug);
                            showNotification('Product ID mismatch. Please refresh the page.', 'warning');
                        }
                        
                        // Re-enable the button
                        $btn.prop('disabled', false).html(originalHtml);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', { xhr, status, error });
                    let errorMsg = 'Failed to remove product.';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg = xhr.responseJSON.message;
                    }
                    showNotification(errorMsg, 'danger');
                    $btn.prop('disabled', false).html(originalHtml);
                }
            });
        }
    });
    
    // ============================================
    // Clear All Products via API
    // ============================================
    
    $(document).on('click', '#clearAllBtn', function() {
        const productCount = $('#productsTable tbody tr:visible').length;
        
        if (confirm(`Clear all ${productCount} products from the list? This will not delete them from the database.`)) {
            const $btn = $(this);
            const originalHtml = $btn.html();
            $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>Clearing...');
            
            $.ajax({
                url: API.clearProducts,
                type: 'POST',
                data: {
                    csrf_token: currentCsrfToken
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Refresh CSRF token
                        refreshCsrfToken(response.new_csrf_token);
                        
                        // Clear all rows with fade out
                        $('#productsTable tbody').fadeOut(300, function() {
                            $(this).empty();
                            showEmptyState();
                            $(this).fadeIn();
                            // Refresh DataTable after clearing
                            refreshDataTable();
                        });
                        
                        // Update badge
                        $('.card-header .badge').text('0 products');
                        
                        // Hide the clear all button
                        $('#clearAllBtn').fadeOut();
                        
                        // Show success message
                        showNotification(response.message, 'success');
                    } else {
                        showNotification(response.message, 'danger');
                    }
                },
                error: function(xhr) {
                    let errorMsg = 'Failed to clear products.';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg = xhr.responseJSON.message;
                    }
                    showNotification(errorMsg, 'danger');
                },
                complete: function() {
                    $btn.prop('disabled', false).html(originalHtml);
                }
            });
        }
    });
    
    // ============================================
    // STEP NAVIGATION
    // ============================================
    
    function showStep(step) {
        $('.step-content').hide();
        $(`#step-${step}`).show();
        
        $('.step').removeClass('active completed');
        for (let i = 1; i <= 4; i++) {
            if (i < step) {
                $(`.step[data-step="${i}"]`).addClass('completed');
            } else if (i === step) {
                $(`.step[data-step="${i}"]`).addClass('active');
            }
        }
        
        $('#prevBtn').prop('disabled', step === 1);
        
        if (step === 4) {
            $('#nextBtn').hide();
            $('#submitBtn').show();
            updateReviewPanel();
        } else {
            $('#nextBtn').show();
            $('#submitBtn').hide();
        }
        
        if (step === 3) {
            refreshStockAllocations();
        }
        
        currentStep = step;
    }
    
    $('#nextBtn').click(function() {
        if (currentStep === 1) {
            if (!$('#product_name').val() || !$('#category_id').val()) {
                showNotification('Please fill in all required fields.', 'danger');
                return;
            }
            showStep(2);
        } else if (currentStep === 2) {
            let valid = true;
            $('.variant-item .variant-name').each(function() {
                if (!$(this).val().trim()) {
                    valid = false;
                    $(this).addClass('is-invalid');
                }
            });
            
            if (!valid) {
                showNotification('Please fill in Variant Name for all variants.', 'danger');
                return;
            }
            showStep(3);
        } else if (currentStep === 3) {
            showStep(4);
        }
    });
    
    $('#prevBtn').click(function() {
        if (currentStep > 1) {
            showStep(currentStep - 1);
        }
    });
    
    // ============================================
    // VARIANTS MANAGEMENT
    // ============================================
    
    $('#addVariantBtn').click(function() {
        addVariant();
        $('#defaultVariant').hide();
    });
    
    function addVariant(variantData = null) {
        variantCount++;
        const template = $('#variantTemplate').html();
        let variantHtml = template.replace(/variants\[0\]/g, `variants[${variantCount}]`);
        
        const $variant = $(variantHtml);
        $variant.attr('data-variant-index', variantCount);
        $variant.find('.variant-number').text(variantCount);
        
        const productCode = $('#product_code').val() || 'PROD';
        $variant.find('.sku-input').val(`SKU-${productCode}-${String(variantCount).padStart(2, '0')}`);
        $variant.find('.barcode-input').val(generateBarcode());
        
        $variant.find('.purchase-price').on('blur', function() {
            const purchasePrice = parseFloat($(this).val()) || 0;
            const sellingInput = $(this).closest('.variant-item').find('.selling-price');
            
            if (purchasePrice > 0 && (!sellingInput.val() || sellingInput.val() === '0')) {
                sellingInput.val((purchasePrice * 1.3).toFixed(2));
            }
        });
        
        $variant.find('.remove-variant').click(function() {
            if (confirm('Remove this variant?')) {
                $(this).closest('.variant-item').fadeOut(300, function() {
                    $(this).remove();
                    variantCount--;
                    updateVariantIndices();
                    refreshStockAllocations();
                    
                    if (variantCount === 0) {
                        $('#defaultVariant').fadeIn();
                    }
                });
            }
        });
        
        $variant.find('.add-attribute').click(function() {
            const $attributesContainer = $(this).closest('.variant-attributes').find('.attributes-container');
            addAttributeRow($attributesContainer);
        });
        
        $variant.find('.variant-image').change(function(e) {
            const file = e.target.files[0];
            const $preview = $(this).closest('.variant-item').find('.variant-image-preview');
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    $preview.html(`<img src="${e.target.result}" class="img-thumbnail" style="max-height: 100px;">`);
                };
                reader.readAsDataURL(file);
            }
        });
        
        if (variantData) {
            populateVariantData($variant, variantData);
        }
        
        $('#variantsContainer').append($variant);
        updateVariantIndices();
        refreshStockAllocations();
    }
    
    function addAttributeRow($container) {
        const template = $('#attributeTemplate').html();
        const $attribute = $(template);
        
        $attribute.find('.remove-attribute').click(function() {
            $(this).closest('.attribute-item').fadeOut(200, function() {
                $(this).remove();
            });
        });
        
        $container.append($attribute);
    }
    
    function updateVariantIndices() {
        $('#variantsContainer .variant-item').each(function(index) {
            const newIndex = index;
            $(this).attr('data-variant-index', newIndex);
            $(this).find('.variant-number').text(newIndex + 1);
            
            $(this).find('input, select, textarea').each(function() {
                const name = $(this).attr('name');
                if (name && name.includes('variants[')) {
                    const newName = name.replace(/variants\[\d+\]/g, `variants[${newIndex}]`);
                    $(this).attr('name', newName);
                }
            });
        });
        
        variantCount = $('#variantsContainer .variant-item').length;
    }
    
    function populateVariantData($variant, data) {
        $variant.find('.variant-name').val(data.variant_name);
        $variant.find('.sku-input').val(data.sku);
        $variant.find('.barcode-input').val(data.barcode);
        $variant.find('.purchase-price').val(data.purchase_price);
        $variant.find('.selling-price').val(data.selling_price);
        $variant.find('[name*="wholesale_price"]').val(data.wholesale_price);
        $variant.find('[name*="tax_rate"]').val(data.tax_rate);
        $variant.find('[name*="reorder_level"]').val(data.reorder_level);
        $variant.find('[name*="max_stock_level"]').val(data.max_stock_level);
        
        // Load attributes if any
        if (data.attributes_list) {
            const attributes = data.attributes_list.split(', ');
            const $attributesContainer = $variant.find('.attributes-container');
            $attributesContainer.empty();
            
            attributes.forEach(attr => {
                if (attr && attr.includes(':')) {
                    const [name, value] = attr.split(': ');
                    if (name && value) {
                        addAttributeRow($attributesContainer);
                        const $lastRow = $attributesContainer.find('.attribute-item:last');
                        $lastRow.find('.attribute-name').val(name);
                        $lastRow.find('.attribute-value').val(value);
                    }
                }
            });
        }
    }
    
    // ============================================
    // STOCK ALLOCATIONS MANAGEMENT
    // ============================================
    
    function refreshStockAllocations() {
        const $variants = $('#variantsContainer .variant-item');
        const $container = $('#stockAllocationsContainer');
        $container.empty();
        
        // Check if there are variants
        if ($variants.length === 0) {
            // Simple product - create stock allocation for default variant
            addStockAllocation('default', 'Standard (Default)');
        } else {
            // Product with variants - create allocations for each variant
            $variants.each(function(index) {
                const variantName = $(this).find('.variant-name').val() || `Variant ${index + 1}`;
                addStockAllocation(index, variantName);
            });
        }
    }
    
    function addStockAllocation(variantId, variantName) {
        const uniqueId = `variant_${variantId}_${Date.now()}_${Math.random()}`;
        const template = $('#stockAllocationTemplate').html();
        let allocationHtml = template.replace(/stock_allocations\[0\]/g, `stock_allocations[${uniqueId}]`);
        
        const $allocation = $(allocationHtml);
        $allocation.attr('data-variant-id', variantId);
        $allocation.find('.allocation-variant-name').text(variantName);
        $allocation.find('.allocation-variant-id').val(variantId);
        
        $allocation.find('.allocation-warehouse').on('change', function() {
            const warehouseId = $(this).val();
            const locationSelect = $(this).closest('.stock-allocation-item').find('.allocation-location');
            
            locationSelect.empty().append('<option value="">Select Location (Optional)</option>');
            
            if (warehouseId && window.locations) {
                const filteredLocations = window.locations.filter(loc => loc.warehouse_id == warehouseId);
                filteredLocations.forEach(loc => {
                    locationSelect.append(`<option value="${loc.id}">${loc.location_name} (${loc.location_code})</option>`);
                });
            }
        });
        
        $allocation.find('.add-another-allocation').click(function() {
            addStockAllocation(variantId, variantName);
        });
        
        $allocation.find('.remove-allocation').click(function() {
            $(this).closest('.stock-allocation-item').fadeOut(300, function() {
                $(this).remove();
                updateReviewPanel();
            });
        });
        
        $('#stockAllocationsContainer').append($allocation);
    }
    
    // ============================================
    // REVIEW PANEL
    // ============================================
    
    function updateReviewPanel() {
        $('#review-product-code').text($('#product_code').val() || '-');
        $('#review-product-name').text($('#product_name').val() || '-');
        $('#review-category').text($('#category_id option:selected').text() || '-');
        $('#review-brand').text($('#brand').val() || '-');
        $('#review-uom').text($('#unit_of_measure option:selected').text() || '-');
        
        const $variants = $('#variantsContainer .variant-item');
        const variantsHtml = [];
        
        if ($variants.length === 0) {
            variantsHtml.push('<p class="text-muted">Simple product (no variants)</p>');
        } else {
            $variants.each(function(index) {
                const name = $(this).find('.variant-name').val() || 'Unnamed';
                const purchasePrice = $(this).find('.purchase-price').val() || 0;
                const sellingPrice = $(this).find('.selling-price').val() || 0;
                
                variantsHtml.push(`
                    <div class="border-bottom pb-2 mb-2">
                        <strong>${index + 1}. ${escapeHtml(name)}</strong><br>
                        <small>Purchase: ${formatCurrency(purchasePrice)} | 
                        Selling: ${formatCurrency(sellingPrice)}</small>
                    </div>
                `);
            });
        }
        
        $('#reviewVariantsList').html(variantsHtml.join(''));
        
        const $allocations = $('#stockAllocationsContainer .stock-allocation-item');
        const stockHtml = [];
        
        if ($allocations.length === 0) {
            stockHtml.push('<p class="text-muted">No stock allocations (can add later)</p>');
        } else {
            $allocations.each(function() {
                const variantName = $(this).find('.allocation-variant-name').text();
                const warehouse = $(this).find('.allocation-warehouse option:selected').text() || 'Not selected';
                const quantity = $(this).find('.allocation-quantity').val() || 0;
                const location = $(this).find('.allocation-location option:selected').text() || 'No location';
                
                if (quantity > 0) {
                    stockHtml.push(`
                        <div class="border-bottom pb-2 mb-2">
                            <strong>${variantName}</strong><br>
                            <small>Warehouse: ${escapeHtml(warehouse)} | Location: ${escapeHtml(location)} | 
                            Quantity: ${formatNumber(quantity)}</small>
                        </div>
                    `);
                }
            });
        }
        
        if (stockHtml.length === 0) {
            stockHtml.push('<p class="text-muted">No valid stock allocations (quantity > 0)</p>');
        }
        
        $('#reviewStockList').html(stockHtml.join(''));
    }
    
    // ============================================
    // FORM SUBMISSION - Using API
    // ============================================
    
    $('#productForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        formData.append('csrf_token', currentCsrfToken);
        
        // Check if there are variants
        const hasVariants = $('#variantsContainer .variant-item').length > 0;
        formData.append('has_variants', hasVariants ? '1' : '0');
        
        const $submitBtn = $('#submitBtn');
        const originalText = $submitBtn.html();
        $submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>Adding...');
        
        $.ajax({
            url: API.addProduct,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // Update CSRF token for next request
                    refreshCsrfToken(response.new_csrf_token);
                    
                    showNotification(response.message, 'success');
                    
                    // Add new row to the table
                    addProductToTable(response.product);
                    
                    // Show clear all button if products exist
                    if ($('#productsTable tbody tr').length > 0 && $('#clearAllBtn').length === 0) {
                        addClearAllButton();
                    }
                    
                    // Reset form and close modal
                    $('#productModal').modal('hide');
                    resetProductForm();
                    
                    // Ask if user wants to add another product
                    if (confirm('Product added successfully! Do you want to add another product?')) {
                        $('#addProductBtn').click();
                    }
                } else {
                    showNotification(response.message, 'danger');
                }
                $submitBtn.prop('disabled', false).html(originalText);
            },
            error: function(xhr, status, error) {
                let errorMessage = 'An error occurred while saving the product.';
                if (xhr.responseJSON && xhr.responseJSON.message) {
                    errorMessage = xhr.responseJSON.message;
                }
                showNotification(errorMessage, 'danger');
                $submitBtn.prop('disabled', false).html(originalText);
            }
        });
    });
    
    function addProductToTable(product) {
        // Remove empty state if exists
        const firstRow = $('#productsTable tbody tr:first');
        if (firstRow.length && firstRow.find('td').attr('colspan')) {
            $('#productsTable tbody').empty();
        }
        
        const rowCount = $('#productsTable tbody tr').length;
        const newRow = `
            <tr>
                <td class="text-center">${rowCount + 1}</td>
                <td><code>${escapeHtml(product.product_code)}</code></td>
                <td><strong>${escapeHtml(product.product_name)}</strong></td>
                <td>${escapeHtml(product.brand || '-')}</td>
                <td>${escapeHtml(product.category_name)}</td>
                <td class="text-center"><span class="badge bg-info">${product.variant_count}</span></td>
                <td>${new Date().toLocaleTimeString()} </td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-info view-product" 
                            data-id="${product.id}" title="View Product">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-danger remove-product" 
                            data-id="${product.id}" 
                            data-name="${escapeHtml(product.product_name)}"
                            title="Remove from list">
                        <i class="fas fa-trash"></i>
                    </button>
                 </td>
             </tr>
             ?>
        `;
        
        $('#productsTable tbody').append(newRow);
        
        // Update badge count
        const productCount = $('#productsTable tbody tr').length;
        $('.card-header .badge').text(productCount + ' products');
        
        // Refresh DataTable after adding row
        refreshDataTable();
        
        // Show the info alert if it was hidden
        if ($('.alert-info').length && productCount > 0) {
            $('.alert-info').fadeIn();
        }
    }
    
    function addClearAllButton() {
        if ($('#clearAllBtn').length === 0) {
            const clearButton = `
                <button type="button" class="btn btn-danger ms-2" id="clearAllBtn">
                    <i class="fas fa-trash-alt me-2"></i>Clear All
                </button>
            `;
            $('.d-flex.justify-content-between .btn-primary').after(clearButton);
        }
    }
    
    // ============================================
    // VIEW PRODUCT - Using API
    // ============================================
    
    function viewProduct(productId) {
        $('#viewProductModal').modal('show');
        $('#viewProductContent').html(`
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>
        `);
        
        $.ajax({
            url: API.getProduct,
            type: 'GET',
            data: { id: productId, csrf_token: currentCsrfToken },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    displayProductDetails(response.data);
                    if (response.new_csrf_token) {
                        refreshCsrfToken(response.new_csrf_token);
                    }
                } else {
                    $('#viewProductContent').html(`<div class="alert alert-danger">${response.message}</div>`);
                }
            },
            error: function() {
                $('#viewProductContent').html(`<div class="alert alert-danger">Failed to load product details.</div>`);
            }
        });
    }
    
    function displayProductDetails(product) {
        let variantsHtml = '';
        if (product.variants && product.variants.length > 0) {
            product.variants.forEach(variant => {
                variantsHtml += `
                    <div class="border-bottom mb-3 pb-3">
                        <h6>${escapeHtml(variant.variant_name)}</h6>
                        <div class="row small">
                            <div class="col-md-3">SKU: ${escapeHtml(variant.sku)}</div>
                            <div class="col-md-3">Barcode: ${escapeHtml(variant.barcode)}</div>
                            <div class="col-md-3">Purchase: ${formatCurrency(variant.purchase_price)}</div>
                            <div class="col-md-3">Selling: ${formatCurrency(variant.selling_price)}</div>
                        </div>
                        ${variant.attributes_list ? `<div class="row small"><div class="col-12">Attributes: ${escapeHtml(variant.attributes_list)}</div></div>` : ''}
                    </div>
                `;
            });
        } else {
            variantsHtml = '<p class="text-muted">Simple product (no variants)</p>';
        }
        
        const html = `
            <div class="row">
                <div class="col-md-6">
                    <h6>Product Information</h6>
                    <table class="table table-sm">
                        <tr><th>Code:</th><td>${escapeHtml(product.product_code)}</div></div>
                        <tr><th>Name:</th><td>${escapeHtml(product.product_name)}</div></div>
                        <tr><th>Category:</th><td>${escapeHtml(product.category_name || '-')}</div></div>
                        <tr><th>Brand:</th><td>${escapeHtml(product.brand || '-')}</div></div>
                        <tr><th>Unit of Measure:</th><td>${escapeHtml(product.unit_of_measure)}</div></div>
                        <tr><th>Description:</th><td>${escapeHtml(product.description || '-')}</div></div>
                     </>
                </div>
                <div class="col-md-6">
                    <h6>Stock Information</h6>
                    <table class="table table-sm">
                        <tr><th>Total Stock:</th><td>${formatNumber(product.total_stock || 0)}</div></div>
                        <tr><th>Variants:</th><td>${product.variant_count || 0}</div></div>
                        <tr><th>Status:</th><td>${product.is_active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'}</div></div>
                        <tr><th>Created:</th><td>${product.created_at || '-'}</div></div>
                     </>
                </div>
            </div>
            <hr>
            <h6>Variants</h6>
            ${variantsHtml}
        `;
        
        $('#viewProductContent').html(html);
    }
    
    // ============================================
    // HELPER FUNCTIONS
    // ============================================
    
    function updateRowNumbers() {
        $('#productsTable tbody tr').each(function(index) {
            $(this).find('td:first').text(index + 1);
        });
        // Refresh DataTable after updating row numbers
        refreshDataTable();
    }
    
    function showEmptyState() {
        const emptyRow = `
            <tr>
                <td colspan="8" class="text-center text-muted py-5">
                    <i class="fas fa-box-open fa-3x mb-3 d-block"></i>
                    <p>No products added yet. Click "Add New Product" to get started.</p>
                 </div
             ?>
        `;
        $('#productsTable tbody').html(emptyRow);
        // Destroy DataTable when showing empty state
        if ($.fn.DataTable && $.fn.DataTable.isDataTable('#productsTable')) {
            $('#productsTable').DataTable().destroy();
            $('#productsTable').removeClass('dataTable');
        }
    }
    
    function resetProductForm() {
        $('#productForm')[0].reset();
        $('#variantsContainer').empty();
        $('#stockAllocationsContainer').empty();
        $('#image-preview').empty();
        $('#product_code').val(generateProductCode());
        variantCount = 0;
        currentStep = 1;
        $('#defaultVariant').show();
        showStep(1);
        $('.is-invalid').removeClass('is-invalid');
    }
    
    function regenerateCode() {
        $('#product_code').val(generateProductCode());
    }
    
    function generateProductCode() {
        const prefix = 'PROD';
        const year = new Date().getFullYear().toString().slice(-2);
        const month = (new Date().getMonth() + 1).toString().padStart(2, '0');
        const random = Math.floor(Math.random() * 9000 + 1000);
        return `${prefix}${year}${month}${random}`;
    }
    
    function generateBarcode() {
        let barcode = '200' + Math.random().toString().slice(2, 12);
        let sum = 0;
        for (let i = 0; i < 12; i++) {
            let digit = parseInt(barcode[i]);
            sum += (i % 2 === 0) ? digit : digit * 3;
        }
        let checkDigit = (10 - (sum % 10)) % 10;
        return barcode + checkDigit;
    }
    
    function formatCurrency(amount) {
        const currency = companyCurrency || 'RWF';
        return new Intl.NumberFormat('rw-RW', { 
            style: 'currency', 
            currency: currency,
            minimumFractionDigits: 0
        }).format(amount);
    }
    
    function formatNumber(number) {
        return new Intl.NumberFormat().format(number);
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function showNotification(message, type = 'info') {
        const toast = $(`
            <div class="toast align-items-center text-white bg-${type} border-0 position-fixed top-0 end-0 m-3" 
                 role="alert" style="z-index: 9999;">
                <div class="d-flex">
                    <div class="toast-body">${message}</div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>
        `);
        
        $('body').append(toast);
        const bsToast = new bootstrap.Toast(toast[0], { autohide: true, delay: 3000 });
        bsToast.show();
        toast.on('hidden.bs.toast', function() { $(this).remove(); });
    }
    
    // ============================================
    // DATATABLE MANAGEMENT - FIXED
    // ============================================
    
    function refreshDataTable() {
        if (!$.fn.DataTable) return;
        
        // Check if table has actual data rows (not empty state with colspan)
        const hasDataRows = $('#productsTable tbody tr').length > 0 && 
                            !$('#productsTable tbody tr:first td[colspan]').length;
        
        if (hasDataRows) {
            // Destroy existing DataTable if it exists
            if ($.fn.DataTable.isDataTable('#productsTable')) {
                $('#productsTable').DataTable().destroy();
                $('#productsTable').removeClass('dataTable');
            }
            
            // Initialize DataTable
            $('#productsTable').DataTable({
                pageLength: 10,
                order: [[0, 'desc']],
                language: {
                    search: "Search products:",
                    lengthMenu: "Show _MENU_ products per page",
                    info: "Showing _START_ to _END_ of _TOTAL_ products",
                    emptyTable: "No products added yet"
                },
                autoWidth: false,
                responsive: true
            });
        } else {
            // Destroy DataTable if it exists and there's no data
            if ($.fn.DataTable.isDataTable('#productsTable')) {
                $('#productsTable').DataTable().destroy();
                $('#productsTable').removeClass('dataTable');
            }
        }
    }
    
    // ============================================
    // INITIALIZATION
    // ============================================
    
    // Image preview for product
    $('#product_image').change(function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                $('#image-preview').html(`
                    <img src="${e.target.result}" class="img-thumbnail" style="max-height: 150px;">
                `);
            };
            reader.readAsDataURL(file);
        }
    });
    
    // Auto-calculate selling price for default variant
    $('input[name="purchase_price"]').on('blur', function() {
        const purchasePrice = parseFloat($(this).val()) || 0;
        const sellingPrice = $('input[name="selling_price"]');
        
        if (purchasePrice > 0 && (!sellingPrice.val() || sellingPrice.val() === '0')) {
            sellingPrice.val((purchasePrice * 1.3).toFixed(2));
        }
    });
    
    // Modal cleanup
    $('#productModal').on('hidden.bs.modal', function() {
        resetProductForm();
    });
    
    // Initialize DataTable on page load if there are products
    refreshDataTable();
    
    // Show clear all button if products already exist on page load
    if ($('#productsTable tbody tr').length > 0 && !$('#productsTable tbody tr:first td[colspan]').length) {
        addClearAllButton();
    }
});