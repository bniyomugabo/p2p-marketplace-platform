<?php
// /src/api/search_autocomplete.php
// Search autocomplete API

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../init.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$query = isset($_GET['q']) ? trim($_GET['q']) : '';

if (strlen($query) < 2) {
    jsonResponse(['success' => true, 'results' => []]);
}

try {
    $db = Database::getInstance();
    
    $sql = "SELECT DISTINCT
                p.id,
                p.product_name,
                p.product_code,
                MIN(v.selling_price) as min_price,
                c.currency,
                (SELECT vi.image_url 
                 FROM variant_images vi 
                 INNER JOIN variants v2 ON vi.variant_id = v2.id
                 WHERE v2.product_id = p.id 
                 AND vi.is_primary = 1 
                 LIMIT 1) as image
            FROM products p
            INNER JOIN companies c ON p.company_id = c.id
            INNER JOIN variants v ON v.product_id = p.id AND v.company_id = p.company_id
            WHERE c.is_public = 1
            AND c.is_active = 1
            AND p.is_active = 1
            AND v.is_active = 1
            AND (p.product_name LIKE :query OR p.product_code LIKE :query)
            GROUP BY p.id, p.product_name, p.product_code, c.currency
            ORDER BY 
                CASE 
                    WHEN p.product_name LIKE :exact THEN 1
                    WHEN p.product_name LIKE :starts THEN 2
                    ELSE 3
                END
            LIMIT 10";
    
    $searchTerm = '%' . $query . '%';
    $exactTerm = $query;
    $startsTerm = $query . '%';
    
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':query', $searchTerm);
    $stmt->bindValue(':exact', $exactTerm);
    $stmt->bindValue(':starts', $startsTerm);
    $stmt->execute();
    
    $results = $stmt->fetchAll();
    
    $formattedResults = array_map(function($item) {
        return [
            'id' => $item['id'],
            'product_name' => $item['product_name'],
            'product_code' => $item['product_code'],
            'price' => number_format($item['min_price'], 0, ',', ' ') . ' ' . ($item['currency'] ?? 'RWF'),
            'image' => $item['image'] ?? null
        ];
    }, $results);
    
    jsonResponse(['success' => true, 'results' => $formattedResults]);
    
} catch (PDOException $e) {
    error_log("Search autocomplete error: " . $e->getMessage());
    jsonResponse(['success' => true, 'results' => []]);
}