<?php
// pages/sales/print-invoice.php
// NO WHITESPACE BEFORE <?php - this is critical!

// Turn off all error reporting to prevent any output
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering to catch any accidental output
ob_start();

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Sale.php';
require_once __DIR__ . '/../../models/Company.php';
require_once __DIR__ . '/../exports/fpdf/fpdf.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    header('HTTP/1.1 401 Unauthorized');
    exit;
}

$companyId = $_SESSION['company_id'] ?? null;
$userId = $_SESSION['user_id'] ?? 0;

// Get invoice ID from URL
$invoiceId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$invoiceId) {
    ob_end_clean();
    die('Invoice ID is required.');
}

// Initialize models with company context
$saleModel = new Sale($companyId);
$companyModel = new Company();

// Get invoice data
try {
    $invoice = $saleModel->getInvoiceWithDetails($invoiceId);
} catch (Exception $e) {
    ob_end_clean();
    die('Error loading invoice: ' . $e->getMessage());
}

if (!$invoice) {
    ob_end_clean();
    die('Invoice not found or not accessible for your company.');
}

// Get company information from database
$companyData = $companyModel->find($companyId);
if (!$companyData) {
    // Fallback to session/default data
    $companyData = [
        'company_name' => $_SESSION['company_name'] ?? 'SATI ERP',
        'address' => $_SESSION['company_address'] ?? 'Kigali, Rwanda',
        'phone' => $_SESSION['company_phone'] ?? '+250 788 123 456',
        'email' => $_SESSION['company_email'] ?? 'info@sati.com',
        'website' => $_SESSION['company_website'] ?? 'www.sati.com',
        'tax_id' => $_SESSION['company_tax_id'] ?? '123456789',
        'registration_number' => $_SESSION['company_registration_number'] ?? '',
        'logo_url' => $_SESSION['company_logo'] ?? null,
        'bank_name' => $_SESSION['company_bank_name'] ?? 'Bank of Kigali',
        'bank_account' => $_SESSION['company_bank_account'] ?? '1234567890',
        'bank_swift' => $_SESSION['company_bank_swift'] ?? 'BKIGRWRW'
    ];
}

// Calculate balance
$balance = $invoice['total_amount'] - $invoice['amount_paid'];

// Get currency from session or company data
$currency = $_SESSION['company_currency'] ?? 'RWF';

// Clean any output buffers before generating PDF
ob_end_clean();

// Company information for PDF
$company = [
    'name' => $companyData['company_name'] ?? 'SATI ERP',
    'address' => $companyData['address'] ?? $companyData['billing_address'] ?? 'Kigali, Rwanda',
    'phone' => $companyData['phone'] ?? '+250 788 123 456',
    'email' => $companyData['email'] ?? 'info@sati.com',
    'website' => $companyData['website'] ?? 'www.sati.com',
    'tax_id' => $companyData['tax_id'] ?? $companyData['vat_number'] ?? '123456789',
    'registration_number' => $companyData['registration_number'] ?? '',
    'logo' => !empty($companyData['logo_url']) ? __DIR__ . '/../..' . $companyData['logo_url'] : __DIR__ . '/../../assets/img/logo.png',
    'bank_name' => $companyData['bank_name'] ?? 'Bank of Kigali',
    'bank_account' => $companyData['bank_account'] ?? '1234567890',
    'bank_swift' => $companyData['bank_swift'] ?? 'BKIGRWRW'
];

// Create PDF class
class InvoicePDF extends FPDF
{
    protected $company;
    protected $invoice;
    protected $balance;
    protected $currency;

    function __construct($company, $invoice, $balance, $currency)
    {
        parent::__construct('P', 'mm', 'A4');
        $this->company = $company;
        $this->invoice = $invoice;
        $this->balance = $balance;
        $this->currency = $currency;
    }

    function Header()
    {
        // Logo (if exists)
        if (file_exists($this->company['logo'])) {
            $this->Image($this->company['logo'], 10, 10, 30);
        } else {
            // Try alternative logo path
            $altLogo = __DIR__ . '/../../assets/img/logo.png';
            if (file_exists($altLogo)) {
                $this->Image($altLogo, 10, 10, 30);
            }
        }

        // Company Info
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(41, 128, 185);
        $this->Cell(0, 10, $this->company['name'], 0, 1, 'R');

        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 4, $this->company['address'], 0, 1, 'R');

        $contactLine = 'Tel: ' . $this->company['phone'];
        if (!empty($this->company['email'])) {
            $contactLine .= ' | Email: ' . $this->company['email'];
        }
        $this->Cell(0, 4, $contactLine, 0, 1, 'R');

