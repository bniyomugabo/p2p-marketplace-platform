<?php
// models/Store.php
declare(strict_types=1);

class Store
{
    private $db;
    private $companyId;

    public function __construct()
    {
        $this->db = Database::getInstance();
        // Get company ID from session or default to active company
        $this->companyId = $_SESSION['company_id'] ?? $this->getDefaultCompanyId();
    }

    private function getDefaultCompanyId()
    {
        $stmt = $this->db->prepare("SELECT id FROM companies WHERE is_active = 1 LIMIT 1");
        $stmt->execute();
        $result = $stmt->fetch();
        return $result ? $result['id'] : 1;
    }

    /**
     * Get featured products for homepage
     */
    public function getFeaturedProducts($limit = 8)
    {
        $sql = "
            SELECT 
                v.id as variant_id,
                v.sku,
                v.variant_name,
                v.selling_price,
                p.id as product_id,
                p.product_name,
                p.description,
                COALESCE(i.available_quantity, 0) as stock,
                (SELECT image_url FROM variant_images WHERE variant_id = v.id AND is_primary = 1 LIMIT 1) as image
            FROM variants v
            JOIN products p ON v.product_id = p.id
            LEFT JOIN inventory i ON v.id = i.variant_id
            WHERE p.company_id = :company_id
                AND p.is_active = 1
                AND v.is_active = 1
                AND v.selling_price > 0
            ORDER BY p.created_at DESC
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Get products with pagination and filters
     */
    public function getProducts($page = 1, $limit = 12, $categoryId = null, $search = '', $sort = 'default')
    {
        $offset = ($page - 1) * $limit;

        $sql = "
            SELECT 
                v.id as variant_id,
                v.sku,
                v.variant_name,
                v.selling_price,
                p.id as product_id,
                p.product_name,
                p.description,
                COALESCE(i.available_quantity, 0) as stock,
                (SELECT image_url FROM variant_images WHERE variant_id = v.id AND is_primary = 1 LIMIT 1) as image
            FROM variants v
            JOIN products p ON v.product_id = p.id
            LEFT JOIN inventory i ON v.id = i.variant_id
            WHERE p.company_id = :company_id
                AND p.is_active = 1
                AND v.is_active = 1
                AND v.selling_price > 0
        ";

        $params = [':company_id' => $this->companyId];

        if ($categoryId) {
            $sql .= " AND p.category_id = :category_id";
            $params[':category_id'] = $categoryId;
        }

        if ($search) {
            $sql .= " AND (p.product_name LIKE :search OR v.variant_name LIKE :search OR v.sku LIKE :search)";
            $params[':search'] = "%{$search}%";
        }

        // Apply sorting
        switch ($sort) {
            case 'price_asc':
                $sql .= " ORDER BY v.selling_price ASC";
                break;
            case 'price_desc':
                $sql .= " ORDER BY v.selling_price DESC";
                break;
            case 'name_asc':
                $sql .= " ORDER BY p.product_name ASC";
                break;
            default:
                $sql .= " ORDER BY p.created_at DESC";
        }

        // Get total count
        $countSql = str_replace(
            "SELECT 
                v.id as variant_id,
                v.sku,
                v.variant_name,
                v.selling_price,
                p.id as product_id,
                p.product_name,
                p.description,
                COALESCE(i.available_quantity, 0) as stock,
                (SELECT image_url FROM variant_images WHERE variant_id = v.id AND is_primary = 1 LIMIT 1) as image",
            "SELECT COUNT(DISTINCT v.id) as total",
            $sql
        );

        $stmt = $this->db->prepare($countSql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $total = $stmt->fetch()['total'];

        // Add pagination
        $sql .= " LIMIT :limit OFFSET :offset";
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $type);
        }
        $stmt->execute();
        $products = $stmt->fetchAll();

        return [
            'products' => $products,
            'total' => (int) $total,
            'total_pages' => ceil($total / $limit),
            'current_page' => $page
        ];
    }

