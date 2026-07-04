<?php
// models/Warehouse.php
// ============================================
// WAREHOUSE MODEL WITH COMPANY ISOLATION
// ============================================

require_once __DIR__ . '/BaseModel.php';

class Warehouse extends BaseModel
{
    protected $table = 'warehouses';
    protected $primaryKey = 'id';

    // Warehouses table has company_id column for isolation
    protected $hasCompanySupport = true;

    /**
     * Constructor - can pass company ID or use session
     */
    public function __construct($companyId = null)
    {
        parent::__construct($companyId);
    }

    /**
     * Generate warehouse code (company-specific)
     */
    public function generateCode()
    {
        if (!$this->companyId) {
            return 'WH' . date('y') . '001';
        }

        $prefix = 'WH';
        $year = date('y');

        $sql = "SELECT COUNT(*) as count FROM {$this->table} 
                WHERE warehouse_code LIKE :pattern AND company_id = :company_id";

        $pattern = $prefix . $year . '%';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'pattern' => $pattern,
            'company_id' => $this->companyId
        ]);
        $count = $stmt->fetch()['count'] + 1;

        return $prefix . $year . str_pad($count, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Find warehouse by code (company-specific)
     */
    public function findByCode($code)
    {
        if (!$this->companyId) {
            return null;
        }

        $sql = "SELECT * FROM {$this->table} 
                WHERE warehouse_code = :code 
                AND company_id = :company_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'code' => $code,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetch();
    }

    /**
     * Get main warehouse for this company
     */
    public function getMainWarehouse()
    {
        if (!$this->companyId) {
            return null;
        }

        $sql = "SELECT * FROM {$this->table} 
                WHERE is_main = 1 AND is_active = 1 AND company_id = :company_id 
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        return $stmt->fetch();
    }

    /**
     * Get warehouse with location count (company-specific)
     */
    public function getWithLocationCount()
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                w.*,
                COUNT(l.id) as location_count,
                SUM(CASE WHEN l.is_active = 1 THEN 1 ELSE 0 END) as active_locations
            FROM {$this->table} w
            LEFT JOIN locations l ON w.id = l.warehouse_id
            WHERE w.company_id = :company_id
            GROUP BY w.id 
            ORDER BY w.warehouse_name
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        return $stmt->fetchAll();
    }

    /**
     * Get warehouse with inventory summary (company-specific)
     */
    public function getWithInventorySummary($warehouseId)
    {
        if (!$this->companyId) {
            return null;
        }

        $sql = "
            SELECT 
                w.*,
                COUNT(DISTINCT i.variant_id) as product_count,
                COALESCE(SUM(i.quantity), 0) as total_items,
                COALESCE(SUM(i.quantity * COALESCE(i.avg_landed_cost, v.purchase_price, 0)), 0) as total_value
            FROM {$this->table} w
            LEFT JOIN inventory i ON w.id = i.warehouse_id AND i.company_id = :company_id
            LEFT JOIN variants v ON i.variant_id = v.id
            WHERE w.id = :warehouse_id AND w.company_id = :company_id
            GROUP BY w.id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'warehouse_id' => $warehouseId,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetch();
    }

    /**
     * Get all warehouses for this company with inventory value
     */
    public function getAllWithInventoryValue()
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                w.*,
                COUNT(DISTINCT i.variant_id) as product_count,
                COALESCE(SUM(i.quantity), 0) as total_quantity,
                COALESCE(SUM(i.quantity * COALESCE(i.avg_landed_cost, v.purchase_price, 0)), 0) as total_value
            FROM {$this->table} w
            LEFT JOIN inventory i ON w.id = i.warehouse_id AND i.company_id = :p_1_company_id
            LEFT JOIN variants v ON i.variant_id = v.id
            WHERE w.company_id = :p_2_company_id
            GROUP BY w.id 
            ORDER BY w.is_main DESC, w.warehouse_name
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['p_1_company_id' => $this->companyId, 'p_2_company_id' => $this->companyId]);
        return $stmt->fetchAll();
    }

    /**
     * Get active warehouses only for this company
     */
    public function getActiveWarehouses()
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "SELECT * FROM {$this->table} 
                WHERE is_active = 1 AND company_id = :company_id 
                ORDER BY is_main DESC, warehouse_name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        return $stmt->fetchAll();
    }

    /**
     * Get warehouse options for dropdown (only this company's warehouses)
     */
    public function getOptions($selected = null)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "SELECT id, warehouse_code, warehouse_name, city, is_main 
                FROM {$this->table} 
                WHERE is_active = 1 AND company_id = :company_id 
                ORDER BY is_main DESC, warehouse_name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        $warehouses = $stmt->fetchAll();

        $options = [];
        foreach ($warehouses as $wh) {
            $text = $wh['warehouse_name'];
            if ($wh['warehouse_code']) {
                $text .= ' (' . $wh['warehouse_code'] . ')';
            }
            if ($wh['city']) {
                $text .= ' - ' . $wh['city'];
            }
            if ($wh['is_main']) {
                $text .= ' [Main]';
            }
            $options[] = [
                'value' => $wh['id'],
                'text' => $text,
                'selected' => ($selected == $wh['id'])
            ];
        }

        return $options;
    }

    /**
     * Transfer stock between warehouses (within same company)
     */
    public function transferStock($fromWarehouseId, $toWarehouseId, $variantId, $quantity, $userId = null)
    {
        if (!$this->companyId) {
            throw new Exception('Company context required for stock transfer');
        }

        try {
            // Verify both warehouses belong to this company
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count FROM {$this->table} 
                WHERE id IN (:from_id, :to_id) AND company_id = :company_id
            ");
            $stmt->execute([
                'from_id' => $fromWarehouseId,
                'to_id' => $toWarehouseId,
                'company_id' => $this->companyId
            ]);
            $result = $stmt->fetch();

            if ($result['count'] != 2) {
                throw new Exception("One or both warehouses do not belong to this company");
            }

            $this->db->beginTransaction();

            $inventoryModel = new Inventory($this->companyId);

            // Remove from source
            $inventoryModel->updateStock(
                $variantId,
                $fromWarehouseId,
                -$quantity,
                null,
                null,
                $userId
            );

            // Add to destination
            $inventoryModel->updateStock(
                $variantId,
                $toWarehouseId,
                $quantity,
                null,
                null,
                $userId
            );

            $this->db->commit();
            return true;

        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Warehouse transfer error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Search warehouses within this company
     */
    public function search($term)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT * FROM {$this->table} 
            WHERE (warehouse_name LIKE :term OR warehouse_code LIKE :term OR city LIKE :term OR address LIKE :term)
                AND company_id = :company_id
            ORDER BY warehouse_name 
            LIMIT 20
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'term' => "%{$term}%",
            'company_id' => $this->companyId
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Get warehouse statistics for this company
     */
    public function getStats()
    {
        if (!$this->companyId) {
            return [
                'total_warehouses' => 0,
                'active_warehouses' => 0,
                'has_main' => false,
                'main_warehouse_name' => null,
                'total_locations' => 0,
                'total_stock_value' => 0,
                'total_products' => 0
            ];
        }

        $sql = "
            SELECT 
                COUNT(DISTINCT w.id) as total_warehouses,
                SUM(CASE WHEN w.is_active = 1 THEN 1 ELSE 0 END) as active_warehouses,
                SUM(CASE WHEN w.is_main = 1 THEN 1 ELSE 0 END) as has_main,
                (SELECT warehouse_name FROM {$this->table} 
                 WHERE company_id = :company_id AND is_main = 1 LIMIT 1) as main_warehouse_name,
                (SELECT COUNT(*) FROM locations l 
                 WHERE l.warehouse_id IN (SELECT id FROM {$this->table} WHERE company_id = :company_id)) as total_locations,
                COALESCE(SUM(i.quantity * COALESCE(i.avg_landed_cost, v.purchase_price, 0)), 0) as total_stock_value,
                COUNT(DISTINCT i.variant_id) as total_products
            FROM {$this->table} w
            LEFT JOIN inventory i ON w.id = i.warehouse_id AND i.company_id = :company_id
            LEFT JOIN variants v ON i.variant_id = v.id
            WHERE w.company_id = :company_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        $result = $stmt->fetch();

        return [
            'total_warehouses' => (int) ($result['total_warehouses'] ?? 0),
            'active_warehouses' => (int) ($result['active_warehouses'] ?? 0),
            'has_main' => (int) ($result['has_main'] ?? 0) > 0,
            'main_warehouse_name' => $result['main_warehouse_name'] ?? null,
            'total_locations' => (int) ($result['total_locations'] ?? 0),
            'total_stock_value' => (float) ($result['total_stock_value'] ?? 0),
            'total_products' => (int) ($result['total_products'] ?? 0)
        ];
    }

    /**
     * Check if warehouse has stock (within this company)
     */
    public function hasStock($warehouseId)
    {
        if (!$this->companyId) {
            return false;
        }

        $sql = "SELECT COUNT(*) as count FROM inventory 
                WHERE warehouse_id = :warehouse_id 
                AND company_id = :company_id 
                AND quantity > 0";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'warehouse_id' => $warehouseId,
            'company_id' => $this->companyId
        ]);
        $result = $stmt->fetch();

        return ($result['count'] ?? 0) > 0;
    }

    /**
     * Get warehouse by ID with company validation
     */
    public function find($id)
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = :id";
        $params = ['id' => $id];

        if ($this->companyId) {
            $sql .= " AND company_id = :company_id";
            $params['company_id'] = $this->companyId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Get all warehouses for this company
     */
    public function all($columns = ['*'], $where = '', $params = [])
    {
        if ($this->companyId) {
            $where = empty($where)
                ? "company_id = :company_id"
                : "({$where}) AND company_id = :company_id";
            $params['company_id'] = $this->companyId;
        }

        return parent::all($columns, $where, $params);
    }

    /**
     * Create new warehouse (automatically adds company_id)
     */
    public function create(array $data): int
    {
        // Always add company_id for isolation
        if ($this->companyId) {
            $data['company_id'] = $this->companyId;
        }

        // Generate warehouse code if not provided
        if (empty($data['warehouse_code'])) {
            $data['warehouse_code'] = $this->generateCode();
        }

        return parent::create($data);
    }

    /**
     * Update warehouse (with company validation)
     */
    public function update($id, array $data): bool
    {
        // Verify warehouse belongs to this company
        $warehouse = $this->find($id);
        if (!$warehouse) {
            throw new Exception('Warehouse not found or not accessible for this company');
        }

        return parent::update($id, $data);
    }

    /**
     * Delete warehouse (with company validation)
     */
    public function delete($id, $softDelete = true): bool
    {
        // Verify warehouse belongs to this company
        $warehouse = $this->find($id);
        if (!$warehouse) {
            throw new Exception('Warehouse not found or not accessible for this company');
        }

        // Check if it's main warehouse
        if ($warehouse['is_main']) {
            throw new Exception('Cannot delete the main warehouse. Set another warehouse as main first.');
        }

        // Check if it has stock
        if ($this->hasStock($id)) {
            throw new Exception('Cannot delete warehouse with existing stock. Transfer or remove stock first.');
        }

        return parent::delete($id, $softDelete);
    }

    /**
     * Set a warehouse as main (removes main from others in this company)
     */
    public function setAsMain($warehouseId): bool
    {
        if (!$this->companyId) {
            throw new Exception('Company context required');
        }

        try {
            $this->db->beginTransaction();

            // Remove main flag from all warehouses in this company
            $stmt = $this->db->prepare("UPDATE {$this->table} SET is_main = 0 WHERE company_id = :company_id");
            $stmt->execute(['company_id' => $this->companyId]);

            // Set the selected warehouse as main
            $stmt = $this->db->prepare("UPDATE {$this->table} SET is_main = 1 WHERE id = :id AND company_id = :company_id");
            $stmt->execute(['id' => $warehouseId, 'company_id' => $this->companyId]);

            $this->db->commit();
            return true;

        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}