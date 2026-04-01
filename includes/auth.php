<?php
/**
 * Authentication Functions
 * File: includes/auth.php
 * 
 * Menggabungkan fungsi autentikasi class-based dan function-based
 * PERBAIKAN: Session handling yang lebih robust untuk Admin
 */

// Required files
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

// ============================================
// CLASS-BASED AUTHENTICATION (Original + Perbaikan)
// ============================================

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Check if user is logged in
     * PERBAIKAN: Validasi session yang lebih baik
     */
    public static function checkAuth() {
        // Pastikan session dimulai
        if (session_status() === PHP_SESSION_NONE) {
            // Set session parameters untuk keamanan
            ini_set('session.cookie_httponly', 1);
            ini_set('session.use_only_cookies', 1);
            session_start();
        }
        
        // Validasi session
        if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
            error_log("Auth check failed: user_id not set in session");
            error_log("Session data keys: " . print_r(array_keys($_SESSION), true));
            
            // Redirect ke login
            $base_url = defined('BASE_URL') ? BASE_URL : '/responsive-message-app/';
            header('Location: ' . $base_url . 'index.php?error=session_expired');
            exit;
        }
        
        // Validasi tipe user
        if (!isset($_SESSION['user_type']) || empty($_SESSION['user_type'])) {
            error_log("Auth check failed: user_type not set for user_id: " . $_SESSION['user_id']);
            $base_url = defined('BASE_URL') ? BASE_URL : '/responsive-message-app/';
            header('Location: ' . $base_url . 'index.php?error=invalid_session');
            exit;
        }
        
        // Check session expiration
        if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > SESSION_LIFETIME)) {
            error_log("Session expired for user_id: " . $_SESSION['user_id']);
            self::logout();
            $base_url = defined('BASE_URL') ? BASE_URL : '/responsive-message-app/';
            header('Location: ' . $base_url . 'index.php?expired=1');
            exit;
        }
        
        // Refresh login time
        $_SESSION['login_time'] = time();
        
        return true;
    }
    
    /**
     * Check if user has admin access
     * PERBAIKAN: Validasi admin yang lebih baik
     */
    public static function checkAdmin() {
        self::checkAuth();
        
        $user_type = $_SESSION['user_type'] ?? '';
        $privilege_level = $_SESSION['privilege_level'] ?? '';
        
        // Admin bisa dari user_type 'Admin' atau privilege_level 'Full_Access'
        if ($user_type !== 'Admin' && $privilege_level !== 'Full_Access') {
            error_log("Admin check failed for user: " . ($_SESSION['username'] ?? 'unknown') . 
                      " (type: $user_type, privilege: $privilege_level)");
            
            $base_url = defined('BASE_URL') ? BASE_URL : '/responsive-message-app/';
            header('Location: ' . $base_url . 'modules/dashboard.php?error=access_denied');
            exit;
        }
        
        return true;
    }
    
    /**
     * Check user privilege level
     */
    public static function checkPrivilege($requiredLevel) {
        $levels = [
            'Limited_Lv3' => 1,
            'Limited_Lv2' => 2,
            'Limited_Lv1' => 3,
            'Full_Access' => 4
        ];
        
        $userLevel = $_SESSION['privilege_level'] ?? 'Limited_Lv3';
        
        if (($levels[$userLevel] ?? 0) < ($levels[$requiredLevel] ?? 0)) {
            error_log("Privilege check failed: user has $userLevel, required $requiredLevel");
            $base_url = defined('BASE_URL') ? BASE_URL : '/responsive-message-app/';
            header('Location: ' . $base_url . 'modules/dashboard.php?error=access_denied');
            exit;
        }
    }
    
    /**
     * Login user
     * PERBAIKAN: Session handling yang lebih robust
     */
    public static function login($user_id, $user_data) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Regenerasi session ID untuk keamanan
        session_regenerate_id(true);
        
        // Set session data
        $_SESSION['user_id'] = (int)$user_id;
        $_SESSION['user_type'] = $user_data['user_type'] ?? '';
        $_SESSION['privilege_level'] = $user_data['privilege_level'] ?? 'Limited_Lv3';
        $_SESSION['username'] = $user_data['username'] ?? '';
        $_SESSION['nama_lengkap'] = $user_data['nama_lengkap'] ?? '';
        $_SESSION['nis_nip'] = $user_data['nis_nip'] ?? '';
        $_SESSION['email'] = $user_data['email'] ?? '';
        $_SESSION['kelas'] = $user_data['kelas'] ?? '';
        $_SESSION['jurusan'] = $user_data['jurusan'] ?? '';
        $_SESSION['phone_number'] = $user_data['phone_number'] ?? '';
        $_SESSION['login_time'] = time();
        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // Set session cookie secure jika HTTPS
        if (isset($_SERVER['HTTPS'])) {
            setcookie(session_name(), session_id(), [
                'expires' => time() + SESSION_LIFETIME,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
        }
        
        error_log("User logged in: user_id=$user_id, type={$_SESSION['user_type']}, session_id=" . session_id());
        
        return true;
    }
    
    /**
     * Logout user
     * PERBAIKAN: Bersihkan semua session data
     */
    public static function logout() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Log audit sebelum logout
        if (isset($_SESSION['user_id'])) {
            $auth = new self();
            $auth->logAudit($_SESSION['user_id'], 'LOGOUT', 'users', $_SESSION['user_id'], null, [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'session_id' => session_id()
            ]);
        }
        
        // Hapus semua session data
        $_SESSION = array();
        
        // Hapus session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        // Hancurkan session
        session_destroy();
        
        error_log("User logged out, session destroyed");
        
        return true;
    }
    
    /**
     * Get current user data
     * PERBAIKAN: Validasi session yang lebih baik
     */
    public static function getCurrentUser() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
            return null;
        }
        
        return [
            'id' => (int)$_SESSION['user_id'],
            'user_type' => $_SESSION['user_type'] ?? '',
            'privilege_level' => $_SESSION['privilege_level'] ?? 'Limited_Lv3',
            'username' => $_SESSION['username'] ?? '',
            'nama_lengkap' => $_SESSION['nama_lengkap'] ?? '',
            'nis_nip' => $_SESSION['nis_nip'] ?? '',
            'email' => $_SESSION['email'] ?? '',
            'kelas' => $_SESSION['kelas'] ?? '',
            'jurusan' => $_SESSION['jurusan'] ?? '',
            'phone_number' => $_SESSION['phone_number'] ?? '',
            'login_time' => $_SESSION['login_time'] ?? null,
            'ip_address' => $_SESSION['ip_address'] ?? ''
        ];
    }
    
    /**
     * Register new user
     */
    public function register($data) {
        try {
            // Validasi input
            $errors = $this->validateRegistration($data);
            if (!empty($errors)) {
                return ['success' => false, 'errors' => $errors];
            }
            
            // Cek username/email sudah ada
            if ($this->userExists($data['username'], $data['email'])) {
                return ['success' => false, 'errors' => ['username' => 'Username atau email sudah terdaftar']];
            }
            
            // Hash password
            $passwordHash = Security::hashPassword($data['password']);
            
            // Begin transaction
            Database::getInstance()->beginTransaction();
            
            // Insert user
            $sql = "INSERT INTO users (
                username, password_hash, email, user_type, nis_nip, 
                nama_lengkap, kelas, jurusan, phone_number, privilege_level
            ) VALUES (
                :username, :password_hash, :email, :user_type, :nis_nip,
                :nama_lengkap, :kelas, :jurusan, :phone_number, :privilege_level
            )";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':username' => Security::sanitize($data['username']),
                ':password_hash' => $passwordHash,
                ':email' => Security::sanitize($data['email'], 'email'),
                ':user_type' => Security::sanitize($data['user_type']),
                ':nis_nip' => Security::sanitize($data['nis_nip']),
                ':nama_lengkap' => Security::sanitize($data['nama_lengkap']),
                ':kelas' => isset($data['kelas']) ? Security::sanitize($data['kelas']) : null,
                ':jurusan' => isset($data['jurusan']) ? Security::sanitize($data['jurusan']) : null,
                ':phone_number' => isset($data['phone_number']) ? Security::sanitize($data['phone_number']) : null,
                ':privilege_level' => $data['privilege_level'] ?? 'Limited_Lv3'
            ]);
            
            $userId = $this->db->lastInsertId();
            
            // Log audit
            $this->logAudit($userId, 'REGISTER', 'users', $userId, null, [
                'username' => $data['username'],
                'user_type' => $data['user_type']
            ]);
            
            Database::getInstance()->commit();
            
            return [
                'success' => true,
                'user_id' => $userId,
                'message' => 'Registrasi berhasil! Silakan login.'
            ];
            
        } catch (Exception $e) {
            Database::getInstance()->rollBack();
            error_log("Registration Error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['system' => 'Terjadi kesalahan sistem. Silakan coba lagi.']];
        }
    }
    
    /**
     * Login user dengan username/password
     */
    public function loginWithPassword($username, $password) {
        try {
            // Cek rate limiting
            if (!Security::checkRateLimit('login', MAX_LOGIN_ATTEMPTS, LOCKOUT_TIME)) {
                Security::logLoginAttempt($username, false);
                return [
                    'success' => false,
                    'errors' => ['system' => 'Terlalu banyak percobaan login. Coba lagi dalam 15 menit.']
                ];
            }
            
            // Cari user
            $sql = "SELECT * FROM users WHERE (username = :username OR email = :username) AND is_active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch();
            
            if (!$user) {
                Security::logLoginAttempt($username, false);
                return ['success' => false, 'errors' => ['username' => 'Username atau password salah']];
            }
            
            // Verifikasi password
            if (!Security::verifyPassword($password, $user['password_hash'])) {
                Security::logLoginAttempt($username, false);
                return ['success' => false, 'errors' => ['username' => 'Username atau password salah']];
            }
            
            // Update last login
            $updateSql = "UPDATE users SET last_login = NOW() WHERE id = :id";
            $updateStmt = $this->db->prepare($updateSql);
            $updateStmt->execute([':id' => $user['id']]);
            
            // Set session
            self::login($user['id'], [
                'user_type' => $user['user_type'],
                'privilege_level' => $user['privilege_level'],
                'username' => $user['username'],
                'nama_lengkap' => $user['nama_lengkap'],
                'nis_nip' => $user['nis_nip'],
                'email' => $user['email'],
                'kelas' => $user['kelas'],
                'jurusan' => $user['jurusan'],
                'phone_number' => $user['phone_number']
            ]);
            
            // Log audit
            $this->logAudit($user['id'], 'LOGIN', 'users', $user['id'], null, [
                'ip' => $_SERVER['REMOTE_ADDR'],
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
            
            Security::logLoginAttempt($username, true);
            
            return ['success' => true, 'user' => $user];
            
        } catch (Exception $e) {
            error_log("Login Error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['system' => 'Terjadi kesalahan sistem. Silakan coba lagi.']];
        }
    }
    
    /**
     * Validate registration data
     */
    private function validateRegistration($data) {
        $errors = [];
        
        // Required fields
        $required = ['username', 'password', 'confirm_password', 'email', 'user_type', 'nama_lengkap'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $errors[$field] = "Field ini wajib diisi";
            }
        }
        
        // Email validation
        if (!empty($data['email']) && !Security::validateEmail($data['email'])) {
            $errors['email'] = "Email tidak valid";
        }
        
        // Password validation
        if (!empty($data['password'])) {
            if (strlen($data['password']) < PASSWORD_MIN_LENGTH) {
                $errors['password'] = "Password minimal " . PASSWORD_MIN_LENGTH . " karakter";
            }
            if ($data['password'] !== $data['confirm_password']) {
                $errors['confirm_password'] = "Konfirmasi password tidak sesuai";
            }
        }
        
        // NIS/NIP validation based on user type
        if (!empty($data['user_type'])) {
            switch ($data['user_type']) {
                case 'Siswa':
                    if (empty($data['nis_nip']) || !preg_match('/^\d{8}$/', $data['nis_nip'])) {
                        $errors['nis_nip'] = "NIS harus 8 digit angka";
                    }
                    if (empty($data['kelas'])) {
                        $errors['kelas'] = "Kelas wajib diisi untuk siswa";
                    }
                    break;
                case 'Guru':
                case 'Guru_BK':
                case 'Guru_Humas':
                case 'Guru_Kurikulum':
                case 'Guru_Kesiswaan':
                case 'Guru_Sarana':
                case 'Wakil_Kepala':
                case 'Kepala_Sekolah':
                    if (empty($data['nis_nip']) || !preg_match('/^\d{9}$/', $data['nis_nip'])) {
                        $errors['nis_nip'] = "NIP harus 9 digit angka";
                    }
                    break;
            }
        }
        
        return $errors;
    }
    
    /**
     * Check if user exists
     */
    private function userExists($username, $email) {
        $sql = "SELECT COUNT(*) as count FROM users WHERE username = :username OR email = :email";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':username' => $username,
            ':email' => $email
        ]);
        $result = $stmt->fetch();
        return $result['count'] > 0;
    }
    
    /**
     * Log audit trail
     */
    private function logAudit($userId, $action, $table, $recordId, $oldValue = null, $newValue = null) {
        try {
            // Cek jika tabel system_logs ada
            $checkTable = $this->db->query("SHOW TABLES LIKE 'system_logs'");
            if ($checkTable->rowCount() == 0) {
                return; // Skip jika tabel tidak ada
            }
            
            $sql = "INSERT INTO system_logs (
                user_id, action_type, table_name, record_id, 
                old_value, new_value, description, ip_address, user_agent
            ) VALUES (
                :user_id, :action, :table_name, :record_id,
                :old_value, :new_value, :description, :ip, :user_agent
            )";
            
            $description = is_array($newValue) ? json_encode($newValue) : $newValue;
            if (is_string($description)) {
                $description = substr($description, 0, 500);
            }
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':user_id' => $userId,
                ':action' => $action,
                ':table_name' => $table,
                ':record_id' => $recordId,
                ':old_value' => $oldValue ? json_encode($oldValue) : null,
                ':new_value' => $newValue ? json_encode($newValue) : null,
                ':description' => $description,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            error_log("Audit Log Error: " . $e->getMessage());
        }
    }
    
    /**
     * Request password reset
     */
    public function requestPasswordReset($email) {
        try {
            $sql = "SELECT id, username, nama_lengkap FROM users WHERE email = :email AND is_active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return ['success' => false, 'message' => 'Email tidak ditemukan'];
            }
            
            // Generate reset token
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600); // 1 jam
            
            // Save token
            $updateSql = "UPDATE users SET reset_token = :token, reset_expires = :expires WHERE id = :id";
            $updateStmt = $this->db->prepare($updateSql);
            $updateStmt->execute([
                ':token' => hash('sha256', $token),
                ':expires' => $expires,
                ':id' => $user['id']
            ]);
            
            // Send reset email
            $resetLink = BASE_URL . "reset_password.php?token=" . urlencode($token) . "&id=" . $user['id'];
            
            // Log reset request
            $this->logAudit($user['id'], 'PASSWORD_RESET_REQUEST', 'users', $user['id'], null, ['email' => $email]);
            
            return [
                'success' => true,
                'message' => 'Link reset password telah dikirim ke email Anda',
                'reset_link' => $resetLink // Untuk development, hapus di production
            ];
            
        } catch (Exception $e) {
            error_log("Password Reset Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Terjadi kesalahan sistem'];
        }
    }
}

