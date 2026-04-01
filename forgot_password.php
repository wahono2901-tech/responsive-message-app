<?php
/**
 * Forgot Password Page - Reset Password dengan Verifikasi dan Notifikasi
 * File: forgot_password.php
 * 
 * FITUR:
 * - Verifikasi email atau username yang terdaftar di database
 * - Reset password dengan input manual oleh user
 * - Mengirim password baru via Email (MailerSend) dan WhatsApp (Fonnte)
 * - Menyimpan password hash ke database, password asli untuk notifikasi
 * - Logging lengkap untuk history (tanpa ditampilkan ke user)
 * - Konfigurasi terpusat dari settings.php
 */

// Aktifkan error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug_error.log');
ini_set('max_execution_time', 120);

// Load konfigurasi
require_once __DIR__ . '/config/config.php';

// Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load database
require_once __DIR__ . '/config/database.php';

// Redirect jika sudah login
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// ============================================================================
// LOGGING SETUP - UNTUK HISTORY ADMIN (TIDAK DITAMPILKAN KE USER)
// ============================================================================
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

define('FORGOT_PASSWORD_LOG', $logDir . '/forgot_password.log');
define('PASSWORD_RESET_LOG', $logDir . '/password_reset.log');
define('EMAIL_DEBUG_LOG', $logDir . '/email_debug.log');
define('WHATSAPP_DEBUG_LOG', $logDir . '/whatsapp_debug.log');

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

function forgotLog($message, $data = null) {
    writeLog(FORGOT_PASSWORD_LOG, $message, $data);
}

function resetLog($message, $data = null) {
    writeLog(PASSWORD_RESET_LOG, $message, $data);
}

function emailLog($message, $data = null) {
    writeLog(EMAIL_DEBUG_LOG, $message, $data);
}

function waLog($message, $data = null) {
    writeLog(WHATSAPP_DEBUG_LOG, $message, $data);
}

forgotLog("========== FORGOT PASSWORD PAGE STARTED ==========");

// ============================================================================
// LOAD KONFIGURASI TERPUSAT DARI SETTINGS.PHP
// ============================================================================
$mailersendConfig = [];
$fonnteConfig = [];

