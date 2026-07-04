<?php
// /public/seller.php

require_once __DIR__ . '/../src/init.php';
require_once __DIR__ . '/../src/Models/Product.php';
require_once __DIR__ . '/../src/Models/Seller.php';
require_once __DIR__ . '/../src/Helpers/SessionManager.php';

// Set page title
$pageTitle = 'Seller Profile';

// Get seller ID from URL
$sellerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$sellerSlug = isset($_GET['slug']) ? trim($_GET['slug']) : '';
$sellerType = isset($_GET['type']) ? $_GET['type'] : 'company'; // 'company' or 'individual'

if ($sellerId <= 0 && empty($sellerSlug)) {
    header('Location: ./');
    exit;
}

// Initialize models
$productModel = new Product();
$sellerModel = new Seller();

// Get seller information based on type
$seller = null;
$isP2PSeller = ($sellerType === 'individual');

if ($isP2PSeller) {
    // Get P2P seller (customer with a store)
    if ($sellerId > 0) {
        $seller = $sellerModel->getP2PSellerById($sellerId);
    } elseif (!empty($sellerSlug)) {
        $seller = $sellerModel->getP2PSellerBySlug($sellerSlug);
    }
} else {
    // Get B2C seller (company)
    if ($sellerId > 0) {
        $seller = $sellerModel->getSellerById($sellerId);
    } elseif (!empty($sellerSlug)) {
        $seller = $sellerModel->getSellerBySlug($sellerSlug);
    }
}

// Check if seller exists and is public/active
if (!$seller) {
    header('Location: ./');
    exit;
}

// Set page title
$pageTitle = htmlspecialchars($seller['seller_name']) . ' - Seller Profile';

// Get filter parameters
$category = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? min(48, max(12, (int)$_GET['limit'])) : 24;
$offset = ($page - 1) * $limit;

// Get seller's products
$products = [];
$totalProducts = 0;

