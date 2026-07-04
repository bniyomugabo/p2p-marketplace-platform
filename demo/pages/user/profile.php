<?php
// pages/user/profile.php
declare(strict_types=1);

$pageTitle = 'My Profile - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../models/UserRole.php';

$db = Database::getInstance();
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

if (!$userId) {
    SessionManager::flash('error', 'Please log in to view your profile.');
    header('Location: ' . BASE_URL . '/auth/signin.php');
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

// Get user details with role
$user = $userModel->getWithRole($userId);

if (!$user) {
    SessionManager::flash('error', 'User not found.');
    header('Location: ' . route_url('dashboard'));
    exit;
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
        SessionManager::flash('error', 'Invalid security token.');
        header('Location: ?page=profile');
        exit;
    }

    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'update_profile') {
            // Update profile information
            $updateData = [
                'full_name' => trim($_POST['full_name']),
                'email' => trim($_POST['email']),
                'phone' => !empty($_POST['phone']) ? trim($_POST['phone']) : null,
            ];

            // Validate email
            if (!filter_var($updateData['email'], FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.');
            }

            // Check if email is already taken by another user in the same company
            $existingUser = $userModel->findByEmail($updateData['email']);
            if ($existingUser && $existingUser['id'] != $userId) {
                throw new Exception('Email address is already in use by another account in your company.');
            }

            $userModel->updateProfile($userId, $updateData);

            // Update session
            $_SESSION['full_name'] = $updateData['full_name'];
            $_SESSION['email'] = $updateData['email'];

            SessionManager::flash('success', 'Profile updated successfully.');

        } elseif ($action === 'change_password') {
            // Change password
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            // Verify current password
            if (!password_verify($currentPassword, $user['password_hash'])) {
                throw new Exception('Current password is incorrect.');
            }

            // Validate new password
            if (strlen($newPassword) < 8) {
                throw new Exception('New password must be at least 8 characters long.');
            }

            if ($newPassword !== $confirmPassword) {
                throw new Exception('New passwords do not match.');
            }

            // Update password
            $userModel->updatePassword($userId, $newPassword);

            // Log activity
            $activitySql = "
                INSERT INTO activity_log (company_id, user_id, action, entity_type, entity_id, new_data, created_at)
                VALUES (:company_id, :user_id, 'password_changed', 'user', :user_id, :new_data, NOW())
            ";
            $activityStmt = $db->prepare($activitySql);
            $activityStmt->execute([
                'company_id' => $companyId,
                'user_id' => $userId,
                'user_id' => $userId,
                'new_data' => json_encode(['action' => 'password_change'])
            ]);

            SessionManager::flash('success', 'Password changed successfully.');

        } elseif ($action === 'update_avatar') {
            // Handle avatar upload
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $avatarUrl = uploadAvatar($_FILES['avatar'], $userId, $companyId);
                $userModel->updateAvatar($userId, $avatarUrl);
                
                // Update session
                $_SESSION['avatar'] = $avatarUrl;

                SessionManager::flash('success', 'Profile picture updated successfully.');
            } else {
                throw new Exception('Please select an image to upload.');
            }
        }

    } catch (Exception $e) {
        SessionManager::flash('error', $e->getMessage());
    }

    header('Location: ?page=profile');
    exit;
}

// Avatar upload function
function uploadAvatar($file, $userId, $companyId)
{
    $uploadDir = __DIR__ . '/../../assets/uploads/avatars/company_' . $companyId . '/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 2 * 1024 * 1024; // 2MB

    if (!in_array($file['type'], $allowedTypes)) {
        throw new Exception('Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.');
    }

    if ($file['size'] > $maxSize) {
        throw new Exception('File is too large. Maximum size is 2MB.');
    }

    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'avatar_' . $userId . '_' . time() . '.' . $extension;
    $filepath = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return 'uploads/avatars/company_' . $companyId . '/' . $filename;
    }

    throw new Exception('Failed to upload image.');
}

// Get login history
$loginHistory = $userModel->getLoginHistory($userId, 10);

// Get recent activity
$activitySql = "
    SELECT action, entity_type, entity_id, new_data, created_at 
    FROM activity_log 
    WHERE user_id = :user_id AND company_id = :company_id
    ORDER BY created_at DESC 
    LIMIT 20
