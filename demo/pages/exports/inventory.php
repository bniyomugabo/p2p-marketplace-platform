<?php
// pages/exports/inventory.php
// INVENTORY REPORT EXPORT (PDF/CSV)
declare(strict_types=1);

while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Inventory.php';
require_once __DIR__ . '/../../models/Warehouse.php';
require_once __DIR__ . '/../../models/Category.php';
require_once __DIR__ . '/../../models/Product.php';
require_once __DIR__ . '/../../models/Variant.php';
require_once __DIR__ . '/fpdf/fpdf.php';

$db = Database::getInstance();

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
$warehouseId = isset($_GET['warehouse_id']) ? (int) $_GET['warehouse_id'] : null;
$categoryId = isset($_GET['category_id']) ? (int) $_GET['category_id'] : null;
$stockStatus = $_GET['stock_status'] ?? 'all';

// Initialize models with company context
$inventoryModel = new Inventory($companyId);
$warehouseModel = new Warehouse($companyId);
$categoryModel = new Category($companyId);
$productModel = new Product($companyId);
$variantModel = new Variant($companyId);

// Get data
$summary = $inventoryModel->getStockSummary();
$valuationByWarehouse = $inventoryModel->getValuationByWarehouse();
$valuationByCategory = $inventoryModel->getValuationByCategory();
$stockAging = $inventoryModel->getStockAging(90);
$lowStockItems = $inventoryModel->getLowStock(100);
$outOfStockItems = $inventoryModel->getOutOfStock($warehouseId);

// Get stock list with filters
$stockList = $inventoryModel->getStockList($warehouseId, null);

// Get product details for each item
foreach ($stockList as &$item) {
    if (isset($item['variant_id'])) {
        $variant = $variantModel->getWithDetails($item['variant_id']);
        if ($variant) {
            $item['product_id'] = $variant['product_id'];
            $item['product_name'] = $variant['product_name'];
            $item['variant_name'] = $variant['variant_name'];
            $item['sku'] = $variant['sku'];
            $item['category_id'] = $variant['category_id'];
            $item['category_name'] = $variant['category_name'];
            $item['reorder_level'] = $variant['reorder_level'];
        }
    }
}

// Filter by category if specified
if ($categoryId) {
    $stockList = array_filter($stockList, function ($item) use ($categoryId) {
        return ($item['category_id'] ?? 0) == $categoryId;
    });
}

// Filter by stock status
if ($stockStatus === 'low') {
    $stockList = array_filter($stockList, function ($item) {
        $qty = $item['quantity'] ?? 0;
        $reorder = $item['reorder_level'] ?? 10;
        return $qty <= $reorder && $qty > 0;
    });
} elseif ($stockStatus === 'out') {
    $stockList = array_filter($stockList, function ($item) {
        return ($item['quantity'] ?? 0) <= 0;
    });
} elseif ($stockStatus === 'normal') {
    $stockList = array_filter($stockList, function ($item) {
        $qty = $item['quantity'] ?? 0;
        $reorder = $item['reorder_level'] ?? 10;
        return $qty > $reorder;
    });
}

// Get warehouse name if filtered
$warehouseName = '';
if ($warehouseId) {
    $warehouse = $warehouseModel->find($warehouseId);
    $warehouseName = $warehouse ? $warehouse['warehouse_name'] : '';
}

// Get category name if filtered
$categoryName = '';
if ($categoryId) {
    $category = $categoryModel->find($categoryId);
    $categoryName = $category ? $category['category_name'] : '';
}

// Get company info for report header
$company = [];
$companyStmt = $db->prepare("SELECT company_name, address, phone, email FROM companies WHERE id = ?");
$companyStmt->execute([$companyId]);
$company = $companyStmt->fetch(PDO::FETCH_ASSOC);

// Clean output buffer
ob_end_clean();

