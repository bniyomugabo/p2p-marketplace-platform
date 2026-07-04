// assets/js/reports/sales.js
// Sales Report Page Scripts

$(document).ready(function() {
    // Daily Sales Chart
    if (typeof dailyData !== 'undefined' && dailyData.length > 0) {
        const dailyCtx = document.getElementById('dailySalesChart').getContext('2d');
        const dates = dailyData.map(d => d.date);
        const salesAmounts = dailyData.map(d => d.sales);
        
        new Chart(dailyCtx, {
            type: 'line',
            data: {
                labels: dates,
                datasets: [{
                    label: 'Sales (' + currency + ')',
                    data: salesAmounts,
                    borderColor: '#4e73df',
                    backgroundColor: 'rgba(78, 115, 223, 0.05)',
                    borderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#4e73df',
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return currency + ' ' + context.raw.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return currency + ' ' + value.toLocaleString();
                            }
                        }
                    }
                }
            }
        });
    }
    
    // Payment Methods Chart
    if (typeof paymentData !== 'undefined' && paymentData.length > 0) {
        const paymentCtx = document.getElementById('paymentMethodChart').getContext('2d');
        const methodLabels = paymentData.map(p => {
            const labels = {
                'cash': '💰 Cash',
                'bank_transfer': '🏦 Bank Transfer',
                'mobile': '📱 Mobile Money',
                'card': '💳 Card',
                'cheque': '📝 Cheque'
            };
            return labels[p.payment_method] || p.payment_method;
        });
        const methodAmounts = paymentData.map(p => p.total_amount);
        const methodColors = {
            'cash': '#1cc88a',
            'bank_transfer': '#36b9cc',
            'mobile': '#f6c23e',
            'card': '#4e73df',
            'cheque': '#858796'
        };
        const backgroundColors = paymentData.map(p => methodColors[p.payment_method] || '#858796');
        
        new Chart(paymentCtx, {
            type: 'doughnut',
            data: {
                labels: methodLabels,
                datasets: [{
                    data: methodAmounts,
                    backgroundColor: backgroundColors,
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
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const value = context.raw;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((value / total) * 100).toFixed(1);
                                return `${context.label}: ${currency} ${value.toLocaleString()} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }
    
    // Initialize DataTables
    if ($.fn.DataTable) {
        if ($.fn.DataTable.isDataTable('#dailyTable')) {
            $('#dailyTable').DataTable().destroy();
        }
        if ($.fn.DataTable.isDataTable('#topProductsTable')) {
            $('#topProductsTable').DataTable().destroy();
        }
        
        $('#dailyTable').DataTable({
            pageLength: 25,
            order: [[0, 'desc']],
            language: { emptyTable: "No sales data available" },
            searching: true,
            paging: true
        });
        
        $('#topProductsTable').DataTable({
            pageLength: 10,
            order: [[2, 'desc']],
            language: { emptyTable: "No product sales data available" },
            searching: true,
            paging: true
        });
    }
});

function toggleCustomDates() {
    const period = document.getElementById('period').value;
    const customDates = document.querySelectorAll('.custom-date');
    customDates.forEach(el => {
        el.style.display = period === 'custom' ? 'block' : 'none';
    });
}