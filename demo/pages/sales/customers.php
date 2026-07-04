<?php
// pages/sales/customers.php
declare(strict_types=1);

$pageTitle = 'Customers - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';

// Load models
require_once __DIR__ . '/../../models/Customer.php';
require_once __DIR__ . '/../../models/Sale.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check permission
if (!in_array($userRole, ['ADM', 'MGR', 'SEL', 'ACC'])) {
    SessionManager::flash('error', 'You do not have permission to access customers.');
    header('Location: ' . route_url('sales'));
    exit;
}

// Initialize models with company context
$customerModel = new Customer($companyId);
$saleModel = new Sale($companyId);

// Handle customer deletion
if (isset($_GET['delete']) && in_array($userRole, ['ADM', 'MGR'])) {
    $id = (int) $_GET['delete'];
    try {
        // Check if customer has any invoices before deleting
        $customerSales = $saleModel->getSalesByCustomer($id);
        if (!empty($customerSales)) {
            throw new Exception('Cannot delete customer with existing sales records. Archive instead.');
        }
        $customerModel->delete($id);
        SessionManager::flash('success', 'Customer deleted successfully.');
    } catch (Exception $e) {
        SessionManager::flash('error', 'Failed to delete customer: ' . $e->getMessage());
    }
    header('Location: ' . route_url('sales/customers'));
    exit;
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$type = $_GET['type'] ?? '';

// Build where clause with company filter
$where = "";
$params = [];

if ($search) {
    $where .= "full_name LIKE :p_1_search OR email LIKE :p_2_search OR phone LIKE :p_3_search OR customer_code LIKE :p_4_search";
    $params['p_1_search'] = "%{$search}%";
    $params['p_2_search'] = "%{$search}%";
    $params['p_3_search'] = "%{$search}%";
    $params['p_4_search'] = "%{$search}%";
}

if ($type) {
    $where .= "customer_type = :type";
    $params['type'] = $type;
}

// Get customers
$customers = $customerModel->all(['*'], $where, $params);

// Get summary (company-specific)
$summary = $customerModel->getSummary();

// Get top customers
$topCustomers = $customerModel->getTopCustomers(5);

// Generate CSRF token
$csrfToken = CSRF::generate();

// Get currency
$currency = $_SESSION['company_currency'] ?? 'RWF';
?>

<!-- Page Header -->
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center flex-wrap">
            <div>
                <a href="<?php echo route_url('sales'); ?>" class="btn btn-outline-secondary btn-sm mb-2">
                    <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
                </a>
                <h2 class="h4 mb-1 text-gray-800">
                    <i class="fas fa-users me-2"></i>Customers
                </h2>
                <p class="mb-0 text-muted">Manage your customer database</p>
            </div>
            <div class="mt-2 mt-sm-0">
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                    <i class="fas fa-user-plus me-2"></i>Add Customer
                </button>
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

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-left-primary shadow-sm h-100">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Customers</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format((float)(float) ($summary['total_customers'] ?? 0)); ?></div>
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
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Active Customers</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format((float)(float) ($summary['active_customers'] ?? 0)); ?></div>
                    </div>
                    <div class="col-auto"><i class="fas fa-user-check fa-2x text-gray-300"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-left-info shadow-sm h-100">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">New This Month</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo number_format((float)(float) ($summary['new_customers'] ?? 0)); ?></div>
                    </div>
                    <div class="col-auto"><i class="fas fa-user-plus fa-2x text-gray-300"></i></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="card border-left-warning shadow-sm h-100">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Total Balance</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?php echo format_currency($summary['total_balance'] ?? 0, $companyId); ?></div>
                    </div>
                    <div class="col-auto"><i class="fas fa-dollar-sign fa-2x text-gray-300"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-filter me-2"></i>Filter Customers</h6>
    </div>
    <div class="card-body">
        <form method="GET" action="" class="row g-3">
            <input type="hidden" name="page" value="sales/customers">
            <div class="col-md-5">
                <label for="search" class="form-label">Search</label>
                <input type="text" class="form-control" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, email, phone, or code...">
            </div>
            <div class="col-md-3">
                <label for="type" class="form-label">Customer Type</label>
                <select class="form-control" id="type" name="type">
                    <option value="">All Types</option>
                    <option value="individual" <?php echo $type === 'individual' ? 'selected' : ''; ?>>👤 Individual</option>
                    <option value="company" <?php echo $type === 'company' ? 'selected' : ''; ?>>🏢 Company</option>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search me-2"></i>Search</button>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <a href="?page=sales/customers" class="btn btn-secondary w-100"><i class="fas fa-undo me-2"></i>Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- Top Customers Section -->
<?php if (!empty($topCustomers)): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h6 class="m-0 font-weight-bold text-primary"><i class="fas fa-trophy me-2"></i>Top Customers by Purchase Value</h6>
        </div>
        <div class="card-body">
            <div class="row">
                <?php foreach ($topCustomers as $index => $customer): ?>
                        <div class="col-md-6 col-lg-4 mb-3">
                            <div class="d-flex align-items-center p-3 bg-light rounded">
                                <div class="flex-shrink-0">
                                    <div class="rounded-circle bg-<?php echo $index === 0 ? 'warning' : ($index === 1 ? 'secondary' : 'info'); ?> text-white d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                                        <span class="h5 mb-0"><?php echo $index + 1; ?></span>
                                    </div>
                                </div>
                                <div class="flex-grow-1 ms-3">
                                    <h6 class="mb-0"><?php echo htmlspecialchars($customer['full_name']); ?></h6>
                                    <small class="text-muted"><?php echo number_format((float)$customer['total_purchases'], 0); ?>         <?php echo $currency; ?></small>
                                    <small class="text-muted d-block"><?php echo $customer['invoice_count']; ?> invoices</small>
                                </div>
                                <a href="?page=sales/view-customer&id=<?php echo $customer['id']; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </div>
                        </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Customers Table -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="m-0 font-weight-bold text-primary">
            <i class="fas fa-list me-2"></i>Customer List
            <span class="badge bg-primary ms-2"><?php echo count($customers); ?> customers</span>
        </h6>
        <button class="btn btn-sm btn-outline-primary" onclick="window.location.reload()">
            <i class="fas fa-sync-alt me-1"></i> Refresh
        </button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="customersTable">
                <thead class="table-light">
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Contact</th>
                        <th>Location</th>
                        <th class="text-end">Credit Limit</th>
                        <th class="text-end">Balance</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($customers)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5">
                                    <div class="text-muted">
                                        <i class="fas fa-user-slash fa-4x mb-3"></i>
                                        <p class="h5 mb-2">No customers found</p>
                                        <p class="mb-0">Click "Add Customer" to get started</p>
                                    </div>
                                </td>
                            </tr>
                    <?php else: ?>
                            <?php foreach ($customers as $customer): ?>
                                    <tr>
                                        <td><strong><code><?php echo htmlspecialchars($customer['customer_code']); ?></code></strong></td>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($customer['full_name']); ?></div>
                                            <?php if ($customer['customer_type'] === 'company'): ?>
                                                    <small class="text-muted">🏢 Company</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge bg-<?php echo $customer['customer_type'] === 'company' ? 'primary' : 'info'; ?>"><?php echo ucfirst($customer['customer_type']); ?></span></td>
                                        <td>
                                            <?php if (!empty($customer['phone'])): ?>
                                                    <div><i class="fas fa-phone me-1"></i> <?php echo htmlspecialchars($customer['phone']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($customer['email'])): ?>
                                                    <div><i class="fas fa-envelope me-1"></i> <?php echo htmlspecialchars($customer['email']); ?></div>
                                            <?php endif; ?>
                                            <?php if (empty($customer['phone']) && empty($customer['email'])): ?>
                                                    <span class="text-muted">No contact info</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($customer['city'])): ?>
                                                    <div><i class="fas fa-city me-1"></i> <?php echo htmlspecialchars($customer['city']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($customer['address'])): ?>
                                                    <small class="text-muted"><?php echo htmlspecialchars(substr($customer['address'], 0, 30)); ?></small>
                                            <?php endif; ?>
                                            <?php if (empty($customer['city']) && empty($customer['address'])): ?>
                                                    <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end"><?php echo format_currency($customer['credit_limit'] ?? 0, $companyId); ?></td>
                                        <td class="text-end <?php echo ($customer['current_balance'] ?? 0) > 0 ? 'text-danger' : 'text-success'; ?>">
                                            <strong><?php echo format_currency($customer['current_balance'] ?? 0, $companyId); ?></strong>
                                        </td>
                                        <td>
                                            <?php if ($customer['is_active']): ?>
                                                    <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                    <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <a href="?page=sales/view-customer&id=<?php echo $customer['id']; ?>" class="btn btn-outline-info" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <button type="button" class="btn btn-outline-warning" onclick='editCustomer(<?php echo htmlspecialchars(json_encode($customer)); ?>)' title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="?page=sales/customers&delete=<?php echo $customer['id']; ?>" class="btn btn-outline-danger delete-customer" data-customer-name="<?php echo htmlspecialchars($customer['full_name']); ?>" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </a>
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

