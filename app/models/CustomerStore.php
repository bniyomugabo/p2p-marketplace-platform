<?php
// /src/Models/CustomerStore.php
// Customer Store Model - P2P Store Management

require_once __DIR__ . '/BaseModel.php';

class CustomerStore extends BaseModel {
    protected $table = 'customer_stores';
    protected $primaryKey = 'id';
    protected $hasCompanySupport = false;
    
    public function __construct($companyId = null) {
        parent::__construct($companyId);
    }
    
    /**
     * Get store by customer ID
     */
    public function getStoreByCustomerId($customerId) {
        $sql = "SELECT * FROM customer_stores WHERE customer_id = :customer_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':customer_id' => $customerId]);
        return $stmt->fetch();
    }
    
    /**
     * Create a new store for customer
     */
    public function createStore($customerId, $storeName, $description = null) {
        $slug = $this->generateSlug($storeName, $customerId);
        
        $sql = "INSERT INTO customer_stores (customer_id, store_name, slug, description, is_active, created_at) 
                VALUES (:customer_id, :store_name, :slug, :description, 1, NOW())";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':customer_id' => $customerId,
            ':store_name' => $storeName,
            ':slug' => $slug,
            ':description' => $description
        ]);
        
        return $this->getStoreByCustomerId($customerId);
    }
    
    /**
     * Get store products
     */
    public function getStoreProducts($storeId, $limit = 50, $offset = 0) {
        $sql = "SELECT * FROM customer_store_products 
                WHERE customer_store_id = :store_id 
                AND is_active = 1
                ORDER BY created_at DESC 
                LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':store_id', $storeId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * Get store statistics
     */
    public function getStoreStats($storeId) {
        $stats = ['total_products' => 0, 'total_sales' => 0];
        
        $sql = "SELECT COUNT(*) as count FROM customer_store_products WHERE customer_store_id = :store_id AND is_active = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':store_id' => $storeId]);
        $stats['total_products'] = $stmt->fetch()['count'];
        
        $sql = "SELECT COUNT(*) as count FROM p2p_order_items poi 
                INNER JOIN p2p_orders po ON poi.p2p_order_id = po.id 
                WHERE po.seller_store_id = :store_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':store_id' => $storeId]);
        $stats['total_sales'] = $stmt->fetch()['count'];
        
        return $stats;
    }
    
    /**
     * Get store orders
     */
    public function getStoreOrders($storeId, $limit = 20) {
        $sql = "SELECT 
                    po.*,
                    c.full_name as buyer_name,
                    poi.product_title,
                    poi.quantity,
                    poi.unit_price
                FROM p2p_orders po
                INNER JOIN customers c ON po.buyer_id = c.id
                INNER JOIN p2p_order_items poi ON po.id = poi.p2p_order_id
                WHERE po.seller_store_id = :store_id
                ORDER BY po.created_at DESC
                LIMIT :limit";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':store_id', $storeId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    /**
     * Generate unique slug
     */
    private function generateSlug($name, $customerId) {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
        return $slug . '-' . $customerId;
    }
}