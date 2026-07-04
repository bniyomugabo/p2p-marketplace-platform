// assets/js/reports/inventory.js
// Inventory Report Page Scripts

// Store chart instances globally to prevent multiple creations
let warehouseChartInstance = null;
let categoryChartInstance = null;
let chartsInitialized = false;

$(document).ready(function() {
    // Initialize DataTables only if there are actual data rows
    initDataTables();
    
    // Initialize charts only once
    if (!chartsInitialized) {
        initCharts();
        chartsInitialized = true;
    }
});

function initDataTables() {
    if (!$.fn.DataTable) return;
    
    // Check if aging table has data rows (not empty state with colspan)
    const $agingTable = $('#agingTable');
    const $agingTbody = $agingTable.find('tbody');
    const $agingFirstRow = $agingTbody.find('tr:first');
    const hasAgingData = $agingFirstRow.length > 0 && !$agingFirstRow.find('td[colspan]').length;
    
    if ($.fn.DataTable.isDataTable('#agingTable')) {
        $('#agingTable').DataTable().destroy();
        $('#agingTable').removeClass('dataTable');
    }
    
    if (hasAgingData) {
        $('#agingTable').DataTable({
            pageLength: 25,
            order: [[5, 'desc']],
            language: { emptyTable: "No aging stock data available" },
            columnDefs: [{ orderable: false, targets: [6] }],
            searching: true,
            paging: true
        });
    }
    
    // Check if stock table has data rows
    const $stockTable = $('#stockTable');
    const $stockTbody = $stockTable.find('tbody');
    const $stockFirstRow = $stockTbody.find('tr:first');
    const hasStockData = $stockFirstRow.length > 0 && !$stockFirstRow.find('td[colspan]').length;
    
    if ($.fn.DataTable.isDataTable('#stockTable')) {
        $('#stockTable').DataTable().destroy();
        $('#stockTable').removeClass('dataTable');
    }
    
    if (hasStockData) {
        $('#stockTable').DataTable({
            pageLength: 25,
            order: [[5, 'desc']],
            language: { emptyTable: "No stock data available" },
            columnDefs: [{ orderable: false, targets: [9] }],
            searching: true,
            paging: true
        });
    }
}

function initCharts() {
    // Warehouse Chart - Show Quantity Distribution
    if (typeof warehouseData !== 'undefined' && warehouseData.labels && warehouseData.labels.length > 0) {
        const hasData = warehouseData.quantities && warehouseData.quantities.some(q => q > 0);
        
        if (hasData) {
            // Destroy existing chart if it exists
            if (warehouseChartInstance) {
                warehouseChartInstance.destroy();
                warehouseChartInstance = null;
            }
            
            const warehouseCtx = document.getElementById('warehouseChart').getContext('2d');
            
            warehouseChartInstance = new Chart(warehouseCtx, {
                type: 'doughnut',
                data: {
                    labels: warehouseData.labels,
                    datasets: [{
                        data: warehouseData.quantities,
                        backgroundColor: warehouseData.colors,
                        borderWidth: 2,
                        borderColor: '#fff',
                        hoverOffset: 10
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '60%',
                    plugins: {
                        legend: { 
                            position: 'right', 
                            labels: { 
                                boxWidth: 12, 
                                padding: 15, 
                                font: { size: 11 }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const value = context.raw;
                                    const total = warehouseData.total || warehouseData.quantities.reduce((a, b) => a + b, 0);
                                    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                    return `${context.label}: ${value.toLocaleString()} units (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        } else {
            showChartPlaceholder('warehouseChart', 'No stock quantity data available');
        }
    } else {
        showChartPlaceholder('warehouseChart', 'No warehouse data available');
    }
    
    // Category Chart - Show Quantity Distribution
    if (typeof categoryData !== 'undefined' && categoryData.labels && categoryData.labels.length > 0) {
        const hasData = categoryData.quantities && categoryData.quantities.some(q => q > 0);
        
        if (hasData) {
            // Destroy existing chart if it exists
            if (categoryChartInstance) {
                categoryChartInstance.destroy();
                categoryChartInstance = null;
            }
            
            const categoryCtx = document.getElementById('categoryChart').getContext('2d');
            
            categoryChartInstance = new Chart(categoryCtx, {
                type: 'doughnut',
                data: {
                    labels: categoryData.labels,
                    datasets: [{
                        data: categoryData.quantities,
                        backgroundColor: categoryData.colors,
                        borderWidth: 2,
                        borderColor: '#fff',
                        hoverOffset: 10
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '60%',
                    plugins: {
                        legend: { 
                            position: 'right', 
                            labels: { 
                                boxWidth: 12, 
                                padding: 15, 
                                font: { size: 11 }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const value = context.raw;
                                    const total = categoryData.total || categoryData.quantities.reduce((a, b) => a + b, 0);
                                    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                    return `${context.label}: ${value.toLocaleString()} units (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
        } else {
            showChartPlaceholder('categoryChart', 'No stock quantity data available');
        }
    } else {
        showChartPlaceholder('categoryChart', 'No category data available');
    }
}

function showChartPlaceholder(canvasId, message) {
    const canvas = document.getElementById(canvasId);
    if (canvas && canvas.parentElement) {
        // Only add placeholder if not already present
        if (canvas.parentElement.querySelector('.chart-placeholder')) {
            return;
        }
        
        canvas.style.display = 'none';
        const parent = canvas.parentElement;
        const placeholder = document.createElement('div');
        placeholder.className = 'text-center py-5 chart-placeholder';
        placeholder.innerHTML = `<div class="text-muted"><i class="fas fa-chart-pie fa-4x mb-3"></i><p class="mb-0">${message}</p><small>Add inventory to see distribution</small></div>`;
        parent.appendChild(placeholder);
    }
}

// Handle window resize without recreating charts
let resizeTimeout;
window.addEventListener('resize', function() {
    if (resizeTimeout) {
        clearTimeout(resizeTimeout);
    }
    resizeTimeout = setTimeout(function() {
        if (warehouseChartInstance) {
            warehouseChartInstance.resize();
        }
        if (categoryChartInstance) {
            categoryChartInstance.resize();
        }
    }, 250);
});