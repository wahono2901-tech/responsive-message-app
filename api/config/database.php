<?php
/**
 * Database Configuration
 * File: api/config/database.php
 */

// ============================================
// DATABASE CONFIGURATION
// ============================================
define('DB_HOST', 'localhost');
define('DB_PORT', '3307');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'responsive_message_db');

// ============================================
// APPLICATION CONFIGURATION
// ============================================
define('APP_NAME', 'Responsive Message App');
define('BASE_URL', 'http://localhost:8090/responsive-message-app/');
define('API_URL', BASE_URL . 'api/');
define('ROOT_PATH', $_SERVER['DOCUMENT_ROOT'] . '/responsive-message-app/');

// ============================================
// SESSION CONFIGURATION
// ============================================
define('SESSION_NAME', 'RMSESSID');
define('SESSION_LIFETIME', 86400); // 24 hours

// ============================================
// ERROR REPORTING
// ============================================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', ROOT_PATH . 'logs/error.log');

// ============================================
// DATABASE CLASS
// ============================================
class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $this->connection = new PDO(
                $dsn,
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
}