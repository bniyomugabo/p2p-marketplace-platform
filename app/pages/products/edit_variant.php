<?php
// pages/products/edit_variant.php
declare(strict_types=1);

$pageTitle = 'Edit Variant - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';

// Load models
require_once __DIR__ . '/../../models/Product.php';
require_once __DIR__ . '/../../models/Variant.php';
require_once __DIR__ . '/../../models/Warehouse.php';
require_once __DIR__ . '/../../models/Location.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR'])) {
    SessionManager::flash('error', 'You do not have permission to edit variants.');
    header('Location: ' . route_url('products'));
    exit;
}

// Get variant ID from URL
$variantId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$variantId) {
    SessionManager::flash('error', 'Variant ID is required.');
    header('Location: ' . route_url('products'));
    exit;
}

// Initialize models
$variantModel = new Variant();
$productModel = new Product();
$warehouseModel = new Warehouse();
$locationModel = new Location();

// Get variant details
$variant = $variantModel->getWithDetails($variantId);

if (!$variant) {
    SessionManager::flash('error', 'Variant not found.');
    header('Location: ' . route_url('products'));
    exit;
}

// Get product details
$product = $productModel->getWithDetails($variant['product_id']);

// Get warehouses
$warehouses = $warehouseModel->all(['id', 'warehouse_code', 'warehouse_name', 'city'], 'is_active = 1');

// Get locations
$locations = $locationModel->getAllWithWarehouse();

// Generate CSRF token
$csrfToken = CSRF::generate();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
        SessionManager::flash('error', 'Invalid security token. Please try again.');
        header('Location: ?page=products/edit-variant&id=' . $variantId);
        exit;
    }

    try {
        // Basic validation
        if (empty($_POST['variant_name'])) {
            throw new Exception("Variant name is required.");
        }

        // Start transaction
        $db->beginTransaction();

        // Handle variant image upload
        if (isset($_FILES['variant_image']) && $_FILES['variant_image']['error'] === UPLOAD_ERR_OK) {
            $variantImageUrl = uploadVariantImage($_FILES['variant_image'], $product['id'], time());
            if ($variantImageUrl) {
                $variantModel->addImage($variantId, $variantImageUrl, !empty($_POST['set_as_primary']));
            }
        }

        // Update variant
        $updateData = [
            'sku' => trim($_POST['sku']),
            'barcode' => trim($_POST['barcode']),
            'variant_name' => trim($_POST['variant_name']),
            'purchase_price' => !empty($_POST['purchase_price']) ? (float) $_POST['purchase_price'] : 0,
            'selling_price' => !empty($_POST['selling_price']) ? (float) $_POST['selling_price'] : 0,
            'wholesale_price' => !empty($_POST['wholesale_price']) ? (float) $_POST['wholesale_price'] : null,
            'tax_rate' => !empty($_POST['tax_rate']) ? (float) $_POST['tax_rate'] : 18.00,
            'reorder_level' => !empty($_POST['reorder_level']) ? (int) $_POST['reorder_level'] : 0,
            'max_stock_level' => !empty($_POST['max_stock_level']) ? (int) $_POST['max_stock_level'] : 0,
            'is_active' => isset($_POST['is_active']) ? 1 : 0
        ];

        $variantModel->update($variantId, $updateData);

        // Clear existing attributes
        $variantModel->clearAttributes($variantId);

        // Add new attributes
        if (isset($_POST['attributes']) && is_array($_POST['attributes'])) {
            foreach ($_POST['attributes'] as $attrIndex => $attr) {
                if (!empty($attr['name']) && !empty($attr['value'])) {
                    $variantModel->addAttribute(
                        $variantId, 
                        trim($attr['name']), 
                        trim($attr['value']), 
                        $attrIndex + 1
                    );
                }
            }
        }

        $db->commit();

        SessionManager::flash('success', 'Variant updated successfully!');
        header('Location: ' . route_url('products/view-variant', ['id' => $variantId]));
        exit;

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Variant update error: " . $e->getMessage());
        SessionManager::flash('error', 'Failed to update variant: ' . $e->getMessage());
    }
}

// Image upload function
function uploadVariantImage($file, $productId, $timestamp)
{
    $uploadDir = __DIR__ . '/../../assets/uploads/products/variants/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception('Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.');
    }

    if ($file['size'] > $maxSize) {
        throw new Exception('File is too large. Maximum size is 5MB.');
    }

    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'variant_' . $productId . '_' . $timestamp . '.' . $extension;
    $filepath = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return 'uploads/products/variants/' . $filename;
    }

    throw new Exception('Failed to upload image.');
}
?>

