<?php
// models/Category.php
// ============================================
// CATEGORY MODEL WITH GLOBAL CATEGORIES + COMPANY ACTIVATION
// ============================================

require_once __DIR__ . '/BaseModel.php';

class Category extends BaseModel
{
    protected $table = 'categories';

    // Override: categories table has NO company_id
    protected $hasCompanySupport = false; // Categories is global master table

    /**
     * Constructor - can pass company ID or use session
     */
    public function __construct($companyId = null)
    {
        parent::__construct($companyId);
    }

    /**
     * Get last insert ID
     */
    public function getLastInsertId(): int
    {
        return (int) $this->db->lastInsertId();
    }

    /**
     * Find category by code or name (global, no company filter)
     */
    public function findByCode($categoryCode)
    {
        $sql = "SELECT * FROM categories WHERE category_code = :category_code LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['category_code' => $categoryCode]);
        return $stmt->fetch();
    }

    public function findByName($categoryName)
    {
        $sql = "SELECT * FROM categories WHERE category_name = :category_name LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['category_name' => $categoryName]);
        return $stmt->fetch();
    }

    /**
     * Create a new global category
     */
    public function createGlobalCategory($data): int
    {
        $sql = "INSERT INTO categories (category_code, category_name) 
                VALUES (:category_code, :category_name)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'category_code' => $data['category_code'],
            'category_name' => $data['category_name']
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update company category settings
     */
    public function updateCompanyCategory($data): bool
    {
        $sql = "UPDATE category_company SET parent_id = :parent_id, description = :description, updated_at = NOW()
                WHERE category_id = :category_id AND company_id = :company_id";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'category_id' => $data['category_id'],
            'company_id' => $data['company_id'],
            'parent_id' => $data['parent_id'] ?? null,
            'description' => $data['description'] ?? null
        ]);
    }

    /**
     * Activate a category for a company (add to category_company)
     */
    public function activateForCompany($categoryId, $companyId = null, $parentId = null, $description = null): bool
    {
        $companyId = $companyId ?? $this->companyId;

        if (!$companyId) {
            error_log("Cannot activate category: no company ID provided");
            return false;
        }

        // Check if already activated
        if ($this->isActivatedForCompany($categoryId, $companyId)) {
            return true; // Already activated
        }

        $sql = "INSERT INTO category_company (category_id, company_id, parent_id, description, is_active, created_at) 
                VALUES (:category_id, :company_id, :parent_id, :description, 1, NOW())";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'category_id' => $categoryId,
            'company_id' => $companyId,
            'parent_id' => $parentId,
            'description' => $description
        ]);
    }

    /**
     * Deactivate a category for a company
     */
    public function deactivateForCompany($categoryId, $companyId = null): bool
    {
        $companyId = $companyId ?? $this->companyId;

        if (!$companyId) {
            return false;
        }

        $sql = "UPDATE category_company SET is_active = 0, updated_at = NOW()
                WHERE category_id = :category_id AND company_id = :company_id";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'category_id' => $categoryId,
            'company_id' => $companyId
        ]);
    }

    /**
     * Check if category is activated for a company
     */
    public function isActivatedForCompany($categoryId, $companyId = null): bool
    {
        $companyId = $companyId ?? $this->companyId;

        if (!$companyId) {
            return false;
        }

        $sql = "SELECT COUNT(*) as count FROM category_company 
                WHERE category_id = :category_id AND company_id = :company_id AND is_active = 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'category_id' => $categoryId,
            'company_id' => $companyId
        ]);

        $result = $stmt->fetch();
        return ($result['count'] ?? 0) > 0;
    }

    /**
     * Get categories activated for a specific company
     * 
     * @param int|null $companyId Company ID (defaults to current company context)
     * @param bool $activeOnly Only return active categories (default: true)
     * @return array List of activated categories with company-specific settings
     */
    public function getActivatedCategories($companyId = null, $activeOnly = true)
    {
        $companyId = $companyId ?? $this->companyId;

        if (!$companyId) {
            return [];
        }

        $sql = "
            SELECT 
                c.id,
                c.category_code,
                c.category_name,
                c.created_at as global_created_at,
                cc.id as company_category_id,
                cc.parent_id,
                cc.description,
                cc.is_active,
                cc.created_at as activated_at,
                cc.updated_at,
                CASE 
                    WHEN cc.parent_id IS NOT NULL THEN (
                        SELECT c2.category_name 
                        FROM categories c2 
                        WHERE c2.id = cc.parent_id
                    )
                    ELSE NULL
                END as parent_name
            FROM categories c
            INNER JOIN category_company cc ON c.id = cc.category_id
            WHERE cc.company_id = :company_id
        ";

        if ($activeOnly) {
            $sql .= " AND cc.is_active = 1";
        }

        $sql .= " ORDER BY COALESCE(cc.parent_id, 0), c.category_name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $companyId]);

        return $stmt->fetchAll();
    }

    /**
     * Get all categories with hierarchy (company-specific - only activated categories)
     */
    public function getAllWithHierarchy()
    {
        if (!$this->companyId) {
            return [];
        }

        // Get all activated categories for this company
        $sql = "SELECT 
                    c.*, 
                    cc.description,
                    cc.is_active,
                    cc.parent_id,
                    cc.created_at as activated_at
                FROM categories c
                INNER JOIN category_company cc ON cc.category_id = c.id
                WHERE cc.company_id = :company_id 
                    AND cc.is_active = 1
                ORDER BY c.category_name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        $categories = $stmt->fetchAll();

        // Build tree
        $tree = [];
        $indexed = [];

        // First pass: index by id
        foreach ($categories as $category) {
            $category['children'] = [];
            $indexed[$category['id']] = $category;
        }

        // Second pass: build tree
        foreach ($indexed as $id => $category) {
            if ($category['parent_id'] && isset($indexed[$category['parent_id']])) {
                $indexed[$category['parent_id']]['children'][] = &$indexed[$id];
            } else {
                $tree[] = &$indexed[$id];
            }
        }

        return $tree;
    }

    /**
     * Get category options for select dropdown (company-specific)
     */
    public function getOptions($selected = null, $parentId = null, $prefix = '')
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "SELECT 
                    c.*, 
                    cc.parent_id
                FROM categories c
                INNER JOIN category_company cc ON cc.category_id = c.id
                WHERE cc.company_id = :company_id 
                    AND cc.is_active = 1";

        $params = ['company_id' => $this->companyId];

        if ($parentId !== null) {
            $sql .= " AND cc.parent_id = :parent_id";
            $params['parent_id'] = $parentId;
        } else {
            $sql .= " AND cc.parent_id IS NULL";
        }

        $sql .= " ORDER BY c.category_name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $categories = $stmt->fetchAll();

        $options = [];
        foreach ($categories as $category) {
            $value = $category['id'];
            $text = $prefix . $category['category_name'];
            $options[] = [
                'value' => $value,
                'text' => $text,
                'selected' => ($selected == $value)
            ];

            // Get subcategories recursively
            $subOptions = $this->getOptions($selected, $category['id'], $prefix . '-- ');
            $options = array_merge($options, $subOptions);
        }

        return $options;
    }

    /**
     * Get categories with product count (company-specific)
     */
    public function getWithProductCount()
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                c.*,
                COUNT(p.id) as product_count
            FROM categories c
            INNER JOIN category_company cc ON c.id = cc.category_id
            LEFT JOIN products p ON c.id = p.category_id 
                AND p.company_id = :company_id
                AND p.is_active = 1
            WHERE cc.company_id = :company_id 
                AND cc.is_active = 1
            GROUP BY c.id
            ORDER BY c.category_name
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        return $stmt->fetchAll();
    }

    /**
     * Get parent ID for company-specific category
     */
    public function getParentForCompany($categoryId, $companyId = null)
    {
        $companyId = $companyId ?? $this->companyId;

        if (!$companyId) {
            return null;
        }

        $sql = "SELECT parent_id FROM category_company 
            WHERE category_id = :category_id AND company_id = :company_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'category_id' => $categoryId,
            'company_id' => $companyId
        ]);

        $result = $stmt->fetch();
        return $result ? $result['parent_id'] : null;
    }

    /**
     * Get category path (breadcrumb) - company check included
     */
    public function getPath($categoryId)
    {
        if (!$this->companyId) {
            return [];
        }

        $path = [];
        $currentId = $categoryId;

        while ($currentId) {
            $sql = "SELECT 
                        c.*, 
                        cc.parent_id,
                        cc.created_at as activated_at
                    FROM categories c
                    INNER JOIN category_company cc ON cc.category_id = c.id
                    WHERE c.id = :id 
                        AND cc.company_id = :company_id
                        AND cc.is_active = 1";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'id' => $currentId,
                'company_id' => $this->companyId
            ]);
            $category = $stmt->fetch();

            if (!$category) {
                break;
            }

            array_unshift($path, $category);
            $currentId = $category['parent_id'];
        }

        return $path;
    }

    /**
     * Get all global categories (for admin to manage)
     */
    public function getAllGlobalCategories()
    {
        $sql = "SELECT * FROM categories ORDER BY category_name";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get categories not yet activated for a company
     */
    public function getAvailableForCompany($companyId = null)
    {
        $companyId = $companyId ?? $this->companyId;

        if (!$companyId) {
            return [];
        }

        $sql = "SELECT * FROM categories 
                WHERE id NOT IN (
                    SELECT category_id FROM category_company 
                    WHERE company_id = :company_id
                )
                ORDER BY category_name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $companyId]);
        return $stmt->fetchAll();
    }

    /**
     * Create or get category and activate for company (convenience method)
     */
    public function findOrCreateAndActivate($categoryData, $companyId = null)
    {
        $companyId = $companyId ?? $this->companyId;

        if (!$companyId) {
            return false;
        }

        // Try to find existing category
        $category = null;
        if (!empty($categoryData['category_code'])) {
            $category = $this->findByCode($categoryData['category_code']);
        }

        if (!$category && !empty($categoryData['category_name'])) {
            $category = $this->findByName($categoryData['category_name']);
        }

        // Create if not exists
        if (!$category) {
            $categoryId = $this->createGlobalCategory($categoryData);
            if (!$categoryId) {
                return false;
            }
            $category = $this->find($categoryId);
        }

        // Activate for company
        if ($category && !$this->isActivatedForCompany($category['id'], $companyId)) {
            $parentId = $categoryData['parent_id'] ?? null;
            $description = $categoryData['description'] ?? null;
            $this->activateForCompany($category['id'], $companyId, $parentId, $description);
        }

        return $category;
    }

    /**
     * Get categories that are activated for a company (alias for compatibility)
     */
    public function getActivated($companyId = null)
    {
        return $this->getActivatedCategories($companyId, true);
    }

    /**
     * Get activated categories count for a company
     */
    public function getActivatedCount($companyId = null)
    {
        $companyId = $companyId ?? $this->companyId;

        if (!$companyId) {
            return 0;
        }

        $sql = "SELECT COUNT(*) as count FROM category_company 
                WHERE company_id = :company_id AND is_active = 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $companyId]);
        $result = $stmt->fetch();

        return (int) ($result['count'] ?? 0);
    }

    /**
     * Get category tree as HTML options
     */
    public function getTreeOptions($selected = null, $companyId = null)
    {
        $companyId = $companyId ?? $this->companyId;

        if (!$companyId) {
            return [];
        }

        $categories = $this->getAllWithHierarchy();
        $options = [];
        $this->buildTreeOptions($categories, $options, $selected);

        return $options;
    }

    /**
     * Helper to build tree options recursively
     */
    private function buildTreeOptions($categories, &$options, $selected = null, $prefix = '')
    {
        foreach ($categories as $category) {
            $options[] = [
                'value' => $category['id'],
                'text' => $prefix . $category['category_name'],
                'selected' => ($selected == $category['id'])
            ];

            if (!empty($category['children'])) {
                $this->buildTreeOptions($category['children'], $options, $selected, $prefix . '-- ');
            }
        }
    }
}