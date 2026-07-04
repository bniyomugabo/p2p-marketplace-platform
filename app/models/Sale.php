<?php
// models/Sale.php
// ============================================
// SALES MODEL WITH COMPANY SUPPORT
// ============================================

require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/Inventory.php';
require_once __DIR__ . '/Payment.php';

class Sale extends BaseModel
{
    protected $table = 'sales_invoices';
    protected $hasCompanySupport = true;

    /**
     * Constructor - can pass company ID or use session
     */
    public function __construct($companyId = null)
    {
        parent::__construct($companyId);
    }

    /**
     * Generate invoice number (company-specific)
     */
    public function generateInvoiceNumber()
    {
        if (!$this->companyId) {
            return 'INV-' . date('Ymd') . '-001';
        }

        $prefix = 'INV';
        $year = date('Y');
        $month = date('m');

        $sql = "SELECT COUNT(*) as count FROM {$this->table} 
                WHERE invoice_number LIKE :pattern AND company_id = :company_id";

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
     * Get today's sales summary (company-specific)
     */
    public function getDailySummary($date = null)
    {
        if (!$this->companyId) {
            return [
                'invoice_count' => 0,
                'total_sales' => 0,
                'total_collected' => 0,
                'outstanding' => 0
            ];
        }

        if (!$date) {
            $date = date('Y-m-d');
        }

        $sql = "
            SELECT 
                COUNT(*) as invoice_count,
                COALESCE(SUM(total_amount), 0) as total_sales,
                COALESCE(SUM(amount_paid), 0) as total_collected,
                COALESCE(SUM(total_amount - amount_paid), 0) as outstanding
            FROM {$this->table}
            WHERE DATE(invoice_date) = :date
                AND status != 'cancelled'
                AND company_id = :company_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'date' => $date,
            'company_id' => $this->companyId
        ]);
        $result = $stmt->fetch();

