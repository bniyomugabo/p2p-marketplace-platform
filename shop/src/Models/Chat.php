<?php
// /src/Models/Chat.php
// Unified Chat Model for P2P and B2C messaging

require_once __DIR__ . '/../../config/database.php';

class Chat {
    private $db;
    private $pdo;
    
    public function __construct() {
        $this->db = Database::getInstance();
        // Get the actual PDO connection
        $this->pdo = $this->db->getConnection();
    }
    
    /**
     * Get or create a chat room
     * 
     * @param int $buyerId Customer ID
     * @param string $sellerType 'peer' or 'corporate'
     * @param int $sellerId Store ID or Company ID
     * @param int|null $productId Product ID (peer or corporate)
     * @param string|null $productType 'p2p' or 'b2c'
     * @return int Chat room ID
     */
    public function getOrCreateRoom($buyerId, $sellerType, $sellerId, $productId = null, $productType = null) {
        try {
            if ($sellerType === 'peer') {
                // Check existing P2P chat room
                $sql = "SELECT id FROM chat_rooms 
                        WHERE room_type = 'p2p' 
                        AND buyer_customer_id = :buyer_id 
                        AND seller_store_id = :seller_id";
                
                if ($productId) {
                    $sql .= " AND p2p_product_id = :product_id";
                }
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindValue(':buyer_id', $buyerId, PDO::PARAM_INT);
                $stmt->bindValue(':seller_id', $sellerId, PDO::PARAM_INT);
                
                if ($productId) {
                    $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
                }
                
                $stmt->execute();
                $room = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($room) {
                    return (int)$room['id'];
                }
                
                // Create new P2P room
                $sql = "INSERT INTO chat_rooms (room_type, buyer_customer_id, seller_store_id, p2p_product_id, created_at, updated_at) 
                        VALUES ('p2p', :buyer_id, :seller_id, :product_id, NOW(), NOW())";
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindValue(':buyer_id', $buyerId, PDO::PARAM_INT);
                $stmt->bindValue(':seller_id', $sellerId, PDO::PARAM_INT);
                $stmt->bindValue(':product_id', $productId, $productId ? PDO::PARAM_INT : PDO::PARAM_NULL);
                $stmt->execute();
                
                return (int)$this->pdo->lastInsertId();
                
            } else {
                // Corporate B2C chat room
                $sql = "SELECT id FROM chat_rooms 
                        WHERE room_type = 'b2c' 
                        AND buyer_customer_id = :buyer_id 
                        AND seller_company_id = :seller_id";
                
                if ($productId) {
                    $sql .= " AND erp_product_id = :product_id";
                }
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindValue(':buyer_id', $buyerId, PDO::PARAM_INT);
                $stmt->bindValue(':seller_id', $sellerId, PDO::PARAM_INT);
                
                if ($productId) {
                    $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
                }
                
                $stmt->execute();
                $room = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($room) {
                    return (int)$room['id'];
                }
                
                // Create new B2C room
                $sql = "INSERT INTO chat_rooms (room_type, buyer_customer_id, seller_company_id, erp_product_id, created_at, updated_at) 
                        VALUES ('b2c', :buyer_id, :seller_id, :product_id, NOW(), NOW())";
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindValue(':buyer_id', $buyerId, PDO::PARAM_INT);
                $stmt->bindValue(':seller_id', $sellerId, PDO::PARAM_INT);
                $stmt->bindValue(':product_id', $productId, $productId ? PDO::PARAM_INT : PDO::PARAM_NULL);
                $stmt->execute();
                
                return (int)$this->pdo->lastInsertId();
            }
            
        } catch (PDOException $e) {
            error_log("Chat::getOrCreateRoom Error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Send a message
     * 
     * @param int $roomId Chat room ID
     * @param string $senderType 'customer' or 'company_user'
     * @param int $senderId Customer ID or User ID
     * @param string $message Message text
     * @return array Success status
     */
    public function sendMessage($roomId, $senderType, $senderId, $message) {
        try {
            // Start transaction
            $this->pdo->beginTransaction();
            
            $sql = "INSERT INTO chat_messages (chat_room_id, sender_type, sender_id, message_text, created_at) 
                    VALUES (:room_id, :sender_type, :sender_id, :message, NOW())";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            $stmt->bindValue(':sender_type', $senderType);
            $stmt->bindValue(':sender_id', $senderId, PDO::PARAM_INT);
            $stmt->bindValue(':message', $message);
            $stmt->execute();
            $messageId = (int)$this->pdo->lastInsertId();
            
            // Update room updated_at
            $sql = "UPDATE chat_rooms SET updated_at = NOW() WHERE id = :room_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            $stmt->execute();
            
            // Commit transaction
            $this->pdo->commit();
            
            return [
                'success' => true,
                'message_id' => $messageId,
                'message' => 'Message sent successfully'
            ];
            
        } catch (PDOException $e) {
            // Rollback on error
            $this->pdo->rollBack();
            error_log("Chat::sendMessage Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to send message'];
        }
    }
    
    /**
     * Get messages for a room
     * 
     * @param int $roomId Chat room ID
     * @param int $limit Limit messages
     * @param int $offset Offset
     * @return array Messages
     */
    public function getMessages($roomId, $limit = 50, $offset = 0) {
        try {
            $sql = "SELECT 
                        cm.id,
                        cm.sender_type,
                        cm.sender_id,
                        cm.message_text,
                        cm.is_read,
                        cm.read_at,
                        cm.created_at,
                        CASE 
                            WHEN cm.sender_type = 'customer' THEN c.full_name
                            ELSE u.full_name
                        END as sender_name,
                        CASE 
                            WHEN cm.sender_type = 'customer' THEN c.avatar
                            ELSE u.avatar
                        END as sender_avatar
                    FROM chat_messages cm
                    LEFT JOIN customers c ON cm.sender_type = 'customer' AND cm.sender_id = c.id
                    LEFT JOIN users u ON cm.sender_type = 'company_user' AND cm.sender_id = u.id
                    WHERE cm.chat_room_id = :room_id
                    ORDER BY cm.created_at ASC
                    LIMIT :limit OFFSET :offset";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Mark messages as read for the other party
            $this->markAsRead($roomId);
            
            return $messages;
            
        } catch (PDOException $e) {
            error_log("Chat::getMessages Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get messages for a room (with pagination metadata)
     * 
     * @param int $roomId Chat room ID
     * @param int $page Page number
     * @param int $perPage Items per page
     * @return array
     */
    public function getMessagesPaginated($roomId, $page = 1, $perPage = 20) {
        try {
            $offset = ($page - 1) * $perPage;
            
            // Get total count
            $sql = "SELECT COUNT(*) as total FROM chat_messages WHERE chat_room_id = :room_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            $stmt->execute();
            $total = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Get messages
            $messages = $this->getMessages($roomId, $perPage, $offset);
            
            return [
                'messages' => $messages,
                'total' => $total,
                'total_pages' => ceil($total / $perPage),
                'current_page' => $page,
                'per_page' => $perPage
            ];
            
        } catch (PDOException $e) {
            error_log("Chat::getMessagesPaginated Error: " . $e->getMessage());
            return ['messages' => [], 'total' => 0, 'total_pages' => 0];
        }
    }
    
    /**
     * Get user's chat rooms
     * 
     * @param string $userType 'customer' or 'company_user'
     * @param int $userId User ID
     * @return array Chat rooms
     */
    public function getUserRooms($userType, $userId) {
        try {
            if ($userType === 'customer') {
                $sql = "SELECT 
                            cr.id,
                            cr.room_type,
                            cr.created_at,
                            cr.updated_at,
                            CASE 
                                WHEN cr.room_type = 'p2p' THEN cs.store_name
                                WHEN cr.room_type = 'b2c' THEN comp.company_name
                            END as other_party_name,
                            CASE 
                                WHEN cr.room_type = 'p2p' THEN cs.slug
                                WHEN cr.room_type = 'b2c' THEN comp.slug
                            END as other_party_slug,
                            cr.p2p_product_id,
                            cr.erp_product_id,
                            (SELECT csp.title FROM customer_store_products csp WHERE csp.id = cr.p2p_product_id) as product_title,
                            (SELECT p.product_name FROM products p WHERE p.id = cr.erp_product_id) as product_name,
                            (SELECT cm.message_text FROM chat_messages cm 
                             WHERE cm.chat_room_id = cr.id 
                             ORDER BY cm.created_at DESC LIMIT 1) as last_message,
                            (SELECT cm.created_at FROM chat_messages cm 
                             WHERE cm.chat_room_id = cr.id 
                             ORDER BY cm.created_at DESC LIMIT 1) as last_message_time,
                            (SELECT COUNT(*) FROM chat_messages cm 
                             WHERE cm.chat_room_id = cr.id 
                             AND cm.sender_type != :user_type_param 
                             AND cm.is_read = 0) as unread_count
                        FROM chat_rooms cr
                        LEFT JOIN customer_stores cs ON cr.seller_store_id = cs.id
                        LEFT JOIN companies comp ON cr.seller_company_id = comp.id
                        WHERE cr.buyer_customer_id = :user_id
                        ORDER BY cr.updated_at DESC";
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindValue(':user_type_param', $userType);
                $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
                $stmt->execute();
                
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
                
            } else {
                // Company user - get rooms where they are the seller
                $sql = "SELECT 
                            cr.id,
                            cr.room_type,
                            cr.created_at,
                            cr.updated_at,
                            c.full_name as buyer_name,
                            c.email as buyer_email,
                            c.id as buyer_id,
                            cr.p2p_product_id,
                            cr.erp_product_id,
                            (SELECT csp.title FROM customer_store_products csp WHERE csp.id = cr.p2p_product_id) as product_title,
                            (SELECT p.product_name FROM products p WHERE p.id = cr.erp_product_id) as product_name,
                            (SELECT cm.message_text FROM chat_messages cm 
                             WHERE cm.chat_room_id = cr.id 
                             ORDER BY cm.created_at DESC LIMIT 1) as last_message,
                            (SELECT cm.created_at FROM chat_messages cm 
                             WHERE cm.chat_room_id = cr.id 
                             ORDER BY cm.created_at DESC LIMIT 1) as last_message_time,
                            (SELECT COUNT(*) FROM chat_messages cm 
                             WHERE cm.chat_room_id = cr.id 
                             AND cm.sender_type != 'company_user'
                             AND cm.is_read = 0) as unread_count
                        FROM chat_rooms cr
                        INNER JOIN customers c ON cr.buyer_customer_id = c.id
                        WHERE cr.seller_store_id IN (SELECT id FROM customer_stores WHERE customer_id = :user_id)
                           OR cr.seller_company_id IN (SELECT company_id FROM company_users WHERE user_id = :user_id2)
                        ORDER BY cr.updated_at DESC";
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
                $stmt->bindValue(':user_id2', $userId, PDO::PARAM_INT);
                $stmt->execute();
                
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
        } catch (PDOException $e) {
            error_log("Chat::getUserRooms Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get a single chat room by ID
     * 
     * @param int $roomId Chat room ID
     * @return array|null
     */
    public function getRoomById($roomId) {
        try {
            $sql = "SELECT 
                        cr.id,
                        cr.room_type,
                        cr.buyer_customer_id,
                        cr.seller_store_id,
                        cr.seller_company_id,
                        cr.p2p_product_id,
                        cr.erp_product_id,
                        cr.created_at,
                        cr.updated_at,
                        c.full_name as buyer_name,
                        c.email as buyer_email,
                        cs.store_name as seller_store_name,
                        comp.company_name as seller_company_name
                    FROM chat_rooms cr
                    LEFT JOIN customers c ON cr.buyer_customer_id = c.id
                    LEFT JOIN customer_stores cs ON cr.seller_store_id = cs.id
                    LEFT JOIN companies comp ON cr.seller_company_id = comp.id
                    WHERE cr.id = :room_id";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Chat::getRoomById Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Mark messages as read
     */
    private function markAsRead($roomId) {
        try {
            $sql = "UPDATE chat_messages SET is_read = 1, read_at = NOW() 
                    WHERE chat_room_id = :room_id AND is_read = 0";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            $stmt->execute();
            
        } catch (PDOException $e) {
            error_log("Chat::markAsRead Error: " . $e->getMessage());
        }
    }
    
    /**
     * Mark specific messages as read
     * 
     * @param int $roomId Chat room ID
     * @param int $messageId Message ID (optional, marks all if not provided)
     * @return bool
     */
    public function markMessagesAsRead($roomId, $messageId = null) {
        try {
            if ($messageId) {
                $sql = "UPDATE chat_messages SET is_read = 1, read_at = NOW() 
                        WHERE id = :message_id AND chat_room_id = :room_id AND is_read = 0";
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindValue(':message_id', $messageId, PDO::PARAM_INT);
                $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            } else {
                $sql = "UPDATE chat_messages SET is_read = 1, read_at = NOW() 
                        WHERE chat_room_id = :room_id AND is_read = 0";
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindValue(':room_id', $roomId, PDO::PARAM_INT);
            }
            
            $stmt->execute();
            return true;
            
        } catch (PDOException $e) {
            error_log("Chat::markMessagesAsRead Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get unread message count for user
     * 
     * @param string $userType 'customer' or 'company_user'
     * @param int $userId User ID
     * @return int
     */
    public function getUnreadCount($userType, $userId) {
        try {
            if ($userType === 'customer') {
                $sql = "SELECT COUNT(*) as count
                        FROM chat_messages cm
                        INNER JOIN chat_rooms cr ON cm.chat_room_id = cr.id
                        WHERE cr.buyer_customer_id = :user_id
                        AND cm.sender_type != 'customer'
                        AND cm.is_read = 0";
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                return (int)($result['count'] ?? 0);
            } else {
                $sql = "SELECT COUNT(*) as count
                        FROM chat_messages cm
                        INNER JOIN chat_rooms cr ON cm.chat_room_id = cr.id
                        WHERE (cr.seller_store_id IN (SELECT id FROM customer_stores WHERE customer_id = :user_id)
                            OR cr.seller_company_id IN (SELECT company_id FROM company_users WHERE user_id = :user_id2))
                        AND cm.sender_type != 'company_user'
                        AND cm.is_read = 0";
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
                $stmt->bindValue(':user_id2', $userId, PDO::PARAM_INT);
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                return (int)($result['count'] ?? 0);
            }
            
        } catch (PDOException $e) {
            error_log("Chat::getUnreadCount Error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Delete a message (soft delete or hard delete)
     * 
     * @param int $messageId Message ID
     * @param int $userId User ID (for authorization)
     * @param string $userType User type
     * @return bool
     */
    public function deleteMessage($messageId, $userId, $userType) {
        try {
            // First verify the user owns this message
            $sql = "SELECT id FROM chat_messages 
                    WHERE id = :message_id AND sender_id = :sender_id AND sender_type = :sender_type";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':message_id', $messageId, PDO::PARAM_INT);
            $stmt->bindValue(':sender_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':sender_type', $userType);
            $stmt->execute();
            
            if (!$stmt->fetch()) {
                return false; // User doesn't own this message
            }
            
            // Hard delete (or you could implement soft delete with a deleted_at column)
            $sql = "DELETE FROM chat_messages WHERE id = :message_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':message_id', $messageId, PDO::PARAM_INT);
            $stmt->execute();
            
            return true;
            
        } catch (PDOException $e) {
            error_log("Chat::deleteMessage Error: " . $e->getMessage());
            return false;
        }
    }
}