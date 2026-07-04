<?php
// models/Variant.php
// ============================================
// VARIANT MODEL WITH COMPANY SUPPORT
// ============================================

require_once __DIR__ . '/BaseModel.php';
require_once __DIR__ . '/Product.php';

class Variant extends BaseModel
{
    protected $table = 'variants';

    /**
     * Constructor - can pass company ID or use session
     */
    public function __construct($companyId = null)
    {
        parent::__construct($companyId);
    }

    /**
     * Verify product belongs to company and category is activated
     */
    protected function verifyProductAccess($productId, $companyId = null)
    {
        $companyId = $companyId ?? $this->companyId;

        if (!$companyId) {
            return false;
        }

        $sql = "
            SELECT 
                p.id,
                p.company_id,
                p.category_id,
                cc.category_id as category_activated
            FROM products p
            LEFT JOIN category_company cc ON p.category_id = cc.category_id AND cc.company_id = :company_id
            WHERE p.id = :product_id AND p.is_active = 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'product_id' => $productId,
            'company_id' => $companyId
        ]);

        $product = $stmt->fetch();

        if (!$product || $product['company_id'] != $companyId) {
            return false;
        }

        return true;
    }

    /**
     * Create new variant (with validation)
     */
    public function create(array $data): int
    {
        // Verify product belongs to company and category is activated
        if (isset($data['product_id'])) {
            if (!$this->verifyProductAccess($data['product_id'], $data['company_id'] ?? $this->companyId)) {
                throw new Exception('Product not found or not accessible for this company');
            }
        }

        // Generate SKU if not provided
        if (empty($data['sku'])) {
            $data['sku'] = $this->generateSku($data['product_id']);
        }

        return parent::create($data);
    }

    /**
     * Update variant (with validation)
     */
    public function update($id, array $data): bool
    {
        // Get current variant
        $current = $this->find($id);
        if (!$current) {
            throw new Exception('Variant not found');
        }

        // Verify product access if product_id is being changed
        if (isset($data['product_id']) && $data['product_id'] != $current['product_id']) {
            if (!$this->verifyProductAccess($data['product_id'], $current['company_id'])) {
                throw new Exception('New product not accessible for this company');
            }
        }

        return parent::update($id, $data);
    }

    /**
     * Generate unique SKU
     */
    public function generateSku($productId): string
    {
        // Get product info
        $stmt = $this->db->prepare("SELECT product_code FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();

        if (!$product) {
            return 'SKU-' . uniqid();
        }

        // Count existing variants for this product
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count FROM variants 
            WHERE product_id = :product_id AND company_id = :company_id
        ");
        $stmt->execute([
            'product_id' => $productId,
            'company_id' => $this->companyId
        ]);
        $count = $stmt->fetch()['count'] + 1;

        return $product['product_code'] . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Get variant with stock information (with company check)
     */
    public function getWithStock($variantId, $warehouseId = null)
    {
        // Verify variant belongs to company
        $sql = "
            SELECT v.id, p.company_id 
            FROM {$this->table} v
            JOIN products p ON v.product_id = p.id
            WHERE v.id = :variant_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['variant_id' => $variantId]);
        $variant = $stmt->fetch();

        if (!$variant || ($this->companyId && $variant['company_id'] != $this->companyId)) {
            return [];
        }

        $sql = "
            SELECT 
                v.id,
                v.product_id,
                v.sku,
                v.barcode,
                v.variant_name,
                v.purchase_price,
                v.selling_price,
                v.wholesale_price,
                v.tax_rate,
                v.reorder_level,
                v.max_stock_level,
                v.is_active,
                v.created_at,
                v.updated_at,
                p.product_name,
                p.product_code,
                p.category_id,
                c.category_name,
                COALESCE(i.quantity, 0) as quantity,
                COALESCE(i.committed_quantity, 0) as committed,
                COALESCE(i.available_quantity, 0) as available,
                i.warehouse_id,
                w.warehouse_name
            FROM {$this->table} v
            JOIN products p ON v.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN inventory i ON v.id = i.variant_id
            LEFT JOIN warehouses w ON i.warehouse_id = w.id
            WHERE v.id = :variant_id
        ";

        if ($warehouseId) {
            $sql .= " AND (i.warehouse_id = :warehouse_id OR i.warehouse_id IS NULL)";
        }

        $stmt = $this->db->prepare($sql);
        $params = ['variant_id' => $variantId];
        if ($warehouseId) {
            $params['warehouse_id'] = $warehouseId;
        }
        $stmt->execute($params);

        $results = $stmt->fetchAll();

        // If no specific warehouse, return all warehouse stock
        if (!$warehouseId && count($results) > 1) {
            return $results;
        }

        return $results[0] ?? [];
    }

    /**
     * Find variant by barcode (company-specific)
     * 
     * @param string $barcode The barcode to search for
     * @return array|false The variant data if found, false otherwise
     */
    public function findByBarcode($barcode)
    {
        if (!$this->companyId) {
            return false;
        }

        $sql = "
        SELECT 
            v.*,
            p.product_name,
            p.product_code,
            p.category_id,
            p.brand,
            p.unit_of_measure,
            p.has_variants,
            c.category_name,
            COALESCE(SUM(i.quantity), 0) as total_stock,
            COALESCE(SUM(i.available_quantity), 0) as available_stock
        FROM {$this->table} v
        JOIN products p ON v.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN inventory i ON v.id = i.variant_id
        WHERE v.barcode = :barcode 
            AND v.is_active = 1 
            AND p.is_active = 1
            AND p.company_id = :company_id
        GROUP BY v.id
        LIMIT 1
    ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'barcode' => $barcode,
            'company_id' => $this->companyId
        ]);

        $result = $stmt->fetch();

        if ($result) {
            // Calculate available stock if not already calculated
            if (!isset($result['available_stock'])) {
                $result['available_stock'] = $result['total_stock'];
            }

            // Get warehouse stock details
            $result['stock_by_warehouse'] = $this->getStockByWarehouse($result['id']);

            // Get variant attributes
            $result['attributes'] = $this->getAttributes($result['id']);

            // Get primary image
            $result['primary_image'] = $this->getPrimaryImage($result['id']);
        }

        return $result;
    }

    /**
     * Find variant by barcode (case-insensitive, with wildcard support)
     * 
     * @param string $barcode The barcode to search for
     * @param bool $partialMatch Whether to allow partial matching
     * @return array|false The variant data if found, false otherwise
     */
    public function findByBarcodePartial($barcode, $partialMatch = false)
    {
        if (!$this->companyId) {
            return false;
        }

        $sql = "
        SELECT 
            v.*,
            p.product_name,
            p.product_code,
            p.category_id,
            p.brand,
            p.unit_of_measure,
            c.category_name,
            COALESCE(SUM(i.quantity), 0) as total_stock,
            COALESCE(SUM(i.available_quantity), 0) as available_stock
        FROM {$this->table} v
        JOIN products p ON v.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN inventory i ON v.id = i.variant_id
        WHERE v.is_active = 1 
            AND p.is_active = 1
            AND p.company_id = :company_id
    ";

        if ($partialMatch) {
            $sql .= " AND v.barcode LIKE :barcode";
            $barcodeParam = "%{$barcode}%";
        } else {
            $sql .= " AND v.barcode = :barcode";
            $barcodeParam = $barcode;
        }

        $sql .= " GROUP BY v.id LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'barcode' => $barcodeParam,
            'company_id' => $this->companyId
        ]);

        $result = $stmt->fetch();

        if ($result) {
            $result['stock_by_warehouse'] = $this->getStockByWarehouse($result['id']);
            $result['attributes'] = $this->getAttributes($result['id']);
            $result['primary_image'] = $this->getPrimaryImage($result['id']);
        }

        return $result;
    }

    /**
     * Get primary image for a variant
     * 
     * @param int $variantId The variant ID
     * @return string|null The image URL or null if no primary image
     */
    public function getPrimaryImage($variantId)
    {
        $sql = "
        SELECT image_url 
        FROM variant_images 
        WHERE variant_id = :variant_id AND is_primary = 1 
        LIMIT 1
    ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['variant_id' => $variantId]);
        $result = $stmt->fetch();

        return $result ? $result['image_url'] : null;
    }

    /**
     * Find multiple variants by barcode (for batch operations)
     * 
     * @param array $barcodes Array of barcodes to search for
     * @return array Array of found variants
     */
    public function findByBarcodes(array $barcodes)
    {
        if (!$this->companyId || empty($barcodes)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($barcodes), '?'));

        $sql = "
        SELECT 
            v.*,
            p.product_name,
            p.product_code,
            p.category_id,
            p.brand,
            c.category_name,
            COALESCE(SUM(i.quantity), 0) as total_stock,
            COALESCE(SUM(i.available_quantity), 0) as available_stock
        FROM {$this->table} v
        JOIN products p ON v.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN inventory i ON v.id = i.variant_id
        WHERE v.barcode IN ({$placeholders})
            AND v.is_active = 1 
            AND p.is_active = 1
            AND p.company_id = ?
        GROUP BY v.id
    ";

        $params = array_merge($barcodes, [$this->companyId]);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $results = $stmt->fetchAll();

        // Add additional data for each result
        foreach ($results as &$result) {
            $result['stock_by_warehouse'] = $this->getStockByWarehouse($result['id']);
            $result['attributes'] = $this->getAttributes($result['id']);
            $result['primary_image'] = $this->getPrimaryImage($result['id']);
        }

        return $results;
    }

    /**
     * Check if barcode exists (for validation)
     * 
     * @param string $barcode The barcode to check
     * @param int|null $excludeVariantId Variant ID to exclude from check (for updates)
     * @return bool True if barcode exists, false otherwise
     */
    public function barcodeExists($barcode, $excludeVariantId = null)
    {
        if (!$this->companyId) {
            return false;
        }

        $sql = "
        SELECT COUNT(*) as count 
        FROM {$this->table} v
        JOIN products p ON v.product_id = p.id
        WHERE v.barcode = :barcode 
            AND p.company_id = :company_id
    ";

        $params = [
            'barcode' => $barcode,
            'company_id' => $this->companyId
        ];

        if ($excludeVariantId) {
            $sql .= " AND v.id != :exclude_id";
            $params['exclude_id'] = $excludeVariantId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();

        return ($result['count'] ?? 0) > 0;
    }

    /**
     * Generate a unique barcode
     * 
     * @return string A unique EAN-13 compatible barcode
     */
    public function generateUniqueBarcode()
    {
        do {
            $barcode = $this->generateBarcode();
        } while ($this->barcodeExists($barcode));

        return $barcode;
    }

    /**
     * Generate EAN-13 barcode
     * 
     * @return string Generated barcode
     */
    protected function generateBarcode()
    {
        $prefix = '200'; // Company prefix (can be configured per company)
        $productCode = str_pad((string) mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT);
        $base = $prefix . $productCode;
        $base = substr($base, 0, 12);

        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $base[$i];
            $multiplier = ($i % 2 === 0) ? 1 : 3;
            $sum += $digit * $multiplier;
        }

        $checkDigit = (10 - ($sum % 10)) % 10;
        return $base . $checkDigit;
    }

    /**
     * Get variant by SKU or barcode (company-specific)
     */
    public function findBySku($sku)
    {
        if (!$this->companyId) {
            return null;
        }

        $sql = "
            SELECT 
                v.*,
                p.product_name,
                p.product_code,
                p.company_id
            FROM {$this->table} v
            JOIN products p ON v.product_id = p.id
            WHERE (v.sku = :sku OR v.barcode = :sku)
                AND p.company_id = :company_id
                AND v.is_active = 1
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'sku' => $sku,
            'company_id' => $this->companyId
        ]);

        return $stmt->fetch();
    }

    /**
     * Get all variants with stock information (company-specific)
     */
    public function getAllWithStock($warehouseId = null)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                v.id,
                v.product_id,
                v.sku,
                v.barcode,
                v.variant_name,
                v.purchase_price,
                v.selling_price,
                v.wholesale_price,
                v.tax_rate,
                v.reorder_level,
                v.max_stock_level,
                v.is_active,
                v.created_at,
                v.updated_at,
                p.product_name,
                p.product_code,
                p.category_id,
                c.category_name,
                COALESCE(i.quantity, 0) as quantity,
                COALESCE(i.committed_quantity, 0) as committed,
                COALESCE(i.available_quantity, 0) as available_stock,
                i.warehouse_id,
                w.warehouse_name
            FROM {$this->table} v
            JOIN products p ON v.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN inventory i ON v.id = i.variant_id
            LEFT JOIN warehouses w ON i.warehouse_id = w.id
            WHERE v.is_active = 1 
                AND p.is_active = 1
                AND p.company_id = :company_id
        ";

        $params = ['company_id' => $this->companyId];

        if ($warehouseId) {
            $sql .= " AND (i.warehouse_id = :warehouse_id OR i.warehouse_id IS NULL)";
            $params['warehouse_id'] = $warehouseId;
        }

        $sql .= " ORDER BY p.product_name, v.variant_name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get variant with full details (with company check)
     */
    public function getWithDetails($variantId)
    {
        if (!$this->companyId) {
            return null;
        }

        $sql = "
            SELECT 
                v.*,
                p.product_name,
                p.product_code,
                p.category_id,
                c.category_name,
                cc.parent_id as category_parent_id
            FROM {$this->table} v
            JOIN products p ON v.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN category_company cc ON p.category_id = cc.category_id AND cc.company_id = :company_id
            WHERE v.id = :variant_id
                AND p.company_id = :p_company_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'variant_id' => $variantId,
            'p_company_id' => $this->companyId,
            'company_id' => $this->companyId
        ]);

        $variant = $stmt->fetch();

        if ($variant) {
            // Get attributes
            $variant['attributes'] = $this->getAttributes($variantId);

            // Get images
            $variant['images'] = $this->getImages($variantId);

            // Get stock across warehouses
            $variant['stock'] = $this->getStockByWarehouse($variantId);

            // Calculate total stock
            $variant['total_stock'] = array_sum(array_column($variant['stock'], 'quantity'));
            $variant['total_committed'] = array_sum(array_column($variant['stock'], 'committed_quantity'));
            $variant['available_stock'] = $variant['total_stock'] - $variant['total_committed'];
        }

        return $variant;
    }

    /**
     * Get stock by warehouse for a variant (with company check)
     */
    public function getStockByWarehouse($variantId)
    {
        if (!$this->companyId) {
            return [];
        }

        // Verify variant belongs to company
        $stmt = $this->db->prepare("
            SELECT p.company_id 
            FROM {$this->table} v
            JOIN products p ON v.product_id = p.id
            WHERE v.id = ?
        ");
        $stmt->execute([$variantId]);
        $product = $stmt->fetch();

        if (!$product || $product['company_id'] != $this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                i.*,
                w.warehouse_name,
                w.warehouse_code,
                l.location_code,
                l.location_name
            FROM inventory i
            JOIN warehouses w ON i.warehouse_id = w.id
            LEFT JOIN locations l ON i.location_id = l.id
            WHERE i.variant_id = :variant_id
                AND w.company_id = :company_id
            ORDER BY w.warehouse_name, l.location_code
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'variant_id' => $variantId,
            'company_id' => $this->companyId
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Search variants (company-specific)
     */
    public function search($term)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                v.*,
                p.product_name,
                p.product_code
            FROM {$this->table} v
            JOIN products p ON v.product_id = p.id
            WHERE (v.sku LIKE :term 
                OR v.barcode LIKE :term 
                OR v.variant_name LIKE :term 
                OR p.product_name LIKE :term)
                AND v.is_active = 1 
                AND p.is_active = 1
                AND p.company_id = :company_id
            ORDER BY p.product_name, v.variant_name 
            LIMIT 50
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'term' => "%{$term}%",
            'company_id' => $this->companyId
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Get variants by price range (company-specific)
     */
    public function getByPriceRange($minPrice, $maxPrice, $priceType = 'selling_price')
    {
        if (!$this->companyId) {
            return [];
        }

        $allowedTypes = ['purchase_price', 'selling_price', 'wholesale_price'];
        $priceType = in_array($priceType, $allowedTypes) ? $priceType : 'selling_price';

        $sql = "
            SELECT 
                v.*,
                p.product_name
            FROM {$this->table} v
            JOIN products p ON v.product_id = p.id
            WHERE v.{$priceType} BETWEEN :min_price AND :max_price
                AND v.is_active = 1
                AND p.is_active = 1
                AND p.company_id = :company_id
            ORDER BY v.{$priceType}
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
            'company_id' => $this->companyId
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Get variant statistics (company-specific)
     */
    public function getStats()
    {
        if (!$this->companyId) {
            return [
                'total_variants' => 0,
                'avg_selling_price' => 0,
                'min_selling_price' => 0,
                'max_selling_price' => 0,
                'products_with_variants' => 0
            ];
        }

        $sql = "
            SELECT 
                COUNT(v.id) as total_variants,
                AVG(v.selling_price) as avg_selling_price,
                MIN(v.selling_price) as min_selling_price,
                MAX(v.selling_price) as max_selling_price,
                COUNT(DISTINCT v.product_id) as products_with_variants
            FROM {$this->table} v
            JOIN products p ON v.product_id = p.id
            WHERE v.is_active = 1
                AND p.is_active = 1
                AND p.company_id = :company_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);

        $result = $stmt->fetch();

        // Round averages
        if ($result) {
            $result['avg_selling_price'] = round($result['avg_selling_price'], 2);
        }

        return $result;
    }

    /**
     * Update stock quantity
     */
    public function updateStock($variantId, $warehouseId, $quantity, $operation = 'set')
    {
        if (!$this->companyId) {
            throw new Exception('Company context required');
        }

        // Verify variant belongs to company
        if (!$this->verifyProductAccess($variantId)) {
            throw new Exception('Variant not accessible');
        }

        try {
            $this->beginTransaction();

            // Get current stock
            $stmt = $this->db->prepare("
                SELECT quantity, committed_quantity 
                FROM inventory 
                WHERE variant_id = :variant_id AND warehouse_id = :warehouse_id
            ");
            $stmt->execute([
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId
            ]);
            $current = $stmt->fetch();

            $newQuantity = 0;
            $changeAmount = 0;

            switch ($operation) {
                case 'add':
                    $changeAmount = $quantity;
                    $newQuantity = ($current ? $current['quantity'] : 0) + $quantity;
                    break;
                case 'subtract':
                    $changeAmount = -$quantity;
                    $newQuantity = ($current ? $current['quantity'] : 0) - $quantity;
                    break;
                case 'set':
                    $changeAmount = $quantity - ($current ? $current['quantity'] : 0);
                    $newQuantity = $quantity;
                    break;
                default:
                    throw new Exception('Invalid operation');
            }

            if ($newQuantity < 0) {
                throw new Exception('Insufficient stock');
            }

            // Update or insert inventory
            if ($current) {
                $stmt = $this->db->prepare("
                    UPDATE inventory 
                    SET quantity = :quantity, last_updated = NOW()
                    WHERE variant_id = :variant_id AND warehouse_id = :warehouse_id
                ");
                $stmt->execute([
                    'quantity' => $newQuantity,
                    'variant_id' => $variantId,
                    'warehouse_id' => $warehouseId
                ]);
            } else {
                $stmt = $this->db->prepare("
                    INSERT INTO inventory (company_id, variant_id, warehouse_id, quantity, last_updated)
                    VALUES (:company_id, :variant_id, :warehouse_id, :quantity, NOW())
                ");
                $stmt->execute([
                    'company_id' => $this->companyId,
                    'variant_id' => $variantId,
                    'warehouse_id' => $warehouseId,
                    'quantity' => $newQuantity
                ]);
            }

            // Create transaction log
            $transactionCode = 'STK-' . date('YmdHis') . '-' . rand(100, 999);
            $stmt = $this->db->prepare("
                INSERT INTO inventory_transactions 
                (company_id, transaction_code, transaction_type, variant_id, warehouse_id, quantity, created_at)
                VALUES (:company_id, :transaction_code, :transaction_type, :variant_id, :warehouse_id, :quantity, NOW())
            ");
            $stmt->execute([
                'company_id' => $this->companyId,
                'transaction_code' => $transactionCode,
                'transaction_type' => 'adjustment',
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'quantity' => $changeAmount
            ]);

            $this->commit();
            return true;

        } catch (Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Get variants by product ID (with company check)
     */
    public function getByProductId($productId)
    {
        if (!$this->companyId) {
            return ['variants_company' => 0];
        }

        // First verify product belongs to company and category is activated
        if (!$this->verifyProductAccess($productId)) {
            return ['variants_access' => 1];
        }

        $sql = "
            SELECT 
                v.id,
                v.product_id,
                v.sku,
                v.barcode,
                v.variant_name,
                v.purchase_price,
                v.selling_price,
                v.wholesale_price,
                v.tax_rate,
                v.reorder_level,
                v.max_stock_level,
                v.is_active,
                v.created_at,
                v.updated_at,
                GROUP_CONCAT(
                    DISTINCT CONCAT(va.attribute_name, ': ', va.attribute_value) 
                    SEPARATOR ', '
                ) as attributes_list,
                (
                    SELECT image_url 
                    FROM variant_images 
                    WHERE variant_id = v.id AND is_primary = 1 
                    LIMIT 1
                ) as primary_image,
                COALESCE(SUM(i.quantity), 0) as total_stock,
                COALESCE(SUM(i.committed_quantity), 0) as total_committed
            FROM {$this->table} v
            LEFT JOIN variant_attributes va ON v.id = va.variant_id
            LEFT JOIN inventory i ON v.id = i.variant_id
            WHERE v.product_id = :product_id 
                AND v.is_active = 1
            GROUP BY v.id
            ORDER BY v.created_at ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['product_id' => $productId]);
        $variants = $stmt->fetchAll();

        // Calculate available stock for each variant
        foreach ($variants as &$variant) {
            $variant['available_stock'] = $variant['total_stock'] - $variant['total_committed'];
        }

        return $variants;
    }

    /**
     * Get low stock variants (company-specific)
     */
    public function getLowStock()
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                v.id,
                v.sku,
                v.variant_name,
                v.reorder_level,
                p.product_name,
                p.product_code,
                COALESCE(SUM(i.quantity), 0) as total_stock,
                w.warehouse_name
            FROM {$this->table} v
            JOIN products p ON v.product_id = p.id
            LEFT JOIN inventory i ON v.id = i.variant_id
            LEFT JOIN warehouses w ON i.warehouse_id = w.id
            WHERE v.is_active = 1
                AND p.is_active = 1
                AND p.company_id = :company_id
            GROUP BY v.id, w.id
            HAVING total_stock <= v.reorder_level AND total_stock > 0
            ORDER BY (total_stock / v.reorder_level) ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['company_id' => $this->companyId]);

        return $stmt->fetchAll();
    }

    /**
     * Get attributes for a variant
     */
    public function getAttributes($variantId)
    {
        $sql = "
SELECT * FROM variant_attributes
WHERE variant_id = :variant_id
ORDER BY display_order
";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['variant_id' => $variantId]);
        return $stmt->fetchAll();
    }

    /**
     * Add attribute to variant
     */
    public function addAttribute($variantId, $attributeName, $attributeValue, $displayOrder = 0): bool
    {
        $sql = "
INSERT INTO variant_attributes
(variant_id, attribute_name, attribute_value, display_order)
VALUES (:variant_id, :attribute_name, :attribute_value, :display_order)
";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'variant_id' => $variantId,
            'attribute_name' => $attributeName,
            'attribute_value' => $attributeValue,
            'display_order' => $displayOrder
        ]);
    }


    /**
     * Remove all attributes from variant
     */
    public function clearAttributes($variantId): bool
    {
        $sql = "DELETE FROM variant_attributes WHERE variant_id = :variant_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['variant_id' => $variantId]);
    }

    /**
     * Remove image from variant
     */
    public function removeImage($imageId): bool
    {
        $sql = "DELETE FROM variant_images WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $imageId]);
    }


    /**
     * Add image to variant
     */
    public function addImage($variantId, $imageUrl, $isPrimary = false, $caption = null)
    {
        // If this is primary, unset other primary images
        if ($isPrimary) {
            $sql = "UPDATE variant_images SET is_primary = 0 WHERE variant_id = :variant_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['variant_id' => $variantId]);
        }

        // Get sort order
        $sql = "SELECT COALESCE(MAX(sort_order), -1) + 1 as next_order FROM variant_images WHERE variant_id = :variant_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['variant_id' => $variantId]);
        $result = $stmt->fetch();
        $sortOrder = $result['next_order'] ?? 0;

        // Insert new image
        $sql = "
            INSERT INTO variant_images 
            (variant_id, image_url, is_primary, sort_order, caption)
            VALUES (:variant_id, :image_url, :is_primary, :sort_order, :caption)
        ";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'variant_id' => $variantId,
            'image_url' => $imageUrl,
            'is_primary' => $isPrimary ? 1 : 0,
            'sort_order' => $sortOrder,
            'caption' => $caption
        ]);
    }

    /**
     * Get variant images
     */
    public function getImages($variantId)
    {
        $sql = "
            SELECT * FROM variant_images 
            WHERE variant_id = :variant_id 
            ORDER BY is_primary DESC, sort_order ASC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['variant_id' => $variantId]);
        return $stmt->fetchAll();
    }


    /**
     * Set primary image
     */
    public function setPrimaryImage($variantId, $imageId): bool
    {
        try {
            $this->beginTransaction();

            // Unset all primary
            $sql1 = "UPDATE variant_images SET is_primary = 0 WHERE variant_id = :variant_id";
            $stmt1 = $this->db->prepare($sql1);
            $stmt1->execute(['variant_id' => $variantId]);

            // Set new primary
            $sql2 = "UPDATE variant_images SET is_primary = 1 WHERE id = :id AND variant_id = :variant_id";
            $stmt2 = $this->db->prepare($sql2);
            $result = $stmt2->execute([
                'id' => $imageId,
                'variant_id' => $variantId
            ]);

            $this->commit();
            return $result;

        } catch (Exception $e) {
            $this->rollback();
            error_log("Set primary image error: " . $e->getMessage());
            throw $e;
        }
    }


}