<?php
/**
 * API Login
 * File: api/auth/login.php
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
header('Access-Control-Allow-Methods: POST, OPTIONS');
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
// KONEKSI DATABASE
// ============================================
$db = Database::getInstance()->getConnection();

// ============================================
// HANDLE LOGIN
// ============================================
try {
    // Ambil input JSON
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid request body');
    }
    
    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        echo json_encode([
            'success' => false,
            'message' => 'Username dan password harus diisi'
        ]);
        exit;
    }
    
    // Cari user di database - SESUAIKAN DENGAN STRUKTUR TABEL
    $sql = "SELECT id, username, email, nama_lengkap, user_type, is_active, nis_nip, 
                   phone_number as no_telp, avatar as foto, password_hash as password 
            FROM users 
            WHERE (username = ? OR email = ?) 
            AND is_active = 1";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode([
            'success' => false,
            'message' => 'Username atau password salah'
        ]);
        exit;
    }
    
    // Verifikasi password - menggunakan password_hash
    if (!password_verify($password, $user['password'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Username atau password salah'
        ]);
        exit;
    }
    
    // Start session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Generate session ID baru
    $sessionId = session_id();
    if (empty($sessionId)) {
        $sessionId = session_create_id();
        session_id($sessionId);
        session_start();
    }
    
    // Simpan data user di session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_type'] = $user['user_type'];
    $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['is_logged_in'] = true;
    
    // Update last_login
    $updateSql = "UPDATE users SET last_login = NOW() WHERE id = ?";
    $updateStmt = $db->prepare($updateSql);
    $updateStmt->execute([$user['id']]);
    
    // Hapus password dari output
    unset($user['password']);
    
    // Response sukses
    echo json_encode([
        'success' => true,
        'message' => 'Login berhasil',
        'session_id' => session_id(),
        'user' => $user
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}