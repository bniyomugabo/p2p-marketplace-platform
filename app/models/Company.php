<?php
// models/Company.php
// ============================================
// COMPANY MODEL
// ============================================

require_once __DIR__ . '/BaseModel.php';

class Company extends BaseModel
{
    protected $table = 'companies';
    protected $primaryKey = 'id';
    protected $hasCompanySupport = false; // Companies don't have company_id (they ARE the companies)

    /**
     * Constructor
     */
    public function __construct($companyId = null)
    {
        parent::__construct($companyId);
    }

    /**
     * Get company by ID
     */
    public function getById($id)
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = :id AND is_active = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    /**
     * Get company by code
     */
    public function getByCode($code)
    {
        $sql = "SELECT * FROM {$this->table} WHERE company_code = :code AND is_active = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['code' => $code]);
        return $stmt->fetch();
    }

    /**
     * Get all active companies
     */
    public function getAllActive()
    {
        $sql = "SELECT * FROM {$this->table} WHERE is_active = 1 ORDER BY company_name";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }

    /**
     * Get company statistics
     */
    public function getStats($companyId)
    {
        $sql = "
            SELECT 
                (SELECT COUNT(*) FROM users WHERE company_id = :cid1 AND is_deleted = 0) as user_count,
                (SELECT COUNT(*) FROM users WHERE company_id = :cid2 AND is_active = 1 AND is_deleted = 0) as active_users,
                (SELECT COUNT(*) FROM customers WHERE company_id = :cid3) as customer_count,
                (SELECT COUNT(*) FROM products WHERE company_id = :cid4 AND is_active = 1) as product_count,
                (SELECT COUNT(*) FROM suppliers WHERE company_id = :cid5 AND is_active = 1) as supplier_count,
                (SELECT COUNT(*) FROM warehouses WHERE company_id = :cid6 AND is_active = 1) as warehouse_count
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'cid1' => $companyId,
            'cid2' => $companyId,
            'cid3' => $companyId,
            'cid4' => $companyId,
            'cid5' => $companyId,
            'cid6' => $companyId
        ]);

        return $stmt->fetch();
    }

    /**
     * Get company subscription info
     */
    public function getSubscriptionInfo($companyId)
    {
        $sql = "SELECT subscription_plan, subscription_start, subscription_end, max_users, max_storage_mb 
                FROM {$this->table} WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $companyId]);
        return $stmt->fetch();
    }

    /**
     * Check if company can add more users
     */
    public function canAddUser($companyId)
    {
        $company = $this->getById($companyId);
        if (!$company)
            return false;

        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM users WHERE company_id = ? AND is_deleted = 0");
        $stmt->execute([$companyId]);
        $userCount = $stmt->fetch()['count'];

        return $userCount < $company['max_users'];
    }

    /**
     * Get companies with user count
     */
    public function getWithUserCount()
    {
        $sql = "
            SELECT 
                c.*,
                COUNT(u.id) as user_count,
                SUM(CASE WHEN u.is_active = 1 THEN 1 ELSE 0 END) as active_user_count
            FROM {$this->table} c
            LEFT JOIN users u ON c.id = u.company_id AND u.is_deleted = 0
            WHERE c.is_active = 1
            GROUP BY c.id
            ORDER BY c.company_name
        ";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }

    /**
     * Create new company
     */
    public function createCompany($data)
    {
        // Generate company code if not provided
        if (empty($data['company_code'])) {
            $data['company_code'] = $this->generateCode();
        }

        // Set default values
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['is_active'] = $data['is_active'] ?? 1;
        $data['subscription_plan'] = $data['subscription_plan'] ?? 'free';
        $data['max_users'] = $data['max_users'] ?? 5;
        $data['max_storage_mb'] = $data['max_storage_mb'] ?? 100;

        return $this->create($data);
    }

    /**
     * Generate unique company code
     */
    public function generateCode()
    {
        $prefix = 'CMP';
        $year = date('y');

        $sql = "SELECT COUNT(*) as count FROM {$this->table} WHERE company_code LIKE '{$prefix}{$year}%'";
        $stmt = $this->db->query($sql);
        $count = $stmt->fetch()['count'] + 1;

        return $prefix . $year . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Update company subscription
     */
    public function updateSubscription($companyId, $plan, $endDate = null)
    {
        $data = [
            'subscription_plan' => $plan,
            'subscription_end' => $endDate
        ];

        // Set limits based on plan
        switch ($plan) {
            case 'basic':
                $data['max_users'] = 10;
                $data['max_storage_mb'] = 500;
                break;
            case 'professional':
                $data['max_users'] = 25;
                $data['max_storage_mb'] = 2000;
                break;
            case 'enterprise':
                $data['max_users'] = 100;
                $data['max_storage_mb'] = 10000;
                break;
            default: // free
                $data['max_users'] = 5;
                $data['max_storage_mb'] = 100;
        }

        return $this->update($companyId, $data);
    }

    /**
     * Get company options for dropdown
     */
    public function getOptions($selected = null)
    {
        $sql = "SELECT id, company_code, company_name FROM {$this->table} WHERE is_active = 1 ORDER BY company_name";
        $stmt = $this->db->query($sql);
        $companies = $stmt->fetchAll();

        $options = [];
        foreach ($companies as $company) {
            $options[] = [
                'value' => $company['id'],
                'text' => $company['company_name'] . ' (' . $company['company_code'] . ')',
                'selected' => ($selected == $company['id'])
            ];
        }
        return $options;
    }
}