<?php
// /public/search.php

require_once __DIR__ . '/../src/init.php';
require_once __DIR__ . '/../src/Models/Product.php';
require_once __DIR__ . '/../src/Models/Category.php'; // Updated model to read shop_product_classes
require_once __DIR__ . '/../src/Helpers/SessionManager.php';

// Set page title
$pageTitle = 'Search Products';

// Initialize models
$productModel = new Product();
$categoryModel = new Category();

// Get filter parameters (using slug for tracking chosen structural categories cleanly)
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$categorySlug = isset($_GET['category']) ? trim($_GET['category']) : '';
$sellerType = isset($_GET['seller_type']) ? $_GET['seller_type'] : 'all';
$minPrice = isset($_GET['min_price']) ? (float)$_GET['min_price'] : null;
$maxPrice = isset($_GET['max_price']) ? (float)$_GET['max_price'] : null;
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'relevance';

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? min(48, max(12, (int)$_GET['limit'])) : 24;
$offset = ($page - 1) * $limit;

// Fetch all Level 1 root categories for display in the sidebar
$allCategories = $categoryModel->getActiveRootCategories();

// Fetch currently selected category parameters if applicable
$currentCategoryName = 'All Categories';
if (!empty($categorySlug)) {
    $currentCategory = $categoryModel->getCategoryBySlug($categorySlug);
    if ($currentCategory) {
        $currentCategoryName = $currentCategory['name'];
    }
}

// Build the products query with hierarchical filters
$products = [];
$totalProducts = 0;

try {
    $db = Database::getInstance()->getConnection();
    
    // Base query conditions
    $conditions = ["p.is_active = 1", "comp.is_public = 1", "comp.is_active = 1"];
    $params = [];
    
    // Search query
    if (!empty($searchQuery)) {
        $conditions[] = "(p.product_name LIKE :search OR p.description LIKE :search OR p.product_code LIKE :search)";
        $params[':search'] = '%' . $searchQuery . '%';
    }
    
    // Hierarchical Category matching
    if (!empty($categorySlug) && isset($currentCategory)) {
        $catId = (int)$currentCategory['id'];
        
        // This safe structural logic checks if target item matches the Category itself, 
        // its subcategory parent, or its parent's root category.
        $conditions[] = "(
            p.shop_product_classe_id = :cat_id 
            OR p.shop_product_classe_id IN (SELECT id FROM shop_product_classes WHERE parent_id = :cat_id)
            OR p.shop_product_classe_id IN (SELECT id FROM shop_product_classes WHERE parent_id IN (SELECT id FROM shop_product_classes WHERE parent_id = :cat_id))
        )";
        $params[':cat_id'] = $catId;
    }
    
    // Seller type filter
    if ($sellerType === 'corporate') {
        $conditions[] = "comp.company_type = 'corporate'";
    } elseif ($sellerType === 'peer') {
        $conditions[] = "(comp.company_type IS NULL OR comp.company_type = 'individual')";
    }
    
    // Price range filters
    if ($minPrice !== null && $minPrice > 0) {
        $conditions[] = "v_min.selling_price >= :min_price";
        $params[':min_price'] = $minPrice;
    }
    if ($maxPrice !== null && $maxPrice > 0) {
        $conditions[] = "v_min.selling_price <= :max_price";
        $params[':max_price'] = $maxPrice;
    }
    
    $whereClause = implode(" AND ", $conditions);
    
    // Count query
    $countSql = "SELECT COUNT(DISTINCT p.id) as total 
                 FROM products p
                 INNER JOIN companies comp ON p.company_id = comp.id
                 INNER JOIN shop_product_classes spc ON spc.id = p.shop_product_classe_id
                 LEFT JOIN variants v_min ON v_min.product_id = p.id AND v_min.company_id = comp.id AND v_min.is_active = 1
                 WHERE $whereClause";
    
    $countStmt = $db->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $countStmt->execute();
    $totalProducts = $countStmt->fetch()['total'] ?? 0;
    
    // Main query
    $sql = "SELECT DISTINCT 
                p.id,
                p.product_code,
                p.product_name,
                p.description,
                p.brand,
                p.created_at,
                comp.id as company_id,
                comp.company_name,
                comp.slug as company_slug,
                comp.currency,
                comp.company_type,
                spc.name as category_name,
                MIN(v.selling_price) as price_from,
                MAX(v.selling_price) as price_to
            FROM products p
            INNER JOIN companies comp ON p.company_id = comp.id
            INNER JOIN shop_product_classes spc ON spc.id = p.shop_product_classe_id
            LEFT JOIN variants v ON v.product_id = p.id AND v.company_id = comp.id AND v.is_active = 1
            WHERE $whereClause
            GROUP BY p.id
            ORDER BY " . getSortOrder($sort) . "
            LIMIT :limit OFFSET :offset";
    
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $products = $stmt->fetchAll();
    
    // Process items formatting
    foreach ($products as &$product) {
        $product['primary_image'] = $productModel->getPrimaryImage($product['id']);
        if (!$product['primary_image']) {
            $product['primary_image'] = '/assets/img/placeholder.jpg';
        }
        $product['price_formatted'] = number_format($product['price_from'], 0, ',', ' ') . ' RWF';
        if ($product['price_to'] && $product['price_to'] > $product['price_from']) {
            $product['price_range'] = number_format($product['price_from'], 0, ',', ' ') . ' - ' . 
                                      number_format($product['price_to'], 0, ',', ' ') . ' RWF';
        }
        $product['seller_badge'] = ($product['company_type'] === 'corporate') ? '🏢 Corporate' : '👤 Individual';
    }
    
} catch (PDOException $e) {
    error_log("Search Error: " . $e->getMessage());
    $products = [];
    $totalProducts = 0;
}

