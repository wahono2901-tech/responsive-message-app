<?php
/**
 * Session Configuration File
 * ULTIMATE FIX: Handles all session states gracefully
 */

// Check if session is already active
$session_active = (session_status() === PHP_SESSION_ACTIVE);

if (!$session_active) {
    // No session active - we can safely configure everything
    
    // Set all session INI settings
    ini_set('session.use_strict_mode', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0);
    ini_set('session.cookie_httponly', 1);
    
    // Set session name
    session_name('RMSESSID');
    
    // Set cookie parameters
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    
    // Start the session
    session_start();
    
    // Regenerate session ID periodically
    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = time();
    } else if (time() - $_SESSION['created'] > 1800) {
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    }
    
    error_log("Session started fresh in session.php");
    
} else {
    // Session already active - we can't change settings
    // But we can still set some things that don't require ini_set
    
    error_log("Session was already active in session.php");
    error_log("Session ID: " . session_id());
    
    // Try to set session name if possible (may fail, but that's OK)
    if (function_exists('session_name') && session_name() !== 'RMSESSID') {
        @session_name('RMSESSID'); // Suppress warnings
    }
    
    // We can't change ini settings, but we can log that they're not set
    error_log("Warning: Some session security settings may not be applied because session started earlier");
}

// Set timeout period (30 minutes)
if (!defined('SESSION_TIMEOUT')) {
    define('SESSION_TIMEOUT', 1800);
}

// Check if session has expired (only if session exists)
if (isset($_SESSION) && isset($_SESSION['login_time'])) {
    if (time() - $_SESSION['login_time'] > SESSION_TIMEOUT) {
        // Session expired
        $_SESSION = array(); // Clear session data
        
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
        
        // Redirect to login with expired message
        $base_url = defined('BASE_URL') ? BASE_URL : '';
        header('Location: ' . $base_url . '/login.php?error=session_expired');
        exit;
    }
}