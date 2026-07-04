<?php
// /public/account_password.php
// Change Password Page

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/init.php';

$customer = SessionManager::getCustomer();

if (!$customer) {
    header('Location: login.php');
    exit;
}

$pageTitle = 'Change Password - ' . SITE_NAME;
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
                <a href="account_password.php" class="active">Change Password</a>
                <a href="wishlist.php">Wishlist</a>
                <a href="logout.php">Logout</a>
            </nav>
        </div>
        
        <div class="account-content">
            <h1>Change Password</h1>
            
            <form id="password-form" class="password-form">
                <input type="hidden" name="csrf_token" value="<?php echo SessionManager::generateCsrfToken(); ?>">
                
                <div class="form-group">
                    <label for="current_password">Current Password *</label>
                    <input type="password" id="current_password" name="current_password" required 
                           placeholder="Enter your current password">
                    <button type="button" class="toggle-password" data-target="current_password">Show</button>
                </div>
                
                <div class="form-group">
                    <label for="new_password">New Password *</label>
                    <input type="password" id="new_password" name="new_password" required 
                           placeholder="Enter new password">
                    <button type="button" class="toggle-password" data-target="new_password">Show</button>
                    <div class="password-strength">
                        <div class="password-strength-bar" id="password-strength-bar"></div>
                    </div>
                    <div class="password-strength-text" id="password-strength-text"></div>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password *</label>
                    <input type="password" id="confirm_password" name="confirm_password" required 
                           placeholder="Confirm new password">
                    <button type="button" class="toggle-password" data-target="confirm_password">Show</button>
                    <div class="password-match-status"></div>
                </div>
                
                <div class="password-requirements">
                    <h4>Password Requirements:</h4>
                    <ul>
                        <li id="req-length">✓ At least 8 characters</li>
                        <li id="req-lower">✓ At least one lowercase letter</li>
                        <li id="req-upper">✓ At least one uppercase letter</li>
                        <li id="req-number">✓ At least one number</li>
                        <li id="req-special">✓ At least one special character</li>
                    </ul>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Change Password</button>
                    <a href="account.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</main>

<style>
.password-form {
    max-width: 500px;
}

.form-group {
    margin-bottom: 1.5rem;
    position: relative;
}

.form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    color: #555;
}

.form-group input {
    width: 100%;
    padding: 0.75rem;
    padding-right: 60px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 1rem;
}

.form-group input:focus {
    outline: none;
    border-color: #2c7da0;
    box-shadow: 0 0 0 3px rgba(44,125,160,0.1);
}

.toggle-password {
    position: absolute;
    right: 12px;
    bottom: 12px;
    background: none;
    border: none;
    color: #888;
    cursor: pointer;
    font-size: 0.8rem;
}

.password-strength {
    margin-top: 0.5rem;
    height: 4px;
    background: #eee;
    border-radius: 2px;
    overflow: hidden;
}

.password-strength-bar {
    width: 0%;
    height: 100%;
    transition: width 0.3s, background 0.3s;
}

.password-strength-text {
    font-size: 0.75rem;
    margin-top: 0.25rem;
    color: #888;
}

.password-match-status {
    font-size: 0.75rem;
    margin-top: 0.25rem;
}

.password-requirements {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
}

.password-requirements h4 {
    margin-bottom: 0.5rem;
    font-size: 0.85rem;
    color: #666;
}

.password-requirements ul {
    list-style: none;
    margin: 0;
    padding: 0;
}

.password-requirements li {
    font-size: 0.8rem;
    padding: 0.25rem 0;
    color: #888;
}

.password-requirements li.valid {
    color: #28a745;
}

.password-requirements li.invalid {
    color: #dc3545;
}

.form-actions {
    display: flex;
    gap: 1rem;
    margin-top: 2rem;
}

