// assets/js/reports/financial.js
// Financial Report Page Scripts

let monthlyChartInstance = null;
let receivablesChartInstance = null;
let payablesChartInstance = null;
let chartsInitialized = false;

$(document).ready(function() {
    if (!chartsInitialized) {
        initCharts();
        chartsInitialized = true;
    }
});

function initCharts() {
    // Monthly Performance Chart
    if (typeof monthlyData !== 'undefined' && monthlyData.sales) {
        const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
        
        if (monthlyChartInstance) {
            monthlyChartInstance.destroy();
        }
        
        monthlyChartInstance = new Chart(monthlyCtx, {
            type: 'bar',
            data: {
                labels: monthlyData.months,
                datasets: [
                    {
                        label: 'Sales (' + currency + ')',
                        data: monthlyData.sales,
                        backgroundColor: 'rgba(78, 115, 223, 0.8)',
                        borderColor: '#4e73df',
                        borderWidth: 1
                    },
                    {
                        label: 'Purchases (' + currency + ')',
                        data: monthlyData.purchases,
                        backgroundColor: 'rgba(28, 200, 138, 0.8)',
                        borderColor: '#1cc88a',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + currency + ' ' + context.raw.toLocaleString();
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
    
    // Receivables Chart
    if (typeof receivablesData !== 'undefined') {
        const receivablesCtx = document.getElementById('receivablesChart').getContext('2d');
        
        if (receivablesChartInstance) {
            receivablesChartInstance.destroy();
        }
        
        receivablesChartInstance = new Chart(receivablesCtx, {
            type: 'doughnut',
            data: {
                labels: ['Paid', 'Partial', 'Overdue'],
                datasets: [{
                    data: [receivablesData.paid, receivablesData.partial, receivablesData.overdue],
                    backgroundColor: ['#1cc88a', '#f6c23e', '#e74a3b'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const value = context.raw;
                                const total = receivablesData.paid + receivablesData.partial + receivablesData.overdue;
                                const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                return `${context.label}: ${currency} ${value.toLocaleString()} (${percentage}%)`;
                            }
                        }
                    }
                },
                cutout: '60%'
            }
        });
    }
    
    // Payables Chart
    if (typeof payablesData !== 'undefined') {
        const payablesCtx = document.getElementById('payablesChart').getContext('2d');
        
        if (payablesChartInstance) {
            payablesChartInstance.destroy();
        }
        
        payablesChartInstance = new Chart(payablesCtx, {
            type: 'doughnut',
            data: {
                labels: ['Approved', 'Partial', 'Pending'],
                datasets: [{
                    data: [payablesData.approved, payablesData.partial, payablesData.pending],
                    backgroundColor: ['#4e73df', '#f6c23e', '#36b9cc'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const value = context.raw;
                                const total = payablesData.approved + payablesData.partial + payablesData.pending;
                                const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                return `${context.label}: ${currency} ${value.toLocaleString()} (${percentage}%)`;
                            }
                        }
                    }
                },
                cutout: '60%'
            }
        });
    }
}

function toggleCustomDates() {
    const period = document.getElementById('period').value;
    const customDates = document.querySelectorAll('.custom-date');
    customDates.forEach(el => {
        el.style.display = period === 'custom' ? 'block' : 'none';
    });
}

// Handle window resize without recreating charts
let resizeTimeout;
window.addEventListener('resize', function() {
    if (resizeTimeout) clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(function() {
        if (monthlyChartInstance) monthlyChartInstance.resize();
        if (receivablesChartInstance) receivablesChartInstance.resize();
        if (payablesChartInstance) payablesChartInstance.resize();
    }, 250);
});