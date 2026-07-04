<?php
// /src/api/get_product_detail.php
// Get single product details for AJAX requests

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../init.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($productId <= 0) {
    jsonResponse(['success' => false, 'message' => 'Invalid product ID']);
}

try {
    $productModel = new Product();
    $product = $productModel->getProductById($productId);
    
    if (!$product) {
        jsonResponse(['success' => false, 'message' => 'Product not found']);
    }
    
    // Format response
    $response = [
        'success' => true,
        'product' => [
            'id' => $product['id'],
            'product_code' => $product['product_code'],
            'product_name' => $product['product_name'],
            'description' => $product['description'],
            'long_description' => $product['long_description'],
            'brand' => $product['brand'],
            'company_name' => $product['company_name'],
            'variants' => array_map(function($variant) {
                return [
                    'id' => $variant['id'],
                    'sku' => $variant['sku'],
                    'variant_name' => $variant['variant_name'],
                    'selling_price' => $variant['selling_price'],
                    'stock_quantity' => $variant['stock_quantity'],
                    'in_stock' => $variant['in_stock']
                ];
            }, $product['variants']),
            'images' => $product['images']
        ]
    ];
    
    jsonResponse($response);
    
} catch (Exception $e) {
    error_log("Get product detail error: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Failed to fetch product details']);
}