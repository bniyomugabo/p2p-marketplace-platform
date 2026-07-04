<?php
// /src/Models/Product.php
// Product Model - Handles all product-related database operations
require_once __DIR__ . '/../../config/database.php';

class Product {
    private $db;
    private $companyId;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Set the context company ID for isolation
     */
    public function setCompanyContext($companyId) {
        $this->companyId = (int)$companyId;
    }
    
    /**
     * Get all public products with variants, images, and company info
     * 
     * @param int $page Page number for pagination
     * @param int $perPage Items per page
     * @param int|null $categoryId Optional category filter
     * @param string|null $search Optional search term
     * @param string $sort Sort order (price_asc, price_desc, newest, name_asc)
     * @return array ['products' => array, 'total' => int, 'total_pages' => int]
     */
    public function getPublicProducts($page = 1, $perPage = 12, $categoryId = null, $search = null, $sort = 'newest') {
        try {
            $offset = ($page - 1) * $perPage;
            
            // Base query - only public companies and active products
            $baseSql = "FROM products p
                        INNER JOIN companies c ON p.company_id = c.id
                        INNER JOIN variants v ON v.product_id = p.id AND v.company_id = p.company_id
                        WHERE c.is_public = 1 
                        AND c.is_active = 1
                        AND p.is_active = 1
                        AND v.is_active = 1";
            
            $params = [];
            
            // Category filter
            if ($categoryId !== null && $categoryId > 0) {
                $baseSql .= " AND p.category_id = :category_id";
                $params[':category_id'] = $categoryId;
            }
            
            // Search filter
            if ($search !== null && trim($search) !== '') {
                $baseSql .= " AND (p.product_name LIKE :search_1 OR p.product_code LIKE :search_2 OR p.description LIKE :search_3)";
                $params[':search_1'] = '%' . trim($search) . '%';
                $params[':search_2'] = '%' . trim($search) . '%';
                $params[':search_3'] = '%' . trim($search) . '%';
            }
            
            // Get total count
            $countSql = "SELECT COUNT(DISTINCT p.id) as total " . $baseSql;
            $stmt = $this->db->prepare($countSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $total = (int)$stmt->fetch()['total'];
            $totalPages = ceil($total / $perPage);
            
            // Sort order
            $orderBy = 'p.created_at DESC';

            if ($sort === 'price_asc') {
                $orderBy = 'MIN(v.selling_price) ASC';
            } elseif ($sort === 'price_desc') {
                $orderBy = 'MIN(v.selling_price) DESC';
            } elseif ($sort === 'name_asc') {
                $orderBy = 'p.product_name ASC';
            } elseif ($sort === 'name_desc') {
                $orderBy = 'p.product_name DESC';
            }
            
            // Get products with their variants and primary image
            $sql = "SELECT 
                        p.id,
                        p.product_code,
                        p.product_name,
                        p.description,
                        p.long_description,
                        p.brand,
                        p.unit_of_measure,
                        p.created_at,
                        c.id as company_id,
                        c.company_name,
                        c.company_code,
                        c.slug as company_slug,
                        c.currency,
                        MIN(v.selling_price) as min_price,
                        MAX(v.selling_price) as max_price,
                        COUNT(DISTINCT v.id) as variant_count,
                        (
                            SELECT vi.image_url
                            FROM variant_images vi
                            INNER JOIN variants v2 ON v2.id = vi.variant_id
                            WHERE v2.product_id = p.id
                            AND vi.is_primary = 1
                            LIMIT 1
                        ) as primary_image
                    " . $baseSql . "
                    GROUP BY p.id, p.product_code, p.product_name, p.description, 
                             p.long_description, p.brand, p.unit_of_measure, p.created_at,
                             c.id, c.company_name, c.company_code, c.slug, c.currency
                    ORDER BY " . $orderBy . "
                    LIMIT :limit OFFSET :offset";
            
            $params[':limit'] = $perPage;
            $params[':offset'] = $offset;
            
            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            
            $products = $stmt->fetchAll();
            
            // Enhance each product with its variants
            foreach ($products as &$product) {
                $product['variants'] = $this->getProductVariants($product['id'], $product['company_id']);
                $product['image_url'] = $product['primary_image'] ?? $this->getDefaultImage($product);
                $product['price_range'] = $this->formatPriceRange($product['min_price'], $product['max_price']);
            }
            
            return [
                'products' => $products,
                'total' => $total,
                'total_pages' => $totalPages,
                'current_page' => $page,
                'per_page' => $perPage
            ];
            
        } catch (PDOException $e) {
            error_log("Product::getPublicProducts Error: " . $e->getMessage());
            return ['products' => [], 'total' => 0, 'total_pages' => 0, 'current_page' => 1, 'per_page' => $perPage];
        }
    }
    
    /**
     * Get a single product with all its details by ID
     * 
     * @param int $productId Product ID
     * @return array|null Product data or null if not found
     */
    public function getProductById($productId) {
        try {
            $sql = "SELECT 
                        p.id,
                        p.product_code,
                        p.product_name,
                        p.description,
                        p.long_description,
                        p.category_id,
                        p.brand,
                        p.unit_of_measure,
                        p.created_at,
                        c.id as company_id,
                        c.company_name,
                        c.company_code,
                        c.slug as company_slug,
                        c.currency,
                        c.address as company_address,
                        c.phone as company_phone,
                        c.email as company_email,
                        cat.category_name
                    FROM products p
                    INNER JOIN companies c ON p.company_id = c.id
                    LEFT JOIN categories cat ON p.category_id = cat.id
                    WHERE p.id = :product_id
                    AND c.is_public = 1
                    AND c.is_active = 1
                    AND p.is_active = 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
            $stmt->execute();
            
            $product = $stmt->fetch();
            
            if ($product) {
                $product['variants'] = $this->getProductVariants($product['id'], $product['company_id']);
                $product['images'] = $this->getProductImages($product['id'], $product['company_id']);
                $product['min_price'] = $this->getMinVariantPrice($product['id'], $product['company_id']);
                $product['max_price'] = $this->getMaxVariantPrice($product['id'], $product['company_id']);
            }
            
            return $product;
            
        } catch (PDOException $e) {
            error_log("Product::getProductById Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get a product by slug or code for SEO-friendly URLs
     * 
     * @param string $slug Product slug or code
     * @return array|null
     */
    public function getProductBySlug($slug) {
        try {
            $sql = "SELECT 
                        p.id,
                        p.product_code,
                        p.product_name,
                        p.description,
                        p.long_description,
                        p.category_id,
                        p.brand,
                        p.unit_of_measure,
                        p.created_at,
                        c.id as company_id,
                        c.company_name,
                        c.company_code,
                        c.slug as company_slug,
                        c.currency,
                        cat.category_name
                    FROM products p
                    INNER JOIN companies c ON p.company_id = c.id
                    LEFT JOIN categories cat ON p.category_id = cat.id
                    WHERE (p.product_code = :slug OR p.product_name = :slug)
                    AND c.is_public = 1
                    AND c.is_active = 1
                    AND p.is_active = 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':slug', $slug);
            $stmt->execute();
            
            $product = $stmt->fetch();
            
            if ($product) {
                $product['variants'] = $this->getProductVariants($product['id'], $product['company_id']);
                $product['images'] = $this->getProductImages($product['id'], $product['company_id']);
            }
            
            return $product;
            
        } catch (PDOException $e) {
            error_log("Product::getProductBySlug Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get all active variants for a specific product
     * 
     * @param int $productId Product ID
     * @param int $companyId Company ID
     * @return array List of variants with inventory info
     */
    public function getProductVariants($productId, $companyId) {
        try {
            $sql = "SELECT 
                        v.id,
                        v.sku,
                        v.barcode,
                        v.variant_name,
                        v.selling_price,
                        v.wholesale_price,
                        v.tax_rate,
                        v.reorder_level,
                        COALESCE(SUM(i.quantity), 0) as stock_quantity,
                        (SELECT COUNT(*) FROM variant_images vi WHERE vi.variant_id = v.id) as image_count,
                        (SELECT vi.image_url FROM variant_images vi WHERE vi.variant_id = v.id AND vi.is_primary = 1 LIMIT 1) as primary_image
                    FROM variants v
                    LEFT JOIN inventory i ON i.variant_id = v.id AND i.company_id = v.company_id
                    WHERE v.product_id = :product_id
                    AND v.company_id = :company_id
                    AND v.is_active = 1
                    GROUP BY v.id, v.sku, v.barcode, v.variant_name, v.selling_price, 
                             v.wholesale_price, v.tax_rate, v.reorder_level
                    ORDER BY v.selling_price ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
            $stmt->bindValue(':company_id', $companyId, PDO::PARAM_INT);
            $stmt->execute();
            
            $variants = $stmt->fetchAll();
            
            foreach ($variants as &$variant) {
                $variant['in_stock'] = $variant['stock_quantity'] > 0;
                $variant['stock_status'] = $this->getStockStatus($variant['stock_quantity'], $variant['reorder_level']);
                $variant['formatted_price'] = $this->formatCurrency($variant['selling_price']);
            }
            
            return $variants;
            
        } catch (PDOException $e) {
            error_log("Product::getProductVariants Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get variant by ID with inventory and price info
     * 
     * @param int $variantId Variant ID
     * @return array|null
     */
    public function getVariantById($variantId) {
        try {
            $sql = "SELECT 
                        v.id,
                        v.product_id,
                        v.company_id,
                        v.sku,
                        v.barcode,
                        v.variant_name,
                        v.selling_price,
                        v.wholesale_price,
                        v.tax_rate,
                        v.reorder_level,
                        p.product_name,
                        p.product_code,
                        p.unit_of_measure,
                        COALESCE(SUM(i.quantity), 0) as stock_quantity,
                        c.company_name,
                        c.currency
                    FROM variants v
                    INNER JOIN products p ON v.product_id = p.id AND v.company_id = p.company_id
                    INNER JOIN companies c ON v.company_id = c.id
                    LEFT JOIN inventory i ON i.variant_id = v.id AND i.company_id = v.company_id
                    WHERE v.id = :variant_id
                    AND v.is_active = 1
                    AND p.is_active = 1
                    AND c.is_public = 1
                    GROUP BY v.id, v.product_id, v.company_id, v.sku, v.barcode, 
                             v.variant_name, v.selling_price, v.wholesale_price, 
                             v.tax_rate, v.reorder_level, p.product_name, 
                             p.product_code, p.unit_of_measure, c.company_name, c.currency";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':variant_id', $variantId, PDO::PARAM_INT);
            $stmt->execute();
            
            $variant = $stmt->fetch();
            
            if ($variant) {
                $variant['in_stock'] = $variant['stock_quantity'] > 0;
                $variant['formatted_price'] = $this->formatCurrency($variant['selling_price']);
                $variant['images'] = $this->getVariantImages($variantId);
            }
            
            return $variant;
            
        } catch (PDOException $e) {
            error_log("Product::getVariantById Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get all images for a product
     */
    public function getProductImages($productId, $companyId) {
        try {
            $sql = "SELECT DISTINCT vi.* 
                    FROM variant_images vi
                    INNER JOIN variants v ON vi.variant_id = v.id
                    WHERE v.product_id = :product_id
                    AND v.company_id = :company_id
                    ORDER BY vi.is_primary DESC, vi.sort_order ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
            $stmt->bindValue(':company_id', $companyId, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("Product::getProductImages Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get all images for a specific variant
     */
    public function getVariantImages($variantId) {
        try {
            $sql = "SELECT * FROM variant_images WHERE variant_id = :variant_id ORDER BY is_primary DESC, sort_order ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':variant_id', $variantId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Product::getVariantImages Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get primary image URL for a product
     * 
     * @param int $productId Product ID
     * @return string|null Image URL or null if not found
     */
    public function getPrimaryImage($productId) {
        try {
            $sql = "SELECT vi.image_url 
                    FROM variant_images vi
                    INNER JOIN variants v ON vi.variant_id = v.id
                    WHERE v.product_id = :product_id 
                        AND vi.is_primary = 1
                    LIMIT 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
            $stmt->execute();
            
            $result = $stmt->fetch();
            return $result ? $result['image_url'] : null;
            
        } catch (PDOException $e) {
            error_log("Product::getPrimaryImage Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get primary image URL for a variant
     * 
     * @param int $variantId Variant ID
     * @return string|null Image URL or null if not found
     */
    public function getVariantPrimaryImage($variantId) {
        try {
            $sql = "SELECT image_url 
                    FROM variant_images 
                    WHERE variant_id = :variant_id 
                        AND is_primary = 1
                    LIMIT 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':variant_id', $variantId, PDO::PARAM_INT);
            $stmt->execute();
            
            $result = $stmt->fetch();
            return $result ? $result['image_url'] : null;
            
        } catch (PDOException $e) {
            error_log("Product::getVariantPrimaryImage Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get any image for a product (fallback if no primary)
     * 
     * @param int $productId Product ID
     * @return string|null Image URL or null
     */
    public function getAnyProductImage($productId) {
        try {
            $sql = "SELECT vi.image_url 
                    FROM variant_images vi
                    INNER JOIN variants v ON vi.variant_id = v.id
                    WHERE v.product_id = :product_id
                    LIMIT 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
            $stmt->execute();
            
            $result = $stmt->fetch();
            return $result ? $result['image_url'] : null;
            
        } catch (PDOException $e) {
            error_log("Product::getAnyProductImage Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get min price among variants
     */
    private function getMinVariantPrice($productId, $companyId) {
        $sql = "SELECT MIN(selling_price) as min_price FROM variants WHERE product_id = :product_id AND company_id = :company_id AND is_active = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $stmt->bindValue(':company_id', $companyId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch();
        return $result['min_price'] ?? 0;
    }
    
    /**
     * Get max price among variants
     */
    private function getMaxVariantPrice($productId, $companyId) {
        $sql = "SELECT MAX(selling_price) as max_price FROM variants WHERE product_id = :product_id AND company_id = :company_id AND is_active = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $stmt->bindValue(':company_id', $companyId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch();
        return $result['max_price'] ?? 0;
    }
    
    /**
     * Check if a variant has sufficient stock
     * 
     * @param int $variantId Variant ID
     * @param float $requestedQuantity Requested quantity
     * @return bool
     */
    public function hasSufficientStock($variantId, $requestedQuantity) {
        try {
            $sql = "SELECT COALESCE(SUM(i.quantity), 0) as total_stock
                    FROM inventory i
                    WHERE i.variant_id = :variant_id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':variant_id', $variantId, PDO::PARAM_INT);
            $stmt->execute();
            
            $result = $stmt->fetch();
            $availableStock = (float)$result['total_stock'];
            
            return $availableStock >= (float)$requestedQuantity;
            
        } catch (PDOException $e) {
            error_log("Product::hasSufficientStock Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get categories with product counts for public store
     * 
     * @return array
     */
    public function getPublicCategories() {
        try {
            $sql = "SELECT DISTINCT 
                        c.id,
                        c.category_code,
                        c.category_name,
                        COUNT(DISTINCT p.id) as product_count
                    FROM categories c
                    INNER JOIN category_company cc ON c.id = cc.category_id
                    INNER JOIN companies comp ON cc.company_id = comp.id
                    INNER JOIN products p ON p.category_id = c.id AND p.company_id = comp.id
                    INNER JOIN variants v ON v.product_id = p.id AND v.company_id = comp.id
                    WHERE comp.is_public = 1
                    AND comp.is_active = 1
                    AND p.is_active = 1
                    AND v.is_active = 1
                    AND cc.is_active = 1
                    GROUP BY c.id, c.category_code, c.category_name
                    HAVING product_count > 0
                    ORDER BY c.category_name ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("Product::getPublicCategories Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get featured products (top sellers or random)
     * 
     * @param int $limit Number of products
     * @return array
     */
    public function getFeaturedProducts($limit = 8) {
        try {
            $sql = "SELECT 
                        p.id,
                        p.product_code,
                        p.product_name,
                        p.description,
                        p.brand,
                        c.id as company_id,
                        c.company_name,
                        c.slug as company_slug,
                        c.currency,
                        MIN(v.selling_price) as min_price,
                        (SELECT vi.image_url 
                         FROM variant_images vi 
                         INNER JOIN variants v2 ON vi.variant_id = v2.id
                         WHERE v2.product_id = p.id 
                         AND vi.is_primary = 1 
                         LIMIT 1) as primary_image
                    FROM products p
                    INNER JOIN companies c ON p.company_id = c.id
                    INNER JOIN variants v ON v.product_id = p.id AND v.company_id = p.company_id
                    WHERE c.is_public = 1
                    AND c.is_active = 1
                    AND p.is_active = 1
                    AND v.is_active = 1
                    GROUP BY p.id, p.product_code, p.product_name, p.description, 
                             p.brand, c.id, c.company_name, c.slug, c.currency
                    ORDER BY RAND()
                    LIMIT :limit";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            $products = $stmt->fetchAll();
            
            foreach ($products as &$product) {
                $product['price_range'] = $this->formatPriceRange($product['min_price'], $product['min_price']);
            }
            
            return $products;
            
        } catch (PDOException $e) {
            error_log("Product::getFeaturedProducts Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Search products with autocomplete
     * 
     * @param string $query Search term
     * @param int $limit Results limit
     * @return array
     */
    public function searchAutocomplete($query, $limit = 10) {
        try {
            $sql = "SELECT DISTINCT
                        p.id,
                        p.product_name,
                        p.product_code,
                        MIN(v.selling_price) as price,
                        c.currency,
                        (SELECT vi.image_url 
                         FROM variant_images vi 
                         INNER JOIN variants v2 ON vi.variant_id = v2.id
                         WHERE v2.product_id = p.id 
                         AND vi.is_primary = 1 
                         LIMIT 1) as image
                    FROM products p
                    INNER JOIN companies c ON p.company_id = c.id
                    INNER JOIN variants v ON v.product_id = p.id AND v.company_id = p.company_id
                    WHERE c.is_public = 1
                    AND c.is_active = 1
                    AND p.is_active = 1
                    AND v.is_active = 1
                    AND (p.product_name LIKE :query OR p.product_code LIKE :query)
                    GROUP BY p.id, p.product_name, p.product_code, c.currency
                    ORDER BY 
                        CASE 
                            WHEN p.product_name LIKE :exact THEN 1
                            WHEN p.product_name LIKE :starts THEN 2
                            ELSE 3
                        END
                    LIMIT :limit";
            
            $searchTerm = '%' . $query . '%';
            $exactTerm = $query;
            $startsTerm = $query . '%';
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':query', $searchTerm);
            $stmt->bindValue(':exact', $exactTerm);
            $stmt->bindValue(':starts', $startsTerm);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("Product::searchAutocomplete Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Validate variant stock before purchase
     * 
     * @param int $variantId Variant ID
     * @param float $quantity Requested quantity
     * @param int $warehouseId Specific warehouse (optional)
     * @return array ['valid' => bool, 'available' => float, 'message' => string]
     */
    public function validateStock($variantId, $quantity, $warehouseId = null) {
        try {
            $sql = "SELECT COALESCE(SUM(i.quantity), 0) as total_stock
                    FROM inventory i
                    WHERE i.variant_id = :variant_id";
            
            if ($warehouseId !== null) {
                $sql .= " AND i.warehouse_id = :warehouse_id";
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':variant_id', $variantId, PDO::PARAM_INT);
            
            if ($warehouseId !== null) {
                $stmt->bindValue(':warehouse_id', $warehouseId, PDO::PARAM_INT);
            }
            
            $stmt->execute();
            $result = $stmt->fetch();
            $available = (float)$result['total_stock'];
            
            return [
                'valid' => $available >= (float)$quantity,
                'available' => $available,
                'message' => $available >= (float)$quantity ? 
                            'Stock available' : 
                            "Only {$available} units available"
            ];
            
        } catch (PDOException $e) {
            error_log("Product::validateStock Error: " . $e->getMessage());
            return ['valid' => false, 'available' => 0, 'message' => 'Stock validation failed'];
        }
    }
    
    /**
     * Helper: Get stock status text
     */
    private function getStockStatus($quantity, $reorderLevel) {
        if ($quantity <= 0) return 'out_of_stock';
        if ($quantity <= $reorderLevel) return 'low_stock';
        return 'in_stock';
    }
    
    /**
     * Helper: Format price range
     */
    private function formatPriceRange($min, $max) {
        if ($min == $max) {
            return $this->formatCurrency($min);
        }
        return $this->formatCurrency($min) . ' - ' . $this->formatCurrency($max);
    }
    
    /**
     * Helper: Format currency
     */
    private function formatCurrency($amount) {
        return number_format((float)$amount, 0, ',', ' ') . ' RWF';
    }
    
    /**
     * Helper: Get default image placeholder
     */
    private function getDefaultImage($product) {
        return '/assets/img/placeholder.jpg';
    }
}