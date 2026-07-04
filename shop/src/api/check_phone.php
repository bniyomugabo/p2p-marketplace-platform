<?php
// /src/api/check_phone.php
// Check if phone number already exists in the system

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../init.php';

// Set headers
header('Content-Type: application/json');
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
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use GET.'
    ]);
    exit;
}

// Get phone number from query parameter
$phone = isset($_GET['phone']) ? trim($_GET['phone']) : '';
$excludeId = isset($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : 0;

// Validate phone number
if (empty($phone)) {
    echo json_encode([
        'success' => true,
        'exists' => false,
        'valid' => false,
        'message' => 'Phone number is required'
    ]);
    exit;
}

// Basic phone validation (international format or local)
$phoneRegex = '/^[0-9+\-\s()]{8,20}$/';
if (!preg_match($phoneRegex, $phone)) {
    echo json_encode([
        'success' => true,
        'exists' => false,
        'valid' => false,
        'message' => 'Invalid phone number format'
    ]);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Check if phone exists in customers table (company_id = 0 for marketplace customers)
    $sql = "SELECT COUNT(*) as count FROM customers WHERE phone = :phone AND company_id = 0";
    $params = [':phone' => $phone];
    
    // Exclude current customer ID when updating profile
    if ($excludeId > 0) {
        $sql .= " AND id != :exclude_id";
        $params[':exclude_id'] = $excludeId;
    }
    
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    
    $result = $stmt->fetch();
    $exists = $result['count'] > 0;
    
    echo json_encode([
        'success' => true,
        'exists' => $exists,
        'valid' => true,
        'phone' => $phone,
        'message' => $exists ? 'Phone number already registered' : 'Phone number available'
    ]);
    
} catch (PDOException $e) {
    error_log("Check phone API error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'exists' => false,
        'valid' => false,
        'message' => 'Database error. Please try again.'
    ]);
}