        if (!empty($this->company['website'])) {
            $this->Cell(0, 4, 'Web: ' . $this->company['website'], 0, 1, 'R');
        }

        $taxLine = 'Tax ID: ' . $this->company['tax_id'];
        if (!empty($this->company['registration_number'])) {
            $taxLine .= ' | Reg No: ' . $this->company['registration_number'];
        }
        $this->Cell(0, 4, $taxLine, 0, 1, 'R');

        // Line separator
        $this->Ln(3);
        $this->SetDrawColor(200, 200, 200);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(8);

        // Invoice Title
        $this->SetFont('Arial', 'B', 22);
        $this->SetTextColor(41, 128, 185);
        $this->Cell(0, 12, 'TAX INVOICE', 0, 1, 'C');

        // Invoice Number and Date
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 7, 'Invoice #: ' . $this->invoice['invoice_number'], 0, 1, 'C');

        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, 'Invoice Date: ' . date('d/m/Y', strtotime($this->invoice['invoice_date'])), 0, 1, 'C');
        $this->Cell(0, 5, 'Due Date: ' . date('d/m/Y', strtotime($this->invoice['due_date'])), 0, 1, 'C');
        $this->Ln(8);

        // Status Banner
        $statusColors = [
            'draft' => [158, 158, 158],
            'issued' => [52, 152, 219],
            'paid' => [46, 204, 113],
            'partial' => [241, 196, 15],
            'overdue' => [231, 76, 60],
            'cancelled' => [108, 117, 125]
        ];

        $color = $statusColors[$this->invoice['status']] ?? [100, 100, 100];
        $this->SetFillColor($color[0], $color[1], $color[2]);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(0, 8, 'STATUS: ' . strtoupper($this->invoice['status']), 0, 1, 'C', true);
        $this->Ln(5);
    }

    function Footer()
    {
        $this->SetY(-40);

        // Line separator
        $this->SetDrawColor(200, 200, 200);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(3);

        // Bank Information
        if (!empty($this->company['bank_name'])) {
            $this->SetFont('Arial', '', 8);
            $this->SetTextColor(100, 100, 100);
            $bankLine = 'Bank: ' . $this->company['bank_name'];
            if (!empty($this->company['bank_account'])) {
                $bankLine .= ' | Account: ' . $this->company['bank_account'];
            }
            if (!empty($this->company['bank_swift'])) {
                $bankLine .= ' | Swift: ' . $this->company['bank_swift'];
            }
            $this->Cell(0, 4, $bankLine, 0, 1, 'C');
        }

        // Footer text
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 5, 'This is a computer generated invoice. No signature required.', 0, 0, 'L');
        $this->Cell(0, 5, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }

    function CustomerInfo()
    {
        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(41, 128, 185);
        $this->Cell(0, 8, 'Bill To:', 0, 1);

        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 5, $this->invoice['customer_name'], 0, 1);

        $this->SetFont('Arial', '', 9);
        if (!empty($this->invoice['customer_phone'])) {
            $this->Cell(0, 4, 'Phone: ' . $this->invoice['customer_phone'], 0, 1);
        }
        if (!empty($this->invoice['customer_email'])) {
            $this->Cell(0, 4, 'Email: ' . $this->invoice['customer_email'], 0, 1);
        }
        if (!empty($this->invoice['customer_address'])) {
            $this->MultiCell(0, 4, 'Address: ' . $this->invoice['customer_address']);
        }
        $this->Ln(6);
    }

    function ItemsTable()
    {
        // Define column widths
        $col1 = 65; // Product
        $col2 = 15; // Qty
        $col3 = 30; // Unit Price
        $col4 = 15; // Disc %
        $col5 = 15; // Tax %
        $col6 = 40; // Line Total

        // Table header
        $this->SetFillColor(41, 128, 185);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 9);

        $this->Cell($col1, 9, 'Product', 1, 0, 'C', true);
        $this->Cell($col2, 9, 'Qty', 1, 0, 'C', true);
        $this->Cell($col3, 9, 'Unit Price', 1, 0, 'C', true);
        $this->Cell($col4, 9, 'Disc %', 1, 0, 'C', true);
        $this->Cell($col5, 9, 'Tax %', 1, 0, 'C', true);
        $this->Cell($col6, 9, 'Line Total', 1, 1, 'C', true);

        // Table rows
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', '', 9);

        foreach ($this->invoice['items'] as $item) {
            // Calculate line totals
            $subtotal = $item['quantity'] * $item['unit_price'];
            $discountAmount = $subtotal * ($item['discount_percent'] / 100);
            $afterDiscount = $subtotal - $discountAmount;
            $taxAmount = $afterDiscount * ($item['tax_rate'] / 100);
            $lineTotal = $afterDiscount + $taxAmount;

            // Product name with variant
            $productText = $item['product_name'];
            if (!empty($item['variant_name']) && $item['variant_name'] !== 'Standard') {
                $productText .= ' - ' . $item['variant_name'];
            }

            // Check if we need a new page
            if ($this->GetY() > 250) {
                $this->AddPage();
                // Reprint header
                $this->SetFillColor(41, 128, 185);
                $this->SetTextColor(255, 255, 255);
                $this->SetFont('Arial', 'B', 9);
                $this->Cell($col1, 9, 'Product', 1, 0, 'C', true);
                $this->Cell($col2, 9, 'Qty', 1, 0, 'C', true);
                $this->Cell($col3, 9, 'Unit Price', 1, 0, 'C', true);
                $this->Cell($col4, 9, 'Disc %', 1, 0, 'C', true);
                $this->Cell($col5, 9, 'Tax %', 1, 0, 'C', true);
                $this->Cell($col6, 9, 'Line Total', 1, 1, 'C', true);
                $this->SetTextColor(0, 0, 0);
                $this->SetFont('Arial', '', 9);
            }

            // Store current position
            $x = $this->GetX();
            $y = $this->GetY();

            // Calculate height needed for product name
            $lines = ceil($this->GetStringWidth($productText) / ($col1 - 4));
            $height = max(6, $lines * 4.5);

            // Product cell (with MultiCell for wrapping)
            $this->SetFont('Arial', '', 9);
            $this->MultiCell($col1, 4.5, $productText, 1, 'L');

            // Get new Y position after MultiCell
            $newY = $this->GetY();

            // Calculate row height
            $rowHeight = $newY - $y;

            // Set position for next cells
            $this->SetXY($x + $col1, $y);

            // Other cells with same height
            $this->Cell($col2, $rowHeight, number_format($item['quantity'], 0), 1, 0, 'C');
            $this->Cell($col3, $rowHeight, number_format($item['unit_price'], 0), 1, 0, 'R');
            $this->Cell($col4, $rowHeight, $item['discount_percent'] . '%', 1, 0, 'C');
            $this->Cell($col5, $rowHeight, $item['tax_rate'] . '%', 1, 0, 'C');
            $this->Cell($col6, $rowHeight, number_format($lineTotal, 0), 1, 1, 'R');
        }

        // Totals
        $this->Ln(6);

        // Right-align totals
        $rightMargin = 20;
        $labelWidth = 50;
        $valueWidth = 45;
        $totalWidth = $labelWidth + $valueWidth;
        $startX = 210 - $totalWidth - $rightMargin;

        $this->SetFont('Arial', 'B', 10);

        // Subtotal
        $this->SetXY($startX, $this->GetY());
        $this->Cell($labelWidth, 7, 'Subtotal:', 0, 0, 'R');
        $this->Cell($valueWidth, 7, number_format($this->invoice['subtotal'], 0), 0, 1, 'R');

        // Discount
        if ($this->invoice['discount_amount'] > 0) {
            $this->SetX($startX);
            $this->SetTextColor(231, 76, 60);
            $this->Cell($labelWidth, 7, 'Discount:', 0, 0, 'R');
            $this->Cell($valueWidth, 7, '- ' . number_format($this->invoice['discount_amount'], 0), 0, 1, 'R');
            $this->SetTextColor(0, 0, 0);
        }

        // Tax
        if ($this->invoice['tax_amount'] > 0) {
            $this->SetX($startX);
            $this->SetTextColor(41, 128, 185);
            $this->Cell($labelWidth, 7, 'Tax:', 0, 0, 'R');
            $this->Cell($valueWidth, 7, number_format($this->invoice['tax_amount'], 0), 0, 1, 'R');
            $this->SetTextColor(0, 0, 0);
        }

        // Line separator
        $this->SetX($startX);
        $this->SetDrawColor(200, 200, 200);
        $this->Line($startX, $this->GetY(), $startX + $totalWidth, $this->GetY());
        $this->Ln(1);

        // Grand Total
        $this->SetX($startX);
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(41, 128, 185);
        $this->Cell($labelWidth, 9, 'TOTAL:', 0, 0, 'R');
        $this->Cell($valueWidth, 9, number_format($this->invoice['total_amount'], 0), 0, 1, 'R');

        // Amount Paid and Balance
        $this->SetFont('Arial', '', 10);
        $this->SetX($startX);
        $this->Cell($labelWidth, 6, 'Amount Paid:', 0, 0, 'R');
        $this->Cell($valueWidth, 6, number_format($this->invoice['amount_paid'], 0), 0, 1, 'R');

        $this->SetX($startX);
        $this->SetFont('Arial', 'B', 11);
        $balanceColor = $this->balance > 0 ? [231, 76, 60] : [46, 204, 113];
        $this->SetTextColor($balanceColor[0], $balanceColor[1], $balanceColor[2]);
        $this->Cell($labelWidth, 7, 'Balance Due:', 0, 0, 'R');
        $this->Cell($valueWidth, 7, number_format($this->balance, 0), 0, 1, 'R');
        $this->SetTextColor(0, 0, 0);

        // Add currency note
        $this->SetFont('Arial', 'I', 8);
        $this->SetX($startX);
        $this->Cell($totalWidth, 4, 'All amounts are in ' . $this->currency, 0, 1, 'R');
    }

    function PaymentHistory()
    {
        if (!empty($this->invoice['payments'])) {
            $this->AddPage();

            $this->SetFont('Arial', 'B', 14);
            $this->SetTextColor(41, 128, 185);
            $this->Cell(0, 10, 'Payment History', 0, 1, 'C');
            $this->Ln(5);

            // Payment table header
            $this->SetFillColor(41, 128, 185);
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Arial', 'B', 10);

            $col1 = 35; // Date
            $col2 = 45; // Payment #
            $col3 = 35; // Method
            $col4 = 35; // Amount
            $col5 = 35; // Reference

            $this->Cell($col1, 8, 'Date', 1, 0, 'C', true);
            $this->Cell($col2, 8, 'Payment #', 1, 0, 'C', true);
            $this->Cell($col3, 8, 'Method', 1, 0, 'C', true);
            $this->Cell($col4, 8, 'Amount', 1, 0, 'C', true);
            $this->Cell($col5, 8, 'Reference', 1, 1, 'C', true);

            $this->SetTextColor(0, 0, 0);
            $this->SetFont('Arial', '', 9);

            $methodLabels = [
                'cash' => 'Cash',
                'bank_transfer' => 'Bank Transfer',
                'mobile' => 'Mobile Money',
                'card' => 'Card',
                'cheque' => 'Cheque'
            ];

            foreach ($this->invoice['payments'] as $payment) {
                $this->Cell($col1, 7, date('d/m/Y', strtotime($payment['payment_date'])), 1);
                $this->Cell($col2, 7, $payment['payment_number'], 1);
                $method = $methodLabels[$payment['payment_method']] ?? ucfirst($payment['payment_method']);
                $this->Cell($col3, 7, $method, 1);
                $this->Cell($col4, 7, number_format($payment['amount'], 0), 1, 0, 'R');
                $this->Cell($col5, 7, $payment['reference_number'] ?? '-', 1, 1);
            }

            // Total paid
            $this->SetFont('Arial', 'B', 10);
            $this->Cell($col1 + $col2 + $col3, 7, 'TOTAL PAID:', 1, 0, 'R');
            $this->Cell($col4, 7, number_format($this->invoice['amount_paid'], 0), 1, 0, 'R');
            $this->Cell($col5, 7, '', 1, 1);
        }
    }

    function Notes()
    {
        if (!empty($this->invoice['notes'])) {
            $this->Ln(5);
            $this->SetFont('Arial', 'B', 11);
            $this->SetTextColor(41, 128, 185);
            $this->Cell(0, 6, 'Notes:', 0, 1);
            $this->SetFont('Arial', '', 9);
            $this->MultiCell(0, 5, $this->invoice['notes']);
        }

        // Add payment instructions if balance > 0
        if ($this->balance > 0 && !empty($this->company['bank_name'])) {
            $this->Ln(3);
            $this->SetFont('Arial', 'B', 10);
            $this->SetTextColor(41, 128, 185);
            $this->Cell(0, 6, 'Payment Instructions:', 0, 1);
            $this->SetFont('Arial', '', 9);
            $this->SetTextColor(0, 0, 0);
            $this->MultiCell(0, 5, 'Please transfer the balance due to the bank account listed at the bottom of this invoice. Use the invoice number as reference.');
        }
    }
}

// Create PDF
$pdf = new InvoicePDF($company, $invoice, $balance, $currency);
$pdf->AliasNbPages();
$pdf->AddPage();

// Add customer info
$pdf->CustomerInfo();

// Add items table
$pdf->ItemsTable();

// Add payment history if any
$pdf->PaymentHistory();

// Add notes
$pdf->Notes();

// Make sure no output has been sent
if (ob_get_length()) {
    ob_clean();
}

// Output PDF
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="Invoice_' . $invoice['invoice_number'] . '_' . date('Y-m-d') . '.pdf"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

$pdf->Output('I', 'Invoice_' . $invoice['invoice_number'] . '_' . date('Y-m-d') . '.pdf');
exit;