<?php
// pages/exports/customers.php
// CUSTOMER REPORT EXPORT (PDF/CSV)
declare(strict_types=1);

while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Customer.php';
require_once __DIR__ . '/../../models/Sale.php';
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
$customerType = $_GET['customer_type'] ?? '';
$sortBy = $_GET['sort_by'] ?? 'total_purchases';

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
$customerModel = new Customer($companyId);
$saleModel = new Sale($companyId);

// Get customer statistics
$customerStats = $customerModel->getStats();

// Get customer list with purchase summary (company-specific)
$sql = "
    SELECT 
        c.*,
        COUNT(DISTINCT si.id) as total_invoices,
        COALESCE(SUM(si.total_amount), 0) as total_purchases,
        COALESCE(SUM(si.amount_paid), 0) as total_paid,
        COALESCE(SUM(CASE WHEN si.status IN ('issued', 'partial', 'overdue') THEN (si.total_amount - si.amount_paid) ELSE 0 END), 0) as outstanding,
        MAX(si.invoice_date) as last_purchase_date,
        AVG(si.total_amount) as avg_order_value
    FROM customers c
    LEFT JOIN sales_invoices si ON c.id = si.customer_id 
        AND si.invoice_date BETWEEN :start_date AND :end_date
        AND si.status != 'cancelled'
        AND si.company_id = :p_1_company_id
    WHERE c.company_id = :p_2_company_id
";

$params = [
    'start_date' => $startDate,
    'end_date' => $endDate,
    'p_1_company_id' => $companyId,
    'p_2_company_id' => $companyId
];

if ($customerType) {
    $sql .= " AND c.customer_type = :customer_type";
    $params['customer_type'] = $customerType;
}

$sql .= " GROUP BY c.id";