if ($format === 'pdf') {
    // PDF Export
    class InventoryReportPDF extends FPDF
    {
        private $summary;
        private $valuationByWarehouse;
        private $valuationByCategory;
        private $stockAging;
        private $lowStockItems;
        private $outOfStockItems;
        private $stockList;
        private $warehouseName;
        private $categoryName;
        private $stockStatus;
        private $company;

        function __construct(
            $summary,
            $valuationByWarehouse,
            $valuationByCategory,
            $stockAging,
            $lowStockItems,
            $outOfStockItems,
            $stockList,
            $warehouseName,
            $categoryName,
            $stockStatus,
            $company
        ) {
            parent::__construct('L', 'mm', 'A4');
            $this->summary = $summary;
            $this->valuationByWarehouse = $valuationByWarehouse;
            $this->valuationByCategory = $valuationByCategory;
            $this->stockAging = $stockAging;
            $this->lowStockItems = $lowStockItems;
            $this->outOfStockItems = $outOfStockItems;
            $this->stockList = $stockList;
            $this->warehouseName = $warehouseName;
            $this->categoryName = $categoryName;
            $this->stockStatus = $stockStatus;
            $this->company = $company;
        }

        function Header()
        {
            // Company header
            $this->SetFont('Arial', 'B', 14);
            $this->SetTextColor(41, 128, 185);
            $this->Cell(0, 8, $this->company['company_name'] ?? 'SATI ERP', 0, 1, 'C');

            $this->SetFont('Arial', 'B', 16);
            $this->Cell(0, 10, 'INVENTORY REPORT', 0, 1, 'C');

            $this->SetFont('Arial', '', 10);
            $this->SetTextColor(100, 100, 100);

            $filterText = [];
            if ($this->warehouseName) {
                $filterText[] = 'Warehouse: ' . $this->warehouseName;
            }
            if ($this->categoryName) {
                $filterText[] = 'Category: ' . $this->categoryName;
            }
            if ($this->stockStatus !== 'all') {
                $statusLabels = ['low' => 'Low Stock', 'out' => 'Out of Stock', 'normal' => 'Normal Stock'];
                $filterText[] = 'Status: ' . ($statusLabels[$this->stockStatus] ?? $this->stockStatus);
            }

            $filterString = empty($filterText) ? 'All Inventory' : implode(' | ', $filterText);

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
            $this->Cell($colWidth, 7, 'Total Stock Value:', 0, 0);
            $this->Cell($colWidth, 7, number_format((float) ($this->summary['stock_value'] ?? 0), 0) . ' RWF', 0, 1);

            $this->SetX($startX);
            $this->Cell($colWidth, 7, 'Total Units:', 0, 0);
            $this->Cell($colWidth, 7, number_format((float) ($this->summary['total_stock'] ?? 0), 0), 0, 1);

            $this->SetX($startX);
            $this->Cell($colWidth, 7, 'Unique Products:', 0, 0);
            $this->Cell($colWidth, 7, number_format((float) ($this->summary['unique_products'] ?? 0), 0), 0, 1);

            $this->SetX($startX);
            $this->Cell($colWidth, 7, 'Low Stock Items:', 0, 0);
            $this->Cell($colWidth, 7, number_format((float) ($this->summary['low_stock'] ?? 0), 0), 0, 1);

            $this->SetX($startX);
            $this->Cell($colWidth, 7, 'Out of Stock:', 0, 0);
            $this->Cell($colWidth, 7, number_format((float) ($this->summary['out_of_stock'] ?? 0), 0), 0, 1);

            $this->Ln(8);
        }

        function ValuationByWarehouseSection()
        {
            if (empty($this->valuationByWarehouse))
                return;

            $this->SetFont('Arial', 'B', 12);
            $this->SetTextColor(41, 128, 185);
            $this->Cell(0, 8, 'INVENTORY BY WAREHOUSE', 0, 1);
            $this->Ln(2);

            // Table header
            $this->SetFillColor(41, 128, 185);
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Arial', 'B', 9);

            $col1 = 60;
            $col2 = 40;
            $col3 = 40;
            $col4 = 50;

            $this->Cell($col1, 8, 'Warehouse', 1, 0, 'C', true);
            $this->Cell($col2, 8, 'Products', 1, 0, 'C', true);
            $this->Cell($col3, 8, 'Quantity', 1, 0, 'C', true);
            $this->Cell($col4, 8, 'Value', 1, 1, 'C', true);

            $this->SetTextColor(0, 0, 0);
            $this->SetFont('Arial', '', 9);

            $totalValue = 0;
            $totalQty = 0;
            $totalProducts = 0;

            foreach ($this->valuationByWarehouse as $warehouse) {
                $this->Cell($col1, 7, $warehouse['warehouse_name'], 1);
                $this->Cell($col2, 7, number_format((float) ($warehouse['product_count'] ?? 0), 0), 1, 0, 'C');
                $this->Cell($col3, 7, number_format((float) ($warehouse['total_quantity'] ?? 0), 0), 1, 0, 'R');
                $this->Cell($col4, 7, number_format((float) ($warehouse['total_value'] ?? 0), 0) . ' RWF', 1, 1, 'R');

                $totalValue += $warehouse['total_value'] ?? 0;
                $totalQty += $warehouse['total_quantity'] ?? 0;
                $totalProducts += $warehouse['product_count'] ?? 0;
            }

            // Totals row
            $this->SetFont('Arial', 'B', 9);
            $this->SetFillColor(240, 240, 240);
            $this->Cell($col1, 7, 'TOTAL', 1, 0, 'R', true);
            $this->Cell($col2, 7, number_format((float)$totalProducts, 0), 1, 0, 'C', true);
            $this->Cell($col3, 7, number_format((float)$totalQty, 0), 1, 0, 'R', true);
            $this->Cell($col4, 7, number_format((float)$totalValue, 0) . ' RWF', 1, 1, 'R', true);

            $this->Ln(8);
        }

        function StockListSection()
        {
            if (empty($this->stockList))
                return;

            $this->SetFont('Arial', 'B', 12);
            $this->SetTextColor(41, 128, 185);
            $this->Cell(0, 8, 'CURRENT STOCK LEVELS', 0, 1);
            $this->Ln(2);

            // Table header
            $this->SetFillColor(41, 128, 185);
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Arial', 'B', 8);

            $col1 = 35;
            $col2 = 40;
            $col3 = 25;
            $col4 = 30;
            $col5 = 20;
            $col6 = 20;
            $col7 = 30;
            $col8 = 25;

            $this->Cell($col1, 8, 'Product', 1, 0, 'C', true);
            $this->Cell($col2, 8, 'SKU', 1, 0, 'C', true);
            $this->Cell($col3, 8, 'Category', 1, 0, 'C', true);
            $this->Cell($col4, 8, 'Warehouse', 1, 0, 'C', true);
            $this->Cell($col5, 8, 'Qty', 1, 0, 'C', true);
            $this->Cell($col6, 8, 'Available', 1, 0, 'C', true);
            $this->Cell($col7, 8, 'Value', 1, 0, 'C', true);
            $this->Cell($col8, 8, 'Status', 1, 1, 'C', true);

            $this->SetTextColor(0, 0, 0);
            $this->SetFont('Arial', '', 8);

            $totalQty = 0;
            $totalValue = 0;
            $rowCount = 0;

            foreach ($this->stockList as $item) {
                if ($this->GetY() > 180) {
                    $this->AddPage();
                    $this->SetFillColor(41, 128, 185);
                    $this->SetTextColor(255, 255, 255);
                    $this->SetFont('Arial', 'B', 8);
                    $this->Cell($col1, 8, 'Product', 1, 0, 'C', true);
                    $this->Cell($col2, 8, 'SKU', 1, 0, 'C', true);
                    $this->Cell($col3, 8, 'Category', 1, 0, 'C', true);
                    $this->Cell($col4, 8, 'Warehouse', 1, 0, 'C', true);
                    $this->Cell($col5, 8, 'Qty', 1, 0, 'C', true);
                    $this->Cell($col6, 8, 'Available', 1, 0, 'C', true);
                    $this->Cell($col7, 8, 'Value', 1, 0, 'C', true);
                    $this->Cell($col8, 8, 'Status', 1, 1, 'C', true);
                    $this->SetTextColor(0, 0, 0);
                    $this->SetFont('Arial', '', 8);
                }

                $productName = $item['product_name'] ?? 'Unknown';
                if (!empty($item['variant_name']) && $item['variant_name'] !== 'Standard') {
                    $productName .= ' - ' . $item['variant_name'];
                }

                $stockValue = ($item['quantity'] ?? 0) * ($item['avg_landed_cost'] ?? 0);
                $qty = $item['quantity'] ?? 0;
                $reorder = $item['reorder_level'] ?? 10;

                $status = 'Normal';
                $statusColor = [0, 128, 0];
                if ($qty <= 0) {
                    $status = 'Out of Stock';
                    $statusColor = [231, 76, 60];
                } elseif ($qty <= $reorder) {
                    $status = 'Low Stock';
                    $statusColor = [241, 196, 15];
                }

                $this->Cell($col1, 7, $this->shortenText($productName, 30), 1);
                $this->Cell($col2, 7, $item['sku'] ?? '-', 1, 0, 'C');
                $this->Cell($col3, 7, $this->shortenText($item['category_name'] ?? 'N/A', 15), 1);
                $this->Cell($col4, 7, $this->shortenText($item['warehouse_name'] ?? 'N/A', 20), 1);
                $this->Cell($col5, 7, number_format((float)$qty, 0), 1, 0, 'R');
                $this->Cell($col6, 7, number_format((float)$item['available_quantity'] ?? 0, 0), 1, 0, 'R');
                $this->Cell($col7, 7, number_format((float)$stockValue, 0) . ' RWF', 1, 0, 'R');

                $this->SetTextColor($statusColor[0], $statusColor[1], $statusColor[2]);
                $this->Cell($col8, 7, $status, 1, 1, 'C');
                $this->SetTextColor(0, 0, 0);

                $totalQty += $qty;
                $totalValue += $stockValue;
                $rowCount++;
            }

            // Totals row
            $this->SetFont('Arial', 'B', 8);
            $this->SetFillColor(240, 240, 240);
            $this->Cell($col1 + $col2 + $col3 + $col4, 7, 'TOTAL (' . $rowCount . ' items)', 1, 0, 'R', true);
            $this->Cell($col5, 7, number_format((float)$totalQty, 0), 1, 0, 'R', true);
            $this->Cell($col6, 7, '', 1, 0, 'R', true);
            $this->Cell($col7, 7, number_format((float)$totalValue, 0) . ' RWF', 1, 0, 'R', true);
            $this->Cell($col8, 7, '', 1, 1, 'R', true);
        }

        function LowStockSection()
        {
            if (empty($this->lowStockItems))
                return;

            $this->AddPage();
            $this->SetFont('Arial', 'B', 14);
            $this->SetTextColor(241, 196, 15);
            $this->Cell(0, 10, 'LOW STOCK ALERTS', 0, 1, 'C');
            $this->Ln(5);

            $this->SetFillColor(241, 196, 15);
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Arial', 'B', 10);

            $col1 = 70;
            $col2 = 35;
            $col3 = 30;
            $col4 = 30;
            $col5 = 40;

            $this->Cell($col1, 8, 'Product', 1, 0, 'C', true);
            $this->Cell($col2, 8, 'SKU', 1, 0, 'C', true);
            $this->Cell($col3, 8, 'Current Stock', 1, 0, 'C', true);
            $this->Cell($col4, 8, 'Reorder Level', 1, 0, 'C', true);
            $this->Cell($col5, 8, 'Warehouse', 1, 1, 'C', true);

            $this->SetTextColor(0, 0, 0);
            $this->SetFont('Arial', '', 9);

            foreach ($this->lowStockItems as $item) {
                $productName = $item['product_name'] ?? 'Unknown';
                if (!empty($item['variant_name'])) {
                    $productName .= ' - ' . $item['variant_name'];
                }

                $this->Cell($col1, 7, $this->shortenText($productName, 40), 1);
                $this->Cell($col2, 7, $item['sku'] ?? '-', 1, 0, 'C');
                $this->Cell($col3, 7, number_format((float)$item['quantity'] ?? 0, 0), 1, 0, 'R');
                $this->Cell($col4, 7, number_format((float)$item['reorder_level'] ?? 0, 0), 1, 0, 'R');
                $this->Cell($col5, 7, $item['warehouse_name'] ?? 'N/A', 1, 1, 'L');
            }
        }

        private function shortenText($text, $length)
        {
            if (strlen($text) > $length) {
                return substr($text, 0, $length - 3) . '...';
            }
            return $text;
        }
    }

    $pdf = new InventoryReportPDF(
        $summary,
        $valuationByWarehouse,
        $valuationByCategory,
        $stockAging,
        $lowStockItems,
        $outOfStockItems,
        $stockList,
        $warehouseName,
        $categoryName,
        $stockStatus,
        $company
    );
    $pdf->AliasNbPages();
    $pdf->AddPage();

    $pdf->SummarySection();
    $pdf->ValuationByWarehouseSection();
    $pdf->StockListSection();
    $pdf->LowStockSection();

    $filename = 'Inventory_Report_' . date('Y-m-d') . '.pdf';
    $pdf->Output('D', $filename);
    exit;

} elseif ($format === 'excel') {
    // Excel/CSV Export
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Inventory_Report_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Company info
    fputcsv($output, [$company['company_name'] ?? 'SATI ERP']);
    fputcsv($output, ['INVENTORY REPORT']);
    fputcsv($output, ['Generated:', date('d/m/Y H:i:s')]);
    if ($warehouseName) {
        fputcsv($output, ['Warehouse:', $warehouseName]);
    }
    if ($categoryName) {
        fputcsv($output, ['Category:', $categoryName]);
    }
    fputcsv($output, []);

    // Summary
    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Total Stock Value', number_format((float) ($summary['stock_value'] ?? 0), 0) . ' RWF']);
    fputcsv($output, ['Total Units', number_format((float) ($summary['total_stock'] ?? 0), 0)]);
    fputcsv($output, ['Unique Products', number_format((float) ($summary['unique_products'] ?? 0), 0)]);
    fputcsv($output, ['Low Stock Items', number_format((float) ($summary['low_stock'] ?? 0), 0)]);
    fputcsv($output, ['Out of Stock', number_format((float) ($summary['out_of_stock'] ?? 0), 0)]);
    fputcsv($output, []);

    // Stock List
    fputcsv($output, ['CURRENT STOCK LEVELS']);
    fputcsv($output, ['Product', 'SKU', 'Category', 'Warehouse', 'Quantity', 'Available', 'Unit Cost', 'Total Value', 'Status']);

    foreach ($stockList as $item) {
        $productName = $item['product_name'] ?? 'Unknown';
        if (!empty($item['variant_name']) && $item['variant_name'] !== 'Standard') {
            $productName .= ' - ' . $item['variant_name'];
        }

        $stockValue = ($item['quantity'] ?? 0) * ($item['avg_landed_cost'] ?? 0);
        $qty = $item['quantity'] ?? 0;
        $reorder = $item['reorder_level'] ?? 10;

        $status = 'Normal';
        if ($qty <= 0) {
            $status = 'Out of Stock';
        } elseif ($qty <= $reorder) {
            $status = 'Low Stock';
        }

        fputcsv($output, [
            $productName,
            $item['sku'] ?? '-',
            $item['category_name'] ?? 'N/A',
            $item['warehouse_name'] ?? 'N/A',
            number_format((float)$qty, 0),
            number_format((float)$item['available_quantity'] ?? 0, 0),
            number_format((float) ($item['avg_landed_cost'] ?? 0), 0) . ' RWF',
            number_format((float)$stockValue, 0) . ' RWF',
            $status
        ]);
    }
    fputcsv($output, []);

    // Low Stock Items
    if (!empty($lowStockItems)) {
        fputcsv($output, ['LOW STOCK ITEMS']);
        fputcsv($output, ['Product', 'SKU', 'Current Stock', 'Reorder Level', 'Warehouse']);

        foreach ($lowStockItems as $item) {
            $productName = $item['product_name'] ?? 'Unknown';
            if (!empty($item['variant_name'])) {
                $productName .= ' - ' . $item['variant_name'];
            }

            fputcsv($output, [
                $productName,
                $item['sku'] ?? '-',
                number_format((float)$item['quantity'] ?? 0, 0),
                number_format((float)$item['reorder_level'] ?? 0, 0),
                $item['warehouse_name'] ?? 'N/A'
            ]);
        }
    }

    fclose($output);
    exit;
}

// If we get here, something went wrong
header('HTTP/1.1 400 Bad Request');
echo 'Invalid export format';
exit;