<?php
// index.php - Updated with Security Middleware
declare(strict_types=1);

ob_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

// ============================================
// BOOTSTRAP & CONFIGURATION
// ============================================

// Load environment
require_once __DIR__ . '/config/autoload.php';
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/middleware/SecurityHeadersMiddleware.php';
require_once __DIR__ . '/middleware/AuthMiddleware.php';
require_once __DIR__ . '/middleware/RateLimitMiddleware.php';

// Load routes configuration - THIS RETURNS AN ARRAY
$routes = require_once __DIR__ . '/config/routes.php';

// DEBUG: Check if routes loaded
//error_log("Routes loaded: " . print_r(array_keys($routes), true));

// Initialize session manager (if not already started)
if (session_status() === PHP_SESSION_NONE) {
    $session = SessionManager::getInstance();
} else {
    // Session already started, just get the instance
    $session = SessionManager::getInstance();
}

// Apply security headers
SecurityHeadersMiddleware::apply();

// Define public pages that don't require authentication
$publicPages = [
    'login',
    'register',
    'forgot-password',
    'reset-password',
    '2fa-verify',
    '404',
    '403',
    '401'
];

// Get current page from query string
$currentPage = $_GET['page'] ?? '';

// Get the current URI path
$requestUri = $_SERVER['REQUEST_URI'];
$requestPath = strtok($requestUri, '?');

// Check if we're directly accessing an auth file
$isAuthFile = (
    strpos($requestPath, 'auth/signin.php') !== false ||
    strpos($requestPath, 'auth/register.php') !== false ||
    strpos($requestPath, 'auth/forgot-password.php') !== false ||
    strpos($requestPath, 'auth/reset-password.php') !== false ||
    strpos($requestPath, 'auth/2fa-verify.php') !== false
);

// Check if it's an API request
$isApiRequest = strpos($requestPath, '/api/') !== false;

// Check if current page is public
$isPublicPage = in_array($currentPage, $publicPages) || $isAuthFile;

// Apply rate limiting for API requests
if ($isApiRequest) {
    RateLimitMiddleware::throttle('api', 60, 1); // 60 requests per minute
}

// Only check authentication for non-public pages
if (!$isPublicPage && !$isApiRequest) {
    AuthMiddleware::check();
} else {
    // For public pages, check if user is already logged in and trying to access login page
    if (
        ($isAuthFile || $currentPage === 'login' || $currentPage === 'register') &&
        isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])
    ) {
        // User is already logged in, redirect to dashboard
        header('Location: ' . BASE_URL . '/index.php?page=dashboard');
        exit;
    }
}

// ============================================
// ROUTING SYSTEM
// ============================================
class Router
{
    private static $routes = [];

    public static function init($routesConfig)
    {
        self::$routes = $routesConfig;
        // DEBUG: Log what routes were initialized
        //error_log("Router initialized with routes: " . print_r(array_keys(self::$routes), true));
    }

    public static function dispatch(): void
    {
        $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $requestMethod = $_SERVER['REQUEST_METHOD'];

        // Remove base path if exists
        $basePath = '/sati_premium';
        if (strpos($requestUri, $basePath) === 0) {
            $requestUri = substr($requestUri, strlen($basePath));
        }

        // Remove trailing slash
        $requestUri = rtrim($requestUri, '/');

        // Handle empty URI
        if (empty($requestUri) || $requestUri === '/') {
            $requestUri = '/';
        }

        // Check for API routes
        if (strpos($requestUri, '/api/') === 0) {
            self::handleApiRequest($requestUri, $requestMethod);
            return;
        }

        // Handle main application routing
        $page = $_GET['page'] ?? 'dashboard';
        self::handlePageRequest($page);
    }

