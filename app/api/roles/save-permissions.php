<?php
// api/roles/save-permissions.php
// Save permissions for a specific role

declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/UserRole.php';
require_once __DIR__ . '/../../models/Permission.php';

header('Content-Type: application/json');



// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Only Admins can save permissions
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
$permissionIds = isset($input['permissions']) ? array_map('intval', $input['permissions']) : [];

if (!$roleId) {
    echo json_encode(['success' => false, 'message' => 'Role ID is required.']);
    exit;
}

try {
    $roleModel = new UserRole($companyId);
    $permissionModel = new Permission($companyId);

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

    // Prevent modifying system role permissions
    if ($role['is_system_role']) {
        echo json_encode(['success' => false, 'message' => 'System role permissions cannot be modified.']);
        exit;
    }

    // Start transaction
    $db = Database::getInstance();
    $db->beginTransaction();

    // Sync permissions
    $permissionModel->syncPermissions($roleId, $permissionIds);

    // Log activity
    $activitySql = "
        INSERT INTO activity_log (company_id, user_id, action, entity_type, entity_id, new_data, created_at)
        VALUES (:company_id, :user_id, 'permissions_updated', 'role', :role_id, :new_data, NOW())
    ";
    $activityStmt = $db->prepare($activitySql);
    $activityStmt->execute([
        'company_id' => $companyId,
        'user_id' => $userId,
        'role_id' => $roleId,
        'new_data' => json_encode(['permission_count' => count($permissionIds)])
    ]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Permissions saved successfully.',
        'permission_count' => count($permissionIds)
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Save permissions error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}