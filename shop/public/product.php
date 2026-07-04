<?php
// /public/product.php
// Hybrid Product Detail Page (Corporate & P2P)

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/init.php';

$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$productType = isset($_GET['type']) ? $_GET['type'] : 'corporate';

if ($productId <= 0) {
    header('Location: index.php');
    exit;
}

$shopProduct = new ShopProduct();
$product = $shopProduct->getProductById($productId, $productType);

if (!$product) {
    header('HTTP/1.0 404 Not Found');
    echo "<h1>Product Not Found</h1>";
    exit;
}

$pageTitle = $product['title'] . ' - ' . SITE_NAME;
$isLoggedIn = SessionManager::isCustomerLoggedIn();
$customer = SessionManager::getCustomer();

include __DIR__ . '/../templates/header.php';
?>

<main class="container">
    <div class="product-detail">
        <div class="product-gallery">
            <div class="main-image">
                <img id="main-product-image" 
                     src="<?php echo htmlspecialchars($product['images'][0]['image_url'] ?? '/assets/img/placeholder.jpg'); ?>" 
                     alt="<?php echo htmlspecialchars($product['title']); ?>">
            </div>
            <?php if (count($product['images']) > 1): ?>
                <div class="thumbnail-gallery">
                    <?php foreach ($product['images'] as $index => $image): ?>
                        <img src="<?php echo htmlspecialchars($image['image_url']); ?>" 
                             alt="Thumbnail <?php echo $index + 1; ?>"
                             onclick="document.getElementById('main-product-image').src = this.src">
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="product-info">
            <div class="seller-info">
                <span class="seller-badge seller-<?php echo $product['seller_type']; ?>">
                    <?php echo $product['seller_type'] === 'corporate' ? '🏢 Official Store' : '👤 Individual Seller'; ?>
                </span>
                <a href="seller.php?id=<?php echo $product['seller_id']; ?>&type=<?php echo $product['seller_type']; ?>" class="seller-name">
                    <?php echo htmlspecialchars($product['seller_name']); ?>
                </a>
                <?php if ($product['seller_type'] === 'peer' && !empty($product['city'])): ?>
                    <span class="seller-location">📍 <?php echo htmlspecialchars($product['city']); ?></span>
                <?php endif; ?>
            </div>
            
            <h1><?php echo htmlspecialchars($product['title']); ?></h1>
            
            <?php if ($product['seller_type'] === 'peer'): ?>
                <div class="peer-badges">
                    <?php echo $product['condition_badge']; ?>
                    <span class="stock-badge <?php echo $product['in_stock'] ? 'in-stock' : 'out-of-stock'; ?>">
                        <?php echo $product['in_stock'] ? '✓ In Stock' : '✗ Out of Stock'; ?>
                    </span>
                </div>
            <?php endif; ?>
            
            <div class="price">
                <?php if (isset($product['formatted_price'])): ?>
                    <?php echo $product['formatted_price']; ?>
                <?php endif; ?>
                <?php if ($product['seller_type'] === 'corporate' && $product['min_price'] != $product['max_price']): ?>
                    <span class="price-range">(from <?php echo $product['formatted_price']; ?>)</span>
                <?php endif; ?>
            </div>

            <?php if ($product['seller_type'] === 'peer' && !empty($product['description'])): ?>
                <div class="description">
                    <h3>Description</h3>
                    <p><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
                </div>
                
                <?php if (!empty($product['condition_label'])): ?>
                    <div class="condition-info">
                        <h3>Condition</h3>
                        <p><?php echo htmlspecialchars($product['condition_label']); ?></p>
                    </div>
                <?php endif; ?>
                
            <?php else: ?>
                <?php if (!empty($product['description'])): ?>
                    <div class="description">
                        <h3>Description</h3>
                        <p><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($product['long_description'])): ?>
                    <div class="long-description">
                        <h3>Details</h3>
                        <div><?php echo nl2br(htmlspecialchars($product['long_description'])); ?></div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($product['seller_type'] === 'corporate' && !empty($product['variants'])): ?>
                <div class="product-variants">
                    <h3>Select Option</h3>
                    <select id="variant-select" class="variant-select">
                        <?php foreach ($product['variants'] as $variant): ?>
                            <option value="<?php echo $variant['id']; ?>" 
                                    data-price="<?php echo $variant['price']; ?>"
                                    data-stock="<?php echo $variant['stock_quantity']; ?>"
                                    data-instock="<?php echo $variant['in_stock'] ? 'true' : 'false'; ?>"
                                    data-variant-name="<?php echo htmlspecialchars($variant['variant_name'] ?? 'Standard'); ?>">
                                <?php echo htmlspecialchars($variant['variant_name'] ?? 'Standard'); ?> - 
                                <?php echo $variant['formatted_price']; ?>
                                <?php echo $variant['in_stock'] ? '' : ' (Out of Stock)'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="quantity-selector">
                    <label for="quantity">Quantity:</label>
                    <input type="number" id="quantity" name="quantity" value="1" min="1" max="99">
                </div>
                
                <div class="stock-status" id="stock-status"></div>
                
                <div class="product-actions">
                    <button id="add-to-cart-btn" class="btn btn-primary btn-large">Add to Cart</button>
                </div>
                
            <?php elseif ($product['seller_type'] === 'peer' && $product['in_stock']): ?>
                <div class="quantity-selector">
                    <label for="quantity">Quantity:</label>
                    <input type="number" id="quantity" name="quantity" value="1" min="1" max="<?php echo min(10, $product['stock_quantity']); ?>">
                </div>
                
                <div class="product-actions">
                    <button id="add-to-cart-peer" class="btn btn-primary btn-large" 
                            data-product-id="<?php echo $product['id']; ?>"
                            data-title="<?php echo htmlspecialchars($product['title']); ?>"
                            data-price="<?php echo $product['price']; ?>"
                            data-seller-id="<?php echo $product['seller_id']; ?>"
                            data-seller-name="<?php echo htmlspecialchars($product['seller_name']); ?>">
                        Add to Cart
                    </button>
                    
                    <button id="chat-with-seller" class="btn btn-secondary btn-large" 
                            data-seller-id="<?php echo $product['seller_id']; ?>"
                            data-product-id="<?php echo $product['id']; ?>">
                        💬 Chat with Seller
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<style>
.seller-info {
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
}

