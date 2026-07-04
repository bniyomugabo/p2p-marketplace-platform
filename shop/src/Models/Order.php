<?php
// /src/Models/Order.php
// Order Model - Handles order creation with transaction safety
require_once __DIR__ . '/../../config/database.php';
class Order {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Create a new order with transaction-safe stock deduction
     * 
     * @param array $orderData Order data
     * @param array $cartItems Cart items
     * @return array ['success' => bool, 'message' => string, 'invoice_id' => int|null, 'invoice_number' => string|null]
     */
    public function createOrder($orderData, $cartItems) {
        try {
            // Start transaction
            $this->db->beginTransaction();
            
            // Validate all items have sufficient stock before proceeding
            foreach ($cartItems as $item) {
                if (!$this->validateStock($item['variant_id'], $item['quantity'])) {
                    $this->db->rollBack();
                    return [
                        'success' => false,
                        'message' => "Insufficient stock for product: {$item['product_name']}"
                    ];
                }
            }
            
            // Get company_id (all items should be from same company for now)
            // In a multi-vendor system, you'd create separate invoices per company
            $firstItem = reset($cartItems);
            $variantInfo = $this->getVariantCompanyInfo($firstItem['variant_id']);
            if (!$variantInfo) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Invalid product variant'];
            }
            
            $companyId = $variantInfo['company_id'];
            
            // Generate invoice number
            $invoiceNumber = $this->generateInvoiceNumber($companyId);
            
            // Calculate totals
            $subtotal = 0;
            $taxAmount = 0;
            
            foreach ($cartItems as &$item) {
                $itemTotal = $item['quantity'] * $item['price'];
                $itemTax = $itemTotal * (($item['tax_rate'] ?? DEFAULT_TAX_RATE) / 100);
                $subtotal += $itemTotal;
                $taxAmount += $itemTax;
                $item['tax_amount'] = $itemTax;
            }
            
            $totalAmount = $subtotal + $taxAmount + ($orderData['shipping_cost'] ?? 0);
            
            // Get or create walk-in customer if no customer logged in
            $customerId = $orderData['customer_id'] ?? $this->getOrCreateWalkInCustomer($companyId);
            
            // Create invoice
            $sql = "INSERT INTO sales_invoices 
                    (company_id, invoice_number, customer_id, invoice_date, due_date, status, 
                     subtotal, tax_amount, total_amount, amount_paid, notes, created_by, created_at) 
                    VALUES 
                    (:company_id, :invoice_number, :customer_id, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), 'issued',
                     :subtotal, :tax_amount, :total_amount, 0, :notes, :created_by, NOW())";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':company_id', $companyId, PDO::PARAM_INT);
            $stmt->bindValue(':invoice_number', $invoiceNumber);
            $stmt->bindValue(':customer_id', $customerId, PDO::PARAM_INT);
            $stmt->bindValue(':subtotal', $subtotal);
            $stmt->bindValue(':tax_amount', $taxAmount);
            $stmt->bindValue(':total_amount', $totalAmount);
            $stmt->bindValue(':notes', $orderData['notes'] ?? null);
            $stmt->bindValue(':created_by', USER_ID, PDO::PARAM_INT); // 0 for system/customer
            $stmt->execute();
            
            $invoiceId = $this->db->lastInsertId();
            
            // Create invoice items and update inventory
            foreach ($cartItems as $item) {
                // Insert invoice item
                $sql = "INSERT INTO invoice_items 
                        (invoice_id, variant_id, quantity, unit_price, discount_percent, tax_rate) 
                        VALUES 
                        (:invoice_id, :variant_id, :quantity, :unit_price, 0, :tax_rate)";
                
                $stmt = $this->db->prepare($sql);
                $stmt->bindValue(':invoice_id', $invoiceId, PDO::PARAM_INT);
                $stmt->bindValue(':variant_id', $item['variant_id'], PDO::PARAM_INT);
                $stmt->bindValue(':quantity', $item['quantity']);
                $stmt->bindValue(':unit_price', $item['price']);
                $stmt->bindValue(':tax_rate', $item['tax_rate'] ?? DEFAULT_TAX_RATE);
                $stmt->execute();
                
                // Update inventory (deduct stock)
                $this->deductStock($item['variant_id'], $item['quantity'], $companyId);
                
                // Create inventory transaction
                $this->createInventoryTransaction($item['variant_id'], $item['quantity'], $companyId, $invoiceId);
            }
            
            // Commit transaction
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Order created successfully',
                'invoice_id' => $invoiceId,
                'invoice_number' => $invoiceNumber,
                'total_amount' => $totalAmount
            ];
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("Order::createOrder Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to create order. Please try again.'.$e->getMessage()];
        }
    }
    /**
 * Get order by ID for specific customer
 * 
 * @param int $orderId Order ID
 * @param int $customerId Customer ID
 * @return array|null
 */
