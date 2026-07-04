
document.addEventListener('DOMContentLoaded', function () {
    let attributeCount = 0;

    // Add attribute
    document.getElementById('add-attribute-btn').addEventListener('click', function () {
        const template = document.getElementById('attribute-template').content.cloneNode(true);
        const container = document.getElementById('attributes-container');

        // Update attribute index
        template.querySelectorAll('[name]').forEach(el => {
            el.name = el.name.replace('[0]', `[${attributeCount}]`);
        });

        // Add remove functionality
        template.querySelector('.remove-attribute').addEventListener('click', function () {
            this.closest('.attribute-item').remove();
        });

        container.appendChild(template);
        attributeCount++;
    });

    // Generate SKU
    document.getElementById('generate-sku').addEventListener('click', function () {
        const variantName = document.getElementById('variant_name').value || 'VAR';
        const timestamp = Date.now().toString().slice(-4);
        //const sku = `SKU-${productCode}-${variantName.substring(0, 3).toUpperCase()}-${timestamp}`;
        const sku = `SKU-${productCode}-${variantName.substring(0, 3).toUpperCase()}-${timestamp}`;
        document.getElementById('sku').value = sku;
    });

    // Generate Barcode
    document.getElementById('generate-barcode').addEventListener('click', function () {
        const barcode = '2' + Math.random().toString().slice(2, 13);
        document.getElementById('barcode').value = barcode;
    });

    // Auto-calculate selling price from purchase price
    document.getElementById('purchase_price').addEventListener('blur', function () {
        const purchasePrice = parseFloat(this.value) || 0;
        const sellingInput = document.getElementById('selling_price');

        if (purchasePrice > 0 && (sellingInput.value === '' || sellingInput.value === '0')) {
            const defaultSelling = purchasePrice * 1.3; // 30% markup
            sellingInput.value = defaultSelling.toFixed(2);
        }
    });

    // Image preview
    document.getElementById('variant_image').addEventListener('change', function (e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function (e) {
                document.getElementById('image-preview').innerHTML = `
                <div class="border rounded p-2 d-inline-block">
                    <img src="${e.target.result}" alt="Preview" class="img-thumbnail" style="max-height: 150px;">
                </div>
            `;
            }
            reader.readAsDataURL(file);
        }
    });

    // Warehouse location filtering
    const warehouseSelect = document.getElementById('warehouse_id');
    const locationSelect = document.getElementById('location_id');

    warehouseSelect.addEventListener('change', function () {
        const selectedWarehouse = this.value;

        locationSelect.innerHTML = '<option value="">Select Location (Optional)</option>';

        if (selectedWarehouse && locations.length > 0) {
            const filteredLocations = locations.filter(loc => loc.warehouse_id == selectedWarehouse);
            filteredLocations.forEach(loc => {
                locationSelect.innerHTML += `<option value="${loc.id}">${loc.location_name} (${loc.location_code})</option>`;
            });
        }
    });

    // Form validation
    document.getElementById('add-variant-form').addEventListener('submit', function (e) {
        const variantName = document.getElementById('variant_name').value.trim();

        if (!variantName) {
            e.preventDefault();
            alert('Please enter a variant name.');
            return false;
        }

        // Validate image size
        const imageInput = document.getElementById('variant_image');
        if (imageInput.files.length > 0) {
            const file = imageInput.files[0];
            if (file.size > 5 * 1024 * 1024) {
                e.preventDefault();
                alert('Image is too large. Maximum size is 5MB.');
                return false;
            }
        }

        // Show loading state
        const submitBtn = this.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';
    });
});