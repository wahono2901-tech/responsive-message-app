<?php
/**
 * Guru Response Page - Detailed Message Response Interface
 * File: modules/guru/response.php
 * 
 * REVISI: 
 * - Menggunakan konfigurasi terpusat dari settings.php
 * - Mengambil MailerSend dan Fonnte config dari file JSON
 * - Mempertahankan semua fitur yang sudah ada (quote, notifikasi langsung, dll)
 * - PERBAIKAN: Error Duplicate entry '0' for key 'PRIMARY' pada tabel message_responses
 * - PERBAIKAN: Menghilangkan kolom id dari INSERT (auto_increment)
 * - PERBAIKAN: Menambahkan kolom email_sent dan whatsapp_sent ke dalam INSERT
 * - PERBAIKAN: Update kolom email_sent_at dan whatsapp_sent_at setelah notifikasi berhasil
 * - PERBAIKAN: Menambahkan pengecekan otomatis data dengan id = 0 dan struktur tabel
 * - PERBAIKAN: Menggunakan $database->select() untuk query SELECT (method dari class Database)
 * - PERBAIKAN: Menggunakan $db->prepare() + execute() untuk query INSERT/UPDATE/DELETE
 * - PERBAIKAN: Menggunakan $db->exec() untuk query struktural (ALTER TABLE, SET FOREIGN_KEY_CHECKS)
 * - PENAMBAHAN: Fitur preview gambar dalam modal dengan ukuran sebenarnya
 * - PENAMBAHAN: Tombol download gambar dari modal preview
 * - PENAMBAHAN: Informasi dimensi dan ukuran file gambar
 * - PENAMBAHAN: Loading state dan error handling untuk preview gambar
 * - PENAMBAHAN: Fitur zoom in/out pada preview gambar (opsional)
 */

// ============================================================================
// DEBUG INITIALIZATION - PALING ATAS
// ============================================================================
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../debug_error.log');
ini_set('max_execution_time', 120);

// Buat direktori logs jika belum ada
$logDir = __DIR__ . '/../../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}

// File log khusus
define('EMAIL_DEBUG_LOG', $logDir . '/email_debug.log');
define('WHATSAPP_DEBUG_LOG', $logDir . '/whatsapp_debug.log');
define('WHATSAPP_SUCCESS_LOG', $logDir . '/whatsapp_success.log');
define('MAILERSEND_LOG', $logDir . '/mailersend_debug.log');
define('RESPONSE_DEBUG_LOG', $logDir . '/response_debug.log');

// Inisialisasi file log
foreach ([EMAIL_DEBUG_LOG, WHATSAPP_DEBUG_LOG, WHATSAPP_SUCCESS_LOG, MAILERSEND_LOG, RESPONSE_DEBUG_LOG] as $logFile) {
    file_put_contents($logFile, "\n[" . date('Y-m-d H:i:s') . "] ========== RESPONSE.PHP START ==========\n", FILE_APPEND);
}

// Fungsi logging super lengkap
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

function email_log($message, $data = null) {
    writeLog(EMAIL_DEBUG_LOG, $message, $data);
}

function whatsapp_log($message, $data = null) {
    writeLog(WHATSAPP_DEBUG_LOG, $message, $data);
}

function whatsapp_success_log($message, $data = null) {
    writeLog(WHATSAPP_SUCCESS_LOG, $message, $data);
}

function mailersend_log($message, $data = null) {
    writeLog(MAILERSEND_LOG, $message, $data);
}

function response_log($message, $data = null) {
    writeLog(RESPONSE_DEBUG_LOG, $message, $data);
}

response_log("MEMULAI EKSEKUSI RESPONSE.PHP");
response_log("SERVER", $_SERVER);

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// ============================================================================
// LOAD KONFIGURASI TERPUSAT DARI SETTINGS.PHP
// ============================================================================
$mailersendConfig = [];
$fonnteConfig = [];

