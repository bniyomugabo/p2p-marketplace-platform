<?php
// pages/purchasing/print-po.php
declare(strict_types=1);

// Turn off error reporting for PDF generation
error_reporting(0);
ini_set('display_errors', 0);

// Start output buffering
ob_start();

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/PurchaseOrder.php';
require_once __DIR__ . '/../../models/Company.php';
require_once __DIR__ . '/../../libs/fpdf/fpdf.php';

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

// Get order ID from URL
$orderId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$orderId) {
    ob_end_clean();
    header('HTTP/1.1 400 Bad Request');
    exit('Order ID is required.');
}

// Initialize models
$purchaseOrderModel = new PurchaseOrder($companyId);
$companyModel = new Company();

// Get order data (company check is done inside the model)
$order = $purchaseOrderModel->getWithItems($orderId);

if (!$order) {
    ob_end_clean();
    header('HTTP/1.1 404 Not Found');
    exit('Purchase order not found or does not belong to your company.');
}

// Get company details for the PDF header
$companyInfo = $companyModel->getById($companyId);

if (!$companyInfo) {
    ob_end_clean();
    header('HTTP/1.1 404 Not Found');
    exit('Company information not found.');
}

// Calculate progress
$totalOrdered = 0;
$totalReceived = 0;
foreach ($order['items'] as $item) {
    $totalOrdered += (float) $item['quantity'];
    $totalReceived += (float) $item['received_quantity'];
}

// Clean output buffer
ob_end_clean();

// Company information for PDF
$company = [
    'name' => $companyInfo['company_name'] ?? 'SATI ERP',
    'address' => $companyInfo['address'] ?? '',
    'city' => $companyInfo['city'] ?? '',
    'country' => $companyInfo['country'] ?? 'Rwanda',
    'phone' => $companyInfo['phone'] ?? '',
    'email' => $companyInfo['email'] ?? '',
    'billing_email' => $companyInfo['billing_email'] ?? '',
    'website' => $companyInfo['website'] ?? '',
    'tax_id' => $companyInfo['tax_id'] ?? $companyInfo['vat_number'] ?? '',
    'registration_number' => $companyInfo['registration_number'] ?? '',
    'logo_url' => $companyInfo['logo_url'] ?? null,
    'invoice_prefix' => $companyInfo['invoice_prefix'] ?? 'INV',
    'quote_prefix' => $companyInfo['quote_prefix'] ?? 'QUO',
    'po_prefix' => $companyInfo['po_prefix'] ?? 'PO',
    'currency' => $companyInfo['currency'] ?? 'RWF'
];

// Build full address
$fullAddress = $company['address'];
if ($company['city']) {
    $fullAddress .= ($fullAddress ? ', ' : '') . $company['city'];
}
if ($company['country']) {
    $fullAddress .= ($fullAddress ? ', ' : '') . $company['country'];
}
$company['full_address'] = $fullAddress;

// Check if logo exists
$logoPath = null;
if (!empty($company['logo_url'])) {
    $possiblePaths = [
        __DIR__ . '/../../' . $company['logo_url'],
        __DIR__ . '/../..' . $company['logo_url'],
        $_SERVER['DOCUMENT_ROOT'] . '/' . $company['logo_url']
    ];
    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            $logoPath = $path;
            break;
        }
    }
}

// Create PDF class
class PurchaseOrderPDF extends FPDF
{
    protected $company;
    protected $order;
    protected $companyId;
    protected $logoPath;

    function __construct($company, $order, $companyId, $logoPath = null)
    {
        parent::__construct('P', 'mm', 'A4');
        $this->company = $company;
        $this->order = $order;
        $this->companyId = $companyId;
        $this->logoPath = $logoPath;
    }

