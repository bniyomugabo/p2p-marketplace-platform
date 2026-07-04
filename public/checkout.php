<?php
// /public/checkout.php
// Checkout Page

require_once __DIR__ . '/../src/init.php';

// Ensure constants are defined (they should be from config.php)
if (!defined('DEFAULT_TAX_RATE')) define('DEFAULT_TAX_RATE', 18);
if (!defined('FREE_SHIPPING_THRESHOLD')) define('FREE_SHIPPING_THRESHOLD', 50000);
if (!defined('SHIPPING_COST')) define('SHIPPING_COST', 5000);
if (!defined('DEFAULT_CURRENCY')) define('DEFAULT_CURRENCY', 'RWF');

// Check if cart is empty
$cart = SessionManager::getCart();

$isLoggedIn = SessionManager::isCustomerLoggedIn();
$customer = SessionManager::getCustomer();
$customerId = SessionManager::getCustomerId();

// Calculate totals
$subtotal = SessionManager::getCartSubtotal();
$taxAmount = $subtotal * (DEFAULT_TAX_RATE / 100);
$shippingCost = ($subtotal >= FREE_SHIPPING_THRESHOLD) ? 0 : SHIPPING_COST;
$total = $subtotal + $taxAmount + $shippingCost;

// If cart is empty, redirect to cart page
if (empty($cart) || $subtotal == 0) {
    header('Location: cart.php');
    exit;
}

$pageTitle = 'Checkout';

include __DIR__ . '/../templates/header.php';
?>

<main class="container">
    <h1>Checkout</h1>
    
    <?php if (empty($cart)): ?>
        <div class="empty-cart">
            <p>Your cart is empty.</p>
            <a href="index.php" class="btn">Continue Shopping</a>
        </div>
    <?php else: ?>
        <div class="checkout-container">
            <form id="checkout-form" class="checkout-form">
                <input type="hidden" name="csrf_token" value="<?php echo SessionManager::generateCsrfToken(); ?>">
                
                <div class="form-section">
                    <h3>Contact Information</h3>
                    
                    <?php if ($isLoggedIn && $customer): ?>
                        <div class="logged-in-info">
                            <p>Logged in as: <strong><?php echo htmlspecialchars($customer['full_name'] ?? ''); ?></strong></p>
                            <p>Email: <?php echo htmlspecialchars($customer['email'] ?? ''); ?></p>
                            <p>Phone: <?php echo htmlspecialchars($customer['phone'] ?? ''); ?></p>
                            <a href="logout.php" class="logout-link">Logout</a>
                        </div>
                        <input type="hidden" name="customer_id" value="<?php echo $customerId; ?>">
                        <input type="hidden" name="full_name" value="<?php echo htmlspecialchars($customer['full_name'] ?? ''); ?>">
                        <input type="hidden" name="email" value="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>">
                    <?php else: ?>
                        <div class="form-group">
                            <label for="full_name">Full Name *</label>
                            <input type="text" id="full_name" name="full_name" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email *</label>
                            <input type="email" id="email" name="email" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone">Phone Number *</label>
                            <input type="tel" id="phone" name="phone" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="address">Delivery Address *</label>
                            <textarea id="address" name="address" rows="3" required></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="city">City *</label>
                            <input type="text" id="city" name="city" required>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="form-section">
                    <h3>Order Summary</h3>
                    <div class="order-summary">
                        <div class="summary-item">
                            <span>Subtotal:</span>
                            <span><?php echo number_format($subtotal, 0, ',', ' ') . ' ' . DEFAULT_CURRENCY; ?></span>
                        </div>
                        <div class="summary-item">
                            <span>Tax (<?php echo DEFAULT_TAX_RATE; ?>%):</span>
                            <span><?php echo number_format($taxAmount, 0, ',', ' ') . ' ' . DEFAULT_CURRENCY; ?></span>
                        </div>
                        <div class="summary-item">
                            <span>Shipping:</span>
                            <span><?php echo $shippingCost == 0 ? 'Free' : number_format($shippingCost, 0, ',', ' ') . ' ' . DEFAULT_CURRENCY; ?></span>
                        </div>
                        <?php if ($subtotal < FREE_SHIPPING_THRESHOLD && $subtotal > 0): ?>
                            <div class="free-shipping-notice">
                                Add <?php echo number_format(FREE_SHIPPING_THRESHOLD - $subtotal, 0, ',', ' ') . ' ' . DEFAULT_CURRENCY; ?> more for free shipping!
                            </div>
                        <?php endif; ?>
                        <div class="summary-item total">
                            <span>Total:</span>
                            <span><?php echo number_format($total, 0, ',', ' ') . ' ' . DEFAULT_CURRENCY; ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h3>Payment Method</h3>
                    <div class="payment-methods">
                        <label class="payment-option">
                            <input type="radio" name="payment_method" value="cash" checked>
                            <span>Cash on Delivery</span>
                        </label>
                        <label class="payment-option">
                            <input type="radio" name="payment_method" value="mobile">
                            <span>Mobile Money</span>
                        </label>
                        <label class="payment-option">
                            <input type="radio" name="payment_method" value="bank_transfer">
                            <span>Bank Transfer</span>
                        </label>
                    </div>
                </div>
                
                <div class="form-section">
                    <h3>Order Notes (Optional)</h3>
                    <textarea name="notes" rows="3" placeholder="Special instructions for delivery..."></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary btn-place-order">Place Order</button>
            </form>
            
            <div class="order-items">
                <h3>Your Items</h3>
                <?php foreach ($cart as $variantId => $item): ?>
                    <div class="order-item">
                        <div class="item-details">
                            <h4><?php echo htmlspecialchars($item['product_name'] ?? 'Product'); ?></h4>
                            <?php if (!empty($item['variant_name'])): ?>
                                <p class="item-variant"><?php echo htmlspecialchars($item['variant_name']); ?></p>
                            <?php endif; ?>
                            <p class="item-quantity">Quantity: <?php echo $item['quantity']; ?></p>
                        </div>
                        <div class="item-price">
                            <?php echo number_format($item['quantity'] * $item['price'], 0, ',', ' ') . ' ' . DEFAULT_CURRENCY; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</main>

