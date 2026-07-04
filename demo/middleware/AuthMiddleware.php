<?php
// middleware/AuthMiddleware.php

require_once __DIR__ . '/../config/routes.php';

class AuthMiddleware
{
    /**
     * Check authentication for the current request
     */
    public static function check(): void
    {
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_name('demo');
            session_start();
        }

        // Get current route info
        $routeInfo = self::getCurrentRoute();
        
        // Check if route is public (from routes.php)
        if (self::isRoutePublic($routeInfo)) {
            return;
        }

        // Check if it's a public page via query parameter
        if (self::isPublicPage()) {
            return;
        }

        // Check if user is logged in
        if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
            self::redirectToLogin();
        }

        // Check if user is active
        if (!self::isUserActive()) {
            self::logout('inactive');
        }

        // Check session expiration (8 hours)
        if (self::isSessionExpired()) {
            self::logout('expired');
        }

        // Update last activity
        $_SESSION['last_activity'] = time();

        // Check user permissions for the route
        self::checkRoutePermissions($routeInfo);
    }

    /**
     * Get current route configuration
     */
    private static function getCurrentRoute(): ?array
    {
        $routes = require __DIR__ . '/../config/routes.php';
        $page = $_GET['page'] ?? 'dashboard';
        
        // Ensure $page is a string
        $page = (string) $page;
        
        // Check for exact match
        if (isset($routes[$page])) {
            return ['name' => $page, 'config' => $routes[$page]];
        }
        
        // Check for pattern matching (for dynamic routes like products/edit?id=1)
        foreach ($routes as $routeName => $routeConfig) {
            // Ensure $routeName is a string
            $routeName = (string) $routeName;
            
            // Check if route name is a string and page starts with it
            if (is_string($routeName) && strpos($page, $routeName) === 0) {
                return ['name' => $routeName, 'config' => $routeConfig];
            }
        }
        
        // Check for API routes
        $requestUri = $_SERVER['REQUEST_URI'];
        $requestPath = strtok($requestUri, '?');
        $requestPath = (string) $requestPath;
        
        foreach ($routes as $routeName => $routeConfig) {
            $routeName = (string) $routeName;
            
            if (isset($routeConfig['type']) && $routeConfig['type'] === 'api') {
                if ($requestPath === $routeName || strpos($requestPath, $routeName) !== false) {
                    return ['name' => $routeName, 'config' => $routeConfig];
                }
            }
        }
        
        return null;
    }

    /**
     * Check if route is public based on routes.php configuration
     */
    private static function isRoutePublic(?array $routeInfo): bool
    {
        if (!$routeInfo) {
            return false;
        }
        
        $config = $routeInfo['config'];
        
        // Check explicit public flag
        if (isset($config['public']) && $config['public'] === true) {
            return true;
        }
        
        // Check if route has no permissions (public by default)
        if (!isset($config['permissions']) && !isset($config['public'])) {
            // Auth pages are public
            $publicAuthPages = ['register', 'login', 'forgot-password', 'reset-password', '2fa-verify'];
            if (in_array($routeInfo['name'], $publicAuthPages)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check user permissions for the route
     */
    private static function checkRoutePermissions(?array $routeInfo): void
    {
        if (!$routeInfo) {
            return;
        }
        
        $config = $routeInfo['config'];
        
        // Skip permission check for public routes
        if (self::isRoutePublic($routeInfo)) {
            return;
        }
        
        // Admin has all permissions
        if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'ADM') {
            return;
        }
        
        // Check route permissions
        if (isset($config['permissions'])) {
            $userRole = $_SESSION['user_role'] ?? 'VIW';
            $allowedRoles = $config['permissions'];
            
            if (!in_array($userRole, $allowedRoles)) {
                $_SESSION['flash_error'] = 'You do not have permission to access this page.';
                header('Location: index.php?page=dashboard');
                exit;
            }
        }
    }

    /**
     * Get base path from server configuration
     */
    private static function getBasePath(): string
    {
        $scriptName = $_SERVER['SCRIPT_NAME'];
        $basePath = str_replace('index.php', '', $scriptName);
        $basePath = rtrim($basePath, '/');
        
        return $basePath ?: '';
    }

    /**
     * Get list of public routes (fallback if routes.php not available)
     */
    private static function getPublicRoutes($basePath): array
    {
        return [
            $basePath . '/auth/signin.php',
            $basePath . '/auth/login.php',
            $basePath . '/auth/register.php',
            $basePath . '/auth/forgot-password.php',
            $basePath . '/auth/reset-password.php',
            $basePath . '/auth/2fa-verify.php',
            $basePath . '/auth/logout.php',
            '/api/auth/login',
            '/api/auth/register',
            '/api/auth/check-username',
            '/api/auth/check-email',
            '/test.php',
            '/assets/',
        ];
    }

    /**
     * Check if current route is public (fallback method)
     */
    private static function isPublicRouteLegacy($requestPath, $publicRoutes): bool
    {
        $requestPath = (string) $requestPath;
        
        foreach ($publicRoutes as $route) {
            $route = (string) $route;
            
            if ($requestPath === $route) {
                return true;
            }
            if (strpos($route, '/assets/') !== false && strpos($requestPath, $route) === 0) {
                return true;
            }
            if (strpos($requestPath, $route) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if current page is public via query parameter
     */
    private static function isPublicPage(): bool
    {
        if (!isset($_GET['page'])) {
            return false;
        }
        
        $publicPages = [
            'login', 'signin', 'register',
            'forgot-password', 'reset-password', '2fa-verify'
        ];
        
        return in_array($_GET['page'], $publicPages);
    }

    /**
     * Check if user is active
     */
    private static function isUserActive(): bool
    {
        if (isset($_SESSION['user_id']) && isset($_SESSION['is_active'])) {
            return (bool) $_SESSION['is_active'];
        }
        return true;
    }

    /**
     * Check if session has expired
     */
    private static function isSessionExpired(): bool
    {
        $sessionLifetime = 28800; // 8 hours
        
        if (!isset($_SESSION['last_activity'])) {
            return false;
        }
        
        return (time() - $_SESSION['last_activity']) > $sessionLifetime;
    }

    /**
     * Redirect to login page
     */
    private static function redirectToLogin(): void
    {
        // Store the requested URL to redirect back after login
        $requestUri = $_SERVER['REQUEST_URI'];
        if (!empty($requestUri) && strpos($requestUri, 'auth/') === false) {
            $_SESSION['redirect_after_login'] = $requestUri;
        }

        // Check if it's an API request
        if (strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Authentication required']);
            exit;
        }

        // Redirect to signin page
        $basePath = self::getBasePath();
        $loginUrl = $basePath . '/auth/signin.php';
        
        header('Location: ' . $loginUrl);
        exit;
    }

    /**
     * Logout user
     */
    private static function logout($reason = ''): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();

        $basePath = self::getBasePath();
        $loginUrl = $basePath . '/auth/signin.php';
        
        if ($reason === 'expired') {
            $loginUrl .= '?expired=1';
        } elseif ($reason === 'inactive') {
            $loginUrl .= '?inactive=1';
        }

        header('Location: ' . $loginUrl);
        exit;
    }

    /**
     * Require specific role for current page
     */
    public static function requireRole($roles): void
    {
        self::check();
        
        if (!is_array($roles)) {
            $roles = [$roles];
        }
        
        $userRole = $_SESSION['user_role'] ?? 'VIW';
        
        if (!in_array($userRole, $roles)) {
            $_SESSION['flash_error'] = 'You do not have permission to access this page.';
            header('Location: index.php?page=dashboard');
            exit;
        }
    }

    /**
     * Require specific permission
     */
    public static function requirePermission($permissionCode): void
    {
        self::check();
        
        if (($_SESSION['user_role'] ?? '') === 'ADM') {
            return;
        }
        
        if (!isset($_SESSION['permissions']) || !in_array($permissionCode, $_SESSION['permissions'])) {
            $_SESSION['flash_error'] = 'You do not have permission to perform this action.';
            header('Location: index.php?page=dashboard');
            exit;
        }
    }

    /**
     * Get menu items for current user (based on routes.php)
     */
    public static function getMenuItems(): array
    {
        $routes = require __DIR__ . '/../config/routes.php';
        $userRole = $_SESSION['user_role'] ?? 'VIW';
        $menuItems = [];
        
        foreach ($routes as $route => $config) {
            // Only include menu items
            if (!isset($config['menu']) || $config['menu'] !== true) {
                continue;
            }
            
            // Check permissions
            if (isset($config['permissions'])) {
                if (!in_array($userRole, $config['permissions'])) {
                    continue;
                }
            }
            
            $menuItems[$route] = [
                'title' => $config['title'],
                'icon' => $config['icon'] ?? 'fas fa-circle',
                'route' => $route
            ];
        }
        
        return $menuItems;
    }
}