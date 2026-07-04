// assets/js/inventory/adjustment.js
// Stock Adjustments Page Scripts

$(document).ready(function() {
    // Initialize DataTable only if there are actual data rows
    const hasDataRows = $('#adjustmentsTable tbody tr').length > 0 && 
                        !$('#adjustmentsTable tbody tr:first td[colspan]').length;
    
    if ($.fn.DataTable && hasDataRows) {
        // Destroy existing DataTable if it exists
        if ($.fn.DataTable.isDataTable('#adjustmentsTable')) {
            $('#adjustmentsTable').DataTable().destroy();
            $('#adjustmentsTable').removeClass('dataTable');
        }
        
        $('#adjustmentsTable').DataTable({
            pageLength: 25,
            order: [[0, 'desc']],
            language: {
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                emptyTable: "No adjustments found"
            },
            columnDefs: [
                { orderable: false, targets: [7] } // Actions column (8th column, index 7)
            ],
            autoWidth: false,
            responsive: true
        });
    }
    
    // Setup location filtering based on warehouse selection
    setupLocationFiltering();
    
    // Setup product search
    setupProductSearch();
});

function setupLocationFiltering() {
    // For new adjustment modal
    $('#modal_warehouse_id').on('change', function() {
        const warehouseId = $(this).val();
        const $locationSelect = $('#modal_location_id');
        $locationSelect.empty().append('<option value="">Select Location (Optional)</option>');
        
        if (warehouseId && locations) {
            const filteredLocations = locations.filter(loc => loc.warehouse_id == warehouseId);
            filteredLocations.forEach(loc => {
                $locationSelect.append(`<option value="${loc.id}">${escapeHtml(loc.location_code)} - ${escapeHtml(loc.location_name || '')}</option>`);
            });
        }
    });
    
    // For stock count modal
    $('#count_warehouse').on('change', function() {
        const warehouseId = $(this).val();
        const $locationSelect = $('#count_location');
        $locationSelect.empty().append('<option value="">All Locations</option>');
        
        if (warehouseId && locations) {
            const filteredLocations = locations.filter(loc => loc.warehouse_id == warehouseId);
            filteredLocations.forEach(loc => {
                $locationSelect.append(`<option value="${loc.id}">${escapeHtml(loc.location_code)} - ${escapeHtml(loc.location_name || '')}</option>`);
            });
        }
        
        // Update start count button link
        const startBtn = $('#startCountBtn');
        if (warehouseId) {
            startBtn.attr('href', `?page=inventory/stock-count&warehouse_id=${warehouseId}`);
        } else {
            startBtn.attr('href', '#');
        }
    });
}

function setupProductSearch() {
    let searchTimeout = null;
    let currentRequest = null;
    
    $('#product_search').on('input', function() {
        const term = $(this).val().trim();
        const $resultsDiv = $('#searchResults');
        
        if (searchTimeout) clearTimeout(searchTimeout);
        if (currentRequest) currentRequest.abort();
        
        if (term.length < 2) {
            $resultsDiv.empty().hide();
            return;
        }
        
        searchTimeout = setTimeout(function() {
            $resultsDiv.html('<div class="list-group-item text-muted"><i class="fas fa-spinner fa-spin me-2"></i>Searching...</div>').show();
            
            currentRequest = $.ajax({
                url: apiBaseUrl + '/api/products/search.php',
                type: 'GET',
                data: { term: term, warehouse_id: $('#modal_warehouse_id').val() || '' },
                dataType: 'json',
                success: function(products) {
                    $resultsDiv.empty();
                    
                    if (!products || products.length === 0) {
                        $resultsDiv.html('<div class="list-group-item text-muted">No products found</div>');
                    } else {
                        products.forEach(function(product) {
                            const displayName = product.display_name || product.product_name;
                            const stockText = product.available_stock > 0 ? `Stock: ${product.available_stock}` : 'Out of stock';
                            const stockClass = product.available_stock > 0 ? 'text-success' : 'text-danger';
                            
                            const $item = $(`
                                <div class="list-group-item" data-product='${JSON.stringify(product)}'>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="flex-grow-1">
                                            <strong>${escapeHtml(displayName)}</strong>
                                            <br>
                                            <small class="text-muted">SKU: ${escapeHtml(product.sku)}</small>
                                            ${product.barcode ? `<br><small class="text-muted">Barcode: ${escapeHtml(product.barcode)}</small>` : ''}
                                        </div>
                                        <div class="text-end ms-3">
                                            <span class="badge bg-primary">${formatCurrency(product.selling_price)}</span>
                                            <br>
                                            <small class="${stockClass}">${stockText}</small>
                                        </div>
                                    </div>
                                </div>
                            `);
                            
                            $item.click(function() {
                                const product = JSON.parse($(this).attr('data-product'));
                                selectProduct(product);
                                $resultsDiv.empty().hide();
                            });
                            
                            $resultsDiv.append($item);
                        });
                        $resultsDiv.show();
                    }
                },
                error: function(xhr, status, error) {
                    if (status !== 'abort') {
                        console.error('Search error:', error);
                        $resultsDiv.html('<div class="list-group-item text-danger">Error loading products</div>').show();
                    }
                },
                complete: function() {
                    currentRequest = null;
                }
            });
        }, 300);
    });
    
    // Hide results when clicking outside
    $(document).click(function(e) {
        if (!$(e.target).closest('#product_search, #searchResults').length) {
            $('#searchResults').hide();
        }
    });
}

