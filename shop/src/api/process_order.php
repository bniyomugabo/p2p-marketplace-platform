<?php
// /src/api/process_order.php
// Order processing API with transaction safety

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../init.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    jsonResponse(['success' => false, 'message' => 'Invalid request data']);
}

// Verify CSRF token
if (!SessionManager::verifyCsrfToken($input['csrf_token'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'Security validation failed']);
}

$cart = SessionManager::getCart();

if (empty($cart)) {
    jsonResponse(['success' => false, 'message' => 'Cart is empty']);
}

// Prepare cart items
$cartItems = [];
foreach ($cart as $variantId => $item) {
    $productModel = new Product();
    $variantInfo = $productModel->getVariantById($variantId);
    
    if (!$variantInfo) {
        jsonResponse(['success' => false, 'message' => "Product not found: {$item['product_name']}"]);
    }
    
    $cartItems[] = [
        'variant_id' => $variantId,
        'quantity' => $item['quantity'],
        'price' => $item['price'],
        'product_name' => $item['product_name'],
        'tax_rate' => DEFAULT_TAX_RATE
    ];
}

// Prepare order data
$orderData = [
    'customer_id' => $input['customer_id'] ?? null,
    'shipping_address' => $input['address'] ?? null,
    'shipping_city' => $input['city'] ?? null,
    'shipping_phone' => $input['phone'] ?? null,
    'notes' => $input['notes'] ?? null,
    'shipping_cost' => $subtotal = SessionManager::getCartSubtotal() >= FREE_SHIPPING_THRESHOLD ? 0 : SHIPPING_COST
];

// Create order
$orderModel = new Order();
$result = $orderModel->createOrder($orderData, $cartItems);

if ($result['success']) {
    // Clear cart after successful order
    SessionManager::clearCart();
    
    jsonResponse([
        'success' => true,
        'message' => 'Order placed successfully',
        'invoice_id' => $result['invoice_id'],
        'invoice_number' => $result['invoice_number'],
        'total_amount' => $result['total_amount']
    ]);
} else {
    jsonResponse(['success' => false, 'message' => $result['message']]);
}