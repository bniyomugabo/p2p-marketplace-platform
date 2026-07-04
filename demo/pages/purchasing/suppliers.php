<?php
// pages/purchasing/suppliers.php
declare(strict_types=1);

$pageTitle = 'Suppliers - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Supplier.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR', 'ACC'])) {
    SessionManager::flash('error', 'You do not have permission to view suppliers.');
    header('Location: ' . route_url('purchasing'));
    exit;
}

// Check if company context is set
if (!$companyId) {
    SessionManager::flash('error', 'Company context not found. Please log in again.');
    header('Location: ' . route_url('login'));
    exit;
}

// Initialize model with company ID
$supplierModel = new Supplier($companyId);

// Handle delete
if (isset($_GET['delete']) && in_array($userRole, ['ADM', 'MGR'])) {
    $id = (int) $_GET['delete'];

    // Verify supplier belongs to company before deleting
    $supplier = $supplierModel->find($id);
    if (!$supplier) {
        SessionManager::flash('error', 'Supplier not found or does not belong to your company.');
    } else {
        try {
            // Check if supplier has purchase orders before deleting
            $db = Database::getInstance();
            $checkStmt = $db->prepare("SELECT COUNT(*) FROM purchase_orders WHERE supplier_id = :supplier_id AND company_id = :company_id");
            $checkStmt->execute(['supplier_id' => $id, 'company_id' => $companyId]);
            $orderCount = $checkStmt->fetchColumn();

            if ($orderCount > 0) {
                SessionManager::flash('error', 'Cannot delete supplier with existing purchase orders. Deactivate instead.');
            } else {
                $supplierModel->delete($id);
                SessionManager::flash('success', 'Supplier deleted successfully.');
            }
        } catch (Exception $e) {
            SessionManager::flash('error', 'Failed to delete supplier: ' . $e->getMessage());
        }
    }
    header('Location: ?page=purchasing/suppliers');
    exit;
}

