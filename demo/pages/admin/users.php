<?php
// pages/admin/users.php
declare(strict_types=1);

$pageTitle = 'User Management - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../models/UserRole.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission (only Admins)
if (!in_array($userRole, ['ADM'])) {
    SessionManager::flash('error', 'You do not have permission to access user management.');
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
$userModel = new User($companyId);
$roleModel = new UserRole($companyId);

// Handle user deletion
if (isset($_GET['delete']) && in_array($userRole, ['ADM'])) {
    $id = (int) $_GET['delete'];

    if ($id == $userId) {
        SessionManager::flash('error', 'You cannot delete your own account.');
    } else {
        try {
            $userModel->delete($id);
            SessionManager::flash('success', 'User deleted successfully.');
        } catch (Exception $e) {
            SessionManager::flash('error', 'Failed to delete user: ' . $e->getMessage());
        }
    }
    header('Location: ?page=admin/users');
    exit;
}

// Handle status toggle
if (isset($_GET['toggle']) && isset($_GET['status'])) {
    $id = (int) $_GET['toggle'];
    $status = (int) $_GET['status'];

    if ($id == $userId) {
        SessionManager::flash('error', 'You cannot change your own status.');
    } else {
        try {
            $userModel->update($id, ['is_active' => $status]);
            SessionManager::flash('success', 'User status updated successfully.');
        } catch (Exception $e) {
            SessionManager::flash('error', 'Failed to update user status: ' . $e->getMessage());
        }
    }
    header('Location: ?page=admin/users');
    exit;
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$roleId = isset($_GET['role_id']) ? (int) $_GET['role_id'] : null;
$status = $_GET['status'] ?? '';

// Build where clause with company filter
$where = "";
$params = [];

if ($search) {
    $where .= " AND (username LIKE :search OR full_name LIKE :search OR email LIKE :search)";
    $params['search'] = "%{$search}%";
}

if ($roleId) {
    $where .= " AND role_id = :role_id";
    $params['role_id'] = $roleId;
}

if ($status === 'active') {
    $where .= " AND is_active = 1";
} elseif ($status === 'inactive') {
    $where .= " AND is_active = 0";
}

// Get users (company-specific)
$users = $userModel->all(['*'], $where, $params);

// Get roles for filter (company-specific - including system roles)
$roles = $roleModel->getCompanyRoles($companyId);

// Get user statistics (company-specific)
$stats = $userModel->getStats();

// Generate CSRF token
$csrfToken = CSRF::generate();
?>

<div class="user-management">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <a href="<?php echo route_url('admin'); ?>" class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Admin
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-users-cog me-2"></i>User Management
                    </h2>
                    <p class="mb-0 text-muted">Manage system users and their access</p>
                </div>
                <div class="mt-2 mt-sm-0">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                        <i class="fas fa-user-plus me-2"></i>Add New User
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

    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-primary shadow-sm h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Users</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) ($stats['total_users'] ?? 0)); ?>
                            </div>
                        </div>
                        <div class="col-auto"><i class="fas fa-users fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-success shadow-sm h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Active Users</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) ($stats['active_users'] ?? 0)); ?>
                            </div>
                        </div>
                        <div class="col-auto"><i class="fas fa-user-check fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-warning shadow-sm h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Inactive Users</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) ($stats['inactive_users'] ?? 0)); ?>
                            </div>
                        </div>
                        <div class="col-auto"><i class="fas fa-user-slash fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-info shadow-sm h-100">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">User Roles</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) ($stats['total_roles'] ?? 0)); ?>
                            </div>
                        </div>
                        <div class="col-auto"><i class="fas fa-user-tag fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-filter me-2"></i>Filter Users
            </h6>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <input type="hidden" name="page" value="admin/users">

                <div class="col-md-4">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" class="form-control" id="search" name="search"
                        value="<?php echo htmlspecialchars($search); ?>" placeholder="Username, name, or email...">
                </div>

                <div class="col-md-3">
                    <label for="role_id" class="form-label">Role</label>
                    <select class="form-control" id="role_id" name="role_id">
                        <option value="">All Roles</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?php echo $role['id']; ?>" <?php echo $roleId == $role['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($role['role_name']); ?>
                                <?php if ($role['company_id'] === null): ?>
                                    [System]
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-control" id="status" name="status">
                        <option value="">All</option>
                        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>

                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search me-2"></i>Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-list me-2"></i>System Users
                <span class="badge bg-primary ms-2"><?php echo count($users); ?> users</span>
            </h6>
            <button class="btn btn-sm btn-outline-primary" onclick="window.location.reload()">
                <i class="fas fa-sync-alt me-1"></i> Refresh
            </button>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="usersTable">
                    <thead class="table-light">
                        <tr>
                            <th>Username</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Role</th>
                            <th>Last Login</th>
                            <th class="text-center">Login Count</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5">
                                    <div class="text-muted">
                                        <i class="fas fa-user-slash fa-4x mb-3"></i>
                                        <p class="h5 mb-2">No users found</p>
                                        <p class="mb-0">Click "Add New User" to create one</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $user):
                                // Get role name
                                $roleName = 'Unknown';
                                foreach ($roles as $role) {
                                    if ($role['id'] == $user['role_id']) {
                                        $roleName = $role['role_name'];
                                        break;
                                    }
                                }
                                ?>
                                <tr>
                                    <td><strong><code><?php echo htmlspecialchars($user['username']); ?></code></strong></td>
                                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                    <td>
                                        <a href="mailto:<?php echo htmlspecialchars($user['email']); ?>">
                                            <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($user['email']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['phone'] ?? '-'); ?></td>
                                    <td>
                                        <span class="badge bg-info">
                                            <i class="fas fa-user-tag me-1"></i><?php echo htmlspecialchars($roleName); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($user['last_login']): ?>
                                            <span title="<?php echo htmlspecialchars($user['last_login']); ?>">
                                                <i class="far fa-calendar-alt me-1"></i>
                                                <?php echo date('d/m/Y H:i', strtotime($user['last_login'])); ?>
                                            </span>
                                            <br><small class="text-muted"><?php echo time_ago($user['last_login']); ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">Never</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center"><?php echo number_format((float) ($user['login_count'] ?? 0)); ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($user['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="<?php echo route_url('admin/user_detail', ['id' => $user['id']]); ?>"
                                                class="btn btn-outline-info" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <button type="button" class="btn btn-outline-warning"
                                                onclick='editUser(<?php echo htmlspecialchars(json_encode($user)); ?>)'
                                                title="Edit User">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ($user['id'] != $userId): ?>
                                                <?php if ($user['is_active']): ?>
                                                    <a href="?page=admin/users&toggle=<?php echo $user['id']; ?>&status=0"
                                                        class="btn btn-outline-secondary" onclick="return confirm('Deactivate user?')"
                                                        title="Deactivate">
                                                        <i class="fas fa-ban"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="?page=admin/users&toggle=<?php echo $user['id']; ?>&status=1"
                                                        class="btn btn-outline-success" onclick="return confirm('Activate user?')"
                                                        title="Activate">
                                                        <i class="fas fa-check-circle"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <a href="?page=admin/users&delete=<?php echo $user['id']; ?>"
                                                    class="btn btn-outline-danger"
                                                    onclick="return confirm('Delete user? This action cannot be undone.')"
                                                    title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="addUserModalLabel">
                    <i class="fas fa-user-plus me-2"></i>Add New User
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>
            <form method="POST" action="?page=admin/save-user" id="addUserForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="company_id" value="<?php echo $companyId; ?>">

                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="username" name="username" required>
                            <small class="text-muted">Unique username for login</small>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="full_name" class="form-label">Full Name <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="full_name" name="full_name" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="email" name="email" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="tel" class="form-control" id="phone" name="phone"
                                placeholder="e.g., +250 788 123 456">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="password" name="password" required>
                            <small class="text-muted">Minimum 8 characters</small>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="confirm_password" class="form-label">Confirm Password <span
                                    class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                                required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="role_id" class="form-label">Role <span class="text-danger">*</span></label>
                            <select class="form-control" id="role_id" name="role_id" required>
                                <option value="">Select Role</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>">
                                        <?php echo htmlspecialchars($role['role_name']); ?>
                                        <?php if ($role['company_id'] === null): ?>
                                            [System]
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <div class="form-check mt-2">
                                <input type="checkbox" class="form-check-input" id="is_active" name="is_active"
                                    value="1" checked>
                                <label class="form-check-label" for="is_active">Active</label>
                            </div>
                            <div class="form-text">Inactive users cannot log in</div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Create User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title" id="editUserModalLabel">
                    <i class="fas fa-edit me-2"></i>Edit User
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>
            <form method="POST" action="?page=admin/update-user" id="editUserForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="id" id="edit_id">
                <input type="hidden" name="company_id" value="<?php echo $companyId; ?>">

                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="edit_username" readonly disabled>
                            <small class="text-muted">Username cannot be changed</small>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="edit_full_name" class="form-label">Full Name <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_full_name" name="full_name" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_email" class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="edit_email" name="email" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="edit_phone" class="form-label">Phone</label>
                            <input type="tel" class="form-control" id="edit_phone" name="phone">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_role_id" class="form-label">Role <span class="text-danger">*</span></label>
                            <select class="form-control" id="edit_role_id" name="role_id" required>
                                <option value="">Select Role</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['id']; ?>">
                                        <?php echo htmlspecialchars($role['role_name']); ?>
                                        <?php if ($role['company_id'] === null): ?>
                                            [System]
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">Status</label>
                            <div class="form-check mt-2">
                                <input type="checkbox" class="form-check-input" id="edit_is_active" name="is_active"
                                    value="1">
                                <label class="form-check-label" for="edit_is_active">Active</label>
                            </div>
                        </div>
                    </div>

                    <hr>

                    <div class="row">
                        <div class="col-12">
                            <p class="mb-2">
                                <a data-bs-toggle="collapse" href="#passwordChange" role="button" aria-expanded="false">
                                    <i class="fas fa-key me-2"></i> Change Password (optional)
                                </a>
                            </p>
                            <div class="collapse" id="passwordChange">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="edit_password" class="form-label">New Password</label>
                                        <input type="password" class="form-control" id="edit_password" name="password">
                                        <small class="text-muted">Leave empty to keep current password</small>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <label for="edit_confirm_password" class="form-label">Confirm New
                                            Password</label>
                                        <input type="password" class="form-control" id="edit_confirm_password"
                                            name="confirm_password">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save me-2"></i>Update User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="viewUserModal" tabindex="-1" aria-labelledby="viewUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="viewUserModalLabel">
                    <i class="fas fa-user-circle me-2"></i>User Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>
            <div class="modal-body" id="userDetailsContent">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php $jsFiles = ['admin/users.js']; ?>

<style>
    .user-management .border-left-primary {
        border-left: 4px solid #4e73df !important;
    }

    .user-management .border-left-success {
        border-left: 4px solid #1cc88a !important;
    }

    .user-management .border-left-info {
        border-left: 4px solid #36b9cc !important;
    }

    .user-management .border-left-warning {
        border-left: 4px solid #f6c23e !important;
    }

    .user-management .table td {
        vertical-align: middle;
    }

    .user-management .btn-group {
        gap: 4px;
    }

    .user-management .badge {
        font-weight: 500;
        padding: 0.5em 0.85em;
    }
</style>