<?php
// models/Customer.php
// ============================================
// CUSTOMER MODEL WITH COMPANY SUPPORT
// ============================================

require_once __DIR__ . '/BaseModel.php';

class Customer extends BaseModel
{
    protected $table = 'customers';
    protected $hasCompanySupport = true;

    /**
     * Constructor - can pass company ID or use session
     */
    public function __construct($companyId = null)
    {
        parent::__construct($companyId);
    }

    /**
     * Generate unique customer code (company-specific)
     */
    public function generateCode()
    {
        if (!$this->companyId) {
            return 'CUST-' . date('Y') . '-' . str_pad(1, 4, '0', STR_PAD_LEFT);
        }

        $prefix = 'CUST';
        $year = date('Y');

        $sql = "SELECT COUNT(*) as count FROM {$this->table} 
                WHERE customer_code LIKE :pattern AND company_id = :company_id";

        $pattern = $prefix . '-' . $year . '%';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'pattern' => $pattern,
            'company_id' => $this->companyId
        ]);
        $count = $stmt->fetch()['count'] + 1;

        return $prefix . '-' . $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Get customer statistics (company-specific)
     */
    public function getStats()
    {
        if (!$this->companyId) {
            return [
                'total_customers' => 0,
                'active_customers' => 0,
                'inactive_customers' => 0,
                'new_customers' => 0,
                'individual_count' => 0,
                'company_count' => 0,
                'total_balance' => 0,
                'avg_balance' => 0
            ];
        }

        $sql = "
            SELECT 
                COUNT(*) as total_customers,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_customers,
                SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_customers,
                COUNT(DISTINCT CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN id END) as new_customers,
                SUM(CASE WHEN customer_type = 'individual' THEN 1 ELSE 0 END) as individual_count,
                SUM(CASE WHEN customer_type = 'company' THEN 1 ELSE 0 END) as company_count,
                COALESCE(SUM(current_balance), 0) as total_balance,
                COALESCE(AVG(current_balance), 0) as avg_balance
            FROM {$this->table}
            WHERE company_id = :company_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        $result = $stmt->fetch();

        return $result ?: [
            'total_customers' => 0,
            'active_customers' => 0,
            'inactive_customers' => 0,
            'new_customers' => 0,
            'individual_count' => 0,
            'company_count' => 0,
            'total_balance' => 0,
            'avg_balance' => 0
        ];
    }

    /**
     * Get customer summary (alias for getStats)
     */
    public function getSummary()
    {
        return $this->getStats();
    }

    /**
     * Get recent customers (company-specific)
     */
    public function getRecent($limit = 5)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT * FROM {$this->table} 
            WHERE company_id = :company_id AND is_active = 1 
            ORDER BY created_at DESC 
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get customer with sales summary (with company check)
     */
    public function getWithSummary($id)
    {
        if (!$this->companyId) {
            return null;
        }

        $sql = "
            SELECT 
                c.*,
                COUNT(DISTINCT si.id) as total_invoices,
                COALESCE(SUM(si.total_amount), 0) as total_purchases,
                COALESCE(SUM(si.amount_paid), 0) as total_paid,
                COALESCE(SUM(CASE WHEN si.status IN ('issued', 'partial', 'overdue') THEN (si.total_amount - si.amount_paid) ELSE 0 END), 0) as outstanding_balance,
                MAX(si.invoice_date) as last_purchase_date,
                MIN(si.invoice_date) as first_purchase_date,
                COALESCE(AVG(si.total_amount), 0) as avg_order_value
            FROM {$this->table} c
            LEFT JOIN sales_invoices si ON c.id = si.customer_id AND si.status != 'cancelled' AND si.company_id = :p_1_company_id
            WHERE c.id = :id AND c.company_id = :p_2_company_id
            GROUP BY c.id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'p_1_company_id' => $this->companyId,
            'p_2_company_id' => $this->companyId
        ]);
        return $stmt->fetch();
    }

    /**
     * Update customer balance (with company check)
     */
    public function updateBalance($customerId, $amount)
    {
        if (!$this->companyId) {
            throw new Exception('Company context required');
        }

        // First verify customer belongs to this company
        $stmt = $this->db->prepare("SELECT company_id FROM {$this->table} WHERE id = ?");
        $stmt->execute([$customerId]);
        $customer = $stmt->fetch();

        if (!$customer || $customer['company_id'] != $this->companyId) {
            throw new Exception("Customer does not belong to this company");
        }

        $sql = "UPDATE {$this->table} SET current_balance = current_balance + :amount WHERE id = :id AND company_id = :company_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'id' => $customerId,
            'amount' => $amount,
            'company_id' => $this->companyId
        ]);
    }

    /**
     * Search customers (company-specific)
     */
    public function search($term)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT * FROM {$this->table} 
            WHERE (full_name LIKE :term OR phone LIKE :term OR email LIKE :term OR customer_code LIKE :term) 
                AND company_id = :company_id
                AND is_active = 1 
            ORDER BY full_name 
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
     * Get customers by type (company-specific)
     */
    public function getByType($type)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "SELECT * FROM {$this->table} 
                WHERE customer_type = :type 
                    AND company_id = :company_id 
                    AND is_active = 1 
                ORDER BY full_name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'type' => $type,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Get top customers by purchase value (company-specific)
     */
    public function getTopCustomers($limit = 10, $startDate = null, $endDate = null)
    {
        if (!$this->companyId) {
            return [];
        }

        if (!$startDate) {
            $startDate = date('Y-m-d', strtotime('-1 year'));
        }
        if (!$endDate) {
            $endDate = date('Y-m-d');
        }

        $sql = "
            SELECT 
                c.*,
                COUNT(DISTINCT si.id) as invoice_count,
                COALESCE(SUM(si.total_amount), 0) as total_purchases,
                COALESCE(SUM(si.amount_paid), 0) as total_paid,
                MAX(si.invoice_date) as last_purchase_date
            FROM {$this->table} c
            JOIN sales_invoices si ON c.id = si.customer_id AND si.company_id = :p_1_company_id
            WHERE DATE(si.invoice_date) BETWEEN :start_date AND :end_date
                AND si.status != 'cancelled'
                AND c.is_active = 1
                AND c.company_id = :p_2_company_id
            GROUP BY c.id 
            ORDER BY total_purchases DESC 
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':start_date', $startDate);
        $stmt->bindValue(':end_date', $endDate);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':p_1_company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':p_2_company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get customer statistics for a date range (company-specific)
     */
    public function getCustomerStats($startDate, $endDate)
    {
        if (!$this->companyId) {
            return [
                'total_customers' => 0,
                'active_customers' => 0,
                'total_invoices' => 0,
                'total_sales' => 0,
                'avg_sale_per_customer' => 0,
                'new_customers_30d' => 0
            ];
        }

        $sql = "
            SELECT 
                COUNT(DISTINCT c.id) as total_customers,
                COUNT(DISTINCT CASE WHEN si.id IS NOT NULL THEN c.id END) as active_customers,
                COUNT(DISTINCT si.id) as total_invoices,
                COALESCE(SUM(si.total_amount), 0) as total_sales,
                COALESCE(AVG(si.total_amount), 0) as avg_sale_per_customer,
                COUNT(DISTINCT CASE WHEN si.invoice_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN c.id END) as new_customers_30d
            FROM {$this->table} c
            LEFT JOIN sales_invoices si ON c.id = si.customer_id 
                AND DATE(si.invoice_date) BETWEEN :start_date AND :end_date
                AND si.status != 'cancelled'
                AND si.company_id = :company_id
            WHERE c.is_active = 1 AND c.company_id = :company_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetch();
    }

    /**
     * Get customer retention stats (company-specific)
     */
    public function getRetentionStats()
    {
        if (!$this->companyId) {
            return [
                'total_customers' => 0,
                'active_last_30d' => 0,
                'active_30_60d' => 0,
                'active_60_90d' => 0,
                'inactive_90d_plus' => 0
            ];
        }

        $sql = "
            SELECT 
                COUNT(DISTINCT c.id) as total_customers,
                COUNT(DISTINCT CASE WHEN si.invoice_date >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN c.id END) as active_last_30d,
                COUNT(DISTINCT CASE WHEN si.invoice_date BETWEEN DATE_SUB(NOW(), INTERVAL 60 DAY) AND DATE_SUB(NOW(), INTERVAL 31 DAY) THEN c.id END) as active_30_60d,
                COUNT(DISTINCT CASE WHEN si.invoice_date BETWEEN DATE_SUB(NOW(), INTERVAL 90 DAY) AND DATE_SUB(NOW(), INTERVAL 61 DAY) THEN c.id END) as active_60_90d,
                COUNT(DISTINCT CASE WHEN si.invoice_date < DATE_SUB(NOW(), INTERVAL 90 DAY) THEN c.id END) as inactive_90d_plus
            FROM {$this->table} c
            LEFT JOIN sales_invoices si ON c.id = si.customer_id AND si.status != 'cancelled' AND si.company_id = :company_id
            WHERE c.is_active = 1 AND c.company_id = :company_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        return $stmt->fetch();
    }

    /**
     * Get customer by email (company-specific)
     */
    public function findByEmail($email)
    {
        if (!$this->companyId) {
            return null;
        }

        $sql = "SELECT * FROM {$this->table} 
                WHERE email = :email AND company_id = :company_id 
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'email' => $email,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetch();
    }

    /**
     * Get customer by phone (company-specific)
     */
    public function findByPhone($phone)
    {
        if (!$this->companyId) {
            return null;
        }

        $sql = "SELECT * FROM {$this->table} 
                WHERE phone = :phone AND company_id = :company_id 
                LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'phone' => $phone,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetch();
    }

    /**
     * Get customer options for dropdown (company-specific)
     */
    public function getOptions($selected = null)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "SELECT id, full_name, customer_code, phone 
                FROM {$this->table} 
                WHERE is_active = 1 AND company_id = :company_id 
                ORDER BY full_name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        $customers = $stmt->fetchAll();

        $options = [];
        foreach ($customers as $customer) {
            $text = $customer['full_name'];
            if ($customer['customer_code']) {
                $text .= ' (' . $customer['customer_code'] . ')';
            }
            if ($customer['phone']) {
                $text .= ' - ' . $customer['phone'];
            }
            $options[] = [
                'value' => $customer['id'],
                'text' => $text,
                'selected' => ($selected == $customer['id'])
            ];
        }

        return $options;
    }

    /**
     * Create new customer (automatically adds company_id)
     */
    public function create(array $data): int
    {
        // Auto-add company_id if not set
        if ($this->companyId && !isset($data['company_id'])) {
            $data['company_id'] = $this->companyId;
        }

        // Generate customer code if not provided
        if (empty($data['customer_code'])) {
            $data['customer_code'] = $this->generateCode();
        }

        // Set created_at if not provided
        if (!isset($data['created_at'])) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }

        return parent::create($data);
    }

    /**
     * Update customer (with company validation)
     */
    public function update($id, array $data): bool
    {
        // Verify customer belongs to this company
        $customer = $this->find($id);
        if (!$customer) {
            throw new Exception('Customer not found or not accessible for this company');
        }

        // Add updated_at timestamp
        $data['updated_at'] = date('Y-m-d H:i:s');

        return parent::update($id, $data);
    }

    /**
     * Get all customers for this company
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
     * Find customer by ID with company validation
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
     * Delete customer (with company validation)
     */
    public function delete($id, $softDelete = true): bool
    {
        // Verify customer belongs to this company
        $customer = $this->find($id);
        if (!$customer) {
            throw new Exception('Customer not found or not accessible for this company');
        }

        // Check if customer has invoices
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count FROM sales_invoices 
            WHERE customer_id = :customer_id AND company_id = :company_id
        ");
        $stmt->execute([
            'customer_id' => $id,
            'company_id' => $this->companyId
        ]);
        $result = $stmt->fetch();

        if ($result['count'] > 0 && $softDelete === false) {
            throw new Exception('Cannot delete customer with existing sales records. Use soft delete or archive instead.');
        }

        return parent::delete($id, $softDelete);
    }

    /**
     * Get customers with outstanding balance (company-specific)
     */
    public function getWithOutstandingBalance($minBalance = 0)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT * FROM {$this->table} 
            WHERE current_balance > :min_balance 
                AND is_active = 1 
                AND company_id = :company_id 
            ORDER BY current_balance DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'min_balance' => $minBalance,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Get customer lifetime value (company-specific)
     */
    public function getCustomerLifetimeValue($customerId)
    {
        if (!$this->companyId) {
            return 0;
        }

        $sql = "
            SELECT 
                COALESCE(SUM(si.total_amount), 0) as lifetime_value,
                COUNT(si.id) as total_orders,
                DATEDIFF(MAX(si.invoice_date), MIN(si.invoice_date)) as customer_lifetime_days
            FROM sales_invoices si
            WHERE si.customer_id = :customer_id
                AND si.status != 'cancelled'
                AND si.company_id = :company_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'customer_id' => $customerId,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetch();
    }
}