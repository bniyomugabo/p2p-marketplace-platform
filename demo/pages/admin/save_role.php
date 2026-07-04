<?php
// pages/admin/save-role.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/UserRole.php';
require_once __DIR__ . '/../../models/Permission.php';



// Check authentication
if (!isset($_SESSION['user_id'])) {
    SessionManager::flash('error', 'Authentication required.');
    header('Location: ' . route_url('login'));
    exit;
}

$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Only Admins can create roles
if ($userRole !== 'ADM') {
    SessionManager::flash('error', 'You do not have permission to create roles.');
    header('Location: ' . route_url('admin/roles'));
    exit;
}

// Check if company context is set
if (!$companyId) {
    SessionManager::flash('error', 'Company context not found. Please log in again.');
    header('Location: ' . route_url('login'));
    exit;
}

// Verify request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . route_url('admin/roles'));
    exit;
}

// Validate CSRF token
if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
    SessionManager::flash('error', 'Invalid security token. Please try again.');
    header('Location: ' . route_url('admin/roles'));
    exit;
}

try {
    // Validate required fields
    if (empty($_POST['role_code'])) {
        throw new Exception('Role code is required.');
    }

    if (empty($_POST['role_name'])) {
        throw new Exception('Role name is required.');
    }

    // Validate role code format (uppercase letters, numbers, underscores only)
    $roleCode = strtoupper(trim($_POST['role_code']));
    if (!preg_match('/^[A-Z0-9_]+$/', $roleCode)) {
        throw new Exception('Role code must contain only uppercase letters, numbers, and underscores.');
    }

    // Check if role code already exists for this company
    $roleModel = new UserRole($companyId);
    $existingRole = $roleModel->findByCode($roleCode);

    if ($existingRole) {
        // If it's a system role (company_id NULL), show different message
        if ($existingRole['company_id'] === null) {
            throw new Exception("Role code '{$roleCode}' is a system role and cannot be duplicated.");
        }
        throw new Exception("Role code '{$roleCode}' already exists in your company.");
    }

    // Check if role name already exists for this company
    $existingRoleByName = $roleModel->findByName($_POST['role_name']);
    if ($existingRoleByName && $existingRoleByName['company_id'] == $companyId) {
        throw new Exception("Role name '{$_POST['role_name']}' already exists in your company.");
    }

    // Initialize database connection
    $db = Database::getInstance();
    $db->beginTransaction();

    // Create the role
    $roleData = [
        'company_id' => $companyId,
        'role_code' => $roleCode,
        'role_name' => trim($_POST['role_name']),
        'description' => !empty($_POST['description']) ? trim($_POST['description']) : null,
        'is_system_role' => 0,
        'created_at' => date('Y-m-d H:i:s')
    ];

    $roleId = $roleModel->create($roleData);

    if (!$roleId) {
        throw new Exception('Failed to create role.');
    }

    // Assign default permissions based on role code (optional)
    $permissionModel = new Permission($companyId);

    // Define default permission sets for common role codes
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

    // Log the activity
    $activitySql = "
        INSERT INTO activity_log (company_id, user_id, action, entity_type, entity_id, new_data, created_at)
        VALUES (:company_id, :user_id, 'role_created', 'role', :role_id, :new_data, NOW())
    ";
    $activityStmt = $db->prepare($activitySql);
    $activityStmt->execute([
        'company_id' => $companyId,
        'user_id' => $userId,
        'role_id' => $roleId,
        'new_data' => json_encode(['role_code' => $roleCode, 'role_name' => $_POST['role_name']])
    ]);

    $db->commit();

    SessionManager::flash('success', 'Role "' . htmlspecialchars($_POST['role_name']) . '" created successfully!');

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Save role error for company {$companyId}: " . $e->getMessage());
    SessionManager::flash('error', 'Failed to create role: ' . $e->getMessage());
}

header('Location: ' . route_url('admin/roles'));
exit;