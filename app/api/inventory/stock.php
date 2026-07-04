<?php
// api/inventory/get-available-qty.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';

// Only accept GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get parameters
$variantId = $_GET['variant_id'] ?? 0;
$warehouseId = $_GET['warehouse_id'] ?? 0;

if (!$variantId || !$warehouseId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameters']);
    exit;
}

try {
    $db = Database::getConnection();

    $sql = "SELECT 
                COALESCE(quantity, 0) as quantity,
                COALESCE(committed_quantity, 0) as committed_quantity,
                COALESCE(quantity - committed_quantity, 0) as available_quantity,
                avg_cost,
                last_cost
            FROM inventory_balance 
            WHERE variant_id = ? AND warehouse_id = ?";

    $stmt = $db->prepare($sql);
    $stmt->execute([$variantId, $warehouseId]);
    $result = $stmt->fetch();

    if ($result) {
        echo json_encode([
            'success' => true,
            'quantity' => (float) $result['quantity'],
            'committed_quantity' => (float) $result['committed_quantity'],
            'available_qty' => (float) $result['available_quantity'],
            'avg_cost' => (float) $result['avg_cost'],
            'last_cost' => (float) $result['last_cost']
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'quantity' => 0,
            'committed_quantity' => 0,
            'available_qty' => 0,
            'avg_cost' => 0,
            'last_cost' => 0
        ]);
    }

} catch (Exception $e) {
    error_log("Get available quantity error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error occurred']);
}