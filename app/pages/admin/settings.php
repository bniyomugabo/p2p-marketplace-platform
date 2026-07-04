<?php
// pages/admin/settings.php
declare(strict_types=1);

$pageTitle = 'System Settings - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Company.php';
require_once __DIR__ . '/../../models/CompanySetting.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission (only Admins)
if (!in_array($userRole, ['ADM'])) {
    SessionManager::flash('error', 'You do not have permission to access system settings.');
    header('Location: ' . route_url('dashboard'));
    exit;
}

// Check if company context is set
if (!$companyId) {
    SessionManager::flash('error', 'Company context not found. Please log in again.');
    header('Location: ' . route_url('login'));
    exit;
}

// Initialize models
$companyModel = new Company();
$company = $companyModel->find($companyId);

if (!$company) {
    SessionManager::flash('error', 'Company not found.');
    header('Location: ' . route_url('dashboard'));
    exit;
}

$settingModel = new CompanySetting($companyId);

// Load company settings
$settings = $settingModel->getAll();

// Define default values
$defaults = [
    'company_name' => $company['company_name'] ?? 'SATI ERP',
    'company_email' => $company['email'] ?? 'info@sati.com',
    'company_phone' => $company['phone'] ?? '+250 788 123 456',
    'company_address' => $company['address'] ?? 'Kigali, Rwanda',
    'company_city' => $company['city'] ?? 'Kigali',
    'company_country' => $company['country'] ?? 'Rwanda',
    'tax_id' => $company['tax_id'] ?? '',
    'vat_number' => $company['vat_number'] ?? '',
    'currency' => $company['currency'] ?? 'RWF',
    'date_format' => 'd/m/Y',
    'timezone' => 'Africa/Kigali',
    'language' => 'en',
    'invoice_prefix' => $company['invoice_prefix'] ?? 'INV',
    'quote_prefix' => $company['quote_prefix'] ?? 'QUO',
    'po_prefix' => $company['po_prefix'] ?? 'PO',
    'next_invoice_number' => $company['next_invoice_number'] ?? 1001,
    'next_quote_number' => $company['next_quote_number'] ?? 1001,
    'next_po_number' => $company['next_po_number'] ?? 1001,
    'due_days' => $company['payment_terms'] ?? 30,
    'default_tax' => 18,
    'auto_invoice' => 1,
    'show_invoice_footer' => 1,
    'invoice_footer_text' => 'Thank you for your business!',
    'low_stock_alert' => 10,
    'default_warehouse' => 1,
    'allow_negative_stock' => 0,
    'auto_reorder' => 0,
    'logo_url' => $company['logo_url'] ?? '',
    'website' => $company['website'] ?? '',
    'registration_number' => $company['registration_number'] ?? '',
    'billing_email' => $company['billing_email'] ?? '',
    'billing_address' => $company['billing_address'] ?? '',
    'contact_person' => $company['contact_person'] ?? '',
    'contact_person_phone' => $company['contact_person_phone'] ?? '',
    'contact_person_email' => $company['contact_person_email'] ?? '',
];

