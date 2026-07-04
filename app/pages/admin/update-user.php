<?php
// pages/admin/update-user.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../models/UserRole.php';



// Check authentication
if (!isset($_SESSION['user_id'])) {
    SessionManager::flash('error', 'Authentication required.');
    header('Location: ' . route_url('login'));
    exit;
}

$userRole = $_SESSION['user_role'] ?? 'VIW';
$currentUserId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Only Admins can update users
if ($userRole !== 'ADM') {
    SessionManager::flash('error', 'You do not have permission to update users.');
    header('Location: ' . route_url('admin/users'));
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
    header('Location: ' . route_url('admin/users'));
    exit;
}

// Validate CSRF token
if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
    SessionManager::flash('error', 'Invalid security token. Please try again.');
    header('Location: ' . route_url('admin/users'));
    exit;
}

// Validate user ID
if (empty($_POST['id'])) {
    SessionManager::flash('error', 'User ID is required.');
    header('Location: ' . route_url('admin/users'));
    exit;
}

$userId = (int) $_POST['id'];

try {
    // Initialize models with company context
    $userModel = new User($companyId);
    $roleModel = new UserRole($companyId);

    // Get the user to update (with company verification)
    $user = $userModel->find($userId);

    if (!$user) {
        throw new Exception('User not found or does not belong to your company.');
    }

    // Validate required fields
    if (empty($_POST['full_name'])) {
        throw new Exception('Full name is required.');
    }

    if (empty($_POST['email'])) {
        throw new Exception('Email is required.');
    }

    if (empty($_POST['role_id'])) {
        throw new Exception('Please select a role for the user.');
    }

    // Validate email format
    if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Please provide a valid email address.');
    }

    // Validate phone if provided
    if (!empty($_POST['phone']) && !preg_match('/^[0-9+\-\s()]+$/', $_POST['phone'])) {
        throw new Exception('Please provide a valid phone number.');
    }

    // Check if email already exists (excluding current user)
    if ($user['email'] !== $_POST['email']) {
        $existingUser = $userModel->findByEmail($_POST['email']);
        if ($existingUser && $existingUser['id'] !== $userId) {
            throw new Exception('Email "' . htmlspecialchars($_POST['email']) . '" is already registered.');
        }
    }

    // Verify that the selected role belongs to this company (or is system role)
    $selectedRole = $roleModel->find((int) $_POST['role_id']);
    if (!$selectedRole) {
        throw new Exception('Selected role does not exist.');
    }

    // Check if role belongs to this company (if it's not a system role)
    if ($selectedRole['company_id'] !== null && $selectedRole['company_id'] != $companyId) {
        throw new Exception('Selected role does not belong to your company.');
    }

    // Prevent self-deactivation or role change that could lock out admin
    if ($userId === $currentUserId) {
        // Can't deactivate own account
        if (isset($_POST['is_active']) && $_POST['is_active'] == 0) {
            throw new Exception('You cannot deactivate your own account.');
        }

        // Can't change own role if it would remove admin privileges
        $currentRole = $roleModel->find($user['role_id']);
        $newRole = $roleModel->find((int) $_POST['role_id']);

        if ($currentRole && $currentRole['role_code'] === 'ADM' && $newRole && $newRole['role_code'] !== 'ADM') {
            throw new Exception('You cannot change your own role from Administrator. Another admin must do this.');
        }
    }

    // Prepare update data
    $updateData = [
        'full_name' => trim($_POST['full_name']),
        'email' => trim($_POST['email']),
        'phone' => !empty($_POST['phone']) ? trim($_POST['phone']) : null,
        'role_id' => (int) $_POST['role_id'],
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'updated_at' => date('Y-m-d H:i:s')
    ];

    // Start transaction
    $db = Database::getInstance();
    $db->beginTransaction();

    // Update user
    $result = $userModel->update($userId, $updateData);

    if (!$result) {
        throw new Exception('Failed to update user.');
    }

    // Update password if provided
    if (!empty($_POST['password'])) {
        $password = $_POST['password'];
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // Validate password strength
        if (strlen($password) < 8) {
            throw new Exception('Password must be at least 8 characters long.');
        }

        if (!preg_match('/[A-Z]/', $password)) {
            throw new Exception('Password must contain at least one uppercase letter.');
        }
        if (!preg_match('/[a-z]/', $password)) {
            throw new Exception('Password must contain at least one lowercase letter.');
        }
        if (!preg_match('/[0-9]/', $password)) {
            throw new Exception('Password must contain at least one number.');
        }

        if ($password !== $confirmPassword) {
            throw new Exception('Passwords do not match.');
        }

        $userModel->updatePassword($userId, $password);
    }

    // Update company_users role if needed
    $sql = "UPDATE company_users SET role_id = :role_id, updated_at = NOW() 
            WHERE user_id = :user_id AND company_id = :company_id";
    $stmt = $db->prepare($sql);
    $stmt->execute([
        'role_id' => (int) $_POST['role_id'],
        'user_id' => $userId,
        'company_id' => $companyId
    ]);

    // Log the activity
    $activitySql = "
        INSERT INTO activity_log (company_id, user_id, action, entity_type, entity_id, old_data, new_data, created_at)
        VALUES (:company_id, :p_1_user_id, 'user_updated', 'user', :p_2_user_id, :old_data, :new_data, NOW())
    ";

    $oldData = json_encode([
        'full_name' => $user['full_name'],
        'email' => $user['email'],
        'role_id' => $user['role_id'],
        'is_active' => $user['is_active']
    ]);

    $newData = json_encode([
        'full_name' => $updateData['full_name'],
        'email' => $updateData['email'],
        'role_id' => $updateData['role_id'],
        'is_active' => $updateData['is_active']
    ]);

    $activityStmt = $db->prepare($activitySql);
    $activityStmt->execute([
        'company_id' => $companyId,
        'p_1_user_id' => $currentUserId,
        'p_2_user_id' => $userId,
        'old_data' => $oldData,
        'new_data' => $newData
    ]);

    $db->commit();

    // Send notification email if password was changed
    if (!empty($_POST['password'])) {
        sendPasswordChangedEmail($user['email'], $user['full_name']);
    }

    SessionManager::flash('success', 'User "' . htmlspecialchars($updateData['full_name']) . '" updated successfully!');

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Update user error for company {$companyId}, user {$userId}: " . $e->getMessage());
    SessionManager::flash('error', 'Failed to update user: ' . $e->getMessage());
}

