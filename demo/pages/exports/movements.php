<?php
// pages/exports/movements.php
// STOCK MOVEMENTS REPORT EXPORT (PDF/CSV)
declare(strict_types=1);

while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Inventory.php';
require_once __DIR__ . '/../../models/Warehouse.php';
require_once __DIR__ . '/../../models/Variant.php';
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
$variantId = isset($_GET['variant_id']) ? (int) $_GET['variant_id'] : null;
$warehouseId = isset($_GET['warehouse_id']) ? (int) $_GET['warehouse_id'] : null;
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$type = $_GET['type'] ?? '';

// Initialize models with company context
$inventoryModel = new Inventory($companyId);
$warehouseModel = new Warehouse($companyId);
$variantModel = new Variant($companyId);

// Get movements data (company-specific)
$movements = $inventoryModel->getMovementsByDateRange($startDate, $endDate, $warehouseId);

// Filter by variant if specified
if ($variantId) {
    $movements = array_filter($movements, function ($m) use ($variantId) {
        return ($m['variant_id'] ?? 0) == $variantId;
    });
}

// Filter by type if specified
if ($type && $type !== 'all') {
    $movements = array_filter($movements, function ($m) use ($type) {
        return ($m['transaction_type'] ?? '') === $type;
    });
}

// Get summary statistics
$totalMovements = count($movements);
$totalIn = array_sum(array_map(function ($m) {
    return $m['quantity'] > 0 ? $m['quantity'] : 0;
}, $movements));
$totalOut = array_sum(array_map(function ($m) {
    return $m['quantity'] < 0 ? abs($m['quantity']) : 0;
}, $movements));
$netChange = $totalIn - $totalOut;

// Get counts by type
$typeCounts = [];
foreach ($movements as $m) {
    $t = $m['transaction_type'] ?? 'unknown';
    if (!isset($typeCounts[$t])) {
        $typeCounts[$t] = 0;
    }
    $typeCounts[$t]++;
}

// Get warehouse name if filtered
$warehouseName = '';
if ($warehouseId) {
    $warehouse = $warehouseModel->find($warehouseId);
    $warehouseName = $warehouse ? $warehouse['warehouse_name'] : '';
}

// Get variant info if filtered
$variantInfo = '';
if ($variantId) {
    $variant = $variantModel->find($variantId);
    if ($variant) {
        $product = $variantModel->getWithDetails($variantId);
        $variantInfo = $product ? $product['product_name'] . ' - ' . ($product['variant_name'] ?? 'Standard') : '';
    }
}

// Get company info for report header
$db = Database::getInstance();
$company = [];
$companyStmt = $db->prepare("SELECT company_name, address, phone, email, currency FROM companies WHERE id = ?");
$companyStmt->execute([$companyId]);
$company = $companyStmt->fetch(PDO::FETCH_ASSOC);
$currency = $company['currency'] ?? 'RWF';

// Ensure we have arrays
if (!is_array($movements)) {
    $movements = [];
}

// Final buffer cleanup
while (ob_get_level()) {
    ob_end_clean();
}

