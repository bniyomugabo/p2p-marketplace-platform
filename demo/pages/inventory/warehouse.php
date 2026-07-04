<?php
// pages/inventory/warehouse.php
declare(strict_types=1);

$pageTitle = 'Warehouse Management - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';

// Load models
require_once __DIR__ . '/../../models/Warehouse.php';
require_once __DIR__ . '/../../models/Location.php';
require_once __DIR__ . '/../../models/Inventory.php';

$db = Database::getInstance();
$userId = $_SESSION['user_id'] ?? 0;
$userRole = $_SESSION['user_role'] ?? 'VIW';
$companyId = $_SESSION['company_id'] ?? null;

// Check permission
if (!in_array($userRole, ['ADM', 'MGR'])) {
    SessionManager::flash('error', 'You do not have permission to manage warehouses.');
    header('Location: ?page=inventory/dashboard');
    exit;
}

// Initialize models with company context
$warehouseModel = new Warehouse($companyId);
$locationModel = new Location($companyId);
$inventoryModel = new Inventory($companyId);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
        SessionManager::flash('error', 'Invalid security token. Please try again.');
        header('Location: ?page=inventory/warehouse');
        exit;
    }

    // Add warehouse
    if (isset($_POST['add_warehouse'])) {
        try {
            // Validate
            if (empty($_POST['warehouse_name'])) {
                throw new Exception('Warehouse name is required');
            }

            $data = [
                'company_id' => $companyId,
                'warehouse_code' => !empty($_POST['warehouse_code']) ? trim($_POST['warehouse_code']) : $warehouseModel->generateCode(),
                'warehouse_name' => trim($_POST['warehouse_name']),
                'address' => trim($_POST['address'] ?? ''),
                'city' => trim($_POST['city'] ?? ''),
                'contact_person' => trim($_POST['contact_person'] ?? ''),
                'phone' => trim($_POST['phone'] ?? ''),
                'is_main' => isset($_POST['is_main']) ? 1 : 0,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s')
            ];

            // If this is main warehouse, unset other main warehouses for this company
            if ($data['is_main']) {
                $stmt = $db->prepare("UPDATE warehouses SET is_main = 0 WHERE company_id = :company_id AND is_main = 1");
                $stmt->execute(['company_id' => $companyId]);
            }

            $warehouseModel->create($data);
            SessionManager::flash('success', 'Warehouse added successfully!');

        } catch (Exception $e) {
            SessionManager::flash('error', 'Failed to add warehouse: ' . $e->getMessage());
        }
        
        header('Location: ?page=inventory/warehouse');
        exit;
    }

    // Edit warehouse
    if (isset($_POST['edit_warehouse'])) {
        try {
            $id = (int)$_POST['warehouse_id'];
            
            if (empty($_POST['warehouse_name'])) {
                throw new Exception('Warehouse name is required');
            }

            $data = [
                'warehouse_name' => trim($_POST['warehouse_name']),
                'address' => trim($_POST['address'] ?? ''),
                'city' => trim($_POST['city'] ?? ''),
                'contact_person' => trim($_POST['contact_person'] ?? ''),
                'phone' => trim($_POST['phone'] ?? ''),
                'is_main' => isset($_POST['is_main']) ? 1 : 0,
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            // If this is main warehouse, unset other main warehouses for this company
            if ($data['is_main']) {
                $stmt = $db->prepare("UPDATE warehouses SET is_main = 0 WHERE company_id = :company_id AND is_main = 1 AND id != :id");
                $stmt->execute(['company_id' => $companyId, 'id' => $id]);
            }

            $warehouseModel->update($id, $data);
            SessionManager::flash('success', 'Warehouse updated successfully!');

        } catch (Exception $e) {
            SessionManager::flash('error', 'Failed to update warehouse: ' . $e->getMessage());
        }
        
        header('Location: ?page=inventory/warehouse');
        exit;
    }

    // Delete warehouse
    if (isset($_POST['delete_warehouse'])) {
        try {
            $id = (int)$_POST['warehouse_id'];
            
            // Check if warehouse has inventory
            $sql = "SELECT COUNT(*) as count FROM inventory WHERE warehouse_id = :id AND company_id = :company_id AND quantity > 0";
            $stmt = $db->prepare($sql);
            $stmt->execute(['id' => $id, 'company_id' => $companyId]);
            $result = $stmt->fetch();
            
            if ($result['count'] > 0) {
                throw new Exception('Cannot delete warehouse with existing stock. Transfer or remove stock first.');
            }

            // Check if warehouse has locations
            $sql = "SELECT COUNT(*) as count FROM locations WHERE warehouse_id = :id AND company_id = :company_id";
            $stmt = $db->prepare($sql);
            $stmt->execute(['id' => $id, 'company_id' => $companyId]);
            $result = $stmt->fetch();
            
            if ($result['count'] > 0) {
                throw new Exception('Please delete all locations in this warehouse first.');
            }

            // Check if this is main warehouse
            $warehouse = $warehouseModel->find($id);
            if ($warehouse && $warehouse['is_main']) {
                throw new Exception('Cannot delete the main warehouse. Set another warehouse as main first.');
            }

            $warehouseModel->delete($id, false); // Hard delete
            SessionManager::flash('success', 'Warehouse deleted successfully!');

        } catch (Exception $e) {
            SessionManager::flash('error', 'Failed to delete warehouse: ' . $e->getMessage());
        }
        
        header('Location: ?page=inventory/warehouse');
        exit;
    }
}

