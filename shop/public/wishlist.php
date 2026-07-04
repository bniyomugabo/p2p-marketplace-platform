<?php
// /public/wishlist.php
// Customer Wishlist Page

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/init.php';

$customer = SessionManager::getCustomer();

if (!$customer) {
    header('Location: login.php');
    exit;
}

$pageTitle = 'My Wishlist - ' . SITE_NAME;

$wishlistModel = new Wishlist();
$wishlistItems = $wishlistModel->getWishlist($customer['id']);
$wishlistCount = $wishlistModel->getCount($customer['id']);
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
                <a href="account_orders.php">My Orders</a>
                <a href="account_profile.php">Profile Settings</a>
                <a href="account_password.php">Change Password</a>
                <a href="wishlist.php" class="active">Wishlist <span class="badge"><?php echo $wishlistCount; ?></span></a>
                <a href="logout.php">Logout</a>
            </nav>
        </div>
        
        <div class="account-content">
            <h1>My Wishlist</h1>
            
            <?php if (empty($wishlistItems)): ?>
                <div class="empty-wishlist">
                    <div class="empty-icon">❤️</div>
                    <p>Your wishlist is empty.</p>
                    <a href="index.php" class="btn btn-primary">Start Shopping</a>
                </div>
            <?php else: ?>
                <div class="wishlist-grid">
                    <?php foreach ($wishlistItems as $item): ?>
                        <div class="wishlist-item" data-product-id="<?php echo $item['product_id']; ?>">
                            <div class="wishlist-item-image">
                                <a href="product.php?id=<?php echo $item['product_id']; ?>">
                                    <img src="<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['product_name']); ?>">
                                </a>
                                <button class="remove-wishlist" data-product-id="<?php echo $item['product_id']; ?>" title="Remove from wishlist">
                                    ×
                                </button>
                            </div>
                            <div class="wishlist-item-info">
                                <div class="vendor-name"><?php echo htmlspecialchars($item['company_name']); ?></div>
                                <h3>
                                    <a href="product.php?id=<?php echo $item['product_id']; ?>">
                                        <?php echo htmlspecialchars($item['product_name']); ?>
                                    </a>
                                </h3>
                                <div class="product-price"><?php echo $item['price_range']; ?></div>
                                <?php if (!empty($item['description'])): ?>
                                    <p class="product-description"><?php echo htmlspecialchars(substr($item['description'], 0, 80)); ?>...</p>
                                <?php endif; ?>
                                <div class="wishlist-actions">
                                    <button class="btn-add-to-cart" data-product-id="<?php echo $item['product_id']; ?>">
                                        Add to Cart
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="wishlist-actions-bottom">
                    <button class="btn-clear-wishlist">Clear All Wishlist</button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<style>
.wishlist-grid {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.wishlist-item {
    display: flex;
    gap: 1.5rem;
    background: #f8f9fa;
    border-radius: 12px;
    padding: 1.5rem;
    position: relative;
    transition: box-shadow 0.2s;
}

.wishlist-item:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.wishlist-item-image {
    position: relative;
    width: 150px;
    height: 150px;
    flex-shrink: 0;
}

.wishlist-item-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 8px;
}

.remove-wishlist {
    position: absolute;
    top: -10px;
    right: -10px;
    width: 28px;
    height: 28px;
    background: #dc3545;
    color: white;
    border: none;
    border-radius: 50%;
    cursor: pointer;
    font-size: 1.2rem;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
}

.remove-wishlist:hover {
    background: #c82333;
}

.wishlist-item-info {
    flex: 1;
}

.vendor-name {
    font-size: 0.8rem;
    color: #888;
    margin-bottom: 0.5rem;
}

.wishlist-item-info h3 {
    margin-bottom: 0.5rem;
    font-size: 1.1rem;
}

.wishlist-item-info h3 a {
    color: #333;
    text-decoration: none;
}

.wishlist-item-info h3 a:hover {
    color: #2c7da0;
}

.product-price {
    font-size: 1.2rem;
    font-weight: bold;
    color: #2c7da0;
    margin-bottom: 0.5rem;
}

.product-description {
    color: #666;
    font-size: 0.85rem;
    margin-bottom: 1rem;
}

.wishlist-actions {
    display: flex;
    gap: 1rem;
}

.btn-add-to-cart {
    padding: 0.5rem 1rem;
    background: #2c7da0;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.2s;
}

.btn-add-to-cart:hover {
    background: #1f5068;
}

.wishlist-actions-bottom {
    margin-top: 2rem;
    text-align: center;
}

.btn-clear-wishlist {
    padding: 0.75rem 1.5rem;
    background: #dc3545;
    color: white;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.9rem;
    transition: background 0.2s;
}

.btn-clear-wishlist:hover {
    background: #c82333;
}

.empty-wishlist {
    text-align: center;
    padding: 4rem;
    background: #f8f9fa;
    border-radius: 12px;
}

.empty-icon {
    font-size: 4rem;
    margin-bottom: 1rem;
    opacity: 0.5;
}

.empty-wishlist p {
    margin-bottom: 1.5rem;
    color: #666;
}

.badge {
    display: inline-block;
    background: #2c7da0;
    color: white;
    border-radius: 20px;
    padding: 2px 8px;
    font-size: 0.7rem;
    margin-left: 0.5rem;
}

@media (max-width: 768px) {
    .wishlist-item {
        flex-direction: column;
        text-align: center;
    }
    
    .wishlist-item-image {
        margin: 0 auto;
    }
    
    .wishlist-actions {
        justify-content: center;
    }
}
</style>

<script>
document.querySelectorAll('.remove-wishlist').forEach(btn => {
    btn.addEventListener('click', async function() {
        const productId = this.dataset.productId;
        
        const response = await fetch('../src/api/wishlist_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'remove',
                product_id: productId
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            this.closest('.wishlist-item').remove();
            showNotification('Removed from wishlist', 'success');
            
            // Update badge count
            const badge = document.querySelector('.badge');
            if (badge) {
                const currentCount = parseInt(badge.textContent);
                badge.textContent = currentCount - 1;
            }
        } else {
            showNotification(result.message, 'error');
        }
    });
});

document.querySelectorAll('.btn-add-to-cart').forEach(btn => {
    btn.addEventListener('click', async function() {
        const productId = this.dataset.productId;
        
        // Fetch product details and add to cart
        const response = await fetch(`../src/api/get_product_detail.php?id=${productId}`);
        const data = await response.json();
        
        if (data.success && data.product.variants && data.product.variants.length > 0) {
            const variant = data.product.variants[0];
            
            await addToCart(
                variant.id,
                1,
                data.product.product_name,
                variant.variant_name || 'Standard',
                variant.selling_price
            );
        }
    });
});

document.querySelector('.btn-clear-wishlist')?.addEventListener('click', async function() {
    if (confirm('Are you sure you want to clear your entire wishlist?')) {
        const response = await fetch('../src/api/wishlist_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'clear' })
        });
        
        const result = await response.json();
        
        if (result.success) {
            location.reload();
        } else {
            showNotification(result.message, 'error');
        }
    }
});
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>