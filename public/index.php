<?php
// /public/index.php
// Home Page - Product listing


require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/init.php';

$customer = SessionManager::getCustomer();
$productModel = new Product();
$categoryModel = new Category();

// Get parameters
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$categoryId = isset($_GET['category']) ? (int)$_GET['category'] : null;
$search = isset($_GET['search']) ? trim($_GET['search']) : null;
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'newest';

// Get products
$productsData = $productModel->getPublicProducts($page, ITEMS_PER_PAGE, $categoryId, $search, $sort);
$categories = $categoryModel->getActiveCategories();

$pageTitle = $search ? "Search: $search" : ($categoryId ? "Category Products" : "Our Products");

include __DIR__ . '/../templates/header.php';
?>

<main class="container">
    <!-- Hero Section -->
    <section class="hero">
        <h1>Welcome to <?php echo SITE_NAME; ?></h1>
        <p>Discover amazing products from trusted vendors</p>
        <form class="search-form" action="index.php" method="GET">
            <input type="text" name="search" placeholder="Search products..." value="<?php echo htmlspecialchars($search ?? ''); ?>">
            <button type="submit">Search</button>
        </form>
    </section>

    <!-- Products Section -->
    <section class="products-section">
        <div class="products-header">
            <div class="products-header-main">
                <h2><?php echo htmlspecialchars($pageTitle); ?></h2>
            </div>
            
            <div class="products-toolbar-controls">

                <div class="seller-type-filter-group">
                    <a href="./?seller_type=all&page=<?php echo $page; ?>" class="toolbar-filter-btn <?php echo $sellerType === 'all' ? 'active' : ''; ?>">
                        <i class="fas fa-th-large"></i> Corporate & Individual
                    </a>
                    <a href="./?seller_type=corporate&page=<?php echo $page; ?>" 
                    class="toolbar-filter-btn <?php echo $sellerType === 'corporate' ? 'active' : ''; ?>">
                        <i class="fas fa-building"></i> Corporate
                    </a>
                    <a href="./?seller_type=peer&page=<?php echo $page; ?>" 
                    class="toolbar-filter-btn <?php echo $sellerType === 'peer' ? 'active' : ''; ?>">
                        <i class="fas fa-user"></i> Individual
                    </a>
                </div>

                <div class="sort-options-wrapper">
                    <label for="sort-select"><i class="fas fa-sort-amount-down"></i> Sort by:</label>
                    <select id="sort-select" class="toolbar-sort-select" onchange="window.location.href=buildFilterUrlWithJs('sort', this.value)">
                        <option value="relevance" <?php echo $sort === 'relevance' ? 'selected' : ''; ?>>Relevance</option>
                        <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest</option>
                        <option value="price_asc" <?php echo $sort === 'price_asc' ? 'selected' : ''; ?>>Price: Low to High</option>
                        <option value="price_desc" <?php echo $sort === 'price_desc' ? 'selected' : ''; ?>>Price: High to Low</option>
                        <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Name: A to Z</option>
                        <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Name: Z to A</option>
                    </select>
                </div>
            </div>
        </div>

        <?php if (empty($productsData['products'])): ?>
            <div class="no-products">
                <p>No products found.</p>
                <a href="index.php" class="btn">Browse All Products</a>
            </div>
        <?php else: ?>
            <div class="products-grid">
                <?php foreach ($productsData['products'] as $product): ?>
                    <?php include __DIR__ . '/../templates/product_card.php'; ?>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($productsData['total_pages'] > 1): ?>
            <div class="pagination">
                <?php if ($productsData['current_page'] > 1): ?>
                    <a href="?page=<?php echo $productsData['current_page'] - 1; ?>&category=<?php echo $categoryId; ?>&search=<?php echo urlencode($search ?? ''); ?>&sort=<?php echo $sort; ?>" class="page-link">&laquo; Previous</a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $productsData['total_pages']; $i++): ?>
                    <?php if ($i == $productsData['current_page']): ?>
                        <span class="page-link current"><?php echo $i; ?></span>
                    <?php elseif ($i <= 3 || $i > $productsData['total_pages'] - 3 || abs($i - $productsData['current_page']) <= 2): ?>
                        <a href="?page=<?php echo $i; ?>&category=<?php echo $categoryId; ?>&search=<?php echo urlencode($search ?? ''); ?>&sort=<?php echo $sort; ?>" class="page-link"><?php echo $i; ?></a>
                    <?php elseif ($i == 4 && $productsData['current_page'] > 5): ?>
                        <span class="page-dots">...</span>
                    <?php elseif ($i == $productsData['total_pages'] - 3 && $productsData['current_page'] < $productsData['total_pages'] - 4): ?>
                        <span class="page-dots">...</span>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($productsData['current_page'] < $productsData['total_pages']): ?>
                    <a href="?page=<?php echo $productsData['current_page'] + 1; ?>&category=<?php echo $categoryId; ?>&search=<?php echo urlencode($search ?? ''); ?>&sort=<?php echo $sort; ?>" class="page-link">Next &raquo;</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>

<script>
function updateSortParam(value) {
    let url = new URL(window.location.href);
    url.searchParams.set('sort', value);
    url.searchParams.set('page', 1);
    return url.toString();
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>