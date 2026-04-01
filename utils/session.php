<?php
/**
 * Session Manager
 * File: responsive-message-app/utils/session.php
 * 
 * VERSI: 2.1 - Dengan penanganan session yang lebih robust dan debugging minimal
 */

// Pastikan session dikonfigurasi dengan benar SEBELUM session_start()
if (session_status() === PHP_SESSION_NONE) {
    // Set session cookie parameters - HARUS sebelum session_start()
    session_set_cookie_params([
        'lifetime' => 86400 * 7, // 7 hari
        'path' => '/',
        'domain' => '',
        'secure' => false, // Set true jika pakai HTTPS
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    session_start();
}

class SessionManager {
    private static $instance = null;
    
    private function __construct() {
        // Constructor private untuk singleton
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function checkSession() {
        // Pastikan session sudah dimulai
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Debugging minimal - hanya log session ID
        error_log("checkSession - Session ID: " . session_id());
        
        if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
            error_log("checkSession failed: user_id not set");
            return false;
        }
        
        return [
            'user_id' => $_SESSION['user_id'],
            'user_type' => $_SESSION['user_type'] ?? 'User',
            'user_name' => $_SESSION['user_name'] ?? $_SESSION['username'] ?? 'Unknown'
        ];
    }
    
    public function setSession($userId, $userType, $userName) {
        // Pastikan session sudah dimulai
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_type'] = $userType;
        $_SESSION['user_name'] = $userName;
        $_SESSION['username'] = $userName; // Untuk kompatibilitas dengan kode lama
        
        error_log("Session set - ID: " . session_id() . " for user: $userId");
    }
    
    public function destroySession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_destroy();
        error_log("Session destroyed");
    }
    
    public function regenerateSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_regenerate_id(true);
        error_log("Session regenerated: " . session_id());
    }
    
    public function getSessionId() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return session_id();
    }
}
?>