try {
    $db = Database::getInstance()->getConnection();
    
    if ($isP2PSeller) {
        // Get P2P products from customer_store_products
        $conditions = [
            "csp.customer_store_id = :store_id",
            "csp.is_active = 1"
        ];
        $params = [':store_id' => $seller['store_id']];
        
        // Category filter (using global_categories)
        if ($category > 0) {
            $conditions[] = "csp.global_category_id = :category_id";
            $params[':category_id'] = $category;
        }
        
        // Search filter
        if (!empty($search)) {
            $conditions[] = "(csp.title LIKE :search OR csp.description LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        
        $whereClause = implode(" AND ", $conditions);
        
        // Count query
        $countSql = "SELECT COUNT(id) as total FROM customer_store_products csp WHERE $whereClause";
        $countStmt = $db->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $countStmt->execute();
        $totalProducts = $countStmt->fetch()['total'] ?? 0;
        
        // Get products query
        $sql = "SELECT 
                    csp.id,
                    csp.title as product_name,
                    csp.description,
                    csp.price,
                    csp.condition,
                    csp.stock_quantity,
                    csp.primary_image_url,
                    csp.additional_images,
                    csp.created_at,
                    gc.name as category_name
                FROM customer_store_products csp
                LEFT JOIN global_categories gc ON csp.global_category_id = gc.id
                WHERE $whereClause
                ORDER BY " . getP2PSortOrder($sort) . "
                LIMIT :limit OFFSET :offset";
        
        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $products = $stmt->fetchAll();
        
        // Format products
        foreach ($products as &$product) {
            $product['primary_image'] = $product['primary_image_url'] ?? '/assets/img/placeholder.jpg';
            $product['price_formatted'] = number_format($product['price'], 0, ',', ' ') . ' RWF';
            $product['condition_badge'] = getConditionBadge($product['condition']);
        }
    } else {
        // Get B2C products from ERP system
        $conditions = [
            "p.company_id = :company_id",
            "p.is_active = 1",
            "comp.is_public = 1",
            "comp.is_active = 1"
        ];
        $params = [':company_id' => $seller['id']];
        
        // Category filter
        if ($category > 0) {
            $conditions[] = "p.category_id = :category_id";
            $params[':category_id'] = $category;
        }
        
        // Search filter
        if (!empty($search)) {
            $conditions[] = "(p.product_name LIKE :search OR p.description LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        
        $whereClause = implode(" AND ", $conditions);
        
        // Count query
        $countSql = "SELECT COUNT(DISTINCT p.id) as total 
                     FROM products p
                     INNER JOIN companies comp ON p.company_id = comp.id
                     WHERE $whereClause";
        
        $countStmt = $db->prepare($countSql);
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $countStmt->execute();
        $totalProducts = $countStmt->fetch()['total'] ?? 0;
        
        // Get products query
        $sql = "SELECT DISTINCT 
                    p.id,
                    p.product_code,
                    p.product_name,
                    p.description,
                    p.brand,
                    p.created_at,
                    c.category_name,
                    MIN(v.selling_price) as price_from,
                    MAX(v.selling_price) as price_to
                FROM products p
                INNER JOIN companies comp ON p.company_id = comp.id
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN variants v ON v.product_id = p.id AND v.company_id = comp.id AND v.is_active = 1
                WHERE $whereClause
                GROUP BY p.id
                ORDER BY " . getB2CSortOrder($sort) . "
                LIMIT :limit OFFSET :offset";
        
        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $products = $stmt->fetchAll();
        
        // Get primary images for each product
        foreach ($products as &$product) {
            $product['primary_image'] = $productModel->getPrimaryImage($product['id']);
            if (!$product['primary_image']) {
                $product['primary_image'] = '/assets/img/placeholder.jpg';
            }
            $product['price_formatted'] = number_format($product['price_from'], 0, ',', ' ') . ' ' . ($seller['currency'] ?? 'RWF');
            if ($product['price_to'] && $product['price_to'] > $product['price_from']) {
                $product['price_range'] = number_format($product['price_from'], 0, ',', ' ') . ' - ' . 
                                          number_format($product['price_to'], 0, ',', ' ') . ' ' . ($seller['currency'] ?? 'RWF');
            }
        }
    }
    
} catch (PDOException $e) {
    error_log("Seller Products Error: " . $e->getMessage());
    $products = [];
    $totalProducts = 0;
}

// Get seller's categories
$sellerCategories = $isP2PSeller ? 
    $sellerModel->getP2PSellerCategories($seller['store_id']) : 
    $sellerModel->getSellerCategories($seller['id']);

// Calculate pagination
$totalPages = ceil($totalProducts / $limit);
$startItem = $totalProducts > 0 ? ($page - 1) * $limit + 1 : 0;
$endItem = min($page * $limit, $totalProducts);

// Helper functions
function getP2PSortOrder($sort) {
    switch ($sort) {
        case 'price_asc':
            return 'price ASC';
        case 'price_desc':
            return 'price DESC';
        case 'name_asc':
            return 'title ASC';
        case 'name_desc':
            return 'title DESC';
        case 'oldest':
            return 'created_at ASC';
        case 'newest':
        default:
            return 'created_at DESC';
    }
}

function getB2CSortOrder($sort) {
    switch ($sort) {
        case 'price_asc':
            return 'price_from ASC';
        case 'price_desc':
            return 'price_from DESC';
        case 'name_asc':
            return 'p.product_name ASC';
        case 'name_desc':
            return 'p.product_name DESC';
        case 'oldest':
            return 'p.created_at ASC';
        case 'newest':
        default:
            return 'p.created_at DESC';
    }
}

function getConditionBadge($condition) {
    $badges = [
        'new' => '🆕 Brand New',
        'like_new' => '✨ Like New',
        'good' => '👍 Good',
        'fair' => '⚠️ Fair'
    ];
    return $badges[$condition] ?? $condition;
}

// Helper function to build filter URLs
function buildFilterUrl($changes) {
    $params = $_GET;
    
    foreach ($changes as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    
    if (empty($params)) {
        return 'seller.php?id=' . $GLOBALS['seller']['id'] . '&type=' . ($GLOBALS['isP2PSeller'] ? 'individual' : 'company');
    }
    
    return 'seller.php?id=' . $GLOBALS['seller']['id'] . '&type=' . ($GLOBALS['isP2PSeller'] ? 'individual' : 'company') . '&' . http_build_query($params);
}

// Include header
include_once __DIR__ . '/../templates/header.php';
?>

<!-- Seller Page Specific Styles -->
<style>
    .seller-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 20px;
    }
    
    .seller-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 16px;
        padding: 40px;
        margin-bottom: 30px;
        color: white;
    }
    
    .seller-info {
        display: flex;
        gap: 30px;
        flex-wrap: wrap;
        align-items: center;
    }
    
    .seller-avatar {
        width: 120px;
        height: 120px;
        background: rgba(255,255,255,0.2);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 48px;
    }
    
    .seller-details {
        flex: 1;
    }
    
    .seller-name {
        font-size: 32px;
        font-weight: 700;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    
    .seller-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 500;
        background: rgba(255,255,255,0.2);
    }
    
    .seller-badge.corporate {
        background: #28a745;
    }
    
    .seller-badge.individual {
        background: #17a2b8;
    }
    
    .seller-meta {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
        margin-bottom: 15px;
        font-size: 14px;
        opacity: 0.9;
    }
    
    .seller-meta i {
        margin-right: 5px;
    }
    
    .seller-description {
        margin-top: 15px;
        line-height: 1.6;
        max-width: 600px;
    }
    
    .chat-button {
        background: white;
        color: #667eea;
        border: none;
        padding: 12px 24px;
        border-radius: 8px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        text-decoration: none;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    
    .chat-button:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .stat-card {
        background: #fff;
        border-radius: 12px;
        padding: 20px;
        text-align: center;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    
    .stat-number {
        font-size: 28px;
        font-weight: 700;
        color: #333;
    }
    
    .stat-label {
        font-size: 13px;
        color: #666;
        margin-top: 5px;
    }
    
    .toolbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
        margin-bottom: 20px;
        padding: 15px;
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    
    .search-form {
        display: flex;
        gap: 10px;
        flex: 1;
        max-width: 400px;
    }
    
    .search-form input {
        flex: 1;
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
    }
    
    .search-form button {
        padding: 8px 16px;
        background: #007bff;
        color: #fff;
        border: none;
        border-radius: 4px;
        cursor: pointer;
    }
    
    .sort-select {
        padding: 8px 12px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
        cursor: pointer;
    }
    
    .category-filter {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 20px;
        padding: 15px;
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    
    .category-chip {
        padding: 6px 16px;
        background: #f5f5f5;
        border-radius: 20px;
        text-decoration: none;
        color: #666;
        font-size: 13px;
        transition: all 0.2s;
    }
    
    .category-chip:hover {
        background: #e0e0e0;
    }
    
    .category-chip.active {
        background: #007bff;
        color: #fff;
    }
    
    .products-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .product-card {
        background: #fff;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        transition: transform 0.2s, box-shadow 0.2s;
    }
    
    .product-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    
    .product-image {
        position: relative;
        height: 200px;
        overflow: hidden;
    }
    
    .product-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .product-badge {
        position: absolute;
        top: 10px;
        right: 10px;
        background: rgba(0,0,0,0.7);
        color: #fff;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 11px;
    }
    
    .condition-badge {
        position: absolute;
        top: 10px;
        left: 10px;
        background: rgba(0,0,0,0.7);
        color: #fff;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 11px;
    }
    
    .product-info {
        padding: 15px;
    }
    
    .product-title {
        font-size: 16px;
        font-weight: 500;
        margin-bottom: 8px;
    }
    
    .product-title a {
        color: #333;
        text-decoration: none;
    }
    
    .product-title a:hover {
        color: #007bff;
    }
    
    .product-price {
        font-size: 18px;
        font-weight: 600;
        color: #007bff;
        margin-bottom: 5px;
    }
    
    .product-price-range {
        font-size: 12px;
        color: #999;
    }
    
    .product-description {
        font-size: 13px;
        color: #666;
        margin-top: 8px;
        line-height: 1.4;
    }
    
    .pagination {
        display: flex;
        justify-content: center;
        gap: 8px;
        flex-wrap: wrap;
        margin-top: 30px;
    }
    
    .pagination a, .pagination span {
        padding: 8px 14px;
        border: 1px solid #ddd;
        border-radius: 4px;
        text-decoration: none;
        color: #333;
        font-size: 14px;
        transition: all 0.2s;
    }
    
    .pagination a:hover {
        background: #f5f5f5;
        border-color: #007bff;
    }
    
    .pagination .active {
        background: #007bff;
        color: #fff;
        border-color: #007bff;
    }
    
    .no-results {
        text-align: center;
        padding: 60px 20px;
        background: #fff;
        border-radius: 8px;
    }
    
    .no-results i {
        font-size: 64px;
        color: #ccc;
        margin-bottom: 20px;
    }
    
    .no-results h3 {
        font-size: 24px;
        color: #333;
        margin-bottom: 10px;
    }
    
    @media (max-width: 768px) {
        .seller-header {
            padding: 20px;
        }
        
        .seller-info {
            flex-direction: column;
            text-align: center;
        }
        
        .seller-name {
            justify-content: center;
        }
        
        .seller-meta {
            justify-content: center;
        }
        
        .products-grid {
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .product-image {
            height: 160px;
        }
        
        .toolbar {
            flex-direction: column;
        }
        
        .search-form {
            max-width: 100%;
        }
    }
</style>

<div class="seller-container">
    <!-- Seller Header -->
    <div class="seller-header">
        <div class="seller-info">
            <div class="seller-avatar">
                <i class="fas <?php echo $isP2PSeller ? 'fa-user' : 'fa-store'; ?>"></i>
            </div>
            <div class="seller-details">
                <div class="seller-name">
                    <?php echo htmlspecialchars($seller['seller_name']); ?>
                    <span class="seller-badge <?php echo $isP2PSeller ? 'individual' : 'corporate'; ?>">
                        <?php echo $isP2PSeller ? '👤 Individual Seller' : '🏢 Corporate Store'; ?>
                    </span>
                    <?php if ($seller['verified'] ?? false): ?>
                        <span class="seller-badge" style="background: #28a745;">
                            ✓ Verified
                        </span>
                    <?php endif; ?>
                </div>
                <div class="seller-meta">
                    <?php if (!empty($seller['location'])): ?>
                        <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($seller['location']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($seller['member_since'])): ?>
                        <span><i class="fas fa-calendar-alt"></i> Member since <?php echo date('F Y', strtotime($seller['member_since'])); ?></span>
                    <?php endif; ?>
                    <span><i class="fas fa-shopping-bag"></i> <?php echo $totalProducts; ?> products</span>
                    <?php if ($seller['rating'] ?? false): ?>
                        <span><i class="fas fa-star" style="color: #ffc107;"></i> <?php echo $seller['rating']; ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($seller['description'])): ?>
                    <div class="seller-description">
                        <?php echo nl2br(htmlspecialchars($seller['description'])); ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php if (SessionManager::isCustomerLoggedIn() && SessionManager::getCustomerId() != ($seller['customer_id'] ?? $seller['owner_id'] ?? 0)): ?>
                <a href="chat.php?with=<?php echo $seller['id']; ?>&type=<?php echo $isP2PSeller ? 'p2p' : 'b2c'; ?>&name=<?php echo urlencode($seller['seller_name']); ?>" class="chat-button">
                    <i class="fas fa-comment-dots"></i> Contact Seller
                </a>
            <?php elseif (!SessionManager::isCustomerLoggedIn()): ?>
                <a href="login.php?redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" class="chat-button" style="text-decoration: none;">
                    <i class="fas fa-sign-in-alt"></i> Login to Contact
                </a>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?php echo $totalProducts; ?></div>
            <div class="stat-label">Total Products</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo count($sellerCategories); ?></div>
            <div class="stat-label">Categories</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo $seller['total_sales'] ?? 0; ?></div>
            <div class="stat-label">Total Sales</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo $seller['response_rate'] ?? '100'; ?>%</div>
            <div class="stat-label">Response Rate</div>
        </div>
    </div>
    
    <!-- Products Section -->
    <h2 style="margin-bottom: 20px;">Products from <?php echo htmlspecialchars($seller['seller_name']); ?></h2>
    
    <!-- Toolbar -->
    <div class="toolbar">
        <form method="GET" action="" class="search-form">
            <input type="hidden" name="id" value="<?php echo $seller['id']; ?>">
            <input type="hidden" name="type" value="<?php echo $isP2PSeller ? 'individual' : 'company'; ?>">
            <input type="text" name="search" placeholder="Search products..." 
                   value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit"><i class="fas fa-search"></i></button>
        </form>
        
        <select name="sort" class="sort-select" onchange="this.form.submit()" form="filter-form">
            <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
            <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
            <option value="price_asc" <?php echo $sort === 'price_asc' ? 'selected' : ''; ?>>Price: Low to High</option>
            <option value="price_desc" <?php echo $sort === 'price_desc' ? 'selected' : ''; ?>>Price: High to Low</option>
            <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>Name: A to Z</option>
            <option value="name_desc" <?php echo $sort === 'name_desc' ? 'selected' : ''; ?>>Name: Z to A</option>
        </select>
    </div>
    
    <form id="filter-form" method="GET" action="">
        <input type="hidden" name="id" value="<?php echo $seller['id']; ?>">
        <input type="hidden" name="type" value="<?php echo $isP2PSeller ? 'individual' : 'company'; ?>">
        <input type="hidden" name="sort" id="sort-input" value="<?php echo htmlspecialchars($sort); ?>">
        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
        <input type="hidden" name="page" id="page-input" value="<?php echo $page; ?>">
    </form>
    
    <!-- Category Filter -->
    <?php if (!empty($sellerCategories)): ?>
        <div class="category-filter">
            <a href="<?php echo buildFilterUrl(['category' => null, 'page' => null]); ?>" 
               class="category-chip <?php echo $category == 0 ? 'active' : ''; ?>">
                All Categories
            </a>
            <?php foreach ($sellerCategories as $cat): ?>
                <a href="<?php echo buildFilterUrl(['category' => $cat['id'], 'page' => null]); ?>" 
                   class="category-chip <?php echo $category == $cat['id'] ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($cat['category_name']); ?>
                    <span style="font-size: 11px;">(<?php echo $cat['product_count']; ?>)</span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <!-- Products Grid -->
    <?php if (!empty($products)): ?>
        <div class="products-grid">
            <?php foreach ($products as $product): ?>
                <div class="product-card">
                    <div class="product-image">
                        <img src="<?php echo htmlspecialchars($product['primary_image']); ?>" 
                             alt="<?php echo htmlspecialchars($product['product_name']); ?>"
                             loading="lazy"
                             onerror="this.src='/assets/img/placeholder.jpg'">
                        <?php if ($isP2PSeller && isset($product['condition_badge'])): ?>
                            <span class="condition-badge"><?php echo $product['condition_badge']; ?></span>
                        <?php endif; ?>
                        <?php if (!empty($product['category_name'])): ?>
                            <span class="product-badge"><?php echo htmlspecialchars($product['category_name']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="product-info">
                        <h3 class="product-title">
                            <a href="<?php echo $isP2PSeller ? 'p2p_product.php?id=' : 'product.php?id='; ?><?php echo $product['id']; ?>">
                                <?php echo htmlspecialchars($product['product_name']); ?>
                            </a>
                        </h3>
                        <div class="product-price">
                            <?php echo $product['price_formatted']; ?>
                        </div>
                        <?php if (isset($product['price_range'])): ?>
                            <div class="product-price-range">
                                Range: <?php echo $product['price_range']; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($product['description'])): ?>
                            <div class="product-description">
                                <?php echo htmlspecialchars(substr($product['description'], 0, 80)); ?>...
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="<?php echo buildFilterUrl(['page' => $page - 1]); ?>">
                        <i class="fas fa-chevron-left"></i> Previous
                    </a>
                <?php else: ?>
                    <span class="disabled"><i class="fas fa-chevron-left"></i> Previous</span>
                <?php endif; ?>
                
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                
                if ($startPage > 1): ?>
                    <a href="<?php echo buildFilterUrl(['page' => 1]); ?>">1</a>
                    <?php if ($startPage > 2): ?>
                        <span>...</span>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="active"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a href="<?php echo buildFilterUrl(['page' => $i]); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($endPage < $totalPages): ?>
                    <?php if ($endPage < $totalPages - 1): ?>
                        <span>...</span>
                    <?php endif; ?>
                    <a href="<?php echo buildFilterUrl(['page' => $totalPages]); ?>"><?php echo $totalPages; ?></a>
                <?php endif; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="<?php echo buildFilterUrl(['page' => $page + 1]); ?>">
                        Next <i class="fas fa-chevron-right"></i>
                    </a>
                <?php else: ?>
                    <span class="disabled">Next <i class="fas fa-chevron-right"></i></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
    <?php else: ?>
        <div class="no-results">
            <i class="fas fa-box-open"></i>
            <h3>No products found</h3>
            <p>This seller doesn't have any products matching your criteria.</p>
            <a href="seller.php?id=<?php echo $seller['id']; ?>&type=<?php echo $isP2PSeller ? 'individual' : 'company'; ?>" class="chat-button" style="display: inline-block; width: auto; padding: 10px 20px; text-decoration: none; background: #007bff; color: white;">
                View All Products
            </a>
        </div>
    <?php endif; ?>
</div>

<script>
document.querySelector('.sort-select')?.addEventListener('change', function() {
    document.getElementById('sort-input').value = this.value;
    document.getElementById('filter-form').submit();
});
</script>

<?php
include_once __DIR__ . '/../templates/footer.php';
?>