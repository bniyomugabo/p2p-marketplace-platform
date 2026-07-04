<?php
// /src/init.php
// Autoloader & Session Initialization

// Load configuration FIRST
require_once __DIR__ . '/../config/config.php';

// Start session with proper configuration
if (session_status() === PHP_SESSION_NONE) {
    // Set session cookie parameters for security
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => '/',
        'domain' => '',
        'secure' => false, // Set to true if using HTTPS
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    session_name(SESSION_NAME);
    session_start();
}

// Error reporting based on debug mode
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Set timezone
date_default_timezone_set('Africa/Kigali');

// Simple autoloader for all classes
spl_autoload_register(function ($class_name) {
    // Possible paths for the class
    $paths = [
        __DIR__ . '/Models/',
        __DIR__ . '/Helpers/',
        __DIR__ . '/../config/',
    ];
    
    foreach ($paths as $path) {
        $file = $path . $class_name . '.php';
        if (file_exists($file)) {
            require_once $file;
            return true;
        }
    }
    
    // Also try with lowercase first letter
    $lowerClass = lcfirst($class_name);
    foreach ($paths as $path) {
        $file = $path . $lowerClass . '.php';
        if (file_exists($file)) {
            require_once $file;
            return true;
        }
    }
    
    return false;
});

// Initialize Logger if available
if (class_exists('Logger')) {
    $logger = Logger::getInstance();
    $logger->debug("Request started", [
        'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'session_id' => session_id()
    ]);
}

// Helper function to return JSON response
if (!function_exists('jsonResponse')) {
    function jsonResponse($data, $statusCode = 200) {
        if (class_exists('Logger')) {
            $logger = Logger::getInstance();
            $logger->debug("JSON Response", [
                'status_code' => $statusCode,
                'data' => $data
            ]);
        }
        
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Helper function to return error response
if (!function_exists('errorResponse')) {
    function errorResponse($message, $statusCode = 400) {
        jsonResponse(['error' => true, 'message' => $message], $statusCode);
    }
}