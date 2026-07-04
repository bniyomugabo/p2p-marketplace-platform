<?php
// pages/sales/record-payment.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Payment.php';
require_once __DIR__ . '/../../models/Sale.php';


// Check authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/auth/signin.php');
    exit;
}

$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR', 'SEL', 'ACC'])) {
    SessionManager::flash('error', 'You do not have permission to record payments.');
    header('Location: ' . route_url('sales/invoices'));
    exit;
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
    SessionManager::flash('error', 'Invalid security token. Please try again.');
    header('Location: ' . route_url('sales/invoices'));
    exit;
}

// Validate required fields
if (empty($_POST['invoice_id']) || empty($_POST['amount'])) {
    SessionManager::flash('error', 'Invoice ID and amount are required.');
    header('Location: ' . route_url('sales/invoices'));
    exit;
}

$db = Database::getInstance();
$paymentModel = new Payment($companyId);
$saleModel = new Sale($companyId);

try {
    // Verify invoice belongs to this company
    $invoice = $saleModel->getInvoiceWithDetails((int) $_POST['invoice_id']);
    if (!$invoice) {
        throw new Exception('Invoice not found or does not belong to your company.');
    }

    // Check if invoice is already paid
    if ($invoice['status'] === 'paid') {
        throw new Exception('This invoice is already fully paid.');
    }

    // Check if invoice is cancelled
    if ($invoice['status'] === 'cancelled') {
        throw new Exception('Cannot record payment for a cancelled invoice.');
    }

    $amount = (float) $_POST['amount'];
    $outstanding = $invoice['total_amount'] - $invoice['amount_paid'];

    // Validate amount
    if ($amount <= 0) {
        throw new Exception('Payment amount must be greater than zero.');
    }

    if ($amount > $outstanding) {
        throw new Exception('Payment amount cannot exceed the outstanding balance of ' . format_currency($outstanding, $companyId));
    }

    // Prepare payment data
    $paymentData = [
        'company_id' => $companyId,
        'invoice_id' => (int) $_POST['invoice_id'],
        'amount' => $amount,
        'payment_method' => $_POST['payment_method'] ?? 'cash',
        'payment_date' => $_POST['payment_date'] ?? date('Y-m-d'),
        'reference_number' => !empty($_POST['reference_number']) ? trim($_POST['reference_number']) : null,
        'notes' => !empty($_POST['payment_notes']) ? trim($_POST['payment_notes']) : null,
        'created_by' => $userId
    ];

    // Start transaction
    $db->beginTransaction();

    // Add payment
    $paymentId = $paymentModel->addPayment($paymentData);

    if (!$paymentId) {
        throw new Exception('Failed to record payment.');
    }

    // Update invoice status
    $newAmountPaid = $invoice['amount_paid'] + $amount;

    if ($newAmountPaid >= $invoice['total_amount']) {
        // Invoice is fully paid
        $newStatus = 'paid';
        $updateStmt = $db->prepare("
            UPDATE sales_invoices 
            SET amount_paid = :amount_paid, status = 'paid', updated_at = NOW()
            WHERE id = :id AND company_id = :company_id
        ");
    } else {
        // Invoice is partially paid
        $newStatus = 'partial';
        $updateStmt = $db->prepare("
            UPDATE sales_invoices 
            SET amount_paid = :amount_paid, status = 'partial', updated_at = NOW()
            WHERE id = :id AND company_id = :company_id
        ");
    }

    $updateStmt->execute([
        'amount_paid' => $newAmountPaid,
        'id' => (int) $_POST['invoice_id'],
        'company_id' => $companyId
    ]);

    // Log activity
    $logStmt = $db->prepare("
        INSERT INTO activity_log 
        (company_id, user_id, action, entity_type, entity_id, new_data, created_at)
        VALUES (:company_id, :user_id, 'payment_recorded', 'invoice', :entity_id, :new_data, NOW())
    ");

    $logStmt->execute([
        'company_id' => $companyId,
        'user_id' => $userId,
        'entity_id' => (int) $_POST['invoice_id'],
        'new_data' => json_encode([
            'payment_id' => $paymentId,
            'amount' => $amount,
            'payment_method' => $paymentData['payment_method'],
            'new_status' => $newStatus,
            'new_amount_paid' => $newAmountPaid
        ])
    ]);

    // Commit transaction
    $db->commit();

    // Success message
    $message = "Payment of " . format_currency($amount, $companyId) . " recorded successfully!";
    if ($newStatus === 'paid') {
        $message .= " The invoice is now fully paid.";
    } else {
        $remaining = $invoice['total_amount'] - $newAmountPaid;
        $message .= " Remaining balance: " . format_currency($remaining, $companyId);
    }

    SessionManager::flash('success', $message);

    // Redirect back to invoice view
    header('Location: ' . route_url('sales/view-invoice', ['id' => $_POST['invoice_id']]));
    exit;

} catch (Exception $e) {
    // Rollback transaction if active
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log("Record payment error: " . $e->getMessage());
    SessionManager::flash('error', 'Failed to record payment: ' . $e->getMessage());
    header('Location: ' . route_url('sales/view-invoice', ['id' => $_POST['invoice_id']]));
    exit;
}