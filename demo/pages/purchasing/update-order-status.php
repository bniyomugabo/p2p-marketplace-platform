<?php
// pages/purchasing/update-order-status.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/autoload.php';
require_once __DIR__ . '/../../models/PurchaseOrder.php';


// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/auth/signin.php');
    exit;
}

$userRole = $_SESSION['user_role'] ?? 'VIW';
$userId = $_SESSION['user_id'] ?? 0;
$companyId = $_SESSION['company_id'] ?? null;

// Check if company context is set
if (!$companyId) {
    SessionManager::flash('error', 'Company context not found. Please log in again.');
    header('Location: ' . route_url('login'));
    exit;
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || !CSRF::validate($_POST['csrf_token'])) {
    SessionManager::flash('error', 'Invalid security token. Please try again.');
    header('Location: ' . route_url('purchasing/orders'));
    exit;
}

// Validate required fields
if (empty($_POST['id']) || empty($_POST['status'])) {
    SessionManager::flash('error', 'Order ID and status are required.');
    header('Location: ' . route_url('purchasing/orders'));
    exit;
}

$orderId = (int) $_POST['id'];
$newStatus = $_POST['status'];
$notes = trim($_POST['notes'] ?? '');

// Validate status value
$allowedStatuses = ['pending', 'approved', 'cancelled'];
if (!in_array($newStatus, $allowedStatuses)) {
    SessionManager::flash('error', 'Invalid status value.');
    header('Location: ' . route_url('purchasing/view-order', ['id' => $orderId]));
    exit;
}

// Validate permissions based on status
if ($newStatus === 'pending' && !in_array($userRole, ['ADM', 'MGR', 'ACC'])) {
    SessionManager::flash('error', 'You do not have permission to submit orders for approval.');
    header('Location: ' . route_url('purchasing/view-order', ['id' => $orderId]));
    exit;
}

if (in_array($newStatus, ['approved', 'cancelled']) && !in_array($userRole, ['ADM', 'MGR'])) {
    SessionManager::flash('error', 'You do not have permission to approve or cancel orders.');
    header('Location: ' . route_url('purchasing/view-order', ['id' => $orderId]));
    exit;
}

// Validate rejection reason
if ($newStatus === 'cancelled' && empty($notes)) {
    SessionManager::flash('error', 'Please provide a reason for cancellation.');
    header('Location: ' . route_url('purchasing/view-order', ['id' => $orderId]));
    exit;
}

$db = Database::getInstance();

// Initialize model with company ID
$purchaseOrderModel = new PurchaseOrder($companyId);

try {
    // Get order with company verification
    $order = $purchaseOrderModel->find($orderId);

    if (!$order) {
        throw new Exception('Purchase order not found or does not belong to your company.');
    }

    // Validate status transition based on current status
    $currentStatus = $order['status'];

    // Define allowed transitions
    $allowedTransitions = [
        'draft' => ['pending'],           // Draft can go to pending
        'pending' => ['approved', 'cancelled'], // Pending can be approved or cancelled
        'approved' => ['cancelled'],      // Approved can be cancelled (if not received)
        'partial' => ['cancelled'],       // Partial can be cancelled
        'received' => [],                  // Received cannot be changed
        'cancelled' => []                  // Cancelled cannot be changed
    ];

    if (!isset($allowedTransitions[$currentStatus]) || !in_array($newStatus, $allowedTransitions[$currentStatus])) {
        throw new Exception("Cannot change status from '{$currentStatus}' to '{$newStatus}'.");
    }

    // Additional validation for approved orders
    if ($newStatus === 'approved' && $currentStatus !== 'pending') {
        throw new Exception('Only pending orders can be approved.');
    }

    // Check if order has items
    if (in_array($newStatus, ['approved', 'pending'])) {
        $itemCheckSql = "SELECT COUNT(*) as item_count FROM purchase_items WHERE purchase_order_id = :order_id AND company_id = :company_id";
        $itemStmt = $db->prepare($itemCheckSql);
        $itemStmt->execute([
            'order_id' => $orderId,
            'company_id' => $companyId
        ]);
        $itemCount = $itemStmt->fetchColumn();

        if ($itemCount == 0) {
            throw new Exception('Cannot submit order with no items.');
        }
    }

    // Start transaction for status update
    $db->beginTransaction();

    // Update order status
    $updateData = ['status' => $newStatus];

    // Add notes if provided
    if ($notes) {
        $existingNotes = $order['notes'] ?? '';
        if ($newStatus === 'cancelled') {
            $updateData['notes'] = $existingNotes
                ? $existingNotes . "\n\n[CANCELLED] " . date('Y-m-d H:i') . " - " . $notes
                : "[CANCELLED] " . date('Y-m-d H:i') . " - " . $notes;
        } elseif ($newStatus === 'approved') {
            $updateData['notes'] = $existingNotes
                ? $existingNotes . "\n\n[APPROVED] " . date('Y-m-d H:i') . " by user ID: " . $userId
                : "[APPROVED] " . date('Y-m-d H:i') . " by user ID: " . $userId;
        } elseif ($newStatus === 'pending') {
            $updateData['notes'] = $existingNotes
                ? $existingNotes . "\n\n[SUBMITTED] " . date('Y-m-d H:i')
                : "[SUBMITTED] " . date('Y-m-d H:i');
        }
    }

    $result = $purchaseOrderModel->update($orderId, $updateData);

    if (!$result) {
        throw new Exception('Failed to update order status.');
    }

    // Log the activity
    $activitySql = "
        INSERT INTO activity_log (company_id, user_id, action, entity_type, entity_id, old_data, new_data, created_at)
        VALUES (:company_id, :user_id, :action, 'purchase_order', :order_id, :old_data, :new_data, NOW())
    ";

    $activityStmt = $db->prepare($activitySql);
    $activityStmt->execute([
        'company_id' => $companyId,
        'user_id' => $userId,
        'action' => "order_{$newStatus}",
        'order_id' => $orderId,
        'old_data' => json_encode(['status' => $currentStatus]),
        'new_data' => json_encode(['status' => $newStatus, 'notes' => $notes])
    ]);

    $db->commit();

    // Prepare success message
    $messages = [
        'pending' => 'Purchase order #' . htmlspecialchars($order['po_number']) . ' has been submitted for approval.',
        'approved' => 'Purchase order #' . htmlspecialchars($order['po_number']) . ' has been approved successfully.',
        'cancelled' => 'Purchase order #' . htmlspecialchars($order['po_number']) . ' has been cancelled.'
    ];

    SessionManager::flash('success', $messages[$newStatus]);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Status update error for company {$companyId}, order {$orderId}: " . $e->getMessage());
    SessionManager::flash('error', 'Failed to update status: ' . $e->getMessage());
}

// Redirect back to order view
header('Location: ' . route_url('purchasing/view-order', ['id' => $orderId]));
exit;