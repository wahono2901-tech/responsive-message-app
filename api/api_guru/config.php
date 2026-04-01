<?php
/**
 * API Configuration for Guru
 * File: api/api_guru/config.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/api_guru_error.log');

// Response helper function
function sendResponse($success, $data = null, $message = '', $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// Auth helper function
function authenticateGuru() {
    $headers = getallheaders();
    $authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';
    
    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $token = $matches[1];
        
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT u.*, ut.auth_token 
            FROM users u
            LEFT JOIN user_tokens ut ON u.id = ut.user_id
            WHERE ut.auth_token = :token AND u.is_active = 1
        ");
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            return $user;
        }
    }
    
    // Check session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (isset($_SESSION['user_id'])) {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = :user_id AND is_active = 1");
        $stmt->execute([':user_id' => $_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            return $user;
        }
    }
    
    return null;
}

// Validate guru access
function validateGuruAccess($user) {
    if (!$user) {
        sendResponse(false, null, 'Unauthorized. Please login.', 401);
    }
    
    $allowedTypes = ['Guru_BK', 'Guru_Humas', 'Guru_Kurikulum', 'Guru_Kesiswaan', 'Guru_Sarana', 'Guru', 'Admin', 'Wakil_Kepala', 'Kepala_Sekolah'];
    if (!in_array($user['user_type'], $allowedTypes)) {
        sendResponse(false, null, 'Access denied. You are not authorized.', 403);
    }
    
    return true;
}
?>