// ============================================
// FUNCTION-BASED AUTHENTICATION (For API)
// ============================================

/**
 * Verify user authentication from token or session
 * 
 * @return array|false User data if authenticated, false otherwise
 */
function verifyAuth() {
    // Start session if not started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $db = Database::getInstance()->getConnection();
    
    // Check for Bearer token in Authorization header
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = isset($headers['Authorization']) ? $headers['Authorization'] : '';
    
    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $token = $matches[1];
        
        // Verify token
        $stmt = $db->prepare("
            SELECT u.*
            FROM users u
            WHERE u.auth_token = :token AND u.is_active = 1
        ");
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            return $user;
        }
    }
    
    // Check for session authentication
    if (isset($_SESSION['user_id'])) {
        $stmt = $db->prepare("
            SELECT u.*
            FROM users u
            WHERE u.id = :user_id AND u.is_active = 1
        ");
        $stmt->execute([':user_id' => $_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            return $user;
        }
    }
    
    // Check for session ID in cookie
    if (isset($_COOKIE['RMSESSID']) || isset($_COOKIE['PHPSESSID'])) {
        $sessionId = isset($_COOKIE['RMSESSID']) ? $_COOKIE['RMSESSID'] : $_COOKIE['PHPSESSID'];
        
        if ($sessionId && session_id() !== $sessionId) {
            session_id($sessionId);
            session_start();
            
            if (isset($_SESSION['user_id'])) {
                $stmt = $db->prepare("
                    SELECT u.*
                    FROM users u
                    WHERE u.id = :user_id AND u.is_active = 1
                ");
                $stmt->execute([':user_id' => $_SESSION['user_id']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    return $user;
                }
            }
        }
    }
    
    return false;
}

/**
 * Generate authentication token
 * 
 * @param int $userId User ID
 * @return string Generated token
 */
function generateAuthToken($userId) {
    $token = bin2hex(random_bytes(32));
    $db = Database::getInstance()->getConnection();
    
    $stmt = $db->prepare("UPDATE users SET auth_token = :token WHERE id = :user_id");
    $stmt->execute([
        ':token' => $token,
        ':user_id' => $userId
    ]);
    
    return $token;
}

/**
 * Check if user has specific role
 * 
 * @param array $user User data
 * @param string|array $roles Required role(s)
 * @return bool
 */
function hasRole($user, $roles) {
    if (!$user) return false;
    
    $roles = is_array($roles) ? $roles : [$roles];
    return in_array($user['user_type'], $roles);
}

/**
 * Check if user has permission for specific action
 * 
 * @param array $user User data
 * @param string $permission Permission code
 * @return bool
 */
function hasPermission($user, $permission) {
    if (!$user) return false;
    
    // Admin has all permissions
    if (in_array($user['user_type'], ['Admin', 'admin'])) {
        return true;
    }
    
    // Get permissions from database
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM permissions 
            WHERE user_type = :user_type AND permission_code = :permission
        ");
        $stmt->execute([
            ':user_type' => $user['user_type'],
            ':permission' => $permission
        ]);
        $result = $stmt->fetch();
        return $result['count'] > 0;
    } catch (Exception $e) {
        error_log("Error checking permission: " . $e->getMessage());
        return false;
    }
}

/**
 * Require authentication for API endpoints
 * 
 * @param string|array|null $requiredRoles Optional required role(s)
 * @return array User data
 */
function requireAuth($requiredRoles = null) {
    $user = verifyAuth();
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Please login.']);
        exit;
    }
    
    if ($requiredRoles !== null && !hasRole($user, $requiredRoles)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden. Insufficient permissions.']);
        exit;
    }
    
    return $user;
}

/**
 * Check if user is admin (convenience function)
 * 
 * @param array $user User data
 * @return bool
 */
function isAdmin($user) {
    return $user && in_array($user['user_type'], ['Admin', 'admin']);
}

/**
 * Get current authenticated user
 * 
 * @return array|false User data or false if not authenticated
 */
function getCurrentUser() {
    return verifyAuth();
}

// ============================================
// INITIALIZATION
// ============================================

// Initialize session if needed
if (session_status() === PHP_SESSION_NONE) {
    // Set session cookie parameters
    session_set_cookie_params([
        'lifetime' => defined('SESSION_LIFETIME') ? SESSION_LIFETIME : 7200,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    
    session_start();
}
?>