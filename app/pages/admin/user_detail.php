<?php
// pages/admin/user_detail.php
declare(strict_types=1);

$pageTitle = 'User Details - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../models/UserRole.php';
require_once __DIR__ . '/../../models/Company.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission (Admins and Managers can view user details)
if (!in_array($userRole, ['ADM', 'MGR'])) {
    SessionManager::flash('error', 'You do not have permission to view user details.');
    header('Location: ' . route_url('dashboard'));
    exit;
}

// Check if company context is set
if (!$companyId) {
    SessionManager::flash('error', 'Company context not found. Please log in again.');
    header('Location: ' . route_url('login'));
    exit;
}

// Get user ID from URL
$viewUserId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$viewUserId) {
    SessionManager::flash('error', 'User ID is required.');
    header('Location: ' . route_url('admin/users'));
    exit;
}

// Initialize models with company context
$userModel = new User($companyId);
$roleModel = new UserRole($companyId);
$companyModel = new Company();

// Get user details
$user = $userModel->getWithRole($viewUserId);

if (!$user) {
    SessionManager::flash('error', 'User not found or does not belong to your company.');
    header('Location: ' . route_url('admin/users'));
    exit;
}

// Get role details
$role = $roleModel->find($user['role_id']);

// Get company details
$company = $companyModel->find($user['company_id']);

// Get login history (last 10 logins)
$loginHistory = $userModel->getLoginHistory($viewUserId, 10);

// Get user activity log (last 20 activities)
$activitySql = "
    SELECT action, entity_type, entity_id, new_data, created_at 
    FROM activity_log 
    WHERE user_id = :user_id AND company_id = :company_id
    ORDER BY created_at DESC 
    LIMIT 20
";
$activityStmt = $db->prepare($activitySql);
$activityStmt->execute([
    'user_id' => $viewUserId,
    'company_id' => $companyId
]);
$recentActivity = $activityStmt->fetchAll();

// Get user statistics
$statsSql = "
    SELECT 
        (SELECT COUNT(*) FROM sales_invoices WHERE created_by = :p_1_user_id AND company_id = :p_1_company_id AND status != 'cancelled') as invoices_created,
        (SELECT SUM(total_amount) FROM sales_invoices WHERE created_by = :p_2_user_id AND company_id = :p_2_company_id AND status != 'cancelled') as invoices_total,
        (SELECT COUNT(*) FROM purchase_orders WHERE created_by = :p_3_user_id AND company_id = :p_3_company_id AND status != 'cancelled') as purchase_orders_created,
        (SELECT COUNT(*) FROM quotations WHERE created_by = :p_4_user_id AND company_id = :p_4_company_id) as quotations_created,
        (SELECT COUNT(*) FROM inventory_transactions WHERE created_by = :p_5_user_id AND company_id = :p_5_company_id) as inventory_transactions
";
$statsStmt = $db->prepare($statsSql);
$statsStmt->execute([
    'p_1_user_id' => $viewUserId,
    'p_1_company_id' => $companyId,
    'p_2_user_id' => $viewUserId,
    'p_2_company_id' => $companyId,
    'p_3_user_id' => $viewUserId,
    'p_3_company_id' => $companyId,
    'p_4_user_id' => $viewUserId,
    'p_4_company_id' => $companyId,
    'p_5_user_id' => $viewUserId,
    'p_5_company_id' => $companyId
]);
$userStats = $statsStmt->fetch();

// Calculate account age
$createdAt = new DateTime($user['created_at']);
$now = new DateTime();
$accountAge = $createdAt->diff($now);

// Determine if user is online (last login within 5 minutes)
$isOnline = false;
if ($user['last_login']) {
    $lastLogin = new DateTime($user['last_login']);
    $diff = $now->getTimestamp() - $lastLogin->getTimestamp();
    $isOnline = $diff < 300; // 5 minutes
}

// Generate CSRF token
$csrfToken = CSRF::generate();
?>