<!-- Add Customer Modal -->
<div class="modal fade" id="addCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add New Customer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="?page=sales/save-customer">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="company_id" value="<?php echo $companyId; ?>">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3"><label for="full_name" class="form-label">Full Name / Company Name <span class="text-danger">*</span></label><input type="text" class="form-control" id="full_name" name="full_name" required></div>
                        <div class="col-md-6 mb-3"><label for="customer_type" class="form-label">Customer Type</label><select class="form-control" id="customer_type" name="customer_type"><option value="individual">👤 Individual</option><option value="company">🏢 Company</option></select></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label for="phone" class="form-label">Phone Number</label><input type="tel" class="form-control" id="phone" name="phone" placeholder="e.g., +250 788 123 456"></div>
                        <div class="col-md-6 mb-3"><label for="email" class="form-label">Email Address</label><input type="email" class="form-control" id="email" name="email" placeholder="customer@example.com"></div>
                    </div>
                    <div class="mb-3"><label for="address" class="form-label">Address</label><textarea class="form-control" id="address" name="address" rows="2" placeholder="Street address, building, etc."></textarea></div>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label for="city" class="form-label">City</label><input type="text" class="form-control" id="city" name="city" placeholder="e.g., Kigali"></div>
                        <div class="col-md-6 mb-3"><label for="tax_id" class="form-label">Tax ID / VAT Number</label><input type="text" class="form-control" id="tax_id" name="tax_id" placeholder="e.g., 123456789"></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label for="credit_limit" class="form-label">Credit Limit</label><div class="input-group"><span class="input-group-text"><?php echo $currency; ?></span><input type="number" class="form-control" id="credit_limit" name="credit_limit" min="0" step="0.01" value="0"></div></div>
                        <div class="col-md-6 mb-3"><label for="initial_balance" class="form-label">Initial Balance</label><div class="input-group"><span class="input-group-text"><?php echo $currency; ?></span><input type="number" class="form-control" id="initial_balance" name="initial_balance" min="0" step="0.01" value="0"></div><div class="form-text">Opening balance if customer has existing debt</div></div>
                    </div>
                    <div class="mb-3"><div class="form-check"><input type="checkbox" class="form-check-input" id="is_active" name="is_active" value="1" checked><label class="form-check-label" for="is_active">Active Customer</label></div></div>
                    <div class="mb-3"><label for="customer_notes" class="form-label">Notes</label><textarea class="form-control" id="customer_notes" name="notes" rows="2" placeholder="Any additional information about this customer"></textarea></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Customer</button></div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Customer Modal -->
