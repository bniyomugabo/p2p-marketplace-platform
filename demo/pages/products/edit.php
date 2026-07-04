<?php
// pages/products/edit.php
declare(strict_types=1);

$pageTitle = 'Edit Product - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';

// Load models
require_once __DIR__ . '/../../models/Product.php';
require_once __DIR__ . '/../../models/Category.php';
require_once __DIR__ . '/../../models/Variant.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check permission
if (!in_array($userRole, ['ADM', 'MGR'])) {
    SessionManager::flash('error', 'You do not have permission to edit products.');
    header('Location: ?page=products/list');
    exit;
}

// Get product ID
$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$productId) {
    SessionManager::flash('error', 'Invalid product ID');
    header('Location: ?page=products/list');
    exit;
}

// Initialize models with company context
$productModel = new Product($companyId);
$categoryModel = new Category($companyId);
$variantModel = new Variant($companyId);

// Get product details (includes company validation)
$product = $productModel->getWithDetails($productId);

if (!$product) {
    SessionManager::flash('error', 'Product not found or not accessible for your company');
    header('Location: ?page=products/list');
    exit;
}

// Get ONLY categories that are ACTIVATED for this company (with hierarchy)
$categories = $categoryModel->getAllWithHierarchy();

// Flatten categories for dropdown with indentation
function flattenCategoriesForSelect($categories, $level = 0, &$result = []) {
    foreach ($categories as $category) {
        $category['level'] = $level;
        $result[] = $category;
        if (!empty($category['children'])) {
            flattenCategoriesForSelect($category['children'], $level + 1, $result);
        }
    }
    return $result;
}

$flatCategories = flattenCategoriesForSelect($categories);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
        SessionManager::flash('error', 'Invalid security token. Please try again.');
        header('Location: ?page=products/edit&id=' . $productId);
        exit;
    }

    try {
        // Validate required fields
        if (empty($_POST['product_name'])) {
            throw new Exception('Product name is required');
        }
        
        if (empty($_POST['category_id'])) {
            throw new Exception('Category is required');
        }
        
        $newCategoryId = (int) $_POST['category_id'];
        
        // Verify category is activated for this company (if changed)
        if ($newCategoryId != $product['category_id']) {
            if (!$categoryModel->isActivatedForCompany($newCategoryId, $companyId)) {
                throw new Exception('Selected category is not activated for your company');
            }
        }

        // Update product
        $productData = [
            'product_name' => trim($_POST['product_name']),
            'description' => trim($_POST['description'] ?? ''),
            'category_id' => $newCategoryId,
            'brand' => trim($_POST['brand'] ?? ''),
            'unit_of_measure' => trim($_POST['unit_of_measure'] ?? 'PCS'),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $productModel->update($productId, $productData);

        SessionManager::flash('success', 'Product updated successfully!');
        header('Location: ?page=products/view&id=' . $productId);
        exit;

    } catch (Exception $e) {
        error_log("Product update error: " . $e->getMessage());
        SessionManager::flash('error', 'Failed to update product: ' . $e->getMessage());
    }
}

// Generate CSRF token
$csrfToken = CSRF::generate();
?>