.seller-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 500;
}

.seller-corporate {
    background: #2c7da0;
    color: white;
}

.seller-peer {
    background: #6f42c1;
    color: white;
}

.seller-name {
    color: #2c7da0;
    text-decoration: none;
    font-weight: 500;
}

.seller-name:hover {
    text-decoration: underline;
}

.seller-location {
    font-size: 0.8rem;
    color: #666;
}

.peer-badges {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.condition-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 500;
}

.condition-new { background: #28a745; color: white; }
.condition-like-new { background: #17a2b8; color: white; }
.condition-good { background: #ffc107; color: #333; }
.condition-fair { background: #fd7e14; color: white; }

.stock-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 500;
}

.stock-badge.in-stock { background: #28a745; color: white; }
.stock-badge.out-of-stock { background: #dc3545; color: white; }

.price {
    font-size: 1.8rem;
    font-weight: bold;
    color: #2c7da0;
    margin-bottom: 1.5rem;
}

.price-range {
    font-size: 0.9rem;
    color: #888;
    font-weight: normal;
}

.condition-info,
.description,
.long-description {
    margin-bottom: 1.5rem;
}

.condition-info h3,
.description h3,
.long-description h3 {
    font-size: 1rem;
    color: #666;
    margin-bottom: 0.5rem;
}

.product-actions {
    display: flex;
    gap: 1rem;
    margin-top: 1.5rem;
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background: #5a6268;
}

@media (max-width: 768px) {
    .product-actions {
        flex-direction: column;
    }
    
    .product-actions button {
        width: 100%;
    }
}
</style>

<script>
// Corporate product add to cart
const addToCartBtn = document.getElementById('add-to-cart-btn');
if (addToCartBtn) {
    const variantSelect = document.getElementById('variant-select');
    const quantityInput = document.getElementById('quantity');
    const stockStatus = document.getElementById('stock-status');
    
    function updateStockStatus() {
        if (!variantSelect) return;
        const selectedOption = variantSelect.options[variantSelect.selectedIndex];
        const inStock = selectedOption.dataset.instock === 'true';
        const stockQty = parseInt(selectedOption.dataset.stock);
        const quantity = parseInt(quantityInput.value);
        
        if (!inStock) {
            stockStatus.innerHTML = '<span class="out-of-stock">Out of Stock</span>';
            addToCartBtn.disabled = true;
        } else if (stockQty < quantity) {
            stockStatus.innerHTML = `<span class="low-stock">Only ${stockQty} units available</span>`;
            addToCartBtn.disabled = true;
        } else {
            stockStatus.innerHTML = '<span class="in-stock">In Stock</span>';
            addToCartBtn.disabled = false;
        }
    }
    
    if (variantSelect) {
        variantSelect.addEventListener('change', updateStockStatus);
        quantityInput.addEventListener('input', updateStockStatus);
        updateStockStatus();
        
        addToCartBtn.addEventListener('click', async function() {
            const selectedOption = variantSelect.options[variantSelect.selectedIndex];
            const variantId = selectedOption.value;
            const variantName = selectedOption.dataset.variantName;
            const price = parseFloat(selectedOption.dataset.price);
            const quantity = parseInt(quantityInput.value);
            const productName = <?php echo json_encode($product['title']); ?>;
            
            const response = await fetch('../src/api/update_cart.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'add',
                    variant_id: variantId,
                    quantity: quantity,
                    product_name: productName,
                    variant_name: variantName,
                    price: price,
                    seller_type: 'corporate'
                })
            });
            
            const result = await response.json();
            if (result.success) {
                showNotification('Product added to cart!', 'success');
                updateCartBadge(result.cart_count);
            } else {
                showNotification(result.message, 'error');
            }
        });
    }
}

// Peer product add to cart
const peerAddToCart = document.getElementById('add-to-cart-peer');
if (peerAddToCart) {
    peerAddToCart.addEventListener('click', async function() {
        const productId = this.dataset.productId;
        const title = this.dataset.title;
        const price = parseFloat(this.dataset.price);
        const sellerId = this.dataset.sellerId;
        const sellerName = this.dataset.sellerName;
        const quantity = parseInt(document.getElementById('quantity')?.value || 1);
        
        const response = await fetch('../src/api/update_cart.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'add',
                variant_id: productId,
                quantity: quantity,
                product_name: title,
                variant_name: null,
                price: price,
                seller_type: 'peer',
                seller_id: sellerId,
                seller_name: sellerName
            })
        });
        
        const result = await response.json();
        if (result.success) {
            showNotification('Item added to cart!', 'success');
            updateCartBadge(result.cart_count);
        } else {
            showNotification(result.message, 'error');
        }
    });
}