// Get all warehouses with inventory summary (company-specific)
$warehouses = $warehouseModel->getAllWithInventoryValue();

// Get warehouse statistics
$warehouseStats = [
    'total' => count($warehouses),
    'main' => 0,
    'active' => 0,
    'total_value' => 0,
    'total_products' => 0
];

foreach ($warehouses as $wh) {
    if ($wh['is_main']) $warehouseStats['main']++;
    if ($wh['is_active']) $warehouseStats['active']++;
    $warehouseStats['total_value'] += $wh['total_value'] ?? 0;
    $warehouseStats['total_products'] += $wh['product_count'] ?? 0;
}

// Generate CSRF token
$csrfToken = CSRF::generate();
?>

<div class="warehouse-management">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-warehouse me-2"></i>Warehouse Management
                    </h2>
                    <p class="mb-0 text-muted">Manage warehouses and storage facilities</p>
                </div>
                <div>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addWarehouseModal">
                        <i class="fas fa-plus-circle me-2"></i>Add Warehouse
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if ($flash = SessionManager::flash('success')): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($flash); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php elseif ($flash = SessionManager::flash('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($flash); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Total Warehouses
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo $warehouseStats['total']; ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-warehouse fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Active Warehouses
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo $warehouseStats['active']; ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-check-circle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                Total Products
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float)$warehouseStats['total_products']); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-boxes fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                Total Inventory Value
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo format_currency($warehouseStats['total_value'], $companyId); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-dollar-sign fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Warehouses List -->
    <div class="row">
        <?php if (empty($warehouses)): ?>
            <div class="col-12">
                <div class="card shadow">
                    <div class="card-body text-center py-5">
                        <div class="text-muted">
                            <i class="fas fa-warehouse fa-4x mb-3"></i>
                            <p class="h5 mb-2">No warehouses found</p>
                            <p class="mb-0">Click "Add Warehouse" to create your first warehouse.</p>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($warehouses as $warehouse): ?>
                <div class="col-xl-4 col-md-6 mb-4">
                    <div class="card shadow h-100 <?php echo $warehouse['is_main'] ? 'border-primary' : ''; ?>">
                        <div class="card-header py-3 d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold <?php echo $warehouse['is_main'] ? 'text-primary' : 'text-primary'; ?>">
                                <i class="fas fa-warehouse me-2"></i><?php echo htmlspecialchars($warehouse['warehouse_name']); ?>
                                <?php if ($warehouse['is_main']): ?>
                                    <span class="badge bg-primary ms-2">Main</span>
                                <?php endif; ?>
                                <?php if (!$warehouse['is_active']): ?>
                                    <span class="badge bg-secondary ms-2">Inactive</span>
                                <?php endif; ?>
                            </h6>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                    <i class="fas fa-cog"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <button class="dropdown-item" onclick="editWarehouse(<?php echo htmlspecialchars(json_encode($warehouse)); ?>)">
                                            <i class="fas fa-edit me-2"></i>Edit
                                        </button>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="?page=inventory/storage-locations&warehouse=<?php echo $warehouse['id']; ?>">
                                            <i class="fas fa-map-marker-alt me-2"></i>View Locations
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="?page=inventory/stock&warehouse=<?php echo $warehouse['id']; ?>">
                                            <i class="fas fa-boxes me-2"></i>View Stock
                                        </a>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <button class="dropdown-item text-danger" onclick="deleteWarehouse(<?php echo $warehouse['id']; ?>, '<?php echo htmlspecialchars($warehouse['warehouse_name']); ?>')">
                                            <i class="fas fa-trash me-2"></i>Delete
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <strong>Code:</strong> <?php echo htmlspecialchars($warehouse['warehouse_code']); ?><br>
                                <?php if ($warehouse['city']): ?>
                                    <strong>City:</strong> <?php echo htmlspecialchars($warehouse['city']); ?><br>
                                <?php endif; ?>
                                <?php if ($warehouse['address']): ?>
                                    <strong>Address:</strong> <?php echo htmlspecialchars(substr($warehouse['address'], 0, 50)) . (strlen($warehouse['address']) > 50 ? '...' : ''); ?><br>
                                <?php endif; ?>
                                <?php if ($warehouse['contact_person']): ?>
                                    <strong>Contact:</strong> <?php echo htmlspecialchars($warehouse['contact_person']); ?><br>
                                <?php endif; ?>
                                <?php if ($warehouse['phone']): ?>
                                    <strong>Phone:</strong> <?php echo htmlspecialchars($warehouse['phone']); ?>
                                <?php endif; ?>
                            </div>

                            <div class="row text-center">
                                <div class="col-6">
                                    <h5 class="fw-bold mb-0"><?php echo number_format((float)($warehouse['product_count'] ?? 0)); ?></h5>
                                    <small class="text-muted">Products</small>
                                </div>
                                <div class="col-6">
                                    <h5 class="fw-bold mb-0"><?php echo number_format((float)($warehouse['total_quantity'] ?? 0)); ?></h5>
                                    <small class="text-muted">Units</small>
                                </div>
                            </div>

                            <div class="mt-3">
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Inventory Value</span>
                                    <span class="fw-bold"><?php echo format_currency($warehouse['total_value'] ?? 0, $companyId); ?></span>
                                </div>
                                <div class="progress" style="height: 5px;">
                                    <?php
                                    $percentage = $warehouseStats['total_value'] > 0 
                                        ? (($warehouse['total_value'] ?? 0) / $warehouseStats['total_value']) * 100 
                                        : 0;
                                    ?>
                                    <div class="progress-bar bg-info" style="width: <?php echo $percentage; ?>%"></div>
                                </div>
                            </div>

                            <div class="mt-3 d-flex justify-content-between align-items-center">
                                <small class="text-muted">Created: <?php echo format_date($warehouse['created_at'], 'd/m/Y'); ?></small>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Add Warehouse Modal -->
