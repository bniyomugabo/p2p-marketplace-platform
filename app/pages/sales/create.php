<?php
// pages/sales/create.php - SLEEK POS INTERFACE
declare(strict_types=1);

// Disable error display for AJAX requests - IMPORTANT!
if (isset($_GET['ajax'])) {
    error_reporting(0);
    ini_set('display_errors', '0');
}

$pageTitle = 'Create Sale - POS System';

// Load models
require_once __DIR__ . '/../../models/Sale.php';
require_once __DIR__ . '/../../models/Customer.php';
require_once __DIR__ . '/../../models/Variant.php';
require_once __DIR__ . '/../../models/Product.php';
require_once __DIR__ . '/../../models/Inventory.php';
require_once __DIR__ . '/../../models/Warehouse.php';
require_once __DIR__ . '/../../models/Location.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Get or create an activated category for the company
 * 
 * @param PDO $db Database connection
 * @param int $companyId Company ID
 * @return int Category ID that is activated for the company
 */
function getOrCreateActivatedCategory($db, $companyId)
{
    // First, try to get any activated category for this company
    $catSql = "
        SELECT cc.category_id, c.category_name
        FROM category_company cc
        JOIN categories c ON cc.category_id = c.id
        WHERE cc.company_id = :company_id AND cc.is_active = 1
        LIMIT 1
    ";
    $catStmt = $db->prepare($catSql);
    $catStmt->execute([':company_id' => $companyId]);
    $activatedCategory = $catStmt->fetch();

    if ($activatedCategory) {
        return (int) $activatedCategory['category_id'];
    }

    // No activated category found, check if there's a default 'General' category
    $defaultCatSql = "SELECT id FROM categories WHERE category_name = 'General' OR category_code = 'GEN' LIMIT 1";
    $defaultCatStmt = $db->prepare($defaultCatSql);
    $defaultCatStmt->execute();
    $defaultCategory = $defaultCatStmt->fetch();

    if ($defaultCategory) {
        $categoryId = (int) $defaultCategory['id'];

        // Activate this category for the company
        $activateSql = "
            INSERT INTO category_company (category_id, company_id, is_active, created_at)
            VALUES (:category_id, :company_id, 1, NOW())
            ON DUPLICATE KEY UPDATE is_active = 1, updated_at = NOW()
        ";
        $activateStmt = $db->prepare($activateSql);
        $activateStmt->execute([
            ':category_id' => $categoryId,
            ':company_id' => $companyId
        ]);

        return $categoryId;
    }

    // Create a new 'General' category
    $newCatSql = "
        INSERT INTO categories (category_code, category_name, created_at)
        VALUES ('GEN', 'General', NOW())
    ";
    $newCatStmt = $db->prepare($newCatSql);
    $newCatStmt->execute();
    $categoryId = (int) $db->lastInsertId();

    // Activate it for the company
    $activateSql = "
        INSERT INTO category_company (category_id, company_id, is_active, created_at)
        VALUES (:category_id, :company_id, 1, NOW())
    ";
    $activateStmt = $db->prepare($activateSql);
    $activateStmt->execute([
        ':category_id' => $categoryId,
        ':company_id' => $companyId
    ]);

    return $categoryId;
}

// Check permission for non-AJAX requests
if (!isset($_GET['ajax']) && !in_array($userRole, ['ADM', 'MGR', 'SEL', 'ACC'])) {
    SessionManager::flash('error', 'You do not have permission to create sales.');
    header('Location: ' . route_url('sales/dashboard'));
    exit;
}

// ============================================
// AJAX HANDLERS - MUST BE BEFORE ANY HTML OUTPUT
// ============================================

