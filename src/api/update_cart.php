<?php
// /src/api/update_cart.php
// Cart management API

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

$action = $input['action'] ?? null;

switch ($action) {
    case 'add':
        $result = handleAddToCart($input);
        break;
    case 'update':
        $result = handleUpdateCart($input);
        break;
    case 'remove':
        $result = handleRemoveFromCart($input);
        break;
    default:
        $result = ['success' => false, 'message' => 'Invalid action'];
}

jsonResponse($result);

function handleAddToCart($data) {
    $variantId = (int)($data['variant_id'] ?? 0);
    $quantity = (int)($data['quantity'] ?? 1);
    $productName = $data['product_name'] ?? '';
    $variantName = $data['variant_name'] ?? '';
    $sellerType = $data['seller_type'] ?? 'corporate';
    $sellerId = $data['seller_id'] ?? null;
    $sellerName = $data['seller_name'] ?? null;

    
    $price = (float)($data['price'] ?? 0);
    
    if ($variantId <= 0 || $quantity <= 0) {
        return ['success' => false, 'message' => 'Invalid product or quantity'];
    }
    
    // Validate stock
    $productModel = new Product();
    $stockCheck = $productModel->validateStock($variantId, $quantity);
    
    if (!$stockCheck['valid']) {
        return ['success' => false, 'message' => $stockCheck['message']];
    }
    
    $item = [
        'variant_id' => $variantId,
        'product_name' => $productName,
        'variant_name' => $variantName,
        'quantity' => $quantity,
        'price' => $price,
        'seller_type' => $sellerType,
        'seller_id' => $sellerId,
        'seller_name' => $sellerName
    ];
    
    $cartCount = SessionManager::addToCart($item);
    
    return [
        'success' => true,
        'message' => 'Product added to cart',
        'cart_count' => $cartCount
    ];
}

function handleUpdateCart($data) {
    $variantId = (int)($data['variant_id'] ?? 0);
    $quantity = (int)($data['quantity'] ?? 0);
    
    if ($variantId <= 0) {
        return ['success' => false, 'message' => 'Invalid product'];
    }
    
    if ($quantity > 0) {
        // Validate stock for new quantity
        $productModel = new Product();
        $stockCheck = $productModel->validateStock($variantId, $quantity);
        
        if (!$stockCheck['valid']) {
            return ['success' => false, 'message' => $stockCheck['message']];
        }
    }
    
    $cartCount = SessionManager::updateCartQuantity($variantId, $quantity);
    
    return [
        'success' => true,
        'message' => 'Cart updated',
        'cart_count' => $cartCount
    ];
}

function handleRemoveFromCart($data) {
    $variantId = (int)($data['variant_id'] ?? 0);
    
    if ($variantId <= 0) {
        return ['success' => false, 'message' => 'Invalid product'];
    }
    
    $cartCount = SessionManager::removeFromCart($variantId);
    
    return [
        'success' => true,
        'message' => 'Product removed from cart',
        'cart_count' => $cartCount
    ];
}