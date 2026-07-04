<?php
// /src/Models/CustomerStore.php
// Customer Store Model for P2P Marketplace

require_once __DIR__ . '/../../config/database.php';

class CustomerStore {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Get store by customer ID
     */
    public function getStoreByCustomerId($customerId) {
        try {
            $conn = $this->db->getConnection();
            
            $sql = "SELECT * FROM customer_stores WHERE customer_id = :customer_id";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':customer_id', $customerId, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetch();
            
        } catch (PDOException $e) {
            error_log("CustomerStore::getStoreByCustomerId Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Create a new store for customer
     */
    public function createStore($customerId, $storeName, $description = null) {
        try {
            $conn = $this->db->getConnection();
            
            $slug = $this->generateSlug($storeName, $customerId);
            
            $sql = "INSERT INTO customer_stores (customer_id, store_name, slug, description, is_active, created_at) 
                    VALUES (:customer_id, :store_name, :slug, :description, 1, NOW())";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':customer_id', $customerId, PDO::PARAM_INT);
            $stmt->bindValue(':store_name', $storeName);
            $stmt->bindValue(':slug', $slug);
            $stmt->bindValue(':description', $description);
            $stmt->execute();
            
            return $this->getStoreByCustomerId($customerId);
            
        } catch (PDOException $e) {
            error_log("CustomerStore::createStore Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update store information
     */
    public function updateStore($storeId, $data) {
        try {
            $conn = $this->db->getConnection();
            
            $updates = [];
            $params = [':id' => $storeId];
            
            if (isset($data['store_name'])) {
                $updates[] = "store_name = :store_name";
                $params[':store_name'] = $data['store_name'];
            }
            
            if (isset($data['description'])) {
                $updates[] = "description = :description";
                $params[':description'] = $data['description'];
            }
            
            if (empty($updates)) {
                return ['success' => false, 'message' => 'No data to update'];
            }
            
            $sql = "UPDATE customer_stores SET " . implode(', ', $updates) . " WHERE id = :id";
            $stmt = $conn->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->execute();
            
            return ['success' => true, 'message' => 'Store updated successfully'];
            
        } catch (PDOException $e) {
            error_log("CustomerStore::updateStore Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to update store'];
        }
    }
    
    /**
     * Get products for a store
     */
    public function getStoreProducts($storeId, $limit = 50, $offset = 0) {
        try {
            $conn = $this->db->getConnection();
            
            $sql = "SELECT * FROM customer_store_products 
                    WHERE customer_store_id = :store_id 
                    ORDER BY created_at DESC 
                    LIMIT :limit OFFSET :offset";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':store_id', $storeId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("CustomerStore::getStoreProducts Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get store statistics
     */
    public function getStoreStats($storeId) {
        try {
            $conn = $this->db->getConnection();
            
            $stats = [
                'total_products' => 0,
                'total_sales' => 0,
                'avg_rating' => 0
            ];
            
            // Total products
            $sql = "SELECT COUNT(*) as count FROM customer_store_products WHERE customer_store_id = :store_id AND is_active = 1";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':store_id', $storeId, PDO::PARAM_INT);
            $stmt->execute();
            $stats['total_products'] = $stmt->fetch()['count'];
            
            // Total sales
            $sql = "SELECT COUNT(*) as count FROM p2p_order_items poi 
                    INNER JOIN p2p_orders po ON poi.p2p_order_id = po.id 
                    WHERE po.seller_store_id = :store_id";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':store_id', $storeId, PDO::PARAM_INT);
            $stmt->execute();
            $stats['total_sales'] = $stmt->fetch()['count'];
            
            return $stats;
            
        } catch (PDOException $e) {
            error_log("CustomerStore::getStoreStats Error: " . $e->getMessage());
            return ['total_products' => 0, 'total_sales' => 0, 'avg_rating' => 0];
        }
    }
    
    /**
     * Get store orders
     */
    public function getStoreOrders($storeId, $limit = 20) {
        try {
            $conn = $this->db->getConnection();
            
            $sql = "SELECT 
                        po.id,
                        po.order_number,
                        po.total_amount,
                        po.payment_status,
                        po.created_at,
                        c.full_name as buyer_name,
                        c.email as buyer_email,
                        poi.product_title,
                        poi.quantity,
                        poi.unit_price
                    FROM p2p_orders po
                    INNER JOIN customers c ON po.buyer_id = c.id
                    INNER JOIN p2p_order_items poi ON po.id = poi.p2p_order_id
                    WHERE po.seller_store_id = :store_id
                    ORDER BY po.created_at DESC
                    LIMIT :limit";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':store_id', $storeId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("CustomerStore::getStoreOrders Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Generate unique slug for store
     */
    private function generateSlug($name, $customerId) {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
        $slug = $slug . '-' . $customerId;
        return $slug;
    }
}