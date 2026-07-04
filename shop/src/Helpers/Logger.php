<?php
// /src/Helpers/Logger.php
// Comprehensive logging class for debugging

class Logger {
    private static $instance = null;
    private $logDir;
    private $errorLog;
    private $apiLog;
    private $dbLog;
    private $enabled = true;
    
    // Log levels
    const LEVEL_ERROR = 'ERROR';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_INFO = 'INFO';
    const LEVEL_DEBUG = 'DEBUG';
    const LEVEL_SQL = 'SQL';
    const LEVEL_API = 'API';
    
    private function __construct() {
        $this->logDir = __DIR__ . '/../../logs/';
        
        // Create logs directory if it doesn't exist
        if (!file_exists($this->logDir)) {
            mkdir($this->logDir, 0777, true);
        }
        
        // Define log files
        $this->errorLog = $this->logDir . 'error.log';
        $this->apiLog = $this->logDir . 'api.log';
        $this->dbLog = $this->logDir . 'database.log';
        
        // Rotate logs if they're too large (10MB)
        $this->rotateLogs();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Enable or disable logging
     */
    public function setEnabled($enabled) {
        $this->enabled = $enabled;
    }
    
    /**
     * Rotate logs if they exceed max size
     */
    private function rotateLogs() {
        $maxSize = 10 * 1024 * 1024; // 10MB
        
        foreach ([$this->errorLog, $this->apiLog, $this->dbLog] as $logFile) {
            if (file_exists($logFile) && filesize($logFile) > $maxSize) {
                $backup = $logFile . '.' . date('Y-m-d-His');
                rename($logFile, $backup);
                
                // Keep only last 5 backups
                $backups = glob($logFile . '.*');
                if (count($backups) > 5) {
                    $oldest = array_shift($backups);
                    unlink($oldest);
                }
            }
        }
    }
    
    /**
     * Write to error log
     */
    public function error($message, $context = []) {
        if (!$this->enabled) return;
        $this->write($this->errorLog, self::LEVEL_ERROR, $message, $context);
    }
    
    /**
     * Write to warning log
     */
    public function warning($message, $context = []) {
        if (!$this->enabled) return;
        $this->write($this->errorLog, self::LEVEL_WARNING, $message, $context);
    }
    
    /**
     * Write to info log
     */
    public function info($message, $context = []) {
        if (!$this->enabled) return;
        $this->write($this->errorLog, self::LEVEL_INFO, $message, $context);
    }
    
    /**
     * Write to debug log
     */
    public function debug($message, $context = []) {
        if (!$this->enabled) return;
        $this->write($this->errorLog, self::LEVEL_DEBUG, $message, $context);
    }
    
    /**
     * Write to API log
     */
    public function api($message, $context = []) {
        if (!$this->enabled) return;
        $this->write($this->apiLog, self::LEVEL_API, $message, $context);
    }
    
    /**
     * Write to SQL log
     */
    public function sql($message, $context = []) {
        if (!$this->enabled) return;
        $this->write($this->dbLog, self::LEVEL_SQL, $message, $context);
    }
    
    /**
     * Log API request
     */
    public function logApiRequest($endpoint, $method, $requestData = null, $responseData = null, $statusCode = null) {
        $context = [
            'endpoint' => $endpoint,
            'method' => $method,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        if ($requestData) {
            // Sanitize sensitive data
            $sanitized = $this->sanitizeData($requestData);
            $context['request'] = $sanitized;
        }
        
        if ($responseData) {
            $context['response'] = $responseData;
        }
        
        if ($statusCode) {
            $context['status_code'] = $statusCode;
        }
        
        $message = "API {$method} request to {$endpoint}";
        $this->api($message, $context);
    }
    
    /**
     * Log database query
     */
    public function logQuery($sql, $params = [], $duration = null) {
        $context = [
            'sql' => $sql,
            'params' => $params,
            'duration_ms' => $duration
        ];
        $this->sql("SQL Query executed", $context);
    }
    
    /**
     * Log user action
     */
    public function logUserAction($action, $userId = null, $details = []) {
        $context = [
            'user_id' => $userId ?? SessionManager::get('customer_id'),
            'action' => $action,
            'details' => $details,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];
        $this->info("User action: {$action}", $context);
    }
    
    /**
     * Log registration attempt
     */
    public function logRegistration($email, $success, $error = null) {
        $context = [
            'email' => $email,
            'success' => $success,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];
        
        if ($error) {
            $context['error'] = $error;
        }
        
        $this->api("Registration attempt: " . ($success ? "SUCCESS" : "FAILED"), $context);
    }
    
    /**
     * Log login attempt
     */
    public function logLogin($email, $success, $error = null) {
        $context = [
            'email' => $email,
            'success' => $success,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];
        
        if ($error) {
            $context['error'] = $error;
        }
        
        $this->api("Login attempt: " . ($success ? "SUCCESS" : "FAILED"), $context);
    }
    
    /**
     * Log error from API
     */
    public function logApiError($endpoint, $error, $trace = null) {
        $context = [
            'endpoint' => $endpoint,
            'error' => $error,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];
        
        if ($trace && DEBUG_MODE) {
            $context['trace'] = $trace;
        }
        
        $this->error("API Error: {$endpoint}", $context);
    }
    
    /**
     * Sanitize sensitive data from logs
     */
    private function sanitizeData($data) {
        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                $sensitiveKeys = ['password', 'confirm_password', 'csrf_token', 'token', 'api_key', 'secret'];
                if (in_array(strtolower($key), $sensitiveKeys)) {
                    $sanitized[$key] = '[REDACTED]';
                } else {
                    $sanitized[$key] = $this->sanitizeData($value);
                }
            }
            return $sanitized;
        }
        return $data;
    }
    
