<?php
// pages/exports/invoice.php
// INVOICE EXPORT (PDF/HTML)
declare(strict_types=1);

while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Sale.php';
require_once __DIR__ . '/../../models/Customer.php';
require_once __DIR__ . '/../../models/Company.php';
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
$userId = $_SESSION['user_id'] ?? 0;

// Check if company context is set
if (!$companyId) {
    ob_end_clean();
    header('HTTP/1.1 400 Bad Request');
    exit('Company context not found.');
}

// Get invoice ID from query string
$invoiceId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$format = isset($_GET['format']) ? strtolower($_GET['format']) : 'pdf';
$action = isset($_GET['action']) ? strtolower($_GET['action']) : 'view';

if (!$invoiceId) {
    ob_end_clean();
    header('HTTP/1.1 400 Bad Request');
    exit('Invoice ID is required.');
}

// Initialize models with company context
$saleModel = new Sale($companyId);
$customerModel = new Customer($companyId);
$companyModel = new Company();

// Get invoice details
$invoice = $saleModel->getInvoiceWithDetails($invoiceId);

if (!$invoice) {
    ob_end_clean();
    header('HTTP/1.1 404 Not Found');
    exit('Invoice not found or you do not have permission to view it.');
}

// Get company details
$company = $companyModel->find($companyId);

// Get currency
$currency = $_SESSION['company_currency'] ?? ($company['currency'] ?? 'RWF');

// Calculate additional totals
$balanceDue = $invoice['total_amount'] - $invoice['amount_paid'];

// Get payment status badge info
function getStatusInfo($status) {
    $statuses = [
        'paid' => ['text' => 'PAID', 'color' => [46, 204, 113]],
        'partial' => ['text' => 'PARTIALLY PAID', 'color' => [241, 196, 15]],
        'issued' => ['text' => 'ISSUED', 'color' => [52, 152, 219]],
        'overdue' => ['text' => 'OVERDUE', 'color' => [231, 76, 60]],
        'cancelled' => ['text' => 'CANCELLED', 'color' => [149, 165, 166]],
        'draft' => ['text' => 'DRAFT', 'color' => [149, 165, 166]]
    ];
    return $statuses[$status] ?? ['text' => strtoupper($status), 'color' => [100, 100, 100]];
}

$statusInfo = getStatusInfo($invoice['status']);

// Clean output buffer
ob_end_clean();

