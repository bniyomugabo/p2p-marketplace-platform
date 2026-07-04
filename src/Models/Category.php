<?php
// /src/Models/Category.php
// Category Model - Handles category operations for public store
require_once __DIR__ . '/../../config/database.php';
class Category {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    /**
     * Fetch top root items (Level 1 Categories)
     */
    public function getActiveRootCategories() {
        try {
            $sql = "SELECT id, name, slug, sort_order 
                    FROM shop_product_classes 
                    WHERE parent_id IS NULL 
                    ORDER BY sort_order ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Category::getActiveRootCategories Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get item structure profile matching a unique safe Slug handle
     */
    public function getCategoryBySlug($slug) {
        try {
            $sql = "SELECT id, parent_id, name, slug FROM shop_product_classes WHERE slug = :slug LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':slug', $slug, PDO::PARAM_STR);
            $stmt->execute();
            return $stmt->fetch() ?: null;
        } catch (PDOException $e) {
            error_log("Category::getCategoryBySlug Error: " . $e->getMessage());
            return null;
        }
    }
    /**
     * Get all active categories with product counts for public store
     * 
     * @return array
     */
    public function getActiveCategories() {
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
            error_log("Category::getActiveCategories Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get category by ID
     * 
     * @param int $categoryId Category ID
     * @return array|null
     */
    public function getCategoryById($categoryId) {
        try {
            $sql = "SELECT * FROM categories WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':id', $categoryId, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetch();
            
        } catch (PDOException $e) {
            error_log("Category::getCategoryById Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get categories with hierarchical structure
     * 
     * @return array
     */
    public function getCategoryHierarchy() {
        try {
            $sql = "SELECT DISTINCT 
                        c.id,
                        c.category_code,
                        c.category_name,
                        cc.parent_id,
                        COUNT(DISTINCT p.id) as product_count
                    FROM categories c
                    INNER JOIN category_company cc ON c.id = cc.category_id
                    INNER JOIN companies comp ON cc.company_id = comp.id
                    LEFT JOIN products p ON p.category_id = c.id AND p.company_id = comp.id AND p.is_active = 1
                    LEFT JOIN variants v ON v.product_id = p.id AND v.company_id = comp.id AND v.is_active = 1
                    WHERE comp.is_public = 1
                    AND comp.is_active = 1
                    AND cc.is_active = 1
                    GROUP BY c.id, c.category_code, c.category_name, cc.parent_id
                    ORDER BY c.category_name ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
            
            $allCategories = $stmt->fetchAll();
            
            // Build hierarchical structure
            $categories = [];
            $byId = [];
            
            foreach ($allCategories as $cat) {
                $cat['children'] = [];
                $byId[$cat['id']] = $cat;
            }
            
            foreach ($byId as $id => $cat) {
                if ($cat['parent_id'] && isset($byId[$cat['parent_id']])) {
                    $byId[$cat['parent_id']]['children'][] = &$byId[$id];
                } else {
                    $categories[] = &$byId[$id];
                }
            }
            
            return $categories;
            
        } catch (PDOException $e) {
            error_log("Category::getCategoryHierarchy Error: " . $e->getMessage());
            return [];
        }
    }
}