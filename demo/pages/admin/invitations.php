<?php
// pages/admin/invitations.php - Enhanced version with company management

declare(strict_types=1);



require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Invitation.php';
require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../models/UserRole.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . route_url('login'));
    exit;
}

$userRole = $_SESSION['user_role'] ?? 'VIW';
$currentUserId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Only Admins can manage invitations
if ($userRole !== 'ADM') {
    SessionManager::flash('error', 'You do not have permission to manage invitations.');
    header('Location: ' . route_url('dashboard'));
    exit;
}

// Check if company context is set
if (!$companyId) {
    SessionManager::flash('error', 'Company context not found. Please log in again.');
    header('Location: ' . route_url('login'));
    exit;
}

$pageTitle = 'Manage Invitations - Administration';
$db = Database::getInstance();
$invitationModel = new Invitation($companyId);
$userModel = new User($companyId);
$roleModel = new UserRole($companyId);

// Get current company (only the admin's own company)
// Admins can only manage invitations for their own company
$selectedCompanyId = $companyId;

// Get available roles for this company (including system roles)
$roles = $roleModel->getCompanyRoles($companyId);

// Filter roles to exclude ADM (admins should be created manually)
$filteredRoles = [];
foreach ($roles as $role) {
    if ($role['role_code'] !== 'ADM') {
        $filteredRoles[] = $role;
    }
}
$roles = $filteredRoles;

