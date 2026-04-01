<?php
/**
 * Halaman Login - VERSI FINAL DENGAN PERBAIKAN SESSION
 * File: login.php
 * 
 * PERBAIKAN:
 * - Redirect untuk user_type Siswa dan Guru ke modules/user/send_message.php
 * - User type lainnya tetap sesuai dengan logika sebelumnya
 */

require_once(__DIR__ . '/config/config.php');

// Cek apakah file session.php ada
$session_file = __DIR__ . '/config/session.php';
if (file_exists($session_file)) {
    require_once $session_file;
} else {
    // Fallback: start session manually
    if (session_status() === PHP_SESSION_NONE) {
        session_name('RMSESSID');
        session_start();
    }
}

// Generate CSRF token jika belum ada
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle POST request (login form submission)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = 'Token keamanan tidak valid.';
        header('Location: login.php?error=csrf');
        exit;
    }
    
    // Get input data
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']) && $_POST['remember'] == '1';
    $captcha_code = trim($_POST['captcha_code'] ?? '');
    
    // Initialize login attempts if not exists
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = 0;
    }
    
    // Validate inputs
    $errors = [];
    
    if (empty($username)) {
        $errors[] = 'Username harus diisi.';
    }
    
    if (empty($password)) {
        $errors[] = 'Password harus diisi.';
    }
    
    // Check if captcha is required
    $show_captcha = ($_SESSION['login_attempts'] >= 3);
    if ($show_captcha && empty($captcha_code)) {
        $errors[] = 'Kode keamanan (CAPTCHA) harus diisi.';
    }
    
    // If no errors, proceed with authentication
    if (empty($errors)) {
        try {
            // Database connection
            $conn = new PDO(
                "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME,
                DB_USER,
                DB_PASS
            );
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Prepare SQL statement
            $stmt = $conn->prepare("
                SELECT 
                    id, 
                    username, 
                    password_hash, 
                    user_type, 
                    nama_lengkap, 
                    email, 
                    is_active,
                    last_login,
                    nis_nip,
                    kelas,
                    jurusan,
                    mata_pelajaran,
                    privilege_level,
                    phone_number,
                    avatar
                FROM users 
                WHERE (username = :username OR email = :username)
                AND is_active = 1
            ");
            
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // Verify password
                if (password_verify($password, $user['password_hash'])) {
                    // Login successful - reset attempts
                    $_SESSION['login_attempts'] = 0;
                    unset($_SESSION['captcha_code']);
                    
                    // SET SESSION VARIABLES
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['user_type'] = $user['user_type'];
                    $_SESSION['nama_lengkap'] = $user['nama_lengkap'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['nis_nip'] = $user['nis_nip'];
                    $_SESSION['kelas'] = $user['kelas'];
                    $_SESSION['jurusan'] = $user['jurusan'];
                    $_SESSION['mata_pelajaran'] = $user['mata_pelajaran'];
                    $_SESSION['privilege_level'] = $user['privilege_level'];
                    $_SESSION['phone_number'] = $user['phone_number'];
                    $_SESSION['avatar'] = $user['avatar'];
                    $_SESSION['login_time'] = time();
                    
                    // DEBUG: Verifikasi session tersimpan
                    error_log("=== SESSION AFTER LOGIN ===");
                    error_log("Session ID: " . session_id());
                    error_log("Session Name: " . session_name());
                    error_log("Session save path: " . session_save_path());
                    error_log("SESSION user_id: " . ($_SESSION['user_id'] ?? 'NOT SET'));
                    error_log("SESSION username: " . ($_SESSION['username'] ?? 'NOT SET'));
                    error_log("SESSION user_type: " . ($_SESSION['user_type'] ?? 'NOT SET'));
                    
                    // Update last login
                    $update_stmt = $conn->prepare("
                        UPDATE users 
                        SET last_login = NOW()
                        WHERE id = :user_id
                    ");
                    $update_stmt->execute([':user_id' => $user['id']]);
                    
                    // Set remember me cookie if requested
                    if ($remember) {
                        $token = bin2hex(random_bytes(32));
                        $expiry = time() + (30 * 24 * 60 * 60); // 30 days
                        
                        setcookie('remember_token', $token, $expiry, '/', '', false, true);
                        
                        // Store token in database
                        try {
                            $token_stmt = $conn->prepare("
                                INSERT INTO remember_tokens (user_id, token, expires_at) 
                                VALUES (:user_id, :token, :expires_at)
                                ON DUPLICATE KEY UPDATE 
                                token = :token, 
                                expires_at = :expires_at
                            ");
                            $token_stmt->execute([
                                ':user_id' => $user['id'],
                                ':token' => hash('sha256', $token),
                                ':expires_at' => date('Y-m-d H:i:s', $expiry)
                            ]);
                        } catch (Exception $e) {
                            error_log("Remember token error: " . $e->getMessage());
                        }
                    }
                    
                    // =========================================================
                    // MODIFIKASI 1: LOGIKA REDIRECT BERDASARKAN USER TYPE
                    // PERUBAHAN: Siswa dan Guru diarahkan ke modules/user/send_message.php
                    // =========================================================
                    $base_url = rtrim(BASE_URL, '/');
                    
                    // Tentukan path redirect berdasarkan user type
                    switch($user['user_type']) {
                        case 'Admin':
                            $redirect_path = '/modules/admin/dashboard.php';
                            break;
                        case 'Kepala_Sekolah':
                        case 'Wakil_Kepala':
                            $redirect_path = '/modules/wakepsek/dashboard.php';
                            break;
                        case 'Guru_BK':
                        case 'Guru_Humas':
                        case 'Guru_Kurikulum':
                        case 'Guru_Kesiswaan':
                        case 'Guru_Sarana':
                            $redirect_path = '/modules/guru/followup.php';
                            break;
                        case 'Guru':
                            // Guru diarahkan ke send_message.php
                            $redirect_path = '/modules/user/send_message.php';
                            break;
                        case 'Siswa':
                        case 'Orang_Tua':
                            // Siswa dan Orang Tua diarahkan ke send_message.php
                            $redirect_path = '/modules/user/send_message.php';
                            break;
                        default:
                            $redirect_path = '/index.php';
                    }
                    
                    $redirect_url = $base_url . $redirect_path;
                    error_log("FINAL REDIRECT TO: " . $redirect_url . " (User Type: " . $user['user_type'] . ")");
                    
                    // Hapus output buffer
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    
                    header('Location: ' . $redirect_url);
                    exit;
                    
                } else {
                    // Password tidak cocok
                    $_SESSION['login_attempts']++;
                    $attempts_left = max(0, 3 - $_SESSION['login_attempts']);
                    $_SESSION['error'] = 'Username atau password salah. ' . 
                                        ($attempts_left > 0 ? "Percobaan tersisa: $attempts_left" : "");
                    header('Location: login.php?error=credentials');
                    exit;
                }
            } else {
                // User tidak ditemukan atau tidak aktif
                $_SESSION['login_attempts']++;
                $_SESSION['error'] = 'Username atau password salah, atau akun tidak aktif.';
                header('Location: login.php?error=credentials');
                exit;
            }
            
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $_SESSION['error'] = 'Terjadi kesalahan sistem. Silakan coba lagi nanti.';
            header('Location: login.php?error=system');
            exit;
        }
    } else {
        // Validation errors
        $_SESSION['error'] = implode('<br>', $errors);
        header('Location: login.php?error=validation');
        exit;
    }
}

// ============================================
// CEK LOGIN - TAPI JANGAN REDIRECT LANGSUNG
// ============================================
if (isset($_SESSION['user_id'])) {
    $userType = $_SESSION['user_type'] ?? '';
    
    error_log("=== ALREADY LOGGED IN ===");
    error_log("User ID: " . $_SESSION['user_id']);
    error_log("User Type: " . $userType);
    
    // CEK APAKAH INI REDIRECT DARI SEND_MESSAGE?
    if (isset($_GET['error']) && $_GET['error'] == 'session_expired') {
        // Jangan redirect, tampilkan pesan error
        error_log("Showing session expired message");
        $show_session_expired = true;
    } else {
        // =========================================================
        // MODIFIKASI 2: LOGIKA REDIRECT UNTUK USER YANG SUDAH LOGIN
        // PERUBAHAN: Siswa dan Guru diarahkan ke modules/user/send_message.php
        // =========================================================
        $base_url = rtrim(BASE_URL, '/');
        
        // Tentukan path redirect berdasarkan user type
        switch($userType) {
            case 'Admin':
                $redirect_path = '/modules/admin/dashboard.php';
                break;
            case 'Kepala_Sekolah':
            case 'Wakil_Kepala':
                $redirect_path = '/modules/wakepsek/dashboard.php';
                break;
            case 'Guru_BK':
            case 'Guru_Humas':
            case 'Guru_Kurikulum':
            case 'Guru_Kesiswaan':
            case 'Guru_Sarana':
                $redirect_path = '/modules/guru/followup.php';
                break;
            case 'Guru':
                // Guru diarahkan ke send_message.php
                $redirect_path = '/modules/user/send_message.php';
                break;
            case 'Siswa':
            case 'Orang_Tua':
                // Siswa dan Orang Tua diarahkan ke send_message.php
                $redirect_path = '/modules/user/send_message.php';
                break;
            default:
                $redirect_path = '/index.php';
        }
        
        $redirect_url = $base_url . $redirect_path;
        error_log("REDIRECT URL: " . $redirect_url . " (User Type: " . $userType . ")");
        
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        header('Location: ' . $redirect_url);
        exit;
    }
}

// Include header
$title = 'Login - Aplikasi Pesan Responsif';
$meta_tags = [
    'charset' => 'UTF-8',
    'viewport' => 'width=device-width, initial-scale=1.0',
    'description' => 'Login ke Aplikasi Pesan Responsif SMKN 12 Jakarta',
    'robots' => 'noindex, nofollow'
];
require_once 'includes/header.php';

// Handle login attempts
$login_attempts = $_SESSION['login_attempts'] ?? 0;
$show_captcha = ($login_attempts >= 3);
$error_message = $_SESSION['error'] ?? '';
unset($_SESSION['error']); // Clear error after displaying

// Generate simple captcha jika diperlukan
if ($show_captcha && !isset($_SESSION['captcha_code'])) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $captcha_text = '';
    for ($i = 0; $i < 6; $i++) {
        $captcha_text .= $chars[rand(0, strlen($chars) - 1)];
    }
    $_SESSION['captcha_code'] = $captcha_text;
}
?>

<!-- ============================================ -->
<!-- FORM LOGIN -->
<!-- ============================================ -->
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card border-0 shadow-lg">
                <div class="card-header bg-primary text-white text-center py-4">
                    <h3 class="mb-0">
                        <i class="fas fa-sign-in-alt me-2"></i>
                        Login Sistem
                    </h3>
                    <p class="mb-0 mt-2 small">SMKN 12 Jakarta</p>
                </div>
                
                <div class="card-body p-4">
                    <!-- Pesan Session Expired -->
                    <?php if (isset($show_session_expired) && $show_session_expired): ?>
                        <div class="alert alert-warning alert-dismissible fade show" role="alert">
                            <i class="fas fa-clock me-2"></i>
                            <strong>Session Tidak Terdeteksi!</strong> 
                            Kami mendeteksi Anda sudah login tetapi session tidak terbaca di halaman tujuan. 
                            Silakan <a href="logout.php" class="alert-link">logout</a> dan login kembali.
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Error Message -->
                    <?php if (!empty($error_message)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <?php echo htmlspecialchars($error_message); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php elseif (isset($_GET['error'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <?php 
                            switch ($_GET['error']) {
                                case 'csrf':
                                    echo 'Token keamanan tidak valid. Silakan refresh halaman dan coba lagi.';
                                    break;
                                case 'credentials':
                                    echo 'Username atau password salah.';
                                    break;
                                case 'inactive':
                                    echo 'Akun tidak aktif. Hubungi administrator.';
                                    break;
                                case 'system':
                                    echo 'Terjadi kesalahan sistem. Silakan coba lagi nanti.';
                                    break;
                                case 'validation':
                                    echo 'Harap isi semua field yang diperlukan.';
                                    break;
                                default:
                                    echo 'Terjadi kesalahan. Silakan coba lagi.';
                            }
                            ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($_GET['logout'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i>
                            Anda telah berhasil logout.
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($_GET['registered'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i>
                            Pendaftaran berhasil! Silakan login dengan akun Anda.
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Login Form -->
                    <form method="POST" action="" id="loginForm">
                        <!-- CSRF Token -->
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        
                        <!-- Username -->
                        <div class="mb-3">
                            <label for="username" class="form-label">
                                <i class="fas fa-user me-1"></i> Username atau Email
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-at"></i>
                                </span>
                                <input type="text" 
                                       class="form-control" 
                                       id="username" 
                                       name="username" 
                                       placeholder="Masukkan username atau email" 
                                       required
                                       autocomplete="username"
                                       autofocus
                                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                                <div class="invalid-feedback" id="username-error"></div>
                            </div>
                        </div>
                        
                        <!-- Password -->
                        <div class="mb-3">
                            <label for="password" class="form-label">
                                <i class="fas fa-lock me-1"></i> Password
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-key"></i>
                                </span>
                                <input type="password" 
                                       class="form-control" 
                                       id="password" 
                                       name="password" 
                                       placeholder="Masukkan password" 
                                       required
                                       autocomplete="current-password">
                                <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <div class="invalid-feedback" id="password-error"></div>
                            </div>
                        </div>
                        
                        <!-- CAPTCHA (jika perlu) -->
                        <?php if ($show_captcha): ?>
                            <div class="mb-3">
                                <label for="captcha_code" class="form-label">
                                    <i class="fas fa-shield-alt me-1"></i> Kode Keamanan
                                </label>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <div class="bg-light border rounded p-2 text-center fw-bold" 
                                             style="font-family: monospace; font-size: 1.5rem; letter-spacing: 5px; background-color: #f8f9fa; color: #333;">
                                            <?php echo htmlspecialchars($_SESSION['captcha_code'] ?? ''); ?>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <input type="text" 
                                               class="form-control" 
                                               id="captcha_code" 
                                               name="captcha_code" 
                                               placeholder="Masukkan kode" 
                                               required
                                               maxlength="6"
                                               style="text-transform: uppercase;">
                                        <div class="invalid-feedback" id="captcha-error"></div>
                                    </div>
                                    <div class="col-12 mt-2">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="refreshCaptcha">
                                            <i class="fas fa-sync-alt me-1"></i> Kode Baru
                                        </button>
                                        <small class="text-muted ms-2">Masukkan kode persis seperti di atas</small>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Remember Me -->
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="remember" name="remember" value="1" <?php echo isset($_POST['remember']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="remember">
                                Ingat saya di komputer ini
                            </label>
                        </div>
                        
                        <!-- Submit Button -->
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-sign-in-alt me-2"></i> Masuk
                            </button>
                        </div>
                    </form>
                    
                    <hr class="my-4">
                    
                    <!-- Additional Links -->
                    <div class="text-center">
                        <p class="mb-2">
                            <a href="<?php echo app_url('forgot_password.php'); ?>" class="text-decoration-none">
                                <i class="fas fa-key me-1"></i> Lupa Password?
                            </a>
                        </p>
                        <p class="mb-0">
                            Belum punya akun? 
                            <a href="<?php echo app_url('register.php'); ?>" class="text-decoration-none fw-bold">
                                Daftar di sini
                            </a>
                        </p>
                    </div>
                    
                    <!-- ========================================================= -->
                    <!-- MODIFIKASI 3: INFORMASI AKUN DEMO -->
                    <!-- PERUBAHAN: Ditambahkan akun demo untuk Siswa -->
                    <!-- ========================================================= -->
                    <div class="alert alert-info mt-3 mb-0">
                        <small>
                            <strong><i class="fas fa-info-circle me-1"></i> Akun Demo:</strong><br>
                            - Kepala Sekolah: kepsek / password (Dashboard Wakepsek)<br>
                            - Wakil Kepala: wakepsek / password (Dashboard Wakepsek)<br>
                            - Admin: admin / password (Dashboard Admin)<br>
                            - Guru BK: gurubk / password (Followup Guru)<br>
                            - Guru Humas: humas / password (Followup Guru)<br>
                            - Guru Kurikulum: kurikulum / password (Followup Guru)<br>
                            - Guru Kesiswaan: kesiswaan / password (Followup Guru)<br>
                            - Guru Sarana: sarana / password (Followup Guru)<br>
                            - Guru: guru / password (Send Message)<br>
                            - Siswa: siswa / password (Send Message)<br>
                            - Orang Tua: orangtua / password (Send Message)
                        </small>
                    </div>
                </div>
                
                <div class="card-footer bg-light text-center py-3">
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        Gunakan akun yang telah terdaftar. Hubungi admin untuk bantuan.
                    </small>
                    <?php if ($login_attempts > 0): ?>
                        <div class="mt-2 small text-danger">
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            Percobaan login gagal: <?php echo $login_attempts; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle password visibility
    const toggleBtn = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');
    
    if (toggleBtn && passwordInput) {
        toggleBtn.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            this.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
        });
    }
    
    // Form validation
    const form = document.getElementById('loginForm');
    const usernameInput = document.getElementById('username');
    const passwordInput = document.getElementById('password');
    const captchaInput = document.getElementById('captcha_code');
    
    if (form) {
        form.addEventListener('submit', function(e) {
            let valid = true;
            
            // Clear previous errors
            document.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
            
            // Validate username
            if (!usernameInput.value.trim()) {
                usernameInput.classList.add('is-invalid');
                document.getElementById('username-error').textContent = 'Username wajib diisi';
                valid = false;
            }
            
            // Validate password
            if (!passwordInput.value) {
                passwordInput.classList.add('is-invalid');
                document.getElementById('password-error').textContent = 'Password wajib diisi';
                valid = false;
            } else if (passwordInput.value.length < <?php echo defined('PASSWORD_MIN_LENGTH') ? PASSWORD_MIN_LENGTH : 6; ?>) {
                passwordInput.classList.add('is-invalid');
                document.getElementById('password-error').textContent = 
                    'Password minimal <?php echo defined('PASSWORD_MIN_LENGTH') ? PASSWORD_MIN_LENGTH : 6; ?> karakter';
                valid = false;
            }
            
            // Validate captcha if shown
            <?php if ($show_captcha): ?>
            if (captchaInput && !captchaInput.value.trim()) {
                captchaInput.classList.add('is-invalid');
                document.getElementById('captcha-error').textContent = 'Kode keamanan wajib diisi';
                valid = false;
            }
            <?php endif; ?>
            
            if (!valid) {
                e.preventDefault();
            }
        });
    }
    
    // Refresh captcha
    const refreshBtn = document.getElementById('refreshCaptcha');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function() {
            window.location.href = 'login.php?refresh_captcha=1';
        });
    }
});

// Handle refresh captcha via GET parameter
<?php if (isset($_GET['refresh_captcha'])): ?>
    <?php 
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $captcha_text = '';
    for ($i = 0; $i < 6; $i++) {
        $captcha_text .= $chars[rand(0, strlen($chars) - 1)];
    }
    $_SESSION['captcha_code'] = $captcha_text;
    ?>
    window.location.href = 'login.php';
<?php endif; ?>
</script>

<?php
require_once 'includes/footer.php';
?>