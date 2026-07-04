<?php
// /src/api/send_message.php
// Unified Chat API Handler for P2P and B2C messaging

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../Models/Chat.php';
require_once __DIR__ . '/../Models/Product.php';
require_once __DIR__ . '/../Models/Seller.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Check if user is logged in
$customer = SessionManager::getCustomer();
if (!$customer) {
    echo json_encode(['success' => false, 'error' => 'Please login to continue', 'redirect' => 'login.php']);
    exit();
}

// Get request data
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_GET['action'] ?? '';

// Initialize models
$chat = new Chat();
$productModel = new Product();
$sellerModel = new Seller();

try {
    switch ($action) {
        
        case 'get_rooms':
            // Get user's chat rooms
            $rooms = $chat->getUserRooms('customer', $customer['id']);
            echo json_encode(['success' => true, 'rooms' => $rooms]);
            break;
            
        case 'get_messages':
            // Get messages for a specific room
            $roomId = isset($input['room_id']) ? (int)$input['room_id'] : (int)$_GET['room_id'];
            $limit = isset($input['limit']) ? (int)$input['limit'] : 50;
            
            if (!$roomId) {
                echo json_encode(['success' => false, 'error' => 'Room ID required']);
                break;
            }
            
            // Verify user has access to this room
            $rooms = $chat->getUserRooms('customer', $customer['id']);
            $hasAccess = false;
            foreach ($rooms as $room) {
                if ($room['id'] == $roomId) {
                    $hasAccess = true;
                    break;
                }
            }
            
            if (!$hasAccess) {
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                break;
            }
            
            $messages = $chat->getMessages($roomId, $limit);
            echo json_encode(['success' => true, 'messages' => $messages]);
            break;
            
        case 'send':
            // Send a message
            $roomId = isset($input['room_id']) ? (int)$input['room_id'] : 0;
            $message = isset($input['message']) ? trim($input['message']) : '';
            
            if (!$roomId) {
                echo json_encode(['success' => false, 'error' => 'Room ID required']);
                break;
            }
            
            if (empty($message)) {
                echo json_encode(['success' => false, 'error' => 'Message cannot be empty']);
                break;
            }
            
            // Verify user has access to this room
            $rooms = $chat->getUserRooms('customer', $customer['id']);
            $hasAccess = false;
            foreach ($rooms as $room) {
                if ($room['id'] == $roomId) {
                    $hasAccess = true;
                    break;
                }
            }
            
            if (!$hasAccess) {
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                break;
            }
            
            $result = $chat->sendMessage($roomId, 'customer', $customer['id'], $message);
            echo json_encode($result);
            break;
            
        case 'create_room':
            // Create a new chat room
            $sellerType = isset($input['seller_type']) ? $input['seller_type'] : ($_GET['seller_type'] ?? '');
            $sellerId = isset($input['seller_id']) ? (int)$input['seller_id'] : (int)($_GET['seller_id'] ?? 0);
            $productId = isset($input['product_id']) ? (int)$input['product_id'] : (int)($_GET['product_id'] ?? 0);
            $productType = isset($input['product_type']) ? $input['product_type'] : ($_GET['product_type'] ?? '');
            
            if (!$sellerType || !$sellerId) {
                echo json_encode(['success' => false, 'error' => 'Seller type and ID required']);
                break;
            }
            
            // Validate seller exists
            if ($sellerType === 'peer') {
                $seller = $sellerModel->getP2PSellerById($sellerId);
                if (!$seller) {
                    echo json_encode(['success' => false, 'error' => 'Seller not found']);
                    break;
                }
            } else {
                $seller = $sellerModel->getSellerById($sellerId);
                if (!$seller) {
                    echo json_encode(['success' => false, 'error' => 'Seller not found']);
                    break;
                }
            }
            
            // Validate product if provided
            if ($productId > 0) {
                if ($productType === 'p2p') {
                    // Check if product exists and belongs to seller
                    $db = Database::getInstance()->getConnection();
                    $stmt = $db->prepare("SELECT id FROM customer_store_products WHERE id = :id AND customer_store_id = :store_id");
                    $stmt->bindValue(':id', $productId, PDO::PARAM_INT);
                    $stmt->bindValue(':store_id', $sellerId, PDO::PARAM_INT);
                    $stmt->execute();
                    if (!$stmt->fetch()) {
                        echo json_encode(['success' => false, 'error' => 'Product not found']);
                        break;
                    }
                } else {
                    // Check if ERP product exists
                    $product = $productModel->getProductById($productId);
                    if (!$product || $product['company_id'] != $sellerId) {
                        echo json_encode(['success' => false, 'error' => 'Product not found']);
                        break;
                    }
                }
            }
            
            $roomId = $chat->getOrCreateRoom(
                $customer['id'],
                $sellerType,
                $sellerId,
                $productId ?: null,
                $productType ?: null
            );
            
            if ($roomId) {
                echo json_encode(['success' => true, 'room_id' => $roomId]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Failed to create chat room']);
            }
            break;
            
        case 'mark_read':
            // Mark messages as read
            $roomId = isset($input['room_id']) ? (int)$input['room_id'] : 0;
            
            if (!$roomId) {
                echo json_encode(['success' => false, 'error' => 'Room ID required']);
                break;
            }
            
            $result = $chat->markMessagesAsRead($roomId);
            echo json_encode(['success' => $result]);
            break;
            
        case 'unread_count':
            // Get unread message count
            $count = $chat->getUnreadCount('customer', $customer['id']);
            echo json_encode(['success' => true, 'count' => $count]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            break;
    }
    
} catch (Exception $e) {
    error_log("Chat API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'An error occurred: ' . $e->getMessage()]);
}