<?php
// /public/logout.php
// Customer Logout - Complete session destruction

require_once __DIR__ . '/../src/init.php';

// Clear remember me cookie
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/', '', false, true);
}

// Clear all session data and destroy session
SessionManager::logoutCustomer();

// Redirect to home page with cache prevention headers
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Location: index.php');
exit;