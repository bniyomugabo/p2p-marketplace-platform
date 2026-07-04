// assets/js/inventory_movement.js
  // Product search autocomplete
    $(document).ready(function() {
        $('#variant').autocomplete({
            source: function(request, response) {
                $.ajax({
                    url: './api/products/get.php',
                    dataType: 'json',
                    data: {
                        term: request.term
                    },
                    success: function(data) {
                        response(data.map(item => ({
                            label: item.product_name + ' - ' + item.sku,
                            value: item.id,
                            text: item.product_name + ' - ' + item.sku
                        })));
                    }
                });
            },
            minLength: 2,
            select: function(event, ui) {
                $('input[name="variant"]').val(ui.item.value);
                $('input[name="variant_search"]').val(ui.item.text);
                return false;
            }
        });
    });

    // Initialize DataTable for better sorting/filtering
    $(document).ready(function() {
        $('#movementsTable').DataTable({
            pageLength: 25,
            ordering: true,
            searching: false,
            info: true,
            lengthChange: true,
            order: [[0, 'desc']]
        });
    });