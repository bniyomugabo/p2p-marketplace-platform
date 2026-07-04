function showDetails(item) {
    const modalContent = document.getElementById('stockDetailsContent');
    
    // Logic for values
    const totalValue = item.quantity * (item.avg_landed_cost || item.purchase_price || 0);
    const statusClass = item.available_quantity <= 0 ? 'danger' : 
                        (item.available_quantity <= item.reorder_level ? 'warning' : 'success');
    const statusText = item.available_quantity <= 0 ? 'Out of Stock' : 
                       (item.available_quantity <= item.reorder_level ? 'Low Stock' : 'In Stock');
    
    // Change grid to col-12 for full-width rows
    modalContent.innerHTML = `
        <div class="container-fluid p-0">
            
            <div class="row mb-4 mx-0">
                <div class="col-12 d-flex align-items-center justify-content-between p-3 rounded-3" style="background: #f8f9fc; border-left: 5px solid #4e73df;">
                    <div>
                        <h4 class="mb-0 fw-bold text-dark">${escapeHtml(item.product_name)}</h4>
                        <span class="text-muted small">Variant: ${escapeHtml(item.variant_name)} | SKU: <code>${escapeHtml(item.sku)}</code></span>
                    </div>
                    <div class="text-end">
                        <span class="badge bg-${statusClass} mb-1">${statusText}</span>
                        <h3 class="mb-0 fw-bold text-${statusClass}">${formatNumber(item.available_quantity)} <small style="font-size: 0.6em;">Available</small></h3>
                    </div>
                </div>
            </div>

            <div class="row mb-4 mx-0">
                <div class="col-12">
                    <h6 class="text-uppercase text-primary fw-bold small mb-3"><i class="fas fa-info-circle me-2"></i>Product Details</h6>
                    <div class="card shadow-sm border-0">
                        <div class="card-body">
                            <table class="table table-sm mb-0">
                                <tr><th style="width: 35%" class="text-muted fw-normal">Code:</th><td class="fw-bold">${escapeHtml(item.product_code)}</td></tr>
                                <tr><th class="text-muted fw-normal">Category:</th><td class="fw-bold">${escapeHtml(item.category_name || '-')}</td></tr>
                                <tr><th class="text-muted fw-normal">Brand:</th><td class="fw-bold">${escapeHtml(item.brand || '-')}</td></tr>
                                <tr><th class="text-muted fw-normal">Unit of Measure:</th><td class="fw-bold">${escapeHtml(item.unit_of_measure || 'PCS')}</td></tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-4 mx-0">
                <div class="col-12">
                    <h6 class="text-uppercase text-primary fw-bold small mb-3"><i class="fas fa-warehouse me-2"></i>Inventory Distribution</h6>
                    <div class="card shadow-sm border-0">
                        <div class="card-body">
                            <table class="table table-sm mb-0">
                                <tr><th style="width: 35%" class="text-muted fw-normal">Warehouse:</th><td class="fw-bold">${escapeHtml(item.warehouse_name)}</td></tr>
                                <tr><th class="text-muted fw-normal">Location:</th><td><span class="badge bg-light text-dark border">${escapeHtml(item.location_code) || 'Default Zone'}</span></td></tr>
                                <tr><th class="text-muted fw-normal">Total Quantity:</th><td class="fw-bold">${formatNumber(item.quantity)}</td></tr>
                                <tr><th class="text-muted fw-normal">Committed:</th><td class="fw-bold text-warning">${formatNumber(item.committed_quantity)}</td></tr>
                                <tr><th class="text-muted fw-normal">Reorder Level:</th><td class="fw-bold text-secondary">${formatNumber(item.reorder_level)}</td></tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-4 mx-0">
                <div class="col-12">
                    <h6 class="text-uppercase text-primary fw-bold small mb-3"><i class="fas fa-tag me-2"></i>Pricing & Valuation</h6>
                    <div class="card shadow-sm border-0">
                        <div class="card-body">
                            <table class="table table-sm mb-0">
                                <tr><th style="width: 35%" class="text-muted fw-normal">Purchase Price:</th><td class="fw-bold">${formatCurrency(item.purchase_price)}</td></tr>
                                <tr><th class="text-muted fw-normal text-success">Selling Price:</th><td class="fw-bold text-success">${formatCurrency(item.selling_price)}</td></tr>
                                <tr><th class="text-muted fw-normal">Tax Rate:</th><td class="fw-bold">${item.tax_rate || 0}%</td></tr>
                                <tr class="table-primary"><th class="fw-bold text-primary">Total Stock Value:</th><td class="fw-bold text-primary h6 mb-0">${formatCurrency(totalValue)}</td></tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Set up action buttons
    document.getElementById('adjustStockBtn').href = `?page=inventory/stock-adjust-variant&id=${item.variant_id}`;
    document.getElementById('viewMovementsBtn').href = `?page=inventory/movements&variant_id=${item.variant_id}`;
    
    // Show modal
    new bootstrap.Modal(document.getElementById('stockDetailsModal')).show();
}
function adjustStock(variantId, productName) {
    window.location.href = `?page=product/stock-adjust-variant&id=${variantId}`;
}

function viewMovements(variantId) {
    window.location.href = `?page=inventory/movements&variant_id=${variantId}`;
}

function refreshTable() {
    window.location.reload();
}

function formatNumber(num) {
    return new Intl.NumberFormat().format(num);
}

function formatCurrency(amount) {
    const currency = 'RWF';
    return currency + ' ' + new Intl.NumberFormat().format(amount);
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Initialize DataTable if available
$(document).ready(function() {
    if ($.fn.DataTable && $('#stockTable tbody tr').length > 10) {
        $('#stockTable').DataTable({
            pageLength: 25,
            order: [[0, 'asc']],
            language: {
                search: "Search:",
                lengthMenu: "Show _MENU_ entries",
                info: "Showing _START_ to _END_ of _TOTAL_ entries",
                emptyTable: "No stock found"
            },
            columnDefs: [
                { orderable: false, targets: [7] }
            ]
        });
    }
});
