<?php
// api/search.php
// Global search API for products, customers, and invoices
declare(strict_types=1);

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

// Set content type early
header('Content-Type: application/json');

// Simple test response to check if file is working
// Uncomment the line below to test if the file loads
// echo json_encode(['test' => 'API is working', 'term' => $_GET['term'] ?? '']); exit;

require_once __DIR__ . '/../../config/autoload.php';

// Check if autoload loaded successfully
if (!class_exists('Database')) {
    echo json_encode(['error' => 'Autoload failed: Database class not found']);
    exit;
}

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$userId = $_SESSION['user_id'];
$companyId = $_SESSION['company_id'] ?? null;
$term = trim($_GET['term'] ?? '');

if (!$companyId) {
    http_response_code(400);
    echo json_encode(['error' => 'Company context not found']);
    exit;
}

if (strlen($term) < 2) {
    echo json_encode(['products' => [], 'customers' => [], 'invoices' => []]);
    exit;
}

$results = ['products' => [], 'customers' => [], 'invoices' => []];

try {
    $db = Database::getInstance();

    // Test database connection
    if (!$db) {
        throw new Exception('Database connection failed');
    }

    // Search Products
    $productSql = "
        SELECT 
            p.id,
            p.product_name,
            p.product_code
        FROM products p
        WHERE p.company_id = :company_id
            AND p.is_active = 1
            AND (p.product_name LIKE :p_1_term OR p.product_code LIKE :p_2_term)
        LIMIT 5
    ";

    $stmt = $db->prepare($productSql);
    $stmt->execute([
        ':company_id' => $companyId,
        ':p_1_term' => "%{$term}%",
        ':p_2_term' => "%{$term}%"
    ]);
    $results['products'] = $stmt->fetchAll();

    // Search Customers
    $customerSql = "
        SELECT 
            id,
            customer_code,
            full_name,
            phone,
            email
        FROM customers
        WHERE company_id = :company_id
            AND is_active = 1
            AND (full_name LIKE :p_1_term OR customer_code LIKE :p_2_term OR phone LIKE :p_3_term OR email LIKE :p_4_term)
        LIMIT 5
    ";

    $stmt = $db->prepare($customerSql);
    $stmt->execute([
        ':company_id' => $companyId,
        ':p_1_term' => "%{$term}%",
        ':p_2_term' => "%{$term}%",
        ':p_3_term' => "%{$term}%",
        ':p_4_term' => "%{$term}%"
    ]);
    $results['customers'] = $stmt->fetchAll();

    // Search Invoices
    $invoiceSql = "
        SELECT 
            si.id,
            si.invoice_number,
            si.total_amount,
            si.status,
            c.full_name as customer_name
        FROM sales_invoices si
        JOIN customers c ON si.customer_id = c.id
        WHERE si.company_id = :company_id
            AND si.status != 'cancelled'
            AND (si.invoice_number LIKE :p_1_term OR c.full_name LIKE :p_2_term)
        LIMIT 5
    ";

    $stmt = $db->prepare($invoiceSql);
    $stmt->execute([
        ':company_id' => $companyId,
        ':p_1_term' => "%{$term}%",
        ':p_2_term' => "%{$term}%"
    ]);
    $results['invoices'] = $stmt->fetchAll();

    echo json_encode($results);

} catch (Exception $e) {
    error_log("Search API error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());

    http_response_code(500);
    echo json_encode([
        'error' => 'Search failed',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}