<?php
// /src/Models/Customer.php
// Customer Model - Handles customer authentication and profile
require_once __DIR__ . '/../../config/database.php';
class Customer {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Register a new customer
     * 
     * @param array $data Customer data (full_name, email, phone, password)
     * @return array ['success' => bool, 'message' => string, 'customer_id' => int|null]
     */
    public function register($data) {
        try {
            // Validate email doesn't exist
            if ($this->emailExists($data['email'])) {
                return ['success' => false, 'message' => 'Email already registered'];
            }
            
            // Validate phone doesn't exist (if provided)
            if (!empty($data['phone']) && $this->phoneExists($data['phone'])) {
                return ['success' => false, 'message' => 'Phone number already registered'];
            }
            
            // Generate customer code
            $customerCode = $this->generateCustomerCode();
            
            // Hash password
            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
            
            // Start transaction
            $this->db->beginTransaction();
            
            // Insert customer (company_id 0 for marketplace customers)
            $sql = "INSERT INTO customers (company_id, customer_code, full_name, email, phone, password_hash, customer_type, created_by, created_at) 
                    VALUES (9, :customer_code, :full_name, :email, :phone, :password_hash, 'individual', 9, NOW())";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':customer_code', $customerCode);
            $stmt->bindValue(':full_name', $data['full_name']);
            $stmt->bindValue(':email', $data['email']);
            $stmt->bindValue(':phone', $data['phone'] ?? null);
            $stmt->bindValue(':password_hash', $hashedPassword);
            $stmt->execute();
            
            $customerId = $this->db->lastInsertId();
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Registration successful',
                'customer_id' => $customerId
            ];
            
        } catch (PDOException $e) {
            $this->db->rollBack();
            error_log("Customer::register Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Registration failed. Please try again.'. $e->getMessage()];
        }
    }


    
    /**
     * Login customer
     * 
     * @param string $email Email address
     * @param string $password Plain text password
     * @return array ['success' => bool, 'message' => string, 'customer' => array|null]
     */
    public function login($email, $password) {
        try {
            $sql = "SELECT id, customer_code, full_name, email, phone, password_hash, 
                           is_active, credit_limit, current_balance
                    FROM customers 
                    WHERE email = :email AND company_id = COMPANY_ID";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':email', $email);
            $stmt->execute();
            
            $customer = $stmt->fetch();
            
            if (!$customer) {
                return ['success' => false, 'message' => 'Invalid email or password'];
            }
            
            if (!$customer['is_active']) {
                return ['success' => false, 'message' => 'Account is deactivated. Please contact support.'];
            }
            
            if (!password_verify($password, $customer['password_hash'])) {
                return ['success' => false, 'message' => 'Invalid email or password'];
            }
            
            // Remove sensitive data
            unset($customer['password_hash']);
            
            return [
                'success' => true,
                'message' => 'Login successful',
                'customer' => $customer
            ];
            
        } catch (PDOException $e) {
            error_log("Customer::login Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Login failed. Please try again. ' .$e->getMessage()];
        }
    }
    
    /**
     * Get customer by ID
     * 
     * @param int $customerId Customer ID
     * @return array|null
     */
    public function getCustomerById($customerId) {
        try {
            $sql = "SELECT id, customer_code, full_name, email, phone, 
                           is_active, credit_limit, current_balance, notes, created_at
                    FROM customers 
                    WHERE id = :id AND company_id = COMPANY_ID";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':id', $customerId, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetch();
            
        } catch (PDOException $e) {
            error_log("Customer::getCustomerById Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update customer profile
     * 
     * @param int $customerId Customer ID
     * @param array $data Update data
     * @return array ['success' => bool, 'message' => string]
     */
    public function updateProfile($customerId, $data) {
        try {
            $updates = [];
            $params = [':id' => $customerId];
            
            if (isset($data['full_name'])) {
                $updates[] = "full_name = :full_name";
                $params[':full_name'] = $data['full_name'];
            }
            
            if (isset($data['phone'])) {
                // Check if phone is taken by another customer
                if ($this->phoneExists($data['phone'], $customerId)) {
                    return ['success' => false, 'message' => 'Phone number already in use'];
                }
                $updates[] = "phone = :phone";
                $params[':phone'] = $data['phone'];
            }
            
            if (isset($data['address'])) {
                $updates[] = "address = :address";
                $params[':address'] = $data['address'];
            }
            
            if (isset($data['city'])) {
                $updates[] = "city = :city";
                $params[':city'] = $data['city'];
            }
            
            if (empty($updates)) {
                return ['success' => true, 'message' => 'No changes to update'];
            }
            
            $updates[] = "updated_at = NOW()";
            $sql = "UPDATE customers SET " . implode(', ', $updates) . " WHERE id = :id AND company_id = COMPANY_ID";
            
            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            
            return ['success' => true, 'message' => 'Profile updated successfully'];
            
        } catch (PDOException $e) {
            error_log("Customer::updateProfile Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Update failed. Please try again.'];
        }
    }
    
    /**
     * Change customer password
     * 
     * @param int $customerId Customer ID
     * @param string $oldPassword Old password
     * @param string $newPassword New password
     * @return array ['success' => bool, 'message' => string]
     */
    public function changePassword($customerId, $oldPassword, $newPassword) {
        try {
            // Verify old password
            $sql = "SELECT password_hash FROM customers WHERE id = :id AND company_id = COMPANY_ID";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':id', $customerId, PDO::PARAM_INT);
            $stmt->execute();
            
            $customer = $stmt->fetch();
            
            if (!$customer || !password_verify($oldPassword, $customer['password_hash'])) {
                return ['success' => false, 'message' => 'Current password is incorrect'];
            }
            
            // Update password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            
            $sql = "UPDATE customers SET password_hash = :password, updated_at = NOW() 
                    WHERE id = :id AND company_id = COMPANY_ID";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':password', $hashedPassword);
            $stmt->bindValue(':id', $customerId, PDO::PARAM_INT);
            $stmt->execute();
            
            return ['success' => true, 'message' => 'Password changed successfully'];
            
        } catch (PDOException $e) {
            error_log("Customer::changePassword Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Password change failed'];
        }
    }
    
    /**
     * Check if email exists
     * 
     * @param string $email Email address
     * @param int $excludeId Customer ID to exclude (for update)
     * @return bool
     */
    private function emailExists($email, $excludeId = null) {
        $sql = "SELECT COUNT(*) as count FROM customers WHERE email = :email AND company_id = COMPANY_ID";
        $params = [':email' => $email];
        
        if ($excludeId) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        
        $result = $stmt->fetch();
        return $result['count'] > 0;
    }
    
    /**
     * Check if phone exists
     * 
     * @param string $phone Phone number
     * @param int $excludeId Customer ID to exclude
     * @return bool
     */
    private function phoneExists($phone, $excludeId = null) {
        $sql = "SELECT COUNT(*) as count FROM customers WHERE phone = :phone AND company_id = COMPANY_ID";
        $params = [':phone' => $phone];
        
        if ($excludeId) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        
        $result = $stmt->fetch();
        return $result['count'] > 0;
    }
    
    /**
     * Generate unique customer code
     * 
     * @return string
     */
    private function generateCustomerCode() {
        $prefix = 'CUST';
        $year = date('Y');
        $maxAttempts = 10;
        
        for ($i = 0; $i < $maxAttempts; $i++) {
            $sequence = str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
            $code = $prefix . '-' . $year . '-' . $sequence;
            
            $sql = "SELECT COUNT(*) as count FROM customers WHERE customer_code = :code";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':code', $code);
            $stmt->execute();
            
            $result = $stmt->fetch();
            if ($result['count'] == 0) {
                return $code;
            }
        }
        
        // Fallback with timestamp
        return $prefix . '-' . $year . '-' . time();
    }
    
    /**
     * Get customer order history
     * 
     * @param int $customerId Customer ID
     * @param int $limit Limit results
     * @param int $offset Offset
     * @return array
     */
    public function getOrderHistory($customerId, $limit = 10, $offset = 0) {
        try {
            $sql = "SELECT 
                        si.id,
                        si.invoice_number,
                        si.invoice_date,
                        si.due_date,
                        si.status,
                        si.subtotal,
                        si.tax_amount,
                        si.total_amount,
                        si.amount_paid,
                        c.company_name,
                        c.id as company_id
                    FROM sales_invoices si
                    INNER JOIN companies c ON si.company_id = c.id
                    WHERE si.customer_id = :customer_id
                    ORDER BY si.invoice_date DESC
                    LIMIT :limit OFFSET :offset";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':customer_id', $customerId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $orders = $stmt->fetchAll();
            
            // Get order items for each order
            foreach ($orders as &$order) {
                $order['items'] = $this->getOrderItems($order['id']);
            }
            
            return $orders;
            
        } catch (PDOException $e) {
            error_log("Customer::getOrderHistory Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get items for a specific order
     * 
     * @param int $invoiceId Invoice ID
     * @return array
     */
    private function getOrderItems($invoiceId) {
        try {
            $sql = "SELECT 
                        ii.id,
                        ii.variant_id,
                        ii.quantity,
                        ii.unit_price,
                        ii.discount_percent,
                        ii.tax_rate,
                        v.sku,
                        v.variant_name,
                        v.selling_price,
                        p.product_name
                    FROM invoice_items ii
                    INNER JOIN variants v ON ii.variant_id = v.id
                    INNER JOIN products p ON v.product_id = p.id
                    WHERE ii.invoice_id = :invoice_id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':invoice_id', $invoiceId, PDO::PARAM_INT);
            $stmt->execute();
            
            $items = $stmt->fetchAll();
            
            foreach ($items as &$item) {
                $item['line_total'] = $item['quantity'] * $item['unit_price'];
                $item['line_total_with_tax'] = $item['line_total'] * (1 + ($item['tax_rate'] / 100));
            }
            
            return $items;
            
        } catch (PDOException $e) {
            error_log("Customer::getOrderItems Error: " . $e->getMessage());
            return [];
        }
    }
}