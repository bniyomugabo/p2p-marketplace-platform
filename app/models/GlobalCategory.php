<?php
// /src/Models/GlobalCategory.php
// Global Category Model - For P2P marketplace-wide categories

require_once __DIR__ . '/BaseModel.php';

class GlobalCategory extends BaseModel {
    protected $table = 'global_categories';
    protected $primaryKey = 'id';
    protected $hasCompanySupport = false; // Global categories are not company-specific
    
    public function __construct($companyId = null) {
        parent::__construct($companyId);
    }
    
    /**
     * Get all active global categories
     */
    public function getAll() {
        $sql = "SELECT * FROM global_categories ORDER BY name ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * Get category by slug
     */
    public function getBySlug($slug) {
        $sql = "SELECT * FROM global_categories WHERE slug = :slug LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':slug' => $slug]);
        return $stmt->fetch();
    }
    
    /**
     * Get category with product count
     */
    public function getWithProductCount() {
        $sql = "SELECT 
                    gc.*,
                    COUNT(csp.id) as product_count
                FROM global_categories gc
                LEFT JOIN customer_store_products csp ON gc.id = csp.global_category_id AND csp.is_active = 1
                GROUP BY gc.id
                ORDER BY gc.name ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}