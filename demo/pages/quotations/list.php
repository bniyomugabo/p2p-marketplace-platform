<?php
// pages/quotations/list.php
declare(strict_types=1);

$pageTitle = 'Quotations - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Quotation.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR', 'SEL'])) {
    SessionManager::flash('error', 'You do not have permission to view quotations.');
    header('Location: ' . route_url('dashboard'));
    exit;
}

// Check if company context is set
if (!$companyId) {
    SessionManager::flash('error', 'Company context not found. Please log in again.');
    header('Location: ' . route_url('login'));
    exit;
}

// Initialize model with company ID
$quotationModel = new Quotation($companyId);

// Get filter parameters
$status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

// Get quotations with company filtering
if (!empty($search)) {
    $quotations = $quotationModel->search($search);
} elseif (!empty($status)) {
    $quotations = $quotationModel->getByStatus($status);
} else {
    $quotations = $quotationModel->getRecent(50);
}

// Get summary using model (already company-filtered)
$summary = $quotationModel->getSummary();

// Mark expired quotations if needed (run on page load)
if (in_array($userRole, ['ADM', 'MGR'])) {
    $quotationModel->markExpired();
}
?>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-file-contract me-2"></i>Quotations (Proforma)
                    </h2>
                    <p class="mb-0 text-muted">Manage customer quotations and proforma invoices for your company</p>
                </div>
                <div>
                    <a href="?page=quotations/create" class="btn btn-primary">
                        <i class="fas fa-plus-circle me-2"></i>New Quotation
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if ($flash = SessionManager::flash('success')): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo htmlspecialchars($flash); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($flash = SessionManager::flash('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo htmlspecialchars($flash); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Total
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) ($summary['total_quotations'] ?? 0)); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-file-contract fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Draft
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) ($summary['draft_count'] ?? 0)); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-pen fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                Sent
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) ($summary['sent_count'] ?? 0)); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-paper-plane fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Accepted
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float) ($summary['accepted_count'] ?? 0)); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-check-circle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-danger shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">
                                Pending Value
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo format_currency($summary['pending_total'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-clock fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-2 col-md-4 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Won Value
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo format_currency($summary['accepted_total'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-trophy fa-2x text-gray-300"></i>
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
                <i class="fas fa-filter me-2"></i>Filter Quotations
            </h6>
        </div>
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <input type="hidden" name="page" value="quotations">

                <div class="col-md-4">
                    <label for="search" class="form-label">Search</label>
                    <input type="text" class="form-control" id="search" name="search"
                        value="<?php echo htmlspecialchars($search); ?>" placeholder="Number, customer name, email...">
                </div>

                <div class="col-md-3">
                    <label for="status" class="form-label">Status</label>
                    <select class="form-control" id="status" name="status">
                        <option value="">All Status</option>
                        <option value="draft" <?php echo $status === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="sent" <?php echo $status === 'sent' ? 'selected' : ''; ?>>Sent</option>
                        <option value="accepted" <?php echo $status === 'accepted' ? 'selected' : ''; ?>>Accepted</option>
                        <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="expired" <?php echo $status === 'expired' ? 'selected' : ''; ?>>Expired</option>
                    </select>
                </div>

                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search me-2"></i>Filter
                    </button>
                </div>

                <div class="col-md-2 d-flex align-items-end">
                    <a href="?page=quotations" class="btn btn-secondary w-100">
                        <i class="fas fa-times me-2"></i>Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Quotations Table -->
    <div class="card shadow mb-4">
        <div class="card-header py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-list me-2"></i>Quotations List
            </h6>
            <span class="text-muted small">Total: <?php echo count($quotations); ?> quotations</span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="quotationsTable">
                    <thead class="table-light">
                        <tr>
                            <th>Quotation #</th>
                            <th>Date</th>
                            <th>Valid Until</th>
                            <th>Customer</th>
                            <th>Items</th>
                            <th class="text-end">Total</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($quotations)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5">
                                    <div class="text-muted">
                                        <i class="fas fa-file-contract fa-4x mb-3"></i>
                                        <p class="mb-0">No quotations found</p>
                                        <p class="small mt-2">
                                            <a href="?page=quotations/create" class="btn btn-sm btn-primary mt-2">
                                                <i class="fas fa-plus me-1"></i>Create Your First Quotation
                                            </a>
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($quotations as $q): ?>
                                <?php
                                $isExpired = strtotime((string)$q['valid_until']) < time() && in_array($q['status'], ['draft', 'sent']);
                                ?>
                                <tr class="<?php echo $isExpired ? 'table-warning' : ''; ?>">
                                    <td>
                                        <strong>
                                            <a href="?page=quotations/view&id=<?php echo $q['id']; ?>"
                                                class="text-decoration-none">
                                                <?php echo htmlspecialchars($q['quotation_number']); ?>
                                            </a>
                                        </strong>
                                    </td>
                                    <td>
                                        <?php echo date('d/m/Y', strtotime((string)$q['quotation_date'])); ?>
                                    </td>
                                    <td>
                                        <?php echo date('d/m/Y', strtotime((string)$q['valid_until'])); ?>
                                        <?php if ($isExpired): ?>
                                            <span class="badge bg-danger ms-1" title="Expired">!</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($q['customer_name']); ?></strong>
                                        <?php if (!empty($q['customer_phone'])): ?>
                                            <br><small
                                                class="text-muted"><?php echo htmlspecialchars($q['customer_phone']); ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($q['customer_email'])): ?>
                                            <br><small><?php echo htmlspecialchars($q['customer_email']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-secondary"><?php echo $q['item_count'] ?? 0; ?> items</span>
                                    </td>
                                    <td class="text-end">
                                        <strong><?php echo format_currency($q['total_amount']); ?></strong>
                                    </td>
                                    <td>
                                        <?php
                                        $statusClass = [
                                            'draft' => 'secondary',
                                            'sent' => 'info',
                                            'accepted' => 'success',
                                            'rejected' => 'danger',
                                            'expired' => 'warning'
                                        ][$q['status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $statusClass; ?>">
                                            <?php echo ucfirst($q['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="?page=quotations/view&id=<?php echo $q['id']; ?>"
                                                class="btn btn-outline-primary" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="?page=quotations/print&id=<?php echo $q['id']; ?>"
                                                class="btn btn-outline-info" title="Print" target="_blank">
                                                <i class="fas fa-print"></i>
                                            </a>
                                            <?php if ($q['status'] === 'draft'): ?>
                                                <a href="?page=quotations/edit&id=<?php echo $q['id']; ?>"
                                                    class="btn btn-outline-warning" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($q['status'] === 'sent'): ?>
                                                <button type="button" class="btn btn-outline-success"
                                                    onclick="updateStatus(<?php echo $q['id']; ?>, 'accepted')"
                                                    title="Mark as Accepted">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-danger"
                                                    onclick="updateStatus(<?php echo $q['id']; ?>, 'rejected')"
                                                    title="Mark as Rejected">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($q['status'] === 'accepted'): ?>
                                                <a href="?page=sales/create&quotation_id=<?php echo $q['id']; ?>"
                                                    class="btn btn-outline-success" title="Convert to Invoice">
                                                    <i class="fas fa-file-invoice"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if (in_array($userRole, ['ADM', 'MGR']) && $q['status'] === 'draft'): ?>
                                                <a href="?page=quotations/delete&id=<?php echo $q['id']; ?>"
                                                    class="btn btn-outline-danger"
                                                    onclick="return confirm('Delete quotation <?php echo htmlspecialchars(addslashes($q['quotation_number'])); ?>? This action cannot be undone.')"
                                                    title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </a>
                                            <?php endif; ?>
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
</div>

<script>
    const table_id = '#quotationsTable';
    const target_cols = 6;

    function updateStatus(quotationId, status) {
        const confirmMsg = status === 'accepted'
            ? 'Mark this quotation as accepted? This will convert it to a won deal.'
            : 'Mark this quotation as rejected? This will close it as lost.';

        if (confirm(confirmMsg)) {
            fetch('?page=quotations/update-status', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'id=' + quotationId + '&status=' + status + '&csrf_token=<?php echo CSRF::generate(); ?>'
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to update status');
                });
        }
    }
</script>

<?php
$jsFiles = ['quotations/list.js'];
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

    .border-left-warning {
        border-left: 4px solid #f6c23e !important;
    }

    .border-left-danger {
        border-left: 4px solid #e74a3b !important;
    }

    .table td {
        vertical-align: middle;
    }

    .btn-group {
        gap: 2px;
    }

    .table-warning {
        background-color: #fff3cd !important;
    }
</style>