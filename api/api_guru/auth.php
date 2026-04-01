<?php
/**
 * Auth API for Guru
 * File: api/api_guru/auth.php
 * Endpoints:
 * - POST /api/api_guru/auth.php?action=login
 * - POST /api/api_guru/auth.php?action=logout
 * - GET /api/api_guru/auth.php?action=me
 */

require_once 'config.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'login':
        handleLogin();
        break;
    case 'logout':
        handleLogout();
        break;
    case 'me':
        handleGetMe();
        break;
    default:
        sendResponse(false, null, 'Invalid action', 400);
}

function handleLogin() {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        $input = $_POST;
    }
    
    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        sendResponse(false, null, 'Username and password are required', 400);
    }
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // Find user
        $stmt = $db->prepare("
            SELECT * FROM users 
            WHERE (username = :username OR email = :username) 
            AND is_active = 1
        ");
        $stmt->execute([':username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            sendResponse(false, null, 'Invalid username or password', 401);
        }
        
        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            sendResponse(false, null, 'Invalid username or password', 401);
        }
        
        // Check if user is guru type
        $allowedTypes = ['Guru_BK', 'Guru_Humas', 'Guru_Kurikulum', 'Guru_Kesiswaan', 'Guru_Sarana', 'Guru', 'Admin', 'Wakil_Kepala', 'Kepala_Sekolah'];
        if (!in_array($user['user_type'], $allowedTypes)) {
            sendResponse(false, null, 'Access denied. You are not authorized as a teacher.', 403);
        }
        
        // Update last login
        $updateStmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = :id");
        $updateStmt->execute([':id' => $user['id']]);
        
        // Generate token
        $token = bin2hex(random_bytes(32));
        
        // Save token
        $tokenStmt = $db->prepare("
            INSERT INTO user_tokens (user_id, auth_token, expires_at, created_at)
            VALUES (:user_id, :token, DATE_ADD(NOW(), INTERVAL 30 DAY), NOW())
            ON DUPLICATE KEY UPDATE auth_token = :token, expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY)
        ");
        $tokenStmt->execute([
            ':user_id' => $user['id'],
            ':token' => $token
        ]);
        
        // Set session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_type'] = $user['user_type'];
        $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
        
        // Prepare response data
        $userData = [
            'id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'user_type' => $user['user_type'],
            'nama_lengkap' => $user['nama_lengkap'],
            'nis_nip' => $user['nis_nip'],
            'phone_number' => $user['phone_number'],
            'kelas' => $user['kelas'],
            'jurusan' => $user['jurusan'],
            'privilege_level' => $user['privilege_level'],
            'last_login' => $user['last_login']
        ];
        
        sendResponse(true, [
            'user' => $userData,
            'token' => $token
        ], 'Login successful');
        
    } catch (Exception $e) {
        error_log("Login error: " . $e->getMessage());
        sendResponse(false, null, 'Login failed. Please try again.', 500);
    }
}

function handleLogout() {
    $user = authenticateGuru();
    
    if ($user) {
        // Delete token
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("DELETE FROM user_tokens WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $user['id']]);
        
        // Destroy session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_destroy();
    }
    
    sendResponse(true, null, 'Logout successful');
}

function handleGetMe() {
    $user = authenticateGuru();
    
    if (!$user) {
        sendResponse(false, null, 'Not authenticated', 401);
    }
    
    $userData = [
        'id' => $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'user_type' => $user['user_type'],
        'nama_lengkap' => $user['nama_lengkap'],
        'nis_nip' => $user['nis_nip'],
        'phone_number' => $user['phone_number'],
        'kelas' => $user['kelas'],
        'jurusan' => $user['jurusan'],
        'privilege_level' => $user['privilege_level']
    ];
    
    sendResponse(true, ['user' => $userData], 'Authenticated');
}
?>