<div class="modal fade" id="addWarehouseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle me-2 text-primary"></i>Add New Warehouse
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="add_warehouse" value="1">
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="warehouse_code" class="form-label">Warehouse Code</label>
                        <input type="text" class="form-control bg-light" id="warehouse_code" name="warehouse_code" 
                               value="<?php echo $warehouseModel->generateCode(); ?>" readonly>
                        <div class="form-text">Auto-generated code</div>
                    </div>

                    <div class="mb-3">
                        <label for="warehouse_name" class="form-label">Warehouse Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="warehouse_name" name="warehouse_name" required>
                    </div>

                    <div class="mb-3">
                        <label for="address" class="form-label">Address</label>
                        <textarea class="form-control" id="address" name="address" rows="2" placeholder="Street address, building, etc."></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="city" class="form-label">City</label>
                            <input type="text" class="form-control" id="city" name="city" placeholder="e.g., Kigali">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="text" class="form-control" id="phone" name="phone" placeholder="e.g., +250 788 123 456">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="contact_person" class="form-label">Contact Person</label>
                        <input type="text" class="form-control" id="contact_person" name="contact_person" placeholder="Name of warehouse manager">
                    </div>

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_main" name="is_main" value="1">
                        <label class="form-check-label" for="is_main">
                            Set as Main Warehouse
                        </label>
                        <div class="form-text">Only one warehouse can be set as main. The main warehouse is used as default.</div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Warehouse</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Warehouse Modal -->
