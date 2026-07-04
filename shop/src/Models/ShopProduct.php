<?php
// /src/Models/ShopProduct.php
// Unified Product Model - Combines Corporate B2C and Peer-to-Peer Products

require_once __DIR__ . '/../../config/database.php';

class ShopProduct {
    private $db;
    private $marketplaceCompanyId = 9;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Get unified catalog - combines corporate and peer products
     * 
     * @param int $page Page number
     * @param int $perPage Items per page
     * @param int|null $categoryId Category filter
     * @param string|null $search Search term
     * @param string $sort Sort order
     * @param string|null $sellerType Filter by 'corporate', 'peer', or null for both
     * @return array Unified product list
     */
    public function getUnifiedCatalog($page = 1, $perPage = ITEMS_PER_PAGE, $categoryId = null, $search = null, $sort = 'newest', $sellerType = null) {
        try {
            $offset = ($page - 1) * $perPage;
            $conn = $this->db->getConnection();
            
            // Build WHERE clauses
            $whereConditions = [];
            $params = [];
            
            if ($categoryId) {
                $whereConditions[] = "global_category_id = :category_id";
                $params[':category_id'] = $categoryId;
            }
            
            if ($search) {
                $whereConditions[] = "(title LIKE :search OR description LIKE :search)";
                $params[':search'] = '%' . $search . '%';
            }
            
            $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";
            
            // Build ORDER BY clause
            $orderBy = $this->getOrderByClause($sort);
            
            // Corporate Products Query
            $corporateSql = "SELECT 
                                p.id as original_id,
                                p.product_name as title,
                                p.description,
                                p.long_description,
                                MIN(v.selling_price) as price,
                                NULL as condition,
                                NULL as city,
                                'corporate' as seller_type,
                                c.id as seller_id,
                                c.company_name as seller_name,
                                c.slug as seller_slug,
                                cat.id as category_id,
                                cat.category_name as category_name,
                                (SELECT vi.image_url 
                                 FROM variant_images vi 
                                 INNER JOIN variants v2 ON vi.variant_id = v2.id
                                 WHERE v2.product_id = p.id 
                                 AND vi.is_primary = 1 
                                 LIMIT 1) as image_url,
                                p.created_at,
                                COUNT(DISTINCT v.id) as variant_count,
                                CASE WHEN SUM(i.quantity) > 0 THEN 1 ELSE 0 END as in_stock
                            FROM products p
                            INNER JOIN companies c ON p.company_id = c.id
                            INNER JOIN categories cat ON p.category_id = cat.id
                            INNER JOIN variants v ON v.product_id = p.id AND v.company_id = p.company_id
                            LEFT JOIN inventory i ON i.variant_id = v.id
                            WHERE c.is_public = 1 
                            AND c.is_active = 1
                            AND p.is_active = 1
                            AND v.is_active = 1
                            " . ($sellerType === 'corporate' ? "" : "AND 1=1") . "
                            GROUP BY p.id, p.product_name, p.description, p.long_description, 
                                     c.id, c.company_name, c.slug, cat.id, cat.category_name, p.created_at";
            
            // Peer Products Query
            $peerSql = "SELECT 
                            csp.id as original_id,
                            csp.title,
                            csp.description,
                            NULL as long_description,
                            csp.price,
                            csp.condition,
                            csp.city,
                            'peer' as seller_type,
                            cs.id as seller_id,
                            cs.store_name as seller_name,
                            cs.slug as seller_slug,
                            gc.id as category_id,
                            gc.name as category_name,
                            csp.primary_image_url as image_url,
                            csp.created_at,
                            1 as variant_count,
                            CASE WHEN csp.stock_quantity > 0 THEN 1 ELSE 0 END as in_stock
                        FROM customer_store_products csp
                        INNER JOIN customer_stores cs ON csp.customer_store_id = cs.id
                        LEFT JOIN global_categories gc ON csp.global_category_id = gc.id
                        WHERE csp.is_active = 1
                        AND cs.is_active = 1
                        " . ($sellerType === 'peer' ? "" : "AND 1=1") . "
                        GROUP BY csp.id, csp.title, csp.description, csp.price, csp.condition,
                                 csp.city, cs.id, cs.store_name, cs.slug, gc.id, gc.name, 
                                 csp.primary_image_url, csp.created_at, csp.stock_quantity";
            
            // Add WHERE clause to both queries
            if ($whereClause) {
                // Convert column names for corporate query
                $corporateWhere = str_replace('title', 'p.product_name', $whereClause);
                $corporateWhere = str_replace('global_category_id', 'p.category_id', $corporateWhere);
                $peerWhere = $whereClause;
                $corporateSql .= " AND " . substr($corporateWhere, 6);
                $peerSql .= " AND " . substr($peerWhere, 6);
            }
            
            // Combine queries with UNION ALL
            $unifiedSql = "SELECT * FROM (($corporateSql) UNION ALL ($peerSql)) AS unified 
                           ORDER BY " . $orderBy . " 
                           LIMIT :limit OFFSET :offset";
            
            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM (($corporateSql) UNION ALL ($peerSql)) AS unified";
            $stmt = $conn->prepare($countSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $total = $stmt->fetch()['total'];
            $totalPages = ceil($total / $perPage);
            
            // Get paginated results
            $stmt = $conn->prepare($unifiedSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $products = $stmt->fetchAll();
            
            // Enhance each product with additional data
            foreach ($products as &$product) {
                $product['formatted_price'] = $this->formatPrice($product['price']);
                $product['image_url'] = $product['image_url'] ?? '/assets/img/placeholder.jpg';
                $product['condition_badge'] = $this->getConditionBadge($product['condition'] ?? null);
                $product['detail_url'] = $this->getDetailUrl($product);
            }
            
            return [
                'products' => $products,
                'total' => $total,
                'total_pages' => $totalPages,
                'current_page' => $page,
                'per_page' => $perPage
            ];
            
        } catch (PDOException $e) {
            error_log("ShopProduct::getUnifiedCatalog Error: " . $e->getMessage());
            return ['products' => [], 'total' => 0, 'total_pages' => 0, 'current_page' => 1, 'per_page' => $perPage];
        }
    }
    
    /**
     * Get single product by ID and type
     * 
     * @param int $id Product ID
     * @param string $type 'corporate' or 'peer'
     * @return array|null
     */
    public function getProductById($id, $type) {
        if ($type === 'corporate') {
            return $this->getCorporateProduct($id);
        } else {
            return $this->getPeerProduct($id);
        }
    }
    
    /**
     * Get corporate product with variants
     */
    private function getCorporateProduct($productId) {
        try {
            $conn = $this->db->getConnection();
            
            $sql = "SELECT 
                        p.id,
                        p.product_code,
                        p.product_name as title,
                        p.description,
                        p.long_description,
                        p.brand,
                        p.unit_of_measure,
                        p.created_at,
                        c.id as seller_id,
                        c.company_name as seller_name,
                        c.slug as seller_slug,
                        c.currency,
                        cat.category_name,
                        'corporate' as seller_type
                    FROM products p
                    INNER JOIN companies c ON p.company_id = c.id
                    LEFT JOIN categories cat ON p.category_id = cat.id
                    WHERE p.id = :id
                    AND c.is_public = 1
                    AND p.is_active = 1";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':id', $productId, PDO::PARAM_INT);
            $stmt->execute();
            
            $product = $stmt->fetch();
            
            if ($product) {
                $product['variants'] = $this->getCorporateVariants($productId);
                $product['images'] = $this->getCorporateImages($productId);
                $product['min_price'] = $this->getMinVariantPrice($productId);
                $product['max_price'] = $this->getMaxVariantPrice($productId);
                $product['in_stock'] = $this->checkCorporateStock($productId);
            }
            
            return $product;
            
        } catch (PDOException $e) {
            error_log("ShopProduct::getCorporateProduct Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get peer product (P2P listing)
     */
    private function getPeerProduct($productId) {
        try {
            $conn = $this->db->getConnection();
            
            $sql = "SELECT 
                        csp.id,
                        csp.title,
                        csp.description,
                        csp.price,
                        csp.condition,
                        csp.stock_quantity,
                        csp.city,
                        csp.primary_image_url,
                        csp.additional_images,
                        csp.created_at,
                        cs.id as seller_id,
                        cs.store_name as seller_name,
                        cs.slug as seller_slug,
                        cs.description as store_description,
                        gc.name as category_name,
                        'peer' as seller_type
                    FROM customer_store_products csp
                    INNER JOIN customer_stores cs ON csp.customer_store_id = cs.id
                    LEFT JOIN global_categories gc ON csp.global_category_id = gc.id
                    WHERE csp.id = :id
                    AND csp.is_active = 1
                    AND cs.is_active = 1";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':id', $productId, PDO::PARAM_INT);
            $stmt->execute();
            
            $product = $stmt->fetch();
            
            if ($product) {
                $product['images'] = $this->getPeerImages($product);
                $product['condition_label'] = $this->getConditionLabel($product['condition']);
                $product['condition_badge'] = $this->getConditionBadge($product['condition']);
                $product['in_stock'] = $product['stock_quantity'] > 0;
                $product['formatted_price'] = $this->formatPrice($product['price']);
            }
            
            return $product;
            
        } catch (PDOException $e) {
            error_log("ShopProduct::getPeerProduct Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get corporate product variants
     */
    private function getCorporateVariants($productId) {
        try {
            $conn = $this->db->getConnection();
            
            $sql = "SELECT 
                        v.id,
                        v.sku,
                        v.variant_name,
                        v.selling_price as price,
                        v.tax_rate,
                        COALESCE(SUM(i.quantity), 0) as stock_quantity
                    FROM variants v
                    LEFT JOIN inventory i ON i.variant_id = v.id
                    WHERE v.product_id = :product_id
                    AND v.is_active = 1
                    GROUP BY v.id, v.sku, v.variant_name, v.selling_price, v.tax_rate";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
            $stmt->execute();
            
            $variants = $stmt->fetchAll();
            
            foreach ($variants as &$variant) {
                $variant['in_stock'] = $variant['stock_quantity'] > 0;
                $variant['formatted_price'] = $this->formatPrice($variant['price']);
            }
            
            return $variants;
            
        } catch (PDOException $e) {
            error_log("ShopProduct::getCorporateVariants Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get corporate product images
     */
    private function getCorporateImages($productId) {
        try {
            $conn = $this->db->getConnection();
            
            $sql = "SELECT DISTINCT vi.image_url, vi.is_primary, vi.sort_order
                    FROM variant_images vi
                    INNER JOIN variants v ON vi.variant_id = v.id
                    WHERE v.product_id = :product_id
                    ORDER BY vi.is_primary DESC, vi.sort_order ASC";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("ShopProduct::getCorporateImages Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get peer product images
     */
    private function getPeerImages($product) {
        $images = [];
        
        if ($product['primary_image_url']) {
            $images[] = [
                'image_url' => $product['primary_image_url'],
                'is_primary' => 1,
                'sort_order' => 0
            ];
        }
        
        if ($product['additional_images']) {
            $additional = json_decode($product['additional_images'], true);
            if (is_array($additional)) {
                foreach ($additional as $index => $img) {
                    $images[] = [
                        'image_url' => $img,
                        'is_primary' => 0,
                        'sort_order' => $index + 1
                    ];
                }
            }
        }
        
        return $images;
    }
    
    /**
     * Get min price among corporate variants
     */
    private function getMinVariantPrice($productId) {
        $sql = "SELECT MIN(selling_price) as min_price FROM variants WHERE product_id = :product_id AND is_active = 1";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch();
        return $result['min_price'] ?? 0;
    }
    
    /**
     * Get max price among corporate variants
     */
    private function getMaxVariantPrice($productId) {
        $sql = "SELECT MAX(selling_price) as max_price FROM variants WHERE product_id = :product_id AND is_active = 1";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch();
        return $result['max_price'] ?? 0;
    }
    
    /**
     * Check if corporate product has any stock
     */
    private function checkCorporateStock($productId) {
        $sql = "SELECT COALESCE(SUM(i.quantity), 0) as total_stock
                FROM inventory i
                INNER JOIN variants v ON i.variant_id = v.id
                WHERE v.product_id = :product_id";
        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch();
        return $result['total_stock'] > 0;
    }
    
    /**
     * Get global categories for filtering
     */
    public function getGlobalCategories() {
        try {
            $sql = "SELECT id, name, slug, icon FROM global_categories ORDER BY name ASC";
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("ShopProduct::getGlobalCategories Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get featured products (mix of corporate and peer)
     */
    public function getFeaturedProducts($limit = 8) {
        $result = $this->getUnifiedCatalog(1, $limit, null, null, 'newest', null);
        return $result['products'];
    }
    
    /**
     * Search products with autocomplete
     */
    public function searchAutocomplete($query, $limit = 10) {
        try {
            $conn = $this->db->getConnection();
            $searchTerm = '%' . $query . '%';
            
            // Search corporate products
            $corporateSql = "SELECT 
                                p.id as product_id,
                                p.product_name as title,
                                'corporate' as type,
                                MIN(v.selling_price) as price,
                                (SELECT vi.image_url 
                                 FROM variant_images vi 
                                 INNER JOIN variants v2 ON vi.variant_id = v2.id
                                 WHERE v2.product_id = p.id 
                                 AND vi.is_primary = 1 
                                 LIMIT 1) as image_url
                            FROM products p
                            INNER JOIN companies c ON p.company_id = c.id
                            INNER JOIN variants v ON v.product_id = p.id
                            WHERE c.is_public = 1
                            AND p.is_active = 1
                            AND (p.product_name LIKE :search OR p.product_code LIKE :search)
                            GROUP BY p.id, p.product_name
                            LIMIT :limit";
            
            // Search peer products
            $peerSql = "SELECT 
                            csp.id as product_id,
                            csp.title,
                            'peer' as type,
                            csp.price,
                            csp.primary_image_url as image_url
                        FROM customer_store_products csp
                        INNER JOIN customer_stores cs ON csp.customer_store_id = cs.id
                        WHERE csp.is_active = 1
                        AND cs.is_active = 1
                        AND csp.title LIKE :search2
                        LIMIT :limit2";
            
            $stmt = $conn->prepare($corporateSql);
            $stmt->bindValue(':search', $searchTerm);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $corporate = $stmt->fetchAll();
            
            $stmt2 = $conn->prepare($peerSql);
            $stmt2->bindValue(':search2', $searchTerm);
            $stmt2->bindValue(':limit2', $limit, PDO::PARAM_INT);
            $stmt2->execute();
            $peer = $stmt2->fetchAll();
            
            return array_merge($corporate, $peer);
            
        } catch (PDOException $e) {
            error_log("ShopProduct::searchAutocomplete Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Helper methods
     */
    private function getOrderByClause($sort) {
        switch ($sort) {
            case 'price_asc':
                return 'price ASC';
            case 'price_desc':
                return 'price DESC';
            case 'name_asc':
                return 'title ASC';
            case 'name_desc':
                return 'title DESC';
            case 'newest':
            default:
                return 'created_at DESC';
        }
    }
    
    private function getConditionBadge($condition) {
        $badges = [
            'new' => '<span class="condition-badge condition-new">New</span>',
            'like_new' => '<span class="condition-badge condition-like-new">Like New</span>',
            'good' => '<span class="condition-badge condition-good">Good</span>',
            'fair' => '<span class="condition-badge condition-fair">Fair</span>'
        ];
        return $badges[$condition] ?? '';
    }
    
    private function getConditionLabel($condition) {
        $labels = [
            'new' => 'Brand New',
            'like_new' => 'Like New - Used very little',
            'good' => 'Good - Some signs of use',
            'fair' => 'Fair - Visible wear, fully functional'
        ];
        return $labels[$condition] ?? 'Good';
    }
    
    private function formatPrice($price) {
        return number_format((float)$price, 0, ',', ' ') . ' ' . DEFAULT_CURRENCY;
    }
    
    private function getDetailUrl($product) {
        return "/product.php?id=" . $product['original_id'] . "&type=" . $product['seller_type'];
    }
}