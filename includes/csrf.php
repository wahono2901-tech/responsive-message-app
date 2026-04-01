<?php
/**
 * CSRF Protection Functions - Single Source of Truth
 * File: includes/csrf.php
 */

if (!function_exists('generateCsrfToken')) {
    /**
     * Generate CSRF token
     */
    function generateCsrfToken() {
        if (!isset($_SESSION['csrf_token']) || empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('verifyCsrfToken')) {
    /**
     * Verifikasi CSRF token
     */
    function verifyCsrfToken($token) {
        if (!isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
            error_log("CSRF verification failed - Token mismatch");
            return false;
        }
        
        // Token expired after 2 hours
        if (isset($_SESSION['csrf_token_time']) && (time() - $_SESSION['csrf_token_time'] > 7200)) {
            error_log("CSRF verification failed - Token expired");
            return false;
        }
        
        return true;
    }
}

if (!function_exists('refreshCsrfToken')) {
    /**
     * Refresh CSRF token (after successful login)
     */
    function refreshCsrfToken() {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();
        return $_SESSION['csrf_token'];
    }
}