<div class="product-edit">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <a href="?page=products/view&id=<?php echo $productId; ?>" class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Product
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-edit me-2"></i>Edit Product
                    </h2>
                    <p class="mb-0 text-muted">
                        <strong><?php echo htmlspecialchars($product['product_name']); ?></strong>
                        <span class="text-muted">(Code: <?php echo htmlspecialchars($product['product_code']); ?>)</span>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if ($flash = SessionManager::flash('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($flash); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($flash = SessionManager::flash('success')): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($flash); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Edit Form -->
    <div class="card shadow">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-edit me-2"></i>Product Information
            </h6>
        </div>
        <div class="card-body">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                
                <div class="row">
                    <div class="col-md-8">
                        <!-- Basic Information -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="product_code" class="form-label">Product Code</label>
                                <input type="text" class="form-control bg-light" id="product_code" 
                                       value="<?php echo htmlspecialchars($product['product_code']); ?>" readonly disabled>
                                <div class="form-text">Product code cannot be changed</div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="product_name" class="form-label">Product Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="product_name" name="product_name" required
                                       value="<?php echo htmlspecialchars($product['product_name']); ?>">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="category_id" class="form-label">Category <span class="text-danger">*</span></label>
                                <select class="form-control" id="category_id" name="category_id" required>
                                    <option value="">Select Category</option>
                                    <?php foreach ($flatCategories as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>" 
                                            <?php echo $product['category_id'] == $cat['id'] ? 'selected' : ''; ?>>
                                            <?php echo str_repeat('-- ', $cat['level']); ?>
                                            <?php echo htmlspecialchars($cat['category_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text small">Only categories activated for your company are shown</div>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="brand" class="form-label">Brand</label>
                                <input type="text" class="form-control" id="brand" name="brand"
                                       value="<?php echo htmlspecialchars($product['brand'] ?? ''); ?>"
                                       placeholder="e.g., Samsung, Nike, Apple">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="unit_of_measure" class="form-label">Unit of Measure</label>
                                <select class="form-control" id="unit_of_measure" name="unit_of_measure">
                                    <option value="PCS" <?php echo ($product['unit_of_measure'] ?? 'PCS') == 'PCS' ? 'selected' : ''; ?>>Pieces (PCS)</option>
                                    <option value="KG" <?php echo ($product['unit_of_measure'] ?? '') == 'KG' ? 'selected' : ''; ?>>Kilograms (KG)</option>
                                    <option value="L" <?php echo ($product['unit_of_measure'] ?? '') == 'L' ? 'selected' : ''; ?>>Liters (L)</option>
                                    <option value="M" <?php echo ($product['unit_of_measure'] ?? '') == 'M' ? 'selected' : ''; ?>>Meters (M)</option>
                                    <option value="BOX" <?php echo ($product['unit_of_measure'] ?? '') == 'BOX' ? 'selected' : ''; ?>>Box</option>
                                    <option value="PKG" <?php echo ($product['unit_of_measure'] ?? '') == 'PKG' ? 'selected' : ''; ?>>Package</option>
                                    <option value="SET" <?php echo ($product['unit_of_measure'] ?? '') == 'SET' ? 'selected' : ''; ?>>Set</option>
                                </select>
                            </div>

                            <div class="col-md-6 mb-3">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                                           <?php echo $product['is_active'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="is_active">
                                        Product is active
                                    </label>
                                    <div class="form-text small">Inactive products won't appear in sales or purchase forms</div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="5" 
                                      placeholder="Product description, features, specifications..."><?php echo htmlspecialchars($product['description'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <!-- Product Stats Card -->
                        <div class="card mb-3">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-chart-line me-2"></i>Product Statistics</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-2">
                                    <small class="text-muted">Total Variants:</small>
                                    <div class="h5 mb-0"><?php echo $product['variant_count'] ?? 0; ?></div>
                                </div>
                                <div class="mb-2">
                                    <small class="text-muted">Total Stock:</small>
                                    <div class="h5 mb-0"><?php echo number_format((float)$product['total_stock'] ?? 0, 0); ?> units</div>
                                </div>
                                <div class="mb-2">
                                    <small class="text-muted">Price Range:</small>
                                    <div class="mb-0">
                                        <?php if (($product['min_price'] ?? 0) > 0): ?>
                                            <?php echo format_currency($product['min_price'] ?? 0, $companyId); ?>
                                            <?php if (($product['max_price'] ?? 0) > ($product['min_price'] ?? 0)): ?>
                                                - <?php echo format_currency($product['max_price'] ?? 0, $companyId); ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">No price set</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <hr>
                                <div>
                                    <small class="text-muted">Created:</small>
                                    <div class="small"><?php echo date('d/m/Y H:i', strtotime($product['created_at'])); ?></div>
                                </div>
                                <?php if (!empty($product['created_by_name'])): ?>
                                    <div>
                                        <small class="text-muted">Created By:</small>
                                        <div class="small"><?php echo htmlspecialchars($product['created_by_name']); ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Product Image (if you have image functionality) -->
                        <div class="card">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-image me-2"></i>Product Image</h6>
                            </div>
                            <div class="card-body text-center">
                                <?php if (!empty($product['image_url'])): ?>
                                    <img src="<?php echo asset_url($product['image_url']); ?>" 
                                         alt="Product Image" class="img-fluid mb-3 rounded" style="max-height: 200px; object-fit: contain;">
                                <?php else: ?>
                                    <div class="bg-light p-4 mb-3 rounded">
                                        <i class="fas fa-image fa-4x text-muted"></i>
                                    </div>
                                    <p class="text-muted small mb-0">No image available</p>
                                <?php endif; ?>
                                <?php if (in_array($userRole, ['ADM', 'MGR'])): ?>
                                    <div class="mt-2">
                                        <button type="button" class="btn btn-sm btn-outline-primary" disabled>
                                            <i class="fas fa-upload me-1"></i>Upload Image
                                        </button>
                                        <div class="form-text small">Image management coming soon</div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <hr class="my-4">

                <!-- Form Actions -->
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <?php if ($product['has_variants'] && !empty($variants)): ?>
                            <a href="?page=products/add-variant&product_id=<?php echo $productId; ?>" 
                               class="btn btn-outline-info me-2">
                                <i class="fas fa-plus me-1"></i>Add Variant
                            </a>
                        <?php endif; ?>
                    </div>
                    <div>
                        <a href="?page=products/view&id=<?php echo $productId; ?>" class="btn btn-secondary me-2">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Variants Section -->
    <?php
    // Get variants for this product
    $variants = $variantModel->getByProductId($productId);
    ?>
    
    <?php if ($product['has_variants']): ?>
        <div class="card shadow mt-4">
            <div class="card-header py-3 d-flex justify-content-between align-items-center">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-layer-group me-2"></i>Product Variants
                    <span class="badge bg-secondary ms-2"><?php echo count($variants); ?> variants</span>
                </h6>
                <a href="?page=products/manage-variants&product_id=<?php echo $productId; ?>" class="btn btn-sm btn-primary">
                    <i class="fas fa-edit me-1"></i>Manage Variants
                </a>
            </div>
            <div class="card-body">
                <?php if (empty($variants)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-layer-group fa-3x mb-3"></i>
                        <p>No variants added for this product</p>
                        <a href="?page=products/add-variant&product_id=<?php echo $productId; ?>" class="btn btn-sm btn-primary">
                            <i class="fas fa-plus me-1"></i>Add First Variant
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>SKU</th>
                                    <th>Variant Name</th>
                                    <th>Barcode</th>
                                    <th class="text-end">Purchase Price</th>
                                    <th class="text-end">Selling Price</th>
                                    <th class="text-end">Stock</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($variants as $variant): ?>
                                    <tr>
                                        <td><code><?php echo htmlspecialchars($variant['sku']); ?></code></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($variant['variant_name']); ?></strong>
                                            <?php if (!empty($variant['attributes_list'])): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars(substr($variant['attributes_list'], 0, 50)); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($variant['barcode'])): ?>
                                                <code><?php echo htmlspecialchars($variant['barcode']); ?></code>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end"><?php echo format_currency($variant['purchase_price'] ?? 0, $companyId); ?></td>
                                        <td class="text-end text-success fw-bold"><?php echo format_currency($variant['selling_price'] ?? 0, $companyId); ?></td>
                                        <td class="text-end">
                                            <?php
                                            $availableStock = ($variant['total_stock'] ?? 0) - ($variant['total_committed'] ?? 0);
                                            $stockClass = $availableStock > 0 ? 'success' : 'danger';
                                            ?>
                                            <span class="badge bg-<?php echo $stockClass; ?>">
                                                <?php echo number_format((float)$availableStock, 0); ?> units
                                            </span>
                                            <?php if (($variant['total_committed'] ?? 0) > 0): ?>
                                                <br><small class="text-muted">(<?php echo number_format((float)$variant['total_committed'], 0); ?> committed)</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($variant['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="?page=products/view-variant&id=<?php echo $variant['id']; ?>" 
                                                   class="btn btn-outline-primary" title="View Variant">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <?php if (in_array($userRole, ['ADM', 'MGR'])): ?>
                                                    <a href="?page=products/edit-variant&id=<?php echo $variant['id']; ?>" 
                                                       class="btn btn-outline-warning" title="Edit Variant">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <a href="?page=inventory/stock-adjust-variant&id=<?php echo $variant['id']; ?>" 
                                                       class="btn btn-outline-info" title="Adjust Stock">
                                                        <i class="fas fa-warehouse"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif (!$product['has_variants'] && empty($variants)): ?>
        <div class="card shadow mt-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-cube me-2"></i>Simple Product
                </h6>
            </div>
            <div class="card-body">
                <div class="alert alert-info mb-0">
                    <i class="fas fa-info-circle me-2"></i>
                    This is a simple product (no variants). 
                    <a href="?page=products/add-variant&product_id=<?php echo $productId; ?>" class="alert-link">
                        Click here to add variants
                    </a> if this product comes in different sizes, colors, or versions.
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
    .product-edit .table td {
        vertical-align: middle;
    }
    
    .product-edit .btn-group {
        gap: 4px;
    }
    
    .product-edit .bg-light {
        background-color: #f8f9fc !important;
    }
    
    .product-edit .form-text {
        font-size: 0.75rem;
    }
    
    .product-edit .card-header {
        background-color: #f8f9fc;
        border-bottom: 1px solid #e3e6f0;
    }
</style>