<?php
// /public/my_store.php
// My Store Management Page

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/init.php';

$customer = SessionManager::getCustomer();

if (!$customer) {
    SessionManager::setFlash('login_error', 'Please login to manage your store');
    header('Location: login.php');
    exit;
}

$pageTitle = 'My Store - ' . SITE_NAME;

// Get user's store
$storeModel = new CustomerStore();
$store = $storeModel->getStoreByCustomerId($customer['id']);

// If no store exists, create one
if (!$store) {
    $store = $storeModel->createStore($customer['id'], $customer['full_name'] . "'s Store");
}

// Get store products
$storeProducts = $storeModel->getStoreProducts($store['id']);

// Get store stats
$stats = $storeModel->getStoreStats($store['id']);

$error = SessionManager::getFlash('store_error');
$success = SessionManager::getFlash('store_success');

include __DIR__ . '/../templates/header.php';
?>

<main class="container">
    <div class="my-store-container">
        <!-- Store Header -->
        <div class="store-header">
            <div class="store-info">
                <div class="store-avatar">
                    <i class="fas fa-store"></i>
                </div>
                <div class="store-details">
                    <h1><?php echo htmlspecialchars($store['store_name']); ?></h1>
                    <p class="store-slug">@<?php echo htmlspecialchars($store['slug']); ?></p>
                    <p class="store-description"><?php echo htmlspecialchars($store['description'] ?? 'No description yet'); ?></p>
                    <div class="store-stats">
                        <span><i class="fas fa-box"></i> <?php echo $stats['total_products']; ?> listings</span>
                        <span><i class="fas fa-shopping-cart"></i> <?php echo $stats['total_sales']; ?> sales</span>
                        <span><i class="fas fa-star"></i> <?php echo $stats['avg_rating']; ?> rating</span>
                    </div>
                </div>
            </div>
            <div class="store-actions">
                <button class="btn btn-secondary" onclick="editStore()">
                    <i class="fas fa-edit"></i> Edit Store
                </button>
                <a href="sell.php" class="btn btn-primary">
                    <i class="fas fa-plus-circle"></i> Sell Item
                </a>
            </div>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <!-- Store Products -->
        <div class="store-products">
            <div class="section-header">
                <h2><i class="fas fa-box"></i> My Listings</h2>
                <div class="filter-buttons">
                    <button class="filter-btn active" data-filter="all">All</button>
                    <button class="filter-btn" data-filter="active">Active</button>
                    <button class="filter-btn" data-filter="sold">Sold Out</button>
                </div>
            </div>
            
            <?php if (empty($storeProducts)): ?>
                <div class="empty-store">
                    <i class="fas fa-store-slash"></i>
                    <h3>No listings yet</h3>
                    <p>Start selling by listing your first item!</p>
                    <a href="sell.php" class="btn btn-primary">Sell an Item</a>
                </div>
            <?php else: ?>
                <div class="products-grid">
                    <?php foreach ($storeProducts as $product): ?>
                        <div class="product-card" data-status="<?php echo $product['stock_quantity'] > 0 ? 'active' : 'sold'; ?>">
                            <div class="product-image">
                                <img src="<?php echo htmlspecialchars($product['primary_image_url'] ?? '/assets/img/placeholder.jpg'); ?>" 
                                     alt="<?php echo htmlspecialchars($product['title']); ?>">
                                <?php if ($product['stock_quantity'] == 0): ?>
                                    <span class="sold-badge">SOLD</span>
                                <?php endif; ?>
                            </div>
                            <div class="product-info">
                                <h3><?php echo htmlspecialchars($product['title']); ?></h3>
                                <p class="price"><?php echo number_format($product['price'], 0, ',', ' ') . ' ' . DEFAULT_CURRENCY; ?></p>
                                <p class="condition"><?php echo ucfirst(str_replace('_', ' ', $product['condition'])); ?></p>
                                <p class="stock">Stock: <?php echo $product['stock_quantity']; ?></p>
                                <div class="product-actions">
                                    <a href="edit_listing.php?id=<?php echo $product['id']; ?>" class="btn-edit">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <button class="btn-delete" data-id="<?php echo $product['id']; ?>" data-title="<?php echo htmlspecialchars($product['title']); ?>">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                    <a href="product.php?id=<?php echo $product['id']; ?>&type=peer" class="btn-view" target="_blank">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Orders Section -->
        <div class="store-orders">
            <h2><i class="fas fa-shopping-cart"></i> Recent Orders</h2>
            
            <?php
            $orders = $storeModel->getStoreOrders($store['id']);
            if (empty($orders)): ?>
                <div class="empty-orders">
                    <i class="fas fa-truck"></i>
                    <p>No orders yet. When someone buys your items, they'll appear here.</p>
                </div>
            <?php else: ?>
                <div class="orders-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Buyer</th>
                                <th>Item</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($order['order_number']); ?></td>
                                    <td><?php echo htmlspecialchars($order['buyer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($order['product_title']); ?></td>
                                    <td><?php echo number_format($order['total_amount'], 0, ',', ' ') . ' ' . DEFAULT_CURRENCY; ?></td>
                                    <td>
                                        <span class="order-status status-<?php echo $order['payment_status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $order['payment_status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="order_details.php?id=<?php echo $order['id']; ?>&type=p2p" class="btn-sm">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- Edit Store Modal -->
<div id="edit-store-modal" class="modal" style="display: none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit Store Information</h3>
            <button class="close-modal">&times;</button>
        </div>
        <form id="edit-store-form">
            <input type="hidden" name="csrf_token" value="<?php echo SessionManager::generateCsrfToken(); ?>">
            <input type="hidden" name="store_id" value="<?php echo $store['id']; ?>">
            
            <div class="form-group">
                <label for="store_name">Store Name</label>
                <input type="text" id="store_name" name="store_name" 
                       value="<?php echo htmlspecialchars($store['store_name']); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="store_description">Store Description</label>
                <textarea id="store_description" name="description" rows="4"><?php echo htmlspecialchars($store['description'] ?? ''); ?></textarea>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <button type="button" class="btn btn-secondary cancel-modal">Cancel</button>
            </div>
        </form>
    </div>
</div>

<style>
.my-store-container {
    margin: 2rem 0;
}

/* Store Header */
.store-header {
    background: white;
    border-radius: 16px;
    padding: 2rem;
    margin-bottom: 2rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1.5rem;
    box-shadow: var(--shadow-sm);
}

.store-info {
    display: flex;
    gap: 1.5rem;
    align-items: center;
    flex-wrap: wrap;
}

.store-avatar {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.5rem;
    color: white;
}

.store-details h1 {
    margin: 0 0 0.25rem;
    font-size: 1.5rem;
}

.store-slug {
    color: #888;
    font-size: 0.85rem;
    margin-bottom: 0.5rem;
}

.store-description {
    color: #666;
    margin-bottom: 1rem;
}

.store-stats {
    display: flex;
    gap: 1.5rem;
}

.store-stats span {
    font-size: 0.85rem;
    color: #666;
}

.store-stats i {
    color: var(--primary-color);
    margin-right: 0.25rem;
}

.store-actions {
    display: flex;
    gap: 1rem;
}

/* Section Header */
.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
    gap: 1rem;
}

.section-header h2 {
    font-size: 1.3rem;
}

.filter-buttons {
    display: flex;
    gap: 0.5rem;
}

.filter-btn {
    padding: 0.4rem 1rem;
    background: #f0f0f0;
    border: none;
    border-radius: 20px;
    cursor: pointer;
    transition: var(--transition);
}

.filter-btn.active {
    background: var(--primary-color);
    color: white;
}

/* Product Card */
.product-card {
    display: flex;
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    transition: var(--transition);
}

.product-card:hover {
    box-shadow: var(--shadow-md);
}

.product-image {
    width: 120px;
    height: 120px;
    position: relative;
    flex-shrink: 0;
}

.product-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.sold-badge {
    position: absolute;
    top: 8px;
    left: 8px;
    background: #dc3545;
    color: white;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 0.7rem;
    font-weight: bold;
}

.product-info {
    flex: 1;
    padding: 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.product-info h3 {
    margin: 0;
    font-size: 1rem;
}

.price {
    font-size: 1.2rem;
    font-weight: bold;
    color: var(--primary-color);
}

.condition {
    font-size: 0.8rem;
    color: #888;
}

.stock {
    font-size: 0.8rem;
}

.product-actions {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.5rem;
}

.product-actions a, .product-actions button {
    padding: 0.3rem 0.8rem;
    border-radius: 5px;
    text-decoration: none;
    font-size: 0.8rem;
    cursor: pointer;
    transition: var(--transition);
}

.btn-edit {
    background: #ffc107;
    color: #333;
    border: none;
}

.btn-edit:hover {
    background: #e0a800;
}

.btn-delete {
    background: #dc3545;
    color: white;
    border: none;
}

.btn-delete:hover {
    background: #c82333;
}

.btn-view {
    background: #6c757d;
    color: white;
}

.btn-view:hover {
    background: #5a6268;
}

/* Orders Table */
.store-orders {
    background: white;
    border-radius: 16px;
    padding: 1.5rem;
    margin-top: 2rem;
    box-shadow: var(--shadow-sm);
}

.store-orders h2 {
    margin-bottom: 1.5rem;
    font-size: 1.3rem;
}

.orders-table {
    overflow-x: auto;
}

.orders-table table {
    width: 100%;
    border-collapse: collapse;
}

.orders-table th,
.orders-table td {
    padding: 0.75rem;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
}

.order-status {
    display: inline-block;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
    font-size: 0.75rem;
}

.status-held_in_escrow {
    background: #fff3cd;
    color: #856404;
}

.status-released_to_seller {
    background: #d4edda;
    color: #155724;
}

/* Empty States */
.empty-store, .empty-orders {
    text-align: center;
    padding: 4rem;
    background: white;
    border-radius: 12px;
}

.empty-store i, .empty-orders i {
    font-size: 4rem;
    color: #ccc;
    margin-bottom: 1rem;
}

.empty-store h3 {
    margin-bottom: 0.5rem;
}

.empty-store p, .empty-orders p {
    color: #888;
    margin-bottom: 1.5rem;
}

/* Modal */
.modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 2000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-content {
    background: white;
    border-radius: 16px;
    width: 90%;
    max-width: 500px;
    animation: modalSlideIn 0.3s ease;
}

@keyframes modalSlideIn {
    from {
        transform: translateY(-50px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
}

.modal-header h3 {
    margin: 0;
}

.close-modal {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: #999;
}

.modal-content .form-group {
    padding: 0 1.5rem;
}

.modal-content .form-actions {
    padding: 1rem 1.5rem;
    border-top: 1px solid var(--border-color);
}

@media (max-width: 768px) {
    .store-header {
        flex-direction: column;
        text-align: center;
    }
    
    .store-info {
        flex-direction: column;
        text-align: center;
    }
    
    .store-stats {
        justify-content: center;
    }
    
    .product-card {
        flex-direction: column;
    }
    
    .product-image {
        width: 100%;
        height: 150px;
    }
    
    .section-header {
        flex-direction: column;
    }
}
</style>

<script>
// Filter products
document.querySelectorAll('.filter-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const filter = this.dataset.filter;
        
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        
        document.querySelectorAll('.product-card').forEach(card => {
            if (filter === 'all') {
                card.style.display = 'flex';
            } else {
                card.style.display = card.dataset.status === filter ? 'flex' : 'none';
            }
        });
    });
});

// Delete product
document.querySelectorAll('.btn-delete').forEach(btn => {
    btn.addEventListener('click', async function() {
        const productId = this.dataset.id;
        const productTitle = this.dataset.title;
        
        if (confirm(`Are you sure you want to delete "${productTitle}"? This action cannot be undone.`)) {
            try {
                const response = await fetch('../src/api/delete_listing.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ product_id: productId })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification('Listing deleted successfully', 'success');
                    location.reload();
                } else {
                    showNotification(result.message, 'error');
                }
            } catch (error) {
                console.error('Delete error:', error);
                showNotification('Failed to delete listing', 'error');
            }
        }
    });
});

// Edit store modal
function editStore() {
    const modal = document.getElementById('edit-store-modal');
    modal.style.display = 'flex';
}

document.querySelectorAll('.close-modal, .cancel-modal').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('edit-store-modal').style.display = 'none';
    });
});

document.getElementById('edit-store-form')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const data = Object.fromEntries(formData);
    
    try {
        const response = await fetch('../src/api/update_store.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            showNotification('Store updated successfully', 'success');
            location.reload();
        } else {
            showNotification(result.message, 'error');
        }
    } catch (error) {
        console.error('Update store error:', error);
        showNotification('Failed to update store', 'error');
    }
});
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>