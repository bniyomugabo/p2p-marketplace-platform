<?php
// models/Inventory.php
// ============================================
// INVENTORY MODEL FOR MULTI-COMPANY STRUCTURE
// ============================================

require_once __DIR__ . '/BaseModel.php';

class Inventory extends BaseModel
{
    protected $table = 'inventory';

    /**
     * Constructor - can pass company ID or use session
     */
    public function __construct($companyId = null)
    {
        parent::__construct($companyId);
    }

    /**
     * Get stock summary
     */
    public function getStockSummary()
    {
        if (!$this->companyId) {
            return [
                'total_stock' => 0,
                'available_stock' => 0,
                'stock_value' => 0,
                'unique_products' => 0,
                'warehouses_used' => 0,
                'low_stock' => 0,
                'out_of_stock' => 0
            ];
        }

        $sql = "
            SELECT 
                COALESCE(SUM(i.quantity), 0) as total_stock,
                COALESCE(SUM(i.available_quantity), 0) as available_stock,
                COALESCE(SUM(i.quantity * COALESCE(i.avg_landed_cost, v.purchase_price, 0)), 0) as stock_value,
                COUNT(DISTINCT i.variant_id) as unique_products,
                COUNT(DISTINCT i.warehouse_id) as warehouses_used,
                SUM(CASE WHEN i.available_quantity <= v.reorder_level AND i.available_quantity > 0 THEN 1 ELSE 0 END) as low_stock,
                SUM(CASE WHEN i.available_quantity <= 0 THEN 1 ELSE 0 END) as out_of_stock
            FROM inventory i
            JOIN variants v ON i.variant_id = v.id
            WHERE i.company_id = :company_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch();

        return $result ?: [
            'total_stock' => 0,
            'available_stock' => 0,
            'stock_value' => 0,
            'unique_products' => 0,
            'warehouses_used' => 0,
            'low_stock' => 0,
            'out_of_stock' => 0
        ];
    }

    /**
     * Get low stock items
     */
    public function getLowStock($limit = 10)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                p.product_name,
                p.product_code,
                v.id as variant_id,
                v.sku,
                v.variant_name,
                v.reorder_level,
                i.quantity,
                i.available_quantity,
                w.warehouse_name,
                w.id as warehouse_id
            FROM inventory i
            JOIN variants v ON i.variant_id = v.id
            JOIN products p ON v.product_id = p.id
            JOIN warehouses w ON i.warehouse_id = w.id
            WHERE i.available_quantity <= v.reorder_level 
                AND i.available_quantity > 0
                AND i.company_id = :company_id
            ORDER BY (i.available_quantity / v.reorder_level) ASC
            LIMIT :limit
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get turnover rate
     */
    public function getTurnoverRate($days = 30)
    {
        if (!$this->companyId) {
            return 0;
        }

        $sql = "
            SELECT 
                COALESCE(SUM(CASE WHEN it.transaction_type = 'sale' THEN ABS(it.quantity) ELSE 0 END), 0) as sold_qty,
                COALESCE(AVG(i.quantity), 1) as avg_stock
            FROM inventory i
            LEFT JOIN inventory_transactions it ON i.variant_id = it.variant_id 
                AND i.company_id = it.company_id
                AND it.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
            WHERE i.company_id = :company_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch();

        if ($result && $result['avg_stock'] > 0) {
            return $result['sold_qty'] / $result['avg_stock'];
        }
        return 0;
    }

