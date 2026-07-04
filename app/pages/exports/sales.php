<?php
// pages/exports/sales.php
// SALES REPORT EXPORT (PDF/CSV)
declare(strict_types=1);

while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Sale.php';
require_once __DIR__ . '/../../models/Customer.php';
require_once __DIR__ . '/../../models/User.php';
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
$startDate = $_GET['start_date'] ?? date('Y-m-01');
$endDate = $_GET['end_date'] ?? date('Y-m-t');
$customerId = isset($_GET['customer_id']) ? (int) $_GET['customer_id'] : null;
$status = $_GET['status'] ?? '';

// Initialize models with company context
$saleModel = new Sale($companyId);
$customerModel = new Customer($companyId);
$userModel = new User($companyId);

// Get sales data
$salesSummary = $saleModel->getSalesSummary($startDate, $endDate, $customerId, $status);
$dailySales = $saleModel->getDailySalesRange($startDate, $endDate, $customerId, $status);
$invoices = $saleModel->getInvoicesByDateRange($startDate, $endDate, $customerId, $status);
$topProducts = $saleModel->getTopProductsByDateRange($startDate, $endDate, 20);
$paymentMethods = $saleModel->getSalesByPaymentMethod($startDate, $endDate);
$salesByCustomer = $saleModel->getSalesByCustomer($startDate, $endDate, 20);

// Ensure we have arrays even if no data
if (!is_array($dailySales))
    $dailySales = [];
if (!is_array($invoices))
    $invoices = [];
if (!is_array($topProducts))
    $topProducts = [];
if (!is_array($paymentMethods))
    $paymentMethods = [];
if (!is_array($salesByCustomer))
    $salesByCustomer = [];

