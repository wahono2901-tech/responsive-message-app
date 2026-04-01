<?php
/**
 * Koneksi Database dengan PDO
 * File: config/database.php
 */

// Load konfigurasi utama terlebih dahulu
// Pastikan path-nya benar, __DIR__ mengacu ke folder config
require_once __DIR__ . '/config.php';

class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            // Pastikan konstanta sudah terdefinisi
            if (!defined('DB_HOST') || !defined('DB_PORT') || !defined('DB_NAME') || !defined('DB_USER')) {
                throw new Exception("Database configuration constants are not defined. Check config.php file.");
            }
            
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => true,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];
            
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
            
        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            error_log("DSN: mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME);
            throw new Exception("Database connection failed. Please check your database configuration.");
        } catch (Exception $e) {
            error_log("Configuration Error: " . $e->getMessage());
            throw $e;
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
    
    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }
    
    public function commit() {
        return $this->connection->commit();
    }
    
    public function rollBack() {
        return $this->connection->rollBack();
    }
    
    /**
     * Execute SELECT query dengan prepared statement
     */
    public function select($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("SELECT Query Error: " . $e->getMessage());
            error_log("SQL: " . $sql);
            error_log("Params: " . print_r($params, true));
            throw $e;
        }
    }
    
    /**
     * Execute SELECT query dengan pagination
     */
    public function selectPaginated($sql, $params = [], $page = 1, $perPage = 10) {
        try {
            // Hitung total records
            $countSql = preg_replace('/SELECT.*?FROM/i', 'SELECT COUNT(*) as total FROM', $sql, 1);
            $countSql = preg_replace('/ORDER BY.*/i', '', $countSql);
            $countResult = $this->select($countSql, $params);
            $total = $countResult[0]['total'] ?? 0;
            
            // Hitung offset
            $offset = ($page - 1) * $perPage;
            $sql .= " LIMIT :offset, :limit";
            $params[':offset'] = $offset;
            $params[':limit'] = $perPage;
            
            $stmt = $this->connection->prepare($sql);
            
            foreach ($params as $key => $value) {
                $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($key, $value, $type);
            }
            
            $stmt->execute();
            $data = $stmt->fetchAll();
            
            return [
                'data' => $data,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => ceil($total / $perPage)
            ];
            
        } catch (PDOException $e) {
            error_log("Paginated Query Error: " . $e->getMessage());
            error_log("SQL: " . $sql);
            throw $e;
        }
    }
    
    /**
     * Execute INSERT/UPDATE/DELETE query
     */
    public function execute($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Execute Query Error: " . $e->getMessage());
            error_log("SQL: " . $sql);
            error_log("Params: " . print_r($params, true));
            throw $e;
        }
    }
    
    /**
     * Get last insert ID
     */
    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }
}
?>