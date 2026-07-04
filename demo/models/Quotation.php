<?php
// models/Quotation.php
// ============================================
// QUOTATION MODEL WITH COMPANY SUPPORT
// INDEPENDENT FROM PRODUCTS/VARIANTS
// ============================================

require_once __DIR__ . '/BaseModel.php';

class Quotation extends BaseModel
{
    protected $table = 'quotations';
    protected $primaryKey = 'id';

    /**
     * Constructor - can pass company ID or use session
     */
    public function __construct($companyId = null)
    {
        parent::__construct($companyId);
    }

    /**
     * Validate company context
     */
    protected function validateCompanyContext()
    {
        if (!$this->companyId) {
            throw new Exception('Company context is required for this operation');
        }
        return true;
    }

    /**
     * Verify quotation belongs to company
     */
    protected function verifyCompanyOwnership($quotationId)
    {
        if (!$this->companyId) {
            return false;
        }

        $stmt = $this->db->prepare("SELECT company_id FROM {$this->table} WHERE id = ?");
        $stmt->execute([$quotationId]);
        $quotation = $stmt->fetch();

        if (!$quotation || $quotation['company_id'] != $this->companyId) {
            throw new Exception('Quotation does not belong to this company');
        }

        return true;
    }

    /**
     * Generate unique quotation number (company-specific)
     */
    public function generateQuotationNumber()
    {
        $this->validateCompanyContext();

        $prefix = 'QUO';
        $year = date('Y');
        $month = date('m');

        // Add company prefix
        $companyPrefix = 'C' . $this->companyId . '-';

        $sql = "SELECT COUNT(*) as count FROM {$this->table} 
                WHERE quotation_number LIKE :pattern AND company_id = :company_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'pattern' => $companyPrefix . $prefix . '-' . $year . $month . '%',
            'company_id' => $this->companyId
        ]);
        $count = $stmt->fetch()['count'] + 1;

        return $companyPrefix . $prefix . '-' . $year . $month . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Create new quotation with custom items (no product/variant links)
     */
    public function createQuotation($data, $items)
    {
        $this->validateCompanyContext();

        $startedTransaction = false;

        try {
            if (!$this->inTransaction()) {
                $this->beginTransaction();
                $startedTransaction = true;
            }

            // Generate quotation number if not provided
            if (empty($data['quotation_number'])) {
                $data['quotation_number'] = $this->generateQuotationNumber();
            }

            // Add company_id if not set
            if (!isset($data['company_id'])) {
                $data['company_id'] = $this->companyId;
            }

            // Set default dates
            $data['quotation_date'] = $data['quotation_date'] ?? date('Y-m-d');
            $data['valid_until'] = $data['valid_until'] ?? date('Y-m-d', strtotime('+30 days'));
            $data['status'] = $data['status'] ?? 'draft';
            $data['currency'] = $data['currency'] ?? 'RWF';

            // Calculate totals
            $subtotal = 0;
            $discountAmount = 0;
            $taxAmount = 0;

            foreach ($items as $item) {
                $quantity = (float) ($item['quantity'] ?? 1);
                $unitPrice = (float) ($item['unit_price'] ?? 0);
                $discountPercent = (float) ($item['discount_percent'] ?? 0);
                $taxRate = (float) ($item['tax_rate'] ?? 0);

                $lineSubtotal = $quantity * $unitPrice;
                $lineDiscount = $lineSubtotal * ($discountPercent / 100);
                $lineAfterDiscount = $lineSubtotal - $lineDiscount;
                $lineTax = $lineAfterDiscount * ($taxRate / 100);

                $subtotal += $lineSubtotal;
                $discountAmount += $lineDiscount;
                $taxAmount += $lineTax;
            }

            $data['subtotal'] = $subtotal;
            $data['discount_amount'] = $discountAmount;
            $data['tax_amount'] = $taxAmount;
            $data['total_amount'] = $subtotal - $discountAmount + $taxAmount;

            // Insert quotation
            $quotationId = $this->create($data);

            // Insert items with sort order
            $itemSql = "
                INSERT INTO quotation_items 
                (company_id, quotation_id, product_name, description, quantity, unit_price, discount_percent, tax_rate, sort_order)
                VALUES (:company_id, :quotation_id, :product_name, :description, :quantity, :unit_price, :discount_percent, :tax_rate, :sort_order)
            ";
            $itemStmt = $this->db->prepare($itemSql);

            $sortOrder = 0;
            foreach ($items as $item) {
                $itemStmt->execute([
                    'company_id' => $this->companyId,
                    'quotation_id' => $quotationId,
                    'product_name' => $item['product_name'],
                    'description' => $item['description'] ?? null,
                    'quantity' => (float) ($item['quantity'] ?? 1),
                    'unit_price' => (float) ($item['unit_price'] ?? 0),
                    'discount_percent' => (float) ($item['discount_percent'] ?? 0),
                    'tax_rate' => (float) ($item['tax_rate'] ?? 0),
                    'sort_order' => $sortOrder++
                ]);
            }

            if ($startedTransaction) {
                $this->commit();
            }

            return $quotationId;

        } catch (Exception $e) {
            if ($startedTransaction && $this->inTransaction()) {
                $this->rollback();
            }
            error_log("Quotation creation error for company {$this->companyId}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update quotation with items
     */
    public function updateQuotation($id, $data, $items)
    {
        $this->validateCompanyContext();
        $this->verifyCompanyOwnership($id);

        $startedTransaction = false;

        try {
            if (!$this->inTransaction()) {
                $this->beginTransaction();
                $startedTransaction = true;
            }

            // Set dates if provided
            if (isset($data['quotation_date']) && empty($data['quotation_date'])) {
                $data['quotation_date'] = date('Y-m-d');
            }
            if (isset($data['valid_until']) && empty($data['valid_until'])) {
                $data['valid_until'] = date('Y-m-d', strtotime('+30 days'));
            }

            // Calculate totals
            $subtotal = 0;
            $discountAmount = 0;
            $taxAmount = 0;

            foreach ($items as $item) {
                $quantity = (float) ($item['quantity'] ?? 1);
                $unitPrice = (float) ($item['unit_price'] ?? 0);
                $discountPercent = (float) ($item['discount_percent'] ?? 0);
                $taxRate = (float) ($item['tax_rate'] ?? 0);

                $lineSubtotal = $quantity * $unitPrice;
                $lineDiscount = $lineSubtotal * ($discountPercent / 100);
                $lineAfterDiscount = $lineSubtotal - $lineDiscount;
                $lineTax = $lineAfterDiscount * ($taxRate / 100);

                $subtotal += $lineSubtotal;
                $discountAmount += $lineDiscount;
                $taxAmount += $lineTax;
            }

            $data['subtotal'] = $subtotal;
            $data['discount_amount'] = $discountAmount;
            $data['tax_amount'] = $taxAmount;
            $data['total_amount'] = $subtotal - $discountAmount + $taxAmount;

            // Update quotation
            $this->update($id, $data);

            // Delete existing items
            $deleteSql = "DELETE FROM quotation_items WHERE quotation_id = :quotation_id AND company_id = :company_id";
            $deleteStmt = $this->db->prepare($deleteSql);
            $deleteStmt->execute([
                'quotation_id' => $id,
                'company_id' => $this->companyId
            ]);

            // Insert new items with sort order
            $itemSql = "
                INSERT INTO quotation_items 
                (company_id, quotation_id, product_name, description, quantity, unit_price, discount_percent, tax_rate, sort_order)
                VALUES (:company_id, :quotation_id, :product_name, :description, :quantity, :unit_price, :discount_percent, :tax_rate, :sort_order)
            ";
            $itemStmt = $this->db->prepare($itemSql);

            $sortOrder = 0;
            foreach ($items as $item) {
                $itemStmt->execute([
                    'company_id' => $this->companyId,
                    'quotation_id' => $id,
                    'product_name' => $item['product_name'],
                    'description' => $item['description'] ?? null,
                    'quantity' => (float) ($item['quantity'] ?? 1),
                    'unit_price' => (float) ($item['unit_price'] ?? 0),
                    'discount_percent' => (float) ($item['discount_percent'] ?? 0),
                    'tax_rate' => (float) ($item['tax_rate'] ?? 0),
                    'sort_order' => $sortOrder++
                ]);
            }

            if ($startedTransaction) {
                $this->commit();
            }

            return true;

        } catch (Exception $e) {
            if ($startedTransaction && $this->inTransaction()) {
                $this->rollback();
            }
            error_log("Quotation update error for company {$this->companyId}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get quotation with items (with company check)
     */
    public function getWithItems($id)
    {
        $this->validateCompanyContext();

        $sql = "
            SELECT q.*, 
                   u.full_name as created_by_name,
                   a.full_name as approved_by_name
            FROM {$this->table} q
            LEFT JOIN users u ON q.created_by = u.id
            LEFT JOIN users a ON q.approved_by = a.id
            WHERE q.id = :id AND q.company_id = :company_id
        ";

        $params = [
            'id' => $id,
            'company_id' => $this->companyId
        ];

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $quotation = $stmt->fetch();

        if ($quotation) {
            // Get items ordered by sort_order
            $sql = "SELECT * FROM quotation_items 
                    WHERE quotation_id = :quotation_id AND company_id = :company_id 
                    ORDER BY sort_order ASC, id ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'quotation_id' => $id,
                'company_id' => $this->companyId
            ]);
            $quotation['items'] = $stmt->fetchAll();
        }

        return $quotation;
    }

    /**
     * Update quotation status (with company check)
     */
    public function updateStatus($id, $status, $notes = null)
    {
        $this->validateCompanyContext();
        $this->verifyCompanyOwnership($id);

        $validStatuses = ['draft', 'sent', 'accepted', 'rejected', 'expired', 'converted'];
        if (!in_array($status, $validStatuses)) {
            throw new Exception("Invalid status: {$status}");
        }

        $data = ['status' => $status];

        // Set timestamps based on status
        if ($status === 'sent') {
            $data['sent_at'] = date('Y-m-d H:i:s');
        } elseif ($status === 'accepted' && !isset($data['accepted_at'])) {
            $data['accepted_at'] = date('Y-m-d H:i:s');
        }

        if ($notes) {
            $existingNotes = $this->getNotes($id);
            $data['notes'] = $existingNotes
                ? $existingNotes . "\n\n[" . strtoupper($status) . "] " . date('Y-m-d H:i') . " - " . $notes
                : "[" . strtoupper($status) . "] " . date('Y-m-d H:i') . " - " . $notes;
        }

        return $this->update($id, $data);
    }

    /**
     * Mark quotation as converted to invoice/sale
     */
    public function markAsConverted($id, $invoiceId, $approvedBy = null)
    {
        $this->validateCompanyContext();
        $this->verifyCompanyOwnership($id);

        $data = [
            'status' => 'converted',
            'converted_to_invoice_id' => $invoiceId,
            'converted_at' => date('Y-m-d H:i:s'),
            'approved_by' => $approvedBy
        ];

        return $this->update($id, $data);
    }

    /**
     * Get notes for quotation
     */
    protected function getNotes($id)
    {
        $sql = "SELECT notes FROM {$this->table} WHERE id = :id AND company_id = :company_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'company_id' => $this->companyId
        ]);
        $result = $stmt->fetch();
        return $result['notes'] ?? null;
    }

    /**
     * Get quotations by status (company-specific)
     */
    public function getByStatus($status, $limit = 100)
    {
        $this->validateCompanyContext();

        $sql = "
            SELECT q.*, 
                   (SELECT COUNT(*) FROM quotation_items WHERE quotation_id = q.id AND company_id = :company_id_1) as item_count
            FROM {$this->table} q
            WHERE q.status = :status AND q.company_id = :company_id_2
            ORDER BY q.created_at DESC
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':company_id_1', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':company_id_2', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get recent quotations (company-specific)
     */
    public function getRecent($limit = 20)
    {
        $this->validateCompanyContext();

        $sql = "
            SELECT q.*, 
                   (SELECT COUNT(*) FROM quotation_items WHERE quotation_id = q.id AND company_id = :company_id_1) as item_count
            FROM {$this->table} q
            WHERE q.company_id = :company_id_2
            ORDER BY q.created_at DESC
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':company_id_1', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':company_id_2', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Search quotations (company-specific)
     */
    public function search($term)
    {
        $this->validateCompanyContext();

        $sql = "
            SELECT q.*, 
                   (SELECT COUNT(*) FROM quotation_items WHERE quotation_id = q.id AND company_id = :company_id_1) as item_count
            FROM {$this->table} q
            WHERE q.company_id = :company_id_2
            AND (q.quotation_number LIKE :term 
                OR q.customer_name LIKE :term 
                OR q.customer_email LIKE :term
                OR q.customer_phone LIKE :term)
            ORDER BY q.created_at DESC
            LIMIT 50
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'company_id_1' => $this->companyId,
            'company_id_2' => $this->companyId,
            'term' => "%{$term}%"
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Get quotations summary (company-specific)
     */
    public function getSummary()
    {
        $this->validateCompanyContext();

        $sql = "
            SELECT 
                COUNT(*) as total_quotations,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_count,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_count,
                SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted_count,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected_count,
                SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired_count,
                SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) as converted_count,
                SUM(CASE WHEN status IN ('accepted', 'converted') THEN total_amount ELSE 0 END) as won_total,
                SUM(CASE WHEN status IN ('draft', 'sent') THEN total_amount ELSE 0 END) as pending_total,
                SUM(total_amount) as total_value
            FROM {$this->table}
            WHERE company_id = :company_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        return $stmt->fetch();
    }

    /**
     * Get quotations by customer (company-specific)
     */
    public function getByCustomer($customerName, $limit = 50)
    {
        $this->validateCompanyContext();

        $sql = "
            SELECT * FROM {$this->table}
            WHERE customer_name LIKE :customer_name
            AND company_id = :company_id
            ORDER BY created_at DESC
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':customer_name', "%{$customerName}%");
        $stmt->bindValue(':company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get expired quotations (company-specific)
     */
    public function getExpired()
    {
        $this->validateCompanyContext();

        $sql = "
            SELECT * FROM {$this->table}
            WHERE valid_until < CURDATE()
            AND status IN ('draft', 'sent')
            AND company_id = :company_id
            ORDER BY valid_until ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        return $stmt->fetchAll();
    }

    /**
     * Mark expired quotations
     */
    public function markExpired()
    {
        $this->validateCompanyContext();

        $sql = "
            UPDATE {$this->table}
            SET status = 'expired'
            WHERE valid_until < CURDATE()
            AND status IN ('draft', 'sent')
            AND company_id = :company_id
        ";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['company_id' => $this->companyId]);
    }

    /**
     * Delete quotation (with company check)
     */
    public function delete($id, $softDelete = false): bool
    {
        $this->validateCompanyContext();
        $this->verifyCompanyOwnership($id);

        // For quotations, we do hard delete since items cascade
        $sql = "DELETE FROM {$this->table} WHERE {$this->primaryKey} = :id AND company_id = :company_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'id' => $id,
            'company_id' => $this->companyId
        ]);
    }

    /**
     * Get quotation statistics for dashboard
     */
    public function getStats()
    {
        $this->validateCompanyContext();

        $sql = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft,
                SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired,
                SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) as converted,
                COALESCE(SUM(CASE WHEN status IN ('accepted', 'converted') THEN total_amount ELSE 0 END), 0) as won_value,
                COALESCE(SUM(CASE WHEN status IN ('draft', 'sent') THEN total_amount ELSE 0 END), 0) as pending_value
            FROM {$this->table}
            WHERE company_id = :company_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);
        return $stmt->fetch();
    }

    /**
     * Get quotations converted to invoices (company-specific)
     */
    public function getConverted($limit = 100)
    {
        $this->validateCompanyContext();

        $sql = "
            SELECT q.*, 
                   (SELECT COUNT(*) FROM quotation_items WHERE quotation_id = q.id AND company_id = :company_id_1) as item_count
            FROM {$this->table} q
            WHERE q.status = 'converted' AND q.company_id = :company_id_2
            ORDER BY q.converted_at DESC
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':company_id_1', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':company_id_2', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get quotation by invoice ID (company-specific)
     */
    public function getByInvoiceId($invoiceId)
    {
        $this->validateCompanyContext();

        $sql = "SELECT * FROM {$this->table} 
                WHERE converted_to_invoice_id = :invoice_id 
                AND company_id = :company_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'invoice_id' => $invoiceId,
            'company_id' => $this->companyId
        ]);
        return $stmt->fetch();
    }

    /**
     * Send quotation email (placeholder for email functionality)
     */
    public function sendQuotationEmail($id, $recipientEmail, $recipientName = null)
    {
        $this->validateCompanyContext();
        $this->verifyCompanyOwnership($id);

        $quotation = $this->getWithItems($id);
        if (!$quotation) {
            throw new Exception('Quotation not found.');
        }

        // Update status to sent if it's draft
        if ($quotation['status'] === 'draft') {
            $this->updateStatus($id, 'sent', 'Email sent to customer');
        } else {
            // Update sent_at timestamp
            $this->update($id, ['sent_at' => date('Y-m-d H:i:s')]);
        }

        // TODO: Implement actual email sending logic here
        // This would generate PDF and send via email

        return true;
    }
}