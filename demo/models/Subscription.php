<?php
// models/Subscription.php
// ============================================
// SUBSCRIPTION MODEL WITH "MORE YOU SELL, LESS YOU PAY"
// ============================================
require_once __DIR__ . '/BaseModel.php';

class Subscription extends BaseModel
{
    protected $table = 'companies';
    protected $primaryKey = 'id';

    // Plan definitions with pricing (monthly)
    private $plans = [
        'trial' => [
            'name' => 'Trial',
            'price' => 0,
            'currency' => 'EUR',
            'features' => [
                'max_users' => 2,
                'max_products' => 50,
                'max_invoices' => 100,
                'reports' => false,
                'api_access' => false,
                'support' => 'email'
            ],
            'price_tiers' => [
                ['min_sales' => 0, 'price' => 0]
            ]
        ],
        'free' => [
            'name' => 'Free',
            'price' => 0,
            'currency' => 'EUR',
            'features' => [
                'max_users' => 1,
                'max_products' => 20,
                'max_invoices' => 100,
                'reports' => false,
                'api_access' => false,
                'support' => 'community'
            ],
            'price_tiers' => [
                ['min_sales' => 0, 'price' => 0]
            ]
        ],
        'basic' => [
            'name' => 'Basic',
            'price' => 3.99,
            'currency' => 'EUR',
            'features' => [
                'max_users' => 3,
                'max_products' => 200,
                'max_invoices' => 500,
                'reports' => true,
                'api_access' => false,
                'support' => 'email'
            ],
            'price_tiers' => [
                ['min_sales' => 0, 'price' => 3.99],
                ['min_sales' => 100, 'price' => 3.49],
                ['min_sales' => 250, 'price' => 2.99],
                ['min_sales' => 500, 'price' => 2.49],
                ['min_sales' => 1000, 'price' => 1.99]
            ]
        ],
        'professional' => [
            'name' => 'Professional',
            'price' => 9.99,
            'currency' => 'EUR',
            'features' => [
                'max_users' => 10,
                'max_products' => 1000,
                'max_invoices' => 2000,
                'reports' => true,
                'api_access' => true,
                'support' => 'priority'
            ],
            'price_tiers' => [
                ['min_sales' => 0, 'price' => 9.99],
                ['min_sales' => 100, 'price' => 8.99],
                ['min_sales' => 250, 'price' => 7.99],
                ['min_sales' => 500, 'price' => 6.99],
                ['min_sales' => 1000, 'price' => 5.99]
            ]
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'price' => 29.99,
            'currency' => 'EUR',
            'features' => [
                'max_users' => 50,
                'max_products' => 5000,
                'max_invoices' => 10000,
                'reports' => true,
                'api_access' => true,
                'support' => 'dedicated'
            ],
            'price_tiers' => [
                ['min_sales' => 0, 'price' => 29.99],
                ['min_sales' => 100, 'price' => 26.99],
                ['min_sales' => 250, 'price' => 23.99],
                ['min_sales' => 500, 'price' => 19.99],
                ['min_sales' => 1000, 'price' => 15.99]
            ]
        ]
    ];

