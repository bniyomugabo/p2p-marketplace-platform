<?php
// pages/inventory/storage-locations.php
declare(strict_types=1);

$pageTitle = 'Storage Locations - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';

// Load models
require_once __DIR__ . '/../../models/Location.php';
require_once __DIR__ . '/../../models/Warehouse.php';
require_once __DIR__ . '/../../models/Inventory.php';

$db = Database::getInstance();
$userId = $_SESSION['user_id'] ?? 0;
$userRole = $_SESSION['user_role'] ?? 'VIW';
$companyId = $_SESSION['company_id'] ?? null;

// Check permission
if (!in_array($userRole, ['ADM', 'MGR'])) {
    SessionManager::flash('error', 'You do not have permission to manage storage locations.');
    header('Location: ?page=inventory/dashboard');
    exit;
}

// Initialize models with company context
$locationModel = new Location($companyId);
$warehouseModel = new Warehouse($companyId);
$inventoryModel = new Inventory($companyId);

// Get filter parameter
$warehouseId = isset($_GET['warehouse']) ? (int) $_GET['warehouse'] : null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
        SessionManager::flash('error', 'Invalid security token. Please try again.');
        header('Location: ?page=inventory/storage-locations' . ($warehouseId ? '&warehouse=' . $warehouseId : ''));
        exit;
    }

    // Add location
    if (isset($_POST['add_location'])) {
        try {
            // Validate
            if (empty($_POST['location_code'])) {
                throw new Exception('Location code is required');
            }
            if (empty($_POST['warehouse_id'])) {
                throw new Exception('Warehouse is required');
            }

            // Verify warehouse belongs to this company
            $warehouse = $warehouseModel->find((int) $_POST['warehouse_id']);
            if (!$warehouse) {
                throw new Exception('Selected warehouse not found or not accessible');
            }

            // Check if code already exists in this warehouse
            $existing = $locationModel->findByCode($_POST['location_code'], $_POST['warehouse_id']);
            if ($existing) {
                throw new Exception('Location code already exists in this warehouse');
            }

            $data = [
                'company_id' => $companyId,
                'warehouse_id' => (int) $_POST['warehouse_id'],
                'location_code' => strtoupper(trim($_POST['location_code'])),
                'location_name' => trim($_POST['location_name'] ?? ''),
                'location_type' => $_POST['location_type'] ?? 'shelf',
                'parent_location_id' => !empty($_POST['parent_location_id']) ? (int) $_POST['parent_location_id'] : null,
                'capacity' => !empty($_POST['capacity']) ? (float) $_POST['capacity'] : null,
                'notes' => trim($_POST['notes'] ?? ''),
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s')
            ];

            $locationModel->create($data);
            SessionManager::flash('success', 'Storage location added successfully!');

        } catch (Exception $e) {
            SessionManager::flash('error', 'Failed to add location: ' . $e->getMessage());
        }

        header('Location: ?page=inventory/storage-locations' . ($warehouseId ? '&warehouse=' . $warehouseId : ''));
        exit;
    }

    // Edit location
    if (isset($_POST['edit_location'])) {
        try {
            $id = (int) $_POST['location_id'];

            if (empty($_POST['location_code'])) {
                throw new Exception('Location code is required');
            }

            // Verify location belongs to this company
            $location = $locationModel->find($id);
            if (!$location) {
                throw new Exception('Location not found or not accessible');
            }

            // Check if code already exists (excluding this location)
            $sql = "SELECT id FROM locations 
                    WHERE warehouse_id = :warehouse_id 
                        AND location_code = :code 
                        AND id != :id 
                        AND company_id = :company_id";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                'warehouse_id' => (int) $_POST['warehouse_id'],
                'code' => strtoupper(trim($_POST['location_code'])),
                'id' => $id,
                'company_id' => $companyId
            ]);
            if ($stmt->fetch()) {
                throw new Exception('Location code already exists in this warehouse');
            }

            $data = [
                'location_code' => strtoupper(trim($_POST['location_code'])),
                'location_name' => trim($_POST['location_name'] ?? ''),
                'location_type' => $_POST['location_type'] ?? 'shelf',
                'parent_location_id' => !empty($_POST['parent_location_id']) ? (int) $_POST['parent_location_id'] : null,
                'capacity' => !empty($_POST['capacity']) ? (float) $_POST['capacity'] : null,
                'notes' => trim($_POST['notes'] ?? ''),
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            $locationModel->update($id, $data);
            SessionManager::flash('success', 'Storage location updated successfully!');

        } catch (Exception $e) {
            SessionManager::flash('error', 'Failed to update location: ' . $e->getMessage());
        }

        header('Location: ?page=inventory/storage-locations' . ($warehouseId ? '&warehouse=' . $warehouseId : ''));
        exit;
    }

    // Delete location
    if (isset($_POST['delete_location'])) {
        try {
            $id = (int) $_POST['location_id'];

            // Verify location belongs to this company
            $location = $locationModel->find($id);
            if (!$location) {
                throw new Exception('Location not found or not accessible');
            }

            // Check if location has inventory
            $sql = "SELECT COUNT(*) as count FROM inventory 
                    WHERE location_id = :id AND company_id = :company_id AND quantity > 0";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                'id' => $id,
                'company_id' => $companyId
            ]);
            $result = $stmt->fetch();

            if ($result['count'] > 0) {
                throw new Exception('Cannot delete location with existing stock. Move stock first.');
            }

            // Check if location has child locations
            $sql = "SELECT COUNT(*) as count FROM locations 
                    WHERE parent_location_id = :id AND company_id = :company_id";
            $stmt = $db->prepare($sql);
            $stmt->execute([
                'id' => $id,
                'company_id' => $companyId
            ]);
            $result = $stmt->fetch();

            if ($result['count'] > 0) {
                throw new Exception('Please delete child locations first.');
            }

            $locationModel->delete($id, false); // Hard delete
            SessionManager::flash('success', 'Location deleted successfully!');

        } catch (Exception $e) {
            SessionManager::flash('error', 'Failed to delete location: ' . $e->getMessage());
        }

        header('Location: ?page=inventory/storage-locations' . ($warehouseId ? '&warehouse=' . $warehouseId : ''));
        exit;
    }
}