// Calculate pagination
$totalPages = ceil($totalProducts / $limit);
$startItem = $totalProducts > 0 ? ($page - 1) * $limit + 1 : 0;
$endItem = min($page * $limit, $totalProducts);

function getSortOrder($sort) {
    switch ($sort) {
        case 'price_asc': return 'price_from ASC';
        case 'price_desc': return 'price_from DESC';
        case 'name_asc': return 'p.product_name ASC';
        case 'name_desc': return 'p.product_name DESC';
        case 'newest': return 'p.created_at DESC';
        case 'oldest': return 'p.created_at ASC';
        default: return 'p.product_name ASC';
    }
}

include_once __DIR__ . '/../templates/header.php';
?>

<style>
/* ============================================
   SEARCH PAGE INTERFACE LAYOUT 
   ============================================ */
.search-container {
    max-width: 1200px;
    margin: 2rem auto;
    padding: 0 20px;
}

.search-header {
    margin-bottom: 2rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--border-color);
}

.search-header h1 {
    font-size: 1.8rem;
    color: var(--dark-color);
    margin-bottom: 0.25rem;
}

.search-summary {
    font-size: 0.9rem;
    color: var(--gray-color);
}

.search-layout {
    display: grid;
    grid-template-columns: 280px 1fr;
    gap: 2rem;
    align-items: flex-start;
}

/* ============================================
   EFFICIENT SIDEBAR FILTER BLOCK
   ============================================ */
