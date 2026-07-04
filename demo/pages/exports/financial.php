<?php
// pages/exports/financial.php
// FINANCIAL REPORT EXPORT (PDF/CSV)
declare(strict_types=1);

while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Sale.php';
require_once __DIR__ . '/../../models/PurchaseOrder.php';
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
if (!in_array($userRole, ['ADM', 'MGR', 'ACC'])) {
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
$period = $_GET['period'] ?? 'month';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$year = $_GET['year'] ?? date('Y');

// Set date range based on period
switch ($period) {
    case 'today':
        $startDate = date('Y-m-d');
        $endDate = date('Y-m-d');
        break;
    case 'week':
        $startDate = date('Y-m-d', strtotime('monday this week'));
        $endDate = date('Y-m-d', strtotime('sunday this week'));
        break;
    case 'month':
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-t');
        break;
    case 'quarter':
        $quarter = ceil(date('n') / 3);
        $startDate = date('Y-' . (($quarter - 1) * 3 + 1) . '-01');
        $endDate = date('Y-m-t', strtotime($startDate . ' +2 months'));
        break;
    case 'year':
        $startDate = date('Y-01-01');
        $endDate = date('Y-12-31');
        break;
    case 'all':
        $startDate = '2000-01-01';
        $endDate = date('Y-m-d');
        break;
    case 'custom':
        if (!$startDate || !$endDate) {
            $startDate = date('Y-m-01');
            $endDate = date('Y-m-t');
        }
        break;
    default:
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-t');
}

$db = Database::getInstance();

// Initialize models with company context
$saleModel = new Sale($companyId);
$purchaseModel = new PurchaseOrder($companyId);
$inventoryModel = new Inventory($companyId);

// Get sales data (company-specific)
$salesSummary = $saleModel->getSalesSummary($startDate, $endDate);
$salesByStatus = $saleModel->getSalesByStatus();

// Get purchase data (company-specific)
$purchaseSummary = $purchaseModel->getPurchasesSummary($startDate, $endDate);
$purchasesByStatus = $purchaseModel->getPurchasesByStatus($startDate, $endDate);

// Get inventory value (company-specific)
$inventorySummary = $inventoryModel->getStockSummary();
$inventoryValue = $inventorySummary['stock_value'] ?? 0;

// Calculate profit
$revenue = $salesSummary['total_sales'] ?? 0;
$cogs = $purchaseSummary['total_amount'] ?? 0;
$grossProfit = $revenue - $cogs;
$grossMargin = $revenue > 0 ? ($grossProfit / $revenue) * 100 : 0;

// Get receivables (company-specific)
$receivables = $saleModel->getTotalReceivables();

// Get payables (company-specific)
$payables = $purchaseModel->getTotalPayables();

// Get monthly data for the year (company-specific)
$monthlySales = [];
$monthlyPurchases = [];
for ($m = 1; $m <= 12; $m++) {
    $monthStart = date('Y-m-d', strtotime("$year-$m-01"));
    $monthEnd = date('Y-m-t', strtotime($monthStart));

    $monthlySales[$m] = $saleModel->getSalesSummary($monthStart, $monthEnd)['total_sales'] ?? 0;
    $monthlyPurchases[$m] = $purchaseModel->getPurchasesSummary($monthStart, $monthEnd)['total_amount'] ?? 0;
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
    // PDF Export
    class FinancialReportPDF extends FPDF
    {
        private $startDate;
        private $endDate;
        private $year;
        private $salesSummary;
        private $purchaseSummary;
        private $inventoryValue;
        private $revenue;
        private $cogs;
        private $grossProfit;
        private $grossMargin;
        private $receivables;
        private $payables;
        private $monthlySales;
        private $monthlyPurchases;
        private $company;
        private $currency;

        function __construct(
            $startDate,
            $endDate,
            $year,
            $salesSummary,
            $purchaseSummary,
            $inventoryValue,
            $revenue,
            $cogs,
            $grossProfit,
            $grossMargin,
            $receivables,
            $payables,
            $monthlySales,
            $monthlyPurchases,
            $company,
            $currency
        ) {
            parent::__construct('P', 'mm', 'A4');
            $this->startDate = $startDate;
            $this->endDate = $endDate;
            $this->year = $year;
            $this->salesSummary = $salesSummary;
            $this->purchaseSummary = $purchaseSummary;
            $this->inventoryValue = $inventoryValue;
            $this->revenue = $revenue;
            $this->cogs = $cogs;
            $this->grossProfit = $grossProfit;
            $this->grossMargin = $grossMargin;
            $this->receivables = $receivables;
            $this->payables = $payables;
            $this->monthlySales = $monthlySales;
            $this->monthlyPurchases = $monthlyPurchases;
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
            $this->Cell(0, 10, 'FINANCIAL REPORT', 0, 1, 'C');

            $this->SetFont('Arial', '', 10);
            $this->SetTextColor(100, 100, 100);
            $this->Cell(0, 6, 'Period: ' . date('d/m/Y', strtotime($this->startDate)) . ' to ' . date('d/m/Y', strtotime($this->endDate)), 0, 1, 'C');
            $this->Cell(0, 6, 'Generated: ' . date('d/m/Y H:i:s'), 0, 1, 'C');

            $this->Ln(5);
            $this->SetDrawColor(200, 200, 200);
            $this->Line(10, $this->GetY(), 200, $this->GetY());
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
            $this->Cell(0, 8, 'FINANCIAL SUMMARY', 0, 1);
            $this->Ln(2);

            $this->SetFont('Arial', '', 10);
            $this->SetTextColor(0, 0, 0);

            $col1 = 60;
            $col2 = 60;
            $startX = 30;

            $this->SetX($startX);
            $this->Cell($col1, 7, 'Revenue:', 0, 0);
            $this->Cell($col2, 7, number_format((float)$this->revenue, 0) . ' ' . $this->currency, 0, 1);

            $this->SetX($startX);
            $this->Cell($col1, 7, 'Cost of Goods Sold:', 0, 0);
            $this->Cell($col2, 7, number_format((float)$this->cogs, 0) . ' ' . $this->currency, 0, 1);

            $this->SetX($startX);
            $this->SetDrawColor(200, 200, 200);
            $this->Line($startX, $this->GetY(), $startX + $col1 + $col2, $this->GetY());
            $this->Ln(1);

            $this->SetX($startX);
            $this->SetFont('Arial', 'B', 10);
            $this->Cell($col1, 7, 'Gross Profit:', 0, 0);
            $this->SetTextColor(46, 204, 113);
            $this->Cell($col2, 7, number_format((float)$this->grossProfit, 0) . ' ' . $this->currency, 0, 1);
            $this->SetTextColor(0, 0, 0);

            $this->SetX($startX);
            $this->SetFont('Arial', '', 10);
            $this->Cell($col1, 7, 'Gross Margin:', 0, 0);
            $this->Cell($col2, 7, number_format((float)$this->grossMargin, 1) . '%', 0, 1);

            $this->Ln(5);

            $this->SetX($startX);
            $this->Cell($col1, 7, 'Inventory Value:', 0, 0);
            $this->Cell($col2, 7, number_format((float)$this->inventoryValue, 0) . ' ' . $this->currency, 0, 1);

            $this->SetX($startX);
            $this->Cell($col1, 7, 'Receivables:', 0, 0);
            $this->Cell($col2, 7, number_format((float)$this->receivables, 0) . ' ' . $this->currency, 0, 1);

            $this->SetX($startX);
            $this->Cell($col1, 7, 'Payables:', 0, 0);
            $this->Cell($col2, 7, number_format((float)$this->payables, 0) . ' ' . $this->currency, 0, 1);

            $this->Ln(8);
        }

        function ProfitLossSection()
        {
            $this->SetFont('Arial', 'B', 12);
            $this->SetTextColor(41, 128, 185);
            $this->Cell(0, 8, 'PROFIT & LOSS STATEMENT', 0, 1);
            $this->Ln(2);

            $col1 = 80;
            $col2 = 50;
            $col3 = 50;
            $startX = 20;

            $this->SetFont('Arial', 'B', 10);
            $this->SetFillColor(240, 240, 240);
            $this->SetX($startX);
            $this->Cell($col1, 8, 'Description', 1, 0, 'C', true);
            $this->Cell($col2, 8, 'Amount', 1, 0, 'C', true);
            $this->Cell($col3, 8, '% of Revenue', 1, 1, 'C', true);

            $this->SetFont('Arial', '', 10);
            $this->SetTextColor(0, 0, 0);

            // Revenue
            $this->SetX($startX);
            $this->Cell($col1, 7, 'Revenue', 1);
            $this->Cell($col2, 7, number_format((float)$this->revenue, 0) . ' ' . $this->currency, 1, 0, 'R');
            $this->Cell($col3, 7, '100%', 1, 1, 'R');

            // Cost of Goods Sold
            $this->SetX($startX);
            $this->Cell($col1, 7, 'Less: Cost of Goods Sold', 1);
            $this->Cell($col2, 7, number_format((float)$this->cogs, 0) . ' ' . $this->currency, 1, 0, 'R');
            $cogsPercentage = $this->revenue > 0 ? ($this->cogs / $this->revenue) * 100 : 0;
            $this->Cell($col3, 7, number_format((float)$cogsPercentage, 1) . '%', 1, 1, 'R');

            // Gross Profit
            $this->SetX($startX);
            $this->SetFont('Arial', 'B', 10);
            $this->Cell($col1, 7, 'Gross Profit', 1);
            $this->SetTextColor(46, 204, 113);
            $this->Cell($col2, 7, number_format((float)$this->grossProfit, 0) . ' ' . $this->currency, 1, 0, 'R');
            $this->Cell($col3, 7, number_format((float)$this->grossMargin, 1) . '%', 1, 1, 'R');
            $this->SetTextColor(0, 0, 0);

            $this->Ln(8);
        }

        function MonthlySection()
        {
            $this->SetFont('Arial', 'B', 12);
            $this->SetTextColor(41, 128, 185);
            $this->Cell(0, 8, 'MONTHLY PERFORMANCE - ' . $this->year, 0, 1);
            $this->Ln(2);

            $col1 = 30;
            $col2 = 50;
            $col3 = 50;
            $col4 = 50;
            $startX = 15;

            $this->SetFont('Arial', 'B', 9);
            $this->SetFillColor(240, 240, 240);
            $this->SetX($startX);
            $this->Cell($col1, 8, 'Month', 1, 0, 'C', true);
            $this->Cell($col2, 8, 'Sales', 1, 0, 'C', true);
            $this->Cell($col3, 8, 'Purchases', 1, 0, 'C', true);
            $this->Cell($col4, 8, 'Net', 1, 1, 'C', true);

            $this->SetFont('Arial', '', 9);
            $this->SetTextColor(0, 0, 0);

            $months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            $totalSales = 0;
            $totalPurchases = 0;

            for ($m = 1; $m <= 12; $m++) {
                $sales = $this->monthlySales[$m] ?? 0;
                $purchases = $this->monthlyPurchases[$m] ?? 0;
                $net = $sales - $purchases;

                $this->SetX($startX);
                $this->Cell($col1, 7, $months[$m - 1], 1);
                $this->Cell($col2, 7, number_format((float)$sales, 0) . ' ' . $this->currency, 1, 0, 'R');
                $this->Cell($col3, 7, number_format((float)$purchases, 0) . ' ' . $this->currency, 1, 0, 'R');

                if ($net < 0) {
                    $this->SetTextColor(231, 76, 60);
                } else {
                    $this->SetTextColor(46, 204, 113);
                }
                $this->Cell($col4, 7, number_format((float)$net, 0) . ' ' . $this->currency, 1, 1, 'R');
                $this->SetTextColor(0, 0, 0);

                $totalSales += $sales;
                $totalPurchases += $purchases;
            }

            // Totals row
            $this->SetFont('Arial', 'B', 9);
            $this->SetFillColor(240, 240, 240);
            $this->SetX($startX);
            $this->Cell($col1, 7, 'TOTAL', 1, 0, 'C', true);
            $this->Cell($col2, 7, number_format((float)$totalSales, 0) . ' ' . $this->currency, 1, 0, 'R', true);
            $this->Cell($col3, 7, number_format((float)$totalPurchases, 0) . ' ' . $this->currency, 1, 0, 'R', true);
            $this->Cell($col4, 7, number_format((float)$totalSales - $totalPurchases, 0) . ' ' . $this->currency, 1, 1, 'R', true);
        }
    }

    $pdf = new FinancialReportPDF(
        $startDate,
        $endDate,
        $year,
        $salesSummary,
        $purchaseSummary,
        $inventoryValue,
        $revenue,
        $cogs,
        $grossProfit,
        $grossMargin,
        $receivables,
        $payables,
        $monthlySales,
        $monthlyPurchases,
        $company,
        $currency
    );
    $pdf->AliasNbPages();
    $pdf->AddPage();

    $pdf->SummarySection();
    $pdf->ProfitLossSection();
    $pdf->MonthlySection();

    $filename = 'Financial_Report_' . date('Y-m-d') . '.pdf';
    $pdf->Output('D', $filename);
    exit;

} elseif ($format === 'excel') {
    // Excel/CSV Export
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Financial_Report_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Company info
    fputcsv($output, [$company['company_name'] ?? 'SATI ERP']);
    fputcsv($output, ['FINANCIAL REPORT']);
    fputcsv($output, ['Generated:', date('d/m/Y H:i:s')]);
    fputcsv($output, ['Period:', date('d/m/Y', strtotime($startDate)), 'to', date('d/m/Y', strtotime($endDate))]);
    fputcsv($output, []);

    // Summary
    fputcsv($output, ['FINANCIAL SUMMARY']);
    fputcsv($output, ['Revenue', number_format((float)$revenue, 0) . ' ' . $currency]);
    fputcsv($output, ['Cost of Goods Sold', number_format((float)$cogs, 0) . ' ' . $currency]);
    fputcsv($output, ['Gross Profit', number_format((float)$grossProfit, 0) . ' ' . $currency]);
    fputcsv($output, ['Gross Margin', number_format((float)$grossMargin, 1) . '%']);
    fputcsv($output, ['Inventory Value', number_format((float)$inventoryValue, 0) . ' ' . $currency]);
    fputcsv($output, ['Receivables', number_format((float)$receivables, 0) . ' ' . $currency]);
    fputcsv($output, ['Payables', number_format((float)$payables, 0) . ' ' . $currency]);
    fputcsv($output, []);

    // Profit & Loss
    fputcsv($output, ['PROFIT & LOSS STATEMENT']);
    fputcsv($output, ['Description', 'Amount', '% of Revenue']);
    fputcsv($output, ['Revenue', number_format((float)$revenue, 0) . ' ' . $currency, '100%']);
    $cogsPercentage = $revenue > 0 ? ($cogs / $revenue) * 100 : 0;
    fputcsv($output, ['Cost of Goods Sold', number_format((float)$cogs, 0) . ' ' . $currency, number_format((float)$cogsPercentage, 1) . '%']);
    fputcsv($output, ['Gross Profit', number_format((float)$grossProfit, 0) . ' ' . $currency, number_format((float)$grossMargin, 1) . '%']);
    fputcsv($output, []);

    // Monthly Performance
    fputcsv($output, ['MONTHLY PERFORMANCE - ' . $year]);
    fputcsv($output, ['Month', 'Sales', 'Purchases', 'Net']);

    $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    for ($m = 1; $m <= 12; $m++) {
        $sales = $monthlySales[$m] ?? 0;
        $purchases = $monthlyPurchases[$m] ?? 0;
        $net = $sales - $purchases;

        fputcsv($output, [
            $months[$m - 1],
            number_format((float)$sales, 0) . ' ' . $currency,
            number_format((float)$purchases, 0) . ' ' . $currency,
            number_format((float)$net, 0) . ' ' . $currency
        ]);
    }

    fclose($output);
    exit;
}

// If we get here, something went wrong
header('HTTP/1.1 400 Bad Request');
echo 'Invalid export format';
exit;