<?php
// /src/api/p2p_order.php
// P2P Order Processing with Escrow

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../init.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$customer = SessionManager::getCustomer();

if (!$customer) {
    jsonResponse(['success' => false, 'message' => 'Please login to place order']);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    jsonResponse(['success' => false, 'message' => 'Invalid request data']);
}

// Validate CSRF token
if (!isset($input['csrf_token']) || !SessionManager::verifyCsrfToken($input['csrf_token'])) {
    jsonResponse(['success' => false, 'message' => 'Security validation failed']);
}

$cart = SessionManager::getCart();
$peerItems = [];
$corporateItems = [];

// Separate peer items from corporate items
foreach ($cart as $item) {
    if (isset($item['seller_type']) && $item['seller_type'] === 'peer') {
        $peerItems[] = $item;
    } else {
        $corporateItems[] = $item;
    }
}

if (empty($peerItems) && empty($corporateItems)) {
    jsonResponse(['success' => false, 'message' => 'Cart is empty']);
}

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    $conn->beginTransaction();
    
    $orderNumbers = [];
    
    // Process peer items (one order per seller store)
    if (!empty($peerItems)) {
        // Group peer items by seller store
        $groupedByStore = [];
        foreach ($peerItems as $item) {
            $storeId = $item['seller_id'];
            if (!isset($groupedByStore[$storeId])) {
                $groupedByStore[$storeId] = [
                    'store_id' => $storeId,
                    'store_name' => $item['seller_name'],
                    'items' => [],
                    'subtotal' => 0
                ];
            }
            $groupedByStore[$storeId]['items'][] = $item;
            $groupedByStore[$storeId]['subtotal'] += $item['quantity'] * $item['price'];
        }
        
        // Create P2P order for each store
        foreach ($groupedByStore as $store) {
            $orderNumber = generateOrderNumber('P2P');
            $shippingCost = SHIPPING_COST;
            $totalAmount = $store['subtotal'] + $shippingCost;
            
            // Insert P2P order
            $sql = "INSERT INTO p2p_orders (order_number, buyer_id, seller_store_id, subtotal, shipping_cost, total_amount, 
                    payment_status, shipping_status, shipping_address, created_at) 
                    VALUES (:order_number, :buyer_id, :seller_store_id, :subtotal, :shipping_cost, :total_amount, 
                    'held_in_escrow', 'pending', :shipping_address, NOW())";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':order_number', $orderNumber);
            $stmt->bindValue(':buyer_id', $customer['id'], PDO::PARAM_INT);
            $stmt->bindValue(':seller_store_id', $store['store_id'], PDO::PARAM_INT);
            $stmt->bindValue(':subtotal', $store['subtotal']);
            $stmt->bindValue(':shipping_cost', $shippingCost);
            $stmt->bindValue(':total_amount', $totalAmount);
            $stmt->bindValue(':shipping_address', $input['address'] ?? null);
            $stmt->execute();
            
            $orderId = $conn->lastInsertId();
            
            // Insert order items
            foreach ($store['items'] as $item) {
                $sql = "INSERT INTO p2p_order_items (p2p_order_id, customer_store_product_id, product_title, quantity, unit_price, line_total, item_status) 
                        VALUES (:order_id, :product_id, :product_title, :quantity, :unit_price, :line_total, 'delivered_ok')";
                
                $stmt = $conn->prepare($sql);
                $stmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
                $stmt->bindValue(':product_id', $item['variant_id'], PDO::PARAM_INT);
                $stmt->bindValue(':product_title', $item['product_name']);
                $stmt->bindValue(':quantity', $item['quantity']);
                $stmt->bindValue(':unit_price', $item['price']);
                $stmt->bindValue(':line_total', $item['quantity'] * $item['price']);
                $stmt->execute();
                
                // Update product stock
                $sql = "UPDATE customer_store_products SET stock_quantity = stock_quantity - :quantity 
                        WHERE id = :product_id";
                $stmt = $conn->prepare($sql);
                $stmt->bindValue(':quantity', $item['quantity']);
                $stmt->bindValue(':product_id', $item['variant_id'], PDO::PARAM_INT);
                $stmt->execute();
            }
            
            $orderNumbers[] = $orderNumber;
        }
    }
    
    // Process corporate items (existing B2C flow)
    if (!empty($corporateItems)) {
        $orderModel = new Order();
        $orderData = [
            'customer_id' => $customer['id'],
            'shipping_address' => $input['address'] ?? null,
            'shipping_city' => $input['city'] ?? null,
            'notes' => $input['notes'] ?? null
        ];
        
        $result = $orderModel->createOrder($orderData, $corporateItems);
        
        if (!$result['success']) {
            throw new Exception($result['message']);
        }
        
        $orderNumbers[] = $result['invoice_number'];
    }
    
    $conn->commit();
    
    // Clear cart
    SessionManager::clearCart();
    
    jsonResponse([
        'success' => true,
        'message' => 'Order placed successfully',
        'order_numbers' => $orderNumbers
    ]);
    
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollBack();
    }
    error_log("P2P Order Error: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => $e->getMessage()]);
}

function generateOrderNumber($prefix = 'P2P') {
    return $prefix . '-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}