if ($format === 'pdf') {
    // PDF Export
    class MovementsReportPDF extends FPDF
    {
        private $movements;
        private $startDate;
        private $endDate;
        private $warehouseName;
        private $variantInfo;
        private $type;
        private $totalMovements;
        private $totalIn;
        private $totalOut;
        private $netChange;
        private $typeCounts;
        private $company;
        private $currency;

        function __construct(
            $movements,
            $startDate,
            $endDate,
            $warehouseName,
            $variantInfo,
            $type,
            $totalMovements,
            $totalIn,
            $totalOut,
            $netChange,
            $typeCounts,
            $company,
            $currency
        ) {
            parent::__construct('L', 'mm', 'A4');
            $this->movements = $movements;
            $this->startDate = $startDate;
            $this->endDate = $endDate;
            $this->warehouseName = $warehouseName;
            $this->variantInfo = $variantInfo;
            $this->type = $type;
            $this->totalMovements = $totalMovements;
            $this->totalIn = $totalIn;
            $this->totalOut = $totalOut;
            $this->netChange = $netChange;
            $this->typeCounts = $typeCounts;
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
            $this->Cell(0, 10, 'STOCK MOVEMENTS REPORT', 0, 1, 'C');

            $this->SetFont('Arial', '', 10);
            $this->SetTextColor(100, 100, 100);

            $filterText = [];
            $filterText[] = 'Period: ' . date('d/m/Y', strtotime($this->startDate)) . ' - ' . date('d/m/Y', strtotime($this->endDate));
            if ($this->warehouseName) {
                $filterText[] = 'Warehouse: ' . $this->warehouseName;
            }
            if ($this->variantInfo) {
                $filterText[] = 'Product: ' . $this->variantInfo;
            }
            if ($this->type && $this->type !== 'all') {
                $filterText[] = 'Type: ' . ucfirst($this->type);
            }

            $filterString = implode(' | ', $filterText);

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
            $this->Cell($colWidth, 7, 'Total Movements:', 0, 0);
            $this->Cell($colWidth, 7, number_format((float)$this->totalMovements, 0), 0, 1);

            $this->SetX($startX);
            $this->Cell($colWidth, 7, 'Total In:', 0, 0);
            $this->Cell($colWidth, 7, number_format((float)$this->totalIn, 0) . ' units', 0, 1);

            $this->SetX($startX);
            $this->Cell($colWidth, 7, 'Total Out:', 0, 0);
            $this->Cell($colWidth, 7, number_format((float)$this->totalOut, 0) . ' units', 0, 1);

            $this->SetX($startX);
            $netChangeColor = $this->netChange >= 0 ? [46, 204, 113] : [231, 76, 60];
            $this->SetTextColor($netChangeColor[0], $netChangeColor[1], $netChangeColor[2]);
            $this->Cell($colWidth, 7, 'Net Change:', 0, 0);
            $this->Cell($colWidth, 7, ($this->netChange >= 0 ? '+' : '') . number_format((float)$this->netChange, 0) . ' units', 0, 1);
            $this->SetTextColor(0, 0, 0);

            $this->Ln(8);

            // Movement by type
            if (!empty($this->typeCounts)) {
                $this->SetFont('Arial', 'B', 10);
                $this->SetTextColor(41, 128, 185);
                $this->Cell(0, 7, 'Movements by Type:', 0, 1);
                $this->Ln(2);

                $this->SetFont('Arial', '', 9);
                $this->SetTextColor(0, 0, 0);

                foreach ($this->typeCounts as $type => $count) {
                    $this->SetX($startX + 20);
                    $this->Cell(50, 6, ucfirst($type) . ':', 0, 0);
                    $this->Cell(30, 6, number_format((float)$count, 0), 0, 1);
                }
                $this->Ln(5);
            }
        }

        function MovementsTable()
        {
            $this->SetFont('Arial', 'B', 12);
            $this->SetTextColor(41, 128, 185);
            $this->Cell(0, 8, 'MOVEMENT DETAILS', 0, 1);
            $this->Ln(2);

            // Table header
            $this->SetFillColor(41, 128, 185);
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Arial', 'B', 8);

            $col1 = 35;
            $col2 = 30;
            $col3 = 25;
            $col4 = 35;
            $col5 = 45;
            $col6 = 30;
            $col7 = 20;
            $col8 = 18;
            $col9 = 22;
            $col10 = 25;

            $this->Cell($col1, 8, 'Date', 1, 0, 'C', true);
            $this->Cell($col2, 8, 'Code', 1, 0, 'C', true);
            $this->Cell($col3, 8, 'Type', 1, 0, 'C', true);
            $this->Cell($col4, 8, 'Product', 1, 0, 'C', true);
            $this->Cell($col5, 8, 'SKU', 1, 0, 'C', true);
            $this->Cell($col6, 8, 'Warehouse', 1, 0, 'C', true);
            $this->Cell($col7, 8, 'Location', 1, 0, 'C', true);
            $this->Cell($col8, 8, 'Qty', 1, 0, 'C', true);
            $this->Cell($col9, 8, 'Unit Cost', 1, 0, 'C', true);
            $this->Cell($col10, 8, 'Total', 1, 1, 'C', true);

            $this->SetTextColor(0, 0, 0);
            $this->SetFont('Arial', '', 7);

            $totalValue = 0;

            foreach ($this->movements as $movement) {
                if ($this->GetY() > 180) {
                    $this->AddPage();
                    $this->SetFillColor(41, 128, 185);
                    $this->SetTextColor(255, 255, 255);
                    $this->SetFont('Arial', 'B', 8);
                    $this->Cell($col1, 8, 'Date', 1, 0, 'C', true);
                    $this->Cell($col2, 8, 'Code', 1, 0, 'C', true);
                    $this->Cell($col3, 8, 'Type', 1, 0, 'C', true);
                    $this->Cell($col4, 8, 'Product', 1, 0, 'C', true);
                    $this->Cell($col5, 8, 'SKU', 1, 0, 'C', true);
                    $this->Cell($col6, 8, 'Warehouse', 1, 0, 'C', true);
                    $this->Cell($col7, 8, 'Location', 1, 0, 'C', true);
                    $this->Cell($col8, 8, 'Qty', 1, 0, 'C', true);
                    $this->Cell($col9, 8, 'Unit Cost', 1, 0, 'C', true);
                    $this->Cell($col10, 8, 'Total', 1, 1, 'C', true);
                    $this->SetTextColor(0, 0, 0);
                    $this->SetFont('Arial', '', 7);
                }

                $date = isset($movement['created_at']) ? date('d/m/Y H:i', strtotime($movement['created_at'])) : '-';
                $code = $movement['transaction_code'] ?? '-';
                $type = $movement['transaction_type'] ?? '-';
                $product = $movement['product_name'] ?? 'Unknown';
                if (!empty($movement['variant_name']) && $movement['variant_name'] !== 'Standard') {
                    $product .= ' - ' . $movement['variant_name'];
                }
                $sku = $movement['sku'] ?? '-';
                $warehouse = $movement['warehouse_name'] ?? '-';
                $location = $movement['location_code'] ?? 'Default';
                $qty = isset($movement['quantity']) ? (float) $movement['quantity'] : 0;
                $unitCost = isset($movement['unit_cost']) ? (float) $movement['unit_cost'] : 0;
                $total = abs($qty) * $unitCost;

                $typeColor = [0, 0, 0];
                if ($type === 'purchase')
                    $typeColor = [46, 204, 113];
                elseif ($type === 'sale')
                    $typeColor = [52, 152, 219];
                elseif ($type === 'return')
                    $typeColor = [241, 196, 15];
                elseif ($type === 'adjustment')
                    $typeColor = [155, 89, 182];
                elseif ($type === 'transfer')
                    $typeColor = [52, 73, 94];

                $this->Cell($col1, 6, $this->shortenText($date, 16), 1);
                $this->Cell($col2, 6, $this->shortenText($code, 15), 1);

                $this->SetTextColor($typeColor[0], $typeColor[1], $typeColor[2]);
                $this->Cell($col3, 6, ucfirst($type), 1);
                $this->SetTextColor(0, 0, 0);

                $this->Cell($col4, 6, $this->shortenText($product, 30), 1);
                $this->Cell($col5, 6, $sku, 1);
                $this->Cell($col6, 6, $this->shortenText($warehouse, 15), 1);
                $this->Cell($col7, 6, $this->shortenText($location, 10), 1);

                if ($qty > 0) {
                    $this->SetTextColor(46, 204, 113);
                } elseif ($qty < 0) {
                    $this->SetTextColor(231, 76, 60);
                }
                $this->Cell($col8, 6, number_format((float)$qty, 0), 1, 0, 'R');
                $this->SetTextColor(0, 0, 0);

                $this->Cell($col9, 6, $unitCost > 0 ? number_format((float)$unitCost, 0) . ' ' . $this->currency : '-', 1, 0, 'R');
                $this->Cell($col10, 6, $total > 0 ? number_format((float)$total, 0) . ' ' . $this->currency : '-', 1, 1, 'R');

                $totalValue += $total;
            }

            // Totals row
            $this->SetFont('Arial', 'B', 8);
            $this->SetFillColor(240, 240, 240);
            $this->Cell($col1 + $col2 + $col3 + $col4 + $col5 + $col6 + $col7, 6, 'TOTAL', 1, 0, 'R', true);
            $this->Cell($col8, 6, '', 1, 0, 'R', true);
            $this->Cell($col9, 6, '', 1, 0, 'R', true);
            $this->Cell($col10, 6, number_format((float)$totalValue, 0) . ' ' . $this->currency, 1, 1, 'R', true);
        }

        private function shortenText($text, $length)
        {
            if (strlen($text) > $length) {
                return substr($text, 0, $length - 3) . '...';
            }
            return $text;
        }
    }

    $pdf = new MovementsReportPDF(
        $movements,
        $startDate,
        $endDate,
        $warehouseName,
        $variantInfo,
        $type,
        $totalMovements,
        $totalIn,
        $totalOut,
        $netChange,
        $typeCounts,
        $company,
        $currency
    );
    $pdf->AliasNbPages();
    $pdf->AddPage();

    $pdf->SummarySection();
    if (!empty($movements)) {
        $pdf->MovementsTable();
    }

    // Final buffer cleanup
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Movements_Report_' . date('Y-m-d') . '.pdf"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    $pdf->Output('D', 'Movements_Report_' . date('Y-m-d') . '.pdf');
    exit;

} elseif ($format === 'excel') {
    // Final buffer cleanup
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Movements_Report_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Company info
    fputcsv($output, [$company['company_name'] ?? 'SATI ERP']);
    fputcsv($output, ['STOCK MOVEMENTS REPORT']);
    fputcsv($output, ['Period:', date('d/m/Y', strtotime($startDate)), 'to', date('d/m/Y', strtotime($endDate))]);
    if ($warehouseName)
        fputcsv($output, ['Warehouse:', $warehouseName]);
    if ($variantInfo)
        fputcsv($output, ['Product:', $variantInfo]);
    if ($type && $type !== 'all')
        fputcsv($output, ['Type:', ucfirst($type)]);
    fputcsv($output, ['Generated:', date('d/m/Y H:i:s')]);
    fputcsv($output, []);

    // Summary
    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Total Movements', $totalMovements]);
    fputcsv($output, ['Total In', number_format((float)$totalIn, 0) . ' units']);
    fputcsv($output, ['Total Out', number_format((float)$totalOut, 0) . ' units']);
    fputcsv($output, ['Net Change', ($netChange >= 0 ? '+' : '') . number_format((float)$netChange, 0) . ' units']);
    fputcsv($output, []);

    if (!empty($typeCounts)) {
        fputcsv($output, ['MOVEMENTS BY TYPE']);
        foreach ($typeCounts as $type => $count) {
            fputcsv($output, [ucfirst($type), $count]);
        }
        fputcsv($output, []);
    }

    // Movement Details
    fputcsv($output, ['MOVEMENT DETAILS']);
    fputcsv($output, ['Date', 'Code', 'Type', 'Product', 'SKU', 'Warehouse', 'Location', 'Quantity', 'Unit Cost', 'Total Value']);

    foreach ($movements as $m) {
        $product = $m['product_name'] ?? 'Unknown';
        if (!empty($m['variant_name']) && $m['variant_name'] !== 'Standard') {
            $product .= ' - ' . $m['variant_name'];
        }

        $qty = isset($m['quantity']) ? (float) $m['quantity'] : 0;
        $unitCost = isset($m['unit_cost']) ? (float) $m['unit_cost'] : 0;
        $total = abs($qty) * $unitCost;

        fputcsv($output, [
            isset($m['created_at']) ? date('d/m/Y H:i', strtotime($m['created_at'])) : '-',
            $m['transaction_code'] ?? '-',
            ucfirst($m['transaction_type'] ?? '-'),
            $product,
            $m['sku'] ?? '-',
            $m['warehouse_name'] ?? '-',
            $m['location_code'] ?? 'Default',
            number_format((float)$qty, 0),
            $unitCost > 0 ? number_format((float)$unitCost, 0) . ' ' . $currency : '-',
            $total > 0 ? number_format((float)$total, 0) . ' ' . $currency : '-'
        ]);
    }

    fclose($output);
    exit;
}

// If we get here, something went wrong
header('HTTP/1.1 400 Bad Request');
echo 'Invalid export format';
exit;