    function Header()
    {
        // Logo
        if ($this->logoPath && file_exists($this->logoPath)) {
            $this->Image($this->logoPath, 10, 10, 30);
        } else {
            // Draw a placeholder rectangle
            $this->SetDrawColor(200, 200, 200);
            $this->Rect(10, 10, 30, 30);
            $this->SetFont('Arial', 'I', 8);
            $this->SetTextColor(150, 150, 150);
            $this->SetXY(12, 22);
            $this->Cell(26, 16, 'LOGO', 0, 0, 'C');
        }

        // Company Info (Right aligned)
        $this->SetFont('Arial', 'B', 16);
        $this->SetTextColor(41, 128, 185);
        $this->Cell(0, 10, $this->company['name'], 0, 1, 'R');

        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(100, 100, 100);

        if (!empty($this->company['full_address'])) {
            $this->Cell(0, 5, $this->company['full_address'], 0, 1, 'R');
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

        if (!empty($this->company['tax_id'])) {
            $this->Cell(0, 5, 'Tax ID: ' . $this->company['tax_id'], 0, 1, 'R');
        }

        if (!empty($this->company['registration_number'])) {
            $this->Cell(0, 5, 'Reg No: ' . $this->company['registration_number'], 0, 1, 'R');
        }

        if (!empty($this->company['website'])) {
            $this->Cell(0, 5, 'Web: ' . $this->company['website'], 0, 1, 'R');
        }

        // Line separator
        $this->Ln(5);
        $this->SetDrawColor(200, 200, 200);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(10);

        // PO Title
        $this->SetFont('Arial', 'B', 24);
        $this->SetTextColor(41, 128, 185);
        $this->Cell(0, 15, 'PURCHASE ORDER', 0, 1, 'C');

        // PO Number
        $this->SetFont('Arial', 'B', 12);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 8, 'PO #: ' . $this->order['po_number'], 0, 1, 'C');

        // Dates
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 6, 'Order Date: ' . date('d/m/Y', strtotime($this->order['order_date'])), 0, 1, 'C');
        if ($this->order['expected_date']) {
            $this->Cell(0, 6, 'Expected Date: ' . date('d/m/Y', strtotime($this->order['expected_date'])), 0, 1, 'C');
        }
        $this->Ln(10);

        // Status Banner
        $statusColors = [
            'draft' => [158, 158, 158],
            'pending' => [52, 152, 219],
            'approved' => [41, 128, 185],
            'received' => [46, 204, 113],
            'partial' => [241, 196, 15],
            'cancelled' => [231, 76, 60]
        ];