    /**
     * Update stock after transaction
     */
    public function updateStock($variantId, $warehouseId, $quantity, $unitCost = null, $locationId = null, $userId = null)
    {
        if (!$this->companyId) {
            throw new Exception('Company context required for stock update');
        }

        // Check if we're already in a transaction
        $inTransaction = $this->db->inTransaction();

        try {
            if (!$inTransaction) {
                $this->db->beginTransaction();
            }

            // Check if inventory record exists
            $sql = "SELECT * FROM inventory 
                    WHERE variant_id = :variant_id 
                    AND warehouse_id = :warehouse_id 
                    AND company_id = :company_id";
            $params = [
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'company_id' => $this->companyId
            ];

            if ($locationId) {
                $sql .= " AND location_id = :location_id";
                $params['location_id'] = $locationId;
            } else {
                $sql .= " AND location_id IS NULL";
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $existing = $stmt->fetch();

            // Get variant purchase price for cost calculation
            $variantStmt = $this->db->prepare("SELECT purchase_price FROM variants WHERE id = ?");
            $variantStmt->execute([$variantId]);
            $variant = $variantStmt->fetch();
            $defaultCost = $variant ? $variant['purchase_price'] : 0;

            if ($existing) {
                // Update existing
                $newQuantity = $existing['quantity'] + $quantity;

                if ($newQuantity < 0) {
                    throw new Exception('Insufficient stock. Cannot reduce below zero.');
                }

                // Update average landed cost if incoming stock
                $currentAvgCost = $existing['avg_landed_cost'] ?: $defaultCost;
                $costToUse = $unitCost ?: $currentAvgCost;

                if ($quantity > 0 && $costToUse > 0) {
                    $totalValue = ($existing['quantity'] * $currentAvgCost) + ($quantity * $costToUse);
                    $avgCost = $newQuantity > 0 ? $totalValue / $newQuantity : $costToUse;
                } else {
                    $avgCost = $currentAvgCost;
                }

                $sql = "UPDATE inventory 
                        SET quantity = :quantity,
                            avg_landed_cost = :avg_cost,
                            last_purchase_price = :last_price,
                            last_updated = NOW()
                        WHERE id = :id 
                        AND company_id = :company_id";

                $updateStmt = $this->db->prepare($sql);
                $updateStmt->execute([
                    'quantity' => $newQuantity,
                    'avg_cost' => $avgCost,
                    'last_price' => $unitCost ?: $existing['last_purchase_price'],
                    'id' => $existing['id'],
                    'company_id' => $this->companyId
                ]);
            } else {
                // Create new inventory record
                if ($quantity < 0) {
                    throw new Exception('Cannot create negative stock record. Please add stock first.');
                }

                $sql = "INSERT INTO inventory 
                        (company_id, variant_id, warehouse_id, location_id, quantity, 
                         avg_landed_cost, last_purchase_price, last_updated)
                        VALUES (:company_id, :variant_id, :warehouse_id, :location_id, :quantity,
                                :avg_cost, :last_price, NOW())";

                $insertStmt = $this->db->prepare($sql);
                $insertStmt->execute([
                    'company_id' => $this->companyId,
                    'variant_id' => $variantId,
                    'warehouse_id' => $warehouseId,
                    'location_id' => $locationId,
                    'quantity' => $quantity,
                    'avg_cost' => $unitCost ?: $defaultCost,
                    'last_price' => $unitCost ?: $defaultCost
                ]);
            }

            // Create transaction record
            $transactionCode = 'TRX-' . date('YmdHis') . '-' . rand(100, 999);

            // Determine transaction type
            $transactionType = 'adjustment';
            if ($quantity > 0) {
                $transactionType = 'purchase';
            } elseif ($quantity < 0) {
                $transactionType = 'sale';
            }

            $sql = "INSERT INTO inventory_transactions 
                    (company_id, transaction_code, transaction_type, variant_id, warehouse_id, 
                     location_id, quantity, unit_cost, created_by, created_at)
                    VALUES (:company_id, :code, :type, :variant_id, :warehouse_id,
                            :location_id, :quantity, :unit_cost, :user_id, NOW())";

            $transStmt = $this->db->prepare($sql);
            $transStmt->execute([
                'company_id' => $this->companyId,
                'code' => $transactionCode,
                'type' => $transactionType,
                'variant_id' => $variantId,
                'warehouse_id' => $warehouseId,
                'location_id' => $locationId,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'user_id' => $userId ?: 1
            ]);

            // Only commit if we started the transaction
            if (!$inTransaction) {
                $this->db->commit();
            }
            return true;

        } catch (Exception $e) {
            // Only rollback if we started the transaction
            if (!$inTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("Stock update error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get available quantity for a variant
     */
    public function getAvailableQuantity($variantId, $warehouseId, $locationId = null)
    {
        if (!$this->companyId) {
            return 0;
        }

        $sql = "SELECT available_quantity FROM inventory 
            WHERE variant_id = :variant_id 
            AND warehouse_id = :warehouse_id 
            AND company_id = :company_id";
        $params = [
            'variant_id' => $variantId,
            'warehouse_id' => $warehouseId,
            'company_id' => $this->companyId
        ];

        if ($locationId) {
            $sql .= " AND location_id = :location_id";
            $params['location_id'] = $locationId;
        } else {
            $sql .= " AND location_id IS NULL";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();

        return $result ? (float) $result['available_quantity'] : 0;
    }

    /**
     * Get stock list with filters
     */
    public function getStockList($warehouseId = null, $locationId = null)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                i.*,
                v.sku,
                v.variant_name,
                v.selling_price,
                v.purchase_price,
                v.reorder_level,
                v.product_id,
                p.product_name,
                p.product_code,
                w.warehouse_name,
                l.location_code,
                l.location_name
            FROM inventory i
            JOIN variants v ON i.variant_id = v.id
            JOIN products p ON v.product_id = p.id
            JOIN warehouses w ON i.warehouse_id = w.id
            LEFT JOIN locations l ON i.location_id = l.id
            WHERE i.company_id = :company_id
        ";

        $params = ['company_id' => $this->companyId];

        if ($warehouseId) {
            $sql .= " AND i.warehouse_id = :warehouse_id";
            $params['warehouse_id'] = $warehouseId;
        }

        if ($locationId) {
            $sql .= " AND i.location_id = :location_id";
            $params['location_id'] = $locationId;
        }

        $sql .= " ORDER BY p.product_name, v.variant_name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get inventory movements with filters (overriding for pagination)
     */
    public function getMovements($variantId = null, $warehouseId = null, $limit = 100, $offset = 0)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                it.*,
                v.sku,
                v.variant_name,
                p.product_name,
                p.id as product_id,
                w.warehouse_name,
                w.warehouse_code,
                l.location_code,
                u.full_name as created_by_name,
                CONCAT(p.product_name, ' - ', COALESCE(v.variant_name, 'Standard')) as product_display
            FROM inventory_transactions it
            JOIN variants v ON it.variant_id = v.id
            JOIN products p ON v.product_id = p.id
            JOIN warehouses w ON it.warehouse_id = w.id
            LEFT JOIN locations l ON it.location_id = l.id
            LEFT JOIN users u ON it.created_by = u.id
            WHERE it.company_id = :company_id
        ";

        $params = ['company_id' => $this->companyId];

        if ($variantId) {
            $sql .= " AND it.variant_id = :variant_id";
            $params['variant_id'] = $variantId;
        }

        if ($warehouseId) {
            $sql .= " AND it.warehouse_id = :warehouse_id";
            $params['warehouse_id'] = $warehouseId;
        }

        $sql .= " ORDER BY it.created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':company_id', $this->companyId, PDO::PARAM_INT);

        if ($variantId) {
            $stmt->bindValue(':variant_id', $variantId, PDO::PARAM_INT);
        }
        if ($warehouseId) {
            $stmt->bindValue(':warehouse_id', $warehouseId, PDO::PARAM_INT);
        }

        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get movements count for pagination
     */
    public function getMovementsCount($variantId = null, $warehouseId = null)
    {
        if (!$this->companyId) {
            return 0;
        }

        $sql = "
            SELECT COUNT(*) as count
            FROM inventory_transactions it
            WHERE it.company_id = :company_id
        ";

        $params = ['company_id' => $this->companyId];

        if ($variantId) {
            $sql .= " AND it.variant_id = :variant_id";
            $params['variant_id'] = $variantId;
        }

        if ($warehouseId) {
            $sql .= " AND it.warehouse_id = :warehouse_id";
            $params['warehouse_id'] = $warehouseId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ? (int) $result['count'] : 0;
    }

    /**
     * Transfer stock between warehouses/locations
     * 
     * @param int $fromWarehouseId Source warehouse ID
     * @param int $toWarehouseId Destination warehouse ID
     * @param int $variantId Variant ID to transfer
     * @param float $quantity Quantity to transfer
     * @param int|null $fromLocationId Source location ID (optional)
     * @param int|null $toLocationId Destination location ID (optional)
     * @param int|null $userId User performing the transfer
     * @return bool True on success
     * @throws Exception On failure
     */
    public function transferStock($fromWarehouseId, $toWarehouseId, $variantId, $quantity, $fromLocationId = null, $toLocationId = null, $userId = null)
    {
        if (!$this->companyId) {
            throw new Exception('Company context required for stock transfer');
        }

        // Validate quantity
        if ($quantity <= 0) {
            throw new Exception('Transfer quantity must be greater than zero');
        }

        // Check if we're already in a transaction
        $inTransaction = $this->db->inTransaction();

        try {
            if (!$inTransaction) {
                $this->db->beginTransaction();
            }

            // Verify source warehouse belongs to this company
            $stmt = $this->db->prepare("SELECT id FROM warehouses WHERE id = :id AND company_id = :company_id");
            $stmt->execute(['id' => $fromWarehouseId, 'company_id' => $this->companyId]);
            if (!$stmt->fetch()) {
                throw new Exception("Source warehouse not found or does not belong to this company");
            }

            // Verify destination warehouse belongs to this company
            $stmt = $this->db->prepare("SELECT id FROM warehouses WHERE id = :id AND company_id = :company_id");
            $stmt->execute(['id' => $toWarehouseId, 'company_id' => $this->companyId]);
            if (!$stmt->fetch()) {
                throw new Exception("Destination warehouse not found or does not belong to this company");
            }

            // Get available quantity at source
            $available = $this->getAvailableQuantity($variantId, $fromWarehouseId, $fromLocationId);
            if ($available < $quantity) {
                throw new Exception("Insufficient stock at source. Available: {$available}, Requested: {$quantity}");
            }

            // Get variant info for cost calculation
            $stmt = $this->db->prepare("SELECT purchase_price, avg_landed_cost FROM variants WHERE id = :id");
            $stmt->execute(['id' => $variantId]);
            $variant = $stmt->fetch();
            $unitCost = $variant ? ($variant['avg_landed_cost'] ?: $variant['purchase_price']) : 0;

            // Remove from source
            $this->updateStock(
                $variantId,
                $fromWarehouseId,
                -$quantity,
                null,
                $fromLocationId,
                $userId
            );

            // Add to destination
            $this->updateStock(
                $variantId,
                $toWarehouseId,
                $quantity,
                $unitCost,
                $toLocationId,
                $userId
            );

            // Only commit if we started the transaction
            if (!$inTransaction) {
                $this->db->commit();
            }

            return true;

        } catch (Exception $e) {
            // Only rollback if we started the transaction
            if (!$inTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("Stock transfer error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Transfer multiple items in a single transaction
     * 
     * @param array $items Array of transfer items with keys: variant_id, quantity, from_location_id, to_location_id
     * @param int $fromWarehouseId Source warehouse ID
     * @param int $toWarehouseId Destination warehouse ID
     * @param int|null $userId User performing the transfer
     * @param string|null $notes Transfer notes
     * @return array Result with success status and details
     */
    public function transferMultipleItems($fromWarehouseId, $toWarehouseId, $items, $userId = null, $notes = null)
    {
        if (!$this->companyId) {
            throw new Exception('Company context required for stock transfer');
        }

        if (empty($items)) {
            throw new Exception('No items to transfer');
        }

        $results = [
            'success' => true,
            'transferred' => [],
            'failed' => [],
            'total_items' => count($items),
            'success_count' => 0,
            'failed_count' => 0
        ];

        $inTransaction = $this->db->inTransaction();

        try {
            if (!$inTransaction) {
                $this->db->beginTransaction();
            }

            // Verify warehouses belong to this company
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM warehouses WHERE id IN (:from_id, :to_id) AND company_id = :company_id");
            $stmt->execute([
                'from_id' => $fromWarehouseId,
                'to_id' => $toWarehouseId,
                'company_id' => $this->companyId
            ]);
            $result = $stmt->fetch();
            if ($result['count'] != 2) {
                throw new Exception("One or both warehouses do not belong to this company");
            }

            // Generate transfer number for logging
            $transferNumber = 'TRF-' . date('YmdHis') . '-' . rand(1000, 9999);

            foreach ($items as $index => $item) {
                $variantId = (int) $item['variant_id'];
                $quantity = (float) $item['quantity'];
                $fromLocationId = !empty($item['from_location_id']) ? (int) $item['from_location_id'] : null;
                $toLocationId = !empty($item['to_location_id']) ? (int) $item['to_location_id'] : null;

                try {
                    // Validate quantity
                    if ($quantity <= 0) {
                        throw new Exception("Quantity must be greater than zero");
                    }

                    // Get variant info
                    $stmt = $this->db->prepare("SELECT sku FROM variants WHERE id = :id");
                    $stmt->execute(['id' => $variantId]);
                    $variant = $stmt->fetch();
                    if (!$variant) {
                        throw new Exception("Variant not found");
                    }

                    // Get available quantity at source
                    $available = $this->getAvailableQuantity($variantId, $fromWarehouseId, $fromLocationId);
                    if ($available < $quantity) {
                        throw new Exception("Insufficient stock for SKU: {$variant['sku']}. Available: {$available}, Requested: {$quantity}");
                    }

                    // Get variant cost
                    $stmt = $this->db->prepare("SELECT purchase_price, avg_landed_cost FROM variants WHERE id = :id");
                    $stmt->execute(['id' => $variantId]);
                    $variantCost = $stmt->fetch();
                    $unitCost = $variantCost ? ($variantCost['avg_landed_cost'] ?: $variantCost['purchase_price']) : 0;

                    // Remove from source
                    $this->updateStock(
                        $variantId,
                        $fromWarehouseId,
                        -$quantity,
                        null,
                        $fromLocationId,
                        $userId
                    );

                    // Add to destination
                    $this->updateStock(
                        $variantId,
                        $toWarehouseId,
                        $quantity,
                        $unitCost,
                        $toLocationId,
                        $userId
                    );

                    $results['transferred'][] = [
                        'variant_id' => $variantId,
                        'sku' => $variant['sku'],
                        'quantity' => $quantity,
                        'from_location_id' => $fromLocationId,
                        'to_location_id' => $toLocationId
                    ];
                    $results['success_count']++;

                    // Log to stock_transfers table if it exists
                    try {
                        $logStmt = $this->db->prepare("
                        INSERT INTO stock_transfers 
                        (company_id, transfer_number, from_warehouse_id, to_warehouse_id, 
                         from_location_id, to_location_id, variant_id, quantity, notes, created_by, created_at)
                        VALUES 
                        (:company_id, :transfer_number, :from_warehouse, :to_warehouse,
                         :from_location, :to_location, :variant_id, :quantity, :notes, :created_by, NOW())
                    ");
                        $logStmt->execute([
                            'company_id' => $this->companyId,
                            'transfer_number' => $transferNumber . '_' . ($index + 1),
                            'from_warehouse' => $fromWarehouseId,
                            'to_warehouse' => $toWarehouseId,
                            'from_location' => $fromLocationId,
                            'to_location' => $toLocationId,
                            'variant_id' => $variantId,
                            'quantity' => $quantity,
                            'notes' => $notes,
                            'created_by' => $userId ?: 1
                        ]);
                    } catch (Exception $e) {
                        // Table might not exist, just log and continue
                        error_log("Could not log to stock_transfers table: " . $e->getMessage());
                    }

                } catch (Exception $e) {
                    $results['failed'][] = [
                        'variant_id' => $variantId,
                        'error' => $e->getMessage()
                    ];
                    $results['failed_count']++;
                    $results['success'] = false;
                }
            }

            if ($results['success_count'] === 0) {
                throw new Exception('No items were successfully transferred');
            }

            // Only commit if we started the transaction
            if (!$inTransaction) {
                $this->db->commit();
            }

            return $results;

        } catch (Exception $e) {
            // Only rollback if we started the transaction
            if (!$inTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("Multiple stock transfer error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get transfer history for a variant
     * 
     * @param int $variantId Variant ID
     * @param int $limit Limit results
     * @return array Transfer history
     */
    public function getTransferHistory($variantId, $limit = 50)
    {
        if (!$this->companyId) {
            return [];
        }

        try {
            $sql = "
            SELECT 
                t.*,
                w1.warehouse_name as from_warehouse_name,
                w2.warehouse_name as to_warehouse_name,
                l1.location_code as from_location_code,
                l2.location_code as to_location_code,
                u.full_name as created_by_name
            FROM stock_transfers t
            LEFT JOIN warehouses w1 ON t.from_warehouse_id = w1.id
            LEFT JOIN warehouses w2 ON t.to_warehouse_id = w2.id
            LEFT JOIN locations l1 ON t.from_location_id = l1.id
            LEFT JOIN locations l2 ON t.to_location_id = l2.id
            LEFT JOIN users u ON t.created_by = u.id
            WHERE t.variant_id = :variant_id AND t.company_id = :company_id
            ORDER BY t.created_at DESC
            LIMIT :limit
        ";

            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':variant_id', $variantId, PDO::PARAM_INT);
            $stmt->bindValue(':company_id', $this->companyId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();

        } catch (Exception $e) {
            // Table might not exist
            error_log("Could not get transfer history: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Validate transfer batch before processing
     * 
     * @param int $fromWarehouseId Source warehouse ID
     * @param int $toWarehouseId Destination warehouse ID
     * @param array $items Items to validate
     * @return array Validation results with availability info
     */
    public function validateTransferBatch($fromWarehouseId, $toWarehouseId, $items)
    {
        if (!$this->companyId) {
            return ['valid' => false, 'error' => 'Company context required'];
        }

        $results = [
            'valid' => true,
            'items' => [],
            'errors' => []
        ];

        foreach ($items as $item) {
            $variantId = (int) $item['variant_id'];
            $quantity = (float) $item['quantity'];
            $fromLocationId = !empty($item['from_location_id']) ? (int) $item['from_location_id'] : null;

            // Get variant info
            $stmt = $this->db->prepare("
            SELECT v.sku, v.variant_name, p.product_name 
            FROM variants v
            JOIN products p ON v.product_id = p.id
            WHERE v.id = :id AND p.company_id = :company_id
        ");
            $stmt->execute(['id' => $variantId, 'company_id' => $this->companyId]);
            $variant = $stmt->fetch();

            if (!$variant) {
                $results['valid'] = false;
                $results['errors'][] = "Variant ID {$variantId} not found";
                continue;
            }

            $available = $this->getAvailableQuantity($variantId, $fromWarehouseId, $fromLocationId);

            $itemResult = [
                'variant_id' => $variantId,
                'sku' => $variant['sku'],
                'product_name' => $variant['product_name'],
                'variant_name' => $variant['variant_name'],
                'requested_quantity' => $quantity,
                'available_quantity' => $available,
                'sufficient' => $available >= $quantity,
                'shortage' => $available < $quantity ? $quantity - $available : 0
            ];

            if (!$itemResult['sufficient']) {
                $results['valid'] = false;
                $results['errors'][] = "Insufficient stock for {$variant['product_name']} - {$variant['sku']}. Available: {$available}, Requested: {$quantity}";
            }

            $results['items'][] = $itemResult;
        }

        return $results;
    }

    /**
     * Get movements by date range
     */
    public function getMovementsByDateRange($startDate, $endDate, $warehouseId = null)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                it.*,
                v.sku,
                v.variant_name,
                p.product_name,
                w.warehouse_name,
                w.warehouse_code,
                u.full_name as created_by_name
            FROM inventory_transactions it
            JOIN variants v ON it.variant_id = v.id
            JOIN products p ON v.product_id = p.id
            JOIN warehouses w ON it.warehouse_id = w.id
            LEFT JOIN users u ON it.created_by = u.id
            WHERE it.company_id = :company_id 
                AND DATE(it.created_at) BETWEEN :start_date AND :end_date
        ";

        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'company_id' => $this->companyId
        ];

        if ($warehouseId) {
            $sql .= " AND it.warehouse_id = :warehouse_id";
            $params['warehouse_id'] = $warehouseId;
        }

        $sql .= " ORDER BY it.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Get movements summary by type
     */
    public function getMovementsSummary($days = 30)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                transaction_type,
                COUNT(*) as transaction_count,
                SUM(ABS(quantity)) as total_quantity,
                SUM(ABS(quantity) * COALESCE(unit_cost, 0)) as total_value
            FROM inventory_transactions
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                AND company_id = :company_id
            GROUP BY transaction_type
            ORDER BY transaction_count DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':days', $days, PDO::PARAM_INT);
        $stmt->bindValue(':company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    /**
     * Get stock aging report - shows items that haven't moved for a specified period
     * 
     * @param int $days Number of days to consider for aging (default: 90)
     * @param int|null $warehouseId Optional warehouse filter
     * @return array List of stock items with aging information
     */
    public function getStockAging($days = 90, $warehouseId = null)
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
        SELECT 
            p.product_name,
            p.id as product_id,
            v.id as variant_id,
            v.sku,
            v.variant_name,
            v.purchase_price,
            i.quantity,
            i.avg_landed_cost,
            i.warehouse_id,
            w.warehouse_name,
            w.warehouse_code,
            MAX(it.created_at) as last_movement_date,
            DATEDIFF(NOW(), MAX(it.created_at)) as days_since_last_movement,
            CASE 
                WHEN MAX(it.created_at) IS NULL THEN 'Dead Stock'
                WHEN DATEDIFF(NOW(), MAX(it.created_at)) <= 30 THEN 'Fast Moving'
                WHEN DATEDIFF(NOW(), MAX(it.created_at)) <= 60 THEN 'Normal'
                WHEN DATEDIFF(NOW(), MAX(it.created_at)) <= 90 THEN 'Slow Moving'
                ELSE 'Dead Stock'
            END as stock_status,
            COALESCE(i.quantity * COALESCE(i.avg_landed_cost, v.purchase_price, 0), 0) as stock_value
        FROM inventory i
        JOIN variants v ON i.variant_id = v.id
        JOIN products p ON v.product_id = p.id
        JOIN warehouses w ON i.warehouse_id = w.id
        LEFT JOIN inventory_transactions it ON v.id = it.variant_id 
            AND it.company_id = i.company_id
        WHERE i.quantity > 0
            AND i.company_id = :p_1_company_id
            AND p.company_id = :p_2_company_id
            AND w.company_id = :p_3_company_id
    ";

        $params = [
            ':p_1_company_id' => $this->companyId,
            ':p_2_company_id' => $this->companyId,
            ':p_3_company_id' => $this->companyId
        ];

        if ($warehouseId) {
            $sql .= " AND i.warehouse_id = :warehouse_id";
            $params[':warehouse_id'] = $warehouseId;
        }

        $sql .= "
        GROUP BY i.id, v.id, p.id, w.id
        HAVING days_since_last_movement >= :days OR days_since_last_movement IS NULL
        ORDER BY days_since_last_movement DESC, stock_value DESC
    ";

        $params[':days'] = $days;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $results = $stmt->fetchAll();

        // Format dates and calculate totals
        foreach ($results as &$item) {
            $item['total_value'] = $item['stock_value'];
            // Format last movement date
            if ($item['last_movement_date']) {
                $item['last_movement_formatted'] = date('d/m/Y', strtotime($item['last_movement_date']));
            } else {
                $item['last_movement_formatted'] = 'Never';
                $item['days_since_last_movement'] = $days;
            }
        }

        return $results;
    }
    /**
     * Get stock aging summary statistics
     * 
     * @param int $days Number of days to consider for aging (default: 90)
     * @param int|null $warehouseId Optional warehouse filter
     * @return array Summary statistics of stock aging
     */
    public function getStockAgingSummary($days = 90, $warehouseId = null)
    {
        if (!$this->companyId) {
            return [
                'fast_moving' => ['count' => 0, 'value' => 0, 'quantity' => 0],
                'normal' => ['count' => 0, 'value' => 0, 'quantity' => 0],
                'slow_moving' => ['count' => 0, 'value' => 0, 'quantity' => 0],
                'dead_stock' => ['count' => 0, 'value' => 0, 'quantity' => 0],
                'total_count' => 0,
                'total_value' => 0,
                'total_quantity' => 0
            ];
        }

        $sql = "
        SELECT 
            CASE 
                WHEN MAX(it.created_at) IS NULL THEN 'Dead Stock'
                WHEN DATEDIFF(NOW(), MAX(it.created_at)) <= 30 THEN 'Fast Moving'
                WHEN DATEDIFF(NOW(), MAX(it.created_at)) <= 60 THEN 'Normal'
                WHEN DATEDIFF(NOW(), MAX(it.created_at)) <= 90 THEN 'Slow Moving'
                ELSE 'Dead Stock'
            END as stock_status,
            COUNT(DISTINCT i.id) as item_count,
            SUM(i.quantity) as total_quantity,
            SUM(i.quantity * COALESCE(i.avg_landed_cost, v.purchase_price, 0)) as total_value
        FROM inventory i
        JOIN variants v ON i.variant_id = v.id
        JOIN products p ON v.product_id = p.id
        LEFT JOIN inventory_transactions it ON v.id = it.variant_id 
            AND it.company_id = i.company_id
        WHERE i.quantity > 0
            AND i.company_id = :p_1_company_id
            AND p.company_id = :p_2_company_id
    ";

        $params = [
            ':p_1_company_id' => $this->companyId,
            ':p_2_company_id' => $this->companyId
        ];

        if ($warehouseId) {
            $sql .= " AND i.warehouse_id = :warehouse_id";
            $params[':warehouse_id'] = $warehouseId;
        }

        $sql .= "
        GROUP BY i.id
    ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();

        // Initialize summary array
        $summary = [
            'fast_moving' => ['count' => 0, 'value' => 0, 'quantity' => 0],
            'normal' => ['count' => 0, 'value' => 0, 'quantity' => 0],
            'slow_moving' => ['count' => 0, 'value' => 0, 'quantity' => 0],
            'dead_stock' => ['count' => 0, 'value' => 0, 'quantity' => 0],
            'total_count' => 0,
            'total_value' => 0,
            'total_quantity' => 0
        ];

        foreach ($results as $result) {
            $status = str_replace(' ', '_', strtolower($result['stock_status']));
            if (isset($summary[$status])) {
                $summary[$status]['count'] = (int) $result['item_count'];
                $summary[$status]['value'] = (float) $result['total_value'];
                $summary[$status]['quantity'] = (float) $result['total_quantity'];
            }

            $summary['total_count'] += (int) $result['item_count'];
            $summary['total_value'] += (float) $result['total_value'];
            $summary['total_quantity'] += (float) $result['total_quantity'];
        }

        return $summary;
    }

    /**
     * Get stock aging by warehouse
     * 
     * @param int $days Number of days to consider for aging (default: 90)
     * @return array Stock aging breakdown by warehouse
     */
    public function getStockAgingByWarehouse($days = 90)
    {
        if (!$this->companyId) {
            return [];
        }

        // First, get the last movement date for each variant
        $sql = "
        SELECT 
            w.id as warehouse_id,
            w.warehouse_name,
            v.id as variant_id,
            i.quantity,
            i.avg_landed_cost,
            v.purchase_price,
            MAX(it.created_at) as last_movement_date
        FROM inventory i
        JOIN variants v ON i.variant_id = v.id
        JOIN products p ON v.product_id = p.id
        JOIN warehouses w ON i.warehouse_id = w.id
        LEFT JOIN inventory_transactions it ON v.id = it.variant_id 
            AND it.company_id = i.company_id
        WHERE i.quantity > 0
            AND i.company_id = :p_1_company_id
            AND p.company_id = :p_2_company_id
            AND w.company_id = :p_3_company_id
        GROUP BY i.id, v.id, w.id
    ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':p_1_company_id' => $this->companyId, ':p_2_company_id' => $this->companyId, ':p_3_company_id' => $this->companyId]);
        $items = $stmt->fetchAll();

        // Calculate aging statistics by warehouse
        $warehouseStats = [];

        foreach ($items as $item) {
            $warehouseId = $item['warehouse_id'];
            $warehouseName = $item['warehouse_name'];
            $quantity = (float) $item['quantity'];
            $cost = $item['avg_landed_cost'] ?: $item['purchase_price'];
            $value = $quantity * $cost;
            $lastMovement = $item['last_movement_date'];

            // Calculate days since last movement
            $daysSince = null;
            if ($lastMovement) {
                $lastDate = new DateTime($lastMovement);
                $now = new DateTime();
                $daysSince = $now->diff($lastDate)->days;
            }

            // Initialize warehouse stats if not exists
            if (!isset($warehouseStats[$warehouseId])) {
                $warehouseStats[$warehouseId] = [
                    'warehouse_name' => $warehouseName,
                    'item_count' => 0,
                    'total_quantity' => 0,
                    'total_value' => 0,
                    'fast_moving_qty' => 0,
                    'normal_qty' => 0,
                    'slow_moving_qty' => 0,
                    'dead_stock_qty' => 0
                ];
            }

            // Update warehouse stats
            $warehouseStats[$warehouseId]['item_count']++;
            $warehouseStats[$warehouseId]['total_quantity'] += $quantity;
            $warehouseStats[$warehouseId]['total_value'] += $value;

            // Determine stock status
            if ($daysSince === null) {
                $warehouseStats[$warehouseId]['dead_stock_qty'] += $quantity;
            } elseif ($daysSince <= 30) {
                $warehouseStats[$warehouseId]['fast_moving_qty'] += $quantity;
            } elseif ($daysSince <= 60) {
                $warehouseStats[$warehouseId]['normal_qty'] += $quantity;
            } elseif ($daysSince <= 90) {
                $warehouseStats[$warehouseId]['slow_moving_qty'] += $quantity;
            } else {
                $warehouseStats[$warehouseId]['dead_stock_qty'] += $quantity;
            }
        }

        // Convert to array and sort by total value
        $result = array_values($warehouseStats);
        usort($result, function ($a, $b) {
            return $b['total_value'] <=> $a['total_value'];
        });

        return $result;
    }
    /**
     * Get inventory valuation by warehouse
     */
    public function getValuationByWarehouse()
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                w.id,
                w.warehouse_name,
                w.warehouse_code,
                COUNT(DISTINCT i.variant_id) as product_count,
                COALESCE(SUM(i.quantity), 0) as total_quantity,
                COALESCE(SUM(i.quantity * COALESCE(i.avg_landed_cost, v.purchase_price, 0)), 0) as total_value
            FROM warehouses w
            LEFT JOIN inventory i ON w.id = i.warehouse_id AND i.company_id = :p_1_company_id
            LEFT JOIN variants v ON i.variant_id = v.id
            WHERE w.company_id = :p_2_company_id AND w.is_active = 1
            GROUP BY w.id
            ORDER BY total_value DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':p_1_company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':p_2_company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get inventory valuation by category
     */
    public function getValuationByCategory()
    {
        if (!$this->companyId) {
            return [];
        }

        $sql = "
            SELECT 
                c.id,
                c.category_name,
                COUNT(DISTINCT v.id) as variant_count,
                COALESCE(SUM(i.quantity), 0) as total_quantity,
                COALESCE(SUM(i.quantity * COALESCE(i.avg_landed_cost, v.purchase_price, 0)), 0) as total_value
            FROM categories c
            INNER JOIN category_company cc ON c.id = cc.category_id AND cc.company_id = :p_1_company_id
            LEFT JOIN products p ON c.id = p.category_id AND p.company_id = :p_2_company_id AND p.is_active = 1
            LEFT JOIN variants v ON p.id = v.product_id AND v.is_active = 1
            LEFT JOIN inventory i ON v.id = i.variant_id AND i.company_id = :p_3_company_id
            WHERE cc.is_active = 1
            GROUP BY c.id
            HAVING total_value > 0 OR total_quantity > 0
            ORDER BY total_value DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':p_1_company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':p_2_company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->bindValue(':p_3_company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get inventory statistics
     */
    public function getStats()
    {
        if (!$this->companyId) {
            return [
                'total_variants' => 0,
                'total_quantity' => 0,
                'total_value' => 0,
                'avg_unit_cost' => 0,
                'warehouses_used' => 0,
                'out_of_stock_count' => 0,
                'low_stock_count' => 0
            ];
        }

        $sql = "
            SELECT 
                COUNT(DISTINCT i.variant_id) as total_variants,
                SUM(i.quantity) as total_quantity,
                SUM(i.quantity * COALESCE(i.avg_landed_cost, v.purchase_price, 0)) as total_value,
                AVG(COALESCE(i.avg_landed_cost, v.purchase_price, 0)) as avg_unit_cost,
                COUNT(DISTINCT i.warehouse_id) as warehouses_used,
                SUM(CASE WHEN i.available_quantity <= 0 THEN 1 ELSE 0 END) as out_of_stock_count,
                SUM(CASE WHEN i.available_quantity <= v.reorder_level AND i.available_quantity > 0 THEN 1 ELSE 0 END) as low_stock_count
            FROM inventory i
            JOIN variants v ON i.variant_id = v.id
            WHERE i.company_id = :company_id
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':company_id', $this->companyId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch();
    }

    /**
     * Get out of stock items
     */

    public function getOutOfStock($warehouseId = null)
    {
        if (!$this->companyId) {
            return [];
        }

        // Build the warehouse filter condition
        $warehouseCondition = "";
        $params = ['p_1_company_id' => $this->companyId, 'p_2_company_id' => $this->companyId, 'p_3_company_id' => $this->companyId];

        if ($warehouseId) {
            $warehouseCondition = " AND w.id = :warehouse_id";
            $params['warehouse_id'] = $warehouseId;
        }

        $sql = "
        SELECT 
            v.id as variant_id,
            v.sku,
            v.variant_name,
            p.product_name,
            p.product_code,
            w.warehouse_name,
            w.id as warehouse_id,
            COALESCE(i.quantity, 0) as current_quantity
        FROM variants v
        INNER JOIN products p ON v.product_id = p.id 
            AND p.company_id = :p_1_company_id 
            AND p.is_active = 1
        CROSS JOIN warehouses w
        LEFT JOIN inventory i ON v.id = i.variant_id 
            AND i.warehouse_id = w.id 
            AND i.company_id = :p_2_company_id
        WHERE v.is_active = 1
            AND w.company_id = :p_3_company_id
            AND w.is_active = 1
            AND (i.quantity IS NULL OR i.quantity <= 0)
            {$warehouseCondition}
        ORDER BY p.product_name, v.variant_name
    ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    /**
     * Get turnover rate formatted
     */
    public function getTurnoverRateFormatted($days = 30)
    {
        $rate = $this->getTurnoverRate($days);
        return [
            'rate' => round($rate, 2),
            'days' => $days,
            'text' => $rate > 0 ? number_format($rate, 2) . 'x' : '0x'
        ];
    }
}