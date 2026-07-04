<?php
// pages/admin/roles.php
declare(strict_types=1);

$pageTitle = 'Role Management - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/UserRole.php';
require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../models/Permission.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission (only Admins)
if (!in_array($userRole, ['ADM', 'MGR'])) {
    SessionManager::flash('error', 'You do not have permission to access role management.');
    header('Location: ' . route_url('dashboard'));
    exit;
}

// Check if company context is set
if (!$companyId) {
    SessionManager::flash('error', 'Company context not found. Please log in again.');
    header('Location: ' . route_url('login'));
    exit;
}

// Initialize models with company context
$roleModel = new UserRole($companyId);
$userModel = new User($companyId);
$permissionModel = new Permission($companyId);

// Handle role deletion
if (isset($_GET['delete'])) {
    $id = (int) $_GET['delete'];

    try {
        if ($roleModel->isSystemRole($id)) {
            throw new Exception('System roles cannot be deleted.');
        }

        $role = $roleModel->find($id);
        if ($role && $role['company_id'] !== null && $role['company_id'] != $companyId) {
            throw new Exception('Role does not belong to your company.');
        }

        $users = $userModel->all(['id'], "role_id = $id");
        if (count($users) > 0) {
            throw new Exception('Cannot delete role that has users assigned.');
        }

        $roleModel->deleteRole($id);
        SessionManager::flash('success', 'Role deleted successfully.');
    } catch (Exception $e) {
        SessionManager::flash('error', $e->getMessage());
    }
    header('Location: ?page=admin/roles');
    exit;
}

