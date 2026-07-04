<?php
// /config/config.php
// Global configuration constants

// Path definitions - IMPORTANT: Use your actual path
define('BASE_PATH', dirname(__DIR__));
define('BASE_URL', 'http://localhost:4444/mpazi/Sati_store/public');

// Database credentials
define('DB_HOST', 'localhost');
define('DB_PORT', '8889');
define('DB_NAME', 'u637140770_mpazi');
define('DB_USER', 'root');//'u637140770_mpazi'
define('DB_PASS', 'root');//'xojfur-2dEhki-jumdixt'
define('COMPANY_ID', 9);
define('USER_ID', 10);

// Application settings
define('SITE_NAME', 'MarketHub');
define('DEBUG_MODE', true);
define('SESSION_NAME', 'markethub_session');
define('CSRF_TOKEN_KEY', 'csrf_token');
define('CART_SESSION_KEY', 'shopping_cart');

// Pagination
define('ITEMS_PER_PAGE', 12);
define('MAX_ITEMS_PER_PAGE', 50);

// Currency and Tax
define('DEFAULT_CURRENCY', 'RWF');
define('DEFAULT_TAX_RATE', 18);

// Shipping
define('FREE_SHIPPING_THRESHOLD', 50000);
define('SHIPPING_COST', 5000);

// Session lifetime (2 hours)
define('SESSION_LIFETIME', 7200);

// Ensure session name is set for cookie
ini_set('session.name', SESSION_NAME);