// Load MailerSend config
$mailersendConfigFile = ROOT_PATH . '/config/mailersend.json';
if (file_exists($mailersendConfigFile)) {
    $mailersendConfig = json_decode(file_get_contents($mailersendConfigFile), true);
    forgotLog("MailerSend config loaded dari settings.php", [
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
    forgotLog("MailerSend config default digunakan");
}

// Load Fonnte config
$fonnteConfigFile = ROOT_PATH . '/config/fonnte.json';
if (file_exists($fonnteConfigFile)) {
    $fonnteConfig = json_decode(file_get_contents($fonnteConfigFile), true);
    forgotLog("Fonnte config loaded dari settings.php", [
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
    forgotLog("Fonnte config default digunakan");
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

// ============================================================================
// DATABASE CONNECTION
// ============================================================================
try {
    $db = Database::getInstance()->getConnection();
    forgotLog("Database connection successful");
} catch (Exception $e) {
    forgotLog("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please try again later.");
}

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
// FUNGSI KIRIM WHATSAPP VIA FONNTE (UNTUK NOTIFIKASI PASSWORD BARU)
// ============================================================================
function kirimWhatsAppPassword($phone, $nama, $username, $password_baru) {
    global $fonnteConfig;
    
    waLog("Mengirim WhatsApp notifikasi password baru ke: $phone untuk user: $username");
    
    // Cek apakah Fonnte aktif
    if (!isset($fonnteConfig['is_active']) || $fonnteConfig['is_active'] != 1) {
        waLog("Fonnte tidak aktif, lewati pengiriman WhatsApp");
        return ['success' => false, 'error' => 'Layanan WhatsApp tidak aktif'];
    }
    
    // Format nomor
    $formatted_phone = formatPhoneNumber($phone);
    
    if (strlen($formatted_phone) < 10 || strlen($formatted_phone) > 15) {
        waLog("ERROR: Nomor WhatsApp tidak valid: $phone");
        return ['success' => false, 'error' => 'Nomor tidak valid'];
    }
    
    // Pesan notifikasi reset password
    $message = "🔐 *RESET PASSWORD - SMKN 12 Jakarta*\n\n";
    $message .= "Yth. *$nama*\n\n";
    $message .= "Password akun Anda telah berhasil direset.\n\n";
    $message .= "*DATA LOGIN BARU:*\n";
    $message .= "Username: $username\n";
    $message .= "Password Baru: $password_baru\n\n";
    $message .= "Login di: " . BASE_URL . "login.php\n\n";
    $message .= "Segera login dan ganti password Anda untuk keamanan.\n";
    $message .= "Jika Anda tidak merasa melakukan reset password, segera hubungi Administrator.\n\n";
    $message .= "_Pesan otomatis dari sistem._";
    
    waLog("Pesan WhatsApp panjang: " . strlen($message));
    
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
        waLog("✓ WhatsApp notifikasi password berhasil dikirim ke $formatted_phone");
    } else {
        waLog("✗ WhatsApp notifikasi password gagal dikirim ke $formatted_phone: " . ($curlError ?: ($response_data['reason'] ?? 'Unknown error')));
    }
    
    return [
        'success' => $success,
        'error' => $curlError ?: ($response_data['reason'] ?? 'Unknown error')
    ];
}

// ============================================================================
// FUNGSI KIRIM EMAIL VIA MAILERSEND (UNTUK NOTIFIKASI PASSWORD BARU)
// ============================================================================
function kirimEmailPassword($email, $nama, $username, $password_baru) {
    global $mailersendConfig;
    
    emailLog("Mengirim email notifikasi password baru ke: $email untuk user: $username");
    
    // Cek apakah MailerSend aktif
    if (!isset($mailersendConfig['is_active']) || $mailersendConfig['is_active'] != 1) {
        emailLog("MailerSend tidak aktif, lewati pengiriman email");
        return ['success' => false, 'error' => 'Layanan email tidak aktif'];
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        emailLog("ERROR: Email tidak valid: $email");
        return ['success' => false, 'error' => 'Email tidak valid'];
    }
    
    if (empty($mailersendConfig['api_token'])) {
        emailLog("ERROR: API Token kosong");
        return ['success' => false, 'error' => 'API Token tidak boleh kosong'];
    }
    
    if (empty($mailersendConfig['from_email'])) {
        emailLog("ERROR: From Email kosong");
        return ['success' => false, 'error' => 'From Email tidak boleh kosong'];
    }
    
    $subject = "Reset Password Berhasil - SMKN 12 Jakarta";
    
    // HTML Email
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; background-color: #f4f6f9; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .header { background: linear-gradient(135deg, #ffc107, #fd7e14); color: white; padding: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 28px; }
        .header p { margin: 10px 0 0; opacity: 0.9; }
        .content { padding: 30px; }
        .credentials { background: #f8f9fa; border-left: 4px solid #ffc107; padding: 20px; margin: 20px 0; border-radius: 0 5px 5px 0; }
        .credential-box { background: white; padding: 15px; border-radius: 5px; }
        .credential-item { padding: 10px 0; border-bottom: 1px solid #e9ecef; }
        .credential-item:last-child { border-bottom: none; }
        .credential-label { color: #6c757d; font-weight: bold; display: inline-block; width: 100px; }
        .credential-value { font-family: monospace; color: #fd7e14; font-weight: bold; }
        .btn { display: inline-block; background: #fd7e14; color: white; padding: 12px 30px; text-decoration: none; border-radius: 50px; font-weight: bold; margin: 10px 0; }
        .footer { background: #e9ecef; padding: 20px; text-align: center; color: #6c757d; font-size: 12px; border-top: 1px solid #dee2e6; }
        .warning { color: #dc3545; font-size: 13px; }
        .config-info { background: #e8f5e9; border-left: 4px solid #25D366; padding: 10px; margin-top: 10px; font-size: 11px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔐 SMKN 12 Jakarta</h1>
            <p>Reset Password Berhasil</p>
        </div>
        
        <div class="content">
            <p>Yth. <strong>' . htmlspecialchars($nama) . '</strong>,</p>
            
            <p>Password akun Anda telah berhasil direset di Aplikasi Pesan Responsif SMKN 12 Jakarta.</p>
            
            <div class="credentials">
                <h4 style="margin-top: 0; color: #fd7e14;">📋 INFORMASI LOGIN BARU</h4>
                
                <div class="credential-box">
                    <div class="credential-item">
                        <span class="credential-label">Username:</span>
                        <span class="credential-value">' . htmlspecialchars($username) . '</span>
                    </div>
                    
                    <div class="credential-item">
                        <span class="credential-label">Password Baru:</span>
                        <span class="credential-value">' . htmlspecialchars($password_baru) . '</span>
                    </div>
                    
                    <div class="credential-item">
                        <span class="credential-label">Tanggal:</span>
                        <span class="credential-value">' . date('d/m/Y H:i') . ' WIB</span>
                    </div>
                </div>
                
                <p style="margin-top: 20px; color: #fd7e14;">
                    <strong>PENTING:</strong> Segera login dan ganti password Anda untuk keamanan!
                </p>
            </div>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="' . BASE_URL . 'login.php" class="btn">🔐 Login Sekarang</a>
            </div>
            
            <p class="warning">
                ⚠️ Jika Anda tidak merasa melakukan reset password, segera hubungi Administrator.
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
    
    emailLog("Data email:", [
        'from' => $mailersendConfig['from_email'],
        'to' => $email,
        'subject' => $subject
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
    
    emailLog("HTTP Code: $httpCode");
    if ($curlError) {
        emailLog("CURL Error: $curlError");
    }
    emailLog("Response: $response");
    
    $response_data = json_decode($response, true);
    $success = ($httpCode >= 200 && $httpCode < 300);
    
    if ($success) {
        emailLog("✓ Email notifikasi password berhasil dikirim ke $email");
    } else {
        emailLog("✗ Email notifikasi password gagal dikirim ke $email");
        if (isset($response_data['message'])) {
            emailLog("Pesan error: " . $response_data['message']);
        }
    }
    
    return [
        'success' => $success,
        'sent' => $success,
        'error' => $curlError ?: ($response_data['message'] ?? 'Unknown error'),
        'http_code' => $httpCode
    ];
}

// ============================================================================
// PROSES FORM
// ============================================================================
$error = '';
$success = '';
$step = 1; // 1: Verifikasi, 2: Reset Password
$user_data = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // STEP 1: Verifikasi Email/Username
    if (isset($_POST['verify'])) {
        $identifier = trim($_POST['identifier'] ?? '');
        
        forgotLog("STEP 1: Verifikasi identifier: $identifier");
        
        if (empty($identifier)) {
            $error = 'Email atau Username harus diisi';
        } else {
            // PERBAIKAN: Query yang benar dengan OR dan AND yang tepat
            $sql = "SELECT id, username, email, nama_lengkap, phone_number 
                    FROM users 
                    WHERE (email = :email_identifier OR username = :username_identifier) 
                    AND is_active = 1";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':email_identifier' => $identifier,
                ':username_identifier' => $identifier
            ]);
            
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                forgotLog("User ditemukan:", [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email']
                ]);
                
                // Simpan data user ke session untuk step 2
                $_SESSION['reset_user'] = $user;
                $step = 2;
                $user_data = $user;
                
                forgotLog("Lanjut ke STEP 2 untuk user ID: " . $user['id']);
            } else {
                $error = 'Email/Username tidak ditemukan atau akun tidak aktif';
                forgotLog("User tidak ditemukan untuk identifier: $identifier");
            }
        }
    }
    
    // STEP 2: Reset Password
    elseif (isset($_POST['reset_password'])) {
        // Pastikan ada data user di session
        if (!isset($_SESSION['reset_user'])) {
            $error = 'Sesi tidak valid. Silakan mulai dari awal.';
            $step = 1;
            forgotLog("ERROR: Session reset_user tidak ditemukan");
        } else {
            $user = $_SESSION['reset_user'];
            $password_baru = $_POST['password_baru'] ?? '';
            $konfirmasi_password = $_POST['konfirmasi_password'] ?? '';
            
            forgotLog("STEP 2: Reset password untuk user ID: " . $user['id']);
            
            // Validasi password
            $validation_errors = [];
            
            if (empty($password_baru)) {
                $validation_errors[] = 'Password baru harus diisi';
            } elseif (strlen($password_baru) < 8) {
                $validation_errors[] = 'Password minimal 8 karakter';
            }
            
            if ($password_baru !== $konfirmasi_password) {
                $validation_errors[] = 'Password dan konfirmasi password tidak cocok';
            }
            
            if (empty($validation_errors)) {
                try {
                    // Generate password hash
                    $password_hash = password_hash($password_baru, PASSWORD_DEFAULT);
                    
                    // Update password di database
                    $update_sql = "UPDATE users SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id";
                    $update_stmt = $db->prepare($update_sql);
                    $update_result = $update_stmt->execute([
                        ':password_hash' => $password_hash,
                        ':id' => $user['id']
                    ]);
                    
                    if ($update_result) {
                        forgotLog("✓ Password berhasil diupdate untuk user ID: " . $user['id']);
                        
                        // Simpan log reset password (untuk history admin)
                        $reset_log = [
                            'timestamp' => date('Y-m-d H:i:s'),
                            'user_id' => $user['id'],
                            'username' => $user['username'],
                            'email' => $user['email'],
                            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                        ];
                        resetLog("PASSWORD RESET SUCCESSFUL:", $reset_log);
                        
                        // Kirim notifikasi ke user
                        $notifications = [];
                        $notification_summary = [];
                        
                        // Kirim Email
                        if (!empty($user['email']) && filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
                            forgotLog(">>> MENGIRIM EMAIL KE: " . $user['email']);
                            $email_result = kirimEmailPassword(
                                $user['email'],
                                $user['nama_lengkap'],
                                $user['username'],
                                $password_baru
                            );
                            $notifications['email'] = $email_result;
                            
                            if ($email_result['success']) {
                                forgotLog("✓ EMAIL NOTIFIKASI BERHASIL");
                                $notification_summary[] = "Email";
                            } else {
                                forgotLog("✗ EMAIL NOTIFIKASI GAGAL: " . ($email_result['error'] ?? 'Unknown error'));
                            }
                        }
                        
                        // Kirim WhatsApp
                        if (!empty($user['phone_number'])) {
                            forgotLog(">>> MENGIRIM WHATSAPP KE: " . $user['phone_number']);
                            $wa_result = kirimWhatsAppPassword(
                                $user['phone_number'],
                                $user['nama_lengkap'],
                                $user['username'],
                                $password_baru
                            );
                            $notifications['whatsapp'] = $wa_result;
                            
                            if ($wa_result['success']) {
                                forgotLog("✓ WHATSAPP NOTIFIKASI BERHASIL");
                                $notification_summary[] = "WhatsApp";
                            } else {
                                forgotLog("✗ WHATSAPP NOTIFIKASI GAGAL: " . ($wa_result['error'] ?? 'Unknown error'));
                            }
                        }
                        
                        // Simpan log notifikasi
                        forgotLog("NOTIFICATION SUMMARY:", [
                            'sent' => $notification_summary,
                            'details' => $notifications
                        ]);
                        
                        // Buat pesan sukses untuk user
                        $success = 'Password berhasil direset! ';
                        if (!empty($notification_summary)) {
                            $success .= 'Notifikasi telah dikirim via ' . implode(' dan ', $notification_summary) . '.';
                        } else {
                            $success .= 'Silakan login dengan password baru Anda.';
                        }
                        
                        // Hapus session reset_user
                        unset($_SESSION['reset_user']);
                        $step = 3; // Step sukses
                        
                    } else {
                        $error = 'Gagal mengupdate password. Silakan coba lagi.';
                        forgotLog("ERROR: Gagal update password di database");
                    }
                    
                } catch (Exception $e) {
                    $error = 'Terjadi kesalahan sistem: ' . $e->getMessage();
                    forgotLog("EXCEPTION: " . $e->getMessage());
                }
            } else {
                $error = implode('<br>', $validation_errors);
                forgotLog("VALIDASI GAGAL:", $validation_errors);
            }
        }
    }
}

// Jika ada error dan masih di step 2, tetap tampilkan form reset dengan data user
if (isset($_SESSION['reset_user']) && $step == 1 && empty($success)) {
    $step = 2;
    $user_data = $_SESSION['reset_user'];
}

$pageTitle = 'Lupa Password';

require_once 'includes/header.php';
?>

<style>
.forgot-password-container {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.forgot-card {
    max-width: 500px;
    width: 100%;
    background: white;
    border-radius: 20px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
    overflow: hidden;
}

.forgot-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 30px;
    text-align: center;
}

.forgot-header h1 {
    margin: 0;
    font-size: 28px;
    font-weight: 600;
}

.forgot-header p {
    margin: 10px 0 0;
    opacity: 0.9;
}

.forgot-body {
    padding: 30px;
}

.step-indicator {
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 30px;
}

.step-item {
    display: flex;
    align-items: center;
}

.step-circle {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #e9ecef;
    color: #6c757d;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 18px;
    transition: all 0.3s ease;
}

.step-circle.active {
    background: #667eea;
    color: white;
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
}

.step-circle.completed {
    background: #28a745;
    color: white;
}

.step-line {
    width: 50px;
    height: 2px;
    background: #e9ecef;
    margin: 0 10px;
}

.step-line.active {
    background: #667eea;
}

.step-label {
    margin-top: 10px;
    font-size: 12px;
    color: #6c757d;
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

.password-strength {
    margin-top: 5px;
}

.password-strength .progress {
    height: 5px;
    border-radius: 3px;
}

.password-strength .progress-bar {
    transition: width 0.3s ease;
}

.user-info-card {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 15px;
    margin-bottom: 20px;
}

.user-info-item {
    display: flex;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #e9ecef;
}

.user-info-item:last-child {
    border-bottom: none;
}

.user-info-label {
    width: 100px;
    font-weight: 600;
    color: #495057;
}

.user-info-value {
    flex: 1;
    color: #212529;
}

.alert {
    margin-bottom: 20px;
}

.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    padding: 12px;
    font-weight: 600;
    transition: all 0.3s ease;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
}

.btn-outline-secondary:hover {
    background: #f8f9fa;
}

.form-control:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
}

@media (max-width: 576px) {
    .forgot-body {
        padding: 20px;
    }
    
    .step-line {
        width: 30px;
    }
}
</style>

<div class="forgot-password-container">
    <div class="forgot-card">
        <div class="forgot-header">
            <h1>
                <i class="fas fa-key me-2"></i>Lupa Password
                <span class="config-badge">⚙️ settings.php</span>
            </h1>
            <p>SMKN 12 Jakarta - Aplikasi Pesan Responsif</p>
        </div>
        
        <div class="forgot-body">
            <!-- Konfigurasi Info Panel (hanya background, tidak ditampilkan mencolok) -->
            <div class="text-center mb-3">
                <span class="mailersend-badge me-1">
                    <i class="fas fa-envelope"></i> MailerSend
                </span>
                <span class="whatsapp-badge me-1">
                    <i class="fab fa-whatsapp"></i> Fonnte
                </span>
                <small class="text-muted ms-2">Notifikasi akan dikirim</small>
            </div>
            
            <!-- Step Indicator -->
            <div class="step-indicator">
                <div class="step-item">
                    <div class="step-circle <?php echo $step >= 1 ? 'active' : ''; ?> <?php echo $step > 1 ? 'completed' : ''; ?>">
                        <?php if ($step > 1): ?>
                            <i class="fas fa-check"></i>
                        <?php else: ?>
                            1
                        <?php endif; ?>
                    </div>
                    <div class="step-label">Verifikasi</div>
                </div>
                <div class="step-line <?php echo $step >= 2 ? 'active' : ''; ?>"></div>
                <div class="step-item">
                    <div class="step-circle <?php echo $step >= 2 ? 'active' : ''; ?> <?php echo $step > 2 ? 'completed' : ''; ?>">
                        <?php if ($step > 2): ?>
                            <i class="fas fa-check"></i>
                        <?php else: ?>
                            2
                        <?php endif; ?>
                    </div>
                    <div class="step-label">Reset Password</div>
                </div>
            </div>
            
            <!-- Error Message -->
            <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Success Message -->
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                <div class="mt-3">
                    <a href="login.php" class="btn btn-sm btn-success">
                        <i class="fas fa-sign-in-alt me-1"></i> Login Sekarang
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- STEP 1: Form Verifikasi -->
            <?php if ($step == 1 && !$success): ?>
            <form method="POST" action="" id="verifyForm">
                <div class="mb-4">
                    <label for="identifier" class="form-label fw-bold">
                        <i class="fas fa-user me-1"></i> Email atau Username
                    </label>
                    <input type="text" 
                           class="form-control form-control-lg" 
                           id="identifier" 
                           name="identifier" 
                           placeholder="Masukkan email atau username Anda"
                           required
                           value="<?php echo htmlspecialchars($_POST['identifier'] ?? ''); ?>">
                    <small class="text-muted">
                        Masukkan email atau username yang terdaftar di sistem
                    </small>
                </div>
                
                <button type="submit" name="verify" value="1" class="btn btn-primary btn-lg w-100 mb-3" id="verifyBtn">
                    <i class="fas fa-arrow-right me-2"></i>Lanjutkan
                </button>
                
                <div class="text-center">
                    <a href="login.php" class="text-decoration-none">
                        <i class="fas fa-arrow-left me-1"></i> Kembali ke Login
                    </a>
                </div>
            </form>
            <?php endif; ?>
            
            <!-- STEP 2: Form Reset Password -->
            <?php if ($step == 2 && isset($user_data) && !empty($user_data) && !$success): ?>
            <div class="user-info-card">
                <div class="user-info-item">
                    <span class="user-info-label">Username</span>
                    <span class="user-info-value"><?php echo htmlspecialchars($user_data['username']); ?></span>
                </div>
                <div class="user-info-item">
                    <span class="user-info-label">Nama</span>
                    <span class="user-info-value"><?php echo htmlspecialchars($user_data['nama_lengkap']); ?></span>
                </div>
                <?php if (!empty($user_data['email'])): ?>
                <div class="user-info-item">
                    <span class="user-info-label">Email</span>
                    <span class="user-info-value">
                        <?php echo htmlspecialchars($user_data['email']); ?>
                        <span class="mailersend-badge ms-2">MailerSend</span>
                    </span>
                </div>
                <?php endif; ?>
                <?php if (!empty($user_data['phone_number'])): ?>
                <div class="user-info-item">
                    <span class="user-info-label">WhatsApp</span>
                    <span class="user-info-value">
                        <?php echo htmlspecialchars($user_data['phone_number']); ?>
                        <span class="whatsapp-badge ms-2">Fonnte</span>
                    </span>
                </div>
                <?php endif; ?>
            </div>
            
            <form method="POST" action="" id="resetForm">
                <input type="hidden" name="reset_password" value="1">
                
                <div class="mb-3">
                    <label for="password_baru" class="form-label fw-bold">
                        <i class="fas fa-lock me-1"></i> Password Baru
                    </label>
                    <div class="input-group">
                        <input type="password" 
                               class="form-control form-control-lg" 
                               id="password_baru" 
                               name="password_baru" 
                               placeholder="Minimal 8 karakter"
                               minlength="8"
                               required>
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('password_baru', this)">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div id="passwordStrength" class="password-strength mt-2"></div>
                </div>
                
                <div class="mb-4">
                    <label for="konfirmasi_password" class="form-label fw-bold">
                        <i class="fas fa-lock me-1"></i> Konfirmasi Password Baru
                    </label>
                    <div class="input-group">
                        <input type="password" 
                               class="form-control form-control-lg" 
                               id="konfirmasi_password" 
                               name="konfirmasi_password" 
                               placeholder="Ketik ulang password baru"
                               minlength="8"
                               required>
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('konfirmasi_password', this)">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div id="passwordMatch" class="mt-2"></div>
                </div>
                
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Password baru akan dikirim ke email dan WhatsApp Anda untuk konfirmasi.
                </div>
                
                <button type="submit" class="btn btn-primary btn-lg w-100 mb-3" id="resetBtn">
                    <i class="fas fa-key me-2"></i>Reset Password
                </button>
                
                <div class="text-center">
                    <button type="button" class="btn btn-link" onclick="window.location.href='?restart=1'">
                        <i class="fas fa-redo me-1"></i> Mulai Ulang
                    </button>
                </div>
            </form>
            <?php endif; ?>
            
            <!-- STEP 3: Success (sudah ditampilkan di alert) -->
            
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle password visibility
    window.togglePassword = function(inputId, button) {
        const input = document.getElementById(inputId);
        const icon = button.querySelector('i');
        
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    };
    
    // Password strength indicator
    const passwordInput = document.getElementById('password_baru');
    const confirmInput = document.getElementById('konfirmasi_password');
    const passwordStrength = document.getElementById('passwordStrength');
    const passwordMatch = document.getElementById('passwordMatch');
    
    if (passwordInput) {
        passwordInput.addEventListener('input', function() {
            const password = this.value;
            const strength = calculatePasswordStrength(password);
            updatePasswordStrength(strength);
            
            // Check match if confirm has value
            if (confirmInput && confirmInput.value) {
                checkPasswordMatch();
            }
        });
    }
    
    if (confirmInput) {
        confirmInput.addEventListener('input', checkPasswordMatch);
    }
    
    function calculatePasswordStrength(password) {
        let score = 0;
        
        if (password.length >= 8) score++;
        if (/[a-z]/.test(password)) score++;
        if (/[A-Z]/.test(password)) score++;
        if (/[0-9]/.test(password)) score++;
        if (/[^a-zA-Z0-9]/.test(password)) score++;
        
        return Math.min(score, 5);
    }
    
    function updatePasswordStrength(strength) {
        if (!passwordStrength) return;
        
        const levels = ['Sangat Lemah', 'Lemah', 'Cukup', 'Kuat', 'Sangat Kuat'];
        const colors = ['danger', 'warning', 'info', 'primary', 'success'];
        
        if (strength === 0) {
            passwordStrength.innerHTML = '';
            return;
        }
        
        passwordStrength.innerHTML = `
            <div class="progress">
                <div class="progress-bar bg-${colors[strength - 1]}" role="progressbar" 
                     style="width: ${(strength / 5) * 100}%"></div>
            </div>
            <small class="text-${colors[strength - 1]} mt-1 d-block">
                Kekuatan: ${levels[strength - 1]}
            </small>
        `;
    }
    
    function checkPasswordMatch() {
        if (!passwordInput || !confirmInput || !passwordMatch) return;
        
        const pass1 = passwordInput.value;
        const pass2 = confirmInput.value;
        
        if (pass2.length === 0) {
            passwordMatch.innerHTML = '';
            return;
        }
        
        if (pass1 === pass2) {
            passwordMatch.innerHTML = '<small class="text-success"><i class="fas fa-check-circle"></i> Password cocok</small>';
            confirmInput.classList.remove('is-invalid');
            confirmInput.classList.add('is-valid');
        } else {
            passwordMatch.innerHTML = '<small class="text-danger"><i class="fas fa-exclamation-circle"></i> Password tidak cocok</small>';
            confirmInput.classList.remove('is-valid');
            confirmInput.classList.add('is-invalid');
        }
    }
    
    // Form validation
    const verifyForm = document.getElementById('verifyForm');
    if (verifyForm) {
        verifyForm.addEventListener('submit', function(e) {
            const identifier = document.getElementById('identifier').value.trim();
            if (!identifier) {
                e.preventDefault();
                alert('Email atau username harus diisi!');
            }
        });
    }
    
    const resetForm = document.getElementById('resetForm');
    if (resetForm) {
        resetForm.addEventListener('submit', function(e) {
            const password = passwordInput.value;
            const confirm = confirmInput.value;
            
            if (password.length < 8) {
                e.preventDefault();
                alert('Password minimal 8 karakter!');
                return false;
            }
            
            if (password !== confirm) {
                e.preventDefault();
                alert('Password dan konfirmasi password tidak cocok!');
                return false;
            }
            
            // Show loading
            const btn = document.getElementById('resetBtn');
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Memproses...';
            btn.disabled = true;
        });
    }
    
    // Restart parameter
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('restart') === '1') {
        fetch('?clear_session=1', { method: 'POST' })
            .then(() => window.location.href = 'forgot_password.php');
    }
});

// Clear session (called by restart)
<?php if (isset($_GET['restart'])): ?>
<?php unset($_SESSION['reset_user']); ?>
window.location.href = 'forgot_password.php';
<?php endif; ?>
</script>

<?php require_once 'includes/footer.php'; ?>