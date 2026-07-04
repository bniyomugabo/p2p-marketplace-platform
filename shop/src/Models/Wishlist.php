<?php
// /src/Models/Wishlist.php
// Wishlist Model

require_once __DIR__ . '/../../config/database.php';

class Wishlist {
    private $db;
    private $marketplaceCompanyId = 9;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Add product to wishlist
     */
    public function add($customerId, $productId, $variantId = null) {
        try {
            $conn = $this->db->getConnection();
            
            // Check if already exists
            $stmt = $conn->prepare("SELECT id FROM wishlist WHERE customer_id = :customer_id AND product_id = :product_id");
            $stmt->bindValue(':customer_id', $customerId, PDO::PARAM_INT);
            $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
            $stmt->execute();
            
            if ($stmt->fetch()) {
                return ['success' => false, 'message' => 'Product already in wishlist'];
            }
            
            // Insert
            $stmt = $conn->prepare("INSERT INTO wishlist (customer_id, product_id, variant_id, created_at) 
                                    VALUES (:customer_id, :product_id, :variant_id, NOW())");
            $stmt->bindValue(':customer_id', $customerId, PDO::PARAM_INT);
            $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
            $stmt->bindValue(':variant_id', $variantId, $variantId ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->execute();
            
            return ['success' => true, 'message' => 'Added to wishlist'];
            
        } catch (PDOException $e) {
            error_log("Wishlist::add Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to add to wishlist'];
        }
    }
    
    /**
     * Remove product from wishlist
     */
    public function remove($customerId, $productId) {
        try {
            $conn = $this->db->getConnection();
            
            $stmt = $conn->prepare("DELETE FROM wishlist WHERE customer_id = :customer_id AND product_id = :product_id");
            $stmt->bindValue(':customer_id', $customerId, PDO::PARAM_INT);
            $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
            $stmt->execute();
            
            return ['success' => true, 'message' => 'Removed from wishlist'];
            
        } catch (PDOException $e) {
            error_log("Wishlist::remove Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to remove from wishlist'];
        }
    }
    
    /**
     * Get customer wishlist with product details
     */
    public function getWishlist($customerId) {
        try {
            $conn = $this->db->getConnection();
            
            $sql = "SELECT 
                        w.id,
                        w.product_id,
                        w.variant_id,
                        w.created_at,
                        p.product_name,
                        p.product_code,
                        p.description,
                        p.brand,
                        c.company_name,
                        c.currency,
                        MIN(v.selling_price) as min_price,
                        (SELECT vi.image_url 
                         FROM variant_images vi 
                         INNER JOIN variants v2 ON vi.variant_id = v2.id
                         WHERE v2.product_id = p.id 
                         AND vi.is_primary = 1 
                         LIMIT 1) as image
                    FROM wishlist w
                    INNER JOIN products p ON w.product_id = p.id
                    INNER JOIN companies c ON p.company_id = c.id
                    LEFT JOIN variants v ON v.product_id = p.id AND v.company_id = p.company_id AND v.is_active = 1
                    WHERE w.customer_id = :customer_id
                    AND c.is_public = 1
                    AND p.is_active = 1
                    GROUP BY w.id, w.product_id, w.variant_id, w.created_at, p.product_name, 
                             p.product_code, p.description, p.brand, c.company_name, c.currency
                    ORDER BY w.created_at DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':customer_id', $customerId, PDO::PARAM_INT);
            $stmt->execute();
            
            $items = $stmt->fetchAll();
            
            foreach ($items as &$item) {
                $item['price_range'] = $this->formatPrice($item['min_price'], $item['currency']);
                $item['image_url'] = $item['image'] ?? '/assets/img/placeholder.jpg';
                $item['in_stock'] = $this->checkStock($item['product_id']);
            }
            
            return $items;
            
        } catch (PDOException $e) {
            error_log("Wishlist::getWishlist Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check if product is in wishlist
     */
    public function isInWishlist($customerId, $productId) {
        try {
            $conn = $this->db->getConnection();
            
            $stmt = $conn->prepare("SELECT id FROM wishlist WHERE customer_id = :customer_id AND product_id = :product_id");
            $stmt->bindValue(':customer_id', $customerId, PDO::PARAM_INT);
            $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetch() !== false;
            
        } catch (PDOException $e) {
            error_log("Wishlist::isInWishlist Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get wishlist count
     */
    public function getCount($customerId) {
        try {
            $conn = $this->db->getConnection();
            
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM wishlist WHERE customer_id = :customer_id");
            $stmt->bindValue(':customer_id', $customerId, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetch();
            
            return $result['count'] ?? 0;
            
        } catch (PDOException $e) {
            error_log("Wishlist::getCount Error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Clear wishlist
     */
    public function clear($customerId) {
        try {
            $conn = $this->db->getConnection();
            
            $stmt = $conn->prepare("DELETE FROM wishlist WHERE customer_id = :customer_id");
            $stmt->bindValue(':customer_id', $customerId, PDO::PARAM_INT);
            $stmt->execute();
            
            return ['success' => true, 'message' => 'Wishlist cleared'];
            
        } catch (PDOException $e) {
            error_log("Wishlist::clear Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to clear wishlist'];
        }
    }
    
    private function checkStock($productId) {
        try {
            $conn = $this->db->getConnection();
            
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM variants v 
                                    LEFT JOIN inventory i ON v.id = i.variant_id 
                                    WHERE v.product_id = :product_id AND (i.quantity > 0 OR i.quantity IS NULL)");
            $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetch();
            
            return $result['count'] > 0;
            
        } catch (PDOException $e) {
            return true;
        }
    }
    
    private function formatPrice($price, $currency = 'RWF') {
        return number_format((float)$price, 0, ',', ' ') . ' ' . $currency;
    }
}