// Handle toggle active status
if (isset($_GET['toggle_active']) && isset($_GET['id']) && in_array($userRole, ['ADM', 'MGR'])) {
    $id = (int) $_GET['id'];
    $supplier = $supplierModel->find($id);

    if ($supplier) {
        $newStatus = $supplier['is_active'] ? 0 : 1;
        $supplierModel->update($id, ['is_active' => $newStatus]);
        SessionManager::flash('success', 'Supplier status updated successfully.');
    } else {
        SessionManager::flash('error', 'Supplier not found.');
    }
    header('Location: ?page=purchasing/suppliers' . (isset($_GET['status']) ? '&status=' . $_GET['status'] : '') . (isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''));
    exit;
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? 'active';

// Build where clause with company filter
$where = "company_id = :company_id";
$params = ['company_id' => $companyId];

if ($status === 'active') {
    $where .= " AND is_active = 1";
} elseif ($status === 'inactive') {
    $where .= " AND is_active = 0";
}

if (!empty($search)) {
    $where .= " AND (supplier_name LIKE :search OR supplier_code LIKE :search OR contact_person LIKE :search OR email LIKE :search OR phone LIKE :search)";
    $params['search'] = "%{$search}%";
}

// Get suppliers
$suppliers = $supplierModel->all(['*'], $where, $params);

// Get summary using model (already company-filtered)
$summary = $supplierModel->getStats();

// Generate CSRF token for forms
$csrfToken = CSRF::generate();
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <a href="<?php echo route_url('purchasing'); ?>" class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Purchasing
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-truck me-2"></i>Suppliers
                    </h2>
                    <p class="mb-0 text-muted">Manage your company's supplier database</p>
                </div>
                <div>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal"
                        data-bs-target="#addSupplierModal">
                        <i class="fas fa-user-plus me-2"></i>Add Supplier
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

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-xl-4 col-md-4 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Total Suppliers
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) ($summary['total_suppliers'] ?? 0)); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-users fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-4 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Active Suppliers
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) ($summary['active_suppliers'] ?? 0)); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-user-check fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-4 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                New This Month
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) ($summary['new_suppliers'] ?? 0)); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-user-plus fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-filter me-2"></i>Filter Suppliers
            </h6>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <input type="hidden" name="page" value="purchasing/suppliers">

                <div class="col-md-5">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" class="form-control" id="search" name="search"
                        value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, code, contact, email...">
                </div>

                <div class="col-md-3">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-control" id="status" name="status">
                        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All</option>
                    </select>
                </div>

                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search me-2"></i>Search
                    </button>
                </div>

                <div class="col-md-2 d-flex align-items-end">
                    <a href="?page=purchasing/suppliers" class="btn btn-secondary w-100">
                        <i class="fas fa-times me-2"></i>Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Suppliers Table -->
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-list me-2"></i>Supplier List
            </h6>
            <span class="text-muted small">Total: <?php echo count($suppliers); ?> suppliers</span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="suppliersTable">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Supplier Name</th>
                            <th>Contact Person</th>
                            <th>Phone</th>
                            <th>Email</th>
                            <th>Payment Terms</th>
                            <th class="text-end">Balance</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($suppliers)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-4">
                                    <div class="text-muted">
                                        <i class="fas fa-truck fa-3x mb-3"></i>
                                        <p class="mb-0">No suppliers found</p>
                                        <p class="small mt-2">
                                            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal"
                                                data-bs-target="#addSupplierModal">
                                                <i class="fas fa-plus me-1"></i>Add Your First Supplier
                                            </button>
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($suppliers as $supplier): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($supplier['supplier_code']); ?></strong>
                                    </td>
                                    <td>
                                        <a href="?page=purchasing/supplier-details&id=<?php echo $supplier['id']; ?>"
                                            class="text-decoration-none">
                                            <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars($supplier['contact_person'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($supplier['phone'] ?? '-'); ?></td>
                                    <td>
                                    <?php if ($supplier['email']): ?>
                                        <a href="mailto:<?php echo htmlspecialchars($supplier['email']); ?>">
                                            <?php echo htmlspecialchars($supplier['email']); ?>
                                        </a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($supplier['payment_terms'] ?? '-'); ?></td>
                                    <td class="text-end <?php echo ($supplier['balance'] ?? 0) > 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo format_currency($supplier['balance'] ?? 0); ?>
                                    </td>
                                    <td>
                                    <?php if ($supplier['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="?page=purchasing/supplier-details&id=<?php echo $supplier['id']; ?>"
                                                class="btn btn-outline-primary" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <button type="button" class="btn btn-outline-warning"
                                                onclick="editSupplier(<?php echo htmlspecialchars(json_encode($supplier)); ?>)"
                                                title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="?page=purchasing/suppliers&toggle_active=1&id=<?php echo $supplier['id']; ?>&status=<?php echo urlencode($status); ?>&search=<?php echo urlencode($search); ?>"
                                                class="btn btn-outline-<?php echo $supplier['is_active'] ? 'secondary' : 'success'; ?>"
                                                title="<?php echo $supplier['is_active'] ? 'Deactivate' : 'Activate'; ?>"
                                                onclick="return confirm('<?php echo $supplier['is_active'] ? 'Deactivate' : 'Activate'; ?> supplier <?php echo htmlspecialchars(addslashes($supplier['supplier_name'])); ?>?')">
                                                <i class="fas fa-<?php echo $supplier['is_active'] ? 'ban' : 'check'; ?>"></i>
                                            </a>
                                            <?php if (in_array($userRole, ['ADM', 'MGR'])): ?>
                                                <a href="?page=purchasing/suppliers&delete=<?php echo $supplier['id']; ?>&status=<?php echo urlencode($status); ?>&search=<?php echo urlencode($search); ?>"
                                                    class="btn btn-outline-danger"
                                                    onclick="return confirm('Delete <?php echo htmlspecialchars(addslashes($supplier['supplier_name'])); ?>? This action cannot be undone if no purchase orders exist.')"
                                                    title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                <?php endforeach; ?>
                            <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add Supplier Modal -->
<div class="modal fade" id="addSupplierModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-user-plus me-2"></i>Add New Supplier
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="?page=purchasing/save-supplier" id="addSupplierForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="company_id" value="<?php echo $companyId; ?>">

                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="supplier_name" class="form-label">Supplier Name <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="supplier_name" name="supplier_name" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="supplier_code" class="form-label">Supplier Code</label>
                            <input type="text" class="form-control" id="supplier_code" name="supplier_code"
                                value="<?php echo htmlspecialchars($supplierModel->generateCode()); ?>" readonly>
                            <small class="text-muted">Auto-generated for your company</small>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="contact_person" class="form-label">Contact Person</label>
                            <input type="text" class="form-control" id="contact_person" name="contact_person">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="tel" class="form-control" id="phone" name="phone">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="tax_id" class="form-label">Tax ID / VAT Number</label>
                            <input type="text" class="form-control" id="tax_id" name="tax_id">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="address" class="form-label">Address</label>
                        <textarea class="form-control" id="address" name="address" rows="2"></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="payment_terms" class="form-label">Payment Terms</label>
                            <select class="form-control" id="payment_terms" name="payment_terms">
                                <option value="">Select Terms</option>
                                <option value="Immediate">Immediate</option>
                                <option value="Net 15">Net 15</option>
                                <option value="Net 30">Net 30</option>
                                <option value="Net 45">Net 45</option>
                                <option value="Net 60">Net 60</option>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="initial_balance" class="form-label">Initial Balance</label>
                            <div class="input-group">
                                <span class="input-group-text">RWF</span>
                                <input type="number" class="form-control" id="initial_balance" name="initial_balance"
                                    min="0" step="0.01" value="0">
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="is_active" name="is_active" value="1"
                                checked>
                            <label class="form-check-label" for="is_active">Active Supplier</label>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Save Supplier
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Supplier Modal -->
<div class="modal fade" id="editSupplierModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="fas fa-edit me-2"></i>Edit Supplier
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="?page=purchasing/update-supplier" id="editSupplierForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="company_id" value="<?php echo $companyId; ?>">
                <input type="hidden" name="supplier_id" id="edit_supplier_id">

                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_supplier_name" class="form-label">Supplier Name <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_supplier_name" name="supplier_name"
                                required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="edit_supplier_code" class="form-label">Supplier Code</label>
                            <input type="text" class="form-control" id="edit_supplier_code" name="supplier_code"
                                readonly>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_contact_person" class="form-label">Contact Person</label>
                            <input type="text" class="form-control" id="edit_contact_person" name="contact_person">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="edit_phone" class="form-label">Phone</label>
                            <input type="tel" class="form-control" id="edit_phone" name="phone">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="edit_email" name="email">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="edit_tax_id" class="form-label">Tax ID / VAT Number</label>
                            <input type="text" class="form-control" id="edit_tax_id" name="tax_id">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="edit_address" class="form-label">Address</label>
                        <textarea class="form-control" id="edit_address" name="address" rows="2"></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_payment_terms" class="form-label">Payment Terms</label>
                            <select class="form-control" id="edit_payment_terms" name="payment_terms">
                                <option value="">Select Terms</option>
                                <option value="Immediate">Immediate</option>
                                <option value="Net 15">Net 15</option>
                                <option value="Net 30">Net 30</option>
                                <option value="Net 45">Net 45</option>
                                <option value="Net 60">Net 60</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save me-2"></i>Update Supplier
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const table_id = '#suppliersTable';
    const target_cols = 7;

    function viewSupplier(id) {
        window.location.href = '?page=purchasing/supplier-details&id=' + id;
    }

    function editSupplier(supplier) {
        // Populate edit modal with supplier data
        document.getElementById('edit_supplier_id').value = supplier.id;
        document.getElementById('edit_supplier_name').value = supplier.supplier_name;
        document.getElementById('edit_supplier_code').value = supplier.supplier_code;
        document.getElementById('edit_contact_person').value = supplier.contact_person || '';
        document.getElementById('edit_phone').value = supplier.phone || '';
        document.getElementById('edit_email').value = supplier.email || '';
        document.getElementById('edit_tax_id').value = supplier.tax_id || '';
        document.getElementById('edit_address').value = supplier.address || '';
        document.getElementById('edit_payment_terms').value = supplier.payment_terms || '';

        // Show the modal
        const modal = new bootstrap.Modal(document.getElementById('editSupplierModal'));
        modal.show();
    }
</script>

<?php
$jsFiles = ['purchasing/orders.js'];
?>

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

    .table td {
        vertical-align: middle;
    }

    .btn-group {
        gap: 2px;
    }
</style>