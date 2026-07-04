// assets/js/reports/customer.js
// Customer Report Page Scripts

$(document).ready(function() {
    // Initialize DataTable
    if ($.fn.DataTable && $('#customerTable tbody tr').length > 0 && $('#customerTable tbody tr:first td').attr('colspan') !== '9') {
        $('#customerTable').DataTable({
            pageLength: 25,
            order: [[4, 'desc']],
            language: {
                search: "Search customers:",
                lengthMenu: "Show _MENU_ customers per page",
                info: "Showing _START_ to _END_ of _TOTAL_ customers",
                emptyTable: "No customer data available"
            },
            columnDefs: [
                { orderable: false, targets: [1, 2, 8] }
            ]
        });
    }
    
    // Top Customers Chart
    if (typeof topCustomers !== 'undefined' && topCustomers.length > 0) {
        const topCtx = document.getElementById('topCustomersChart').getContext('2d');
        const topLabels = topCustomers.map(c => {
            let name = c.full_name;
            if (name.length > 20) name = name.substring(0, 18) + '...';
            return name;
        });
        const topValues = topCustomers.map(c => c.total_purchases);
        
        new Chart(topCtx, {
            type: 'bar',
            data: {
                labels: topLabels,
                datasets: [{
                    label: 'Total Purchases (' + currency + ')',
                    data: topValues,
                    backgroundColor: 'rgba(78, 115, 223, 0.8)',
                    borderColor: '#4e73df',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => currency + ' ' + ctx.raw.toLocaleString()
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: {
                            callback: (value) => currency + ' ' + value.toLocaleString()
                        }
                    }
                }
            }
        });
    }
    
    // Customer Type Chart
    if (typeof individualCount !== 'undefined' && (individualCount > 0 || companyCount > 0)) {
        const typeCtx = document.getElementById('customerTypeChart').getContext('2d');
        new Chart(typeCtx, {
            type: 'pie',
            data: {
                labels: ['👤 Individual', '🏢 Company'],
                datasets: [{
                    data: [individualCount, companyCount],
                    backgroundColor: ['#36b9cc', '#4e73df'],
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const label = ctx.label || '';
                                const value = ctx.raw;
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((value / total) * 100).toFixed(1);
                                return `${label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }
});

// Toggle custom date fields
function toggleCustomDates() {
    const period = document.getElementById('period').value;
    const customDates = document.querySelectorAll('.custom-date');
    customDates.forEach(el => {
        el.style.display = period === 'custom' ? 'block' : 'none';
    });
}