// Handle AJAX product search
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_products') {
    // Clean output buffer
    if (ob_get_level())
        ob_clean();
    header('Content-Type: application/json');

    $searchTerm = $_GET['term'] ?? '';

    if (strlen($searchTerm) < 2) {
        echo json_encode([]);
        exit;
    }

    try {
        $sql = "
            SELECT 
                v.id as variant_id,
                v.sku,
                v.barcode,
                v.variant_name,
                v.selling_price,
                v.purchase_price,
                v.tax_rate,
                p.id as product_id,
                p.product_name,
                p.product_code,
                COALESCE(SUM(i.available_quantity), 0) as available_stock
            FROM variants v
            JOIN products p ON v.product_id = p.id
            LEFT JOIN inventory i ON v.id = i.variant_id AND i.company_id = :p_1_company_id
            WHERE p.company_id = :p_2_company_id
                AND v.is_active = 1
                AND p.is_active = 1
                AND (
                    p.product_name LIKE :p_1_term 
                    OR v.variant_name LIKE :p_2_term 
                    OR v.sku LIKE :p_3_term 
                    OR v.barcode LIKE :p_4_term
                )
            GROUP BY v.id
            LIMIT 15
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':p_1_company_id' => $companyId,
            ':p_2_company_id' => $companyId,
            ':p_1_term' => "%{$searchTerm}%",
            ':p_2_term' => "%{$searchTerm}%",
            ':p_3_term' => "%{$searchTerm}%",
            ':p_4_term' => "%{$searchTerm}%"
        ]);

        $results = $stmt->fetchAll();

        $formatted = [];
        foreach ($results as $r) {
            $displayName = $r['product_name'];
            if ($r['variant_name'] && $r['variant_name'] !== 'Standard') {
                $displayName .= ' - ' . $r['variant_name'];
            }

            $formatted[] = [
                'id' => $r['variant_id'],
                'variant_id' => $r['variant_id'],
                'text' => $displayName,
                'sku' => $r['sku'] ?? '',
                'barcode' => $r['barcode'] ?? '',
                'product_name' => $r['product_name'],
                'variant_name' => $r['variant_name'] ?? '',
                'selling_price' => (float) ($r['selling_price'] ?? 0),
                'tax_rate' => (float) ($r['tax_rate'] ?? 18),
                'available_stock' => (float) ($r['available_stock'] ?? 0)
            ];
        }

        echo json_encode($formatted);
        exit;

    } catch (Exception $e) {
        error_log("Search error: " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Handle AJAX barcode lookup
if (isset($_GET['ajax']) && $_GET['ajax'] === 'barcode_lookup') {
    if (ob_get_level())
        ob_clean();
    header('Content-Type: application/json');

    $barcode = $_GET['barcode'] ?? '';

    if (empty($barcode)) {
        echo json_encode(null);
        exit;
    }

    try {
        $sql = "
            SELECT 
                v.id as variant_id,
                v.sku,
                v.barcode,
                v.variant_name,
                v.selling_price,
                v.tax_rate,
                p.id as product_id,
                p.product_name,
                COALESCE(SUM(i.available_quantity), 0) as available_stock
            FROM variants v
            JOIN products p ON v.product_id = p.id
            LEFT JOIN inventory i ON v.id = i.variant_id AND i.company_id = :company_id
            WHERE p.company_id = :company_id
                AND v.is_active = 1
                AND p.is_active = 1
                AND v.barcode = :barcode
            GROUP BY v.id
            LIMIT 1
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':company_id' => $companyId,
            ':barcode' => $barcode
        ]);

        $result = $stmt->fetch();

        if ($result) {
            echo json_encode([
                'variant_id' => $result['variant_id'],
                'product_name' => $result['product_name'],
                'variant_name' => $result['variant_name'] ?? '',
                'selling_price' => (float) ($result['selling_price'] ?? 0),
                'tax_rate' => (float) ($result['tax_rate'] ?? 18),
                'available_stock' => (float) ($result['available_stock'] ?? 0),
                'sku' => $result['sku'] ?? ''
            ]);
        } else {
            echo json_encode(null);
        }
        exit;

    } catch (Exception $e) {
        error_log("Barcode lookup error: " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

// Handle AJAX stock check
if (isset($_GET['ajax']) && $_GET['ajax'] === 'check_stock') {
    if (ob_get_level())
        ob_clean();
    header('Content-Type: application/json');

    $variantId = (int) ($_GET['variant_id'] ?? 0);
    $warehouseId = (int) ($_GET['warehouse_id'] ?? 0);

    if (!$variantId) {
        echo json_encode(['available_stock' => 0]);
        exit;
    }

    try {
        $sql = "
            SELECT COALESCE(available_quantity, 0) as available_stock
            FROM inventory
            WHERE variant_id = :variant_id 
                AND warehouse_id = :warehouse_id
                AND company_id = :company_id
            LIMIT 1
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':variant_id' => $variantId,
            ':warehouse_id' => $warehouseId,
            ':company_id' => $companyId
        ]);

        $result = $stmt->fetch();
        echo json_encode(['available_stock' => (float) ($result['available_stock'] ?? 0)]);
        exit;

    } catch (Exception $e) {
        error_log("Stock check error: " . $e->getMessage());
        echo json_encode(['available_stock' => 0]);
        exit;
    }
}

// Handle AJAX quick product creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax']) && $_GET['ajax'] === 'quick_product') {
    if (ob_get_level())
        ob_clean();
    header('Content-Type: application/json');

    $productName = trim($_POST['product_name'] ?? '');
    $sellingPrice = (float) ($_POST['selling_price'] ?? 0);
    $initialStock = (float) ($_POST['initial_stock'] ?? 0);

    if (empty($productName) || $sellingPrice <= 0) {
        echo json_encode(['success' => false, 'error' => 'Product name and selling price are required']);
        exit;
    }

    try {
        $db->beginTransaction();

        // Get or create an activated category for this company
        $categoryId = getOrCreateActivatedCategory($db, $companyId);

        $productModel = new Product($companyId);
        $productCode = $productModel->generateCode();

        $productId = $productModel->create([
            'company_id' => $companyId,
            'product_code' => $productCode,
            'product_name' => $productName,
            'category_id' => $categoryId,
            'has_variants' => 0,
            'is_active' => 1,
            'created_by' => $userId
        ]);

        // Generate a better SKU
        $baseSku = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $productName), 0, 8));
        if (empty($baseSku)) {
            $baseSku = 'PROD';
        }
        $sku = $baseSku . '-' . str_pad((string)$productId, 4, '0', STR_PAD_LEFT);

        $warehouseModel = new Warehouse($companyId);
        $defaultWarehouse = $warehouseModel->getMainWarehouse();
        if (!$defaultWarehouse) {
            $warehouses = $warehouseModel->all(['id', 'warehouse_name'], 'is_active = 1');
            $defaultWarehouse = !empty($warehouses) ? $warehouses[0] : null;
        }

        $variantModel = new Variant($companyId);
        $variantId = $variantModel->create([
            'company_id' => $companyId,
            'product_id' => $productId,
            'sku' => $sku,
            'variant_name' => 'Standard',
            'selling_price' => $sellingPrice,
            'purchase_price' => $sellingPrice * 0.7,
            'tax_rate' => 18.00,
            'is_active' => 1
        ]);

        if ($initialStock > 0 && $defaultWarehouse) {
            $inventoryModel = new Inventory($companyId);
            $inventoryModel->updateStock(
                $variantId,
                $defaultWarehouse['id'],
                $initialStock,
                $sellingPrice,
                null,
                $userId
            );
        }

        $db->commit();

        echo json_encode([
            'success' => true,
            'variant_id' => $variantId,
            'product_id' => $productId,
            'product_name' => $productName,
            'variant_name' => 'Standard',
            'selling_price' => $sellingPrice,
            'tax_rate' => 18.00,
            'available_stock' => $initialStock,
            'sku' => $sku
        ]);
        exit;

    } catch (Exception $e) {
        $db->rollBack();
        error_log("Quick product error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        echo json_encode(['success' => false, 'error' => 'Failed to create product: ' . $e->getMessage()]);
        exit;
    }
}

