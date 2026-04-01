<?php
/**
 * Add New User - Revisi dengan Fitur Password Manual dan Notifikasi
 * File: modules/admin/add_user.php
 * 
 * REVISI:
 * - Menggunakan konfigurasi terpusat dari settings.php
 * - Mengambil MailerSend dan Fonnte config dari file JSON
 * - Mempertahankan semua fitur yang sudah ada
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Check authentication and admin privilege
Auth::checkAuth();
if ($_SESSION['user_type'] !== 'Admin' && ($_SESSION['privilege_level'] ?? '') !== 'Full_Access') {
    header('Location: ' . BASE_URL . 'index.php?error=access_denied');
    exit;
}

// ============================================================================
// LOGGING SETUP - DENGAN DEBUG LEBIH DETAIL
// ============================================================================
$logDir = __DIR__ . '/../../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

define('PASSWORD_LOG', $logDir . '/password_generator.log');
define('USER_CREATION_LOG', $logDir . '/user_creation.log');
define('EMAIL_DEBUG_LOG', $logDir . '/email_debug.log');

function writeLog($file, $message, $data = null) {
    $log = "[" . date('Y-m-d H:i:s') . "] " . $message;
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $log .= " - " . print_r($data, true);
        } else {
            $log .= " - " . $data;
        }
    }
    $log .= "\n";
    file_put_contents($file, $log, FILE_APPEND);
    error_log($log);
}

function writeUserLog($file, $message, $data = null) {
    writeLog($file, $message, $data);
}

// ============================================================================
// LOAD KONFIGURASI TERPUSAT DARI SETTINGS.PHP
// ============================================================================
$mailersendConfig = [];
$fonnteConfig = [];

// Load MailerSend config
$mailersendConfigFile = ROOT_PATH . '/config/mailersend.json';
if (file_exists($mailersendConfigFile)) {
    $mailersendConfig = json_decode(file_get_contents($mailersendConfigFile), true);
    writeUserLog(USER_CREATION_LOG, "MailerSend config loaded dari settings.php", [
        'from_email' => $mailersendConfig['from_email'] ?? 'not set',
        'is_active' => $mailersendConfig['is_active'] ?? 0
    ]);
} else {
    // Default config jika file belum ada
    $mailersendConfig = [
        'api_token' => 'mlsn.3ae3e0dd1dec6af1d2aa199c1d20287ee29e9fcda6679c4ff4049a7c26061d29',
        'domain' => 'test-r9084zv6rpjgw63d.mlsender.net',
        'domain_id' => '69oxl5ejo22l785k',
        'from_email' => 'noreply@test-r9084zv6rpjgw63d.mlsender.net',
        'from_name' => 'SMKN 12 Jakarta - Aplikasi Pesan Responsif',
        'is_active' => 1
    ];
    writeUserLog(USER_CREATION_LOG, "MailerSend config default digunakan");
}

// Load Fonnte config
$fonnteConfigFile = ROOT_PATH . '/config/fonnte.json';
if (file_exists($fonnteConfigFile)) {
    $fonnteConfig = json_decode(file_get_contents($fonnteConfigFile), true);
    writeUserLog(USER_CREATION_LOG, "Fonnte config loaded dari settings.php", [
        'device_id' => $fonnteConfig['device_id'] ?? 'not set',
        'is_active' => $fonnteConfig['is_active'] ?? 0
    ]);
} else {
    // Default config jika file belum ada
    $fonnteConfig = [
        'api_token' => 'FS2cq8FckmaTegxtZpFB',
        'api_url' => 'https://api.fonnte.com/send',
        'device_id' => '6285174207795',
        'country_code' => '62',
        'is_active' => 1
    ];
    writeUserLog(USER_CREATION_LOG, "Fonnte config default digunakan");
}

// ============================================================================
// SET KONSTANTA UNTUK KOMPATIBILITAS DENGAN KODE LAMA
// ============================================================================
if (!defined('MAILERSEND_API_TOKEN')) {
    define('MAILERSEND_API_TOKEN', $mailersendConfig['api_token'] ?? '');
}
if (!defined('MAILERSEND_DOMAIN')) {
    define('MAILERSEND_DOMAIN', $mailersendConfig['domain'] ?? '');
}
if (!defined('MAILERSEND_DOMAIN_ID')) {
    define('MAILERSEND_DOMAIN_ID', $mailersendConfig['domain_id'] ?? '');
}
if (!defined('MAILERSEND_FROM_EMAIL')) {
    define('MAILERSEND_FROM_EMAIL', $mailersendConfig['from_email'] ?? '');
}
if (!defined('MAILERSEND_FROM_NAME')) {
    define('MAILERSEND_FROM_NAME', $mailersendConfig['from_name'] ?? 'SMKN 12 Jakarta - Aplikasi Pesan Responsif');
}

if (!defined('FONNTE_API_URL')) {
    define('FONNTE_API_URL', $fonnteConfig['api_url'] ?? 'https://api.fonnte.com/send');
}
if (!defined('FONNTE_API_KEY')) {
    define('FONNTE_API_KEY', $fonnteConfig['api_token'] ?? '');
}
if (!defined('FONNTE_COUNTRY_CODE')) {
    define('FONNTE_COUNTRY_CODE', $fonnteConfig['country_code'] ?? '62');
}

// Tampilkan konfigurasi untuk verifikasi
writeUserLog(USER_CREATION_LOG, "========== ADD_USER.PHP START ==========");
writeUserLog(USER_CREATION_LOG, "MAILERSEND FROM EMAIL: " . MAILERSEND_FROM_EMAIL);
writeUserLog(USER_CREATION_LOG, "MAILERSEND ACTIVE: " . ($mailersendConfig['is_active'] ?? 0));
writeUserLog(USER_CREATION_LOG, "FONNTE DEVICE ID: " . FONNTE_API_KEY);
writeUserLog(USER_CREATION_LOG, "FONNTE ACTIVE: " . ($fonnteConfig['is_active'] ?? 0));

// ============================================================================
// FUNGSI FORMAT NOMOR WHATSAPP
// ============================================================================
function formatPhoneNumber($phone) {
    // Hapus semua karakter non-digit
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Jika dimulai dengan 0, ganti dengan 62
    if (substr($phone, 0, 1) == '0') {
        $phone = '62' . substr($phone, 1);
    }
    // Jika tidak dimulai dengan 62, tambahkan 62
    elseif (substr($phone, 0, 2) !== '62') {
        $phone = '62' . $phone;
    }
    
    return $phone;
}

// ============================================================================
// FUNGSI KIRIM WHATSAPP VIA FONNTE (MENGGUNAKAN KONFIGURASI DARI SETTINGS.PHP)
// ============================================================================
function kirimWhatsApp($phone, $nama, $username, $password) {
    global $fonnteConfig;
    
    writeUserLog(USER_CREATION_LOG, "Mengirim WhatsApp ke: $phone untuk user: $username");
    
    // Cek apakah Fonnte aktif
    if (!isset($fonnteConfig['is_active']) || $fonnteConfig['is_active'] != 1) {
        writeUserLog(USER_CREATION_LOG, "Fonnte tidak aktif, lewati pengiriman WhatsApp");
        return ['success' => false, 'error' => 'Layanan WhatsApp tidak aktif'];
    }
    
    // Format nomor
    $formatted_phone = formatPhoneNumber($phone);
    
    if (strlen($formatted_phone) < 10 || strlen($formatted_phone) > 15) {
        writeUserLog(USER_CREATION_LOG, "ERROR: Nomor WhatsApp tidak valid: $phone");
        return ['success' => false, 'error' => 'Nomor tidak valid'];
    }
    
    // Pesan notifikasi
    $message = "🔔 *NOTIFIKASI PEMBUATAN AKUN - SMKN 12 Jakarta*\n\n";
    $message .= "Yth. *$nama*\n\n";
    $message .= "Akun Anda telah berhasil dibuat oleh Administrator.\n\n";
    $message .= "*DATA LOGIN:*\n";
    $message .= "Username: $username\n";
    $message .= "Password: $password\n\n";
    $message .= "Login di: " . BASE_URL . "login.php\n\n";
    $message .= "Simpan informasi ini dengan aman.\n";
    $message .= "_Pesan otomatis dari sistem._";
    
    // Kirim via Fonnte
    $postData = [
        'target' => $formatted_phone,
        'message' => $message,
        'countryCode' => $fonnteConfig['country_code'] ?? '62',
        'delay' => '0'
    ];
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $fonnteConfig['api_url'] ?? 'https://api.fonnte.com/send',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_HTTPHEADER => ['Authorization: ' . ($fonnteConfig['api_token'] ?? '')],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    $response_data = json_decode($response, true);
    
    // Cek sukses
    $success = false;
    if ($httpCode == 200) {
        if (isset($response_data['status']) && ($response_data['status'] === true || $response_data['status'] == 1)) {
            $success = true;
        } elseif (isset($response_data['id'])) {
            $success = true;
        }
    }
    
    if ($success) {
        writeUserLog(USER_CREATION_LOG, "✓ WhatsApp berhasil dikirim ke $formatted_phone");
    } else {
        writeUserLog(USER_CREATION_LOG, "✗ WhatsApp gagal dikirim ke $formatted_phone: " . ($curlError ?: ($response_data['reason'] ?? 'Unknown error')));
    }
    
    return [
        'success' => $success,
        'error' => $curlError ?: ($response_data['reason'] ?? 'Unknown error')
    ];
}

// ============================================================================
// FUNGSI KIRIM EMAIL VIA MAILERSEND (MENGGUNAKAN KONFIGURASI DARI SETTINGS.PHP)
// ============================================================================
function kirimEmailViaAPI($email, $nama, $username, $password) {
    global $mailersendConfig;
    
    writeUserLog(USER_CREATION_LOG, "========== FUNGSI KIRIM EMAIL VIA API ==========");
    writeUserLog(USER_CREATION_LOG, "Input - email: $email, nama: $nama");
    
    // Cek apakah MailerSend aktif
    if (!isset($mailersendConfig['is_active']) || $mailersendConfig['is_active'] != 1) {
        writeUserLog(USER_CREATION_LOG, "MailerSend tidak aktif, lewati pengiriman email");
        return ['success' => false, 'error' => 'Layanan email tidak aktif'];
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        writeUserLog(USER_CREATION_LOG, "ERROR: Email tidak valid");
        return ['success' => false, 'error' => 'Email tidak valid'];
    }
    
    if (empty($mailersendConfig['api_token'])) {
        writeUserLog(USER_CREATION_LOG, "ERROR: API Token kosong");
        return ['success' => false, 'error' => 'API Token tidak boleh kosong'];
    }
    
    if (empty($mailersendConfig['from_email'])) {
        writeUserLog(USER_CREATION_LOG, "ERROR: From Email kosong");
        return ['success' => false, 'error' => 'From Email tidak boleh kosong'];
    }
    
    $subject = "Akun Baru Dibuat - SMKN 12 Jakarta";
    
    // HTML Email dengan format baru (mengikuti pola register.php)
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; background-color: #f4f6f9; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #007bff, #0056b3); color: white; padding: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 28px; }
        .header p { margin: 10px 0 0; opacity: 0.9; }
        .content { padding: 30px; }
        .credentials { background: #f8f9fa; border-left: 4px solid #007bff; padding: 20px; margin: 20px 0; border-radius: 0 5px 5px 0; }
        .credential-box { background: white; padding: 15px; border-radius: 5px; }
        .credential-item { padding: 10px 0; border-bottom: 1px solid #e9ecef; }
        .credential-item:last-child { border-bottom: none; }
        .credential-label { color: #6c757d; font-weight: bold; display: inline-block; width: 100px; }
        .credential-value { font-family: monospace; color: #007bff; font-weight: bold; }
        .btn { display: inline-block; background: #007bff; color: white; padding: 12px 30px; text-decoration: none; border-radius: 50px; font-weight: bold; margin: 10px 0; }
        .footer { background: #e9ecef; padding: 20px; text-align: center; color: #6c757d; font-size: 12px; border-top: 1px solid #dee2e6; }
        .warning { color: #dc3545; font-size: 13px; }
        .config-info { background: #e8f5e9; border-left: 4px solid #25D366; padding: 10px; margin-top: 10px; font-size: 11px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎉 SMKN 12 Jakarta</h1>
            <p>Akun Baru Telah Dibuat</p>
        </div>
        
        <div class="content">
            <p>Yth. <strong>' . htmlspecialchars($nama) . '</strong>,</p>
            
            <p>Akun Anda telah berhasil dibuat oleh Administrator di Aplikasi Pesan Responsif SMKN 12 Jakarta.</p>
            
            <div class="credentials">
                <h4 style="margin-top: 0; color: #007bff;">📋 INFORMASI AKUN ANDA</h4>
                
                <div class="credential-box">
                    <div class="credential-item">
                        <span class="credential-label">Username:</span>
                        <span class="credential-value">' . htmlspecialchars($username) . '</span>
                    </div>
                    
                    <div class="credential-item">
                        <span class="credential-label">Password:</span>
                        <span class="credential-value">' . htmlspecialchars($password) . '</span>
                    </div>
                    
                    <div class="credential-item">
                        <span class="credential-label">Tanggal:</span>
                        <span class="credential-value">' . date('d/m/Y H:i') . ' WIB</span>
                    </div>
                </div>
                
                <p style="margin-top: 20px; color: #007bff;">
                    <strong>PENTING:</strong> Simpan informasi login ini dengan aman! Jangan berikan kepada siapapun.
                </p>
            </div>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="' . BASE_URL . 'login.php" class="btn">🔐 Login Sekarang</a>
            </div>
            
            <p class="warning">
                ⚠️ Email ini dikirim otomatis. Mohon tidak membalas email ini.
            </p>
            <div class="config-info">
                <small>⚙️ Konfigurasi dari settings.php | MailerSend: ' . ($mailersendConfig['from_email'] ?? 'N/A') . '</small>
            </div>
        </div>
        
        <div class="footer">
            <p>&copy; ' . date('Y') . ' SMKN 12 Jakarta. All rights reserved.</p>
            <p><i>Powered by MailerSend (Konfigurasi Terpusat)</i></p>
        </div>
    </div>
</body>
</html>';
    
    // Data untuk MailerSend API
    $data = [
        'from' => [
            'email' => $mailersendConfig['from_email'],
            'name' => $mailersendConfig['from_name'] ?? 'SMKN 12 Jakarta'
        ],
        'to' => [
            [
                'email' => $email,
                'name' => $nama
            ]
        ],
        'subject' => $subject,
        'html' => $html,
        'text' => strip_tags($html)
    ];
    
    writeUserLog(USER_CREATION_LOG, "Data email via API:", [
        'from' => $mailersendConfig['from_email'],
        'to' => $email,
        'subject' => $subject,
        'html_length' => strlen($html)
    ]);
    
    // Kirim via MailerSend API
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://api.mailersend.com/v1/email',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $mailersendConfig['api_token'],
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    writeUserLog(USER_CREATION_LOG, "HTTP Code: $httpCode");
    if ($curlError) {
        writeUserLog(USER_CREATION_LOG, "CURL Error: $curlError");
    }
    writeUserLog(USER_CREATION_LOG, "Response: $response");
    
    $response_data = json_decode($response, true);
    $success = ($httpCode >= 200 && $httpCode < 300);
    
    if ($success) {
        writeUserLog(USER_CREATION_LOG, "✓ EMAIL VIA API BERHASIL dikirim ke $email");
    } else {
        writeUserLog(USER_CREATION_LOG, "✗ EMAIL VIA API GAGAL dikirim ke $email");
        if (isset($response_data['message'])) {
            writeUserLog(USER_CREATION_LOG, "Pesan error: " . $response_data['message']);
        }
    }
    
    return [
        'success' => $success,
        'sent' => $success,
        'error' => $curlError ?: ($response_data['message'] ?? 'Unknown error'),
        'http_code' => $httpCode,
        'response' => $response_data
    ];
}

// ============================================================================
// FUNGSI KIRIM EMAIL VIA SMTP (ALTERNATIF)
// ============================================================================
function kirimEmailViaSMTP($email, $nama, $username, $password) {
    global $mailersendConfig;
    
    writeUserLog(USER_CREATION_LOG, "========== FUNGSI KIRIM EMAIL VIA SMTP ==========");
    writeUserLog(USER_CREATION_LOG, "Input - email: $email, nama: $nama");
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        writeUserLog(USER_CREATION_LOG, "ERROR: Email tidak valid");
        return ['success' => false, 'error' => 'Email tidak valid'];
    }
    
    $subject = "Akun Baru Dibuat - SMKN 12 Jakarta";
    
    $html = "<!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='font-family:Arial;background:#f4f6f9;padding:20px'>
    <div style='max-width:600px;margin:0 auto;background:white;border-radius:10px'>
    <div style='background:#007bff;color:white;padding:30px;text-align:center'>
    <h1>SMKN 12 Jakarta</h1>
    </div>
    <div style='padding:30px'>
    <p>Yth. <strong>$nama</strong>,</p>
    <p>Akun Anda telah berhasil dibuat oleh Administrator. Berikut data login Anda:</p>
    <div style='background:#f8f9fa;padding:20px;border-left:4px solid #007bff'>
    <p><strong>Username:</strong> $username</p>
    <p><strong>Password:</strong> $password</p>
    </div>
    <p>Login: <a href='" . BASE_URL . "login.php'>" . BASE_URL . "login.php</a></p>
    </div>
    </div>
    </body>
    </html>";
    
    writeUserLog(USER_CREATION_LOG, "SMTP alternatif - menggunakan API sebagai fallback");
    
    return ['success' => false, 'error' => 'SMTP fallback - akan menggunakan API'];
}

// ============================================================================
// FUNGSI KIRIM EMAIL - UTAMA (MENGGUNAKAN API DENGAN FALLBACK)
// ============================================================================
function kirimEmail($email, $nama, $username, $password) {
    writeUserLog(USER_CREATION_LOG, "========== FUNGSI KIRIM EMAIL UTAMA ==========");
    writeUserLog(USER_CREATION_LOG, ">>> MENGIRIM EMAIL KE: $email");
    
    // Coba kirim via API terlebih dahulu
    $api_result = kirimEmailViaAPI($email, $nama, $username, $password);
    
    if ($api_result['success']) {
        writeUserLog(USER_CREATION_LOG, "✓ EMAIL BERHASIL VIA API");
        return $api_result;
    }
    
    // Jika API gagal, coba alternatif SMTP
    writeUserLog(USER_CREATION_LOG, "✗ API gagal, mencoba alternatif SMTP...");
    $smtp_result = kirimEmailViaSMTP($email, $nama, $username, $password);
    
    // Jika SMTP juga gagal atau belum diimplementasi, tetap kembalikan hasil API
    // dengan pesan error yang jelas
    if (!$smtp_result['success']) {
        writeUserLog(USER_CREATION_LOG, "SMTP juga gagal atau tidak tersedia");
        return $api_result; // Kembalikan hasil API dengan error asli
    }
    
    writeUserLog(USER_CREATION_LOG, "✓ EMAIL BERHASIL VIA SMTP");
    return $smtp_result;
}

// ============================================================================
// INITIALIZATION
// ============================================================================
$error = '';
$success = '';
$form_data = [
    'nama_lengkap' => '',
    'email' => '',
    'nis_nip' => '',
    'phone_number' => '',
    'kelas' => '',         // Dipisah: kelas
    'jurusan' => '',       // Dipisah: jurusan
    'mata_pelajaran' => '', // Field baru untuk Guru
    'user_type' => 'Siswa',
    'privilege_level' => 'Limited_Access',
    'is_active' => 1,
    'password_hash' => '' // Untuk menyimpan hash password
];

// Data untuk password generator (disimpan di session sementara)
$generated_passwords = $_SESSION['generated_passwords'] ?? [];

// Get database connection
try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    $error = 'Database connection failed: ' . $e->getMessage();
}

// ============================================================================
// AJAX HANDLER UNTUK PASSWORD GENERATOR
// ============================================================================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['ajax_action'] === 'generate_password') {
        $username = trim($_POST['username'] ?? '');
        $password_asli = trim($_POST['password_asli'] ?? '');
        
        if (empty($username)) {
            echo json_encode(['success' => false, 'error' => 'Username harus diisi']);
            exit;
        }
        
        if (empty($password_asli)) {
            echo json_encode(['success' => false, 'error' => 'Password asli harus diisi']);
            exit;
        }
        
        // Generate password hash
        $password_hash = password_hash($password_asli, PASSWORD_DEFAULT);
        
        // Simpan ke log
        $log_data = [
            'timestamp' => date('Y-m-d H:i:s'),
            'username' => $username,
            'password_asli' => $password_asli,
            'password_hash' => $password_hash,
            'generated_by' => $_SESSION['username'] ?? 'unknown',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ];
        
        writeUserLog(PASSWORD_LOG, "PASSWORD GENERATED:", $log_data);
        
        // Simpan ke session untuk ditampilkan di history
        $generated_passwords[] = $log_data;
        if (count($generated_passwords) > 50) { // Batasi 50 record terakhir
            array_shift($generated_passwords);
        }
        $_SESSION['generated_passwords'] = $generated_passwords;
        
        echo json_encode([
            'success' => true,
            'password_hash' => $password_hash,
            'username' => $username,
            'password_asli' => $password_asli,
            'message' => 'Password berhasil digenerate dan disimpan ke log'
        ]);
        exit;
    }
    
    if ($_POST['ajax_action'] === 'get_password_history') {
        echo json_encode([
            'success' => true,
            'history' => array_reverse($generated_passwords) // Tampilkan terbaru dulu
        ]);
        exit;
    }
    
    if ($_POST['ajax_action'] === 'check_email') {
        $email = trim($_POST['email'] ?? '');
        
        if (empty($email)) {
            echo json_encode(['success' => false, 'error' => 'Email harus diisi']);
            exit;
        }
        
        // Cek email di database
        $check_sql = "SELECT id, nama_lengkap FROM users WHERE email = :email";
        $check_stmt = $db->prepare($check_sql);
        $check_stmt->execute([':email' => $email]);
        $existing = $check_stmt->fetch();
        
        if ($existing) {
            echo json_encode([
                'success' => false,
                'exists' => true,
                'message' => 'Email sudah terdaftar atas nama: ' . $existing['nama_lengkap']
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'exists' => false,
                'message' => 'Email tersedia'
            ]);
        }
        exit;
    }
}

// ============================================================================
// HANDLE FORM SUBMISSION
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_action'])) {
    // Get and sanitize form data
    $form_data = [
        'nama_lengkap' => trim($_POST['nama_lengkap'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'nis_nip' => trim($_POST['nis_nip'] ?? ''),
        'phone_number' => trim($_POST['phone_number'] ?? ''),
        'kelas' => trim($_POST['kelas'] ?? ''),           // Kelas
        'jurusan' => trim($_POST['jurusan'] ?? ''),       // Jurusan
        'mata_pelajaran' => trim($_POST['mata_pelajaran'] ?? ''), // Mata Pelajaran untuk Guru
        'user_type' => $_POST['user_type'] ?? 'Siswa',
        'privilege_level' => $_POST['privilege_level'] ?? 'Limited_Access',
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'password_hash' => trim($_POST['password_hash'] ?? '')
    ];
    
    // Data untuk notifikasi
    $username = trim($_POST['username'] ?? '');
    $password_asli = trim($_POST['password_asli'] ?? '');
    
    // Validate form data
    $errors = [];
    
    // Validasi field wajib
    if (empty($form_data['nama_lengkap'])) {
        $errors[] = 'Nama lengkap harus diisi';
    }
    
    if (empty($form_data['email'])) {
        $errors[] = 'Email harus diisi';
    } elseif (!filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email tidak valid';
    }
    
    if (empty($username)) {
        $errors[] = 'Username harus diisi (gunakan fitur Buat Password User)';
    }
    
    if (empty($password_asli)) {
        $errors[] = 'Password asli harus diisi (gunakan fitur Buat Password User)';
    }
    
    if (empty($form_data['password_hash'])) {
        $errors[] = 'Password hash harus diisi (gunakan fitur Buat Password User)';
    }
    
    // Validasi berdasarkan tipe user
    if ($form_data['user_type'] == 'Siswa') {
        if (empty($form_data['kelas'])) {
            $errors[] = 'Kelas harus diisi untuk Siswa';
        }
        if (empty($form_data['jurusan'])) {
            $errors[] = 'Jurusan harus diisi untuk Siswa';
        }
    } elseif ($form_data['user_type'] == 'Guru') {
        if (empty($form_data['mata_pelajaran'])) {
            $errors[] = 'Mata pelajaran harus diisi untuk Guru';
        }
    }
    
    if (empty($errors)) {
        try {
            // Check if email already exists
            $check_email_sql = "SELECT id FROM users WHERE email = :email";
            $check_email_stmt = $db->prepare($check_email_sql);
            $check_email_stmt->execute([':email' => $form_data['email']]);
            
            if ($check_email_stmt->fetch()) {
                $errors[] = 'Email sudah terdaftar. Gunakan email lain.';
            }
            
            // Check if username already exists
            $check_username_sql = "SELECT id FROM users WHERE username = :username";
            $check_username_stmt = $db->prepare($check_username_sql);
            $check_username_stmt->execute([':username' => $username]);
            
            if ($check_username_stmt->fetch()) {
                $errors[] = 'Username sudah terdaftar. Gunakan username lain.';
            }
            
            // Check if NIS/NIP already exists
            if (!empty($form_data['nis_nip'])) {
                $check_nis_sql = "SELECT id FROM users WHERE nis_nip = :nis_nip";
                $check_nis_stmt = $db->prepare($check_nis_sql);
                $check_nis_stmt->execute([':nis_nip' => $form_data['nis_nip']]);
                
                if ($check_nis_stmt->fetch()) {
                    $errors[] = 'NIS/NIP sudah terdaftar. Gunakan NIS/NIP lain.';
                }
            }
            
            if (empty($errors)) {
                // Get current timestamp
                $current_time = date('Y-m-d H:i:s');
                
                // Insert user into database
                $insert_sql = "
                    INSERT INTO users (
                        username, nama_lengkap, email, password_hash, nis_nip, 
                        phone_number, kelas, jurusan, mata_pelajaran, user_type, 
                        privilege_level, is_active, created_at, updated_at
                    ) VALUES (
                        :username, :nama_lengkap, :email, :password_hash, :nis_nip,
                        :phone_number, :kelas, :jurusan, :mata_pelajaran, :user_type,
                        :privilege_level, :is_active, :created_at, :updated_at
                    )
                ";
                
                $insert_stmt = $db->prepare($insert_sql);
                $insert_stmt->execute([
                    ':username' => $username,
                    ':nama_lengkap' => $form_data['nama_lengkap'],
                    ':email' => $form_data['email'],
                    ':password_hash' => $form_data['password_hash'],
                    ':nis_nip' => $form_data['nis_nip'],
                    ':phone_number' => $form_data['phone_number'],
                    ':kelas' => $form_data['kelas'] ?: null,           // Bisa NULL
                    ':jurusan' => $form_data['jurusan'] ?: null,       // Bisa NULL
                    ':mata_pelajaran' => $form_data['mata_pelajaran'] ?: null, // Bisa NULL
                    ':user_type' => $form_data['user_type'],
                    ':privilege_level' => $form_data['privilege_level'],
                    ':is_active' => $form_data['is_active'],
                    ':created_at' => $current_time,
                    ':updated_at' => $current_time
                ]);
                
                $new_user_id = $db->lastInsertId();
                
                // Create audit log
                createAuditLog(
                    $_SESSION['user_id'],
                    'CREATE',
                    'users',
                    $new_user_id,
                    null,
                    [
                        'username' => $username,
                        'user_type' => $form_data['user_type'],
                        'privilege_level' => $form_data['privilege_level']
                    ]
                );
                
                // Kirim notifikasi ke user
                $notifications = [];
                
                // Kirim email (menggunakan fungsi yang sudah diperbaiki)
                if (!empty($form_data['email']) && filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
                    writeUserLog(USER_CREATION_LOG, ">>> MENGIRIM EMAIL KE: " . $form_data['email']);
                    $email_result = kirimEmail(
                        $form_data['email'],
                        $form_data['nama_lengkap'],
                        $username,
                        $password_asli
                    );
                    $notifications['email'] = $email_result;
                    
                    if ($email_result['success']) {
                        writeUserLog(USER_CREATION_LOG, "✓ EMAIL BERHASIL");
                    } else {
                        writeUserLog(USER_CREATION_LOG, "✗ EMAIL GAGAL: " . ($email_result['error'] ?? 'Unknown error'));
                        if (isset($email_result['response'])) {
                            writeUserLog(USER_CREATION_LOG, "Detail response:", $email_result['response']);
                        }
                    }
                }
                
                // Kirim WhatsApp (tetap sama)
                if (!empty($form_data['phone_number'])) {
                    writeUserLog(USER_CREATION_LOG, ">>> MENGIRIM WHATSAPP KE: " . $form_data['phone_number']);
                    $wa_result = kirimWhatsApp(
                        $form_data['phone_number'],
                        $form_data['nama_lengkap'],
                        $username,
                        $password_asli
                    );
                    $notifications['whatsapp'] = $wa_result;
                    
                    if ($wa_result['success']) {
                        writeUserLog(USER_CREATION_LOG, "✓ WHATSAPP BERHASIL");
                    } else {
                        writeUserLog(USER_CREATION_LOG, "✗ WHATSAPP GAGAL: " . ($wa_result['error'] ?? 'Unknown error'));
                    }
                }
                
                // Simpan log pembuatan user
                writeUserLog(USER_CREATION_LOG, "USER CREATED:", [
                    'user_id' => $new_user_id,
                    'username' => $username,
                    'nama_lengkap' => $form_data['nama_lengkap'],
                    'email' => $form_data['email'],
                    'phone' => $form_data['phone_number'],
                    'kelas' => $form_data['kelas'],
                    'jurusan' => $form_data['jurusan'],
                    'mata_pelajaran' => $form_data['mata_pelajaran'],
                    'user_type' => $form_data['user_type'],
                    'created_by' => $_SESSION['username'] ?? 'unknown',
                    'notifications' => $notifications,
                    'mailersend_active' => $mailersendConfig['is_active'] ?? 0,
                    'fonnte_active' => $fonnteConfig['is_active'] ?? 0
                ]);
                
                // Buat pesan sukses
                $success = 'User berhasil ditambahkan! ';
                if (!empty($notifications)) {
                    $success .= '<br>';
                    if (isset($notifications['email']) && $notifications['email']['success']) {
                        $success .= '✓ Email notifikasi terkirim<br>';
                    } elseif (isset($notifications['email'])) {
                        $success .= '✗ Email gagal: ' . $notifications['email']['error'] . '<br>';
                    }
                    
                    if (isset($notifications['whatsapp']) && $notifications['whatsapp']['success']) {
                        $success .= '✓ WhatsApp notifikasi terkirim<br>';
                    } elseif (isset($notifications['whatsapp'])) {
                        $success .= '✗ WhatsApp gagal: ' . $notifications['whatsapp']['error'] . '<br>';
                    }
                } else {
                    $success .= 'Tidak ada notifikasi yang dikirim.';
                }
                
                // Reset form data
                $form_data = [
                    'nama_lengkap' => '',
                    'email' => '',
                    'nis_nip' => '',
                    'phone_number' => '',
                    'kelas' => '',
                    'jurusan' => '',
                    'mata_pelajaran' => '',
                    'user_type' => 'Siswa',
                    'privilege_level' => 'Limited_Access',
                    'is_active' => 1,
                    'password_hash' => ''
                ];
            }
        } catch (Exception $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
            writeUserLog(USER_CREATION_LOG, "ERROR: " . $e->getMessage());
        }
    }
    
    if (!empty($errors)) {
        $error = implode('<br>', $errors);
    }
}

$pageTitle = 'Tambah User Baru';

require_once '../../includes/header.php';
?>

<style>
.card {
    border-radius: 10px;
}
.form-label {
    font-weight: 500;
}
.form-text {
    font-size: 0.85rem;
}
.alert ul {
    padding-left: 1.2rem;
}
.alert ul li {
    margin-bottom: 0.3rem;
}
.password-generator-panel {
    background: #f8f9fa;
    border-left: 4px solid #007bff;
    padding: 20px;
    margin-bottom: 20px;
    border-radius: 0 8px 8px 0;
}
.password-history {
    max-height: 300px;
    overflow-y: auto;
    font-size: 0.9rem;
}
.password-history-item {
    border-bottom: 1px dashed #dee2e6;
    padding: 8px 0;
}
.password-history-item:last-child {
    border-bottom: none;
}
.badge-password {
    background: #28a745;
    color: white;
    padding: 3px 8px;
    border-radius: 4px;
    font-family: monospace;
}
.modal-password {
    font-family: monospace;
    font-size: 1.1rem;
    background: #e9ecef;
    padding: 10px;
    border-radius: 5px;
}
.config-badge {
    background: #6c757d;
    color: white;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 10px;
    margin-left: 5px;
}
.mailersend-badge {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 10px;
}
.whatsapp-badge {
    background: linear-gradient(145deg, #25D366, #128C7E);
    color: white;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 10px;
}
@media (max-width: 768px) {
    .card-body {
        padding: 1rem;
    }
}
</style>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-0">
                <i class="fas fa-user-plus me-2"></i><?php echo $pageTitle; ?>
                <span class="config-badge ms-2">⚙️ dari settings.php</span>
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>">Beranda</a></li>
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="manage_users.php">Kelola User</a></li>
                    <li class="breadcrumb-item active">Tambah User</li>
                </ol>
            </nav>
        </div>
        <div>
            <a href="manage_users.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i> Kembali
            </a>
        </div>
    </div>

    <!-- Konfigurasi Info Panel -->
    <div class="alert alert-info bg-light border-0 mb-4">
        <div class="d-flex align-items-center">
            <i class="fas fa-cog me-2 fa-spin"></i>
            <div>
                <strong>⚙️ Konfigurasi Notifikasi Terpusat dari settings.php</strong><br>
                <span class="mailersend-badge me-2">
                    <i class="fas fa-envelope"></i> MailerSend: <?php echo $mailersendConfig['from_email'] ?? 'N/A'; ?>
                </span>
                <span class="whatsapp-badge me-2">
                    <i class="fab fa-whatsapp"></i> Fonnte: <?php echo $fonnteConfig['device_id'] ?? 'N/A'; ?>
                </span>
                <span class="badge bg-<?php echo ($mailersendConfig['is_active'] ?? 0) ? 'success' : 'secondary'; ?> me-1">
                    Email: <?php echo ($mailersendConfig['is_active'] ?? 0) ? 'Aktif' : 'Nonaktif'; ?>
                </span>
                <span class="badge bg-<?php echo ($fonnteConfig['is_active'] ?? 0) ? 'success' : 'secondary'; ?>">
                    WA: <?php echo ($fonnteConfig['is_active'] ?? 0) ? 'Aktif' : 'Nonaktif'; ?>
                </span>
                <small class="d-block mt-1 text-muted">
                    Notifikasi akan dikirim menggunakan konfigurasi di atas. Ubah di Admin → Settings jika perlu.
                </small>
            </div>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <!-- Password Generator Panel -->
    <div class="row">
        <div class="col-lg-8 mx-auto">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-primary text-white py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-key me-2"></i>Fitur Buat Password User
                    </h5>
                </div>
                <div class="card-body">
                    <div class="password-generator-panel">
                        <div class="row">
                            <div class="col-md-5">
                                <div class="mb-3">
                                    <label class="form-label">Username <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="gen_username" placeholder="Masukkan username">
                                    <small class="text-muted">Username untuk login</small>
                                </div>
                            </div>
                            <div class="col-md-5">
                                <div class="mb-3">
                                    <label class="form-label">Password Asli <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="gen_password" placeholder="Masukkan password">
                                        <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <small class="text-muted">Password yang akan diberikan ke user</small>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <button class="btn btn-primary w-100" id="generateBtn">
                                    <i class="fas fa-cog me-1"></i> Generate
                                </button>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-12">
                                <div class="alert alert-info mb-0">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>Informasi:</strong> Password hash akan otomatis tergenerate dan 
                                    disimpan ke log untuk backup. Password asli akan dikirim ke user via 
                                    <span class="mailersend-badge">MailerSend</span> dan 
                                    <span class="whatsapp-badge">Fonnte</span>.
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Hasil Generate -->
                    <div id="generateResult" style="display: none;" class="mt-3">
                        <div class="alert alert-success">
                            <h6><i class="fas fa-check-circle me-2"></i>Password Berhasil Digenerate:</h6>
                            <div class="row">
                                <div class="col-md-4">
                                    <strong>Username:</strong> 
                                    <span id="resultUsername" class="badge-password"></span>
                                </div>
                                <div class="col-md-4">
                                    <strong>Password Asli:</strong> 
                                    <span id="resultPassword" class="badge-password"></span>
                                </div>
                                <div class="col-md-4">
                                    <strong>Password Hash:</strong> 
                                    <span id="resultHash" class="badge-password"></span>
                                </div>
                            </div>
                            <hr>
                            <button class="btn btn-sm btn-success" id="usePasswordBtn">
                                <i class="fas fa-arrow-down me-1"></i> Gunakan Password Ini untuk User Baru
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" id="copyHashBtn">
                                <i class="fas fa-copy me-1"></i> Copy Hash
                            </button>
                        </div>
                    </div>
                    
                    <!-- Password History -->
                    <div class="mt-4">
                        <h6>
                            <i class="fas fa-history me-2"></i>History Password Terbaru
                            <button class="btn btn-sm btn-link" id="refreshHistory">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </h6>
                        <div class="password-history border rounded p-2" id="passwordHistory">
                            <div class="text-center text-muted py-3">
                                <i class="fas fa-spinner fa-spin me-2"></i>Memuat history...
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- User Form -->
    <div class="row">
        <div class="col-lg-8 mx-auto">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-user-cog me-2"></i>Form Tambah User
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="" id="addUserForm">
                        <!-- Hidden fields untuk password -->
                        <input type="hidden" name="username" id="input_username">
                        <input type="hidden" name="password_asli" id="input_password_asli">
                        <input type="hidden" name="password_hash" id="input_password_hash" value="<?php echo htmlspecialchars($form_data['password_hash']); ?>">
                        
                        <div class="row">
                            <!-- Personal Information -->
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="nama_lengkap" class="form-label">Nama Lengkap *</label>
                                    <input type="text" class="form-control" id="nama_lengkap" 
                                           name="nama_lengkap" value="<?php echo htmlspecialchars($form_data['nama_lengkap']); ?>" 
                                           required maxlength="100">
                                    <div class="form-text">Nama lengkap pengguna</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email *</label>
                                    <div class="input-group">
                                        <input type="email" class="form-control" id="email" 
                                               name="email" value="<?php echo htmlspecialchars($form_data['email']); ?>" 
                                               required maxlength="100">
                                        <button class="btn btn-outline-secondary" type="button" id="checkEmailBtn">
                                            <i class="fas fa-search"></i>
                                        </button>
                                    </div>
                                    <div class="form-text" id="emailStatus"></div>
                                    <small class="text-muted">
                                        <span class="mailersend-badge">MailerSend</span> Notifikasi akan dikirim ke email ini
                                    </small>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="nis_nip" class="form-label">NIS/NIP</label>
                                    <input type="text" class="form-control" id="nis_nip" 
                                           name="nis_nip" value="<?php echo htmlspecialchars($form_data['nis_nip']); ?>" 
                                           maxlength="20">
                                    <div class="form-text">Nomor Induk Siswa/Nomor Induk Pegawai</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="phone_number" class="form-label">Nomor Telepon/WhatsApp</label>
                                    <input type="tel" class="form-control" id="phone_number" 
                                           name="phone_number" value="<?php echo htmlspecialchars($form_data['phone_number']); ?>" 
                                           maxlength="20" placeholder="08123456789">
                                    <div class="form-text">Untuk notifikasi WhatsApp (format: 08123456789)</div>
                                    <small class="text-muted">
                                        <span class="whatsapp-badge">Fonnte</span> Notifikasi akan dikirim ke nomor ini
                                    </small>
                                </div>
                            </div>
                            
                            <!-- Account Information -->
                            <div class="col-md-6">
                                <!-- Field Kelas -->
                                <div class="mb-3">
                                    <label for="kelas" class="form-label">Kelas</label>
                                    <select class="form-select" id="kelas" name="kelas">
                                        <option value="">-- Pilih Kelas --</option>
                                        <option value="X" <?php echo ($form_data['kelas'] ?? '') == 'X' ? 'selected' : ''; ?>>X</option>
                                        <option value="XI" <?php echo ($form_data['kelas'] ?? '') == 'XI' ? 'selected' : ''; ?>>XI</option>
                                        <option value="XII" <?php echo ($form_data['kelas'] ?? '') == 'XII' ? 'selected' : ''; ?>>XII</option>
                                    </select>
                                    <div class="form-text">Pilih kelas (untuk Siswa)</div>
                                </div>
                                
                                <!-- Field Jurusan -->
                                <div class="mb-3">
                                    <label for="jurusan" class="form-label">Jurusan</label>
                                    <select class="form-select" id="jurusan" name="jurusan">
                                        <option value="">-- Pilih Jurusan --</option>
                                        <option value="IPA" <?php echo ($form_data['jurusan'] ?? '') == 'IPA' ? 'selected' : ''; ?>>IPA</option>
                                        <option value="IPS" <?php echo ($form_data['jurusan'] ?? '') == 'IPS' ? 'selected' : ''; ?>>IPS</option>
                                        <option value="Bahasa" <?php echo ($form_data['jurusan'] ?? '') == 'Bahasa' ? 'selected' : ''; ?>>Bahasa</option>
                                        <option value="TKJ" <?php echo ($form_data['jurusan'] ?? '') == 'TKJ' ? 'selected' : ''; ?>>TKJ</option>
                                        <option value="RPL" <?php echo ($form_data['jurusan'] ?? '') == 'RPL' ? 'selected' : ''; ?>>RPL</option>
                                        <option value="AKL" <?php echo ($form_data['jurusan'] ?? '') == 'AKL' ? 'selected' : ''; ?>>AKL</option>
                                        <option value="OTKP" <?php echo ($form_data['jurusan'] ?? '') == 'OTKP' ? 'selected' : ''; ?>>OTKP</option>
                                        <option value="BDP" <?php echo ($form_data['jurusan'] ?? '') == 'BDP' ? 'selected' : ''; ?>>BDP</option>
                                    </select>
                                    <div class="form-text">Pilih jurusan (untuk Siswa)</div>
                                </div>
                                
                                <!-- Field Mata Pelajaran (untuk Guru) -->
                                <div class="mb-3" id="mata_pelajaran_container">
                                    <label for="mata_pelajaran" class="form-label">Mata Pelajaran</label>
                                    <input type="text" class="form-control" id="mata_pelajaran" 
                                           name="mata_pelajaran" value="<?php echo htmlspecialchars($form_data['mata_pelajaran'] ?? ''); ?>" 
                                           maxlength="100" placeholder="Contoh: Matematika, Bahasa Indonesia, Fisika">
                                    <div class="form-text">Diisi jika tipe user adalah Guru</div>
                                </div>
                                
                                <!-- Field Tipe User -->
                                <div class="mb-3">
                                    <label for="user_type" class="form-label">Tipe User *</label>
                                    <select class="form-select" id="user_type" name="user_type" required>
                                        <option value="Siswa" <?php echo ($form_data['user_type'] ?? '') == 'Siswa' ? 'selected' : ''; ?>>Siswa</option>
                                        <option value="Guru" <?php echo ($form_data['user_type'] ?? '') == 'Guru' ? 'selected' : ''; ?>>Guru</option>
                                        <option value="Orang_Tua" <?php echo ($form_data['user_type'] ?? '') == 'Orang_Tua' ? 'selected' : ''; ?>>Orang Tua</option>
                                        <option value="Admin" <?php echo ($form_data['user_type'] ?? '') == 'Admin' ? 'selected' : ''; ?>>Admin</option>
                                    </select>
                                    <div class="form-text">Tentukan hak akses pengguna</div>
                                </div>
                                
                                <!-- Field Privilege Level -->
                                <div class="mb-3">
                                    <label for="privilege_level" class="form-label">Level Privilege</label>
                                    <select class="form-select" id="privilege_level" name="privilege_level">
                                        <option value="Limited_Access" <?php echo ($form_data['privilege_level'] ?? '') == 'Limited_Access' ? 'selected' : ''; ?>>Akses Terbatas</option>
                                        <option value="Standard_Access" <?php echo ($form_data['privilege_level'] ?? '') == 'Standard_Access' ? 'selected' : ''; ?>>Akses Standar</option>
                                        <option value="Full_Access" <?php echo ($form_data['privilege_level'] ?? '') == 'Full_Access' ? 'selected' : ''; ?>>Akses Penuh</option>
                                    </select>
                                    <div class="form-text">Hanya untuk user tertentu</div>
                                </div>
                                
                                <!-- Field Active Status -->
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" 
                                               id="is_active" name="is_active" value="1" 
                                               <?php echo ($form_data['is_active'] ?? 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_active">
                                            User Aktif
                                        </label>
                                    </div>
                                    <div class="form-text">Nonaktifkan untuk menonaktifkan akun sementara</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Status Password -->
                        <div class="alert alert-info mt-4" id="passwordStatus" style="display: none;">
                            <h6><i class="fas fa-lock me-2"></i>Status Password</h6>
                            <div id="passwordInfo"></div>
                        </div>
                        
                        <!-- Information Alert -->
                        <div class="alert alert-warning mt-4">
                            <h6><i class="fas fa-info-circle me-2"></i>Informasi Penting</h6>
                            <ul class="mb-0">
                                <li>Gunakan fitur <strong>Buat Password User</strong> di atas untuk membuat password</li>
                                <li>Password asli akan dikirim ke user via <span class="mailersend-badge">MailerSend</span> (Email) dan <span class="whatsapp-badge">Fonnte</span> (WhatsApp)</li>
                                <li>Password hash akan disimpan di database dan log untuk backup</li>
                                <li>Pastikan email dan nomor WhatsApp yang dimasukkan valid</li>
                                <li><strong>Untuk Siswa:</strong> wajib mengisi Kelas dan Jurusan</li>
                                <li><strong>Untuk Guru:</strong> wajib mengisi Mata Pelajaran</li>
                                <li><small class="text-muted">⚙️ Konfigurasi notifikasi dikelola melalui Admin → Settings</small></li>
                            </ul>
                        </div>
                        
                        <!-- Form Actions -->
                        <div class="d-flex justify-content-between mt-4">
                            <button type="reset" class="btn btn-outline-secondary">
                                <i class="fas fa-redo me-1"></i> Reset Form
                            </button>
                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                <i class="fas fa-save me-1"></i> Simpan User
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Quick Guide -->
            <div class="card border-0 shadow-sm mt-4">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-question-circle me-2"></i>Panduan Pengisian Field
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <h6>Siswa</h6>
                            <ul class="small">
                                <li>Wajib mengisi Kelas & Jurusan</li>
                                <li>Mata Pelajaran tidak perlu diisi</li>
                                <li>Dapat mengirim dan melihat pesan sendiri</li>
                                <li>Akses terbatas berdasarkan kelas/jurusan</li>
                            </ul>
                        </div>
                        <div class="col-md-4">
                            <h6>Guru</h6>
                            <ul class="small">
                                <li>Wajib mengisi Mata Pelajaran</li>
                                <li>Kelas & Jurusan tidak perlu diisi</li>
                                <li>Dapat mengirim dan merespon pesan</li>
                                <li>Akses berdasarkan mata pelajaran</li>
                            </ul>
                        </div>
                        <div class="col-md-4">
                            <h6>Orang Tua / Admin</h6>
                            <ul class="small">
                                <li>Kelas, Jurusan, Mapel tidak diperlukan</li>
                                <li>Orang Tua: memantau pesan anak</li>
                                <li>Admin: akses penuh ke semua fitur</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('addUserForm');
    const submitBtn = document.getElementById('submitBtn');
    const passwordStatus = document.getElementById('passwordStatus');
    const passwordInfo = document.getElementById('passwordInfo');
    
    // ========================================================================
    // PASSWORD GENERATOR
    // ========================================================================
    const genUsername = document.getElementById('gen_username');
    const genPassword = document.getElementById('gen_password');
    const generateBtn = document.getElementById('generateBtn');
    const generateResult = document.getElementById('generateResult');
    const resultUsername = document.getElementById('resultUsername');
    const resultPassword = document.getElementById('resultPassword');
    const resultHash = document.getElementById('resultHash');
    const usePasswordBtn = document.getElementById('usePasswordBtn');
    const copyHashBtn = document.getElementById('copyHashBtn');
    
    // Input hidden
    const inputUsername = document.getElementById('input_username');
    const inputPasswordAsli = document.getElementById('input_password_asli');
    const inputPasswordHash = document.getElementById('input_password_hash');
    
    // Toggle password visibility
    document.getElementById('togglePassword').addEventListener('click', function() {
        const type = genPassword.type === 'password' ? 'text' : 'password';
        genPassword.type = type;
        this.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
    });
    
    // Generate password
    generateBtn.addEventListener('click', function() {
        const username = genUsername.value.trim();
        const password = genPassword.value.trim();
        
        if (!username) {
            alert('Username harus diisi!');
            genUsername.focus();
            return;
        }
        
        if (!password) {
            alert('Password asli harus diisi!');
            genPassword.focus();
            return;
        }
        
        // Disable button
        generateBtn.disabled = true;
        generateBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Memproses...';
        
        // Kirim ke server
        fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'ajax_action=generate_password&username=' + encodeURIComponent(username) + 
                  '&password_asli=' + encodeURIComponent(password)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Tampilkan hasil
                resultUsername.textContent = data.username;
                resultPassword.textContent = data.password_asli;
                resultHash.textContent = data.password_hash.substring(0, 20) + '...';
                generateResult.style.display = 'block';
                
                // Update status password
                passwordStatus.style.display = 'block';
                passwordInfo.innerHTML = '<span class="text-success">✓ Password telah digenerate untuk username: <strong>' + 
                                        data.username + '</strong></span>';
                
                // Load history
                loadPasswordHistory();
                
                // Beri pesan
                alert('Password berhasil digenerate! Klik "Gunakan Password Ini" untuk mengisi form.');
            } else {
                alert('Error: ' + data.error);
            }
        })
        .catch(error => {
            alert('Error: ' + error);
        })
        .finally(() => {
            generateBtn.disabled = false;
            generateBtn.innerHTML = '<i class="fas fa-cog me-1"></i> Generate';
        });
    });
    
    // Gunakan password
    usePasswordBtn.addEventListener('click', function() {
        const username = resultUsername.textContent;
        const password = resultPassword.textContent;
        const hash = resultHash.textContent;
        
        // Isi hidden fields dengan data asli (bukan yang dipotong)
        inputUsername.value = username;
        inputPasswordAsli.value = password;
        inputPasswordHash.value = '<?php echo password_hash("temp", PASSWORD_DEFAULT); ?>'.replace('temp', password); // Ini akan diganti oleh server
        
        // Tampilkan informasi
        passwordStatus.style.display = 'block';
        passwordInfo.innerHTML = '<span class="text-success">✓ Password siap digunakan untuk username: <strong>' + 
                                username + '</strong></span>';
        
        // Scroll ke form
        form.scrollIntoView({ behavior: 'smooth' });
    });
    
    // Copy hash
    copyHashBtn.addEventListener('click', function() {
        const hash = resultHash.textContent;
        navigator.clipboard.writeText(hash).then(() => {
            alert('Password hash berhasil dicopy!');
        });
    });
    
    // ========================================================================
    // LOAD PASSWORD HISTORY
    // ========================================================================
    function loadPasswordHistory() {
        const historyDiv = document.getElementById('passwordHistory');
        
        fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'ajax_action=get_password_history'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.history.length > 0) {
                let html = '';
                data.history.forEach(item => {
                    html += '<div class="password-history-item">';
                    html += '<small class="text-muted">' + item.timestamp + '</small><br>';
                    html += '<strong>Username:</strong> ' + item.username + '<br>';
                    html += '<strong>Password:</strong> ' + item.password_asli + '<br>';
                    html += '<strong>Hash:</strong> <small>' + item.password_hash.substring(0, 30) + '...</small>';
                    html += '</div>';
                });
                historyDiv.innerHTML = html;
            } else {
                historyDiv.innerHTML = '<div class="text-center text-muted py-3">Belum ada history password</div>';
            }
        })
        .catch(error => {
            historyDiv.innerHTML = '<div class="text-center text-danger py-3">Error loading history</div>';
        });
    }
    
    // Load history on page load
    loadPasswordHistory();
    
    // Refresh history
    document.getElementById('refreshHistory').addEventListener('click', loadPasswordHistory);
    
    // ========================================================================
    // CHECK EMAIL
    // ========================================================================
    const emailInput = document.getElementById('email');
    const checkEmailBtn = document.getElementById('checkEmailBtn');
    const emailStatus = document.getElementById('emailStatus');
    
    checkEmailBtn.addEventListener('click', function() {
        const email = emailInput.value.trim();
        
        if (!email) {
            alert('Email harus diisi!');
            return;
        }
        
        emailStatus.innerHTML = '<span class="text-info"><i class="fas fa-spinner fa-spin"></i> Memeriksa email...</span>';
        
        fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'ajax_action=check_email&email=' + encodeURIComponent(email)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.exists) {
                    emailStatus.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle"></i> ' + data.message + '</span>';
                } else {
                    emailStatus.innerHTML = '<span class="text-success"><i class="fas fa-check-circle"></i> ' + data.message + '</span>';
                }
            } else {
                emailStatus.innerHTML = '<span class="text-danger">' + data.error + '</span>';
            }
        })
        .catch(error => {
            emailStatus.innerHTML = '<span class="text-danger">Error checking email</span>';
        });
    });
    
    // ========================================================================
    // DYNAMIC FIELDS BASED ON USER TYPE
    // ========================================================================
    function updateFieldsByUserType() {
        const userType = document.getElementById('user_type').value;
        const kelasField = document.getElementById('kelas').closest('.mb-3');
        const jurusanField = document.getElementById('jurusan').closest('.mb-3');
        const mapelContainer = document.getElementById('mata_pelajaran_container');
        
        if (userType === 'Siswa') {
            // Untuk Siswa: tampilkan kelas & jurusan, sembunyikan mata pelajaran
            kelasField.style.display = 'block';
            jurusanField.style.display = 'block';
            mapelContainer.style.display = 'none';
            
            // Reset mata pelajaran
            document.getElementById('mata_pelajaran').value = '';
            
            // Set required attribute
            document.getElementById('kelas').setAttribute('required', 'required');
            document.getElementById('jurusan').setAttribute('required', 'required');
            document.getElementById('mata_pelajaran').removeAttribute('required');
        } 
        else if (userType === 'Guru') {
            // Untuk Guru: sembunyikan kelas & jurusan, tampilkan mata pelajaran
            kelasField.style.display = 'none';
            jurusanField.style.display = 'none';
            mapelContainer.style.display = 'block';
            
            // Reset kelas & jurusan
            document.getElementById('kelas').value = '';
            document.getElementById('jurusan').value = '';
            
            // Set required attribute
            document.getElementById('kelas').removeAttribute('required');
            document.getElementById('jurusan').removeAttribute('required');
            document.getElementById('mata_pelajaran').setAttribute('required', 'required');
        }
        else {
            // Untuk Orang Tua & Admin: sembunyikan semua
            kelasField.style.display = 'none';
            jurusanField.style.display = 'none';
            mapelContainer.style.display = 'none';
            
            // Reset semua
            document.getElementById('kelas').value = '';
            document.getElementById('jurusan').value = '';
            document.getElementById('mata_pelajaran').value = '';
            
            // Remove required attributes
            document.getElementById('kelas').removeAttribute('required');
            document.getElementById('jurusan').removeAttribute('required');
            document.getElementById('mata_pelajaran').removeAttribute('required');
        }
    }
    
    // Panggil saat user type berubah
    document.getElementById('user_type').addEventListener('change', updateFieldsByUserType);
    
    // Panggil saat halaman dimuat
    updateFieldsByUserType();
    
    // ========================================================================
    // FORM VALIDATION
    // ========================================================================
    
    // Real-time validation
    const namaInput = document.getElementById('nama_lengkap');
    const phoneInput = document.getElementById('phone_number');
    
    if (namaInput) {
        namaInput.addEventListener('input', function() {
            if (this.value.length < 2) {
                this.classList.add('is-invalid');
            } else {
                this.classList.remove('is-invalid');
            }
        });
    }
    
    if (emailInput) {
        emailInput.addEventListener('blur', function() {
            const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailPattern.test(this.value)) {
                this.classList.add('is-invalid');
            } else {
                this.classList.remove('is-invalid');
            }
        });
    }
    
    if (phoneInput) {
        phoneInput.addEventListener('input', function() {
            // Hanya angka
            this.value = this.value.replace(/\D/g, '');
        });
    }
    
    // Form submission
    if (form) {
        form.addEventListener('submit', function(e) {
            let valid = true;
            
            // Validate required fields
            const requiredFields = form.querySelectorAll('[required]');
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.classList.add('is-invalid');
                    valid = false;
                } else {
                    field.classList.remove('is-invalid');
                }
            });
            
            // Validate email format
            if (emailInput && emailInput.value) {
                const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailPattern.test(emailInput.value)) {
                    emailInput.classList.add('is-invalid');
                    valid = false;
                }
            }
            
            // Validate password has been generated
            if (!inputPasswordHash.value) {
                alert('Harap generate password terlebih dahulu menggunakan fitur Buat Password User!');
                valid = false;
            }
            
            if (!valid) {
                e.preventDefault();
                return false;
            }
            
            // Show loading state
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Menyimpan...';
            }
        });
    }
    
    // User type change handler tambahan untuk update privilege level
    const userTypeSelect = document.getElementById('user_type');
    const privilegeSelect = document.getElementById('privilege_level');
    
    if (userTypeSelect) {
        userTypeSelect.addEventListener('change', function() {
            const selectedType = this.value;
            
            // Update privilege level based on user type
            if (privilegeSelect) {
                if (selectedType === 'Admin') {
                    privilegeSelect.value = 'Full_Access';
                } else if (selectedType === 'Guru') {
                    privilegeSelect.value = 'Standard_Access';
                } else {
                    privilegeSelect.value = 'Limited_Access';
                }
            }
        });
    }
});
</script>

<?php
require_once '../../includes/footer.php';

// Helper Functions
function validateUserData($data) {
    $errors = [];
    
    // Validate nama lengkap
    if (empty($data['nama_lengkap'])) {
        $errors[] = 'Nama lengkap harus diisi';
    } elseif (strlen($data['nama_lengkap']) < 2) {
        $errors[] = 'Nama lengkap minimal 2 karakter';
    } elseif (strlen($data['nama_lengkap']) > 100) {
        $errors[] = 'Nama lengkap maksimal 100 karakter';
    }
    
    // Validate email
    if (empty($data['email'])) {
        $errors[] = 'Email harus diisi';
    } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email tidak valid';
    } elseif (strlen($data['email']) > 100) {
        $errors[] = 'Email maksimal 100 karakter';
    }
    
    // Validate NIS/NIP
    if (!empty($data['nis_nip']) && strlen($data['nis_nip']) > 20) {
        $errors[] = 'NIS/NIP maksimal 20 karakter';
    }
    
    // Validate phone number
    if (!empty($data['phone_number']) && !preg_match('/^[0-9+\-\s()]{10,20}$/', $data['phone_number'])) {
        $errors[] = 'Format nomor telepon tidak valid';
    }
    
    // Validate kelas (only for Siswa)
    if ($data['user_type'] == 'Siswa') {
        if (empty($data['kelas'])) {
            $errors[] = 'Kelas harus diisi untuk Siswa';
        }
        if (empty($data['jurusan'])) {
            $errors[] = 'Jurusan harus diisi untuk Siswa';
        }
    }
    
    // Validate mata_pelajaran (only for Guru)
    if ($data['user_type'] == 'Guru' && empty($data['mata_pelajaran'])) {
        $errors[] = 'Mata pelajaran harus diisi untuk Guru';
    }
    
    // Validate user type
    $allowed_user_types = ['Siswa', 'Guru', 'Orang_Tua', 'Admin'];
    if (!in_array($data['user_type'], $allowed_user_types)) {
        $errors[] = 'Tipe user tidak valid';
    }
    
    // Validate privilege level
    $allowed_privileges = ['Limited_Access', 'Standard_Access', 'Full_Access'];
    if (!in_array($data['privilege_level'], $allowed_privileges)) {
        $errors[] = 'Level privilege tidak valid';
    }
    
    return $errors;
}

function createAuditLog($user_id, $action, $table_name, $record_id, $old_value = null, $new_value = null) {
    try {
        $db = Database::getInstance()->getConnection();
        
        $sql = "
            INSERT INTO audit_logs (
                user_id, action, table_name, record_id, 
                old_value, new_value, ip_address, user_agent, created_at
            ) VALUES (
                :user_id, :action, :table_name, :record_id,
                :old_value, :new_value, :ip_address, :user_agent, NOW()
            )
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':user_id' => $user_id,
            ':action' => $action,
            ':table_name' => $table_name,
            ':record_id' => $record_id,
            ':old_value' => $old_value ? json_encode($old_value) : null,
            ':new_value' => $new_value ? json_encode($new_value) : null,
            ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
        ]);
        
        return true;
    } catch (Exception $e) {
        error_log("Audit log error: " . $e->getMessage());
        return false;
    }
}
?>