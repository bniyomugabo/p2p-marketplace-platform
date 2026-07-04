<?php
// api/roles/create.php
// Create a new role

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

// Only Admins can create roles
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

// Validate inputs
$roleCode = isset($input['role_code']) ? strtoupper(trim($input['role_code'])) : '';
$roleName = isset($input['role_name']) ? trim($input['role_name']) : '';
$description = isset($input['description']) ? trim($input['description']) : '';

if (empty($roleCode)) {
    echo json_encode(['success' => false, 'message' => 'Role code is required.']);
    exit;
}

if (empty($roleName)) {
    echo json_encode(['success' => false, 'message' => 'Role name is required.']);
    exit;
}

// Validate role code format
if (!preg_match('/^[A-Z0-9_]+$/', $roleCode)) {
    echo json_encode(['success' => false, 'message' => 'Role code must contain only uppercase letters, numbers, and underscores.']);
    exit;
}

// Validate max length
if (strlen($roleCode) > 20) {
    echo json_encode(['success' => false, 'message' => 'Role code must be 20 characters or less.']);
    exit;
}

if (strlen($roleName) > 50) {
    echo json_encode(['success' => false, 'message' => 'Role name must be 50 characters or less.']);
    exit;
}

try {
    $roleModel = new UserRole($companyId);

    // Check if role code already exists for this company
    $existingRole = $roleModel->findByCode($roleCode);
    if ($existingRole) {
        if ($existingRole['company_id'] === null) {
            echo json_encode(['success' => false, 'message' => "Role code '{$roleCode}' is a system role and cannot be duplicated."]);
        } else {
            echo json_encode(['success' => false, 'message' => "Role code '{$roleCode}' already exists in your company."]);
        }
        exit;
    }

    // Check if role name already exists for this company
    $existingRoleByName = $roleModel->findByName($roleName);
    if ($existingRoleByName && $existingRoleByName['company_id'] == $companyId) {
        echo json_encode(['success' => false, 'message' => "Role name '{$roleName}' already exists in your company."]);
        exit;
    }

    // Start transaction
    $db = Database::getInstance();
    $db->beginTransaction();

    // Create the role
    $roleData = [
        'company_id' => $companyId,
        'role_code' => $roleCode,
        'role_name' => $roleName,
        'description' => $description ?: null,
        'is_system_role' => 0,
        'created_at' => date('Y-m-d H:i:s')
    ];

    $roleId = $roleModel->create($roleData);

    if (!$roleId) {
        throw new Exception('Failed to create role.');
    }

    // Assign default permissions based on role code
    $permissionModel = new Permission($companyId);

    $defaultPermissionSets = [
        'MGR' => [ // Manager
            'dashboard_view',
            'products_view',
            'products_create',
            'products_edit',
            'inventory_view',
            'inventory_manage',
            'sales_view',
            'sales_create',
            'sales_edit',
            'quotations_view',
            'quotations_create',
            'quotations_edit',
            'purchasing_view',
            'purchasing_create',
            'purchasing_approve',
            'reports_view',
            'reports_export'
        ],
        'ACC' => [ // Accountant
            'dashboard_view',
            'products_view',
            'inventory_view',
            'sales_view',
            'sales_create',
            'quotations_view',
            'quotations_create',
            'purchasing_view',
            'purchasing_create',
            'reports_view',
            'reports_export'
        ],
        'SEL' => [ // Seller
            'dashboard_view',
            'products_view',
            'inventory_view',
            'sales_view',
            'sales_create',
            'quotations_view',
            'quotations_create',
            'reports_view'
        ],
        'WHS' => [ // Warehouse Staff
            'dashboard_view',
            'products_view',
            'inventory_view',
            'inventory_manage',
            'inventory_adjust',
            'purchasing_view'
        ],
        'VIW' => [ // Viewer
            'dashboard_view',
            'products_view',
            'inventory_view',
            'sales_view',
            'quotations_view',
            'purchasing_view',
            'reports_view'
        ]
    ];

    // If the role code matches a predefined set, assign those permissions
    if (isset($defaultPermissionSets[$roleCode])) {
        $permissionIds = [];
        foreach ($defaultPermissionSets[$roleCode] as $permCode) {
            $perm = $permissionModel->getByCode($permCode);
            if ($perm) {
                $permissionIds[] = $perm['id'];
            }
        }
        if (!empty($permissionIds)) {
            $permissionModel->syncPermissions($roleId, $permissionIds);
        }
    }

    // Log activity
    $activitySql = "
        INSERT INTO activity_log (company_id, user_id, action, entity_type, entity_id, new_data, created_at)
        VALUES (:company_id, :user_id, 'role_created', 'role', :role_id, :new_data, NOW())
    ";
    $activityStmt = $db->prepare($activitySql);
    $activityStmt->execute([
        'company_id' => $companyId,
        'user_id' => $userId,
        'role_id' => $roleId,
        'new_data' => json_encode(['role_code' => $roleCode, 'role_name' => $roleName])
    ]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Role created successfully.',
        'role' => [
            'id' => $roleId,
            'role_code' => $roleCode,
            'role_name' => $roleName,
            'description' => $description
        ]
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Create role error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}