        $color = $statusColors[$this->order['status']] ?? [100, 100, 100];
        $this->SetFillColor($color[0], $color[1], $color[2]);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 10, 'STATUS: ' . strtoupper($this->order['status']), 0, 1, 'C', true);
        $this->Ln(5);

        // Reset text color
        $this->SetTextColor(0, 0, 0);
    }

    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');

        // Print date on left
        $this->SetY(-15);
        $this->SetX(10);
        $this->Cell(0, 10, 'Printed: ' . date('d/m/Y H:i'), 0, 0, 'L');
    }

    function SupplierInfo()
    {
        // Supplier box
        $this->SetFillColor(250, 250, 250);
        $this->SetDrawColor(200, 200, 200);

        // Box around supplier info
        $startY = $this->GetY();
        $this->Rect(10, $startY, 190, 65, 'D');
        $this->SetY($startY + 2);

        $this->SetFont('Arial', 'B', 11);
        $this->SetTextColor(41, 128, 185);
        $this->Cell(0, 8, 'SUPPLIER INFORMATION', 0, 1, 'L');

        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 6, $this->order['supplier_name'], 0, 1);

        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(80, 80, 80);

        if (!empty($this->order['contact_person'])) {
            $this->Cell(0, 5, 'Contact Person: ' . $this->order['contact_person'], 0, 1);
        }
        if (!empty($this->order['supplier_phone'])) {
            $this->Cell(0, 5, 'Phone: ' . $this->order['supplier_phone'], 0, 1);
        }
        if (!empty($this->order['supplier_email'])) {
            $this->Cell(0, 5, 'Email: ' . $this->order['supplier_email'], 0, 1);
        }
        if (!empty($this->order['supplier_address'])) {
            $this->MultiCell(0, 5, 'Address: ' . $this->order['supplier_address']);
        }

        $this->Ln(5);
    }

    function ItemsTable()
    {
        // Table header
        $this->SetFillColor(41, 128, 185);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 9);

        $this->Cell(70, 10, 'Product', 1, 0, 'C', true);
        $this->Cell(25, 10, 'SKU', 1, 0, 'C', true);
        $this->Cell(20, 10, 'Qty', 1, 0, 'C', true);
        $this->Cell(25, 10, 'Unit Price', 1, 0, 'C', true);
        $this->Cell(15, 10, 'Tax %', 1, 0, 'C', true);
        $this->Cell(35, 10, 'Line Total', 1, 1, 'C', true);

        // Table rows
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', '', 8);

        $subtotal = 0;
        $totalTax = 0;

        foreach ($this->order['items'] as $item) {
            $lineSubtotal = (float) $item['quantity'] * (float) $item['unit_price'];
            $lineTax = $lineSubtotal * ((float) $item['tax_rate'] / 100);
            $lineTotal = $lineSubtotal + $lineTax;

            $subtotal += $lineSubtotal;
            $totalTax += $lineTax;

            // Product name with variant
            $productText = $item['product_name'];
            if (!empty($item['variant_name']) && $item['variant_name'] !== 'Standard') {
                $productText .= ' - ' . $item['variant_name'];
            }

            // Check page break
            if ($this->GetY() > 250) {
                $this->AddPage();
                // Reprint header
                $this->SetFillColor(41, 128, 185);
                $this->SetTextColor(255, 255, 255);
                $this->SetFont('Arial', 'B', 9);
                $this->Cell(70, 10, 'Product', 1, 0, 'C', true);
                $this->Cell(25, 10, 'SKU', 1, 0, 'C', true);
                $this->Cell(20, 10, 'Qty', 1, 0, 'C', true);
                $this->Cell(25, 10, 'Unit Price', 1, 0, 'C', true);
                $this->Cell(15, 10, 'Tax %', 1, 0, 'C', true);
                $this->Cell(35, 10, 'Line Total', 1, 1, 'C', true);
                $this->SetTextColor(0, 0, 0);
                $this->SetFont('Arial', '', 8);
            }

            $this->Cell(70, 8, $this->shortenText($productText, 45), 1);
            $this->Cell(25, 8, $item['sku'], 1);
            $this->Cell(20, 8, number_format($item['quantity'], 2), 1, 0, 'R');
            $this->Cell(25, 8, number_format($item['unit_price'], 0) . ' ' . $this->company['currency'], 1, 0, 'R');
            $this->Cell(15, 8, $item['tax_rate'] . '%', 1, 0, 'C');
            $this->Cell(35, 8, number_format($lineTotal, 0) . ' ' . $this->company['currency'], 1, 1, 'R');
        }

        // Totals
        $this->Ln(5);
        $this->SetFont('Arial', 'B', 10);

        $this->SetX(120);
        $this->Cell(40, 7, 'Subtotal:', 0, 0, 'R');
        $this->Cell(40, 7, number_format($subtotal, 0) . ' ' . $this->company['currency'], 0, 1, 'R');

        $this->SetX(120);
        $avgTaxRate = $subtotal > 0 ? ($totalTax / $subtotal) * 100 : 0;
        $this->Cell(40, 7, 'Tax (' . number_format($avgTaxRate, 1) . '% avg):', 0, 0, 'R');
        $this->Cell(40, 7, number_format($totalTax, 0) . ' ' . $this->company['currency'], 0, 1, 'R');

        $this->SetX(120);
        $this->SetDrawColor(200, 200, 200);
        $this->Line(120, $this->GetY(), 200, $this->GetY());
        $this->Ln(1);

        $this->SetX(120);
        $this->SetFont('Arial', 'B', 14);
        $this->SetTextColor(41, 128, 185);
        $this->Cell(40, 10, 'TOTAL:', 0, 0, 'R');
        $this->Cell(40, 10, number_format($subtotal + $totalTax, 0) . ' ' . $this->company['currency'], 0, 1, 'R');

        $this->SetTextColor(0, 0, 0);
    }

    function Notes()
    {
        if (!empty($this->order['notes'])) {
            $this->Ln(5);
            $this->SetFont('Arial', 'B', 10);
            $this->SetTextColor(100, 100, 100);
            $this->Cell(0, 6, 'Notes:', 0, 1);
            $this->SetFont('Arial', '', 9);
            $this->MultiCell(0, 5, $this->order['notes']);
            $this->Ln(5);
        }

        // Terms and conditions
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 6, 'Terms & Conditions:', 0, 1);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(80, 80, 80);
        $this->MultiCell(0, 4, "1. This Purchase Order is valid as per the terms agreed with the supplier.\n2. All items must meet the specified quality standards.\n3. Delivery must be made by the expected date unless otherwise agreed.\n4. Invoices must reference this PO number for processing.\n5. Payment terms are as agreed with the supplier.");
    }

    private function shortenText($text, $length)
    {
        $text = strip_tags($text);
        if (strlen($text) > $length) {
            return substr($text, 0, $length) . '...';
        }
        return $text;
    }
}

// Create PDF
$pdf = new PurchaseOrderPDF($company, $order, $companyId, $logoPath);
$pdf->AliasNbPages();
$pdf->AddPage();

// Add supplier info
$pdf->SupplierInfo();

// Add items table
$pdf->ItemsTable();

// Add notes
$pdf->Notes();

// Output PDF
$filename = 'PO_' . $order['po_number'] . '_' . date('Y-m-d') . '.pdf';
$pdf->Output('D', $filename);
exit;