<?php
// pages/sales/save-customer.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Customer.php';



// Check authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/auth/signin.php');
    exit;
}

$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR', 'SEL', 'ACC'])) {
    SessionManager::flash('error', 'You do not have permission to add customers.');
    header('Location: ' . route_url('sales/customers'));
    exit;
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
    SessionManager::flash('error', 'Invalid security token. Please try again.');
    header('Location: ' . route_url('sales/customers'));
    exit;
}

// Get database connection
$db = Database::getInstance();
$customerModel = new Customer($companyId);

try {
    // Validate required fields
    if (empty($_POST['full_name'])) {
        throw new Exception('Customer name is required.');
    }

    // Validate email if provided
    if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Please enter a valid email address.');
    }

    // Check if email already exists for this company
    if (!empty($_POST['email'])) {
        $existingCustomer = $customerModel->findByEmail(trim($_POST['email']));
        if ($existingCustomer) {
            throw new Exception('A customer with this email already exists in your company.');
        }
    }

    // Validate phone if provided (basic validation)
    if (!empty($_POST['phone'])) {
        $phone = preg_replace('/[^0-9+]/', '', $_POST['phone']);
        if (strlen($phone) < 8) {
            throw new Exception('Please enter a valid phone number (at least 8 digits).');
        }

        // Check if phone already exists for this company
        $existingCustomer = $customerModel->findByPhone($phone);
        if ($existingCustomer) {
            throw new Exception('A customer with this phone number already exists in your company.');
        }
    }

    // Start transaction
    $db->beginTransaction();

    // Generate unique customer code using the model (company-specific)
    $customerCode = $customerModel->generateCode();

    // Prepare customer data with company_id
    $customerData = [
        'company_id' => $companyId,
        'customer_code' => $customerCode,
        'full_name' => trim($_POST['full_name']),
        'customer_type' => $_POST['customer_type'] ?? 'individual',
        'phone' => !empty($_POST['phone']) ? trim($_POST['phone']) : null,
        'email' => !empty($_POST['email']) ? trim(strtolower($_POST['email'])) : null,
        'address' => !empty($_POST['address']) ? trim($_POST['address']) : null,
        'city' => !empty($_POST['city']) ? trim($_POST['city']) : null,
        'tax_id' => !empty($_POST['tax_id']) ? trim($_POST['tax_id']) : null,
        'credit_limit' => !empty($_POST['credit_limit']) ? (float) $_POST['credit_limit'] : 0.00,
        'current_balance' => !empty($_POST['initial_balance']) ? (float) $_POST['initial_balance'] : 0.00,
        'notes' => !empty($_POST['notes']) ? trim($_POST['notes']) : null,
        'is_active' => isset($_POST['is_active']) ? 1 : 1, // Default to active
        'created_by' => $userId,
        'created_at' => date('Y-m-d H:i:s')
    ];

    // Use the model's create method (inherited from BaseModel) to insert the customer
    $customerId = $customerModel->create($customerData);

    if (!$customerId) {
        throw new Exception('Failed to create customer record.');
    }

    // Log activity
    $logStmt = $db->prepare("
        INSERT INTO activity_log 
        (company_id, user_id, action, entity_type, entity_id, new_data, created_at)
        VALUES (:company_id, :user_id, 'customer_created', 'customer', :entity_id, :new_data, NOW())
    ");

    $logStmt->execute([
        'company_id' => $companyId,
        'user_id' => $userId,
        'entity_id' => $customerId,
        'new_data' => json_encode([
            'customer_code' => $customerCode,
            'full_name' => $customerData['full_name'],
            'customer_type' => $customerData['customer_type']
        ])
    ]);

    $db->commit();

    // Set success message and redirect
    SessionManager::flash('success', 'Customer added successfully! Customer code: ' . $customerCode);

    // Check if we need to redirect back to sales/create page
    if (!empty($_POST['take_me_back'])) {
        header('Location: ' . route_url('sales/create', ['customer_id' => $customerId]));
    } else {
        header('Location: ' . route_url('sales/customers'));
    }
    exit;

} catch (Exception $e) {
    // Rollback transaction if active
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    // Log error
    error_log("Save customer error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());

    // Set error message and redirect
    SessionManager::flash('error', 'Failed to save customer: ' . $e->getMessage());

    if (!empty($_POST['take_me_back'])) {
        header('Location: ' . route_url('sales/create'));
    } else {
        header('Location: ' . route_url('sales/customers'));
    }
    exit;
}