.search-sidebar {
    background: var(--bg-card, #ffffff);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: var(--shadow-sm);
    position: sticky;
    top: 100px;
}

.filter-section {
    border-bottom: 1px solid var(--border-light, #eeeeee);
    padding-bottom: 1.25rem;
    margin-bottom: 1.25rem;
}

.filter-section:last-of-type {
    border-bottom: none;
    padding-bottom: 0;
    margin-bottom: 1.5rem;
}

.filter-section h3 {
    font-size: 0.95rem;
    color: var(--dark-color);
    margin-bottom: 0.75rem;
    font-weight: 700;
}

/* Uniform Filter Field Utilities */
.filter-select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 0.85rem;
    background-color: var(--bg-card);
    color: var(--text-main);
    outline: none;
    cursor: pointer;
    transition: var(--transition);
}

.filter-select:focus {
    border-color: var(--primary-color);
}

/* Structural Lists Navigation */
.category-list {
    list-style: none;
    padding: 0;
    margin: 0;
    max-height: 240px;
    overflow-y: auto;
}

.category-list li {
    margin-bottom: 0.25rem;
}

.category-list li a {
    display: block;
    padding: 6px 10px;
    font-size: 0.85rem;
    color: var(--gray-color);
    text-decoration: none;
    border-radius: 4px;
    transition: var(--transition);
}

.category-list li a:hover {
    background-color: var(--light-color);
    color: var(--primary-color);
}

.category-list li a.active {
    background-color: var(--primary-light, #667eea);
    color: #ffffff;
    font-weight: 600;
}

/* Radio Field Wrappers */
.filter-group {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.filter-group label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
    color: var(--text-main);
    cursor: pointer;
}

.filter-group input[type="radio"] {
    accent-color: var(--primary-color);
    width: 16px;
    height: 16px;
}

/* Price Range Inputs Elements */
.price-inputs {
    display: grid;
    grid-template-columns: 1fr auto 1fr;
    align-items: center;
    gap: 0.5rem;
}

.price-inputs input {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 0.85rem;
    outline: none;
    text-align: center;
}

.price-inputs input:focus {
    border-color: var(--primary-color);
}

.price-inputs span {
    color: var(--gray-light);
    font-size: 0.85rem;
}

/* Filter Submit CTA controls */
.filter-actions {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.apply-filters-btn {
    width: 100%;
    padding: 10px;
    background: var(--primary-color);
    color: white;
    border: none;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: var(--transition);
}

.apply-filters-btn:hover {
    background: var(--primary-dark);
}

.reset-filters {
    width: 100%;
    padding: 10px;
    background: transparent;
    color: var(--gray-color);
    border: 1px solid var(--border-color);
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    transition: var(--transition);
    text-align: center;
    text-decoration: none;
}

.reset-filters:hover {
    background: var(--light-color);
    color: var(--text-main);
}

/* Responsive Structural Overrides */
.mobile-filter-toggle {
    display: none;
    background: var(--primary-color);
    color: white;
    padding: 12px;
    text-align: center;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    margin-bottom: 1.5rem;
}

@media (max-width: 991px) {
    .search-layout {
        grid-template-columns: 1fr;
    }
    
    .mobile-filter-toggle {
        display: block;
    }
    
    .search-sidebar {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        z-index: 2000;
        overflow-y: auto;
        border-radius: 0;
        padding: 2rem;
    }
    
    .search-sidebar.open {
        display: block;
    }
}
</style>

<div class="search-container">
    <div class="search-header">
        <h1>
            <?php if (!empty($searchQuery)): ?>
                Search Results for "<?php echo htmlspecialchars($searchQuery); ?>"
            <?php else: ?>
                <?php echo htmlspecialchars($currentCategoryName); ?>
            <?php endif; ?>
        </h1>
        <div class="search-summary">
            Found <?php echo number_format($totalProducts); ?> product<?php echo $totalProducts !== 1 ? 's' : ''; ?>
            <?php if ($totalProducts > 0): ?>
                (Showing <?php echo $startItem; ?> - <?php echo $endItem; ?>)
            <?php endif; ?>
        </div>
    </div>
    
    <div class="mobile-filter-toggle" onclick="toggleFilters()">
        <i class="fas fa-filter"></i> Filter & Sort Options
    </div>
    
    <div class="search-layout">
        <aside class="search-sidebar" id="search-sidebar">
            <form method="GET" action="" id="filter-form">
                <?php if (!empty($searchQuery)): ?>
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>">
                <?php endif; ?>
                
                <!-- Hidden inputs to preserve category structure across submissions -->
                <?php if (!empty($categorySlug)): ?>
                    <input type="hidden" name="category" value="<?php echo htmlspecialchars($categorySlug); ?>">
                <?php endif; ?>

                <div class="filter-section">
                    <h3>Sort Order</h3>
                    <select name="sort" class="filter-select" onchange="this.form.submit()">
                        <option value="relevance" <?php echo $sort === 'relevance' ? 'selected' : ''; ?>>Relevance</option>
                        <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                        <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                        <option value="price_asc" <?php echo $sort === 'price_asc' ? 'selected' : ''; ?>>Price: Low to High</option>
                        <option value="price_desc" <?php echo $sort === 'price_desc' ? 'selected' : ''; ?>>Price: High to Low</option>
                        <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Name: A to Z</option>
                        <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Name: Z to A</option>
                    </select>
                </div>
                
                <div class="filter-section">
                    <h3>Categories</h3>
                    <ul class="category-list">
                        <li>
                            <a href="<?php echo buildFilterUrl(['category' => null, 'page' => null]); ?>" 
                               class="<?php echo empty($categorySlug) ? 'active' : ''; ?>">
                                All Categories
                            </a>
                        </li>
                        <?php foreach ($allCategories as $cat): ?>
                            <li>
                                <a href="<?php echo buildFilterUrl(['category' => $cat['slug'], 'page' => null]); ?>" 
                                   class="<?php echo $categorySlug === $cat['slug'] ? 'active' : ''; ?>">
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                
                <div class="filter-section">
                    <h3>Seller Profile</h3>
                    <div class="filter-group">
                        <label>
                            <input type="radio" name="seller_type" value="all" <?php echo $sellerType === 'all' ? 'checked' : ''; ?> onchange="this.form.submit()"> All Verification Types
                        </label>
                        <label>
                            <input type="radio" name="seller_type" value="corporate" <?php echo $sellerType === 'corporate' ? 'checked' : ''; ?> onchange="this.form.submit()"> Corporate Stores
                        </label>
                        <label>
                            <input type="radio" name="seller_type" value="peer" <?php echo $sellerType === 'peer' ? 'checked' : ''; ?> onchange="this.form.submit()"> Individual Sellers
                        </label>
                    </div>
                </div>
                
                <div class="filter-section">
                    <h3>Price Evaluation (RWF)</h3>
                    <div class="price-inputs">
                        <input type="number" name="min_price" placeholder="Min" value="<?php echo $minPrice ?: ''; ?>" min="0" step="500">
                        <span>to</span>
                        <input type="number" name="max_price" placeholder="Max" value="<?php echo $maxPrice ?: ''; ?>" min="0" step="500">
                    </div>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="apply-filters-btn">Apply Constraints</button>
                    <button type="button" class="reset-filters" onclick="resetFilters()">Clear Filters</button>
                </div>
            </form>
        </aside>
        
        <main class="search-main">
            <?php if (!empty($products)): ?>
                <div class="products-grid" id="products-grid">
                    <?php foreach ($products as $product): ?>
                        <div class="product-card">
                            <div class="product-image">
                                <img src="<?php echo htmlspecialchars($product['primary_image']); ?>" alt="<?php echo htmlspecialchars($product['product_name']); ?>" loading="lazy" onerror="this.src='/assets/img/placeholder.jpg'">
                                <span class="seller-badge <?php echo ($product['company_type'] === 'corporate') ? 'seller-corporate' : 'seller-peer'; ?>">
                                    <?php echo $product['seller_badge']; ?>
                                </span>
                            </div>
                            <div class="product-info">
                                <h3 class="product-title">
                                    <a href="/product.php?id=<?php echo $product['id']; ?>">
                                        <?php echo htmlspecialchars($product['product_name']); ?>
                                    </a>
                                </h3>
                                <div class="vendor-name"><i class="fas fa-store"></i> <?php echo htmlspecialchars($product['company_name']); ?></div>
                                <div class="product-price"><?php echo $product['price_formatted']; ?></div>
                                <?php if (isset($product['price_range'])): ?><div class="product-description" style="color:var(--gray-color);">Range: <?php echo $product['price_range']; ?></div><?php endif; ?>
                                <?php if (!empty($product['description'])): ?><div class="product-description"><?php echo htmlspecialchars(substr($product['description'], 0, 80)); ?>...</div><?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Simple structural pagination wrapper styling -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <a href="<?php echo buildFilterUrl(['page' => $i]); ?>" class="page-link <?php echo $page === $i ? 'current' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="empty-chat text-center" style="padding: 4rem; background: var(--bg-card); border-radius: 12px; border: 1px solid var(--border-color);">
                    <div class="empty-chat-icon"><i class="fas fa-search" style="font-size: 3rem; color: var(--gray-light);"></i></div>
                    <h4 class="mt-2">No matching products found</h4>
                    <p class="mb-2" style="color: var(--gray-color);">Try adjusting your parameters or broadening your query.</p>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<script>
function toggleFilters() {
    const sidebar = document.getElementById('search-sidebar');
    sidebar.classList.toggle('open');
}

function resetFilters() {
    // Structural redirect back to baseline query clean state
    const urlParams = new URLSearchParams(window.location.search);
    const searchVal = urlParams.get('search');
    if (searchVal) {
        window.location.href = window.location.pathname + '?search=' + encodeURIComponent(searchVal);
    } else {
        window.location.href = window.location.pathname;
    }
}
</script>

<?php 
function buildFilterUrl($changes) {
    $params = $_GET;
    foreach ($changes as $key => $value) {
        if ($value === null) unset($params[$key]);
        else $params[$key] = $value;
    }
    return empty($params) ? 'search.php' : 'search.php?' . http_build_query($params);
}
include_once __DIR__ . '/../templates/footer.php'; 
?>