// Merge saved settings with defaults
foreach ($settings as $key => $value) {
    if (isset($defaults[$key])) {
        $defaults[$key] = $value;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
        SessionManager::flash('error', 'Invalid security token.');
        header('Location: ?page=admin/settings');
        exit;
    }

    try {
        $db->beginTransaction();

        // Update company information
        $companyData = [
            'company_name' => trim($_POST['company_name'] ?? $defaults['company_name']),
            'email' => trim($_POST['company_email'] ?? $defaults['company_email']),
            'phone' => trim($_POST['company_phone'] ?? $defaults['company_phone']),
            'address' => trim($_POST['company_address'] ?? $defaults['company_address']),
            'city' => trim($_POST['company_city'] ?? $defaults['company_city']),
            'country' => trim($_POST['company_country'] ?? $defaults['company_country']),
            'tax_id' => trim($_POST['tax_id'] ?? $defaults['tax_id']),
            'vat_number' => trim($_POST['vat_number'] ?? $defaults['vat_number']),
            'currency' => $_POST['currency'] ?? $defaults['currency'],
            'invoice_prefix' => trim($_POST['invoice_prefix'] ?? $defaults['invoice_prefix']),
            'quote_prefix' => trim($_POST['quote_prefix'] ?? $defaults['quote_prefix']),
            'po_prefix' => trim($_POST['po_prefix'] ?? $defaults['po_prefix']),
            'next_invoice_number' => (int) ($_POST['next_invoice_number'] ?? $defaults['next_invoice_number']),
            'next_quote_number' => (int) ($_POST['next_quote_number'] ?? $defaults['next_quote_number']),
            'next_po_number' => (int) ($_POST['next_po_number'] ?? $defaults['next_po_number']),
            'payment_terms' => (int) ($_POST['due_days'] ?? $defaults['due_days']),
            'website' => trim($_POST['website'] ?? $defaults['website']),
            'registration_number' => trim($_POST['registration_number'] ?? $defaults['registration_number']),
            'billing_email' => trim($_POST['billing_email'] ?? $defaults['billing_email']),
            'billing_address' => trim($_POST['billing_address'] ?? $defaults['billing_address']),
            'contact_person' => trim($_POST['contact_person'] ?? $defaults['contact_person']),
            'contact_person_phone' => trim($_POST['contact_person_phone'] ?? $defaults['contact_person_phone']),
            'contact_person_email' => trim($_POST['contact_person_email'] ?? $defaults['contact_person_email']),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $companyModel->update($companyId, $companyData);

        // Save company settings
        $settingData = [
            'date_format' => $_POST['date_format'] ?? $defaults['date_format'],
            'timezone' => $_POST['timezone'] ?? $defaults['timezone'],
            'language' => $_POST['language'] ?? $defaults['language'],
            'default_tax' => (float) ($_POST['default_tax'] ?? $defaults['default_tax']),
            'auto_invoice' => isset($_POST['auto_invoice']) ? 1 : 0,
            'show_invoice_footer' => isset($_POST['show_invoice_footer']) ? 1 : 0,
            'invoice_footer_text' => trim($_POST['invoice_footer_text'] ?? $defaults['invoice_footer_text']),
            'low_stock_alert' => (int) ($_POST['low_stock_alert'] ?? $defaults['low_stock_alert']),
            'default_warehouse' => (int) ($_POST['default_warehouse'] ?? $defaults['default_warehouse']),
            'allow_negative_stock' => isset($_POST['allow_negative_stock']) ? 1 : 0,
            'auto_reorder' => isset($_POST['auto_reorder']) ? 1 : 0,
        ];

        foreach ($settingData as $key => $value) {
            $settingModel->set($key, $value, 'text');
        }

        // Log the activity
        $activitySql = "
            INSERT INTO activity_log (company_id, user_id, action, entity_type, entity_id, new_data, created_at)
            VALUES (:p_1_company_id, :user_id, 'settings_updated', 'company', :p_2_company_id, :new_data, NOW())
        ";
        $activityStmt = $db->prepare($activitySql);
        $activityStmt->execute([
            'p_1_company_id' => $companyId,
            'user_id' => $userId,
            'p_2_company_id' => $companyId,
            'new_data' => json_encode(array_keys($settingData))
        ]);

        $db->commit();

        // Update session variables
        $_SESSION['company_name'] = $companyData['company_name'];
        $_SESSION['company_currency'] = $companyData['currency'];
        $_SESSION['company_email'] = $companyData['email'];
        $_SESSION['company_phone'] = $companyData['phone'];
        $_SESSION['company_address'] = $companyData['address'];
        $_SESSION['company_tax_id'] = $companyData['tax_id'];

        SessionManager::flash('success', 'Settings saved successfully!');

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Save settings error for company {$companyId}: " . $e->getMessage());
        SessionManager::flash('error', 'Failed to save settings: ' . $e->getMessage());
    }

    header('Location: ?page=admin/settings');
    exit;
}

// Generate CSRF token
$csrfToken = CSRF::generate();

// Get warehouses for dropdown
$warehouseModel = new Warehouse($companyId);
$warehouses = $warehouseModel->all(['id', 'warehouse_name'], 'is_active = 1');
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <a href="?page=admin" class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Admin
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-cogs me-2"></i>System Settings
                    </h2>
                    <p class="mb-0 text-muted">Configure company preferences and system options</p>
                </div>
            </div>
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

    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

        <!-- General Settings -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-globe me-2"></i>General Settings
                </h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Company Name</label>
                        <input type="text" class="form-control" name="company_name" value="<?php echo htmlspecialchars($defaults['company_name']); ?>">
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Registration Number</label>
                        <input type="text" class="form-control" name="registration_number" value="<?php echo htmlspecialchars($defaults['registration_number']); ?>">
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Company Email</label>
                        <input type="email" class="form-control" name="company_email" value="<?php echo htmlspecialchars($defaults['company_email']); ?>">
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Billing Email</label>
                        <input type="email" class="form-control" name="billing_email" value="<?php echo htmlspecialchars($defaults['billing_email']); ?>">
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Company Phone</label>
                        <input type="tel" class="form-control" name="company_phone" value="<?php echo htmlspecialchars($defaults['company_phone']); ?>">
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Website</label>
                        <input type="url" class="form-control" name="website" value="<?php echo htmlspecialchars($defaults['website']); ?>" placeholder="https://example.com">
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Tax ID</label>
                        <input type="text" class="form-control" name="tax_id" value="<?php echo htmlspecialchars($defaults['tax_id']); ?>">
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">VAT Number</label>
                        <input type="text" class="form-control" name="vat_number" value="<?php echo htmlspecialchars($defaults['vat_number']); ?>">
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Company Address</label>
                    <textarea class="form-control" name="company_address" rows="2"><?php echo htmlspecialchars($defaults['company_address']); ?></textarea>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">City</label>
                        <input type="text" class="form-control" name="company_city" value="<?php echo htmlspecialchars($defaults['company_city']); ?>">
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Country</label>
                        <input type="text" class="form-control" name="company_country" value="<?php echo htmlspecialchars($defaults['company_country']); ?>">
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Billing Address</label>
                        <textarea class="form-control" name="billing_address" rows="2"><?php echo htmlspecialchars($defaults['billing_address']); ?></textarea>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Contact Person</label>
                        <input type="text" class="form-control" name="contact_person" value="<?php echo htmlspecialchars($defaults['contact_person']); ?>">
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Contact Person Phone</label>
                        <input type="tel" class="form-control" name="contact_person_phone" value="<?php echo htmlspecialchars($defaults['contact_person_phone']); ?>">
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Contact Person Email</label>
                        <input type="email" class="form-control" name="contact_person_email" value="<?php echo htmlspecialchars($defaults['contact_person_email']); ?>">
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Currency</label>
                        <select class="form-control" name="currency">
                            <option value="RWF" <?php echo $defaults['currency'] === 'RWF' ? 'selected' : ''; ?>>Rwandan Franc (RWF)</option>
                            <option value="USD" <?php echo $defaults['currency'] === 'USD' ? 'selected' : ''; ?>>US Dollar (USD)</option>
                            <option value="EUR" <?php echo $defaults['currency'] === 'EUR' ? 'selected' : ''; ?>>Euro (EUR)</option>
                            <option value="GBP" <?php echo $defaults['currency'] === 'GBP' ? 'selected' : ''; ?>>British Pound (GBP)</option>
                        </select>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Date Format</label>
                        <select class="form-control" name="date_format">
                            <option value="d/m/Y" <?php echo $defaults['date_format'] === 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY</option>
                            <option value="m/d/Y" <?php echo $defaults['date_format'] === 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY</option>
                            <option value="Y-m-d" <?php echo $defaults['date_format'] === 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD</option>
                        </select>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Time Zone</label>
                        <select class="form-control" name="timezone">
                            <option value="Africa/Kigali" <?php echo $defaults['timezone'] === 'Africa/Kigali' ? 'selected' : ''; ?>>Africa/Kigali (CAT)</option>
                            <option value="Africa/Nairobi" <?php echo $defaults['timezone'] === 'Africa/Nairobi' ? 'selected' : ''; ?>>Africa/Nairobi (EAT)</option>
                            <option value="UTC" <?php echo $defaults['timezone'] === 'UTC' ? 'selected' : ''; ?>>UTC</option>
                        </select>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Language</label>
                        <select class="form-control" name="language">
                            <option value="en" <?php echo $defaults['language'] === 'en' ? 'selected' : ''; ?>>English</option>
                            <option value="fr" <?php echo $defaults['language'] === 'fr' ? 'selected' : ''; ?>>French</option>
                            <option value="rw" <?php echo $defaults['language'] === 'rw' ? 'selected' : ''; ?>>Kinyarwanda</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Invoice Settings -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-file-invoice me-2"></i>Invoice & Document Settings
                </h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Invoice Prefix</label>
                        <input type="text" class="form-control" name="invoice_prefix" value="<?php echo htmlspecialchars($defaults['invoice_prefix']); ?>">
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Quotation Prefix</label>
                        <input type="text" class="form-control" name="quote_prefix" value="<?php echo htmlspecialchars($defaults['quote_prefix']); ?>">
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Purchase Order Prefix</label>
                        <input type="text" class="form-control" name="po_prefix" value="<?php echo htmlspecialchars($defaults['po_prefix']); ?>">
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Next Invoice Number</label>
                        <input type="number" class="form-control" name="next_invoice_number" value="<?php echo $defaults['next_invoice_number']; ?>">
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Next Quotation Number</label>
                        <input type="number" class="form-control" name="next_quote_number" value="<?php echo $defaults['next_quote_number']; ?>">
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Next PO Number</label>
                        <input type="number" class="form-control" name="next_po_number" value="<?php echo $defaults['next_po_number']; ?>">
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Default Due Days</label>
                        <input type="number" class="form-control" name="due_days" value="<?php echo $defaults['due_days']; ?>">
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Default Tax Rate (%)</label>
                        <input type="number" class="form-control" name="default_tax" value="<?php echo $defaults['default_tax']; ?>" step="0.1">
                    </div>
                </div>
                
                <div class="mb-3">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="auto_invoice" name="auto_invoice" <?php echo $defaults['auto_invoice'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="auto_invoice">Auto-generate invoice numbers</label>
                    </div>
                </div>
                
                <div class="mb-3">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="show_invoice_footer" name="show_invoice_footer" <?php echo $defaults['show_invoice_footer'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="show_invoice_footer">Show footer on invoices</label>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Invoice Footer Text</label>
                    <textarea class="form-control" name="invoice_footer_text" rows="2"><?php echo htmlspecialchars($defaults['invoice_footer_text']); ?></textarea>
                </div>
            </div>
        </div>

        <!-- Inventory Settings -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-boxes me-2"></i>Inventory Settings
                </h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Low Stock Alert Level</label>
                        <input type="number" class="form-control" name="low_stock_alert" value="<?php echo $defaults['low_stock_alert']; ?>">
                        <small class="text-muted">Notify when stock falls below this level</small>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Default Warehouse</label>
                        <select class="form-control" name="default_warehouse">
                            <?php foreach ($warehouses as $warehouse): ?>
                                    <option value="<?php echo $warehouse['id']; ?>" <?php echo $defaults['default_warehouse'] == $warehouse['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($warehouse['warehouse_name']); ?>
                                    </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="mb-3">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="allow_negative_stock" name="allow_negative_stock" <?php echo $defaults['allow_negative_stock'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="allow_negative_stock">Allow negative stock (not recommended)</label>
                    </div>
                </div>
                
                <div class="mb-3">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="auto_reorder" name="auto_reorder" <?php echo $defaults['auto_reorder'] ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="auto_reorder">Auto-generate reorder alerts</label>
                    </div>
                </div>
            </div>
        </div>

        <!-- Submit Button -->
        <div class="row mb-4">
            <div class="col-12 text-end">
                <button type="submit" class="btn btn-primary btn-lg">
                    <i class="fas fa-save me-2"></i>Save All Settings
                </button>
            </div>
        </div>
    </form>
</div>

<style>
    .card-header { background-color: #f8f9fc; }
    .form-check-label { cursor: pointer; }
    .card { transition: box-shadow 0.2s; }
    .card:hover { box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1); }
</style>