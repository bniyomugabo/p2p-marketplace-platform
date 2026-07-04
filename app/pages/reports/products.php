<?php
// pages/reports/products.php
declare(strict_types=1);

$pageTitle = 'Product Report - Inventory Management System';
require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Product.php';
require_once __DIR__ . '/../../models/Category.php';
require_once __DIR__ . '/../../models/Inventory.php';
require_once __DIR__ . '/../../models/Sale.php';

$db = Database::getInstance();
$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR', 'ACC', 'VIW'])) {
    SessionManager::flash('error', 'You do not have permission to view product reports.');
    header('Location: ' . route_url('reports'));
    exit;
}

// Check if company context is set
if (!$companyId) {
    SessionManager::flash('error', 'Company context not found. Please log in again.');
    header('Location: ' . route_url('login'));
    exit;
}

// Initialize models
$productModel = new Product($companyId);
$categoryModel = new Category($companyId);
$inventoryModel = new Inventory($companyId);
$saleModel = new Sale($companyId);

// Get filter parameters
$categoryId = isset($_GET['category_id']) ? (int) $_GET['category_id'] : null;
$status = $_GET['status'] ?? 'active';
$sortBy = $_GET['sort_by'] ?? 'name';
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Get categories for filter
$categories = $categoryModel->all(['id', 'category_name']);

// SQL Query
$sql = "
    SELECT 
        p.id, p.product_code, p.product_name, p.description, p.category_id, p.brand, 
        p.has_variants, p.unit_of_measure, p.is_active, p.created_at,
        c.category_name,
        COUNT(DISTINCT v.id) as variant_count,
        COALESCE(SUM(i.quantity), 0) as total_stock,
        COALESCE(SUM(i.available_quantity), 0) as available_stock,
        COALESCE(SUM(i.quantity * i.avg_landed_cost), 0) as inventory_value,
        COALESCE(AVG(v.purchase_price), 0) as avg_purchase_price,
        COALESCE(AVG(v.selling_price), 0) as avg_selling_price,
        COALESCE(MIN(v.selling_price), 0) as min_price,
        COALESCE(MAX(v.selling_price), 0) as max_price,
        COUNT(DISTINCT CASE WHEN si.id IS NOT NULL AND si.company_id = :p_1_company_id THEN si.id END) as sales_count,
        COALESCE(SUM(ii.quantity), 0) as units_sold,
        COALESCE(SUM(ii.quantity * ii.unit_price * (1 - ii.discount_percent/100)), 0) as revenue,
        MAX(si.invoice_date) as last_sale_date
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN variants v ON p.id = v.product_id AND v.is_active = 1 AND v.company_id = :p_2_company_id
    LEFT JOIN inventory i ON v.id = i.variant_id AND i.company_id = :p_3_company_id
    LEFT JOIN invoice_items ii ON v.id = ii.variant_id
    LEFT JOIN sales_invoices si ON ii.invoice_id = si.id 
        AND si.status != 'cancelled'
        AND si.company_id = :p_4_company_id
        AND DATE(si.invoice_date) BETWEEN :date_from AND :date_to
    WHERE p.company_id = :p_5_company_id
";

$params = [
    'p_1_company_id' => $companyId,
    'p_2_company_id' => $companyId,
    'p_3_company_id' => $companyId,
    'p_4_company_id' => $companyId,
    'p_5_company_id' => $companyId,
    'date_from' => $dateFrom,
    'date_to' => $dateTo
];

if ($categoryId) {
    $sql .= " AND p.category_id = :category_id";
    $params['category_id'] = $categoryId;
}

if ($status === 'active')
    $sql .= " AND p.is_active = 1";
elseif ($status === 'inactive')
    $sql .= " AND p.is_active = 0";

$sql .= " GROUP BY p.id";