public function getOrderById($orderId, $customerId) {
    try {
        $conn = $this->db->getConnection();
        
        $sql = "SELECT 
                    si.*,
                    c.full_name as customer_name,
                    c.email as customer_email,
                    c.phone as customer_phone,
                    comp.company_name,
                    comp.company_code
                FROM sales_invoices si
                INNER JOIN customers c ON si.customer_id = c.id
                INNER JOIN companies comp ON si.company_id = comp.id
                WHERE si.id = :order_id AND si.customer_id = :customer_id";
        
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
        $stmt->bindValue(':customer_id', $customerId, PDO::PARAM_INT);
        $stmt->execute();
        
        $order = $stmt->fetch();
        
        if ($order) {
            $order['items'] = $this->getOrderItems($orderId);
        }
        
        return $order;
        
    } catch (PDOException $e) {
        error_log("Order::getOrderById Error: " . $e->getMessage());
        return null;
    }
}
    /**
     * Validate stock for a variant
     * 
     * @param int $variantId Variant ID
     * @param float $quantity Requested quantity
     * @return bool
     */
    private function validateStock($variantId, $quantity) {
        try {
            $sql = "SELECT COALESCE(SUM(i.quantity), 0) as total_stock
                    FROM inventory i
                    WHERE i.variant_id = :variant_id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':variant_id', $variantId, PDO::PARAM_INT);
            $stmt->execute();
            
            $result = $stmt->fetch();
            return $result['total_stock'] >= $quantity;
            
        } catch (PDOException $e) {
            error_log("Order::validateStock Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Deduct stock from inventory
     * 
     * @param int $variantId Variant ID
     * @param float $quantity Quantity to deduct
     * @param int $companyId Company ID
     * @return bool
     */
    private function deductStock($variantId, $quantity, $companyId) {
        try {
            // Get the primary warehouse (implementation can be enhanced for multi-warehouse)
            $sql = "SELECT id FROM warehouses WHERE company_id = :company_id ORDER BY is_main DESC LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':company_id', $companyId, PDO::PARAM_INT);
            $stmt->execute();
            
            $warehouse = $stmt->fetch();
            if (!$warehouse) {
                return false;
            }
            
            $sql = "UPDATE inventory 
                    SET quantity = quantity - :quantity, last_updated = NOW()
                    WHERE variant_id = :variant_id AND warehouse_id = :warehouse_id AND company_id = :company_id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':quantity', $quantity);
            $stmt->bindValue(':variant_id', $variantId, PDO::PARAM_INT);
            $stmt->bindValue(':warehouse_id', $warehouse['id'], PDO::PARAM_INT);
            $stmt->bindValue(':company_id', $companyId, PDO::PARAM_INT);
            $stmt->execute();
            
            return true;
            
        } catch (PDOException $e) {
            error_log("Order::deductStock Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create inventory transaction record
     * 
     * @param int $variantId Variant ID
     * @param float $quantity Quantity (negative for sale)
     * @param int $companyId Company ID
     * @param int $invoiceId Invoice ID
     * @return bool
     */
    private function createInventoryTransaction($variantId, $quantity, $companyId, $invoiceId) {
        try {
            $transactionCode = 'SALE-' . date('YmdHis') . '-' . rand(100, 999);
            
            $sql = "INSERT INTO inventory_transactions 
                    (company_id, transaction_code, transaction_type, variant_id, warehouse_id, 
                     quantity, unit_price, reference_type, reference_id,created_by, created_at) 
                    SELECT 
                        :company_id, :transaction_code, 'sale', :variant_id, 
                        id as warehouse_id, -:quantity, 
                        (SELECT selling_price FROM variants WHERE id = :variant_id),
                        'sales_invoice', :invoice_id, :created_by, NOW()
                    FROM warehouses 
                    WHERE company_id = :company_id 
                    ORDER BY is_main DESC LIMIT 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':company_id', $companyId, PDO::PARAM_INT);
            $stmt->bindValue(':transaction_code', $transactionCode);
            $stmt->bindValue(':variant_id', $variantId, PDO::PARAM_INT);
            $stmt->bindValue(':quantity', $quantity);
            $stmt->bindValue(':invoice_id', $invoiceId, PDO::PARAM_INT);
            $stmt->bindValue(':created_by', USER_ID, PDO::PARAM_INT);
            $stmt->execute();
            
            return true;
            
        } catch (PDOException $e) {
            error_log("Order::createInventoryTransaction Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get variant and company information
     * 
     * @param int $variantId Variant ID
     * @return array|null
     */
    private function getVariantCompanyInfo($variantId) {
        try {
            $sql = "SELECT v.id, v.company_id, v.selling_price as price, v.tax_rate, 
                           p.product_name
                    FROM variants v
                    INNER JOIN products p ON v.product_id = p.id
                    WHERE v.id = :variant_id AND v.is_active = 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':variant_id', $variantId, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetch();
            
        } catch (PDOException $e) {
            error_log("Order::getVariantCompanyInfo Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Generate unique invoice number
     * 
     * @param int $companyId Company ID
     * @return string
     */
    private function generateInvoiceNumber($companyId) {
        $sql = "SELECT invoice_prefix FROM companies WHERE id = :company_id";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':company_id', $companyId, PDO::PARAM_INT);
        $stmt->execute();
        
        $company = $stmt->fetch();
        $prefix = ($company && $company['invoice_prefix']) ? $company['invoice_prefix'] : 'INV';
        
        $yearMonth = date('Ym');
        
        $sql = "SELECT COUNT(*) as count FROM sales_invoices 
                WHERE company_id = :company_id AND invoice_number LIKE :pattern";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':company_id', $companyId, PDO::PARAM_INT);
        $stmt->bindValue(':pattern', $prefix . '-' . $yearMonth . '-%');
        $stmt->execute();
        
        $count = $stmt->fetch()['count'] + 1;
        $sequence = str_pad($count, 4, '0', STR_PAD_LEFT);
        
        return $prefix . '-' . $yearMonth . '-' . $sequence;
    }
    
    /**
     * Get or create walk-in customer
     * 
     * @param int $companyId Company ID
     * @return int Customer ID
     */
    private function getOrCreateWalkInCustomer($companyId) {
        try {
            // Check if walk-in customer exists for this company
            $sql = "SELECT id FROM customers 
                    WHERE company_id = :company_id AND full_name = 'Walk-in Customer' LIMIT 1";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':company_id', $companyId, PDO::PARAM_INT);
            $stmt->execute();
            
            $customer = $stmt->fetch();
            if ($customer) {
                return $customer['id'];
            }
            
            // Create walk-in customer
            $customerCode = $this->generateCustomerCode($companyId);
            
            $sql = "INSERT INTO customers (company_id, customer_code, full_name, customer_type, created_by, created_at) 
                    VALUES (:company_id, :customer_code, 'Walk-in Customer', 'individual', :created_by, NOW())";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':company_id', $companyId, PDO::PARAM_INT);
            $stmt->bindValue(':customer_code', $customerCode);
            $stmt->bindValue(':created_by', USER_ID, PDO::PARAM_INT);
            $stmt->execute();
            
            return $this->db->lastInsertId();
            
        } catch (PDOException $e) {
            error_log("Order::getOrCreateWalkInCustomer Error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Generate customer code
     * 
     * @param int $companyId Company ID
     * @return string
     */
    private function generateCustomerCode($companyId) {
        $company = $this->getCompanyCode($companyId);
        $year = date('Y');
        $sequence = str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        return $company . '-CUST-' . $year . '-' . $sequence;
    }
    
    /**
     * Get company code
     * 
     * @param int $companyId Company ID
     * @return string
     */
    private function getCompanyCode($companyId) {
        $sql = "SELECT company_code FROM companies WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':id', $companyId, PDO::PARAM_INT);
        $stmt->execute();
        
        $company = $stmt->fetch();
        return $company ? $company['company_code'] : 'CMP';
    }
    
    /**
     * Get order details by invoice number
     * 
     * @param string $invoiceNumber Invoice number
     * @return array|null
     */
    public function getOrderByInvoiceNumber($invoiceNumber) {
        try {
            $sql = "SELECT 
                        si.*,
                        c.full_name as customer_name,
                        c.email as customer_email,
                        c.phone as customer_phone,
                        comp.company_name,
                        comp.company_code
                    FROM sales_invoices si
                    INNER JOIN customers c ON si.customer_id = c.id
                    INNER JOIN companies comp ON si.company_id = comp.id
                    WHERE si.invoice_number = :invoice_number";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':invoice_number', $invoiceNumber);
            $stmt->execute();
            
            $order = $stmt->fetch();
            
            if ($order) {
                $order['items'] = $this->getOrderItems($order['id']);
            }
            
            return $order;
            
        } catch (PDOException $e) {
            error_log("Order::getOrderByInvoiceNumber Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get order items
     * 
     * @param int $invoiceId Invoice ID
     * @return array
     */
    private function getOrderItems($invoiceId) {
        try {
            $sql = "SELECT 
                        ii.*,
                        v.sku,
                        v.variant_name,
                        p.product_name,
                        p.product_code
                    FROM invoice_items ii
                    INNER JOIN variants v ON ii.variant_id = v.id
                    INNER JOIN products p ON v.product_id = p.id
                    WHERE ii.invoice_id = :invoice_id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':invoice_id', $invoiceId, PDO::PARAM_INT);
            $stmt->execute();
            
            $items = $stmt->fetchAll();
            
            foreach ($items as &$item) {
                $item['subtotal'] = $item['quantity'] * $item['unit_price'];
                $item['tax_amount'] = $item['subtotal'] * ($item['tax_rate'] / 100);
                $item['total'] = $item['subtotal'] + $item['tax_amount'];
            }
            
            return $items;
            
        } catch (PDOException $e) {
            error_log("Order::getOrderItems Error: " . $e->getMessage());
            return [];
        }
    }
}