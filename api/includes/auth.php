<?php
/**
 * Authentication Helper
 * File: api/includes/auth.php
 */

class Auth {
    /**
     * Check if user is authenticated
     */
    public static function checkAuth() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Unauthorized'
            ]);
            exit;
        }
        
        return $_SESSION;
    }
    
    /**
     * Check if user has admin access
     */
    public static function checkAdmin() {
        $session = self::checkAuth();
        
        if ($session['user_type'] !== 'Admin') {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Access denied. Admin only.'
            ]);
            exit;
        }
        
        return $session;
    }
    
    /**
     * Get current user data from database
     */
    public static function getCurrentUser($db) {
        $session = self::checkAuth();
        
        // SESUAIKAN DENGAN STRUKTUR TABEL
        $sql = "SELECT id, username, email, nama_lengkap, user_type, is_active, nis_nip, 
                       phone_number as no_telp, avatar as foto 
                FROM users 
                WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$session['user_id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}