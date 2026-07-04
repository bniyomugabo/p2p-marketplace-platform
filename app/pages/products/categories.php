<?php
// pages/products/categories.php
declare(strict_types=1);

$pageTitle = 'Product Categories - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';

// Load models
require_once __DIR__ . '/../../models/Category.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? 0;

// Check permission
if (!in_array($userRole, ['ADM', 'MGR'])) {
    SessionManager::flash('error', 'You do not have permission to manage categories.');
    header('Location: ?page=products');
    exit;
}

// Initialize model with company context
$categoryModel = new Category($companyId);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
        SessionManager::flash('error', 'Invalid security token. Please try again.');
        header('Location: ?page=products/categories');
        exit;
    }

    // Add category (activate global category or create new)
    if (isset($_POST['add_category'])) {
        try {
            // Validate
            if (empty($_POST['category_name'])) {
                throw new Exception('Category name is required');
            }

            $categoryName = trim($_POST['category_name']);
            $parentId = !empty($_POST['parent_id']) ? (int) $_POST['parent_id'] : null;
            $description = trim($_POST['description'] ?? '');
            $isActive = isset($_POST['is_active']) ? 1 : 1;

            // Generate category code
            $code = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $categoryName), 0, 4));
            $code .= rand(10, 99);

            // Check if category already exists in global categories
            $existingCategory = $categoryModel->findByName($categoryName);

            if ($existingCategory) {
                // Category exists globally, just activate it for this company
                $categoryId = $existingCategory['id'];

                // Check if already activated
                if ($categoryModel->isActivatedForCompany($categoryId, $companyId)) {
                    throw new Exception('Category already exists in your company');
                }

                // Activate for company
                if ($categoryModel->activateForCompany($categoryId, $companyId, $parentId)) {
                    SessionManager::flash('success', 'Category activated successfully!');
                } else {
                    throw new Exception('Failed to activate category');
                }
            } else {
                // Create new global category
                $categoryData = [
                    'category_code' => $code,
                    'category_name' => $categoryName
                ];

                $categoryId = $categoryModel->createGlobalCategory($categoryData);

                if (!$categoryId) {
                    throw new Exception('Failed to create category');
                }

                // Activate for company
                if ($categoryModel->activateForCompany($categoryId, $companyId, $parentId, $description)) {
                    SessionManager::flash('success', 'Category created and activated successfully!');
                } else {
                    throw new Exception('Category created but failed to activate');
                }
            }

        } catch (Exception $e) {
            SessionManager::flash('error', 'Failed to add category: ' . $e->getMessage());
        }

        header('Location: ?page=products/categories');
        exit;
    }

    // Edit category (update parent and activation)
    if (isset($_POST['edit_category'])) {
        try {
            $id = (int) $_POST['category_id'];
            $newParentId = !empty($_POST['parent_id']) ? (int) $_POST['parent_id'] : null;
            $description = trim($_POST['description'] ?? '');
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            // Check if category is activated for this company
            if (!$categoryModel->isActivatedForCompany($id, $companyId)) {
                throw new Exception('Category not found or not activated for your company');
            }
            $data = [
                'category_id' => $id,
                'company_id' => $companyId,
                'parent_id' => $newParentId,
                'description' => $description
            ];

            $categoryModel->updateCompanyCategory($data);


            SessionManager::flash('success', 'Category updated successfully!');

        } catch (Exception $e) {
            SessionManager::flash('error', 'Failed to update category: ' . $e->getMessage());
        }

        header('Location: ?page=products/categories');
        exit;
    }

    // Delete category (deactivate from company)
    if (isset($_POST['delete_category'])) {
        try {
            $id = (int) $_POST['category_id'];

            // Check if category is activated for this company
            if (!$categoryModel->isActivatedForCompany($id, $companyId)) {
                throw new Exception('Category not found or not activated for your company');
            }

            // Check if category has products
            $sql = "SELECT COUNT(*) as count FROM products WHERE category_id = :id AND company_id = :company_id AND is_active = 1";
            $stmt = $db->prepare($sql);
            $stmt->execute(['id' => $id, 'company_id' => $companyId]);
            $result = $stmt->fetch();

            if ($result['count'] > 0) {
                throw new Exception('Cannot deactivate category with associated products. Move or delete products first.');
            }

            // Deactivate from company (remove from category_company)
            if ($categoryModel->deactivateForCompany($id, $companyId)) {
                SessionManager::flash('success', 'Category deactivated successfully!');
            } else {
                throw new Exception('Failed to deactivate category');
            }

        } catch (Exception $e) {
            SessionManager::flash('error', 'Failed to delete category: ' . $e->getMessage());
        }

        header('Location: ?page=products/categories');
        exit;
    }
}

