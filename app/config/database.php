<?php
// config/database.php
// ============================================
// DATABASE CONNECTION FOR RETAIL SYSTEM
// ============================================

class Database
{
    private static $instance = null;
    private $connection;
    private $host = 'localhost';
    private $dbname = 'u637140770_mpazi';//'u637140770_sati_ERP'; // Your new database name
    private $username = 'root';//'u637140770_sati_ERP';
    private $password = 'root'; //'cyqbix-xafqe6-seXwop';

    private function __construct()
    {
        try {
            $this->connection = new PDO(
                "mysql:host={$this->host};dbname={$this->dbname};charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection error. Please try again later.");
        }
    }

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance->connection;
    }

    public static function closeConnection(): void
    {
        self::$instance = null;
    }

    public static function beginTransaction(): void
    {
        self::getInstance()->beginTransaction();
    }

    public static function commit(): void
    {
        self::getInstance()->commit();
    }

    public static function rollBack(): void
    {
        self::getInstance()->rollBack();
    }
}