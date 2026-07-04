<?php
// pages/exports/products.php
// PRODUCT REPORT EXPORT (PDF/CSV)
declare(strict_types=1);

while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Product.php';
require_once __DIR__ . '/../../models/Category.php';
require_once __DIR__ . '/../../models/Inventory.php';
require_once __DIR__ . '/fpdf/fpdf.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    header('HTTP/1.1 401 Unauthorized');
    exit('Authentication required.');
}

$userRole = $_SESSION['user_role'] ?? 'VIW';
$companyId = $_SESSION['company_id'] ?? null;

// Check permission
if (!in_array($userRole, ['ADM', 'MGR', 'ACC', 'WHS'])) {
    ob_end_clean();
    header('HTTP/1.1 403 Forbidden');
    exit('Permission denied.');
}

// Check if company context is set
if (!$companyId) {
    ob_end_clean();
    header('HTTP/1.1 400 Bad Request');
    exit('Company context not found.');
}

// Get parameters
$format = $_GET['format'] ?? 'pdf';
$categoryId = isset($_GET['category_id']) ? (int) $_GET['category_id'] : null;
$status = $_GET['status'] ?? 'active';
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Initialize models with company context
$productModel = new Product($companyId);
$categoryModel = new Category($companyId);
$inventoryModel = new Inventory($companyId);

// Get database connection
$db = Database::getInstance();

// Get categories for filter
$categories = $categoryModel->all(['id', 'category_name']);

// Get products with sales and inventory data (company-specific)
$sql = "
    SELECT 
        p.id,
        p.product_code,
        p.product_name,
        p.description,
        p.category_id,
        p.brand,
        p.has_variants,
        p.unit_of_measure,
        p.is_active,
        p.created_at,
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

if ($status === 'active') {
    $sql .= " AND p.is_active = 1";
} elseif ($status === 'inactive') {
    $sql .= " AND p.is_active = 0";
}

$sql .= " GROUP BY p.id ORDER BY p.product_name ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Calculate summary statistics
$totalProducts = count($products);
$totalStock = array_sum(array_column($products, 'total_stock'));
$totalValue = array_sum(array_column($products, 'inventory_value'));
$totalRevenue = array_sum(array_column($products, 'revenue'));
$totalUnitsSold = array_sum(array_column($products, 'units_sold'));

// Get category name if filtered
$categoryName = '';
if ($categoryId) {
    $category = $categoryModel->find($categoryId);
    $categoryName = $category ? $category['category_name'] : '';
}

// Get company info for report header
$company = [];
$companyStmt = $db->prepare("SELECT company_name, address, phone, email, currency FROM companies WHERE id = ?");
$companyStmt->execute([$companyId]);
$company = $companyStmt->fetch(PDO::FETCH_ASSOC);
$currency = $company['currency'] ?? 'RWF';

// Clean output buffer
ob_end_clean();

