<?php
// pages/admin/role_edit.php
declare(strict_types=1);

$pageTitle = 'Edit Role - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/UserRole.php';
require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../models/Permission.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission (only Admins)
if (!in_array($userRole, ['ADM'])) {
    SessionManager::flash('error', 'You do not have permission to edit roles.');
    header('Location: ' . route_url('dashboard'));
    exit;
}

// Check if company context is set
if (!$companyId) {
    SessionManager::flash('error', 'Company context not found. Please log in again.');
    header('Location: ' . route_url('login'));
    exit;
}

// Get role ID from URL
$roleId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$roleId) {
    SessionManager::flash('error', 'Role ID is required.');
    header('Location: ' . route_url('admin/roles'));
    exit;
}

// Initialize models with company context
$roleModel = new UserRole($companyId);
$userModel = new User($companyId);
$permissionModel = new Permission($companyId);

// Get role details
$role = $roleModel->find($roleId);

if (!$role) {
    SessionManager::flash('error', 'Role not found.');
    header('Location: ' . route_url('admin/roles'));
    exit;
}

// Verify role belongs to company (if not system role)
if ($role['company_id'] !== null && $role['company_id'] != $companyId) {
    SessionManager::flash('error', 'Role does not belong to your company.');
    header('Location: ' . route_url('admin/roles'));
    exit;
}

// Check if role is system role (cannot edit)
if ($role['is_system_role'] || $role['company_id'] === null) {
    SessionManager::flash('error', 'System roles cannot be modified.');
    header('Location: ' . route_url('admin/roles'));
    exit;
}

// Get all permissions grouped
$allPermissions = $permissionModel->getAllGrouped();

// Get current role permissions
$rolePermissionIds = $permissionModel->getRolePermissionIds($roleId);

// Handle form submission
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            // Update role information
            if (isset($_POST['update_info'])) {
                $roleName = trim($_POST['role_name']);
                $description = trim($_POST['description'] ?? '');
                
                if (empty($roleName)) {
                    throw new Exception('Role name is required.');
                }
                
                $roleModel->updateRole($roleId, [
                    'role_name' => $roleName,
                    'description' => $description
                ]);
                
                $message = 'Role information updated successfully.';
                
                // Refresh role data
                $role = $roleModel->find($roleId);
            }
            
            // Update permissions
            if (isset($_POST['update_permissions'])) {
                $permissionIds = isset($_POST['permissions']) ? array_map('intval', $_POST['permissions']) : [];
                
                $permissionModel->syncPermissions($roleId, $permissionIds);
                
                // Refresh permission IDs
                $rolePermissionIds = $permissionModel->getRolePermissionIds($roleId);
                
                $message = 'Permissions updated successfully.';
            }
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Generate CSRF token
$csrfToken = CSRF::generate();
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <a href="<?php echo route_url('admin/roles'); ?>" class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Roles
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-edit me-2"></i>Edit Role
                    </h2>
                    <p class="mb-0 text-muted">Edit role information and permissions</p>
                </div>
                <div class="mt-2 mt-sm-0">
                    <a href="<?php echo route_url('admin/role_detail', ['id' => $roleId]); ?>" class="btn btn-info">
                        <i class="fas fa-eye me-2"></i> View Role Details
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Role Information Card -->
        <div class="col-md-4 mb-4">
            <div class="card shadow h-100">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Role Information</h6>
                </div>
                <div class="card-body">
                    <form method="POST" action="" id="roleInfoForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="update_info" value="1">
                        
                        <div class="mb-3">
                            <label for="role_code" class="form-label">Role Code</label>
                            <input type="text" class="form-control" id="role_code" value="<?php echo htmlspecialchars($role['role_code']); ?>" readonly disabled>
                            <small class="text-muted">Role code cannot be changed</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="role_name" class="form-label">Role Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="role_name" name="role_name" value="<?php echo htmlspecialchars($role['role_name']); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="4"><?php echo htmlspecialchars($role['description'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Role Type</label>
                            <div>
                                <span class="badge bg-primary">Company Role</span>
                                <small class="text-muted d-block mt-1">This is a custom role for your company</small>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Created At</label>
                            <div><?php echo date('d/m/Y H:i:s', strtotime($role['created_at'])); ?></div>
                        </div>
                        
                        <hr>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Update Role Info
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Permissions Card -->
        <div class="col-md-8 mb-4">
            <div class="card shadow">
                <div class="card-header bg-warning text-white">
                    <h6 class="mb-0"><i class="fas fa-key me-2"></i>Role Permissions</h6>
                </div>
                <div class="card-body">
                    <form method="POST" action="" id="permissionsForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="update_permissions" value="1">
                        
                        <div class="mb-3">
                            <div class="btn-group">
                                <button type="button" class="btn btn-sm btn-success" onclick="selectAllPermissions()">
                                    <i class="fas fa-check-double me-1"></i> Select All
                                </button>
                                <button type="button" class="btn btn-sm btn-secondary" onclick="deselectAllPermissions()">
                                    <i class="fas fa-times me-1"></i> Deselect All
                                </button>
                            </div>
                            <span class="ms-2 text-muted small">
                                <i class="fas fa-info-circle me-1"></i>
                                <span id="selectedCount">0</span> permissions selected
                            </span>
                        </div>
                        
                        <div class="row">
                            <?php if (empty($allPermissions)): ?>
                                <div class="col-12">
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        No permissions have been configured yet. Please install default permissions first.
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($allPermissions as $module => $permissions): ?>
                                    <div class="col-md-6 mb-4">
                                        <div class="card h-100">
                                            <div class="card-header bg-light">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <h6 class="mb-0">
                                                        <i class="fas fa-folder-open me-2 text-primary"></i>
                                                        <?php echo ucfirst(str_replace('_', ' ', $module)); ?>
                                                    </h6>
                                                    <div class="form-check">
                                                        <input type="checkbox" class="form-check-input module-select-all" 
                                                               data-module="<?php echo $module; ?>"
                                                               id="module_<?php echo md5($module); ?>">
                                                        <label class="form-check-label small" for="module_<?php echo md5($module); ?>">
                                                            Select All
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="card-body">
                                                <?php foreach ($permissions as $permission): ?>
                                                    <div class="form-check mb-2">
                                                        <input type="checkbox" 
                                                               class="form-check-input permission-checkbox" 
                                                               name="permissions[]" 
                                                               value="<?php echo $permission['id']; ?>"
                                                               data-module="<?php echo $module; ?>"
                                                               id="perm_<?php echo $permission['id']; ?>"
                                                               <?php echo in_array($permission['id'], $rolePermissionIds) ? 'checked' : ''; ?>>
                                                        <label class="form-check-label" for="perm_<?php echo $permission['id']; ?>">
                                                            <?php echo htmlspecialchars($permission['permission_name']); ?>
                                                            <?php if ($permission['company_id'] === null): ?>
                                                                <span class="badge bg-secondary ms-1" title="System Permission">Sys</span>
                                                            <?php endif; ?>
                                                        </label>
                                                        <br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($permission['permission_code']); ?></small>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                        <hr>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-warning btn-lg">
                                <i class="fas fa-save me-2"></i>Save Permissions
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Update selected count
function updateSelectedCount() {
    const count = document.querySelectorAll('.permission-checkbox:checked').length;
    const selectedCountSpan = document.getElementById('selectedCount');
    if (selectedCountSpan) {
        selectedCountSpan.textContent = count;
    }
}

