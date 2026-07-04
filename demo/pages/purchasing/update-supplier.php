<?php
// pages/purchasing/update-supplier.php
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

// Validate supplier ID
if (empty($_POST['supplier_id'])) {
    SessionManager::flash('error', 'Supplier ID is required.');
    header('Location: ' . route_url('purchasing/suppliers'));
    exit;
}

$db = Database::getInstance();
$supplierModel = new Supplier($companyId);
$supplierId = (int) $_POST['supplier_id'];

try {
    // Verify supplier exists and belongs to company
    $existingSupplier = $supplierModel->find($supplierId);
    if (!$existingSupplier) {
        throw new Exception('Supplier not found or does not belong to your company.');
    }

    // Validate required fields
    if (empty($_POST['supplier_name'])) {
        throw new Exception('Supplier name is required.');
    }

    // Trim and sanitize input
    $supplierName = trim($_POST['supplier_name']);

    // Check if supplier name already exists for this company (excluding current supplier)
    if ($supplierModel->existsByName($supplierName, $supplierId)) {
        throw new Exception('A supplier with this name already exists for your company.');
    }

    // Validate email if provided
    if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Please provide a valid email address.');
    }

    // Validate phone if provided
    if (!empty($_POST['phone']) && !preg_match('/^[0-9+\-\s()]+$/', $_POST['phone'])) {
        throw new Exception('Please provide a valid phone number.');
    }

    $db->beginTransaction();

    // Prepare update data (don't change balance or code)
    $updateData = [
        'supplier_name' => $supplierName,
        'contact_person' => !empty($_POST['contact_person']) ? trim($_POST['contact_person']) : null,
        'phone' => !empty($_POST['phone']) ? trim($_POST['phone']) : null,
        'email' => !empty($_POST['email']) ? trim($_POST['email']) : null,
        'address' => !empty($_POST['address']) ? trim($_POST['address']) : null,
        'tax_id' => !empty($_POST['tax_id']) ? trim($_POST['tax_id']) : null,
        'payment_terms' => !empty($_POST['payment_terms']) ? $_POST['payment_terms'] : null
    ];

    // Update the supplier
    $result = $supplierModel->update($supplierId, $updateData);

    if (!$result) {
        throw new Exception('Failed to update supplier.');
    }

    // Log the activity
    $activitySql = "
        INSERT INTO activity_log (company_id, user_id, action, entity_type, entity_id, old_data, new_data, created_at)
        VALUES (:company_id, :user_id, 'supplier_updated', 'supplier', :supplier_id, :old_data, :new_data, NOW())
    ";

    $activityStmt = $db->prepare($activitySql);
    $activityStmt->execute([
        'company_id' => $companyId,
        'user_id' => $userId,
        'supplier_id' => $supplierId,
        'old_data' => json_encode(['supplier_name' => $existingSupplier['supplier_name']]),
        'new_data' => json_encode(['supplier_name' => $supplierName])
    ]);

    $db->commit();

    SessionManager::flash('success', 'Supplier "' . htmlspecialchars($supplierName) . '" updated successfully!');

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Update supplier error for company {$companyId}, supplier {$supplierId}: " . $e->getMessage());
    SessionManager::flash('error', 'Failed to update supplier: ' . $e->getMessage());
}

// Redirect back to suppliers page with filters preserved
$redirectUrl = route_url('purchasing/suppliers');
if (isset($_GET['status'])) {
    $redirectUrl .= '&status=' . urlencode($_GET['status']);
}
if (isset($_GET['search'])) {
    $redirectUrl .= '&search=' . urlencode($_GET['search']);
}
header('Location: ' . $redirectUrl);
exit;