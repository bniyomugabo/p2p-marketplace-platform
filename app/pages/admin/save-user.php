<?php
// pages/admin/save-user.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../models/UserRole.php';



// Check authentication and permission
if (!isset($_SESSION['user_id'])) {
    SessionManager::flash('error', 'Authentication required.');
    header('Location: ' . route_url('login'));
    exit;
}

$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Only Admins can create users
if ($userRole !== 'ADM') {
    SessionManager::flash('error', 'You do not have permission to create users.');
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

// Initialize models with company context
$userModel = new User($companyId);
$roleModel = new UserRole($companyId);

try {
    // Validate required fields
    if (empty($_POST['username'])) {
        throw new Exception('Username is required.');
    }

    if (empty($_POST['full_name'])) {
        throw new Exception('Full name is required.');
    }

    if (empty($_POST['email'])) {
        throw new Exception('Email is required.');
    }

    if (empty($_POST['role_id'])) {
        throw new Exception('Please select a role for the user.');
    }

    if (empty($_POST['password'])) {
        throw new Exception('Password is required.');
    }

    // Validate password
    $password = $_POST['password'];
    if (strlen($password) < 8) {
        throw new Exception('Password must be at least 8 characters long.');
    }

    // Check password strength
    if (!preg_match('/[A-Z]/', $password)) {
        throw new Exception('Password must contain at least one uppercase letter.');
    }
    if (!preg_match('/[a-z]/', $password)) {
        throw new Exception('Password must contain at least one lowercase letter.');
    }
    if (!preg_match('/[0-9]/', $password)) {
        throw new Exception('Password must contain at least one number.');
    }

    if ($password !== ($_POST['confirm_password'] ?? '')) {
        throw new Exception('Passwords do not match.');
    }

    // Validate email format
    if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Please provide a valid email address.');
    }

    // Validate phone if provided
    if (!empty($_POST['phone']) && !preg_match('/^[0-9+\-\s()]+$/', $_POST['phone'])) {
        throw new Exception('Please provide a valid phone number.');
    }

    // Check if username already exists in this company
    $existingUser = $userModel->findByUsername($_POST['username']);
    if ($existingUser) {
        throw new Exception('Username "' . htmlspecialchars($_POST['username']) . '" already exists in your company.');
    }

    // Check if email already exists in this company
    $existingEmail = $userModel->findByEmail($_POST['email']);
    if ($existingEmail) {
        throw new Exception('Email "' . htmlspecialchars($_POST['email']) . '" is already registered.');
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

    // Check company user limit (optional)
    $stats = $userModel->getStats();
    $company = new Company();
    $companyInfo = $company->find($companyId);
    $maxUsers = $companyInfo['max_users'] ?? 5;

    if (($stats['total_users'] ?? 0) >= $maxUsers) {
        throw new Exception("User limit reached. Your company can have maximum {$maxUsers} users.");
    }

    // Prepare user data
    $data = [
        'company_id' => $companyId,
        'username' => trim($_POST['username']),
        'full_name' => trim($_POST['full_name']),
        'email' => trim($_POST['email']),
        'phone' => !empty($_POST['phone']) ? trim($_POST['phone']) : null,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role_id' => (int) $_POST['role_id'],
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'is_deleted' => 0,
        'created_at' => date('Y-m-d H:i:s')
    ];

    // Create the user
    $newUserId = $userModel->create($data);

    if (!$newUserId) {
        throw new Exception('Failed to create user. Please try again.');
    }

    // Add to company_users table
    $sql = "
        INSERT INTO company_users (company_id, user_id, role_id, status, joined_at)
        VALUES (:company_id, :user_id, :role_id, 'active', NOW())
    ";
    $stmt = $userModel->getConnection()->prepare($sql);
    $stmt->execute([
        'company_id' => $companyId,
        'user_id' => $newUserId,
        'role_id' => (int) $_POST['role_id']
    ]);

    // Log the activity
    $activitySql = "
        INSERT INTO activity_log (company_id, user_id, action, entity_type, entity_id, new_data, created_at)
        VALUES (:company_id, :user_id, 'user_created', 'user', :new_user_id, :new_data, NOW())
    ";
    $activityStmt = $userModel->getConnection()->prepare($activitySql);
    $activityStmt->execute([
        'company_id' => $companyId,
        'user_id' => $userId,
        'new_user_id' => $newUserId,
        'new_data' => json_encode(['username' => $_POST['username'], 'email' => $_POST['email']])
    ]);

    // Send welcome email (optional)
    sendWelcomeEmail($_POST['email'], $_POST['full_name'], $_POST['username'], $password);

    SessionManager::flash('success', 'User "' . htmlspecialchars($_POST['full_name']) . '" created successfully!');

} catch (Exception $e) {
    error_log("Save user error for company {$companyId}: " . $e->getMessage());
    SessionManager::flash('error', 'Failed to create user: ' . $e->getMessage());
}

header('Location: ' . route_url('admin/users'));
exit;

/**
 * Send welcome email to new user
 */
function sendWelcomeEmail($email, $fullName, $username, $password)
{
    try {
        $subject = "Welcome to SATI ERP - Your Account Has Been Created";

        $loginUrl = (defined('BASE_URL') ? BASE_URL : '') . "/auth/signin.php";

        $message = "
        <html>
        <head>
            <title>Welcome to SATI ERP</title>
            <style>
                body { font-family: Arial, sans-serif; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #4e73df; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background-color: #f8f9fc; }
                .credentials { background-color: #fff; padding: 15px; border-left: 4px solid #4e73df; margin: 15px 0; }
                .footer { text-align: center; padding: 15px; font-size: 12px; color: #666; }
                .btn { background-color: #4e73df; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>Welcome to SATI ERP</h2>
                </div>
                <div class='content'>
                    <p>Dear <strong>" . htmlspecialchars($fullName) . "</strong>,</p>
                    <p>Your account has been created successfully. You can now log in to the SATI ERP system using the credentials below:</p>
                    
                    <div class='credentials'>
                        <p><strong>Username:</strong> " . htmlspecialchars($username) . "</p>
                        <p><strong>Password:</strong> " . htmlspecialchars($password) . "</p>
                        <p><strong>Login URL:</strong> <a href='{$loginUrl}'>{$loginUrl}</a></p>
                    </div>
                    
                    <p><strong>Important:</strong> Please change your password after your first login for security purposes.</p>
                    
                    <div style='text-align: center; margin: 30px 0;'>
                        <a href='{$loginUrl}' class='btn'>Login to Your Account</a>
                    </div>
                    
                    <p>If you have any questions or need assistance, please contact your system administrator.</p>
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
        error_log("Failed to send welcome email: " . $e->getMessage());
        // Don't throw - email failure shouldn't stop user creation
    }
}