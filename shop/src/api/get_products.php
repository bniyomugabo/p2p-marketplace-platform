<?php
// /src/api/get_products.php
// API endpoint to fetch products for the public storefront

// Include initialization
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../init.php';

// Allow CORS for public API
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    errorResponse('Method not allowed', 405);
}

try {
    // Initialize Product model
    $productModel = new Product();
    
    // Get request parameters with validation
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $perPage = isset($_GET['per_page']) ? min(MAX_ITEMS_PER_PAGE, max(1, (int)$_GET['per_page'])) : ITEMS_PER_PAGE;
    $categoryId = isset($_GET['category_id']) && (int)$_GET['category_id'] > 0 ? (int)$_GET['category_id'] : null;
    $search = isset($_GET['search']) && trim($_GET['search']) !== '' ? trim($_GET['search']) : null;
    $sort = isset($_GET['sort']) && in_array($_GET['sort'], ['price_asc', 'price_desc', 'newest', 'name_asc', 'name_desc']) 
            ? $_GET['sort'] : 'newest';
    
    // Get products
    $result = $productModel->getPublicProducts($page, $perPage, $categoryId, $search, $sort);
    
    // Build response
    $response = [
        'success' => true,
        'data' => [
            'products' => $result['products'],
            'pagination' => [
                'current_page' => $result['current_page'],
                'per_page' => $result['per_page'],
                'total' => $result['total'],
                'total_pages' => $result['total_pages'],
                'has_next' => $result['current_page'] < $result['total_pages'],
                'has_previous' => $result['current_page'] > 1
            ]
        ],
        'timestamp' => date('c')
    ];
    
    // Add optional filters info
    if ($categoryId) {
        $response['filters']['category_id'] = $categoryId;
    }
    if ($search) {
        $response['filters']['search'] = $search;
    }
    $response['filters']['sort'] = $sort;
    
    jsonResponse($response);
    
} catch (PDOException $e) {
    error_log("API Error - get_products.php: " . $e->getMessage());
    
    if (DEBUG_MODE) {
        errorResponse('Database error: ' . $e->getMessage(), 500);
    } else {
        errorResponse('Unable to fetch products. Please try again later.', 500);
    }
} catch (Exception $e) {
    error_log("API Error - get_products.php: " . $e->getMessage());
    errorResponse('An unexpected error occurred', 500);
}