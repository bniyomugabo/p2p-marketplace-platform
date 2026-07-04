<?php
// pages/quotations/print.php
// NO WHITESPACE BEFORE <?php - this is critical!

// Turn off all error reporting to prevent any output
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering to catch any accidental output
ob_start();

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Quotation.php';
require_once __DIR__ . '/../../models/Company.php';
require_once __DIR__ . '/../exports/fpdf/fpdf.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    header('HTTP/1.1 401 Unauthorized');
    exit('Authentication required.');
}

// Get company ID from session
$companyId = $_SESSION['company_id'] ?? null;

if (!$companyId) {
    ob_end_clean();
    header('HTTP/1.1 400 Bad Request');
    exit('Company context not found.');
}

// Get quotation ID from URL
$quotationId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$quotationId) {
    ob_end_clean();
    die('Quotation ID is required.');
}

// Get quotation data with company check
try {
    $quotationModel = new Quotation($companyId);
    $quotation = $quotationModel->getWithItems($quotationId);
} catch (Exception $e) {
    ob_end_clean();
    die('Error loading quotation: ' . $e->getMessage());
}

if (!$quotation) {
    ob_end_clean();
    die('Quotation not found or does not belong to your company.');
}

// Get company information
$companyModel = new Company();
$companyInfo = $companyModel->find($companyId);

if (!$companyInfo) {
    // Fallback to session data
    $companyInfo = [
        'company_name' => $_SESSION['company_name'] ?? 'SATI ERP',
        'address' => $_SESSION['company_address'] ?? 'Kigali, Rwanda',
        'phone' => $_SESSION['company_phone'] ?? '+250 788 123 456',
        'email' => $_SESSION['company_email'] ?? 'info@sati.com',
        'website' => $_SESSION['company_website'] ?? 'www.sati.com',
        'tax_id' => $_SESSION['company_tax_id'] ?? $_SESSION['company_vat_number'] ?? '123456789',
        'logo_url' => $_SESSION['company_logo'] ?? null,
        'currency' => $_SESSION['company_currency'] ?? 'RWF'
    ];
}

// Build full address
$fullAddress = $companyInfo['address'] ?? '';
if (!empty($companyInfo['city'])) {
    $fullAddress .= ($fullAddress ? ', ' : '') . $companyInfo['city'];
}
if (!empty($companyInfo['country'])) {
    $fullAddress .= ($fullAddress ? ', ' : '') . $companyInfo['country'];
}

// Check if logo exists
$logoPath = null;
if (!empty($companyInfo['logo_url'])) {
    $possiblePaths = [
        __DIR__ . '/../../' . $companyInfo['logo_url'],
        __DIR__ . '/../..' . $companyInfo['logo_url'],
        $_SERVER['DOCUMENT_ROOT'] . '/' . $companyInfo['logo_url']
    ];
    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            $logoPath = $path;
            break;
        }
    }
}

// Clean any output buffers before generating PDF
ob_end_clean();

// Company information for PDF
$company = [
    'name' => $companyInfo['company_name'] ?? 'SATI ERP',
    'address' => $fullAddress ?: 'Kigali, Rwanda',
    'phone' => $companyInfo['phone'] ?? '+250 788 123 456',
    'email' => $companyInfo['email'] ?? 'info@sati.com',
    'website' => $companyInfo['website'] ?? 'www.sati.com',
    'tax_id' => $companyInfo['tax_id'] ?? '123456789',
    'currency' => $companyInfo['currency'] ?? 'RWF',
    'logo' => $logoPath
];

// Create PDF class
class PDF extends FPDF
{
    protected $company;
    protected $quotation;
    protected $logoPath;

    function __construct($company, $quotation, $logoPath = null)
    {
        parent::__construct('P', 'mm', 'A4');
        $this->company = $company;
        $this->quotation = $quotation;
        $this->logoPath = $logoPath;
    }