if ($format === 'pdf') {
    // PDF Export class definition
    class ProductReportPDF extends FPDF
    {
        private $products;
        private $totalProducts;
        private $totalStock;
        private $totalValue;
        private $totalRevenue;
        private $totalUnitsSold;
        private $categoryName;
        private $dateFrom;
        private $dateTo;
        private $status;
        private $company;
        private $currency;

        function __construct(
            $products,
            $totalProducts,
            $totalStock,
            $totalValue,
            $totalRevenue,
            $totalUnitsSold,
            $categoryName,
            $dateFrom,
            $dateTo,
            $status,
            $company,
            $currency
        ) {
            parent::__construct('L', 'mm', 'A4');
            $this->products = $products;
            $this->totalProducts = $totalProducts;
            $this->totalStock = $totalStock;
            $this->totalValue = $totalValue;
            $this->totalRevenue = $totalRevenue;
            $this->totalUnitsSold = $totalUnitsSold;
            $this->categoryName = $categoryName;
            $this->dateFrom = $dateFrom;
            $this->dateTo = $dateTo;
            $this->status = $status;
            $this->company = $company;
            $this->currency = $currency;
        }

        function Header()
        {
            // Company header
            $this->SetFont('Arial', 'B', 14);
            $this->SetTextColor(41, 128, 185);
            $this->Cell(0, 8, $this->company['company_name'] ?? 'SATI ERP', 0, 1, 'C');

            $this->SetFont('Arial', 'B', 16);
            $this->Cell(0, 10, 'PRODUCT REPORT', 0, 1, 'C');

            $this->SetFont('Arial', '', 10);
            $this->SetTextColor(100, 100, 100);

            $filterText = [];
            if ($this->categoryName) {
                $filterText[] = 'Category: ' . $this->categoryName;
            }
            if ($this->status !== 'all') {
                $filterText[] = 'Status: ' . ucfirst($this->status);
            }
            $filterText[] = 'Period: ' . date('d/m/Y', strtotime($this->dateFrom)) . ' - ' . date('d/m/Y', strtotime($this->dateTo));

            $filterString = empty($filterText) ? 'All Products' : implode(' | ', $filterText);

            $this->Cell(0, 6, $filterString, 0, 1, 'C');
            $this->Cell(0, 6, 'Generated: ' . date('d/m/Y H:i:s'), 0, 1, 'C');

            $this->Ln(5);
            $this->SetDrawColor(200, 200, 200);
            $this->Line(10, $this->GetY(), 290, $this->GetY());
            $this->Ln(8);
        }

        function Footer()
        {
            $this->SetY(-15);
            $this->SetFont('Arial', 'I', 8);
            $this->SetTextColor(150, 150, 150);
            $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
        }

        function SummarySection()
        {
            $this->SetFont('Arial', 'B', 12);
            $this->SetTextColor(41, 128, 185);
            $this->Cell(0, 8, 'SUMMARY', 0, 1);
            $this->Ln(2);

            $this->SetFont('Arial', '', 10);
            $this->SetTextColor(0, 0, 0);

            $colWidth = 70;
            $startX = 20;

            $this->SetX($startX);
            $this->Cell($colWidth, 7, 'Total Products:', 0, 0);
            $this->Cell($colWidth, 7, number_format((float)$this->totalProducts, 0), 0, 1);

            $this->SetX($startX);
            $this->Cell($colWidth, 7, 'Total Stock:', 0, 0);
            $this->Cell($colWidth, 7, number_format((float)$this->totalStock, 0), 0, 1);

            $this->SetX($startX);
            $this->Cell($colWidth, 7, 'Inventory Value:', 0, 0);
            $this->Cell($colWidth, 7, number_format((float)$this->totalValue, 0) . ' ' . $this->currency, 0, 1);

            $this->SetX($startX);
            $this->Cell($colWidth, 7, 'Units Sold:', 0, 0);
            $this->Cell($colWidth, 7, number_format((float)$this->totalUnitsSold, 0), 0, 1);

            $this->SetX($startX);
            $this->Cell($colWidth, 7, 'Total Revenue:', 0, 0);
            $this->Cell($colWidth, 7, number_format((float)$this->totalRevenue, 0) . ' ' . $this->currency, 0, 1);

            $this->Ln(8);
        }

        function ProductsTable()
        {
            $this->SetFont('Arial', 'B', 12);
            $this->SetTextColor(41, 128, 185);
            $this->Cell(0, 8, 'PRODUCT DETAILS', 0, 1);
            $this->Ln(2);

            // Table header
            $this->SetFillColor(41, 128, 185);
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Arial', 'B', 8);

            $col1 = 35;
            $col2 = 45;
            $col3 = 25;
            $col4 = 18;
            $col5 = 15;
            $col6 = 18;
            $col7 = 25;
            $col8 = 18;
            $col9 = 28;
            $col10 = 25;

            $this->Cell($col1, 8, 'Code', 1, 0, 'C', true);
            $this->Cell($col2, 8, 'Product', 1, 0, 'C', true);
            $this->Cell($col3, 8, 'Category', 1, 0, 'C', true);
            $this->Cell($col4, 8, 'Brand', 1, 0, 'C', true);
            $this->Cell($col5, 8, 'Var', 1, 0, 'C', true);
            $this->Cell($col6, 8, 'Stock', 1, 0, 'C', true);
            $this->Cell($col7, 8, 'Value', 1, 0, 'C', true);
            $this->Cell($col8, 8, 'Sold', 1, 0, 'C', true);
            $this->Cell($col9, 8, 'Revenue', 1, 0, 'C', true);
            $this->Cell($col10, 8, 'Last Sale', 1, 1, 'C', true);

            $this->SetTextColor(0, 0, 0);
            $this->SetFont('Arial', '', 8);

            $totalValue = 0;
            $totalRevenue = 0;
            $totalStock = 0;
            $totalSold = 0;

            foreach ($this->products as $product) {
                if ($this->GetY() > 180) {
                    $this->AddPage();
                    $this->SetFillColor(41, 128, 185);
                    $this->SetTextColor(255, 255, 255);
                    $this->SetFont('Arial', 'B', 8);
                    $this->Cell($col1, 8, 'Code', 1, 0, 'C', true);
                    $this->Cell($col2, 8, 'Product', 1, 0, 'C', true);
                    $this->Cell($col3, 8, 'Category', 1, 0, 'C', true);
                    $this->Cell($col4, 8, 'Brand', 1, 0, 'C', true);
                    $this->Cell($col5, 8, 'Var', 1, 0, 'C', true);
                    $this->Cell($col6, 8, 'Stock', 1, 0, 'C', true);
                    $this->Cell($col7, 8, 'Value', 1, 0, 'C', true);
                    $this->Cell($col8, 8, 'Sold', 1, 0, 'C', true);
                    $this->Cell($col9, 8, 'Revenue', 1, 0, 'C', true);
                    $this->Cell($col10, 8, 'Last Sale', 1, 1, 'C', true);
                    $this->SetTextColor(0, 0, 0);
                    $this->SetFont('Arial', '', 8);
                }

                $productName = $this->shortenText($product['product_name'], 30);

                // Determine stock status color
                if ($product['total_stock'] <= 0) {
                    $this->SetTextColor(231, 76, 60);
                } elseif ($product['total_stock'] <= 10) {
                    $this->SetTextColor(241, 196, 15);
                } else {
                    $this->SetTextColor(0, 0, 0);
                }

                $this->Cell($col1, 7, $product['product_code'], 1);
                $this->SetTextColor(0, 0, 0);
                $this->Cell($col2, 7, $productName, 1);
                $this->Cell($col3, 7, $this->shortenText($product['category_name'] ?? 'N/A', 15), 1);
                $this->Cell($col4, 7, $this->shortenText($product['brand'] ?? '-', 12), 1);
                $this->Cell($col5, 7, $product['variant_count'], 1, 0, 'C');
                $this->Cell($col6, 7, number_format((float)$product['total_stock'], 0), 1, 0, 'R');
                $this->Cell($col7, 7, number_format((float)$product['inventory_value'], 0) . ' ' . $this->currency, 1, 0, 'R');
                $this->Cell($col8, 7, number_format((float)$product['units_sold'], 0), 1, 0, 'R');
                $this->Cell($col9, 7, number_format((float)$product['revenue'], 0) . ' ' . $this->currency, 1, 0, 'R');

                $lastSale = $product['last_sale_date'] ? date('d/m/Y', strtotime($product['last_sale_date'])) : '-';
                $this->Cell($col10, 7, $lastSale, 1, 1, 'C');

                $totalValue += $product['inventory_value'];
                $totalRevenue += $product['revenue'];
                $totalStock += $product['total_stock'];
                $totalSold += $product['units_sold'];
            }

            // Totals row
            $this->SetFont('Arial', 'B', 8);
            $this->SetFillColor(240, 240, 240);
            $this->Cell($col1 + $col2 + $col3 + $col4 + $col5, 7, 'TOTAL', 1, 0, 'R', true);
            $this->Cell($col6, 7, number_format((float)$totalStock, 0), 1, 0, 'R', true);
            $this->Cell($col7, 7, number_format((float)$totalValue, 0) . ' ' . $this->currency, 1, 0, 'R', true);
            $this->Cell($col8, 7, number_format((float)$totalSold, 0), 1, 0, 'R', true);
            $this->Cell($col9, 7, number_format((float)$totalRevenue, 0) . ' ' . $this->currency, 1, 0, 'R', true);
            $this->Cell($col10, 7, '', 1, 1, 'R', true);
        }

        private function shortenText($text, $length)
        {
            if (strlen($text) > $length) {
                return substr($text, 0, $length - 3) . '...';
            }
            return $text;
        }
    }

    // Create PDF
    $pdf = new ProductReportPDF(
        $products,
        $totalProducts,
        $totalStock,
        $totalValue,
        $totalRevenue,
        $totalUnitsSold,
        $categoryName,
        $dateFrom,
        $dateTo,
        $status,
        $company,
        $currency
    );
    $pdf->AliasNbPages();
    $pdf->AddPage();

    $pdf->SummarySection();
    $pdf->ProductsTable();

    // Make sure no output buffer is active
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Set headers
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Product_Report_' . date('Y-m-d') . '.pdf"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    // Output PDF
    $pdf->Output('D', 'Product_Report_' . date('Y-m-d') . '.pdf');
    exit;

} elseif ($format === 'excel') {
    // Make sure no output buffer is active
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Excel/CSV Export
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Product_Report_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Company info
    fputcsv($output, [$company['company_name'] ?? 'SATI ERP']);
    fputcsv($output, ['PRODUCT REPORT']);
    fputcsv($output, ['Generated:', date('d/m/Y H:i:s')]);
    if ($categoryName)
        fputcsv($output, ['Category:', $categoryName]);
    fputcsv($output, ['Period:', date('d/m/Y', strtotime($dateFrom)), 'to', date('d/m/Y', strtotime($dateTo))]);
    fputcsv($output, []);

    // Summary
    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Total Products', number_format((float)$totalProducts, 0)]);
    fputcsv($output, ['Total Stock', number_format((float)$totalStock, 0)]);
    fputcsv($output, ['Inventory Value', number_format((float)$totalValue, 0) . ' ' . $currency]);
    fputcsv($output, ['Units Sold', number_format((float)$totalUnitsSold, 0)]);
    fputcsv($output, ['Total Revenue', number_format((float)$totalRevenue, 0) . ' ' . $currency]);
    fputcsv($output, []);

    // Product Details
    fputcsv($output, ['PRODUCT DETAILS']);
    fputcsv($output, ['Code', 'Product', 'Category', 'Brand', 'Variants', 'Stock', 'Value', 'Units Sold', 'Revenue', 'Last Sale']);

    foreach ($products as $product) {
        $productName = $product['product_name'];

        fputcsv($output, [
            $product['product_code'],
            $productName,
            $product['category_name'] ?? 'N/A',
            $product['brand'] ?? '-',
            $product['variant_count'],
            number_format((float)$product['total_stock'], 0),
            number_format((float)$product['inventory_value'], 0) . ' ' . $currency,
            number_format((float)$product['units_sold'], 0),
            number_format((float)$product['revenue'], 0) . ' ' . $currency,
            $product['last_sale_date'] ? date('d/m/Y', strtotime($product['last_sale_date'])) : '-'
        ]);
    }

    fclose($output);
    exit;
}

// If we get here, something went wrong
header('HTTP/1.1 400 Bad Request');
echo 'Invalid export format';
exit;