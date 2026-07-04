<?php
// pages/exports/inventory.php
// Stock Report Export (PDF/CSV)
declare(strict_types=1);

// Clean output buffers
while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../config/database.php';
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
$sortBy = $_GET['sort_by'] ?? 'product';

// Initialize models with company context
$inventoryModel = new Inventory($companyId);
$warehouseModel = new Warehouse($companyId);
$categoryModel = new Category($companyId);
$productModel = new Product($companyId);
$variantModel = new Variant($companyId);

// Get stock list with filters
$stockList = $inventoryModel->getStockList($warehouseId, null);

// Get product and category details for each item
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

// Apply sorting
if ($sortBy === 'product') {
    usort($stockList, function ($a, $b) {
        return strcmp($a['product_name'] ?? '', $b['product_name'] ?? '');
    });
} elseif ($sortBy === 'stock_asc') {
    usort($stockList, function ($a, $b) {
        return ($a['quantity'] ?? 0) - ($b['quantity'] ?? 0);
    });
} elseif ($sortBy === 'stock_desc') {
    usort($stockList, function ($a, $b) {
        return ($b['quantity'] ?? 0) - ($a['quantity'] ?? 0);
    });
} elseif ($sortBy === 'value_desc') {
    usort($stockList, function ($a, $b) {
        $valA = ($a['quantity'] ?? 0) * ($a['avg_landed_cost'] ?? 0);
        $valB = ($b['quantity'] ?? 0) * ($b['avg_landed_cost'] ?? 0);
        return $valB - $valA;
    });
}

// Calculate summary statistics
$totalItems = count($stockList);
$totalQuantity = array_sum(array_column($stockList, 'quantity'));
$totalValue = array_sum(array_map(function ($item) {
    return ($item['quantity'] ?? 0) * ($item['avg_landed_cost'] ?? 0);
}, $stockList));

$lowStockCount = count(array_filter($stockList, function ($item) {
    $qty = $item['quantity'] ?? 0;
    $reorder = $item['reorder_level'] ?? 10;
    return $qty <= $reorder && $qty > 0;
}));

$outOfStockCount = count(array_filter($stockList, function ($item) {
    return ($item['quantity'] ?? 0) <= 0;
}));

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

// Ensure we have arrays
if (!is_array($stockList)) {
    $stockList = [];
}

// Final buffer cleanup
while (ob_get_level()) {
    ob_end_clean();
}

