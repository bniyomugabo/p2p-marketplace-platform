<?php
// /src/api/login_api.php
// Customer Login API with logging

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../init.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$logger = Logger::getInstance();

// Log request received
$logger->api("Login API called", [
    'method' => $_SERVER['REQUEST_METHOD'],
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'unknown'
]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $logger->api("Invalid method for login API", ['method' => $_SERVER['REQUEST_METHOD']]);
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

// Get raw input
$rawInput = file_get_contents('php://input');
$logger->debug("Raw login input", ['raw' => $rawInput]);

$input = json_decode($rawInput, true);

if (!$input) {
    $logger->api("Invalid JSON in login request", ['raw_input' => $rawInput]);
    jsonResponse(['success' => false, 'message' => 'Invalid request data']);
}

$email = trim($input['email'] ?? '');
$password = $input['password'] ?? '';
$rememberMe = isset($input['remember_me']) && ($input['remember_me'] === 'on' || $input['remember_me'] === true);

$logger->api("Login attempt", [
    'email' => $email,
    'remember_me' => $rememberMe,
    'has_password' => !empty($password)
]);

if (empty($email) || empty($password)) {
    $logger->api("Missing credentials", ['missing_email' => empty($email), 'missing_password' => empty($password)]);
    jsonResponse(['success' => false, 'message' => 'Email and password are required']);
}

// Verify CSRF token if provided (optional for login)
if (isset($input['csrf_token']) && !SessionManager::verifyCsrfToken($input['csrf_token'])) {
    $logger->warning("CSRF validation failed for login");
    // Don't block login, just log it
}

try {
    $customerModel = new Customer();
    
    $logger->info("Attempting login", ['email' => $email]);
    
    $result = $customerModel->login($email, $password);
    
    $logger->logLogin($email, $result['success'], $result['success'] ? null : ($result['message'] ?? 'Unknown error'));
    
    if ($result['success']) {
        // Set session
        SessionManager::setCustomer($result['customer']);
        
        $logger->info("Login successful", [
            'customer_id' => $result['customer']['id'],
            'email' => $email
        ]);
        
        // Set remember me cookie if requested
        if ($rememberMe) {
            $token = bin2hex(random_bytes(32));
            $expires = time() + (86400 * 30); // 30 days
            
            setcookie('remember_token', $token, $expires, '/', '', false, true);
            $logger->debug("Remember me cookie set", ['expires' => date('Y-m-d H:i:s', $expires)]);
        }
        
        jsonResponse([
            'success' => true,
            'message' => 'Login successful',
            'redirect' => $input['redirect'] ?? 'account.php',
            'customer' => [
                'id' => $result['customer']['id'],
                'full_name' => $result['customer']['full_name'],
                'email' => $result['customer']['email']
            ]
        ]);
    } else {
        $logger->warning("Login failed", ['email' => $email, 'reason' => $result['message']]);
        jsonResponse(['success' => false, 'message' => $result['message']]);
    }
    
} catch (PDOException $e) {
    $logger->error("Database error during login", [
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'trace' => $e->getTraceAsString()
    ]);
    jsonResponse(['success' => false, 'message' => 'Login failed due to database error. Please try again.']);
} catch (Exception $e) {
    $logger->error("Unexpected error during login", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    jsonResponse(['success' => false, 'message' => 'Login failed. Please try again.']);
}