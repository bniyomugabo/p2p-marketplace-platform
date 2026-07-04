$(document).ready(function() {
    // Sales Graph
    if (document.getElementById('salesGraph')) {
        window.salesGraph = new DynamicGraph('salesGraph', 'sales', {
            period: 'weekly',
            currency: companyCurrency
        });
    }
    
    // Invoices Graph
    if (document.getElementById('invoicesGraph')) {
        window.invoicesGraph = new DynamicGraph('invoicesGraph', 'invoices', {
            period: 'weekly',
            currency: companyCurrency
        });
    }
    
    // Quotations Graph
    if (document.getElementById('quotationsGraph')) {
        window.quotationsGraph = new DynamicGraph('quotationsGraph', 'quotations', {
            period: 'weekly',
            currency: companyCurrency
        });
    }
    
    // Purchases Graph
    if (document.getElementById('purchasesGraph')) {
        window.purchasesGraph = new DynamicGraph('purchasesGraph', 'purchases', {
            period: 'weekly',
            currency: companyCurrency
        });
    }
    
    // Stock Graph
    if (document.getElementById('stockGraph')) {
        window.stockGraph = new DynamicGraph('stockGraph', 'stock', {
            period: 'weekly',
            currency: companyCurrency
        });
    }
});