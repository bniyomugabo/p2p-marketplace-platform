<?php
// /src/api/register_api.php
// Customer Registration API with logging

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
$logger->api("Register API called", [
    'method' => $_SERVER['REQUEST_METHOD'],
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'unknown'
]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $logger->api("Invalid method for register API", ['method' => $_SERVER['REQUEST_METHOD']]);
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

// Get raw input
$rawInput = file_get_contents('php://input');
$logger->debug("Raw register input", ['raw' => $rawInput]);

$input = json_decode($rawInput, true);

if (!$input) {
    $logger->api("Invalid JSON in register request", ['raw_input' => $rawInput]);
    jsonResponse(['success' => false, 'message' => 'Invalid request data']);
}

// Log sanitized input
$logger->api("Register request data", [
    'has_full_name' => isset($input['full_name']),
    'email' => $input['email'] ?? 'missing',
    'has_password' => isset($input['password']),
    'has_csrf' => isset($input['csrf_token']),
    'verified_token' => isset($input['csrf_token']) ? SessionManager::verifyCsrfToken($input['csrf_token']) : 'no token'
]);

// Verify CSRF token
if (!isset($input['csrf_token']) || !SessionManager::verifyCsrfToken($input['csrf_token'])) {
    $logger->warning("CSRF validation failed for registration", [
        'provided_token' => $input['csrf_token'] ?? 'none'
    ]);
    jsonResponse(['success' => false, 'message' => 'Security validation failed']);
}

// Validate required fields
$requiredFields = ['full_name', 'email', 'phone', 'password', 'confirm_password'];
$missingFields = [];

foreach ($requiredFields as $field) {
    if (empty($input[$field])) {
        $missingFields[] = $field;
    }
}

if (!empty($missingFields)) {
    $logger->api("Missing required fields", ['fields' => $missingFields]);
    jsonResponse(['success' => false, 'message' => 'Missing required fields: ' . implode(', ', $missingFields)]);
}

$fullName = trim($input['full_name']);
$email = trim($input['email']);
$phone = trim($input['phone']);
$password = $input['password'];
$confirmPassword = $input['confirm_password'];

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $logger->api("Invalid email format", ['email' => $email]);
    jsonResponse(['success' => false, 'message' => 'Invalid email format']);
}

// Validate phone format
if (!preg_match('/^[0-9+\-\s()]{8,20}$/', $phone)) {
    $logger->api("Invalid phone format", ['phone' => $phone]);
    jsonResponse(['success' => false, 'message' => 'Invalid phone number format']);
}

// Validate password length
if (strlen($password) < 8) {
    $logger->api("Password too short", ['length' => strlen($password)]);
    jsonResponse(['success' => false, 'message' => 'Password must be at least 8 characters']);
}

// Validate password match
if ($password !== $confirmPassword) {
    $logger->api("Password mismatch");
    jsonResponse(['success' => false, 'message' => 'Passwords do not match']);
}

// Validate password strength
$passwordStrength = checkPasswordStrength($password);
if ($passwordStrength < 3) {
    $logger->api("Weak password", ['strength_score' => $passwordStrength]);
    jsonResponse(['success' => false, 'message' => 'Please choose a stronger password (at least 8 chars, mix of letters, numbers, and symbols)']);
}

try {
    $customerModel = new Customer();
    
    $logger->info("Attempting to register customer", ['email' => $email]);
    
    $result = $customerModel->register([
        'full_name' => $fullName,
        'email' => $email,
        'phone' => $phone,
        'password' => $password
    ]);
    
    $logger->logregistration($email, $result['success'], $result['success'] ? null : ($result['message'] ?? 'Unknown error'));
    
    if ($result['success']) {
        // Send welcome email (queue for background processing)
        sendWelcomeEmail($email, $fullName);
        
        $logger->info("Registration successful", [
            'customer_id' => $result['customer_id'],
            'email' => $email
        ]);
        
        jsonResponse([
            'success' => true,
            'message' => 'Registration successful! Please login.',
            'customer_id' => $result['customer_id']
        ]);
    } else {
        $logger->warning("Registration failed", ['reason' => $result['message']]);
        jsonResponse(['success' => false, 'message' => $result['message']]);
    }
    
} catch (PDOException $e) {
    $logger->error("Database error during registration", [
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'trace' => $e->getTraceAsString()
    ]);
    jsonResponse(['success' => false, 'message' => 'Registration failed due to database error. Please try again.']);
} catch (Exception $e) {
    $logger->error("Unexpected error during registration", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    jsonResponse(['success' => false, 'message' => 'Registration failed. Please try again.']);
}

/**
 * Check password strength
 */
function checkPasswordStrength($password) {
    $score = 0;
    
    if (strlen($password) >= 8) $score++;
    if (preg_match('/[a-z]/', $password)) $score++;
    if (preg_match('/[A-Z]/', $password)) $score++;
    if (preg_match('/[0-9]/', $password)) $score++;
    if (preg_match('/[^A-Za-z0-9]/', $password)) $score++;
    
    return $score;
}

/**
 * Send welcome email
 */
function sendWelcomeEmail($email, $name) {
    try {
        $db = Database::getInstance();
        $logger = Logger::getInstance();
        
        $sql = "INSERT INTO email_queue (company_id, to_email, to_name, subject, body, status, created_at) 
                VALUES (0, :email, :name, :subject, :body, 'pending', NOW())";
        
        $subject = "Welcome to " . SITE_NAME;
        $body = "Dear {$name},\n\nWelcome to " . SITE_NAME . "! Your account has been created successfully.\n\nYou can now log in and start shopping.\n\nBest regards,\n" . SITE_NAME . " Team";
        
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':email', $email);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':subject', $subject);
        $stmt->bindValue(':body', $body);
        $stmt->execute();
        
        $logger->info("Welcome email queued", ['email' => $email]);
        
    } catch (Exception $e) {
        $logger->error("Failed to queue welcome email", ['error' => $e->getMessage()]);
    }
}