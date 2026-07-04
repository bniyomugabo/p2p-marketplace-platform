/**
 * reports/products.js
 * Product Report Page Scripts
 * Handles charts, data tables, and interactive elements
 */

$(document).ready(function() {
    // ============================================
    // Initialize DataTable
    // ============================================
    if ($.fn.DataTable && $('#productsTable tbody tr').length > 0) {
        const hasDataRows = $('#productsTable tbody tr:first td[colspan]').length === 0;
        
        if (hasDataRows) {
            if ($.fn.DataTable.isDataTable('#productsTable')) {
                $('#productsTable').DataTable().destroy();
                $('#productsTable').removeClass('dataTable');
            }
            
            $('#productsTable').DataTable({
                pageLength: 25,
                order: [[1, 'asc']],
                language: {
                    search: "Search products:",
                    lengthMenu: "Show _MENU_ products per page",
                    info: "Showing _START_ to _END_ of _TOTAL_ products",
                    infoEmpty: "No products found",
                    emptyTable: "No products found",
                    paginate: {
                        first: "First",
                        last: "Last",
                        next: "Next",
                        previous: "Previous"
                    }
                },
                columnDefs: [
                    { orderable: false, targets: [5] } // Last Sale column
                ],
                searching: true,
                paging: true,
                responsive: true
            });
        }
    }
    
    // ============================================
    // Initialize Charts
    // ============================================
    
    // Top Products Chart (Bar Chart)
    if (typeof topProducts !== 'undefined' && topProducts.length > 0) {
        const ctx1 = document.getElementById('topProductsChart');
        if (ctx1) {
            // Destroy existing chart if any
            if (window.topProductsChartInstance) {
                window.topProductsChartInstance.destroy();
            }
            
            window.topProductsChartInstance = new Chart(ctx1.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: topProducts.map(p => {
                        let name = p.product_name;
                        if (name.length > 25) {
                            name = name.substring(0, 22) + '...';
                        }
                        return name;
                    }),
                    datasets: [{
                        label: 'Revenue (' + companyCurrency + ')',
                        data: topProducts.map(p => parseFloat(p.revenue) || 0),
                        backgroundColor: 'rgba(78, 115, 223, 0.8)',
                        borderColor: '#4e73df',
                        borderWidth: 1,
                        borderRadius: 4,
                        barPercentage: 0.7
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                font: { size: 12 },
                                usePointStyle: true,
                                boxWidth: 10
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    let value = context.raw;
                                    return `${label}: ${formatCurrency(value)}`;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Revenue',
                                font: { weight: 'bold', size: 12 }
                            },
                            ticks: {
                                callback: function(value) {
                                    return formatCurrency(value);
                                }
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Products',
                                font: { weight: 'bold', size: 12 }
                            },
                            ticks: {
                                autoSkip: true,
                                maxRotation: 45,
                                minRotation: 45
                            }
                        }
                    }
                }
            });
        }
    } else if (document.getElementById('topProductsChart')) {
        // Show message if no data
        document.getElementById('topProductsChart').style.display = 'none';
        document.getElementById('topProductsChart').parentElement.innerHTML += 
            '<div class="text-center py-5 text-muted">No product data available</div>';
    }
    
    // Category Chart (Pie Chart)
    if (typeof categoryData !== 'undefined' && categoryData.length > 0 && categoryData.some(c => c.total_value > 0)) {
        const ctx2 = document.getElementById('categoryChart');
        if (ctx2) {
            // Destroy existing chart if any
            if (window.categoryChartInstance) {
                window.categoryChartInstance.destroy();
            }
            
            const categoryColors = [
                '#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', 
                '#e74a3b', '#858796', '#5a5c69', '#fd7e14',
                '#20c997', '#6f42c1', '#d63384', '#198754'
            ];
            
            window.categoryChartInstance = new Chart(ctx2.getContext('2d'), {
                type: 'pie',
                data: {
                    labels: categoryData.map(c => c.category),
                    datasets: [{
                        data: categoryData.map(c => parseFloat(c.total_value) || 0),
                        backgroundColor: categoryColors.slice(0, categoryData.length),
                        borderWidth: 2,
                        borderColor: '#fff',
                        hoverOffset: 10
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                font: { size: 11 },
                                boxWidth: 12,
                                padding: 10
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                    return `${label}: ${formatCurrency(value)} (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        }
    } else if (document.getElementById('categoryChart')) {
        // Show message if no data
        document.getElementById('categoryChart').style.display = 'none';
        document.getElementById('categoryChart').parentElement.innerHTML += 
            '<div class="text-center py-5 text-muted">No category data available</div>';
    }
    
    // ============================================
    // Stock Level Color Coding in Table
    // ============================================
    $('#productsTable tbody tr').each(function() {
        const stockCell = $(this).find('td:eq(3)'); // Stock column
        const stock = parseInt(stockCell.text().replace(/,/g, '')) || 0;
        
        if (stock <= 0) {
            stockCell.addClass('text-danger fw-bold');
            stockCell.html('<i class="fas fa-times-circle me-1"></i> ' + stockCell.text());
        } else if (stock <= 10) {
            stockCell.addClass('text-warning fw-bold');
            stockCell.html('<i class="fas fa-exclamation-triangle me-1"></i> ' + stockCell.text());
        } else {
            stockCell.addClass('text-success');
            stockCell.html('<i class="fas fa-check-circle me-1"></i> ' + stockCell.text());
        }
    });
    
    // ============================================
    // Revenue Column Formatting
    // ============================================
    $('#productsTable tbody tr').each(function() {
        const revenueCell = $(this).find('td:eq(4)'); // Revenue column
        const revenue = parseFloat(revenueCell.text().replace(/,/g, '')) || 0;
        revenueCell.html(formatCurrency(revenue));
    });
    
    // ============================================
    // Filter Form Auto-submit on Change
    // ============================================
    $('#category_id, #status, #sort_by').on('change', function() {
        $(this).closest('form').submit();
    });
    
    $('#date_from, #date_to').on('change', function() {
        if ($('#date_from').val() && $('#date_to').val()) {
            $(this).closest('form').submit();
        }
    });
    
    // ============================================
    // Export Functionality
    // ============================================
    $('.dropdown-item').on('click', function(e) {
        const href = $(this).attr('href');
        if (href && href !== '#') {
            e.preventDefault();
            window.open(href, '_blank');
        }
    });
    
    // ============================================
    // Tooltips Initialization
    // ============================================
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function(tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // ============================================
    // Print Functionality
    // ============================================
    $(document).on('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
            e.preventDefault();
            window.print();
        }
    });
});

// ============================================
// Helper Functions
// ============================================

/**
 * Format currency
 * @param {number} amount - Amount to format
 * @returns {string} Formatted currency string
 */
function formatCurrency(amount) {
    const currency = window.companyCurrency || 'RWF';
    const formatted = Math.round(amount).toLocaleString();
    return `${currency} ${formatted}`;
}

/**
 * Export table to CSV
 */
function exportToCSV() {
    const table = document.getElementById('productsTable');
    if (!table) return;
    
    const rows = table.querySelectorAll('tr');
    const csvData = [];
    
    rows.forEach(row => {
        const rowData = [];
        const cells = row.querySelectorAll('th, td');
        cells.forEach(cell => {
            let text = cell.textContent.trim();
            // Remove icons if present
            text = text.replace(/[^\w\s\-\.\(\)]/g, '');
            rowData.push('"' + text + '"');
        });
        csvData.push(rowData.join(','));
    });
    
    const blob = new Blob(["\uFEFF" + csvData.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', `product_report_${new Date().toISOString().slice(0, 19)}.csv`);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

/**
 * Show notification toast
 * @param {string} message - Notification message
 * @param {string} type - Notification type
 */
function showNotification(message, type = 'info') {
    // Remove existing toasts
    $('.toast').remove();
    
    const bgClass = {
        'success': 'bg-success',
        'danger': 'bg-danger',
        'warning': 'bg-warning',
        'info': 'bg-info'
    }[type] || 'bg-info';
    
    const iconClass = {
        'success': 'fa-check-circle',
        'danger': 'fa-exclamation-circle',
        'warning': 'fa-exclamation-triangle',
        'info': 'fa-info-circle'
    }[type] || 'fa-info-circle';
    
    const toast = $(`
        <div class="toast align-items-center text-white ${bgClass} border-0 position-fixed top-0 end-0 m-3" 
             role="alert" style="z-index: 9999; min-width: 300px;">
            <div class="d-flex">
                <div class="toast-body">
                    <i class="fas ${iconClass} me-2"></i>${escapeHtml(message)}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    `);
    
    $('body').append(toast);
    const bsToast = new bootstrap.Toast(toast[0], { autohide: true, delay: 3000 });
    bsToast.show();
    
    toast.on('hidden.bs.toast', function() { 
        $(this).remove(); 
    });
}

/**
 * Escape HTML to prevent XSS
 * @param {string} text - Text to escape
 * @returns {string} Escaped HTML
 */
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}