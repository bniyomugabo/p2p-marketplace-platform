<?php
// /public/account_profile.php
// Customer Profile Settings

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/init.php';

$customer = SessionManager::getCustomer();

if (!$customer) {
    header('Location: login.php');
    exit;
}

$pageTitle = 'Profile Settings - ' . SITE_NAME;
$success = SessionManager::getFlash('profile_success');
$error = SessionManager::getFlash('profile_error');

$customerModel = new Customer();
$customerData = $customerModel->getCustomerById($customer['id']);
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
                <a href="account_profile.php" class="active">Profile Settings</a>
                <a href="account_password.php">Change Password</a>
                <a href="wishlist.php">Wishlist</a>
                <a href="logout.php">Logout</a>
            </nav>
        </div>
        
        <div class="account-content">
            <h1>Profile Settings</h1>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form id="profile-form" class="profile-form">
                <input type="hidden" name="csrf_token" value="<?php echo SessionManager::generateCsrfToken(); ?>">
                
                <div class="form-group">
                    <label for="full_name">Full Name *</label>
                    <input type="text" id="full_name" name="full_name" 
                           value="<?php echo htmlspecialchars($customerData['full_name'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="email">Email Address *</label>
                    <input type="email" id="email" name="email" 
                           value="<?php echo htmlspecialchars($customerData['email'] ?? ''); ?>" required readonly disabled>
                    <small class="form-hint">Email cannot be changed</small>
                </div>
                
                <div class="form-group">
                    <label for="phone">Phone Number *</label>
                    <input type="tel" id="phone" name="phone" 
                           value="<?php echo htmlspecialchars($customerData['phone'] ?? ''); ?>" required>
                    <div class="phone-status"></div>
                </div>
                
                <div class="form-group">
                    <label for="address">Address</label>
                    <textarea id="address" name="address" rows="3" 
                              placeholder="Enter your full address"><?php echo htmlspecialchars($customerData['address'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-group">
                    <label for="city">City</label>
                    <input type="text" id="city" name="city" 
                           value="<?php echo htmlspecialchars($customerData['city'] ?? ''); ?>"
                           placeholder="Enter your city">
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <a href="account.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</main>

<style>
.profile-form {
    max-width: 600px;
}

.form-group {
    margin-bottom: 1.5rem;
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

.form-group input:disabled {
    background: #f5f5f5;
    cursor: not-allowed;
}

.form-hint {
    display: block;
    font-size: 0.75rem;
    color: #888;
    margin-top: 0.25rem;
}

.form-actions {
    display: flex;
    gap: 1rem;
    margin-top: 2rem;
}

.alert {
    padding: 0.75rem 1rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert-error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.phone-status {
    font-size: 0.75rem;
    margin-top: 0.25rem;
}

@media (max-width: 768px) {
    .profile-form {
        max-width: 100%;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .form-actions .btn {
        width: 100%;
        text-align: center;
    }
}
</style>

<script>
let phoneCheckTimeout;

document.getElementById('phone').addEventListener('input', function() {
    clearTimeout(phoneCheckTimeout);
    const phone = this.value.trim();
    const phoneStatus = document.querySelector('.phone-status');
    
    if (phone.length < 8) {
        phoneStatus.innerHTML = '';
        return;
    }
    
    phoneCheckTimeout = setTimeout(async () => {
        try {
            const response = await fetch(`../src/api/check_phone.php?phone=${encodeURIComponent(phone)}&exclude_id=<?php echo $customer['id']; ?>`);
            const result = await response.json();
            
            if (result.exists) {
                phoneStatus.innerHTML = '<span style="color:#dc3545;">✗ Phone number already registered</span>';
                document.getElementById('phone').classList.add('error');
            } else {
                phoneStatus.innerHTML = '<span style="color:#28a745;">✓ Phone available</span>';
                document.getElementById('phone').classList.remove('error');
            }
        } catch (error) {
            console.error('Phone check error:', error);
        }
    }, 500);
});

document.getElementById('profile-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const data = Object.fromEntries(formData);
    
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.textContent = 'Saving...';
    submitBtn.disabled = true;
    
    try {
        const response = await fetch('../src/api/update_profile.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            showNotification('Profile updated successfully!', 'success');
            setTimeout(() => location.reload(), 1500);
        } else {
            showNotification(result.message, 'error');
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
        }
    } catch (error) {
        console.error('Profile update error:', error);
        showNotification('Network error. Please try again.', 'error');
        submitBtn.textContent = originalText;
        submitBtn.disabled = false;
    }
});
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>