// Handle role creation via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    header('Content-Type: application/json');

    if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    try {
        $roleCode = strtoupper(trim($_POST['role_code']));
        $roleName = trim($_POST['role_name']);
        $description = trim($_POST['description'] ?? '');

        if (empty($roleCode)) {
            throw new Exception('Role code is required.');
        }
        if (empty($roleName)) {
            throw new Exception('Role name is required.');
        }
        if (!preg_match('/^[A-Z0-9_]+$/', $roleCode)) {
            throw new Exception('Role code must contain only uppercase letters, numbers, and underscores.');
        }

        $roleModel->createRole([
            'role_code' => $roleCode,
            'role_name' => $roleName,
            'description' => $description,
            'company_id' => $companyId,
            'is_system_role' => 0
        ]);

        echo json_encode(['success' => true, 'message' => 'Role created successfully.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle role update via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    header('Content-Type: application/json');

    if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    try {
        $roleId = (int) $_POST['role_id'];
        $roleName = trim($_POST['role_name']);
        $description = trim($_POST['description'] ?? '');

        $role = $roleModel->find($roleId);
        if (!$role) {
            throw new Exception('Role not found.');
        }
        if ($role['company_id'] !== null && $role['company_id'] != $companyId) {
            throw new Exception('Role does not belong to your company.');
        }
        if ($role['is_system_role']) {
            throw new Exception('System roles cannot be modified.');
        }

        if (empty($roleName)) {
            throw new Exception('Role name is required.');
        }

        $roleModel->updateRole($roleId, [
            'role_name' => $roleName,
            'description' => $description
        ]);

        echo json_encode(['success' => true, 'message' => 'Role updated successfully.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle permission sync via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync_permissions') {
    header('Content-Type: application/json');

    if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    try {
        $roleId = (int) $_POST['role_id'];
        $permissionIds = isset($_POST['permissions']) ? array_map('intval', $_POST['permissions']) : [];

        $role = $roleModel->find($roleId);
        if (!$role) {
            throw new Exception('Role not found.');
        }
        if ($role['company_id'] !== null && $role['company_id'] != $companyId) {
            throw new Exception('Role does not belong to your company.');
        }

        $permissionModel->syncPermissions($roleId, $permissionIds);

        echo json_encode(['success' => true, 'message' => 'Permissions saved successfully.']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Get all roles with user count (company-specific)
$roles = $roleModel->getAllWithUserCount();

// Get all permissions grouped (for the edit modal)
$allPermissions = $permissionModel->getAllGrouped();

// Generate CSRF token
$csrfToken = CSRF::generate();

// Prepare role permissions data for JavaScript
$rolePermissionsData = [];
foreach ($roles as $role) {
    $rolePermissionsData[$role['id']] = [
        'permissions' => $allPermissions,
        'role_permission_ids' => $permissionModel->getRolePermissionIds($role['id'])
    ];
}
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <a href="?page=admin/users" class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Users
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-user-shield me-2"></i>Role Management
                    </h2>
                    <p class="mb-0 text-muted">Create and manage user roles and permissions</p>
                </div>
                <div class="mt-2 mt-sm-0">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRoleModal">
                        <i class="fas fa-plus-circle me-2"></i>Create New Role
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php if ($flash = SessionManager::flash('success')): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($flash); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($flash = SessionManager::flash('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($flash); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Roles List -->
    <div class="row">
        <?php foreach ($roles as $role): ?>
            <div class="col-xl-4 col-md-6 mb-4">
                <div class="card shadow h-100">
                    <div class="card-header py-3 d-flex justify-content-between align-items-center flex-wrap">
                        <h6 class="m-0 font-weight-bold text-primary">
                            <?php echo htmlspecialchars($role['role_name']); ?>
                            <?php if ($role['is_system_role'] || $role['company_id'] === null): ?>
                                <span class="badge bg-secondary ms-2">System</span>
                            <?php endif; ?>
                        </h6>
                        <span class="badge bg-info"><?php echo $role['user_count']; ?> users</span>
                    </div>
                    <div class="card-body">
                        <p class="small text-muted mb-2">
                            <strong>Role Code:</strong> <?php echo htmlspecialchars($role['role_code']); ?>
                        </p>
                        <?php if (!empty($role['description'])): ?>
                            <p class="small"><?php echo nl2br(htmlspecialchars($role['description'])); ?></p>
                        <?php else: ?>
                            <p class="small text-muted">No description</p>
                        <?php endif; ?>

                        <hr>

                        <div class="d-flex justify-content-between align-items-center">
                            <div class="btn-group btn-group-sm w-100" role="group">
                                <!-- View Details Button -->
                                <a href="<?php echo route_url('admin/role_detail', ['id' => $role['id']]); ?>"
                                    class="btn btn-outline-info" title="View Details">
                                    <i class="fas fa-eye me-1"></i> View
                                </a>

                                <?php if (!$role['is_system_role'] && $role['company_id'] !== null): ?>
                                    <!-- Edit Button -->
                                    <a href="<?php echo route_url('admin/role_edit', ['id' => $role['id']]); ?>"
                                        class="btn btn-outline-primary" title="Edit Role">
                                        <i class="fas fa-edit me-1"></i> Edit
                                    </a>
                                    <!-- Delete Button -->
                                    <a href="?page=admin/roles&delete=<?php echo $role['id']; ?>" class="btn btn-outline-danger"
                                        onclick="return confirm('Delete role <?php echo htmlspecialchars(addslashes($role['role_name'])); ?>? This action cannot be undone.')">
                                        <i class="fas fa-trash me-1"></i> Delete
                                    </a>
                                <?php else: ?>
                                    <!-- For system roles, only show view (edit and delete disabled) -->
                                    <button type="button" class="btn btn-outline-secondary" disabled
                                        title="System roles cannot be edited">
                                        <i class="fas fa-edit me-1"></i> Edit
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" disabled
                                        title="System roles cannot be deleted">
                                        <i class="fas fa-trash me-1"></i> Delete
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Add Role Modal -->
<div class="modal fade" id="addRoleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle me-2"></i>Create New Role
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="addRoleForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="company_id" value="<?php echo $companyId; ?>">

                <div class="modal-body">
                    <div class="mb-3">
                        <label for="role_code" class="form-label">Role Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="role_code" name="role_code"
                            placeholder="e.g., MGR, ACC, SALES" maxlength="20" required>
                        <small class="text-muted">Unique identifier (uppercase letters, numbers, and underscores
                            only)</small>
                    </div>

                    <div class="mb-3">
                        <label for="role_name" class="form-label">Role Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="role_name" name="role_name"
                            placeholder="e.g., Manager, Accountant" required>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3"
                            placeholder="Describe what this role can do..."></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Role</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Role Modal (Combined with Permissions) -->
<div class="modal fade" id="editRoleModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title">
                    <i class="fas fa-edit me-2"></i>Edit Role: <span id="editRoleNameDisplay"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#roleInfoTab" type="button"
                            role="tab">
                            <i class="fas fa-info-circle me-2"></i>Role Information
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#permissionsTab" type="button"
                            role="tab">
                            <i class="fas fa-key me-2"></i>Permissions
                        </button>
                    </li>
                </ul>

                <div class="tab-content mt-3">
                    <!-- Role Information Tab -->
                    <div class="tab-pane fade show active" id="roleInfoTab" role="tabpanel">
                        <form id="editRoleInfoForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="role_id" id="edit_role_id">
                            <input type="hidden" name="company_id" value="<?php echo $companyId; ?>">

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Role Code</label>
                                    <input type="text" class="form-control" id="edit_role_code" readonly disabled>
                                    <small class="text-muted">Role code cannot be changed</small>
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="edit_role_name" class="form-label">Role Name <span
                                            class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="edit_role_name" name="role_name"
                                        required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="edit_description" class="form-label">Description</label>
                                <textarea class="form-control" id="edit_description" name="description"
                                    rows="3"></textarea>
                            </div>

                            <div class="text-end">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-warning">Update Role Info</button>
                            </div>
                        </form>
                    </div>

                    <!-- Permissions Tab -->
                    <div class="tab-pane fade" id="permissionsTab" role="tabpanel">
                        <div id="permissionsContent">
                            <div class="text-center py-5">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="mt-2">Loading permissions...</p>
                            </div>
                        </div>
                        <div class="text-end mt-3">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" onclick="savePermissions()">
                                <i class="fas fa-save me-2"></i>Save Permissions
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Include external JS -->
<script>
    const csrfToken = '<?php echo $csrfToken; ?>';
    const companyId = <?php echo json_encode($companyId); ?>;
</script>
<script src="<?php echo BASE_URL; ?>/assets/js/admin/roles.js"></script>

<style>
    .border-left-primary {
        border-left: 4px solid #4e73df !important;
    }

    .card {
        transition: transform 0.2s;
    }

    .card:hover {
        transform: translateY(-2px);
    }

    .table td {
        vertical-align: middle;
    }

    .form-check-input:checked {
        background-color: #4e73df;
        border-color: #4e73df;
    }

    .sticky-top {
        position: sticky;
        top: 0;
        z-index: 10;
    }

    .nav-tabs .nav-link {
        color: #4e73df;
    }

    .nav-tabs .nav-link.active {
        color: #4e73df;
        font-weight: bold;
    }

    .btn-group {
        gap: 2px;
    }

    .btn-group .btn {
        border-radius: 4px;
    }
</style>