switch ($sortBy) {
    case 'stock':
        $sql .= " ORDER BY total_stock DESC";
        break;
    case 'value':
        $sql .= " ORDER BY inventory_value DESC";
        break;
    case 'sales':
        $sql .= " ORDER BY units_sold DESC";
        break;
    case 'revenue':
        $sql .= " ORDER BY revenue DESC";
        break;
    default:
        $sql .= " ORDER BY p.product_name ASC";
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Summary Stats
$totalProducts = count($products);
$totalStock = array_sum(array_column($products, 'total_stock'));
$totalValue = array_sum(array_column($products, 'inventory_value'));
$totalRevenue = array_sum(array_column($products, 'revenue'));
$totalUnitsSold = array_sum(array_column($products, 'units_sold'));

$lowStockProducts = array_filter($products, fn($p) => $p['total_stock'] > 0 && $p['total_stock'] <= 10);
$outOfStockProducts = array_filter($products, fn($p) => $p['total_stock'] == 0);
$noSalesProducts = array_filter($products, fn($p) => $p['units_sold'] == 0);

$inventoryByCategory = [];
foreach ($products as $product) {
    $cat = $product['category_name'] ?? 'Uncategorized';
    if (!isset($inventoryByCategory[$cat])) {
        $inventoryByCategory[$cat] = ['category' => $cat, 'total_value' => 0];
    }
    $inventoryByCategory[$cat]['total_value'] += $product['inventory_value'];
}
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="h4 mb-0 text-gray-800"><i class="fas fa-box me-2"></i>Product Report</h2>
        <div class="btn-group">
            <button type="button" class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown">Export</button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item"  href="?page=exports/products&format=pdf" target="_blank">PDF</a></li>
                <li><a class="dropdown-item" href="?page=exports/products&format=excel" target="_blank">Excel</a></li>
            </ul>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <input type="hidden" name="page" value="reports/products">
                <div class="col-md-3">
                    <label class="form-label">Category</label>
                    <select class="form-control" name="category_id">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= $categoryId == $cat['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['category_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select class="form-control" name="status">
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">From</label>
                    <input type="date" class="form-control" name="date_from" value="<?= $dateFrom ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">To</label>
                    <input type="date" class="form-control" name="date_to" value="<?= $dateTo ?>">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i></button>
                </div>
            </form>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2">
                <div class="card-body">
                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Total Products</div>
                    <div class="h5 mb-0 font-weight-bold"><?= number_format((float) $totalProducts) ?></div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2">
                <div class="card-body">
                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Total Stock</div>
                    <div class="h5 mb-0 font-weight-bold"><?= number_format((float) $totalStock) ?></div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-info shadow h-100 py-2">
                <div class="card-body">
                    <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Total Revenue</div>
                    <div class="h5 mb-0 font-weight-bold"><?= number_format((float) $totalRevenue) ?></div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2">
                <div class="card-body">
                    <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Out of Stock</div>
                    <div class="h5 mb-0 font-weight-bold"><?= count($outOfStockProducts) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-xl-8 col-lg-7 mb-4">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Top 10 Products by Revenue</h6>
                </div>
                <div class="card-body"><canvas id="topProductsChart" height="300"></canvas></div>
            </div>
        </div>
        <div class="col-xl-4 col-lg-5 mb-4">
            <div class="card shadow">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Inventory by Category</h6>
                </div>
                <div class="card-body"><canvas id="categoryChart" height="300"></canvas></div>
            </div>
        </div>
    </div>

    <div class="card shadow mb-4">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover" id="productsTable">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Product Name</th>
                            <th>Category</th>
                            <th class="text-end">Stock</th>
                            <th class="text-end">Revenue</th>
                            <th>Last Sale</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($product['product_code']) ?></strong></td>
                                <td><?= htmlspecialchars($product['product_name']) ?></td>
                                <td><?= htmlspecialchars($product['category_name'] ?? 'N/A') ?></td>
                                <td class="text-end"><?= number_format((float) $product['total_stock']) ?></td>
                                <td class="text-end"><?= number_format((float) $product['revenue']) ?></td>
                                <td><?= $product['last_sale_date'] ? date('d/m/Y', strtotime($product['last_sale_date'])) : '-' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    const companyCurrency = '<?= $_SESSION['company_currency'] ?? 'RWF' ?>';
    const categoryData = <?= json_encode(array_values($inventoryByCategory)) ?>;
    const topProducts = <?= json_encode(array_slice($products, 0, 10)) ?>;
</script>
<?php $jsFiles = ['reports/products.js']; ?>