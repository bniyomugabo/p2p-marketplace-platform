$(document).ready(function() {
   
    const variantsContainer = $('#variants-container');
    const variantTemplate = $('#variant-template').html();
    const imageTemplate = $('#image-template').html();
    const attributeTemplate = $('#attribute-template').html();
    const noVariantsMessage = $('#no-variants-message');
    
    // Update variant counter
    function updateVariantCounter() {
        const count = variantsContainer.find('.variant-item').length;
        $('#variant-count').text(count);
        
        if (count > 0) {
            noVariantsMessage.addClass('d-none');
        } else {
            noVariantsMessage.removeClass('d-none');
        }
    }
    
    // Add variant button
    $('#add-variant-btn').on('click', function() {
        variantCount++;
        
        // Create new variant from template
        let variantHtml = variantTemplate
            .replace(/variants\[0\]/g, `variants[${variantCount}]`)
            .replace(/data-variant-index="0"/g, `data-variant-index="${variantCount}"`)
            .replace('New Variant <span class="variant-number">1</span>', `New Variant <span class="variant-number">${variantCount}</span>`);
        
        const $variant = $(variantHtml);
        
        // Generate variant code
        const variantCode = `${productCode}-${variantCount.toString().padStart(3, '0')}`;
        $variant.find('.variant-code').val(variantCode);
        $variant.find('.variant-code-display').text(variantCode);
        
        // Generate SKU
        const sku = `SKU-${productCode}-${variantCount.toString().padStart(2, '0')}`;
        $variant.find('[name*="sku"]').val(sku);
        
        variantsContainer.append($variant);
        updateVariantCounter();
        
        // Auto-calculate selling price
        $variant.find('.purchase-price').on('blur', function() {
            const purchasePrice = parseFloat($(this).val()) || 0;
            const sellingInput = $(this).closest('.variant-item').find('.selling-price');
            
            if (purchasePrice > 0 && (sellingInput.val() === '' || sellingInput.val() === '0')) {
                const defaultSelling = purchasePrice * 1.3;
                sellingInput.val(defaultSelling.toFixed(2));
            }
        });
    });
    
    // Remove variant
    variantsContainer.on('click', '.remove-variant', function() {
        const $variant = $(this).closest('.variant-item');
        const hasId = $variant.find('input[name*="[id]"]').val();
        
        if (hasId) {
            // For existing variants, mark for soft delete
            if (confirm('Remove this variant? It will be marked as inactive. Stock records will be preserved.')) {
                $variant.remove();
                variantCount--;
                updateVariantNumbers();
                updateVariantCounter();
            }
        } else {
            // For new variants, just remove
            $variant.remove();
            variantCount--;
            updateVariantNumbers();
            updateVariantCounter();
        }
    });
    
    // Update variant names when variant name changes
    variantsContainer.on('input', '.variant-name', function() {
        const $variant = $(this).closest('.variant-item');
        const variantName = $(this).val();
        $variant.find('.variant-name-display').text(variantName || 'Unnamed');
    });
    
    // Update variant code display when code changes
    variantsContainer.on('input', '.variant-code', function() {
        const $variant = $(this).closest('.variant-item');
        const variantCode = $(this).val();
        $variant.find('.variant-code-display').text(variantCode || '---');
    });
    
    // Add attribute to variant
    variantsContainer.on('click', '.add-attribute', function() {
        const $variant = $(this).closest('.variant-item');
        const $attributesContainer = $variant.find('.attributes-container');
        const variantIndex = getVariantIndex($variant);
        
        const attributeHtml = attributeTemplate;
        const $attribute = $(attributeHtml);
        
        // Set attribute names
        const attrCount = $attributesContainer.find('.attribute-item').length;
        $attribute.find('.attribute-name').attr('name', `variants[${variantIndex}][attributes][${attrCount}][name]`);
        $attribute.find('.attribute-value').attr('name', `variants[${variantIndex}][attributes][${attrCount}][value]`);
        
        $attributesContainer.append($attribute);
    });
    
    // Remove attribute
    variantsContainer.on('click', '.remove-attribute', function() {
        $(this).closest('.attribute-item').remove();
    });
    
    // Add image input manually
    variantsContainer.on('click', '.add-image-btn', function() {
        const variantIndex = $(this).data('variant-index');
        const $variant = $(this).closest('.variant-item');
        const $imagesContainer = $variant.find('.images-container');
        
        const imageHtml = imageTemplate;
        const $image = $(imageHtml);
        
        // Update input names
        const currentImagesCount = $imagesContainer.find('.image-item').length;
        
        // Check if this variant has an ID (existing variant) or is new
        const hasVariantId = $variant.find('input[name*="[id]"]').val();
        
        if (hasVariantId) {
            // For existing variants, use new_images array
            $image.find('.image-url').attr('name', `variants[${variantIndex}][new_images][${currentImagesCount}][url]`);
            $image.find('.image-sort').attr('name', `variants[${variantIndex}][new_images][${currentImagesCount}][sort]`);
            $image.find('.primary-image').attr('name', `variants[${variantIndex}][primary_image_new][${currentImagesCount}]`);
        } else {
            // For new variants, use new_images as well
            $image.find('.image-url').attr('name', `variants[${variantIndex}][new_images][${currentImagesCount}][url]`);
            $image.find('.image-sort').attr('name', `variants[${variantIndex}][new_images][${currentImagesCount}][sort]`);
            $image.find('.primary-image').attr('name', `variants[${variantIndex}][primary_image_new][${currentImagesCount}]`);
        }
        
        // Remove preview image (will be added when URL is entered)
        $image.find('img').hide();
        
        $imagesContainer.append($image);
    });
    
    // Remove image
    variantsContainer.on('click', '.remove-image', function() {
        const $imageItem = $(this).closest('.image-item');
        const hasId = $imageItem.find('input[name*="[id]"]').length > 0;
        
        if (hasId) {
            // For existing images, mark for deletion
            if (confirm('Remove this image?')) {
                // Add hidden input to mark for deletion
                const idField = $imageItem.find('input[name*="[id]"]');
                const idName = idField.attr('name');
                
                const $hidden = $('<input>').attr({
                    type: 'hidden',
                    name: idName.replace('[id]', '[delete]'),
                    value: '1'
                });
                $imageItem.append($hidden);
                $imageItem.hide();
            }
        } else {
            // For new images, just remove
            $imageItem.remove();
        }
    });
    
    // Handle image upload
    variantsContainer.on('click', '.upload-images-btn', function() {
        const variantIndex = $(this).data('variant-index');
        const $variantItem = $(this).closest('.variant-item');
        const variantId = $variantItem.find('input[name*="[id]"]').val();
        const $fileInput = $variantItem.find('.image-file-input');
        const $progressContainer = $variantItem.find('.upload-progress');
        const $progressBar = $progressContainer.find('.progress-bar');
        
        if (!$fileInput[0].files.length) {
            alert('Please select images to upload');
            return;
        }
        
        if (!variantId) {
            alert('Please save the variant first before uploading images. You can add image URLs manually for now.');
            return;
        }
        
        const formData = new FormData();
        formData.append('variant_id', variantId);
        
        for (let i = 0; i < $fileInput[0].files.length; i++) {
            formData.append('images[]', $fileInput[0].files[i]);
        }
        
        $progressContainer.show();
        
        $.ajax({
            url: BASE_URL + 'api/upload_variant_images.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function() {
                const xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener('progress', function(e) {
                    if (e.lengthComputable) {
                        const percent = Math.round((e.loaded / e.total) * 100);
                        $progressBar.css('width', percent + '%').text(percent + '%');
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                if (response.success && response.files.length > 0) {
                    // Add uploaded images to the list
                    const $imagesContainer = $variantItem.find('.images-container');
                    
                    response.files.forEach(function(file, index) {
                        const $newImage = $(imageTemplate);
                        
                        // Update input names
                        const currentImagesCount = $imagesContainer.find('.image-item').length;
                        
                        $newImage.find('.image-url')
                            .attr('name', `variants[${variantIndex}][new_images][${currentImagesCount + index}][url]`)
                            .val(file.url);
                        
                        $newImage.find('.image-sort')
                            .attr('name', `variants[${variantIndex}][new_images][${currentImagesCount + index}][sort]`)
                            .val(0);
                        
                        $newImage.find('.primary-image')
                            .attr('name', `variants[${variantIndex}][primary_image_new][${currentImagesCount + index}]`);
                        
                        // Add preview
                        $newImage.find('img')
                            .attr('src', file.url)
                            .show();
                        
                        $imagesContainer.append($newImage);
                    });
                    
                    $progressContainer.hide();
                    $progressBar.css('width', '0%');
                    $fileInput.val('');
                    
                    // Show success message
                    showNotification('Images uploaded successfully', 'success');
                } else {
                    showNotification('No images were uploaded', 'warning');
                    $progressContainer.hide();
                }
            },
            error: function(xhr, status, error) {
                console.error('Upload error:', error);
                alert('Failed to upload images. Please check console for details.');
                $progressContainer.hide();
            }
        });
    });
    
    // Preview image when URL is entered
    variantsContainer.on('blur', '.image-url', function() {
        const $input = $(this);
        const url = $input.val();
        const $previewImg = $input.closest('.col-md-6').find('img');
        
        if (url && $previewImg.length) {
            $previewImg.attr('src', url).show();
        } else if ($previewImg.length) {
            $previewImg.hide();
        }
    });
    
    // Helper functions
    function getVariantIndex($variant) {
        const name = $variant.find('input').first().attr('name');
        if (!name) return 0;
        const match = name.match(/variants\[(\d+)\]/);
        return match ? parseInt(match[1]) : 0;
    }
    
    function updateVariantNumbers() {
        variantsContainer.find('.variant-item').each(function(index) {
            // Update variant number display
            $(this).find('.variant-number').text(index + 1);
            
            // Update array indices in input names
            updateVariantInputNames($(this), index);
            
            // Update data attributes for buttons
            $(this).find('[data-variant-index]').attr('data-variant-index', index);
        });
        variantCount = variantsContainer.find('.variant-item').length;
    }
    
    function updateVariantInputNames($variant, newIndex) {
        $variant.find('input, select, textarea').each(function() {
            const name = $(this).attr('name');
            if (name && name.includes('variants[')) {
                // Replace the variant index
                const newName = name.replace(/variants\[\d+\]/g, `variants[${newIndex}]`);
                $(this).attr('name', newName);
                
                // Update IDs for checkboxes
                if ($(this).attr('id') && $(this).attr('id').includes('variant_active_')) {
                    $(this).attr('id', `variant_active_${newIndex}`);
                    const $label = $(this).next('label');
                    if ($label.length) {
                        $label.attr('for', `variant_active_${newIndex}`);
                    }
                }
            }
        });
    }
    
    // Form validation
    $('#edit-product-form').on('submit', function(e) {
        const productCode = $('#product_code').val();
        const productName = $('#product_name').val();
        const category = $('#category_id').val();
        
        if (!productCode || !productName || !category) {
            e.preventDefault();
            alert('Please fill in all required fields in the General Information section.');
            return false;
        }
        
        // Validate variants
        let hasErrors = false;
        variantsContainer.find('.variant-item').each(function() {
            const variantCode = $(this).find('.variant-code').val();
            const variantName = $(this).find('.variant-name').val();
            
            if (!variantCode || !variantName) {
                hasErrors = true;
                $(this).addClass('border-danger');
            } else {
                $(this).removeClass('border-danger');
            }
            
            // Validate image URLs if any
            $(this).find('.image-url').each(function() {
                if ($(this).val() && !isValidUrl($(this).val())) {
                    hasErrors = true;
                    $(this).addClass('is-invalid');
                } else {
                    $(this).removeClass('is-invalid');
                }
            });
        });
        
        if (hasErrors) {
            e.preventDefault();
            alert('Please fix the errors in the form:\n- Variant Code and Name are required\n- Image URLs must be valid');
            return false;
        }
        
        // Show loading
        const $submitBtn = $(this).find('button[type="submit"]');
        $submitBtn.prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin me-2"></i>Updating...');
    });
    
    // URL validation helper
    function isValidUrl(string) {
        if (!string) return true; // Empty is allowed
        try {
            new URL(string);
            return true;
        } catch (_) {
            return false;
        }
    }
    
    // Auto-generate variant codes for new variants
    variantsContainer.on('blur', '.variant-name', function() {
        const $variant = $(this).closest('.variant-item');
        const variantName = $(this).val();
        const variantCodeInput = $variant.find('.variant-code');
        
        // Only auto-generate if empty and it's a new variant (no ID)
        if (!variantCodeInput.val() && !$variant.find('input[name*="[id]"]').val()) {
            const variantIndex = getVariantIndex($variant) + 1;
            const code = `${productCode}-${variantIndex.toString().padStart(3, '0')}`;
            variantCodeInput.val(code);
            $variant.find('.variant-code-display').text(code);
            
            // Also generate SKU if empty
            const skuInput = $variant.find('[name*="sku"]');
            if (!skuInput.val()) {
                skuInput.val(`SKU-${productCode}-${variantIndex.toString().padStart(2, '0')}`);
            }
        }
    });
    
    // Initialize variant displays
    variantsContainer.find('.variant-item').each(function() {
        const $variant = $(this);
        const variantName = $variant.find('.variant-name').val();
        const variantCode = $variant.find('.variant-code').val();
        
        if (variantName) {
            $variant.find('.variant-name-display').text(variantName);
        }
        if (variantCode) {
            $variant.find('.variant-code-display').text(variantCode);
        }
    });
    
    // Show notification function
    function showNotification(message, type = 'info') {
        const alertClass = type === 'success' ? 'alert-success' : 
                          type === 'warning' ? 'alert-warning' : 
                          type === 'error' ? 'alert-danger' : 'alert-info';
        
        const $alert = $(`
            <div class="alert ${alertClass} alert-dismissible fade show position-fixed top-0 end-0 m-3" style="z-index: 9999;" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `);
        
        $('body').append($alert);
        
        setTimeout(() => {
            $alert.alert('close');
        }, 5000);
    }
    
    // Initialize variant counter
    updateVariantCounter();
});