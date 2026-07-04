<?php
// pages/purchasing/save-supplier.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Supplier.php';


// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/auth/signin.php');
    exit;
}

$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR', 'ACC'])) {
    SessionManager::flash('error', 'Permission denied.');
    header('Location: ' . route_url('purchasing/suppliers'));
    exit;
}

// Check if company context is set
if (!$companyId) {
    SessionManager::flash('error', 'Company context not found. Please log in again.');
    header('Location: ' . route_url('login'));
    exit;
}

// Validate CSRF token
if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
    SessionManager::flash('error', 'Invalid security token. Please try again.');
    header('Location: ' . route_url('purchasing/suppliers'));
    exit;
}

$db = Database::getInstance();
$supplierModel = new Supplier($companyId);

try {
    // Validate required fields
    if (empty($_POST['supplier_name'])) {
        throw new Exception('Supplier name is required.');
    }

    // Trim and sanitize input
    $supplierName = trim($_POST['supplier_name']);

    // Check if supplier name already exists for this company
    if ($supplierModel->existsByName($supplierName)) {
        throw new Exception('A supplier with this name already exists for your company.');
    }

    // Validate email if provided
    if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Please provide a valid email address.');
    }

    // Validate phone if provided (basic validation)
    if (!empty($_POST['phone']) && !preg_match('/^[0-9+\-\s()]+$/', $_POST['phone'])) {
        throw new Exception('Please provide a valid phone number.');
    }

    // Validate initial balance
    $initialBalance = 0;
    if (!empty($_POST['initial_balance'])) {
        $initialBalance = (float) $_POST['initial_balance'];
        if ($initialBalance < 0) {
            throw new Exception('Initial balance cannot be negative.');
        }
    }

    $db->beginTransaction();

    // Prepare supplier data with company_id
    $supplierData = [
        'company_id' => $companyId,
        'supplier_code' => $supplierModel->generateCode(), // Already company-specific
        'supplier_name' => $supplierName,
        'contact_person' => !empty($_POST['contact_person']) ? trim($_POST['contact_person']) : null,
        'phone' => !empty($_POST['phone']) ? trim($_POST['phone']) : null,
        'email' => !empty($_POST['email']) ? trim($_POST['email']) : null,
        'address' => !empty($_POST['address']) ? trim($_POST['address']) : null,
        'tax_id' => !empty($_POST['tax_id']) ? trim($_POST['tax_id']) : null,
        'payment_terms' => !empty($_POST['payment_terms']) ? $_POST['payment_terms'] : null,
        'balance' => $initialBalance,
        'is_active' => isset($_POST['is_active']) ? 1 : 1 // Default to active if checkbox present
    ];

    // Create the supplier
    $supplierId = $supplierModel->create($supplierData);

    // Log the activity (optional)
    $activitySql = "
        INSERT INTO activity_log (company_id, user_id, action, entity_type, entity_id, new_data, created_at)
        VALUES (:company_id, :user_id, 'supplier_created', 'supplier', :supplier_id, :new_data, NOW())
    ";

    $activityStmt = $db->prepare($activitySql);
    $activityStmt->execute([
        'company_id' => $companyId,
        'user_id' => $userId,
        'supplier_id' => $supplierId,
        'new_data' => json_encode(['supplier_name' => $supplierName, 'supplier_code' => $supplierData['supplier_code']])
    ]);

    $db->commit();

    SessionManager::flash('success', 'Supplier "' . htmlspecialchars($supplierName) . '" added successfully!');

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Save supplier error for company {$companyId}: " . $e->getMessage());
    SessionManager::flash('error', 'Failed to save supplier: ' . $e->getMessage());
}

// Redirect back to suppliers page
header('Location: ' . route_url('purchasing/suppliers'));
exit;