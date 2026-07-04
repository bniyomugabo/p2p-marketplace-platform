// pages/products/categories.js

$(document).ready(function() {
    
    
    // Edit category button click
    $('.edit-category').on('click', function() {
        
        // Get data from button attributes
        const id = $(this).data('id');
        const code = $(this).data('code');
        const name = $(this).data('name');
        const description = $(this).data('description') || '';
        const parentId = $(this).data('parent');
        const sortOrder = $(this).data('sort') || 0;
        const isActive = $(this).data('active');
        
        
        // Populate edit modal fields
        $('#edit_category_id').val(id);
        $('#edit_category_code').val(code);
        $('#edit_category_name').val(name);
        $('#edit_description').val(description);
        $('#edit_sort_order').val(sortOrder);
        
        // Set parent select option
        $('#edit_parent_id').val(parentId);
        
        // Set active checkbox (make sure to compare correctly)
        $('#edit_is_active').prop('checked', isActive == 1 || isActive === true);
        
        // Show the modal
        $('#editCategoryModal').modal('show');
    });
    
    // Delete category button click
    $('.delete-category').on('click', function() {
        
        // Get data from button attributes
        const id = $(this).data('id');
        const name = $(this).data('name');
        const productCount = $(this).data('product-count') || 0;
        const subcategoryCount = $(this).data('subcategory-count') || 0;
        
        // Set category ID in hidden input
        $('#delete_category_id').val(id);
        
        // Set category name in modal
        $('#delete_category_name').text(name);
        
        // Show warning if category has products or subcategories
        const warningMessages = [];
        if (parseInt(productCount) > 0) {
            warningMessages.push(`This category contains ${productCount} product(s).`);
        }
        if (parseInt(subcategoryCount) > 0) {
            warningMessages.push(`This category contains ${subcategoryCount} subcategor${subcategoryCount > 1 ? 'ies' : 'y'}.`);
        }
        
        if (warningMessages.length > 0) {
            $('#warning_message').html(warningMessages.join('<br>'));
            $('#delete_warning').show();
        } else {
            $('#delete_warning').hide();
        }
        
        // Show the modal
        $('#deleteCategoryModal').modal('show');
    });
    
    // Form validation for add category
    $('#addCategoryModal form').on('submit', function(e) {
        const code = $('#category_code').val().trim();
        const name = $('#category_name').val().trim();
        
        if (!code || !name) {
            e.preventDefault();
            alert('Please fill in all required fields.');
            return false;
        }
        
        // Show loading state
        $(this).find('button[type="submit"]').prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin me-2"></i>Adding...');
    });
    
    // Form validation for edit category
    $('#editCategoryModal form').on('submit', function(e) {
        const code = $('#edit_category_code').val().trim();
        const name = $('#edit_category_name').val().trim();
        
        if (!code || !name) {
            e.preventDefault();
            alert('Please fill in all required fields.');
            return false;
        }
        
        // Show loading state
        $(this).find('button[type="submit"]').prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin me-2"></i>Updating...');
    });
    
    // Form validation for delete category
    $('#deleteCategoryModal form').on('submit', function(e) {
        const id = $('#delete_category_id').val();
        
        if (!id) {
            e.preventDefault();
            alert('Invalid category ID.');
            return false;
        }
        
        // Show loading state
        $(this).find('button[type="submit"]').prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin me-2"></i>Deleting...');
    });
    
    // Clear form when add modal is closed
    $('#addCategoryModal').on('hidden.bs.modal', function() {
        $(this).find('form')[0].reset();
        $(this).find('button[type="submit"]').prop('disabled', false)
            .html('Add Category');
    });
    
    // Clear validation states when edit modal is closed
    $('#editCategoryModal').on('hidden.bs.modal', function() {
        $(this).find('button[type="submit"]').prop('disabled', false)
            .html('Update Category');
        // Reset any validation styles if needed
        $(this).find('.is-invalid').removeClass('is-invalid');
    });
    
    // Clear delete modal when closed
    $('#deleteCategoryModal').on('hidden.bs.modal', function() {
        $(this).find('button[type="submit"]').prop('disabled', false)
            .html('Delete Category');
        $('#delete_warning').hide();
    });
    
    // Auto-generate category code from name (optional feature)
    $('#category_name, #edit_category_name').on('blur', function() {
        const name = $(this).val().trim();
        const isEdit = $(this).attr('id') === 'edit_category_name';
        const codeField = isEdit ? $('#edit_category_code') : $('#category_code');
        
        // Only auto-generate if code field is empty
        if (name && !codeField.val().trim()) {
            // Generate code from name (uppercase, take first letters, max 10 chars)
            let code = name
                .toUpperCase()
                .replace(/[^A-Z0-9\s]/g, '')
                .split(/\s+/)
                .map(word => word.substring(0, 3))
                .join('')
                .substring(0, 10);
            
            codeField.val(code);
        }
    });
    
    // Handle parent category selection to prevent self-referencing in edit modal
    $('#edit_parent_id').on('change', function() {
        const selectedParent = $(this).val();
        const currentId = $('#edit_category_id').val();
        
        if (selectedParent && selectedParent === currentId) {
            alert('A category cannot be its own parent.');
            $(this).val('');
        }
    });
    
    // Initialize tooltips if Bootstrap tooltips are enabled
    if (typeof $.fn.tooltip !== 'undefined') {
        $('[title]').tooltip();
    }
});