    private static function handleApiRequest(string $uri, string $method): void
    {
        header('Content-Type: application/json');

        // Check if route exists
        if (isset(self::$routes[$uri])) {
            $route = self::$routes[$uri];

            // Check method
            if ($route['method'] !== $method) {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit;
            }

            // Check if public or authenticated
            $isPublic = $route['public'] ?? false;

            if (!$isPublic) {
                // Check authentication for non-public API routes
                if (!isset($_SESSION['user_id'])) {
                    http_response_code(401);
                    echo json_encode(['error' => 'Authentication required']);
                    exit;
                }

                // Check permissions if specified
                if (isset($route['permissions'])) {
                    $userRole = $_SESSION['user_role'] ?? '';
                    if (!in_array($userRole, $route['permissions'])) {
                        http_response_code(403);
                        echo json_encode(['error' => 'Insufficient permissions']);
                        exit;
                    }
                }
            }

            // Include the handler
            require_once __DIR__ . $route['handler'];
            exit;
        }

        // Route not found
        http_response_code(404);
        echo json_encode(['error' => 'API endpoint not found']);
        exit;
    }

    private static function handlePageRequest(string $page): void
    {
        // DEBUG: Log what page we're trying to load
        //error_log("Attempting to load page: " . $page);
        //error_log("Available routes: " . print_r(array_keys(self::$routes), true));

        // Check if route exists in pages array
        if (isset(self::$routes[$page])) {
            $route = self::$routes[$page];
            $pageFile = $route['handler'];
            error_log("Found route for '$page': " . $pageFile);

            // Check if public or authenticated
            $isPublic = $route['public'] ?? false;

            if (!$isPublic) {
                // Check authentication
                if (!isset($_SESSION['user_id'])) {
                    // Store the requested page to redirect back after login
                    $_SESSION['redirect_after_login'] = 'index.php?page=' . $page;
                    header('Location: auth/signin.php');
                    exit;
                }

                // Check permissions if specified
                if (isset($route['permissions'])) {
                    $userRole = $_SESSION['user_role'] ?? '';
                    if (!in_array($userRole, $route['permissions'])) {
                        header('Location: ?page=403');
                        exit;
                    }
                }
            }
        } else {
            // Page not found
            error_log("Route not found for page: " . $page);
            $pageFile = 'pages/errors/404.php';
        }

        // Check if file exists
        $fullPath = __DIR__ . '/' . $pageFile;
        if (!file_exists($fullPath)) {
            error_log("File does not exist: " . $fullPath);
            $pageFile = 'pages/errors/404.php';
        }

        // Set active module for sidebar highlighting
        $module = explode('/', $page)[0];
        $_SESSION['active_module'] = $module;

        // For public pages, don't include header/sidebar/footer
        $publicPages = ['login', 'register', 'forgot-password', 'reset-password', '2fa-verify'];
        $isPublicPage = in_array($page, $publicPages);

        if ($isPublicPage) {
            // Render public page without layout
            require_once __DIR__ . '/' . $pageFile;
        } else {
            // Check if template files exist
            $headerPath = __DIR__ . '/templates/header.php';
            $sidebarPath = __DIR__ . '/templates/sidebar.php';
            $footerPath = __DIR__ . '/templates/footer.php';

            if (!file_exists($headerPath)) {
                die("Header template not found at: $headerPath");
            }
            if (!file_exists($sidebarPath)) {
                die("Sidebar template not found at: $sidebarPath");
            }
            if (!file_exists($footerPath)) {
                die("Footer template not found at: $footerPath");
            }

            // Render page with layout
            require_once $headerPath;
            require_once $sidebarPath;
            require_once __DIR__ . '/' . $pageFile;
            require_once $footerPath;
        }
    }
}

// DEBUG: Check routes before initializing
//error_log("Routes before init: " . print_r(array_keys($routes), true));

// Initialize router with routes
Router::init($routes);

// ============================================
// ROUTE DISPATCHING
// ============================================
Router::dispatch();

// ============================================
// CLEANUP
// ============================================
if (ob_get_level() > 0) {
    ob_end_flush();
}