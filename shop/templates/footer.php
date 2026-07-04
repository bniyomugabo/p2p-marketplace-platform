<?php
// /templates/footer.php
?>
    </main>
    
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-section">
                    <h4><?php echo SITE_NAME; ?></h4>
                    <p>Your trusted multi-vendor marketplace</p>
                </div>
                <div class="footer-section">
                    <h4>Quick Links</h4>
                    <ul>
                        <li><a href="./">Home</a></li>
                        <li><a href="./cart.php">Cart</a></li>
                        <?php if (SessionManager::isCustomerLoggedIn()): ?>
                            <li><a href="./account.php">My Account</a></li>
                            <li><a href="./logout.php">Logout</a></li>
                        <?php else: ?>
                            <li><a href="./login.php">Login</a></li>
                            <li><a href="./register.php">Register</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="footer-section">
                    <h4>Contact</h4>
                    <p>Email: support@markethub.com</p>
                    <p>Phone: +250 788 123 456</p>
                </div>
            </div>
            <div class="footer-bottom">
                <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. All rights reserved.</p>
            </div>
        </div>
    </footer>
    
    <!-- Load JavaScript -->
    <script src="./assets/js/app.js"></script>
    
    <!-- Inline fallback for showNotification if app.js fails to load -->
    <script>
    // Fallback notification function in case app.js doesn't load
    if (typeof window.showNotification === 'undefined') {
        window.showNotification = function(message, type) {
            // Remove existing notifications
            const existing = document.querySelectorAll('.notification');
            existing.forEach(notification => notification.remove());
            
            const notification = document.createElement('div');
            notification.className = `notification notification-${type || 'info'}`;
            notification.textContent = message;
            
            // Add basic styling if not present
            notification.style.cssText = `
                position: fixed;
                bottom: 20px;
                right: 20px;
                padding: 12px 24px;
                border-radius: 8px;
                color: white;
                z-index: 10000;
                animation: slideIn 0.3s ease;
                background: ${type === 'success' ? '#28a745' : type === 'error' ? '#dc3545' : '#17a2b8'};
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.opacity = '0';
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        };
        
        // Add animation style if not present
        if (!document.querySelector('#notification-styles')) {
            const style = document.createElement('style');
            style.id = 'notification-styles';
            style.textContent = `
                @keyframes slideIn {
                    from {
                        transform: translateX(100%);
                        opacity: 0;
                    }
                    to {
                        transform: translateX(0);
                        opacity: 1;
                    }
                }
            `;
            document.head.appendChild(style);
        }
    }
    </script>
</body>
</html>