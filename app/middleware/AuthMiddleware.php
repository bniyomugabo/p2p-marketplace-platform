<?php
// middleware/AuthMiddleware.php

class AuthMiddleware
{
    /**
     * Check authentication for the current request
     */
    public static function check(): void
    {
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_name('sati');
            session_start();
        }

        // Get base path dynamically
        $basePath = self::getBasePath();

        // Define public routes (no authentication required)
        $publicRoutes = self::getPublicRoutes($basePath);

        $requestUri = $_SERVER['REQUEST_URI'];
        $requestPath = strtok($requestUri, '?');

        // Check if current route is public
        if (self::isPublicRoute($requestPath, $publicRoutes)) {
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

        // Check if user has permission for the requested page
        self::checkPermissions();
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
     * Get list of public routes
     */
    private static function getPublicRoutes($basePath): array
    {
        $routes = [
            // Auth pages
            $basePath . '/auth/signin.php',
            $basePath . '/auth/login.php',
            $basePath . '/auth/register.php',
            $basePath . '/auth/forgot-password.php',
            $basePath . '/auth/reset-password.php',
            $basePath . '/auth/2fa-verify.php',
            $basePath . '/auth/logout.php',
            
            // API endpoints
            '/api/auth/login',
            '/api/auth/register',
            '/api/auth/check-username',
            '/api/auth/check-email',
            
            // Test files
            '/test.php',
            '/test-session.php',
            '/path-test.php',
            '/debug.php',
            
            // Assets (allow all assets)
            '/assets/',
        ];
        
        // Add routes with subdirectory prefix if exists
        if (!empty($basePath) && $basePath !== '/') {
            $subdirRoutes = [
                $basePath . '/auth/signin.php',
                $basePath . '/auth/register.php',
                $basePath . '/auth/forgot-password.php',
                $basePath . '/auth/reset-password.php',
                $basePath . '/auth/2fa-verify.php',
                $basePath . '/assets/',
            ];
            $routes = array_merge($routes, $subdirRoutes);
        }
        
        return $routes;
    }

    /**
     * Check if current route is public
     */
    private static function isPublicRoute($requestPath, $publicRoutes): bool
    {
        foreach ($publicRoutes as $route) {
            // Exact match
            if ($requestPath === $route) {
                return true;
            }
            
            // Path starts with route (for asset directories)
            if (strpos($route, '/assets/') !== false && strpos($requestPath, $route) === 0) {
                return true;
            }
            
            // Route contains in path (for backward compatibility)
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
            'login',
            'signin', 
            'register',
            'forgot-password',
            'reset-password',
            '2fa-verify'
        ];
        
        return in_array($_GET['page'], $publicPages);
    }

    /**
     * Check if user is active
     */
    private static function isUserActive(): bool
    {
        // If we have user_id but need to verify status, query database
        if (isset($_SESSION['user_id']) && isset($_SESSION['is_active'])) {
            return (bool) $_SESSION['is_active'];
        }
        
        // If status not in session, we could query database
        // For performance, we assume active unless we know otherwise
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
     * Check user permissions for current page
     */
    private static function checkPermissions(): void
    {
        // Skip permission check for non-authenticated users (already handled)
        if (!isset($_SESSION['user_id'])) {
            return;
        }
        
        // Admin has all permissions
        if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'ADM') {
            return;
        }
        
        // Get current page
        $page = $_GET['page'] ?? 'dashboard';
        
        // Define which roles can access which pages
        $pagePermissions = [
            'dashboard' => ['ADM', 'MGR', 'ACC', 'SEL', 'WHS', 'VIW'],
            'products' => ['ADM', 'MGR', 'SEL', 'VIW'],
            'products/add' => ['ADM', 'MGR'],
            'products/edit' => ['ADM', 'MGR'],
            'products/delete' => ['ADM'],
            'inventory' => ['ADM', 'MGR', 'WHS'],
            'inventory/stock' => ['ADM', 'MGR', 'WHS'],
            'inventory/adjustments' => ['ADM', 'MGR', 'WHS'],
            'sales' => ['ADM', 'MGR', 'SEL'],
            'sales/create' => ['ADM', 'MGR', 'SEL'],
            'sales/invoices' => ['ADM', 'MGR', 'SEL', 'ACC'],
            'quotations' => ['ADM', 'MGR', 'SEL'],
            'quotations/create' => ['ADM', 'MGR', 'SEL'],
            'purchasing' => ['ADM', 'MGR', 'ACC', 'WHS'],
            'purchasing/orders' => ['ADM', 'MGR', 'ACC', 'WHS'],
            'purchasing/suppliers' => ['ADM', 'MGR', 'ACC'],
            'reports' => ['ADM', 'MGR', 'ACC', 'VIW'],
            'admin' => ['ADM'],
            'admin/users' => ['ADM'],
            'admin/roles' => ['ADM'],
            'admin/permissions' => ['ADM'],
            'admin/settings' => ['ADM'],
        ];
        
        $userRole = $_SESSION['user_role'] ?? 'VIW';
        
        // Check if page requires specific permissions
        if (isset($pagePermissions[$page])) {
            if (!in_array($userRole, $pagePermissions[$page])) {
                // Redirect to dashboard with error
                $_SESSION['flash_error'] = 'You do not have permission to access this page.';
                header('Location: index.php?page=dashboard');
                exit;
            }
        }
        
        // For pages with dynamic segments (like view?id=123)
        foreach ($pagePermissions as $pattern => $allowedRoles) {
            if (strpos($page, $pattern) === 0 && !in_array($userRole, $allowedRoles)) {
                $_SESSION['flash_error'] = 'You do not have permission to access this page.';
                header('Location: index.php?page=dashboard');
                exit;
            }
        }
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
        // Clear session
        $_SESSION = [];

        // Clear session cookie
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

        // Destroy session
        session_destroy();

        // Build login URL with reason
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
        
        // Admin has all permissions
        if (($_SESSION['user_role'] ?? '') === 'ADM') {
            return;
        }
        
        // Check if user has the specific permission
        if (!isset($_SESSION['permissions']) || !in_array($permissionCode, $_SESSION['permissions'])) {
            $_SESSION['flash_error'] = 'You do not have permission to perform this action.';
            header('Location: index.php?page=dashboard');
            exit;
        }
    }
}