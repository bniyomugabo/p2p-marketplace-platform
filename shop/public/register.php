<?php
// /public/register.php
// Customer Registration Page

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/init.php';

SessionManager::logoutCustomer();
$pageTitle = 'Register - ' . SITE_NAME;
$error = SessionManager::getFlash('register_error');

include __DIR__ . '/../templates/header.php';
?>

<main class="container">
    <div class="auth-container">
        <h1>Create an Account</h1>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form id="register-form" class="auth-form" method="POST" action="../src/api/register_api.php">
            <input type="hidden" name="csrf_token" value="<?php echo SessionManager::generateCsrfToken(); ?>">
            
            <div class="form-group">
                <label for="full_name">Full Name *</label>
                <input type="text" id="full_name" name="full_name" required 
                       placeholder="Enter your full name">
            </div>
            
            <div class="form-group">
                <label for="email">Email Address *</label>
                <input type="email" id="email" name="email" required 
                       placeholder="Enter your email address">
                <div class="email-status"></div>
            </div>
            
            <div class="form-group">
                <label for="phone">Phone Number *</label>
                <input type="tel" id="phone" name="phone" required 
                       placeholder="Enter your phone number">
                <div class="phone-status"></div>
            </div>
            
            <div class="form-group">
                <label for="password">Password *</label>
                <input type="password" id="password" name="password" required 
                       placeholder="Create a password">
                <button type="button" class="toggle-password" data-target="password">Show</button>
                <div class="password-strength">
                    <div class="password-strength-bar" id="password-strength-bar"></div>
                </div>
                <div class="password-strength-text" id="password-strength-text"></div>
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Confirm Password *</label>
                <input type="password" id="confirm_password" name="confirm_password" required 
                       placeholder="Confirm your password">
                <button type="button" class="toggle-password" data-target="confirm_password">Show</button>
                <div class="password-match-status"></div>
            </div>
            
            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="terms" required>
                    I agree to the <a href="terms.php" target="_blank">Terms of Service</a> and 
                    <a href="privacy.php" target="_blank">Privacy Policy</a> *
                </label>
            </div>
            
            <button type="submit" class="btn btn-primary btn-block">Create Account</button>
        </form>
        
        <div class="auth-links">
            <p>Already have an account? <a href="login.php">Sign in</a></p>
        </div>
        
        <div class="auth-divider">
            <span>Or sign up with</span>
        </div>
        
        <div class="social-login">
            <button type="button" class="btn-social google" onclick="socialLogin('google')">
                <svg width="20" height="20" viewBox="0 0 24 24">
                    <path fill="#DB4437" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                    <path fill="#4285F4" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                    <path fill="#0F9D58" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                </svg>
                Google
            </button>
            <button type="button" class="btn-social facebook" onclick="socialLogin('facebook')">
                <svg width="20" height="20" viewBox="0 0 24 24">
                    <path fill="#4267B2" d="M22 12c0-5.52-4.48-10-10-10S2 6.48 2 12c0 4.84 3.44 8.87 8 9.8V15H8v-3h2V9.5C10 7.57 11.57 6 13.5 6H16v3h-2c-.55 0-1 .45-1 1v2h3v3h-3v6.95c5.05-.5 9-4.76 9-9.95z"/>
                </svg>
                Facebook
            </button>
        </div>
    </div>
</main>

<script>
// Password strength checker
const passwordInput = document.getElementById('password');
const confirmInput = document.getElementById('confirm_password');
const strengthBar = document.getElementById('password-strength-bar');
const strengthText = document.getElementById('password-strength-text');
const emailInput = document.getElementById('email');
const phoneInput = document.getElementById('phone');
const emailStatus = document.querySelector('.email-status');
const phoneStatus = document.querySelector('.phone-status');
const passwordMatchStatus = document.querySelector('.password-match-status');

// Password strength calculation
function checkPasswordStrength(password) {
    let score = 0;
    let feedback = [];
    
    if (password.length === 0) {
        return { score: 0, feedback: '' };
    }
    
    // Length check
    if (password.length >= 8) {
        score += 1;
    } else {
        feedback.push('at least 8 characters');
    }
    
    // Lowercase check
    if (/[a-z]/.test(password)) {
        score += 1;
    } else {
        feedback.push('lowercase letters');
    }
    
    // Uppercase check
    if (/[A-Z]/.test(password)) {
        score += 1;
    } else {
        feedback.push('uppercase letters');
    }
    
    // Number check
    if (/[0-9]/.test(password)) {
        score += 1;
    } else {
        feedback.push('numbers');
    }
    
    // Special character check
    if (/[^A-Za-z0-9]/.test(password)) {
        score += 1;
    } else {
        feedback.push('special characters');
    }
    
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
    
    let message = feedback.length > 0 ? `Weak: Add ${feedback.join(', ')}` : strength;
    
    return { score, strength, color, percentage, message };
}