<div class="modal fade" id="editWarehouseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-edit me-2 text-warning"></i>Edit Warehouse
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="edit_warehouse" value="1">
                <input type="hidden" name="warehouse_id" id="edit_warehouse_id">
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Warehouse Code</label>
                        <input type="text" class="form-control bg-light" id="edit_warehouse_code" readonly disabled>
                    </div>

                    <div class="mb-3">
                        <label for="edit_warehouse_name" class="form-label">Warehouse Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_warehouse_name" name="warehouse_name" required>
                    </div>

                    <div class="mb-3">
                        <label for="edit_address" class="form-label">Address</label>
                        <textarea class="form-control" id="edit_address" name="address" rows="2" placeholder="Street address, building, etc."></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_city" class="form-label">City</label>
                            <input type="text" class="form-control" id="edit_city" name="city" placeholder="e.g., Kigali">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_phone" class="form-label">Phone</label>
                            <input type="text" class="form-control" id="edit_phone" name="phone" placeholder="e.g., +250 788 123 456">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="edit_contact_person" class="form-label">Contact Person</label>
                        <input type="text" class="form-control" id="edit_contact_person" name="contact_person" placeholder="Name of warehouse manager">
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="edit_is_main" name="is_main" value="1">
                        <label class="form-check-label" for="edit_is_main">
                            Set as Main Warehouse
                        </label>
                    </div>

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active" value="1">
                        <label class="form-check-label" for="edit_is_active">
                            Active
                        </label>
                        <div class="form-text">Inactive warehouses won't appear in stock allocation forms</div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Update Warehouse</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>Delete Warehouse
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="delete_warehouse" value="1">
                <input type="hidden" name="warehouse_id" id="delete_warehouse_id">
                
                <div class="modal-body">
                    <p>Are you sure you want to delete <strong id="deleteWarehouseName"></strong>?</p>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Warning:</strong> This action:
                        <ul class="mb-0 mt-2">
                            <li>Cannot be undone</li>
                            <li>Requires the warehouse to have no stock</li>
                            <li>Requires all locations in this warehouse to be deleted first</li>
                            <li>Cannot delete the main warehouse</li>
                        </ul>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Warehouse</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function editWarehouse(warehouse) {
        document.getElementById('edit_warehouse_id').value = warehouse.id;
        document.getElementById('edit_warehouse_code').value = warehouse.warehouse_code;
        document.getElementById('edit_warehouse_name').value = warehouse.warehouse_name;
        document.getElementById('edit_address').value = warehouse.address || '';
        document.getElementById('edit_city').value = warehouse.city || '';
        document.getElementById('edit_phone').value = warehouse.phone || '';
        document.getElementById('edit_contact_person').value = warehouse.contact_person || '';
        document.getElementById('edit_is_main').checked = warehouse.is_main == 1;
        document.getElementById('edit_is_active').checked = warehouse.is_active == 1;
        
        new bootstrap.Modal(document.getElementById('editWarehouseModal')).show();
    }

    function deleteWarehouse(id, name) {
        document.getElementById('delete_warehouse_id').value = id;
        document.getElementById('deleteWarehouseName').textContent = name;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }
</script>

<style>
    .warehouse-management .border-left-primary {
        border-left: 4px solid #4e73df !important;
    }
    .warehouse-management .border-left-success {
        border-left: 4px solid #1cc88a !important;
    }
    .warehouse-management .border-left-info {
        border-left: 4px solid #36b9cc !important;
    }
    .warehouse-management .border-left-warning {
        border-left: 4px solid #f6c23e !important;
    }
    .warehouse-management .card {
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .warehouse-management .card:hover {
        transform: translateY(-4px);
        box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.1) !important;
    }
    .warehouse-management .border-primary {
        border: 2px solid #4e73df !important;
    }
    .warehouse-management .progress {
        border-radius: 10px;
        background-color: #e9ecef;
    }
    .warehouse-management .dropdown-menu {
        font-size: 0.875rem;
    }
    .warehouse-management .form-text {
        font-size: 0.75rem;
    }
</style>