// Handle AJAX quick customer creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax']) && $_GET['ajax'] === 'quick_customer') {
    if (ob_get_level())
        ob_clean();
    header('Content-Type: application/json');

    $fullName = trim($_POST['full_name'] ?? '');
    $customerType = $_POST['customer_type'] ?? 'individual';
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $taxId = trim($_POST['tax_id'] ?? '');

    if (empty($fullName)) {
        echo json_encode(['success' => false, 'error' => 'Customer name is required']);
        exit;
    }

    try {
        $customerModel = new Customer($companyId);
        $customerCode = $customerModel->generateCode();

        $customerId = $customerModel->create([
            'company_id' => $companyId,
            'customer_code' => $customerCode,
            'full_name' => $fullName,
            'customer_type' => $customerType,
            'phone' => $phone,
            'email' => $email,
            'address' => $address,
            'city' => $city,
            'tax_id' => $taxId,
            'is_active' => 1,
            'created_by' => $userId
        ]);

        echo json_encode([
            'success' => true,
            'customer_id' => $customerId,
            'full_name' => $fullName,
            'customer_code' => $customerCode
        ]);
        exit;

    } catch (Exception $e) {
        error_log("Quick customer error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ============================================
// REGULAR FORM SUBMISSION (NON-AJAX)
// ============================================

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['ajax'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
        SessionManager::flash('error', 'Invalid security token. Please try again.');
        header('Location: ' . route_url('sales/create'));
        exit;
    }

    try {
        $customerId = $_POST['customer_id'] === 'walkin' ? null : (int) $_POST['customer_id'];

        // Check if items exist
        if (empty($_POST['items'])) {
            throw new Exception('Please add at least one item to the sale.');
        }

        $items = [];

        // Handle items - decode JSON string from hidden form field
        if (is_string($_POST['items'])) {
            $itemsData = json_decode($_POST['items'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid items data: ' . json_last_error_msg());
            }
        } else {
            throw new Exception('Invalid items format');
        }

        if (empty($itemsData)) {
            throw new Exception('No items found in the submission');
        }

        foreach ($itemsData as $item) {
            if (empty($item['variant_id']) || empty($item['quantity']) || $item['quantity'] <= 0) {
                continue;
            }

            $warehouseId = (int) ($item['warehouse_id'] ?? 0);
            if (!$warehouseId) {
                $warehouseModel = new Warehouse($companyId);
                $defaultWh = $warehouseModel->getMainWarehouse();
                $warehouseId = $defaultWh['id'] ?? 0;
            }

            $items[] = [
                'variant_id' => (int) $item['variant_id'],
                'quantity' => (float) $item['quantity'],
                'unit_price' => (float) $item['unit_price'],
                'discount_percent' => (float) ($item['discount_percent'] ?? 0),
                'tax_rate' => (float) ($item['tax_rate'] ?? 18.00),
                'warehouse_id' => $warehouseId,
                'location_id' => !empty($item['location_id']) ? (int) $item['location_id'] : null
            ];
        }

        if (empty($items)) {
            throw new Exception('No valid items to process.');
        }

        $paymentMethod = $_POST['payment_method'] ?? 'cash';

        // Handle walk-in customer
        if ($customerId === null) {
            $walkinSql = "SELECT id FROM customers WHERE full_name = 'Walk-in Customer' AND company_id = :company_id LIMIT 1";
            $walkinStmt = $db->prepare($walkinSql);
            $walkinStmt->execute(['company_id' => $companyId]);
            $walkin = $walkinStmt->fetch();

            if ($walkin) {
                $customerId = $walkin['id'];
            } else {
                $customerModel = new Customer($companyId);
                $customerId = $customerModel->create([
                    'company_id' => $companyId,
                    'customer_code' => $customerModel->generateCode(),
                    'full_name' => 'Walk-in Customer',
                    'customer_type' => 'individual',
                    'is_active' => 1,
                    'created_by' => $userId
                ]);
            }
        }

        $saleModel = new Sale($companyId);
        $invoiceId = $saleModel->createSale($customerId, $items, $paymentMethod, $userId);

        SessionManager::flash('success', 'Sale created successfully!');
        header('Location: ' . route_url('sales/view-invoice', ['id' => $invoiceId]));
        exit;

    } catch (Exception $e) {
        error_log("Sale creation error: " . $e->getMessage());
        SessionManager::flash('error', 'Failed to create sale: ' . $e->getMessage());
        header('Location: ' . route_url('sales/create'));
        exit;
    }
}

// ============================================
// PAGE RENDERING (ONLY FOR NON-AJAX REQUESTS)
// ============================================

// Initialize models for page display
$customerModel = new Customer($companyId);
$warehouseModel = new Warehouse($companyId);
$locationModel = new Location($companyId);

// Get default/main warehouse
$defaultWarehouse = $warehouseModel->getMainWarehouse();
if (!$defaultWarehouse) {
    $warehouses = $warehouseModel->all(['id', 'warehouse_name', 'warehouse_code'], 'is_active = 1');
    $defaultWarehouse = !empty($warehouses) ? $warehouses[0] : null;
}

// Get customers
$customers = $customerModel->all(['id', 'customer_code', 'full_name', 'phone'], 'is_active = 1');

// Get currency
$currency = $_SESSION['company_currency'] ?? 'RWF';

// Generate CSRF token
$csrfToken = CSRF::generate();

// Get locations grouped by warehouse
$locationsByWarehouse = [];
$locations = $locationModel->getAllWithWarehouse();
foreach ($locations as $loc) {
    if (!isset($locationsByWarehouse[$loc['warehouse_id']])) {
        $locationsByWarehouse[$loc['warehouse_id']] = [];
    }
    $locationsByWarehouse[$loc['warehouse_id']][] = $loc;
}

// Set page-specific JS files
$jsFiles = ['sales/create.js'];
?>

<!-- Main POS Interface -->
<div class="pos-container">
    <!-- Fixed Header Section -->
    <div class="header-section">
        <div class="row align-items-end">
            <div class="col-md-4">
                <label class="small fw-bold text-muted mb-1">CUSTOMER</label>
                <select class="form-select border-0 bg-light" id="customer_id" name="customer_id">
                    <option value="walkin" selected>🚶 Walk-in Customer</option>
                    <?php foreach ($customers as $customer): ?>
                        <option value="<?php echo $customer['id']; ?>">
                            <?php echo htmlspecialchars($customer['full_name']); ?>
                            <?php if ($customer['customer_code']): ?>
                                (<?php echo htmlspecialchars($customer['customer_code']); ?>)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-link btn-sm p-0 mt-1 text-decoration-none" id="add-item-trigger">
                    <i class="fas fa-plus-circle"></i> Add Item
                </button>
            </div>
            <div class="col-md-2">
                <label class="small fw-bold text-muted mb-1">PAYMENT</label>
                <select class="form-select border-0 bg-light" id="payment_method" name="payment_method">
                    <option value="cash">💰 Cash</option>
                    <option value="card">💳 Card</option>
                    <option value="mobile">📱 Mobile Money</option>
                    <option value="bank_transfer">🏦 Bank Transfer</option>
                    <option value="credit">📝 Credit</option>
                </select>
            </div>
            <div class="col-md-6 text-end">
                <button type="button" class="btn btn-outline-secondary btn-sm me-2" id="toggle-notes-btn">
                    <i class="fas fa-sticky-note"></i> <span id="notes-btn-text">Add Notes</span>
                </button>
                <div id="invoice-notes-wrapper" class="mt-2" style="display:none;">
                    <textarea class="form-control" id="notes" name="notes" rows="2"
                        placeholder="Type invoice notes here..."></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- Scrollable Items Table -->
    <div class="items-container">
        <div class="item-table">
            <div class="row fw-bold p-3 border-bottom text-muted small bg-white sticky-top">
                <div class="col-5">PRODUCT / VARIANT</div>
                <div class="col-2 text-center">QTY</div>
                <div class="col-2 text-center">PRICE</div>
                <div class="col-2 text-end">TOTAL</div>
                <div class="col-1"></div>
            </div>

            <div id="items-list">
                <!-- Items will be added here dynamically -->
            </div>
        </div>
    </div>

    <!-- Fixed Bottom Summary Bar -->
    <div class="bottom-summary-bar">
        <div class="d-flex align-items-center">
            <div class="summary-item">
                <span class="summary-label">Subtotal</span>
                <span class="summary-value" id="summary-subtotal"><?php echo $currency; ?> 0.00</span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Tax</span>
                <span class="summary-value" id="summary-tax"><?php echo $currency; ?> 0.00</span>
            </div>
            <div class="summary-item">
                <span class="summary-label">Discount</span>
                <span class="summary-value text-warning" id="summary-discount"><?php echo $currency; ?> 0.00</span>
            </div>
        </div>

        <div class="d-flex align-items-center">
            <div class="summary-item me-4">
                <span class="summary-label text-white">TOTAL DUE</span>
                <span class="summary-value text-success fs-4" id="summary-total"><?php echo $currency; ?> 0.00</span>
            </div>
            <button type="submit" form="create-sale-form" class="btn btn-success fw-bold px-4 shadow-sm">
                SUBMIT SALE <i class="fas fa-arrow-right ms-2"></i>
            </button>
        </div>
    </div>

    <!-- Hidden Form for Submission -->
    <form method="POST" id="create-sale-form" style="display: none;">
        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
        <input type="hidden" name="company_id" value="<?php echo $companyId; ?>">
        <input type="hidden" name="customer_id" id="form_customer_id">
        <input type="hidden" name="payment_method" id="form_payment_method">
        <input type="hidden" name="items" id="form_items">
        <input type="hidden" name="notes" id="form_notes">
    </form>
</div>

<!-- Add Customer Modal -->
<div class="modal fade" id="addCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="modal-title">
                    <i class="fas fa-user-plus me-2"></i>Add New Customer
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="quickCustomerForm">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Full Name / Company Name <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="cust_full_name" name="full_name" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Customer Type</label>
                            <select class="form-select" id="cust_type" name="customer_type">
                                <option value="individual">👤 Individual</option>
                                <option value="company">🏢 Company</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="cust_phone" name="phone">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" id="cust_email" name="email">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" id="cust_address" name="address" rows="2"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">City</label>
                            <input type="text" class="form-control" id="cust_city" name="city">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Tax ID</label>
                            <input type="text" class="form-control" id="cust_tax_id" name="tax_id">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveCustomerBtn">
                    <i class="fas fa-save me-2"></i>Save Customer
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Quick Add Product Modal -->
<div class="modal fade" id="quickAddProductModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="modal-title">
                    <i class="fas fa-box-open me-2"></i>Quick Add Product
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Product Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="new-prod-name" placeholder="e.g., Wireless Mouse">
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label">Selling Price <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><?php echo $currency; ?></span>
                            <input type="number" class="form-control" id="new-prod-price" step="0.01" min="0">
                        </div>
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label">Initial Stock</label>
                        <input type="number" class="form-control" id="new-prod-stock" step="1" min="0" value="0">
                    </div>
                </div>
                <div class="form-text text-muted small">
                    <i class="fas fa-info-circle me-1"></i>Product will be added to default category with 18% tax rate.
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="quickSaveProductBtn">
                    <i class="fas fa-plus me-2"></i>Create Product
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Item Row Template -->
<template id="item-template">
    <div class="item-row p-3" data-variant-id="" data-index="">
        <div class="row align-items-center">
            <div class="col-5">
                <div class="search-container">
                    <input type="text" class="form-control form-control-sm border-0 bg-light product-search-input"
                        placeholder="🔍 Search product name, SKU, or barcode..." autocomplete="off">
                    <div class="autocomplete-results"></div>
                    <div class="no-result-pop">
                        Product not found? <a href="#" class="fw-bold quick-add-link">Add New</a>
                    </div>
                </div>
                <input type="hidden" class="variant-id" name="items[INDEX][variant_id]" value="">
                <div class="selected-info small text-muted mt-1"></div>
            </div>
            <div class="col-2">
                <input type="number" class="form-control form-control-sm border-0 bg-light text-center quantity"
                    name="items[INDEX][quantity]" min="0.01" step="0.01" value="1" placeholder="Qty">
                <div class="stock-warning small text-danger mt-1" style="display: none;"></div>
            </div>
            <div class="col-2">
                <div class="input-group">
                    <span class="input-group-text bg-light border-0 currency-symbol"><?php echo $currency; ?></span>
                    <input type="number" class="form-control form-control-sm border-0 bg-light text-center unit-price"
                        name="items[INDEX][unit_price]" min="0" step="0.01" value="0" placeholder="0.00">
                </div>
            </div>
            <div class="col-2 text-end fw-bold">
                <span class="line-total"><?php echo $currency; ?> 0.00</span>
            </div>
            <div class="col-1 text-center">
                <button type="button" class="btn btn-sm text-primary p-0 me-2 toggle-details-btn" title="Details">
                    <i class="fas fa-cog"></i>
                </button>
                <button type="button" class="btn btn-sm text-danger p-0 remove-item" title="Remove">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
        <div class="details-pane mt-3">
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="small text-muted mb-1">Warehouse</label>
                    <select class="form-select form-select-sm border-0 bg-light warehouse-select"
                        name="items[INDEX][warehouse_id]">
                        <?php if ($defaultWarehouse): ?>
                            <option value="<?php echo $defaultWarehouse['id']; ?>" selected>
                                <?php echo htmlspecialchars($defaultWarehouse['warehouse_name']); ?>
                            </option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="small text-muted mb-1">Storage Location</label>
                    <select class="form-select form-select-sm border-0 bg-light location-select"
                        name="items[INDEX][location_id]">
                        <option value="">Default</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="small text-muted mb-1">Tax Rate (%)</label>
                    <input type="number" class="form-control form-control-sm border-0 bg-light tax-rate"
                        name="items[INDEX][tax_rate]" min="0" max="100" step="0.1" value="18">
                </div>
                <div class="col-md-2">
                    <label class="small text-muted mb-1">Discount (%)</label>
                    <input type="number" class="form-control form-control-sm border-0 bg-light discount-percent"
                        name="items[INDEX][discount_percent]" min="0" max="100" step="0.1" value="0">
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-12">
                    <label class="small text-muted mb-1">Item Note</label>
                    <input type="text" class="form-control form-control-sm border-0 bg-light description"
                        name="items[INDEX][description]" placeholder="Optional item note...">
                </div>
            </div>
        </div>
    </div>
</template>

<style>
    .pos-container {
        display: flex;
        flex-direction: column;
        height: calc(100vh - 60px);
        overflow: hidden;
        background-color: #f4f7f6;
    }

    .header-section {
        background: white;
        padding: 15px 25px;
        border-bottom: 1px solid #e3e6f0;
        flex-shrink: 0;
    }

    .items-container {
        flex-grow: 1;
        overflow-y: auto;
        padding: 20px;
    }

    .item-table {
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        width: 100%;
    }

    .item-row {
        border-bottom: 1px solid #f0f0f0;
        transition: background 0.2s;
    }

    .item-row:hover {
        background-color: #fafafa;
    }

    .details-pane {
        display: none;
        background: #f8f9fc;
        padding: 15px;
        border-radius: 0 0 8px 8px;
    }

    .bottom-summary-bar {
        background: #1a1d21;
        color: white;
        padding: 12px 30px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-shrink: 0;
    }

    .summary-item {
        display: flex;
        align-items: center;
        margin-right: 30px;
    }

    .summary-label {
        color: #a0a0a0;
        font-size: 0.75rem;
        text-transform: uppercase;
        margin-right: 10px;
    }

    .summary-value {
        font-weight: 600;
        font-size: 1.1rem;
    }

    .search-container {
        position: relative;
    }

    .autocomplete-results {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        z-index: 1000;
        background: white;
        border: 1px solid #ddd;
        border-radius: 4px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        max-height: 250px;
        overflow-y: auto;
        display: none;
    }

    .autocomplete-item {
        padding: 8px 12px;
        cursor: pointer;
        border-bottom: 1px solid #f0f0f0;
        transition: background 0.15s;
    }

    .autocomplete-item:hover {
        background-color: #f0f4ff;
    }

    .autocomplete-item:last-child {
        border-bottom: none;
    }

    .no-result-pop {
        display: none;
        background: #fff3cd;
        border: 1px solid #ffeeba;
        padding: 5px 10px;
        border-radius: 4px;
        font-size: 0.85rem;
        margin-top: 5px;
    }

    ::-webkit-scrollbar {
        width: 6px;
    }

    ::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }

    ::-webkit-scrollbar-thumb {
        background: #ccc;
        border-radius: 10px;
    }

    ::-webkit-scrollbar-thumb:hover {
        background: #aaa;
    }

    .form-control.bg-light,
    .form-select.bg-light {
        background-color: #f8f9fc !important;
        border-color: #e3e6f0;
    }

    .form-control.bg-light:focus,
    .form-select.bg-light:focus {
        background-color: #fff !important;
        border-color: #4e73df;
        box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
    }

    .currency-symbol {
        font-weight: 500;
        font-size: 0.75rem;
        padding: 0 8px;
    }

    .line-total {
        font-weight: 600;
        font-size: 0.9rem;
    }

    input[type="number"] {
        appearance: textfield;
    }

    input[type="number"]::-webkit-inner-spin-button,
    input[type="number"]::-webkit-outer-spin-button {
        opacity: 0.5;
    }

    .stock-warning {
        font-size: 0.7rem;
    }

    .sticky-top {
        position: sticky;
        top: 0;
        z-index: 10;
        background: white !important;
    }
</style>

<script>
    // Pass PHP variables to JavaScript
    window.saleCreateConfig = {
        companyCurrency: '<?php echo $currency; ?>',
        apiBaseUrl: '<?php echo BASE_URL; ?>',
        defaultWarehouseId: '<?php echo $defaultWarehouse['id'] ?? ''; ?>',
        locationsByWarehouse: <?php echo json_encode($locationsByWarehouse); ?>
    };
</script>