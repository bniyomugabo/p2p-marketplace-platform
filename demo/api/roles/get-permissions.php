<?php
// api/roles/get-permissions.php
// Get permissions for a specific role

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
$companyId = $_SESSION['company_id'] ?? null;

// Only Admins can view permissions
if ($userRole !== 'ADM') {
    echo json_encode(['success' => false, 'message' => 'Permission denied.']);
    exit;
}

// Check if company context is set
if (!$companyId) {
    echo json_encode(['success' => false, 'message' => 'Company context not found.']);
    exit;
}

// Get role ID from request
$roleId = isset($_GET['role_id']) ? (int) $_GET['role_id'] : (isset($_POST['role_id']) ? (int) $_POST['role_id'] : 0);

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

    // Get all permissions grouped by module
    $allPermissions = $permissionModel->getAllGrouped();

    // Get role's current permissions
    $rolePermissions = $permissionModel->getRolePermissions($roleId);

    echo json_encode([
        'success' => true,
        'role' => [
            'id' => $role['id'],
            'name' => $role['role_name'],
            'code' => $role['role_code'],
            'description' => $role['description']
        ],
        'permissions' => $allPermissions,
        'role_permissions' => $rolePermissions
    ]);

} catch (Exception $e) {
    error_log("Get permissions error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}