    /**
     * Get company subscription details
     */
    public function getCompanySubscription($companyId)
    {
        $sql = "
            SELECT 
                c.*,
                COUNT(DISTINCT u.id) as current_users,
                COUNT(DISTINCT p.id) as total_products,
                COUNT(DISTINCT si.id) as total_sales,
                COALESCE(SUM(si.total_amount), 0) as total_revenue
            FROM companies c
            LEFT JOIN users u ON c.id = u.company_id AND u.is_deleted = 0
            LEFT JOIN products p ON c.id = p.company_id AND p.is_active = 1
            LEFT JOIN sales_invoices si ON c.id = si.company_id 
                AND si.status != 'cancelled'
                AND MONTH(si.invoice_date) = MONTH(CURDATE())
                AND YEAR(si.invoice_date) = YEAR(CURDATE())
            WHERE c.id = :company_id
            GROUP BY c.id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $companyId]);
        $company = $stmt->fetch();

        if ($company) {
            $company['plan_details'] = $this->getPlanDetails($company['subscription_plan']);
            $company['current_price'] = $this->calculateCurrentPrice(
                $company['subscription_plan'],
                $company['total_sales'] ?? 0
            );
            $company['next_tier'] = $this->getNextPriceTier(
                $company['subscription_plan'],
                $company['total_sales'] ?? 0
            );
        }

        return $company;
    }

    /**
     * Get plan details
     */
    public function getPlanDetails($planCode)
    {
        return $this->plans[$planCode] ?? null;
    }

    /**
     * Get all plans with pricing
     */
    public function getAllPlans($includeTrial = true)
    {
        $plans = $this->plans;
        if (!$includeTrial) {
            unset($plans['trial']);
        }
        return $plans;
    }

    /**
     * Calculate current price based on sales volume
     */
    public function calculateCurrentPrice($planCode, $monthlySales)
    {
        if (!isset($this->plans[$planCode])) {
            return 0;
        }

        $plan = $this->plans[$planCode];
        $tiers = $plan['price_tiers'];

        // Find applicable tier
        $applicablePrice = $plan['price'];
        foreach ($tiers as $tier) {
            if ($monthlySales >= $tier['min_sales']) {
                $applicablePrice = $tier['price'];
            } else {
                break;
            }
        }

        return $applicablePrice;
    }

    /**
     * Get next price tier
     */
    public function getNextPriceTier($planCode, $currentSales)
    {
        if (!isset($this->plans[$planCode])) {
            return null;
        }

        $plan = $this->plans[$planCode];
        $tiers = $plan['price_tiers'];

        foreach ($tiers as $index => $tier) {
            if ($tier['min_sales'] > $currentSales) {
                return [
                    'sales_needed' => $tier['min_sales'] - $currentSales,
                    'new_price' => $tier['price'],
                    'savings' => $plan['price'] - $tier['price']
                ];
            }
        }

        return null; // Already at best price
    }

    /**
     * Initialize trial for new company
     */
    public function startTrial($companyId)
    {
        $trialStart = date('Y-m-d');
        $trialEnd = date('Y-m-d', strtotime('+3 months'));

        $sql = "
            UPDATE companies 
            SET subscription_plan = 'trial',
                trial_start = :trial_start,
                trial_end = :trial_end,
                subscription_start = :trial_start,
                subscription_end = :trial_end,
                monthly_sales_count = 0,
                monthly_sales_reset = DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
            WHERE id = :company_id
        ";

        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute([
            'company_id' => $companyId,
            'trial_start' => $trialStart,
            'trial_end' => $trialEnd
        ]);

        if ($result) {
            $this->logSubscriptionChange($companyId, null, 'trial', 'Trial started');
        }

        return $result;
    }

    /**
     * Change subscription plan
     */
    public function changePlan($companyId, $newPlan, $reason = null, $changedBy = null)
    {
        // Get current plan
        $stmt = $this->db->prepare("SELECT subscription_plan FROM companies WHERE id = ?");
        $stmt->execute([$companyId]);
        $currentPlan = $stmt->fetchColumn();

        // Calculate new dates
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime('+1 month'));

        $sql = "
            UPDATE companies 
            SET subscription_plan = :new_plan,
                subscription_start = :start_date,
                subscription_end = :end_date,
                monthly_sales_reset = DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
            WHERE id = :company_id
        ";

        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute([
            'company_id' => $companyId,
            'new_plan' => $newPlan,
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);

        if ($result) {
            $this->logSubscriptionChange(
                $companyId,
                $currentPlan,
                $newPlan,
                $reason,
                $changedBy
            );
        }

        return $result;
    }

    /**
     * Increment monthly sales count
     */
    public function incrementSalesCount($companyId, $amount = 1)
    {
        // Check if need to reset monthly count
        $stmt = $this->db->prepare("
            SELECT monthly_sales_reset FROM companies 
            WHERE id = ? AND (monthly_sales_reset IS NULL OR monthly_sales_reset <= CURDATE())
        ");
        $stmt->execute([$companyId]);
        $needsReset = $stmt->fetch();

        if ($needsReset) {
            // Reset monthly count
            $sql = "
                UPDATE companies 
                SET monthly_sales_count = :amount,
                    monthly_sales_reset = DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
                WHERE id = :company_id
            ";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                'company_id' => $companyId,
                'amount' => $amount
            ]);
        } else {
            // Increment existing count
            $sql = "
                UPDATE companies 
                SET monthly_sales_count = monthly_sales_count + :amount
                WHERE id = :company_id
            ";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                'company_id' => $companyId,
                'amount' => $amount
            ]);
        }
    }

    /**
     * Check if company can create more invoices
     */
    public function canCreateInvoice($companyId)
    {
        $company = $this->getCompanySubscription($companyId);
        if (!$company)
            return false;

        $plan = $this->getPlanDetails($company['subscription_plan']);
        if (!$plan)
            return false;

        // Trial has limit of 100 invoices
        if ($company['subscription_plan'] === 'trial') {
            return ($company['total_sales'] ?? 0) < 100;
        }

        // Free plan has limit of 100 invoices
        if ($company['subscription_plan'] === 'free') {
            return ($company['total_sales'] ?? 0) < 100;
        }

        // Other plans have higher limits
        return ($company['total_sales'] ?? 0) < $plan['features']['max_invoices'];
    }

    /**
     * Check if company can add more users
     */
    public function canAddUser($companyId)
    {
        $company = $this->getCompanySubscription($companyId);
        if (!$company)
            return false;

        $plan = $this->getPlanDetails($company['subscription_plan']);
        if (!$plan)
            return false;

        return ($company['current_users'] ?? 0) < $plan['features']['max_users'];
    }

    /**
     * Check if company can add more products
     */
    public function canAddProduct($companyId)
    {
        $company = $this->getCompanySubscription($companyId);
        if (!$company)
            return false;

        $plan = $this->getPlanDetails($company['subscription_plan']);
        if (!$plan)
            return false;

        return ($company['total_products'] ?? 0) < $plan['features']['max_products'];
    }

    /**
     * Generate invoice for subscription
     */
    public function generateInvoice($companyId)
    {
        $company = $this->getCompanySubscription($companyId);
        if (!$company)
            return false;

        $price = $this->calculateCurrentPrice(
            $company['subscription_plan'],
            $company['total_sales'] ?? 0
        );

        if ($price <= 0)
            return true; // No invoice needed for free plans

        // Generate invoice number
        $prefix = 'SUB';
        $year = date('Y');
        $month = date('m');
        $invoiceNumber = $prefix . '-' . $year . $month . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

        $periodStart = date('Y-m-d');
        $periodEnd = date('Y-m-d', strtotime('+1 month'));
        $dueDate = date('Y-m-d', strtotime('+7 days'));

        $sql = "
            INSERT INTO subscription_invoices (
                company_id, invoice_number, plan, amount, currency,
                billing_period_start, billing_period_end, due_date, status, created_at
            ) VALUES (
                :company_id, :invoice_number, :plan, :amount, :currency,
                :period_start, :period_end, :due_date, 'pending', NOW()
            )
        ";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'company_id' => $companyId,
            'invoice_number' => $invoiceNumber,
            'plan' => $company['subscription_plan'],
            'amount' => $price,
            'currency' => $company['currency'] ?? 'EUR',
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'due_date' => $dueDate
        ]);
    }

    /**
     * Process subscription renewals (cron job)
     */
    public function processRenewals()
    {
        // Find companies whose subscription ends today
        $sql = "
            SELECT * FROM companies 
            WHERE subscription_end <= CURDATE()
            AND subscription_plan NOT IN ('trial', 'free')
        ";
        $stmt = $this->db->query($sql);
        $companies = $stmt->fetchAll();

        foreach ($companies as $company) {
            // Generate new invoice
            $this->generateInvoice($company['id']);

            // Extend subscription
            $sql = "UPDATE companies SET subscription_end = DATE_ADD(subscription_end, INTERVAL 1 MONTH) WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$company['id']]);
        }

        // Handle expired trials
        $sql = "
            UPDATE companies 
            SET subscription_plan = 'free'
            WHERE subscription_plan = 'trial' AND trial_end < CURDATE()
        ";
        $this->db->query($sql);
    }

    /**
     * Log subscription changes
     */
    private function logSubscriptionChange($companyId, $oldPlan, $newPlan, $reason = null, $changedBy = null)
    {
        $sql = "
            INSERT INTO subscription_history (
                company_id, old_plan, new_plan, reason, changed_by, created_at
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$companyId, $oldPlan, $newPlan, $reason, $changedBy]);
    }

    /**
     * Get subscription history
     */
    public function getHistory($companyId)
    {
        $sql = "
            SELECT sh.*, u.full_name as changed_by_name
            FROM subscription_history sh
            LEFT JOIN users u ON sh.changed_by = u.id
            WHERE sh.company_id = :company_id
            ORDER BY sh.created_at DESC
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $companyId]);
        return $stmt->fetchAll();
    }

    /**
     * Get subscription usage stats
     */
    public function getUsageStats($companyId)
    {
        $sql = "
            SELECT 
                (SELECT COUNT(*) FROM users WHERE company_id = ? AND is_deleted = 0) as user_count,
                (SELECT COUNT(*) FROM products WHERE company_id = ? AND is_active = 1) as product_count,
                (SELECT COUNT(*) FROM sales_invoices 
                 WHERE company_id = ? 
                 AND MONTH(invoice_date) = MONTH(CURDATE())
                 AND YEAR(invoice_date) = YEAR(CURDATE())) as monthly_sales,
                (SELECT COUNT(*) FROM sales_invoices WHERE company_id = ?) as total_sales,
                (SELECT SUM(total_amount) FROM sales_invoices 
                 WHERE company_id = ? 
                 AND MONTH(invoice_date) = MONTH(CURDATE())
                 AND YEAR(invoice_date) = YEAR(CURDATE())) as monthly_revenue
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$companyId, $companyId, $companyId, $companyId, $companyId]);
        return $stmt->fetch();
    }

    /**
     * Get upcoming invoices
     */
    public function getUpcomingInvoices($companyId)
    {
        $sql = "
            SELECT * FROM subscription_invoices 
            WHERE company_id = :company_id 
            AND status = 'pending'
            ORDER BY due_date ASC
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $companyId]);
        return $stmt->fetchAll();
    }
}