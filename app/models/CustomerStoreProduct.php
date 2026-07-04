<?php
// /src/Models/CustomerStoreProduct.php
// Customer Store Product Model - P2P Product Listings

require_once __DIR__ . '/BaseModel.php';

class CustomerStoreProduct extends BaseModel {
    protected $table = 'customer_store_products';
    protected $primaryKey = 'id';
    protected $hasCompanySupport = false;
    
    public function __construct($companyId = null) {
        parent::__construct($companyId);
    }
    
    /**
     * Get product by ID
     */
    public function getById($id) {
        $sql = "SELECT 
                    csp.*,
                    cs.store_name,
                    cs.slug as store_slug,
                    gc.name as category_name
                FROM customer_store_products csp
                INNER JOIN customer_stores cs ON csp.customer_store_id = cs.id
                LEFT JOIN global_categories gc ON csp.global_category_id = gc.id
                WHERE csp.id = :id AND csp.is_active = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }
    
    /**
     * Get product by slug
     */
    public function getBySlug($slug) {
        $sql = "SELECT 
                    csp.*,
                    cs.store_name,
                    cs.slug as store_slug,
                    gc.name as category_name
                FROM customer_store_products csp
                INNER JOIN customer_stores cs ON csp.customer_store_id = cs.id
                LEFT JOIN global_categories gc ON csp.global_category_id = gc.id
                WHERE csp.slug = :slug AND csp.is_active = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':slug' => $slug]);
        return $stmt->fetch();
    }
    
    /**
     * Get products by category
     */
    public function getByCategory($categoryId, $page = 1, $limit = 20) {
        $offset = ($page - 1) * $limit;
        $sql = "SELECT 
                    csp.*,
                    cs.store_name,
                    cs.slug as store_slug
                FROM customer_store_products csp
                INNER JOIN customer_stores cs ON csp.customer_store_id = cs.id
                WHERE csp.global_category_id = :category_id 
                AND csp.is_active = 1
                ORDER BY csp.created_at DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':category_id', $categoryId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * Search products
     */
    public function search($term, $page = 1, $limit = 20) {
        $offset = ($page - 1) * $limit;
        $searchTerm = '%' . $term . '%';
        $sql = "SELECT 
                    csp.*,
                    cs.store_name,
                    cs.slug as store_slug
                FROM customer_store_products csp
                INNER JOIN customer_stores cs ON csp.customer_store_id = cs.id
                WHERE (csp.title LIKE :search OR csp.description LIKE :search)
                AND csp.is_active = 1
                ORDER BY csp.created_at DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':search', $searchTerm);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * Update stock quantity
     */
    public function updateStock($productId, $quantity) {
        $sql = "UPDATE customer_store_products SET stock_quantity = stock_quantity + :quantity 
                WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':quantity' => $quantity, ':id' => $productId]);
    }
}