<style>
.checkout-container {
    display: grid;
    grid-template-columns: 1fr 350px;
    gap: 2rem;
    margin: 2rem 0;
}

.checkout-form {
    background: white;
    border-radius: 12px;
    padding: 2rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.form-section {
    margin-bottom: 2rem;
}

.form-section h3 {
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #2c7da0;
}

.form-group {
    margin-bottom: 1rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    color: #555;
}

.form-group input,
.form-group textarea {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 1rem;
}

.form-group input:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #2c7da0;
    box-shadow: 0 0 0 3px rgba(44,125,160,0.1);
}

.logged-in-info {
    background: #e8f4f8;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1rem;
}

.logged-in-info p {
    margin: 0.25rem 0;
}

.logout-link {
    display: inline-block;
    margin-top: 0.5rem;
    color: #dc3545;
    text-decoration: none;
}

.payment-methods {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.payment-option {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
}

.order-items {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    height: fit-content;
    position: sticky;
    top: 100px;
}

.order-item {
    display: flex;
    justify-content: space-between;
    padding: 1rem 0;
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
}

.item-price {
    font-weight: 600;
    color: #2c7da0;
}

.order-summary {
    margin-top: 1rem;
}

.summary-item {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
}

.summary-item.total {
    font-weight: bold;
    font-size: 1.1rem;
    border-top: 2px solid #eee;
    margin-top: 0.5rem;
    padding-top: 0.5rem;
}

.free-shipping-notice {
    background: #d4edda;
    color: #155724;
    padding: 0.5rem;
    border-radius: 5px;
    margin: 1rem 0;
    text-align: center;
    font-size: 0.85rem;
}

.btn-place-order {
    width: 100%;
    margin-top: 1rem;
    padding: 1rem;
    font-size: 1.1rem;
}

.empty-cart {
    text-align: center;
    padding: 4rem;
}

@media (max-width: 768px) {
    .checkout-container {
        grid-template-columns: 1fr;
    }
    
    .order-items {
        position: static;
    }
}
</style>

<script>
document.getElementById('checkout-form')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const data = Object.fromEntries(formData);
    
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.textContent = 'Processing...';
    submitBtn.disabled = true;
    
    try {
        const response = await fetch('../src/api/process_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            showNotification('Order placed successfully!', 'success');
            setTimeout(() => {
                window.location.href = 'order_confirmation.php?invoice=' + result.invoice_number;
            }, 1000);
        } else {
            showNotification(result.message || 'Order failed. Please try again.', 'error');
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
        }
    } catch (error) {
        console.error('Checkout error:', error);
        showNotification('Network error. Please try again.', 'error');
        submitBtn.textContent = originalText;
        submitBtn.disabled = false;
    }
});

function showNotification(message, type) {
    const existing = document.querySelectorAll('.notification');
    existing.forEach(n => n.remove());
    
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.textContent = message;
    notification.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        padding: 12px 24px;
        border-radius: 8px;
        color: white;
        z-index: 10000;
        background: ${type === 'success' ? '#28a745' : '#dc3545'};
        animation: slideIn 0.3s ease;
    `;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.opacity = '0';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>