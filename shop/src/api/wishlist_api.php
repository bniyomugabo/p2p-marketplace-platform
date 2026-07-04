<?php
// /src/api/wishlist_api.php
// Wishlist API endpoints

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../init.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$customer = SessionManager::getCustomer();

if (!$customer) {
    jsonResponse(['success' => false, 'message' => 'Please login first']);
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_GET['action'] ?? null;

$wishlistModel = new Wishlist();

switch ($action) {
    case 'add':
        $productId = $input['product_id'] ?? null;
        $variantId = $input['variant_id'] ?? null;
        
        if (!$productId) {
            jsonResponse(['success' => false, 'message' => 'Product ID required']);
        }
        
        $result = $wishlistModel->add($customer['id'], $productId, $variantId);
        jsonResponse($result);
        break;
        
    case 'remove':
        $productId = $input['product_id'] ?? null;
        
        if (!$productId) {
            jsonResponse(['success' => false, 'message' => 'Product ID required']);
        }
        
        $result = $wishlistModel->remove($customer['id'], $productId);
        jsonResponse($result);
        break;
        
    case 'clear':
        $result = $wishlistModel->clear($customer['id']);
        jsonResponse($result);
        break;
        
    case 'check':
        $productId = $_GET['product_id'] ?? null;
        
        if (!$productId) {
            jsonResponse(['success' => false, 'message' => 'Product ID required']);
        }
        
        $inWishlist = $wishlistModel->isInWishlist($customer['id'], $productId);
        jsonResponse(['success' => true, 'in_wishlist' => $inWishlist]);
        break;
        
    default:
        // Get wishlist
        $items = $wishlistModel->getWishlist($customer['id']);
        $count = $wishlistModel->getCount($customer['id']);
        jsonResponse(['success' => true, 'items' => $items, 'count' => $count]);
        break;
}