function selectProduct(product) {
    $('#variant_id').val(product.id);
    $('#product_search').val(product.display_name || product.product_name);
    
    // Update selected product display
    $('#selectedProductName').html(`<strong>${escapeHtml(product.product_name)}</strong>`);
    $('#selectedProductDetails').html(`
        ${product.variant_name && product.variant_name !== 'Standard' ? `Variant: ${escapeHtml(product.variant_name)}<br>` : ''}
        SKU: ${escapeHtml(product.sku)} | Stock: ${product.available_stock} units
    `);
    $('#selectedProductDisplay').show();
    
    // Auto-fill unit price if available
    if (product.selling_price > 0) {
        $('#unit_cost').val(product.selling_price);
    }
}

function viewAdjustment(adjustment) {
    const modalContent = document.getElementById('adjustmentDetails');
    
    const typeConfig = {
        'purchase': { class: 'success', icon: 'fa-shopping-cart', text: 'Purchase' },
        'sale': { class: 'primary', icon: 'fa-tag', text: 'Sale' },
        'return': { class: 'warning', icon: 'fa-undo', text: 'Return' },
        'adjustment': { class: 'info', icon: 'fa-adjust', text: 'Adjustment' },
        'transfer': { class: 'secondary', icon: 'fa-exchange-alt', text: 'Transfer' }
    };
    const config = typeConfig[adjustment.transaction_type] || { class: 'secondary', icon: 'fa-question', text: adjustment.transaction_type };
    
    const quantityClass = adjustment.quantity > 0 ? 'success' : 'danger';
    const quantitySign = adjustment.quantity > 0 ? '+' : '';
    
    modalContent.innerHTML = `
        <div class="container-fluid p-0">
            
            <div class="row mb-3">
                <div class="col-12">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-light border-bottom">
                            <h6 class="mb-0 fw-bold text-primary"><i class="fas fa-info-circle me-2"></i>Transaction Information</h6>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm mb-0">
                                <tr><th style="width: 35%">Transaction Code:</th><td><code>${escapeHtml(adjustment.transaction_code)}</code></td></tr>
                                <tr><th>Transaction Type:</th><td><span class="badge bg-${config.class}"><i class="fas ${config.icon} me-1"></i> ${config.text}</span></td></tr>
                                <tr><th>Date & Time:</th><td>${new Date(adjustment.created_at).toLocaleString()}</td></tr>
                                <tr><th>Created By:</th><td>${escapeHtml(adjustment.created_by_name || 'System')}</td></tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-12">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-light border-bottom">
                            <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-box me-2"></i>Product Information</h6>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm mb-0">
                                <tr><th style="width: 35%">Product:</th><td><strong>${escapeHtml(adjustment.product_name)}</strong></td></tr>
                                <tr><th>Variant:</th><td>${escapeHtml(adjustment.variant_name)}</td></tr>
                                <tr><th>SKU:</th><td><code>${escapeHtml(adjustment.sku)}</code></td></tr>
                                <tr><th>Warehouse:</th><td>${escapeHtml(adjustment.warehouse_name)} ${adjustment.warehouse_code ? '(' + adjustment.warehouse_code + ')' : ''}</td></tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-12">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-light border-bottom">
                            <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-chart-line me-2"></i>Quantity & Cost</h6>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm mb-0">
                                <tr><th style="width: 35%">Quantity:</th><td class="text-${quantityClass} fw-bold">${quantitySign}${formatNumber(Math.abs(adjustment.quantity))} units</td></tr>
                                <tr><th>Unit Cost:</th><td>${formatCurrency(adjustment.unit_cost)}</td></tr>
                                <tr><th>Total Value:</th><td class="fw-bold text-primary">${formatCurrency(Math.abs(adjustment.quantity) * (adjustment.unit_cost || 0))}</td></tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-12">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-light border-bottom">
                            <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-link me-2"></i>Reference Information</h6>
                        </div>
                        <div class="card-body">
                            <table class="table table-sm mb-0">
                                <tr><th style="width: 35%">Reference Type:</th><td>${adjustment.reference_type ? adjustment.reference_type.charAt(0).toUpperCase() + adjustment.reference_type.slice(1) : '-'}</td></tr>
                                <tr><th>Reference ID:</th><td>${adjustment.reference_id ? '#' + adjustment.reference_id : '-'}</td></tr>
                                <tr><th>Location:</th><td>${adjustment.location_code ? adjustment.location_code : '-'}</td></tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            ${adjustment.notes ? `
            <div class="row">
                <div class="col-12">
                    <div class="card shadow-sm border-0 bg-light-yellow" style="background-color: #fffdf0;">
                        <div class="card-header border-bottom" style="background-color: #fff9db;">
                            <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-sticky-note me-2 text-warning"></i>Notes</h6>
                        </div>
                        <div class="card-body">
                            <p class="mb-0 small italic">${escapeHtml(adjustment.notes)}</p>
                        </div>
                    </div>
                </div>
            </div>
            ` : ''}
        </div>
    `;
    
    // Set up product view button
    if (adjustment.product_id) {
        document.getElementById('viewRelatedProductBtn').href = `?page=products/view&id=${adjustment.product_id}`;
    }
    
    new bootstrap.Modal(document.getElementById('viewAdjustmentModal')).show();
}

function formatNumber(num) {
    return new Intl.NumberFormat().format(num);
}

function formatCurrency(amount) {
    return companyCurrency + ' ' + new Intl.NumberFormat().format(amount || 0);
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}