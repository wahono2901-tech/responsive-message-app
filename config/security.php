<?php
/**
 * Security Configuration and Functions
 * File: config/security.php
 */

class Security {
    
    /**
     * Generate CSRF Token
     */
    public static function generateCSRFToken($sessionId) {
        $token = bin2hex(random_bytes(32));
        $hashedToken = hash_hmac('sha256', $token, $sessionId);
        
        // Simpan di session
        $_SESSION['csrf_token'] = $hashedToken;
        $_SESSION['csrf_token_time'] = time();
        
        return $token;
    }
    
    /**
     * Validate CSRF Token
     */
    public static function validateCSRFToken($token) {
        if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
            return false;
        }
        
        // Check token expiration
        if (time() - $_SESSION['csrf_token_time'] > CSRF_TOKEN_LIFETIME) {
            self::clearCSRFToken();
            return false;
        }
        
        // Validate token
        $expected = hash_hmac('sha256', $token, session_id());
        if (!hash_equals($_SESSION['csrf_token'], $expected)) {
            return false;
        }
        
        // Clear token setelah digunakan
        self::clearCSRFToken();
        return true;
    }
    
    /**
     * Clear CSRF Token
     */
    public static function clearCSRFToken() {
        unset($_SESSION['csrf_token']);
        unset($_SESSION['csrf_token_time']);
    }
    
    /**
     * Sanitize input data
     */
    public static function sanitize($data, $type = 'string') {
        if (is_array($data)) {
            return array_map([self::class, 'sanitize'], $data);
        }
        
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        switch ($type) {
            case 'email':
                $data = filter_var($data, FILTER_SANITIZE_EMAIL);
                break;
            case 'int':
                $data = filter_var($data, FILTER_SANITIZE_NUMBER_INT);
                break;
            case 'float':
                $data = filter_var($data, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                break;
            case 'url':
                $data = filter_var($data, FILTER_SANITIZE_URL);
                break;
        }
        
        return $data;
    }
    
    /**
     * Validate email
     */
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
    
    /**
     * Validate URL
     */
    public static function validateURL($url) {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
    
    /**
     * Hash password
     */
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }
    
    /**
     * Verify password
     */
    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    /**
     * Check rate limiting
     */
    public static function checkRateLimit($key, $limit, $timeframe) {
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("
            SELECT COUNT(*) as attempts 
            FROM login_attempts 
            WHERE ip_address = :ip 
            AND attempt_time > DATE_SUB(NOW(), INTERVAL :timeframe SECOND)
        ");
        
        $stmt->execute([
            ':ip' => $_SERVER['REMOTE_ADDR'],
            ':timeframe' => $timeframe
        ]);
        
        $result = $stmt->fetch();
        return $result['attempts'] < $limit;
    }
    
    /**
     * Log login attempt
     */
    public static function logLoginAttempt($username, $success) {
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO login_attempts (username, ip_address, success) 
            VALUES (:username, :ip, :success)
        ");
        
        $stmt->execute([
            ':username' => $username,
            ':ip' => $_SERVER['REMOTE_ADDR'],
            ':success' => $success
        ]);
    }
    
    /**
     * Generate secure random string
     */
    public static function generateRandomString($length = 32) {
        return bin2hex(random_bytes($length / 2));
    }
    
    /**
     * XSS Protection
     */
    public static function xssClean($data) {
        if (is_array($data)) {
            return array_map([self::class, 'xssClean'], $data);
        }
        
        // Remove JavaScript
        $data = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $data);
        
        // Remove event handlers
        $data = preg_replace('#on\w+="[^"]+"#', '', $data);
        $data = preg_replace("#on\w+='[^']+'#", '', $data);
        
        // Remove dangerous tags
        $dangerous_tags = ['iframe', 'object', 'embed', 'base', 'meta', 'link'];
        foreach ($dangerous_tags as $tag) {
            $data = preg_replace("#<{$tag}[^>]*>.*?</{$tag}>#is", '', $data);
        }
        
        return htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}