    function Header()
    {
        // Logo
        if ($this->logoPath && file_exists($this->logoPath)) {
            $this->Image($this->logoPath, 10, 10, 30);
        } else {
            // Draw a placeholder rectangle for logo area
            $this->SetDrawColor(200, 200, 200);
            $this->Rect(10, 10, 30, 30);
            $this->SetFont('Arial', 'I', 8);
            $this->SetTextColor(150, 150, 150);
            $this->SetXY(12, 22);
            $this->Cell(26, 16, 'LOGO', 0, 0, 'C');
        }

        // Company Info
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(41, 128, 185);
        $this->Cell(0, 10, $this->company['name'], 0, 1, 'R');

        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(100, 100, 100);

        if (!empty($this->company['address'])) {
            $this->Cell(0, 5, $this->company['address'], 0, 1, 'R');
        }

        $contactLine = '';
        if (!empty($this->company['phone'])) {
            $contactLine .= 'Tel: ' . $this->company['phone'];
        }
        if (!empty($this->company['email'])) {
            $contactLine .= ($contactLine ? ' | ' : '') . 'Email: ' . $this->company['email'];
        }
        if ($contactLine) {
            $this->Cell(0, 5, $contactLine, 0, 1, 'R');
        }

        if (!empty($this->company['website'])) {
            $this->Cell(0, 5, 'Web: ' . $this->company['website'], 0, 1, 'R');
        }

        if (!empty($this->company['tax_id'])) {
            $this->Cell(0, 5, 'Tax ID: ' . $this->company['tax_id'], 0, 1, 'R');
        }

        // Line separator
        $this->Ln(5);
        $this->SetDrawColor(200, 200, 200);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(10);

        // Quotation Title
        $this->SetFont('Arial', 'B', 24);
        $this->SetTextColor(41, 128, 185);
        $this->Cell(0, 15, 'QUOTATION', 0, 1, 'C');

        // Quotation Number and Date
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 8, 'Quotation #: ' . $this->quotation['quotation_number'], 0, 1, 'C');

        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 6, 'Date: ' . date('d/m/Y', strtotime($this->quotation['quotation_date'])), 0, 1, 'C');
        $this->Cell(0, 6, 'Valid Until: ' . date('d/m/Y', strtotime($this->quotation['valid_until'])), 0, 1, 'C');
        $this->Ln(10);

        // Status Badge
        if ($this->quotation['status'] !== 'draft') {
            $statusColors = [
                'sent' => [52, 152, 219],
                'accepted' => [46, 204, 113],
                'rejected' => [231, 76, 60],
                'expired' => [241, 196, 15]
            ];

            $color = $statusColors[$this->quotation['status']] ?? [100, 100, 100];
            $this->SetFillColor($color[0], $color[1], $color[2]);
            $this->SetTextColor(255, 255, 255);
            $this->SetFont('Arial', 'B', 12);
            $this->Cell(0, 10, 'STATUS: ' . strtoupper($this->quotation['status']), 0, 1, 'C', true);
            $this->Ln(5);
        }

        $this->SetTextColor(0, 0, 0);
    }

    function Footer()
    {
        $this->SetY(-30);

        // Line separator
        $this->SetDrawColor(200, 200, 200);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(5);

        // Terms and conditions if available
        if (!empty($this->quotation['terms_conditions'])) {
            $this->SetFont('Arial', 'B', 9);
            $this->SetTextColor(100, 100, 100);
            $this->Cell(0, 5, 'Terms & Conditions:', 0, 1);
            $this->SetFont('Arial', '', 8);
            $this->MultiCell(0, 4, $this->quotation['terms_conditions']);
            $this->Ln(2);
        }

        // Footer text
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 5, 'Generated on: ' . date('d/m/Y H:i:s'), 0, 0, 'L');
        $this->Cell(0, 5, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }

    function CustomerInfo()
    {
        // Draw box around customer info
        $this->SetFillColor(248, 249, 252);
        $this->SetDrawColor(200, 200, 200);
        $this->Rect(10, $this->GetY(), 190, 40, 'D');
        $this->SetY($this->GetY() + 2);

        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(41, 128, 185);
        $this->Cell(0, 8, 'Bill To:', 0, 1, 'L');

        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 6, $this->quotation['customer_name'], 0, 1);

        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(80, 80, 80);

        if (!empty($this->quotation['customer_phone'])) {
            $this->Cell(0, 5, 'Phone: ' . $this->quotation['customer_phone'], 0, 1);
        }
        if (!empty($this->quotation['customer_email'])) {
            $this->Cell(0, 5, 'Email: ' . $this->quotation['customer_email'], 0, 1);
        }
        $this->Ln(8);
    }

    function ItemsTable()
    {
        // Table header
        $this->SetFillColor(41, 128, 185);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 9);

        $col1 = 70; // Product
        $col2 = 20; // Qty
        $col3 = 25; // Unit Price
        $col4 = 15; // Disc %
        $col5 = 15; // Tax %
        $col6 = 35; // Total

        $this->Cell($col1, 10, 'Product', 1, 0, 'C', true);
        $this->Cell($col2, 10, 'Qty', 1, 0, 'C', true);
        $this->Cell($col3, 10, 'Unit Price', 1, 0, 'C', true);
        $this->Cell($col4, 10, 'Disc %', 1, 0, 'C', true);
        $this->Cell($col5, 10, 'Tax %', 1, 0, 'C', true);
        $this->Cell($col6, 10, 'Total', 1, 1, 'C', true);

        // Table rows
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', '', 9);
        $currency = $this->company['currency'];

        foreach ($this->quotation['items'] as $item) {
            $subtotal = (float) $item['quantity'] * (float) $item['unit_price'];
            $discountAmount = $subtotal * ((float) $item['discount_percent'] / 100);
            $afterDiscount = $subtotal - $discountAmount;
            $taxAmount = $afterDiscount * ((float) $item['tax_rate'] / 100);
            $lineTotal = $afterDiscount + $taxAmount;

            $productText = $item['product_name'];

            // Check page break
            if ($this->GetY() > 250) {
                $this->AddPage();
                $this->SetFillColor(41, 128, 185);
                $this->SetTextColor(255, 255, 255);
                $this->SetFont('Arial', 'B', 9);
                $this->Cell($col1, 10, 'Product', 1, 0, 'C', true);
                $this->Cell($col2, 10, 'Qty', 1, 0, 'C', true);
                $this->Cell($col3, 10, 'Unit Price', 1, 0, 'C', true);
                $this->Cell($col4, 10, 'Disc %', 1, 0, 'C', true);
                $this->Cell($col5, 10, 'Tax %', 1, 0, 'C', true);
                $this->Cell($col6, 10, 'Total', 1, 1, 'C', true);
                $this->SetTextColor(0, 0, 0);
                $this->SetFont('Arial', '', 9);
            }

            $this->SetFont('Arial', 'B', 9);
            $lines = ceil($this->GetStringWidth($productText) / ($col1 - 4));
            $height = max(8, $lines * 5);
            $x = $this->GetX();
            $y = $this->GetY();

            $this->MultiCell($col1, 5, $productText, 1, 'L');
            $newY = $this->GetY();
            $this->SetXY($x + $col1, $y);

            $this->Cell($col2, $newY - $y, number_format($item['quantity'], 2), 1, 0, 'C');
            $this->Cell($col3, $newY - $y, number_format($item['unit_price'], 0) . ' ' . $currency, 1, 0, 'R');
            $this->Cell($col4, $newY - $y, ($item['discount_percent'] > 0 ? $item['discount_percent'] . '%' : '-'), 1, 0, 'C');
            $this->Cell($col5, $newY - $y, ($item['tax_rate'] > 0 ? $item['tax_rate'] . '%' : '-'), 1, 0, 'C');
            $this->Cell($col6, $newY - $y, number_format($lineTotal, 0) . ' ' . $currency, 1, 1, 'R');

            if (!empty($item['description'])) {
                $this->SetX($x);
                $this->SetFont('Arial', 'I', 8);
                $this->SetTextColor(100, 100, 100);
                $this->MultiCell($col1 + $col2 + $col3 + $col4 + $col5 + $col6, 4, 'Note: ' . $item['description'], 0, 'L');
                $this->SetTextColor(0, 0, 0);
                $this->SetFont('Arial', '', 9);
            }
        }

        // Totals
        $this->Ln(5);

        $subtotal = (float) $this->quotation['subtotal'];
        $discount = (float) $this->quotation['discount_amount'];
        $tax = (float) $this->quotation['tax_amount'];
        $total = (float) $this->quotation['total_amount'];
        $currency = $this->company['currency'];

        $rightMargin = 20;
        $labelWidth = 40;
        $valueWidth = 45;
        $totalWidth = $labelWidth + $valueWidth;
        $startX = 210 - $totalWidth - $rightMargin;

        $this->SetFont('Arial', 'B', 10);
        $this->SetXY($startX, $this->GetY());
        $this->Cell($labelWidth, 7, 'Subtotal:', 0, 0, 'R');
        $this->Cell($valueWidth, 7, number_format($subtotal, 0) . ' ' . $currency, 0, 1, 'R');

        if ($discount > 0) {
            $this->SetX($startX);
            $this->SetTextColor(231, 76, 60);
            $this->Cell($labelWidth, 7, 'Discount:', 0, 0, 'R');
            $this->Cell($valueWidth, 7, '- ' . number_format($discount, 0) . ' ' . $currency, 0, 1, 'R');
            $this->SetTextColor(0, 0, 0);
        }

        if ($tax > 0) {
            $this->SetX($startX);
            $this->SetTextColor(41, 128, 185);
            $this->Cell($labelWidth, 7, 'Tax:', 0, 0, 'R');
            $this->Cell($valueWidth, 7, number_format($tax, 0) . ' ' . $currency, 0, 1, 'R');
            $this->SetTextColor(0, 0, 0);
        }

        $this->SetX($startX);
        $this->SetDrawColor(200, 200, 200);
        $this->Line($startX, $this->GetY(), $startX + $totalWidth, $this->GetY());
        $this->Ln(1);

        $this->SetX($startX);
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(41, 128, 185);
        $this->Cell($labelWidth, 10, 'TOTAL:', 0, 0, 'R');
        $this->Cell($valueWidth, 10, number_format($total, 0) . ' ' . $currency, 0, 1, 'R');

        $this->SetTextColor(0, 0, 0);
    }

    function Notes()
    {
        if (!empty($this->quotation['notes'])) {
            $this->Ln(5);
            $this->SetFont('Arial', 'B', 10);
            $this->SetTextColor(100, 100, 100);
            $this->Cell(0, 6, 'Internal Notes:', 0, 1);
            $this->SetFont('Arial', '', 9);
            $this->MultiCell(0, 5, $this->quotation['notes']);
        }
    }
}

// Create PDF
$pdf = new PDF($company, $quotation, $logoPath);
$pdf->AliasNbPages();
$pdf->AddPage();

// Add customer info
$pdf->CustomerInfo();

// Add items table
$pdf->ItemsTable();

// Add notes
$pdf->Notes();

// Make sure no output has been sent
if (ob_get_length()) {
    ob_clean();
}

// Output PDF
$filename = 'Quotation_' . $quotation['quotation_number'] . '_' . date('Y-m-d') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

$pdf->Output('D', $filename);
exit;