// Update password strength indicator
passwordInput.addEventListener('input', function() {
    const result = checkPasswordStrength(this.value);
    
    strengthBar.style.width = result.percentage + '%';
    strengthBar.style.backgroundColor = result.color;
    strengthText.textContent = result.message;
    strengthText.style.color = result.color;
    
    // Check password match
    if (confirmInput.value) {
        checkPasswordMatch();
    }
});

// Check password match
function checkPasswordMatch() {
    if (passwordInput.value === confirmInput.value && passwordInput.value !== '') {
        passwordMatchStatus.innerHTML = '<span style="color:#28a745;">✓ Passwords match</span>';
        return true;
    } else if (confirmInput.value !== '') {
        passwordMatchStatus.innerHTML = '<span style="color:#dc3545;">✗ Passwords do not match</span>';
        return false;
    }
    return false;
}

confirmInput.addEventListener('input', checkPasswordMatch);

// Email availability check with debounce
let emailCheckTimeout;
emailInput.addEventListener('input', function() {
    clearTimeout(emailCheckTimeout);
    const email = this.value.trim();
    
    if (email.length < 3 || !email.includes('@')) {
        emailStatus.innerHTML = '';
        return;
    }
    
    emailCheckTimeout = setTimeout(async () => {
        try {
            const response = await fetch(`../src/api/check_email.php?email=${encodeURIComponent(email)}`);
            const result = await response.json();
            
            if (result.exists) {
                emailStatus.innerHTML = '<span style="color:#dc3545;">✗ Email already registered</span>';
                emailInput.classList.add('error');
            } else {
                emailStatus.innerHTML = '<span style="color:#28a745;">✓ Email available</span>';
                emailInput.classList.remove('error');
            }
        } catch (error) {
            console.error('Email check error:', error);
        }
    }, 500);
});

// Phone availability check
let phoneCheckTimeout;
phoneInput.addEventListener('input', function() {
    clearTimeout(phoneCheckTimeout);
    const phone = this.value.trim();
    
    if (phone.length < 8) {
        phoneStatus.innerHTML = '';
        return;
    }
    
    phoneCheckTimeout = setTimeout(async () => {
        try {
            const response = await fetch(`../src/api/check_phone.php?phone=${encodeURIComponent(phone)}`);
            const result = await response.json();
            
            if (result.exists) {
                phoneStatus.innerHTML = '<span style="color:#dc3545;">✗ Phone number already registered</span>';
                phoneInput.classList.add('error');
            } else {
                phoneStatus.innerHTML = '<span style="color:#28a745;">✓ Phone available</span>';
                phoneInput.classList.remove('error');
            }
        } catch (error) {
            console.error('Phone check error:', error);
        }
    }, 500);
});

// Form submission
document.getElementById('register-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    // Validate password match
    if (!checkPasswordMatch()) {
        showNotification('Passwords do not match', 'error');
        return;
    }
    
    // Validate password strength
    const passwordStrength = checkPasswordStrength(passwordInput.value);
    if (passwordStrength.score < 3) {
        showNotification('Please choose a stronger password', 'error');
        return;
    }
    
    // Validate terms
    const termsCheckbox = document.querySelector('input[name="terms"]');
    if (!termsCheckbox.checked) {
        showNotification('You must agree to the Terms of Service', 'error');
        return;
    }
    
    const formData = new FormData(this);
    const data = Object.fromEntries(formData);
    
    // Show loading state
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.textContent = 'Creating Account...';
    submitBtn.disabled = true;
    
    try {
        const response = await fetch('../src/api/register_api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            showNotification('Account created successfully! Redirecting to login...', 'success');
            setTimeout(() => {
                window.location.href = 'login.php?registered=1';
            }, 1500);
        } else {
            showNotification(result.message || 'Registration failed', 'error');
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
        }
    } catch (error) {
        console.error('Registration error:', error);
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

function socialLogin(provider) {
    window.location.href = `../src/api/social_login.php?provider=${provider}`;
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>