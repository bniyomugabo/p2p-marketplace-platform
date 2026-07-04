<?php
// /public/login.php
// Customer Login Page

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/init.php';
// Redirect if already logged in
if (SessionManager::isCustomerLoggedIn()) {
    header('Location: ./account.php');
    exit;
}


$pageTitle = 'Login - ' . SITE_NAME;
$error = SessionManager::getFlash('login_error');
$success = SessionManager::getFlash('register_success');

include __DIR__ . '/../templates/header.php';
?>

<main class="container">
    <div class="auth-container">
        <h1>Login to Your Account</h1>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <form id="login-form" class="auth-form" method="POST" action="../src/api/login_api.php">
            <input type="hidden" name="csrf_token" value="<?php echo SessionManager::generateCsrfToken(); ?>">
            
            <div class="form-group">
                <label for="email">Email Address *</label>
                <input type="email" id="email" name="email" required 
                       placeholder="Enter your email address">
            </div>
            
            <div class="form-group">
                <label for="password">Password *</label>
                <input type="password" id="password" name="password" required 
                       placeholder="Enter your password">
                <button type="button" class="toggle-password" data-target="password">Show</button>
            </div>
            
            <div class="form-options">
                <label class="checkbox-label">
                    <input type="checkbox" name="remember_me"> Remember Me
                </label>
                <a href="forgot_password.php" class="forgot-link">Forgot Password?</a>
            </div>
            
            <button type="submit" class="btn btn-primary btn-block">Login</button>
        </form>
        
        <div class="auth-links">
            <p>Don't have an account? <a href="register.php">Create an account</a></p>
        </div>
        
        <div class="auth-divider">
            <span>Or continue with</span>
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

<style>
.auth-container {
    max-width: 480px;
    margin: 3rem auto;
    background: white;
    border-radius: 12px;
    padding: 2rem;
    box-shadow: 0 2px 20px rgba(0,0,0,0.1);
}

.auth-container h1 {
    text-align: center;
    margin-bottom: 2rem;
    color: #333;
    font-size: 1.8rem;
}

.alert {
    padding: 0.75rem 1rem;
    border-radius: 6px;
    margin-bottom: 1.5rem;
    font-size: 0.9rem;
}

.alert-error {
    background: #fee;
    color: #c00;
    border: 1px solid #fcc;
}

.alert-success {
    background: #efe;
    color: #2a6e2a;
    border: 1px solid #cfc;
}

.auth-form .form-group {
    margin-bottom: 1.5rem;
    position: relative;
}

.auth-form label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    color: #555;
}

.auth-form input[type="email"],
.auth-form input[type="password"],
.auth-form input[type="text"],
.auth-form input[type="tel"] {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 1rem;
    transition: border-color 0.2s;
}

.auth-form input:focus {
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

.form-options {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
    font-size: 0.9rem;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    color: #666;
}

.forgot-link {
    color: #2c7da0;
    text-decoration: none;
}

.forgot-link:hover {
    text-decoration: underline;
}

.btn-block {
    width: 100%;
    padding: 0.85rem;
    font-size: 1rem;
    font-weight: 600;
}

.auth-links {
    text-align: center;
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid #eee;
    color: #666;
}

.auth-links a {
    color: #2c7da0;
    text-decoration: none;
    font-weight: 500;
}

.auth-links a:hover {
    text-decoration: underline;
}

.auth-divider {
    text-align: center;
    margin: 1.5rem 0;
    position: relative;
}

.auth-divider::before,
.auth-divider::after {
    content: '';
    position: absolute;
    top: 50%;
    width: calc(50% - 60px);
    height: 1px;
    background: #ddd;
}

.auth-divider::before {
    left: 0;
}

.auth-divider::after {
    right: 0;
}

.auth-divider span {
    background: white;
    padding: 0 1rem;
    color: #888;
    font-size: 0.85rem;
}

.social-login {
    display: flex;
    gap: 1rem;
    justify-content: center;
}

.btn-social {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.6rem 1.2rem;
    border: 1px solid #ddd;
    border-radius: 8px;
    background: white;
    cursor: pointer;
    transition: background 0.2s;
    font-size: 0.9rem;
}

.btn-social:hover {
    background: #f5f5f5;
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

@media (max-width: 576px) {
    .auth-container {
        margin: 1rem;
        padding: 1.5rem;
    }
}
</style>

<script>
document.getElementById('login-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const data = Object.fromEntries(formData);
    
    // Show loading state
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.textContent = 'Logging in...';
    submitBtn.disabled = true;
    
    try {
        const response = await fetch('../src/api/login_api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        console.log('Login API response:', result);
        
        if (result.success) {
            showNotification('Login successful! Redirecting...', 'success');
            setTimeout(() => {
                window.location.href = result.redirect || './account.php';
            }, 1000);
        } else {
            showNotification(result.message || 'Login failed', 'error');
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
        }
    } catch (error) {
        console.error('Login error:', error);
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