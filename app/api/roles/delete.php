<?php
// api/roles/delete.php
// Delete a role

declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/UserRole.php';
require_once __DIR__ . '/../../models/User.php';

header('Content-Type: application/json');



// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Only Admins can delete roles
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

if (!$roleId) {
    echo json_encode(['success' => false, 'message' => 'Role ID is required.']);
    exit;
}

try {
    $roleModel = new UserRole($companyId);
    $userModel = new User($companyId);

    // Verify role belongs to company
    $role = $roleModel->find($roleId);
    if (!$role) {
        echo json_encode(['success' => false, 'message' => 'Role not found.']);
        exit;
    }

    // Check if it's a system role
    if ($role['is_system_role'] || $role['company_id'] === null) {
        echo json_encode(['success' => false, 'message' => 'System roles cannot be deleted.']);
        exit;
    }

    if ($role['company_id'] !== null && $role['company_id'] != $companyId) {
        echo json_encode(['success' => false, 'message' => 'Role does not belong to your company.']);
        exit;
    }

    // Check if role has users in this company
    $users = $userModel->all(['id'], "role_id = $roleId");
    if (count($users) > 0) {
        echo json_encode(['success' => false, 'message' => 'Cannot delete role that has users assigned.']);
        exit;
    }

    // Start transaction
    $db = Database::getInstance();
    $db->beginTransaction();

    // Delete role (this will also delete role_permissions via cascade)
    $roleModel->deleteRole($roleId);

    // Log activity
    $activitySql = "
        INSERT INTO activity_log (company_id, user_id, action, entity_type, entity_id, old_data, created_at)
        VALUES (:company_id, :user_id, 'role_deleted', 'role', :role_id, :old_data, NOW())
    ";
    $activityStmt = $db->prepare($activitySql);
    $activityStmt->execute([
        'company_id' => $companyId,
        'user_id' => $userId,
        'role_id' => $roleId,
        'old_data' => json_encode(['role_code' => $role['role_code'], 'role_name' => $role['role_name']])
    ]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Role deleted successfully.'
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Delete role error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}