<div class="modal fade" id="editCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Customer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="?page=sales/update-customer">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="id" id="edit_id">
                <input type="hidden" name="company_id" value="<?php echo $companyId; ?>">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3"><label for="edit_full_name" class="form-label">Full Name / Company Name <span class="text-danger">*</span></label><input type="text" class="form-control" id="edit_full_name" name="full_name" required></div>
                        <div class="col-md-6 mb-3"><label for="edit_customer_type" class="form-label">Customer Type</label><select class="form-control" id="edit_customer_type" name="customer_type"><option value="individual">👤 Individual</option><option value="company">🏢 Company</option></select></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label for="edit_phone" class="form-label">Phone Number</label><input type="tel" class="form-control" id="edit_phone" name="phone"></div>
                        <div class="col-md-6 mb-3"><label for="edit_email" class="form-label">Email Address</label><input type="email" class="form-control" id="edit_email" name="email"></div>
                    </div>
                    <div class="mb-3"><label for="edit_address" class="form-label">Address</label><textarea class="form-control" id="edit_address" name="address" rows="2"></textarea></div>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label for="edit_city" class="form-label">City</label><input type="text" class="form-control" id="edit_city" name="city"></div>
                        <div class="col-md-6 mb-3"><label for="edit_tax_id" class="form-label">Tax ID / VAT Number</label><input type="text" class="form-control" id="edit_tax_id" name="tax_id"></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label for="edit_credit_limit" class="form-label">Credit Limit</label><div class="input-group"><span class="input-group-text"><?php echo $currency; ?></span><input type="number" class="form-control" id="edit_credit_limit" name="credit_limit" min="0" step="0.01"></div></div>
                        <div class="col-md-6 mb-3"><label for="edit_is_active" class="form-label">Status</label><select class="form-control" id="edit_is_active" name="is_active"><option value="1">✅ Active</option><option value="0">❌ Inactive</option></select></div>
                    </div>
                    <div class="mb-3"><label for="edit_notes" class="form-label">Notes</label><textarea class="form-control" id="edit_notes" name="notes" rows="2"></textarea></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning"><i class="fas fa-save me-2"></i>Update Customer</button></div>
            </form>
        </div>
    </div>