// Get all activated categories with hierarchy for this company
$categories = $categoryModel->getAllWithHierarchy();

// Get flat list for parent selection (only activated categories)
$categoryOptions = $categoryModel->getOptions();

// Generate CSRF token
$csrfToken = CSRF::generate();

// Helper function to get parent name by ID
function getParentName($parentId, $options)
{
    foreach ($options as $option) {
        if ($option['value'] == $parentId) {
            return $option['text'];
        }
    }
    return '-';
}
?>

<div class="categories-management">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-tags me-2"></i>Product Categories
                    </h2>
                    <p class="mb-0 text-muted">Manage product categories and hierarchy for your company</p>
                </div>
                <div>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal"
                        data-bs-target="#addCategoryModal">
                        <i class="fas fa-plus-circle me-2"></i>Add Category
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if ($flash = SessionManager::flash('success')): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($flash); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php elseif ($flash = SessionManager::flash('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($flash); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Categories List -->
    <div class="row">
        <div class="col-12">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-sitemap me-2"></i>Category Hierarchy
                    </h6>
                </div>
                <div class="card-body">
                    <?php if (empty($categories)): ?>
                        <div class="text-center py-4">
                            <div class="text-muted">
                                <i class="fas fa-folder-open fa-3x mb-3"></i>
                                <p class="mb-0">No categories found. Create your first category!</p>
                                <small class="text-muted">Categories are shared globally but activated per company</small>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Code</th>
                                        <th>Category Name</th>
                                        <th>Parent</th>
                                        <th>Description</th>
                                        <th>Products</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (flattenCategories($categories) as $category): ?>
                                        <tr>
                                            <td>
                                                <strong>
                                                    <?php echo htmlspecialchars($category['category_code']); ?>
                                                </strong>
                                            </td>
                                            <td>
                                                <?php echo str_repeat('&nbsp;&nbsp;&nbsp;', $category['level']); ?>
                                                <?php if ($category['level'] > 0): ?>
                                                    <i class="fas fa-level-down-alt text-muted me-1"></i>
                                                <?php endif; ?>
                                                <?php echo htmlspecialchars($category['category_name']); ?>
                                            </td>
                                            <td>
                                                <?php if ($category['parent_id']): ?>
                                                    <?php echo htmlspecialchars(getParentName($category['parent_id'], $categoryOptions)); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($category['description']): ?>
                                                    <?php echo htmlspecialchars(substr($category['description'], 0, 50)) . (strlen($category['description']) > 50 ? '...' : ''); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                // Get product count for this company only
                                                $sql = "SELECT COUNT(*) as count FROM products 
                                                        WHERE category_id = :id 
                                                        AND company_id = :company_id 
                                                        AND is_active = 1";
                                                $stmt = $db->prepare($sql);
                                                $stmt->execute([
                                                    'id' => $category['id'],
                                                    'company_id' => $companyId
                                                ]);
                                                $count = $stmt->fetch()['count'];
                                                ?>
                                                <span class="badge bg-info">
                                                    <?php echo $count; ?> products
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($category['is_active']): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-warning"
                                                        onclick="editCategory(<?php echo htmlspecialchars(json_encode($category)); ?>)"
                                                        title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-outline-danger"
                                                        onclick="deleteCategory(<?php echo $category['id']; ?>, '<?php echo htmlspecialchars($category['category_name']); ?>')"
                                                        title="Deactivate">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
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
        </div>
    </div>
</div>

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle me-2 text-primary"></i>Add New Category
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="add_category" value="1">

                <div class="modal-body">
                    <div class="mb-3">
                        <label for="category_name" class="form-label">Category Name <span
                                class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="category_name" name="category_name" required>
                        <small class="text-muted">If category exists globally, it will be activated for your
                            company</small>
                    </div>

                    <div class="mb-3">
                        <label for="parent_id" class="form-label">Parent Category</label>
                        <select class="form-control" id="parent_id" name="parent_id">
                            <option value="">None (Top Level)</option>
                            <?php foreach ($categoryOptions as $option): ?>
                                <option value="<?php echo $option['value']; ?>">
                                    <?php echo str_repeat('-- ', substr_count($option['text'], '-- ')); ?>
                                    <?php echo htmlspecialchars($option['text']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                    </div>

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                            checked>
                        <label class="form-check-label" for="is_active">
                            Active
                        </label>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Category Modal -->
<div class="modal fade" id="editCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit me-2 text-warning"></i>Edit Category
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="edit_category" value="1">
                <input type="hidden" name="category_id" id="edit_category_id">

                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_category_code" class="form-label">Category Code</label>
                        <input type="text" class="form-control" id="edit_category_code" readonly disabled>
                    </div>

                    <div class="mb-3">
                        <label for="edit_category_name" class="form-label">Category Name</label>
                        <input type="text" class="form-control" id="edit_category_name" readonly disabled>
                        <small class="text-muted">Category name cannot be changed. Create a new category if
                            needed.</small>
                    </div>

                    <div class="mb-3">
                        <label for="edit_parent_id" class="form-label">Parent Category</label>
                        <select class="form-control" id="edit_parent_id" name="parent_id">
                            <option value="">None (Top Level)</option>
                            <?php foreach ($categoryOptions as $option): ?>
                                <option value="<?php echo $option['value']; ?>">
                                    <?php echo str_repeat('-- ', substr_count($option['text'], '-- ')); ?>
                                    <?php echo htmlspecialchars($option['text']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Changing parent will update the category hierarchy</small>
                    </div>

                    <div class="mb-3">
                        <label for="edit_description" class="form-label">Description</label>
                        <textarea class="form-control" id="edit_description" name="description" rows="3"></textarea>
                    </div>

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active" value="1">
                        <label class="form-check-label" for="edit_is_active">
                            Active
                        </label>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Update Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>Deactivate Category
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="delete_category" value="1">
                <input type="hidden" name="category_id" id="delete_category_id">

                <div class="modal-body">
                    <p>Are you sure you want to deactivate <strong id="deleteCategoryName"></strong>?</p>
                    <p class="text-danger small">
                        This will remove the category from your company's view. The category will still exist globally.
                        Products using this category will need to be reassigned.
                    </p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Deactivate Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
// Helper function to flatten hierarchical categories
function flattenCategories($categories, $level = 0, &$result = [])
{
    foreach ($categories as $category) {
        $category['level'] = $level;
        $result[] = $category;
        if (!empty($category['children'])) {
            flattenCategories($category['children'], $level + 1, $result);
        }
    }
    return $result;
}
?>

<script>
    function editCategory(category) {
        document.getElementById('edit_category_id').value = category.id;
        document.getElementById('edit_category_code').value = category.category_code;
        document.getElementById('edit_category_name').value = category.category_name;
        document.getElementById('edit_parent_id').value = category.parent_id || '';
        document.getElementById('edit_description').value = category.description || '';
        document.getElementById('edit_is_active').checked = category.is_active == 1;

        new bootstrap.Modal(document.getElementById('editCategoryModal')).show();
    }

    function deleteCategory(id, name) {
        document.getElementById('delete_category_id').value = id;
        document.getElementById('deleteCategoryName').textContent = name;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }
</script>

<style>
    .categories-management .table td {
        vertical-align: middle;
    }

    .categories-management .btn-group {
        gap: 4px;
    }

    .categories-management .fa-level-down-alt {
        font-size: 0.8rem;
        opacity: 0.6;
    }
</style>