// Get warehouses for filter and dropdown (only this company's warehouses)
$warehouses = $warehouseModel->all(['id', 'warehouse_name'], 'is_active = 1');

// Get locations with hierarchy (company-specific)
if ($warehouseId) {
    $locations = $locationModel->getHierarchy($warehouseId);
    $currentWarehouse = $warehouseModel->find($warehouseId);
} else {
    $locations = $locationModel->getHierarchy();
    $currentWarehouse = null;
}

// Get all locations flat for parent selection (company-specific)
$allLocations = $locationModel->getAllWithWarehouse();

// Get location statistics (company-specific)
$locationStats = $locationModel->getStats($warehouseId);

// Generate CSRF token
$csrfToken = CSRF::generate();
?>

<div class="storage-locations">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <a href="?page=inventory/warehouse" class="btn btn-outline-secondary btn-sm mb-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Warehouses
                    </a>
                    <h2 class="h4 mb-1 text-gray-800">
                        <i class="fas fa-map-marker-alt me-2"></i>Storage Locations
                    </h2>
                    <p class="mb-0 text-muted">
                        <?php if ($currentWarehouse): ?>
                            Managing locations in:
                            <strong><?php echo htmlspecialchars($currentWarehouse['warehouse_name']); ?></strong>
                        <?php else: ?>
                            Manage storage locations across all warehouses
                        <?php endif; ?>
                    </p>
                </div>
                <div>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal"
                        data-bs-target="#addLocationModal">
                        <i class="fas fa-plus-circle me-2"></i>Add Location
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Warehouse Filter -->
    <div class="row mb-4">
        <div class="col-md-4">
            <select class="form-control"
                onchange="window.location.href='?page=inventory/storage-locations&warehouse=' + this.value">
                <option value="">All Warehouses</option>
                <?php foreach ($warehouses as $wh): ?>
                    <option value="<?php echo $wh['id']; ?>" <?php echo $warehouseId == $wh['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($wh['warehouse_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-8 text-end">
            <button class="btn btn-sm btn-outline-primary" onclick="refreshPage()">
                <i class="fas fa-sync-alt me-1"></i> Refresh
            </button>
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
        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                Total Locations
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float)$locationStats['total_locations'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-map-marker-alt fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                Active Locations
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float)$locationStats['active_locations'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-check-circle fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-md-6 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                Warehouses Using
                            </div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800">
                                <?php echo number_format((float)$locationStats['warehouses_using'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-warehouse fa-2x text-gray-300"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Locations Tree -->
    <div class="card shadow">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-sitemap me-2"></i>Location Hierarchy
                <span class="badge bg-primary ms-2"><?php echo count($locations); ?> root locations</span>
            </h6>
        </div>
        <div class="card-body">
            <?php if (empty($locations)): ?>
                <div class="text-center py-5">
                    <div class="text-muted">
                        <i class="fas fa-map-marker-alt fa-4x mb-3"></i>
                        <p class="h5 mb-2">No locations found</p>
                        <p class="mb-0">Create your first storage location to organize inventory</p>
                        <button type="button" class="btn btn-primary mt-3" data-bs-toggle="modal"
                            data-bs-target="#addLocationModal">
                            <i class="fas fa-plus-circle me-2"></i>Add Location
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover" id="locationsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Location</th>
                                <th>Type</th>
                                <th>Warehouse</th>
                                <th>Parent</th>
                                <th>Capacity</th>
                                <th class="text-center">Stock</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (flattenLocations($locations) as $location):
                                // Get stock count for this location
                                $stockCount = $locationModel->getStockCount($location['id']);
                                ?>
                                <tr>
                                    <td>
                                        <?php echo str_repeat('&nbsp;&nbsp;&nbsp;', $location['level']); ?>
                                        <?php if ($location['level'] > 0): ?>
                                            <i class="fas fa-level-down-alt text-muted me-1"></i>
                                        <?php endif; ?>
                                        <code class="fw-bold"><?php echo htmlspecialchars($location['location_code']); ?></code>
                                        <?php if ($location['location_name']): ?>
                                            <br><small
                                                class="text-muted"><?php echo htmlspecialchars($location['location_name']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?php echo ucfirst($location['location_type']); ?></span>
                                    </td>
                                    <td>
                                        <i class="fas fa-building me-1"></i>
                                        <?php echo htmlspecialchars($location['warehouse_name']); ?>
                                    </td>
                                    <td>
                                        <?php if ($location['parent_code']): ?>
                                            <code><?php echo htmlspecialchars($location['parent_code']); ?></code>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($location['capacity']): ?>
                                            <?php echo number_format((float)$location['capacity'], 0); ?> units
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-<?php echo $stockCount > 0 ? 'success' : 'secondary'; ?>">
                                            <?php echo number_format((float)$stockCount); ?> items
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($location['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-info"
                                                onclick="viewLocationDetails(<?php echo htmlspecialchars(json_encode($location)); ?>)"
                                                title="View Details">
                                                <i class="fas fa-info-circle"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-warning"
                                                onclick="editLocation(<?php echo htmlspecialchars(json_encode($location)); ?>)"
                                                title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-danger"
                                                onclick="deleteLocation(<?php echo $location['id']; ?>, '<?php echo htmlspecialchars($location['location_code']); ?>')"
                                                title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Location Details Modal -->
<div class="modal fade" id="viewLocationModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">
                    <i class="fas fa-info-circle me-2"></i>Location Details
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="locationDetailsContent">
                <!-- Dynamic content -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a href="#" id="viewStockBtn" class="btn btn-primary" target="_blank">
                    <i class="fas fa-boxes me-2"></i>View Stock
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Add Location Modal -->
<div class="modal fade" id="addLocationModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle me-2"></i>Add Storage Location
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="add_location" value="1">
                <input type="hidden" name="company_id" value="<?php echo $companyId; ?>">

                <div class="modal-body">
                    <div class="mb-3">
                        <label for="warehouse_id" class="form-label">Warehouse <span
                                class="text-danger">*</span></label>
                        <select class="form-control" id="warehouse_id" name="warehouse_id" required>
                            <option value="">Select Warehouse</option>
                            <?php foreach ($warehouses as $wh): ?>
                                <option value="<?php echo $wh['id']; ?>" <?php echo $warehouseId == $wh['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($wh['warehouse_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="location_code" class="form-label">Location Code <span
                                class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="location_code" name="location_code" required
                            placeholder="e.g., A-01, SHELF-01, BIN-001">
                        <div class="form-text">Unique code within the warehouse (e.g., A-01, RACK-01)</div>
                    </div>

                    <div class="mb-3">
                        <label for="location_name" class="form-label">Location Name (Optional)</label>
                        <input type="text" class="form-control" id="location_name" name="location_name"
                            placeholder="e.g., Main Shelf A, Top Bin">
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="location_type" class="form-label">Location Type</label>
                            <select class="form-control" id="location_type" name="location_type">
                                <option value="shelf">Shelf</option>
                                <option value="bin">Bin</option>
                                <option value="rack">Rack</option>
                                <option value="pallet">Pallet</option>
                                <option value="room">Room</option>
                                <option value="aisle">Aisle</option>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="capacity" class="form-label">Capacity (Units)</label>
                            <input type="number" class="form-control" id="capacity" name="capacity" step="1"
                                placeholder="Optional">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="parent_location_id" class="form-label">Parent Location</label>
                        <select class="form-control" id="parent_location_id" name="parent_location_id">
                            <option value="">None (Top Level)</option>
                            <?php foreach ($allLocations as $loc): ?>
                                <option value="<?php echo $loc['id']; ?>"
                                    data-warehouse="<?php echo $loc['warehouse_id']; ?>">
                                    <?php echo htmlspecialchars($loc['warehouse_name'] . ': ' . $loc['location_code']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Parent location must be in the same warehouse</div>
                    </div>

                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2"
                            placeholder="Any additional information about this location"></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Add Location
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Location Modal -->
<div class="modal fade" id="editLocationModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title">
                    <i class="fas fa-edit me-2"></i>Edit Location
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="edit_location" value="1">
                <input type="hidden" name="location_id" id="edit_location_id">

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Warehouse</label>
                        <input type="text" class="form-control bg-light" id="edit_warehouse_name" readonly disabled>
                        <input type="hidden" name="warehouse_id" id="edit_warehouse_id">
                    </div>

                    <div class="mb-3">
                        <label for="edit_location_code" class="form-label">Location Code <span
                                class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_location_code" name="location_code" required>
                    </div>

                    <div class="mb-3">
                        <label for="edit_location_name" class="form-label">Location Name</label>
                        <input type="text" class="form-control" id="edit_location_name" name="location_name">
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_location_type" class="form-label">Location Type</label>
                            <select class="form-control" id="edit_location_type" name="location_type">
                                <option value="shelf">Shelf</option>
                                <option value="bin">Bin</option>
                                <option value="rack">Rack</option>
                                <option value="pallet">Pallet</option>
                                <option value="room">Room</option>
                                <option value="aisle">Aisle</option>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="edit_capacity" class="form-label">Capacity (Units)</label>
                            <input type="number" class="form-control" id="edit_capacity" name="capacity" step="1">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="edit_parent_location_id" class="form-label">Parent Location</label>
                        <select class="form-control" id="edit_parent_location_id" name="parent_location_id">
                            <option value="">None (Top Level)</option>
                            <?php foreach ($allLocations as $loc): ?>
                                <option value="<?php echo $loc['id']; ?>"
                                    data-warehouse="<?php echo $loc['warehouse_id']; ?>">
                                    <?php echo htmlspecialchars($loc['warehouse_name'] . ': ' . $loc['location_code']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="edit_notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="edit_notes" name="notes" rows="2"></textarea>
                    </div>

                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active" value="1">
                        <label class="form-check-label" for="edit_is_active">
                            Active
                        </label>
                        <div class="form-text">Inactive locations won't appear in stock allocation forms</div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save me-2"></i>Update Location
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="fas fa-exclamation-triangle me-2"></i>Delete Location
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="delete_location" value="1">
                <input type="hidden" name="location_id" id="delete_location_id">

                <div class="modal-body">
                    <p>Are you sure you want to delete <strong id="deleteLocationCode"></strong>?</p>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Warning:</strong> This action:
                        <ul class="mb-0 mt-2">
                            <li>Cannot be undone</li>
                            <li>Requires the location to have no stock</li>
                            <li>Requires no child locations</li>
                        </ul>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-2"></i>Delete Location
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
// Helper function to flatten hierarchical locations
function flattenLocations($locations, $level = 0, &$result = [])
{
    foreach ($locations as $location) {
        $location['level'] = $level;
        $result[] = $location;
        if (!empty($location['children'])) {
            flattenLocations($location['children'], $level + 1, $result);
        }
    }
    return $result;
}
?>

<script>
    function viewLocationDetails(location) {
        const modalContent = document.getElementById('locationDetailsContent');

        modalContent.innerHTML = `
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Location Information</h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr><th style="width: 40%">Location Code:</th><td><code>${escapeHtml(location.location_code)}</code></td></tr>
                            <tr><th>Location Name:</th><td>${escapeHtml(location.location_name || '-')}</td></tr>
                            <tr><th>Location Type:</th><td><span class="badge bg-info">${escapeHtml(location.location_type)}</span></td></tr>
                            <tr><th>Status:</th><td>${location.is_active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>'}</td></tr>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-building me-2"></i>Warehouse Information</h6>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr><th style="width: 40%">Warehouse:</th><td><strong>${escapeHtml(location.warehouse_name)}</strong></td></tr>
                            <tr><th>Parent Location:</th><td>${location.parent_code ? escapeHtml(location.parent_code) : '<span class="text-muted">None (Top Level)</span>'}</td></tr>
                            <tr><th>Capacity:</th><td>${location.capacity ? formatNumber(location.capacity) + ' units' : '<span class="text-muted">Not set</span>'}</td></tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="fas fa-sticky-note me-2"></i>Notes</h6>
            </div>
            <div class="card-body">
                <p class="mb-0">${location.notes ? escapeHtml(location.notes) : '<span class="text-muted">No notes</span>'}</p>
            </div>
        </div>
    `;

        // Set up stock view button
        document.getElementById('viewStockBtn').href = `?page=inventory/stock&location=${location.id}`;

        new bootstrap.Modal(document.getElementById('viewLocationModal')).show();
    }

    function editLocation(location) {
        document.getElementById('edit_location_id').value = location.id;
        document.getElementById('edit_warehouse_name').value = location.warehouse_name;
        document.getElementById('edit_warehouse_id').value = location.warehouse_id;
        document.getElementById('edit_location_code').value = location.location_code;
        document.getElementById('edit_location_name').value = location.location_name || '';
        document.getElementById('edit_location_type').value = location.location_type;
        document.getElementById('edit_capacity').value = location.capacity || '';
        document.getElementById('edit_parent_location_id').value = location.parent_location_id || '';
        document.getElementById('edit_notes').value = location.notes || '';
        document.getElementById('edit_is_active').checked = location.is_active == 1;

        new bootstrap.Modal(document.getElementById('editLocationModal')).show();
    }

    function deleteLocation(id, code) {
        document.getElementById('delete_location_id').value = id;
        document.getElementById('deleteLocationCode').textContent = code;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }

    function refreshPage() {
        window.location.reload();
    }

    function formatNumber(num) {
        return new Intl.NumberFormat().format(num);
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Filter parent locations by selected warehouse
    document.getElementById('warehouse_id')?.addEventListener('change', function () {
        const warehouseId = this.value;
        const parentSelect = document.getElementById('parent_location_id');

        Array.from(parentSelect.options).forEach(option => {
            if (option.value === '') return;
            const optionWarehouse = option.getAttribute('data-warehouse');
            option.style.display = (!warehouseId || optionWarehouse == warehouseId) ? 'block' : 'none';
        });
    });

    // Initialize DataTable if needed
    $(document).ready(function () {
        if ($.fn.DataTable && $('#locationsTable tbody tr').length > 10) {
            $('#locationsTable').DataTable({
                pageLength: 25,
                order: [[0, 'asc']],
                language: {
                    search: "Search locations:",
                    lengthMenu: "Show _MENU_ locations per page",
                    info: "Showing _START_ to _END_ of _TOTAL_ locations",
                    emptyTable: "No locations found"
                },
                columnDefs: [
                    { orderable: false, targets: [7] }
                ]
            });
        }
    });
</script>

<style>
    .storage-locations .border-left-primary {
        border-left: 4px solid #4e73df !important;
    }

    .storage-locations .border-left-success {
        border-left: 4px solid #1cc88a !important;
    }

    .storage-locations .border-left-info {
        border-left: 4px solid #36b9cc !important;
    }

    .storage-locations .table td {
        vertical-align: middle;
    }

    .storage-locations .btn-group {
        gap: 4px;
    }

    .storage-locations .fa-level-down-alt {
        font-size: 0.8rem;
        opacity: 0.6;
    }

    .modal .table-sm th {
        background-color: #f8f9fc;
        width: 40%;
    }

    .storage-locations .badge {
        font-weight: 500;
        padding: 0.5em 0.85em;
    }
</style>