</div>

<!-- View Customer Modal -->
<div class="modal fade" id="viewCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-user-circle me-2"></i>Customer Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="customerDetailsContent">
                <div class="text-center py-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><a href="#" id="viewCustomerInvoicesBtn" class="btn btn-primary"><i class="fas fa-file-invoice me-2"></i>View Invoices</a></div>
        </div>
    </div>
</div>

<script>
const currency = '<?php echo $currency; ?>';
const companyId = <?php echo json_encode($companyId); ?>;

function editCustomer(customer) {
    document.getElementById('edit_id').value = customer.id;
    document.getElementById('edit_full_name').value = customer.full_name;
    document.getElementById('edit_customer_type').value = customer.customer_type;
    document.getElementById('edit_phone').value = customer.phone || '';
    document.getElementById('edit_email').value = customer.email || '';
    document.getElementById('edit_address').value = customer.address || '';
    document.getElementById('edit_city').value = customer.city || '';
    document.getElementById('edit_tax_id').value = customer.tax_id || '';
    document.getElementById('edit_credit_limit').value = customer.credit_limit || 0;
    document.getElementById('edit_is_active').value = customer.is_active;
    document.getElementById('edit_notes').value = customer.notes || '';
    new bootstrap.Modal(document.getElementById('editCustomerModal')).show();
}

function viewCustomer(customerId) {
    const modalContent = document.getElementById('customerDetailsContent');
    modalContent.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>';
    fetch(`/sati_premium/api/customers/get.php?id=${customerId}&company_id=${companyId}`).then(response => response.json()).then(data => {
        if (data.success) displayCustomerDetails(data.customer);
        else modalContent.innerHTML = `<div class="alert alert-danger">${data.message}</div>`;
    }).catch(error => { modalContent.innerHTML = '<div class="alert alert-danger">Failed to load customer details.</div>'; });
    new bootstrap.Modal(document.getElementById('viewCustomerModal')).show();
}

