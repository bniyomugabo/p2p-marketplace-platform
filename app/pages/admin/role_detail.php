<?php
// pages/admin/roles_detail.php
declare(strict_types=1);

$pageTitle = 'Role Details - Inventory Management System';
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
    SessionManager::flash('error', 'You do not have permission to view role details.');
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

// Get users with this role
$users = $userModel->all(['id', 'username', 'full_name', 'email', 'is_active', 'last_login'], "role_id = $roleId");

// Get permissions for this role
$permissions = $permissionModel->getRolePermissions($roleId);
$permissionsGrouped = [];
foreach ($permissions as $perm) {
    $module = $perm['module'];
    if (!isset($permissionsGrouped[$module])) {
        $permissionsGrouped[$module] = [];
    }
    $permissionsGrouped[$module][] = $perm;
}

// Get role statistics
$stats = [
    'total_users' => count($users),
    'active_users' => count(array_filter($users, fn($u) => $u['is_active'] == 1)),
    'total_permissions' => count($permissions),
    'modules_count' => count($permissionsGrouped)
];

// Get recent activity for this role
$activitySql = "
    SELECT action, entity_type, entity_id, created_at 
    FROM activity_log 
    WHERE entity_type = 'role' AND entity_id = :role_id AND company_id = :company_id
    ORDER BY created_at DESC 
    LIMIT 20
