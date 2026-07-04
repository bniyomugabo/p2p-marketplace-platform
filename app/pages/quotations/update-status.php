<?php
// pages/quotations/update-status.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/Quotation.php';



// Check authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/auth/signin.php');
    exit;
}

$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR', 'SEL'])) {
    SessionManager::flash('error', 'You do not have permission to update quotation status.');
    header('Location: ' . route_url('quotations'));
    exit;
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
    SessionManager::flash('error', 'Invalid security token. Please try again.');
    header('Location: ' . route_url('quotations'));
    exit;
}

// Validate required fields
if (empty($_POST['id']) || empty($_POST['status'])) {
    SessionManager::flash('error', 'Quotation ID and status are required.');
    header('Location: ' . route_url('quotations'));
    exit;
}

$quotationId = (int) $_POST['id'];
$newStatus = $_POST['status'];
$notes = !empty($_POST['notes']) ? trim($_POST['notes']) : null;

// Validate status
$allowedStatuses = ['sent', 'accepted', 'rejected', 'expired'];
if (!in_array($newStatus, $allowedStatuses)) {
    SessionManager::flash('error', 'Invalid status.');
    header('Location: ' . route_url('quotations/view', ['id' => $quotationId]));
    exit;
}

$db = Database::getInstance();
$quotationModel = new Quotation();

try {
    // Get quotation details
    $quotation = $quotationModel->find($quotationId);

    if (!$quotation) {
        throw new Exception('Quotation not found.');
    }

    // Validate status transition
    if ($quotation['status'] === 'accepted' || $quotation['status'] === 'rejected') {
        throw new Exception('Cannot change status of accepted or rejected quotations.');
    }

    if ($newStatus === 'sent' && $quotation['status'] !== 'draft') {
        throw new Exception('Only draft quotations can be marked as sent.');
    }

    // Update status
    $updateData = ['status' => $newStatus];
    if ($notes) {
        $updateData['notes'] = $notes;
    }

    $result = $quotationModel->update($quotationId, $updateData);

    if (!$result) {
        throw new Exception('Failed to update quotation status.');
    }

    // Log the activity
    error_log("User {$userId} updated quotation {$quotationId} status from {$quotation['status']} to {$newStatus}");

    $message = 'Quotation status updated successfully.';
    if ($newStatus === 'accepted') {
        $message = 'Quotation marked as accepted!';
    } elseif ($newStatus === 'rejected') {
        $message = 'Quotation marked as rejected.';
    } elseif ($newStatus === 'sent') {
        $message = 'Quotation marked as sent.';
    }

    SessionManager::flash('success', $message);
    header('Location: ' . route_url('quotations/view', ['id' => $quotationId]));
    exit;

} catch (Exception $e) {
    error_log("Update quotation status error: " . $e->getMessage());
    SessionManager::flash('error', 'Failed to update quotation status: ' . $e->getMessage());
    header('Location: ' . route_url('quotations/view', ['id' => $quotationId]));
    exit;
}