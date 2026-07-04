<?php
// models/PurchaseOrder.php
// ============================================
// PURCHASE ORDER MODEL WITH COMPANY SUPPORT
// ============================================

require_once __DIR__ . '/BaseModel.php';

class PurchaseOrder extends BaseModel
{
    protected $table = 'purchase_orders';
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
     * Verify that a purchase order belongs to the current company
     */
    protected function verifyCompanyOwnership($orderId)
    {
        if (!$this->companyId) {
            return false;
        }

        $stmt = $this->db->prepare("SELECT company_id FROM {$this->table} WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order || $order['company_id'] != $this->companyId) {
            throw new Exception('Purchase order does not belong to this company');
        }

        return true;
    }

    /**
     * Generate unique PO number (company-specific)
     */
    public function generatePONumber()
    {
        $this->validateCompanyContext();

        $prefix = 'PO';
        $year = date('Y');
        $month = date('m');

        // Add company prefix if available
        $companyPrefix = 'C' . $this->companyId . '-';

        $sql = "SELECT COUNT(*) as count FROM {$this->table} 
                WHERE po_number LIKE :pattern AND company_id = :company_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'pattern' => $companyPrefix . $prefix . '-' . $year . $month . '%',
            'company_id' => $this->companyId
        ]);
        $count = $stmt->fetch()['count'] + 1;

        return $companyPrefix . $prefix . '-' . $year . $month . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Get purchases summary for date range (company-specific)
     */
    public function getPurchasesSummary($startDate, $endDate)
    {
        $this->validateCompanyContext();

        $sql = "
            SELECT 
                COUNT(*) as total_orders,
                COALESCE(SUM(total_amount), 0) as total_amount,
                COALESCE(SUM(subtotal), 0) as subtotal,
                COALESCE(SUM(tax_amount), 0) as tax_amount,
                COUNT(DISTINCT supplier_id) as unique_suppliers,
                COALESCE(AVG(total_amount), 0) as avg_order_value
            FROM {$this->table}
            WHERE DATE(order_date) BETWEEN :start_date AND :end_date
            AND status != 'cancelled'
            AND company_id = :company_id
        ";

        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'company_id' => $this->companyId
        ];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Get purchases by status for date range (company-specific)
     */
    public function getPurchasesByStatus($startDate, $endDate)
    {
        $this->validateCompanyContext();

        $sql = "
            SELECT 
                status,
                COUNT(*) as count,
                COALESCE(SUM(total_amount), 0) as total
            FROM {$this->table}
            WHERE DATE(order_date) BETWEEN :start_date AND :end_date
            AND company_id = :company_id
            GROUP BY status
            ORDER BY total DESC
        ";

        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'company_id' => $this->companyId
        ];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get purchase order summary (company-specific)
     */
    public function getSummary()
    {
        $this->validateCompanyContext();

        $sql = "
            SELECT 
                COUNT(*) as total_orders,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_count,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved_count,
                SUM(CASE WHEN status = 'received' THEN 1 ELSE 0 END) as received_count,
                SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) as partial_count,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
                COALESCE(SUM(total_amount), 0) as total_value,
                COALESCE(SUM(CASE WHEN status IN ('approved', 'partial') THEN total_amount ELSE 0 END), 0) as pending_value,
                COALESCE(SUM(CASE WHEN status = 'approved' THEN total_amount ELSE 0 END), 0) as approved_value,
                COALESCE(SUM(CASE WHEN status = 'partial' THEN total_amount ELSE 0 END), 0) as partial_value
            FROM {$this->table}
            WHERE company_id = :company_id
        ";

        $params = ['company_id' => $this->companyId];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Create new purchase order with items (adds company_id automatically)
     */
    public function createOrder($data, $items)
    {
        $this->validateCompanyContext();

        // Track if we started the transaction
        $startedTransaction = false;

        try {
            if (!$this->inTransaction()) {
                $this->beginTransaction();
                $startedTransaction = true;
            }

            // Generate PO number if not provided
            if (empty($data['po_number'])) {
                $data['po_number'] = $this->generatePONumber();
            }

            // Add company_id if not set
            if (!isset($data['company_id'])) {
                $data['company_id'] = $this->companyId;
            }

            // Calculate totals
            $subtotal = 0;
            $taxAmount = 0;

            foreach ($items as $item) {
                $lineSubtotal = $item['quantity'] * $item['unit_price'];
                $lineTax = $lineSubtotal * (($item['tax_rate'] ?? 0) / 100);

                $subtotal += $lineSubtotal;
                $taxAmount += $lineTax;
            }

            $data['subtotal'] = $subtotal;
            $data['tax_amount'] = $taxAmount;
            $data['total_amount'] = $subtotal + $taxAmount;

            // Insert purchase order
            $orderId = $this->create($data);

            // Insert items
            $itemSql = "
                INSERT INTO purchase_items 
                (company_id, purchase_order_id, variant_id, quantity, unit_price, tax_rate, received_quantity)
                VALUES (:company_id, :purchase_order_id, :variant_id, :quantity, :unit_price, :tax_rate, 0)
            ";
            $itemStmt = $this->db->prepare($itemSql);

            foreach ($items as $item) {
                $itemStmt->execute([
                    'company_id' => $this->companyId,
                    'purchase_order_id' => $orderId,
                    'variant_id' => $item['variant_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'tax_rate' => $item['tax_rate'] ?? 0
                ]);
            }

            if ($startedTransaction) {
                $this->commit();
            }

            return $orderId;

        } catch (Exception $e) {
            if ($startedTransaction && $this->inTransaction()) {
                $this->rollback();
            }
            error_log("Purchase order creation error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get purchase order with items (with company check)
     */
    public function getWithItems($id)
    {
        $this->validateCompanyContext();

        // Get order with company check
        $sql = "
            SELECT 
                po.*,
                s.supplier_name,
                s.supplier_code,
                s.contact_person,
                s.phone as supplier_phone,
                s.email as supplier_email,
                s.address as supplier_address,
                u.full_name as created_by_name
            FROM {$this->table} po
            JOIN suppliers s ON po.supplier_id = s.id
            LEFT JOIN users u ON po.created_by = u.id
            WHERE po.id = :id AND po.company_id = :company_id
        ";

        $params = [
            'id' => $id,
            'company_id' => $this->companyId
        ];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $order = $stmt->fetch();

        if ($order) {
            // Get items with company check
            $sql = "
                SELECT 
                    pi.*,
                    v.sku,
                    v.variant_name,
                    p.product_name,
                    p.product_code
                FROM purchase_items pi
                JOIN variants v ON pi.variant_id = v.id
                JOIN products p ON v.product_id = p.id
                WHERE pi.purchase_order_id = :order_id AND pi.company_id = :company_id
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'order_id' => $id,
                'company_id' => $this->companyId
            ]);
            $order['items'] = $stmt->fetchAll();

            // Get additional costs
            $sql = "SELECT * FROM purchase_costs WHERE purchase_order_id = :order_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['order_id' => $id]);
            $order['costs'] = $stmt->fetchAll();
        }

        return $order;
    }

    /**
     * Get pending orders (company-specific)
     */
    public function getPendingOrders()
    {
        $this->validateCompanyContext();

        $sql = "
            SELECT 
                po.*,
                s.supplier_name,
                s.supplier_code,
                SUM(pi.quantity) as total_ordered,
                SUM(pi.received_quantity) as total_received
            FROM {$this->table} po
            JOIN suppliers s ON po.supplier_id = s.id
            JOIN purchase_items pi ON po.id = pi.purchase_order_id
            WHERE po.status IN ('approved', 'partial')
            AND po.company_id = :p_1_company_id
            AND pi.company_id = :p_2_company_id
            GROUP BY po.id
            HAVING total_received < total_ordered
            ORDER BY po.expected_date ASC
        ";

        $params = [
            'p_1_company_id' => $this->companyId,
            'p_2_company_id' => $this->companyId
        ];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get total payables (approved and partial orders)
     */
    public function getTotalPayables()
    {
        $sql = "SELECT COALESCE(SUM(total_amount), 0) as total 
            FROM {$this->table} 
            WHERE status IN ('approved', 'partial') 
            AND company_id = :company_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        $result = $stmt->fetch();
        return $result['total'] ?? 0;
    }

    /**
     * Get recent orders (company-specific)
     */
    public function getRecent($limit = 50)
    {
        $this->validateCompanyContext();

        $sql = "
            SELECT 
                po.*,
                s.supplier_name,
                s.supplier_code,
                (SELECT COUNT(*) FROM purchase_items WHERE purchase_order_id = po.id AND company_id = :p_1_company_id) as item_count,
                (SELECT SUM(received_quantity) FROM purchase_items WHERE purchase_order_id = po.id AND company_id = :p_2_company_id) as total_received,
                (SELECT SUM(quantity) FROM purchase_items WHERE purchase_order_id = po.id AND company_id = :p_3_company_id) as total_ordered
            FROM {$this->table} po
            JOIN suppliers s ON po.supplier_id = s.id
            WHERE po.company_id = :p_4_company_id
            ORDER BY po.created_at DESC
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':p_1_company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':p_2_company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':p_3_company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':p_4_company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Receive items (with company check)
     */
    public function receiveItems($orderId, $receivedItems)
    {
        $this->validateCompanyContext();
        $startedTransaction = false;

        try {
            // Verify order belongs to company
            $this->verifyCompanyOwnership($orderId);

            if (!$this->inTransaction()) {
                $this->beginTransaction();
                $startedTransaction = true;
            }

            $inventoryModel = new Inventory($this->companyId);
            $allReceived = true;

            foreach ($receivedItems as $item) {
                // Update received quantity in purchase_items
                $sql = "UPDATE purchase_items 
                        SET received_quantity = received_quantity + :received_qty
                        WHERE id = :item_id AND company_id = :company_id";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    'received_qty' => $item['received_quantity'],
                    'item_id' => $item['item_id'],
                    'company_id' => $this->companyId
                ]);

                // Get item details for inventory update
                $sql = "SELECT pi.*, v.purchase_price 
                        FROM purchase_items pi
                        JOIN variants v ON pi.variant_id = v.id
                        WHERE pi.id = :item_id AND pi.company_id = :company_id";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    'item_id' => $item['item_id'],
                    'company_id' => $this->companyId
                ]);
                $purchaseItem = $stmt->fetch();

                if (!$purchaseItem) {
                    throw new Exception("Purchase item not found");
                }

                // Update inventory
                $inventoryModel->updateStock(
                    $purchaseItem['variant_id'],
                    $item['warehouse_id'] ?? 1,
                    $item['received_quantity'],
                    $purchaseItem['unit_price'],
                    $item['location_id'] ?? null,
                    $_SESSION['user_id'] ?? 1
                );

                // Check if still pending
                $sql = "SELECT quantity, received_quantity FROM purchase_items 
                        WHERE id = :item_id AND company_id = :company_id";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    'item_id' => $item['item_id'],
                    'company_id' => $this->companyId
                ]);
                $current = $stmt->fetch();

                if ($current['received_quantity'] < $current['quantity']) {
                    $allReceived = false;
                }
            }

            // Update order status
            $order = $this->find($orderId);
            if ($order && $order['status'] === 'approved') {
                $newStatus = $allReceived ? 'received' : 'partial';
                $this->update($orderId, ['status' => $newStatus]);
            }

            if ($startedTransaction) {
                $this->commit();
            }

            return true;

        } catch (Exception $e) {
            if ($startedTransaction && $this->inTransaction()) {
                $this->rollback();
            }
            error_log("Receive items error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update order status (with company check)
     */
    public function updateStatus($id, $status, $notes = null)
    {
        $this->validateCompanyContext();
        $this->verifyCompanyOwnership($id);

        $data = ['status' => $status];
        if ($notes) {
            $data['notes'] = $notes;
        }
        return $this->update($id, $data);
    }

    /**
     * Get orders by status (company-specific)
     */
    public function getByStatus($status, $limit = 100)
    {
        $this->validateCompanyContext();

        $sql = "
            SELECT 
                po.*,
                s.supplier_name,
                s.supplier_code,
                (SELECT COUNT(*) FROM purchase_items WHERE purchase_order_id = po.id AND company_id = :company_id) as item_count,
                (SELECT SUM(received_quantity) FROM purchase_items WHERE purchase_order_id = po.id AND company_id = :company_id) as total_received,
                (SELECT SUM(quantity) FROM purchase_items WHERE purchase_order_id = po.id AND company_id = :company_id) as total_ordered
            FROM {$this->table} po
            JOIN suppliers s ON po.supplier_id = s.id
            WHERE po.status = :status AND po.company_id = :company_id
            ORDER BY po.order_date DESC
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Search purchase orders (company-specific)
     */
    public function search($term)
    {
        $this->validateCompanyContext();

        $sql = "
            SELECT 
                po.*,
                s.supplier_name,
                s.supplier_code
            FROM {$this->table} po
            JOIN suppliers s ON po.supplier_id = s.id
            WHERE (po.po_number LIKE :term OR s.supplier_name LIKE :term OR s.supplier_code LIKE :term)
            AND po.company_id = :company_id
            ORDER BY po.created_at DESC
            LIMIT 50
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
     * Get purchase order by number (company-specific)
     */
    public function findByPONumber($poNumber)
    {
        $this->validateCompanyContext();

        $sql = "SELECT * FROM {$this->table} 
                WHERE po_number = :po_number AND company_id = :company_id";

        $params = [
            'po_number' => $poNumber,
            'company_id' => $this->companyId
        ];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Get purchase orders by supplier (company-specific)
     */
    public function getBySupplier($supplierId, $limit = 50)
    {
        $this->validateCompanyContext();

        $sql = "
            SELECT * FROM {$this->table}
            WHERE supplier_id = :supplier_id 
            AND company_id = :company_id
            ORDER BY order_date DESC
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':supplier_id', $supplierId, PDO::PARAM_INT);
        $stmt->bindValue(':company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}