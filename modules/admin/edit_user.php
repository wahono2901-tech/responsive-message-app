<?php
/**
 * Edit User Page - Dengan Integrasi Notifikasi dari settings.php
 * File: modules/admin/edit_user.php
 * 
 * REVISI:
 * - Menggunakan konfigurasi terpusat dari settings.php untuk notifikasi
 * - Fitur Buat Password User terintegrasi (seperti add_user.php)
 * - Menyimpan password hash ke database, password asli untuk notifikasi
 * - History password lengkap dengan timestamp di log
 * - Mengirim notifikasi hanya jika ada perubahan pada:
 *   - Username
 *   - Password
 *   - Email
 *   - Nomor Telepon
 * - Semua perubahan dicatat dalam log dengan timestamp
 * - Mempertahankan semua fitur yang sudah ada
 */

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

// Check authentication and admin privilege
Auth::checkAuth();
if ($_SESSION['user_type'] !== 'Admin' && $_SESSION['privilege_level'] !== 'Full_Access') {
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
define('USER_EDIT_LOG', $logDir . '/user_edit.log');
define('EMAIL_DEBUG_LOG', $logDir . '/email_debug.log');
define('WHATSAPP_DEBUG_LOG', $logDir . '/whatsapp_debug.log');
define('USER_CHANGES_LOG', $logDir . '/user_changes.log');

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

function editLog($message, $data = null) {
    writeLog(USER_EDIT_LOG, $message, $data);
}

function emailLog($message, $data = null) {
    writeLog(EMAIL_DEBUG_LOG, $message, $data);
}

function waLog($message, $data = null) {
    writeLog(WHATSAPP_DEBUG_LOG, $message, $data);
}

function changesLog($message, $data = null) {
    writeLog(USER_CHANGES_LOG, $message, $data);
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
    editLog("MailerSend config loaded dari settings.php", [
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
    editLog("MailerSend config default digunakan");
}

// Load Fonnte config
$fonnteConfigFile = ROOT_PATH . '/config/fonnte.json';
if (file_exists($fonnteConfigFile)) {
    $fonnteConfig = json_decode(file_get_contents($fonnteConfigFile), true);
    editLog("Fonnte config loaded dari settings.php", [
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
    editLog("Fonnte config default digunakan");
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

// Check if user ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: manage_users.php?error=invalid_id');
    exit;
}

$userId = intval($_GET['id']);
$pageTitle = 'Edit User';

// Get database connection
$db = Database::getInstance()->getConnection();

// Get user details
$stmt = $db->prepare("
    SELECT * FROM users WHERE id = :user_id
");
$stmt->execute([':user_id' => $userId]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: manage_users.php?error=user_not_found');
    exit;
}

// ============================================================================
// DATA UNTUK PASSWORD GENERATOR (disimpan di session sementara)
// ============================================================================
$generated_passwords = $_SESSION['generated_passwords'] ?? [];

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
// FUNGSI KIRIM WHATSAPP VIA FONNTE (UNTUK NOTIFIKASI PERUBAHAN)
// ============================================================================
function kirimWhatsAppNotifikasi($phone, $nama, $changes) {
    global $fonnteConfig;
    
    waLog("Mengirim WhatsApp notifikasi perubahan ke: $phone untuk user: $nama");
    
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
    
    // Bangun pesan berdasarkan perubahan
    $message = "🔔 *NOTIFIKASI PERUBAHAN AKUN - SMKN 12 Jakarta*\n\n";
    $message .= "Yth. *$nama*\n\n";
    $message .= "Data akun Anda telah diperbarui oleh Administrator.\n\n";
    $message .= "*PERUBAHAN YANG DILAKUKAN:*\n";
    
    foreach ($changes as $field => $value) {
        switch ($field) {
            case 'username':
                $message .= "• Username baru: *$value*\n";
                break;
            case 'password':
                $message .= "• Password baru: *$value*\n";
                break;
            case 'email':
                $message .= "• Email baru: *$value*\n";
                break;
            case 'phone':
                $message .= "• Nomor telepon baru: *$value*\n";
                break;
        }
    }
    
    $message .= "\nLogin di: " . BASE_URL . "login.php\n\n";
    $message .= "Jika Anda tidak merasa melakukan perubahan ini, segera hubungi Administrator.\n";
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
        waLog("✓ WhatsApp notifikasi berhasil dikirim ke $formatted_phone");
    } else {
        waLog("✗ WhatsApp notifikasi gagal dikirim ke $formatted_phone: " . ($curlError ?: ($response_data['reason'] ?? 'Unknown error')));
    }
    
    return [
        'success' => $success,
        'error' => $curlError ?: ($response_data['reason'] ?? 'Unknown error')
    ];
}

// ============================================================================
// FUNGSI KIRIM EMAIL VIA MAILERSEND (UNTUK NOTIFIKASI PERUBAHAN)
// ============================================================================
function kirimEmailNotifikasi($email, $nama, $changes) {
    global $mailersendConfig;
    
    emailLog("Mengirim email notifikasi perubahan ke: $email untuk user: $nama");
    
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
    
    $subject = "Notifikasi Perubahan Akun - SMKN 12 Jakarta";
    
    // Bangun daftar perubahan untuk email
    $changes_html = '';
    $changes_list = [];
    foreach ($changes as $field => $value) {
        $changes_list[] = "$field: $value";
        switch ($field) {
            case 'username':
                $changes_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #e9ecef;"><strong>Username</strong></td><td style="padding: 8px; border-bottom: 1px solid #e9ecef;">' . htmlspecialchars($value) . '</td></tr>';
                break;
            case 'password':
                $changes_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #e9ecef;"><strong>Password</strong></td><td style="padding: 8px; border-bottom: 1px solid #e9ecef;"><code>' . htmlspecialchars($value) . '</code></td></tr>';
                break;
            case 'email':
                $changes_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #e9ecef;"><strong>Email</strong></td><td style="padding: 8px; border-bottom: 1px solid #e9ecef;">' . htmlspecialchars($value) . '</td></tr>';
                break;
            case 'phone':
                $changes_html .= '<tr><td style="padding: 8px; border-bottom: 1px solid #e9ecef;"><strong>Nomor Telepon</strong></td><td style="padding: 8px; border-bottom: 1px solid #e9ecef;">' . htmlspecialchars($value) . '</td></tr>';
                break;
        }
    }
    
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
        .changes-box { background: #f8f9fa; border-left: 4px solid #ffc107; padding: 20px; margin: 20px 0; border-radius: 0 5px 5px 0; }
        .changes-table { width: 100%; border-collapse: collapse; }
        .changes-table td { padding: 10px; border-bottom: 1px solid #e9ecef; }
        .changes-table td:first-child { font-weight: bold; color: #495057; width: 140px; }
        .btn { display: inline-block; background: #ffc107; color: #212529; padding: 12px 30px; text-decoration: none; border-radius: 50px; font-weight: bold; margin: 10px 0; }
        .footer { background: #e9ecef; padding: 20px; text-align: center; color: #6c757d; font-size: 12px; border-top: 1px solid #dee2e6; }
        .warning { color: #dc3545; font-size: 13px; }
        .config-info { background: #e8f5e9; border-left: 4px solid #25D366; padding: 10px; margin-top: 10px; font-size: 11px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔄 SMKN 12 Jakarta</h1>
            <p>Notifikasi Perubahan Akun</p>
        </div>
        
        <div class="content">
            <p>Yth. <strong>' . htmlspecialchars($nama) . '</strong>,</p>
            
            <p>Data akun Anda telah diperbarui oleh Administrator di Aplikasi Pesan Responsif SMKN 12 Jakarta.</p>
            
            <div class="changes-box">
                <h4 style="margin-top: 0; color: #fd7e14;">📋 PERUBAHAN YANG DILAKUKAN</h4>
                
                <table class="changes-table">
                    ' . $changes_html . '
                </table>
                
                <p style="margin-top: 20px; color: #fd7e14;">
                    <strong>PENTING:</strong> Simpan informasi baru ini dengan aman!
                </p>
            </div>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="' . BASE_URL . 'login.php" class="btn">🔐 Login Sekarang</a>
            </div>
            
            <p class="warning">
                ⚠️ Jika Anda tidak merasa melakukan perubahan ini, segera hubungi Administrator.
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
        emailLog("✓ Email notifikasi berhasil dikirim ke $email");
    } else {
        emailLog("✗ Email notifikasi gagal dikirim ke $email");
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
// FUNGSI UNTUK MENGIRIM NOTIFIKASI PERUBAHAN
// ============================================================================
function kirimNotifikasiPerubahan($old_data, $new_data, $changes, $password_asli = null) {
    global $mailersendConfig, $fonnteConfig;
    
    editLog("\n" . str_repeat("=", 60));
    editLog("MEMULAI PENGIRIMAN NOTIFIKASI PERUBAHAN");
    
    $results = ['email' => null, 'whatsapp' => null];
    $success_messages = [];
    $error_messages = [];
    
    $nama = $new_data['nama_lengkap'] ?? $old_data['nama_lengkap'];
    
    // Bangun array perubahan untuk notifikasi
    $notif_changes = [];
    if (isset($changes['username'])) {
        $notif_changes['username'] = $new_data['username'];
    }
    if (isset($changes['password'])) {
        $notif_changes['password'] = $password_asli; // Password asli untuk notifikasi
    }
    if (isset($changes['email'])) {
        $notif_changes['email'] = $new_data['email'];
    }
    if (isset($changes['phone'])) {
        $notif_changes['phone'] = $new_data['phone_number'];
    }
    
    if (empty($notif_changes)) {
        editLog("Tidak ada perubahan yang memerlukan notifikasi");
        return ['skipped' => true];
    }
    
    editLog("Perubahan yang akan dinotifikasikan:", $notif_changes);
    
    // Kirim WhatsApp jika nomor telepon ada
    $phone_to_send = $new_data['phone_number'] ?? $old_data['phone_number'] ?? null;
    if (!empty($phone_to_send)) {
        editLog(">>> MENGIRIM WHATSAPP KE: " . $phone_to_send);
        $wa_result = kirimWhatsAppNotifikasi(
            $phone_to_send,
            $nama,
            $notif_changes
        );
        $results['whatsapp'] = $wa_result;
        
        if ($wa_result['success']) {
            editLog("✓ WHATSAPP NOTIFIKASI BERHASIL");
            $success_messages[] = "✓ WhatsApp";
        } else {
            editLog("✗ WHATSAPP NOTIFIKASI GAGAL: " . ($wa_result['error'] ?? 'Unknown error'));
            $error_messages[] = "✗ WhatsApp: " . $wa_result['error'];
        }
    }
    
    // Kirim Email jika email ada
    $email_to_send = $new_data['email'] ?? $old_data['email'] ?? null;
    if (!empty($email_to_send)) {
        editLog(">>> MENGIRIM EMAIL KE: " . $email_to_send);
        $email_result = kirimEmailNotifikasi(
            $email_to_send,
            $nama,
            $notif_changes
        );
        $results['email'] = $email_result;
        
        if ($email_result['success']) {
            editLog("✓ EMAIL NOTIFIKASI BERHASIL");
            $success_messages[] = "✓ Email";
        } else {
            editLog("✗ EMAIL NOTIFIKASI GAGAL: " . ($email_result['error'] ?? 'Unknown error'));
            $error_messages[] = "✗ Email: " . $email_result['error'];
        }
    }
    
    editLog("PENGIRIMAN SELESAI");
    editLog("Success: " . implode(' • ', $success_messages));
    editLog("Errors: " . implode(' • ', $error_messages));
    editLog(str_repeat("=", 60));
    
    $summary = "Data user berhasil diperbarui.";
    if (!empty($success_messages)) {
        $summary .= " " . implode(' • ', $success_messages) . " terkirim.";
    }
    if (!empty($error_messages)) {
        $summary .= " Gagal: " . implode(', ', $error_messages);
    }
    
    return [
        'results' => $results,
        'summary' => $summary,
        'has_errors' => !empty($error_messages),
        'changes_sent' => $notif_changes
    ];
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
        
        writeLog(PASSWORD_LOG, "PASSWORD GENERATED:", $log_data);
        
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
        
        // Cek email di database (untuk edit, exclude current user)
        $check_sql = "SELECT id, nama_lengkap FROM users WHERE email = :email AND id != :current_id";
        $check_stmt = $db->prepare($check_sql);
        $check_stmt->execute([
            ':email' => $email,
            ':current_id' => $userId
        ]);
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
    
    if ($_POST['ajax_action'] === 'check_username') {
        $username = trim($_POST['username'] ?? '');
        
        if (empty($username)) {
            echo json_encode(['success' => false, 'error' => 'Username harus diisi']);
            exit;
        }
        
        // Cek username di database (untuk edit, exclude current user)
        $check_sql = "SELECT id, nama_lengkap FROM users WHERE username = :username AND id != :current_id";
        $check_stmt = $db->prepare($check_sql);
        $check_stmt->execute([
            ':username' => $username,
            ':current_id' => $userId
        ]);
        $existing = $check_stmt->fetch();
        
        if ($existing) {
            echo json_encode([
                'success' => false,
                'exists' => true,
                'message' => 'Username sudah terdaftar'
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'exists' => false,
                'message' => 'Username tersedia'
            ]);
        }
        exit;
    }
}

// Handle form submission
$errors = [];
$success = false;
$successMessage = '';
$notificationResults = null;
$passwordChanged = false;
$password_asli_saved = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_action'])) {
    // Get form data
    $data = [
        'username' => trim($_POST['username'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'nama_lengkap' => trim($_POST['nama_lengkap'] ?? ''),
        'user_type' => $_POST['user_type'] ?? $user['user_type'],
        'nis_nip' => trim($_POST['nis_nip'] ?? ''),
        'kelas' => trim($_POST['kelas'] ?? ''),
        'jurusan' => trim($_POST['jurusan'] ?? ''),
        'mata_pelajaran' => trim($_POST['mata_pelajaran'] ?? ''),
        'privilege_level' => $_POST['privilege_level'] ?? $user['privilege_level'],
        'phone_number' => trim($_POST['phone_number'] ?? ''),
        'is_active' => isset($_POST['is_active']) ? 1 : 0
    ];

    // Validate required fields
    if (empty($data['username'])) {
        $errors[] = 'Username wajib diisi';
    }
    
    if (empty($data['email'])) {
        $errors[] = 'Email wajib diisi';
    } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email tidak valid';
    }
    
    if (empty($data['nama_lengkap'])) {
        $errors[] = 'Nama lengkap wajib diisi';
    }

    // Check for duplicate username (excluding current user)
    $stmt = $db->prepare("
        SELECT id FROM users 
        WHERE username = :username AND id != :user_id
    ");
    $stmt->execute([
        ':username' => $data['username'],
        ':user_id' => $userId
    ]);
    
    if ($stmt->fetch()) {
        $errors[] = 'Username sudah digunakan oleh user lain';
    }

    // Check for duplicate email (excluding current user)
    $stmt = $db->prepare("
        SELECT id FROM users 
        WHERE email = :email AND id != :user_id
    ");
    $stmt->execute([
        ':email' => $data['email'],
        ':user_id' => $userId
    ]);
    
    if ($stmt->fetch()) {
        $errors[] = 'Email sudah digunakan oleh user lain';
    }

    // Check for duplicate NIS/NIP (if provided)
    if (!empty($data['nis_nip'])) {
        $stmt = $db->prepare("
            SELECT id FROM users 
            WHERE nis_nip = :nis_nip AND id != :user_id
        ");
        $stmt->execute([
            ':nis_nip' => $data['nis_nip'],
            ':user_id' => $userId
        ]);
        
        if ($stmt->fetch()) {
            $errors[] = 'NIS/NIP sudah digunakan oleh user lain';
        }
    }

    // Track changes for notification and logging
    $changes = [];
    $old_values = [];
    $new_values = [];

    // Handle password change if provided via password generator
    if (!empty($_POST['new_password']) && !empty($_POST['password_asli'])) {
        $password_asli = trim($_POST['password_asli'] ?? '');
        $password_hash = trim($_POST['new_password'] ?? ''); // Ini sebenarnya password hash dari generator
        
        if (strlen($password_asli) < 8) {
            $errors[] = 'Password minimal 8 karakter';
        } else {
            $data['password_hash'] = $password_hash;
            $passwordChanged = true;
            $password_asli_saved = $password_asli;
            $changes['password'] = true;
            
            // Simpan ke log password generator
            $log_data = [
                'timestamp' => date('Y-m-d H:i:s'),
                'user_id' => $userId,
                'username' => $data['username'],
                'password_asli' => $password_asli,
                'password_hash' => $password_hash,
                'action' => 'password_change',
                'changed_by' => $_SESSION['username'] ?? 'unknown',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ];
            writeLog(PASSWORD_LOG, "PASSWORD CHANGED FOR USER:", $log_data);
        }
    }

    // Check for other changes
    $fields_to_check = ['username', 'email', 'phone_number', 'nama_lengkap', 'user_type', 'privilege_level', 'is_active', 'kelas', 'jurusan', 'mata_pelajaran', 'nis_nip'];
    foreach ($fields_to_check as $field) {
        $old_value = $user[$field] ?? '';
        $new_value = $data[$field] ?? '';
        
        if ($old_value != $new_value) {
            $changes[$field] = true;
            $old_values[$field] = $old_value;
            $new_values[$field] = $new_value;
            
            // Khusus untuk field yang perlu notifikasi
            if (in_array($field, ['username', 'email', 'phone_number'])) {
                $changes[$field] = true;
            }
        }
    }

    // If no errors, update user
    if (empty($errors)) {
        try {
            // Start transaction
            $db->beginTransaction();
            
            // Prepare update query
            $updateFields = [];
            $updateParams = [':user_id' => $userId];
            
            foreach ($data as $field => $value) {
                if ($field === 'password_hash' && isset($data['password_hash'])) {
                    $updateFields[] = "password_hash = :password_hash";
                    $updateParams[':password_hash'] = $value;
                } else {
                    $updateFields[] = "$field = :$field";
                    $updateParams[":$field"] = $value;
                }
            }
            
            // Add updated_at timestamp
            $updateFields[] = "updated_at = NOW()";
            
            $updateQuery = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = :user_id";
            
            $stmt = $db->prepare($updateQuery);
            $stmt->execute($updateParams);
            
            // Log all changes dengan timestamp lengkap
            if (!empty($changes)) {
                $change_log = [
                    'timestamp' => date('Y-m-d H:i:s'),
                    'user_id' => $userId,
                    'username' => $data['username'],
                    'changed_by' => [
                        'id' => $_SESSION['user_id'],
                        'username' => $_SESSION['username'] ?? 'unknown',
                        'name' => $_SESSION['nama_lengkap'] ?? 'unknown'
                    ],
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                    'old_values' => $old_values,
                    'new_values' => $new_values,
                    'password_changed' => $passwordChanged
                ];
                
                changesLog("USER DATA CHANGED:", $change_log);
            }
            
            // Log the activity to user_activity_logs
            $logStmt = $db->prepare("
                INSERT INTO user_activity_logs 
                (user_id, activity_type, description, ip_address, user_agent)
                VALUES (:user_id, 'UPDATE', :description, :ip_address, :user_agent)
            ");
            
            $logDescription = "Mengedit data user {$data['username']} (ID: {$userId})";
            if (!empty($changes)) {
                $changed_fields = array_keys($changes);
                $logDescription .= ". Perubahan: " . implode(', ', $changed_fields);
                if ($passwordChanged) {
                    $logDescription .= " (termasuk password)";
                }
            }
            
            $logStmt->execute([
                ':user_id' => $_SESSION['user_id'],
                ':description' => $logDescription,
                ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
            ]);
            
            // Commit transaction
            $db->commit();
            
            // Kirim notifikasi jika ada perubahan yang memerlukan notifikasi (username, password, email, phone)
            $notifiable_changes = [];
            if (isset($changes['username'])) $notifiable_changes['username'] = true;
            if ($passwordChanged) $notifiable_changes['password'] = true;
            if (isset($changes['email'])) $notifiable_changes['email'] = true;
            if (isset($changes['phone_number'])) $notifiable_changes['phone'] = true;
            
            if (!empty($notifiable_changes)) {
                editLog("Perubahan terdeteksi, mengirim notifikasi:", array_keys($notifiable_changes));
                $notificationResults = kirimNotifikasiPerubahan($user, $data, $notifiable_changes, $password_asli_saved);
                $successMessage = $notificationResults['summary'];
            } else {
                $successMessage = 'Data user berhasil diperbarui. Tidak ada perubahan yang memerlukan notifikasi.';
            }
            
            $success = true;
            
            // Refresh user data
            $stmt = $db->prepare("SELECT * FROM users WHERE id = :user_id");
            $stmt->execute([':user_id' => $userId]);
            $user = $stmt->fetch();
            
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Terjadi kesalahan sistem: ' . $e->getMessage();
            error_log("Edit user error: " . $e->getMessage());
            editLog("ERROR: " . $e->getMessage());
        }
    }
}

// Define user types for dropdown
$userTypes = [
    'Siswa' => 'Siswa',
    'Guru' => 'Guru',
    'Guru_BK' => 'Guru Bimbingan Konseling',
    'Guru_Humas' => 'Guru Hubungan Masyarakat',
    'Guru_Kurikulum' => 'Guru Kurikulum',
    'Guru_Kesiswaan' => 'Guru Kesiswaan',
    'Guru_Sarana' => 'Guru Sarana Prasarana',
    'Orang_Tua' => 'Orang Tua/Wali',
    'Admin' => 'Administrator',
    'Wakil_Kepala' => 'Wakil Kepala Sekolah',
    'Kepala_Sekolah' => 'Kepala Sekolah'
];

// Define privilege levels
$privilegeLevels = [
    'Full_Access' => 'Akses Penuh',
    'Limited_Lv1' => 'Akses Terbatas Level 1',
    'Limited_Lv2' => 'Akses Terbatas Level 2',
    'Limited_Lv3' => 'Akses Terbatas Level 3'
];

// Define classes (for siswa)
$classes = ['X', 'XI', 'XII'];

// Define majors (for siswa)
$majors = [
    'Teknik Komputer dan Jaringan' => 'Teknik Komputer dan Jaringan',
    'Rekayasa Perangkat Lunak' => 'Rekayasa Perangkat Lunak',
    'Multimedia' => 'Multimedia',
    'Teknik Elektronika' => 'Teknik Elektronika',
    'Teknik Mesin' => 'Teknik Mesin',
    'Teknik Kendaraan Ringan' => 'Teknik Kendaraan Ringan',
    'Akuntansi' => 'Akuntansi',
    'Pemasaran' => 'Pemasaran',
    'Perhotelan' => 'Perhotelan'
];

// Define subjects (for guru)
$subjects = [
    'Matematika' => 'Matematika',
    'Bahasa Indonesia' => 'Bahasa Indonesia',
    'Bahasa Inggris' => 'Bahasa Inggris',
    'IPA' => 'IPA',
    'IPS' => 'IPS',
    'PKN' => 'PKN',
    'Seni Budaya' => 'Seni Budaya',
    'PJOK' => 'PJOK',
    'Agama' => 'Agama',
    'Bimbingan Konseling' => 'Bimbingan Konseling',
    'Kurikulum' => 'Kurikulum',
    'Hubungan Masyarakat' => 'Hubungan Masyarakat',
    'Kesiswaan' => 'Kesiswaan',
    'Sarana Prasarana' => 'Sarana Prasarana'
];

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
.breadcrumb {
    background: transparent;
    padding: 0;
}
.breadcrumb-item + .breadcrumb-item::before {
    content: "›";
}
.card-header {
    border-bottom: 2px solid #f8f9fa;
}
.form-check-input:checked {
    background-color: #0d6efd;
    border-color: #0d6efd;
}
.input-group-text {
    background-color: #f8f9fa;
}
.invalid-feedback {
    display: block;
}
.table th {
    font-weight: 600;
    color: #495057;
}
.table td {
    color: #6c757d;
}
.password-strength .progress {
    background-color: #e9ecef;
    border-radius: 3px;
}
.password-strength .progress-bar {
    border-radius: 3px;
    transition: width 0.3s ease;
}
.toast-container {
    z-index: 1090;
}
.bg-opacity-50 {
    background-color: rgba(0, 0, 0, 0.5) !important;
}
.z-1050 {
    z-index: 1050 !important;
}
.spinner-border {
    width: 3rem;
    height: 3rem;
}
.form-control.is-invalid {
    border-color: #dc3545;
    padding-right: calc(1.5em + 0.75rem);
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right calc(0.375em + 0.1875rem) center;
    background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
}
.invalid-feedback {
    display: block;
    width: 100%;
    margin-top: 0.25rem;
    font-size: 0.875em;
    color: #dc3545;
}
@media (max-width: 768px) {
    .btn-group {
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    .btn-group .btn {
        flex: 1 1 auto;
        min-width: 120px;
    }
    .card-body .row {
        margin-bottom: 0.5rem;
    }
    .modal-dialog {
        margin: 0.5rem;
    }
}
</style>

<div class="container-fluid">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h2 mb-0">
                <i class="fas fa-edit me-2"></i>Edit User
                <span class="config-badge ms-2">⚙️ dari settings.php</span>
            </h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="manage_users.php">Manajemen User</a></li>
                    <li class="breadcrumb-item"><a href="user_detail.php?id=<?php echo $userId; ?>">
                        <?php echo htmlspecialchars($user['nama_lengkap']); ?>
                    </a></li>
                    <li class="breadcrumb-item active">Edit</li>
                </ol>
            </nav>
        </div>
        <div class="btn-group" role="group">
            <a href="user_detail.php?id=<?php echo $userId; ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-1"></i> Kembali
            </a>
            <button type="button" class="btn btn-primary" onclick="document.getElementById('userForm').submit()">
                <i class="fas fa-save me-1"></i> Simpan
            </button>
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
                    Notifikasi akan dikirim ke user jika ada perubahan pada Username, Password, Email, atau Nomor Telepon.
                    Semua perubahan dicatat dalam log dengan timestamp lengkap.
                </small>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-lg-8 mx-auto">
            <!-- Success Message -->
            <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                <div class="d-flex align-items-center">
                    <i class="fas fa-check-circle fa-2x me-3"></i>
                    <div>
                        <h5 class="mb-1">Berhasil!</h5>
                        <p class="mb-0"><?php echo $successMessage; ?></p>
                        <?php if ($passwordChanged): ?>
                        <p class="mb-0 mt-2"><i class="fas fa-info-circle me-1"></i> Password telah diubah</p>
                        <?php endif; ?>
                        
                        <?php if ($notificationResults && !empty($notificationResults['changes_sent'])): ?>
                        <div class="mt-2">
                            <small><strong>Notifikasi terkirim untuk perubahan:</strong> 
                                <?php echo implode(', ', array_keys($notificationResults['changes_sent'])); ?>
                            </small>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($changes)): ?>
                        <div class="mt-2">
                            <small><strong>Perubahan tercatat di log:</strong> 
                                <?php echo implode(', ', array_keys($changes)); ?>
                            </small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                <div class="mt-3">
                    <a href="user_detail.php?id=<?php echo $userId; ?>" class="btn btn-sm btn-outline-success me-2">
                        <i class="fas fa-eye me-1"></i> Lihat Detail
                    </a>
                    <a href="manage_users.php?type=<?php echo urlencode($user['user_type']); ?>" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-users me-1"></i> Kembali ke Daftar
                    </a>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Error Messages -->
            <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                <div class="d-flex align-items-center">
                    <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
                    <div>
                        <h5 class="mb-1">Terjadi Kesalahan</h5>
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Password Generator Panel (untuk edit password) -->
            <div class="row">
                <div class="col-12">
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-primary text-white py-3">
                            <h5 class="mb-0">
                                <i class="fas fa-key me-2"></i>Fitur Ubah Password User
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="password-generator-panel">
                                <div class="row">
                                    <div class="col-md-5">
                                        <div class="mb-3">
                                            <label class="form-label">Username <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="gen_username" 
                                                   value="<?php echo htmlspecialchars($user['username']); ?>" 
                                                   placeholder="Masukkan username" readonly>
                                            <small class="text-muted">Username user (tidak dapat diubah di sini)</small>
                                        </div>
                                    </div>
                                    <div class="col-md-5">
                                        <div class="mb-3">
                                            <label class="form-label">Password Baru <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <input type="text" class="form-control" id="gen_password" placeholder="Masukkan password baru">
                                                <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                            <small class="text-muted">Password baru yang akan diberikan ke user</small>
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
                                            <span class="whatsapp-badge">Fonnte</span> jika ada perubahan.
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Hasil Generate Password -->
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
                                        <i class="fas fa-arrow-down me-1"></i> Gunakan Password Ini untuk User
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

            <!-- Edit User Form -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-user-edit me-2"></i>Edit Data User
                        <small class="text-muted ms-2">ID: #<?php echo $userId; ?></small>
                    </h5>
                </div>
                <form id="userForm" method="POST" action="" class="needs-validation" novalidate>
                    <!-- Hidden fields untuk password dari generator -->
                    <input type="hidden" name="new_password" id="input_password_hash">
                    <input type="hidden" name="password_asli" id="input_password_asli">
                    
                    <div class="card-body">
                        <!-- Basic Information -->
                        <div class="row mb-4">
                            <div class="col-12">
                                <h6 class="text-uppercase text-muted mb-3">
                                    <i class="fas fa-user me-1"></i> Informasi Dasar
                                </h6>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="username" class="form-label">
                                    Username <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-at"></i>
                                    </span>
                                    <input type="text" 
                                           class="form-control" 
                                           id="username" 
                                           name="username" 
                                           value="<?php echo htmlspecialchars($user['username']); ?>"
                                           required
                                           pattern="[a-zA-Z0-9._-]{3,50}"
                                           title="Username hanya boleh berisi huruf, angka, titik, underscore, dan dash (3-50 karakter)">
                                    <button class="btn btn-outline-secondary" type="button" id="checkUsernameBtn" title="Cek ketersediaan username">
                                        <i class="fas fa-search"></i>
                                    </button>
                                    <div class="invalid-feedback">
                                        Username harus 3-50 karakter dan hanya boleh berisi huruf, angka, titik, underscore, dan dash
                                    </div>
                                </div>
                                <small class="form-text text-muted" id="usernameStatus"></small>
                                <small class="form-text text-muted">
                                    Untuk login. Perubahan akan dinotifikasikan ke user.
                                </small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">
                                    Email <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-envelope"></i>
                                    </span>
                                    <input type="email" 
                                           class="form-control" 
                                           id="email" 
                                           name="email" 
                                           value="<?php echo htmlspecialchars($user['email']); ?>"
                                           required>
                                    <button class="btn btn-outline-secondary" type="button" id="checkEmailBtn" title="Cek ketersediaan email">
                                        <i class="fas fa-search"></i>
                                    </button>
                                    <div class="invalid-feedback">
                                        Masukkan alamat email yang valid
                                    </div>
                                </div>
                                <small class="text-muted" id="emailStatus"></small>
                                <small class="text-muted">
                                    <span class="mailersend-badge">MailerSend</span> Perubahan akan dinotifikasikan ke email ini.
                                </small>
                            </div>
                            
                            <div class="col-md-12 mb-3">
                                <label for="nama_lengkap" class="form-label">
                                    Nama Lengkap <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-user"></i>
                                    </span>
                                    <input type="text" 
                                           class="form-control" 
                                           id="nama_lengkap" 
                                           name="nama_lengkap" 
                                           value="<?php echo htmlspecialchars($user['nama_lengkap']); ?>"
                                           required
                                           minlength="3"
                                           maxlength="100">
                                    <div class="invalid-feedback">
                                        Nama lengkap harus 3-100 karakter
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="nis_nip" class="form-label">
                                    NIS / NIP
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-id-card"></i>
                                    </span>
                                    <input type="text" 
                                           class="form-control" 
                                           id="nis_nip" 
                                           name="nis_nip" 
                                           value="<?php echo htmlspecialchars($user['nis_nip'] ?? ''); ?>"
                                           maxlength="20">
                                </div>
                                <small class="form-text text-muted">
                                    Nomor Induk Siswa / Nomor Induk Pegawai
                                </small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="phone_number" class="form-label">
                                    Nomor Telepon
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-phone"></i>
                                    </span>
                                    <input type="tel" 
                                           class="form-control" 
                                           id="phone_number" 
                                           name="phone_number" 
                                           value="<?php echo htmlspecialchars($user['phone_number'] ?? ''); ?>"
                                           pattern="[0-9+]{10,20}">
                                    <div class="invalid-feedback">
                                        Masukkan nomor telepon yang valid
                                    </div>
                                </div>
                                <small class="text-muted">
                                    <span class="whatsapp-badge">Fonnte</span> Perubahan akan dinotifikasikan ke nomor ini.
                                </small>
                            </div>
                        </div>
                        
                        <!-- User Type & Privilege -->
                        <div class="row mb-4">
                            <div class="col-md-6 mb-3">
                                <label for="user_type" class="form-label">
                                    Tipe User <span class="text-danger">*</span>
                                </label>
                                <select class="form-select" id="user_type" name="user_type" required onchange="updateFormFields()">
                                    <option value="">Pilih tipe user</option>
                                    <?php foreach ($userTypes as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>" 
                                            <?php echo ($user['user_type'] === $value) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">
                                    Pilih tipe user
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="privilege_level" class="form-label">
                                    Level Privilege
                                </label>
                                <select class="form-select" id="privilege_level" name="privilege_level">
                                    <option value="">Default (sesuai tipe user)</option>
                                    <?php foreach ($privilegeLevels as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($value); ?>" 
                                            <?php echo ($user['privilege_level'] === $value) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Additional Information (Conditional) -->
                        <div class="row mb-4" id="additionalInfo">
                            <!-- Siswa fields -->
                            <div class="col-md-6 mb-3" id="kelasField" style="display: none;">
                                <label for="kelas" class="form-label">Kelas</label>
                                <select class="form-select" id="kelas" name="kelas">
                                    <option value="">Pilih kelas</option>
                                    <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo htmlspecialchars($class); ?>"
                                            <?php echo ($user['kelas'] === $class) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3" id="jurusanField" style="display: none;">
                                <label for="jurusan" class="form-label">Jurusan</label>
                                <select class="form-select" id="jurusan" name="jurusan">
                                    <option value="">Pilih jurusan</option>
                                    <?php foreach ($majors as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($label); ?>"
                                            <?php echo ($user['jurusan'] === $label) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Guru fields -->
                            <div class="col-md-12 mb-3" id="mataPelajaranField" style="display: none;">
                                <label for="mata_pelajaran" class="form-label">Mata Pelajaran / Bidang</label>
                                <select class="form-select" id="mata_pelajaran" name="mata_pelajaran">
                                    <option value="">Pilih mata pelajaran/bidang</option>
                                    <?php foreach ($subjects as $value => $label): ?>
                                    <option value="<?php echo htmlspecialchars($label); ?>"
                                            <?php echo ($user['mata_pelajaran'] === $label) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Status & Actions -->
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" 
                                           type="checkbox" 
                                           id="is_active" 
                                           name="is_active" 
                                           value="1"
                                           <?php echo $user['is_active'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="is_active">
                                        <span class="badge bg-<?php echo $user['is_active'] ? 'success' : 'danger'; ?>">
                                            <?php echo $user['is_active'] ? 'Aktif' : 'Nonaktif'; ?>
                                        </span>
                                        &nbsp; Akun Aktif
                                    </label>
                                </div>
                                <small class="form-text text-muted">
                                    Nonaktifkan untuk menonaktifkan akun user tanpa menghapus datanya
                                </small>
                            </div>
                            
                            <div class="col-md-6 mb-3 text-end">
                                <div class="d-flex gap-2 justify-content-end">
                                    <button type="button" class="btn btn-outline-danger" onclick="confirmDelete()">
                                        <i class="fas fa-trash me-1"></i> Hapus User
                                    </button>
                                    <button type="reset" class="btn btn-outline-secondary">
                                        <i class="fas fa-redo me-1"></i> Reset
                                    </button>
                                    <button type="submit" class="btn btn-primary" id="submitBtn">
                                        <i class="fas fa-save me-1"></i> Simpan Perubahan
                                    </button>
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
                                <li>Gunakan fitur <strong>Ubah Password User</strong> di atas jika ingin mengganti password</li>
                                <li>Password asli akan dikirim ke user via <span class="mailersend-badge">MailerSend</span> (Email) dan <span class="whatsapp-badge">Fonnte</span> (WhatsApp) jika ada perubahan</li>
                                <li>Password hash akan disimpan di database dan log untuk backup</li>
                                <li>Semua perubahan dicatat dalam log dengan timestamp lengkap</li>
                                <li><strong>Untuk Siswa:</strong> wajib mengisi Kelas dan Jurusan</li>
                                <li><strong>Untuk Guru:</strong> wajib mengisi Mata Pelajaran</li>
                                <li><small class="text-muted">⚙️ Konfigurasi notifikasi dikelola melalui Admin → Settings</small></li>
                            </ul>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- User Info Card -->
            <div class="card border-0 shadow-sm mt-4">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-info-circle me-2"></i>Informasi Sistem
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <th width="40%">ID User</th>
                                    <td>#<?php echo $userId; ?></td>
                                </tr>
                                <tr>
                                    <th>Bergabung</th>
                                    <td><?php echo date('d F Y H:i', strtotime($user['created_at'])); ?></td>
                                </tr>
                                <tr>
                                    <th>Diperbarui</th>
                                    <td>
                                        <?php echo !empty($user['updated_at']) && $user['updated_at'] !== '0000-00-00 00:00:00' 
                                            ? date('d F Y H:i', strtotime($user['updated_at'])) 
                                            : 'Belum pernah diperbarui'; ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <th width="40%">Terakhir Login</th>
                                    <td>
                                        <?php echo !empty($user['last_login']) 
                                            ? date('d F Y H:i', strtotime($user['last_login'])) . ' (' . Functions::timeAgo($user['last_login']) . ')'
                                            : 'Belum pernah login'; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Avatar</th>
                                    <td>
                                        <?php if (!empty($user['avatar']) && $user['avatar'] !== 'default-avatar.png'): ?>
                                        <img src="<?php echo BASE_URL . 'assets/uploads/avatars/' . htmlspecialchars($user['avatar']); ?>" 
                                             alt="Avatar" class="rounded-circle" width="32" height="32"
                                             onerror="this.src='<?php echo BASE_URL; ?>assets/images/default-avatar.png'">
                                        <span class="ms-2"><?php echo htmlspecialchars($user['avatar']); ?></span>
                                        <?php else: ?>
                                        <span class="text-muted">Default</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>Konfirmasi Hapus User
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle fa-2x me-3 float-start"></i>
                    <h5 class="alert-heading">Peringatan!</h5>
                    <p class="mb-0">Apakah Anda yakin ingin menghapus user <strong><?php echo htmlspecialchars($user['nama_lengkap']); ?></strong>?</p>
                </div>
                
                <div class="mt-3">
                    <h6>Konsekuensi:</h6>
                    <ul class="text-danger">
                        <li>Semua data user akan dihapus permanen</li>
                        <li>Semua pesan yang dikirim oleh user akan dihapus</li>
                        <li>Tindakan ini tidak dapat dibatalkan!</li>
                    </ul>
                </div>
                
                <div class="mt-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="confirmDeleteCheck">
                        <label class="form-check-label" for="confirmDeleteCheck">
                            Saya memahami konsekuensi dan ingin melanjutkan
                        </label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i> Batal
                </button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn" disabled onclick="deleteUser()">
                    <i class="fas fa-trash me-1"></i> Hapus Permanen
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Initialize form validation and conditional fields
document.addEventListener('DOMContentLoaded', function() {
    // Initialize form validation
    initializeFormValidation();
    
    // Initialize conditional fields based on current user type
    updateFormFields();
    
    // Initialize delete confirmation
    initializeDeleteConfirmation();
    
    // ========================================================================
    // PASSWORD GENERATOR (SAMA SEPERTI ADD_USER.PHP)
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
    
    // Input hidden untuk form utama
    const inputPasswordHash = document.getElementById('input_password_hash');
    const inputPasswordAsli = document.getElementById('input_password_asli');
    const passwordStatus = document.getElementById('passwordStatus');
    const passwordInfo = document.getElementById('passwordInfo');
    
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
            alert('Password baru harus diisi!');
            genPassword.focus();
            return;
        }
        
        if (password.length < 8) {
            alert('Password minimal 8 karakter!');
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
                passwordInfo.innerHTML = '<span class="text-success">✓ Password baru telah digenerate untuk username: <strong>' + 
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
        
        // Isi hidden fields dengan data asli
        inputPasswordAsli.value = password;
        inputPasswordHash.value = hash; // Ini adalah password hash
        
        // Tampilkan informasi
        passwordStatus.style.display = 'block';
        passwordInfo.innerHTML = '<span class="text-success">✓ Password baru siap digunakan untuk username: <strong>' + 
                                username + '</strong></span>';
        
        // Scroll ke form
        document.getElementById('userForm').scrollIntoView({ behavior: 'smooth' });
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
    // CHECK USERNAME
    // ========================================================================
    const usernameInput = document.getElementById('username');
    const checkUsernameBtn = document.getElementById('checkUsernameBtn');
    const usernameStatus = document.getElementById('usernameStatus');
    
    checkUsernameBtn.addEventListener('click', function() {
        const username = usernameInput.value.trim();
        
        if (!username) {
            alert('Username harus diisi!');
            return;
        }
        
        usernameStatus.innerHTML = '<span class="text-info"><i class="fas fa-spinner fa-spin"></i> Memeriksa username...</span>';
        
        fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'ajax_action=check_username&username=' + encodeURIComponent(username)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.exists) {
                    usernameStatus.innerHTML = '<span class="text-danger"><i class="fas fa-times-circle"></i> ' + data.message + '</span>';
                } else {
                    usernameStatus.innerHTML = '<span class="text-success"><i class="fas fa-check-circle"></i> ' + data.message + '</span>';
                }
            } else {
                usernameStatus.innerHTML = '<span class="text-danger">' + data.error + '</span>';
            }
        })
        .catch(error => {
            usernameStatus.innerHTML = '<span class="text-danger">Error checking username</span>';
        });
    });
});

// Initialize form validation
function initializeFormValidation() {
    const form = document.getElementById('userForm');
    
    form.addEventListener('submit', function(event) {
        if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
        }
        
        form.classList.add('was-validated');
    }, false);
}

// Update form fields based on user type
function updateFormFields() {
    const userType = document.getElementById('user_type').value;
    
    // Hide all conditional fields first
    document.getElementById('kelasField').style.display = 'none';
    document.getElementById('jurusanField').style.display = 'none';
    document.getElementById('mataPelajaranField').style.display = 'none';
    
    // Show fields based on user type
    if (userType === 'Siswa') {
        document.getElementById('kelasField').style.display = 'block';
        document.getElementById('jurusanField').style.display = 'block';
    } else if (userType.includes('Guru') || userType === 'Admin' || 
               userType === 'Wakil_Kepala' || userType === 'Kepala_Sekolah') {
        document.getElementById('mataPelajaranField').style.display = 'block';
    }
}

// Toggle password visibility
function togglePassword(inputId, button) {
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
}

// Initialize delete confirmation
function initializeDeleteConfirmation() {
    const checkBox = document.getElementById('confirmDeleteCheck');
    const deleteBtn = document.getElementById('confirmDeleteBtn');
    
    checkBox.addEventListener('change', function() {
        deleteBtn.disabled = !this.checked;
    });
}

// Show delete confirmation modal
function confirmDelete() {
    const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
    modal.show();
}

// Delete user
function deleteUser() {
    if (!confirm('Anda yakin ingin menghapus user ini secara permanen?')) {
        return;
    }
    
    showLoading('Menghapus user...');
    
    fetch(`<?php echo BASE_URL; ?>api/users.php?action=delete&id=<?php echo $userId; ?>`)
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('success', 'User berhasil dihapus');
            
            // Redirect to manage users after 2 seconds
            setTimeout(() => {
                window.location.href = 'manage_users.php?type=<?php echo urlencode($user['user_type']); ?>&deleted=1';
            }, 2000);
        } else {
            showToast('error', data.message || 'Gagal menghapus user');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showToast('error', 'Gagal menghapus user');
    })
    .finally(() => {
        hideLoading();
    });
}

// Generate random password
function generatePassword() {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
    let password = '';
    
    // Ensure at least one of each required character type
    password += chars[Math.floor(Math.random() * 26)]; // Uppercase
    password += chars[26 + Math.floor(Math.random() * 26)]; // Lowercase
    password += chars[52 + Math.floor(Math.random() * 10)]; // Number
    password += chars[62 + Math.floor(Math.random() * 8)]; // Special character
    
    // Fill the rest
    for (let i = 4; i < 12; i++) {
        password += chars[Math.floor(Math.random() * chars.length)];
    }
    
    // Shuffle the password
    password = password.split('').sort(() => Math.random() - 0.5).join('');
    
    // Set the generated password
    document.getElementById('new_password').value = password;
    document.getElementById('confirm_password').value = password;
    
    // Trigger validation
    validatePassword();
    
    // Show success message
    showToast('success', 'Password acak telah dibuat', 'Anda dapat menyimpan atau mengubahnya');
}

// Copy password to clipboard
function copyPassword() {
    const passwordField = document.getElementById('new_password');
    const confirmField = document.getElementById('confirm_password');
    
    if (passwordField.value) {
        navigator.clipboard.writeText(passwordField.value)
            .then(() => {
                showToast('success', 'Password disalin ke clipboard');
            })
            .catch(err => {
                console.error('Failed to copy: ', err);
            });
    } else if (confirmField.value) {
        navigator.clipboard.writeText(confirmField.value)
            .then(() => {
                showToast('success', 'Password disalin ke clipboard');
            })
            .catch(err => {
                console.error('Failed to copy: ', err);
            });
    }
}

// Show toast notification
function showToast(type, title, message = '') {
    const toastId = 'toast-' + Date.now();
    const toastHtml = `
        <div id="${toastId}" class="toast align-items-center text-white bg-${type} border-0" role="alert">
            <div class="d-flex">
                <div class="toast-body">
                    <strong>${title}</strong> ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    `;
    
    // Create toast container if not exists
    let toastContainer = document.querySelector('.toast-container');
    if (!toastContainer) {
        toastContainer = document.createElement('div');
        toastContainer.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        document.body.appendChild(toastContainer);
    }
    
    toastContainer.insertAdjacentHTML('beforeend', toastHtml);
    
    const toastElement = document.getElementById(toastId);
    const toast = new bootstrap.Toast(toastElement, {
        autohide: true,
        delay: 3000
    });
    
    toast.show();
    
    // Remove toast after it's hidden
    toastElement.addEventListener('hidden.bs.toast', function () {
        this.remove();
    });
}

// Show loading overlay
function showLoading(message = 'Memproses...') {
    let loadingOverlay = document.getElementById('loadingOverlay');
    
    if (!loadingOverlay) {
        loadingOverlay = document.createElement('div');
        loadingOverlay.id = 'loadingOverlay';
        loadingOverlay.className = 'position-fixed top-0 start-0 w-100 h-100 bg-dark bg-opacity-50 d-flex justify-content-center align-items-center z-1050';
        loadingOverlay.style.zIndex = '1050';
        
        loadingOverlay.innerHTML = `
            <div class="bg-white rounded p-4 shadow-lg text-center">
                <div class="spinner-border text-primary mb-3" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <div class="fw-bold" id="loadingMessage">${message}</div>
            </div>
        `;
        
        document.body.appendChild(loadingOverlay);
    } else {
        document.getElementById('loadingMessage').textContent = message;
        loadingOverlay.style.display = 'flex';
    }
}

// Hide loading overlay
function hideLoading() {
    const loadingOverlay = document.getElementById('loadingOverlay');
    if (loadingOverlay) {
        loadingOverlay.style.display = 'none';
    }
}

// Preview avatar image
function previewAvatar() {
    const input = document.getElementById('avatar');
    const preview = document.getElementById('avatarPreview');
    
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
        }
        
        reader.readAsDataURL(input.files[0]);
    }
}

// Set default avatar
function setDefaultAvatar() {
    document.getElementById('avatarPreview').src = '<?php echo BASE_URL; ?>assets/images/default-avatar.png';
    document.getElementById('avatar').value = '';
}

// Format phone number
function formatPhoneNumber(input) {
    let value = input.value.replace(/\D/g, '');
    
    if (value.length > 0) {
        if (value.length <= 3) {
            value = value;
        } else if (value.length <= 6) {
            value = value.substring(0, 3) + '-' + value.substring(3);
        } else if (value.length <= 10) {
            value = value.substring(0, 3) + '-' + value.substring(3, 6) + '-' + value.substring(6);
        } else {
            value = value.substring(0, 3) + '-' + value.substring(3, 7) + '-' + value.substring(7, 11);
        }
    }
    
    input.value = value;
}

// Add event listener for phone number formatting
document.getElementById('phone_number').addEventListener('input', function() {
    formatPhoneNumber(this);
});

// Display password strength
document.getElementById('new_password')?.addEventListener('input', function() {
    const password = this.value;
    const strengthIndicator = document.getElementById('passwordStrength');
    
    if (!strengthIndicator) {
        const parent = this.closest('.mb-3');
        const indicator = document.createElement('div');
        indicator.id = 'passwordStrength';
        indicator.className = 'password-strength mt-2';
        parent.appendChild(indicator);
    }
    
    const strength = calculatePasswordStrength(password);
    updatePasswordStrengthIndicator(strength);
});

function calculatePasswordStrength(password) {
    let score = 0;
    
    if (password.length >= 8) score++;
    if (/[a-z]/.test(password)) score++;
    if (/[A-Z]/.test(password)) score++;
    if (/[0-9]/.test(password)) score++;
    if (/[^a-zA-Z0-9]/.test(password)) score++;
    
    return Math.min(score, 5);
}

function updatePasswordStrengthIndicator(strength) {
    const indicator = document.getElementById('passwordStrength');
    const levels = ['Sangat Lemah', 'Lemah', 'Cukup', 'Kuat', 'Sangat Kuat'];
    const colors = ['danger', 'warning', 'info', 'primary', 'success'];
    
    if (strength === 0) {
        indicator.innerHTML = '';
        return;
    }
    
    indicator.innerHTML = `
        <div class="progress" style="height: 5px;">
            <div class="progress-bar bg-${colors[strength - 1]}" role="progressbar" 
                 style="width: ${(strength / 5) * 100}%"></div>
        </div>
        <small class="text-${colors[strength - 1]} mt-1 d-block">
            Kekuatan: ${levels[strength - 1]}
        </small>
    `;
}

// Validate form before submit
document.getElementById('userForm').addEventListener('submit', function(e) {
    // Remove previous validation messages
    const invalidElements = this.querySelectorAll('.is-invalid');
    invalidElements.forEach(el => {
        el.classList.remove('is-invalid');
    });
    
    // Validate required fields
    const requiredFields = this.querySelectorAll('[required]');
    let isValid = true;
    
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            field.classList.add('is-invalid');
            isValid = false;
        }
    });
    
    // Validate email format
    const emailField = document.getElementById('email');
    if (emailField.value) {
        const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailPattern.test(emailField.value)) {
            emailField.classList.add('is-invalid');
            isValid = false;
        }
    }
    
    if (!isValid) {
        e.preventDefault();
        e.stopPropagation();
        
        // Scroll to first invalid field
        const firstInvalid = this.querySelector('.is-invalid');
        if (firstInvalid) {
            firstInvalid.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        }
        
        showToast('error', 'Perbaiki kesalahan berikut:', 'Isi semua field yang diperlukan');
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