<?php
// models/Supplier.php
// ============================================
// SUPPLIER MODEL WITH COMPANY SUPPORT
// ============================================

require_once __DIR__ . '/BaseModel.php';

class Supplier extends BaseModel
{
    protected $table = 'suppliers';
    protected $primaryKey = 'id';

    /**
     * Constructor - can pass company ID or use session
     */
    public function __construct($companyId = null)
    {
        parent::__construct($companyId);
    }

    /**
     * Validate that company context is set for sensitive operations
     */
    protected function validateCompanyContext()
    {
        if (!$this->companyId) {
            throw new Exception('Company context is required for this operation');
        }
        return true;
    }

    /**
     * Verify that a supplier belongs to the current company
     */
    protected function verifyCompanyOwnership($supplierId)
    {
        if (!$this->companyId) {
            return false;
        }

        $stmt = $this->db->prepare("SELECT company_id FROM {$this->table} WHERE id = ?");
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch();

        if (!$supplier || $supplier['company_id'] != $this->companyId) {
            throw new Exception('Supplier does not belong to this company');
        }

        return true;
    }

    /**
     * Generate unique supplier code (company-specific)
     */
    public function generateCode()
    {
        $this->validateCompanyContext();

        $prefix = 'SUP';
        $year = date('Y');

        // Add company prefix
        $companyPrefix = 'C' . $this->companyId . '-';

        $sql = "SELECT COUNT(*) as count FROM {$this->table} 
                WHERE supplier_code LIKE :pattern AND company_id = :company_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'pattern' => $companyPrefix . $prefix . '-' . $year . '%',
            'company_id' => $this->companyId
        ]);
        $count = $stmt->fetch()['count'] + 1;

        return $companyPrefix . $prefix . '-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Find supplier by code (company-specific)
     */
    public function findByCode($code)
    {
        $this->validateCompanyContext();

        $sql = "SELECT * FROM {$this->table} 
                WHERE supplier_code = :code AND company_id = :company_id";

        $params = [
            'code' => $code,
            'company_id' => $this->companyId
        ];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Get supplier with purchase summary (with company check)
     */
    public function getWithSummary($id)
    {
        $this->validateCompanyContext();

        $sql = "
            SELECT 
                s.*,
                COUNT(DISTINCT po.id) as total_orders,
                COALESCE(SUM(po.total_amount), 0) as total_purchases,
                MAX(po.order_date) as last_order_date,
                COALESCE(AVG(po.total_amount), 0) as avg_order_value
            FROM {$this->table} s
            LEFT JOIN purchase_orders po ON s.id = po.supplier_id 
                AND po.status != 'cancelled' 
                AND po.company_id = :p_1_company_id
            WHERE s.id = :id AND s.company_id = :p_2_company_id
            GROUP BY s.id
        ";

        $params = [
            'id' => $id,
            'p_1_company_id' => $this->companyId,
            'p_2_company_id' => $this->companyId
        ];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Search suppliers (company-specific)
     */
    public function search($term)
    {
        $this->validateCompanyContext();

        $sql = "
            SELECT * FROM {$this->table} 
            WHERE (supplier_name LIKE :term 
                OR supplier_code LIKE :term 
                OR contact_person LIKE :term 
                OR email LIKE :term 
                OR phone LIKE :term)
            AND is_active = 1
            AND company_id = :company_id
            ORDER BY supplier_name
            LIMIT 20
        ";

        $params = [
            'term' => "%{$term}%",
            'company_id' => $this->companyId
        ];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get active suppliers for dropdown (company-specific)
     */
    public function getOptions($selected = null)
    {
        $this->validateCompanyContext();

        $sql = "SELECT id, supplier_code, supplier_name 
                FROM {$this->table} 
                WHERE is_active = 1 
                AND company_id = :company_id
                ORDER BY supplier_name";

        $params = ['company_id' => $this->companyId];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $suppliers = $stmt->fetchAll();

        $options = [];
        foreach ($suppliers as $sup) {
            $options[] = [
                'value' => $sup['id'],
                'text' => $sup['supplier_name'] . ' (' . $sup['supplier_code'] . ')',
                'selected' => ($selected == $sup['id'])
            ];
        }
        return $options;
    }

    /**
     * Get supplier statistics (company-specific)
     */
    public function getStats()
    {
        $this->validateCompanyContext();

        $sql = "
            SELECT 
                COUNT(*) as total_suppliers,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_suppliers,
                COUNT(DISTINCT CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN id END) as new_suppliers
            FROM {$this->table}
            WHERE company_id = :company_id
        ";

        $params = ['company_id' => $this->companyId];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Update supplier balance (with company check)
     */
    public function updateBalance($supplierId, $amount)
    {
        $this->validateCompanyContext();
        $this->verifyCompanyOwnership($supplierId);

        $sql = "UPDATE {$this->table} SET balance = balance + :amount WHERE id = :id AND company_id = :company_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'id' => $supplierId,
            'amount' => $amount,
            'company_id' => $this->companyId
        ]);
    }

    /**
     * Create new supplier (automatically adds company_id)
     */
    public function create(array $data): int
    {
        $this->validateCompanyContext();

        // Auto-add company_id if not set
        if (!isset($data['company_id'])) {
            $data['company_id'] = $this->companyId;
        }

        // Generate supplier code if not provided
        if (empty($data['supplier_code'])) {
            $data['supplier_code'] = $this->generateCode();
        }

        return parent::create($data);
    }

    /**
     * Update supplier (with company check)
     */
    public function update($id, array $data): bool
    {
        $this->validateCompanyContext();
        $this->verifyCompanyOwnership($id);
        return parent::update($id, $data);
    }

    /**
     * Delete supplier (soft delete) with company check
     */
    public function delete($id, $softDelete = true): bool
    {
        $this->validateCompanyContext();
        $this->verifyCompanyOwnership($id);
        return parent::delete($id, $softDelete);
    }

    /**
     * Get all suppliers with purchase stats (company-specific)
     */
    public function getAllWithStats()
    {
        $this->validateCompanyContext();

        $sql = "
            SELECT 
                s.*,
                COUNT(DISTINCT po.id) as total_orders,
                COALESCE(SUM(po.total_amount), 0) as total_purchases,
                MAX(po.order_date) as last_order_date
            FROM {$this->table} s
            LEFT JOIN purchase_orders po ON s.id = po.supplier_id 
                AND po.status != 'cancelled'
                AND po.company_id = :company_id
            WHERE s.company_id = :company_id
            GROUP BY s.id
            ORDER BY s.supplier_name
        ";

        $params = ['company_id' => $this->companyId];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get top suppliers by purchase amount (company-specific)
     */
    public function getTopSuppliers($limit = 10)
    {
        $this->validateCompanyContext();

        $sql = "
            SELECT 
                s.id,
                s.supplier_name,
                s.supplier_code,
                COUNT(DISTINCT po.id) as order_count,
                COALESCE(SUM(po.total_amount), 0) as total_purchases,
                COALESCE(AVG(po.total_amount), 0) as avg_order_value
            FROM {$this->table} s
            INNER JOIN purchase_orders po ON s.id = po.supplier_id
            WHERE po.status NOT IN ('cancelled', 'draft')
            AND s.company_id = :company_id
            AND po.company_id = :company_id
            GROUP BY s.id
            ORDER BY total_purchases DESC
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get inactive suppliers (company-specific)
     */
    public function getInactive()
    {
        $this->validateCompanyContext();

        $sql = "SELECT * FROM {$this->table} 
                WHERE is_active = 0 
                AND company_id = :company_id
                ORDER BY supplier_name";

        $params = ['company_id' => $this->companyId];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Check if supplier exists with given name (company-specific)
     */
    public function existsByName($name, $excludeId = null)
    {
        $this->validateCompanyContext();

        $sql = "SELECT COUNT(*) as count FROM {$this->table} 
                WHERE supplier_name = :name 
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

    /**
     * Toggle supplier active status (company-specific)
     */
    public function toggleActive($id)
    {
        $this->validateCompanyContext();
        $this->verifyCompanyOwnership($id);

        $sql = "UPDATE {$this->table} 
                SET is_active = NOT is_active 
                WHERE id = :id AND company_id = :company_id";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'id' => $id,
            'company_id' => $this->companyId
        ]);
    }
}