<?php
// config/autoload.php
// ============================================
// UPDATED AUTOLOADER FOR NEW STRUCTURE
// ============================================

// Prevent multiple inclusions
if (defined('AUTOLOAD_LOADED')) {
    return;
}
define('AUTOLOAD_LOADED', true);

spl_autoload_register(function ($className) {
    // Define base directories
    $directories = [
        __DIR__ . '/../models/',
        __DIR__ . '/../controllers/',
        __DIR__ . '/../api/',
        __DIR__ . '/../components/',
        __DIR__ . '/'
    ];

    // Convert namespace separators to directory separators
    $className = str_replace('\\', DIRECTORY_SEPARATOR, $className);

    // Try to load the class from each directory
    foreach ($directories as $directory) {
        $file = $directory . $className . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// Load helpers
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/helpers.php';

// Load SessionManager (now in separate file)
require_once __DIR__ . '/SessionManager.php';

// Load environment variables if .env exists
if (file_exists(__DIR__ . '/../.env')) {
    $env = parse_ini_file(__DIR__ . '/../.env');
    foreach ($env as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
    }
}

// Define constants
define('APP_ROOT', dirname(__DIR__));
define('UPLOAD_PATH', APP_ROOT . '/uploads');
define('LOG_PATH', APP_ROOT . '/logs');
define('ASSETS_URL', './assets');
if (!defined('BASE_URL')) {
define(
    'BASE_URL',
    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') .
    '://' .
    $_SERVER['HTTP_HOST'] .
    str_replace(basename($_SERVER['SCRIPT_NAME']), '', $_SERVER['SCRIPT_NAME'])
);}

// Ensure log directory exists
if (!is_dir(LOG_PATH)) {
    mkdir(LOG_PATH, 0755, true);
}

// Set error logging
ini_set('error_log', LOG_PATH . '/error.log');

// Initialize session manager (this will handle session start)
$session = SessionManager::getInstance();

/**
 * CSRF Protection
 */
class CSRF
{
    private static $tokenLength = 32;
    private static $tokenLifetime = 3600; // 1 hour
    private static $maxTokensPerSession = 10; // Maximum tokens to keep per session

    /**
     * Generate a new CSRF token
     */
    public static function generate(): string
    {
        if (!isset($_SESSION['_csrf_tokens'])) {
            $_SESSION['_csrf_tokens'] = [];
        }

        self::cleanExpiredTokens();
        self::limitTokenCount();

        $token = bin2hex(random_bytes(self::$tokenLength));

        $_SESSION['_csrf_tokens'][$token] = [
            'time' => time(),
            'used' => false
        ];

        return $token;
    }

    /**
     * Generate a token that can be used multiple times (for AJAX forms)
     */
    public static function generateMultiUse(): string
    {
        if (!isset($_SESSION['_csrf_tokens_multi'])) {
            $_SESSION['_csrf_tokens_multi'] = [];
        }

        self::cleanExpiredTokensMulti();

        $token = bin2hex(random_bytes(self::$tokenLength));

        $_SESSION['_csrf_tokens_multi'][$token] = [
            'time' => time(),
            'used_count' => 0,
            'max_uses' => 10 // Can be used up to 10 times
        ];

        return $token;
    }

    /**
     * Generate HTML field for single-use token
     */
    public static function field(): string
    {
        $token = self::generate();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }

    /**
     * Generate HTML field for multi-use token (good for AJAX)
     */
    public static function fieldMultiUse(): string
    {
        $token = self::generateMultiUse();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }

    /**
     * Validate a token (single-use)
     */
    public static function validate($token): bool
    {
        if (empty($token) || !isset($_SESSION['_csrf_tokens'][$token])) {
            self::logAttempt('Invalid token');
            return false;
        }

        $tokenData = $_SESSION['_csrf_tokens'][$token];

        if (time() - $tokenData['time'] > self::$tokenLifetime) {
            unset($_SESSION['_csrf_tokens'][$token]);
            self::logAttempt('Expired token');
            return false;
        }

        if ($tokenData['used']) {
            unset($_SESSION['_csrf_tokens'][$token]);
            self::logAttempt('Token already used - possible replay attack');
            return false;
        }

        $_SESSION['_csrf_tokens'][$token]['used'] = true;
        return true;
    }

    /**
     * Validate a multi-use token (can be used multiple times)
     */
    public static function validateMultiUse($token): bool
    {
        if (empty($token) || !isset($_SESSION['_csrf_tokens_multi'][$token])) {
            self::logAttempt('Invalid multi-use token');
            return false;
        }

        $tokenData = $_SESSION['_csrf_tokens_multi'][$token];

        if (time() - $tokenData['time'] > self::$tokenLifetime) {
            unset($_SESSION['_csrf_tokens_multi'][$token]);
            self::logAttempt('Expired multi-use token');
            return false;
        }

        if ($tokenData['used_count'] >= $tokenData['max_uses']) {
            unset($_SESSION['_csrf_tokens_multi'][$token]);
            self::logAttempt('Multi-use token exceeded max uses');
            return false;
        }

        $_SESSION['_csrf_tokens_multi'][$token]['used_count']++;
        return true;
    }

    /**
     * Validate POST request
     */
    public static function validatePost(): bool
    {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return self::validate($token);
    }

    /**
     * Validate POST request with multi-use token
     */
    public static function validatePostMultiUse(): bool
    {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return self::validateMultiUse($token);
    }

    /**
     * Refresh token after successful form submission
     */
    public static function refresh(): string
    {
        return self::generate();
    }

    /**
     * Get current token or generate new one
     */
    public static function getCurrentToken(): ?string
    {
        if (isset($_SESSION['_current_csrf_token'])) {
            return $_SESSION['_current_csrf_token'];
        }
        return null;
    }

    /**
     * Set current token for session
     */
    public static function setCurrentToken(): string
    {
        $token = self::generate();
        $_SESSION['_current_csrf_token'] = $token;
        return $token;
    }

    /**
     * Validate and refresh token in one call
     */
    public static function validateAndRefresh($token): bool
    {
        if (self::validate($token)) {
            // Token was valid, generate new one for next request
            self::setCurrentToken();
            return true;
        }
        return false;
    }

    /**
     * Clean expired single-use tokens
     */
    private static function cleanExpiredTokens()
    {
        if (!isset($_SESSION['_csrf_tokens'])) {
            return;
        }

        foreach ($_SESSION['_csrf_tokens'] as $token => $data) {
            if (time() - $data['time'] > self::$tokenLifetime) {
                unset($_SESSION['_csrf_tokens'][$token]);
            }
        }
    }

    /**
     * Clean expired multi-use tokens
     */
    private static function cleanExpiredTokensMulti()
    {
        if (!isset($_SESSION['_csrf_tokens_multi'])) {
            return;
        }

        foreach ($_SESSION['_csrf_tokens_multi'] as $token => $data) {
            if (time() - $data['time'] > self::$tokenLifetime) {
                unset($_SESSION['_csrf_tokens_multi'][$token]);
            }
        }
    }

    /**
     * Limit the number of tokens stored per session
     */
    private static function limitTokenCount()
    {
        if (count($_SESSION['_csrf_tokens']) > self::$maxTokensPerSession) {
            // Remove oldest tokens
            $tokens = array_keys($_SESSION['_csrf_tokens']);
            $toRemove = count($_SESSION['_csrf_tokens']) - self::$maxTokensPerSession;

            for ($i = 0; $i < $toRemove; $i++) {
                unset($_SESSION['_csrf_tokens'][$tokens[$i]]);
            }
        }
    }

    /**
     * Log CSRF attempts
     */
    private static function logAttempt($reason)
    {
        error_log(sprintf(
            "[CSRF] %s - IP: %s, User Agent: %s, URI: %s",
            $reason,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            $_SERVER['REQUEST_URI'] ?? 'unknown'
        ));
    }

    /**
     * Generate API token
     */
    public static function generateApiToken($userId): string
    {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+' . ($_ENV['API_KEY_EXPIRY'] ?? 90) . ' days'));

        $db = Database::getInstance();
        $stmt = $db->prepare("
            INSERT INTO api_tokens (user_id, token, expires_at) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$userId, password_hash($token, PASSWORD_DEFAULT), $expires]);

        return $token;
    }

    /**
     * Validate API token
     */
    public static function validateApiToken($token): ?int
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("
            SELECT user_id, token FROM api_tokens 
            WHERE expires_at > NOW() 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute();

        while ($row = $stmt->fetch()) {
            if (password_verify($token, $row['token'])) {
                return $row['user_id'];
            }
        }

        return null;
    }

    /**
     * Clear all tokens for current session (useful on logout)
     */
    public static function clearTokens(): void
    {
        unset($_SESSION['_csrf_tokens']);
        unset($_SESSION['_csrf_tokens_multi']);
        unset($_SESSION['_current_csrf_token']);
    }
}
/**
 * UserPermission Model
 */
class UserPermission
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public static function getAllowedPages($userId): array
    {
        $db = Database::getInstance();

        $stmt = $db->prepare("SELECT user_roles.role_code FROM users JOIN user_roles ON users.role_id = user_roles.id WHERE users.id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['dashboard'];
        }

        $rolePermissions = [
            'ADM' => [
                'dashboard',
                'products',
                'products/add',
                'products/edit',
                'products/view',
                'inventory',
                'inventory/stock',
                'inventory/movements',
                'sales',
                'sales/create',
                'sales/invoices',
                'sales/customers',
                'purchasing',
                'purchasing/orders',
                'purchasing/suppliers',
                'reports',
                'reports/sales',
                'reports/inventory',
                'admin/users',
                'admin/settings'
            ],
            'MGR' => [
                'dashboard',
                'products',
                'products/add',
                'products/edit',
                'products/view',
                'inventory',
                'inventory/stock',
                'inventory/movements',
                'sales',
                'sales/create',
                'sales/invoices',
                'sales/customers',
                'reports',
                'reports/sales',
                'reports/inventory'
            ],
            'SEL' => [
                'dashboard',
                'products',
                'products/view',
                'sales',
                'sales/create',
                'sales/invoices',
                'sales/customers',
                'reports/sales'
            ],
            'VIW' => [
                'dashboard',
                'products',
                'products/view',
                'inventory',
                'sales/invoices',
                'reports/sales'
            ]
        ];

        return $rolePermissions[$user['role_code']] ?? ['dashboard'];
    }

    public function can($userId, $permission): bool
    {
        static $permissions = null;

        if ($permissions === null) {
            $permissions = UserPermission::getAllowedPages($userId);
        }

        return in_array($permission, $permissions);
    }
}


class Validator
{

    /**
     * Sanitize input string
     */
    public static function sanitize($input): string
    {
        if ($input === null) {
            return '';
        }
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Validate email
     */
    public static function email($email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate required field
     */
    public static function required($value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        return !empty($value);
    }

    /**
     * Validate string length
     */
    public static function length($value, $min, $max): bool
    {
        $length = mb_strlen((string) $value, 'UTF-8');
        return $length >= $min && $length <= $max;
    }

    /**
     * Validate min length
     */
    public static function minLength($value, $min): bool
    {
        return mb_strlen((string) $value, 'UTF-8') >= $min;
    }

    /**
     * Validate max length
     */
    public static function maxLength($value, $max): bool
    {
        return mb_strlen((string) $value, 'UTF-8') <= $max;
    }

    /**
     * Validate numeric value
     */
    public static function numeric($value): bool
    {
        return is_numeric($value);
    }

    /**
     * Validate integer
     */
    public static function integer($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    /**
     * Validate float
     */
    public static function float($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_FLOAT) !== false;
    }

    /**
     * Validate min value
     */
    public static function min($value, $min): bool
    {
        if (!is_numeric($value)) {
            return false;
        }
        return (float) $value >= $min;
    }

    /**
     * Validate max value
     */
    public static function max($value, $max): bool
    {
        if (!is_numeric($value)) {
            return false;
        }
        return (float) $value <= $max;
    }

    /**
     * Validate range
     */
    public static function range($value, $min, $max): bool
    {
        return self::min($value, $min) && self::max($value, $max);
    }

    /**
     * Validate date
     */
    public static function date($date, $format = 'Y-m-d'): bool
    {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }

    /**
     * Validate datetime
     */
    public static function datetime($datetime, $format = 'Y-m-d H:i:s'): bool
    {
        $d = DateTime::createFromFormat($format, $datetime);
        return $d && $d->format($format) === $datetime;
    }

    /**
     * Validate time
     */
    public static function time($time, $format = 'H:i'): bool
    {
        $d = DateTime::createFromFormat($format, $time);
        return $d && $d->format($format) === $time;
    }

    /**
     * Validate URL
     */
    public static function url($url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Validate IP address
     */
    public static function ip($ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Validate phone number (simple check)
     */
    public static function phone($phone): bool
    {
        // Remove common phone number characters
        $cleaned = preg_replace('/[^0-9+]/', '', $phone);
        // Check if it's a valid phone number format
        return preg_match('/^[+]?[0-9]{8,15}$/', $cleaned) === 1;
    }

    /**
     * Validate alpha (only letters)
     */
    public static function alpha($value): bool
    {
        return ctype_alpha(str_replace(' ', '', $value));
    }

    /**
     * Validate alphanumeric
     */
    public static function alphanumeric($value): bool
    {
        return ctype_alnum(str_replace(' ', '', $value));
    }

    /**
     * Validate matches pattern
     */
    public static function matches($value, $pattern): bool
    {
        return preg_match($pattern, $value) === 1;
    }

    /**
     * Validate in array
     */
    public static function inArray($value, array $allowed): bool
    {
        return in_array($value, $allowed, true);
    }

    /**
     * Validate file type
     */
    public static function fileType($file, array $allowedTypes): bool
    {
        if (!isset($file['type']) || !isset($file['tmp_name'])) {
            return false;
        }

        // Check MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        return in_array($mimeType, $allowedTypes, true);
    }

    /**
     * Validate file size
     */
    public static function fileSize($file, $maxSize): bool
    {
        if (!isset($file['size'])) {
            return false;
        }
        return $file['size'] <= $maxSize;
    }

    /**
     * Validate image dimensions
     */
    public static function imageDimensions($file, $maxWidth = null, $maxHeight = null, $minWidth = null, $minHeight = null): bool
    {
        if (!isset($file['tmp_name']) || !file_exists($file['tmp_name'])) {
            return false;
        }

        $imageInfo = getimagesize($file['tmp_name']);
        if (!$imageInfo) {
            return false;
        }

        list($width, $height) = $imageInfo;

        if ($maxWidth !== null && $width > $maxWidth) {
            return false;
        }
        if ($maxHeight !== null && $height > $maxHeight) {
            return false;
        }
        if ($minWidth !== null && $width < $minWidth) {
            return false;
        }
        if ($minHeight !== null && $height < $minHeight) {
            return false;
        }

        return true;
    }

    /**
     * Validate password strength
     */
    public static function password($password, $minLength = 8, $requireUppercase = true, $requireLowercase = true, $requireNumber = true, $requireSpecial = true): array
    {
        $errors = [];

        if (strlen($password) < $minLength) {
            $errors[] = "Password must be at least {$minLength} characters long";
        }

        if ($requireUppercase && !preg_match('/[A-Z]/', $password)) {
            $errors[] = "Password must contain at least one uppercase letter";
        }

        if ($requireLowercase && !preg_match('/[a-z]/', $password)) {
            $errors[] = "Password must contain at least one lowercase letter";
        }

        if ($requireNumber && !preg_match('/[0-9]/', $password)) {
            $errors[] = "Password must contain at least one number";
        }

        if ($requireSpecial && !preg_match('/[^a-zA-Z0-9]/', $password)) {
            $errors[] = "Password must contain at least one special character";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'strength' => self::passwordStrength($password)
        ];
    }

    /**
     * Calculate password strength (0-100)
     */
    public static function passwordStrength($password): int
    {
        $strength = 0;

        // Length contribution (up to 40 points)
        $length = strlen($password);
        if ($length >= 8)
            $strength += 20;
        if ($length >= 10)
            $strength += 10;
        if ($length >= 12)
            $strength += 10;

        // Character variety contributions (15 points each)
        if (preg_match('/[A-Z]/', $password))
            $strength += 15;
        if (preg_match('/[a-z]/', $password))
            $strength += 15;
        if (preg_match('/[0-9]/', $password))
            $strength += 15;
        if (preg_match('/[^a-zA-Z0-9]/', $password))
            $strength += 15;

        return min(100, $strength);
    }

    /**
     * Validate multiple fields with rules
     */
    public static function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;
            $fieldRules = is_array($fieldRules) ? $fieldRules : explode('|', $fieldRules);

            foreach ($fieldRules as $rule) {
                $ruleName = $rule;
                $ruleParam = null;

                // Check if rule has parameter (e.g., min:5)
                if (strpos($rule, ':') !== false) {
                    list($ruleName, $ruleParam) = explode(':', $rule, 2);
                }

                $error = self::applyRule($field, $value, $ruleName, $ruleParam, $data);
                if ($error) {
                    $errors[$field][] = $error;
                    // Stop on first error for this field
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Apply a single validation rule
     */
    private static function applyRule($field, $value, $rule, $param = null, $allData = []): ?string
    {
        switch ($rule) {
            case 'required':
                if (!self::required($value)) {
                    return "The {$field} field is required";
                }
                break;

            case 'email':
                if (!empty($value) && !self::email($value)) {
                    return "The {$field} must be a valid email address";
                }
                break;

            case 'numeric':
                if (!empty($value) && !self::numeric($value)) {
                    return "The {$field} must be a number";
                }
                break;

            case 'integer':
                if (!empty($value) && !self::integer($value)) {
                    return "The {$field} must be an integer";
                }
                break;

            case 'min':
                if (!empty($value) && !self::min($value, (float) $param)) {
                    return "The {$field} must be at least {$param}";
                }
                break;

            case 'max':
                if (!empty($value) && !self::max($value, (float) $param)) {
                    return "The {$field} must not exceed {$param}";
                }
                break;

            case 'min_length':
                if (!empty($value) && !self::minLength($value, (int) $param)) {
                    return "The {$field} must be at least {$param} characters";
                }
                break;

            case 'max_length':
                if (!empty($value) && !self::maxLength($value, (int) $param)) {
                    return "The {$field} must not exceed {$param} characters";
                }
                break;

            case 'date':
                if (!empty($value) && !self::date($value)) {
                    return "The {$field} must be a valid date";
                }
                break;

            case 'in':
                $allowed = explode(',', $param);
                if (!empty($value) && !self::inArray($value, $allowed)) {
                    return "The {$field} must be one of: " . implode(', ', $allowed);
                }
                break;

            case 'alpha':
                if (!empty($value) && !self::alpha($value)) {
                    return "The {$field} may only contain letters";
                }
                break;

            case 'alphanumeric':
                if (!empty($value) && !self::alphanumeric($value)) {
                    return "The {$field} may only contain letters and numbers";
                }
                break;

            case 'phone':
                if (!empty($value) && !self::phone($value)) {
                    return "The {$field} must be a valid phone number";
                }
                break;

            case 'confirmed':
                $confirmationField = $param ?: $field . '_confirmation';
                if ($value !== ($allData[$confirmationField] ?? null)) {
                    return "The {$field} confirmation does not match";
                }
                break;

            case 'unique':
                // This would need database access - implement separately
                break;
        }

        return null;
    }
}