<?php
// /public/cart.php
// Shopping Cart Page

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/init.php';

$cart = SessionManager::getCart();
$cartItems = [];
$subtotal = 0;

foreach ($cart as $variantId => $item) {
    $item['subtotal'] = $item['quantity'] * $item['price'];
    $subtotal += $item['subtotal'];
    $cartItems[] = $item;
}

$taxAmount = $subtotal * (DEFAULT_TAX_RATE / 100);
$shippingCost = ($subtotal >= FREE_SHIPPING_THRESHOLD) ? 0 : SHIPPING_COST;
$total = $subtotal + $taxAmount + $shippingCost;

$pageTitle = 'Shopping Cart';

include __DIR__ . '/../templates/header.php';
?>

<main class="container">
    <h1>Shopping Cart</h1>
    
    <?php if (empty($cartItems)): ?>
        <div class="empty-cart">
            <p>Your cart is empty.</p>
            <a href="index.php" class="btn">Continue Shopping</a>
        </div>
    <?php else: ?>
        <div class="cart-container">
            <div class="cart-items">
                <table class="cart-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Price</th>
                            <th>Quantity</th>
                            <th>Subtotal</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cartItems as $item): ?>
                        <tr data-variant-id="<?php echo $item['variant_id']; ?>">
                            <td class="product-info">
                                <div>
                                    <h4><?php echo htmlspecialchars($item['product_name']); ?></h4>
                                    <?php if (!empty($item['variant_name'])): ?>
                                        <small><?php echo htmlspecialchars($item['variant_name']); ?></small>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="product-price"><?php echo Formatter::currency($item['price']); ?></td>
                            <td class="product-quantity">
                                <input type="number" 
                                       class="quantity-input" 
                                       value="<?php echo $item['quantity']; ?>" 
                                       min="1" 
                                       max="99"
                                       data-variant-id="<?php echo $item['variant_id']; ?>">
                            </td>
                            <td class="product-subtotal"><?php echo Formatter::currency($item['subtotal']); ?></td>
                            <td class="product-remove">
                                <button class="remove-btn" data-variant-id="<?php echo $item['variant_id']; ?>">×</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="cart-summary">
                <h3>Order Summary</h3>
                <div class="summary-row">
                    <span>Subtotal:</span>
                    <span><?php echo Formatter::currency($subtotal); ?></span>
                </div>
                <div class="summary-row">
                    <span>Tax (<?php echo DEFAULT_TAX_RATE; ?>%):</span>
                    <span><?php echo Formatter::currency($taxAmount); ?></span>
                </div>
                <div class="summary-row">
                    <span>Shipping:</span>
                    <span>
                        <?php if ($shippingCost == 0): ?>
                            Free
                        <?php else: ?>
                            <?php echo Formatter::currency($shippingCost); ?>
                        <?php endif; ?>
                    </span>
                </div>
                <?php if ($subtotal < FREE_SHIPPING_THRESHOLD && $subtotal > 0): ?>
                    <div class="free-shipping-notice">
                        Add <?php echo Formatter::currency(FREE_SHIPPING_THRESHOLD - $subtotal); ?> more for free shipping!
                    </div>
                <?php endif; ?>
                <div class="summary-row total">
                    <span>Total:</span>
                    <span><?php echo Formatter::currency($total); ?></span>
                </div>
                <a href="./checkout.php" class="btn btn-primary btn-checkout">Proceed to Checkout</a>
                <a href="./index.php" class="btn btn-secondary">Continue Shopping</a>
            </div>
        </div>
    <?php endif; ?>
</main>

<script>
document.querySelectorAll('.quantity-input').forEach(input => {
    input.addEventListener('change', async function() {
        const variantId = this.dataset.variantId;
        const quantity = parseInt(this.value);
        
        const response = await fetch('../src/api/update_cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'update',
                variant_id: variantId,
                quantity: quantity
            })
        });
        
        if (response.ok) {
            location.reload();
        }
    });
});

document.querySelectorAll('.remove-btn').forEach(btn => {
    btn.addEventListener('click', async function() {
        const variantId = this.dataset.variantId;
        
        const response = await fetch('../src/api/update_cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'remove',
                variant_id: variantId
            })
        });
        
        if (response.ok) {
            location.reload();
        }
    });
});
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>