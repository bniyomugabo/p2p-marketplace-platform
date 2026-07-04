<?php
// api/products/search.php - Updated with multi-company support
declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Variant.php';
require_once __DIR__ . '/../../models/Product.php';

header('Content-Type: application/json');

try {
    $term = $_GET['term'] ?? '';
    $companyId = $_SESSION['company_id'] ?? null;

    if (strlen($term) < 2) {
        echo json_encode([]);
        exit;
    }

    $variantModel = new Variant($companyId);
    $results = $variantModel->search($term);

    // Format results
    $formatted = [];
    foreach ($results as $item) {
        $formatted[] = [
            'id' => $item['id'],
            'product_id' => $item['product_id'],
            'product_name' => $item['product_name'],
            'variant_name' => $item['variant_name'],
            'sku' => $item['sku'],
            'barcode' => $item['barcode'] ?? null,
            'available_stock' => $item['available_stock'] ?? 0,
            'selling_price' => $item['selling_price'] ?? 0,
            'purchase_price' => $item['purchase_price'] ?? 0
        ];
    }

    echo json_encode($formatted);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>