if ($format === 'pdf') {
    // PDF Export class definition
    class InvoicePDF extends FPDF
    {
        private $invoice;
        private $company;
        private $currency;
        private $statusInfo;

        function __construct($invoice, $company, $currency, $statusInfo)
        {
            parent::__construct('P', 'mm', 'A4');
            $this->invoice = $invoice;
            $this->company = $company;
            $this->currency = $currency;
            $this->statusInfo = $statusInfo;
        }

        function Header()
        {
            // Company header
            $this->SetY(10);
            $this->SetFont('Arial', 'B', 16);
            $this->SetTextColor(41, 128, 185);
            $this->Cell(0, 8, $this->company['company_name'] ?? 'SATI ERP', 0, 1, 'C');
            
            $this->SetFont('Arial', 'B', 24);
            $this->SetTextColor(0, 0, 0);
            $this->Cell(0, 12, 'INVOICE', 0, 1, 'C');
            
            // Status badge
            $this->SetFont('Arial', 'B', 10);
            $this->SetTextColor($this->statusInfo['color'][0], $this->statusInfo['color'][1], $this->statusInfo['color'][2]);
            $this->Cell(0, 6, $this->statusInfo['text'], 0, 1, 'C');
            
            $this->Ln(3);
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

        function CompanyAndCustomerInfo()
        {
            // Company Info
            $this->SetFont('Arial', 'B', 10);
            $this->SetTextColor(0, 0, 0);
            $this->Cell(80, 6, 'FROM:', 0, 1);
            
            $this->SetFont('Arial', '', 9);
            $this->SetTextColor(80, 80, 80);
            $this->Cell(80, 5, $this->company['company_name'] ?? 'Company Name', 0, 1);
            
            $address = $this->company['address'] ?? '';
            if ($address) {
                $addressLines = explode("\n", wordwrap($address, 40));
                foreach ($addressLines as $line) {
                    $this->Cell(80, 4, trim($line), 0, 1);
                }
            }
            
            if (!empty($this->company['phone'])) {
                $this->Cell(80, 4, 'Tel: ' . $this->company['phone'], 0, 1);
            }
            if (!empty($this->company['email'])) {
                $this->Cell(80, 4, 'Email: ' . $this->company['email'], 0, 1);
            }
            if (!empty($this->company['tax_id'])) {
                $this->Cell(80, 4, 'Tax ID: ' . $this->company['tax_id'], 0, 1);
            }
            
            // Invoice Info (Right side)
            $this->SetY($this->GetY() - 45);
            $this->SetX(110);
            
            $this->SetFont('Arial', 'B', 10);
            $this->SetTextColor(0, 0, 0);
            $this->Cell(40, 6, 'INVOICE #:', 0, 0);
            $this->SetFont('Arial', '', 10);
            $this->Cell(40, 6, $this->invoice['invoice_number'], 0, 1);
            
            $this->SetX(110);
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(40, 6, 'Date:', 0, 0);
            $this->SetFont('Arial', '', 10);
            $this->Cell(40, 6, date('d/m/Y', strtotime($this->invoice['invoice_date'])), 0, 1);
            
            $this->SetX(110);
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(40, 6, 'Due Date:', 0, 0);
            $this->SetFont('Arial', '', 10);
            $this->Cell(40, 6, date('d/m/Y', strtotime($this->invoice['due_date'])), 0, 1);
            
            $this->SetX(110);
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(40, 6, 'Created By:', 0, 0);
            $this->SetFont('Arial', '', 10);
            $this->Cell(40, 6, $this->invoice['created_by_name'] ?? 'System', 0, 1);
            
            $this->Ln(10);
            
            // Customer Info
            $this->SetFont('Arial', 'B', 10);
            $this->SetTextColor(0, 0, 0);
            $this->Cell(80, 6, 'BILL TO:', 0, 1);
            
            $this->SetFont('Arial', '', 9);
            $this->SetTextColor(80, 80, 80);
            $this->Cell(80, 5, $this->invoice['customer_name'], 0, 1);
            
            $customerAddress = $this->invoice['customer_address'] ?? '';
            if ($customerAddress) {
                $addressLines = explode("\n", wordwrap($customerAddress, 40));
                foreach ($addressLines as $line) {
                    $this->Cell(80, 4, trim($line), 0, 1);
                }
            }
            
            if (!empty($this->invoice['customer_phone'])) {
                $this->Cell(80, 4, 'Tel: ' . $this->invoice['customer_phone'], 0, 1);
            }
            if (!empty($this->invoice['customer_email'])) {
                $this->Cell(80, 4, 'Email: ' . $this->invoice['customer_email'], 0, 1);
            }
            
            $this->Ln(15);
        }

        function ItemsTable()
        {
            $this->SetFont('Arial', 'B', 10);
            $this->SetFillColor(41, 128, 185);
            $this->SetTextColor(255, 255, 255);
            
            $col1 = 10;  // #
            $col2 = 70;  // Product
            $col3 = 20;  // Qty
            $col4 = 25;  // Unit Price
            $col5 = 20;  // Discount
            $col6 = 20;  // Tax
            $col7 = 25;  // Total
            
            $this->Cell($col1, 8, '#', 1, 0, 'C', true);
            $this->Cell($col2, 8, 'Product / Item', 1, 0, 'C', true);
            $this->Cell($col3, 8, 'Qty', 1, 0, 'C', true);
            $this->Cell($col4, 8, 'Unit Price', 1, 0, 'C', true);
            $this->Cell($col5, 8, 'Disc %', 1, 0, 'C', true);
            $this->Cell($col6, 8, 'Tax %', 1, 0, 'C', true);
            $this->Cell($col7, 8, 'Total', 1, 1, 'C', true);
            
            $this->SetTextColor(0, 0, 0);
            $this->SetFont('Arial', '', 9);
            $this->SetFillColor(255, 255, 255);
            
            $counter = 1;
            foreach ($this->invoice['items'] as $item) {
                $subtotal = $item['quantity'] * $item['unit_price'];
                $discount = $subtotal * ($item['discount_percent'] / 100);
                $afterDiscount = $subtotal - $discount;
                $tax = $afterDiscount * ($item['tax_rate'] / 100);
                $lineTotal = $afterDiscount + $tax;
                
                $productName = $item['product_name'];
                if (!empty($item['variant_name']) && $item['variant_name'] !== 'Standard') {
                    $productName .= ' - ' . $item['variant_name'];
                }
                
                $this->Cell($col1, 7, $counter, 1, 0, 'C');
                $this->Cell($col2, 7, $this->shortenText($productName, 40), 1, 0, 'L');
                $this->Cell($col3, 7, number_format($item['quantity'], 2), 1, 0, 'C');
                $this->Cell($col4, 7, $this->currency . ' ' . number_format($item['unit_price'], 2), 1, 0, 'R');
                $this->Cell($col5, 7, number_format($item['discount_percent'], 1) . '%', 1, 0, 'C');
                $this->Cell($col6, 7, number_format($item['tax_rate'], 1) . '%', 1, 0, 'C');
                $this->Cell($col7, 7, $this->currency . ' ' . number_format($lineTotal, 2), 1, 1, 'R');
                
                $counter++;
            }
            
            $this->Ln(5);
        }

        function SummarySection()
        {
            $col1 = 100;
            $col2 = 80;
            
            $balanceDue = $this->invoice['total_amount'] - $this->invoice['amount_paid'];
            
            $this->SetFont('Arial', '', 10);
            
            // Subtotal
            $this->SetX(110);
            $this->Cell($col1 - 110, 6, 'Subtotal:', 0, 0, 'R');
            $this->Cell($col2, 6, $this->currency . ' ' . number_format($this->invoice['subtotal'], 2), 0, 1, 'R');
            
            // Discount
            if (($this->invoice['discount_amount'] ?? 0) > 0) {
                $this->SetX(110);
                $this->SetTextColor(231, 76, 60);
                $this->Cell($col1 - 110, 6, 'Discount:', 0, 0, 'R');
                $this->Cell($col2, 6, '- ' . $this->currency . ' ' . number_format($this->invoice['discount_amount'] ?? 0, 2), 0, 1, 'R');
                $this->SetTextColor(0, 0, 0);
            }
            
            // Tax
            $this->SetX(110);
            $this->Cell($col1 - 110, 6, 'Tax Amount:', 0, 0, 'R');
            $this->Cell($col2, 6, $this->currency . ' ' . number_format($this->invoice['tax_amount'], 2), 0, 1, 'R');
            
            // Total
            $this->SetX(110);
            $this->SetFont('Arial', 'B', 11);
            $this->Cell($col1 - 110, 8, 'Total Amount:', 0, 0, 'R');
            $this->SetTextColor(41, 128, 185);
            $this->Cell($col2, 8, $this->currency . ' ' . number_format($this->invoice['total_amount'], 2), 0, 1, 'R');
            $this->SetTextColor(0, 0, 0);
            
            // Amount Paid
            if ($this->invoice['amount_paid'] > 0) {
                $this->SetX(110);
                $this->SetFont('Arial', '', 10);
                $this->Cell($col1 - 110, 6, 'Amount Paid:', 0, 0, 'R');
                $this->SetTextColor(46, 204, 113);
                $this->Cell($col2, 6, $this->currency . ' ' . number_format($this->invoice['amount_paid'], 2), 0, 1, 'R');
                $this->SetTextColor(0, 0, 0);
            }
            
            // Balance Due
            if ($balanceDue > 0) {
                $this->SetX(110);
                $this->SetFont('Arial', 'B', 11);
                $this->Cell($col1 - 110, 8, 'Balance Due:', 0, 0, 'R');
                $this->SetTextColor(231, 76, 60);
                $this->Cell($col2, 8, $this->currency . ' ' . number_format($balanceDue, 2), 0, 1, 'R');
                $this->SetTextColor(0, 0, 0);
            }
            
            $this->Ln(10);
        }

        function PaymentHistory()
        {
            if (empty($this->invoice['payments']))
                return;
            
            $this->SetFont('Arial', 'B', 11);
            $this->SetTextColor(41, 128, 185);
            $this->Cell(0, 8, 'PAYMENT HISTORY', 0, 1);
            $this->Ln(2);
            
            $this->SetFont('Arial', 'B', 9);
            $this->SetFillColor(240, 240, 240);
            $this->SetTextColor(0, 0, 0);
            
            $col1 = 35;
            $col2 = 45;
            $col3 = 35;
            $col4 = 40;
            $col5 = 35;
            
            $this->Cell($col1, 7, 'Date', 1, 0, 'C', true);
            $this->Cell($col2, 7, 'Payment #', 1, 0, 'C', true);
            $this->Cell($col3, 7, 'Method', 1, 0, 'C', true);
            $this->Cell($col4, 7, 'Amount', 1, 0, 'C', true);
            $this->Cell($col5, 7, 'Reference', 1, 1, 'C', true);
            
            $this->SetFont('Arial', '', 9);
            
            $methodLabels = [
                'cash' => 'Cash',
                'bank_transfer' => 'Bank Transfer',
                'mobile' => 'Mobile Money',
                'card' => 'Card',
                'cheque' => 'Cheque'
            ];
            
            foreach ($this->invoice['payments'] as $payment) {
                $method = $methodLabels[$payment['payment_method']] ?? ucfirst($payment['payment_method']);
                
                $this->Cell($col1, 6, date('d/m/Y', strtotime($payment['payment_date'])), 1);
                $this->Cell($col2, 6, $payment['payment_number'], 1);
                $this->Cell($col3, 6, $method, 1);
                $this->Cell($col4, 6, $this->currency . ' ' . number_format($payment['amount'], 2), 1, 0, 'R');
                $this->Cell($col5, 6, $payment['reference_number'] ?? '-', 1, 1);
            }
            
            $this->Ln(8);
        }

        function NotesSection()
        {
            if (!empty($this->invoice['notes'])) {
                $this->SetFont('Arial', 'B', 10);
                $this->SetTextColor(41, 128, 185);
                $this->Cell(0, 6, 'NOTES', 0, 1);
                
                $this->SetFont('Arial', '', 9);
                $this->SetTextColor(80, 80, 80);
                $this->MultiCell(0, 5, $this->invoice['notes'], 0, 'L');
                $this->Ln(5);
            }
        }

        function FooterMessage()
        {
            $this->SetY(-35);
            $this->SetFont('Arial', 'I', 9);
            $this->SetTextColor(100, 100, 100);
            $this->Cell(0, 5, 'Thank you for your business!', 0, 1, 'C');
            
            $balanceDue = $this->invoice['total_amount'] - $this->invoice['amount_paid'];
            if ($balanceDue > 0) {
                $this->SetTextColor(231, 76, 60);
                $this->Cell(0, 5, 'Please pay the outstanding balance by ' . date('d/m/Y', strtotime($this->invoice['due_date'])), 0, 1, 'C');
            }
            
            $this->SetTextColor(150, 150, 150);
            $this->SetFont('Arial', 'I', 7);
            $this->Cell(0, 4, 'This is a computer-generated document. No signature is required.', 0, 1, 'C');
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
    $pdf = new InvoicePDF($invoice, $company, $currency, $statusInfo);
    $pdf->AliasNbPages();
    $pdf->AddPage();
    
    $pdf->CompanyAndCustomerInfo();
    $pdf->ItemsTable();
    $pdf->SummarySection();
    $pdf->PaymentHistory();
    $pdf->NotesSection();
    $pdf->FooterMessage();
    
    $filename = 'Invoice_' . $invoice['invoice_number'] . '.pdf';
    $pdf->Output('I', $filename);
    exit;

} elseif ($format === 'html') {
    // HTML Export (for browser view and printing)
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?></title>
        <style>
            @media print {
                body {
                    padding: 0;
                    margin: 0;
                }
                .no-print {
                    display: none !important;
                }
                @page {
                    size: A4;
                    margin: 1.5cm;
                }
            }
            
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
                background-color: #f5f5f5;
                padding: 30px;
            }
            
            .invoice-container {
                max-width: 1100px;
                margin: 0 auto;
                background: white;
                border-radius: 12px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.1);
                overflow: hidden;
            }
            
            .invoice-header {
                background: linear-gradient(135deg, #2c3e50 0%, #1a2632 100%);
                color: white;
                padding: 30px 40px;
                text-align: center;
            }
            
            .invoice-header h1 {
                font-size: 28px;
                margin-bottom: 8px;
            }
            
            .status-badge {
                display: inline-block;
                padding: 5px 15px;
                border-radius: 20px;
                font-size: 11px;
                font-weight: bold;
                text-transform: uppercase;
                letter-spacing: 1px;
            }
            
            .status-paid { background: #28a745; color: white; }
            .status-partial { background: #ffc107; color: #000; }
            .status-issued { background: #17a2b8; color: white; }
            .status-overdue { background: #dc3545; color: white; }
            .status-cancelled { background: #6c757d; color: white; }
            .status-draft { background: #6c757d; color: white; }
            
            .invoice-body {
                padding: 40px;
            }
            
            .info-section {
                display: flex;
                justify-content: space-between;
                margin-bottom: 30px;
            }
            
            .info-box {
                flex: 1;
            }
            
            .info-label {
                font-size: 11px;
                font-weight: bold;
                text-transform: uppercase;
                color: #6c757d;
                margin-bottom: 8px;
                letter-spacing: 0.5px;
            }
            
            .info-value {
                font-size: 14px;
                line-height: 1.5;
            }
            
            .invoice-details {
                display: flex;
                justify-content: flex-end;
                gap: 30px;
                margin-bottom: 30px;
            }
            
            .detail-item {
                text-align: right;
            }
            
            .detail-label {
                font-size: 11px;
                color: #6c757d;
                text-transform: uppercase;
            }
            
            .detail-value {
                font-size: 14px;
                font-weight: 500;
            }
            
            table {
                width: 100%;
                border-collapse: collapse;
                margin: 25px 0;
            }
            
            th {
                background: #f8f9fc;
                padding: 12px;
                text-align: left;
                border-bottom: 2px solid #e3e6f0;
                font-size: 12px;
                font-weight: bold;
                text-transform: uppercase;
                color: #4e73df;
            }
            
            td {
                padding: 12px;
                border-bottom: 1px solid #e3e6f0;
                font-size: 13px;
            }
            
            .text-right { text-align: right; }
            .text-center { text-align: center; }
            
            .summary {
                width: 100%;
                max-width: 350px;
                margin-left: auto;
                margin-top: 20px;
            }
            
            .summary-row {
                display: flex;
                justify-content: space-between;
                padding: 8px 0;
                border-bottom: 1px solid #e3e6f0;
            }
            
            .summary-row.total {
                border-top: 2px solid #e3e6f0;
                border-bottom: none;
                font-size: 18px;
                font-weight: bold;
                padding-top: 15px;
            }
            
            .payment-history {
                background: #f8f9fc;
                padding: 20px;
                border-radius: 8px;
                margin-top: 30px;
            }
            
            .payment-history h4 {
                margin-bottom: 15px;
                color: #4e73df;
            }
            
            .payment-table {
                width: 100%;
                margin: 0;
            }
            
            .payment-table th,
            .payment-table td {
                padding: 8px;
                font-size: 12px;
            }
            
            .notes {
                margin-top: 30px;
                padding: 15px;
                background: #fff9e6;
                border-left: 4px solid #ffc107;
                border-radius: 4px;
            }
            
            .footer {
                margin-top: 40px;
                padding-top: 20px;
                text-align: center;
                border-top: 1px solid #e3e6f0;
                font-size: 11px;
                color: #6c757d;
            }
            
            .btn-print {
                position: fixed;
                bottom: 30px;
                right: 30px;
                background: #4e73df;
                color: white;
                border: none;
                padding: 12px 24px;
                border-radius: 30px;
                cursor: pointer;
                font-size: 14px;
                font-weight: bold;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                transition: all 0.3s;
                z-index: 1000;
            }
            
            .btn-print:hover {
                background: #224abe;
                transform: scale(1.02);
            }
            
            .btn-close {
                bottom: 100px;
                background: #6c757d;
            }
            
            .btn-close:hover {
                background: #5a6268;
            }
            
            @media print {
                .btn-print, .btn-close {
                    display: none;
                }
                body {
                    padding: 0;
                    background: white;
                }
                .invoice-container {
                    box-shadow: none;
                    border-radius: 0;
                }
            }
        </style>
    </head>
    <body>
        <div class="invoice-container">
            <div class="invoice-header">
                <h1>INVOICE</h1>
                <div class="status-badge status-<?php echo $invoice['status']; ?>">
                    <?php echo $statusInfo['text']; ?>
                </div>
            </div>
            
            <div class="invoice-body">
                <div class="info-section">
                    <div class="info-box">
                        <div class="info-label">FROM</div>
                        <div class="info-value">
                            <strong><?php echo htmlspecialchars($company['company_name'] ?? 'Company Name'); ?></strong><br>
                            <?php echo nl2br(htmlspecialchars($company['address'] ?? '')); ?><br>
                            <?php if (!empty($company['phone'])): ?>
                                Tel: <?php echo htmlspecialchars($company['phone']); ?><br>
                            <?php endif; ?>
                            <?php if (!empty($company['email'])): ?>
                                Email: <?php echo htmlspecialchars($company['email']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="info-box">
                        <div class="info-label">BILL TO</div>
                        <div class="info-value">
                            <strong><?php echo htmlspecialchars($invoice['customer_name']); ?></strong><br>
                            <?php echo nl2br(htmlspecialchars($invoice['customer_address'] ?? '')); ?><br>
                            <?php if (!empty($invoice['customer_phone'])): ?>
                                Tel: <?php echo htmlspecialchars($invoice['customer_phone']); ?><br>
                            <?php endif; ?>
                            <?php if (!empty($invoice['customer_email'])): ?>
                                Email: <?php echo htmlspecialchars($invoice['customer_email']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="invoice-details">
                    <div class="detail-item">
                        <div class="detail-label">Invoice Number</div>
                        <div class="detail-value"><?php echo htmlspecialchars($invoice['invoice_number']); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Invoice Date</div>
                        <div class="detail-value"><?php echo date('d/m/Y', strtotime($invoice['invoice_date'])); ?></div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">Due Date</div>
                        <div class="detail-value"><?php echo date('d/m/Y', strtotime($invoice['due_date'])); ?></div>
                    </div>
                </div>
                
                <!-- Items Table -->
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Product / Item</th>
                            <th class="text-center">Qty</th>
                            <th class="text-right">Unit Price</th>
                            <th class="text-center">Disc %</th>
                            <th class="text-center">Tax %</th>
                            <th class="text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $counter = 1; ?>
                        <?php foreach ($invoice['items'] as $item): 
                            $subtotal = $item['quantity'] * $item['unit_price'];
                            $discount = $subtotal * ($item['discount_percent'] / 100);
                            $afterDiscount = $subtotal - $discount;
                            $tax = $afterDiscount * ($item['tax_rate'] / 100);
                            $lineTotal = $afterDiscount + $tax;
                        ?>
                            <tr>
                                <td><?php echo $counter++; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                                    <?php if (!empty($item['variant_name']) && $item['variant_name'] !== 'Standard'): ?>
                                        <br><small><?php echo htmlspecialchars($item['variant_name']); ?></small>
                                    <?php endif; ?>
                                    <?php if (!empty($item['sku'])): ?>
                                        <br><small>SKU: <?php echo htmlspecialchars($item['sku']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?php echo number_format($item['quantity'], 2); ?></td>
                                <td class="text-right"><?php echo $currency; ?> <?php echo number_format($item['unit_price'], 2); ?></td>
                                <td class="text-center"><?php echo number_format($item['discount_percent'], 1); ?>%</td>
                                <td class="text-center"><?php echo number_format($item['tax_rate'], 1); ?>%</td>
                                <td class="text-right"><strong><?php echo $currency; ?> <?php echo number_format($lineTotal, 2); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- Summary -->
                <div class="summary">
                    <div class="summary-row">
                        <span>Subtotal:</span>
                        <span><?php echo $currency; ?> <?php echo number_format($invoice['subtotal'], 2); ?></span>
                    </div>
                    <?php if (($invoice['discount_amount'] ?? 0) > 0): ?>
                    <div class="summary-row">
                        <span>Discount:</span>
                        <span style="color: #dc3545;">-<?php echo $currency; ?> <?php echo number_format($invoice['discount_amount'] ?? 0, 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="summary-row">
                        <span>Tax Amount:</span>
                        <span><?php echo $currency; ?> <?php echo number_format($invoice['tax_amount'], 2); ?></span>
                    </div>
                    <div class="summary-row total">
                        <span>Total Amount:</span>
                        <span style="color: #28a745;"><?php echo $currency; ?> <?php echo number_format($invoice['total_amount'], 2); ?></span>
                    </div>
                    <?php if ($invoice['amount_paid'] > 0): ?>
                    <div class="summary-row">
                        <span>Amount Paid:</span>
                        <span style="color: #28a745;"><?php echo $currency; ?> <?php echo number_format($invoice['amount_paid'], 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($balanceDue > 0): ?>
                    <div class="summary-row total">
                        <span>Balance Due:</span>
                        <span style="color: #dc3545;"><?php echo $currency; ?> <?php echo number_format($balanceDue, 2); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Payment History -->
                <?php if (!empty($invoice['payments'])): ?>
                <div class="payment-history">
                    <h4>Payment History</h4>
                    <table class="payment-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Payment Number</th>
                                <th>Method</th>
                                <th class="text-right">Amount</th>
                                <th>Reference</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoice['payments'] as $payment): ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($payment['payment_date'])); ?></td>
                                <td><?php echo htmlspecialchars($payment['payment_number']); ?></td>
                                <td><?php echo ucfirst(str_replace('_', ' ', $payment['payment_method'])); ?></td>
                                <td class="text-right"><?php echo $currency; ?> <?php echo number_format($payment['amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($payment['reference_number'] ?? '-'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                
                <!-- Notes -->
                <?php if (!empty($invoice['notes'])): ?>
                <div class="notes">
                    <strong>Notes:</strong><br>
                    <?php echo nl2br(htmlspecialchars($invoice['notes'])); ?>
                </div>
                <?php endif; ?>
                
                <div class="footer">
                    <p>Thank you for your business!</p>
                    <?php if ($balanceDue > 0): ?>
                    <p style="color: #dc3545;">Please pay the outstanding balance by <?php echo date('d/m/Y', strtotime($invoice['due_date'])); ?></p>
                    <?php endif; ?>
                    <p><small>This is a computer-generated document. No signature is required.</small></p>
                </div>
            </div>
        </div>
        
        <div class="no-print">
            <button class="btn-print" onclick="window.print();">
                <i class="fas fa-print"></i> Print / Save PDF
            </button>
            <button class="btn-print btn-close" onclick="window.close();">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
        
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        
        <script>
            <?php if ($action === 'print'): ?>
            window.onload = function() {
                setTimeout(function() {
                    window.print();
                }, 500);
            }
            <?php endif; ?>
        </script>
    </body>
    </html>
    <?php
    exit;
}

// If we get here, something went wrong
header('HTTP/1.1 400 Bad Request');
echo 'Invalid export format';
exit;