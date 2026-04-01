<?php
/**
 * Konfigurasi Aplikasi
 * File: config/config.php
 * 
 * VERSI: 3.1 - Dengan Konfigurasi MailerSend dan Fonnte yang Benar
 * - Menggunakan MailerSend akun baru: Dell_PC
 * - Domain: test-r9084zv6rpjgw63d.mlsender.net
 * - API Token baru: mlsn.a4e70a19ff00a659620ddf13fa13ea30662bb0199fa07f13ad391b43507025fa
 * - SMTP credentials untuk alternatif pengiriman
 * - Fonnte tetap aktif untuk WhatsApp
 * - TAMBAHAN: DEFAULT_PRIVILEGE_LEVEL dan SESSION_LIFETIME constants
 */

// Error reporting untuk development
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/error.log');

// Cek apakah file diakses dari root atau dari folder responsive-message-app
$is_from_root = (strpos(__DIR__, 'responsive-message-app') === false);
$app_folder = 'responsive-message-app/';

// Tentukan BASE_PATH dan BASE_URL berdasarkan lokasi akses
if ($is_from_root) {
    // Jika diakses dari root (C:\xampp\htdocs\)
    define('ROOT_PATH', realpath(__DIR__ . '/responsive-message-app'));
    define('BASE_PATH', __DIR__ . '/responsive-message-app/');
    define('BASE_URL', 'http://localhost:8090/');
    define('APP_FOLDER', 'responsive-message-app/');
} else {
    // Jika diakses dari dalam folder responsive-message-app
    define('ROOT_PATH', realpath(dirname(__FILE__) . '/..'));
    define('BASE_PATH', ROOT_PATH . '/');
    define('BASE_URL', 'http://localhost:8090/responsive-message-app/');
    define('APP_FOLDER', '');
}

// Path konfigurasi
define('UPLOAD_PATH', ROOT_PATH . '/assets/uploads/');
define('BACKUP_PATH', ROOT_PATH . '/backups/');
define('INCLUDE_PATH', ROOT_PATH . '/includes/');
define('CLASS_PATH', ROOT_PATH . '/classes/');
define('MODEL_PATH', ROOT_PATH . '/models/');
define('MODULE_PATH', ROOT_PATH . '/modules/');
define('ASSET_PATH', ROOT_PATH . '/assets/');

// Konfigurasi database - CEK PORT MYSQL
define('DB_HOST', 'localhost');
define('DB_PORT', '3307'); // Default MySQL port adalah 3306, bukan 3307
define('DB_NAME', 'responsive_message_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// Konfigurasi aplikasi
define('APP_NAME', 'Responsive Message SMKN 12 Jakarta');
define('APP_VERSION', '1.0.0');
define('SSL_URL', 'https://localhost:444/responsive-message-app/');

// ============================================================================
// KONSTANTA DEFAULT UNTUK AUTHENTICATION
// ============================================================================
// Default privilege level untuk user baru
if (!defined('DEFAULT_PRIVILEGE_LEVEL')) {
    define('DEFAULT_PRIVILEGE_LEVEL', 'Limited_Lv3');
}

// Session lifetime in seconds (default: 2 jam = 7200 detik)
if (!defined('SESSION_LIFETIME')) {
    define('SESSION_LIFETIME', 7200);
}

// ============================================================================
// KONSTANTA KEAMANAN LAINNYA
// ============================================================================
define('SESSION_NAME', 'RMSESSID');
define('CSRF_TOKEN_LIFETIME', 1800);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 900);
define('PASSWORD_MIN_LENGTH', 8);

// Fungsi helper untuk URL
function app_url($path = '') {
    $path = ltrim($path, '/');
    return BASE_URL . $path;
}

function asset_url($path = '') {
    $path = ltrim($path, '/');
    return BASE_URL . 'assets/' . $path;
}

function module_url($path = '') {
    $path = ltrim($path, '/');
    return BASE_URL . 'modules/' . $path;
}

// Timezone
date_default_timezone_set('Asia/Jakarta');

// Start session jika belum dimulai
if (session_status() === PHP_SESSION_NONE) {
    // Set session cookie parameters dengan SESSION_LIFETIME yang telah didefinisikan
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    
    session_name(SESSION_NAME);
    session_start();
    
    // Regenerate session ID setiap 5 menit untuk keamanan
    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
    } elseif (time() - $_SESSION['last_regeneration'] > 300) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
}

