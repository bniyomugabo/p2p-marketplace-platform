<?php
// /src/Models/Seller.php

class Seller {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Get B2C seller by company ID
     */
    public function getSellerById($companyId) {
        try {
            $sql = "SELECT 
                        comp.id,
                        comp.company_code,
                        comp.company_name as seller_name,
                        comp.slug,
                        comp.store_description as description,
                        comp.email,
                        comp.phone,
                        comp.address,
                        comp.city as location,
                        comp.country,
                        comp.currency,
                        comp.logo_url,
                        comp.is_active,
                        comp.is_public,
                        comp.created_at as member_since,
                        'company' as seller_type,
                        'corporate' as badge_type,
                        u.id as owner_id
                    FROM companies comp
                    LEFT JOIN users u ON u.company_id = comp.id AND u.role_id = 1
                    WHERE comp.id = :company_id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':company_id', $companyId, PDO::PARAM_INT);
            $stmt->execute();
            
            $seller = $stmt->fetch();
            
            if ($seller) {
                $seller['total_products'] = $this->getSellerProductCount($companyId);
                $seller['total_sales'] = $this->getSellerSalesCount($companyId);
                $seller['rating'] = $this->getSellerRating($companyId);
                $seller['verified'] = !empty($seller['registration_number']);
            }
            
            return $seller;
            
        } catch (PDOException $e) {
            error_log("Seller::getSellerById Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get B2C seller by slug
     */
    public function getSellerBySlug($slug) {
        try {
            $sql = "SELECT 
                        comp.id,
                        comp.company_code,
                        comp.company_name as seller_name,
                        comp.slug,
                        comp.store_description as description,
                        comp.email,
                        comp.phone,
                        comp.address,
                        comp.city as location,
                        comp.country,
                        comp.currency,
                        comp.logo_url,
                        comp.created_at as member_since,
                        'company' as seller_type,
                        'corporate' as badge_type
                    FROM companies comp
                    WHERE comp.slug = :slug";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':slug', $slug, PDO::PARAM_STR);
            $stmt->execute();
            
            $seller = $stmt->fetch();
            
            if ($seller) {
                $seller['total_products'] = $this->getSellerProductCount($seller['id']);
                $seller['total_sales'] = $this->getSellerSalesCount($seller['id']);
                $seller['rating'] = $this->getSellerRating($seller['id']);
                $seller['verified'] = !empty($seller['registration_number']);
            }
            
            return $seller;
            
        } catch (PDOException $e) {
            error_log("Seller::getSellerBySlug Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get P2P seller by customer ID
     */
    public function getP2PSellerById($customerId) {
        try {
            $sql = "SELECT 
                        c.id as customer_id,
                        c.full_name as seller_name,
                        c.phone,
                        c.email,
                        c.address,
                        c.city as location,
                        c.country,
                        c.created_at as member_since,
                        cs.id as store_id,
                        cs.store_name,
                        cs.slug,
                        cs.description,
                        'individual' as seller_type,
                        'individual' as badge_type
                    FROM customers c
                    INNER JOIN customer_stores cs ON c.id = cs.customer_id
                    WHERE c.id = :customer_id AND cs.is_active = 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':customer_id', $customerId, PDO::PARAM_INT);
            $stmt->execute();
            
            $seller = $stmt->fetch();
            
            if ($seller) {
                $seller['total_products'] = $this->getP2PProductCount($seller['store_id']);
                $seller['total_sales'] = $this->getP2PSalesCount($seller['store_id']);
                $seller['rating'] = $this->getP2PSellerRating($seller['store_id']);
                $seller['verified'] = false; // P2P sellers are not auto-verified
            }
            
            return $seller;
            
        } catch (PDOException $e) {
            error_log("Seller::getP2PSellerById Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get P2P seller by store slug
     */
    public function getP2PSellerBySlug($slug) {
        try {
            $sql = "SELECT 
                        c.id as customer_id,
                        c.full_name as seller_name,
                        c.phone,
                        c.email,
                        c.address,
                        c.city as location,
                        c.country,
                        c.created_at as member_since,
                        cs.id as store_id,
                        cs.store_name,
                        cs.slug,
                        cs.description,
                        'individual' as seller_type,
                        'individual' as badge_type
                    FROM customer_stores cs
                    INNER JOIN customers c ON cs.customer_id = c.id
                    WHERE cs.slug = :slug AND cs.is_active = 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':slug', $slug, PDO::PARAM_STR);
            $stmt->execute();
            
            $seller = $stmt->fetch();
            
            if ($seller) {
                $seller['total_products'] = $this->getP2PProductCount($seller['store_id']);
                $seller['total_sales'] = $this->getP2PSalesCount($seller['store_id']);
                $seller['rating'] = $this->getP2PSellerRating($seller['store_id']);
            }
            
            return $seller;
            
        } catch (PDOException $e) {
            error_log("Seller::getP2PSellerBySlug Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get product count for B2C seller
     */
    public function getSellerProductCount($companyId) {
        try {
            $sql = "SELECT COUNT(DISTINCT p.id) as total
                    FROM products p
                    WHERE p.company_id = :company_id 
                        AND p.is_active = 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':company_id', $companyId, PDO::PARAM_INT);
            $stmt->execute();
            
            $result = $stmt->fetch();
            return (int)$result['total'];
            
        } catch (PDOException $e) {
            error_log("Seller::getSellerProductCount Error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get product count for P2P seller
     */
    public function getP2PProductCount($storeId) {
        try {
            $sql = "SELECT COUNT(id) as total
                    FROM customer_store_products
                    WHERE customer_store_id = :store_id AND is_active = 1";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':store_id', $storeId, PDO::PARAM_INT);
            $stmt->execute();
            
            $result = $stmt->fetch();
            return (int)$result['total'];
            
        } catch (PDOException $e) {
            error_log("Seller::getP2PProductCount Error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get sales count for B2C seller
     */
    public function getSellerSalesCount($companyId) {
        try {
            $sql = "SELECT COUNT(DISTINCT si.id) as total
                    FROM sales_invoices si
                    INNER JOIN invoice_items ii ON si.id = ii.invoice_id
                    INNER JOIN variants v ON ii.variant_id = v.id
                    WHERE v.company_id = :company_id
                        AND si.status IN ('paid', 'partial')";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':company_id', $companyId, PDO::PARAM_INT);
            $stmt->execute();
            
            $result = $stmt->fetch();
            return (int)$result['total'];
            
        } catch (PDOException $e) {
            error_log("Seller::getSellerSalesCount Error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get sales count for P2P seller
     */
    public function getP2PSalesCount($storeId) {
        try {
            $sql = "SELECT COUNT(DISTINCT po.id) as total
                    FROM p2p_orders po
                    WHERE po.seller_store_id = :store_id
                        AND po.payment_status = 'released_to_seller'";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':store_id', $storeId, PDO::PARAM_INT);
            $stmt->execute();
            
            $result = $stmt->fetch();
            return (int)$result['total'];
            
        } catch (PDOException $e) {
            error_log("Seller::getP2PSalesCount Error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get rating for B2C seller
     */
    public function getSellerRating($companyId) {
        try {
            $sql = "SELECT AVG(rating) as avg_rating, COUNT(id) as review_count
                    FROM seller_reviews
                    WHERE company_id = :company_id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':company_id', $companyId, PDO::PARAM_INT);
            $stmt->execute();
            
            $result = $stmt->fetch();
            
            if ($result && $result['avg_rating'] > 0) {
                return '⭐ ' . round($result['avg_rating'], 1) . ' (' . $result['review_count'] . ' reviews)';
            }
            
            return null;
            
        } catch (PDOException $e) {
            error_log("Seller::getSellerRating Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get rating for P2P seller (placeholder)
     */
    public function getP2PSellerRating($storeId) {
        // Implement P2P rating logic based on order completions
        return null;
    }
    
    /**
     * Get categories for B2C seller
     */
    public function getSellerCategories($companyId) {
        try {
            $sql = "SELECT 
                        c.id,
                        c.category_code,
                        c.category_name,
                        COUNT(DISTINCT p.id) as product_count
                    FROM categories c
                    INNER JOIN products p ON p.category_id = c.id
                    WHERE p.company_id = :company_id
                        AND p.is_active = 1
                    GROUP BY c.id
                    ORDER BY c.category_name ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':company_id', $companyId, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("Seller::getSellerCategories Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get categories for P2P seller
     */
    public function getP2PSellerCategories($storeId) {
        try {
            $sql = "SELECT 
                        gc.id,
                        gc.name as category_name,
                        COUNT(csp.id) as product_count
                    FROM global_categories gc
                    INNER JOIN customer_store_products csp ON csp.global_category_id = gc.id
                    WHERE csp.customer_store_id = :store_id
                        AND csp.is_active = 1
                    GROUP BY gc.id
                    ORDER BY gc.name ASC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':store_id', $storeId, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log("Seller::getP2PSellerCategories Error: " . $e->getMessage());
            return [];
        }
    }
}