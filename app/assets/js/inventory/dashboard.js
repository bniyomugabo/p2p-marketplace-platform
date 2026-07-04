// assets/js/inventory/dashboard.js
// Inventory Dashboard Page Scripts

(function() {
    'use strict';
    
    // Wait for DOM to be fully loaded
    function initChart() {
        // Check if categoryData exists
        if (typeof categoryData === 'undefined') {
            console.error('categoryData is not defined');
            return;
        }
        
        // Check if we have valid data
        if (!categoryData.labels || categoryData.labels.length === 0) {
            console.log('No category labels available');
            return;
        }
        
        if (!categoryData.quantities || categoryData.quantities.length === 0) {
            console.log('No category quantities available');
            return;
        }
        
        var hasData = false;
        for (var i = 0; i < categoryData.quantities.length; i++) {
            if (categoryData.quantities[i] > 0) {
                hasData = true;
                break;
            }
        }
        
        if (!hasData) {
            console.log('No stock quantity data available (all zeros)');
            var chartContainer = document.getElementById('categoryChart');
            if (chartContainer && chartContainer.parentElement) {
                chartContainer.style.display = 'none';
                var parent = chartContainer.parentElement;
                if (!parent.querySelector('.no-data-message')) {
                    var msgDiv = document.createElement('div');
                    msgDiv.className = 'text-center py-5 no-data-message';
                    msgDiv.innerHTML = '<div class="text-muted"><i class="fas fa-chart-pie fa-4x mb-3"></i><p class="mb-0">No stock data available</p><small>Add inventory to see distribution</small></div>';
                    parent.appendChild(msgDiv);
                }
            }
            return;
        }
        
        // Get the canvas element
        var canvas = document.getElementById('categoryChart');
        if (!canvas) {
            console.error('Canvas element #categoryChart not found');
            return;
        }
        
        // Get context
        var ctx = canvas.getContext('2d');
        if (!ctx) {
            console.error('Could not get canvas context');
            return;
        }
        
        // Destroy existing chart if it exists
        if (window.categoryChartInstance) {
            try {
                window.categoryChartInstance.destroy();
            } catch(e) {
                console.log('Error destroying chart:', e);
            }
            window.categoryChartInstance = null;
        }
        
        // Create new chart
        try {
            window.categoryChartInstance = new Chart(ctx, {
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
                                font: { size: 11 },
                                usePointStyle: true
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    var value = context.raw;
                                    var total = 0;
                                    for (var i = 0; i < context.dataset.data.length; i++) {
                                        total += context.dataset.data[i];
                                    }
                                    var percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                    var formattedValue = new Intl.NumberFormat().format(value);
                                    return context.label + ': ' + formattedValue + ' units (' + percentage + '%)';
                                }
                            }
                        }
                    }
                }
            });
            console.log('Chart initialized successfully');
        } catch (error) {
            console.error('Error creating chart:', error);
        }
    }
    
    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initChart);
    } else {
        initChart();
    }
})();