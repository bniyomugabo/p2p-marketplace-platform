<?php
// api/inventory/check-stock.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Inventory.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$companyId = $_SESSION['company_id'] ?? null;
$variantId = isset($_GET['variant_id']) ? (int) $_GET['variant_id'] : 0;
$warehouseId = isset($_GET['warehouse_id']) ? (int) $_GET['warehouse_id'] : null;
$locationId = isset($_GET['location_id']) ? (int) $_GET['location_id'] : null;

if (!$variantId || !$warehouseId) {
    echo json_encode(['available_stock' => 0, 'error' => 'Missing parameters']);
    exit;
}

try {
    $inventoryModel = new Inventory($companyId);
    $availableStock = $inventoryModel->getAvailableQuantity($variantId, $warehouseId, $locationId);

    echo json_encode([
        'available_stock' => $availableStock,
        'variant_id' => $variantId,
        'warehouse_id' => $warehouseId,
        'location_id' => $locationId
    ]);

} catch (Exception $e) {
    error_log("Stock check error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}