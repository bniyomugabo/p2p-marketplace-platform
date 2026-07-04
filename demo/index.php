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
$routes = require __DIR__ . '/config/routes.php';

// Initialize session manager (if not already started)
if (session_status() === PHP_SESSION_NONE) {
    $session = SessionManager::getInstance();
} else {
    // Session already started, just get the instance
    $session = SessionManager::getInstance();
}

// Apply security headers
SecurityHeadersMiddleware::apply();

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

// ============================================
// ROUTER CLASS DEFINITION
// ============================================

class Router
{
    private static $routes = [];
    private static $basePath = '';
    private static $db = null;

    public static function init($routesConfig)
    {
        self::$routes = $routesConfig;
        
        // Determine base path automatically
        $scriptName = $_SERVER['SCRIPT_NAME'];
        self::$basePath = str_replace('index.php', '', $scriptName);
        self::$basePath = rtrim(self::$basePath, '/');
    }

    public static function dispatch(): void
    {
        $requestUri = $_GET['page'] ?? '';
        $requestMethod = $_SERVER['REQUEST_METHOD'];

        // Remove trailing slash
        $requestUri = rtrim($requestUri, '/');
        // Handle empty URI
        if (empty($requestUri) || $requestUri === '/') {
            $requestUri = '/';
        }

        // Check for API routes
        if (strpos($requestUri, 'api/') === 0) {
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
                echo json_encode(['error' => 'Method not allowed', 'allowed_method' => $route['method']]);
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
            $handlerFile = __DIR__ . $route['handler'];
            if (file_exists($handlerFile)) {
                require_once $handlerFile;
            } else {
                http_response_code(500);
                echo json_encode(['error' => 'Handler file not found'.$handlerFile, 'file' => $route['handler']] );
            }
            exit;
        }

        // Route not found
        http_response_code(404);
        echo json_encode(['error' => 'API endpoint not found', 'uri' => $uri]);
        exit;
    }

    private static function handlePageRequest(string $page): void
    {
        // Check if route exists in pages array
        if (isset(self::$routes[$page])) {
            $route = self::$routes[$page];
            $pageFile = $route['handler'];

            // Check if public or authenticated
            $isPublic = $route['public'] ?? false;

            if (!$isPublic) {
                // Check authentication - but only if not already handled by middleware
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
                        header('Location: index.php?page=403');
                        exit;
                    }
                }
            }
        } else {
            // Page not found
            $pageFile = 'pages/errors/404.php';
        }

        // Check if file exists
        $fullPath = __DIR__ . '/' . $pageFile;
        if (!file_exists($fullPath)) {
            $pageFile = 'pages/errors/404.php';
        }

        // Set active module for sidebar highlighting
        $module = explode('/', $page)[0];
        $_SESSION['active_module'] = $module;

        // Determine if this is a public page (no layout needed)
        $publicPages = ['login', 'register', 'forgot-password', 'reset-password', '2fa-verify', '403', '404', '401'];
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

// ============================================
// AUTHENTICATION CHECK (BEFORE ROUTING)
// ============================================

// Define public pages that don't require authentication
$publicPagesList = [
    'login', 'register', 'forgot-password', 'reset-password', 
    '2fa-verify', '404', '403', '401'
];

// Check if current page is public
$isPublicPage = in_array($currentPage, $publicPagesList) || $isAuthFile;

// Apply rate limiting for API requests
if ($isApiRequest) {
    RateLimitMiddleware::throttle('api', 60, 1); // 60 requests per minute
}

// Only check authentication for non-public pages and non-API requests
// (API requests are authenticated inside the router)
if (!$isPublicPage && !$isApiRequest) {
    AuthMiddleware::check();
} else {
    // For public pages, check if user is already logged in and trying to access login page
    if (
        ($isAuthFile || $currentPage === 'login' || $currentPage === 'register') &&
        isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])
    ) {
        // User is already logged in, redirect to dashboard
        $redirectUrl = BASE_URL . '/index.php?page=dashboard';
        header('Location: ' . $redirectUrl);
        exit;
    }
}

// ============================================
// ROUTE DISPATCHING
// ============================================

// Initialize router with routes
Router::init($routes);

// Dispatch the request
Router::dispatch();

// ============================================
// CLEANUP
// ============================================
if (ob_get_level() > 0) {
    ob_end_flush();
}