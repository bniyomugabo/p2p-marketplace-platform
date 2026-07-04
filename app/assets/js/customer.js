// assets/js/customer.js
// Customer management JavaScript with jQuery

$(document).ready(function() {
    // Initialize DataTable
    initializeDataTable();
    
    // Initialize form validation
    initializeFormValidation();
    
    // Initialize phone number formatting
    initializePhoneFormatting();
    
    // Initialize delete confirmation
    initializeDeleteConfirmation();
});

function initializeDataTable() {
    // Check if customers exist (passed from PHP)
    if (typeof customersExist !== 'undefined' && customersExist) {
        $('#customersTable').DataTable({
            pageLength: 25,
            order: [[1, 'asc']], // Order by Name column (index 1)
            columnDefs: [
                { orderable: false, targets: [8] } // Disable ordering on Actions column (index 8)
            ],
            language: {
                emptyTable: "No customers available",
                zeroRecords: "No matching customers found",
                info: "Showing _START_ to _END_ of _TOTAL_ customers",
                infoEmpty: "Showing 0 to 0 of 0 customers",
                infoFiltered: "(filtered from _MAX_ total customers)"
            }
        });
    }
}

function initializeFormValidation() {
    // Add Customer Form Validation
    $('#addCustomerForm').on('submit', function(e) {
        const fullName = $('#full_name').val().trim();
        if (!fullName) {
            e.preventDefault();
            showAlert('Please enter customer name.');
            return false;
        }

        const email = $('#email').val();
        if (email && !isValidEmail(email)) {
            e.preventDefault();
            showAlert('Please enter a valid email address.');
            return false;
        }

        // Show loading state
        const $submitBtn = $(this).find('button[type="submit"]');
        $submitBtn.prop('disabled', true);
        $submitBtn.html('<i class="fas fa-spinner fa-spin me-2"></i>Saving...');
    });

    // Edit Customer Form Validation
    $('#editCustomerForm').on('submit', function(e) {
        const fullName = $('#edit_full_name').val().trim();
        if (!fullName) {
            e.preventDefault();
            showAlert('Please enter customer name.');
            return false;
        }

        const email = $('#edit_email').val();
        if (email && !isValidEmail(email)) {
            e.preventDefault();
            showAlert('Please enter a valid email address.');
            return false;
        }

        // Show loading state
        const $submitBtn = $(this).find('button[type="submit"]');
        $submitBtn.prop('disabled', true);
        $submitBtn.html('<i class="fas fa-spinner fa-spin me-2"></i>Updating...');
    });
}

function initializePhoneFormatting() {
    $('input[type="tel"]').on('input', function() {
        let value = $(this).val().replace(/\D/g, '');
        if (value.length <= 3) {
            $(this).val(value);
        } else if (value.length <= 6) {
            $(this).val(value.slice(0, 3) + '-' + value.slice(3));
        } else {
            $(this).val(value.slice(0, 3) + '-' + value.slice(3, 6) + '-' + value.slice(6, 10));
        }
    });
}

function initializeDeleteConfirmation() {
    $('.delete-customer').on('click', function(e) {
        e.preventDefault();
        const customerName = $(this).data('customer-name');
        const deleteUrl = $(this).attr('href');
        
        if (confirm('Are you sure you want to delete ' + customerName + '?')) {
            window.location.href = deleteUrl;
        }
    });
}

// Global functions for button clicks
window.editCustomer = function(customer) {
    $('#edit_id').val(customer.id);
    $('#edit_full_name').val(customer.full_name);
    $('#edit_customer_type').val(customer.customer_type);
    $('#edit_phone').val(customer.phone || '');
    $('#edit_email').val(customer.email || '');
    $('#edit_address').val(customer.address || '');
    $('#edit_city').val(customer.city || '');
    $('#edit_tax_id').val(customer.tax_id || '');
    $('#edit_credit_limit').val(customer.credit_limit || 0);
    $('#edit_is_active').val(customer.is_active);
    $('#edit_notes').val(customer.notes || '');
    
    $('#editCustomerModal').modal('show');
};

window.viewCustomer = function(id) {
    window.location.href = '?page=sales/view-customer&id=' + id;
};

// Helper functions
function showAlert(message) {
    alert(message); // You can replace with a better notification system
}

function isValidEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

// Optional: Add tooltips
$(function() {
    $('[data-toggle="tooltip"]').tooltip();
});