// Load MailerSend config
$mailersendConfigFile = ROOT_PATH . '/config/mailersend.json';
if (file_exists($mailersendConfigFile)) {
    $mailersendConfig = json_decode(file_get_contents($mailersendConfigFile), true);
    response_log("MailerSend config loaded dari settings.php", [
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
    response_log("MailerSend config default digunakan", ['from_email' => $mailersendConfig['from_email']]);
}

// Load Fonnte config
$fonnteConfigFile = ROOT_PATH . '/config/fonnte.json';
if (file_exists($fonnteConfigFile)) {
    $fonnteConfig = json_decode(file_get_contents($fonnteConfigFile), true);
    response_log("Fonnte config loaded dari settings.php", [
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
    response_log("Fonnte config default digunakan", ['device_id' => $fonnteConfig['device_id']]);
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

mailersend_log("KONFIGURASI MAILERSEND (DARI SETTINGS.PHP)", [
    'api_token' => substr(MAILERSEND_API_TOKEN, 0, 15) . '...',
    'domain' => MAILERSEND_DOMAIN,
    'from_email' => MAILERSEND_FROM_EMAIL,
    'is_active' => $mailersendConfig['is_active'] ?? 0
]);

// ============================================================================
// KONFIGURASI WHATSAPP (FONNTE) - DARI SETTINGS.PHP
// ============================================================================
if (!defined('FONNTE_API_URL')) {
    define('FONNTE_API_URL', $fonnteConfig['api_url'] ?? 'https://api.fonnte.com/send');
}
if (!defined('FONNTE_API_KEY')) {
    define('FONNTE_API_KEY', $fonnteConfig['api_token'] ?? '');
}
if (!defined('FONNTE_COUNTRY_CODE')) {
    define('FONNTE_COUNTRY_CODE', $fonnteConfig['country_code'] ?? '62');
}

// Untuk kompatibilitas dengan kode lama
if (!defined('WHATSAPP_TOKEN')) {
    define('WHATSAPP_TOKEN', FONNTE_API_KEY);
}
if (!defined('WHATSAPP_API_URL')) {
    define('WHATSAPP_API_URL', FONNTE_API_URL);
}
if (!defined('WHATSAPP_DEVICE')) {
    define('WHATSAPP_DEVICE', $fonnteConfig['device_id'] ?? '6285174207795');
}

whatsapp_log("KONFIGURASI WHATSAPP (FONNTE) - DARI SETTINGS.PHP", [
    'api_url' => WHATSAPP_API_URL,
    'token' => substr(WHATSAPP_TOKEN, 0, 5) . '...',
    'device' => WHATSAPP_DEVICE,
    'is_active' => $fonnteConfig['is_active'] ?? 0
]);

// ============================================================================
// KONFIGURASI SMTP (ALTERNATIF) - TETAP SAMA
// ============================================================================
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', 'smtp.mailersend.net');
    define('SMTP_PORT', 587);
    define('SMTP_USER', 'MS_SBZwCT@test-r9084zv6rpjgw63d.mlsender.net');
    define('SMTP_PASS', 'mssp.U9Sci64.yzkq340dem6ld796.wqvxBa3');
    define('SMTP_SECURE', 'tls');
}

// Check authentication and guru privilege
Auth::checkAuth();

$allowedTypes = ['Guru_BK', 'Guru_Humas', 'Guru_Kurikulum', 'Guru_Kesiswaan', 'Guru_Sarana'];
if (!in_array($_SESSION['user_type'], $allowedTypes)) {
    response_log("ACCESS DENIED - User type tidak diizinkan", $_SESSION['user_type']);
    header('Location: ' . BASE_URL . 'index.php?error=access_denied');
    exit;
}

$guruId = $_SESSION['user_id'];
$guruType = $_SESSION['user_type'];
$guruNama = $_SESSION['nama_lengkap'] ?? $_SESSION['username'];

response_log("USER AUTHENTICATED", [
    'guru_id' => $guruId,
    'guru_type' => $guruType,
    'guru_nama' => $guruNama
]);

// ============================================================================
// DEBUG FUNCTION - SUPER LENGKAP DENGAN TOGGLE
// ============================================================================
$debug_steps = [];
$step_counter = 0;
$debug_enabled = isset($_COOKIE['debug_mode']) ? $_COOKIE['debug_mode'] === 'true' : true;

if (isset($_GET['debug'])) {
    $debug_enabled = $_GET['debug'] === 'on';
    setcookie('debug_mode', $debug_enabled ? 'true' : 'false', time() + 86400 * 30);
}

function debug_step($title, $data = null, $type = 'info') {
    global $debug_steps, $step_counter, $debug_enabled;
    
    if (!$debug_enabled) {
        return;
    }
    
    $step_counter++;
    
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    $caller = isset($backtrace[1]) ? $backtrace[1]['function'] : 'main';
    $line = isset($backtrace[0]['line']) ? $backtrace[0]['line'] : 'unknown';
    
    $current_date = date('Y-m-d H:i:s');
    
    $debug_steps[] = [
        'step' => $step_counter,
        'time' => date('H:i:s'),
        'title' => $title,
        'data' => $data,
        'type' => $type,
        'caller' => $caller,
        'line' => $line,
        'date' => $current_date
    ];
    
    $log_message = "[RESPONSE_DEBUG][{$current_date}][STEP {$step_counter}][{$caller}:{$line}] {$title}";
    if ($data !== null) {
        $log_message .= " - " . print_r($data, true);
    }
    error_log($log_message);
    
    $debug_file = __DIR__ . '/../../response_debug.log';
    file_put_contents($debug_file, $log_message . "\n", FILE_APPEND);
    
    response_log("DEBUG STEP {$step_counter}: {$title}", $data);
}

debug_step("=" . str_repeat("=", 70), null, 'separator');
debug_step("RESPONSE.PHP - MULAI EKSEKUSI", [
    'time' => date('Y-m-d H:i:s'),
    'guru_id' => $guruId,
    'guru_type' => $guruType,
    'debug_enabled' => $debug_enabled,
    'mailersend_active' => $mailersendConfig['is_active'] ?? 0,
    'fonnte_active' => $fonnteConfig['is_active'] ?? 0
], 'start');

// ============================================================================
// FUNGSI WHATSAPP (DENGAN KONFIGURASI DARI SETTINGS.PHP)
// ============================================================================
function formatPhoneNumber($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (substr($phone, 0, 1) == '0') {
        $phone = '62' . substr($phone, 1);
    } elseif (substr($phone, 0, 2) !== '62') {
        $phone = '62' . $phone;
    }
    return $phone;
}

function formatOriginalMessageForWA($original_message, $sender_name, $date) {
    $max_length = 300;
    $original_message = strlen($original_message) > $max_length 
        ? substr($original_message, 0, $max_length) . '...' 
        : $original_message;
    
    $lines = explode("\n", $original_message);
    $quoted = "";
    foreach ($lines as $line) {
        $quoted .= "> " . $line . "\n";
    }
    
    return "\n\n*Pesan Asli dari {$sender_name} ({$date}):*\n" . $quoted;
}

function sendWhatsAppNotification($phone, $nama, $reference, $status, $catatan, $guru_nama, $guru_type, $original_message = '', $original_date = '', $original_sender = '') {
    global $fonnteConfig;
    
    whatsapp_success_log("\n" . str_repeat("=", 80));
    whatsapp_success_log("sendWhatsAppNotification() DIPANGGIL");
    
    // Cek apakah Fonnte aktif
    if (!isset($fonnteConfig['is_active']) || $fonnteConfig['is_active'] != 1) {
        whatsapp_success_log("Fonnte tidak aktif, lewati pengiriman WhatsApp");
        return ['success' => false, 'sent' => false, 'error' => 'Layanan WhatsApp tidak aktif'];
    }
    
    $original_phone = $phone;
    $formatted_phone = formatPhoneNumber($phone);
    
    whatsapp_success_log("Original phone: $original_phone");
    whatsapp_success_log("Formatted phone: $formatted_phone");
    
    if (empty($formatted_phone) || strlen($formatted_phone) < 10) {
        $error = "Nomor tidak valid: $formatted_phone";
        whatsapp_success_log("ERROR: $error");
        return ['success' => false, 'sent' => false, 'error' => $error];
    }
    
    $current_date = date('d/m/Y H:i');
    
    $statusEmoji = '•';
    if ($status == 'Disetujui') $statusEmoji = '✅';
    elseif ($status == 'Ditolak') $statusEmoji = '❌';
    elseif ($status == 'Diproses') $statusEmoji = '⚙️';
    elseif ($status == 'Selesai') $statusEmoji = '🏁';
    
    $original_date_formatted = date('d/m/Y H:i', strtotime($original_date));
    
    $whatsapp_message = "🔔 *NOTIFIKASI WHATSAPP - SMKN 12 Jakarta*\n\n";
    $whatsapp_message .= "Yth. *{$nama}*\n\n";
    $whatsapp_message .= "Pesan #{$reference}\n";
    $whatsapp_message .= "{$statusEmoji} *Status: {$status}*\n\n";
    $whatsapp_message .= "*Respons dari {$guru_nama} ({$guru_type}):*\n";
    $whatsapp_message .= "{$catatan}\n";
    
    if (!empty($original_message)) {
        $whatsapp_message .= formatOriginalMessageForWA($original_message, $original_sender, $original_date_formatted);
    }
    
    $whatsapp_message .= "\nWaktu: {$current_date}\n";
    $whatsapp_message .= "_Dikirim dari perangkat: " . ($fonnteConfig['device_id'] ?? '6285174207795') . "_";
    
    whatsapp_success_log("Message length: " . strlen($whatsapp_message));
    
    $postData = [
        'target' => $formatted_phone,
        'message' => $whatsapp_message,
        'countryCode' => $fonnteConfig['country_code'] ?? '62',
        'delay' => '0'
    ];
    
    whatsapp_success_log("Data dikirim:", $postData);
    
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
    
    whatsapp_success_log("HTTP Code: $httpCode");
    whatsapp_success_log("Response: $response");
    
    $response_data = json_decode($response, true);
    
    $success = false;
    if ($httpCode == 200) {
        if (isset($response_data['status']) && $response_data['status'] == 1) {
            $success = true;
        } elseif (isset($response_data['status']) && $response_data['status'] === true) {
            $success = true;
        } elseif (isset($response_data['id'])) {
            $success = true;
        }
    }
    
    whatsapp_success_log($success ? "✓ BERHASIL" : "✗ GAGAL");
    whatsapp_success_log(str_repeat("=", 80) . "\n");
    
    return [
        'success' => $success,
        'sent' => $success,
        'http_code' => $httpCode,
        'response' => $response_data,
        'error' => $curlError ?: ($response_data['reason'] ?? null)
    ];
}

// ============================================================================
// FUNGSI NOTIFIKASI EMAIL (DENGAN KONFIGURASI DARI SETTINGS.PHP)
// ============================================================================
function formatOriginalMessageForEmail($original_message, $sender_name, $date) {
    return '
    <div style="margin: 20px 0; padding: 15px; background-color: #f8f9fa; border-left: 4px solid #6c757d; font-style: italic;">
        <p style="margin: 0 0 10px 0; color: #495057; font-weight: bold;">
            📨 Pesan Asli dari ' . htmlspecialchars($sender_name) . ' (' . date('d/m/Y H:i', strtotime($date)) . '):
        </p>
        <p style="margin: 0; color: #6c757d; white-space: pre-line;">' . nl2br(htmlspecialchars($original_message)) . '</p>
    </div>';
}

function sendMailerSendEmail($to_email, $to_name, $subject, $html_content, $text_content = '') {
    global $mailersendConfig;
    
    mailersend_log("\n" . str_repeat("=", 80));
    mailersend_log("sendMailerSendEmail() ke: $to_email");
    
    // Cek apakah MailerSend aktif
    if (!isset($mailersendConfig['is_active']) || $mailersendConfig['is_active'] != 1) {
        mailersend_log("MailerSend tidak aktif, lewati pengiriman email");
        return ['success' => false, 'sent' => false, 'error' => 'Layanan email tidak aktif'];
    }
    
    if (!function_exists('curl_init')) {
        mailersend_log("ERROR: cURL tidak tersedia");
        return ['success' => false, 'error' => 'cURL tidak tersedia', 'sent' => false];
    }
    
    if (empty($to_email) || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        mailersend_log("ERROR: Email tidak valid: $to_email");
        return ['success' => false, 'error' => 'Email tidak valid', 'sent' => false];
    }
    
    if (empty($mailersendConfig['api_token'])) {
        mailersend_log("ERROR: API Token kosong");
        return ['success' => false, 'error' => 'API Token tidak boleh kosong', 'sent' => false];
    }
    
    if (empty($mailersendConfig['from_email'])) {
        mailersend_log("ERROR: From Email kosong");
        return ['success' => false, 'error' => 'From Email tidak boleh kosong', 'sent' => false];
    }
    
    $data = [
        'from' => [
            'email' => $mailersendConfig['from_email'],
            'name' => $mailersendConfig['from_name'] ?? 'SMKN 12 Jakarta'
        ],
        'to' => [
            ['email' => $to_email, 'name' => $to_name]
        ],
        'subject' => $subject,
        'html' => $html_content,
        'text' => $text_content ?: strip_tags($html_content)
    ];
    
    mailersend_log("Data email:", [
        'from' => $mailersendConfig['from_email'],
        'to' => $to_email,
        'subject' => $subject,
        'html_length' => strlen($html_content)
    ]);
    
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
    
    mailersend_log("HTTP Code: $httpCode");
    if ($curlError) {
        mailersend_log("CURL Error: $curlError");
    }
    mailersend_log("Response: $response");
    
    $success = ($httpCode >= 200 && $httpCode < 300);
    
    return [
        'success' => $success,
        'http_code' => $httpCode,
        'error' => $curlError,
        'sent' => $success
    ];
}

function sendEmailNotification($email, $nama, $reference, $status, $catatan, $guru_nama, $guru_type, $original_message = '', $original_date = '', $original_sender = '') {
    global $mailersendConfig;
    
    email_log("\n" . str_repeat("=", 80));
    email_log("sendEmailNotification() ke: $email");
    
    // Cek apakah MailerSend aktif
    if (!isset($mailersendConfig['is_active']) || $mailersendConfig['is_active'] != 1) {
        email_log("MailerSend tidak aktif, lewati pengiriman email");
        return ['success' => false, 'sent' => false, 'error' => 'Layanan email tidak aktif'];
    }
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        email_log("ERROR: Email tidak valid: $email");
        return ['success' => false, 'sent' => false, 'error' => 'Email tidak valid'];
    }
    
    $statusColor = '#6c757d';
    $statusIcon = '•';
    if ($status == 'Disetujui') { $statusColor = '#28a745'; $statusIcon = '✓'; }
    elseif ($status == 'Ditolak') { $statusColor = '#dc3545'; $statusIcon = '✗'; }
    elseif ($status == 'Diproses') { $statusColor = '#ffc107'; $statusIcon = '⟳'; }
    elseif ($status == 'Selesai') { $statusColor = '#17a2b8'; $statusIcon = '✓✓'; }
    
    $current_date = date('d/m/Y H:i');
    
    $nama_esc = htmlspecialchars($nama, ENT_QUOTES, 'UTF-8');
    $reference_esc = htmlspecialchars($reference, ENT_QUOTES, 'UTF-8');
    $status_esc = htmlspecialchars($status, ENT_QUOTES, 'UTF-8');
    $catatan_esc = htmlspecialchars($catatan, ENT_QUOTES, 'UTF-8');
    $guru_nama_esc = htmlspecialchars($guru_nama, ENT_QUOTES, 'UTF-8');
    $guru_type_esc = htmlspecialchars($guru_type, ENT_QUOTES, 'UTF-8');
    
    $original_html = '';
    if (!empty($original_message)) {
        $original_sender_esc = htmlspecialchars($original_sender, ENT_QUOTES, 'UTF-8');
        $original_date_formatted = date('d/m/Y H:i', strtotime($original_date));
        $original_message_esc = htmlspecialchars($original_message, ENT_QUOTES, 'UTF-8');
        
        $original_html = '
        <div style="margin: 30px 0; padding: 20px; background-color: #f8f9fa; border-left: 4px solid #6c757d; border-radius: 0 5px 5px 0;">
            <p style="margin: 0 0 10px 0; color: #495057; font-weight: bold; font-size: 14px;">
                📨 PESAN ASLI DARI ' . $original_sender_esc . ' (' . $original_date_formatted . '):
            </p>
            <div style="margin: 0; color: #6c757d; white-space: pre-line; font-style: italic; padding: 10px; background-color: white; border-radius: 5px;">
                ' . nl2br($original_message_esc) . '
            </div>
        </div>';
    }
    
    $html_content = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
    $html_content .= '<style>
        body{font-family:Arial;line-height:1.6;background:#f4f6f9;padding:20px}
        .container{max-width:600px;margin:0 auto;background:white;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,0.1)}
        .header{background:#0b4d8a;color:white;padding:20px;text-align:center}
        .content{padding:30px}
        .status-badge{display:inline-block;padding:8px 16px;background:' . $statusColor . ';color:white;border-radius:50px;font-weight:bold;margin:10px 0}
        .reference{background:#e8f0fe;color:#0b4d8a;padding:8px 16px;border-radius:5px;font-family:monospace}
        .response-card{background:#f8f9fa;border-left:4px solid #0b4d8a;padding:20px;margin:20px 0}
        .footer{background:#e9ecef;padding:20px;text-align:center;font-size:12px;color:#6c757d}
        .config-info{background:#e8f5e9;border-left:4px solid #25D366;padding:10px;margin-top:10px;font-size:11px}
    </style>';
    $html_content .= '</head><body><div class="container">';
    $html_content .= '<div class="header"><h1>🏫 SMKN 12 Jakarta</h1><p>Responsive Message App</p></div>';
    $html_content .= '<div class="content">';
    $html_content .= '<h3>Yth. <strong>' . $nama_esc . '</strong>,</h3>';
    $html_content .= '<p>Pesan Anda telah mendapatkan respons:</p>';
    $html_content .= '<div style="text-align:center;margin:20px 0"><span class="reference">📋 ' . $reference_esc . '</span></div>';
    $html_content .= '<div style="text-align:center;margin:20px 0"><span class="status-badge">' . $statusIcon . ' Status: ' . $status_esc . '</span></div>';
    $html_content .= '<div class="response-card"><h4 style="color:#0b4d8a">👤 Respons dari ' . $guru_nama_esc . ' (' . $guru_type_esc . ')</h4>';
    $html_content .= '<div>' . nl2br($catatan_esc) . '</div>';
    $html_content .= '<p style="margin-top:15px;color:#6c757d"><i>Waktu: ' . $current_date . '</i></p></div>';
    
    $html_content .= $original_html;
    
    $html_content .= '<p><small>Email dikirim otomatis. Mohon tidak membalas email ini.</small></p>';
    $html_content .= '</div><div class="footer"><p>&copy; ' . date('Y') . ' SMKN 12 Jakarta</p>';
    $html_content .= '<p><i>Powered by MailerSend (Konfigurasi dari settings.php)</i></p>';
    $html_content .= '<div class="config-info">📧 From: ' . ($mailersendConfig['from_email'] ?? 'N/A') . '</div>';
    $html_content .= '</div></div></body></html>';
    
    $subject = "Respons Pesan #{$reference} - SMKN 12 Jakarta";
    
    email_log(">>> MENGIRIM EMAIL...");
    $result = sendMailerSendEmail($email, $nama, $subject, $html_content, $catatan);
    email_log(">>> HASIL EMAIL:", $result);
    
    return $result;
}

// ============================================================================
// FUNGSI SIMPAN LOG NOTIFIKASI (TETAP SAMA)
// ============================================================================
function saveNotificationLog($db, $message_id, $type, $recipient, $status, $response_id = null, $details = null) {
    try {
        $checkStmt = $db->query("SHOW TABLES LIKE 'notification_logs'");
        if ($checkStmt->rowCount() == 0) {
            response_log("Tabel notification_logs belum ada");
            return false;
        }
        
        $stmt = $db->prepare("
            INSERT INTO notification_logs 
            (message_id, response_id, notification_type, recipient, status, details, sent_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $details_json = $details ? json_encode($details) : null;
        
        $result = $stmt->execute([
            $message_id, $response_id, $type, $recipient, $status, $details_json
        ]);
        
        response_log("Notification log saved: $type - $status");
        
        return $result;
    } catch (Exception $e) {
        response_log("Error saving notification log: " . $e->getMessage());
        return false;
    }
}

// ============================================================================
// FUNGSI KIRIM NOTIFIKASI LANGSUNG (PERBAIKAN: Update kolom email_sent_at & whatsapp_sent_at)
// ============================================================================
function sendNotificationsNow($db, $message, $status, $catatan, $guruNama, $guruType, $guruId, $response_id = null) {
    response_log("\n" . str_repeat("=", 60));
    response_log("MEMULAI PENGIRIMAN NOTIFIKASI LANGSUNG");
    response_log("Response ID: " . ($response_id ?: 'NULL'));
    
    $results = ['email' => null, 'whatsapp' => null];
    $success_messages = [];
    $error_messages = [];
    
    // Kirim WhatsApp
    if (!empty($message['pengirim_phone'])) {
        response_log("\n>>> MENGIRIM WHATSAPP ke: " . $message['pengirim_phone']);
        
        $wa_result = sendWhatsAppNotification(
            $message['pengirim_phone'],
            $message['pengirim_nama_display'],
            $message['reference_number'],
            $status,
            $catatan,
            $guruNama,
            $guruType,
            $message['isi_pesan'],
            $message['created_at'],
            $message['pengirim_nama_display']
        );
        
        $results['whatsapp'] = $wa_result;
        
        if ($wa_result['sent']) {
            response_log("✓ WHATSAPP BERHASIL");
            $success_messages[] = "✓ WhatsApp";
            
            // Update messages
            try {
                $db->prepare("UPDATE messages SET whatsapp_notified = 1 WHERE id = ?")->execute([$message['id']]);
                response_log("✓ messages.whatsapp_notified updated");
            } catch (Exception $e) {
                response_log("✗ Gagal update messages.whatsapp_notified: " . $e->getMessage());
            }
            
            // Update message_responses jika ada response_id
            if ($response_id) {
                try {
                    $db->prepare("
                        UPDATE message_responses 
                        SET whatsapp_sent = 1, whatsapp_sent_at = NOW() 
                        WHERE id = ?
                    ")->execute([$response_id]);
                    response_log("✓ message_responses.whatsapp_sent updated for ID: $response_id");
                } catch (Exception $e) {
                    response_log("✗ Gagal update message_responses.whatsapp_sent: " . $e->getMessage());
                }
            }
            
            saveNotificationLog($db, $message['id'], 'whatsapp', $message['pengirim_phone'], 'sent', $response_id, $wa_result);
        } else {
            $error_msg = $wa_result['error'] ?? 'Unknown';
            response_log("✗ WHATSAPP GAGAL: " . $error_msg);
            $error_messages[] = "✗ WhatsApp: " . $error_msg;
            saveNotificationLog($db, $message['id'], 'whatsapp', $message['pengirim_phone'], 'failed', $response_id, $wa_result);
        }
    }
    
    // Kirim Email
    if (!empty($message['pengirim_email'])) {
        response_log("\n>>> MENGIRIM EMAIL ke: " . $message['pengirim_email']);
        
        $email_result = sendEmailNotification(
            $message['pengirim_email'],
            $message['pengirim_nama_display'],
            $message['reference_number'],
            $status,
            $catatan,
            $guruNama,
            $guruType,
            $message['isi_pesan'],
            $message['created_at'],
            $message['pengirim_nama_display']
        );
        
        $results['email'] = $email_result;
        
        if ($email_result['sent']) {
            response_log("✓ EMAIL BERHASIL");
            $success_messages[] = "✓ Email";
            
            // Update messages
            try {
                $db->prepare("UPDATE messages SET email_notified = 1 WHERE id = ?")->execute([$message['id']]);
                response_log("✓ messages.email_notified updated");
            } catch (Exception $e) {
                response_log("✗ Gagal update messages.email_notified: " . $e->getMessage());
            }
            
            // Update message_responses jika ada response_id
            if ($response_id) {
                try {
                    $db->prepare("
                        UPDATE message_responses 
                        SET email_sent = 1, email_sent_at = NOW() 
                        WHERE id = ?
                    ")->execute([$response_id]);
                    response_log("✓ message_responses.email_sent updated for ID: $response_id");
                } catch (Exception $e) {
                    response_log("✗ Gagal update message_responses.email_sent: " . $e->getMessage());
                }
            }
            
            saveNotificationLog($db, $message['id'], 'email', $message['pengirim_email'], 'sent', $response_id, $email_result);
        } else {
            $error_msg = $email_result['error'] ?? 'Unknown';
            response_log("✗ EMAIL GAGAL: " . $error_msg);
            $error_messages[] = "✗ Email: " . $error_msg;
            saveNotificationLog($db, $message['id'], 'email', $message['pengirim_email'], 'failed', $response_id, $email_result);
        }
    }
    
    response_log("\nPENGIRIMAN SELESAI");
    response_log("Success: " . implode(', ', $success_messages));
    response_log("Errors: " . implode(', ', $error_messages));
    response_log(str_repeat("=", 60));
    
    $summary = "Respons berhasil dikirim.";
    if (!empty($success_messages)) {
        $summary .= " " . implode(' • ', $success_messages);
    }
    if (!empty($error_messages)) {
        $summary .= " Gagal: " . implode(', ', $error_messages);
    }
    
    return [
        'results' => $results,
        'summary' => $summary,
        'has_errors' => !empty($error_messages)
    ];
}

// ============================================================================
// FUNGSI UNTUK MENDAPATKAN ATTACHMENTS GAMBAR
// ============================================================================
function getMessageAttachments($db, $message_id) {
    try {
        $sql = "
            SELECT 
                id,
                message_id,
                filename,
                filepath,
                filetype,
                filesize,
                is_approved,
                virus_scan_status,
                download_count,
                created_at
            FROM message_attachments 
            WHERE message_id = ? 
            AND is_approved = 1 
            AND virus_scan_status IN ('Clean', 'Pending')
            ORDER BY created_at ASC
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$message_id]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        response_log("Error getting attachments: " . $e->getMessage());
        return [];
    }
}

// ============================================================================
// MULAI PROSES UTAMA - DENGAN PERBAIKAN
// ============================================================================
debug_step("=" . str_repeat("=", 70), null, 'separator');
debug_step("RESPONSE.PHP - MULAI PROSES UTAMA", [
    'time' => date('Y-m-d H:i:s'),
    'guru_id' => $guruId,
    'guru_type' => $guruType,
    'mailersend_from' => $mailersendConfig['from_email'] ?? 'not set',
    'fonnte_device' => $fonnteConfig['device_id'] ?? 'not set'
], 'start');

response_log("\n" . str_repeat("=", 80));
response_log("PROSES UTAMA DIMULAI");

// Dapatkan instance Database (bukan PDO langsung)
$database = Database::getInstance();
$db = $database->getConnection(); // Ini PDO untuk query biasa
debug_step("Database connection", ['connected' => !empty($db)]);

// ============================================================================
// CEK DAN PERBAIKI DATA BERMASALAH DI MESSAGE_RESPONSES
// ============================================================================
try {
    // Gunakan $database->select() untuk query SELECT
    $checkZero = $database->select("SELECT COUNT(*) as total FROM message_responses WHERE id = 0");
    if (!empty($checkZero) && $checkZero[0]['total'] > 0) {
        response_log("⚠️ Ditemukan " . $checkZero[0]['total'] . " data dengan id = 0, mencoba memperbaiki...");
        
        // Gunakan $db->prepare() + execute() untuk query DELETE
        $stmt = $db->prepare("DELETE FROM message_responses WHERE id = 0");
        $stmt->execute();
        response_log("✓ Data dengan id = 0 telah dihapus");
    }
    
    // Cek apakah kolom id sudah auto_increment
    $checkAI = $database->select("
        SELECT EXTRA 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_NAME = 'message_responses' 
        AND COLUMN_NAME = 'id' 
        AND TABLE_SCHEMA = DATABASE()
    ");
    
    $extra = !empty($checkAI) ? $checkAI[0]['EXTRA'] : '';
    if (strpos($extra, 'auto_increment') === false) {
        response_log("⚠️ Kolom id belum auto_increment, memperbaiki...");
        
        // Gunakan $db->exec() untuk query struktural
        $db->exec("SET FOREIGN_KEY_CHECKS = 0");
        $db->exec("ALTER TABLE message_responses MODIFY id INT NOT NULL AUTO_INCREMENT");
        $db->exec("SET FOREIGN_KEY_CHECKS = 1");
        
        response_log("✓ Kolom id berhasil diubah menjadi AUTO_INCREMENT");
    } else {
        response_log("✓ Kolom id sudah AUTO_INCREMENT");
    }
} catch (Exception $e) {
    response_log("✗ Gagal memperbaiki struktur tabel: " . $e->getMessage());
}

// Get message ID from URL
$messageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
debug_step("Message ID dari URL", ['id' => $messageId]);

if ($messageId <= 0) {
    debug_step("ERROR: Message ID tidak valid", ['id' => $messageId], 'error');
    header('Location: followup.php?error=invalid_id');
    exit;
}

// ============================================================================
// PERUBAHAN UTAMA: Ambil SEMUA message type IDs berdasarkan responder_type
// ============================================================================
$typeStmt = $db->prepare("SELECT id FROM message_types WHERE responder_type = ?");
$typeStmt->execute([$guruType]);
$messageTypeIds = $typeStmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($messageTypeIds)) {
    debug_step("ERROR: Tidak ada message type untuk guru ini", ['guru_type' => $guruType], 'error');
    die('Tidak ada jenis pesan yang ditugaskan untuk guru ini.');
}

debug_step("Message type IDs untuk guru", $messageTypeIds);

// Get message details
$sql = "
    SELECT 
        m.*,
        m.is_external,
        CASE 
            WHEN m.is_external = 1 THEN 
                CONCAT('EXT-', 
                    CASE 
                        WHEN es.phone_number IS NOT NULL AND es.phone_number != '' THEN es.phone_number
                        WHEN es.email IS NOT NULL AND es.email != '' THEN SUBSTRING_INDEX(es.email, '@', 1)
                        ELSE CONCAT('ID', es.id)
                    END
                )
            ELSE 
                COALESCE(u.username, CONCAT('USR-', u.id))
        END as nomor_identitas,
        CASE 
            WHEN m.is_external = 1 THEN es.nama_lengkap
            ELSE COALESCE(u.nama_lengkap, m.pengirim_nama, 'Unknown')
        END as pengirim_nama_display,
        CASE 
            WHEN m.is_external = 1 THEN 
                COALESCE(es.identitas, 'External')
            ELSE 
                COALESCE(u.user_type, 'Internal')
        END as pengirim_tipe,
        CASE 
            WHEN m.is_external = 1 THEN es.email
            ELSE u.email
        END as pengirim_email,
        CASE 
            WHEN m.is_external = 1 THEN es.phone_number
            ELSE u.phone_number
        END as pengirim_phone,
        mt.response_deadline_hours,
        TIMESTAMPDIFF(HOUR, m.created_at, NOW()) as hours_since_created,
        GREATEST(0, mt.response_deadline_hours - TIMESTAMPDIFF(HOUR, m.created_at, NOW())) as hours_remaining
    FROM messages m
    LEFT JOIN users u ON m.pengirim_id = u.id
    LEFT JOIN external_senders es ON m.external_sender_id = es.id
    LEFT JOIN message_types mt ON m.jenis_pesan_id = mt.id
    WHERE m.id = ?
";

$stmt = $db->prepare($sql);
$stmt->execute([$messageId]);
$message = $stmt->fetch();

if (!$message) {
    debug_step("ERROR: Pesan tidak ditemukan", ['message_id' => $messageId], 'error');
    header('Location: followup.php?error=message_not_found');
    exit;
}

// ============================================================================
// PERUBAHAN UTAMA: Cek apakah pesan ini termasuk dalam type IDs guru
// ============================================================================
if (!in_array($message['jenis_pesan_id'], $messageTypeIds)) {
    debug_step("ERROR: Pesan bukan untuk guru ini", [
        'message_type_id' => $message['jenis_pesan_id'],
        'guru_type_ids' => $messageTypeIds
    ], 'error');
    header('Location: followup.php?error=unauthorized');
    exit;
}

debug_step("Detail pesan ditemukan", [
    'message_id' => $message['id'],
    'reference' => $message['reference_number'],
    'jenis_pesan_id' => $message['jenis_pesan_id']
]);

// Get attachments
$attachments = getMessageAttachments($db, $messageId);
debug_step("Attachments ditemukan", ['count' => count($attachments)]);

// Get response history
$responsesStmt = $db->prepare("
    SELECT mr.*, u.nama_lengkap as responder_name, u.user_type as responder_type
    FROM message_responses mr
    LEFT JOIN users u ON mr.responder_id = u.id
    WHERE mr.message_id = ?
    ORDER BY mr.created_at DESC
");
$responsesStmt->execute([$messageId]);
$responses = $responsesStmt->fetchAll();

// Get response templates
$templatesStmt = $db->prepare("
    SELECT id, name, content, category, default_status, guru_type, is_active, use_count,
           CASE 
               WHEN category LIKE '%external%' OR category LIKE '%umum%' OR category = 'External' THEN 1 
               ELSE 0 
           END as for_external
    FROM response_templates 
    WHERE (guru_type = ? OR guru_type = 'ALL' OR guru_type IS NULL OR guru_type = '')
    AND is_active = 1
    ORDER BY for_external DESC, use_count DESC, name ASC
");
$templatesStmt->execute([$guruType]);
$templates = $templatesStmt->fetchAll();

// Get notification status
$notificationStatus = [
    'email' => $message['email_notified'] ?? 0,
    'whatsapp' => $message['whatsapp_notified'] ?? 0
];

// Placeholder image for error handling
$placeholder_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><rect width="100" height="100" fill="#f8f9fa"/><text x="50" y="50" font-family="Arial" font-size="12" fill="#adb5bd" text-anchor="middle" dy=".3em">No Image</text></svg>';
$placeholder_image = 'data:image/svg+xml;base64,' . base64_encode($placeholder_svg);

// ============================================================================
// HANDLE FORM SUBMISSION - PERBAIKAN
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    debug_step("=" . str_repeat("-", 50), null, 'separator');
    debug_step("POST REQUEST DITERIMA", ['post_data' => $_POST], 'submit');
    
    response_log("\n" . str_repeat("-", 60));
    response_log("POST REQUEST DITERIMA");
    
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        switch ($action) {
            case 'submit_response':
            case 'edit_response':
                $status = $_POST['status'] ?? '';
                $catatan = trim($_POST['catatan_respon'] ?? '');
                $templateId = isset($_POST['template_id']) ? (int)$_POST['template_id'] : 0;
                $isExternal = $message['is_external'];
                
                debug_step("Data respons", [
                    'status' => $status,
                    'catatan_length' => strlen($catatan),
                    'template_id' => $templateId
                ]);
                
                if (strlen($catatan) < 10) {
                    debug_step("VALIDASI GAGAL: Catatan terlalu pendek", ['length' => strlen($catatan)], 'error');
                    $_SESSION['error_message'] = 'Catatan respons harus minimal 10 karakter.';
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $messageId);
                    exit;
                }
                
                $db->beginTransaction();
                debug_step("Transaction started");
                
                try {
                    $response_id = null;
                    
                    if ($action === 'submit_response') {
                        // UPDATE MESSAGES
                        $updateStmt = $db->prepare("
                            UPDATE messages 
                            SET status = ?, tanggal_respon = NOW(), responder_id = ?
                            WHERE id = ?
                        ");
                        $updateStmt->execute([$status, $guruId, $messageId]);
                        
                        // INSERT NEW RESPONSE - TIDAK menyertakan id karena auto_increment
                        $responseStmt = $db->prepare("
                            INSERT INTO message_responses (
                                message_id, responder_id, catatan_respon, status, is_external,
                                email_sent, whatsapp_sent, created_at
                            ) VALUES (
                                ?, ?, ?, ?, ?,
                                0, 0, NOW()
                            )
                        ");
                        $responseStmt->execute([
                            $messageId, 
                            $guruId, 
                            $catatan, 
                            $status, 
                            $isExternal
                        ]);
                        
                        // Ambil ID terakhir yang diinsert - gunakan $database->select()
                        $last_id_sql = "SELECT LAST_INSERT_ID() as last_id";
                        $last_id_result = $database->select($last_id_sql);
                        $response_id = $last_id_result[0]['last_id'] ?? 0;
                        
                        debug_step("Insert response", ['response_id' => $response_id], 'success');
                        
                    } else { // edit_response
                        // Cari response terakhir untuk message ini
                        $findStmt = $db->prepare("
                            SELECT id FROM message_responses 
                            WHERE message_id = ? 
                            ORDER BY created_at DESC LIMIT 1
                        ");
                        $findStmt->execute([$messageId]);
                        $lastResponse = $findStmt->fetch();
                        
                        if ($lastResponse) {
                            // UPDATE response terakhir
                            $responseStmt = $db->prepare("
                                UPDATE message_responses 
                                SET catatan_respon = ?, status = ?, created_at = NOW()
                                WHERE id = ?
                            ");
                            $responseStmt->execute([$catatan, $status, $lastResponse['id']]);
                            $response_id = $lastResponse['id'];
                            
                            debug_step("Update response", ['response_id' => $response_id], 'success');
                        } else {
                            // Jika tidak ada response, INSERT baru
                            $responseStmt = $db->prepare("
                                INSERT INTO message_responses (
                                    message_id, responder_id, catatan_respon, status, is_external,
                                    email_sent, whatsapp_sent, created_at
                                ) VALUES (
                                    ?, ?, ?, ?, ?,
                                    0, 0, NOW()
                                )
                            ");
                            $responseStmt->execute([
                                $messageId, 
                                $guruId, 
                                $catatan, 
                                $status, 
                                $isExternal
                            ]);
                            
                            $last_id_sql = "SELECT LAST_INSERT_ID() as last_id";
                            $last_id_result = $database->select($last_id_sql);
                            $response_id = $last_id_result[0]['last_id'] ?? 0;
                        }
                        
                        // Update messages
                        $updateStmt = $db->prepare("
                            UPDATE messages 
                            SET status = ?, tanggal_respon = NOW() 
                            WHERE id = ?
                        ");
                        $updateStmt->execute([$status, $messageId]);
                    }
                    
                    // Update template use count jika menggunakan template
                    if ($templateId > 0) {
                        $templateStmt = $db->prepare("
                            UPDATE response_templates 
                            SET use_count = use_count + 1, last_used_at = NOW() 
                            WHERE id = ?
                        ");
                        $templateStmt->execute([$templateId]);
                    }
                    
                    $db->commit();
                    debug_step("✓ TRANSACTION COMMITTED", [], 'success');
                    
                    debug_step("MEMULAI PENGIRIMAN NOTIFIKASI", [
                        'email' => !empty($message['pengirim_email']),
                        'whatsapp' => !empty($message['pengirim_phone']),
                        'response_id' => $response_id
                    ]);
                    
                    $notification_result = sendNotificationsNow(
                        $db, $message, $status, $catatan, $guruNama, $guruType, $guruId, $response_id
                    );
                    
                    $_SESSION['success_message'] = $notification_result['summary'];
                    
                    if ($notification_result['has_errors']) {
                        $_SESSION['warning_message'] = 'Beberapa notifikasi gagal, namun respons tetap tersimpan.';
                    }
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    debug_step("✗ TRANSACTION ROLLBACK", ['error' => $e->getMessage()], 'error');
                    $_SESSION['error_message'] = 'Gagal mengirim respons: ' . $e->getMessage();
                }
                
                header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $messageId);
                exit;
        }
    }
}

// Get notification logs
$notificationLogs = [];
try {
    $logSql = "SELECT * FROM notification_logs WHERE message_id = ? ORDER BY sent_at DESC";
    $logStmt = $db->prepare($logSql);
    $logStmt->execute([$messageId]);
    $notificationLogs = $logStmt->fetchAll();
} catch (Exception $e) {
    debug_step("Gagal mengambil notification logs", ['error' => $e->getMessage()], 'warning');
}

debug_step("RESPONSE.PHP - SIAP MENAMPILKAN HALAMAN", [
    'debug_steps_count' => count($debug_steps),
    'mailersend_active' => $mailersendConfig['is_active'] ?? 0,
    'fonnte_active' => $fonnteConfig['is_active'] ?? 0,
    'attachments_count' => count($attachments)
], 'complete');

// ============================================================================
// TAMPILAN HTML (DENGAN NULL COALESCING OPERATOR)
// ============================================================================
require_once '../../includes/header.php';
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Respons Pesan #<?php echo htmlspecialchars($message['reference_number'] ?? ''); ?> - SMKN 12 Jakarta</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* ============================================================================
           DEBUG PANEL STYLES
           ============================================================================ */
        .debug-panel {
            background: #1e1e2f;
            border-radius: 10px;
            margin-bottom: 30px;
            color: #e0e0e0;
            font-family: 'Consolas', monospace;
            border: 1px solid #2d2d44;
            display: none;
            position: fixed;
            bottom: 80px;
            right: 20px;
            width: 600px;
            max-width: 90%;
            z-index: 10000;
            max-height: 500px;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }
        .debug-panel.visible { display: block; }
        .debug-header {
            background: #2d2d44;
            padding: 10px 15px;
            border-radius: 10px 10px 0 0;
            cursor: pointer;
            position: sticky;
            top: 0;
        }
        .debug-header h3 { margin: 0; color: #fff; font-size: 14px; }
        .debug-content { padding: 10px; max-height: 400px; overflow-y: auto; display: none; }
        .debug-content.show { display: block; }
        .debug-entry {
            margin-bottom: 8px;
            padding: 8px;
            border-left: 3px solid;
            background: rgba(255,255,255,0.05);
            font-size: 11px;
        }
        .debug-entry.success { border-left-color: #28a745; }
        .debug-entry.error { border-left-color: #dc3545; }
        .debug-entry.warning { border-left-color: #ffc107; }
        .debug-step { color: #ff9900; font-weight: bold; margin-right: 5px; }
        .debug-time { color: #888; font-size: 10px; }
        .debug-message { color: #fff; }
        .debug-data {
            background: #000;
            padding: 5px;
            border-radius: 3px;
            margin-top: 3px;
            color: #0f0;
            font-size: 10px;
            overflow-x: auto;
        }
        
        .debug-toggle-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #1e1e2f;
            color: white;
            border: 2px solid #ff9900;
            border-radius: 50px;
            padding: 8px 15px;
            font-size: 12px;
            cursor: pointer;
            z-index: 10002;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .debug-toggle-btn.active { background: #ff9900; color: #1e1e2f; }
        
        /* MailerSend Badge */
        .mailersend-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        /* WhatsApp Badge */
        .whatsapp-badge {
            background: linear-gradient(145deg, #25D366, #128C7E);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        /* Settings Badge */
        .settings-badge {
            background: #0b4d8a;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .template-btn {
            height: 80px;
            white-space: normal;
            text-align: left;
            transition: all 0.2s;
            overflow: hidden;
            margin-bottom: 10px;
        }
        .template-btn:hover {
            background-color: #f8f9fa;
            border-color: #0d6efd;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .avatar { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; }
        
        .notification-bar {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .device-status {
            background: #e8f5e9;
            border: 1px solid #25D366;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .device-status h6 { color: #075E54; margin-bottom: 10px; }
        .status-badge.connected {
            background: #25D366;
            color: white;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .direct-badge {
            background: #28a745;
            color: white;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .log-link {
            color: #6c757d;
            text-decoration: none;
            font-size: 11px;
            margin-left: 10px;
        }
        .log-link:hover {
            color: #0d6efd;
        }
        
        .quote-badge {
            background: #6c757d;
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 9px;
            margin-left: 5px;
        }
        
        .config-info-panel {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            border-radius: 8px;
            padding: 10px 15px;
            margin-bottom: 20px;
            font-size: 12px;
        }

        /* ============================================================================
           ATTACHMENTS / IMAGE PREVIEW STYLES
           ============================================================================ */
        .attachment-item {
            transition: transform 0.2s, box-shadow 0.2s;
            border: 1px solid #e9ecef;
            overflow: hidden;
        }
        .attachment-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .attachment-preview {
            position: relative;
            background: #f8f9fa;
            height: 150px;
            overflow: hidden;
            cursor: pointer;
        }
        .attachment-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: transform 0.3s ease;
        }
        .attachment-preview:hover img {
            transform: scale(1.05);
        }
        .attachment-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(to bottom, rgba(0,0,0,0.3), rgba(0,0,0,0.5));
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .attachment-preview:hover .attachment-overlay {
            opacity: 1;
        }
        .empty-attachment-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto;
            background: #f8f9fa;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px dashed #dee2e6;
        }
        .empty-attachment-icon i {
            font-size: 40px;
            color: #adb5bd;
        }

        /* ============================================================================
           IMAGE PREVIEW MODAL STYLES
           ============================================================================ */
        #imagePreviewModal .modal-content {
            border: none;
            border-radius: 12px;
            overflow: hidden;
        }

        #imagePreviewModal .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-bottom: none;
            padding: 15px 20px;
        }

        #imagePreviewModal .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.8;
        }

        #imagePreviewModal .modal-header .btn-close:hover {
            opacity: 1;
        }

        #imagePreviewModal .modal-body {
            background: #1a1a1a;
            min-height: 500px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #imagePreviewModal #previewImage {
            max-width: 100%;
            max-height: 80vh;
            object-fit: contain;
            box-shadow: 0 5px 25px rgba(0,0,0,0.5);
            border-radius: 4px;
            transition: transform 0.2s ease;
        }

        #imagePreviewModal .modal-footer {
            background: #f8f9fa;
            border-top: 1px solid #dee2e6;
            padding: 12px 20px;
        }

        #previewLoading, #previewError {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 400px;
            width: 100%;
        }

        #previewLoading .spinner-border {
            width: 3rem;
            height: 3rem;
        }

        #previewError {
            background: linear-gradient(135deg, #f56565 0%, #c53030 100%);
            border-radius: 8px;
        }

        #imageDimensions, #imageSize {
            background: #e9ecef;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
        }

        /* Zoom controls */
        .zoom-controls {
            position: absolute;
            bottom: 20px;
            right: 20px;
            z-index: 1050;
        }
        .zoom-controls .btn-group {
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }
        .zoom-controls .btn {
            background: rgba(255,255,255,0.9);
            border: none;
            padding: 8px 12px;
        }
        .zoom-controls .btn:hover {
            background: white;
        }

        /* Transition effects */
        #previewImage {
            transition: opacity 0.3s ease, transform 0.2s ease;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            #imagePreviewModal .modal-body {
                min-height: 300px;
            }
            
            #imagePreviewModal .modal-footer {
                flex-direction: column;
                gap: 10px;
            }
            
            #imagePreviewModal .modal-footer > div {
                flex-direction: column;
                gap: 10px;
            }
            
            #imageDimensions, #imageSize {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <!-- DEBUG TOGGLE BUTTON -->
    <div class="debug-toggle-btn" onclick="toggleDebugPanel()" id="debugToggleBtn">
        <i class="fas fa-bug"></i> 
        <span>Debug <?php echo $debug_enabled ? 'ON' : 'OFF'; ?></span>
        <?php if ($debug_enabled): ?>
        <span class="badge" style="background: #ff9900; color: #1e1e2f; padding: 2px 5px; border-radius: 10px;">
            <?php echo count($debug_steps); ?>
        </span>
        <?php endif; ?>
    </div>

    <!-- DEBUG PANEL -->
    <?php if (!empty($debug_steps) && $debug_enabled): ?>
    <div class="debug-panel" id="debugPanel">
        <div class="debug-header" onclick="toggleDebugContent()">
            <h3>
                <i class="fas fa-bug"></i> 
                DEBUG LOG - <?php echo count($debug_steps); ?> STEPS
                <span style="margin-left: 10px; font-size: 10px;">
                    <span class="mailersend-badge">📧 MailerSend (Settings)</span>
                    <span class="whatsapp-badge ms-1">📱 Fonnte (Settings)</span>
                    <span class="settings-badge ms-1">⚙️ Terpusat</span>
                    <span class="quote-badge">📝 Quote</span>
                </span>
            </h3>
            <i class="fas fa-chevron-down" id="debugContentToggleIcon"></i>
        </div>
        <div class="debug-content" id="debugContent">
            <?php foreach ($debug_steps as $step): ?>
                <div class="debug-entry <?php echo $step['type']; ?>">
                    <div>
                        <span class="debug-step">STEP <?php echo str_pad($step['step'], 2, '0', STR_PAD_LEFT); ?></span>
                        <span class="debug-message"><?php echo htmlspecialchars($step['title']); ?></span>
                        <span class="debug-time"><?php echo $step['time']; ?></span>
                    </div>
                    <div class="debug-caller"><?php echo $step['caller']; ?>:<?php echo $step['line']; ?></div>
                    <?php if ($step['data'] !== null): ?>
                        <div class="debug-data">
                            <pre style="margin:0;"><?php echo htmlspecialchars(print_r($step['data'], true)); ?></pre>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="container-fluid py-4">
        <!-- Konfigurasi Info Panel -->
        <div class="config-info-panel">
            <div class="d-flex align-items-center">
                <i class="fas fa-cog me-2 fa-spin"></i>
                <div>
                    <strong>⚙️ Konfigurasi Terpusat dari settings.php</strong><br>
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
                </div>
            </div>
        </div>

        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-0">
                    <i class="fas fa-reply me-2 text-primary"></i>Respons Pesan
                    <span class="quote-badge">Dengan Quote</span>
                </h1>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>">Beranda</a></li>
                        <li class="breadcrumb-item"><a href="dashboard_guru.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="followup.php">Follow-Up</a></li>
                        <li class="breadcrumb-item active">Respons #<?php echo htmlspecialchars($message['reference_number'] ?? ''); ?></li>
                    </ol>
                </nav>
            </div>
            <div class="d-flex align-items-center">
                <span class="badge bg-primary p-2 me-2">
                    <i class="fas fa-user-tag me-1"></i>
                    <?php echo str_replace('_', ' ', $guruType); ?>
                </span>
                <?php if (($message['is_external'] ?? 0) == 1): ?>
                <span class="badge bg-warning p-2 me-2">
                    <i class="fas fa-external-link-alt me-1"></i>External
                </span>
                <?php endif; ?>
                <span class="mailersend-badge p-2 me-1">
                    <i class="fas fa-rocket"></i> MailerSend
                </span>
                <span class="whatsapp-badge p-2 me-1">
                    <i class="fab fa-whatsapp"></i> Fonnte
                </span>
                <span class="direct-badge">
                    <i class="fas fa-bolt me-1"></i>Langsung
                </span>
                <?php if ($debug_enabled): ?>
                <a href="?id=<?php echo $messageId; ?>&debug=<?php echo $debug_enabled ? 'off' : 'on'; ?>" 
                   class="btn btn-sm btn-outline-secondary ms-2">
                    <i class="fas fa-bug"></i> Debug <?php echo $debug_enabled ? 'OFF' : 'ON'; ?>
                </a>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Status Perangkat -->
        <div class="device-status">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h6><i class="fab fa-whatsapp me-2"></i>Status Perangkat WhatsApp</h6>
                    <span class="status-badge connected">
                        <i class="fas fa-check-circle me-1"></i> TERHUBUNG
                    </span>
                    <span class="ms-3">
                        <strong>Nomor:</strong> <?php echo $fonnteConfig['device_id'] ?? '6285174207795'; ?>
                    </span>
                </div>
                <div class="col-md-6 text-md-end">
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        Notifikasi dikirim langsung dengan quote pesan asli
                        <br>MailerSend: <?php echo $mailersendConfig['from_email'] ?? 'N/A'; ?> (dari settings.php)
                        <a href="#" onclick="window.open('<?php echo BASE_URL; ?>logs/whatsapp_success.log', '_blank')" class="log-link">
                            <i class="fas fa-file-alt"></i> Lihat Log
                        </a>
                    </small>
                </div>
            </div>
        </div>
        
        <!-- MailerSend Info -->
        <?php if (!empty($message['pengirim_email'] ?? '') && ($mailersendConfig['is_active'] ?? 0)): ?>
        <div class="mailersend-info" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
            <div class="d-flex align-items-center">
                <i class="fas fa-rocket fa-2x me-3"></i>
                <div>
                    <h5 class="mb-1">MailerSend Integration Active (dari settings.php)</h5>
                    <p class="mb-0 small opacity-75">
                        <i class="fas fa-check-circle me-1"></i> From: <?php echo $mailersendConfig['from_email'] ?? ''; ?>
                        <i class="fas fa-envelope ms-3 me-1"></i> To: <?php echo htmlspecialchars($message['pengirim_email'] ?? ''); ?>
                        <span class="quote-badge ms-2">Quote Disertakan</span>
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Alert Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i>
            <?php echo $_SESSION['success_message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success_message']); endif; ?>
        
        <?php if (isset($_SESSION['warning_message'])): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <?php echo $_SESSION['warning_message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['warning_message']); endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo $_SESSION['error_message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error_message']); endif; ?>
        
        <!-- Main Content -->
        <div class="row">
            <!-- Left Column: Message Details -->
            <div class="col-lg-8">
                <!-- Message Card -->
                <div class="card border-0 shadow mb-4">
                    <div class="card-header bg-white py-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-envelope me-2 text-primary"></i>
                                Detail Pesan
                                <span class="badge bg-<?php 
                                    $status = $message['status'] ?? '';
                                    echo $status == 'Pending' ? 'warning' : 
                                        ($status == 'Disetujui' ? 'success' : 
                                        ($status == 'Ditolak' ? 'danger' : 'secondary')); 
                                ?> ms-2">
                                    <?php echo htmlspecialchars($status); ?>
                                </span>
                            </h5>
                            <small class="text-muted">
                                <i class="far fa-clock me-1"></i>
                                <?php echo date('d/m/Y H:i', strtotime($message['created_at'] ?? '')); ?>
                            </small>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="d-flex align-items-center">
                                    <div class="avatar bg-light rounded-circle p-2 me-3">
                                        <i class="fas fa-user text-secondary"></i>
                                    </div>
                                    <div>
                                        <div class="fw-bold">
                                            <?php echo htmlspecialchars($message['pengirim_nama_display'] ?? 'Unknown'); ?>
                                        </div>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($message['nomor_identitas'] ?? ''); ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <?php if (!empty($message['pengirim_email'] ?? '')): ?>
                                <div class="mb-2">
                                    <small class="text-muted">Email:</small>
                                    <div>
                                        <i class="fas fa-envelope me-1 text-primary"></i>
                                        <?php echo htmlspecialchars($message['pengirim_email'] ?? ''); ?>
                                        <?php if (($notificationStatus['email'] ?? 0)): ?>
                                        <span class="badge bg-success ms-1" title="Notifikasi email telah dikirim">
                                            <i class="fas fa-check"></i>
                                        </span>
                                        <?php endif; ?>
                                        <span class="mailersend-badge ms-1">MailerSend</span>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($message['pengirim_phone'] ?? '')): ?>
                                <div>
                                    <small class="text-muted">WhatsApp:</small>
                                    <div>
                                        <i class="fab fa-whatsapp me-1 text-success"></i>
                                        <?php echo htmlspecialchars($message['pengirim_phone'] ?? ''); ?>
                                        <?php if (($notificationStatus['whatsapp'] ?? 0)): ?>
                                        <span class="badge bg-success ms-1" title="Notifikasi WhatsApp telah dikirim">
                                            <i class="fas fa-check"></i>
                                        </span>
                                        <?php endif; ?>
                                        <span class="whatsapp-badge ms-1">Fonnte</span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="fw-bold">Referensi:</label>
                            <div class="bg-light p-2 rounded">
                                <code><?php echo htmlspecialchars($message['reference_number'] ?? ''); ?></code>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="fw-bold">Isi Pesan:</label>
                            <div class="bg-light p-3 rounded" style="white-space: pre-line; border-left: 4px solid #6c757d;">
                                <?php echo nl2br(htmlspecialchars($message['isi_pesan'] ?? '')); ?>
                            </div>
                            <small class="text-muted mt-1">
                                <i class="fas fa-quote-right"></i> Pesan ini akan ditampilkan sebagai quote di notifikasi
                            </small>
                        </div>
                    </div>
                </div>
                
                <!-- ========================================================= -->
                <!-- ATTACHMENTS SECTION - Menampilkan thumbnail gambar -->
                <!-- ========================================================= -->
                <div class="card border-0 shadow mb-4">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0">
                            <i class="fas fa-images me-2 text-primary"></i>
                            Lampiran Gambar 
                            <span class="badge <?php echo !empty($attachments) ? 'bg-primary' : 'bg-secondary'; ?> ms-2">
                                <?php echo count($attachments); ?>
                            </span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($attachments)): ?>
                            <div class="row g-3">
                                <?php foreach ($attachments as $attachment): ?>
                                <?php 
                                    // Buat URL gambar dari filepath
                                    $filepath = $attachment['filepath'];
                                    // Pastikan path tidak dimulai dengan slash jika BASE_URL sudah memiliki slash
                                    $image_url = BASE_URL . '/' . ltrim($filepath, '/');
                                    $display_name = $attachment['filename'];
                                    
                                    // Format file size
                                    $size_formatted = '';
                                    if ($attachment['filesize']) {
                                        $size = $attachment['filesize'];
                                        if ($size < 1024) {
                                            $size_formatted = $size . ' B';
                                        } elseif ($size < 1048576) {
                                            $size_formatted = round($size / 1024, 1) . ' KB';
                                        } else {
                                            $size_formatted = round($size / 1048576, 1) . ' MB';
                                        }
                                    }
                                    
                                    // Status virus scan
                                    $virus_status = $attachment['virus_scan_status'] ?? 'Pending';
                                    $status_class = match($virus_status) {
                                        'Clean' => 'success',
                                        'Pending' => 'warning',
                                        'Infected' => 'danger',
                                        'Error' => 'secondary',
                                        default => 'secondary'
                                    };
                                    
                                    $download_count = $attachment['download_count'] ?? 0;
                                ?>
                                <div class="col-md-4 col-sm-6">
                                    <div class="attachment-item card h-100">
                                        <div class="attachment-preview position-relative" 
                                             onclick="previewImage('<?php echo $image_url . '?t=' . time(); ?>', '<?php echo htmlspecialchars($display_name); ?>')">
                                            <img src="<?php echo $image_url . '?t=' . time(); ?>" 
                                                 alt="<?php echo htmlspecialchars($display_name); ?>"
                                                 loading="lazy"
                                                 onerror="this.onerror=null; this.src='<?php echo $placeholder_image; ?>'; this.style.objectFit='contain'; this.style.padding='10px';">
                                            <div class="attachment-overlay">
                                                <div class="text-center text-white">
                                                    <i class="fas fa-search-plus fa-3x mb-2"></i>
                                                    <span class="d-block small">Klik untuk preview</span>
                                                </div>
                                            </div>
                                            
                                            <!-- Status Badge -->
                                            <span class="position-absolute top-0 end-0 m-1 badge bg-<?php echo $status_class; ?>" 
                                                  title="Virus Scan: <?php echo $virus_status; ?>">
                                                <i class="fas fa-shield-alt"></i>
                                            </span>
                                        </div>
                                        <div class="card-body p-2">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div class="text-truncate" style="max-width: 100px;">
                                                    <small title="<?php echo htmlspecialchars($display_name); ?>">
                                                        <?php echo htmlspecialchars(substr($display_name, 0, 15)); ?>
                                                        <?php if (strlen($display_name) > 15) echo '...'; ?>
                                                    </small>
                                                </div>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" 
                                                            class="btn btn-outline-primary" 
                                                            onclick="previewImage('<?php echo $image_url; ?>', '<?php echo htmlspecialchars($display_name); ?>')"
                                                            title="Preview">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <a href="<?php echo $image_url; ?>" 
                                                       class="btn btn-outline-success" 
                                                       download="<?php echo $display_name; ?>"
                                                       title="Download (<?php echo $download_count; ?>x)"
                                                       target="_blank">
                                                        <i class="fas fa-download"></i>
                                                        <?php if ($download_count > 0): ?>
                                                        <span class="badge bg-light text-dark ms-1"><?php echo $download_count; ?></span>
                                                        <?php endif; ?>
                                                    </a>
                                                </div>
                                            </div>
                                            <div class="d-flex justify-content-between align-items-center mt-1">
                                                <small class="text-muted"><?php echo $size_formatted; ?></small>
                                                <small class="text-muted">
                                                    <i class="far fa-clock"></i>
                                                    <?php echo date('d/m/Y', strtotime($attachment['created_at'])); ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Keterangan Status -->
                            <div class="mt-3 small text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                File dengan status <span class="badge bg-success">Clean</span> aman untuk diunduh.
                                Status <span class="badge bg-warning">Pending</span> masih dalam proses scan.
                            </div>
                            
                        <?php else: ?>
                            <!-- Tampilan placeholder ketika tidak ada lampiran -->
                            <div class="text-center py-4">
                                <div class="empty-attachment-icon mb-3">
                                    <i class="fas fa-image fa-4x text-muted opacity-50"></i>
                                </div>
                                <h6 class="text-muted">Tidak Ada Lampiran Gambar</h6>
                                <p class="text-muted small mb-0">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Pesan ini tidak dilengkapi dengan gambar lampiran.
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Response History Card -->
                <?php if (!empty($responses)): ?>
                <div class="card border-0 shadow mb-4">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0">
                            <i class="fas fa-history me-2 text-primary"></i>
                            Riwayat Respons (<?php echo count($responses); ?>)
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach ($responses as $response): ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <div>
                                        <span class="badge bg-<?php 
                                            $respStatus = $response['status'] ?? '';
                                            echo $respStatus == 'Disetujui' ? 'success' : 
                                                ($respStatus == 'Ditolak' ? 'danger' : 
                                                ($respStatus == 'Diproses' ? 'warning' : 'secondary')); 
                                        ?>">
                                            <?php echo htmlspecialchars($respStatus); ?>
                                        </span>
                                        <small class="text-muted ms-2">
                                            <i class="far fa-clock me-1"></i>
                                            <?php echo date('d/m/Y H:i', strtotime($response['created_at'] ?? '')); ?>
                                        </small>
                                    </div>
                                    <small class="text-muted">
                                        Oleh: <?php echo htmlspecialchars($response['responder_name'] ?? 'Unknown'); ?>
                                    </small>
                                </div>
                                <div class="bg-light p-2 rounded">
                                    <?php echo nl2br(htmlspecialchars($response['catatan_respon'] ?? '')); ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Notification Logs -->
                <?php if (!empty($notificationLogs)): ?>
                <div class="card border-0 shadow mb-4">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0">
                            <i class="fas fa-bell me-2 text-primary"></i>
                            Riwayat Notifikasi
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach ($notificationLogs as $log): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <?php if (($log['notification_type'] ?? '') == 'email'): ?>
                                    <i class="fas fa-envelope text-primary me-2"></i>
                                    <?php else: ?>
                                    <i class="fab fa-whatsapp text-success me-2"></i>
                                    <?php endif; ?>
                                    <span><?php echo htmlspecialchars($log['recipient'] ?? ''); ?></span>
                                    <?php if (($log['notification_type'] ?? '') == 'email'): ?>
                                    <span class="mailersend-badge ms-2">MailerSend</span>
                                    <?php else: ?>
                                    <span class="whatsapp-badge ms-2">Fonnte</span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <span class="badge bg-<?php echo ($log['status'] ?? '') == 'sent' ? 'success' : 'danger'; ?>">
                                        <?php echo htmlspecialchars($log['status'] ?? ''); ?>
                                    </span>
                                    <small class="text-muted ms-2">
                                        <?php echo date('d/m/Y H:i', strtotime($log['sent_at'] ?? '')); ?>
                                    </small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Right Column: Response Form -->
            <div class="col-lg-4">
                <!-- Templates -->
                <?php if (!empty($templates)): ?>
                <div class="card border-0 shadow mb-4">
                    <div class="card-header bg-white py-3">
                        <h6 class="mb-0 fw-bold">
                            <i class="fas fa-sticky-note me-2 text-primary"></i>
                            Template Respons Cepat
                            <span class="badge bg-primary ms-2"><?php echo count($templates); ?></span>
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-2">
                            <?php foreach ($templates as $template): ?>
                            <div class="col-md-12">
                                <button type="button" 
                                        class="btn btn-outline-secondary w-100 text-start template-btn"
                                        onclick="useTemplate(<?php echo $template['id']; ?>, '<?php echo addslashes($template['content']); ?>', '<?php echo addslashes($template['default_status'] ?? ''); ?>')">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <span class="fw-small">
                                            <i class="fas fa-copy me-1"></i>
                                            <?php echo htmlspecialchars($template['name'] ?? ''); ?>
                                        </span>
                                        <span class="badge bg-light text-dark">
                                            <?php echo $template['use_count'] ?? 0; ?>x
                                        </span>
                                    </div>
                                    <small class="text-muted d-block text-truncate mt-1">
                                        <?php echo htmlspecialchars($template['content'] ?? ''); ?>
                                    </small>
                                </button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Response Form -->
                <div class="card border-0 shadow">
                    <div class="card-header bg-white py-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-reply me-2 text-success"></i>
                                <?php echo empty($responses) ? 'Beri Respons' : 'Edit/Update Respons'; ?>
                            </h5>
                            <div>
                                <span class="badge bg-success me-1" title="Notifikasi dikirim langsung">
                                    <i class="fas fa-bolt"></i> Langsung
                                </span>
                                <span class="quote-badge">Quote</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Info Notifikasi -->
                    <div class="card-body pt-0 pb-2">
                        <div class="alert alert-info py-2 mb-0 small">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-bell me-2"></i>
                                <strong>Notifikasi akan dikirim langsung ke:</strong>
                            </div>
                            <div class="mt-2">
                                <?php if (!empty($message['pengirim_email'] ?? '')): ?>
                                <span class="badge bg-light text-dark p-2 me-2">
                                    <i class="fas fa-envelope text-primary"></i> 
                                    <?php echo htmlspecialchars(substr($message['pengirim_email'] ?? '', 0, 20)) . '...'; ?>
                                    <span class="mailersend-badge ms-1">MailerSend</span>
                                </span>
                                <?php endif; ?>
                                <?php if (!empty($message['pengirim_phone'] ?? '')): ?>
                                <span class="badge bg-light text-dark p-2">
                                    <i class="fab fa-whatsapp text-success"></i> 
                                    <?php echo htmlspecialchars($message['pengirim_phone'] ?? ''); ?>
                                    <span class="whatsapp-badge ms-1">Fonnte</span>
                                </span>
                                <?php endif; ?>
                            </div>
                            <div class="mt-2 small text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                ✅ Fitur Quote: Pesan asli akan disertakan dalam notifikasi
                                <br>Email via MailerSend (<?php echo $mailersendConfig['from_email'] ?? 'N/A'; ?>) • WhatsApp via Fonnte (<?php echo $fonnteConfig['device_id'] ?? '6285174207795'; ?>)
                                <br><small>⚙️ Konfigurasi dikelola melalui Admin → Settings</small>
                                <br><a href="#" onclick="window.open('<?php echo BASE_URL; ?>logs/whatsapp_success.log', '_blank')" class="text-primary">
                                    <i class="fas fa-file-alt"></i> Lihat Log WhatsApp
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <form method="POST" id="responseForm">
                            <input type="hidden" name="action" value="<?php echo empty($responses) ? 'submit_response' : 'edit_response'; ?>">
                            <input type="hidden" name="template_id" id="selectedTemplateId" value="0">
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Status</label>
                                <select class="form-select" name="status" id="responseStatus" required>
                                    <option value="Disetujui" <?php echo ($message['status'] ?? '') == 'Disetujui' ? 'selected' : ''; ?>>✅ Disetujui</option>
                                    <option value="Diproses" <?php echo ($message['status'] ?? '') == 'Diproses' ? 'selected' : ''; ?>>⚙️ Diproses</option>
                                    <option value="Ditolak" <?php echo ($message['status'] ?? '') == 'Ditolak' ? 'selected' : ''; ?>>❌ Ditolak</option>
                                    <option value="Selesai" <?php echo ($message['status'] ?? '') == 'Selesai' ? 'selected' : ''; ?>>🏁 Selesai</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Catatan Respons</label>
                                <textarea class="form-control" 
                                          name="catatan_respon" 
                                          id="responseNote" 
                                          rows="6" 
                                          placeholder="Tulis respons Anda..." 
                                          minlength="10" 
                                          required><?php 
                                    if (!empty($responses)) {
                                        echo htmlspecialchars($responses[0]['catatan_respon'] ?? '');
                                    }
                                ?></textarea>
                                <div class="d-flex justify-content-between mt-1">
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Minimal 10 karakter
                                    </small>
                                    <small id="noteCounter" class="text-muted">0/10</small>
                                </div>
                            </div>
                            
                            <!-- Preview Pesan Asli -->
                            <div class="mb-3">
                                <div class="bg-light p-2 rounded" style="font-size: 12px; border-left: 3px solid #6c757d;">
                                    <small class="text-muted">
                                        <i class="fas fa-quote-left me-1"></i>
                                        Pesan asli akan disertakan sebagai quote:
                                    </small>
                                    <div class="text-truncate mt-1">
                                        <?php 
                                        $isiPesan = $message['isi_pesan'] ?? '';
                                        echo htmlspecialchars(substr($isiPesan, 0, 100)) . (strlen($isiPesan) > 100 ? '...' : ''); 
                                        ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary" id="submitBtn">
                                    <i class="fas fa-paper-plane me-1"></i>
                                    <?php echo empty($responses) ? 'Kirim Respons' : 'Update Respons'; ?>
                                    <span class="badge bg-light text-dark ms-2">
                                        <i class="fas fa-bolt"></i> Langsung
                                    </span>
                                    <span class="quote-badge ms-1">Quote</span>
                                </button>
                                <a href="followup.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left me-1"></i>Kembali ke Daftar
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================================
         IMAGE PREVIEW MODAL
         ============================================================================ -->
    <div class="modal fade" id="imagePreviewModal" tabindex="-1" aria-labelledby="imagePreviewModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="imagePreviewModalLabel">
                        <i class="fas fa-image me-2 text-primary"></i>
                        <span id="previewImageTitle">Preview Gambar</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0 bg-dark position-relative" style="min-height: 500px;">
                    <div class="text-center w-100 h-100 d-flex align-items-center justify-content-center" id="previewImageContainer">
                        <img id="previewImage" src="" alt="Preview" style="max-width: 100%; max-height: 80vh; object-fit: contain; display: none;">
                        <div id="previewLoading" class="text-white p-5">
                            <div class="spinner-border text-light mb-3" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p>Memuat gambar...</p>
                        </div>
                        <div id="previewError" class="text-white p-5 bg-danger" style="display: none;">
                            <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                            <p>Gagal memuat gambar</p>
                        </div>
                    </div>
                    
                    <!-- Zoom Controls -->
                    <div class="zoom-controls">
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-light" onclick="zoomOut()" title="Perkecil">
                                <i class="fas fa-search-minus"></i>
                            </button>
                            <button type="button" class="btn btn-light" onclick="resetZoom()" title="Reset Zoom">
                                <span id="zoomIndicator">100%</span>
                            </button>
                            <button type="button" class="btn btn-light" onclick="zoomIn()" title="Perbesar">
                                <i class="fas fa-search-plus"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <div class="d-flex justify-content-between w-100">
                        <div>
                            <span id="imageDimensions" class="text-muted me-3">
                                <i class="fas fa-expand-alt me-1"></i>
                                <span>-</span>
                            </span>
                            <span id="imageSize" class="text-muted">
                                <i class="fas fa-database me-1"></i>
                                <span>-</span>
                            </span>
                        </div>
                        <div>
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times me-1"></i>Tutup
                            </button>
                            <a href="#" id="downloadPreviewBtn" class="btn btn-success" download>
                                <i class="fas fa-download me-1"></i>Download
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // ============================================================================
        // DEBUG PANEL FUNCTIONS
        // ============================================================================
        function toggleDebugPanel() {
            const panel = document.getElementById('debugPanel');
            const toggleBtn = document.getElementById('debugToggleBtn');
            
            if (panel) {
                panel.classList.toggle('visible');
                toggleBtn.classList.toggle('active');
                
                if (panel.classList.contains('visible')) {
                    const content = document.getElementById('debugContent');
                    const icon = document.getElementById('debugContentToggleIcon');
                    content.classList.add('show');
                    icon.classList.remove('fa-chevron-down');
                    icon.classList.add('fa-chevron-up');
                    localStorage.setItem('debugPanelVisible', 'true');
                } else {
                    localStorage.setItem('debugPanelVisible', 'false');
                }
            }
        }
        
        function toggleDebugContent() {
            const content = document.getElementById('debugContent');
            const icon = document.getElementById('debugContentToggleIcon');
            content.classList.toggle('show');
            icon.classList.toggle('fa-chevron-down');
            icon.classList.toggle('fa-chevron-up');
        }
        
        function useTemplate(templateId, content, defaultStatus) {
            document.getElementById('responseNote').value = content;
            document.getElementById('selectedTemplateId').value = templateId;
            updateNoteCounter();
            
            if (defaultStatus) {
                const statusSelect = document.getElementById('responseStatus');
                if (statusSelect.querySelector('option[value="' + defaultStatus + '"]')) {
                    statusSelect.value = defaultStatus;
                }
            }
        }
        
        function updateNoteCounter() {
            const note = document.getElementById('responseNote');
            const counter = document.getElementById('noteCounter');
            if (note && counter) {
                const length = note.value.length;
                counter.innerHTML = length + '/10';
                counter.className = length >= 10 ? 'text-success' : 'text-danger';
            }
        }

        // ============================================================================
        // IMAGE PREVIEW FUNCTIONALITY
        // ============================================================================

        // Zoom variables
        let currentZoom = 1;
        const zoomStep = 0.1;
        const maxZoom = 3;
        const minZoom = 0.5;

        /**
         * Preview image in modal with actual size
         * @param {string} imageUrl - URL of the image
         * @param {string} imageName - Name of the image
         */
        function previewImage(imageUrl, imageName) {
            console.log('Preview image called:', imageUrl, imageName);
            
            // Get modal elements
            const modal = document.getElementById('imagePreviewModal');
            const previewImage = document.getElementById('previewImage');
            const previewTitle = document.getElementById('previewImageTitle');
            const previewLoading = document.getElementById('previewLoading');
            const previewError = document.getElementById('previewError');
            const imageDimensions = document.getElementById('imageDimensions');
            const imageSize = document.getElementById('imageSize');
            const downloadBtn = document.getElementById('downloadPreviewBtn');
            
            if (!modal || !previewImage) {
                console.error('Modal elements not found');
                return;
            }
            
            // Reset UI
            previewImage.style.display = 'none';
            previewLoading.style.display = 'block';
            previewError.style.display = 'none';
            previewTitle.textContent = 'Memuat: ' + (imageName || 'Gambar');
            imageDimensions.querySelector('span').textContent = '-';
            imageSize.querySelector('span').textContent = '-';
            
            // Reset zoom
            resetZoom();
            
            // Update download button
            downloadBtn.href = imageUrl;
            downloadBtn.download = imageName || 'image.jpg';
            
            // Create new image to load and get dimensions
            const img = new Image();
            
            img.onload = function() {
                console.log('Image loaded successfully:', this.width, 'x', this.height);
                
                // Update modal with loaded image
                previewImage.src = imageUrl;
                previewImage.style.display = 'block';
                previewLoading.style.display = 'none';
                previewTitle.textContent = imageName || 'Preview Gambar';
                
                // Show image dimensions
                imageDimensions.querySelector('span').textContent = this.width + ' × ' + this.height + ' px';
                
                // Get file size if possible (via fetch)
                fetch(imageUrl, { method: 'HEAD' })
                    .then(response => {
                        const size = response.headers.get('content-length');
                        if (size) {
                            const formattedSize = formatFileSize(parseInt(size));
                            imageSize.querySelector('span').textContent = formattedSize;
                        } else {
                            imageSize.querySelector('span').textContent = 'Ukuran tidak diketahui';
                        }
                    })
                    .catch(() => {
                        imageSize.querySelector('span').textContent = 'Ukuran tidak diketahui';
                    });
                
                // Show modal
                const bsModal = new bootstrap.Modal(modal);
                bsModal.show();
            };
            
            img.onerror = function() {
                console.error('Failed to load image:', imageUrl);
                
                previewLoading.style.display = 'none';
                previewError.style.display = 'block';
                previewTitle.textContent = 'Gagal Memuat: ' + (imageName || 'Gambar');
                
                // Show modal with error
                const bsModal = new bootstrap.Modal(modal);
                bsModal.show();
            };
            
            // Start loading image
            img.src = imageUrl + '?t=' + new Date().getTime(); // Add timestamp to prevent cache
        }

        /**
         * Format file size to human readable format
         * @param {number} bytes - File size in bytes
         * @returns {string} Formatted file size
         */
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 B';
            if (!bytes || isNaN(bytes)) return '?';
            
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        /**
         * Alternative preview function for fallback
         * @param {string} imageUrl - URL of the image
         * @param {string} imageName - Name of the image
         */
        function previewImageFallback(imageUrl, imageName) {
            // Jika modal tidak bisa digunakan, buka di tab baru
            window.open(imageUrl, '_blank');
        }

        // ============================================================================
        // ZOOM FUNCTIONS
        // ============================================================================
        function zoomIn() {
            if (currentZoom < maxZoom) {
                currentZoom += zoomStep;
                applyZoom();
            }
        }

        function zoomOut() {
            if (currentZoom > minZoom) {
                currentZoom -= zoomStep;
                applyZoom();
            }
        }

        function resetZoom() {
            currentZoom = 1;
            applyZoom();
        }

        function applyZoom() {
            const previewImage = document.getElementById('previewImage');
            const zoomIndicator = document.getElementById('zoomIndicator');
            
            if (previewImage) {
                previewImage.style.transform = `scale(${currentZoom})`;
                previewImage.style.transition = 'transform 0.2s ease';
                
                // Update zoom indicator
                if (zoomIndicator) {
                    zoomIndicator.textContent = Math.round(currentZoom * 100) + '%';
                }
            }
        }

        /**
         * Initialize image preview on document ready
         */
        document.addEventListener('DOMContentLoaded', function() {
            console.log('========== RESPONSE PAGE INITIALIZED ==========');
            console.log('MailerSend from settings:', '<?php echo $mailersendConfig['from_email'] ?? "N/A"; ?>');
            console.log('Fonnte device from settings:', '<?php echo $fonnteConfig['device_id'] ?? "N/A"; ?>');
            console.log('Image preview system initialized');
            
            // Load debug panel state
            const debugPanelVisible = localStorage.getItem('debugPanelVisible');
            if (debugPanelVisible === 'true' && document.getElementById('debugPanel')) {
                const panel = document.getElementById('debugPanel');
                const toggleBtn = document.getElementById('debugToggleBtn');
                panel.classList.add('visible');
                toggleBtn.classList.add('active');
                
                const content = document.getElementById('debugContent');
                const icon = document.getElementById('debugContentToggleIcon');
                content.classList.add('show');
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-up');
            }
            
            // Initialize note counter
            const responseNote = document.getElementById('responseNote');
            if (responseNote) {
                updateNoteCounter();
                responseNote.addEventListener('input', updateNoteCounter);
            }
            
            // Handle form submission
            const responseForm = document.getElementById('responseForm');
            if (responseForm) {
                responseForm.addEventListener('submit', function(e) {
                    const note = document.getElementById('responseNote').value;
                    if (note.length < 10) {
                        e.preventDefault();
                        alert('Catatan respons minimal 10 karakter.');
                        return false;
                    }
                    
                    const btn = document.getElementById('submitBtn');
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Mengirim...';
                    btn.disabled = true;
                });
            }
            
            // Handle keyboard navigation in modal
            const modal = document.getElementById('imagePreviewModal');
            if (modal) {
                modal.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        const bsModal = bootstrap.Modal.getInstance(modal);
                        if (bsModal) bsModal.hide();
                    }
                });
                
                // Reset zoom when modal is hidden
                modal.addEventListener('hidden.bs.modal', function() {
                    resetZoom();
                });
            }
        });
    </script>
</body>
</html>

<?php require_once '../../includes/footer.php'; ?>