// Get customer name if filtered
$customerName = '';
if ($customerId) {
    $customer = $customerModel->find($customerId);
    $customerName = $customer ? $customer['full_name'] : '';
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
    class SalesReportPDF extends FPDF
    {
        private $startDate;
        private $endDate;
        private $summary;
        private $dailySales;
        private $invoices;
        private $topProducts;
        private $paymentMethods;
        private $salesByCustomer;
        private $customerName;
        private $company;
        private $currency;

        function __construct($startDate, $endDate, $summary, $dailySales, $invoices, $topProducts, $paymentMethods, $salesByCustomer, $customerName, $company, $currency)
        {
            parent::__construct('L', 'mm', 'A4');
            $this->startDate = $startDate;
            $this->endDate = $endDate;
            $this->summary = $summary;
            $this->dailySales = $dailySales;
            $this->invoices = $invoices;
            $this->topProducts = $topProducts;
            $this->paymentMethods = $paymentMethods;
            $this->salesByCustomer = $salesByCustomer;
            $this->customerName = $customerName;
            $this->company = $company;
            $this->currency = $currency;
        }

        function Header()
        {
            // Company header
            $this->SetFont('Arial', 'B', 14);
            $this->SetTextColor(41, 128, 185);
            $this->Cell(0, 8, $this->company['company_name'] ?? 'SATI ERP', 0, 1, 'C');

            $this->SetFont('Arial', 'B', 20);
            $this->Cell(0, 12, 'SALES REPORT', 0, 1, 'C');

            $this->SetFont('Arial', '', 10);
            $this->SetTextColor(100, 100, 100);

            $dateRange = 'Period: ' . date('d/m/Y', strtotime($this->startDate)) . ' to ' . date('d/m/Y', strtotime($this->endDate));
            if ($this->customerName) {
                $dateRange .= ' | Customer: ' . $this->customerName;
            }

            $this->Cell(0, 6, $dateRange, 0, 1, 'C');
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
            $this->SetFont('Arial', 'B', 14);
            $this->SetTextColor(41, 128, 185);
            $this->Cell(0, 8, 'EXECUTIVE SUMMARY', 0, 1);
            $this->Ln(2);

            $this->SetFont('Arial', '', 10);
            $this->SetTextColor(0, 0, 0);

            $colWidth = 70;
            $startX = 20;

            $totalSales = isset($this->summary['total_sales']) ? (float) $this->summary['total_sales'] : 0;
            $totalInvoices = isset($this->summary['total_invoices']) ? (float) $this->summary['total_invoices'] : 0;
            $totalCollected = isset($this->summary['total_collected']) ? (float) $this->summary['total_collected'] : 0;
            $outstanding = isset($this->summary['outstanding']) ? (float) $this->summary['outstanding'] : 0;
            $uniqueCustomers = isset($this->summary['unique_customers']) ? (float) $this->summary['unique_customers'] : 0;
            $avgOrderValue = isset($this->summary['avg_order_value']) ? (float) $this->summary['avg_order_value'] : 0;

            $this->SetX($startX);
            $this->Cell($colWidth, 7, 'Total Sales:', 0, 0);
            $this->Cell($colWidth, 7, number_format((float)$totalSales, 0) . ' ' . $this->currency, 0, 1);

            $this->SetX($startX);
            $this->Cell($colWidth, 7, 'Total Invoices:', 0, 0);
            $this->Cell($colWidth, 7, number_format((float)$totalInvoices, 0), 0, 1);

            $this->SetX($startX);
            $this->Cell($colWidth, 7, 'Total Collected:', 0, 0);
            $this->Cell($colWidth, 7, number_format((float)$totalCollected, 0) . ' ' . $this->currency, 0, 1);

            $this->SetX($startX);
            $this->Cell($colWidth, 7, 'Outstanding:', 0, 0);
            $this->Cell($colWidth, 7, number_format((float)$outstanding, 0) . ' ' . $this->currency, 0, 1);

            $this->SetX($startX);
            $this->Cell($colWidth, 7, 'Unique Customers:', 0, 0);
            $this->Cell($colWidth, 7, number_format((float)$uniqueCustomers, 0), 0, 1);

            $this->SetX($startX);
            $this->Cell($colWidth, 7, 'Average Order Value:', 0, 0);
            $this->Cell($colWidth, 7, number_format((float)$avgOrderValue, 0) . ' ' . $this->currency, 0, 1);

            $this->Ln(8);
        }

        function DailySalesSection()
        {
            if (empty($this->dailySales))
                return;

            $this->SetFont('Arial', 'B', 14);
            $this->SetTextColor(41, 128, 185);
            $this->Cell(0, 8, 'DAILY SALES BREAKDOWN', 0, 1);
            $this->Ln(2);

            $this->SetFillColor(41, 128, 185);
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Arial', 'B', 9);

            $col1 = 35;
            $col2 = 30;
            $col3 = 40;
            $col4 = 40;
            $col5 = 40;
            $col6 = 35;

            $this->Cell($col1, 8, 'Date', 1, 0, 'C', true);
            $this->Cell($col2, 8, 'Invoices', 1, 0, 'C', true);
            $this->Cell($col3, 8, 'Sales', 1, 0, 'C', true);
            $this->Cell($col4, 8, 'Payments', 1, 0, 'C', true);
            $this->Cell($col5, 8, 'Outstanding', 1, 0, 'C', true);
            $this->Cell($col6, 8, 'Items Sold', 1, 1, 'C', true);

            $this->SetTextColor(0, 0, 0);
            $this->SetFont('Arial', '', 9);

            $totalSales = 0;
            $totalPayments = 0;
            $totalItems = 0;

            foreach ($this->dailySales as $day) {
                if ($this->GetY() > 180) {
                    $this->AddPage();
                    $this->SetFillColor(41, 128, 185);
                    $this->SetTextColor(255, 255, 255);
                    $this->SetFont('Arial', 'B', 9);
                    $this->Cell($col1, 8, 'Date', 1, 0, 'C', true);
                    $this->Cell($col2, 8, 'Invoices', 1, 0, 'C', true);
                    $this->Cell($col3, 8, 'Sales', 1, 0, 'C', true);
                    $this->Cell($col4, 8, 'Payments', 1, 0, 'C', true);
                    $this->Cell($col5, 8, 'Outstanding', 1, 0, 'C', true);
                    $this->Cell($col6, 8, 'Items Sold', 1, 1, 'C', true);
                    $this->SetTextColor(0, 0, 0);
                    $this->SetFont('Arial', '', 9);
                }

                $date = isset($day['date']) ? date('d/m/Y', strtotime($day['date'])) : '-';
                $invCount = isset($day['invoice_count']) ? (float) $day['invoice_count'] : 0;
                $sales = isset($day['sales']) ? (float) $day['sales'] : 0;
                $payments = isset($day['payments']) ? (float) $day['payments'] : 0;
                $outstanding = isset($day['outstanding']) ? (float) $day['outstanding'] : 0;
                $items = isset($day['items_sold']) ? (float) $day['items_sold'] : 0;

                $this->Cell($col1, 7, $date, 1);
                $this->Cell($col2, 7, number_format((float)$invCount, 0), 1, 0, 'C');
                $this->Cell($col3, 7, number_format((float)$sales, 0) . ' ' . $this->currency, 1, 0, 'R');
                $this->Cell($col4, 7, number_format((float)$payments, 0) . ' ' . $this->currency, 1, 0, 'R');
                $this->Cell($col5, 7, number_format((float)$outstanding, 0) . ' ' . $this->currency, 1, 0, 'R');
                $this->Cell($col6, 7, number_format((float)$items, 0), 1, 1, 'C');

                $totalSales += $sales;
                $totalPayments += $payments;
                $totalItems += $items;
            }

            $this->SetFont('Arial', 'B', 9);
            $this->SetFillColor(240, 240, 240);
            $this->Cell($col1, 7, 'TOTAL', 1, 0, 'C', true);
            $this->Cell($col2, 7, '', 1, 0, 'C', true);
            $this->Cell($col3, 7, number_format((float)$totalSales, 0) . ' ' . $this->currency, 1, 0, 'R', true);
            $this->Cell($col4, 7, number_format((float)$totalPayments, 0) . ' ' . $this->currency, 1, 0, 'R', true);
            $this->Cell($col5, 7, number_format((float)$totalSales - $totalPayments, 0) . ' ' . $this->currency, 1, 0, 'R', true);
            $this->Cell($col6, 7, number_format((float)$totalItems, 0), 1, 1, 'C', true);

            $this->Ln(8);
        }

        function InvoicesSection()
        {
            if (empty($this->invoices))
                return;

            $this->SetFont('Arial', 'B', 14);
            $this->SetTextColor(41, 128, 185);
            $this->Cell(0, 8, 'INVOICE DETAILS', 0, 1);
            $this->Ln(2);

            $this->SetFillColor(41, 128, 185);
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Arial', 'B', 8);

            $col1 = 35;
            $col2 = 30;
            $col3 = 35;
            $col4 = 30;
            $col5 = 30;
            $col6 = 35;
            $col7 = 30;

            $this->Cell($col1, 8, 'Invoice #', 1, 0, 'C', true);
            $this->Cell($col2, 8, 'Date', 1, 0, 'C', true);
            $this->Cell($col3, 8, 'Customer', 1, 0, 'C', true);
            $this->Cell($col4, 8, 'Subtotal', 1, 0, 'C', true);
            $this->Cell($col5, 8, 'Tax', 1, 0, 'C', true);
            $this->Cell($col6, 8, 'Total', 1, 0, 'C', true);
            $this->Cell($col7, 8, 'Status', 1, 1, 'C', true);

            $this->SetTextColor(0, 0, 0);
            $this->SetFont('Arial', '', 8);

            $totalSubtotal = 0;
            $totalTax = 0;
            $totalAmount = 0;

            foreach ($this->invoices as $invoice) {
                if ($this->GetY() > 180) {
                    $this->AddPage();
                    $this->SetFillColor(41, 128, 185);
                    $this->SetTextColor(255, 255, 255);
                    $this->SetFont('Arial', 'B', 8);
                    $this->Cell($col1, 8, 'Invoice #', 1, 0, 'C', true);
                    $this->Cell($col2, 8, 'Date', 1, 0, 'C', true);
                    $this->Cell($col3, 8, 'Customer', 1, 0, 'C', true);
                    $this->Cell($col4, 8, 'Subtotal', 1, 0, 'C', true);
                    $this->Cell($col5, 8, 'Tax', 1, 0, 'C', true);
                    $this->Cell($col6, 8, 'Total', 1, 0, 'C', true);
                    $this->Cell($col7, 8, 'Status', 1, 1, 'C', true);
                    $this->SetTextColor(0, 0, 0);
                    $this->SetFont('Arial', '', 8);
                }

                $statusColors = [
                    'paid' => [46, 204, 113],
                    'partial' => [241, 196, 15],
                    'issued' => [52, 152, 219],
                    'overdue' => [231, 76, 60],
                    'draft' => [149, 165, 166],
                    'cancelled' => [149, 165, 166]
                ];
                $color = $statusColors[$invoice['status']] ?? [100, 100, 100];

                $this->Cell($col1, 7, $invoice['invoice_number'], 1);
                $this->Cell($col2, 7, date('d/m/Y', strtotime($invoice['invoice_date'])), 1);
                $this->Cell($col3, 7, $this->shortenText($invoice['customer_name'], 20), 1);
                $this->Cell($col4, 7, number_format((float)$invoice['subtotal'], 0) . ' ' . $this->currency, 1, 0, 'R');
                $this->Cell($col5, 7, number_format((float)$invoice['tax_amount'], 0) . ' ' . $this->currency, 1, 0, 'R');
                $this->Cell($col6, 7, number_format((float)$invoice['total_amount'], 0) . ' ' . $this->currency, 1, 0, 'R');

                $this->SetTextColor($color[0], $color[1], $color[2]);
                $this->Cell($col7, 7, ucfirst($invoice['status']), 1, 1, 'C');
                $this->SetTextColor(0, 0, 0);

                $totalSubtotal += $invoice['subtotal'];
                $totalTax += $invoice['tax_amount'];
                $totalAmount += $invoice['total_amount'];
            }

            $this->SetFont('Arial', 'B', 8);
            $this->SetFillColor(240, 240, 240);
            $this->Cell($col1 + $col2 + $col3, 7, 'TOTAL', 1, 0, 'R', true);
            $this->Cell($col4, 7, number_format((float)$totalSubtotal, 0) . ' ' . $this->currency, 1, 0, 'R', true);
            $this->Cell($col5, 7, number_format((float)$totalTax, 0) . ' ' . $this->currency, 1, 0, 'R', true);
            $this->Cell($col6, 7, number_format((float)$totalAmount, 0) . ' ' . $this->currency, 1, 0, 'R', true);
            $this->Cell($col7, 7, '', 1, 1, 'R', true);
        }

        function TopProductsSection()
        {
            if (empty($this->topProducts))
                return;

            $this->AddPage();
            $this->SetFont('Arial', 'B', 14);
            $this->SetTextColor(41, 128, 185);
            $this->Cell(0, 8, 'TOP SELLING PRODUCTS', 0, 1);
            $this->Ln(2);

            $this->SetFillColor(41, 128, 185);
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Arial', 'B', 9);

            $col1 = 50;
            $col2 = 65;
            $col3 = 30;
            $col4 = 35;

            $this->Cell($col1, 8, 'Product', 1, 0, 'C', true);
            $this->Cell($col2, 8, 'SKU', 1, 0, 'C', true);
            $this->Cell($col3, 8, 'Quantity', 1, 0, 'C', true);
            $this->Cell($col4, 8, 'Revenue', 1, 1, 'C', true);

            $this->SetTextColor(0, 0, 0);
            $this->SetFont('Arial', '', 9);

            $totalRevenue = 0;
            $totalQty = 0;

            foreach ($this->topProducts as $product) {
                if ($this->GetY() > 250) {
                    $this->AddPage();
                    $this->SetFillColor(41, 128, 185);
                    $this->SetTextColor(255, 255, 255);
                    $this->SetFont('Arial', 'B', 9);
                    $this->Cell($col1, 8, 'Product', 1, 0, 'C', true);
                    $this->Cell($col2, 8, 'SKU', 1, 0, 'C', true);
                    $this->Cell($col3, 8, 'Quantity', 1, 0, 'C', true);
                    $this->Cell($col4, 8, 'Revenue', 1, 1, 'C', true);
                    $this->SetTextColor(0, 0, 0);
                    $this->SetFont('Arial', '', 9);
                }

                $productName = $product['product_name'] ?? 'Unknown';
                if (!empty($product['variant_name']) && $product['variant_name'] !== 'Standard') {
                    $productName .= ' - ' . $product['variant_name'];
                }

                $sku = $product['sku'] ?? '-';
                $sold = isset($product['total_sold']) ? (float) $product['total_sold'] : 0;
                $revenue = isset($product['total_revenue']) ? (float) $product['total_revenue'] : 0;

                $this->Cell($col1, 7, $this->shortenText($productName, 45), 1);
                $this->Cell($col2, 7, $sku, 1, 0, 'C');
                $this->Cell($col3, 7, number_format((float)$sold, 0), 1, 0, 'R');
                $this->Cell($col4, 7, number_format((float)$revenue, 0) . ' ' . $this->currency, 1, 1, 'R');

                $totalRevenue += $revenue;
                $totalQty += $sold;
            }

            $this->SetFont('Arial', 'B', 9);
            $this->SetFillColor(240, 240, 240);
            $this->Cell($col1, 7, 'TOTAL', 1, 0, 'R', true);
            $this->Cell($col2, 7, '', 1, 0, 'C', true);
            $this->Cell($col3, 7, number_format((float)$totalQty, 0), 1, 0, 'R', true);
            $this->Cell($col4, 7, number_format((float)$totalRevenue, 0) . ' ' . $this->currency, 1, 1, 'R', true);

            $this->Ln(8);
        }

        function PaymentMethodsSection()
        {
            if (empty($this->paymentMethods))
                return;

            $this->SetFont('Arial', 'B', 14);
            $this->SetTextColor(41, 128, 185);
            $this->Cell(0, 8, 'PAYMENT METHODS', 0, 1);
            $this->Ln(2);

            $this->SetFillColor(41, 128, 185);
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Arial', 'B', 9);

            $col1 = 60;
            $col2 = 35;
            $col3 = 50;

            $this->Cell($col1, 8, 'Payment Method', 1, 0, 'C', true);
            $this->Cell($col2, 8, 'Count', 1, 0, 'C', true);
            $this->Cell($col3, 8, 'Amount', 1, 1, 'C', true);

            $this->SetTextColor(0, 0, 0);
            $this->SetFont('Arial', '', 9);

            $totalCount = 0;
            $totalAmount = 0;

            $methodLabels = [
                'cash' => 'Cash',
                'bank_transfer' => 'Bank Transfer',
                'mobile' => 'Mobile Money',
                'card' => 'Card',
                'cheque' => 'Cheque'
            ];

            foreach ($this->paymentMethods as $method) {
                $label = $methodLabels[$method['payment_method']] ?? ucfirst($method['payment_method']);
                $count = isset($method['payment_count']) ? (float) $method['payment_count'] : 0;
                $amount = isset($method['total_amount']) ? (float) $method['total_amount'] : 0;

                $this->Cell($col1, 7, $label, 1);
                $this->Cell($col2, 7, number_format((float)$count, 0), 1, 0, 'C');
                $this->Cell($col3, 7, number_format((float)$amount, 0) . ' ' . $this->currency, 1, 1, 'R');

                $totalCount += $count;
                $totalAmount += $amount;
            }

            $this->SetFont('Arial', 'B', 9);
            $this->SetFillColor(240, 240, 240);
            $this->Cell($col1, 7, 'TOTAL', 1, 0, 'R', true);
            $this->Cell($col2, 7, number_format((float)$totalCount, 0), 1, 0, 'C', true);
            $this->Cell($col3, 7, number_format((float)$totalAmount, 0) . ' ' . $this->currency, 1, 1, 'R', true);
        }

        function SalesByCustomerSection()
        {
            if (empty($this->salesByCustomer))
                return;

            $this->AddPage();
            $this->SetFont('Arial', 'B', 14);
            $this->SetTextColor(41, 128, 185);
            $this->Cell(0, 8, 'TOP CUSTOMERS', 0, 1);
            $this->Ln(2);

            $this->SetFillColor(41, 128, 185);
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Arial', 'B', 9);

            $col1 = 60;
            $col2 = 40;
            $col3 = 40;
            $col4 = 40;

            $this->Cell($col1, 8, 'Customer', 1, 0, 'C', true);
            $this->Cell($col2, 8, 'Invoices', 1, 0, 'C', true);
            $this->Cell($col3, 8, 'Total Purchases', 1, 0, 'C', true);
            $this->Cell($col4, 8, 'Avg Order', 1, 1, 'C', true);

            $this->SetTextColor(0, 0, 0);
            $this->SetFont('Arial', '', 9);

            foreach ($this->salesByCustomer as $customer) {
                if ($this->GetY() > 250) {
                    $this->AddPage();
                    $this->SetFillColor(41, 128, 185);
                    $this->SetTextColor(255, 255, 255);
                    $this->SetFont('Arial', 'B', 9);
                    $this->Cell($col1, 8, 'Customer', 1, 0, 'C', true);
                    $this->Cell($col2, 8, 'Invoices', 1, 0, 'C', true);
                    $this->Cell($col3, 8, 'Total Purchases', 1, 0, 'C', true);
                    $this->Cell($col4, 8, 'Avg Order', 1, 1, 'C', true);
                    $this->SetTextColor(0, 0, 0);
                    $this->SetFont('Arial', '', 9);
                }

                $this->Cell($col1, 7, $this->shortenText($customer['customer_name'], 35), 1);
                $this->Cell($col2, 7, number_format((float)$customer['invoice_count'], 0), 1, 0, 'C');
                $this->Cell($col3, 7, number_format((float)$customer['total_purchases'], 0) . ' ' . $this->currency, 1, 0, 'R');
                $this->Cell($col4, 7, number_format((float)$customer['avg_order_value'], 0) . ' ' . $this->currency, 1, 1, 'R');
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

    // Create PDF
    $pdf = new SalesReportPDF($startDate, $endDate, $salesSummary, $dailySales, $invoices, $topProducts, $paymentMethods, $salesByCustomer, $customerName, $company, $currency);
    $pdf->AliasNbPages();
    $pdf->AddPage();

    $pdf->SummarySection();
    $pdf->DailySalesSection();
    $pdf->InvoicesSection();
    $pdf->TopProductsSection();
    $pdf->PaymentMethodsSection();
    $pdf->SalesByCustomerSection();

    $filename = 'Sales_Report_' . date('Y-m-d') . '.pdf';
    $pdf->Output('D', $filename);
    exit;

} elseif ($format === 'excel') {
    // Excel/CSV Export
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Sales_Report_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    // Company info
    fputcsv($output, [$company['company_name'] ?? 'SATI ERP']);
    fputcsv($output, ['SALES REPORT']);
    fputcsv($output, ['Period:', date('d/m/Y', strtotime($startDate)), 'to', date('d/m/Y', strtotime($endDate))]);
    if ($customerName)
        fputcsv($output, ['Customer:', $customerName]);
    fputcsv($output, ['Generated:', date('d/m/Y H:i:s')]);
    fputcsv($output, []);

    // Summary
    fputcsv($output, ['EXECUTIVE SUMMARY']);
    fputcsv($output, ['Total Sales', number_format((float) ($salesSummary['total_sales'] ?? 0), 0) . ' ' . $currency]);
    fputcsv($output, ['Total Invoices', number_format((float) ($salesSummary['total_invoices'] ?? 0), 0)]);
    fputcsv($output, ['Total Collected', number_format((float) ($salesSummary['total_collected'] ?? 0), 0) . ' ' . $currency]);
    fputcsv($output, ['Outstanding', number_format((float) ($salesSummary['outstanding'] ?? 0), 0) . ' ' . $currency]);
    fputcsv($output, ['Unique Customers', number_format((float) ($salesSummary['unique_customers'] ?? 0), 0)]);
    fputcsv($output, ['Average Order Value', number_format((float) ($salesSummary['avg_order_value'] ?? 0), 0) . ' ' . $currency]);
    fputcsv($output, []);

    // Daily Sales
    if (!empty($dailySales)) {
        fputcsv($output, ['DAILY SALES BREAKDOWN']);
        fputcsv($output, ['Date', 'Invoices', 'Sales', 'Payments', 'Outstanding', 'Items Sold']);
        foreach ($dailySales as $day) {
            fputcsv($output, [
                date('d/m/Y', strtotime($day['date'])),
                $day['invoice_count'] ?? 0,
                number_format((float) ($day['sales'] ?? 0), 0) . ' ' . $currency,
                number_format((float) ($day['payments'] ?? 0), 0) . ' ' . $currency,
                number_format((float) ($day['outstanding'] ?? 0), 0) . ' ' . $currency,
                number_format((float) ($day['items_sold'] ?? 0), 0)
            ]);
        }
        fputcsv($output, []);
    }

    // Invoices
    if (!empty($invoices)) {
        fputcsv($output, ['INVOICE DETAILS']);
        fputcsv($output, ['Invoice #', 'Date', 'Customer', 'Subtotal', 'Tax', 'Total', 'Status']);
        foreach ($invoices as $invoice) {
            fputcsv($output, [
                $invoice['invoice_number'],
                date('d/m/Y', strtotime($invoice['invoice_date'])),
                $invoice['customer_name'],
                number_format((float)$invoice['subtotal'], 0) . ' ' . $currency,
                number_format((float)$invoice['tax_amount'], 0) . ' ' . $currency,
                number_format((float)$invoice['total_amount'], 0) . ' ' . $currency,
                ucfirst($invoice['status'])
            ]);
        }
        fputcsv($output, []);
    }

    // Top Products
    if (!empty($topProducts)) {
        fputcsv($output, ['TOP SELLING PRODUCTS']);
        fputcsv($output, ['Product', 'SKU', 'Quantity Sold', 'Revenue']);
        foreach ($topProducts as $product) {
            $productName = $product['product_name'] ?? 'Unknown';
            if (!empty($product['variant_name']) && $product['variant_name'] !== 'Standard') {
                $productName .= ' - ' . $product['variant_name'];
            }
            fputcsv($output, [
                $productName,
                $product['sku'] ?? '-',
                number_format((float) ($product['total_sold'] ?? 0), 0),
                number_format((float) ($product['total_revenue'] ?? 0), 0) . ' ' . $currency
            ]);
        }
        fputcsv($output, []);
    }

    // Payment Methods
    if (!empty($paymentMethods)) {
        fputcsv($output, ['PAYMENT METHODS']);
        fputcsv($output, ['Method', 'Count', 'Amount']);
        $methodLabels = [
            'cash' => 'Cash',
            'bank_transfer' => 'Bank Transfer',
            'mobile' => 'Mobile Money',
            'card' => 'Card',
            'cheque' => 'Cheque'
        ];
        foreach ($paymentMethods as $method) {
            $label = $methodLabels[$method['payment_method']] ?? ucfirst($method['payment_method']);
            fputcsv($output, [
                $label,
                $method['payment_count'] ?? 0,
                number_format((float) ($method['total_amount'] ?? 0), 0) . ' ' . $currency
            ]);
        }
        fputcsv($output, []);
    }

    // Top Customers
    if (!empty($salesByCustomer)) {
        fputcsv($output, ['TOP CUSTOMERS']);
        fputcsv($output, ['Customer', 'Invoices', 'Total Purchases', 'Average Order']);
        foreach ($salesByCustomer as $customer) {
            fputcsv($output, [
                $customer['customer_name'],
                number_format((float)$customer['invoice_count'], 0),
                number_format((float)$customer['total_purchases'], 0) . ' ' . $currency,
                number_format((float)$customer['avg_order_value'], 0) . ' ' . $currency
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