<div class="container-fluid">
    <!-- Back button and title -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <a href="<?php echo route_url('products/view-variant', ['id' => $variantId]); ?>" 
                       class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Variant
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-edit me-2"></i>Edit Variant
                    </h2>
                    <p class="mb-0 text-muted">
                        Editing variant for: <strong><?php echo htmlspecialchars($product['product_name'] ?? ''); ?></strong>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if ($flash = SessionManager::flash('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($flash); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Form -->
    <div class="card shadow">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-edit me-2"></i>Variant Details
            </h6>
        </div>
        <div class="card-body">
            <form method="POST" id="edit-variant-form" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                <div class="row">
                    <div class="col-md-6">
                        <h5 class="mb-3"><i class="fas fa-info-circle me-2"></i>Basic Information</h5>

                        <div class="mb-3">
                            <label for="variant_name" class="form-label">
                                Variant Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="variant_name" name="variant_name" required
                                   value="<?php echo htmlspecialchars($variant['variant_name']); ?>"
                                   placeholder="e.g., Black, Large, 1GB">
                        </div>

                        <div class="mb-3">
                            <label for="sku" class="form-label">SKU</label>
                            <input type="text" class="form-control" id="sku" name="sku" 
                                   value="<?php echo htmlspecialchars($variant['sku']); ?>">
                            <div class="form-text">Stock Keeping Unit - unique identifier</div>
                        </div>

                        <div class="mb-3">
                            <label for="barcode" class="form-label">Barcode</label>
                            <input type="text" class="form-control" id="barcode" name="barcode" 
                                   value="<?php echo htmlspecialchars($variant['barcode'] ?? ''); ?>">
                            <div class="form-text">EAN-13 format</div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <h5 class="mb-3"><i class="fas fa-tag me-2"></i>Pricing & Inventory</h5>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="purchase_price" class="form-label">Purchase Price</label>
                                <div class="input-group">
                                    <span class="input-group-text">RWF</span>
                                    <input type="number" class="form-control" id="purchase_price" name="purchase_price" 
                                           min="0" step="0.01" value="<?php echo $variant['purchase_price'] ?? 0; ?>">
                                </div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="selling_price" class="form-label">Selling Price</label>
                                <div class="input-group">
                                    <span class="input-group-text">RWF</span>
                                    <input type="number" class="form-control" id="selling_price" name="selling_price" 
                                           min="0" step="0.01" value="<?php echo $variant['selling_price'] ?? 0; ?>">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="wholesale_price" class="form-label">Wholesale Price</label>
                                <div class="input-group">
                                    <span class="input-group-text">RWF</span>
                                    <input type="number" class="form-control" id="wholesale_price" name="wholesale_price" 
                                           min="0" step="0.01" value="<?php echo $variant['wholesale_price'] ?? ''; ?>">
                                </div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="tax_rate" class="form-label">Tax Rate (%)</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="tax_rate" name="tax_rate" 
                                           min="0" max="100" step="0.01" value="<?php echo $variant['tax_rate'] ?? 18.00; ?>">
                                    <span class="input-group-text">%</span>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="reorder_level" class="form-label">Reorder Level</label>
                                <input type="number" class="form-control" id="reorder_level" name="reorder_level" 
                                       min="0" value="<?php echo $variant['reorder_level'] ?? 0; ?>">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="max_stock_level" class="form-label">Max Stock Level</label>
                                <input type="number" class="form-control" id="max_stock_level" name="max_stock_level" 
                                       min="0" value="<?php echo $variant['max_stock_level'] ?? 0; ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Variant Attributes -->
                <div class="row mt-4">
                    <div class="col-12">
                        <h5 class="mb-3 border-bottom pb-2">
                            <i class="fas fa-list me-2"></i>Variant Attributes
                        </h5>
                        
                        <div id="attributes-container">
                            <?php if (!empty($variant['attributes'])): ?>
                                <?php foreach ($variant['attributes'] as $attrIndex => $attr): ?>
                                    <div class="attribute-item row g-2 mb-2">
                                        <div class="col-md-5">
                                            <input type="text" class="form-control" 
                                                   name="attributes[<?php echo $attrIndex; ?>][name]" 
                                                   value="<?php echo htmlspecialchars($attr['attribute_name']); ?>"
                                                   placeholder="Attribute name (e.g., Color)">
                                        </div>
                                        <div class="col-md-5">
                                            <input type="text" class="form-control" 
                                                   name="attributes[<?php echo $attrIndex; ?>][value]" 
                                                   value="<?php echo htmlspecialchars($attr['attribute_value']); ?>"
                                                   placeholder="Attribute value (e.g., Black)">
                                        </div>
                                        <div class="col-md-2">
                                            <button type="button" class="btn btn-sm btn-outline-danger w-100 remove-attribute">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                        <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="add-attribute-btn">
                            <i class="fas fa-plus me-1"></i>Add Attribute
                        </button>
                    </div>
                </div>

                <!-- Images -->
                <div class="row mt-4">
                    <div class="col-12">
                        <h5 class="mb-3 border-bottom pb-2">
                            <i class="fas fa-images me-2"></i>Images
                        </h5>
                        
                        <?php if (!empty($variant['images'])): ?>
                            <div class="row mb-3">
                                <?php foreach ($variant['images'] as $image): ?>
                                    <div class="col-md-3 mb-3">
                                        <div class="card">
                                            <img src="<?php echo asset_url($image['image_url']); ?>" 
                                                 class="card-img-top" 
                                                 alt="<?php echo htmlspecialchars($image['caption'] ?? ''); ?>"
                                                 style="height: 150px; object-fit: cover;">
                                            <div class="card-body p-2">
                                                <?php if ($image['is_primary']): ?>
                                                    <span class="badge bg-primary">Primary</span>
                                                <?php else: ?>
                                                    <a href="?page=products/set-primary-image&id=<?php echo $image['id']; ?>&variant_id=<?php echo $variantId; ?>" 
                                                       class="btn btn-sm btn-outline-primary">Set as Primary</a>
                                                <?php endif; ?>
                                                <a href="?page=products/delete-image&id=<?php echo $image['id']; ?>&variant_id=<?php echo $variantId; ?>" 
                                                   class="btn btn-sm btn-outline-danger float-end"
                                                   onclick="return confirm('Delete this image?')">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label for="variant_image" class="form-label">Add New Image</label>
                            <input type="file" class="form-control" id="variant_image" name="variant_image" accept="image/*">
                            <div class="form-check mt-2">
                                <input type="checkbox" class="form-check-input" id="set_as_primary" name="set_as_primary">
                                <label class="form-check-label" for="set_as_primary">Set as primary image</label>
                            </div>
                            <div class="mt-2" id="image-preview"></div>
                        </div>
                    </div>
                </div>

                <!-- Status -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="is_active" name="is_active" 
                                   <?php echo $variant['is_active'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_active">
                                Variant is active
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Submit Buttons -->
                <div class="row mt-4">
                    <div class="col-12">
                        <hr>
                        <div class="d-flex justify-content-between">
                            <a href="<?php echo route_url('products/view-variant', ['id' => $variantId]); ?>" 
                               class="btn btn-outline-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Update Variant
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Attribute Template -->
<template id="attribute-template">
    <div class="attribute-item row g-2 mb-2">
        <div class="col-md-5">
            <input type="text" class="form-control" name="attributes[0][name]" 
                   placeholder="Attribute name (e.g., Color)">
        </div>
        <div class="col-md-5">
            <input type="text" class="form-control" name="attributes[0][value]" 
                   placeholder="Attribute value (e.g., Black)">
        </div>
        <div class="col-md-2">
            <button type="button" class="btn btn-sm btn-outline-danger w-100 remove-attribute">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>
</template>

<!-- Pass PHP data to JavaScript -->
<script>
    const locations = <?php echo json_encode($locations); ?>;
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let attributeCount = <?php echo count($variant['attributes'] ?? []); ?>;
    
    // Add attribute
    document.getElementById('add-attribute-btn').addEventListener('click', function() {
        const template = document.getElementById('attribute-template').content.cloneNode(true);
        const container = document.getElementById('attributes-container');
        
        // Update attribute index
        template.querySelectorAll('[name]').forEach(el => {
            el.name = el.name.replace('[0]', `[${attributeCount}]`);
        });
        
        // Add remove functionality
        template.querySelector('.remove-attribute').addEventListener('click', function() {
            this.closest('.attribute-item').remove();
        });
        
        container.appendChild(template);
        attributeCount++;
    });
    
    // Remove attribute functionality for existing items
    document.querySelectorAll('.remove-attribute').forEach(btn => {
        btn.addEventListener('click', function() {
            this.closest('.attribute-item').remove();
        });
    });
    
    // Image preview
    document.getElementById('variant_image').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('image-preview').innerHTML = `
                    <div class="border rounded p-2 d-inline-block">
                        <img src="${e.target.result}" alt="Preview" class="img-thumbnail" style="max-height: 150px;">
                    </div>
                `;
            }
            reader.readAsDataURL(file);
        }
    });
    
    // Form validation
    document.getElementById('edit-variant-form').addEventListener('submit', function(e) {
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
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Updating...';
    });
});
</script>