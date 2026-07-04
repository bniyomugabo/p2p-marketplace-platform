// assets/js/graph.js
// Graph Management Class - Single definition

// Initialize all dynamic graphs


// Graph Management Class
class DynamicGraph {
    constructor(canvasId, type, options = {}) {
        this.canvasId = canvasId;
        this.type = type;
        this.chart = null;
        this.currentPeriod = options.period || 'weekly';
        this.currentYear = options.year || new Date().getFullYear();
        this.currentMonth = options.month || new Date().getMonth() + 1;
        this.customerId = options.customerId || 0;
        this.currency = options.currency || 'RWF';
        this.init();
    }
    
    init() {
        this.bindEvents();
        this.loadData();
    }
    
    bindEvents() {
        // Period buttons for this specific graph
        $(`.period-btn[data-graph="${this.canvasId}"]`).off('click').on('click', (e) => {
            const btn = $(e.currentTarget);
            const period = btn.data('period');
            
            // Update active state
            $(`.period-btn[data-graph="${this.canvasId}"]`).removeClass('active');
            btn.addClass('active');
            
            this.currentPeriod = period;
            this.loadData();
        });
    }
    
    loadData() {
        // Show loading
        $(`#${this.canvasId}`).hide();
        $(`#loading_${this.canvasId}`).show();
        
        let url = `?page=api/graph&type=${this.type}&period=${this.currentPeriod}&year=${this.currentYear}`;
        
        if (this.currentPeriod === 'monthly') {
            url += `&month=${this.currentMonth}`;
        }
        
        if (this.customerId) {
            url += `&customer_id=${this.customerId}`;
        }
        
        $.ajax({
            url: url,
            method: 'GET',
            success: (response) => {
                this.renderChart(response);
                this.updateStats(response);
            },
            error: (xhr) => {
                console.error('Error loading graph:', xhr);
                $(`#loading_${this.canvasId}`).html('<div class="alert alert-danger">Failed to load graph data</div>');
            },
            complete: () => {
                $(`#${this.canvasId}`).show();
                $(`#loading_${this.canvasId}`).hide();
            }
        });
    }
    