        return $result ?: [
            'invoice_count' => 0,
            'total_sales' => 0,
            'total_collected' => 0,
            'outstanding' => 0
        ];
    }

    /**
     * Get sales summary for date range (company-specific)
     */
    public function getSalesSummary($startDate, $endDate, $customerId = null, $status = null)
    {
        if (!$this->companyId) {
            return [
                'total_invoices' => 0,
                'total_sales' => 0,
                'total_collected' => 0,
                'total_discount' => 0,
                'outstanding' => 0,
                'unique_customers' => 0,
                'avg_order_value' => 0,
                'total_items_sold' => 0,
                'avg_items_per_order' => 0,
                'total_returns' => 0
            ];
        }

        $sql = "
            SELECT 
                COUNT(DISTINCT si.id) as total_invoices,
                COALESCE(SUM(si.total_amount), 0) as total_sales,
                COALESCE(SUM(si.amount_paid), 0) as total_collected,
                COALESCE(SUM(si.discount_amount), 0) as total_discount,
                COALESCE(SUM(si.total_amount - si.amount_paid), 0) as outstanding,
                COUNT(DISTINCT si.customer_id) as unique_customers,
                COALESCE(AVG(si.total_amount), 0) as avg_order_value,
                COALESCE(SUM(ii.quantity), 0) as total_items_sold,
                COALESCE(COUNT(DISTINCT ii.id), 0) / NULLIF(COUNT(DISTINCT si.id), 0) as avg_items_per_order
            FROM {$this->table} si
            LEFT JOIN invoice_items ii ON si.id = ii.invoice_id
            WHERE DATE(si.invoice_date) BETWEEN :start_date AND :end_date
                AND si.status != 'cancelled'
                AND si.company_id = :company_id
        ";

        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'company_id' => $this->companyId
        ];

        if ($customerId) {
            $sql .= " AND si.customer_id = :customer_id";
            $params['customer_id'] = $customerId;
        }

        if ($status) {
            $sql .= " AND si.status = :status";
            $params['status'] = $status;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();

        // Get returns amount (company-specific)
        $sql = "
            SELECT COALESCE(SUM(ABS(it.quantity) * COALESCE(it.unit_cost, v.purchase_price, 0)), 0) as total_returns
            FROM inventory_transactions it
            JOIN variants v ON it.variant_id = v.id
            JOIN products p ON v.product_id = p.id
            WHERE it.transaction_type = 'return'
                AND DATE(it.created_at) BETWEEN :start_date AND :end_date
                AND p.company_id = :company_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'company_id' => $this->companyId
        ]);
        $returns = $stmt->fetch();
        $result['total_returns'] = $returns['total_returns'] ?? 0;

        return $result;
    }

    /**
     * Get weekly sales trend (company-specific)
     */
    public function getWeeklyTrend()
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                DATE(invoice_date) as date,
                DAYNAME(invoice_date) as day_name,
                COALESCE(SUM(total_amount), 0) as total_sales,
                COALESCE(COUNT(*), 0) as invoice_count
            FROM {$this->table}
            WHERE invoice_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                AND status != 'cancelled'
                AND company_id = :company_id
            GROUP BY DATE(invoice_date), DAYNAME(invoice_date)
            ORDER BY date
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        return $stmt->fetchAll();
    }

    /**
     * Get monthly trend for last N months (company-specific)
     */
    public function getMonthlyTrend($months = 6)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                DATE_FORMAT(invoice_date, '%Y-%m') as month,
                DATE_FORMAT(invoice_date, '%M %Y') as month_name,
                COUNT(*) as invoice_count,
                COALESCE(SUM(total_amount), 0) as total_sales,
                COALESCE(SUM(amount_paid), 0) as total_paid,
                COALESCE(AVG(total_amount), 0) as avg_invoice
            FROM {$this->table}
            WHERE invoice_date >= DATE_SUB(CURDATE(), INTERVAL :months MONTH)
                AND status != 'cancelled'
                AND company_id = :company_id
            GROUP BY DATE_FORMAT(invoice_date, '%Y-%m'), DATE_FORMAT(invoice_date, '%M %Y')
            ORDER BY month ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'months' => $months,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Get daily sales for date range (company-specific)
     */
    public function getDailySalesRange($startDate, $endDate, $customerId = null, $status = null)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                DATE(si.invoice_date) as date,
                COUNT(*) as invoice_count,
                COALESCE(SUM(si.total_amount), 0) as sales,
                COALESCE(SUM(si.amount_paid), 0) as payments,
                COALESCE(SUM(si.total_amount - si.amount_paid), 0) as outstanding,
                COALESCE(SUM(ii.quantity), 0) as items_sold
            FROM {$this->table} si
            LEFT JOIN invoice_items ii ON si.id = ii.invoice_id
            WHERE DATE(si.invoice_date) BETWEEN :start_date AND :end_date
                AND si.status != 'cancelled'
                AND si.company_id = :company_id
        ";

        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'company_id' => $this->companyId
        ];

        if ($customerId) {
            $sql .= " AND si.customer_id = :customer_id";
            $params['customer_id'] = $customerId;
        }

        if ($status) {
            $sql .= " AND si.status = :status";
            $params['status'] = $status;
        }

        $sql .= " GROUP BY DATE(si.invoice_date) ORDER BY date";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get monthly sales summary (company-specific)
     */
    public function getMonthlySummary($year = null, $month = null)
    {
        if (!$this->companyId) {
            return [
                'total_sales' => 0,
                'invoice_count' => 0,
                'avg_invoice' => 0
            ];
        }

        if (!$year)
            $year = date('Y');
        if (!$month)
            $month = date('m');

        $sql = "
            SELECT 
                COALESCE(SUM(total_amount), 0) as total_sales,
                COALESCE(COUNT(*), 0) as invoice_count,
                COALESCE(AVG(total_amount), 0) as avg_invoice
            FROM {$this->table}
            WHERE YEAR(invoice_date) = :year
                AND MONTH(invoice_date) = :month
                AND status != 'cancelled'
                AND company_id = :company_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'year' => $year,
            'month' => $month,
            'company_id' => $this->companyId
        ]);
        $result = $stmt->fetch();

        return $result ?: [
            'total_sales' => 0,
            'invoice_count' => 0,
            'avg_invoice' => 0
        ];
    }

    /**
     * Get sales by status (company-specific)
     */
    public function getSalesByStatus()
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                status,
                COUNT(*) as count,
                COALESCE(SUM(total_amount), 0) as total
            FROM {$this->table}
            WHERE status IN ('paid', 'partial', 'overdue', 'issued', 'draft', 'cancelled')
                AND company_id = :company_id
            GROUP BY status
            ORDER BY total DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        return $stmt->fetchAll();
    }
    /**
     * Get invoices by date range
     */
    public function getInvoicesByDateRange($startDate, $endDate, $customerId = null, $status = null)
    {
        $sql = "SELECT si.*, c.full_name as customer_name 
            FROM sales_invoices si
            JOIN customers c ON si.customer_id = c.id
            WHERE si.invoice_date BETWEEN :start_date AND :end_date
            AND si.company_id = :company_id";
        $params = ['start_date' => $startDate, 'end_date' => $endDate, 'company_id' => $this->companyId];

        if ($customerId) {
            $sql .= " AND si.customer_id = :customer_id";
            $params['customer_id'] = $customerId;
        }
        if ($status) {
            $sql .= " AND si.status = :status";
            $params['status'] = $status;
        }

        $sql .= " ORDER BY si.invoice_date DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }


    /**
     * Get sales by payment method (company-specific)
     */
    public function getSalesByPaymentMethod($startDate, $endDate)
    {
        if (!$this->companyId) {
            return [];
        }

        if ($startDate !== "" || $endDate !== "") {
            $sql = "
            SELECT 
                p.payment_method,
                COUNT(*) as payment_count,
                COALESCE(SUM(p.amount), 0) as total_amount
            FROM payments p
            JOIN {$this->table} si ON p.invoice_id = si.id
            WHERE DATE(p.payment_date) BETWEEN :start_date AND :end_date
                AND 
            si.status != 'cancelled'
                AND si.company_id = :company_id
            GROUP BY p.payment_method
            ORDER BY total_amount DESC
        ";
        } else {
            $sql = "
            SELECT 
                p.payment_method,
                COUNT(*) as payment_count,
                COALESCE(SUM(p.amount), 0) as total_amount
            FROM payments p
            JOIN {$this->table} si ON p.invoice_id = si.id
            WHERE 
            si.status != 'cancelled'
                AND si.company_id = :company_id
            GROUP BY p.payment_method
            ORDER BY total_amount DESC
        ";
        }



        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Get top selling products (company-specific)
     */
    public function getTopProducts($limit = 5)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                p.product_name,
                v.sku,
                v.variant_name,
                SUM(ii.quantity) as total_sold,
                SUM(ii.quantity * ii.unit_price * (1 - ii.discount_percent/100)) as total_revenue
            FROM invoice_items ii
            JOIN variants v ON ii.variant_id = v.id
            JOIN products p ON v.product_id = p.id
            JOIN {$this->table} si ON ii.invoice_id = si.id
            WHERE MONTH(si.invoice_date) = MONTH(CURDATE())
                AND YEAR(si.invoice_date) = YEAR(CURDATE())
                AND p.company_id = :company_id
            GROUP BY v.id
            ORDER BY total_sold DESC
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get top products by date range (company-specific)
     */
    public function getTopProductsByDateRange($startDate, $endDate, $limit = 20)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                p.id as product_id,
                p.product_name,
                v.id as variant_id,
                v.sku,
                v.variant_name,
                SUM(ii.quantity) as total_sold,
                SUM(ii.quantity * ii.unit_price * (1 - ii.discount_percent/100)) as total_revenue,
                COUNT(DISTINCT si.id) as invoice_count
            FROM invoice_items ii
            JOIN variants v ON ii.variant_id = v.id
            JOIN products p ON v.product_id = p.id
            JOIN {$this->table} si ON ii.invoice_id = si.id
            WHERE DATE(si.invoice_date) BETWEEN :start_date AND :end_date
                AND si.status != 'cancelled'
                AND p.company_id = :company_id
            GROUP BY v.id
            ORDER BY total_revenue DESC
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':start_date', $startDate);
        $stmt->bindValue(':end_date', $endDate);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get total receivables (company-specific)
     */
    public function getTotalReceivables()
    {
        if (!$this->companyId) {
            return 0;
        }

        $sql = "
            SELECT COALESCE(SUM(total_amount - amount_paid), 0) as total
            FROM {$this->table}
            WHERE status IN ('issued', 'partial', 'overdue')
                AND company_id = :company_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        $result = $stmt->fetch();
        return $result['total'] ?? 0;
    }

    /**
     * Get recent payments (company-specific)
     */
    public function getRecentPayments($limit = 5)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                p.*,
                si.invoice_number,
                c.full_name as customer_name
            FROM payments p
            JOIN {$this->table} si ON p.invoice_id = si.id
            JOIN customers c ON si.customer_id = c.id
            WHERE si.company_id = :company_id
            ORDER BY p.payment_date DESC
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get recent sales (company-specific)
     */
    public function getRecentSales($limit = 5)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                si.*,
                c.full_name as customer_name,
                COUNT(ii.id) as item_count
            FROM {$this->table} si
            JOIN customers c ON si.customer_id = c.id
            LEFT JOIN invoice_items ii ON si.id = ii.invoice_id
            WHERE si.status != 'cancelled'
                AND si.company_id = :company_id
            GROUP BY si.id
            ORDER BY si.created_at DESC
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get growth rate (company-specific)
     */
    public function getGrowthRate()
    {
        if (!$this->companyId) {
            return ['current' => 0, 'previous' => 0, 'rate' => 0];
        }

        $sql = "
            SELECT 
                (SELECT COALESCE(SUM(total_amount), 0) 
                 FROM {$this->table} 
                 WHERE YEAR(invoice_date) = YEAR(CURDATE()) 
                    AND MONTH(invoice_date) = MONTH(CURDATE())
                    AND company_id = :company_id) as current_month,
                (SELECT COALESCE(SUM(total_amount), 0) 
                 FROM {$this->table} 
                 WHERE YEAR(invoice_date) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
                    AND MONTH(invoice_date) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))
                    AND company_id = :company_id2) as previous_month
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'company_id' => $this->companyId,
            'company_id2' => $this->companyId
        ]);
        $result = $stmt->fetch();

        $rate = 0;
        if ($result && $result['previous_month'] > 0) {
            $rate = (($result['current_month'] - $result['previous_month']) / $result['previous_month']) * 100;
        }

        return [
            'current' => $result['current_month'] ?? 0,
            'previous' => $result['previous_month'] ?? 0,
            'rate' => round($rate, 2)
        ];
    }

    /**
     * Get sales by customer (company-specific)
     */
    public function getSalesByCustomer($customerId, $startDate = null, $endDate = null)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                si.*,
                COUNT(ii.id) as item_count
            FROM {$this->table} si
            LEFT JOIN invoice_items ii ON si.id = ii.invoice_id
            WHERE si.customer_id = :customer_id
                AND si.company_id = :company_id
        ";

        $params = [
            'customer_id' => $customerId,
            'company_id' => $this->companyId
        ];

        if ($startDate) {
            $sql .= " AND DATE(si.invoice_date) >= :start_date";
            $params['start_date'] = $startDate;
        }

        if ($endDate) {
            $sql .= " AND DATE(si.invoice_date) <= :end_date";
            $params['end_date'] = $endDate;
        }

        $sql .= " GROUP BY si.id ORDER BY si.invoice_date DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Create new sale with items (adds company_id automatically)
     */
    public function createSale($customerId, $items, $paymentMethod = 'cash', $userId = null)
    {
        if (!$this->companyId) {
            throw new Exception('Company context required for creating sale');
        }

        // Track if we started the transaction
        $startedTransaction = false;

        try {
            // Check if a transaction is already active
            if (!$this->inTransaction()) {
                $this->beginTransaction();
                $startedTransaction = true;
            }

            $invoiceNumber = $this->generateInvoiceNumber();
            $invoiceDate = date('Y-m-d');
            $dueDate = date('Y-m-d', strtotime('+30 days'));

            // Calculate totals
            $subtotal = 0;
            $taxAmount = 0;

            foreach ($items as $item) {
                $lineSubtotal = $item['quantity'] * $item['unit_price'];
                $lineDiscount = $lineSubtotal * (($item['discount_percent'] ?? 0) / 100);
                $lineTax = ($lineSubtotal - $lineDiscount) * (($item['tax_rate'] ?? 0) / 100);

                $subtotal += $lineSubtotal;
                $taxAmount += $lineTax;
            }

            $totalAmount = $subtotal + $taxAmount;

            // Insert invoice with company_id
            $sql = "
                INSERT INTO {$this->table} 
                (company_id, invoice_number, customer_id, invoice_date, due_date, status,
                 subtotal, tax_amount, total_amount, amount_paid, created_by, created_at)
                VALUES (:company_id, :invoice_number, :customer_id, :invoice_date, :due_date, 'issued',
                        :subtotal, :tax_amount, :total_amount, 0, :created_by, NOW())
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'company_id' => $this->companyId,
                'invoice_number' => $invoiceNumber,
                'customer_id' => $customerId,
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'subtotal' => $subtotal,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'created_by' => $userId ?: 1
            ]);

            $invoiceId = $this->db->lastInsertId();

            // Insert items and update inventory
            $itemSql = "
                INSERT INTO invoice_items 
                (invoice_id, variant_id, quantity, unit_price, discount_percent, tax_rate)
                VALUES (:invoice_id, :variant_id, :quantity, :unit_price, :discount_percent, :tax_rate)
            ";
            $itemStmt = $this->db->prepare($itemSql);

            $inventory = new Inventory($this->companyId);

            foreach ($items as $item) {
                // Insert invoice item
                $itemStmt->execute([
                    'invoice_id' => $invoiceId,
                    'variant_id' => $item['variant_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount_percent' => $item['discount_percent'] ?? 0,
                    'tax_rate' => $item['tax_rate'] ?? 0
                ]);

                // Update inventory (reduce stock)
                $inventory->updateStock(
                    $item['variant_id'],
                    $item['warehouse_id'] ?? 1,
                    -$item['quantity'],
                    null,
                    $item['location_id'] ?? null,
                    $userId
                );
            }

            // Process payment if cash payment
            if ($paymentMethod === 'cash') {
                $paymentModel = new Payment($this->companyId);
                $paymentModel->addPayment([
                    'invoice_id' => $invoiceId,
                    'amount' => $totalAmount,
                    'payment_method' => 'cash',
                    'payment_date' => $invoiceDate,
                    'created_by' => $userId ?: 1
                ]);

                // Update invoice status to paid
                $updateStmt = $this->db->prepare("
                    UPDATE {$this->table} 
                    SET status = 'paid', amount_paid = :amount_paid 
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    'amount_paid' => $totalAmount,
                    'id' => $invoiceId
                ]);
            }

            // Only commit if we started the transaction
            if ($startedTransaction) {
                $this->commit();
            }

            return $invoiceId;

        } catch (Exception $e) {
            // Only rollback if we started the transaction and it's still active
            if ($startedTransaction && $this->inTransaction()) {
                $this->rollback();
            }
            error_log("Sale creation error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get invoice with details (with company check)
     */
    public function getInvoiceWithDetails($invoiceId)
    {
        if (!$this->companyId) {
            return null;
        }

        // Get invoice header with company check
        $sql = "
            SELECT 
                si.*,
                c.full_name as customer_name,
                c.phone as customer_phone,
                c.email as customer_email,
                c.address as customer_address,
                u.full_name as created_by_name
            FROM {$this->table} si
            JOIN customers c ON si.customer_id = c.id
            LEFT JOIN users u ON si.created_by = u.id
            WHERE si.id = :id
                AND si.company_id = :company_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $invoiceId,
            'company_id' => $this->companyId
        ]);
        $invoice = $stmt->fetch();

        if ($invoice) {
            // Get items
            $sql = "
                SELECT 
                    ii.*,
                    v.sku,
                    v.variant_name,
                    p.product_name
                FROM invoice_items ii
                JOIN variants v ON ii.variant_id = v.id
                JOIN products p ON v.product_id = p.id
                WHERE ii.invoice_id = :invoice_id
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute(['invoice_id' => $invoiceId]);
            $invoice['items'] = $stmt->fetchAll();

            // Get payments
            $sql = "SELECT * FROM payments WHERE invoice_id = :invoice_id ORDER BY payment_date";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['invoice_id' => $invoiceId]);
            $invoice['payments'] = $stmt->fetchAll();
        }

        return $invoice;
    }
}