<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <a href="<?php echo route_url('admin/users'); ?>" class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Users
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-user-circle me-2"></i>User Details
                    </h2>
                    <p class="mb-0 text-muted">View detailed information about this user</p>
                </div>
                <div class="mt-2 mt-sm-0">
                    <?php if ($userRole === 'ADM' && $viewUserId != $userId): ?>
                        <a href="<?php echo route_url('admin/users'); ?>?edit=<?php echo $viewUserId; ?>"
                            class="btn btn-warning">
                            <i class="fas fa-edit me-2"></i> Edit User
                        </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-primary" onclick="window.print()">
                        <i class="fas fa-print me-2"></i> Print
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

    <div class="row">
        <div class="col-md-4 mb-4">
            <div class="card shadow h-100">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0"><i class="fas fa-user me-2"></i>Personal Information</h6>
                </div>
                <div class="card-body">
                    <div class="text-center mb-3">
                        <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center"
                            style="width: 100px; height: 100px;">
                            <i class="fas fa-user-circle fa-5x text-primary"></i>
                        </div>
                        <h5 class="mt-2 mb-1"><?php echo htmlspecialchars($user['full_name']); ?></h5>
                        <span class="badge <?php echo $user['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                            <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                        <?php if ($isOnline): ?>
                            <span class="badge bg-info ms-1">
                                <i class="fas fa-circle me-1" style="font-size: 8px;"></i> Online
                            </span>
                        <?php endif; ?>
                    </div>

                    <table class="table table-sm">
                        <tr>
                            <th style="width: 40%">Username:</th>
                            <td><code><?php echo htmlspecialchars($user['username']); ?></code></td>
                        </tr>
                        <tr>
                            <th>Full Name:</th>
                            <td><strong><?php echo htmlspecialchars($user['full_name']); ?></strong></td>
                        </tr>
                        <tr>
                            <th>Email:</th>
                            <td><a href="mailto:<?php echo htmlspecialchars($user['email']); ?>"><?php echo htmlspecialchars($user['email']); ?></a></td>
                        </tr>
                        <tr>
                            <th>Phone:</th>
                            <td><?php echo $user['phone'] ? htmlspecialchars($user['phone']) : '<span class="text-muted">Not provided</span>'; ?></td>
                        </tr>
                        <tr>
                            <th>Role:</th>
                            <td>
                                <span class="badge bg-info"><?php echo htmlspecialchars($role['role_name'] ?? 'Unknown'); ?></span>
                                <small class="text-muted">(<?php echo htmlspecialchars($role['role_code'] ?? 'N/A'); ?>)</small>
                            </td>
                        </tr>
                        <tr>
                            <th>Company:</th>
                            <td>
                                <strong><?php echo htmlspecialchars($company['company_name'] ?? 'N/A'); ?></strong>
                                <br><small class="text-muted"><?php echo htmlspecialchars($company['company_code'] ?? ''); ?></small>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-4 mb-4">
            <div class="card shadow h-100">
                <div class="card-header bg-success text-white">
                    <h6 class="mb-0"><i class="fas fa-chart-line me-2"></i>Account Statistics</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <th style="width: 50%">Account Created:</th>
                            <td><?php echo date('d/m/Y H:i:s', strtotime($user['created_at'])); ?></td>
                        </tr>
                        <tr>
                            <th>Account Age:</th>
                            <td><?php echo $accountAge->days; ?> days</td>
                        </tr>
                        <tr>
                            <th>Last Login:</th>
                            <td>
                                <?php if ($user['last_login']): ?>
                                    <?php echo date('d/m/Y H:i:s', strtotime($user['last_login'])); ?>
                                    <br><small class="text-muted"><?php echo time_ago($user['last_login']); ?></small>
                                <?php else: ?>
                                    <span class="text-muted">Never</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Login Count:</th>
                            <td><strong><?php echo number_format($user['login_count'] ?? 0); ?></strong> times</td>
                        </tr>
                        <tr>
                            <th>Last Updated:</th>
                            <td><?php echo $user['updated_at'] ? date('d/m/Y H:i:s', strtotime($user['updated_at'])) : '-'; ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-4 mb-4">
            <div class="card shadow h-100">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Activity Summary</h6>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6 mb-3">
                            <div class="h3 mb-0 text-primary"><?php echo number_format((float)($userStats['invoices_created'] ?? 0)); ?></div>
                            <small class="text-muted">Invoices Created</small>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="h3 mb-0 text-success"><?php echo number_format((float)($userStats['purchase_orders_created'] ?? 0)); ?></div>
                            <small class="text-muted">Purchase Orders</small>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="h3 mb-0 text-warning"><?php echo number_format((float)($userStats['quotations_created'] ?? 0)); ?></div>
                            <small class="text-muted">Quotations</small>
                        </div>
                        <div class="col-6 mb-3">
                            <div class="h3 mb-0 text-info"><?php echo number_format((float)($userStats['inventory_transactions'] ?? 0)); ?></div>
                            <small class="text-muted">Inventory Transactions</small>
                        </div>
                    </div>
                    <hr>
                    <div class="text-center">
                        <div class="h5 mb-0"><?php echo format_currency((float)($userStats['invoices_total'] ?? 0)); ?></div>
                        <small class="text-muted">Total Sales Value</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-sign-in-alt me-2"></i>Login History
                <span class="badge bg-primary ms-2">Last <?php echo count($loginHistory); ?> logins</span>
            </h6>
        </div>
        <div class="card-body">
            <?php if (empty($loginHistory)): ?>
                <div class="text-center py-4">
                    <i class="fas fa-history fa-3x text-muted mb-3"></i>
                    <p class="mb-0">No login records found.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>IP Address</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($loginHistory as $login): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y H:i:s', strtotime($login['created_at'])); ?></td>
                                    <td><code><?php echo htmlspecialchars($login['ip_address'] ?? 'Unknown'); ?></code></td>
                                    <td>
                                        <?php if ($login['success']): ?>
                                            <span class="badge bg-success">Success</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Failed</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

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
                                <th>Details</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentActivity as $activity): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($activity['action']); ?></span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($activity['entity_type']); ?> #<?php echo $activity['entity_id']; ?>
                                    </td>
                                    <td>
                                        <?php
                                        if ($activity['new_data']) {
                                            $data = json_decode($activity['new_data'], true);
                                            if ($data) {
                                                echo '<small class="text-muted">' . htmlspecialchars(json_encode($data)) . '</small>';
                                            }
                                        }
                                        ?>
                                    </td>
                                    <td><small><?php echo date('d/m/Y H:i:s', strtotime($activity['created_at'])); ?></small></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    // Helper function for time ago
    function timeAgo(timestamp) {
        const date = new Date(timestamp);
        const now = new Date();
        const seconds = Math.floor((now - date) / 1000);

        if (seconds < 60) return 'just now';
        if (seconds < 3600) return Math.floor(seconds / 60) + ' minutes ago';
        if (seconds < 86400) return Math.floor(seconds / 3600) + ' hours ago';
        if (seconds < 604800) return Math.floor(seconds / 86400) + ' days ago';
        return date.toLocaleDateString();
    }
</script>

<style>
    @media print {
        .btn-group, .btn, .modal, .sidebar, .card-header .btn, footer, nav, .sidebar-card, #sidebar, .sidebar, .top-navbar, .btn-outline-secondary {
            display: none !important;
        }
        body { background: white; padding: 0; margin: 0; }
        .container-fluid { width: 100%; padding: 0; margin: 0; }
        .card { border: 1px solid #ddd !important; box-shadow: none !important; break-inside: avoid; }
        .card-header { background: #f8f9fc !important; }
        .badge { border: 1px solid #000; background: none !important; color: #000 !important; }
    }
    .border-left-primary { border-left: 4px solid #4e73df !important; }
    .border-left-success { border-left: 4px solid #1cc88a !important; }
    .border-left-info { border-left: 4px solid #36b9cc !important; }
    .border-left-warning { border-left: 4px solid #f6c23e !important; }
</style>