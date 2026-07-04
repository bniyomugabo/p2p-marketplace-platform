<?php
// /src/api/get_cart.php
// Get current cart count and contents

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../init.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$cart = SessionManager::getCart();
$cartItems = [];
$subtotal = 0;

foreach ($cart as $variantId => $item) {
    $itemSubtotal = $item['quantity'] * $item['price'];
    $subtotal += $itemSubtotal;
    
    $cartItems[] = [
        'variant_id' => $variantId,
        'product_name' => $item['product_name'],
        'variant_name' => $item['variant_name'] ?? '',
        'quantity' => $item['quantity'],
        'price' => $item['price'],
        'subtotal' => $itemSubtotal
    ];
}

jsonResponse([
    'success' => true,
    'cart_count' => SessionManager::getCartCount(),
    'cart_items' => $cartItems,
    'subtotal' => $subtotal
]);