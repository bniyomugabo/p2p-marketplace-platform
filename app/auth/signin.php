<?php
// pages/auth/signin.php
declare(strict_types=1);

// Start session if not already started - BUT let SessionManager handle it
if (session_status() === PHP_SESSION_NONE) {
    session_name('sati');
    session_start();
}

// Define BASE_URL if not defined - BUT only do it once
if (!defined('BASE_URL')) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    // Remove /pages/auth from the path to get base
    $basePath = str_replace('/pages/auth', '', $scriptDir);
    define('BASE_URL', $protocol . '://' . $host . $basePath);
}

// IMPORTANT: Check if user is already logged in
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    // User is logged in, redirect to dashboard
    header('Location: ' . BASE_URL . '/../index.php?page=dashboard');
    exit;
}

$pageTitle = 'Sign In - Inventory Management System';
require_once __DIR__ . '/../config/autoload.php'; // This will now include SessionManager

$db = Database::getInstance();
$error = '';


// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    try {
        // Validate inputs
        if (empty($username) || empty($password)) {
            throw new Exception('Please enter both username and password.');
        }

        // Check if user exists (by username or email) - NOW INCLUDING COMPANY_ID
        $stmt = $db->prepare("
            SELECT u.id, u.username, u.full_name, u.email, u.password_hash, 
                   u.is_active, u.role_id, u.company_id,
                   c.company_name, c.currency
            FROM users u
            LEFT JOIN companies c ON u.company_id = c.id
            WHERE (u.username = ? OR u.email = ?) AND u.is_deleted = 0
            LIMIT 1
        ");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            // Log failed attempt
            logLoginAttempt($username, 0, 'User not found');
            throw new Exception('Invalid username or password.');
        }

        // Check if user is active
        if (!$user['is_active']) {
            logLoginAttempt($username, 0, 'Account deactivated');
            throw new Exception('Your account is deactivated. Please contact administrator.');
        }

        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            logLoginAttempt($username, 0, 'Invalid password');
            throw new Exception('Invalid username or password.');
        }

        // Get user role
        $stmt = $db->prepare("SELECT role_code, role_name FROM user_roles WHERE id = ?");
        $stmt->execute([$user['role_id']]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);

        // Regenerate session ID to prevent session fixation
        session_regenerate_id(true);

        // Set session variables - INCLUDING COMPANY_ID
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['user_role'] = $role['role_code'] ?? 'VIW';
        $_SESSION['role_name'] = $role['role_name'] ?? 'Viewer';
        $_SESSION['role_id'] = (int) $user['role_id'];
        $_SESSION['company_id'] = $user['company_id'] ? (int) $user['company_id'] : 1; // Default to company 1
        $_SESSION['company_name'] = $user['company_name'] ?? 'Default Company';
        $_SESSION['company_currency'] = $user['currency'] ?? 'RWF';
        $_SESSION['is_authenticated'] = true;
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();

        // Generate CSRF token
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_tokens'] = [];

        // Update last login
        $stmt = $db->prepare("
            UPDATE users 
            SET last_login = NOW(), 
                login_count = COALESCE(login_count, 0) + 1 
            WHERE id = ?
        ");
        $stmt->execute([$user['id']]);

        // Log successful login
        logLoginAttempt($username, 1, 'Successful login', $user['id']);

        // Remember me functionality
        if ($remember) {
            $token = bin2hex(random_bytes(32));
            $series = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+30 days'));

            // Check if remember_tokens table exists
            $checkTable = $db->query("SHOW TABLES LIKE 'remember_tokens'");
            if ($checkTable->rowCount() > 0) {
                $stmt = $db->prepare("
                    INSERT INTO remember_tokens (user_id, token, series, expires_at, ip_address, user_agent, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $user['id'],
                    password_hash($token, PASSWORD_DEFAULT),
                    $series,
                    $expires,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);

                setcookie('remember', $series . ':' . $token, [
                    'expires' => strtotime('+30 days'),
                    'path' => '/',
                    'domain' => '',
                    'secure' => isset($_SERVER['HTTPS']),
                    'httponly' => true,
                    'samesite' => 'Strict'
                ]);
            }
        }

        // Check if 2FA is enabled
        if (isset($user['two_factor_enabled']) && $user['two_factor_enabled']) {
            $_SESSION['2fa_required'] = true;
            $redirect = '2fa-verify.php';
        } else {
            // Determine redirect URL
            $redirect = $_SESSION['redirect_after_login'] ?? '../index.php?page=dashboard';
        }

        // Clear redirect after login
        unset($_SESSION['redirect_after_login']);

        // Redirect
        header('Location: ' . BASE_URL . '/../index.php?page=dashboard');
        exit;

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Check for remember token
if (empty($_SESSION['user_id']) && isset($_COOKIE['remember'])) {
    try {
        list($series, $token) = explode(':', $_COOKIE['remember'], 2);

        // Check if remember_tokens table exists
        $checkTable = $db->query("SHOW TABLES LIKE 'remember_tokens'");
        if ($checkTable->rowCount() > 0) {
            $stmt = $db->prepare("
                SELECT rt.user_id, rt.token, rt.series, u.username, u.full_name, u.email, 
                       u.is_active, u.role_id, u.company_id, ur.role_code,
                       c.company_name, c.currency
                FROM remember_tokens rt
                JOIN users u ON rt.user_id = u.id
                LEFT JOIN user_roles ur ON u.role_id = ur.id
                LEFT JOIN companies c ON u.company_id = c.id
                WHERE rt.series = ? AND rt.expires_at > NOW() AND u.is_deleted = 0
                LIMIT 1
            ");
            $stmt->execute([$series]);
            $rememberData = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($rememberData && password_verify($token, $rememberData['token']) && $rememberData['is_active']) {
                session_regenerate_id(true);

                $_SESSION['user_id'] = (int) $rememberData['user_id'];
                $_SESSION['username'] = $rememberData['username'];
                $_SESSION['full_name'] = $rememberData['full_name'];
                $_SESSION['email'] = $rememberData['email'];
                $_SESSION['user_role'] = $rememberData['role_code'] ?? 'VIW';
                $_SESSION['role_id'] = (int) $rememberData['role_id'];
                $_SESSION['company_id'] = $rememberData['company_id'] ? (int) $rememberData['company_id'] : 1;
                $_SESSION['company_name'] = $rememberData['company_name'] ?? 'Default Company';
                $_SESSION['company_currency'] = $rememberData['currency'] ?? 'RWF';
                $_SESSION['is_authenticated'] = true;
                $_SESSION['login_time'] = time();
                $_SESSION['last_activity'] = time();
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $_SESSION['csrf_tokens'] = [];

                // Update last login
                $stmt = $db->prepare("
                    UPDATE users 
                    SET last_login = NOW(), 
                        login_count = COALESCE(login_count, 0) + 1 
                    WHERE id = ?
                ");
                $stmt->execute([$rememberData['user_id']]);

                header('Location: ../index.php?page=dashboard');
                exit;
            }
        }
    } catch (Exception $e) {
        // Invalid token, clear cookie
        setcookie('remember', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
    }
}

/**
 * Log login attempt
 */
function logLoginAttempt($username, $success, $reason = '', $userId = null)
{
    $db = Database::getInstance();

    // Check if login_attempts table exists
    $checkTable = $db->query("SHOW TABLES LIKE 'login_attempts'");
    if ($checkTable->rowCount() == 0) {
        return; // Table doesn't exist, skip logging
    }

    $stmt = $db->prepare("
        INSERT INTO login_attempts (user_id, username, success, reason, ip_address, user_agent, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $userId,
        $username,
        $success ? 1 : 0,
        $reason,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ]);
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }

        .login-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            max-width: 450px;
            width: 100%;
        }

        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .login-body {
            padding: 40px;
        }

        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 12px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }

        .logo {
            width: 80px;
            height: 80px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 32px;
            color: #667eea;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            z-index: 10;
        }

        .password-toggle:hover {
            color: #667eea;
        }

        .input-group {
            position: relative;
        }

        .form-control-lg {
            padding-right: 45px;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="login-card">
                    <!-- Header -->
                    <div class="login-header">
                        <div class="logo">
                            <i class="fas fa-warehouse"></i>
                        </div>
                        <h3>SATI ERP</h3>
                        <p class="mb-0">Sign in to your account</p>
                    </div>

                    <!-- Body -->
                    <div class="login-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($_GET['registered']) && $_GET['registered'] == 'success'): ?>
                            <div class="alert alert-success alert-dismissible fade show">
                                <i class="fas fa-check-circle me-2"></i>Registration successful! Please sign in.
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if (isset($_GET['expired']) && $_GET['expired'] == 1): ?>
                            <div class="alert alert-warning alert-dismissible fade show">
                                <i class="fas fa-clock me-2"></i>Your session has expired. Please sign in again.
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="" id="login-form">
                            <div class="mb-4">
                                <label for="username" class="form-label">
                                    <i class="fas fa-user me-2"></i>Username or Email
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-user text-muted"></i>
                                    </span>
                                    <input type="text" class="form-control form-control-lg bg-light border-start-0"
                                        id="username" name="username"
                                        value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required
                                        autocomplete="username" placeholder="Enter your username or email">
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="password" class="form-label">
                                    <i class="fas fa-lock me-2"></i>Password
                                </label>
                                <div class="input-group position-relative">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-lock text-muted"></i>
                                    </span>
                                    <input type="password" class="form-control form-control-lg bg-light border-start-0"
                                        id="password" name="password" required autocomplete="current-password"
                                        placeholder="Enter your password">
                                    <button type="button" class="password-toggle" id="toggle-password" tabindex="-1">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <div class="password-strength mt-2" id="password-strength" style="display: none;"></div>
                            </div>

                            <div class="mb-4 d-flex justify-content-between align-items-center">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="remember" name="remember">
                                    <label class="form-check-label" for="remember">
                                        Remember me
                                    </label>
                                </div>
                                <a href="forgot-password.php" class="text-decoration-none small">
                                    Forgot Password?
                                </a>
                            </div>

                            <div class="d-grid gap-2 mb-4">
                                <button type="submit" class="btn btn-login btn-lg" id="submit-btn">
                                    <i class="fas fa-sign-in-alt me-2"></i>Sign In
                                </button>
                            </div>

                            <div class="text-center">
                                <p class="mb-0 text-muted">
                                    Don't have an account?
                                    <a href="register.php" class="text-decoration-none fw-bold">
                                        Register here
                                    </a>
                                </p>
                            </div>

                            <hr class="my-4">

                            <div class="text-center">
                                <small class="text-muted">
                                    <i class="fas fa-shield-alt me-1"></i>
                                    Secure login with 256-bit encryption
                                </small>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Toggle password visibility
            const toggleBtn = document.getElementById('toggle-password');
            const passwordInput = document.getElementById('password');

            if (toggleBtn && passwordInput) {
                toggleBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordInput.setAttribute('type', type);

                    // Toggle icon
                    const icon = this.querySelector('i');
                    if (type === 'password') {
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                    } else {
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                    }
                });
            }

            // Form validation and submission handling
            const form = document.getElementById('login-form');
            const submitBtn = document.getElementById('submit-btn');
            const usernameInput = document.getElementById('username');

            if (form && submitBtn) {
                form.addEventListener('submit', function (e) {
                    const username = usernameInput.value.trim();
                    const password = passwordInput.value;

                    if (!username || !password) {
                        e.preventDefault();
                        showNotification('Please fill in all required fields.', 'danger');
                        return;
                    }

                    // Disable button and show loading
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Signing in...';
                });
            }

            // Auto-focus username field
            if (usernameInput) {
                usernameInput.focus();
            }

            // Clear error on input
            const inputs = document.querySelectorAll('input');
            inputs.forEach(input => {
                input.addEventListener('input', function () {
                    const errorAlert = document.querySelector('.alert-danger');
                    if (errorAlert) {
                        errorAlert.remove();
                    }
                });
            });

            // Enter key submits form
            if (form) {
                form.addEventListener('keypress', function (e) {
                    if (e.key === 'Enter' && !submitBtn.disabled) {
                        e.preventDefault();
                        form.submit();
                    }
                });
            }

            // Notification function
            function showNotification(message, type = 'info') {
                const alertDiv = document.createElement('div');
                alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
                alertDiv.innerHTML = `
                <i class="fas fa-${type === 'danger' ? 'exclamation-circle' : 'info-circle'} me-2"></i>
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;

                const existingAlert = document.querySelector('.alert');
                if (existingAlert) {
                    existingAlert.remove();
                }

                const loginBody = document.querySelector('.login-body');
                loginBody.insertBefore(alertDiv, loginBody.firstChild);

                // Auto-remove after 5 seconds
                setTimeout(() => {
                    if (alertDiv.parentNode) {
                        alertDiv.remove();
                    }
                }, 5000);
            }
        });
    </script>
</body>

</html>