function displayCustomerDetails(customer) {
    document.getElementById('customerDetailsContent').innerHTML = `
        <div class="row"><div class="col-md-6"><div class="card mb-3"><div class="card-header bg-light"><h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Basic Information</h6></div><div class="card-body"><table class="table table-sm"><tr><th style="width: 40%">Customer Code:</th><td><code>${escapeHtml(customer.customer_code)}</code></td></tr><tr><th>Name:</th><td><strong>${escapeHtml(customer.full_name)}</strong></td></tr><tr><th>Type:</th><td><span class="badge bg-${customer.customer_type === 'company' ? 'primary' : 'info'}">${escapeHtml(customer.customer_type)}</span></td></tr><tr><th>Status:</th><td>${customer.is_active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'}</td></tr></table></div></div></div><div class="col-md-6"><div class="card mb-3"><div class="card-header bg-light"><h6 class="mb-0"><i class="fas fa-address-card me-2"></i>Contact Information</h6></div><div class="card-body"><table class="table table-sm"><tr><th style="width: 40%">Phone:</th><td>${customer.phone ? escapeHtml(customer.phone) : '-'}</td></tr><tr><th>Email:</th><td>${customer.email ? escapeHtml(customer.email) : '-'}</td></tr><tr><th>Address:</th><td>${customer.address ? escapeHtml(customer.address) : '-'}</td></tr><tr><th>City:</th><td>${customer.city ? escapeHtml(customer.city) : '-'}</td></tr><tr><th>Tax ID:</th><td>${customer.tax_id ? escapeHtml(customer.tax_id) : '-'}</td></tr></table></div></div></div></div><div class="row"><div class="col-md-6"><div class="card mb-3"><div class="card-header bg-light"><h6 class="mb-0"><i class="fas fa-chart-line me-2"></i>Financial Information</h6></div><div class="card-body"><table class="table table-sm"><tr><th style="width: 40%">Credit Limit:</th><td>${formatCurrency(customer.credit_limit)}</td></tr><tr><th>Current Balance:</th><td class="${customer.current_balance > 0 ? 'text-danger' : 'text-success'} fw-bold">${formatCurrency(customer.current_balance)}</td></tr><tr><th>Total Purchases:</th><td>${formatCurrency(customer.total_purchases)}</td></tr><tr><th>Total Paid:</th><td>${formatCurrency(customer.total_paid)}</td></tr><tr><th>Outstanding:</th><td class="${customer.outstanding_balance > 0 ? 'text-danger' : 'text-success'}">${formatCurrency(customer.outstanding_balance)}</td></tr></table></div></div></div><div class="col-md-6"><div class="card mb-3"><div class="card-header bg-light"><h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Purchase History</h6></div><div class="card-body"><table class="table table-sm"><tr><th style="width: 40%">Total Invoices:</th><td>${customer.total_invoices || 0}</td></tr><tr><th>First Purchase:</th><td>${customer.first_purchase_date ? new Date(customer.first_purchase_date).toLocaleDateString() : '-'}</td></tr><tr><th>Last Purchase:</th><td>${customer.last_purchase_date ? new Date(customer.last_purchase_date).toLocaleDateString() : '-'}</td></tr><tr><th>Avg Order Value:</th><td>${formatCurrency(customer.avg_order_value)}</td></tr></table></div></div></div></div>${customer.notes ? `<div class="card"><div class="card-header bg-light"><h6 class="mb-0"><i class="fas fa-sticky-note me-2"></i>Notes</h6></div><div class="card-body"><p class="mb-0">${escapeHtml(customer.notes)}</p></div></div>` : ''}`;
    document.getElementById('viewCustomerInvoicesBtn').href = `?page=sales/invoices&customer_id=${customer.id}`;
}

function formatCurrency(amount) { return currency + ' ' + new Intl.NumberFormat().format(amount || 0); }
function escapeHtml(text) { if (!text) return ''; const div = document.createElement('div'); div.textContent = text; return div.innerHTML; }

// Delete confirmation
document.querySelectorAll('.delete-customer').forEach(link => {
    link.addEventListener('click', function(e) { e.preventDefault(); const customerName = this.getAttribute('data-customer-name'); if (confirm(`Are you sure you want to delete customer "${customerName}"? This action cannot be undone.`)) { window.location.href = this.href; } });
});

// Initialize DataTable
$(document).ready(function() {
    if ($.fn.DataTable && $('#customersTable tbody tr').length > 10) {
        $('#customersTable').DataTable({ pageLength: 25, order: [[1, 'asc']], language: { search: "Search customers:", lengthMenu: "Show _MENU_ customers per page", info: "Showing _START_ to _END_ of _TOTAL_ customers", emptyTable: "No customers found" }, columnDefs: [{ orderable: false, targets: [8] }], searching: false, paging: false });
    }
});
</script>

<style>
    .border-left-primary { border-left: 4px solid #4e73df !important; }
    .border-left-success { border-left: 4px solid #1cc88a !important; }
    .border-left-info { border-left: 4px solid #36b9cc !important; }
    .border-left-warning { border-left: 4px solid #f6c23e !important; }
    .table td { vertical-align: middle; }
    .btn-group { gap: 4px; }
    .badge { font-weight: 500; padding: 0.5em 0.85em; }
    .modal .table-sm th { background-color: #f8f9fc; width: 40%; }
    .currency-symbol { min-width: 50px; }
</style>