if ($format === 'pdf') {
    // PDF Export
    class StockReportPDF extends FPDF
    {
        private $stockList;
        private $totalItems;
        private $totalQuantity;
        private $totalValue;
        private $lowStockCount;
        private $outOfStockCount;
        private $warehouseName;
        private $categoryName;
        private $stockStatus;
        private $sortBy;
        private $company;

        function __construct(
            $stockList,
            $totalItems,
            $totalQuantity,
            $totalValue,
            $lowStockCount,
            $outOfStockCount,
            $warehouseName,
            $categoryName,
            $stockStatus,
            $sortBy,
            $company
        ) {
            parent::__construct('L', 'mm', 'A4');
            $this->stockList = $stockList;
            $this->totalItems = $totalItems;
            $this->totalQuantity = $totalQuantity;
            $this->totalValue = $totalValue;
            $this->lowStockCount = $lowStockCount;
            $this->outOfStockCount = $outOfStockCount;
            $this->warehouseName = $warehouseName;
            $this->categoryName = $categoryName;
            $this->stockStatus = $stockStatus;
            $this->sortBy = $sortBy;
            $this->company = $company;
        }

        function Header()
        {
            // Company header
            $this->SetFont('Arial', 'B', 14);
            $this->SetTextColor(41, 128, 185);
            $this->Cell(0, 8, $this->company['company_name'] ?? 'SATI ERP', 0, 1, 'C');

            $this->SetFont('Arial', 'B', 16);
            $this->Cell(0, 10, 'CURRENT STOCK REPORT', 0, 1, 'C');

            $this->SetFont('Arial', '', 9);
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

            $sortLabels = [
                'product' => 'Product Name',
                'stock_asc' => 'Stock (Low to High)',
                'stock_desc' => 'Stock (High to Low)',
                'value_desc' => 'Value (High to Low)'
            ];
            $filterText[] = 'Sort: ' . ($sortLabels[$this->sortBy] ?? $this->sortBy);

            $filterString = empty($filterText) ? 'All Stock' : implode(' | ', $filterText);

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
            $this->Cell($colWidth, 7, number_format($this->totalItems, 0), 0, 1);

            $this->SetX($startX);
            $this->Cell($colWidth, 7, 'Total Units:', 0, 0);
            $this->Cell($colWidth, 7, number_format($this->totalQuantity, 0), 0, 1);

            $this->SetX($startX);
            $this->Cell($colWidth, 7, 'Total Stock Value:', 0, 0);
            $this->Cell($colWidth, 7, number_format($this->totalValue, 0) . ' RWF', 0, 1);

            $this->SetX($startX);
            $this->Cell($colWidth, 7, 'Low Stock Items:', 0, 0);
            $this->Cell($colWidth, 7, number_format($this->lowStockCount, 0), 0, 1);

            $this->SetX($startX);
            $this->Cell($colWidth, 7, 'Out of Stock:', 0, 0);
            $this->Cell($colWidth, 7, number_format($this->outOfStockCount, 0), 0, 1);

            $this->Ln(8);
        }

        function StockTable()
        {
            $this->SetFont('Arial', 'B', 12);
            $this->SetTextColor(41, 128, 185);
            $this->Cell(0, 8, 'STOCK DETAILS', 0, 1);
            $this->Ln(2);

            // Table header
            $this->SetFillColor(41, 128, 185);
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Arial', 'B', 8);

            $col1 = 45; // Product
            $col2 = 35; // SKU
            $col3 = 25; // Category
            $col4 = 30; // Warehouse
            $col5 = 18; // Qty
            $col6 = 18; // Available
            $col7 = 25; // Unit Cost
            $col8 = 30; // Total Value
            $col9 = 22; // Status
            $col10 = 22; // Reorder

            $this->Cell($col1, 8, 'Product', 1, 0, 'C', true);
            $this->Cell($col2, 8, 'SKU', 1, 0, 'C', true);
            $this->Cell($col3, 8, 'Category', 1, 0, 'C', true);
            $this->Cell($col4, 8, 'Warehouse', 1, 0, 'C', true);
            $this->Cell($col5, 8, 'Qty', 1, 0, 'C', true);
            $this->Cell($col6, 8, 'Available', 1, 0, 'C', true);
            $this->Cell($col7, 8, 'Unit Cost', 1, 0, 'C', true);
            $this->Cell($col8, 8, 'Total Value', 1, 0, 'C', true);
            $this->Cell($col9, 8, 'Status', 1, 0, 'C', true);
            $this->Cell($col10, 8, 'Reorder', 1, 1, 'C', true);

            $this->SetTextColor(0, 0, 0);
            $this->SetFont('Arial', '', 7);

            $totalValue = 0;
            $totalQty = 0;

            foreach ($this->stockList as $item) {
                if ($this->GetY() > 170) {
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
                    $this->Cell($col7, 8, 'Unit Cost', 1, 0, 'C', true);
                    $this->Cell($col8, 8, 'Total Value', 1, 0, 'C', true);
                    $this->Cell($col9, 8, 'Status', 1, 0, 'C', true);
                    $this->Cell($col10, 8, 'Reorder', 1, 1, 'C', true);
                    $this->SetTextColor(0, 0, 0);
                    $this->SetFont('Arial', '', 7);
                }

                $productName = $item['product_name'] ?? 'Unknown';
                if (!empty($item['variant_name']) && $item['variant_name'] !== 'Standard') {
                    $productName .= ' - ' . $item['variant_name'];
                }

                $qty = isset($item['quantity']) ? (float) $item['quantity'] : 0;
                $available = isset($item['available_quantity']) ? (float) $item['available_quantity'] : 0;
                $unitCost = isset($item['avg_landed_cost']) ? (float) $item['avg_landed_cost'] : 0;
                $itemValue = $qty * $unitCost;
                $reorderLevel = isset($item['reorder_level']) ? (int) $item['reorder_level'] : 0;

                // Determine status
                $status = 'Normal';
                $statusColor = [0, 128, 0];
                if ($qty <= 0) {
                    $status = 'Out of Stock';
                    $statusColor = [231, 76, 60];
                } elseif ($qty <= $reorderLevel) {
                    $status = 'Low Stock';
                    $statusColor = [241, 196, 15];
                }

                $this->Cell($col1, 6, $this->shortenText($productName, 30), 1);
                $this->Cell($col2, 6, $item['sku'] ?? '-', 1);
                $this->Cell($col3, 6, $this->shortenText($item['category_name'] ?? 'N/A', 15), 1);
                $this->Cell($col4, 6, $this->shortenText($item['warehouse_name'] ?? 'N/A', 20), 1);
                $this->Cell($col5, 6, number_format($qty, 0), 1, 0, 'R');
                $this->Cell($col6, 6, number_format($available, 0), 1, 0, 'R');
                $this->Cell($col7, 6, $unitCost > 0 ? number_format($unitCost, 0) . ' RWF' : '-', 1, 0, 'R');
                $this->Cell($col8, 6, number_format($itemValue, 0) . ' RWF', 1, 0, 'R');

                $this->SetTextColor($statusColor[0], $statusColor[1], $statusColor[2]);
                $this->Cell($col9, 6, $status, 1, 0, 'C');
                $this->SetTextColor(0, 0, 0);

                $this->Cell($col10, 6, $reorderLevel > 0 ? number_format($reorderLevel, 0) : '-', 1, 1, 'C');

                $totalValue += $itemValue;
                $totalQty += $qty;
            }

            // Totals row
            $this->SetFont('Arial', 'B', 8);
            $this->SetFillColor(240, 240, 240);
            $this->Cell($col1 + $col2 + $col3 + $col4, 6, 'TOTAL', 1, 0, 'R', true);
            $this->Cell($col5, 6, number_format($totalQty, 0), 1, 0, 'R', true);
            $this->Cell($col6, 6, '', 1, 0, 'R', true);
            $this->Cell($col7, 6, '', 1, 0, 'R', true);
            $this->Cell($col8, 6, number_format($totalValue, 0) . ' RWF', 1, 0, 'R', true);
            $this->Cell($col9, 6, '', 1, 0, 'R', true);
            $this->Cell($col10, 6, '', 1, 1, 'R', true);
        }

        private function shortenText($text, $length)
        {
            if (strlen($text) > $length) {
                return substr($text, 0, $length - 3) . '...';
            }
            return $text;
        }
    }

    $pdf = new StockReportPDF(
        $stockList,
        $totalItems,
        $totalQuantity,
        $totalValue,
        $lowStockCount,
        $outOfStockCount,
        $warehouseName,
        $categoryName,
        $stockStatus,
        $sortBy,
        $company
    );
    $pdf->AliasNbPages();
    $pdf->AddPage();

    $pdf->SummarySection();
    if (!empty($stockList)) {
        $pdf->StockTable();
    }

    // Final buffer cleanup
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Stock_Report_' . date('Y-m-d') . '.pdf"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    $pdf->Output('D', 'Stock_Report_' . date('Y-m-d') . '.pdf');
    exit;

} elseif ($format === 'excel') {
    // Final buffer cleanup
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Stock_Report_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Company info
    fputcsv($output, [$company['company_name'] ?? 'SATI ERP']);
    fputcsv($output, ['CURRENT STOCK REPORT']);
    fputcsv($output, []);
    fputcsv($output, ['Generated:', date('d/m/Y H:i:s')]);
    if ($warehouseName)
        fputcsv($output, ['Warehouse:', $warehouseName]);
    if ($categoryName)
        fputcsv($output, ['Category:', $categoryName]);
    if ($stockStatus !== 'all') {
        $statusLabels = ['low' => 'Low Stock', 'out' => 'Out of Stock', 'normal' => 'Normal Stock'];
        fputcsv($output, ['Status:', $statusLabels[$stockStatus] ?? $stockStatus]);
    }
    fputcsv($output, []);

    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Total Products', $totalItems]);
    fputcsv($output, ['Total Units', number_format($totalQuantity, 0)]);
    fputcsv($output, ['Total Stock Value', number_format($totalValue, 0) . ' RWF']);
    fputcsv($output, ['Low Stock Items', $lowStockCount]);
    fputcsv($output, ['Out of Stock', $outOfStockCount]);
    fputcsv($output, []);

    fputcsv($output, ['STOCK DETAILS']);
    fputcsv($output, ['Product', 'SKU', 'Category', 'Warehouse', 'Quantity', 'Available', 'Unit Cost', 'Total Value', 'Status', 'Reorder Level']);

    foreach ($stockList as $item) {
        $productName = $item['product_name'] ?? 'Unknown';
        if (!empty($item['variant_name']) && $item['variant_name'] !== 'Standard') {
            $productName .= ' - ' . $item['variant_name'];
        }

        $qty = isset($item['quantity']) ? (float) $item['quantity'] : 0;
        $available = isset($item['available_quantity']) ? (float) $item['available_quantity'] : 0;
        $unitCost = isset($item['avg_landed_cost']) ? (float) $item['avg_landed_cost'] : 0;
        $itemValue = $qty * $unitCost;
        $reorderLevel = isset($item['reorder_level']) ? (int) $item['reorder_level'] : 0;

        $status = 'Normal';
        if ($qty <= 0)
            $status = 'Out of Stock';
        elseif ($qty <= $reorderLevel)
            $status = 'Low Stock';

        fputcsv($output, [
            $productName,
            $item['sku'] ?? '-',
            $item['category_name'] ?? 'N/A',
            $item['warehouse_name'] ?? 'N/A',
            number_format($qty, 0),
            number_format($available, 0),
            $unitCost > 0 ? number_format($unitCost, 0) . ' RWF' : '-',
            number_format($itemValue, 0) . ' RWF',
            $status,
            $reorderLevel > 0 ? number_format($reorderLevel, 0) : '-'
        ]);
    }

    fclose($output);
    exit;
}

// If we get here, something went wrong
header('HTTP/1.1 400 Bad Request');
echo 'Invalid export format';
exit;