";
$activityStmt = $db->prepare($activitySql);
$activityStmt->execute([
    'user_id' => $userId,
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
            <a href="<?php echo route_url('dashboard'); ?>" class="btn btn-outline-secondary btn-sm mb-2">
                <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
            </a>
            <h2 class="h4 mb-1 text-gray-800">
                <i class="fas fa-user-circle me-2"></i>My Profile
            </h2>
            <p class="mb-0 text-muted">Manage your account settings and preferences</p>
        </div>
    </div>

    <!-- Flash Messages -->
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
        <!-- Profile Information Column -->
        <div class="col-lg-4">
            <!-- Profile Card -->
            <div class="card shadow mb-4">
                <div class="card-body text-center">
                    <!-- Avatar -->
                    <div class="mb-3 position-relative">
                        <?php if (!empty($user['avatar'])): ?>
                            <img src="<?php echo asset_url($user['avatar']); ?>" 
                                 class="rounded-circle img-thumbnail" 
                                 style="width: 150px; height: 150px; object-fit: cover;"
                                 alt="Profile Picture">
                        <?php else: ?>
                            <div class="mx-auto rounded-circle bg-primary text-white d-flex align-items-center justify-content-center"
                                 style="width: 150px; height: 150px; font-size: 48px;">
                                <?php echo strtoupper(substr($user['full_name'] ?? $user['username'], 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Upload button -->
                        <button type="button" class="btn btn-sm btn-primary position-absolute bottom-0 end-50 translate-middle-x mb-2"
                                data-bs-toggle="modal" data-bs-target="#avatarModal">
                            <i class="fas fa-camera"></i>
                        </button>
                    </div>
                    
                    <h5 class="fw-bold"><?php echo htmlspecialchars($user['full_name']); ?></h5>
                    <p class="text-muted mb-2">@<?php echo htmlspecialchars($user['username']); ?></p>
                    
                    <!-- Role Badge -->
                    <span class="badge bg-info mb-3">
                        <?php echo htmlspecialchars($user['role_name'] ?? $user['role_code'] ?? 'User'); ?>
                    </span>
                    
                    <!-- Account Status -->
                    <div class="d-flex justify-content-center gap-2 mb-3">
                        <?php if ($user['is_active']): ?>
                            <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Active Account</span>
                        <?php else: ?>
                            <span class="badge bg-secondary"><i class="fas fa-ban me-1"></i>Inactive Account</span>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Account Info -->
                    <div class="text-start small">
                        <p class="mb-1">
                            <i class="fas fa-calendar-alt me-2 text-muted"></i>
                            Member since: <?php echo date('d M Y', strtotime($user['created_at'])); ?>
                        </p>
                        <p class="mb-1">
                            <i class="fas fa-clock me-2 text-muted"></i>
                            Last login: <?php echo $user['last_login'] ? time_ago($user['last_login']) : 'Never'; ?>
                        </p>
                        <p class="mb-1">
                            <i class="fas fa-sign-in-alt me-2 text-muted"></i>
                            Login count: <?php echo number_format($user['login_count'] ?? 0); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Profile Column -->
        <div class="col-lg-8">
            <!-- Edit Profile Form -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-edit me-2"></i>Edit Profile
                    </h6>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="username" 
                                       value="<?php echo htmlspecialchars($user['username']); ?>" readonly>
                                <small class="text-muted">Username cannot be changed</small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="full_name" name="full_name" 
                                       value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($user['email']); ?>" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="phone" class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" id="phone" name="phone" 
                                       value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="role" class="form-label">Role</label>
                            <input type="text" class="form-control" id="role" 
                                   value="<?php echo htmlspecialchars($user['role_name'] ?? $user['role_code'] ?? 'User'); ?>" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label for="company" class="form-label">Company</label>
                            <input type="text" class="form-control" id="company" 
                                   value="<?php echo htmlspecialchars($_SESSION['company_name'] ?? 'N/A'); ?>" readonly>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save Changes
                        </button>
                    </form>
                </div>
            </div>

            <!-- Change Password Form -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">
                        <i class="fas fa-key me-2"></i>Change Password
                    </h6>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Current Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="current_password" name="current_password" required>
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('current_password')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="new_password" class="form-label">New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="new_password" name="new_password" required>
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('new_password')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="password-strength mt-2" id="passwordStrength"></div>
                            <small class="text-muted">Minimum 8 characters, include uppercase, lowercase, and numbers</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_password')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="invalid-feedback" id="passwordMatch"></div>
                        </div>
                        
                        <button type="submit" class="btn btn-warning" onclick="return validatePassword()">
                            <i class="fas fa-key me-2"></i>Change Password
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

   
</div>

<!-- Avatar Upload Modal -->
<div class="modal fade" id="avatarModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-camera me-2"></i>Update Profile Picture
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo CSRF::generate(); ?>">
                <input type="hidden" name="action" value="update_avatar">
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="avatar" class="form-label">Choose Image</label>
                        <input type="file" class="form-control" id="avatar" name="avatar" accept="image/*" required>
                        <small class="text-muted">Max size: 2MB. Allowed formats: JPG, PNG, GIF, WebP</small>
                    </div>
                    
                    <!-- Image Preview -->
                    <div class="text-center mt-3">
                        <img id="avatarPreview" src="#" alt="Preview" style="max-width: 200px; max-height: 200px; display: none;" class="img-thumbnail">
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-upload me-2"></i>Upload
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .password-strength {
        height: 5px;
        border-radius: 3px;
        transition: all 0.3s;
        background-color: #e9ecef;
    }
    
    .strength-0 { width: 0%; background: #dc3545; }
    .strength-1 { width: 25%; background: #dc3545; }
    .strength-2 { width: 50%; background: #ffc107; }
    .strength-3 { width: 75%; background: #17a2b8; }
    .strength-4 { width: 100%; background: #28a745; }
    
    .timeline {
        position: relative;
        padding-left: 30px;
    }
    
    .timeline-item {
        position: relative;
        padding-bottom: 20px;
    }
    
    .timeline-item:last-child {
        padding-bottom: 0;
    }
    
    .timeline-badge {
        position: absolute;
        left: -30px;
        top: 0;
        width: 20px;
        text-align: center;
    }
    
    .timeline-badge i {
        font-size: 12px;
    }
    
    .timeline-content {
        padding-left: 15px;
        border-left: 2px solid #e3e6f0;
    }
    
    .timeline-item:last-child .timeline-content {
        border-left-color: transparent;
    }
</style>

<script>
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const type = field.getAttribute('type') === 'password' ? 'text' : 'password';
    field.setAttribute('type', type);
}

// Password strength checker
document.getElementById('new_password')?.addEventListener('input', function() {
    const password = this.value;
    const strengthBar = document.getElementById('passwordStrength');
    
    let strength = 0;
    
    if (password.length >= 8) strength++;
    if (password.match(/[a-z]/)) strength++;
    if (password.match(/[A-Z]/)) strength++;
    if (password.match(/[0-9]/)) strength++;
    if (password.match(/[^a-zA-Z0-9]/)) strength++;
    
    strengthBar.className = 'password-strength strength-' + strength;
});

// Password match checker
document.getElementById('confirm_password')?.addEventListener('input', function() {
    const password = document.getElementById('new_password').value;
    const confirm = this.value;
    const matchDiv = document.getElementById('passwordMatch');
    
    if (confirm === '') {
        matchDiv.style.display = 'none';
    } else if (password === confirm) {
        matchDiv.style.display = 'block';
        matchDiv.innerHTML = '✓ Passwords match';
        matchDiv.className = 'valid-feedback';
    } else {
        matchDiv.style.display = 'block';
        matchDiv.innerHTML = '✗ Passwords do not match';
        matchDiv.className = 'invalid-feedback';
    }
});

function validatePassword() {
    const password = document.getElementById('new_password').value;
    const confirm = document.getElementById('confirm_password').value;
    
    if (password.length < 8) {
        alert('Password must be at least 8 characters long.');
        return false;
    }
    
    if (password !== confirm) {
        alert('Passwords do not match.');
        return false;
    }
    
    return true;
}

// Image preview
document.getElementById('avatar')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById('avatarPreview');
            preview.src = e.target.result;
            preview.style.display = 'block';
        }
        reader.readAsDataURL(file);
    }
});
</script>

