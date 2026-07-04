<?php
// pages/purchasing/delete-order.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/PurchaseOrder.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/auth/signin.php');
    exit;
}

$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;

// Check if user has permission
if (!in_array($userRole, ['ADM', 'MGR'])) {
    SessionManager::flash('error', 'You do not have permission to delete orders.');
    header('Location: ' . route_url('purchasing/orders'));
    exit;
}

// Get order ID from URL
$orderId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$orderId) {
    SessionManager::flash('error', 'Order ID is required.');
    header('Location: ' . route_url('purchasing/orders'));
    exit;
}

$db = Database::getInstance();
$purchaseOrderModel = new PurchaseOrder();

try {
    // Get order details for validation
    $order = $purchaseOrderModel->find($orderId);

    if (!$order) {
        throw new Exception('Order not found.');
    }

    // Only allow deletion of draft orders
    if ($order['status'] !== 'draft') {
        throw new Exception('Only draft orders can be deleted.');
    }

    // Start transaction
    $db->beginTransaction();

    // Delete order (cascade will delete items automatically)
    $result = $purchaseOrderModel->delete($orderId, false); // hard delete

    if (!$result) {
        throw new Exception('Failed to delete order.');
    }

    // Log the activity
    error_log("User {$userId} deleted purchase order ID: {$orderId}, PO: {$order['po_number']}");

    $db->commit();

    SessionManager::flash('success', 'Purchase order deleted successfully.');
    header('Location: ' . route_url('purchasing/orders'));
    exit;

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log("Delete order error: " . $e->getMessage());
    SessionManager::flash('error', 'Failed to delete order: ' . $e->getMessage());
    header('Location: ' . route_url('purchasing/view-order', ['id' => $orderId]));
    exit;
}