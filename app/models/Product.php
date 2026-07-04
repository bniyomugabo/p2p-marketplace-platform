<?php
// models/Product.php
// ============================================
// PRODUCT MODEL WITH COMPANY SUPPORT
// ============================================

require_once __DIR__ . '/BaseModel.php';

class Product extends BaseModel
{
    protected $table = 'products';

    /**
     * Constructor - can pass company ID or use session
     */
    public function __construct($companyId = null)
    {
        parent::__construct($companyId);
    }

    /**
     * Validate that category is activated for the company
     */
    protected function validateCategory($categoryId, $companyId = null)
    {
        $companyId = $companyId ?? $this->companyId;

        if (!$companyId) {
            return false;
        }

        $sql = "SELECT COUNT(*) as count FROM category_company 
                WHERE category_id = :category_id AND company_id = :company_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'category_id' => $categoryId,
            'company_id' => $companyId
        ]);

        $result = $stmt->fetch();
        return ($result['count'] ?? 0) > 0;
    }

    /**
     * Get unified catalog (corporate + P2P products)
     * 
     * @param int $page Page number
     * @param int $perPage Items per page
     * @param array $filters Filter options
     * @return array Unified product list
     */
    public function getUnifiedCatalog($page = 1, $perPage = 20, $filters = []) {
        try {
            $conn = $this->db->getConnection();
            $offset = ($page - 1) * $perPage;
            
            // Build filters
            $whereConditions = [];
            $params = [];
            
            if (isset($filters['category_id']) && $filters['category_id']) {
                $whereConditions[] = "category_id = :category_id";
                $params[':category_id'] = $filters['category_id'];
            }
            
            if (isset($filters['search']) && $filters['search']) {
                $whereConditions[] = "(title LIKE :search OR description LIKE :search)";
                $params[':search'] = '%' . $filters['search'] . '%';
            }
            
            if (isset($filters['seller_type']) && $filters['seller_type'] !== 'all') {
                $whereConditions[] = "seller_type = :seller_type";
                $params[':seller_type'] = $filters['seller_type'];
            }
            
            if (isset($filters['min_price']) && $filters['min_price'] > 0) {
                $whereConditions[] = "price >= :min_price";
                $params[':min_price'] = $filters['min_price'];
            }
            
            if (isset($filters['max_price']) && $filters['max_price'] > 0) {
                $whereConditions[] = "price <= :max_price";
                $params[':max_price'] = $filters['max_price'];
            }
            
            $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";
            $orderBy = $this->getOrderBy($filters['sort'] ?? 'newest');
            
            // Corporate products query
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
                                CASE WHEN COALESCE(SUM(i.quantity), 0) > 0 THEN 1 ELSE 0 END as in_stock
                            FROM products p
                            INNER JOIN companies c ON p.company_id = c.id
                            INNER JOIN categories cat ON p.category_id = cat.id
                            INNER JOIN variants v ON v.product_id = p.id AND v.company_id = p.company_id
                            LEFT JOIN inventory i ON i.variant_id = v.id
                            WHERE c.is_public = 1 
                            AND c.is_active = 1
                            AND p.is_active = 1
                            AND v.is_active = 1
                            GROUP BY p.id, p.product_name, p.description, p.long_description, 
                                    c.id, c.company_name, c.slug, cat.id, cat.category_name, p.created_at";
            
            // Peer products query
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
                        AND cs.is_active = 1";
            
            // Apply filters
            if ($whereClause) {
                $corporateWhere = str_replace('title', 'p.product_name', $whereClause);
                $corporateWhere = str_replace('category_id', 'p.category_id', $corporateWhere);
                $corporateSql .= " AND " . substr($corporateWhere, 6);
                $peerSql .= " AND " . substr($whereClause, 6);
            }
            
            // Combined query
            $combinedSql = "SELECT * FROM (($corporateSql) UNION ALL ($peerSql)) AS unified 
                            ORDER BY " . $orderBy . " 
                            LIMIT :limit OFFSET :offset";
            
            // Get count
            $countSql = "SELECT COUNT(*) as total FROM (($corporateSql) UNION ALL ($peerSql)) AS unified";
            $stmt = $conn->prepare($countSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            $total = $stmt->fetch()['total'];
            
            // Get results
            $stmt = $conn->prepare($combinedSql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            
            return [
                'products' => $stmt->fetchAll(),
                'total' => (int)$total,
                'current_page' => (int)$page,
                'per_page' => (int)$perPage
            ];
            
        } catch (\PDOException $e) {
            error_log("Product::getUnifiedCatalog Error: " . $e->getMessage());
            return ['products' => [], 'total' => 0, 'current_page' => 1, 'per_page' => $perPage];
        }
    }

    private function getOrderBy($sort) {
        switch ($sort) {
            case 'price_asc': return 'price ASC';
            case 'price_desc': return 'price DESC';
            case 'name_asc': return 'title ASC';
            case 'name_desc': return 'title DESC';
            default: return 'created_at DESC';
        }
    }
    /**
     * Override create to validate category
     */
    public function create(array $data): int
    {
        // Validate category if provided
        if (isset($data['category_id']) && $data['category_id']) {
            if (!$this->validateCategory($data['category_id'], $data['company_id'] ?? $this->companyId)) {
                throw new Exception('Category is not activated for this company');
            }
        }

        return parent::create($data);
    }

    /**
     * Override update to validate category
     */
    public function update($id, array $data): bool
    {
        // Validate category if being changed
        if (isset($data['category_id']) && $data['category_id']) {
            $product = $this->find($id);
            if ($product && $data['category_id'] != $product['category_id']) {
                if (!$this->validateCategory($data['category_id'], $product['company_id'])) {
                    throw new Exception('Category is not activated for this company');
                }
            }
        }

        return parent::update($id, $data);
    }

    /**
     * Search products by name or code (company-specific)
     */
    public function search($term)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                p.*,
                COUNT(DISTINCT v.id) as variant_count,
                COALESCE(SUM(i.quantity), 0) as total_stock
            FROM {$this->table} p
            LEFT JOIN variants v ON p.id = v.product_id AND v.is_active = 1
            LEFT JOIN inventory i ON v.id = i.variant_id
            WHERE p.is_active = 1 
                AND p.company_id = :company_id
                AND (p.product_name LIKE :term OR p.product_code LIKE :term)
            GROUP BY p.id
            LIMIT 20
        ";

        $params = [
            'company_id' => $this->companyId,
            'term' => "%{$term}%"
        ];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get products by category (company-specific)
     * Ensures category is activated for company
     */
    public function getByCategory($categoryId)
    {
        if (!$this->companyId) {
            return [];
        }

        // Verify category is activated for this company
        if (!$this->validateCategory($categoryId)) {
            return [];
        }

        $sql = "
            SELECT 
                p.*,
                COUNT(DISTINCT v.id) as variant_count,
                COALESCE(SUM(i.quantity), 0) as total_stock,
                MIN(v.selling_price) as min_price,
                MAX(v.selling_price) as max_price
            FROM {$this->table} p
            LEFT JOIN variants v ON p.id = v.product_id AND v.is_active = 1
            LEFT JOIN inventory i ON v.id = i.variant_id
            WHERE p.category_id = :category_id 
                AND p.is_active = 1 
                AND p.company_id = :company_id
            GROUP BY p.id
        ";

        $params = [
            'category_id' => $categoryId,
            'company_id' => $this->companyId
        ];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get low stock products (company-specific)
     */
    public function getLowStock()
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                p.product_name,
                p.product_code,
                p.id as product_id,
                v.id as variant_id,
                v.sku,
                v.variant_name,
                i.quantity,
                v.reorder_level,
                w.warehouse_name,
                w.id as warehouse_id
            FROM inventory i
            INNER JOIN variants v ON i.variant_id = v.id
            INNER JOIN {$this->table} p ON v.product_id = p.id
            INNER JOIN warehouses w ON i.warehouse_id = w.id
            WHERE i.quantity <= v.reorder_level 
                AND i.quantity > 0
                AND p.is_active = 1
                AND p.company_id = :company_id
            ORDER BY (i.quantity / v.reorder_level) ASC
        ";

        $params = ['company_id' => $this->companyId];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Generate unique product code (company-specific)
     */
    public function generateCode()
    {
        $prefix = 'PROD';
        $year = date('y');
        $month = date('m');

        // Add company prefix
        $companyPrefix = 'C' . $this->companyId;

        $sql = "SELECT COUNT(*) as count FROM {$this->table} 
                WHERE product_code LIKE :pattern AND company_id = :company_id";

        $pattern = $companyPrefix . $prefix . $year . $month . '%';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'pattern' => $pattern,
            'company_id' => $this->companyId
        ]);

        $count = $stmt->fetch()['count'] + 1;

        return $companyPrefix . $prefix . $year . $month . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Find product by code (company-specific)
     */
    public function findByCode($code)
    {
        if (!$this->companyId) {
            return null;
        }

        $sql = "SELECT * FROM {$this->table} 
                WHERE product_code = :code 
                AND company_id = :company_id 
                AND is_active = 1";

        $params = [
            'code' => $code,
            'company_id' => $this->companyId
        ];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Find products by name (partial match) (company-specific)
     */
    public function findByName($name)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "SELECT * FROM {$this->table} 
                WHERE product_name LIKE :name 
                AND company_id = :company_id 
                AND is_active = 1
                ORDER BY product_name 
                LIMIT 10";

        $params = [
            'name' => "%{$name}%",
            'company_id' => $this->companyId
        ];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get products with variants count, stock, and price range
     */
    public function getAllWithVariants()
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                p.*,
                c.category_name,
                COUNT(DISTINCT v.id) as variant_count,
                MIN(v.selling_price) as min_price,
                MAX(v.selling_price) as max_price,
                COALESCE(SUM(i.quantity), 0) as total_stock
            FROM {$this->table} p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN variants v ON p.id = v.product_id AND v.is_active = 1
            LEFT JOIN inventory i ON v.id = i.variant_id
            WHERE p.is_active = 1 
                AND p.company_id = :company_id
            GROUP BY p.id
            ORDER BY p.id DESC
        ";

        $params = ['company_id' => $this->companyId];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get single product with all details including variants
     */
    public function getWithDetails($id)
    {
        if (!$this->companyId) {
            return null;
        }

        $sql = "
            SELECT 
                p.*,
                c.category_name,
                cc.parent_id as category_parent_id
            FROM {$this->table} p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN category_company cc ON p.category_id = cc.category_id AND cc.company_id = :p_company_id
            WHERE p.id = :id 
                AND p.is_active = 1 
                AND p.company_id = :company_id
        ";

        $params = [
            'id' => $id,
            'p_company_id' => $this->companyId,
            'company_id' => $this->companyId
        ];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $product = $stmt->fetch();

        if ($product) {
            // Get variants using the Variant model
            $variantModel = new Variant($this->companyId);
            $product['variants'] = $variantModel->getByProductId($id);
            $product['variant_count'] = count($product['variants']);

            // Calculate total stock
            $stockSql = "
                SELECT COALESCE(SUM(i.quantity), 0) as total_stock,
                       COALESCE(SUM(i.committed_quantity), 0) as total_committed
                FROM variants v
                LEFT JOIN inventory i ON v.id = i.variant_id
                WHERE v.product_id = :product_id 
                    AND v.is_active = 1
            ";

            $stockStmt = $this->db->prepare($stockSql);
            $stockStmt->execute(['product_id' => $id]);
            $stockResult = $stockStmt->fetch();

            $product['total_stock'] = $stockResult['total_stock'] ?? 0;
            $product['total_committed'] = $stockResult['total_committed'] ?? 0;
            $product['available_stock'] = $product['total_stock'] - $product['total_committed'];
        }

        return $product;
    }

    /**
     * Get product statistics (company-specific)
     */
    public function getStats()
    {
        if (!$this->companyId) {
            return [
                'total_products' => 0,
                'products_with_variants' => 0,
                'simple_products' => 0,
                'categories_used' => 0
            ];
        }

        $sql = "
            SELECT 
                COUNT(*) as total_products,
                SUM(CASE WHEN has_variants = 1 THEN 1 ELSE 0 END) as products_with_variants,
                SUM(CASE WHEN has_variants = 0 THEN 1 ELSE 0 END) as simple_products,
                COUNT(DISTINCT category_id) as categories_used
            FROM {$this->table}
            WHERE is_active = 1 
                AND company_id = :company_id
        ";

        $params = ['company_id' => $this->companyId];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Get products that are out of stock
     */
    public function getOutOfStock()
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                p.id,
                p.product_name,
                p.product_code,
                v.id as variant_id,
                v.sku,
                v.variant_name,
                COALESCE(SUM(i.quantity), 0) as total_stock
            FROM {$this->table} p
            INNER JOIN variants v ON p.id = v.product_id
            LEFT JOIN inventory i ON v.id = i.variant_id
            WHERE p.is_active = 1
                AND p.company_id = :company_id
                AND v.is_active = 1
            GROUP BY v.id
            HAVING total_stock = 0
            ORDER BY p.product_name
        ";

        $params = ['company_id' => $this->companyId];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get top selling products
     */
    public function getTopSelling($limit = 10)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                p.id,
                p.product_name,
                p.product_code,
                SUM(ii.quantity) as total_quantity_sold,
                SUM(ii.quantity * ii.unit_price) as total_revenue
            FROM {$this->table} p
            INNER JOIN variants v ON p.id = v.product_id
            INNER JOIN invoice_items ii ON v.id = ii.variant_id
            INNER JOIN sales_invoices si ON ii.invoice_id = si.id
            WHERE p.is_active = 1
                AND p.company_id = :company_id
                AND si.status IN ('paid', 'issued')
                AND si.company_id = :company_id
            GROUP BY p.id
            ORDER BY total_quantity_sold DESC
            LIMIT :limit
        ";

        $params = [
            'company_id' => $this->companyId,
            'limit' => $limit
        ];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get products by category with stock info
     */
    public function getByCategoryWithStock($categoryId)
    {
        if (!$this->companyId) {
            return [];
        }

        if (!$this->validateCategory($categoryId)) {
            return [];
        }

        $sql = "
            SELECT 
                p.*,
                COUNT(DISTINCT v.id) as variant_count,
                COALESCE(SUM(i.quantity), 0) as total_stock,
                COALESCE(SUM(i.committed_quantity), 0) as committed_stock
            FROM {$this->table} p
            LEFT JOIN variants v ON p.id = v.product_id AND v.is_active = 1
            LEFT JOIN inventory i ON v.id = i.variant_id
            WHERE p.category_id = :category_id 
                AND p.is_active = 1 
                AND p.company_id = :company_id
            GROUP BY p.id
            HAVING total_stock > 0 OR variant_count = 0
            ORDER BY p.product_name
        ";

        $params = [
            'category_id' => $categoryId,
            'company_id' => $this->companyId
        ];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Check if product exists with given name (company-specific)
     */
    public function existsByName($name, $excludeId = null)
    {
        if (!$this->companyId) {
            return false;
        }

        $sql = "SELECT COUNT(*) as count FROM {$this->table} 
                WHERE product_name = :name 
                AND company_id = :company_id";

        $params = [
            'name' => $name,
            'company_id' => $this->companyId
        ];

        if ($excludeId) {
            $sql .= " AND id != :exclude_id";
            $params['exclude_id'] = $excludeId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();

        return ($result['count'] ?? 0) > 0;
    }
}