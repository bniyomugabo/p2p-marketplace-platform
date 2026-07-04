<?php
// /public/order_details.php
// Order Details Page

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/init.php';

$customer = SessionManager::getCustomer();

if (!$customer) {
    header('Location: login.php');
    exit;
}

$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($orderId <= 0) {
    header('Location: account_orders.php');
    exit;
}

$orderModel = new Order();
$order = $orderModel->getOrderById($orderId, $customer['id']);

if (!$order) {
    header('Location: account_orders.php');
    exit;
}

$pageTitle = 'Order Details - ' . SITE_NAME;
$additionalStyles = ['account.css'];
include __DIR__ . '/../templates/header.php';
?>

<main class="container">
    <div class="order-details-container">
        <div class="order-details-header">
            <h1>Order Details</h1>
            <a href="account_orders.php" class="btn-back">← Back to Orders</a>
        </div>
        
        <div class="order-info-card">
            <div class="order-info-row">
                <div class="order-info-group">
                    <label>Order Number:</label>
                    <span><?php echo htmlspecialchars($order['invoice_number']); ?></span>
                </div>
                <div class="order-info-group">
                    <label>Order Date:</label>
                    <span><?php echo date('F d, Y', strtotime($order['invoice_date'])); ?></span>
                </div>
                <div class="order-info-group">
                    <label>Status:</label>
                    <span class="status-badge status-<?php echo $order['status']; ?>"><?php echo ucfirst($order['status']); ?></span>
                </div>
            </div>
            
            <div class="order-info-row">
                <div class="order-info-group">
                    <label>Payment Method:</label>
                    <span><?php echo ucfirst($order['payment_method'] ?? 'Not paid yet'); ?></span>
                </div>
                <div class="order-info-group">
                    <label>Payment Status:</label>
                    <span><?php echo $order['amount_paid'] >= $order['total_amount'] ? 'Paid' : 'Pending'; ?></span>
                </div>
            </div>
        </div>
        
        <div class="order-items-card">
            <h3>Items Ordered</h3>
            
            <div class="order-items-table">
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Variant</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($order['items'] as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                <td><?php echo htmlspecialchars($item['variant_name'] ?? 'Standard'); ?></td>
                                <td><?php echo $item['quantity']; ?></td>
                                <td><?php echo number_format($item['unit_price'], 0); ?> RWF</td>
                                <td><?php echo number_format($item['quantity'] * $item['unit_price'], 0); ?> RWF</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="text-right"><strong>Subtotal:</strong></td>
                            <td><?php echo number_format($order['subtotal'], 0); ?> RWF</td>
                        </tr>
                        <tr>
                            <td colspan="4" class="text-right"><strong>Tax (<?php echo DEFAULT_TAX_RATE; ?>%):</strong></td>
                            <td><?php echo number_format($order['tax_amount'], 0); ?> RWF</td>
                        </tr>
                        <tr>
                            <td colspan="4" class="text-right"><strong>Shipping:</strong></td>
                            <td><?php echo number_format($order['shipping_cost'] ?? 0, 0); ?> RWF</td>
                        </tr>
                        <tr class="total-row">
                            <td colspan="4" class="text-right"><strong>Total:</strong></td>
                            <td><strong><?php echo number_format($order['total_amount'], 0); ?> RWF</strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        
        <div class="order-actions">
            <?php if ($order['status'] === 'issued' && $order['amount_paid'] < $order['total_amount']): ?>
                <button class="btn-pay-now">Pay Now</button>
            <?php endif; ?>
            <button class="btn-invoice" onclick="window.print()">Print Invoice</button>
        </div>
    </div>
</main>

<style>
.order-details-container {
    max-width: 1000px;
    margin: 2rem auto;
}

.order-details-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
}

.order-details-header h1 {
    margin: 0;
    font-size: 1.5rem;
}

.btn-back {
    padding: 0.5rem 1rem;
    background: #6c757d;
    color: white;
    text-decoration: none;
    border-radius: 6px;
    font-size: 0.85rem;
}

.btn-back:hover {
    background: #5a6268;
}

.order-info-card,
.order-items-card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.order-info-row {
    display: flex;
    gap: 2rem;
    flex-wrap: wrap;
}

.order-info-group {
    flex: 1;
    min-width: 150px;
}

.order-info-group label {
    display: block;
    font-size: 0.75rem;
    color: #888;
    margin-bottom: 0.25rem;
    text-transform: uppercase;
}

.order-info-group span {
    font-size: 1rem;
    font-weight: 500;
    color: #333;
}

.status-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 500;
}

.order-items-card h3 {
    margin: 0 0 1rem;
    font-size: 1.1rem;
}

.order-items-table {
    overflow-x: auto;
}

.order-items-table table {
    width: 100%;
    border-collapse: collapse;
}

.order-items-table th,
.order-items-table td {
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid #eee;
}

.order-items-table th {
    background: #f8f9fa;
    font-weight: 600;
}

.text-right {
    text-align: right;
}

.total-row td {
    border-top: 2px solid #ddd;
    font-size: 1.1rem;
}

.order-actions {
    display: flex;
    gap: 1rem;
    justify-content: flex-end;
}

.btn-pay-now,
.btn-invoice {
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 1rem;
    transition: background 0.2s;
}

.btn-pay-now {
    background: #28a745;
    color: white;
}

.btn-pay-now:hover {
    background: #218838;
}

.btn-invoice {
    background: #6c757d;
    color: white;
}

.btn-invoice:hover {
    background: #5a6268;
}

@media print {
    .account-sidebar,
    .account-nav,
    .btn-back,
    .order-actions,
    .footer,
    .header {
        display: none;
    }
    
    .order-details-container {
        margin: 0;
        padding: 0;
    }
    
    .order-info-card,
    .order-items-card {
        box-shadow: none;
        padding: 0;
        margin-bottom: 1rem;
    }
    
    .order-items-table th,
    .order-items-table td {
        padding: 0.5rem;
    }
}

@media (max-width: 768px) {
    .order-info-row {
        flex-direction: column;
        gap: 1rem;
    }
    
    .order-actions {
        flex-direction: column;
    }
    
    .order-actions button {
        width: 100%;
    }
}
</style>

<script>
document.querySelector('.btn-pay-now')?.addEventListener('click', function() {
    // Redirect to payment page
    window.location.href = `checkout.php?order_id=<?php echo $orderId; ?>`;
});
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>