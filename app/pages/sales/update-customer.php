<?php
// pages/sales/update-customer.php
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
    SessionManager::flash('error', 'You do not have permission to edit customers.');
    header('Location: ' . route_url('sales/customers'));
    exit;
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
    SessionManager::flash('error', 'Invalid security token. Please try again.');
    header('Location: ' . route_url('sales/customers'));
    exit;
}

// Check if customer ID is provided
if (empty($_POST['id'])) {
    SessionManager::flash('error', 'Customer ID is required.');
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

    // Validate phone if provided
    if (!empty($_POST['phone'])) {
        $phone = preg_replace('/[^0-9+]/', '', $_POST['phone']);
        if (strlen($phone) < 8) {
            throw new Exception('Please enter a valid phone number (at least 8 digits).');
        }
    }

    // Get customer ID
    $customerId = (int) $_POST['id'];

    // Check if customer exists and belongs to this company using the model's find method
    $existingCustomer = $customerModel->find($customerId);
    if (!$existingCustomer) {
        throw new Exception('Customer not found or does not belong to your company.');
    }

    // Check if email already exists for another customer in this company
    if (!empty($_POST['email'])) {
        $email = trim(strtolower($_POST['email']));
        $stmt = $db->prepare("
            SELECT id FROM customers 
            WHERE email = :email 
                AND company_id = :company_id 
                AND id != :customer_id
        ");
        $stmt->execute([
            'email' => $email,
            'company_id' => $companyId,
            'customer_id' => $customerId
        ]);
        if ($stmt->fetch()) {
            throw new Exception('Another customer with this email already exists in your company.');
        }
    }

    // Check if phone already exists for another customer in this company
    if (!empty($_POST['phone'])) {
        $phone = preg_replace('/[^0-9+]/', '', $_POST['phone']);
        $stmt = $db->prepare("
            SELECT id FROM customers 
            WHERE phone = :phone 
                AND company_id = :company_id 
                AND id != :customer_id
        ");
        $stmt->execute([
            'phone' => $phone,
            'company_id' => $companyId,
            'customer_id' => $customerId
        ]);
        if ($stmt->fetch()) {
            throw new Exception('Another customer with this phone number already exists in your company.');
        }
    }

    // Start transaction
    $db->beginTransaction();

    // Prepare update data (note: we don't update customer_code or created_at)
    $updateData = [
        'full_name' => trim($_POST['full_name']),
        'customer_type' => $_POST['customer_type'] ?? $existingCustomer['customer_type'],
        'phone' => !empty($_POST['phone']) ? preg_replace('/[^0-9+]/', '', trim($_POST['phone'])) : null,
        'email' => !empty($_POST['email']) ? trim(strtolower($_POST['email'])) : null,
        'address' => !empty($_POST['address']) ? trim($_POST['address']) : null,
        'city' => !empty($_POST['city']) ? trim($_POST['city']) : null,
        'tax_id' => !empty($_POST['tax_id']) ? trim($_POST['tax_id']) : null,
        'credit_limit' => isset($_POST['credit_limit']) ? (float) $_POST['credit_limit'] : 0.00,
        'notes' => !empty($_POST['notes']) ? trim($_POST['notes']) : null,
        'is_active' => isset($_POST['is_active']) ? (int) $_POST['is_active'] : $existingCustomer['is_active'],
        'updated_at' => date('Y-m-d H:i:s')
        // Note: current_balance is typically updated through transactions, not direct editing
    ];

    // Use the model's update method (inherited from BaseModel)
    $result = $customerModel->update($customerId, $updateData);

    if (!$result) {
        throw new Exception('Failed to update customer record.');
    }

    // Log activity
    $logStmt = $db->prepare("
        INSERT INTO activity_log 
        (company_id, user_id, action, entity_type, entity_id, new_data, old_data, created_at)
        VALUES (:company_id, :user_id, 'customer_updated', 'customer', :entity_id, :new_data, :old_data, NOW())
    ");

    // Track what changed
    $changes = [];
    $fields = ['full_name', 'customer_type', 'phone', 'email', 'address', 'city', 'tax_id', 'credit_limit', 'is_active'];
    foreach ($fields as $field) {
        $oldValue = $existingCustomer[$field] ?? null;
        $newValue = $updateData[$field] ?? null;
        if ($oldValue != $newValue) {
            $changes[$field] = [
                'old' => $oldValue,
                'new' => $newValue
            ];
        }
    }

    $logStmt->execute([
        'company_id' => $companyId,
        'user_id' => $userId,
        'entity_id' => $customerId,
        'new_data' => json_encode($updateData),
        'old_data' => json_encode(['changes' => $changes])
    ]);

    $db->commit();

    // Set success message and redirect
    SessionManager::flash('success', 'Customer updated successfully!');
    header('Location: ' . route_url('sales/customers'));
    exit;

} catch (Exception $e) {
    // Rollback transaction if active
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    // Log error
    error_log("Update customer error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());

    // Set error message and redirect
    SessionManager::flash('error', 'Failed to update customer: ' . $e->getMessage());
    header('Location: ' . route_url('sales/customers'));
    exit;
}