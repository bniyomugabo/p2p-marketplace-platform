<?php
// api/inventory/get-locations.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$warehouseId = $_GET['warehouse_id'] ?? 0;

if (!$warehouseId) {
    echo json_encode(['locations' => []]);
    exit;
}

try {
    $db = Database::getConnection();
    
    $sql = "SELECT id, location_code, location_name 
            FROM storage_location 
            WHERE warehouse_id = ? AND is_active = 1 
            ORDER BY location_code";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$warehouseId]);
    $locations = $stmt->fetchAll();
    
    echo json_encode(['locations' => $locations]);
    
} catch (Exception $e) {
    error_log("Get locations error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error occurred']);
}