    /**
     * Get single product by variant ID
     */
    public function getProduct($variantId)
    {
        $sql = "
            SELECT 
                v.id as variant_id,
                v.sku,
                v.barcode,
                v.variant_name,
                v.selling_price,
                v.purchase_price,
                v.tax_rate,
                p.id as product_id,
                p.product_name,
                p.product_code,
                p.description,
                p.category_id,
                c.category_name,
                COALESCE(i.available_quantity, 0) as stock
            FROM variants v
            JOIN products p ON v.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN inventory i ON v.id = i.variant_id
            WHERE v.id = :variant_id
                AND p.company_id IN (SELECT id FROM companies WHERE is_active = 1)
                AND p.is_active = 1
                AND v.is_active = 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':variant_id' => $variantId
        ]);

        $product = $stmt->fetch();

        if ($product) {
            // Get product images
            $imgSql = "SELECT image_url, is_primary FROM variant_images WHERE variant_id = :variant_id ORDER BY is_primary DESC, sort_order ASC";
            $imgStmt = $this->db->prepare($imgSql);
            $imgStmt->execute([':variant_id' => $variantId]);
            $product['images'] = $imgStmt->fetchAll();

            // Get product attributes
            $attrSql = "SELECT attribute_name, attribute_value FROM variant_attributes WHERE variant_id = :variant_id ORDER BY display_order";
            $attrStmt = $this->db->prepare($attrSql);
            $attrStmt->execute([':variant_id' => $variantId]);
            $product['attributes'] = $attrStmt->fetchAll();
        }

        return $product;
    }

    /**
     * Get related products
     */
    public function getRelatedProducts($productId, $categoryId, $excludeVariantId, $limit = 4)
    {
        $sql = "
            SELECT 
                v.id as variant_id,
                v.selling_price,
                p.product_name,
                (SELECT image_url FROM variant_images WHERE variant_id = v.id AND is_primary = 1 LIMIT 1) as image
            FROM variants v
            JOIN products p ON v.product_id = p.id
            WHERE p.category_id = :category_id
                AND p.company_id = :company_id
                AND v.id != :exclude_id
                AND v.is_active = 1
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
        $stmt->bindValue(':company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':exclude_id', $excludeVariantId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Get all categories with product counts
     */
    public function getCategories()
    {
        $sql = "
            SELECT 
                c.id,
                c.category_code,
                c.category_name,
                COUNT(DISTINCT p.id) as product_count
            FROM categories c
            JOIN category_company cc ON c.id = cc.category_id
            LEFT JOIN products p ON c.id = p.category_id AND p.is_active = 1
            WHERE cc.company_id = :company_id
                AND cc.is_active = 1
            GROUP BY c.id
            ORDER BY c.category_name
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':company_id' => $this->companyId]);

        return $stmt->fetchAll();
    }

    /**
     * Get products by category
     */
    public function getProductsByCategory($categoryId, $page = 1, $limit = 12)
    {
        return $this->getProducts($page, $limit, $categoryId);
    }

    /**
     * Search products
     */
    public function searchProducts($term, $page = 1, $limit = 12)
    {
        return $this->getProducts($page, $limit, null, $term);
    }

    /**
     * Create customer account
     */
    public function createCustomer($data)
    {
        // Check if email already exists
        $stmt = $this->db->prepare("SELECT id FROM customers WHERE email = :email AND company_id = :company_id");
        $stmt->execute([
            ':email' => $data['email'],
            ':company_id' => $this->companyId
        ]);

        if ($stmt->fetch()) {
            return ['success' => false, 'error' => 'Email already registered'];
        }

        // Generate customer code
        $codeStmt = $this->db->prepare("SELECT COUNT(*) as count FROM customers WHERE company_id = :company_id");
        $codeStmt->execute([':company_id' => $this->companyId]);
        $count = $codeStmt->fetch()['count'] + 1;
        $customerCode = 'CUST-' . date('Y') . '-' . str_pad((string)$count, 4, '0', STR_PAD_LEFT);

        // Hash password
        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);

        // Insert customer
        $stmt = $this->db->prepare("
            INSERT INTO customers (
                company_id, customer_code, full_name, email, phone, address, 
                password, is_active, created_by, created_at
            ) VALUES (
                :company_id, :customer_code, :full_name, :email, :phone, :address,
                :password, 1, 0, NOW()
            )
        ");

        $result = $stmt->execute([
            ':company_id' => $this->companyId,
            ':customer_code' => $customerCode,
            ':full_name' => $data['full_name'],
            ':email' => $data['email'],
            ':phone' => $data['phone'] ?? null,
            ':address' => $data['address'] ?? null,
            ':password' => $hashedPassword
        ]);

        if ($result) {
            $customerId = $this->db->lastInsertId();
            return ['success' => true, 'customer_id' => $customerId];
        }

        return ['success' => false, 'error' => 'Failed to create account'];
    }

    /**
     * Login customer
     */
    public function loginCustomer($email, $password)
    {
        $stmt = $this->db->prepare("
            SELECT id, full_name, email, password 
            FROM customers 
            WHERE email = :email 
                AND company_id = :company_id 
                AND is_active = 1
        ");
        $stmt->execute([
            ':email' => $email,
            ':company_id' => $this->companyId
        ]);

        $customer = $stmt->fetch();

        if ($customer && password_verify($password, $customer['password'])) {
            return [
                'success' => true,
                'customer_id' => $customer['id'],
                'full_name' => $customer['full_name'],
                'email' => $customer['email']
            ];
        }

        return ['success' => false, 'error' => 'Invalid email or password'];
    }

    /**
     * Get customer orders
     */
    public function getCustomerOrders($customerId)
    {
        $sql = "
            SELECT 
                o.*,
                si.total_amount,
                si.status as invoice_status
            FROM online_orders o
            JOIN sales_invoices si ON o.invoice_id = si.id
            WHERE o.customer_id = :customer_id 
                AND o.company_id = :company_id
            ORDER BY o.created_at DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':customer_id' => $customerId,
            ':company_id' => $this->companyId
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Get order details
     */
    public function getOrderDetails($orderNumber, $customerId)
    {
        $sql = "
            SELECT 
                o.*,
                si.invoice_number,
                si.total_amount,
                si.subtotal,
                si.tax_amount,
                si.status as invoice_status,
                c.full_name as customer_name,
                c.email as customer_email,
                c.phone as customer_phone
            FROM online_orders o
            JOIN sales_invoices si ON o.invoice_id = si.id
            JOIN customers c ON o.customer_id = c.id
            WHERE o.order_number = :order_number 
                AND o.customer_id = :customer_id
                AND o.company_id = :company_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':order_number' => $orderNumber,
            ':customer_id' => $customerId,
            ':company_id' => $this->companyId
        ]);

        $order = $stmt->fetch();

        if ($order) {
            // Get order items
            $itemSql = "
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
            $itemStmt = $this->db->prepare($itemSql);
            $itemStmt->execute([':invoice_id' => $order['invoice_id']]);
            $order['items'] = $itemStmt->fetchAll();
        }

        return $order;
    }

    /**
     * Create order from cart
     */
    public function createOrder($customerId, $items, $paymentMethod, $shippingAddress)
    {
        try {
            $this->db->beginTransaction();

            // Calculate totals
            $subtotal = 0;
            foreach ($items as $item) {
                $subtotal += $item['price'] * $item['quantity'];
            }

            $shipping = $subtotal >= 50000 ? 0 : 2000;
            $tax = $subtotal * 0.18;
            $total = $subtotal + $shipping + $tax;

            // Create sale invoice
            $invoiceNumber = $this->generateInvoiceNumber();
            $invoiceDate = date('Y-m-d');
            $dueDate = date('Y-m-d', strtotime('+30 days'));

            $invoiceSql = "
                INSERT INTO sales_invoices (
                    company_id, invoice_number, customer_id, invoice_date, due_date, status,
                    subtotal, tax_amount, total_amount, amount_paid, created_by, created_at
                ) VALUES (
                    :company_id, :invoice_number, :customer_id, :invoice_date, :due_date, 'paid',
                    :subtotal, :tax_amount, :total_amount, 0, 0, NOW()
                )
            ";

            $invoiceStmt = $this->db->prepare($invoiceSql);
            $invoiceStmt->execute([
                ':company_id' => $this->companyId,
                ':invoice_number' => $invoiceNumber,
                ':customer_id' => $customerId,
                ':invoice_date' => $invoiceDate,
                ':due_date' => $dueDate,
                ':subtotal' => $subtotal,
                ':tax_amount' => $tax,
                ':total_amount' => $total
            ]);

            $invoiceId = $this->db->lastInsertId();

            // Add invoice items and update inventory
            foreach ($items as $item) {
                $itemSql = "
                    INSERT INTO invoice_items (invoice_id, variant_id, quantity, unit_price, discount_percent, tax_rate)
                    VALUES (:invoice_id, :variant_id, :quantity, :unit_price, 0, 18)
                ";
                $itemStmt = $this->db->prepare($itemSql);
                $itemStmt->execute([
                    ':invoice_id' => $invoiceId,
                    ':variant_id' => $item['id'],
                    ':quantity' => $item['quantity'],
                    ':unit_price' => $item['price']
                ]);

                // Update inventory (reduce stock)
                $updateSql = "
                    UPDATE inventory 
                    SET quantity = quantity - :quantity, 
                        available_quantity = available_quantity - :quantity,
                        last_updated = NOW()
                    WHERE variant_id = :variant_id AND company_id = :company_id
                ";
                $updateStmt = $this->db->prepare($updateSql);
                $updateStmt->execute([
                    ':quantity' => $item['quantity'],
                    ':variant_id' => $item['id'],
                    ':company_id' => $this->companyId
                ]);
            }

            // Create online order record
            $orderNumber = 'ORD-' . date('Ymd') . '-' . str_pad($invoiceId, 6, '0', STR_PAD_LEFT);

            $orderSql = "
                INSERT INTO online_orders (
                    company_id, invoice_id, order_number, customer_id, 
                    shipping_address, payment_method, payment_status, delivery_status, created_at
                ) VALUES (
                    :company_id, :invoice_id, :order_number, :customer_id,
                    :shipping_address, :payment_method, 'pending', 'processing', NOW()
                )
            ";

            $orderStmt = $this->db->prepare($orderSql);
            $orderStmt->execute([
                ':company_id' => $this->companyId,
                ':invoice_id' => $invoiceId,
                ':order_number' => $orderNumber,
                ':customer_id' => $customerId,
                ':shipping_address' => $shippingAddress,
                ':payment_method' => $paymentMethod
            ]);

            $this->db->commit();

            return [
                'success' => true,
                'order_number' => $orderNumber,
                'invoice_id' => $invoiceId,
                'total' => $total
            ];

        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Order creation error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Generate invoice number
     */
    private function generateInvoiceNumber()
    {
        $prefix = 'INV';
        $year = date('Y');
        $month = date('m');

        $sql = "SELECT COUNT(*) as count FROM sales_invoices WHERE invoice_number LIKE :pattern AND company_id = :company_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':pattern' => $prefix . '-' . $year . $month . '%',
            ':company_id' => $this->companyId
        ]);
        $count = $stmt->fetch()['count'] + 1;

        return $prefix . '-' . $year . $month . '-' . str_pad((string)$count, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Get store settings
     */
    public function getStoreSettings()
    {
        $sql = "SELECT setting_key, setting_value FROM store_settings WHERE company_id = :company_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':company_id' => $this->companyId]);
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $defaults = [
            'store_name' => 'SATI Shop',
            'store_email' => 'shop@sati.com',
            'store_phone' => '+250 788 123 456',
            'store_address' => 'Kigali, Rwanda',
            'shipping_cost' => 2000,
            'free_shipping_threshold' => 50000,
            'tax_rate' => 18,
            'currency' => 'RWF',
            'items_per_page' => 12
        ];

        return array_merge($defaults, $settings);
    }
}