<?php
/**
 * Verify Session
 * File: api/auth/verify.php
 */

// ============================================
// SET ERROR REPORTING
// ============================================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ============================================
// HEADERS
// ============================================
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cookie');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================
// INCLUDE REQUIRED FILES
// ============================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// ============================================
// HANDLE VERIFICATION
// ============================================
try {
    // Start session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Cek apakah user sudah login
    if (isset($_SESSION['user_id']) && isset($_SESSION['is_logged_in']) && $_SESSION['is_logged_in'] === true) {
        
        // Ambil data user terbaru dari database - SESUAIKAN DENGAN STRUKTUR TABEL
        $db = Database::getInstance()->getConnection();
        $sql = "SELECT id, username, email, nama_lengkap, user_type, is_active, nis_nip, 
                       phone_number as no_telp, avatar as foto 
                FROM users 
                WHERE id = ? AND is_active = 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            echo json_encode([
                'success' => true,
                'message' => 'Session valid',
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'nama_lengkap' => $user['nama_lengkap'],
                    'user_type' => $user['user_type'],
                    'is_active' => $user['is_active'],
                    'nis_nip' => $user['nis_nip'],
                    'no_telp' => $user['no_telp'],
                    'foto' => $user['foto']
                ]
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'User not found or inactive'
            ]);
        }
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Session invalid'
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}