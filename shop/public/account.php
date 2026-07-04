<?php
// /public/account.php
// Customer Account Dashboard

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/init.php';

$customer = SessionManager::getCustomer();
$pageTitle = 'My Account - ' . SITE_NAME;

if (!$customer) {
    header('Location: ./login.php');
    exit;
}

$customerModel = new Customer();
$orderModel = new Order();

// Get order history
$orders = $customerModel->getOrderHistory($customer['id'], 10, 0);
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
                <a href="account.php" class="active">Dashboard</a>
                <a href="account_orders.php">My Orders</a>
                <a href="account_profile.php">Profile Settings</a>
                <a href="account_password.php">Change Password</a>
                <a href="wishlist.php">Wishlist</a>
                <a href="logout.php">Logout</a>
            </nav>
        </div>
        
        <div class="account-content">
            <h1>Dashboard</h1>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value"><?php echo count($orders); ?></div>
                    <div class="stat-label">Total Orders</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">0</div>
                    <div class="stat-label">Pending Orders</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">0</div>
                    <div class="stat-label">Wishlist Items</div>
                </div>
            </div>
            
            <div class="recent-orders">
                <h2>Recent Orders</h2>
                
                <?php if (empty($orders)): ?>
                    <div class="no-orders">
                        <p>You haven't placed any orders yet.</p>
                        <a href="index.php" class="btn btn-primary">Start Shopping</a>
                    </div>
                <?php else: ?>
                    <div class="orders-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Date</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders as $order): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($order['invoice_number']); ?></td>
                                        <td><?php echo date('d/m/Y', strtotime($order['invoice_date'])); ?></td>
                                        <td><?php echo Formatter::currency($order['total_amount']); ?></td>
                                        <td>
                                            <span class="order-status status-<?php echo $order['status']; ?>">
                                                <?php echo ucfirst($order['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="order_details.php?id=<?php echo $order['id']; ?>" class="btn-sm">View</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>


<?php include __DIR__ . '/../templates/footer.php'; ?>