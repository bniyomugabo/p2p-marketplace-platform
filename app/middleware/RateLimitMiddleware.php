<?php
// middleware/RateLimitMiddleware.php
// ============================================
// RATE LIMITING MIDDLEWARE
// ============================================

class RateLimitMiddleware
{
    private static $maxAttempts;
    private static $decayMinutes;

    public function __construct()
    {
        self::$maxAttempts = $_ENV['RATE_LIMIT_MAX_ATTEMPTS'] ?? 5;
        self::$decayMinutes = $_ENV['RATE_LIMIT_DECAY_MINUTES'] ?? 15;
    }

    public static function check($key, $maxAttempts = null, $decayMinutes = null): bool
    {
        if (!($_ENV['RATE_LIMIT_ENABLED'] ?? true)) {
            return true;
        }

        $maxAttempts = $maxAttempts ?? self::$maxAttempts;
        $decayMinutes = $decayMinutes ?? self::$decayMinutes;

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $cacheKey = 'rate_limit:' . $key . ':' . $ip;

        $attempts = self::getAttempts($cacheKey);

        if (count($attempts) >= $maxAttempts) {
            // Check if oldest attempt is still within decay window
            $oldest = min($attempts);
            if (time() - $oldest < $decayMinutes * 60) {
                self::logRateLimit($key, $ip);
                return false;
            }
            // Remove expired attempts
            self::cleanAttempts($cacheKey, $decayMinutes);
        }

        // Add current attempt
        self::addAttempt($cacheKey);

        return true;
    }

    private static function getAttempts($key): array
    {
        return $_SESSION['_rate_limits'][$key] ?? [];
    }

    private static function addAttempt($key): void
    {
        if (!isset($_SESSION['_rate_limits'][$key])) {
            $_SESSION['_rate_limits'][$key] = [];
        }

        $_SESSION['_rate_limits'][$key][] = time();

        // Keep only last 100 attempts per key to prevent memory issues
        if (count($_SESSION['_rate_limits'][$key]) > 100) {
            array_shift($_SESSION['_rate_limits'][$key]);
        }
    }

    private static function cleanAttempts($key, $decayMinutes): void
    {
        if (!isset($_SESSION['_rate_limits'][$key])) {
            return;
        }

        $cutoff = time() - ($decayMinutes * 60);
        $_SESSION['_rate_limits'][$key] = array_filter(
            $_SESSION['_rate_limits'][$key],
            function ($timestamp) use ($cutoff) {
                return $timestamp > $cutoff;
            }
        );
    }

    private static function logRateLimit($key, $ip): void
    {
        error_log(sprintf(
            "[RATE LIMIT] Key: %s, IP: %s, URI: %s",
            $key,
            $ip,
            $_SERVER['REQUEST_URI'] ?? 'unknown'
        ));
    }

    public static function throttle($key, $maxAttempts = null, $decayMinutes = null)
    {
        if (!self::check($key, $maxAttempts, $decayMinutes)) {
            http_response_code(429);
            header('Retry-After: ' . ($decayMinutes ?? 15) * 60);

            if (self::isApiRequest()) {
                header('Content-Type: application/json');
                echo json_encode([
                    'error' => 'Too many requests',
                    'code' => 'RATE_LIMITED',
                    'retry_after' => ($decayMinutes ?? 15) * 60
                ]);
            } else {
                echo '<h1>429 - Too Many Requests</h1>';
                echo '<p>Please try again later.</p>';
            }
            exit;
        }
    }

    private static function isApiRequest(): bool
    {
        return strpos($_SERVER['REQUEST_URI'], '/api/') !== false;
    }
}