<?php
// api/sales/search.php - Enhanced with better search and stock information
declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Variant.php';
require_once __DIR__ . '/../../models/Product.php';
require_once __DIR__ . '/../../models/Inventory.php';
require_once __DIR__ . '/../../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../../middleware/RateLimitMiddleware.php';


// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
header('Content-Type: application/json');


// Apply rate limiting for API requests
RateLimitMiddleware::throttle('api', 60, 1); // 60 requests per minute

// Log the request for debugging
error_log("Search API called - " . date('Y-m-d H:i:s'));


// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $term = trim($_GET['term'] ?? '');
    $companyId = $_SESSION['company_id'] ?? null;
    $warehouseId = isset($_GET['warehouse_id']) ? (int) $_GET['warehouse_id'] : null;
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;

    if (strlen($term) < 2) {
        echo json_encode([]);
        exit;
    }

    if (!$companyId) {
        http_response_code(400);
        echo json_encode(['error' => 'Company context required']);
        exit;
    }

    $db = Database::getInstance();

    // Build search query with stock information
    $sql = "
        SELECT 
            v.id as variant_id,
            v.product_id,
            v.sku,
            v.barcode,
            v.variant_name,
            v.selling_price,
            v.purchase_price,
            v.tax_rate,
            p.product_name,
            p.product_code,
            p.description as product_description,
            COALESCE(SUM(i.quantity - i.committed_quantity), 0) as available_stock,
            COUNT(DISTINCT i.warehouse_id) as warehouses_with_stock
        FROM variants v
        INNER JOIN products p ON v.product_id = p.id AND p.company_id = :p_1_company_id
        LEFT JOIN inventory i ON v.id = i.variant_id AND i.company_id = :p_2_company_id
    ";

    $params = ['p_1_company_id' => $companyId, 'p_2_company_id' => $companyId];

    // Add warehouse filter if provided
    if ($warehouseId) {
        $sql .= " AND (i.warehouse_id = :warehouse_id OR i.warehouse_id IS NULL)";
        $params['warehouse_id'] = $warehouseId;
    }

    $sql .= "
        WHERE v.is_active = 1 
            AND p.is_active = 1
            AND (
                p.product_name LIKE :p_1_search 
                OR v.sku LIKE :p_2_search 
                OR v.barcode LIKE :p_3_search
                OR v.variant_name LIKE :p_4_search
                OR p.product_name LIKE :p_5_search
                OR p.product_code LIKE :p_6_search
            )
        GROUP BY v.id
        ORDER BY 
            CASE 
                WHEN p.product_name = :p_1_exact THEN 1
                WHEN v.sku = :p_2_exact THEN 2
                WHEN v.barcode = :p_3_exact THEN 3
                WHEN p.product_name LIKE :p_4_starts_with THEN 4
                ELSE 5
            END,
            p.product_name,
            v.variant_name
        LIMIT :limit
    ";

    $searchParam = "%{$term}%";
    $exactParam = $term;
    $startsWithParam = "{$term}%";

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':p_1_company_id', $companyId, PDO::PARAM_INT);
    $stmt->bindValue(':p_2_company_id', $companyId, PDO::PARAM_INT);
    $stmt->bindValue(':p_1_search', $searchParam, PDO::PARAM_STR);
    $stmt->bindValue(':p_2_search', $searchParam, PDO::PARAM_STR);
    $stmt->bindValue(':p_3_search', $searchParam, PDO::PARAM_STR);
    $stmt->bindValue(':p_4_search', $searchParam, PDO::PARAM_STR);
    $stmt->bindValue(':p_5_search', $searchParam, PDO::PARAM_STR);
    $stmt->bindValue(':p_6_search', $searchParam, PDO::PARAM_STR);
    $stmt->bindValue(':p_1_exact', $exactParam, PDO::PARAM_STR);
    $stmt->bindValue(':p_2_exact', $exactParam, PDO::PARAM_STR);
    $stmt->bindValue(':p_3_exact', $exactParam, PDO::PARAM_STR);
    $stmt->bindValue(':p_4_starts_with', $startsWithParam, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);

    if ($warehouseId) {
        $stmt->bindValue(':warehouse_id', $warehouseId, PDO::PARAM_INT);
    }

    $stmt->execute();
    $results = $stmt->fetchAll();

    // Format results for autocomplete
    $formatted = [];
    foreach ($results as $item) {
        // Build display name
        $displayName = $item['product_name'];
        if (!empty($item['variant_name']) && $item['variant_name'] !== 'Standard') {
            $displayName .= ' - ' . $item['variant_name'];
        }

        // Determine stock status
        $stockStatus = 'in_stock';
        $stockStatusText = 'In Stock';
        $stockStatusClass = 'success';

        if ($item['available_stock'] <= 0) {
            $stockStatus = 'out_of_stock';
            $stockStatusText = 'Out of Stock';
            $stockStatusClass = 'danger';
        } elseif ($item['available_stock'] <= 5) {
            $stockStatus = 'low_stock';
            $stockStatusText = 'Low Stock';
            $stockStatusClass = 'warning';
        }

        $formatted[] = [
            'id' => (int) $item['variant_id'],
            'product_id' => (int) $item['product_id'],
            'product_name' => $item['product_name'],
            'variant_name' => $item['variant_name'] ?: 'Standard',
            'display_name' => $displayName,
            'sku' => $item['sku'],
            'barcode' => $item['barcode'],
            'selling_price' => (float) $item['selling_price'],
            'purchase_price' => (float) $item['purchase_price'],
            'tax_rate' => (float) $item['tax_rate'],
            'available_stock' => (int) $item['available_stock'],
            'stock_status' => $stockStatus,
            'stock_status_text' => $stockStatusText,
            'stock_status_class' => $stockStatusClass,
            'warehouses_with_stock' => (int) $item['warehouses_with_stock']
        ];
    }

    echo json_encode($formatted);

} catch (Exception $e) {
    error_log("Product search error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['error' => 'An error occurred while searching for products']);
}