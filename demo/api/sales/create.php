<?php
// api/sales/create.php
// ============================================
// API: CREATE NEW SALE
// ============================================

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../models/Sale.php';
require_once __DIR__ . '/../../models/Customer.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

// Validate required fields
$required = ['customer_id', 'items'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Missing required field: {$field}"]);
        exit;
    }
}

// Validate items
if (!is_array($input['items']) || empty($input['items'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Items must be a non-empty array']);
    exit;
}

try {
    $saleModel = new Sale();

    $invoiceId = $saleModel->createSale(
        $input['customer_id'],
        $input['items'],
        $input['payment_method'] ?? 'cash',
        $_SESSION['user_id'] ?? 1
    );

    // Get the created invoice
    $invoice = $saleModel->getInvoiceWithDetails($invoiceId);

    echo json_encode([
        'success' => true,
        'message' => 'Sale created successfully',
        'data' => [
            'invoice_id' => $invoiceId,
            'invoice_number' => $invoice['invoice_number'],
            'total_amount' => $invoice['total_amount']
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to create sale: ' . $e->getMessage()
    ]);
}