// Select all permissions
function selectAllPermissions() {
    document.querySelectorAll('.permission-checkbox').forEach(cb => {
        cb.checked = true;
    });
    updateSelectedCount();
    updateAllModuleCheckboxes();
}

// Deselect all permissions
function deselectAllPermissions() {
    document.querySelectorAll('.permission-checkbox').forEach(cb => {
        cb.checked = false;
    });
    updateSelectedCount();
    updateAllModuleCheckboxes();
}

// Update module checkbox state
function updateModuleCheckbox(moduleCheckbox) {
    const module = moduleCheckbox.dataset.module;
    const modulePermissions = document.querySelectorAll(`.permission-checkbox[data-module="${module}"]`);
    const checkedCount = Array.from(modulePermissions).filter(cb => cb.checked).length;
    
    if (checkedCount === 0) {
        moduleCheckbox.checked = false;
        moduleCheckbox.indeterminate = false;
    } else if (checkedCount === modulePermissions.length) {
        moduleCheckbox.checked = true;
        moduleCheckbox.indeterminate = false;
    } else {
        moduleCheckbox.indeterminate = true;
    }
}

// Update all module checkboxes
function updateAllModuleCheckboxes() {
    document.querySelectorAll('.module-select-all').forEach(moduleCheckbox => {
        updateModuleCheckbox(moduleCheckbox);
    });
}

// Initialize event listeners
document.addEventListener('DOMContentLoaded', function() {
    // Update initial selected count
    updateSelectedCount();
    
    // Module select all checkboxes
    document.querySelectorAll('.module-select-all').forEach(moduleCheckbox => {
        moduleCheckbox.addEventListener('change', function() {
            const module = this.dataset.module;
            const modulePermissions = document.querySelectorAll(`.permission-checkbox[data-module="${module}"]`);
            modulePermissions.forEach(cb => {
                cb.checked = this.checked;
            });
            updateSelectedCount();
            updateModuleCheckbox(this);
        });
        
        // Initialize module checkbox state
        updateModuleCheckbox(moduleCheckbox);
    });
    
    // Individual permission checkboxes
    document.querySelectorAll('.permission-checkbox').forEach(cb => {
        cb.addEventListener('change', function() {
            updateSelectedCount();
            
            // Update module checkbox
            const module = this.dataset.module;
            const moduleCheckbox = document.querySelector(`.module-select-all[data-module="${module}"]`);
            if (moduleCheckbox) {
                updateModuleCheckbox(moduleCheckbox);
            }
        });
    });
    
    // Role info form submission
    const roleInfoForm = document.getElementById('roleInfoForm');
    if (roleInfoForm) {
        roleInfoForm.addEventListener('submit', function(e) {
            const roleName = document.getElementById('role_name').value.trim();
            if (!roleName) {
                e.preventDefault();
                alert('Role name is required.');
                return false;
            }
            
            if (!confirm('Update role information?')) {
                e.preventDefault();
                return false;
            }
            
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Updating...';
        });
    }
    
    // Permissions form submission
    const permissionsForm = document.getElementById('permissionsForm');
    if (permissionsForm) {
        permissionsForm.addEventListener('submit', function(e) {
            if (!confirm('Save permission changes for this role?')) {
                e.preventDefault();
                return false;
            }
            
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';
        });
    }
});
</script>

<style>
    .border-left-primary {  
        border-left: 4px solid #4e73df !important;
    }
    .border-left-success {
        border-left: 4px solid #1cc88a !important;
    }
    .border-left-info {
        border-left: 4px solid #36b9cc !important;
    }
    .border-left-warning {
        border-left: 4px solid #f6c23e !important;
    }
    .card {
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .card:hover {
        transform: translateY(-2px);
        box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
    }
    .form-check-input {
        cursor: pointer;
    }
    .form-check-label {
        cursor: pointer;
    }
    .module-select-all {
        cursor: pointer;
    }
    .permission-checkbox:checked + label {
        color: #4e73df;
    }
    @media (max-width: 768px) {
        .btn-group {
            margin-bottom: 1rem;
        }
    }
</style>