<?php
// pages/products/list.php
declare(strict_types=1);

$pageTitle = 'Products - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';

// Load models
require_once __DIR__ . '/../../models/Product.php';
require_once __DIR__ . '/../../models/Category.php';

$db = Database::getInstance();
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? 0;
$userRole = $_SESSION['user_role'] ?? 'VIW';

// Initialize models with company context
$productModel = new Product($companyId);
$categoryModel = new Category($companyId);

// Get filter parameters
$categoryId = isset($_GET['category']) ? (int) $_GET['category'] : null;
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? 'active';

// IMPORTANT: For status filter, we need to handle differently
// 'active' shows only active products
// 'inactive' shows only inactive products  
// 'all' shows both active and inactive

// Build where clause - products table has company_id directly
$where = "p.company_id = :company_id";
$params = ['company_id' => $companyId];

// Status filter
if ($status === 'active') {
    $where .= " AND p.is_active = 1";
} elseif ($status === 'inactive') {
    $where .= " AND p.is_active = 0";
}
// 'all' - no status filter

// Category filter - only show categories activated for this company
if ($categoryId) {
    // Verify category is activated for this company
    $categoryActive = $categoryModel->isActivatedForCompany($categoryId, $companyId);
    if (!$categoryActive) {
        // Invalid category for this company, ignore filter
        $categoryId = null;
    } else {
        $where .= " AND p.category_id = :category_id";
        $params['category_id'] = $categoryId;
    }
}

// Search filter
if (!empty($search)) {
    $where .= " AND (p.product_name LIKE :search OR p.product_code LIKE :search)";
    $params['search'] = "%{$search}%";
}

// Get products with their details
$sql = "
    SELECT 
        p.*,
        c.category_name,
        COUNT(DISTINCT v.id) as variant_count,
        COALESCE(SUM(i.quantity), 0) as total_stock,
        COALESCE(SUM(i.committed_quantity), 0) as total_committed,
        COALESCE(MIN(v.selling_price), 0) as min_price,
        COALESCE(MAX(v.selling_price), 0) as max_price
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN variants v ON p.id = v.product_id AND v.is_active = 1
    LEFT JOIN inventory i ON v.id = i.variant_id
    WHERE {$where}
    GROUP BY p.id
    ORDER BY p.created_at DESC
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Get ONLY categories that are ACTIVATED for this company
// Using the Category model's method that respects company activation
$categories = $categoryModel->getAllWithHierarchy();

// Flatten categories for dropdown
function flattenCategoriesForSelect($categories, $level = 0, &$result = [])
{
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
?>

<div class="products-list">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-boxes me-2"></i>Products
                    </h2>
                    <p class="mb-0 text-muted">Manage your product catalog and variants</p>
                </div>
                <div>
                    <?php if (in_array($userRole, ['ADM', 'MGR'])): ?>
                        <a href="?page=products/add" class="btn btn-primary">
                            <i class="fas fa-plus-circle me-2"></i>Add Product
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if ($flash = SessionManager::flash('success')): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($flash); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php elseif ($flash = SessionManager::flash('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($flash); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card shadow mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <input type="hidden" name="page" value="products">

                <div class="col-md-4">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" class="form-control" id="search" name="search"
                        value="<?php echo htmlspecialchars($search); ?>" placeholder="Product name or code...">
                </div>

                <div class="col-md-3">
                    <label for="category" class="form-label">Category</label>
                    <select class="form-control" id="category" name="category">
                        <option value="">All Categories</option>
                        <?php foreach ($flatCategories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo $categoryId == $cat['id'] ? 'selected' : ''; ?>>
                                <?php echo str_repeat('-- ', $cat['level']); ?>
                                <?php echo htmlspecialchars($cat['category_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-control" id="status" name="status">
                        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All</option>
                    </select>
                </div>

                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter me-2"></i>Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Products Table -->
    <div class="card shadow">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="productsTable">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Product Name</th>
                            <th>Category</th>
                            <th>Variants</th>
                            <th>Stock</th>
                            <th>Price Range</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($products)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <div class="text-muted">
                                        <i class="fas fa-box-open fa-3x mb-3"></i>
                                        <p class="mb-0">No products found</p>
                                        <?php if ($categoryId): ?>
                                            <small class="text-muted">Try changing your category filter</small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($products as $product): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($product['product_code']); ?></strong>
                                    </td>
                                    <td>
                                        <a href="?page=products/view&id=<?php echo $product['id']; ?>"
                                            class="text-decoration-none">
                                            <?php echo htmlspecialchars($product['product_name']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?php if ($product['category_name']): ?>
                                            <?php echo htmlspecialchars($product['category_name']); ?>
                                        <?php else: ?>
                                            <span class="text-muted">Uncategorized</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?php echo $product['variant_count']; ?> variants</span>
                                    </td>
                                    <td>
                                        <?php
                                        $availableStock = $product['total_stock'] - $product['total_committed'];
                                        $stockClass = 'success';
                                        if ($availableStock <= 0) {
                                            $stockClass = 'danger';
                                        } elseif ($availableStock <= 10) {
                                            $stockClass = 'warning';
                                        }
                                        ?>
                                        <span class="badge bg-<?php echo $stockClass; ?>">
                                            <?php echo number_format((float) $availableStock, 0); ?> units
                                        </span>
                                        <?php if ($product['total_committed'] > 0): ?>
                                            <small class="text-muted d-block">
                                                (<?php echo number_format((float) $product['total_committed'], 0); ?> committed)
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($product['min_price'] > 0): ?>
                                            <?php echo format_currency($product['min_price'], $companyId); ?>
                                            <?php if ($product['max_price'] > $product['min_price']): ?>
                                                - <?php echo format_currency($product['max_price'], $companyId); ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">No price</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($product['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="?page=products/view&id=<?php echo $product['id']; ?>"
                                                class="btn btn-outline-primary" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if (in_array($userRole, ['ADM', 'MGR'])): ?>
                                                <a href="?page=products/edit&id=<?php echo $product['id']; ?>"
                                                    class="btn btn-outline-warning" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <button type="button" class="btn btn-outline-danger"
                                                    onclick="deleteProduct(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars($product['product_name']); ?>')"
                                                    title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>Delete Product
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong id="deleteProductName"></strong>?</p>
                <p class="text-muted small">This action can be undone by reactivating the product.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="deleteConfirmBtn" class="btn btn-danger">Delete Product</a>
            </div>
        </div>
    </div>
</div>

<script>
    function deleteProduct(id, name) {
        document.getElementById('deleteProductName').textContent = name;
        document.getElementById('deleteConfirmBtn').href = '?page=products/delete&id=' + id;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }

    // Initialize DataTable
    $(document).ready(function () {
        if ($('#productsTable tbody tr').length > 1) {
            $('#productsTable').DataTable({
                pageLength: 25,
                ordering: true,
                searching: false,
                info: true,
                lengthChange: true,
                language: {
                    emptyTable: "No products found"
                },
                columnDefs: [
                    { orderable: false, targets: 7 }
                ]
            });
        }
    });
</script>

<style>
    .products-list .table td {
        vertical-align: middle;
    }

    .products-list .btn-group {
        gap: 4px;
    }

    .products-list .badge {
        font-weight: 500;
        padding: 0.5em 0.85em;
    }

    .products-list .table .badge {
        white-space: nowrap;
    }
</style>