";
$activityStmt = $db->prepare($activitySql);
$activityStmt->execute([
    'role_id' => $roleId,
    'company_id' => $companyId
]);
$recentActivity = $activityStmt->fetchAll();

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
                        <i class="fas fa-user-shield me-2"></i>Role Details
                    </h2>
                    <p class="mb-0 text-muted">View detailed information about this role</p>
                </div>
                <div class="mt-2 mt-sm-0">
                    <?php if (!$role['is_system_role'] && $role['company_id'] !== null): ?>
                        <a href="<?php echo route_url('admin/roles'); ?>?edit=<?php echo $roleId; ?>"
                            class="btn btn-warning">
                            <i class="fas fa-edit me-2"></i>Edit Role
                        </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-primary" onclick="window.print()">
                        <i class="fas fa-print me-2"></i>Print
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if ($flash = SessionManager::flash('success')): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($flash); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($flash = SessionManager::flash('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($flash); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Role Information Card -->
    <div class="row">
        <div class="col-md-4 mb-4">
            <div class="card shadow h-100">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Role Information</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <th style="width: 40%">Role Name:</th>
                            <td><strong>
                                    <?php echo htmlspecialchars($role['role_name']); ?>
                                </strong></td>
                        </tr>
                        <tr>
                            <th>Role Code:</th>
                            <td><code><?php echo htmlspecialchars($role['role_code']); ?></code></td>
                        </tr>
                        <tr>
                            <th>Role Type:</th>
                            <td>
                                <?php if ($role['is_system_role'] || $role['company_id'] === null): ?>
                                    <span class="badge bg-secondary">System Role</span>
                                    <small class="text-muted d-block">Available to all companies</small>
                                <?php else: ?>
                                    <span class="badge bg-primary">Company Role</span>
                                    <small class="text-muted d-block">Custom role for this company</small>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Created At:</th>
                            <td>
                                <?php echo date('d/m/Y H:i:s', strtotime($role['created_at'])); ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Description:</th>
                            <td>
                                <?php if (!empty($role['description'])): ?>
                                    <?php echo nl2br(htmlspecialchars($role['description'])); ?>
                                <?php else: ?>
                                    <span class="text-muted">No description provided</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="col-md-8 mb-4">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <div class="card border-left-primary shadow h-100">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                        Total Users
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php echo number_format($stats['total_users']); ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-users fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4 mb-3">
                    <div class="card border-left-success shadow h-100">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                        Active Users
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php echo number_format($stats['active_users']); ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-user-check fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4 mb-3">
                    <div class="card border-left-info shadow h-100">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                        Permissions
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php echo number_format($stats['total_permissions']); ?>
                                        <small class="text-muted">(
                                            <?php echo $stats['modules_count']; ?> modules)
                                        </small>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-key fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Users with this Role -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-users me-2"></i>Users with this Role
                <span class="badge bg-primary ms-2">
                    <?php echo count($users); ?> users
                </span>
            </h6>
        </div>
        <div class="card-body">
            <?php if (empty($users)): ?>
                <div class="text-center py-4">
                    <i class="fas fa-user-slash fa-3x text-muted mb-3"></i>
                    <p class="mb-0">No users have been assigned to this role yet.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover" id="usersTable">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Last Login</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($user['username']); ?></code></td>
                                    <td>
                                        <?php echo htmlspecialchars($user['full_name']); ?>
                                    </td>
                                    <td><a href="mailto:<?php echo htmlspecialchars($user['email']); ?>">
                                            <?php echo htmlspecialchars($user['email']); ?>
                                        </a></td>
                                    <td>
                                        <?php if ($user['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($user['last_login']): ?>
                                            <?php echo date('d/m/Y H:i', strtotime($user['last_login'])); ?>
                                        <?php else: ?>
                                            <span class="text-muted">Never</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="<?php echo route_url('admin/user_detail', ['id' => $user['id']]); ?>"
                                            class="btn btn-sm btn-outline-primary" title="View User">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Permissions List -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-key me-2"></i>Role Permissions
                <span class="badge bg-primary ms-2">
                    <?php echo $stats['total_permissions']; ?> permissions
                </span>
            </h6>
        </div>
        <div class="card-body">
            <?php if (empty($permissionsGrouped)): ?>
                <div class="text-center py-4">
                    <i class="fas fa-lock fa-3x text-muted mb-3"></i>
                    <p class="mb-0">No permissions have been assigned to this role yet.</p>
                    <?php if (!$role['is_system_role'] && $role['company_id'] !== null): ?>
                        <a href="<?php echo route_url('admin/roles'); ?>?edit=<?php echo $roleId; ?>"
                            class="btn btn-sm btn-primary mt-3">
                            <i class="fas fa-edit me-1"></i> Edit Permissions
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="row">
                    <?php foreach ($permissionsGrouped as $module => $modulePermissions): ?>
                        <div class="col-md-6 mb-4">
                            <div class="card h-100">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0">
                                        <i class="fas fa-folder-open me-2 text-primary"></i>
                                        <?php echo ucfirst(str_replace('_', ' ', $module)); ?>
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <?php foreach ($modulePermissions as $permission): ?>
                                            <div class="col-12 mb-2">
                                                <div class="d-flex align-items-center">
                                                    <i class="fas fa-check-circle text-success me-2"></i>
                                                    <div>
                                                        <strong>
                                                            <?php echo htmlspecialchars($permission['permission_name']); ?>
                                                        </strong>
                                                        <br>
                                                        <small class="text-muted">
                                                            <?php echo htmlspecialchars($permission['permission_code']); ?>
                                                        </small>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Activity -->
    <?php if (!empty($recentActivity)): ?>
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-history me-2"></i>Recent Activity
                </h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Action</th>
                                <th>Entity</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentActivity as $activity): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?php echo htmlspecialchars($activity['action']); ?>
                                        </span>
                    </div>
                    <td>
                        <?php echo htmlspecialchars($activity['entity_type']); ?> #
                        <?php echo $activity['entity_id']; ?>
                </div>
                <td><small>
                        <?php echo date('d/m/Y H:i:s', strtotime($activity['created_at'])); ?>
                    </small>
            </div>
            ?>
        <?php endforeach; ?>
        </tbody>
    </div>
    </div>
    </div>
    </div>
<?php endif; ?>
</div>

<style>
    @media print {

        .btn-group,
        .btn,
        .modal,
        .sidebar,
        .card-header .btn,
        footer,
        nav,
        .sidebar-card,
        #sidebar,
        .sidebar,
        .top-navbar,
        .btn-outline-secondary {
            display: none !important;
        }

        body {
            background: white;
            padding: 0;
            margin: 0;
        }

        .container-fluid {
            width: 100%;
            padding: 0;
            margin: 0;
        }

        .card {
            border: 1px solid #ddd !important;
            box-shadow: none !important;
            break-inside: avoid;
        }

        .card-header {
            background: #f8f9fc !important;
        }

        .badge {
            border: 1px solid #000;
            background: none !important;
            color: #000 !important;
        }
    }

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
</style>