@media (max-width: 768px) {
    .password-form {
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
// Password strength checker
function checkPasswordStrength(password) {
    let score = 0;
    let checks = {
        length: password.length >= 8,
        lower: /[a-z]/.test(password),
        upper: /[A-Z]/.test(password),
        number: /[0-9]/.test(password),
        special: /[^A-Za-z0-9]/.test(password)
    };
    
    // Update requirement list
    document.getElementById('req-length').className = checks.length ? 'valid' : 'invalid';
    document.getElementById('req-lower').className = checks.lower ? 'valid' : 'invalid';
    document.getElementById('req-upper').className = checks.upper ? 'valid' : 'invalid';
    document.getElementById('req-number').className = checks.number ? 'valid' : 'invalid';
    document.getElementById('req-special').className = checks.special ? 'valid' : 'invalid';
    
    // Calculate score
    Object.values(checks).forEach(check => { if (check) score++; });
    
    let strength = '';
    let color = '';
    let percentage = 0;
    
    switch(score) {
        case 0:
        case 1:
            strength = 'Very Weak';
            color = '#dc3545';
            percentage = 20;
            break;
        case 2:
            strength = 'Weak';
            color = '#ffc107';
            percentage = 40;
            break;
        case 3:
            strength = 'Fair';
            color = '#ffc107';
            percentage = 60;
            break;
        case 4:
            strength = 'Good';
            color = '#28a745';
            percentage = 80;
            break;
        case 5:
            strength = 'Strong';
            color = '#28a745';
            percentage = 100;
            break;
    }
    
    return { score, strength, color, percentage, checks };
}

const newPassword = document.getElementById('new_password');
const confirmPassword = document.getElementById('confirm_password');
const strengthBar = document.getElementById('password-strength-bar');
const strengthText = document.getElementById('password-strength-text');
const matchStatus = document.querySelector('.password-match-status');

newPassword.addEventListener('input', function() {
    const result = checkPasswordStrength(this.value);
    
    strengthBar.style.width = result.percentage + '%';
    strengthBar.style.backgroundColor = result.color;
    strengthText.textContent = result.strength;
    strengthText.style.color = result.color;
    
    if (confirmPassword.value) {
        checkPasswordMatch();
    }
});

function checkPasswordMatch() {
    if (newPassword.value === confirmPassword.value && newPassword.value !== '') {
        matchStatus.innerHTML = '<span style="color:#28a745;">✓ Passwords match</span>';
        return true;
    } else if (confirmPassword.value !== '') {
        matchStatus.innerHTML = '<span style="color:#dc3545;">✗ Passwords do not match</span>';
        return false;
    }
    return false;
}

confirmPassword.addEventListener('input', checkPasswordMatch);

document.getElementById('password-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const result = checkPasswordStrength(newPassword.value);
    
    if (result.score < 3) {
        showNotification('Please choose a stronger password', 'error');
        return;
    }
    
    if (!checkPasswordMatch()) {
        showNotification('Passwords do not match', 'error');
        return;
    }
    
    const formData = new FormData(this);
    const data = Object.fromEntries(formData);
    
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.textContent = 'Changing Password...';
    submitBtn.disabled = true;
    
    try {
        const response = await fetch('../src/api/change_password.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            showNotification('Password changed successfully! Please login again.', 'success');
            setTimeout(() => {
                window.location.href = 'logout.php';
            }, 2000);
        } else {
            showNotification(result.message, 'error');
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
        }
    } catch (error) {
        console.error('Password change error:', error);
        showNotification('Network error. Please try again.', 'error');
        submitBtn.textContent = originalText;
        submitBtn.disabled = false;
    }
});

// Toggle password visibility
document.querySelectorAll('.toggle-password').forEach(btn => {
    btn.addEventListener('click', function() {
        const targetId = this.dataset.target;
        const input = document.getElementById(targetId);
        if (input.type === 'password') {
            input.type = 'text';
            this.textContent = 'Hide';
        } else {
            input.type = 'password';
            this.textContent = 'Show';
        }
    });
});
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>