// Handle form submission
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $email = trim($_POST['email'] ?? '');
            $roleId = (int) ($_POST['role_id'] ?? 0);
            $fullName = trim($_POST['full_name'] ?? '');
            $expiryDays = (int) ($_POST['expiry_days'] ?? 7);

            // Validate inputs
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.');
            }

            if (empty($roleId)) {
                throw new Exception('Please select a role.');
            }

            // Validate role belongs to this company
            $role = $roleModel->find($roleId);
            if (!$role) {
                throw new Exception('Selected role does not exist.');
            }
            if ($role['company_id'] !== null && $role['company_id'] != $selectedCompanyId) {
                throw new Exception('Selected role does not belong to your company.');
            }

            // Check if user already exists in this company
            $existingUser = $userModel->findByEmail($email);
            if ($existingUser) {
                throw new Exception('A user with this email already exists in your company.');
            }

            // Check if there's already a pending invitation for this email
            $existingInvitation = $invitationModel->getByEmail($email);
            if ($existingInvitation && $existingInvitation['status'] === 'pending') {
                throw new Exception('An invitation has already been sent to this email. Please wait for it to expire or cancel it first.');
            }

            // Create invitation
            $result = $invitationModel->createInvitation(
                $selectedCompanyId,
                $email,
                $roleId,
                $currentUserId,
                $fullName ?: null,
                $expiryDays
            );

            $message = "Invitation sent successfully to {$email}";

            // Log the action
            error_log("Admin {$currentUserId} sent invitation to {$email} for company {$selectedCompanyId}");

        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Handle AJAX actions
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    // Verify CSRF for AJAX requests
    $headers = getallheaders();
    $ajaxToken = $headers['X-CSRF-Token'] ?? $_GET['csrf_token'] ?? '';

    if (!CSRF::validate($ajaxToken)) {
        echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
        exit;
    }

    $invitationId = (int) ($_GET['id'] ?? 0);
    $action = $_GET['action'];

    try {
        switch ($action) {
            case 'resend':
                $result = $invitationModel->resendInvitation($invitationId);
                echo json_encode(['success' => $result, 'message' => $result ? 'Invitation resent successfully.' : 'Failed to resend invitation.']);
                break;
            case 'cancel':
                $result = $invitationModel->cancelInvitation($invitationId);
                echo json_encode(['success' => $result, 'message' => $result ? 'Invitation cancelled successfully.' : 'Failed to cancel invitation.']);
                break;
            case 'approve':
                $userId = (int) ($_GET['user_id'] ?? 0);
                $result = $userModel->approveUser($userId, $currentUserId);
                echo json_encode(['success' => $result, 'message' => $result ? 'User approved successfully.' : 'Failed to approve user.']);
                break;
            case 'reject':
                $userId = (int) ($_GET['user_id'] ?? 0);
                $reason = $_GET['reason'] ?? null;
                $result = $userModel->rejectUser($userId, $reason);
                echo json_encode(['success' => $result, 'message' => $result ? 'User rejected successfully.' : 'Failed to reject user.']);
                break;
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Get pending registrations (users waiting for approval) for this company
$pendingRegistrations = $userModel->getPendingApproval($selectedCompanyId);

// Get invitations for current company
$invitations = $invitationModel->getByCompany($selectedCompanyId, $_GET['status'] ?? 'pending');
$stats = $invitationModel->getStats($selectedCompanyId);

// Get user registration statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_active = 1 AND is_deleted = 0 THEN 1 ELSE 0 END) as active_users,
        SUM(CASE WHEN is_active = 0 AND is_deleted = 0 THEN 1 ELSE 0 END) as pending_approval
    FROM users 
    WHERE company_id = ?
");
$stmt->execute([$selectedCompanyId]);
$userStats = $stmt->fetch();
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <a href="?page=admin/users" class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Users
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-envelope me-2 text-primary"></i>User Invitations
                    </h2>
                    <p class="mb-0 text-muted">Invite new users to join your organization</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-white bg-primary">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title">Total Invitations</h6>
                            <h3><?php echo number_format((float)$stats['total'] ?? 0); ?></h3>
                        </div>
                        <i class="fas fa-envelope fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-warning">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title">Pending</h6>
                            <h3><?php echo number_format((float)$stats['pending'] ?? 0); ?></h3>
                        </div>
                        <i class="fas fa-clock fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-success">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title">Accepted</h6>
                            <h3><?php echo number_format((float)$stats['accepted'] ?? 0); ?></h3>
                        </div>
                        <i class="fas fa-check-circle fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-info">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="card-title">Pending Approval</h6>
                            <h3><?php echo number_format((float)$userStats['pending_approval'] ?? 0); ?></h3>
                        </div>
                        <i class="fas fa-user-clock fa-2x"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Messages -->
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

    <!-- Invitation Form -->
    <div class="card shadow mb-4">
        <div class="card-header bg-white">
            <h5 class="mb-0">
                <i class="fas fa-user-plus me-2 text-primary"></i>Send New Invitation
            </h5>
        </div>
        <div class="card-body">
            <form method="POST" class="row g-3" id="invitationForm">
                <input type="hidden" name="csrf_token" value="<?php echo CSRF::generate(); ?>">
                <input type="hidden" name="company_id" value="<?php echo $selectedCompanyId; ?>">

                <div class="col-md-4">
                    <label for="email" class="form-label">Email Address *</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                        <input type="email" class="form-control" id="email" name="email" required
                            placeholder="user@example.com">
                    </div>
                </div>

                <div class="col-md-3">
                    <label for="full_name" class="form-label">Full Name</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                        <input type="text" class="form-control" id="full_name" name="full_name" placeholder="John Doe">
                    </div>
                    <small class="text-muted">Optional - user can provide during registration</small>
                </div>

                <div class="col-md-2">
                    <label for="role_id" class="form-label">Role *</label>
                    <select class="form-select" id="role_id" name="role_id" required>
                        <option value="">Select...</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?php echo $role['id']; ?>">
                                <?php echo htmlspecialchars($role['role_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label for="expiry_days" class="form-label">Expires in</label>
                    <select class="form-select" id="expiry_days" name="expiry_days">
                        <option value="3">3 days</option>
                        <option value="7" selected>7 days</option>
                        <option value="14">14 days</option>
                        <option value="30">30 days</option>
                    </select>
                </div>

                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100" id="sendInviteBtn">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Pending Approvals Section -->
    <?php if (!empty($pendingRegistrations)): ?>
        <div class="card shadow mb-4 border-warning">
            <div class="card-header bg-warning text-white">
                <h5 class="mb-0">
                    <i class="fas fa-user-clock me-2"></i>Pending Approvals
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Username</th>
                                <th>Requested Role</th>
                                <th>Requested Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingRegistrations as $pending): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($pending['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($pending['email']); ?></td>
                                    <td><?php echo htmlspecialchars($pending['username']); ?></td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?php echo htmlspecialchars($pending['role_name'] ?? 'Unknown'); ?>
                                        </span>
                                    </td> <td><?php echo date('M d, Y H:i', strtotime($pending['requested_at'])); ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-success"
                                            onclick="approveUser(<?php echo $pending['id']; ?>)">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                        <button class="btn btn-sm btn-danger"
                                            onclick="rejectUser(<?php echo $pending['id']; ?>)">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                    </td> ?>
                                <?php endforeach; ?>
                        </tbody>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Invitations List -->
<div class="card shadow">
    <div class="card-header bg-white">
        <ul class="nav nav-tabs card-header-tabs">
            <li class="nav-item">
                <a class="nav-link <?php echo !isset($_GET['status']) || $_GET['status'] === 'pending' ? 'active' : ''; ?>"
                    href="?page=admin/invitations&status=pending">
                    Pending
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo ($_GET['status'] ?? '') === 'accepted' ? 'active' : ''; ?>"
                    href="?page=admin/invitations&status=accepted">
                    Accepted
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo ($_GET['status'] ?? '') === 'expired' ? 'active' : ''; ?>"
                    href="?page=admin/invitations&status=expired">
                    Expired
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo ($_GET['status'] ?? '') === 'all' ? 'active' : ''; ?>"
                    href="?page=admin/invitations&status=all">
                    All
                </a>
            </li>
        </ul>
    </div>
    <div class="card-body">
        <?php if (empty($invitations)): ?>
            <div class="text-center py-4">
                <i class="fas fa-envelope-open fa-3x text-muted mb-3"></i>
                <p class="mb-0">No invitations found</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover" id="invitationsTable">
                    <thead>
                        <tr>
                            <th>Email</th>
                            <th>Full Name</th>
                            <th>Role</th>
                            <th>Sent By</th>
                            <th>Sent Date</th>
                            <th>Expires</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invitations as $inv): ?>
                            <?php
                            $status = 'pending';
                            $badgeClass = 'warning';

                            if ($inv['used_at']) {
                                $status = 'accepted';
                                $badgeClass = 'success';
                            } elseif (strtotime($inv['expires_at']) < time()) {
                                $status = 'expired';
                                $badgeClass = 'danger';
                            }
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($inv['email']); ?></td>
                                <td><?php echo htmlspecialchars($inv['full_name'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($inv['role_name']); ?></td>
                                <td><?php echo htmlspecialchars($inv['created_by_name']); ?></td>
                                <td><?php echo date('M d, Y H:i', strtotime($inv['created_at'])); ?></td>
                                <td><?php echo date('M d, Y', strtotime($inv['expires_at'])); ?></td>
                                <span class="badge bg-<?php echo $badgeClass; ?>">
                                    <?php echo ucfirst($status); ?>
                                </span>
                                </td> <td>
                                <?php if ($status === 'pending'): ?>
                                    <button class="btn btn-sm btn-outline-primary"
                                        onclick="copyInvitationLink('<?php echo $inv['token']; ?>')" title="Copy invitation link">
                                        <i class="fas fa-link"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-warning"
                                        onclick="resendInvitation(<?php echo $inv['id']; ?>)" title="Resend invitation">
                                        <i class="fas fa-redo"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger"
                                        onclick="cancelInvitation(<?php echo $inv['id']; ?>)" title="Cancel invitation">
                                        <i class="fas fa-times"></i>
                                    </button>
                                <?php elseif ($status === 'accepted'): ?>
                                    <span class="text-success">
                                        <i class="fas fa-check-circle"></i>
                                        <?php echo $inv['used_by_name'] ? 'by ' . htmlspecialchars($inv['used_by_name']) : 'Accepted'; ?>
                                    </span>
                                <?php endif; ?>
                                </td> 
                            <?php endforeach; ?>
                    </tbody>
            </div>
        </div>
    <?php endif; ?>
</div>
</div>
</div>

<!-- Invitation Link Modal -->
<div class="modal fade" id="invitationLinkModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-link me-2"></i>Invitation Link
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Share this link with the user:</p>
                <div class="input-group">
                    <input type="text" class="form-control" id="invitationLink" readonly>
                    <button class="btn btn-outline-primary" type="button" onclick="copyToClipboard()">
                        <i class="fas fa-copy"></i>
                    </button>
                </div>
                <p class="text-muted small mt-2">
                    <i class="fas fa-info-circle"></i>
                    This link will expire in the configured number of days and can only be used once.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
    const csrfToken = '<?php echo CSRF::generate(); ?>';
    const baseUrl = '<?php echo BASE_URL; ?>';
    const currentStatus = '<?php echo $_GET['status'] ?? 'pending'; ?>';

    function copyInvitationLink(token) {
        const link = `${baseUrl}/auth/register.php?token=${token}`;
        document.getElementById('invitationLink').value = link;
        const modal = new bootstrap.Modal(document.getElementById('invitationLinkModal'));
        modal.show();
    }

    function copyToClipboard() {
        const linkInput = document.getElementById('invitationLink');
        linkInput.select();
        document.execCommand('copy');

        // Show feedback
        const btn = event.target.closest('button');
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i>';
        setTimeout(() => {
            btn.innerHTML = originalHtml;
        }, 2000);
    }

    function resendInvitation(id) {
        if (confirm('Resend this invitation? The user will receive a new email.')) {
            fetch(`?page=admin/invitations&action=resend&id=${id}&csrf_token=${csrfToken}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to resend invitation.');
                });
        }
    }

    function cancelInvitation(id) {
        if (confirm('Cancel this invitation? The user will not be able to use it.')) {
            fetch(`?page=admin/invitations&action=cancel&id=${id}&csrf_token=${csrfToken}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to cancel invitation.');
                });
        }
    }

    function approveUser(userId) {
        if (confirm('Approve this user registration? They will be able to log in.')) {
            fetch(`?page=admin/invitations&action=approve&user_id=${userId}&csrf_token=${csrfToken}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to approve user.');
                });
        }
    }

    function rejectUser(userId) {
        const reason = prompt('Please provide a reason for rejection:');
        if (reason !== null) {
            fetch(`?page=admin/invitations&action=reject&user_id=${userId}&reason=${encodeURIComponent(reason)}&csrf_token=${csrfToken}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to reject user.');
                });
        }
    }
</script>

<?php $jsFiles = ['admin/invitation.js']; ?>

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

    .table td {
        vertical-align: middle;
    }

    .btn-group-sm .btn {
        margin: 0 2px;
    }
</style>