    renderChart(data) {
        const ctx = document.getElementById(this.canvasId).getContext('2d');
        
        // Destroy existing chart if exists
        if (this.chart) {
            this.chart.destroy();
        }
        
        // Configure datasets based on graph type
        let datasets = [];
        
        switch(this.type) {
            case 'sales':
            case 'purchases':
                datasets = [
                    {
                        label: 'Amount',
                        data: data.values || [],
                        borderColor: '#4e73df',
                        backgroundColor: 'rgba(78, 115, 223, 0.05)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Count',
                        data: data.counts || [],
                        borderColor: '#1cc88a',
                        backgroundColor: 'rgba(28, 200, 138, 0.05)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3,
                        yAxisID: 'y1'
                    }
                ];
                break;
                
            case 'quotations':
                datasets = [
                    {
                        label: 'Total Amount',
                        data: data.values || [],
                        borderColor: '#36b9cc',
                        backgroundColor: 'rgba(54, 185, 204, 0.05)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Converted',
                        data: data.converted || [],
                        borderColor: '#1cc88a',
                        backgroundColor: 'rgba(28, 200, 138, 0.05)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3,
                        yAxisID: 'y1'
                    },
                    {
                        label: 'Total Quotes',
                        data: data.counts || [],
                        borderColor: '#f6c23e',
                        backgroundColor: 'rgba(246, 194, 62, 0.05)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3,
                        yAxisID: 'y1'
                    }
                ];
                break;
                
            case 'invoices':
                datasets = [
                    {
                        label: 'Paid',
                        data: data.paid || [],
                        borderColor: '#1cc88a',
                        backgroundColor: 'rgba(28, 200, 138, 0.05)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3
                    },
                    {
                        label: 'Pending',
                        data: data.pending || [],
                        borderColor: '#f6c23e',
                        backgroundColor: 'rgba(246, 194, 62, 0.05)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3
                    },
                    {
                        label: 'Overdue',
                        data: data.overdue || [],
                        borderColor: '#e74a3b',
                        backgroundColor: 'rgba(231, 74, 59, 0.05)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.3
                    }
                ];
                break;
                
            case 'stock':
                datasets = [
                    {
                        label: 'In Stock',
                        data: data.in_stock || [],
                        backgroundColor: '#1cc88a',
                        borderColor: '#fff',
                        borderWidth: 2,
                        barPercentage: 0.8
                    },
                    {
                        label: 'Low Stock',
                        data: data.low_stock || [],
                        backgroundColor: '#f6c23e',
                        borderColor: '#fff',
                        borderWidth: 2,
                        barPercentage: 0.8
                    },
                    {
                        label: 'Out of Stock',
                        data: data.out_of_stock || [],
                        backgroundColor: '#e74a3b',
                        borderColor: '#fff',
                        borderWidth: 2,
                        barPercentage: 0.8
                    }
                ];
                break;
                
            default:
                datasets = [{
                    label: 'Amount',
                    data: data.values || [],
                    borderColor: '#4e73df',
                    backgroundColor: 'rgba(78, 115, 223, 0.05)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3
                }];
        }
        
        // Determine chart type
        let chartType = this.type === 'stock' ? 'bar' : 'line';
        
        let options = {
            responsive: true,
            maintainAspectRatio: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: (ctx) => {
                            let label = ctx.dataset.label || '';
                            let value = ctx.raw;
                            if (ctx.dataset.label?.includes('Amount') || ctx.dataset.label?.includes('Paid') || ctx.dataset.label?.includes('Pending')) {
                                return `${label}: ${this.currency} ${value.toLocaleString()}`;
                            }
                            return `${label}: ${value}`;
                        }
                    }
                },
                legend: { position: 'top' }
            }
        };
        
        // Add scales for non-stock charts
        if (this.type !== 'stock') {
            options.scales = {
                y: {
                    beginAtZero: true,
                    title: { display: true, text: `Amount (${this.currency})`, font: { size: 11 } },
                    ticks: { callback: (v) => v >= 1000000 ? (v/1000000).toFixed(1)+'M' : v >= 1000 ? (v/1000).toFixed(0)+'K' : v }
                },
                y1: {
                    beginAtZero: true,
                    position: 'right',
                    title: { display: true, text: 'Count', font: { size: 11 } },
                    grid: { drawOnChartArea: false },
                    ticks: { stepSize: 1 }
                }
            };
        } else {
            options.scales = {
                y: {
                    beginAtZero: true,
                    title: { display: true, text: 'Number of Products', font: { size: 11 } },
                    ticks: { stepSize: 1 }
                }
            };
        }
        
        this.chart = new Chart(ctx, {
            type: chartType,
            data: {
                labels: data.labels || [],
                datasets: datasets
            },
            options: options
        });
    }
    
    updateStats(data) {
        let total = 0;
        let count = 0;
        let values = data.values || [];
        
        if (this.type === 'invoices') {
            total = (data.paid || []).reduce((a, b) => a + b, 0);
            count = values.length;
        } else if (this.type === 'stock') {
            total = (data.in_stock || []).reduce((a, b) => a + b, 0);
            count = (data.low_stock || []).reduce((a, b) => a + b, 0) + (data.out_of_stock || []).reduce((a, b) => a + b, 0);
        } else if (this.type === 'quotations') {
            total = (data.values || []).reduce((a, b) => a + b, 0);
            count = (data.counts || []).reduce((a, b) => a + b, 0);
        } else {
            total = values.reduce((a, b) => a + b, 0);
            count = (data.counts || []).reduce((a, b) => a + b, 0);
        }
        
        const average = values.length > 0 ? total / values.length : 0;
        
        $(`#statTotal_${this.canvasId}`).text(`${this.currency} ${total.toLocaleString()}`);
        $(`#statAverage_${this.canvasId}`).text(`${this.currency} ${average.toLocaleString()}`);
        $(`#statCount_${this.canvasId}`).text(count.toLocaleString());
    }
    
    refresh() {
        this.loadData();
    }
}

// Global export functions
function exportGraphAsImage(canvasId) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    
    const link = document.createElement('a');
    link.download = `${canvasId}.png`;
    link.href = canvas.toDataURL();
    link.click();
}

function exportGraphData(canvasId) {
    const graph = window[`${canvasId}Graph`];
    if (!graph || !graph.chart) return;
    
    const chart = graph.chart;
    let csv = 'Date,';
    chart.data.datasets.forEach(ds => { csv += `${ds.label},`; });
    csv += '\n';
    
    for (let i = 0; i < chart.data.labels.length; i++) {
        csv += `${chart.data.labels[i]},`;
        chart.data.datasets.forEach(ds => {
            let value = ds.data[i];
            if (typeof value === 'object') value = value;
            csv += `${value},`;
        });
        csv += '\n';
    }
    
    const blob = new Blob([csv], { type: 'text/csv' });
    const link = document.createElement('a');
    link.download = `${canvasId}-data.csv`;
    link.href = URL.createObjectURL(blob);
    link.click();
    URL.revokeObjectURL(link.href);
}

// Make functions global
window.exportGraphAsImage = exportGraphAsImage;
window.exportGraphData = exportGraphData;