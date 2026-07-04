<?php
// /public/sell.php
// Sell Item Page - Create new P2P listing

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/init.php';

$customer = SessionManager::getCustomer();

if (!$customer) {
    SessionManager::setFlash('login_error', 'Please login to sell items');
    header('Location: login.php');
    exit;
}

$pageTitle = 'Sell an Item - ' . SITE_NAME;

// Get user's store
$storeModel = new CustomerStore();
$store = $storeModel->getStoreByCustomerId($customer['id']);

// If no store exists, create one
if (!$store) {
    $store = $storeModel->createStore($customer['id'], $customer['full_name'] . "'s Store");
}

// Get global categories
$shopProduct = new ShopProduct();
$categories = $shopProduct->getGlobalCategories();

$error = SessionManager::getFlash('sell_error');
$success = SessionManager::getFlash('sell_success');

include __DIR__ . '/../templates/header.php';
?>

<main class="container">
    <div class="sell-container">
        <div class="sell-header">
            <h1><i class="fas fa-plus-circle"></i> Sell an Item</h1>
            <p>List your item for sale on the marketplace</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <form id="sell-form" class="sell-form" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo SessionManager::generateCsrfToken(); ?>">
            <input type="hidden" name="store_id" value="<?php echo $store['id']; ?>">
            
            <div class="form-row two-columns">
                <div class="form-group">
                    <label for="title">Item Title *</label>
                    <input type="text" id="title" name="title" required 
                           placeholder="e.g., iPhone 12 Pro Max - Like New"
                           maxlength="150">
                    <small class="form-hint">Be specific and descriptive (max 150 characters)</small>
                </div>
                
                <div class="form-group">
                    <label for="global_category_id">Category *</label>
                    <select id="global_category_id" name="global_category_id" required>
                        <option value="">Select a category</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>">
                                <?php echo htmlspecialchars($category['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label for="description">Description *</label>
                <textarea id="description" name="description" rows="6" required 
                          placeholder="Describe your item in detail... Include brand, model, size, color, condition details, reason for selling, etc."></textarea>
                <small class="form-hint">Detailed descriptions help buyers make informed decisions</small>
            </div>
            
            <div class="form-row three-columns">
                <div class="form-group">
                    <label for="price">Price * (RWF)</label>
                    <input type="number" id="price" name="price" required 
                           step="100" min="100"
                           placeholder="e.g., 50000">
                </div>
                
                <div class="form-group">
                    <label for="condition">Condition *</label>
                    <select id="condition" name="condition" required>
                        <option value="">Select condition</option>
                        <option value="new">🆕 New - Never used, in original packaging</option>
                        <option value="like_new">✨ Like New - Used briefly, no signs of wear</option>
                        <option value="good">👍 Good - Light signs of use, fully functional</option>
                        <option value="fair">📦 Fair - Visible wear, fully functional</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="stock_quantity">Quantity</label>
                    <input type="number" id="stock_quantity" name="stock_quantity" 
                           value="1" min="1" max="10">
                    <small class="form-hint">How many of this item do you have? (max 10)</small>
                </div>
            </div>
            
            <div class="form-row two-columns">
                <div class="form-group">
                    <label for="city">City / Location *</label>
                    <input type="text" id="city" name="city" required 
                           placeholder="e.g., Kigali, Musanze, Huye"
                           value="<?php echo htmlspecialchars($customer['city'] ?? ''); ?>">
                    <small class="form-hint">For local pickup or delivery estimation</small>
                </div>
                
                <div class="form-group">
                    <label for="shipping_options">Shipping Options</label>
                    <select id="shipping_options" name="shipping_options">
                        <option value="local_pickup">📍 Local Pickup Only</option>
                        <option value="local_delivery">🚚 Local Delivery</option>
                        <option value="national">📦 National Shipping</option>
                    </select>
                </div>
            </div>
            
            <div class="form-group">
                <label for="primary_image">Primary Image *</label>
                <div class="image-upload-area" id="primary-image-area">
                    <input type="file" id="primary_image" name="primary_image" accept="image/*" required>
                    <div class="upload-preview" id="primary-preview"></div>
                    <div class="upload-placeholder">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <p>Click or drag to upload main image</p>
                        <small>JPEG, PNG, WEBP (Max 5MB)</small>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label>Additional Images (Optional)</label>
                <div class="image-upload-area multiple" id="additional-images-area">
                    <input type="file" id="additional_images" name="additional_images[]" accept="image/*" multiple>
                    <div class="upload-preview" id="additional-preview"></div>
                    <div class="upload-placeholder">
                        <i class="fas fa-images"></i>
                        <p>Add up to 5 additional photos</p>
                        <small>Show different angles, accessories, or defects</small>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="terms" required>
                    I confirm that this item is legal to sell and the description is accurate
                </label>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-large">
                    <i class="fas fa-plus-circle"></i> List Item for Sale
                </button>
                <a href="account.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</main>

<style>
.sell-container {
    max-width: 800px;
    margin: 2rem auto;
    background: white;
    border-radius: 16px;
    padding: 2rem;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
}

.sell-header {
    text-align: center;
    margin-bottom: 2rem;
}

.sell-header h1 {
    color: var(--primary-color);
    margin-bottom: 0.5rem;
}

.sell-header p {
    color: #666;
}

.form-row {
    display: grid;
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.form-row.two-columns {
    grid-template-columns: 1fr 1fr;
}

.form-row.three-columns {
    grid-template-columns: 1fr 1fr 1fr;
}

.form-group {
    margin-bottom: 1.5rem;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 600;
    color: #333;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    font-size: 1rem;
    transition: var(--transition);
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(44,125,160,0.1);
}

.form-hint {
    display: block;
    font-size: 0.75rem;
    color: #888;
    margin-top: 0.25rem;
}

/* Image Upload Area */
.image-upload-area {
    border: 2px dashed var(--border-color);
    border-radius: 12px;
    padding: 1.5rem;
    text-align: center;
    cursor: pointer;
    transition: var(--transition);
    position: relative;
}

.image-upload-area:hover {
    border-color: var(--primary-color);
    background: #f8f9fa;
}

.image-upload-area input {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
}

.upload-placeholder i {
    font-size: 3rem;
    color: #adb5bd;
    margin-bottom: 0.5rem;
}

.upload-placeholder p {
    margin: 0;
    color: #666;
}

.upload-placeholder small {
    color: #999;
}

.upload-preview {
    display: none;
    margin-top: 1rem;
}

.upload-preview.active {
    display: block;
}

.upload-preview img {
    max-width: 150px;
    max-height: 150px;
    border-radius: 8px;
    object-fit: cover;
}

.multiple .upload-preview {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.multiple .upload-preview img {
    width: 100px;
    height: 100px;
}

.alert {
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}

.alert-error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
}

.form-actions {
    display: flex;
    gap: 1rem;
    margin-top: 2rem;
}

.btn-large {
    flex: 1;
    padding: 1rem;
    font-size: 1rem;
}

@media (max-width: 768px) {
    .sell-container {
        margin: 1rem;
        padding: 1.5rem;
    }
    
    .form-row.two-columns,
    .form-row.three-columns {
        grid-template-columns: 1fr;
    }
    
    .form-actions {
        flex-direction: column;
    }
}
</style>

<script>
// Image preview for primary image
document.getElementById('primary_image').addEventListener('change', function(e) {
    const preview = document.getElementById('primary-preview');
    const file = e.target.files[0];
    
    if (file) {
        const reader = new FileReader();
        reader.onload = function(event) {
            preview.innerHTML = `<img src="${event.target.result}" alt="Preview">`;
            preview.classList.add('active');
        };
        reader.readAsDataURL(file);
    }
});

// Preview for multiple images
document.getElementById('additional_images').addEventListener('change', function(e) {
    const preview = document.getElementById('additional-preview');
    const files = Array.from(e.target.files);
    
    preview.innerHTML = '';
    files.slice(0, 5).forEach(file => {
        const reader = new FileReader();
        reader.onload = function(event) {
            const img = document.createElement('img');
            img.src = event.target.result;
            preview.appendChild(img);
        };
        reader.readAsDataURL(file);
    });
    preview.classList.add('active');
});

// Form submission
document.getElementById('sell-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Listing...';
    submitBtn.disabled = true;
    
    try {
        const response = await fetch('../src/api/sell_item.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showNotification('Item listed successfully!', 'success');
            setTimeout(() => {
                window.location.href = 'my_store.php';
            }, 1500);
        } else {
            showNotification(result.message || 'Failed to list item', 'error');
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    } catch (error) {
        console.error('Sell error:', error);
        showNotification('Network error. Please try again.', 'error');
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
});
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>


