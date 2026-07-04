<?php
// models/Location.php
// ============================================
// STORAGE LOCATION MODEL WITH COMPANY SUPPORT
// ============================================

require_once __DIR__ . '/BaseModel.php';

class Location extends BaseModel
{
    protected $table = 'locations';
    protected $primaryKey = 'id';
    protected $hasCompanySupport = true;

    /**
     * Constructor - can pass company ID or use session
     */
    public function __construct($companyId = null)
    {
        parent::__construct($companyId);
    }

    /**
     * Generate location code
     */
    public function generateCode($warehouseId)
    {
        // First verify warehouse belongs to this company
        if ($this->companyId) {
            $stmt = $this->db->prepare("SELECT company_id, warehouse_code FROM warehouses WHERE id = ?");
            $stmt->execute([$warehouseId]);
            $warehouse = $stmt->fetch();

            if (!$warehouse || $warehouse['company_id'] != $this->companyId) {
                throw new Exception("Warehouse does not belong to this company");
            }
            $prefix = $warehouse['warehouse_code'];
        } else {
            $sql = "SELECT warehouse_code FROM warehouses WHERE id = :warehouse_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['warehouse_id' => $warehouseId]);
            $warehouse = $stmt->fetch();
            $prefix = $warehouse ? $warehouse['warehouse_code'] : 'LOC';
        }

        $sql = "SELECT COUNT(*) as count FROM {$this->table} WHERE warehouse_id = :warehouse_id AND company_id = :company_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'warehouse_id' => $warehouseId,
            'company_id' => $this->companyId
        ]);
        $count = $stmt->fetch()['count'] + 1;

        return $prefix . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Find location by code (company-specific)
     */
    public function findByCode($code, $warehouseId = null)
    {
        if (!$this->companyId) {
            return null;
        }

        $sql = "SELECT l.* FROM {$this->table} l
                JOIN warehouses w ON l.warehouse_id = w.id
                WHERE l.location_code = :code AND w.company_id = :company_id";
        $params = [
            'code' => $code,
            'company_id' => $this->companyId
        ];

        if ($warehouseId) {
            $sql .= " AND l.warehouse_id = :warehouse_id";
            $params['warehouse_id'] = $warehouseId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Get locations by warehouse (company-specific)
     */
    public function getByWarehouse($warehouseId, $activeOnly = true)
    {
        if (!$this->companyId) {
            return [];
        }

        // Verify warehouse belongs to company
        $stmt = $this->db->prepare("SELECT company_id FROM warehouses WHERE id = ? AND company_id = ?");
        $stmt->execute([$warehouseId, $this->companyId]);
        $warehouse = $stmt->fetch();

        if (!$warehouse) {
            return [];
        }

        $sql = "SELECT * FROM {$this->table} WHERE warehouse_id = :warehouse_id AND company_id = :company_id";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY location_code";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'warehouse_id' => $warehouseId,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Get all locations with warehouse info (company-specific)
     */
    public function getAllWithWarehouse()
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                l.*,
                w.warehouse_name,
                w.warehouse_code as parent_code,
                w.warehouse_code,
                w.city as warehouse_city
            FROM {$this->table} l
            JOIN warehouses w ON l.warehouse_id = w.id
            WHERE l.is_active = 1 AND w.company_id = :p_1_company_id AND l.company_id = :p_2_company_id
            ORDER BY w.warehouse_name, l.location_code
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['p_1_company_id' => $this->companyId, 'p_2_company_id' => $this->companyId]);
        $locations = $stmt->fetchAll();

        // Add parent_code for backward compatibility
        foreach ($locations as &$location) {
            $location['parent_code'] = $location['warehouse_code'];
        }

        return $locations;
    }

    /**
     * Get location hierarchy (parent-child) (company-specific)
     */
    public function getHierarchy($warehouseId = null)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                l.*,
                w.warehouse_name,
                w.warehouse_code as parent_code
            FROM {$this->table} l
            JOIN warehouses w ON l.warehouse_id = w.id
            WHERE l.is_active = 1 AND w.company_id = :p_1_company_id AND l.company_id = :p_2_company_id
        ";
        $params = ['p_1_company_id' => $this->companyId, 'p_2_company_id' => $this->companyId];

        if ($warehouseId) {
            $sql .= " AND l.warehouse_id = :warehouse_id";
            $params['warehouse_id'] = $warehouseId;
        }

        $sql .= " ORDER BY l.parent_location_id, l.location_code";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $locations = $stmt->fetchAll();

        // Build tree
        $tree = [];
        $indexed = [];

        foreach ($locations as $location) {
            $location['children'] = [];
            $location['level'] = 0;
            $indexed[$location['id']] = $location;
        }

        foreach ($indexed as $id => $location) {
            if ($location['parent_location_id'] && isset($indexed[$location['parent_location_id']])) {
                $indexed[$location['parent_location_id']]['children'][] = &$indexed[$id];
            } else {
                $tree[] = &$indexed[$id];
            }
        }

        return $tree;
    }

    /**
     * Get flattened location hierarchy with proper levels (company-specific)
     */
    public function getFlattenedHierarchy($warehouseId = null)
    {
        $tree = $this->getHierarchy($warehouseId);
        $result = [];
        $this->flattenLocations($tree, 0, $result);
        return $result;
    }

    /**
     * Helper function to flatten location tree with levels
     */
    private function flattenLocations($locations, $level = 0, &$result = [])
    {
        foreach ($locations as $location) {
            $location['level'] = $level;
            $result[] = $location;
            if (!empty($location['children'])) {
                $this->flattenLocations($location['children'], $level + 1, $result);
            }
        }
        return $result;
    }

    /**
     * Get location with inventory summary (with company check)
     */
    public function getWithInventory($locationId)
    {
        if (!$this->companyId) {
            return null;
        }

        $sql = "
            SELECT 
                l.*,
                w.warehouse_name,
                w.warehouse_code as parent_code,
                COUNT(DISTINCT i.variant_id) as product_count,
                COALESCE(SUM(i.quantity), 0) as total_items,
                COALESCE(SUM(i.quantity * COALESCE(i.avg_landed_cost, v.purchase_price, 0)), 0) as total_value
            FROM {$this->table} l
            JOIN warehouses w ON l.warehouse_id = w.id AND w.company_id = :company_id
            LEFT JOIN inventory i ON l.id = i.location_id AND i.company_id = :company_id
            LEFT JOIN variants v ON i.variant_id = v.id
            WHERE l.id = :location_id AND l.company_id = :company_id
            GROUP BY l.id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'location_id' => $locationId,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetch();
    }

    /**
     * Get location options for dropdown (company-specific)
     */
    public function getOptions($warehouseId = null, $selected = null)
    {
        if (!$this->companyId) {
            return [];
        }

        if ($warehouseId) {
            $sql = "SELECT l.id, l.location_code, l.location_name 
                    FROM {$this->table} l
                    JOIN warehouses w ON l.warehouse_id = w.id
                    WHERE l.warehouse_id = :warehouse_id 
                        AND l.is_active = 1 
                        AND w.company_id = :company_id
                        AND l.company_id = :company_id
                    ORDER BY l.location_code";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'warehouse_id' => $warehouseId,
                'company_id' => $this->companyId
            ]);
        } else {
            $sql = "SELECT l.id, l.location_code, l.location_name, w.warehouse_name 
                    FROM {$this->table} l 
                    JOIN warehouses w ON l.warehouse_id = w.id 
                    WHERE l.is_active = 1 
                        AND w.company_id = :company_id
                        AND l.company_id = :company_id
                    ORDER BY w.warehouse_name, l.location_code";

            $stmt = $this->db->prepare($sql);
            $stmt->execute(['company_id' => $this->companyId]);
        }

        $locations = $stmt->fetchAll();

        $options = [];
        foreach ($locations as $loc) {
            $text = $loc['location_code'];
            if (!empty($loc['location_name'])) {
                $text .= ' - ' . $loc['location_name'];
            }
            if (isset($loc['warehouse_name'])) {
                $text = $loc['warehouse_name'] . ': ' . $text;
            }

            $options[] = [
                'value' => $loc['id'],
                'text' => $text,
                'selected' => ($selected == $loc['id'])
            ];
        }

        return $options;
    }

    /**
     * Check if location has stock (with company check)
     */
    public function hasStock($locationId): bool
    {
        if (!$this->companyId) {
            return false;
        }

        // Verify location belongs to company
        $stmt = $this->db->prepare("
            SELECT l.id 
            FROM {$this->table} l
            JOIN warehouses w ON l.warehouse_id = w.id
            WHERE l.id = ? AND w.company_id = ? AND l.company_id = ?
        ");
        $stmt->execute([$locationId, $this->companyId, $this->companyId]);
        if (!$stmt->fetch()) {
            return false;
        }

        $sql = "SELECT COUNT(*) as count FROM inventory WHERE location_id = :location_id AND company_id = :company_id AND quantity > 0";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'location_id' => $locationId,
            'company_id' => $this->companyId
        ]);
        $result = $stmt->fetch();
        return $result['count'] > 0;
    }

    /**
     * Get stock count for a specific location
     * 
     * @param int $locationId The location ID
     * @param int|null $warehouseId Optional warehouse filter
     * @return int Number of items (variants) in this location with positive stock
     */
    public function getStockCount($locationId, $warehouseId = null)
    {
        if (!$this->companyId) {
            return 0;
        }

        $sql = "
            SELECT COUNT(DISTINCT i.variant_id) as stock_count
            FROM inventory i
            WHERE i.location_id = :location_id
                AND i.company_id = :company_id
                AND i.quantity > 0
        ";

        $params = [
            'location_id' => $locationId,
            'company_id' => $this->companyId
        ];

        if ($warehouseId) {
            $sql .= " AND i.warehouse_id = :warehouse_id";
            $params['warehouse_id'] = $warehouseId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();

        return (int) ($result['stock_count'] ?? 0);
    }

    /**
     * Get total quantity of stock in a location
     * 
     * @param int $locationId The location ID
     * @return float Total quantity of items in this location
     */
    public function getTotalStockQuantity($locationId)
    {
        if (!$this->companyId) {
            return 0;
        }

        $sql = "
            SELECT COALESCE(SUM(i.quantity), 0) as total_quantity
            FROM inventory i
            WHERE i.location_id = :location_id
                AND i.company_id = :company_id
                AND i.quantity > 0
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'location_id' => $locationId,
            'company_id' => $this->companyId
        ]);
        $result = $stmt->fetch();

        return (float) ($result['total_quantity'] ?? 0);
    }

    /**
     * Get stock value in a location
     * 
     * @param int $locationId The location ID
     * @return float Total value of stock in this location
     */
    public function getStockValue($locationId)
    {
        if (!$this->companyId) {
            return 0;
        }

        $sql = "
            SELECT COALESCE(SUM(i.quantity * COALESCE(i.avg_landed_cost, v.purchase_price, 0)), 0) as total_value
            FROM inventory i
            JOIN variants v ON i.variant_id = v.id
            WHERE i.location_id = :location_id
                AND i.company_id = :company_id
                AND i.quantity > 0
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'location_id' => $locationId,
            'company_id' => $this->companyId
        ]);
        $result = $stmt->fetch();

        return (float) ($result['total_value'] ?? 0);
    }

    /**
     * Get stock items in a location with details
     * 
     * @param int $locationId The location ID
     * @param int $limit Maximum number of items to return
     * @return array List of stock items with product and variant details
     */
    public function getStockItems($locationId, $limit = 100)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                i.*,
                v.sku,
                v.variant_name,
                p.product_name,
                p.product_code
            FROM inventory i
            JOIN variants v ON i.variant_id = v.id
            JOIN products p ON v.product_id = p.id
            WHERE i.location_id = :location_id
                AND i.company_id = :company_id
                AND i.quantity > 0
            ORDER BY p.product_name, v.variant_name
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':location_id', $locationId, PDO::PARAM_INT);
        $stmt->bindValue(':company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Get location usage statistics
     * 
     * @param int|null $warehouseId Optional warehouse filter
     * @return array Statistics about location usage
     */
    public function getLocationUsageStats($warehouseId = null)
    {
        if (!$this->companyId) {
            return [
                'total_locations' => 0,
                'used_locations' => 0,
                'empty_locations' => 0,
                'utilization_rate' => 0
            ];
        }

        $sql = "
            SELECT 
                COUNT(DISTINCT l.id) as total_locations,
                COUNT(DISTINCT CASE WHEN i.quantity > 0 THEN l.id END) as used_locations,
                COUNT(DISTINCT CASE WHEN i.quantity IS NULL OR i.quantity = 0 THEN l.id END) as empty_locations
            FROM locations l
            LEFT JOIN inventory i ON l.id = i.location_id AND i.company_id = :company_id
            WHERE l.company_id = :company_id
        ";

        $params = ['company_id' => $this->companyId];

        if ($warehouseId) {
            $sql .= " AND l.warehouse_id = :warehouse_id";
            $params['warehouse_id'] = $warehouseId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();

        $total = (int) ($result['total_locations'] ?? 0);
        $used = (int) ($result['used_locations'] ?? 0);

        return [
            'total_locations' => $total,
            'used_locations' => $used,
            'empty_locations' => (int) ($result['empty_locations'] ?? 0),
            'utilization_rate' => $total > 0 ? round(($used / $total) * 100, 2) : 0
        ];
    }

    /**
     * Get locations with stock summary (including stock count and quantity)
     * 
     * @param int|null $warehouseId Optional warehouse filter
     * @return array List of locations with stock information
     */
    public function getLocationsWithStockSummary($warehouseId = null)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                l.*,
                w.warehouse_name,
                COUNT(DISTINCT CASE WHEN i.quantity > 0 THEN i.variant_id END) as stock_count,
                COALESCE(SUM(i.quantity), 0) as total_quantity,
                COALESCE(SUM(i.quantity * COALESCE(i.avg_landed_cost, v.purchase_price, 0)), 0) as total_value
            FROM locations l
            JOIN warehouses w ON l.warehouse_id = w.id AND w.company_id = :company_id
            LEFT JOIN inventory i ON l.id = i.location_id AND i.company_id = :company_id
            LEFT JOIN variants v ON i.variant_id = v.id
            WHERE l.company_id = :company_id
        ";

        $params = ['company_id' => $this->companyId];

        if ($warehouseId) {
            $sql .= " AND l.warehouse_id = :warehouse_id";
            $params['warehouse_id'] = $warehouseId;
        }

        $sql .= " GROUP BY l.id ORDER BY l.location_code";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Get location path (breadcrumb) (with company check)
     */
    public function getPath($locationId)
    {
        if (!$this->companyId) {
            return [];
        }

        $path = [];
        $currentId = $locationId;

        while ($currentId) {
            $sql = "
                SELECT 
                    l.*,
                    w.warehouse_name,
                    w.warehouse_code as parent_code
                FROM {$this->table} l
                JOIN warehouses w ON l.warehouse_id = w.id
                WHERE l.id = :id AND w.company_id = :company_id AND l.company_id = :company_id
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'id' => $currentId,
                'company_id' => $this->companyId
            ]);
            $location = $stmt->fetch();

            if (!$location) {
                break;
            }

            array_unshift($path, $location);
            $currentId = $location['parent_location_id'];
        }

        return $path;
    }

    /**
     * Get location statistics (company-specific)
     */
    public function getStats($warehouseId = null)
    {
        if (!$this->companyId) {
            return [
                'total_locations' => 0,
                'active_locations' => 0,
                'warehouses_using' => 0
            ];
        }

        $sql = "SELECT 
                COUNT(*) as total_locations,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_locations,
                COUNT(DISTINCT warehouse_id) as warehouses_using
            FROM {$this->table} l
            WHERE company_id = :company_id";

        $params = ['company_id' => $this->companyId];

        if ($warehouseId) {
            $sql .= " AND warehouse_id = :warehouse_id";
            $params['warehouse_id'] = $warehouseId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Get locations as tree structure for dropdown (company-specific)
     */
    public function getTreeOptions($warehouseId = null, $selected = null, $prefix = '')
    {
        $tree = $this->getHierarchy($warehouseId);
        $options = [];
        $this->buildTreeOptions($tree, $selected, $options, $prefix);
        return $options;
    }

    /**
     * Helper function to build tree options recursively
     */
    private function buildTreeOptions($locations, $selected, &$options, $prefix = '')
    {
        foreach ($locations as $location) {
            $value = $location['id'];
            $text = $prefix . $location['location_code'];
            if (!empty($location['location_name'])) {
                $text .= ' - ' . $location['location_name'];
            }

            $options[] = [
                'value' => $value,
                'text' => $text,
                'selected' => ($selected == $value)
            ];

            if (!empty($location['children'])) {
                $this->buildTreeOptions($location['children'], $selected, $options, $prefix . '-- ');
            }
        }
    }

    /**
     * Create new location (automatically adds company_id)
     */
    public function create(array $data): int
    {
        // Auto-add company_id if not set
        if ($this->companyId && !isset($data['company_id'])) {
            $data['company_id'] = $this->companyId;
        }

        return parent::create($data);
    }

    /**
     * Find location by ID with company validation
     */
    public function find($id)
    {
        $sql = "SELECT l.*, w.warehouse_name 
                FROM {$this->table} l
                JOIN warehouses w ON l.warehouse_id = w.id
                WHERE l.id = :id";
        $params = ['id' => $id];

        if ($this->companyId) {
            $sql .= " AND l.company_id = :company_id AND w.company_id = :company_id";
            $params['company_id'] = $this->companyId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }
}