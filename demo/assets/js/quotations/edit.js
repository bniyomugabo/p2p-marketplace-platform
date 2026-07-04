
document.addEventListener('DOMContentLoaded', function () {
    let itemCount = 0;
    const itemsContainer = document.getElementById('items-container');
    const itemTemplate = document.getElementById('item-template').content;


    existingItems.forEach((item, index) => {
        addItem(item, index);
    });

    // Add item button
    document.getElementById('add-item-btn').addEventListener('click', () => addItem());

    function addItem(itemData = null, index = null) {
        const itemRow = itemTemplate.cloneNode(true);
        const currentIndex = index !== null ? index : itemCount;

        // Update indices
        itemRow.querySelectorAll('[name*="INDEX"]').forEach(el => {
            el.name = el.name.replace('INDEX', currentIndex);
        });

        // Fill data if provided
        if (itemData) {
            itemRow.querySelector('.product-name').value = itemData.product_name || '';
            itemRow.querySelector('.quantity').value = itemData.quantity || 1;
            itemRow.querySelector('.unit-price').value = itemData.unit_price || 0;
            itemRow.querySelector('.discount').value = itemData.discount_percent || 0;
            itemRow.querySelector('.tax-rate').value = itemData.tax_rate || 18;
            itemRow.querySelector('.description').value = itemData.description || '';
        }

        // Add remove functionality
        itemRow.querySelector('.remove-item').addEventListener('click', function () {
            if (document.querySelectorAll('.item-row').length > 1) {
                this.closest('.item-row').remove();
                calculateTotals();
            } else {
                alert('You need at least one item.');
            }
        });

        // Input change handlers
        const quantityInput = itemRow.querySelector('.quantity');
        const unitPriceInput = itemRow.querySelector('.unit-price');
        const discountInput = itemRow.querySelector('.discount');
        const taxRateInput = itemRow.querySelector('.tax-rate');

        [quantityInput, unitPriceInput, discountInput, taxRateInput].forEach(input => {
            input.addEventListener('input', function () {
                calculateLineTotal(itemRow);
            });
        });

        itemsContainer.appendChild(itemRow);
        itemCount = Math.max(itemCount, currentIndex + 1);

        calculateLineTotal(itemRow);
        calculateTotals();
    }

    function calculateLineTotal(row) {
        const quantity = parseFloat(row.querySelector('.quantity').value) || 0;
        const unitPrice = parseFloat(row.querySelector('.unit-price').value) || 0;
        const discount = parseFloat(row.querySelector('.discount').value) || 0;
        const taxRate = parseFloat(row.querySelector('.tax-rate').value) || 0;

        const subtotal = quantity * unitPrice;
        const discountAmount = subtotal * (discount / 100);
        const afterDiscount = subtotal - discountAmount;
        const taxAmount = afterDiscount * (taxRate / 100);
        const lineTotal = afterDiscount + taxAmount;

        row.querySelector('.line-total').textContent = `Line Total: RWF ${Math.round(lineTotal).toLocaleString()}`;

        calculateTotals();
    }

    function calculateTotals() {
        let subtotal = 0;
        let totalDiscount = 0;
        let totalTax = 0;

        document.querySelectorAll('.item-row').forEach(row => {
            const quantity = parseFloat(row.querySelector('.quantity').value) || 0;
            const unitPrice = parseFloat(row.querySelector('.unit-price').value) || 0;
            const discount = parseFloat(row.querySelector('.discount').value) || 0;
            const taxRate = parseFloat(row.querySelector('.tax-rate').value) || 0;

            const lineSubtotal = quantity * unitPrice;
            const lineDiscount = lineSubtotal * (discount / 100);
            const lineAfterDiscount = lineSubtotal - lineDiscount;
            const lineTax = lineAfterDiscount * (taxRate / 100);

            subtotal += lineSubtotal;
            totalDiscount += lineDiscount;
            totalTax += lineTax;
        });

        const total = subtotal - totalDiscount + totalTax;

        document.getElementById('subtotal').textContent = `RWF ${Math.round(subtotal).toLocaleString()}`;
        document.getElementById('discount').textContent = `RWF ${Math.round(totalDiscount).toLocaleString()}`;
        document.getElementById('tax').textContent = `RWF ${Math.round(totalTax).toLocaleString()}`;
        document.getElementById('total').textContent = `RWF ${Math.round(total).toLocaleString()}`;
    }

    // Form validation
    document.getElementById('edit-quotation-form').addEventListener('submit', function (e) {
        const customerName = document.getElementById('customer_name').value.trim();

        if (!customerName) {
            e.preventDefault();
            alert('Please enter customer name.');
            return false;
        }

        const items = document.querySelectorAll('.item-row');
        let hasValidItem = false;

        items.forEach(item => {
            const productName = item.querySelector('.product-name').value.trim();
            const quantity = parseFloat(item.querySelector('.quantity').value) || 0;

            if (productName && quantity > 0) {
                hasValidItem = true;
            }
        });

        if (!hasValidItem) {
            e.preventDefault();
            alert('Please add at least one valid item with product name and quantity.');
            return false;
        }

        // Show loading state
        const submitBtn = e.submitter;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Updating...';
    });
});