    /**
     * Write to log file
     */
    private function write($file, $level, $message, $context = []) {
        $timestamp = date('Y-m-d H:i:s');
        $pid = getmypid();
        
        $logEntry = [
            'timestamp' => $timestamp,
            'pid' => $pid,
            'level' => $level,
            'message' => $message
        ];
        
        if (!empty($context)) {
            $logEntry['context'] = $context;
        }
        
        $logLine = json_encode($logEntry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
        
        file_put_contents($file, $logLine, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Get recent logs
     */
    public function getRecentLogs($type = 'error', $limit = 100) {
    $logFile = $this->getLogFile($type);

    if (!file_exists($logFile)) {
        return [];
    }

    // Read entire file
    $content = file_get_contents($logFile);

    // Split entries by blank lines
    $entries = preg_split('/\n\s*\n/', trim($content));

    $logs = [];

    foreach ($entries as $entry) {
        $decoded = json_decode(trim($entry), true);

        if (json_last_error() === JSON_ERROR_NONE) {
            $logs[] = $decoded;
        }
    }

    // Get latest entries
    $logs = array_slice($logs, -$limit);

    return array_reverse($logs);
}
    
    /**
     * Get log file path by type
     */
    private function getLogFile($type) {
        switch ($type) {
            case 'api':
                return $this->apiLog;
            case 'database':
            case 'sql':
                return $this->dbLog;
            default:
                return $this->errorLog;
        }
    }
    
    /**
     * Clear logs
     */
    public function clearLogs($type = null) {
        if ($type === null) {
            foreach ([$this->errorLog, $this->apiLog, $this->dbLog] as $log) {
                if (file_exists($log)) {
                    file_put_contents($log, '');
                }
            }
        } else {
            $logFile = $this->getLogFile($type);
            if (file_exists($logFile)) {
                file_put_contents($logFile, '');
            }
        }
    }
    
    /**
     * Get log file size
     */
    public function getLogSize($type = 'error') {
        $logFile = $this->getLogFile($type);
        if (file_exists($logFile)) {
            return filesize($logFile);
        }
        return 0;
    }
    
    /**
     * Export logs for debugging
     */
    public function exportLogs($type = 'error') {
        $logFile = $this->getLogFile($type);
        if (!file_exists($logFile)) {
            return "No logs available";
        }
        return file_get_contents($logFile);
    }
}