// Chat with seller
const chatButton = document.getElementById('chat-with-seller');
if (chatButton) {
    chatButton.addEventListener('click', async function() {
        <?php if (!$isLoggedIn): ?>
            showNotification('Please login to chat with seller', 'error');
            setTimeout(() => {
                window.location.href = 'login.php?redirect=product.php?id=<?php echo $productId; ?>&type=<?php echo $productType; ?>';
            }, 1500);
            return;
        <?php endif; ?>
        
        const sellerId = this.dataset.sellerId;
        const productId = this.dataset.productId;
        
        const response = await fetch('../src/api/send_message.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'create_room',
                seller_type: 'peer',
                seller_id: sellerId,
                product_id: productId,
                product_type: 'p2p'
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            window.location.href = `chat.php?room_id=${result.room_id}`;
        } else if (result.message.includes('login')) {
            window.location.href = 'login.php';
        } else {
            showNotification(result.message, 'error');
        }
    });
}

function showNotification(message, type) {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.textContent = message;
    document.body.appendChild(notification);
    setTimeout(() => notification.remove(), 3000);
}

function updateCartBadge(count) {
    const badge = document.getElementById('cart-badge');
    if (badge) {
        badge.textContent = count;
        badge.style.display = count > 0 ? 'inline-block' : 'none';
    }
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>