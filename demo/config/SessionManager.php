<?php
// config/SessionManager.php
// ============================================
// ENHANCED SESSION MANAGEMENT WITH SECURITY
// ============================================

class SessionManager
{
    private static $instance = null;
    private $sessionName = 'demo';
    private $lifetime;
    private $refreshTime;
    private static $initialized = false;

    // Public routes that don't require session validation
    private static $publicRoutes = [
        'auth/signin.php',
        'auth/login.php',
        'auth/register.php',
        'auth/forgot-password.php',
        'auth/reset-password.php',
        'auth/2fa-verify.php',
        'auth/logout.php',
    ];

    private function __construct()
    {
        // Load configuration
        $this->lifetime = $_ENV['SESSION_LIFETIME'] ?? 28800;
        $this->refreshTime = $_ENV['SESSION_REFRESH'] ?? 3600;

        // Only configure session if not already active
        if (session_status() === PHP_SESSION_NONE) {
            $this->configureSession();
            $this->startSession();
        } else {
            // Session already started, just validate it (skip for public routes)
            if (!$this->isPublicRoute()) {
                $this->validateSession();
            }
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Check if current request is for a public route
     */
    private function isPublicRoute(): bool
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

        // Check if we're on a registration page
        if (strpos($requestUri, 'register.php') !== false) {
            return true;
        }

        // Check script name
        foreach (self::$publicRoutes as $route) {
            if (strpos($scriptName, $route) !== false) {
                return true;
            }
        }

        // Check query parameter
        if (isset($_GET['page'])) {
            $publicPages = ['register', 'login', 'signin', 'forgot-password', 'reset-password', '2fa-verify'];
            if (in_array($_GET['page'], $publicPages)) {
                return true;
            }
        }

        return false;
    }

    public function setCompanyContext($companyId)
    {
        if (!isset($_SESSION['company_id']) || $_SESSION['company_id'] != $companyId) {
            $_SESSION['company_id'] = $companyId;
            $_SESSION['company_changed_at'] = time();
            unset($_SESSION['company_data']);
        }
    }

    public function getCompanyId()
    {
        return $_SESSION['company_id'] ?? null;
    }

    private function configureSession()
    {
        // These can only be set BEFORE session start
        session_name($this->sessionName);

        // Set secure session options
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? '1' : '0');
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_cookies', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_path', '/');
        ini_set('session.gc_maxlifetime', $this->lifetime);
        ini_set('session.cookie_lifetime', $this->lifetime);
        ini_set('session.use_trans_sid', '0');

        if (function_exists('random_bytes')) {
            ini_set('session.entropy_file', '/dev/urandom');
            ini_set('session.entropy_length', '32');
            ini_set('session.hash_function', 'sha256');
        }
    }

    private function startSession()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Skip validation for public routes (like registration)
        if (!$this->isPublicRoute()) {
            $this->validateSession();
        }
    }

    private function validateSession()
    {
        // Only run if session exists
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        // Regenerate session ID periodically
        if (!isset($_SESSION['_last_regenerate'])) {
            $_SESSION['_last_regenerate'] = time();
            @session_regenerate_id(true);
        } elseif (time() - $_SESSION['_last_regenerate'] > $this->refreshTime) {
            @session_regenerate_id(true);
            $_SESSION['_last_regenerate'] = time();
        }

        // Check session expiration - only if user is logged in
        if (isset($_SESSION['user_id'])) {
            if (isset($_SESSION['_last_activity'])) {
                if (time() - $_SESSION['_last_activity'] > $this->lifetime) {
                    $this->destroy();
                    $this->redirectToLogin('expired');
                    exit;
                }
            }
        }

        // Update last activity
        $_SESSION['_last_activity'] = time();

        // Validate user agent (only for logged-in users)
        if (isset($_SESSION['user_id'])) {
            $this->validateUserAgent();
        }
    }

    private function validateUserAgent()
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if (!isset($_SESSION['_user_agent'])) {
            $_SESSION['_user_agent'] = $userAgent;
        } elseif ($_SESSION['_user_agent'] !== $userAgent) {
            $this->destroy();
            error_log('Session hijacking attempt detected - User Agent mismatch');
            $this->redirectToLogin('security');
            exit;
        }
    }

    private function redirectToLogin($reason = '')
    {
        $basePath = $this->getBasePath();
        $loginUrl = $basePath . '/auth/signin.php';

        if ($reason === 'expired') {
            $loginUrl .= '?expired=1';
        } elseif ($reason === 'security') {
            $loginUrl .= '?security=1';
        }

        header('Location: ' . $loginUrl);
    }

    private function getBasePath(): string
    {
        $scriptName = $_SERVER['SCRIPT_NAME'];
        $basePath = str_replace('index.php', '', $scriptName);
        return rtrim($basePath, '/');
    }

    public function set($key, $value)
    {
        $_SESSION[$key] = $value;
    }

    public function get($key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    public function remove($key)
    {
        unset($_SESSION[$key]);
    }

    public function has($key)
    {
        return isset($_SESSION[$key]);
    }

    /**
     * Flash message system
     */
    public static function flash($key, $value = null)
    {
        if ($value !== null) {
            $_SESSION['_flash'][$key] = $value;
        } else {
            $value = $_SESSION['_flash'][$key] ?? null;
            unset($_SESSION['_flash'][$key]);
            return $value;
        }
    }

    /**
     * Check if there's a flash message
     */
    public static function hasFlash($key)
    {
        return isset($_SESSION['_flash'][$key]);
    }

    /**
     * Get all flash messages
     */
    public static function getAllFlashes()
    {
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flashes;
    }

    public function regenerate()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
            $_SESSION['_last_regenerate'] = time();
        }
    }

    public function destroy()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
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
        }
    }

    public function getId()
    {
        return session_status() === PHP_SESSION_ACTIVE ? session_id() : '';
    }

    public function getName()
    {
        return session_name();
    }

    /**
     * Check if user is logged in
     */
    public function isLoggedIn()
    {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }

    /**
     * Get current user role
     */
    public function getUserRole()
    {
        return $_SESSION['user_role'] ?? 'VIW';
    }

    /**
     * Check if user has specific role
     */
    public function hasRole($role)
    {
        return $this->getUserRole() === $role;
    }

    /**
     * Login user
     */
    public function login($userId, $username, $fullName, $roleId, $companyId, $companyName, $roleCode = 'VIW')
    {
        $this->regenerate();

        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        $_SESSION['full_name'] = $fullName;
        $_SESSION['role_id'] = $roleId;
        $_SESSION['user_role'] = $roleCode;
        $_SESSION['company_id'] = $companyId;
        $_SESSION['company_name'] = $companyName;
        $_SESSION['login_time'] = time();
        $_SESSION['_last_activity'] = time();
        $_SESSION['_user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Clear any flash messages
        unset($_SESSION['_flash']);
    }

    /**
     * Logout user
     */
    public function logout()
    {
        $this->destroy();

        // Start a new session (optional)
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}

// Initialize SessionManager
SessionManager::getInstance();