header('Location: ' . route_url('admin/users'));
exit;

/**
 * Send notification email when password is changed
 */
function sendPasswordChangedEmail($email, $fullName)
{
    try {
        $subject = "Your SATI ERP Password Has Been Changed";

        $loginUrl = (defined('BASE_URL') ? BASE_URL : '') . "/auth/signin.php";

        $message = "
        <html>
        <head>
            <title>Password Changed Notification</title>
            <style>
                body { font-family: Arial, sans-serif; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #f39c12; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background-color: #f8f9fc; }
                .footer { text-align: center; padding: 15px; font-size: 12px; color: #666; }
                .btn { background-color: #f39c12; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>Password Changed</h2>
                </div>
                <div class='content'>
                    <p>Dear <strong>" . htmlspecialchars($fullName) . "</strong>,</p>
                    <p>Your SATI ERP account password has been changed by an administrator.</p>
                    
                    <p>If you did not request this change, please contact your system administrator immediately.</p>
                    
                    <div style='text-align: center; margin: 30px 0;'>
                        <a href='{$loginUrl}' class='btn'>Login to Your Account</a>
                    </div>
                    
                    <p>Thank you for using SATI ERP!</p>
                </div>
                <div class='footer'>
                    <p>&copy; " . date('Y') . " SATI ERP. All rights reserved.</p>
                    <p>This is an automated message, please do not reply.</p>
                </div>
            </div>
        </body>
        </html>
        ";

        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8\r\n";
        $headers .= "From: noreply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "\r\n";

        // Uncomment to actually send email
        // mail($email, $subject, $message, $headers);

    } catch (Exception $e) {
        error_log("Failed to send password change email: " . $e->getMessage());
    }
}