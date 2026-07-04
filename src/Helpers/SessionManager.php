<?php
// /src/Helpers/SessionManager.php
// Session management for cart and customer login

// Ensure constants are defined with fallbacks
if (!defined('SESSION_NAME')) define('SESSION_NAME', 'markethub_session');
if (!defined('CSRF_TOKEN_KEY')) define('CSRF_TOKEN_KEY', 'csrf_token');
if (!defined('CART_SESSION_KEY')) define('CART_SESSION_KEY', 'shopping_cart');
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', 7200);

class SessionManager {
    
    /**
     * Start session if not already started
     */
    public static function start() {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(SESSION_NAME);
            session_start();
        }
    }
    
    /**
     * Set a session value
     */
    public static function set($key, $value) {
        self::start();
        $_SESSION[$key] = $value;
    }
    
    /**
     * Get a session value
     */
    public static function get($key, $default = null) {
        self::start();
        return $_SESSION[$key] ?? $default;
    }
    
    /**
     * Check if session key exists
     */
    public static function has($key) {
        self::start();
        return isset($_SESSION[$key]);
    }
    
    /**
     * Remove a session key
     */
    public static function remove($key) {
        self::start();
        unset($_SESSION[$key]);
    }
    
    /**
     * Destroy all session data completely
     */
    public static function destroy() {
        self::start();
        
        // Clear all session variables
        $_SESSION = [];
        
        // Delete session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        // Destroy the session
        session_destroy();
    }
    
    /**
     * Set customer session
     */
    public static function setCustomer($customerData) {
        self::start();
        
        // Regenerate session ID to prevent fixation
        session_regenerate_id(true);
        
        $_SESSION['customer_logged_in'] = true;
        $_SESSION['customer_id'] = $customerData['id'];
        $_SESSION['customer_name'] = $customerData['full_name'];
        $_SESSION['customer_email'] = $customerData['email'];
        $_SESSION['customer_phone'] = $customerData['phone'] ?? '';
        $_SESSION['customer_data'] = $customerData;
        $_SESSION['login_time'] = time();
        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
    }
    
    /**
     * Get current customer
     */
    public static function getCustomer() {
        self::start();
        
        if (!self::isCustomerLoggedIn()) {
            return null;
        }
        
        // Check for session timeout
        $loginTime = $_SESSION['login_time'] ?? 0;
        
        if (time() - $loginTime > SESSION_LIFETIME) {
            self::logoutCustomer();
            return null;
        }
        
        return [
            'id' => $_SESSION['customer_id'] ?? null,
            'full_name' => $_SESSION['customer_name'] ?? null,
            'email' => $_SESSION['customer_email'] ?? null,
            'phone' => $_SESSION['customer_phone'] ?? null,
            'data' => $_SESSION['customer_data'] ?? null
        ];
    }
    
    /**
     * Get customer ID safely
     */
    public static function getCustomerId() {
        self::start();
        return $_SESSION['customer_id'] ?? null;
    }
    
    /**
     * Check if customer is logged in
     */
    public static function isCustomerLoggedIn() {
        self::start();
        
        $isLoggedIn = isset($_SESSION['customer_logged_in']) && $_SESSION['customer_logged_in'] === true;
        
        // Additional validation - check if customer_id exists
        if ($isLoggedIn && !isset($_SESSION['customer_id'])) {
            self::logoutCustomer();
            return false;
        }
        
        return $isLoggedIn;
    }
    
    /**
     * Logout customer - COMPLETE destruction
     */
    public static function logoutCustomer() {
        self::start();
        
        // Clear all customer-related session variables
        unset($_SESSION['customer_logged_in']);
        unset($_SESSION['customer_id']);
        unset($_SESSION['customer_name']);
        unset($_SESSION['customer_email']);
        unset($_SESSION['customer_phone']);
        unset($_SESSION['customer_data']);
        unset($_SESSION['login_time']);
        unset($_SESSION['ip_address']);
        
        // Regenerate session ID to prevent session fixation
        session_regenerate_id(true);
    }
    
    /**
     * Cart management methods
     */
    public static function addToCart($item) {
        self::start();
        
        if (!isset($_SESSION[CART_SESSION_KEY])) {
            $_SESSION[CART_SESSION_KEY] = [];
        }
        
        $cart = &$_SESSION[CART_SESSION_KEY];
        $variantId = $item['variant_id'];
        
        if (isset($cart[$variantId])) {
            $cart[$variantId]['quantity'] += $item['quantity'];
        } else {
            $cart[$variantId] = $item;
        }
        
        return self::getCartCount();
    }
    
    /**
     * Update cart item quantity
     */
    public static function updateCartQuantity($variantId, $quantity) {
        self::start();
        
        if (isset($_SESSION[CART_SESSION_KEY][$variantId])) {
            if ($quantity <= 0) {
                unset($_SESSION[CART_SESSION_KEY][$variantId]);
            } else {
                $_SESSION[CART_SESSION_KEY][$variantId]['quantity'] = $quantity;
            }
        }
        
        return self::getCartCount();
    }
    
    /**
     * Remove item from cart
     */
    public static function removeFromCart($variantId) {
        self::start();
        
        if (isset($_SESSION[CART_SESSION_KEY][$variantId])) {
            unset($_SESSION[CART_SESSION_KEY][$variantId]);
        }
        
        return self::getCartCount();
    }
    
    /**
     * Get full cart contents
     */
    public static function getCart() {
        self::start();
        return $_SESSION[CART_SESSION_KEY] ?? [];
    }
    
    /**
     * Get cart item count
     */
    public static function getCartCount() {
        self::start();
        $cart = $_SESSION[CART_SESSION_KEY] ?? [];
        $count = 0;
        foreach ($cart as $item) {
            $count += $item['quantity'];
        }
        return $count;
    }
    
    /**
     * Get cart subtotal
     */
    public static function getCartSubtotal() {
        self::start();
        $cart = $_SESSION[CART_SESSION_KEY] ?? [];
        $subtotal = 0;
        foreach ($cart as $item) {
            $subtotal += $item['quantity'] * $item['price'];
        }
        return $subtotal;
    }
    
    /**
     * Clear cart
     */
    public static function clearCart() {
        self::start();
        $_SESSION[CART_SESSION_KEY] = [];
    }
    
    /**
     * Generate CSRF token
     */
    public static function generateCsrfToken() {
        self::start();
        if (!self::has(CSRF_TOKEN_KEY)) {
            $token = bin2hex(random_bytes(32));
            self::set(CSRF_TOKEN_KEY, $token);
        }
        return self::get(CSRF_TOKEN_KEY);
    }
    
    /**
     * Verify CSRF token
     */
    public static function verifyCsrfToken($token) {
        $storedToken = self::get(CSRF_TOKEN_KEY);
        return !empty($storedToken) && hash_equals($storedToken, $token);
    }
    
    /**
     * Set flash message
     */
    public static function setFlash($type, $message) {
        self::set('flash_' . $type, $message);
    }
    
    /**
     * Get flash message
     */
    public static function getFlash($type) {
        $message = self::get('flash_' . $type);
        self::remove('flash_' . $type);
        return $message;
    }
    
    /**
     * Debug: Get all session data (for troubleshooting)
     */
    public static function debugSession() {
        self::start();
        return [
            'session_id' => session_id(),
            'session_name' => session_name(),
            'session_status' => session_status(),
            'session_data' => $_SESSION,
            'cookie_params' => session_get_cookie_params(),
            'constants_defined' => [
                'SESSION_NAME' => defined('SESSION_NAME'),
                'CART_SESSION_KEY' => defined('CART_SESSION_KEY'),
                'CSRF_TOKEN_KEY' => defined('CSRF_TOKEN_KEY'),
                'SESSION_LIFETIME' => defined('SESSION_LIFETIME')
            ]
        ];
    }
}