// Apply sorting
switch ($sortBy) {
    case 'total_purchases':
        $sql .= " ORDER BY total_purchases DESC";
        break;
    case 'invoices':
        $sql .= " ORDER BY total_invoices DESC";
        break;
    case 'outstanding':
        $sql .= " ORDER BY outstanding DESC";
        break;
    case 'last_purchase':
        $sql .= " ORDER BY last_purchase_date DESC NULLS LAST";
        break;
    case 'name':
        $sql .= " ORDER BY c.full_name ASC";
        break;
    default:
        $sql .= " ORDER BY total_purchases DESC";
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

// Calculate totals
$totalCustomers = count($customers);
$totalPurchases = array_sum(array_column($customers, 'total_purchases'));
$totalPaid = array_sum(array_column($customers, 'total_paid'));
$totalOutstanding = array_sum(array_column($customers, 'outstanding'));
$avgPurchasePerCustomer = $totalCustomers > 0 ? $totalPurchases / $totalCustomers : 0;

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
    class CustomerReportPDF extends FPDF
    {
        private $customers;
        private $totalCustomers;
        private $totalPurchases;
        private $totalPaid;
        private $totalOutstanding;
        private $avgPurchasePerCustomer;
        private $startDate;
        private $endDate;
        private $customerType;
        private $sortBy;
        private $company;
        private $currency;

        function __construct(
            $customers,
            $totalCustomers,
            $totalPurchases,
            $totalPaid,
            $totalOutstanding,
            $avgPurchasePerCustomer,
            $startDate,
            $endDate,
            $customerType,
            $sortBy,
            $company,
            $currency
        ) {
            parent::__construct('L', 'mm', 'A4');
            $this->customers = $customers;
            $this->totalCustomers = $totalCustomers;
            $this->totalPurchases = $totalPurchases;
            $this->totalPaid = $totalPaid;
            $this->totalOutstanding = $totalOutstanding;
            $this->avgPurchasePerCustomer = $avgPurchasePerCustomer;
            $this->startDate = $startDate;
            $this->endDate = $endDate;
            $this->customerType = $customerType;
            $this->sortBy = $sortBy;
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
            $this->Cell(0, 10, 'CUSTOMER REPORT', 0, 1, 'C');

            $this->SetFont('Arial', '', 10);
            $this->SetTextColor(100, 100, 100);

            $filterText = [];
            $filterText[] = 'Period: ' . date('d/m/Y', strtotime($this->startDate)) . ' - ' . date('d/m/Y', strtotime($this->endDate));
            if ($this->customerType) {
                $filterText[] = 'Type: ' . ucfirst($this->customerType);
            }

            $sortLabels = [
                'total_purchases' => 'Total Purchases',
                'invoices' => 'Number of Invoices',
                'outstanding' => 'Outstanding Balance',
                'last_purchase' => 'Last Purchase Date',
                'name' => 'Customer Name'
            ];
            $filterText[] = 'Sort: ' . ($sortLabels[$this->sortBy] ?? $this->sortBy);

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
            $this->Cell($colWidth, 7, 'Total Customers:', 0, 0);
            $this->Cell($colWidth, 7, number_format((float)$this->totalCustomers, 0), 0, 1);

            $this->SetX($startX);
            $this->Cell($colWidth, 7, 'Total Purchases:', 0, 0);
            $this->Cell($colWidth, 7, number_format((float)$this->totalPurchases, 0) . ' ' . $this->currency, 0, 1);

            $this->SetX($startX);
            $this->Cell($colWidth, 7, 'Total Paid:', 0, 0);
            $this->Cell($colWidth, 7, number_format((float)$this->totalPaid, 0) . ' ' . $this->currency, 0, 1);

            $this->SetX($startX);
            $this->Cell($colWidth, 7, 'Outstanding:', 0, 0);
            $this->Cell($colWidth, 7, number_format((float)$this->totalOutstanding, 0) . ' ' . $this->currency, 0, 1);

            $this->SetX($startX);
            $this->Cell($colWidth, 7, 'Avg per Customer:', 0, 0);
            $this->Cell($colWidth, 7, number_format((float)$this->avgPurchasePerCustomer, 0) . ' ' . $this->currency, 0, 1);

            $this->Ln(8);
        }

        function CustomersTable()
        {
            $this->SetFont('Arial', 'B', 12);
            $this->SetTextColor(41, 128, 185);
            $this->Cell(0, 8, 'CUSTOMER DETAILS', 0, 1);
            $this->Ln(2);

            // Table header
            $this->SetFillColor(41, 128, 185);
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Arial', 'B', 8);

            $col1 = 25;
            $col2 = 40;
            $col3 = 18;
            $col4 = 25;
            $col5 = 30;
            $col6 = 18;
            $col7 = 28;
            $col8 = 28;
            $col9 = 28;
            $col10 = 25;

            $this->Cell($col1, 8, 'Code', 1, 0, 'C', true);
            $this->Cell($col2, 8, 'Customer', 1, 0, 'C', true);
            $this->Cell($col3, 8, 'Type', 1, 0, 'C', true);
            $this->Cell($col4, 8, 'Phone', 1, 0, 'C', true);
            $this->Cell($col5, 8, 'Email', 1, 0, 'C', true);
            $this->Cell($col6, 8, 'Inv', 1, 0, 'C', true);
            $this->Cell($col7, 8, 'Purchases', 1, 0, 'C', true);
            $this->Cell($col8, 8, 'Paid', 1, 0, 'C', true);
            $this->Cell($col9, 8, 'Outstanding', 1, 0, 'C', true);
            $this->Cell($col10, 8, 'Last Sale', 1, 1, 'C', true);

            $this->SetTextColor(0, 0, 0);
            $this->SetFont('Arial', '', 8);

            $totalPurchases = 0;
            $totalPaid = 0;
            $totalOutstanding = 0;

            foreach ($this->customers as $customer) {
                if ($this->GetY() > 180) {
                    $this->AddPage();
                    $this->SetFillColor(41, 128, 185);
                    $this->SetTextColor(255, 255, 255);
                    $this->SetFont('Arial', 'B', 8);
                    $this->Cell($col1, 8, 'Code', 1, 0, 'C', true);
                    $this->Cell($col2, 8, 'Customer', 1, 0, 'C', true);
                    $this->Cell($col3, 8, 'Type', 1, 0, 'C', true);
                    $this->Cell($col4, 8, 'Phone', 1, 0, 'C', true);
                    $this->Cell($col5, 8, 'Email', 1, 0, 'C', true);
                    $this->Cell($col6, 8, 'Inv', 1, 0, 'C', true);
                    $this->Cell($col7, 8, 'Purchases', 1, 0, 'C', true);
                    $this->Cell($col8, 8, 'Paid', 1, 0, 'C', true);
                    $this->Cell($col9, 8, 'Outstanding', 1, 0, 'C', true);
                    $this->Cell($col10, 8, 'Last Sale', 1, 1, 'C', true);
                    $this->SetTextColor(0, 0, 0);
                    $this->SetFont('Arial', '', 8);
                }

                $name = $this->shortenText($customer['full_name'], 25);
                $phone = $this->shortenText($customer['phone'] ?? '-', 15);
                $email = $this->shortenText($customer['email'] ?? '-', 20);
                $typeLabel = $customer['customer_type'] === 'company' ? 'Company' : 'Individual';

                $this->Cell($col1, 7, $customer['customer_code'], 1);
                $this->Cell($col2, 7, $name, 1);
                $this->Cell($col3, 7, $typeLabel, 1, 0, 'C');
                $this->Cell($col4, 7, $phone, 1);
                $this->Cell($col5, 7, $email, 1);
                $this->Cell($col6, 7, number_format((float)$customer['total_invoices'], 0), 1, 0, 'C');
                $this->Cell($col7, 7, number_format((float)$customer['total_purchases'], 0) . ' ' . $this->currency, 1, 0, 'R');
                $this->Cell($col8, 7, number_format((float)$customer['total_paid'], 0) . ' ' . $this->currency, 1, 0, 'R');

                // Outstanding with color
                if ($customer['outstanding'] > 0) {
                    $this->SetTextColor(231, 76, 60);
                }
                $this->Cell($col9, 7, number_format((float)$customer['outstanding'], 0) . ' ' . $this->currency, 1, 0, 'R');
                $this->SetTextColor(0, 0, 0);

                $lastSale = $customer['last_purchase_date'] ? date('d/m/Y', strtotime($customer['last_purchase_date'])) : '-';
                $this->Cell($col10, 7, $lastSale, 1, 1, 'C');

                $totalPurchases += $customer['total_purchases'];
                $totalPaid += $customer['total_paid'];
                $totalOutstanding += $customer['outstanding'];
            }

            // Totals row
            $this->SetFont('Arial', 'B', 8);
            $this->SetFillColor(240, 240, 240);
            $this->Cell($col1 + $col2 + $col3 + $col4 + $col5, 7, 'TOTAL', 1, 0, 'R', true);
            $this->Cell($col6, 7, number_format((float)array_sum(array_column($this->customers, 'total_invoices')), 0), 1, 0, 'C', true);
            $this->Cell($col7, 7, number_format((float)$totalPurchases, 0) . ' ' . $this->currency, 1, 0, 'R', true);
            $this->Cell($col8, 7, number_format((float)$totalPaid, 0) . ' ' . $this->currency, 1, 0, 'R', true);
            $this->Cell($col9, 7, number_format((float)$totalOutstanding, 0) . ' ' . $this->currency, 1, 0, 'R', true);
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

    $pdf = new CustomerReportPDF(
        $customers,
        $totalCustomers,
        $totalPurchases,
        $totalPaid,
        $totalOutstanding,
        $avgPurchasePerCustomer,
        $startDate,
        $endDate,
        $customerType,
        $sortBy,
        $company,
        $currency
    );
    $pdf->AliasNbPages();
    $pdf->AddPage();

    $pdf->SummarySection();
    $pdf->CustomersTable();

    $filename = 'Customer_Report_' . date('Y-m-d') . '.pdf';
    $pdf->Output('D', $filename);
    exit;

} elseif ($format === 'excel') {
    // Excel/CSV Export
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Customer_Report_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Company info
    fputcsv($output, [$company['company_name'] ?? 'SATI ERP']);
    fputcsv($output, ['CUSTOMER REPORT']);
    fputcsv($output, ['Generated:', date('d/m/Y H:i:s')]);
    fputcsv($output, ['Period:', date('d/m/Y', strtotime($startDate)), 'to', date('d/m/Y', strtotime($endDate))]);
    if ($customerType) {
        fputcsv($output, ['Customer Type:', ucfirst($customerType)]);
    }
    fputcsv($output, []);

    // Summary
    fputcsv($output, ['SUMMARY']);
    fputcsv($output, ['Total Customers', number_format((float)$totalCustomers, 0)]);
    fputcsv($output, ['Total Purchases', number_format((float)$totalPurchases, 0) . ' ' . $currency]);
    fputcsv($output, ['Total Paid', number_format((float)$totalPaid, 0) . ' ' . $currency]);
    fputcsv($output, ['Total Outstanding', number_format((float)$totalOutstanding, 0) . ' ' . $currency]);
    fputcsv($output, ['Average per Customer', number_format((float)$avgPurchasePerCustomer, 0) . ' ' . $currency]);
    fputcsv($output, []);

    // Customer Details
    fputcsv($output, ['CUSTOMER DETAILS']);
    fputcsv($output, ['Code', 'Customer', 'Type', 'Phone', 'Email', 'Invoices', 'Purchases', 'Paid', 'Outstanding', 'Last Purchase']);

    foreach ($customers as $customer) {
        fputcsv($output, [
            $customer['customer_code'],
            $customer['full_name'],
            $customer['customer_type'] === 'company' ? 'Company' : 'Individual',
            $customer['phone'] ?? '-',
            $customer['email'] ?? '-',
            $customer['total_invoices'],
            number_format((float)$customer['total_purchases'], 0) . ' ' . $currency,
            number_format((float)$customer['total_paid'], 0) . ' ' . $currency,
            number_format((float)$customer['outstanding'], 0) . ' ' . $currency,
            $customer['last_purchase_date'] ? date('d/m/Y', strtotime($customer['last_purchase_date'])) : '-'
        ]);
    }

    fclose($output);
    exit;
}

// If we get here, something went wrong
header('HTTP/1.1 400 Bad Request');
echo 'Invalid export format';
exit;