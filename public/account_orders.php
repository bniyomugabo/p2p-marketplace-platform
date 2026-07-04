<?php
// /public/account_orders.php
// Customer Orders List

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/init.php';

$customer = SessionManager::getCustomer();

if (!$customer) {
    header('Location: login.php');
    exit;
}

$pageTitle = 'My Orders - ' . SITE_NAME;

$customerModel = new Customer();
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$orders = $customerModel->getOrderHistory($customer['id'], $limit, $offset);
$additionalStyles = ['account.css'];
include __DIR__ . '/../templates/header.php';
?>

<main class="container">
    <div class="account-container">
        <div class="account-sidebar">
            <div class="user-avatar">
                <div class="avatar-initials">
                    <?php echo strtoupper(substr($customer['full_name'], 0, 2)); ?>
                </div>
                <h3><?php echo htmlspecialchars($customer['full_name']); ?></h3>
                <p><?php echo htmlspecialchars($customer['email']); ?></p>
            </div>
            
            <nav class="account-nav">
                <a href="account.php">Dashboard</a>
                <a href="account_orders.php" class="active">My Orders</a>
                <a href="account_profile.php">Profile Settings</a>
                <a href="account_password.php">Change Password</a>
                <a href="wishlist.php">Wishlist</a>
                <a href="logout.php">Logout</a>
            </nav>
        </div>
        
        <div class="account-content">
            <h1>My Orders</h1>
            
            <?php if (empty($orders)): ?>
                <div class="no-orders">
                    <div class="empty-icon">📦</div>
                    <p>You haven't placed any orders yet.</p>
                    <a href="index.php" class="btn btn-primary">Start Shopping</a>
                </div>
            <?php else: ?>
                <div class="orders-list">
                    <?php foreach ($orders as $order): ?>
                        <div class="order-card">
                            <div class="order-header">
                                <div class="order-info">
                                    <span class="order-number">Order #<?php echo htmlspecialchars($order['invoice_number']); ?></span>
                                    <span class="order-date"><?php echo date('F d, Y', strtotime($order['invoice_date'])); ?></span>
                                </div>
                                <div class="order-status-badge status-<?php echo $order['status']; ?>">
                                    <?php echo ucfirst($order['status']); ?>
                                </div>
                            </div>
                            
                            <div class="order-items">
                                <?php foreach (array_slice($order['items'], 0, 3) as $item): ?>
                                    <div class="order-item">
                                        <div class="item-details">
                                            <h4><?php echo htmlspecialchars($item['product_name']); ?></h4>
                                            <?php if (!empty($item['variant_name'])): ?>
                                                <p class="item-variant"><?php echo htmlspecialchars($item['variant_name']); ?></p>
                                            <?php endif; ?>
                                            <p class="item-quantity">Quantity: <?php echo $item['quantity']; ?> × <?php echo number_format($item['unit_price'], 0); ?> RWF</p>
                                        </div>
                                        <div class="item-total">
                                            <?php echo number_format($item['quantity'] * $item['unit_price'], 0); ?> RWF
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                
                                <?php if (count($order['items']) > 3): ?>
                                    <div class="more-items">
                                        +<?php echo count($order['items']) - 3; ?> more items
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="order-footer">
                                <div class="order-total">
                                    <span>Total:</span>
                                    <strong><?php echo number_format($order['total_amount'], 0); ?> RWF</strong>
                                </div>
                                <a href="order_details.php?id=<?php echo $order['id']; ?>" class="btn-view-order">View Order Details</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<style>
.orders-list {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.order-card {
    background: #f8f9fa;
    border-radius: 12px;
    overflow: hidden;
    transition: box-shadow 0.2s;
}

.order-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.order-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.5rem;
    background: white;
    border-bottom: 1px solid #eee;
}

.order-number {
    font-weight: 600;
    color: #333;
}

.order-date {
    font-size: 0.85rem;
    color: #888;
    margin-left: 1rem;
}

.order-status-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 500;
}

.status-paid, .status-issued {
    background: #d4edda;
    color: #155724;
}

.status-pending {
    background: #fff3cd;
    color: #856404;
}

.status-draft {
    background: #e2e3e5;
    color: #383d41;
}

.status-cancelled {
    background: #f8d7da;
    color: #721c24;
}

.order-items {
    padding: 1rem 1.5rem;
}

.order-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 0;
    border-bottom: 1px solid #eee;
}

.order-item:last-child {
    border-bottom: none;
}

.item-details h4 {
    margin: 0 0 0.25rem;
    font-size: 0.95rem;
}

.item-variant {
    font-size: 0.8rem;
    color: #888;
    margin: 0.25rem 0;
}

.item-quantity {
    font-size: 0.8rem;
    color: #666;
    margin: 0.25rem 0 0;
}

.item-total {
    font-weight: 600;
    color: #2c7da0;
}

.more-items {
    text-align: center;
    font-size: 0.8rem;
    color: #888;
    padding-top: 0.5rem;
}

.order-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.5rem;
    background: white;
    border-top: 1px solid #eee;
}

.order-total span {
    font-size: 0.9rem;
    color: #666;
}

.order-total strong {
    font-size: 1.1rem;
    color: #2c7da0;
    margin-left: 0.5rem;
}

.btn-view-order {
    padding: 0.5rem 1rem;
    background: #2c7da0;
    color: white;
    text-decoration: none;
    border-radius: 6px;
    font-size: 0.85rem;
    transition: background 0.2s;
}

.btn-view-order:hover {
    background: #1f5068;
}

.empty-icon {
    font-size: 4rem;
    margin-bottom: 1rem;
    opacity: 0.5;
}

.no-orders {
    text-align: center;
    padding: 4rem;
    background: #f8f9fa;
    border-radius: 12px;
}

.no-orders p {
    margin-bottom: 1.5rem;
    color: #666;
}

@media (max-width: 768px) {
    .order-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.5rem;
    }
    
    .order-footer {
        flex-direction: column;
        gap: 1rem;
    }
    
    .btn-view-order {
        width: 100%;
        text-align: center;
    }
}
</style>

<?php include __DIR__ . '/../templates/footer.php'; ?>