// ============================================================================
// MAILERSEND CONFIGURATION (AKUN BARU)
// ============================================================================
if (!defined('MAILERSEND_API_TOKEN')) {
    // MailerSend API Configuration - Akun Dell_PC
    define('MAILERSEND_API_TOKEN', 'mlsn.a4e70a19ff00a659620ddf13fa13ea30662bb0199fa07f13ad391b43507025fa');
    define('MAILERSEND_DOMAIN', 'test-r9084zv6rpjgw63d.mlsender.net');
    define('MAILERSEND_DOMAIN_ID', '69oxl5ejo22l785k');
    define('MAILERSEND_FROM_EMAIL', 'noreply@test-r9084zv6rpjgw63d.mlsender.net');
    define('MAILERSEND_FROM_NAME', 'SMKN 12 Jakarta - Aplikasi Pesan Responsif');
}

// ============================================================================
// SMTP CONFIGURATION (MAILERSEND SMTP - SEBAGAI ALTERNATIF)
// ============================================================================
if (!defined('SMTP_HOST')) {
    // SMTP Configuration for MailerSend
    define('SMTP_HOST', 'smtp.mailersend.net');
    define('SMTP_PORT', 587); // TLS port
    define('SMTP_USER', 'MS_SBZwCT@test-r9084zv6rpjgw63d.mlsender.net');
    define('SMTP_PASS', 'mssp.U9Sci64.yzkq340dem6ld796.wqvxBa3');
    define('SMTP_SECURE', 'tls'); // 'tls' atau 'ssl'
    define('SMTP_FROM', 'noreply@test-r9084zv6rpjgw63d.mlsender.net');
    define('SMTP_FROM_NAME', 'SMKN 12 Jakarta - Aplikasi Pesan Responsif');
}

// ============================================================================
// FONNTE WHATSAPP GATEWAY CONFIGURATION (AKTIF)
// ============================================================================
if (!defined('FONNTE_API_URL')) {
    // Fonnte WhatsApp Gateway Configuration - PRODUCTION
    define('FONNTE_API_URL', 'https://api.fonnte.com/send');
    define('FONNTE_API_KEY', 'FS2cq8FckmaTegxtZpFB');
    define('FONNTE_DEVICE', '6285174207795'); // Nomor perangkat Fonnte
    define('FONNTE_PHONE_DEFAULT', '6281319055440'); // Nomor default untuk test
    define('FONNTE_COUNTRY_CODE', '62'); // Kode negara Indonesia
}

// ============================================================================
// WHATSAPP API CONFIGURATION (CALLMEBOT) - NONAKTIFKAN
// ============================================================================
// CallMeBot sudah tidak digunakan, digantikan oleh Fonnte
// Semua kode yang menggunakan WHATSAPP_API_URL akan diarahkan ke FONNTE_API_URL
if (!defined('WHATSAPP_API_URL')) {
    // Alihkan ke Fonnte agar kode lama tetap berfungsi
    define('WHATSAPP_API_URL', FONNTE_API_URL);
}
if (!defined('WHATSAPP_TOKEN')) {
    define('WHATSAPP_TOKEN', FONNTE_API_KEY);
}

// ============================================================================
// EMAIL API CONFIGURATION
// ============================================================================
if (!defined('EMAIL_ENABLED')) {
    // Email API Configuration
    define('EMAIL_ENABLED', true);
    define('EMAIL_FROM_NAME', MAILERSEND_FROM_NAME);
    define('EMAIL_FROM_EMAIL', MAILERSEND_FROM_EMAIL);
    define('EMAIL_USE_SMTP', false); // Set true jika ingin menggunakan SMTP
}

// ============================================================================
// DEBUG MODE - UNTUK DEVELOPMENT
// ============================================================================
define('DEBUG_MODE', true); // Set false untuk production
if (DEBUG_MODE) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
}

// ============================================================================
// LOG ALL CONFIGURATIONS (UNTUK DEBUG)
// ============================================================================
if (DEBUG_MODE) {
    error_log("=== CONFIGURATION LOADED ===");
    error_log("BASE_URL: " . BASE_URL);
    error_log("MAILERSEND_DOMAIN: " . MAILERSEND_DOMAIN);
    error_log("MAILERSEND_FROM_EMAIL: " . MAILERSEND_FROM_EMAIL);
    error_log("FONNTE_API_URL: " . FONNTE_API_URL);
    error_log("FONNTE_DEVICE: " . FONNTE_DEVICE);
    error_log("SMTP_HOST: " . SMTP_HOST);
    error_log("SESSION_LIFETIME: " . SESSION_LIFETIME);
    error_log("DEFAULT_PRIVILEGE_LEVEL: " . DEFAULT_PRIVILEGE_LEVEL);
}