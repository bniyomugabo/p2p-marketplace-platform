<?php
// api/roles/update.php
// Update an existing role

declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/UserRole.php';

header('Content-Type: application/json');


// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Only Admins can update roles
if ($userRole !== 'ADM') {
    echo json_encode(['success' => false, 'message' => 'Permission denied.']);
    exit;
}

// Check if company context is set
if (!$companyId) {
    echo json_encode(['success' => false, 'message' => 'Company context not found.']);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

// Validate CSRF token
if (!isset($input['csrf_token']) || !CSRF::validate($input['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit;
}

$roleId = isset($input['role_id']) ? (int) $input['role_id'] : 0;
$roleName = isset($input['role_name']) ? trim($input['role_name']) : '';
$description = isset($input['description']) ? trim($input['description']) : '';

if (!$roleId) {
    echo json_encode(['success' => false, 'message' => 'Role ID is required.']);
    exit;
}

if (empty($roleName)) {
    echo json_encode(['success' => false, 'message' => 'Role name is required.']);
    exit;
}

if (strlen($roleName) > 50) {
    echo json_encode(['success' => false, 'message' => 'Role name must be 50 characters or less.']);
    exit;
}

try {
    $roleModel = new UserRole($companyId);

    // Verify role belongs to company
    $role = $roleModel->find($roleId);
    if (!$role) {
        echo json_encode(['success' => false, 'message' => 'Role not found.']);
        exit;
    }

    if ($role['company_id'] !== null && $role['company_id'] != $companyId) {
        echo json_encode(['success' => false, 'message' => 'Role does not belong to your company.']);
        exit;
    }

    if ($role['is_system_role']) {
        echo json_encode(['success' => false, 'message' => 'System roles cannot be modified.']);
        exit;
    }

    // Check if role name already exists for this company (excluding current role)
    $existingRoleByName = $roleModel->findByName($roleName);
    if ($existingRoleByName && $existingRoleByName['id'] != $roleId && $existingRoleByName['company_id'] == $companyId) {
        echo json_encode(['success' => false, 'message' => "Role name '{$roleName}' already exists in your company."]);
        exit;
    }

    // Start transaction
    $db = Database::getInstance();
    $db->beginTransaction();

    // Update the role
    $roleModel->updateRole($roleId, [
        'role_name' => $roleName,
        'description' => $description ?: null
    ]);

    // Log activity
    $activitySql = "
        INSERT INTO activity_log (company_id, user_id, action, entity_type, entity_id, old_data, new_data, created_at)
        VALUES (:company_id, :user_id, 'role_updated', 'role', :role_id, :old_data, :new_data, NOW())
    ";
    $activityStmt = $db->prepare($activitySql);
    $activityStmt->execute([
        'company_id' => $companyId,
        'user_id' => $userId,
        'role_id' => $roleId,
        'old_data' => json_encode(['role_name' => $role['role_name'], 'description' => $role['description']]),
        'new_data' => json_encode(['role_name' => $roleName, 'description' => $description])
    ]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Role updated successfully.',
        'role' => [
            'id' => $roleId,
            'role_code' => $role['role_code'],
            'role_name' => $roleName,
            'description' => $description
        ]
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Update role error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}