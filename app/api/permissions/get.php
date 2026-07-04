<?php
// api/permissions/get.php
// Get permissions for a role (company-specific)

declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Permission.php';
require_once __DIR__ . '/../../models/UserRole.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit;
}

$userRole = $_SESSION['user_role'] ?? 'VIW';
$companyId = $_SESSION['company_id'] ?? null;

if ($userRole !== 'ADM') {
    echo json_encode(['success' => false, 'message' => 'Permission denied.']);
    exit;
}

if (!$companyId) {
    echo json_encode(['success' => false, 'message' => 'Company context not found.']);
    exit;
}

$roleId = isset($_GET['role_id']) ? (int) $_GET['role_id'] : 0;

if (!$roleId) {
    echo json_encode(['success' => false, 'message' => 'Role ID is required.']);
    exit;
}

try {
    $permissionModel = new Permission($companyId);
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

    $allPermissions = $permissionModel->getAllGrouped();
    $rolePermissions = $permissionModel->getRolePermissions($roleId);

    echo json_encode([
        'success' => true,
        'permissions' => $allPermissions,
        'role_permissions' => $rolePermissions
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}