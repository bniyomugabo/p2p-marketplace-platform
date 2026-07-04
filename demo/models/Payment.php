<?php
// models/Payment.php
// ============================================
// PAYMENT MODEL WITH COMPANY SUPPORT
// ============================================

require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/Customer.php';

class Payment extends BaseModel
{
    protected $table = 'payments';
    protected $hasCompanySupport = true;

    /**
     * Constructor - can pass company ID or use session
     */
    public function __construct($companyId = null)
    {
        parent::__construct($companyId);
    }

    /**
     * Generate payment number (company-specific)
     */
    public function generatePaymentNumber()
    {
        if (!$this->companyId) {
            return 'PAY-' . date('Ymd') . '-' . str_pad(1, 4, '0', STR_PAD_LEFT);
        }

        $prefix = 'PAY';
        $year = date('Y');
        $month = date('m');

        $sql = "SELECT COUNT(*) as count FROM {$this->table} 
                WHERE payment_number LIKE :pattern AND company_id = :company_id";

        $pattern = $prefix . '-' . $year . $month . '%';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'pattern' => $pattern,
            'company_id' => $this->companyId
        ]);
        $count = $stmt->fetch()['count'] + 1;

        return $prefix . '-' . $year . $month . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Add payment to invoice (with company check)
     */
    public function addPayment($data)
    {
        // Track if we started the transaction
        $startedTransaction = false;

        try {
            // Check if a transaction is already active
            if (!$this->inTransaction()) {
                $this->beginTransaction();
                $startedTransaction = true;
            }

            // First verify invoice belongs to this company
            if ($this->companyId) {
                $stmt = $this->db->prepare("
                    SELECT company_id, total_amount, amount_paid, customer_id, invoice_number 
                    FROM sales_invoices 
                    WHERE id = :id AND company_id = :company_id
                ");
                $stmt->execute([
                    'id' => $data['invoice_id'],
                    'company_id' => $this->companyId
                ]);
                $invoice = $stmt->fetch();

                if (!$invoice) {
                    throw new Exception("Invoice not found or does not belong to this company");
                }
            } else {
                // Fallback without company filter
                $stmt = $this->db->prepare("
                    SELECT total_amount, amount_paid, customer_id, invoice_number 
                    FROM sales_invoices 
                    WHERE id = :id
                ");
                $stmt->execute(['id' => $data['invoice_id']]);
                $invoice = $stmt->fetch();

                if (!$invoice) {
                    throw new Exception("Invoice not found");
                }
            }

            // Check if payment amount is valid
            $newAmountPaid = $invoice['amount_paid'] + $data['amount'];
            if ($newAmountPaid > $invoice['total_amount'] + 0.01) { // Allow small rounding difference
                throw new Exception("Payment amount exceeds invoice total. Max allowed: " . ($invoice['total_amount'] - $invoice['amount_paid']));
            }

            // Generate payment number if not provided
            if (empty($data['payment_number'])) {
                $data['payment_number'] = $this->generatePaymentNumber();
            }

            // Add company_id to payment data
            if ($this->companyId && !isset($data['company_id'])) {
                $data['company_id'] = $this->companyId;
            }

            // Set created_at if not provided
            if (!isset($data['created_at'])) {
                $data['created_at'] = date('Y-m-d H:i:s');
            }

            // Insert payment
            $paymentId = $this->create($data);

            // Determine new invoice status
            if (abs($newAmountPaid - $invoice['total_amount']) < 0.01) {
                $status = 'paid';
            } elseif ($newAmountPaid > 0) {
                $status = 'partial';
            } else {
                $status = 'issued';
            }

            // Update invoice amount_paid and status
            $sql = "UPDATE sales_invoices 
                    SET amount_paid = :amount_paid, status = :status, updated_at = NOW()
                    WHERE id = :id";

            if ($this->companyId) {
                $sql .= " AND company_id = :company_id";
            }

            $stmt = $this->db->prepare($sql);
            $params = [
                'amount_paid' => $newAmountPaid,
                'status' => $status,
                'id' => $data['invoice_id']
            ];

            if ($this->companyId) {
                $params['company_id'] = $this->companyId;
            }

            $stmt->execute($params);

            // Update customer balance if needed (negative because payment reduces balance)
            if ($data['payment_method'] === 'credit') {
                $customerModel = new Customer($this->companyId);
                $customerModel->updateBalance($invoice['customer_id'], -$data['amount']);
            }

            // Log activity
            $logStmt = $this->db->prepare("
                INSERT INTO activity_log 
                (company_id, user_id, action, entity_type, entity_id, new_data, created_at)
                VALUES (:company_id, :user_id, 'payment_recorded', 'payment', :entity_id, :new_data, NOW())
            ");

            $logStmt->execute([
                'company_id' => $this->companyId,
                'user_id' => $data['created_by'] ?? 1,
                'entity_id' => $paymentId,
                'new_data' => json_encode([
                    'invoice_id' => $data['invoice_id'],
                    'invoice_number' => $invoice['invoice_number'],
                    'amount' => $data['amount'],
                    'payment_method' => $data['payment_method'],
                    'new_status' => $status,
                    'new_amount_paid' => $newAmountPaid
                ])
            ]);

            // Only commit if we started the transaction
            if ($startedTransaction) {
                $this->commit();
            }

            return $paymentId;

        } catch (Exception $e) {
            // Only rollback if we started the transaction and it's still active
            if ($startedTransaction && $this->inTransaction()) {
                $this->rollback();
            }
            error_log("Payment addition error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get payments by invoice (with company check)
     */
    public function getByInvoice($invoiceId)
    {
        if (!$this->companyId) {
            return [];
        }

        // Verify invoice belongs to company
        $stmt = $this->db->prepare("
            SELECT company_id FROM sales_invoices 
            WHERE id = :id AND company_id = :company_id
        ");
        $stmt->execute([
            'id' => $invoiceId,
            'company_id' => $this->companyId
        ]);
        $invoice = $stmt->fetch();

        if (!$invoice) {
            return [];
        }

        $sql = "
            SELECT 
                p.*,
                u.full_name as created_by_name
            FROM {$this->table} p
            LEFT JOIN users u ON p.created_by = u.id
            WHERE p.invoice_id = :invoice_id AND p.company_id = :company_id
            ORDER BY p.payment_date DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'invoice_id' => $invoiceId,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Get payments by date range (company-specific)
     */
    public function getByDateRange($startDate, $endDate)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                p.*,
                si.invoice_number,
                c.full_name as customer_name,
                c.customer_code
            FROM {$this->table} p
            JOIN sales_invoices si ON p.invoice_id = si.id AND si.company_id = :company_id
            JOIN customers c ON si.customer_id = c.id AND c.company_id = :company_id
            WHERE DATE(p.payment_date) BETWEEN :start_date AND :end_date
                AND p.company_id = :company_id
            ORDER BY p.payment_date DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Get payment summary by method (company-specific)
     */
    public function getSummaryByMethod($startDate, $endDate)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                payment_method,
                COUNT(*) as payment_count,
                SUM(amount) as total_amount
            FROM {$this->table}
            WHERE DATE(payment_date) BETWEEN :start_date AND :end_date
                AND company_id = :company_id
            GROUP BY payment_method
            ORDER BY total_amount DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Get total payments by period (company-specific)
     */
    public function getTotalByPeriod($period = 'month')
    {
        if (!$this->companyId) {
            return 0;
        }

        $sql = "SELECT SUM(amount) as total FROM {$this->table} 
                WHERE company_id = :company_id";

        if ($period === 'month') {
            $sql .= " AND MONTH(payment_date) = MONTH(CURDATE()) 
                      AND YEAR(payment_date) = YEAR(CURDATE())";
        } elseif ($period === 'week') {
            $sql .= " AND YEARWEEK(payment_date) = YEARWEEK(CURDATE())";
        } elseif ($period === 'today') {
            $sql .= " AND DATE(payment_date) = CURDATE()";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        $result = $stmt->fetch();
        return $result['total'] ?? 0;
    }

    /**
     * Get recent payments (company-specific)
     */
    public function getRecent($limit = 10)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                p.*,
                si.invoice_number,
                c.full_name as customer_name
            FROM {$this->table} p
            JOIN sales_invoices si ON p.invoice_id = si.id AND si.company_id = :company_id
            JOIN customers c ON si.customer_id = c.id AND c.company_id = :company_id
            WHERE p.company_id = :company_id
            ORDER BY p.payment_date DESC
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Void a payment (with company check)
     */
    public function voidPayment($paymentId, $reason = null, $userId = null)
    {
        if (!$this->companyId) {
            throw new Exception('Company context required');
        }

        $startedTransaction = false;

        try {
            if (!$this->inTransaction()) {
                $this->beginTransaction();
                $startedTransaction = true;
            }

            // Get payment details
            $stmt = $this->db->prepare("
                SELECT p.*, si.total_amount, si.amount_paid, si.customer_id, si.invoice_number
                FROM {$this->table} p
                JOIN sales_invoices si ON p.invoice_id = si.id
                WHERE p.id = :payment_id AND p.company_id = :company_id
            ");
            $stmt->execute([
                'payment_id' => $paymentId,
                'company_id' => $this->companyId
            ]);
            $payment = $stmt->fetch();

            if (!$payment) {
                throw new Exception("Payment not found or does not belong to this company");
            }

            // Update invoice amount_paid
            $newAmountPaid = $payment['amount_paid'] - $payment['amount'];
            $newStatus = $newAmountPaid > 0 ? 'partial' : 'issued';

            $stmt = $this->db->prepare("
                UPDATE sales_invoices 
                SET amount_paid = :amount_paid, status = :status, updated_at = NOW()
                WHERE id = :id AND company_id = :company_id
            ");
            $stmt->execute([
                'amount_paid' => $newAmountPaid,
                'status' => $newStatus,
                'id' => $payment['invoice_id'],
                'company_id' => $this->companyId
            ]);

            // Mark payment as voided (soft delete)
            $stmt = $this->db->prepare("
                UPDATE {$this->table} 
                SET is_voided = 1, voided_at = NOW(), voided_by = :voided_by, void_reason = :reason
                WHERE id = :id AND company_id = :company_id
            ");
            $stmt->execute([
                'voided_by' => $userId,
                'reason' => $reason,
                'id' => $paymentId,
                'company_id' => $this->companyId
            ]);

            // Log activity
            $logStmt = $this->db->prepare("
                INSERT INTO activity_log 
                (company_id, user_id, action, entity_type, entity_id, new_data, created_at)
                VALUES (:company_id, :user_id, 'payment_voided', 'payment', :entity_id, :new_data, NOW())
            ");

            $logStmt->execute([
                'company_id' => $this->companyId,
                'user_id' => $userId,
                'entity_id' => $paymentId,
                'new_data' => json_encode([
                    'invoice_id' => $payment['invoice_id'],
                    'invoice_number' => $payment['invoice_number'],
                    'amount' => $payment['amount'],
                    'reason' => $reason
                ])
            ]);

            if ($startedTransaction) {
                $this->commit();
            }

            return true;

        } catch (Exception $e) {
            if ($startedTransaction && $this->inTransaction()) {
                $this->rollback();
            }
            error_log("Payment void error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get payment statistics (company-specific)
     */
    public function getStats($days = 30)
    {
        if (!$this->companyId) {
            return [
                'total_payments' => 0,
                'total_amount' => 0,
                'avg_payment' => 0,
                'payment_count_by_method' => []
            ];
        }

        $sql = "
            SELECT 
                COUNT(*) as total_payments,
                SUM(amount) as total_amount,
                AVG(amount) as avg_payment
            FROM {$this->table}
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                AND company_id = :company_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'days' => $days,
            'company_id' => $this->companyId
        ]);
        $result = $stmt->fetch();

        // Get payment count by method
        $stmt = $this->db->prepare("
            SELECT 
                payment_method,
                COUNT(*) as count,
                SUM(amount) as total
            FROM {$this->table}
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                AND company_id = :company_id
            GROUP BY payment_method
            ORDER BY total DESC
        ");
        $stmt->execute([
            'days' => $days,
            'company_id' => $this->companyId
        ]);
        $paymentMethods = $stmt->fetchAll();

        return [
            'total_payments' => (int) ($result['total_payments'] ?? 0),
            'total_amount' => (float) ($result['total_amount'] ?? 0),
            'avg_payment' => (float) ($result['avg_payment'] ?? 0),
            'payment_count_by_method' => $paymentMethods
        ];
    }
}