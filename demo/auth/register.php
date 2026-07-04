<?php
// pages/auth/register.php
// ============================================
// COMPANY REGISTRATION WITH COMPLETE DEFAULT DATA
// ============================================
declare(strict_types=1);

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

ini_set('error_log', __DIR__ . '/../logs/error.log');

if (session_status() === PHP_SESSION_NONE) {
    session_name('demo');
    session_start();
}

$pageTitle = 'Register Company - SATI ERP';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/autoload.php';
require_once __DIR__ . '/../config/helpers.php';

// Define BASE_URL if not defined
if (!defined('BASE_URL')) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    $basePath = str_replace('/pages/auth', '', $scriptDir);
    define('BASE_URL', $protocol . '://' . $host . $basePath);
}

$db = Database::getInstance();
$errors = [];
$success = false;

// Get countries list
$countries = [
    'Rwanda', 'Uganda', 'Kenya', 'Tanzania', 'Burundi',
    'South Africa', 'Nigeria', 'Ghana', 'Ethiopia', 'Egypt',
    'Morocco', 'Kenya', 'Other'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
        $errors[] = 'Invalid security token. Please try again.';
        error_log("CSRF token validation failed");
    } else {
        // Get form data
        $company_name = trim($_POST['company_name'] ?? '');
        $company_email = trim($_POST['company_email'] ?? '');
        $company_phone = trim($_POST['company_phone'] ?? '');
        $company_address = trim($_POST['company_address'] ?? '');
        $company_city = trim($_POST['company_city'] ?? '');
        $company_country = trim($_POST['company_country'] ?? 'Rwanda');
        
        $admin_full_name = trim($_POST['admin_full_name'] ?? '');
        $admin_email = trim($_POST['admin_email'] ?? '');
        $admin_phone = trim($_POST['admin_phone'] ?? '');
        $admin_position = trim($_POST['admin_position'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $terms = isset($_POST['terms']);

        // ============================================
        // VALIDATION
        // ============================================
        
        // Company validation
        if (empty($company_name)) {
            $errors[] = "Company name is required";
        } elseif (strlen($company_name) < 2) {
            $errors[] = "Company name must be at least 2 characters";
        }

        if (empty($company_email)) {
            $errors[] = "Company email is required";
        } elseif (!filter_var($company_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Valid company email is required";
        }

        // Admin validation
        if (empty($admin_full_name)) {
            $errors[] = "Administrator full name is required";
        } elseif (strlen($admin_full_name) < 3) {
            $errors[] = "Full name must be at least 3 characters";
        }

        if (empty($admin_email)) {
            $errors[] = "Administrator email is required";
        } elseif (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Valid administrator email is required";
        }

        if (empty($admin_phone)) {
            $errors[] = "Administrator phone number is required";
        }

        if (empty($username)) {
            $errors[] = "Username is required";
        } elseif (strlen($username) < 3) {
            $errors[] = "Username must be at least 3 characters";
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $errors[] = "Username can only contain letters, numbers, and underscores";
        }

        // Password validation
        if (empty($password)) {
            $errors[] = "Password is required";
        } elseif (strlen($password) < 8) {
            $errors[] = "Password must be at least 8 characters";
        } else {
            if (!preg_match('/[A-Z]/', $password)) {
                $errors[] = "Password must contain at least one uppercase letter";
            }
            if (!preg_match('/[a-z]/', $password)) {
                $errors[] = "Password must contain at least one lowercase letter";
            }
            if (!preg_match('/[0-9]/', $password)) {
                $errors[] = "Password must contain at least one number";
            }
        }

        if ($password !== $confirm_password) {
            $errors[] = "Passwords do not match";
        }

        if (!$terms) {
            $errors[] = "You must agree to the terms and conditions";
        }

        // Check if username or email already exists
        if (empty($errors)) {
            $stmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $admin_email]);
            if ($stmt->rowCount() > 0) {
                $errors[] = "Username or email already exists. Please try different credentials.";
            }
        }

        // Check if company email already registered
        if (empty($errors)) {
            $stmt = $db->prepare("SELECT id FROM companies WHERE email = ?");
            $stmt->execute([$company_email]);
            if ($stmt->rowCount() > 0) {
                $errors[] = "A company with this email is already registered.";
            }
        }

        // ============================================
        // REGISTRATION PROCESS
        // ============================================
        
        if (empty($errors)) {
            try {
                $db->beginTransaction();

                // Generate unique company code
                $company_code = 'CMP' . date('y') . str_pad((string)rand(0, 9999), 4, '0', STR_PAD_LEFT);

                // Ensure company code is unique
                $stmt = $db->prepare("SELECT id FROM companies WHERE company_code = ?");
                while (true) {
                    $stmt->execute([$company_code]);
                    if ($stmt->rowCount() === 0) break;
                    $company_code = 'CMP' . date('y') . str_pad((string)rand(0, 9999), 4, '0', STR_PAD_LEFT);
                }

                // Insert company
                $stmt = $db->prepare("
                    INSERT INTO companies (
                        company_code, company_name, email, phone, address, city, country,
                        currency, is_active, is_default, subscription_plan, 
                        max_users, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?,
                        'RWF', 1, 0, 'trial', 
                        5, NOW()
                    )
                ");
                $stmt->execute([
                    $company_code,
                    $company_name,
                    $company_email,
                    $company_phone,
                    $company_address,
                    $company_city,
                    $company_country
                ]);

                $company_id = $db->lastInsertId();

                // Hash password
                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                // Insert admin user (role_id 1 = Administrator)
                $stmt = $db->prepare("
                    INSERT INTO users (
                        company_id, username, full_name, email, phone, 
                        password_hash, role_id, is_active, is_deleted, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, 1, 1, 0, NOW())
                ");
                $stmt->execute([
                    $company_id,
                    $username,
                    $admin_full_name,
                    $admin_email,
                    $admin_phone,
                    $password_hash
                ]);

                $user_id = $db->lastInsertId();

                // Add to company_users
                $stmt = $db->prepare("
                    INSERT INTO company_users (company_id, user_id, role_id, status, joined_at)
                    VALUES (?, ?, 1, 'active', NOW())
                ");
                $stmt->execute([$company_id, $user_id]);

                // ============================================
                // 1. DEFAULT CATEGORY (GEN)
                // ============================================
                // Insert general category
                $stmt = $db->prepare("
                    INSERT IGNORE INTO categories (category_code, category_name, created_at)
                    VALUES ('GEN', 'General', NOW())
                ");
                $stmt->execute();
                
                $genCatId = $db->lastInsertId();
                if (!$genCatId) {
                    $stmt = $db->prepare("SELECT id FROM categories WHERE category_code = 'GEN' LIMIT 1");
                    $stmt->execute();
                    $genCatId = $stmt->fetchColumn();
                }
                
                // Map to company
                $stmt = $db->prepare("
                    INSERT IGNORE INTO category_company (category_id, company_id, is_active, created_at)
                    VALUES (?, ?, 1, NOW())
                ");
                $stmt->execute([$genCatId, $company_id]);

                // ============================================
                // 2. DEFAULT WAREHOUSE
                // ============================================
                $warehouseCode = 'WH' . date('y') . '001';
                $stmt = $db->prepare("
                    INSERT INTO warehouses (company_id, warehouse_code, warehouse_name, address, city, is_main, is_active, created_at)
                    VALUES (?, ?, 'Main Warehouse', ?, ?, 1, 1, NOW())
                ");
                $stmt->execute([$company_id, $warehouseCode, $company_address, $company_city]);
                $warehouse_id = $db->lastInsertId();

                // ============================================
                // 3. DEFAULT STORAGE LOCATIONS
                // ============================================
                $locations = [
                    ['A-01', 'Aisle A - Shelf 1', 'shelf'],
                    ['B-01', 'Aisle B - Shelf 1', 'shelf'],
                    ['C-01', 'Aisle C - Shelf 1', 'shelf']
                ];
                $stmt = $db->prepare("
                    INSERT INTO locations (company_id, warehouse_id, location_code, location_name, location_type, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, 1, NOW())
                ");
                foreach ($locations as $location) {
                    $stmt->execute([$company_id, $warehouse_id, $location[0], $location[1], $location[2]]);
                }

                // ============================================
                // 4. DEFAULT SUPPLIER
                // ============================================
                $supplierCode = 'SUP' . date('y') . '001';
                $stmt = $db->prepare("
                    INSERT INTO suppliers (company_id, supplier_code, supplier_name, contact_person, is_active, created_at)
                    VALUES (?, ?, 'General Supplier', 'System Admin', 1, NOW())
                ");
                $stmt->execute([$company_id, $supplierCode]);

                // ============================================
                // 5. DEFAULT EXPENSE CATEGORIES
                // ============================================
                $expenseCategories = [
                    ['EXP001', 'Office Supplies', NULL],
                    ['EXP002', 'Utilities', NULL],
                    ['EXP003', 'Rent', NULL],
                    ['EXP004', 'Salaries', NULL],
                    ['EXP005', 'Transport', NULL]
                ];
                $stmt = $db->prepare("
                    INSERT INTO expense_categories (company_id, category_code, category_name, parent_id, is_active, created_at)
                    VALUES (?, ?, ?, ?, 1, NOW())
                ");
                foreach ($expenseCategories as $category) {
                    $stmt->execute([$company_id, $category[0], $category[1], $category[2]]);
                }

                // ============================================
                // 6. DEFAULT CUSTOMER (Walk-in)
                // ============================================
                $customerCode = 'CUST-' . date('Y') . '-0001';
                $stmt = $db->prepare("
                    INSERT INTO customers (company_id, customer_code, full_name, customer_type, is_active, created_by, created_at)
                    VALUES (?, ?, 'Walk-in Customer', 'individual', 1, ?, NOW())
                ");
                $stmt->execute([$company_id, $customerCode, $user_id]);

                // ============================================
                // 7. DEFAULT SETTINGS
                // ============================================
                $settings = [
                    ['date_format', 'd/m/Y', 'text'],
                    ['timezone', 'Africa/Kigali', 'text'],
                    ['currency', 'RWF', 'text'],
                    ['default_tax_rate', '18', 'number'],
                    ['invoice_prefix', 'INV', 'text'],
                    ['low_stock_alert_threshold', '10', 'number'],
                    ['lifo_fifo_method', 'LIFO', 'text'],
                    ['language', 'en', 'text'],
                    ['invoice_footer_text', 'Thank you for your business!', 'text'],
                    ['auto_invoice', '1', 'boolean'],
                    ['show_invoice_footer', '1', 'boolean'],
                    ['allow_negative_stock', '0', 'boolean'],
                    ['auto_reorder', '0', 'boolean']
                ];
                $stmt = $db->prepare("
                    INSERT IGNORE INTO company_settings (company_id, setting_key, setting_value, setting_type, created_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                foreach ($settings as $setting) {
                    $stmt->execute([$company_id, $setting[0], $setting[1], $setting[2]]);
                }

                // ============================================
                // 8. DEFAULT EMAIL TEMPLATES
                // ============================================
                $emailTemplates = [
                    ['invoice', 'Invoice Template', 'Invoice #{invoice_number}', '<p>Dear {customer_name}, Please find attached your invoice.</p>', 'customer_name, invoice_number'],
                    ['low_stock', 'Low Stock Alert', 'Low Stock Alert - {product_name}', '<p>The stock level for {product_name} is low.</p>', 'product_name, current_stock'],
                    ['welcome', 'Welcome Email', 'Welcome to {company_name}', '<p>Dear {user_name}, Welcome to {company_name} ERP system.</p>', 'user_name, company_name']
                ];
                $stmt = $db->prepare("
                    INSERT IGNORE INTO email_templates (company_id, template_code, template_name, subject, body, variables, is_active, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 1, NOW())
                ");
                foreach ($emailTemplates as $template) {
                    $stmt->execute([$company_id, $template[0], $template[1], $template[2], $template[3], $template[4]]);
                }

                $db->commit();

                // Auto-login

                $_SESSION['csrf_token'] = $csrfToken; // Regenerate CSRF token for the session
                $_SESSION['user_id'] = $user_id;
                $_SESSION['username'] = $username;
                $_SESSION['full_name'] = $admin_full_name;
                $_SESSION['user_role'] = 'ADM';
                $_SESSION['company_id'] = $company_id;
                $_SESSION['company_name'] = $company_name;

                SessionManager::flash('success', 'Company registered successfully! Welcome to SATI ERP.');
                header('Location: ../index.php?page=dashboard&welcome=1');
                exit;

            } catch (Exception $e) {
                $db->rollBack();
                $errors[] = "Registration failed: " . $e->getMessage();
                error_log("Company registration error: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
            }
        }
    }
}

// Generate CSRF token
$csrfToken = CSRF::generate();
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .register-container {
            max-width: 900px;
            margin: 0 auto;
        }
        .register-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }
        .register-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
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
            font-size: 40px;
            color: #667eea;
        }
        .form-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
        }
        .form-section-title {
            color: #667eea;
            font-weight: 600;
            margin-bottom: 20px;
            font-size: 18px;
            border-bottom: 2px solid #e0e0e0;
            padding-bottom: 10px;
        }
        .form-section-title i {
            margin-right: 10px;
        }
        .form-group {
            position: relative;
            margin-bottom: 20px;
        }
        .form-control, .form-select {
            height: 48px;
            border-radius: 10px;
            border: 1px solid #e1e5e9;
            padding-left: 45px;
            font-size: 14px;
            transition: all 0.3s;
        }
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.1);
        }
        .form-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #667eea;
            z-index: 10;
        }
        textarea.form-control {
            padding-top: 12px;
        }
        .password-strength {
            height: 4px;
            border-radius: 2px;
            margin-top: 8px;
            transition: all 0.3s;
        }
        .requirement-list {
            list-style: none;
            padding: 0;
            margin: 10px 0 0;
            font-size: 12px;
        }
        .requirement-list li {
            margin-bottom: 5px;
        }
        .requirement-list li.met {
            color: #28a745;
        }
        .requirement-list li.unmet {
            color: #6c757d;
        }
        .btn-register {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 14px;
            font-weight: 600;
            border-radius: 10px;
            width: 100%;
            font-size: 16px;
            transition: all 0.3s;
        }
        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
        }
        .alert {
            border-radius: 10px;
        }
        @media (max-width: 768px) {
            body { padding: 20px 0; }
            .register-header { padding: 20px; }
            .form-section { padding: 15px; }
        }
    </style>
</head>
<body>
    <div class="container register-container">
        <div class="register-card">
            <div class="register-header">
                <div class="logo">
                    <i class="fas fa-warehouse"></i>
                </div>
                <h2>Start Your Business Journey</h2>
                <p class="mb-0">Register your company and get started with SATI ERP</p>
            </div>

            <div class="p-4 p-md-5">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" id="registerForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                    <!-- Company Information -->
                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="fas fa-building"></i> Company Information
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <i class="fas fa-building form-icon"></i>
                                    <input type="text" class="form-control" name="company_name"
                                        placeholder="Company Name *"
                                        value="<?php echo htmlspecialchars($_POST['company_name'] ?? ''); ?>"
                                        required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <i class="fas fa-envelope form-icon"></i>
                                    <input type="email" class="form-control" name="company_email"
                                        placeholder="Company Email *"
                                        value="<?php echo htmlspecialchars($_POST['company_email'] ?? ''); ?>"
                                        required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <i class="fas fa-phone form-icon"></i>
                                    <input type="tel" class="form-control" name="company_phone"
                                        placeholder="Company Phone"
                                        value="<?php echo htmlspecialchars($_POST['company_phone'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <i class="fas fa-globe form-icon"></i>
                                    <select class="form-select" name="company_country">
                                        <option value="">Select Country</option>
                                        <?php foreach ($countries as $country): ?>
                                            <option value="<?php echo $country; ?>" 
                                                <?php echo ($_POST['company_country'] ?? 'Rwanda') == $country ? 'selected' : ''; ?>>
                                                <?php echo $country; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <i class="fas fa-map-marker-alt form-icon"></i>
                                    <input type="text" class="form-control" name="company_address"
                                        placeholder="Street Address"
                                        value="<?php echo htmlspecialchars($_POST['company_address'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <i class="fas fa-city form-icon"></i>
                                    <input type="text" class="form-control" name="company_city"
                                        placeholder="City"
                                        value="<?php echo htmlspecialchars($_POST['company_city'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Administrator Information -->
                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="fas fa-user-tie"></i> Administrator Information
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <i class="fas fa-user form-icon"></i>
                                    <input type="text" class="form-control" name="admin_full_name"
                                        placeholder="Full Name *"
                                        value="<?php echo htmlspecialchars($_POST['admin_full_name'] ?? ''); ?>"
                                        required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <i class="fas fa-envelope form-icon"></i>
                                    <input type="email" class="form-control" name="admin_email"
                                        placeholder="Email Address *"
                                        value="<?php echo htmlspecialchars($_POST['admin_email'] ?? ''); ?>"
                                        required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <i class="fas fa-phone form-icon"></i>
                                    <input type="tel" class="form-control" name="admin_phone"
                                        placeholder="Phone Number *"
                                        value="<?php echo htmlspecialchars($_POST['admin_phone'] ?? ''); ?>"
                                        required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <i class="fas fa-briefcase form-icon"></i>
                                    <input type="text" class="form-control" name="admin_position"
                                        placeholder="Position / Title"
                                        value="<?php echo htmlspecialchars($_POST['admin_position'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Account Security -->
                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="fas fa-lock"></i> Account Security
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <i class="fas fa-user-circle form-icon"></i>
                                    <input type="text" class="form-control" name="username"
                                        placeholder="Username *"
                                        value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                                        minlength="3" required>
                                    <small class="text-muted">Used for login (letters, numbers, underscores)</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <i class="fas fa-lock form-icon"></i>
                                    <input type="password" class="form-control" id="password"
                                        name="password" placeholder="Password *" minlength="8" required>
                                </div>
                                <div class="password-strength" id="passwordStrength"></div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <i class="fas fa-lock form-icon"></i>
                                    <input type="password" class="form-control" id="confirm_password"
                                        name="confirm_password" placeholder="Confirm Password *" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <ul class="requirement-list" id="passwordRequirements">
                                    <li class="unmet" id="req-length">
                                        <i class="far fa-circle"></i> At least 8 characters
                                    </li>
                                    <li class="unmet" id="req-uppercase">
                                        <i class="far fa-circle"></i> One uppercase letter
                                    </li>
                                    <li class="unmet" id="req-lowercase">
                                        <i class="far fa-circle"></i> One lowercase letter
                                    </li>
                                    <li class="unmet" id="req-number">
                                        <i class="far fa-circle"></i> One number
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- Terms and Submit -->
                    <div class="mb-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="terms" name="terms" required>
                            <label class="form-check-label" for="terms">
                                I agree to the <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal">Terms & Conditions</a>
                                and <a href="#" data-bs-toggle="modal" data-bs-target="#privacyModal">Privacy Policy</a> *
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="btn-register" id="submitBtn">
                        <i class="fas fa-user-plus me-2"></i> Register Company
                    </button>

                    <div class="text-center mt-4">
                        <p class="text-muted">
                            Already have an account?
                            <a href="signin.php" class="text-decoration-none">Sign In</a>
                        </p>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Terms Modal -->
    <div class="modal fade" id="termsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Terms & Conditions</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6>1. Acceptance of Terms</h6>
                    <p>By registering a company on SATI ERP, you agree to these Terms and Conditions.</p>
                    <h6>2. Account Responsibility</h6>
                    <p>You are responsible for maintaining the security of your account and all activities under it.</p>
                    <h6>3. Data Ownership</h6>
                    <p>You retain ownership of all data you input into the system.</p>
                    <h6>4. Service Availability</h6>
                    <p>We strive to maintain 99.9% uptime but cannot guarantee uninterrupted service.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Privacy Modal -->
    <div class="modal fade" id="privacyModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">Privacy Policy</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6>Information We Collect</h6>
                    <p>We collect company and user information provided during registration.</p>
                    <h6>How We Use Your Information</h6>
                    <p>We use your information to provide and improve our services.</p>
                    <h6>Data Security</h6>
                    <p>We implement industry-standard security measures to protect your data.</p>
                    <h6>Data Sharing</h6>
                    <p>We do not sell your personal information to third parties.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Password strength checker
        const passwordInput = document.getElementById('password');
        const strengthBar = document.getElementById('passwordStrength');
        const confirmInput = document.getElementById('confirm_password');

        function updatePasswordStrength() {
            const password = passwordInput.value;
            const checks = {
                length: password.length >= 8,
                uppercase: /[A-Z]/.test(password),
                lowercase: /[a-z]/.test(password),
                number: /[0-9]/.test(password)
            };

            // Update requirement icons
            const reqs = ['length', 'uppercase', 'lowercase', 'number'];
            reqs.forEach(req => {
                const element = document.getElementById(`req-${req}`);
                if (element) {
                    if (checks[req]) {
                        element.innerHTML = '<i class="fas fa-check-circle text-success"></i> ' + element.innerHTML.split('</i>')[1];
                        element.className = 'met';
                    } else {
                        element.innerHTML = '<i class="far fa-circle"></i> ' + element.innerHTML.split('</i>')[1];
                        element.className = 'unmet';
                    }
                }
            });

            // Update strength bar
            const strength = Object.values(checks).filter(Boolean).length;
            const colors = ['', '#dc3545', '#ffc107', '#17a2b8', '#28a745'];
            const widths = ['0%', '25%', '50%', '75%', '100%'];

            if (strengthBar) {
                strengthBar.style.width = widths[strength];
                strengthBar.style.backgroundColor = colors[strength];
                strengthBar.style.height = '4px';
                strengthBar.style.borderRadius = '2px';
            }
        }

        // Password match check
        function checkPasswordMatch() {
            if (confirmInput.value && passwordInput.value !== confirmInput.value) {
                confirmInput.setCustomValidity('Passwords do not match');
            } else {
                confirmInput.setCustomValidity('');
            }
        }

        passwordInput.addEventListener('input', updatePasswordStrength);
        passwordInput.addEventListener('change', checkPasswordMatch);
        confirmInput.addEventListener('keyup', checkPasswordMatch);

        // Form validation
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            if (passwordInput.value !== confirmInput.value) {
                e.preventDefault();
                alert('Passwords do not match!');
                return false;
            }
        });
    </script>
</body>
</html>